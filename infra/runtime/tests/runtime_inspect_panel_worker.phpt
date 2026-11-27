--TEST--
Runtime inspects the control-plane worker without changing service state
--FILE--
<?php
require __DIR__ . '/inc/simulated_runtime.php';

with_simulated_runtime(function (Freebsd_simulated_runtime $runtime) {
    $running = $runtime->dispatch(['action' => 'inspect-panel-worker']);
    echo implode('|', [
        $running['status'],
        $running['panel_jail_running'] ? 'panel-up' : 'panel-down',
        $running['worker_service_active'] ? 'service-up' : 'service-down',
        implode(',', $running['worker_process_samples']),
        hash_equals(hash('sha256', 'worker source'), $running['worker_source_sha256']) ? 'source-hash-ok' : 'source-hash-bad',
    ]), "\n";

    $runtime->mock_worker_service_running = false;
    $runtime->mock_worker_pids = [];
    $runtime->mock_other_php_processes = 1;
    $stopped = $runtime->dispatch(['action' => 'inspect-panel-worker']);
    echo $stopped['worker_service_active'] ? 'service-up' : 'service-down', '|';
    echo implode(',', $stopped['worker_process_samples']), "\n";

    $runtime->mock_panel_jail_running = false;
    $absent = $runtime->dispatch(['action' => 'inspect-panel-worker']);
    echo $absent['panel_jail_running'] ? 'panel-up' : 'panel-down', '|';
    echo $absent['worker_source_sha256'] === null ? 'no-source' : 'source-present', "\n";
});
?>
--EXPECT--
succeeded|panel-up|service-up|1,1,1|source-hash-ok
service-down|0,0,0
panel-down|no-source
