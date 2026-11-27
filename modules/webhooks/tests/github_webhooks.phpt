--TEST--
GitHub push webhook HMAC verification, branch matching, auto_deploy toggle, deduplication, and HTTP integration
--FILE--
<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/tests/inc/model_fixture.php';

function assert_ok(bool $cond, string $msg): void {
    if (!$cond) {
        throw new RuntimeException($msg);
    }
}

$dir = sys_get_temp_dir() . '/tc-wh-' . bin2hex(random_bytes(6));
mkdir($dir);
putenv('TC_DSN=sqlite:' . $dir . '/state.db');
putenv('TC_STATE=' . $dir);
putenv('TC_BASE_URL=http://127.0.0.1:18785');
putenv('GITHUB_APP_CLIENT_ID=Iv23liWebhookTest');
putenv('GITHUB_APP_CLIENT_SECRET=webhook-test-client-secret');
putenv('GITHUB_APP_SLUG=trongate-cloud');
putenv('GITHUB_APP_WEBHOOK_SECRET=test-webhook-secret-12345');
file_put_contents($dir . '/key', Encryptor::generate_key());

$model = new Test_model('jobs');
(new Setup_model('setup', $model->db->connection()))->migrate();
$model->db->query_bind('INSERT INTO admins VALUES(?,?)', ['admin', password_hash('test-only-long-password', PASSWORD_DEFAULT)], 'statement');
(new Login_model('login', $model->db->connection()))->_import_legacy_admins();

$model->put('server', 'web', [
    'application_ready' => true,
    'local_builder_ready' => true,
    'host' => '192.0.2.10',
]);
$model->put('app', 'auto-main', [
    'server' => 'web',
    'label' => 'auto-main',
    'domain' => 'auto-main.trongate.dev',
    'repository' => 'Acme/My-App',
    'branch' => 'main',
    'auto_deploy' => true,
    'php_version' => '8.4',
    'database' => true,
]);
$model->put('app', 'manual-main', [
    'server' => 'web',
    'label' => 'manual-main',
    'domain' => 'manual-main.trongate.dev',
    'repository' => 'acme/my-app',
    'branch' => 'main',
    'auto_deploy' => false,
    'php_version' => '8.4',
    'database' => true,
]);
$model->put('app', 'auto-staging', [
    'server' => 'web',
    'label' => 'auto-staging',
    'domain' => 'auto-staging.trongate.dev',
    'repository' => 'acme/my-app',
    'branch' => 'staging',
    'auto_deploy' => true,
    'php_version' => '8.4',
    'database' => true,
]);
$model->put('app', 'canary-main', [
    'server' => 'web',
    'label' => 'canary-main',
    'domain' => 'canary-main.trongate.dev',
    'repository' => 'acme/my-app',
    'branch' => 'main',
    'auto_deploy' => true,
    'canary' => ['weight' => 10, 'commit' => str_repeat('1', 40)],
    'php_version' => '8.4',
    'database' => true,
]);

// 1. Unit tests of Webhooks::_process_github_request().
$webhooks = new Webhooks('webhooks');
$webhooks->_share_database(static fn() => $model->db->connection());
$secret = 'test-webhook-secret-12345';
$sign = static fn(string $body): string => 'sha256=' . hash_hmac('sha256', $body, $secret);

assert_ok($webhooks->_process_github_request('GET', '', '', 'push', 'application/json', $secret)['status'] === 405, 'GET must return 405');
assert_ok($webhooks->_process_github_request('POST', '{}', '', 'push', 'application/json', '')['status'] === 503, 'Unconfigured secret must return 503');
assert_ok($webhooks->_process_github_request('POST', '{}', 'sha256=deadbeef', 'push', 'application/json', $secret)['status'] === 401, 'Invalid signature must return 401');
assert_ok($webhooks->_process_github_request('POST', 'not-json', $sign('not-json'), 'push', 'application/json', $secret)['status'] === 400, 'Invalid JSON must return 400');

$ping_body = json_encode(['zen' => 'Keep it simple']);
$ping_res = $webhooks->_process_github_request('POST', $ping_body, $sign($ping_body), 'ping', 'application/json', $secret);
assert_ok($ping_res['status'] === 200 && ($ping_res['body']['message'] ?? '') === 'pong', 'Ping event failed');

$other_res = $webhooks->_process_github_request('POST', $ping_body, $sign($ping_body), 'issues', 'application/json', $secret);
assert_ok($other_res['status'] === 202 && ($other_res['body']['ignored'] ?? '') === 'event', 'Non-push event should be ignored');

$tag_body = json_encode(['ref' => 'refs/tags/v1.0.0', 'after' => str_repeat('a', 40), 'repository' => ['full_name' => 'acme/my-app']]);
$tag_res = $webhooks->_process_github_request('POST', $tag_body, $sign($tag_body), 'push', 'application/json', $secret);
assert_ok($tag_res['status'] === 202 && ($tag_res['body']['ignored'] ?? '') === 'ref', 'Tag push should be ignored');

