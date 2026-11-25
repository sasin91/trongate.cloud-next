--TEST--
Setup, monitoring, and worker CLI entrypoints reject bad args and initialize cleanly
--FILE--
<?php
$root = dirname(__DIR__, 3);
require $root . '/bootstrap.php';

$process = new Process;
$badEnv = ['TC_DSN' => 'unsupported:must-not-open'];
foreach (['setup/init.php', 'setup/migrate.php', 'monitoring/collect.php'] as $script) {
    $result = $process->run([PHP_BINARY, $root . '/modules/' . $script, 'unexpected'], '{}', 10, null, $badEnv);
    echo $script, ' exit=', $result['exit'], ' usage=', (str_contains($result['err'], 'Usage: php modules/' . $script) ? 'yes' : 'no'), "\n";
}

$directory = sys_get_temp_dir() . '/tc-cli-' . bin2hex(random_bytes(6));
mkdir($directory);
$env = ['TC_STATE' => $directory, 'TC_KEY_FILE' => $directory . '/key', 'TC_DSN' => 'sqlite:' . $directory . '/db'];
try {
    $result = $process->run([PHP_BINARY, $root . '/modules/setup/init.php'], json_encode(['name' => 'admin', 'password' => 'disposable-test-password']), 15, null, $env);
    echo "init exit=", $result['exit'], " no_leak=", (!str_contains($result['out'], 'disposable-test-password') ? 'yes' : 'no'), "\n";
    $key = file_get_contents($directory . '/key');
    foreach (['setup/migrate.php', 'monitoring/collect.php'] as $script) {
        $r = $process->run([PHP_BINARY, $root . '/modules/' . $script], '', 15, null, $env);
        echo $script, " exit=", $r['exit'], "\n";
    }
    $w = $process->run([PHP_BINARY, $root . '/modules/jobs/worker.php', '--once'], '', 15, null, $env);
    echo "worker exit=", $w['exit'], " key_same=", ($key === file_get_contents($directory . '/key') ? 'yes' : 'no'), "\n";
    $db = new PDO('sqlite:' . $directory . '/db');
    $hash = $db->query("SELECT password FROM trongate_administrators WHERE username='admin'")->fetchColumn();
    var_dump(password_verify('disposable-test-password', $hash));
    unset($db);
} finally {
    foreach (glob($directory . '/*') as $file) {
        unlink($file);
    }
    rmdir($directory);
}
?>
--EXPECT--
setup/init.php exit=1 usage=yes
setup/migrate.php exit=1 usage=yes
monitoring/collect.php exit=1 usage=yes
init exit=0 no_leak=yes
setup/migrate.php exit=0
monitoring/collect.php exit=0
worker exit=0 key_same=yes
bool(true)
