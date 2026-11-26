--TEST--
End-to-end Git launch, encrypted env, build handoff, rollback, destroy-app, and ARC monitoring flows
--FILE--
<?php
require __DIR__ . '/inc/model_fixture.php';

$dir = sys_get_temp_dir() . '/tc-flow-' . bin2hex(random_bytes(6));
mkdir($dir);
putenv('TC_STATE=' . $dir);
putenv('TC_DSN=sqlite:' . $dir . '/db');
file_put_contents($dir . '/key', Encryptor::generate_key());
$s = new Test_model('jobs');
(new Setup_model('setup', $s->db->connection()))->migrate();
$flow = new Apps_model('apps', $s->db->connection());
$deployments = new Deployments_model('deployments', $s->db->connection());

function check(bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

try {
    $s->put('server', 'web', ['application_ready' => true, 'local_builder_ready' => true, 'host' => '192.0.2.10']);
    $s->put('server', 'unready', ['application_ready' => false, 'local_builder_ready' => false]);
    $s->put('server', 'no-builder', ['application_ready' => true, 'local_builder_ready' => false]);

    $commit = str_repeat('c', 40);
    $source = [
        'repository' => 'owner/repo',
        'branch' => 'main',
        'commit' => $commit,
        'message' => 'Ship the dashboard',
        'author' => 'Fixture',
        'committed_at' => '2026-11-25T10:00:00Z',
    ];
    $p = [
        'label' => 'demo',
        'repository' => 'owner/repo',
        'branch' => 'main',
        'domain' => 'demo.example.com',
        'php_version' => '8.4',
        'php_extensions' => 'imagick, gmp, mbstring',
        'build_commands' => "npm ci\nnpm run build",
        'environment' => "DB_PASSWORD=fixture-private-value\nAPP_ENV=production",
    ];

    $job = $flow->_plan_launch($p, 'request-one', $source);
    check($job === $flow->_plan_launch($p, 'request-one', $source), 'Repeat submission must reuse job');
    check((int) $s->db->query_bind('SELECT COUNT(*) FROM jobs', [], 'statement')->fetchColumn() === 1, 'Duplicate job queued');

    $payload = json_decode($s->job($job)['payload'], true);
    check($payload['builder'] === 'web', 'Build must auto-select the ready FreeBSD host');
    check($payload['launch']['server'] === 'web', 'Launch must auto-select the ready FreeBSD host');
    check($payload['plan']['extensions'] === ['imagick', 'gmp'], 'Base extensions must be stripped and optional extensions kept');
    $release = $payload['launch']['release'];
    check($release['artifact'] === 'pending', 'Launch release must start with pending artifact');
    check($release['database'] === true, 'Auto-MariaDB must always be enabled');
    check($release['disk_quota_mb'] === 128, 'Default disk quota must start at 128 MiB');
    check($release['mounts'] === [], 'Mounts start empty before repository inspection');
    check($s->secret($release['secrets']['DB_PASSWORD']) === 'fixture-private-value', 'Encrypted environment missing');
    check(($release['file_secrets'] ?? []) === [], 'Git launch must not overwrite repo config.php with stock defaults');
    check(!str_contains(json_encode($s->job($job), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'fixture-private-value'), 'Secret leaked to job payload');

    foreach ([
        array_replace($p, ['label' => 'bad', 'server' => 'unready']),
        array_replace($p, ['label' => 'bad', 'server' => 'no-builder']),
        array_replace($p, ['label' => 'bad', 'environment' => 'INVALID']),
        array_replace($p, ['label' => 'bad', 'php_extensions' => 'xdebug']),
        array_replace($p, ['label' => 'demo']),
    ] as $invalid) {
        try {
            $flow->_plan_launch($invalid, bin2hex(random_bytes(4)), $source);
            throw new LogicException('Invalid launch accepted');
        } catch (RuntimeException) {
        }
    }
    check((int) $s->db->query_bind('SELECT COUNT(*) FROM jobs', [], 'statement')->fetchColumn() === 1, 'Invalid launch caused side effects');
    check(count($s->all('app')) === 1, 'Invalid launch created app');

    $flow->db->connection()->beginTransaction();
    $rolledBack = $flow->enqueue('deploy', 'server:web', ['app' => 'rollback-fixture']);
    $flow->records->_put(['kind' => 'app', 'id' => 'rollback-fixture', 'data' => ['server' => 'web']]);
    $flow->secret('rollback-fixture', 'test-only');
    $flow->db->connection()->rollBack();
    check((int) $s->db->query_bind('SELECT COUNT(*) FROM jobs WHERE id=?', [$rolledBack], 'statement')->fetchColumn() === 0, 'Delegated queue escaped model transaction');
    check((int) $s->db->query_bind("SELECT COUNT(*) FROM records WHERE id='rollback-fixture'", [], 'statement')->fetchColumn() === 0, 'Inventory escaped model transaction');
    check((int) $s->db->query_bind("SELECT COUNT(*) FROM credentials WHERE id='rollback-fixture'", [], 'statement')->fetchColumn() === 0, 'Credential escaped model transaction');

    $artifact = 'sha256:' . str_repeat('b', 64);
    $releasePath = '/zroot/tc/apps/demo/releases/' . str_repeat('d', 32);
    $buildResult = [
        'artifact' => $artifact,
        'release_path' => $releasePath,
        'disk_quota_mb' => 384,
        'persistent_paths' => ['public/uploads'],
    ];
    $deployment = $flow->_deploy_built($job, $payload['launch'], $buildResult);
    check($deployment === $flow->_deploy_built($job, $payload['launch'], $buildResult), 'Build retry created duplicate deployment');
    check((int) $s->db->query_bind('SELECT COUNT(*) FROM jobs', [], 'statement')->fetchColumn() === 2, 'Build handoff not idempotent');

    $builtPayload = json_decode($s->job($deployment)['payload'], true);
    $builtRelease = $s->get('release', $builtPayload['release']);
    check($builtRelease['spec']['artifact'] === $artifact, 'Release artifact digest missing');
    check($builtRelease['spec']['release_path'] === $releasePath, 'Release ZFS path missing');
    check($builtRelease['spec']['disk_quota_mb'] === 384, 'Inspected disk quota must be applied on build handoff');
    check(array_values($builtRelease['spec']['mounts']) === ['/app/public/uploads'], 'Inspected upload mount missing');
    check(str_starts_with((string) array_key_first($builtRelease['spec']['mounts']), 'upload-'), 'Inspected upload volume name must be deterministic');
    check($builtRelease['metadata']['message'] === 'Ship the dashboard', 'Release lost source metadata');
    check($builtRelease['created'] > 0, 'Release timestamp missing');

    $releases = [['id' => $builtPayload['release']] + $builtRelease];
    $releases[0]['metadata']['message'] = '<script>alert(1)</script>';
    $e = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    ob_start();
    require dirname(__DIR__) . '/modules/apps/views/releases.php';
    $releaseHtml = ob_get_clean();
    check(str_contains($releaseHtml, '&lt;script&gt;') && !str_contains($releaseHtml, '<script>'), 'Commit message was not escaped');
    check(str_contains($releaseHtml, 'ccccccc') && str_contains($releaseHtml, 'main') && str_contains($releaseHtml, '<time'), 'Release context missing');

    $s->module('apps');
    $s->apps->model->_release(['app' => 'demo', 'release' => $builtRelease['spec']]);
    check($s->get('release', $builtPayload['release'])['metadata'] === $builtRelease['metadata'], 'Repeated release erased metadata');
    check(Apps::_repository('https://github.com/owner/repo.git') === 'owner/repo', 'Repository URL conversion');
    check(Apps::_repository('https://www.github.com/owner/repo/') === 'owner/repo', 'www GitHub URL conversion');
    check(Apps::_repository('github.com/owner/repo') === 'owner/repo', 'Schemeless GitHub URL conversion');
    check(Apps::_repository_and_branch('https://github.com/owner/repo/tree/develop') === ['repository' => 'owner/repo', 'branch' => 'develop'], 'GitHub tree URL branch extraction');
    check(Apps::_suggest_label('owner/My_Cool.App') === 'my-cool-app', 'App label suggestion');
    check(Apps::_suggest_label('owner/123app') === 'app-123app', 'Numeric-leading label suggestion');
    try {
        Apps::_repository('https://evil.example/repo');
        throw new LogicException('Foreign URL accepted');
    } catch (RuntimeException) {
    }
    $autoDomainJob = $flow->_plan_launch([
        'label' => 'auto-sub',
        'repository' => 'owner/repo',
        'branch' => 'main',
    ], 'request-auto-domain', $source);
    $autoPayload = json_decode($s->job($autoDomainJob)['payload'], true);
    check($autoPayload['launch']['release']['domain'] === 'auto-sub.trongate.dev', 'Launch without domain must default to <label>.trongate.dev');

    $local = $flow->_build_target('web', 'demo');
    check($local === ['builder' => 'web', 'app' => 'demo'], 'FreeBSD local build target mismatch');
    try {
        $flow->_build_target('no-builder', 'demo');
        throw new LogicException('Server without local_builder_ready accepted');
    } catch (RuntimeException) {
    }

    $redeployJob = $flow->_queue_redeploy_latest('demo', $source);
    check($s->job($redeployJob)['kind'] === 'deploy', 'Same-commit redeploy should reuse built release');
    $newCommitSource = $source;
    $newCommitSource['commit'] = str_repeat('d', 40);
    $rebuildJob = $flow->_queue_redeploy_latest('demo', $newCommitSource);
    check($s->job($rebuildJob)['kind'] === 'build', 'New-commit Deploy now should queue a build job');
    $firstRetryJob = $flow->_queue_redeploy_latest('auto-sub', $source);
    check($s->job($firstRetryJob)['kind'] === 'build', 'Deploy now on app with no prior built release should queue a build job');

    $rollbackJob = $deployments->_queue('rollback', ['app' => 'demo']);
    check($s->job($rollbackJob)['kind'] === 'rollback', 'Rollback job not queued');
    $destroyJob = $deployments->_queue_destroy('demo', true);
    $destroyRecord = $s->job($destroyJob);
    check($destroyRecord['kind'] === 'destroy-app', 'Destroy job not queued');
    check($destroyRecord['resource'] === 'server:web', 'Destroy job resource lock mismatch');
    check(json_decode($destroyRecord['payload'], true)['purge_data'] === true, 'Destroy purge_data flag missing');

    $timeline = $deployments->_timeline($deployment);
    check($timeline !== null && count($timeline['stages']) === 4, 'Deployment timeline missing stages');
    check(str_contains(Deployments_model::_phase_label('preparing-database'), 'MariaDB'), 'Phase label missing MariaDB');
    check(str_contains(Deployments_model::_phase_label('preparing-sessions'), 'session'), 'Phase label missing session');

    $monitor = new Monitoring_model('monitoring', $s->db->connection());
    foreach ([600, 300, 0] as $ago) {
        $monitor->_record('web', [
            'at' => time() - $ago,
            'cpu_percent' => 90,
            'memory_used' => 90,
            'memory_total' => 100,
            'arc_size' => 20,
            'arc_reclaimable' => 10,
            'disk_used' => 50,
            'disk_total' => 100,
            'disk_free' => 10000000000,
        ]);
    }
    check(count($monitor->_summary('web')['hints']) === 2, 'Sustained pressure should produce CPU and memory hints');
    $monitor->_record('web', ['at' => time() + 1, 'cpu_percent' => 10, 'memory_used' => 10, 'memory_total' => 100, 'disk_used' => 50, 'disk_total' => 100, 'disk_free' => 1000]);
    check(str_contains(implode(' ', $monitor->_summary('web')['hints']), 'Less than 5 GB is free on the ZFS pool'), 'Low disk space should warn immediately');
    echo "PASS: FreeBSD Git launch, encrypted config/env, build handoff, rollback, destroy-app, ARC monitoring\n";
} finally {
    unset($monitor, $deployments, $flow, $s);
    foreach (glob($dir . '/*') as $f) {
        unlink($f);
    }
    rmdir($dir);
}
?>
--EXPECT--
PASS: FreeBSD Git launch, encrypted config/env, build handoff, rollback, destroy-app, ARC monitoring