$del_body = json_encode(['ref' => 'refs/heads/main', 'after' => str_repeat('0', 40), 'deleted' => true, 'repository' => ['full_name' => 'acme/my-app']]);
$del_res = $webhooks->_process_github_request('POST', $del_body, $sign($del_body), 'push', 'application/json', $secret);
assert_ok($del_res['status'] === 202 && ($del_res['body']['ignored'] ?? '') === 'deleted_or_empty', 'Branch deletion should be ignored');

$sha = str_repeat('e', 40);
$push_payload = [
    'ref' => 'refs/heads/main',
    'after' => $sha,
    'repository' => ['full_name' => 'acme/my-app', 'html_url' => 'https://github.com/acme/my-app'],
    'head_commit' => [
        'message' => 'Ship auto deploy on push',
        'timestamp' => '2026-11-27T10:00:00Z',
        'author' => ['name' => 'Jonas'],
    ],
];
$push_body = json_encode($push_payload);
$push_res = $webhooks->_process_github_request('POST', $push_body, $sign($push_body), 'push', 'application/json', $secret);
assert_ok($push_res['status'] === 202, 'Signed push should answer 202');
assert_ok(isset($push_res['body']['deployments']['auto-main']), 'auto-main should be queued on push to main');
assert_ok(($push_res['body']['skipped']['manual-main'] ?? '') === 'auto_deploy_off', 'manual-main should be skipped');
assert_ok(($push_res['body']['skipped']['canary-main'] ?? '') === 'not_deployable', 'canary-main should be skipped while canary is active');
assert_ok(!isset($push_res['body']['deployments']['auto-staging']) && !isset($push_res['body']['skipped']['auto-staging']), 'staging branch app must not match push to main');

$queued_op = $push_res['body']['deployments']['auto-main'];
$queued_job = $model->job($queued_op);
$queued_payload = json_decode($queued_job['payload'], true);
assert_ok($queued_job['kind'] === 'build', 'Push must enqueue a build job');
assert_ok(($queued_payload['trigger'] ?? '') === 'webhook', 'Build job must record webhook trigger');
assert_ok(($queued_payload['plan']['commit'] ?? '') === $sha, 'Build job must pin pushed commit SHA');
assert_ok(($queued_payload['launch']['metadata']['message'] ?? '') === 'Ship auto deploy on push', 'Commit message metadata missing');

// Repeated delivery of the same push commit must be idempotent.
$dup_res = $webhooks->_process_github_request('POST', $push_body, $sign($push_body), 'push', 'application/json', $secret);
assert_ok(($dup_res['body']['deployments']['auto-main'] ?? '') === $queued_op, 'Duplicate webhook delivery created a second build');
assert_ok((int) $model->db->query_bind("SELECT COUNT(*) FROM jobs WHERE kind='build'", [], 'statement')->fetchColumn() === 1, 'Duplicate build row inserted');

// Form-encoded webhook delivery also works.
$sha2 = str_repeat('f', 40);
$form_payload = $push_payload;
$form_payload['after'] = $sha2;
$form_body = 'payload=' . rawurlencode(json_encode($form_payload));
$form_res = $webhooks->_process_github_request('POST', $form_body, $sign($form_body), 'push', 'application/x-www-form-urlencoded', $secret);
assert_ok($form_res['status'] === 202 && isset($form_res['body']['deployments']['auto-main']), 'Form-encoded webhook delivery failed');

// Overview shows push trigger and auto_deploy flag.
$apps_model = new Apps_model('apps', $model->db->connection());
$ov = $apps_model->_overview('auto-main');
assert_ok(($ov['settings']['auto_deploy'] ?? false) === true, 'Overview settings missing auto_deploy=true');
assert_ok(($ov['deployments'][0]['trigger'] ?? '') === 'push', 'Overview deployment trigger should be push');

// 2. Live HTTP tests for /webhooks/github, /login, /auth/github, and /apps/settings auto_deploy toggle.
$proc = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:18785', '-t', dirname(__DIR__, 3) . '/public', dirname(__DIR__, 3) . '/public/index.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $dir . '/server.log', 'w'], 2 => ['file', $dir . '/server.log', 'a']],
    $pipes
);
$cookie = $dir . '/cookies';

