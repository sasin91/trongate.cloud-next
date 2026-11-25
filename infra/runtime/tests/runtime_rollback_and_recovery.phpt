--TEST--
Runtime rollback preserves persistent data and failure/crash states recover safely
--FILE--
<?php
require __DIR__ . '/inc/simulated_runtime.php';

// 1. Deploy, idempotent replay, and sub-second rollback preserve persistent data.
with_simulated_runtime(function (Freebsd_simulated_runtime $runtime) {
    $dataFile = $runtime->releases_root . '/demo/data/uploads/example.txt';
    Runtime::directory(dirname($dataFile));
    file_put_contents($dataFile, 'keep me');

    echo "d1=", $runtime->dispatch(runtime_request(1))['status'], "\n";
    echo "d2=", $runtime->dispatch(runtime_request(2))['status'], "\n";
    echo "d2_replay=", $runtime->dispatch(runtime_request(2))['status'], ' starts=', $runtime->starts, "\n";

    $rb = $runtime->dispatch(['action' => 'rollback', 'operation' => sprintf('%032x', 3), 'app' => 'demo']);
    echo "rb=", $rb['status'], '|', $rb['candidate']['slot'], '|', ($rb['active'] === sprintf('%032x', 1) ? 'v1' : 'other'), '|', file_get_contents($dataFile), "\n";
});

// 2. Failed candidate, failed public check, crash after switch, and unknown migration recover safely.
with_simulated_runtime(function (Freebsd_simulated_runtime $runtime) {
    $runtime->dispatch(runtime_request(1));
    $runtime->bad = sprintf('%032x', 2);
    echo "bad_cand=", $runtime->dispatch(runtime_request(2))['status'], '|', ($runtime->read($runtime->root . '/apps/demo/state.json')['active']['operation'] === sprintf('%032x', 1) ? 'kept_v1' : 'lost'), "\n";

    $runtime->bad = null;
    $runtime->public_bad = true;
    echo "bad_pub=", $runtime->dispatch(runtime_request(3))['status'], '|', ($runtime->read($runtime->root . '/apps/demo/state.json')['active']['operation'] === sprintf('%032x', 1) ? 'kept_v1' : 'lost'), "\n";

    $before = $runtime->read($runtime->root . '/apps/demo/state.json');
    $runtime->write($runtime->root . '/jobs/' . sprintf('%032x', 4) . '.json', ['status' => 'running', 'phase' => 'switching', 'before' => $before]);
    echo "crash_switch=", $runtime->dispatch(runtime_request(4))['status'], "\n";

    $runtime->write($runtime->root . '/jobs/' . sprintf('%032x', 5) . '.json', ['status' => 'running', 'phase' => 'migration-started', 'before' => $before]);
    echo "crash_mig=", $runtime->dispatch(runtime_request(5))['status'], "\n";
});
?>
--EXPECT--
d1=succeeded
d2=succeeded
d2_replay=succeeded starts=2
rb=succeeded|blue|v1|keep me
bad_cand=failed|kept_v1
bad_pub=failed|kept_v1
crash_switch=failed
crash_mig=uncertain
