--TEST--
End-to-end HTTP team invitations, scoped viewer access (tbj@syncronet.dk -> slop), unified login, and suspension
--FILE--
<?php
require dirname(__DIR__, 3) . '/tests/inc/model_fixture.php';

$dir = sys_get_temp_dir() . '/tc-team-web-' . bin2hex(random_bytes(6));
mkdir($dir);
putenv('TC_DSN=sqlite:' . $dir . '/state.db');
putenv('TC_STATE=' . $dir);
putenv('TC_BASE_URL=http://127.0.0.1:18782');
putenv('TC_SMTP_HOST=');

$model = new Test_model('jobs');
(new Setup_model('setup', $model->db->connection()))->migrate();
$model->db->query_bind('INSERT INTO admins VALUES(?,?)', ['admin', password_hash('admin-very-long-password', PASSWORD_DEFAULT)], 'statement');
(new Login_model('login', $model->db->connection()))->_import_legacy_admins();
file_put_contents($dir . '/key', Encryptor::generate_key());

// Seed server, two apps (slop and secret-app), releases, jobs, and an app-scoped service on slop.
$model->put('server', 'web', [
    'application_ready' => true,
    'local_builder_ready' => true,
    'host' => '192.0.2.10',
    'facts' => ['os' => 'freebsd', 'freebsd_version' => '15.1-RELEASE', 'arch' => 'amd64', 'zfs_pool' => 'zroot'],
    'php_versions' => ['8.4'],
    'missing' => [],
]);
$model->put('app', 'slop', [
    'server' => 'web',
    'label' => 'slop',
    'domain' => 'slop.trongate.dev',
    'repository' => 'jonas/slop',
    'branch' => 'main',
    'php_version' => '8.4',
    'database' => true,
]);
$model->put('app', 'secret-app', [
    'server' => 'web',
    'label' => 'secret-app',
    'domain' => 'secret-app.trongate.dev',
    'repository' => 'jonas/secret-app',
    'branch' => 'main',
    'php_version' => '8.4',
    'database' => true,
]);
$model->put('service', 'slop-laya', [
    'server' => 'web',
    'app' => 'slop',
    'name' => 'laya',
    'runtime_name' => 'slop_laya',
    'command' => 'python3 -m laya.serve',
    'port' => 8080,
    'memory_mb' => 512,
    'status' => 'running',
    'url' => 'http://10.99.0.15:8080',
]);
$slopJob = $model->enqueue('deploy', 'app:slop', ['app' => 'slop', 'server' => 'web', 'release' => 'rel-slop']);
$secretJob = $model->enqueue('deploy', 'app:secret-app', ['app' => 'secret-app', 'server' => 'web', 'release' => 'rel-secret']);

$proc = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:18782', '-t', dirname(__DIR__, 3) . '/public', dirname(__DIR__, 3) . '/public/index.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $dir . '/server.log', 'w'], 2 => ['file', $dir . '/server.log', 'a']],
    $pipes
);
$adminCookie = $dir . '/admin.cookies';
$tbjCookie = $dir . '/tbj.cookies';

function req(string $cookieFile, ?array $body = null, string $path = '/login/login/1', bool $mx = false): array {
    $c = curl_init('http://127.0.0.1:18782' . $path);
    curl_setopt_array($c, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_HTTPHEADER => array_merge(['X-Requested-With: XMLHttpRequest'], $mx ? ['Trongate-MX-Request: true'] : []),
    ]);
    if ($body !== null) {
        curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($body));
    }
    $raw = (string) curl_exec($c);
    $code = curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    $headerSize = curl_getinfo($c, CURLINFO_HEADER_SIZE);
    curl_close($c);
    $headers = substr($raw, 0, $headerSize);
    $html = substr($raw, $headerSize);
    $location = '';
    if (preg_match('/^Location:\s*(.+)$/mi', $headers, $lm)) {
        $location = trim($lm[1]);
    }
    return [$code, $html, $location];
}

function extract_csrf(string $html): string {
    if (!preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $html, $m)) {
        throw new RuntimeException('Missing CSRF token in HTML');
    }
    return $m[1];
}

