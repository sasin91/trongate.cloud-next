<?php
require __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/bootstrap.php';

test('context heartbeat renews only the claimed job lease', function () {
    $jobs = new Jobs_model('jobs', new PDO('sqlite::memory:'));
    (new Setup_model('setup', $jobs->db->connection()))->migrate();
    $id = $jobs->enqueue('build', 'builder:test', []);
    $jobs->claim('fixture-owner');
    $context = new Job_context($id, 'build', [], fn() => $jobs->heartbeat($id, 'fixture-owner'));
    $jobs->db->query_bind('UPDATE jobs SET lease=0 WHERE id=?', [$id], 'statement');
    $context->heartbeat();
    assert_same(true, $jobs->job($id)['lease'] > time());
    $jobs->finish($id, 'fixture-owner', 'uncertain', []);
    assert_throws(fn() => $context->heartbeat(), 'lease lost');
});

test('worker dispatches through Modules::run using the feature module connection', function () {
    $directory = sys_get_temp_dir() . '/tc-job-context-' . bin2hex(random_bytes(6));
    mkdir($directory);
    $environment = [];
    foreach (['TC_STATE', 'TC_DSN', 'TC_KEY_FILE'] as $name) {
        $environment[$name] = getenv($name);
    }
    putenv('TC_STATE=' . $directory);
    putenv('TC_DSN=sqlite:' . $directory . '/db');
    putenv('TC_KEY_FILE=' . $directory . '/key');
    file_put_contents($directory . '/key', Encryptor::generate_key());
    $cwd = getcwd();
    try {
        $jobs = new Jobs_model('jobs');
        (new Setup_model('setup', $jobs->db->connection()))->migrate();
        $proxmox = new Proxmox('proxmox');
        assert_same(false, $jobs->db->connection() === $proxmox->model->db->connection());
        $proxmox->model->secret('lab-token', 'reader@pve!cloud=fixture-secret');
        // An old request without SSH credentials stops before any network mutation.
        $id = $jobs->enqueue('proxmox-provision', 'proxmox:fixture', [
            'endpoint' => 'https://pve.test:8006', 'credential' => 'lab-token',
        ]);
        chdir(sys_get_temp_dir());
        $worker = new Worker($jobs);
        assert_same(true, $worker->once());
        $job = $jobs->job($id);
        assert_same('uncertain', $job['status']);
        assert_contains('no SSH enrollment credentials', json_decode($job['result'], true)['reason']);
        assert_same(false, str_contains($job['result'], 'fixture-secret'));
        assert_same(false, $worker->once());
        unset($worker, $jobs, $proxmox);
        gc_collect_cycles();
    } finally {
        chdir($cwd);
        foreach ($environment as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
        foreach (glob($directory . '/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
});

finish_tests();
