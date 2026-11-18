<?php
require dirname(__DIR__).'/modules/operations/bootstrap.php';
$dir=sys_get_temp_dir().'/tc-flow-'.bin2hex(random_bytes(6)); mkdir($dir);
putenv('TC_STATE='.$dir); putenv('TC_DSN=sqlite:'.$dir.'/db');
file_put_contents($dir.'/key',Encryptor::generate_key());
$s=new Operations_model('operations'); (new Setup_model('setup',$s->connection()))->migrate(); $flow=new Apps_model('apps',$s->connection());
function check(bool $ok,string $message): void { if (!$ok) { throw new RuntimeException($message); } }
try {
    $s->put('server','web',['application_ready'=>true]);
    $s->put('server','unready',['application_ready'=>false]);
    $p=['source'=>'image','label'=>'demo','server'=>'web','domain'=>'demo.example.com','image'=>'registry.example.com/demo@sha256:'.str_repeat('a',64),'environment'=>"DB_PASSWORD=fixture-private-value\nAPP_ENV=production",'persistent_paths'=>'public/uploads'];
    $job=$flow->_plan_launch($p,'request-one');
    check($job===$flow->_plan_launch($p,'request-one'),'Repeat submission must reuse job');
    check((int)$s->run('SELECT COUNT(*) FROM jobs')->fetchColumn()===1,'Duplicate job queued');
    $payload=json_decode($s->job($job)['payload'],true);
    $release=$s->get('release',$payload['release'])['spec'];
    check($release['mounts']['data-0']==='/app/public/uploads','Mount was not mapped');
    check($s->secret($release['secrets']['DB_PASSWORD'])==='fixture-private-value','Encrypted environment missing');
    check(!str_contains(tc_json($s->all('release')),'fixture-private-value'),'Secret leaked to release metadata');
    foreach ([array_replace($p,['label'=>'bad','server'=>'unready']),array_replace($p,['label'=>'bad','environment'=>'INVALID']),array_replace($p,['label'=>'demo'])] as $invalid) {
        try { $flow->_plan_launch($invalid,bin2hex(random_bytes(4))); throw new LogicException('Invalid launch accepted'); }
        catch (RuntimeException) {}
    }
    check((int)$s->run('SELECT COUNT(*) FROM jobs')->fetchColumn()===1,'Invalid launch caused side effects');
    check(count($s->all('app'))===1,'Invalid launch created app');
    $flow->connection()->beginTransaction();
    $rolledBack=$flow->enqueue('deploy','server:web',['app'=>'rollback-fixture']);
    $flow->put('app','rollback-fixture',['server'=>'web']);
    $flow->secret('rollback-fixture','test-only');
    $flow->connection()->rollBack();
    check((int)$s->run('SELECT COUNT(*) FROM jobs WHERE id=?',[$rolledBack])->fetchColumn()===0,'Delegated queue escaped model transaction');
    check((int)$s->run("SELECT COUNT(*) FROM records WHERE id='rollback-fixture'")->fetchColumn()===0,'Inventory escaped model transaction');
    check((int)$s->run("SELECT COUNT(*) FROM credentials WHERE id='rollback-fixture'")->fetchColumn()===0,'Credential escaped model transaction');
    $launch=['app'=>'demo','server'=>'web','release'=>$release];
    $deployment=$flow->_deploy_built('build-one',$launch,['image'=>'registry.example.com/demo@sha256:'.str_repeat('b',64)]);
    check($deployment===$flow->_deploy_built('build-one',$launch,['image'=>'registry.example.com/demo@sha256:'.str_repeat('b',64)]),'Build retry created duplicate deployment');
    check((int)$s->run('SELECT COUNT(*) FROM jobs')->fetchColumn()===2,'Build handoff not idempotent');
    check(Apps_model::_repository('https://github.com/owner/repo.git')==='owner/repo','Repository URL conversion');
    try { Apps_model::_repository('https://evil.example/repo'); throw new LogicException('Foreign URL accepted'); } catch (RuntimeException) {}
    echo "PASS: launch, encrypted environment, invalid inputs, duplicate submission, build handoff\n";
} finally { unset($flow,$s); foreach (glob($dir.'/*') as $file) { unlink($file); } rmdir($dir); }
