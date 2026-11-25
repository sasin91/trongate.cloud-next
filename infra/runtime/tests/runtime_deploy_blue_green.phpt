--TEST--
Runtime blue/green deploy provisions ZFS, nullfs, PHP-FPM, Valkey, MariaDB, rctl, pf, and Caddy
--FILE--
<?php
require __DIR__ . '/inc/simulated_runtime.php';

with_simulated_runtime(function (Freebsd_simulated_runtime $runtime) {
    $r1 = $runtime->dispatch(runtime_request(1));
    echo "r1=", $r1['status'], '|', $r1['phase'], '|', $r1['candidate']['slot'], '|', $r1['candidate']['jail'], '|', $r1['candidate']['database_name'], "\n";
    var_dump(str_starts_with($r1['candidate']['ip'], '10.99.'));

    // MariaDB and Valkey ACL provisioning via stdin.
    $mariadb = array_values(array_filter($runtime->inputs, fn(array $c) => $c['args'][0] === 'mariadb'));
    var_dump(
        count($mariadb) === 1,
        str_contains($mariadb[0]['input'], 'CREATE DATABASE IF NOT EXISTS `tc_demo`'),
        str_contains($mariadb[0]['input'], 'MAX_USER_CONNECTIONS 20')
    );

    $valkey = array_values(array_filter($runtime->inputs, fn(array $c) => $c['args'][0] === 'valkey-cli'));
    var_dump(
        count($valkey) === 1,
        str_contains($valkey[0]['input'], 'ACL SETUSER tc_demo on >'),
        str_contains($valkey[0]['input'], '~tc:demo:* -@all +get +set +setex +psetex'),
        str_contains($valkey[0]['input'], 'ACL SAVE')
    );

    // ZFS datasets, quota, data security props, skeleton@v1 clone.
    var_dump(
        in_array(['zfs', 'create', '-p', '-o', 'quota=1024M', 'zroot/tc/apps/demo'], $runtime->commands, true),
        in_array(['zfs', 'create', '-p', '-o', 'exec=off', '-o', 'setuid=off', 'zroot/tc/apps/demo/data'], $runtime->commands, true),
        in_array(['zfs', 'clone', '-o', 'quota=128M', 'zroot/tc/skeleton@v1', 'zroot/tc/jails/demo-blue'], $runtime->commands, true)
    );

    // Optional extensions, PHP-FPM config, database.php overlay, no secret leak.
    $jailRoot = $runtime->jails_root . '/demo-blue';
    $extIni = (string) file_get_contents($jailRoot . '/etc/tc/php.d/extensions.ini');
    $fpmConf = (string) file_get_contents($jailRoot . '/etc/tc/php-fpm.conf');
    $overlayDb = (string) file_get_contents($runtime->root . '/apps/demo/releases/' . sprintf('%032x', 1) . '/config/database.php');
    $releaseJson = (string) file_get_contents($runtime->root . '/apps/demo/releases/' . sprintf('%032x', 1) . '/release.json');
    var_dump(
        str_contains($extIni, 'extension=imagick.so'),
        str_contains($extIni, 'extension=gmp.so'),
        str_contains($fpmConf, 'pm = ondemand'),
        str_contains($fpmConf, 'env[PHP_INI_SCAN_DIR] = /usr/local/etc/php-base:/etc/tc/php.d'),
        str_contains($fpmConf, 'php_admin_value[pdo_mysql.default_socket] = /var/run/tc/mariadb/mysqld.sock'),
        str_contains($fpmConf, 'php_admin_value[session.save_handler] = redis'),
        str_contains($fpmConf, 'unix:///var/run/tc/valkey/valkey.sock?auth[user]=tc_demo'),
        str_contains($fpmConf, 'env[API_KEY] = "top-secret"'),
        str_contains($overlayDb, "define('DATABASE', 'tc_demo');"),
        !str_contains($releaseJson, 'top-secret')
    );

    // nullfs fstab, jail.conf, pfctl, rctl, Caddyfile (no on_demand).
    $fstab = (string) file_get_contents($r1['candidate']['fstab']);
    $jailConf = (string) file_get_contents($r1['candidate']['jail_conf']);
    $caddyfile = (string) file_get_contents($runtime->root . '/Caddyfile');
    var_dump(
        str_contains($fstab, '/.base nullfs ro 0 0'),
        str_contains($fstab, '/usr/local nullfs ro 0 0'),
        str_contains($fstab, '/app nullfs ro 0 0'),
        str_contains($fstab, '/app/config nullfs ro 0 0'),
        str_contains($fstab, '/app/public/uploads nullfs rw 0 0'),
        str_contains($fstab, '/var/run/tc/mariadb nullfs rw 0 0'),
        str_contains($fstab, '/var/run/tc/valkey nullfs rw 0 0'),
        str_contains($jailConf, 'ip4.addr = "lo1|' . $r1['candidate']['ip'] . '/32";'),
        str_contains($jailConf, 'exec.poststart = "/usr/bin/rctl -a jail:tc_demo_blue:memoryuse:deny=256M'),
        in_array(['pfctl', '-t', 'tc_jails', '-T', 'add', $r1['candidate']['ip']], $runtime->commands, true),
        in_array(['rctl', '-a', 'jail:tc_demo_blue:memoryuse:deny=256M'], $runtime->commands, true),
        in_array(['rctl', '-a', 'jail:tc_demo_blue:swapuse:deny=256M'], $runtime->commands, true),
        in_array(['rctl', '-a', 'jail:tc_demo_blue:pcpu:deny=100'], $runtime->commands, true),
        in_array(['rctl', '-a', 'jail:tc_demo_blue:maxproc:deny=64'], $runtime->commands, true),
        in_array(['rctl', '-a', 'jail:tc_demo_blue:openfiles:deny=1024'], $runtime->commands, true),
        !str_contains($caddyfile, 'on_demand'),
        str_contains($caddyfile, 'demo.test {'),
        str_contains($caddyfile, 'php_fastcgi unix//'),
        str_contains($caddyfile, 'file_server')
    );

    // Slot alternation blue -> green -> blue.
    $r2 = $runtime->dispatch(runtime_request(2));
    echo "r2=", $r2['status'], '|', $r2['candidate']['slot'], "\n";
    var_dump(
        in_array(['jail', '-r', 'tc_demo_blue'], $runtime->commands, true),
        in_array(['pfctl', '-t', 'tc_jails', '-T', 'delete', $r1['candidate']['ip']], $runtime->commands, true)
    );
    $r3 = $runtime->dispatch(runtime_request(3));
    echo "r3=", $r3['status'], '|', $r3['candidate']['slot'], "\n";
});
?>
--EXPECT--
r1=succeeded|accepted|blue|tc_demo_blue|tc_demo
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
r2=succeeded|green
bool(true)
bool(true)
r3=succeeded|blue
