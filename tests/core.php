<?php
require_once __DIR__ . '/model_fixture.php';
require __DIR__.'/bootstrap.php';
require_once dirname(__DIR__).'/bootstrap.php';
function database(): Jobs_model { $s=new Test_model('jobs',new PDO('sqlite::memory:')); (new Setup_model('setup',$s->db->connection()))->migrate(); return $s; }
test('queue claims atomically, fences conflicts, recovers same expired claim',function() {
    $s=database(); $a=$s->enqueue('deploy','server:a',[]); $b=$s->enqueue('deploy','server:a',[]);
    $claimed=$s->claim('worker-a'); assert_same($a,$claimed['id']); assert_same(null,$s->claim('worker-b'));
    $s->db->query_bind('UPDATE jobs SET lease=0 WHERE id=?',[$a], 'statement'); assert_same($a,$s->claim('worker-b')['id']);
    assert_throws(fn()=>$s->finish($a,'worker-a','succeeded',[]),'lease lost');
    $s->finish($a,'worker-b','uncertain',[]); assert_same(null,$s->claim('worker-c'));
    $s->_retry($a); assert_same($a,$s->claim('worker-c')['id']);
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
test('unsupported legacy jobs cannot execute feature actions', function () {
    $model = database();
    assert_same(0, (int) $model->db->query_bind('SELECT COUNT(*) FROM jobs', [], 'statement')->fetchColumn());
    $id = $model->enqueue('provision', 'legacy-provider', []);
    (new Worker($model))->once();
    assert_same('uncertain', $model->job($id)['status']);
    assert_same([], $model->all('external'));
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
    $s->secret('key', "  -----BEGIN OPENSSH PRIVATE KEY-----\r\nfixture\r\n-----END OPENSSH PRIVATE KEY-----  ");
    $ssh->with_credentials($server, function($opts, $temporary) {
        assert_same("-----BEGIN OPENSSH PRIVATE KEY-----\nfixture\n-----END OPENSSH PRIVATE KEY-----\n", file_get_contents($temporary.'/identity'));
    });
    $failed = new class extends Process {
        function run(array $argv, string $input = '', int $timeout = 60, ?callable $heartbeat = null, array $extra_env = []): array {
            return ['exit'=>255, 'out'=>'', 'err'=>'Permission denied (publickey).'];
        }
    };
    assert_throws(fn()=>(new Ssh($s,$failed))->inspect($server),'server rejected');

    unlink($dir.'/key'); rmdir($dir); putenv('TC_STATE');
});
test('build plan keeps source pinned and uses inherited command encoding',function() {
    $p=Build::plan(['repository'=>'owner/repo','commit'=>str_repeat('b',40),'base'=>'repo/base@sha256:'.str_repeat('a',64),'tag'=>'registry.test/app:build','commands'=>'echo hello']);
    $default=Build::plan(['repository'=>'owner/repo','commit'=>str_repeat('b',40),'base'=>'','tag'=>'registry.test/app:build','container_port'=>9090]);
    assert_contains('FROM '.Build::DEFAULT_BASE,$default['dockerfile']);
    assert_contains('SERVER_NAME=:9090',$default['dockerfile']);
    assert_contains('WORKDIR /app',$default['dockerfile']);
    assert_contains('pdo_mysql redis gd intl zip bcmath exif',$default['dockerfile']);
    assert_contains('COPY --from='.Build::COMPOSER_IMAGE,$default['dockerfile']);
    assert_contains(base64_encode("set -e\necho hello\n"),$p['dockerfile']); assert_same(str_repeat('b',40),$p['commit']);
});
class BackendProcess extends Process {
    public array $calls=[];
    function run(array $argv,string $input='',int $timeout=60,?callable $heartbeat=null,array $extra_env=[]): array {
        $this->calls[]=$argv;
        if ($argv[0]==='ssh') {
            $request=json_decode($input,true);
            return ['exit'=>0,'out'=>json_encode(['status'=>$request['action']==='host-begin'?'reserved':'succeeded','generation'=>$request['expected'] ?? '/nix/store/observed'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),'err'=>''];
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
