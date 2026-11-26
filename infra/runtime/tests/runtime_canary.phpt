--TEST--
Runtime canary deploy splits traffic per slot, supports weight updates, boot, cleanup, abort, and promote
--FILE--
<?php
require __DIR__ . '/inc/simulated_runtime.php';

with_simulated_runtime(function (Freebsd_simulated_runtime $runtime) {
    // 0. UUID hex bucket regex sanity checks.
    var_dump(
        Runtime::canary_uuid_regex(0) === null,
        Runtime::canary_uuid_regex(10) === '^(?:0[0-9a-f]|1[0-9])',
        Runtime::canary_uuid_regex(25) === '^[0-3]',
        Runtime::canary_uuid_regex(50) === '^[0-7]'
    );

    // 1. Canary before first active release is rejected cleanly.
    $rejected_first = false;
    try {
        $runtime->dispatch(runtime_request(1, ['canary' => true, 'canary_weight' => 10]));
    } catch (RuntimeException $e) {
        $rejected_first = str_contains($e->getMessage(), 'active release');
    }
    var_dump($rejected_first);

    // 2. Normal first deploy puts blue active.
    $r1 = $runtime->dispatch(runtime_request(1));
    echo "r1=", $r1['status'], '|', $r1['phase'], '|', $r1['candidate']['slot'], "\n";

    // 3. Canary deploy puts green live alongside blue at 25%.
    $c1 = $runtime->dispatch(runtime_request(2, ['canary' => true, 'canary_weight' => 25]));
    echo "c1=", $c1['status'], '|', $c1['phase'], '|', $c1['canary']['slot'], '|', $c1['canary']['weight'], "\n";
    var_dump(
        isset($runtime->running_jails['tc_demo_blue']),
        isset($runtime->running_jails['tc_demo_green']),
        end($runtime->public_headers) === ['X-Canary: 1']
    );

    $caddy = (string) file_get_contents($runtime->root . '/Caddyfile');
    var_dump(
        str_contains($caddy, '@force_canary'),
        str_contains($caddy, '@sample_canary expression `{http.request.uuid}.matches("^[0-3]")`'),
        str_contains($caddy, 'header X-TC-Slot "blue"'),
        str_contains($caddy, 'header X-TC-Slot "green"'),
        str_contains($caddy, 'header X-TC-Release "canary"'),
        str_contains($caddy, 'tc_canary=1; Path=/; Max-Age=1800; SameSite=Lax'),
        str_contains($caddy, '/releases/' . sprintf('%032x', 1) . '/public'),
        str_contains($caddy, '/releases/' . sprintf('%032x', 2) . '/public')
    );

    // 4. Starting another deploy while canary is active is rejected.
    $rejected_concurrent = false;
    try {
        $runtime->dispatch(runtime_request(3));
    } catch (RuntimeException $e) {
        $rejected_concurrent = str_contains($e->getMessage(), 'active canary');
    }
    var_dump($rejected_concurrent);

    // 5. Adjust canary weight: 25% -> 50% -> 0% (preview only).
    $w50 = $runtime->dispatch([
        'action' => 'canary-weight',
        'operation' => sprintf('%032x', 10),
        'app' => 'demo',
        'weight' => 50,
    ]);
    $caddy50 = (string) file_get_contents($runtime->root . '/Caddyfile');
    echo "w50=", $w50['status'], '|', $w50['weight'], "\n";
    var_dump(str_contains($caddy50, 'matches("^[0-7]")'));

    $w0 = $runtime->dispatch([
        'action' => 'canary-weight',
        'operation' => sprintf('%032x', 11),
        'app' => 'demo',
        'weight' => 0,
    ]);
    $caddy0 = (string) file_get_contents($runtime->root . '/Caddyfile');
    echo "w0=", $w0['status'], '|', $w0['weight'], "\n";
    var_dump(
        !str_contains($caddy0, '@sample_canary'),
        str_contains($caddy0, '@force_canary')
    );

    // 6. boot_active() boots both stable and canary slots across a host reboot.
    $runtime->running_jails = [];
    $boot = $runtime->dispatch(['action' => 'boot']);
    var_dump(
        in_array('demo', $boot['started'], true),
        in_array('demo:canary', $boot['started'], true),
        isset($runtime->running_jails['tc_demo_blue']),
        isset($runtime->running_jails['tc_demo_green'])
    );

    // 7. cleanup() preserves both active and canary release directories.
    $canary_meta = $runtime->root . '/apps/demo/releases/' . sprintf('%032x', 2) . '/release.json';
    touch($canary_meta, time() - 172800);
    $clean = $runtime->dispatch(['action' => 'cleanup']);
    var_dump($clean['removed_releases'] === 0, is_file($canary_meta));

    // 8. Abort canary restores 100% single-slot Caddyfile on blue and stops green.
    $aborted = $runtime->dispatch([
        'action' => 'canary-abort',
        'operation' => sprintf('%032x', 12),
        'app' => 'demo',
    ]);
    $caddy_aborted = (string) file_get_contents($runtime->root . '/Caddyfile');
    echo "abort=", $aborted['status'], '|', $aborted['phase'], "\n";
    var_dump(
        isset($runtime->running_jails['tc_demo_blue']),
        !isset($runtime->running_jails['tc_demo_green']),
        !str_contains($caddy_aborted, '@force_canary'),
        str_contains($caddy_aborted, 'demo-blue/php-fpm.sock')
    );

    // 9. Second canary -> promote cuts 100% to green, stops blue, and retains blue as previous for rollback.
    $c2 = $runtime->dispatch(runtime_request(4, ['canary' => true, 'canary_weight' => 10]));
    $promoted = $runtime->dispatch([
        'action' => 'canary-promote',
        'operation' => sprintf('%032x', 13),
        'app' => 'demo',
    ]);
    $caddy_promoted = (string) file_get_contents($runtime->root . '/Caddyfile');
    $state = $runtime->read($runtime->root . '/apps/demo/state.json');
    echo "promote=", $promoted['status'], '|', $promoted['phase'], '|', $state['active']['slot'], '|', $state['previous']['slot'], "\n";
    var_dump(
        ($state['canary'] ?? null) === null,
        !isset($runtime->running_jails['tc_demo_blue']),
        isset($runtime->running_jails['tc_demo_green']),
        !str_contains($caddy_promoted, '@force_canary'),
        str_contains($caddy_promoted, 'demo-green/php-fpm.sock')
    );
});
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
r1=succeeded|accepted|blue
c1=succeeded|canary-active|green|25
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
w50=succeeded|50
bool(true)
w0=succeeded|0
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
abort=succeeded|canary-aborted
bool(true)
bool(true)
bool(true)
bool(true)
promote=succeeded|accepted|green|blue
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
