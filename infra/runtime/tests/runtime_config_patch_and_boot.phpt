--TEST--
Runtime patches repo config.php preserving custom defines and boot starts only active app jails
--FILE--
<?php
require __DIR__ . '/inc/simulated_runtime.php';

// 1. Stage patches only BASE_URL and ENV in repo config/config.php while keeping custom defines.
with_simulated_runtime(function (Freebsd_simulated_runtime $runtime) {
    $op = sprintf('%032x', 20);
    $releaseDir = $runtime->releases_root . '/demo/releases/' . $op;
    Runtime::directory($releaseDir . '/config', 0755);
    file_put_contents($releaseDir . '/config/config.php', <<<'PHP'
<?php
// User config
define('BASE_URL', 'http://localhost/demo/');
define('ENV', 'dev');
define('DEFAULT_MODULE', 'store');
define('SITE_NAME', 'Acme Shop');
PHP);

    $req = runtime_request(20);
    $req['file_values'] = [];
    $res = $runtime->dispatch($req);
    echo "stage=", $res['status'], "\n";
    echo file_get_contents($runtime->root . '/apps/demo/releases/' . $op . '/config/config.php'), "\n";
    $db_php = (string) file_get_contents($runtime->root . '/apps/demo/releases/' . $op . '/config/database.php');
    echo 'db_v2=', (str_contains($db_php, "\$databases = [") && str_contains($db_php, "\$GLOBALS['databases'] = \$databases;") ? 'yes' : 'no'),
        '|db_v1=', (str_contains($db_php, "define('HOST', 'localhost');") ? 'yes' : 'no'), "\n";
});

// 2. boot starts only active app jails and restores pf table entries.
with_simulated_runtime(function (Freebsd_simulated_runtime $runtime) {
    $runtime->dispatch(runtime_request(1));
    $r2 = $runtime->dispatch(runtime_request(2));
    echo "active_slot=", $r2['candidate']['slot'], "\n";

    $runtime->running_jails = [];
    $runtime->commands = [];

    $boot = $runtime->dispatch(['action' => 'boot']);
    echo "boot=", $boot['status'], '|started=', implode(',', $boot['started']),
        '|green=', (isset($runtime->running_jails['tc_demo_green']) ? 'up' : 'down'),
        '|blue=', (isset($runtime->running_jails['tc_demo_blue']) ? 'up' : 'down'),
        '|pf=', (in_array(['pfctl', '-t', 'tc_jails', '-T', 'add', $r2['candidate']['ip']], $runtime->commands, true) ? 'yes' : 'no'), "\n";
});
?>
--EXPECT--
stage=succeeded
<?php
// User config
define('BASE_URL', 'https://demo.test/');
define('ENV', 'production');
define('DEFAULT_MODULE', 'store');
define('SITE_NAME', 'Acme Shop');
db_v2=yes|db_v1=yes
active_slot=green
boot=succeeded|started=demo|green=up|blue=down|pf=yes