function http_req(string $method, string $path, ?string $raw_body = null, array $headers = []): array {
    global $cookie;
    $c = curl_init('http://127.0.0.1:18785' . $path);
    curl_setopt_array($c, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($raw_body !== null) {
        curl_setopt($c, CURLOPT_POSTFIELDS, $raw_body);
    }
    $raw = (string) curl_exec($c);
    $code = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    $hsize = (int) curl_getinfo($c, CURLINFO_HEADER_SIZE);
    curl_close($c);
    return [$code, substr($raw, 0, $hsize), substr($raw, $hsize)];
}

try {
    $ready = false;
    for ($i = 0; $i < 30; $i++) {
        usleep(100000);
        [$code, , $body] = http_req('GET', '/login');
        if ($code === 200 && str_contains($body, 'Continue with GitHub')) {
            $ready = true;
            break;
        }
    }
    assert_ok($ready, 'Login page did not render Continue with GitHub button: ' . @file_get_contents($dir . '/server.log'));

    // GET /auth/github redirects to GitHub OAuth authorize URL and sets SameSite=Lax HttpOnly cookies.
    [$code, $hdrs] = http_req('GET', '/auth/github?then=apps/create');
    assert_ok($code === 302 && str_contains($hdrs, 'https://github.com/login/oauth/authorize'), 'GET /auth/github did not redirect to GitHub');
    assert_ok(str_contains($hdrs, 'tc_github_state=') && str_contains(strtolower($hdrs), 'samesite=lax'), 'Missing SameSite=Lax state cookie');

    // Live HTTP POST /webhooks/github unsigned -> 401; GET -> 405; signed -> 202.
    [$code] = http_req('GET', '/webhooks/github');
    assert_ok($code === 405, 'HTTP GET /webhooks/github must return 405');
    [$code] = http_req('POST', '/webhooks/github', '{}', ['Content-Type: application/json', 'X-GitHub-Event: push']);
    assert_ok($code === 401, 'Unsigned HTTP POST /webhooks/github must return 401');

    $sha3 = str_repeat('9', 40);
    $live_payload = json_encode([
        'ref' => 'refs/heads/main',
        'after' => $sha3,
        'repository' => ['full_name' => 'acme/my-app'],
        'head_commit' => ['message' => 'Live HTTP push', 'author' => ['name' => 'CI'], 'timestamp' => '2026-11-27T11:00:00Z'],
    ]);
    [$code, , $body] = http_req('POST', '/webhooks/github', $live_payload, [
        'Content-Type: application/json',
        'X-GitHub-Event: push',
        'X-Hub-Signature-256: ' . $sign($live_payload),
    ]);
    $decoded = json_decode($body, true);
    assert_ok($code === 202 && !empty($decoded['deployments']['auto-main']), 'Signed HTTP POST /webhooks/github failed: ' . $body);

    // Internal methods must remain blocked via URL.
    foreach (['/github_auth/_app', '/github_auth/_sign_state', '/webhooks/_handle_push', '/webhooks/_valid_signature'] as $blocked) {
        [$bcode] = http_req('GET', $blocked);
        assert_ok(in_array($bcode, [403, 404], true), 'Internal route exposed: ' . $blocked);
    }

    // Sign in and verify Settings toggles auto_deploy and Overview/Header reflect it.
    [, , $login_html] = http_req('GET', '/login');
    preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $login_html, $m);
    $csrf = $m[1];
    [$code] = http_req('POST', '/login/submit_login/1', http_build_query([
        'csrf_token' => $csrf,
        'identifier' => 'admin',
        'password' => 'test-only-long-password',
    ]), ['Content-Type: application/x-www-form-urlencoded']);
    assert_ok($code === 302, 'Admin login failed');

    [$code, , $show_html] = http_req('GET', '/apps/show/auto-main');
    assert_ok($code === 200 && str_contains($show_html, 'Deploy on push'), 'App header/overview missing Deploy on push');

    [$code, , $wizard_html] = http_req('GET', '/apps/create');
    assert_ok($code === 200 && str_contains($wizard_html, 'Connect GitHub') && str_contains($wizard_html, 'Deploy on push'), 'Wizard missing Connect GitHub or Deploy on push toggle');

    // Toggle Deploy on push OFF via Settings POST.
    [$code] = http_req('POST', '/apps/save_settings', http_build_query([
        'csrf_token' => $csrf,
        'app' => 'auto-main',
        'branch' => 'main',
        'auto_deploy' => '0',
        'health' => '/',
        'php_extensions' => '',
        'build_commands' => '',
        'migration_command' => '',
    ]), ['Content-Type: application/x-www-form-urlencoded']);
    assert_ok($code === 303, 'Saving auto_deploy=0 failed');

    [$code, , $show_after] = http_req('GET', '/apps/show/auto-main');
    assert_ok($code === 200 && str_contains($show_after, 'Manual deploys'), 'App header did not update to Manual deploys after turning auto_deploy off');

    echo "PASS: GitHub push webhooks, auto_deploy toggle, and HTTP OAuth routes\n";
} finally {
    proc_terminate($proc);
    fclose($pipes[0]);
    proc_close($proc);
    unset($apps_model, $webhooks, $model);
    foreach (glob($dir . '/*') as $f) {
        @unlink($f);
    }
    @rmdir($dir);
}
?>
--EXPECT--
PASS: GitHub push webhooks, auto_deploy toggle, and HTTP OAuth routes
