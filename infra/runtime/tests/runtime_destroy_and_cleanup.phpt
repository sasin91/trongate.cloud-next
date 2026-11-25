--TEST--
Runtime destroy-app (soft vs purge), operation flock, release cleanup, and Runtime::process deadlines
--FILE--
<?php
require __DIR__ . '/inc/simulated_runtime.php';

// 1. destroy-app soft vs purge_data=true.
with_simulated_runtime(function (Freebsd_simulated_runtime $runtime) {
    $runtime->dispatch(runtime_request(1));
    $soft = $runtime->dispatch(['action' => 'destroy-app', 'operation' => sprintf('%032x', 10), 'app' => 'demo', 'purge_data' => false]);
    $delValkey = array_values(array_filter($runtime->inputs, fn(array $c) => $c['args'][0] === 'valkey-cli' && str_contains($c['input'], 'ACL DELUSER tc_demo')));
    $dropDbSoft = array_values(array_filter($runtime->inputs, fn(array $c) => $c['args'][0] === 'mariadb' && str_contains($c['input'], 'DROP DATABASE')));
    echo "soft=", $soft['status'], '|', $soft['phase'], '|route=', (is_file($runtime->root . '/routes/demo.caddy') ? 'yes' : 'no'),
        '|caddy=', (str_contains((string) file_get_contents($runtime->root . '/Caddyfile'), 'demo.test') ? 'yes' : 'no'),
        '|dbjson=', (is_file($runtime->root . '/apps/demo/db.json') ? 'yes' : 'no'),
        '|delvk=', count($delValkey), '|dropdb=', count($dropDbSoft), "\n";

    $runtime->dispatch(runtime_request(11));
    $purge = $runtime->dispatch(['action' => 'destroy-app', 'operation' => sprintf('%032x', 12), 'app' => 'demo', 'purge_data' => true]);
    $dropDbPurge = array_values(array_filter($runtime->inputs, fn(array $c) => $c['args'][0] === 'mariadb' && str_contains($c['input'], 'DROP DATABASE IF EXISTS `tc_demo`')));
    echo "purge=", $purge['status'], '|appdir=', (is_dir($runtime->root . '/apps/demo') ? 'yes' : 'no'),
        '|dropdb=', count($dropDbPurge),
        '|zfs=', (in_array(['zfs', 'destroy', '-r', 'zroot/tc/apps/demo'], $runtime->commands, true) ? 'yes' : 'no'), "\n";
});

// 2. operation flock blocks a concurrent worker.
with_simulated_runtime(function (Freebsd_simulated_runtime $runtime) {
    $lock = fopen($runtime->root . '/operation.lock', 'ab');
    flock($lock, LOCK_EX);
    try {
        echo "flock=", $runtime->dispatch(runtime_request(1))['status'], '|starts=', $runtime->starts, "\n";
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
});

// 3. cleanup pauses during resource recovery and retires expired old releases.
with_simulated_runtime(function (Freebsd_simulated_runtime $runtime) {
    $runtime->write($runtime->root . '/resources-pending.json', ['before' => [], 'policies' => []]);
    echo "cleanup_pending=", $runtime->dispatch(['action' => 'cleanup'])['status'], "\n";
    unlink($runtime->root . '/resources-pending.json');

    $runtime->dispatch(runtime_request(1));
    $runtime->dispatch(runtime_request(2));
    $runtime->dispatch(runtime_request(3));
    $oldMeta = $runtime->root . '/apps/demo/releases/' . sprintf('%032x', 1) . '/release.json';
    touch($oldMeta, time() - 90000);

    $cleaned = $runtime->dispatch(['action' => 'cleanup']);
    echo "cleanup=", $cleaned['status'], '|removed=', $cleaned['removed_releases'],
        '|old=', (is_file($oldMeta) ? 'yes' : 'no'),
        '|keep2=', (is_file($runtime->root . '/apps/demo/releases/' . sprintf('%032x', 2) . '/release.json') ? 'yes' : 'no'),
        '|keep3=', (is_file($runtime->root . '/apps/demo/releases/' . sprintf('%032x', 3) . '/release.json') ? 'yes' : 'no'), "\n";
});

// 4. Runtime::process stdin/stdout, stderr suppression, and deadline enforcement.
$input = json_encode(['operation' => str_repeat('a', 32), 'data' => 'fixture'], JSON_THROW_ON_ERROR);
$out = Runtime::process([PHP_BINARY, '-r', 'echo hash("sha256", stream_get_contents(STDIN));'], $input, 5);
echo "proc_hash=", ($out === hash('sha256', $input) ? 'ok' : 'fail'), "\n";

try {
    Runtime::process([PHP_BINARY, '-r', 'fwrite(STDERR, "fixture-private-output"); exit(1);']);
    echo "FAIL stderr not thrown\n";
} catch (RuntimeException $e) {
    echo "proc_fail=", (str_contains($e->getMessage(), 'Controlled command failed:') && !str_contains($e->getMessage(), 'fixture-private-output') ? 'ok' : 'fail'), "\n";
}

try {
    Runtime::process([PHP_BINARY, '-r', 'sleep(5);'], '', 1);
    echo "FAIL timeout not enforced\n";
} catch (RuntimeException $e) {
    echo "proc_timeout=", (str_contains($e->getMessage(), 'timed out') ? 'ok' : 'fail'), "\n";
}
?>
--EXPECT--
soft=succeeded|destroyed|route=no|caddy=no|dbjson=yes|delvk=1|dropdb=0
purge=succeeded|appdir=no|dropdb=1|zfs=yes
flock=uncertain|starts=0
cleanup_pending=waiting
cleanup=succeeded|removed=1|old=no|keep2=yes|keep3=yes
proc_hash=ok
proc_fail=ok
proc_timeout=ok
