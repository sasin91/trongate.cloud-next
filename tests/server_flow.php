<?php
require dirname(__DIR__).'/modules/operations/bootstrap.php';
final class ProviderFixture extends Http {
    public array $writes=[];
    public bool $available=true;
    public string $imageArchitecture='arm';
    function request(string $method,string $path,string $token,?array $body=null): array {
        $price=['location'=>'nbg1','price_monthly'=>['net'=>'5.99'],'price_hourly'=>['net'=>'0.0096']];
        $type=['id'=>45,'name'=>'cax11','cores'=>2,'memory'=>4,'disk'=>40,'architecture'=>'arm','deprecated'=>false,'locations'=>[['name'=>'nbg1','available'=>$this->available]],'prices'=>[$price]];
        $image=['id'=>11,'type'=>'system','name'=>'debian-12','description'=>'Debian 12','architecture'=>$this->imageArchitecture,'status'=>'available','disk_size'=>5];
        if ($method==='POST') { $this->writes[]=$body; return ['server'=>['id'=>99],'action'=>['id'=>100]]; }
        if (str_starts_with($path,'/server_types/')) return ['server_type'=>$type];
        if (str_starts_with($path,'/server_types?')) return ['server_types'=>[$type]];
        if (str_starts_with($path,'/locations?')) return ['locations'=>[['name'=>'nbg1','description'=>'Nuremberg']]];
        if (str_starts_with($path,'/images/')) return ['image'=>$image];
        if (str_starts_with($path,'/images?')) return ['images'=>str_contains($path,'type=system')?[$image]:[]];
        if (str_starts_with($path,'/ssh_keys?')) return ['ssh_keys'=>[['id'=>7,'name'=>'my-key','fingerprint'=>'fixture']]];
        if ($path==='/pricing') return ['pricing'=>['currency'=>'EUR','primary_ips'=>[['type'=>'ipv4','prices'=>[['location'=>'nbg1','price_monthly'=>['net'=>'0.50'],'price_hourly'=>['net'=>'0.0008']]]]]]];
        if (str_starts_with($path,'/servers?')) return ['servers'=>[]];
        if ($path==='/actions/100') return ['action'=>['status'=>'success']];
        if ($path==='/servers/99') return ['server'=>['id'=>99,'public_net'=>['ipv4'=>['ip'=>'192.0.2.99']]]];
        throw new RuntimeException('Unexpected fixture request: '.$path);
    }
}
class ProviderModuleFixture extends Providers_model {
    function _options(string $id,Http $http=new Http): array {
        return parent::_options($id,$GLOBALS['http']);
    }
    function _connect_project(string $id,string $token,Http $http=new Http): array {
        return parent::_connect_project($id,$token,$GLOBALS['http']);
    }
}
function verify(bool $condition,string $message): void { if (!$condition) throw new LogicException($message); }
$dir=sys_get_temp_dir().'/tc-provider-'.bin2hex(random_bytes(6)); mkdir($dir); putenv('TC_STATE='.$dir);
file_put_contents($dir.'/key',Encryptor::generate_key());
$s=new Operations_model('operations',new PDO('sqlite::memory:')); (new Setup_model('setup',$s->connection()))->migrate(); $http=new ProviderFixture; $flow=new ProviderModuleFixture('providers',$s->connection());
try {
    $flow->_connect_project('project','fixture-token');
    verify(!str_contains(tc_json($s->all('provider')),'fixture-token'),'Provider token exposed');
    $named=$flow->_connect_project('Trongate.cloud Next','fixture-token');
    verify($named['id']==='trongate-cloud-next','Project display name rejected');
    verify($s->get('provider',$named['id'])['label']==='Trongate.cloud Next','Project display name lost');
    $flow->_update_token('project','replacement-token',$http);
    $ref=$s->get('provider','project')['credential'];
    verify($s->secret($ref)==='replacement-token','Token not replaced');
    $reject=new class extends Http {
        function request(string $method,string $path,string $token,?array $body=null): array {
            throw new RuntimeException('Rejected token');
        }
    };
    try { $flow->_update_token('project','bad-token',$reject); throw new LogicException('Bad token accepted'); } catch (RuntimeException) {}
    verify($s->secret($ref)==='replacement-token','Rejected token overwrote working credential');
    $input=['provider'=>'project','name'=>'web','server_type'=>'cax11','location'=>'nbg1','image'=>11,'ssh_key'=>7];
    $quote=$flow->_quote($input);
    verify($quote['monthly_net']==='6.49' && $quote['preparation_required'],'Incorrect price or preparation status');
    try { $flow->_create($quote['quote'],false); throw new LogicException('Missing consent accepted'); } catch (RuntimeException) {}
    verify((int)$s->run('SELECT COUNT(*) FROM jobs')->fetchColumn()===0,'Unconfirmed creation queued');
    $queued=$flow->_create($quote['quote'],true);
    verify($queued===$flow->_create($quote['quote'],true),'Repeated request duplicated server');
    verify((int)$s->run('SELECT COUNT(*) FROM jobs')->fetchColumn()===1,'Duplicate creation job');
    $worker=new Worker($s,$http); $worker->once(); $worker->once();
    verify(count($http->writes)===1,'Provider server was created more than once');
    verify($http->writes[0]['labels']['tc-temporary']==='false','Application server marked temporary');
    $status=$flow->_status($queued['operation']);
    verify($status['status']==='succeeded' && $status['host']==='192.0.2.99' && $status['provider_id']===99,'Enrollment handoff missing');
    $http->available=false;
    try { $flow->_quote($input); throw new LogicException('Unavailable type accepted'); } catch (RuntimeException) {}
    $http->available=true;
    $http->imageArchitecture='x86';
    try { $flow->_quote($input); throw new LogicException('Architecture mismatch accepted'); } catch (RuntimeException) {}
    $http->imageArchitecture='arm';
    try { (new Hetzner('fixture-token',$http))->create('fixture',$input); throw new LogicException('Implicit stock image accepted'); } catch (RuntimeException) {}
    try { $flow->_quote(array_replace($input,['ssh_key'=>8])); throw new LogicException('Unknown SSH key accepted'); } catch (RuntimeException) {}
    $expired=$flow->_quote($input); $record=$s->get('server-quote',$expired['quote']); $record['expires']=0; $s->put('server-quote',$expired['quote'],$record);
    try { $flow->_create($expired['quote'],true); throw new LogicException('Expired review accepted'); } catch (RuntimeException) {}
    verify(count($http->writes)===1,'Invalid request mutated provider');
    echo "PASS: provider options, price review, confirmation, idempotent creation, worker polling, enrollment handoff\n";
} finally { unlink($dir.'/key'); rmdir($dir); }
