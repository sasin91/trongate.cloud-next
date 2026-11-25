--TEST--
Job_context renews only the claimed lease and Worker dispatches via Modules::run without leaking secrets
--FILE--
<?php
require dirname(__DIR__, 3) . '/bootstrap.php';

// 1. Heartbeat renews only the claimed job lease.
$jobs = new Jobs_model('jobs', new PDO('sqlite::memory:'));
(new Setup_model('setup', $jobs->db->connection()))->migrate();
$id = $jobs->enqueue('build', 'builder:test', []);
$jobs->claim('fixture-owner');
$context = new Job_context($id, 'build', [], fn() => $jobs->heartbeat($id, 'fixture-owner'));
$jobs->db->query_bind('UPDATE jobs SET lease=0 WHERE id=?', [$id], 'statement');
$context->heartbeat();
var_dump($jobs->job($id)['lease'] > time());
$jobs->finish($id, 'fixture-owner', 'uncertain', []);
try {
    $context->heartbeat();
    echo "FAIL renewed finished lease\n";
} catch (RuntimeException $e) {
    echo "lost=", $e->getMessage(), "\n";
}

// 2. Worker dispatches through Modules::run with separate feature connection and zero secret leak.
$directory = sys_get_temp_dir() . '/tc-job-context-' . bin2hex(random_bytes(6));
mkdir($directory);
putenv('TC_STATE=' . $directory);
putenv('TC_DSN=sqlite:' . $directory . '/db');
putenv('TC_KEY_FILE=' . $directory . '/key');
file_put_contents($directory . '/key', Encryptor::generate_key());
$cwd = getcwd();
try {
    $jobs2 = new Jobs_model('jobs');
    (new Setup_model('setup', $jobs2->db->connection()))->migrate();
    $deployments = new Deployments('deployments');
    var_dump($jobs2->db->connection() !== $deployments->model->db->connection());
    $deployments->model->secret('ssh-fixture', 'private-ed25519-fixture-secret');
    $deployments->records->_put([
        'kind' => 'server',
        'id' => 'freebsd-unpinned',
        'data' => [
            'host' => '192.0.2.50',
            'port' => 22,
            'user' => 'tc-agent',
            'credential' => 'ssh-fixture',
        ],
    ]);
    $jobId = $jobs2->enqueue('destroy-app', 'server:freebsd-unpinned', [
        'app' => 'demo',
        'server' => 'freebsd-unpinned',
        'purge_data' => false,
    ]);
    chdir(sys_get_temp_dir());
    $worker = new Worker($jobs2);
    var_dump($worker->once());
    $job = $jobs2->job($jobId);
    echo "status=", $job['status'], " error=", json_decode($job['result'], true)['error'], "\n";
    var_dump(!str_contains($job['result'], 'fixture-secret'));
    var_dump($worker->once());
    unset($worker, $jobs2, $deployments);
    gc_collect_cycles();
} finally {
    chdir($cwd);
    foreach (glob($directory . '/*') as $f) {
        unlink($f);
    }
    rmdir($directory);
}
?>
--EXPECT--
bool(true)
lost=Worker lease lost
bool(true)
bool(true)
status=uncertain error=RuntimeException
bool(true)
bool(false)
