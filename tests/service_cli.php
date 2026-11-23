<?php
require __DIR__ . '/bootstrap.php';
require dirname(__DIR__) . '/bootstrap.php';

test('module CLI scripts reject arguments before opening the database', function () {
    $process = new Process;
    $environment = ['TC_DSN' => 'unsupported:must-not-open'];
    foreach (['setup/init.php', 'setup/migrate.php', 'monitoring/collect.php'] as $script) {
        $result = $process->run([PHP_BINARY, dirname(__DIR__) . '/modules/' . $script, 'unexpected'], '{}', 10, null, $environment);
        assert_same(1, $result['exit']);
        assert_contains('Usage: php modules/' . $script, $result['err']);
    }
});

test('service CLI initializes, migrates and runs an empty worker', function () {
    $directory = sys_get_temp_dir() . '/tc-cli-' . bin2hex(random_bytes(6));
    mkdir($directory);
    $environment = ['TC_STATE' => $directory, 'TC_KEY_FILE' => $directory . '/key', 'TC_DSN' => 'sqlite:' . $directory . '/db'];
    $process = new Process;
    try {
        $result = $process->run([PHP_BINARY, dirname(__DIR__) . '/modules/setup/init.php'], json_encode(['name' => 'admin', 'password' => 'disposable-test-password']), 15, null, $environment);
        assert_same(0, $result['exit']);
        assert_same(false, str_contains($result['out'], 'disposable-test-password'));
        $key = file_get_contents($directory . '/key');
        foreach (['setup/migrate.php', 'monitoring/collect.php'] as $script) {
            $result = $process->run([PHP_BINARY, dirname(__DIR__) . '/modules/' . $script], '', 15, null, $environment);
            assert_same(0, $result['exit']);
        }
        $result = $process->run([PHP_BINARY, dirname(__DIR__) . '/modules/jobs/worker.php', '--once'], '', 15, null, $environment);
        assert_same(0, $result['exit']);
        assert_same($key, file_get_contents($directory . '/key'));
        $db = new PDO('sqlite:' . $directory . '/db');
        $password = $db->query("SELECT password FROM trongate_administrators WHERE username='admin'")->fetchColumn();
        assert_same(true, password_verify('disposable-test-password', $password));
        unset($db);
    } finally {
        foreach (glob($directory . '/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
});

finish_tests();
