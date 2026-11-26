--TEST--
Deployments_model canary queueing guards and timeline stages
--FILE--
<?php
require dirname(__DIR__, 3) . '/tests/inc/model_fixture.php';

$dir = sys_get_temp_dir() . '/tc-canary-' . bin2hex(random_bytes(6));
mkdir($dir);
putenv('TC_STATE=' . $dir);
putenv('TC_DSN=sqlite:' . $dir . '/db');
file_put_contents($dir . '/key', Encryptor::generate_key());

function canary_assert(bool $ok, string $msg): void {
    if (!$ok) {
        throw new RuntimeException($msg);
    }
}

try {
    $s = new Test_model('jobs');
    (new Setup_model('setup', $s->db->connection()))->migrate();
    $s->put('server', 'tc-host1', ['application_ready' => true, 'local_builder_ready' => true, 'host' => '10.99.0.1']);
    $s->put('app', 'demo', ['server' => 'tc-host1', 'domain' => 'demo.trongate.dev']);
    $s->put('release', 'rel-1', ['app' => 'demo', 'spec' => ['domain' => 'demo.trongate.dev']]);

    $deployments = new Deployments_model('deployments', $s->db->connection());

    // Cannot promote/abort/adjust weight without an active canary.
    $blocked = false;
    try {
        $deployments->_queue('canary-promote', ['app' => 'demo']);
    } catch (RuntimeException) {
        $blocked = true;
    }
    canary_assert($blocked, 'Expected canary-promote without active canary to throw');

    // Queueing a canary deploy works when no canary is active.
    $op = $deployments->_queue('deploy', [
        'app' => 'demo',
        'release' => 'rel-1',
        'canary' => true,
        'canary_weight' => 25,
    ]);
    $tl = $deployments->_timeline($op);
    canary_assert($tl !== null && $tl['stages'][3]['name'] === 'Route canary', 'Expected Route canary stage');

    // Mark app as having an active canary.
    $s->put('app', 'demo', [
        'server' => 'tc-host1',
        'domain' => 'demo.trongate.dev',
        'canary' => ['slot' => 'green', 'weight' => 25, 'operation' => $op, 'commit' => 'abcdef1'],
    ]);

    // Normal deploy and rollback are blocked while canary is active.
    $deploy_blocked = false;
    try {
        $deployments->_queue('deploy', ['app' => 'demo', 'release' => 'rel-1']);
    } catch (RuntimeException) {
        $deploy_blocked = true;
    }
    canary_assert($deploy_blocked, 'Expected deploy while canary active to throw');

    $rollback_blocked = false;
    try {
        $deployments->_queue('rollback', ['app' => 'demo']);
    } catch (RuntimeException) {
        $rollback_blocked = true;
    }
    canary_assert($rollback_blocked, 'Expected rollback while canary active to throw');

    // Weight update, promote, and abort queue cleanly when canary is active.
    $w_op = $deployments->_queue('canary-weight', ['app' => 'demo', 'weight' => 50]);
    $p_op = $deployments->_queue('canary-promote', ['app' => 'demo']);
    $a_op = $deployments->_queue('canary-abort', ['app' => 'demo']);

    $w_tl = $deployments->_timeline($w_op);
    $p_tl = $deployments->_timeline($p_op);
    $a_tl = $deployments->_timeline($a_op);
    canary_assert($w_tl !== null && $w_tl['canary']['weight'] === 25, 'Timeline missing active canary state');
    canary_assert($p_tl !== null && $p_tl['stages'][3]['name'] === 'Go live', 'Promote stage mismatch');
    canary_assert($a_tl !== null && $a_tl['stages'][3]['name'] === 'Restore stable', 'Abort stage mismatch');
    canary_assert(
        Deployments_model::_phase_label('routing-canary') === 'Routing canary traffic.'
            && Deployments_model::_phase_label('canary-active') === 'Canary is live alongside the stable release.'
            && Deployments_model::_phase_label('canary-aborted') === 'Canary aborted; 100% traffic restored to stable release.',
        'Phase labels mismatch'
    );

    echo "OK\n";
} finally {
    $deployments = null;
    $s = null;
    gc_collect_cycles();
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($dir);
}
?>
--EXPECT--
OK
