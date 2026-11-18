<?php
require __DIR__.'/bootstrap.php';
require dirname(__DIR__).'/lib/bootstrap.php';
function database(): Store { $s=new Store(new PDO('sqlite::memory:')); $s->migrate(); return $s; }
test('queue claims atomically, fences conflicts, recovers same expired claim',function() {
    $s=database(); $a=$s->enqueue('deploy','server:a',[]); $b=$s->enqueue('deploy','server:a',[]);
    $claimed=$s->claim('worker-a'); assert_same($a,$claimed['id']); assert_same(null,$s->claim('worker-b'));
    $s->run('UPDATE jobs SET lease=0 WHERE id=?',[$a]); assert_same($a,$s->claim('worker-b')['id']);
    assert_throws(fn()=>$s->finish($a,'worker-a','succeeded',[]),'lease lost');
    $s->finish($a,'worker-b','uncertain',[]); assert_same(null,$s->claim('worker-c'));
    (new Control($s))->execute('reconcile',['operation'=>$a]); assert_same($a,$s->claim('worker-c')['id']);
    $s->finish($a,'worker-c','succeeded',[]); assert_same($b,$s->claim('worker-d')['id']);
});
test('parallel resources can progress and pending provider polling keeps its lock',function() {
    $s=database(); $a=$s->enqueue('provision','provider:a',[]); $b=$s->enqueue('deploy','server:a',[]);
    $j=$s->claim('one'); $k=$s->claim('two'); assert_same(true,$j['resource']!==$k['resource']);
    $s->finish($j['id'],'one','pending',[]); assert_same($j['id'],$s->claim('three')['id']);
});
test('input validation rejects shell and Nix interpolation',function() {
    assert_throws(fn()=>Validate::host('-oProxyCommand=bad'),'hostname');
    assert_throws(fn()=>Validate::id('x;touch /tmp/bad'),'identifier');
    assert_throws(fn()=>Validate::image('image:latest'),'digest');
    assert_throws(fn()=>Validate::release(['image'=>'repo/image@sha256:'.str_repeat('a',64),'domain'=>'x.test','mounts'=>['uploads'=>'/etc/shadow']]),'mount');
    assert_throws(fn()=>DeployRs::configuration(['host'=>'x.test','port'=>22,'user'=>'root','credential'=>'key'],['hostname'=>'${bad}','system'=>'x86_64-linux']),'identifier');
});
test('deterministic host settings are JSON data',function() {
    $s=['host'=>'x.test','port'=>22,'user'=>'root','credential'=>'key']; $p=['hostname'=>'web','system'=>'x86_64-linux'];
    assert_same(DeployRs::configuration($s,$p),DeployRs::configuration($s,$p));
    assert_same('web',json_decode(DeployRs::configuration($s,$p),true)['hostname']);
});
test('credentials authenticate ciphertext and do not appear in records',function() {
    $key=Encryptor::generate_key(); $cipher=Encryptor::encrypt('secret-value',$key);
    assert_same(false,str_contains($cipher,'secret-value')); assert_same('secret-value',Encryptor::decrypt($cipher,$key));
    assert_same(false,Encryptor::decrypt($cipher,Encryptor::generate_key()));
});
test('diagnostic redaction removes explicit credentials and PEM blocks',function() {
    $text="credential-value\nAuthorization: Bearer abc123\n-----BEGIN OPENSSH PRIVATE KEY-----\nencoded\n-----END OPENSSH PRIVATE KEY-----";
    $redacted=Redact::text($text,['credential-value']);
    assert_same(false,str_contains($redacted,'credential-value')); assert_same(false,str_contains($redacted,'abc123')); assert_same(false,str_contains($redacted,'encoded'));
});
class FixtureHttp extends Http {
    public array $calls=[];
    function __construct(public array $responses) {}
    function request(string $method,string $path,string $token,?array $body=null): array {
        $this->calls[]=[$method,$path,$body];
        $next=array_shift($this->responses); if ($next instanceof Throwable) { throw $next; } return $next;
    }
}
test('Hetzner action status mapping includes asynchronous errors',function() {
    $http=new FixtureHttp([['action'=>['status'=>'running']],['action'=>['status'=>'success']],['action'=>['status'=>'error','error'=>['code'=>'resource_unavailable']]]]);
    $p=new Hetzner('never-logged',$http);
    assert_same('pending',$p->action(1)['status']); assert_same('succeeded',$p->action(1)['status']); assert_same('failed',$p->action(1)['status']);
});
test('ambiguous create reconciles by stable operation label without a second POST',function() {
    $http=new FixtureHttp([['images'=>[]],['servers'=>[['id'=>99]]]]);
    $p=new Hetzner('secret',$http); $p->list('images'); assert_same(99,$p->reconcile_create(str_repeat('a',32))['id']);
    assert_same(['GET','GET'],array_column($http->calls,0)); assert_contains('label_selector=tc-operation',$http->calls[1][1]);
});
test('duplicate matching provider resources fail closed',function() {
    $p=new Hetzner('secret',new FixtureHttp([['servers'=>[['id'=>1],['id'=>2]]]]));
    assert_throws(fn()=>$p->reconcile_create(str_repeat('a',32)),'Multiple provider');
});
test('worker persists ambiguous create intent and never reissues POST after recovery',function() {
    $dir=sys_get_temp_dir().'/tc-provider-'.bin2hex(random_bytes(5)); mkdir($dir); putenv('TC_STATE='.$dir); file_put_contents($dir.'/key',Encryptor::generate_key());
    $s=database(); $s->secret('token','provider-secret'); $s->put('provider','hetzner',['credential'=>'token']);
    $payload=['provider'=>'hetzner','name'=>'web','image'=>12,'server_type'=>'cx23','location'=>'fsn1','ssh_keys'=>[1]];
    $id=$s->enqueue('provision','provider:hetzner',$payload);
    $http=new FixtureHttp([
        ['servers'=>[]],
        ['image'=>['type'=>'snapshot','status'=>'available','labels'=>['tc-nixos'=>'true'],'architecture'=>'x86']],
        ['server_type'=>['architecture'=>'x86']],
        new RuntimeException('transport timeout provider-secret'),
        ['servers'=>[]],
    ]);
    $worker=new Worker($s,$http); $worker->once();
    assert_same('uncertain',$s->job($id)['status']); assert_same(false,str_contains($s->job($id)['result'],'provider-secret'));
    (new Control($s))->execute('reconcile',['operation'=>$id]); $worker->once();
    assert_same('uncertain',$s->job($id)['status']);
    assert_same(1,count(array_filter($http->calls,fn($c)=>$c[0]==='POST')));
    assert_same($id,$http->calls[3][2]['labels']['tc-operation']);
    unlink($dir.'/key'); rmdir($dir); putenv('TC_STATE');
});
class FixtureProcess extends Process {
    public array $args=[];
    function run(array $argv,string $input='',int $timeout=60,?callable $heartbeat=null,array $extra_env=[]): array {
        $this->args=$argv; return ['exit'=>0,'out'=>"os=ubuntu\narch=x86_64\nprivileged=yes\n",'err'=>''];
    }
}
test('SSH prerequisites are explicit and pinned identities are required',function() {
    $dir=sys_get_temp_dir().'/tc-test-'.bin2hex(random_bytes(5)); mkdir($dir); putenv('TC_STATE='.$dir); file_put_contents($dir.'/key',Encryptor::generate_key());
    $s=database(); $s->secret('key','not-a-real-key'); $process=new FixtureProcess; $ssh=new Ssh($s,$process);
    $server=['host'=>'localhost','port'=>22,'user'=>'root','credential'=>'key'];
    assert_throws(fn()=>$ssh->inspect($server),'identity');
    $server['host_key']='ssh-ed25519 '.base64_encode('fixture'); $r=$ssh->inspect($server);
    assert_same(false,$r['application_ready']); assert_same(false,$r['managed_eligible']); assert_same(true,in_array('podman',$r['missing']));
    assert_same(true,in_array('StrictHostKeyChecking=yes',$process->args));
    unlink($dir.'/key'); rmdir($dir); putenv('TC_STATE');
});
test('build plan keeps source pinned and uses inherited command encoding',function() {
    $p=Build::plan(['repository'=>'owner/repo','commit'=>str_repeat('b',40),'base'=>'repo/base@sha256:'.str_repeat('a',64),'tag'=>'registry.test/app:build','commands'=>'echo hello']);
    assert_contains(base64_encode("set -e\necho hello\n"),$p['dockerfile']); assert_same(str_repeat('b',40),$p['commit']);
});
class BackendProcess extends Process {
    public array $calls=[];
    function run(array $argv,string $input='',int $timeout=60,?callable $heartbeat=null,array $extra_env=[]): array {
        $this->calls[]=$argv;
        if ($argv[0]==='ssh') {
            $request=json_decode($input,true);
            return ['exit'=>0,'out'=>tc_json(['status'=>$request['action']==='host-begin'?'reserved':'succeeded','generation'=>$request['expected'] ?? '/nix/store/observed']),'err'=>''];
        }
        return ['exit'=>0,'out'=>'activation completed','err'=>''];
    }
}
test('deploy-rs backend fences activation, passes strict SSH options and maps observed result',function() {
    $dir=sys_get_temp_dir().'/tc-backend-'.bin2hex(random_bytes(5)); mkdir($dir); putenv('TC_STATE='.$dir); file_put_contents($dir.'/key',Encryptor::generate_key());
    $s=database(); $s->secret('key','fixture-private-key');
    $revision=str_repeat('a',64); mkdir($dir.'/revisions/'.$revision,0700,true);
    file_put_contents($dir.'/revisions/'.$revision.'/flake.lock','{}'); file_put_contents($dir.'/revisions/'.$revision.'/expected-system','/nix/store/expected');
    $process=new BackendProcess; $backend=new DeployRs(new Ssh($s,$process),$process);
    $server=['host'=>'web.test','port'=>22,'user'=>'root','credential'=>'key','host_key'=>'ssh-ed25519 Zml4dHVyZQ=='];
    $result=$backend->deploy($server,['revision'=>$revision,'operation'=>str_repeat('b',32)],fn()=>null);
    assert_same('succeeded',$result['status']); assert_same('/nix/store/expected',$result['generation']);
    assert_same(['ssh','deploy','ssh'],array_map(fn($a)=>$a[0],$process->calls));
    assert_contains('StrictHostKeyChecking=yes',$process->calls[1][2]);
    assert_same('activation completed',trim($result['diagnostic']));
    unlink($dir.'/revisions/'.$revision.'/flake.lock'); unlink($dir.'/revisions/'.$revision.'/expected-system'); rmdir($dir.'/revisions/'.$revision); rmdir($dir.'/revisions'); unlink($dir.'/key'); rmdir($dir); putenv('TC_STATE');
});
finish_tests();
