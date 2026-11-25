--TEST--
Runtime deferred go-live, pending HTTPS preservation, and same-app/domain fencing
--FILE--
<?php
require __DIR__ . '/inc/simulated_runtime.php';

// 1. First deployment waits for Go live without rebuilding.
with_simulated_runtime(function (Freebsd_simulated_runtime $runtime) {
    $req = runtime_request(1) + ['defer_public' => true];
    echo "wait1=", $runtime->dispatch($req)['status'], ' route=', (is_file($runtime->root . '/routes/demo.caddy') ? 'yes' : 'no'), "\n";
    echo "wait2=", $runtime->dispatch($req)['status'], ' starts=', $runtime->starts, "\n";
    $runtime->public_bad = true;
    echo "golive_retry=", $runtime->dispatch($req + ['go_live' => true])['status'], ' route=', (is_file($runtime->root . '/routes/demo.caddy') ? 'yes' : 'no'), ' attempts=', end($runtime->public_attempts), "\n";
    echo "golive_ok=", $runtime->dispatch($req + ['go_live' => true])['status'], ' starts=', $runtime->starts, ' staged=', (is_file($runtime->root . '/apps/demo/staged.json') ? 'yes' : 'no'), "\n";
});

// 2. Pending HTTPS replacement preserves the previous application.
with_simulated_runtime(function (Freebsd_simulated_runtime $runtime) {
    $runtime->dispatch(runtime_request(1));
    $runtime->public_bad = true;
    $req = runtime_request(2) + ['defer_public' => true];
    echo "repl_wait=", $runtime->dispatch($req)['status'], "\n";
    var_dump(str_contains((string) file_get_contents($runtime->root . '/routes/demo.caddy'), sprintf('%032x', 1)));
    $res = $runtime->dispatch($req + ['go_live' => true]);
    echo "repl_ok=", $res['status'], ' msg=', (isset($res['message']) ? 'yes' : 'no'), ' starts=', $runtime->starts, "\n";
});

// 3. Waiting deployment allows another app but fences same app and duplicate domain.
with_simulated_runtime(function (Freebsd_simulated_runtime $runtime) {
    $runtime->dispatch(runtime_request(1) + ['defer_public' => true]);
    $other = runtime_request(2, ['app' => 'other', 'release' => ['domain' => 'other.test']]);
    echo "other=", $runtime->dispatch($other)['status'], "\n";
    try {
        $runtime->dispatch(runtime_request(3));
        echo "FAIL same app not fenced\n";
    } catch (RuntimeException $e) {
        echo "fenced=", (str_contains($e->getMessage(), 'staged deployment') ? 'yes' : 'no'), "\n";
    }
    $collision = runtime_request(4, ['app' => 'third', 'release' => ['domain' => 'other.test']]);
    $failed = $runtime->dispatch($collision);
    echo "dup_domain=", $failed['status'], '|', (str_contains($failed['error'], 'already assigned') ? 'yes' : 'no'), "\n";
});
?>
--EXPECT--
wait1=waiting route=no
wait2=waiting starts=1
golive_retry=waiting route=yes attempts=30
golive_ok=succeeded starts=1 staged=no
repl_wait=waiting
bool(true)
repl_ok=succeeded msg=no starts=2
other=succeeded
fenced=yes
dup_domain=failed|yes
