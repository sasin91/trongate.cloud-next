--TEST--
HTTP login, CSRF, authenticated panel, MX fragments, asset serving, and colocated test blocking
--FILE--
<?php
require __DIR__ . '/inc/model_fixture.php';

$dir = sys_get_temp_dir() . '/tc-web-' . bin2hex(random_bytes(6));
mkdir($dir);
putenv('TC_DSN=sqlite:' . $dir . '/state.db');
putenv('TC_STATE=' . $dir);
putenv('TC_BASE_URL=http://127.0.0.1:18781');
$mysqlAdmin = null;
$testDatabase = null;
if ($mysqlDsn = getenv('TC_WEB_MYSQL')) {
    $mysqlAdmin = new PDO($mysqlDsn, getenv('TC_DB_USER'), getenv('TC_DB_PASSWORD'));
    $testDatabase = 'tc_web_' . bin2hex(random_bytes(6));
    $mysqlAdmin->exec('CREATE DATABASE ' . $testDatabase);
    putenv('TC_DSN=' . $mysqlDsn . ';dbname=' . $testDatabase);
}
$model = new Test_model('jobs');
(new Setup_model('setup', $model->db->connection()))->migrate();
$model->db->query_bind('INSERT INTO admins VALUES(?,?)', ['admin', password_hash('test-only-long-password', PASSWORD_DEFAULT)], 'statement');
(new Login_model('login', $model->db->connection()))->_import_legacy_admins();
$originalHash = $model->db->query_bind('SELECT password FROM admins WHERE name=?', ['admin'], 'statement')->fetchColumn();
(new Login_model('login', $model->db->connection()))->_import_legacy_admins();
if (
    $model->db->query_bind('SELECT password FROM trongate_administrators WHERE username=?', ['admin'], 'statement')->fetchColumn() !== $originalHash
    || (int) $model->db->query_bind('SELECT COUNT(*) FROM trongate_users', [], 'statement')->fetchColumn() !== 1
) {
    throw new RuntimeException('Administrator migration changed credentials or duplicated users');
}
file_put_contents($dir . '/key', Encryptor::generate_key());
$proc = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:18781', '-t', dirname(__DIR__) . '/public', dirname(__DIR__) . '/public/index.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $dir . '/server.log', 'w'], 2 => ['file', $dir . '/server.log', 'a']],
    $pipes
);
$cookie = $dir . '/cookies';

function request(?array $body = null, string $path = '/login/login/1', bool $mx = false): array {
    global $cookie;
    $c = curl_init('http://127.0.0.1:18781' . $path);
    curl_setopt_array($c, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_HTTPHEADER => array_merge(['X-Requested-With: XMLHttpRequest'], $mx ? ['Trongate-MX-Request: true'] : []),
    ]);
    if ($body !== null) {
        curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($body));
    }
    $html = curl_exec($c);
    $code = curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    curl_close($c);
    return [$code, $html];
}