try {
    $page = '';
    for ($i = 0; $i < 30; $i++) {
        usleep(100000);
        [$code, $page] = req($adminCookie);
        if ($code === 200) {
            break;
        }
    }
    if ($code !== 200 || !str_contains($page, 'Email or username')) {
        throw new RuntimeException('Unified login page failed: ' . file_get_contents($dir . '/server.log'));
    }

    // 1. Admin signs in
    $adminCsrf = extract_csrf($page);
    [$code] = req($adminCookie, ['csrf_token' => $adminCsrf, 'identifier' => 'admin', 'password' => 'admin-very-long-password'], '/login/submit_login/1');
    if ($code !== 302) {
        throw new RuntimeException('Admin login failed');
    }

    // Verify CSRF on all team mutation routes
    foreach (['/team/invite', '/team/resend_invite', '/team/revoke_invite', '/team/update_member', '/team/update_app_member', '/team/toggle_member', '/team/remove_member'] as $route) {
        if (req($adminCookie, ['csrf_token' => 'bad'], $route)[0] !== 403) {
            throw new RuntimeException('Team route bypassed CSRF: ' . $route);
        }
    }
    if (req($tbjCookie, ['csrf_token' => 'bad', 'token' => str_repeat('a', 64)], '/team/complete_invite')[0] !== 403) {
        throw new RuntimeException('Public complete_invite bypassed CSRF');
    }

    // Verify team.js asset served and model blocked
    [$assetCode, $assetBody] = req($adminCookie, null, '/team_module/js/team.js');
    if ($assetCode !== 200 || !str_contains($assetBody, 'data-perm-preset')) {
        throw new RuntimeException('team.js asset not served');
    }
    if (!in_array(req($adminCookie, null, '/team_module/Team_model.php')[0], [400, 403, 404], true)) {
        throw new RuntimeException('Team_model.php exposed');
    }

    // 2. Admin opens slop's Access tab and invites tbj@syncronet.dk as Viewer of slop
    [$code, $accessPage] = req($adminCookie, null, '/apps/access/slop');
    if ($code !== 200 || !str_contains($accessPage, 'Invite to slop') || !str_contains($accessPage, 'Who has access to slop')) {
        throw new RuntimeException('App Access tab failed');
    }
    $adminCsrf = extract_csrf($accessPage);

    [$code, , $loc] = req($adminCookie, [
        'csrf_token' => $adminCsrf,
        'access_mode' => 'scoped',
        'return_app' => 'slop',
        'email' => 'tbj@syncronet.dk',
        'note' => 'Ideas for ops.syncronet.dev',
        'app_perms' => ['slop' => ['view', 'deployments', 'logs', 'traffic', 'services_view']],
    ], '/team/invite');
    if ($code !== 303 || $loc !== '/apps/access/slop') {
        throw new RuntimeException('Invite creation redirect failed: ' . $code . ' ' . $loc);
    }

    [$code, $accessAfter] = req($adminCookie, null, '/apps/access/slop');
    if ($code !== 200 || !preg_match('~http://127\.0\.0\.1:18782(/team/accept/[a-f0-9]{64})~', $accessAfter, $m)) {
        throw new RuntimeException('One-time invite URL missing after creation');
    }
    $acceptPath = $m[1];
    $token = basename($acceptPath);

    // 3. tbj@syncronet.dk opens invite link (unauthenticated) and accepts
    [$code, $acceptPage] = req($tbjCookie, null, $acceptPath);
    if (
        $code !== 200
        || !str_contains($acceptPage, 'tbj@syncronet.dk')
        || !str_contains($acceptPage, 'Ideas for ops.syncronet.dev')
        || !str_contains($acceptPage, 'slop · Viewer')
    ) {
        throw new RuntimeException('Invite acceptance screen missing expected details');
    }
    $tbjCsrf = extract_csrf($acceptPage);

    [$code, , $loc] = req($tbjCookie, [
        'csrf_token' => $tbjCsrf,
        'token' => $token,
        'name' => 'TBJ',
        'password' => 'syncronet-strong-pass-42',
        'password_confirm' => 'syncronet-strong-pass-42',
    ], '/team/complete_invite');
    if ($code !== 303 || $loc !== '/apps/show/slop') {
        throw new RuntimeException('Invite acceptance did not redirect straight to /apps/show/slop: ' . $code . ' ' . $loc);
    }

    // Reusing invite link must show unavailable
    [$code, $reusedPage] = req($tbjCookie, null, $acceptPath);
    if ($code !== 200 || !str_contains($reusedPage, 'Invitation unavailable.')) {
        throw new RuntimeException('Used invite link did not show unavailable');
    }

    // 4. Verify scoped Viewer sees ONLY slop and read-only views
    [$code, $tbjDash] = req($tbjCookie, null, '/apps');
    if (
        $code !== 200
        || !str_contains($tbjDash, 'slop')
        || str_contains($tbjDash, 'secret-app')
        || str_contains($tbjDash, '/apps/create')
        || str_contains($tbjDash, 'href="/team"')
        || str_contains($tbjDash, 'href="/services"')
    ) {
        throw new RuntimeException('Scoped viewer dashboard leaked unauthorized apps or nav items');
    }

    [$code, $tbjSlop] = req($tbjCookie, null, '/apps/show/slop');
    if (
        $code !== 200
        || !str_contains($tbjSlop, 'slop.trongate.dev')
        || !str_contains($tbjSlop, 'laya')
        || str_contains($tbjSlop, 'Deploy now')
        || str_contains($tbjSlop, 'Delete app')
        || str_contains($tbjSlop, 'Canary rollout')
        || str_contains($tbjSlop, '/apps/settings/slop')
        || str_contains($tbjSlop, '/apps/database/slop')
        || str_contains($tbjSlop, '/apps/access/slop')
    ) {
        throw new RuntimeException('Viewer app overview exposed restricted actions or tabs');
    }
    $tbjCsrf = extract_csrf($tbjSlop);

    [$code, $tbjDeps] = req($tbjCookie, null, '/apps/deployments/slop');
    if ($code !== 200 || str_contains($tbjDeps, 'Deploy selected release')) {
        throw new RuntimeException('Viewer deployments tab failed or showed deploy button');
    }
    if (req($tbjCookie, null, '/apps/logs/slop')[0] !== 200) {
        throw new RuntimeException('Viewer could not access slop logs');
    }
    if (req($tbjCookie, null, '/apps/traffic/slop')[0] !== 200) {
        throw new RuntimeException('Viewer could not access slop traffic');
    }
    [$code, $tbjSvc] = req($tbjCookie, null, '/apps/services/slop');
    if ($code !== 200 || !str_contains($tbjSvc, 'LAYA_URL') || str_contains($tbjSvc, 'Add a service') || str_contains($tbjSvc, '/services/remove')) {
        throw new RuntimeException('Viewer app services tab failed or exposed mutation forms');
    }
    if (req($tbjCookie, null, '/deployments?operation=' . $slopJob)[0] !== 200) {
        throw new RuntimeException('Viewer could not view slop deployment operation');
    }
    [$code, $tbjJobs] = req($tbjCookie, null, '/jobs');
    if ($code !== 200 || !str_contains($tbjJobs, $slopJob) || str_contains($tbjJobs, $secretJob)) {
        throw new RuntimeException('Viewer jobs page leaked unauthorized app job or hid slop job');
    }

    // 5. Verify strict 403 enforcement on every unauthorized GET & POST
    foreach ([
        '/apps/show/secret-app',
        '/apps/deployments/secret-app',
        '/apps/database/slop',
        '/apps/settings/slop',
        '/apps/access/slop',
        '/apps/create',
        '/deployments?operation=' . $secretJob,
        '/deployments?poll=1&operation=' . $secretJob,
        '/servers',
        '/services',
        '/team',
    ] as $forbiddenGet) {
        if (req($tbjCookie, null, $forbiddenGet)[0] !== 403) {
            throw new RuntimeException('Viewer was not blocked with 403 on GET ' . $forbiddenGet);
        }
    }

    foreach ([
        ['/apps/deploy', ['csrf_token' => $tbjCsrf, 'app' => 'slop']],
        ['/deployments/change', ['csrf_token' => $tbjCsrf, 'app' => 'slop', 'command' => 'rollback-app']],
        ['/apps/save_settings', ['csrf_token' => $tbjCsrf, 'app' => 'slop', 'branch' => 'hacked']],
        ['/apps/save_env', ['csrf_token' => $tbjCsrf, 'app' => 'slop', 'environment' => 'A=1']],
        ['/apps/delete_env', ['csrf_token' => $tbjCsrf, 'app' => 'slop', 'name' => 'A']],
        ['/apps/destroy', ['csrf_token' => $tbjCsrf, 'app' => 'slop']],
        ['/apps/database_query', ['csrf_token' => $tbjCsrf, 'app' => 'slop', 'sql' => 'SHOW TABLES']],
        ['/apps/database_import', ['csrf_token' => $tbjCsrf, 'app' => 'slop', 'import_sql' => 'CREATE TABLE x(id INT)']],
        ['/services/add', ['csrf_token' => $tbjCsrf, 'app' => 'slop', 'service_name' => 'x']],
        ['/services/remove', ['csrf_token' => $tbjCsrf, 'app' => 'slop', 'service' => 'slop-laya']],
        ['/jobs', ['csrf_token' => $tbjCsrf, 'action' => 'reconcile', 'job' => $slopJob]],
    ] as [$route, $payload]) {
        if (req($tbjCookie, $payload, $route)[0] !== 403) {
            throw new RuntimeException('Viewer was not blocked with 403 on POST ' . $route);
        }
    }

    // 6. Admin grants TBJ read-only database access on slop -> verify read allowed, write/import blocked
    $memberId = (int) $model->db->query_bind('SELECT id FROM team_members WHERE email=?', ['tbj@syncronet.dk'], 'statement')->fetchColumn();
    [$code] = req($adminCookie, [
        'csrf_token' => $adminCsrf,
        'member_id' => $memberId,
        'name' => 'TBJ',
        'access_mode' => 'scoped',
        'app_perms' => ['slop' => ['view', 'deployments', 'logs', 'traffic', 'services_view', 'database_read']],
    ], '/team/update_member');
    if ($code !== 303) {
        throw new RuntimeException('Admin failed to update member access');
    }
    if (req($tbjCookie, null, '/apps/database/slop')[0] !== 200) {
        throw new RuntimeException('Member with database_read could not open Database tab');
    }
    if (req($tbjCookie, null, '/apps/database/slop?view=import')[0] !== 403) {
        throw new RuntimeException('Read-only database member accessed import tab');
    }
    if (req($tbjCookie, ['csrf_token' => $tbjCsrf, 'app' => 'slop', 'sql' => 'DELETE FROM t', 'allow_changes' => '1'], '/apps/database_query')[0] !== 403) {
        throw new RuntimeException('Read-only database member bypassed allow_changes guard');
    }

    // 7. TBJ signs out and signs back in via unified /login/login/1 using case-insensitive email
    req($tbjCookie, ['csrf_token' => $tbjCsrf], '/login/logout');
    unlink($tbjCookie);
    [$code, $loginPage] = req($tbjCookie, null, '/login/login/1');
    $loginCsrf = extract_csrf($loginPage);
    [$code, , $loc] = req($tbjCookie, [
        'csrf_token' => $loginCsrf,
        'identifier' => 'TBJ@SyncroNet.dk',
        'password' => 'syncronet-strong-pass-42',
    ], '/login/submit_login/1');
    if ($code !== 302) {
        throw new RuntimeException('Team member unified sign-in failed');
    }
    if (req($tbjCookie, null, '/apps/show/slop')[0] !== 200) {
        throw new RuntimeException('Team member session invalid after unified sign-in');
    }

    // 8. Admin suspends TBJ -> active session immediately revoked
    [$code] = req($adminCookie, [
        'csrf_token' => $adminCsrf,
        'member_id' => $memberId,
        'active' => '0',
    ], '/team/toggle_member');
    if ($code !== 303) {
        throw new RuntimeException('Admin failed to suspend member');
    }
    if (req($tbjCookie, null, '/apps/show/slop')[0] !== 302) {
        throw new RuntimeException('Suspended team member retained active session');
    }

    echo "End-to-end team invitations, granular access, unified login, and suspension passed\n";
} finally {
    proc_terminate($proc);
    fclose($pipes[0]);
    proc_close($proc);
    unset($model);
    foreach (glob($dir . '/*') as $f) {
        unlink($f);
    }
    rmdir($dir);
}
?>
--EXPECT--
End-to-end team invitations, granular access, unified login, and suspension passed
