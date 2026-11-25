--TEST--
Runtime inspect-host reports protocol v2 FreeBSD capabilities and missing prerequisites
--FILE--
<?php
require __DIR__ . '/inc/simulated_runtime.php';

with_simulated_runtime(function (Freebsd_simulated_runtime $runtime) {
    $ok = $runtime->dispatch(['action' => 'inspect-host']);
    echo implode('|', [
        $ok['status'],
        $ok['protocol'],
        $ok['os'],
        $ok['arch'],
        $ok['freebsd_version'],
        $ok['zfs_pool'],
        implode(',', $ok['php_versions']),
        $ok['application_ready'] ? 'ready' : 'not-ready',
        $ok['local_builder_ready'] ? 'builder-ok' : 'builder-no',
        count($ok['missing']),
    ]), "\n";
    var_dump(in_array('imagick', $ok['optional_extensions'], true));

    $runtime->mock_os = 'Linux';
    $runtime->mock_rctl_enabled = false;
    $runtime->mock_pf_enabled = false;
    $runtime->mock_builder_ready = false;
    $runtime->existing_paths[$runtime->valkey_socket] = false;
    $bad = $runtime->dispatch(['action' => 'inspect-host']);
    echo ($bad['application_ready'] ? 'ready' : 'unready'), '|', ($bad['local_builder_ready'] ? 'b-ok' : 'b-no'), '|', implode(',', $bad['missing']), "\n";
});
?>
--EXPECT--
succeeded|2|freebsd|amd64|15.1-RELEASE-p7|zroot|8.4|ready|builder-ok|0
bool(true)
unready|b-no|os,rctl,pf,valkey
