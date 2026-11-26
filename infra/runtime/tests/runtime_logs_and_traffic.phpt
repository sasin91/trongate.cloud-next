--TEST--
Runtime Caddy JSON access logs, app-traffic polling, and app-logs parsing with 24h error/warning rates
--FILE--
<?php
require __DIR__ . '/inc/simulated_runtime.php';

with_simulated_runtime(function (Freebsd_simulated_runtime $runtime) {
    $r1 = $runtime->dispatch(runtime_request(1));
    $caddy = (string) file_get_contents($runtime->root . '/Caddyfile');
    var_dump(
        str_contains($caddy, 'demo-access.log'),
        str_contains($caddy, 'format json')
    );

    // 1. Write sample PHP-FPM log lines into the active blue jail and verify parsing + cursor + 24h rates.
    $now = gmdate('d-M-Y H:i:s');
    $log_path = $runtime->jails_root . '/demo-blue/var/log/php-fpm.log';
    file_put_contents($log_path, implode("\n", [
        "[{$now} UTC] PHP Warning: Undefined array key \"foo\" in /app/modules/demo/Demo.php on line 12",
        "[{$now} UTC] PHP Fatal error: Uncaught RuntimeException: Boom in /app/modules/demo/Demo.php:42",
        "[{$now} UTC] NOTICE: ready to handle connections",
        "",
    ]));

    $logs1 = $runtime->dispatch(['action' => 'app-logs', 'app' => 'demo', 'limit' => 50]);
    var_dump(
        $logs1['ok'] === true,
        count($logs1['lines']) === 3,
        $logs1['lines'][0]['level'] === 'warn',
        $logs1['lines'][1]['level'] === 'error',
        $logs1['lines'][2]['level'] === '',
        count($logs1['rates']) === 24,
        array_sum(array_column($logs1['rates'], 'errors')) === 1,
        array_sum(array_column($logs1['rates'], 'warnings')) === 1
    );

    // Cursor polling returns only new lines appended after the cursor.
    file_put_contents($log_path, "[{$now} UTC] PHP Fatal error: Second boom\n", FILE_APPEND);
    $logs2 = $runtime->dispatch(['action' => 'app-logs', 'app' => 'demo', 'since' => $logs1['cursor']]);
    var_dump(
        count($logs2['lines']) === 1,
        str_contains($logs2['lines'][0]['text'], 'Second boom')
    );

    // 2. Write sample Caddy JSON access log entries and verify app-traffic + cursor.
    $ts1 = microtime(true) - 10.0;
    $ts2 = microtime(true) - 2.0;
    $access_log = $runtime->root . '/logs/demo-access.log';
    file_put_contents($access_log, implode("\n", [
        json_encode(['ts' => $ts1, 'request' => ['method' => 'GET', 'host' => 'demo.test', 'uri' => '/'], 'status' => 200, 'duration' => 0.014, 'size' => 1600]),
        json_encode(['ts' => $ts2, 'request' => ['method' => 'POST', 'host' => 'demo.test', 'uri' => '/api/run'], 'status' => 500, 'duration' => 0.165, 'size' => 420]),
        "",
    ]));

    $t1 = $runtime->dispatch(['action' => 'app-traffic', 'app' => 'demo']);
    var_dump(
        $t1['ok'] === true,
        count($t1['requests']) === 2,
        $t1['requests'][0]['method'] === 'GET',
        $t1['requests'][0]['status'] === 200,
        $t1['requests'][0]['ms'] === 14.0,
        $t1['requests'][1]['status'] === 500,
        $t1['requests'][1]['bytes'] === 420
    );

    $ts3 = microtime(true);
    file_put_contents($access_log, json_encode(['ts' => $ts3, 'request' => ['method' => 'GET', 'host' => 'demo.test', 'uri' => '/health'], 'status' => 200, 'duration' => 0.005, 'size' => 2]) . "\n", FILE_APPEND);
    $t2 = $runtime->dispatch(['action' => 'app-traffic', 'app' => 'demo', 'since' => $t1['cursor']]);
    var_dump(
        count($t2['requests']) === 1,
        $t2['requests'][0]['path'] === '/health'
    );

    // 3. refresh-routes rewrites Caddyfile for active apps.
    $ref = $runtime->dispatch(['action' => 'refresh-routes']);
    var_dump($ref['status'] === 'succeeded', in_array('demo', $ref['refreshed'], true));
});
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
