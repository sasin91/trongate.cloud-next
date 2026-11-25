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

    foreach (['/servers/scan', '/deployments/change', '/apps/destroy'] as $route) {
        if (request(['csrf_token' => 'bad'], $route)[0] !== 403) {
            throw new RuntimeException('Feature action bypassed CSRF: ' . $route);
        }
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
        || !str_contains($page, 'Build commands')
        || !str_contains($page, 'Extra PHP extensions')
        || !str_contains($page, 'Auto-provision a shared MariaDB database')
        || !str_contains($page, 'Shared Valkey sessions included')
        || !str_contains($page, 'ZFS disk quota')
    ) {
        throw new RuntimeException('Wizard missing expected FreeBSD fields or exposing retired options');
    }
    if (str_contains($page, 'id="server-dialog"') || !str_contains($page, 'href="/servers"')) {
        throw new RuntimeException('Wizard must link to server pages');
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
    if ($code !== 200 || !str_contains($page, 'web-demo') || !str_contains($page, 'Delete app') || str_contains($page, 'web-fixture-secret')) {
        throw new RuntimeException('App dashboard failed or exposed secret');
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