try {
    $page = '';
    for ($i = 0; $i < 30; $i++) {
        usleep(100000);
        [$code, $page] = request();
        if ($code === 200) {
            break;
        }
    }
    if (!str_contains((string) $page, 'Sign in')) {
        throw new RuntimeException('Login page failed: ' . file_get_contents($dir . '/server.log'));
    }

    preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $page, $m);
    $csrf = $m[1];
    if (request(['command' => 'inventory', 'csrf_token' => 'bad'], '/login/submit_login/1')[0] !== 403) {
        throw new RuntimeException('CSRF not enforced');
    }
    request(['csrf_token' => $csrf, 'identifier' => 'admin', 'password' => 'incorrect-password'], '/login/submit_login/1');
    if (
        (int) $model->db->query_bind('SELECT COUNT(*) FROM login_attempts', [], 'statement')->fetchColumn() !== 1
        || (int) $model->db->query_bind('SELECT COUNT(*) FROM trongate_tokens', [], 'statement')->fetchColumn() !== 0
    ) {
        throw new RuntimeException('Native login failure not recorded');
    }
    [$code, $page] = request(['csrf_token' => $csrf, 'identifier' => 'admin', 'password' => 'test-only-long-password'], '/login/submit_login/1');
    if ($code !== 302) {
        throw new RuntimeException('Native login failed: ' . $page . ' ' . file_get_contents($dir . '/server.log'));
    }
    [$code, $page] = request(null, '/apps');
    if (!str_contains($page, 'Your apps.')) {
        throw new RuntimeException('Login failed');
    }
    if ((int) $model->db->query_bind('SELECT COUNT(*) FROM trongate_tokens', [], 'statement')->fetchColumn() !== 1) {
        throw new RuntimeException('Native login token missing');
    }

    if (request(['command' => 'inventory', 'csrf_token' => 'bad'], '/jobs')[0] !== 403) {
        throw new RuntimeException('Authenticated mutation bypassed CSRF');
    }
    if (request(['app' => 'demo', 'mode' => 'automatic', 'csrf_token' => 'bad'], '/servers/details?server=web')[0] !== 403) {
        throw new RuntimeException('Resource edit bypassed CSRF');
    }

    [$code, $page] = request(null, '/jobs');
    if ($code !== 200 || !str_contains($page, 'Jobs')) {
        throw new RuntimeException('Authenticated job history failed');
    }
    if (str_contains($page, 'name="payload"') || str_contains($page, 'Perform an operation')) {
        throw new RuntimeException('Generic command console remains');
    }

    $retry = $model->enqueue('deploy', 'server:recovery-fixture', []);
    $model->claim('recovery-owner');
    $model->finish($retry, 'recovery-owner', 'uncertain', []);
    if (request(['action' => 'reconcile', 'job' => $retry, 'csrf_token' => 'bad'], '/jobs')[0] !== 403) {
        throw new RuntimeException('Job recovery bypassed CSRF');
    }
    if (request(['action' => 'reconcile', 'job' => $retry, 'csrf_token' => $csrf], '/jobs')[0] !== 200 || $model->job($retry)['status'] !== 'pending') {
        throw new RuntimeException('Job recovery failed');
    }
    if ($model->db->query_bind('SELECT operation FROM resource_locks WHERE resource=?', ['server:recovery-fixture'], 'statement')->fetchColumn() !== $retry) {
        throw new RuntimeException('Job recovery released its resource lock');
    }
    if (request(['action' => 'reconcile', 'job' => $retry, 'csrf_token' => $csrf], '/jobs')[0] !== 422) {
        throw new RuntimeException('Pending job was reconciled twice');
    }
    $model->db->query_bind('DELETE FROM jobs WHERE id=?', [$retry], 'statement');
    $model->db->query_bind('DELETE FROM resource_locks WHERE operation=?', [$retry], 'statement');

    foreach (['/servers/scan', '/deployments/change', '/apps/deploy', '/apps/save_settings', '/apps/save_env', '/apps/delete_env', '/apps/database_query', '/apps/database_import', '/apps/destroy', '/services/add', '/services/redeploy', '/services/remove'] as $route) {
        if (request(['csrf_token' => 'bad'], $route)[0] !== 403) {
            throw new RuntimeException('Feature action bypassed CSRF: ' . $route);
        }
    }
    [$code, $svcPage] = request(null, '/services');
    if ($code !== 200 || !str_contains($svcPage, 'Add a service') || !str_contains($svcPage, 'Laya (AI verdict model)')) {
        throw new RuntimeException('Services page missing expected content');
    }

    $model->put('server', 'web', [
        'application_ready' => true,
        'local_builder_ready' => true,
        'host' => '192.0.2.10',
        'facts' => ['os' => 'freebsd', 'freebsd_version' => '15.1-RELEASE', 'arch' => 'amd64', 'zfs_pool' => 'zroot'],
        'php_versions' => ['8.4'],
        'missing' => [],
    ]);
    [$code, $page] = request(null, '/apps/create');
    if ($code !== 200 || !str_contains($page, 'Which app are we putting online?')) {
        throw new RuntimeException('Wizard missing');
    }
    if (
        str_contains($page, 'name="builder"')
        || str_contains($page, 'name="tag"')
        || str_contains($page, 'name="server"')
        || str_contains($page, 'name="database"')
        || str_contains($page, 'name="domain"')
        || str_contains($page, 'name="disk_quota_mb"')
        || str_contains($page, 'name="persistent_paths"')
        || str_contains($page, 'ZFS')
        || str_contains($page, 'Where should it run?')
        || str_contains($page, 'Does your app need a database?')
        || !str_contains($page, 'mx-post="/apps/validate_step"')
        || !str_contains($page, '.trongate.dev')
        || !str_contains($page, 'Build commands')
        || !str_contains($page, 'Extra PHP extensions')
        || !str_contains($page, 'Health check path')
    ) {
        throw new RuntimeException('Wizard missing expected fields or exposing server/database/domain/quota/persistent-path internals');
    }
    if (request(['csrf_token' => 'bad', 'wizard_step' => '0'], '/apps/validate_step', true)[0] !== 403) {
        throw new RuntimeException('Wizard step validation bypassed CSRF');
    }
    [$code, $stepErr] = request(['csrf_token' => $csrf, 'wizard_step' => '0', 'repository' => 'https://evil.example/repo'], '/apps/validate_step', true);
    if ($code !== 422 || !isset(json_decode($stepErr, true)['repository'])) {
        throw new RuntimeException('Step 0 must reject non-GitHub URLs with 422 field error');
    }
    [$code, $stepErr] = request(['csrf_token' => $csrf, 'wizard_step' => '2', 'environment' => 'NOT_A_PAIR'], '/apps/validate_step', true);
    if ($code !== 422 || !isset(json_decode($stepErr, true)['environment'])) {
        throw new RuntimeException('Step 2 must reject malformed environment lines with 422');
    }
    [$code, $stepErr] = request(['csrf_token' => $csrf, 'wizard_step' => '3', 'label' => 'Bad_Name'], '/apps/validate_step', true);
    if ($code !== 422 || !isset(json_decode($stepErr, true)['label'])) {
        throw new RuntimeException('Step 3 must reject invalid app label with 422');
    }
    [$code, $stepOk] = request(['csrf_token' => $csrf, 'wizard_step' => '3', 'label' => 'my-demo'], '/apps/validate_step', true);
    if ($code !== 200 || !str_contains($stepOk, 'data-ok="1"') || !str_contains($stepOk, 'data-domain="my-demo.trongate.dev"')) {
        throw new RuntimeException('Step 3 valid label must return MX step state with *.trongate.dev domain');
    }
    [$code, $stepErr] = request(['csrf_token' => $csrf, 'wizard_step' => '4', 'php_extensions' => 'xdebug'], '/apps/validate_step', true);
    if ($code !== 422 || !isset(json_decode($stepErr, true)['php_extensions'])) {
        throw new RuntimeException('Step 4 must reject unsupported PHP extensions with 422');
    }
    [$code, $stepErr] = request(['csrf_token' => $csrf, 'wizard_step' => '5', 'repository' => 'owner/repo', 'branch' => 'main', 'label' => 'my-demo', 'health' => 'no-slash'], '/apps/validate_step', true);
    if ($code !== 422 || !isset(json_decode($stepErr, true)['health'])) {
        throw new RuntimeException('Step 5 must reject invalid health path with 422');
    }
    if (str_contains($page, 'id="server-dialog"') || str_contains($page, 'href="/servers"')) {
        throw new RuntimeException('Wizard must not expose server links or dialogs');
    }
    preg_match('/name="request" value="([a-f0-9]+)"/', $page, $launch);
    if (request(['csrf_token' => 'bad', 'label' => 'draft'], '/apps/draft')[0] !== 403) {
        throw new RuntimeException('Draft bypassed CSRF');
    }
    [$code, $saved] = request([
        'csrf_token' => $csrf,
        'repository' => 'owner/trongate-demo',
        'branch' => 'main',
        'label' => 'saved-app',
        'environment' => 'PASSWORD=draft-secret',
        'wizard_step' => '3',
    ], '/apps/draft');
    if ($code !== 200 || !json_decode($saved, true)['saved']) {
        throw new RuntimeException('Draft not saved');
    }

    [$code, $page] = request(null, '/servers');
    if (
        $code !== 200
        || !str_contains($page, 'Connect a FreeBSD host')
        || !str_contains($page, '/servers/connect')
        || !str_contains($page, 'FreeBSD 15.1-RELEASE')
        || !str_contains($page, 'zroot')
    ) {
        throw new RuntimeException('FreeBSD server pages missing expected content');
    }
    if (str_contains($page, 'Hetzner') || str_contains($page, 'href="/providers"')) {
        throw new RuntimeException('Retired provider UI remains visible');
    }

    [$code, $page] = request(null, '/apps/create');
    if (!str_contains($page, 'saved-app') || !str_contains($page, 'PASSWORD=draft-secret') || !str_contains($page, 'data-step="3"') || !str_contains($page, $launch[1])) {
        throw new RuntimeException('App draft or launch identity lost');
    }
    $savedRecord = $model->db->query_bind("SELECT data FROM records WHERE kind='app-draft'", [], 'statement')->fetchColumn();
    if (str_contains($savedRecord, 'draft-secret')) {
        throw new RuntimeException('Draft secret stored unencrypted');
    }

    request(['csrf_token' => $csrf], '/login/logout');
    if (request(null, '/servers/details?server=web', true)[0] !== 401) {
        throw new RuntimeException('MX must reject expired sessions without embedding login');
    }

    unlink($cookie);
    [$code, $page] = request();
    preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $page, $m);
    $csrf = $m[1];
    request(['csrf_token' => $csrf, 'identifier' => 'admin', 'password' => 'test-only-long-password'], '/login/submit_login/1');
    [$code, $page] = request(null, '/apps');
    if (!str_contains($page, 'Finish setting up your app.') || !str_contains($page, 'Continue app setup')) {
        throw new RuntimeException('Dashboard resume missing after login');
    }
    [$code, $page] = request(null, '/apps/create');
    if (!str_contains($page, 'PASSWORD=draft-secret') || !str_contains($page, 'data-step="3"') || !str_contains($page, $launch[1])) {
        throw new RuntimeException('Draft lost across fresh login');
    }
    $draftModel = new Apps_model('apps', $model->db->connection());
    if ($draftModel->_load_draft(999999) !== null) {
        throw new RuntimeException('Draft leaked to another account');
    }

    [$code, $error] = request([
        'command' => 'connect-server',
        'inline' => 'server',
        'csrf_token' => $csrf,
        'id' => 'new-server',
        'host' => '192.0.2.11',
        'user' => 'tc-agent',
        'port' => 22,
        'private_key' => '',
    ], '/servers/action');
    if ($code !== 422 || !isset(json_decode($error, true)['error'])) {
        throw new RuntimeException('Inline errors must return JSON without navigating away');
    }
    [$code, $error] = request([
        'command' => 'confirm-server',
        'inline' => 'server',
        'csrf_token' => $csrf,
        'enrollment' => 'expired',
        'fingerprint' => 'SHA256:fixture',
    ], '/servers/action');
    if ($code !== 422 || !str_contains(json_decode($error, true)['error'], 'expired')) {
        throw new RuntimeException('Inline confirmation must reject stale enrollment');
    }

    $sourceData = [
        'repository' => 'owner/web-demo',
        'branch' => 'main',
        'commit' => str_repeat('a', 40),
        'message' => 'Initial FreeBSD release',
        'author' => 'Fixture',
        'committed_at' => '2026-11-25T10:00:00Z',
    ];
    $buildId = $draftModel->_plan_launch([
        'label' => 'web-demo',
        'server' => 'web',
        'repository' => 'owner/web-demo',
        'branch' => 'main',
        'domain' => 'demo.example.com',
        'php_version' => '8.4',
        'database' => '1',
        'environment' => 'PASSWORD=web-fixture-secret',
    ], $launch[1], $sourceData);
    if ($draftModel->_plan_launch([
        'label' => 'web-demo',
        'server' => 'web',
        'repository' => 'owner/web-demo',
        'branch' => 'main',
        'domain' => 'demo.example.com',
        'php_version' => '8.4',
        'database' => '1',
        'environment' => 'PASSWORD=web-fixture-secret',
    ], $launch[1], $sourceData) !== $buildId) {
        throw new RuntimeException('Repeated launch request duplicated build');
    }
    $buildPayload = json_decode($model->job($buildId)['payload'], true);
    $id = $draftModel->_deploy_built($buildId, $buildPayload['launch'], [
        'artifact' => 'sha256:' . str_repeat('b', 64),
        'release_path' => '/zroot/tc/apps/web-demo/releases/' . $buildId,
    ]);

    [$code, $page] = request(null, '/apps');
    if (
        $code !== 200
        || !str_contains($page, 'web-demo')
        || !str_contains($page, '/apps/show/web-demo')
        || str_contains($page, 'web-fixture-secret')
        || str_contains($page, 'href="/servers"')
    ) {
        throw new RuntimeException('App dashboard failed, exposed secret, or exposed server links');
    }

    [$code, $overviewPage] = request(null, '/apps/show/web-demo');
    if (
        $code !== 200
        || !str_contains($overviewPage, 'Environment')
        || !str_contains($overviewPage, 'Deploy now')
        || !str_contains($overviewPage, 'Canary rollout')
        || !str_contains($overviewPage, 'tc_web_demo')
        || !str_contains($overviewPage, 'Delete app')
        || !str_contains($overviewPage, 'href="/apps/deployments/web-demo"')
        || !str_contains($overviewPage, 'href="/apps/services/web-demo"')
        || !str_contains($overviewPage, 'href="/apps/settings/web-demo"')
        || !str_contains($overviewPage, 'name="app" value="web-demo"')
        || str_contains($overviewPage, 'web-fixture-secret')
    ) {
        throw new RuntimeException('App overview page missing expected sections, app ID links/forms, or exposed secret');
    }

    // Add a shared server-wide service and ensure app overview + services tab link to it
    $model->put('service', 'laya', [
        'name' => 'laya',
        'runtime_name' => 'laya',
        'server' => 'web',
        'command' => '/var/db/service/venv/bin/python -m laya.serve',
        'port' => 8080,
        'memory_mb' => 4096,
        'status' => 'running',
        'ip' => '10.99.251.1',
        'url' => 'http://10.99.251.1:8080',
    ]);
    [$code, $overviewWithShared] = request(null, '/apps/show/web-demo');
    if (
        $code !== 200
        || !str_contains($overviewWithShared, 'Shared service')
        || !str_contains($overviewWithShared, 'LAYA_URL')
        || !str_contains($overviewWithShared, 'href="/services#service-laya"')
    ) {
        throw new RuntimeException('App overview did not link to shared service');
    }

    [$code, $appSvcPage] = request(null, '/apps/services/web-demo');
    if (
        $code !== 200
        || !str_contains($appSvcPage, 'Shared services')
        || !str_contains($appSvcPage, 'LAYA_URL=http://10.99.251.1:8080')
        || !str_contains($appSvcPage, 'href="/services#service-laya"')
        || !str_contains($appSvcPage, 'private services web-demo can call')
        || !str_contains($appSvcPage, 'name="app" value="web-demo"')
        || !str_contains($appSvcPage, 'Laya (AI verdict model)')
        || !str_contains($appSvcPage, 'href="/apps/services/web-demo" aria-current="page"')
    ) {
        throw new RuntimeException('App services tab missing expected shared/app-scoped content');
    }

    // Add a service scoped to web-demo and ensure another app does not see it
    $model->put('app', 'other-demo', ['server' => 'web', 'label' => 'other-demo', 'domain' => 'other-demo.trongate.dev']);
    [$code] = request([
        'csrf_token' => $csrf,
        'app' => 'web-demo',
        'service_name' => 'worker',
        'service_command' => 'python3.12 -m http.server 8080',
        'service_port' => '8080',
        'service_memory_mb' => '256',
    ], '/services/add');
    if ($code !== 303) {
        throw new RuntimeException('App-scoped service add failed with status ' . $code);
    }
    [$code, $appSvcAfter] = request(null, '/apps/services/web-demo');
    [$code2, $otherSvcPage] = request(null, '/apps/services/other-demo');
    if ($code !== 200 || !str_contains($appSvcAfter, 'WORKER_URL') || $code2 !== 200 || str_contains($otherSvcPage, 'WORKER_URL')) {
        throw new RuntimeException('App-scoped service leaked to another app or missing from owning app');
    }
    $svcJobId = (string) $model->db->query_bind("SELECT id FROM jobs WHERE kind='deploy-service' ORDER BY created DESC LIMIT 1", [], 'statement')->fetchColumn();
    [$code, $svcTimelinePage] = request(null, '/deployments?operation=' . $svcJobId);
    if (
        $code !== 200
        || !str_contains($svcTimelinePage, 'deployment-timeline')
        || !str_contains($svcTimelinePage, 'Service deployment')
        || !str_contains($svcTimelinePage, 'WORKER_URL')
        || !str_contains($svcTimelinePage, '/apps/services/web-demo')
        || str_contains($svcTimelinePage, '"jail"')
    ) {
        throw new RuntimeException('Service deployment page must render customer timeline without internal jail JSON');
    }
    $model->db->query_bind("DELETE FROM records WHERE kind='app' AND id='other-demo'", [], 'statement');
    $model->db->query_bind("DELETE FROM records WHERE kind='service'", [], 'statement');
    $model->db->query_bind("DELETE FROM jobs WHERE kind='deploy-service'", [], 'statement');

    [$code, $depsPage] = request(null, '/apps/deployments/web-demo');
    if ($code !== 200 || !str_contains($depsPage, 'Deployment history') || !str_contains($depsPage, 'Built releases') || str_contains($depsPage, 'web-fixture-secret')) {
        throw new RuntimeException('App deployments tab failed or exposed secret');
    }

    [$code, $settingsPage] = request(null, '/apps/settings/web-demo');
    if (
        $code !== 200
        || !str_contains($settingsPage, 'Application settings')
        || !str_contains($settingsPage, 'Migration command')
        || !str_contains($settingsPage, 'Environment variables')
        || !str_contains($settingsPage, 'PASSWORD')
        || str_contains($settingsPage, 'web-fixture-secret')
    ) {
        throw new RuntimeException('App settings tab failed or exposed secret');
    }

    [$code, $dbPage] = request(null, '/apps/database/web-demo');
    if ($code !== 200 || !str_contains($dbPage, 'href="/apps/database/web-demo"') || str_contains($dbPage, 'web-fixture-secret')) {
        throw new RuntimeException('App database tab failed');
    }

    [$code, $logsPage] = request(null, '/apps/logs/web-demo');
    if ($code !== 200 || !str_contains($logsPage, 'Warnings and errors only') || !str_contains($logsPage, 'app-log-box')) {
        throw new RuntimeException('App logs tab failed');
    }
    [$code, $logsPoll] = request(null, '/apps/logs_poll/web-demo?since=abc', true);
    if ($code !== 200 || !str_contains($logsPoll, '<div id="log-cursor">abc</div>')) {
        throw new RuntimeException('App logs poll must return #log-cursor div for MX value swap');
    }

    [$code, $trafficPage] = request(null, '/apps/traffic/web-demo');
    if ($code !== 200 || !str_contains($trafficPage, 'Live traffic') || !str_contains($trafficPage, 'Start recording') || !str_contains($trafficPage, '/apps/traffic_poll/web-demo')) {
        throw new RuntimeException('App traffic tab failed');
    }

    [$code, $page] = request(null, '/deployments?operation=' . $id);
    if ($code !== 200 || !str_contains($page, 'deployment-timeline') || str_contains($page, 'web-fixture-secret')) {
        throw new RuntimeException('Deployment view failed or exposed secret');
    }
    [$code, $page] = request(null, '/deployments?poll=1&operation=' . $id);
    if ($code !== 200 || json_decode($page, true)['job']['status'] !== 'pending' || str_contains($page, 'web-fixture-secret')) {
        throw new RuntimeException('Deployment polling failed');
    }
    [$code, $timelineJson] = request(null, '/deployments?poll=1&timeline=1&operation=' . $id);
    if ($code !== 200 || count(json_decode($timelineJson, true)['stages']) !== 4 || str_contains($timelineJson, 'web-fixture-secret')) {
        throw new RuntimeException('Timeline API failed');
    }
    [$mxCode, $fragment] = request(null, '/deployments?operation=' . $id, true);
    if ($mxCode !== 200 || str_contains($fragment, '<html') || !str_contains($fragment, 'id="deployment-content"') || !str_contains($fragment, 'every 3s')) {
        throw new RuntimeException('MX timeline must return a polling fragment');
    }
    [$mxCode, $fragment] = request(null, '/servers/details?server=missing', true);
    if ($mxCode !== 200 || str_contains($fragment, '<html') || !str_contains($fragment, 'id="resource-workspace"') || !str_contains($fragment, 'role="alert"')) {
        throw new RuntimeException('MX resource errors must remain inline');
    }
    if (request(['csrf_token' => 'bad'], '/servers/details?server=web', true)[0] !== 403) {
        throw new RuntimeException('MX resource edit bypassed CSRF');
    }
    if (request(['operation' => $id, 'csrf_token' => 'bad'], '/deployments/live')[0] !== 403) {
        throw new RuntimeException('Go live bypassed CSRF');
    }

    [$code] = request(['csrf_token' => $csrf, 'app' => 'web-demo', 'purge_data' => '0'], '/apps/destroy');
    if ($code !== 303) {
        throw new RuntimeException('App destroy failed with status ' . $code);
    }
    if ((int) $model->db->query_bind("SELECT COUNT(*) FROM jobs WHERE kind='destroy-app'", [], 'statement')->fetchColumn() !== 1) {
        throw new RuntimeException('App destroy did not enqueue destroy-app job');
    }

    foreach ([
        '/apps_module/js/flows.js',
        '/apps_module/js/app_logs.js',
        '/apps_module/js/app_traffic.js',
        '/templates_module/css/wizard.css',
        '/templates_module/css/panel.css',
        '/templates_module/js/mx.js',
        '/servers_module/js/resources.js',
        '/deployments_module/css/deployments.css',
    ] as $asset) {
        [$code, $body] = request(null, $asset);
        if ($code !== 200 || strlen($body) < 100 || str_contains($body, 'Control plane unavailable')) {
            throw new RuntimeException('Module asset not served: ' . $asset);
        }
    }
    foreach (['/servers/builds', '/apps/create', '/apps', '/servers', '/servers/connect', '/servers/confirm', '/jobs', '/'] as $route) {
        [$code, $body] = request(null, $route);
        if ($code !== 200 || str_contains($body, 'Warning:') || str_contains($body, 'Control plane unavailable')) {
            throw new RuntimeException('Module view failed: ' . $route);
        }
    }
    foreach (['/deployments/host', '/servers/create', '/providers/action'] as $retired) {
        if (!in_array(request(null, $retired)[0], [403, 404], true)) {
            throw new RuntimeException('Retired route remains accessible: ' . $retired);
        }
    }
    foreach (['/setup/migrate', '/monitoring/index', '/jobs/_share_database'] as $route) {
        if (!in_array(request(null, $route)[0], [403, 404], true)) {
            throw new RuntimeException('Internal loader or model module exposed: ' . $route);
        }
    }
    if (request(null, '/db/connection')[0] !== 403) {
        throw new RuntimeException('Direct database access must be blocked');
    }
    foreach ([
        '/records/_get',
        '/records/_find',
        '/records/_all',
        '/records/_put',
        '/records/_insert',
        '/records/_delete',
        '/records_module/Records_model.php',
        '/apps/_encode_json',
        '/apps/_build',
        '/apps/_queue_build',
        '/apps/_plan_launch',
        '/apps/_deploy_built',
        '/apps/_use_http_client',
        '/apps_module/tests/trongate_config.phpt',
        '/servers/_scan_server',
        '/servers/_connect',
        '/deployments/_run',
        '/deployments/_destroy',
        '/jobs/_retry',
        '/jobs/worker',
        '/jobs_module/worker.php',
        '/setup/init',
        '/setup_module/init.php',
        '/setup/migrate',
        '/setup_module/migrate.php',
        '/monitoring/_collect',
        '/monitoring/collect',
        '/monitoring_module/collect.php',
        '/http_client/request',
        '/authentication/_logout',
        '/servers_module/views/dialog.php',
        '/servers_module/js/%2e%2e/Servers.php',
    ] as $route) {
        if (!in_array(request(null, $route)[0], [400, 403, 404], true)) {
            throw new RuntimeException('Internal module path exposed: ' . $route);
        }
    }

    request(['csrf_token' => $csrf], '/login/logout');
    if (request(null, '/servers/details?server=web', true)[0] !== 401) {
        throw new RuntimeException('MX must reject expired sessions without embedding login');
    }
    if ((int) $model->db->query_bind('SELECT COUNT(*) FROM trongate_tokens', [], 'statement')->fetchColumn() !== 0) {
        throw new RuntimeException('Logout did not revoke native token');
    }
    [$code] = request(['command' => 'connect-server', 'inline' => 'server', 'csrf_token' => $csrf], '/servers/action');
    if ($code !== 401) {
        throw new RuntimeException('Logged-out inline enrollment accepted');
    }
    if (request(null, '/deployments?poll=1&operation=' . $id)[0] !== 401) {
        throw new RuntimeException('Unauthenticated polling allowed');
    }
    if (request(null, '/apps/create')[0] !== 302) {
        throw new RuntimeException('Module route bypassed login');
    }
    [$code, $login] = request(null, '/login/login/1');
    if ($code !== 200 || !str_contains($login, 'Welcome back.') || str_contains($login, 'Which app are we putting online?')) {
        throw new RuntimeException('Module route bypassed login');
    }
    if (request(null, '/deployments?poll=1&operation=' . $id)[0] !== 401) {
        throw new RuntimeException('Module polling bypassed authentication');
    }
    echo "HTTP login, CSRF and authenticated console passed\n";
} finally {
    proc_terminate($proc);
    fclose($pipes[0]);
    proc_close($proc);
    unset($draftModel, $model);
    foreach (glob($dir . '/*') as $f) {
        unlink($f);
    }
    rmdir($dir);
    if ($mysqlAdmin) {
        $mysqlAdmin->exec('DROP DATABASE ' . $testDatabase);
    }
}
?>
--EXPECT--
HTTP login, CSRF and authenticated console passed
