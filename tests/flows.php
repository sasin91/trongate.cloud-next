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
    $launch=['app'=>'demo','server'=>'web','release'=>$release,'metadata'=>['message'=>'Ship the dashboard','branch'=>'main','commit'=>str_repeat('c',40)]];
    $deployment=$flow->_deploy_built('build-one',$launch,['image'=>'registry.example.com/demo@sha256:'.str_repeat('b',64)]);
    check($deployment===$flow->_deploy_built('build-one',$launch,['image'=>'registry.example.com/demo@sha256:'.str_repeat('b',64)]),'Build retry created duplicate deployment');
    check((int)$s->run('SELECT COUNT(*) FROM jobs')->fetchColumn()===2,'Build handoff not idempotent');
    $builtPayload = json_decode($s->job($deployment)['payload'], true);
    $builtRelease = $s->get('release', $builtPayload['release']);
    check($builtRelease['metadata']['message'] === 'Ship the dashboard', 'Release lost source metadata');
    check($builtRelease['created'] > 0, 'Release timestamp missing');
    $releases = [['id'=>$builtPayload['release']] + $builtRelease];
    $releases[0]['metadata']['message'] = '<script>alert(1)</script>';
    $e = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    ob_start();
    require dirname(__DIR__) . '/modules/apps/views/releases.php';
    $releaseHtml = ob_get_clean();
    check(str_contains($releaseHtml, '&lt;script&gt;') && !str_contains($releaseHtml, '<script>'), 'Commit message was not escaped');
    check(str_contains($releaseHtml, 'ccccccc') && str_contains($releaseHtml, 'main') && str_contains($releaseHtml, '<time'), 'Release context missing');

    (new Control($s))->execute('release', ['app'=>'demo', 'release'=>$builtRelease['spec']]);
    check($s->get('release', $builtPayload['release'])['metadata'] === $builtRelease['metadata'], 'Repeated release erased metadata');
    check(Apps_model::_repository('https://github.com/owner/repo.git')==='owner/repo','Repository URL conversion');
    try { Apps_model::_repository('https://evil.example/repo'); throw new LogicException('Foreign URL accepted'); } catch (RuntimeException) {}
    $s->put('server','web',['application_ready'=>true,'host'=>'192.0.2.10','facts'=>['arch'=>'aarch64']]);
    try { $flow->_build_target('web','demo','request','dedicated'); throw new LogicException('Missing builder accepted'); } catch (RuntimeException) {}
    foreach (['builder-a','builder-b'] as $builder) {
        $s->put('server',$builder,['host'=>$builder.'.example.com','builder_ready'=>true,'facts'=>['arch'=>'aarch64']]);
    }
    $s->put('server','wrong-arch',['host'=>'x86.example.com','builder_ready'=>true,'facts'=>['arch'=>'x86_64']]);
    $s->put('server','same-host',['host'=>'192.0.2.10','builder_ready'=>true,'facts'=>['arch'=>'aarch64']]);
    putenv('TC_BUILD_REGISTRY');
    try { $flow->_build_target('web','demo','request','dedicated'); throw new LogicException('Missing registry accepted'); } catch (RuntimeException) {}
    putenv('TC_BUILD_REGISTRY=registry.example.com/apps');
    $s->enqueue('build','builder:builder-a',[]);
    $automatic=$flow->_build_target('web','demo','request','dedicated');
    check($automatic['builder']==='builder-b','Did not prefer compatible builder with shorter queue');
    check(str_starts_with($automatic['tag'],'registry.example.com/apps/demo:build-'),'Automatic tag missing');
    check($automatic===$flow->_build_target('web','demo','request','dedicated'),'Automatic target not stable');
    check($automatic['tag']!==$flow->_build_target('web','demo','another-request','dedicated')['tag'],'Distinct launches reuse tag');
    $s->put('app','other',['server'=>'builder-b']);
    check($flow->_build_target('web','demo','request','dedicated')['builder']==='builder-a','Selected a builder hosting an app');
    putenv('TC_BUILD_REGISTRY');
    $s->put('server', 'web', ['application_ready' => true, 'local_builder_ready' => true, 'host' => '192.0.2.10']);
    $local = $flow->_build_target('web', 'demo', 'local-request');
    check($local['builder'] === 'web' && $local['mode'] === 'local', 'Local builds must be the default');
    check(str_starts_with($local['tag'], 'localhost/tc/demo:'), 'Local build must not require a registry');
    $monitor = new Monitoring_model('monitoring', $s->connection());
    foreach ([600, 300, 0] as $ago) {
        $monitor->_record('web', ['at' => time() - $ago, 'cpu_percent' => 90, 'memory_used' => 90, 'memory_total' => 100, 'disk_used' => 50, 'disk_total' => 100, 'disk_free' => 10000000000]);
    }
    check(count($monitor->_summary('web')['hints']) === 2, 'Sustained pressure should produce CPU and memory hints');
    check($monitor->_summary('web')['autoscaling'] === false, 'Monitoring must never authorize billed scaling');
    check($monitor->_summary('unknown')['stale'] === true, 'Missing monitoring must not imply zero usage');
    $monitor->_record('brief', ['at' => time(), 'cpu_percent' => 99, 'memory_used' => 99, 'memory_total' => 100, 'disk_used' => 50, 'disk_total' => 100, 'disk_free' => 10000000000]);
    check($monitor->_summary('brief')['hints'] === [], 'A single spike must not suggest scaling');
    check(Deployments_model::_dns_matches(['192.0.2.10'], ['192.0.2.10']), 'Expected DNS rejected');
    check(!Deployments_model::_dns_matches(['192.0.2.10', '2001:db8::2'], ['192.0.2.10']), 'Stale AAAA accepted');
    check(!Deployments_model::_dns_matches([], ['192.0.2.10']), 'Missing DNS accepted');
    echo "PASS: launch, encrypted environment, invalid inputs, duplicate submission, build handoff\n";
} finally { unset($monitor,$flow,$s); foreach (glob($dir.'/*') as $file) { unlink($file); } rmdir($dir); }
