<?php
require dirname(__DIR__).'/lib/bootstrap.php';
$dir=sys_get_temp_dir().'/tc-flow-'.bin2hex(random_bytes(6)); mkdir($dir);
putenv('TC_STATE='.$dir); putenv('TC_DSN=sqlite:'.$dir.'/db');
file_put_contents($dir.'/key',Encryptor::generate_key());
$s=Store::open(); $s->migrate(); $flow=new App_flow($s);
function check(bool $ok,string $message): void { if (!$ok) { throw new RuntimeException($message); } }
try {
    $s->put('server','web',['application_ready'=>true]);
    $s->put('server','unready',['application_ready'=>false]);
    $p=['source'=>'image','label'=>'demo','server'=>'web','domain'=>'demo.example.com','image'=>'registry.example.com/demo@sha256:'.str_repeat('a',64),'environment'=>"DB_PASSWORD=fixture-private-value\nAPP_ENV=production",'persistent_paths'=>'public/uploads'];
    $job=$flow->launch($p,'request-one');
    check($job===$flow->launch($p,'request-one'),'Repeat submission must reuse job');
    check((int)$s->run('SELECT COUNT(*) FROM jobs')->fetchColumn()===1,'Duplicate job queued');
    $payload=json_decode($s->job($job)['payload'],true);
    $release=$s->get('release',$payload['release'])['spec'];
    check($release['mounts']['data-0']==='/app/public/uploads','Mount was not mapped');
    check($s->secret($release['secrets']['DB_PASSWORD'])==='fixture-private-value','Encrypted environment missing');
    check(!str_contains(json($s->all('release')),'fixture-private-value'),'Secret leaked to release metadata');
    foreach ([array_replace($p,['label'=>'bad','server'=>'unready']),array_replace($p,['label'=>'bad','environment'=>'INVALID']),array_replace($p,['label'=>'demo'])] as $invalid) {
        try { $flow->launch($invalid,bin2hex(random_bytes(4))); throw new LogicException('Invalid launch accepted'); }
        catch (RuntimeException) {}
    }
    check((int)$s->run('SELECT COUNT(*) FROM jobs')->fetchColumn()===1,'Invalid launch caused side effects');
    check(count($s->all('app'))===1,'Invalid launch created app');
    $launch=['app'=>'demo','server'=>'web','release'=>$release];
    $deployment=$flow->deploy_built('build-one',$launch,['image'=>'registry.example.com/demo@sha256:'.str_repeat('b',64)]);
    check($deployment===$flow->deploy_built('build-one',$launch,['image'=>'registry.example.com/demo@sha256:'.str_repeat('b',64)]),'Build retry created duplicate deployment');
    check((int)$s->run('SELECT COUNT(*) FROM jobs')->fetchColumn()===2,'Build handoff not idempotent');
    check(App_flow::repository('https://github.com/owner/repo.git')==='owner/repo','Repository URL conversion');
    try { App_flow::repository('https://evil.example/repo'); throw new LogicException('Foreign URL accepted'); } catch (RuntimeException) {}
    echo "PASS: launch, encrypted environment, invalid inputs, duplicate submission, build handoff\n";
} finally { unset($flow,$s); foreach (glob($dir.'/*') as $file) { unlink($file); } rmdir($dir); }
