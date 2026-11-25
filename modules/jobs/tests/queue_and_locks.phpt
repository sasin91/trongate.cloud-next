--TEST--
Jobs queue claims atomically, fences conflicts, recovers expired leases, and blocks legacy actions
--FILE--
<?php
require dirname(__DIR__, 3) . '/tests/inc/model_fixture.php';

function make_db(): Test_model {
    $s = new Test_model('jobs', new PDO('sqlite::memory:'));
    (new Setup_model('setup', $s->db->connection()))->migrate();
    return $s;
}

// 1. Atomic claim, conflict fencing, expired claim recovery, uncertain lock, retry.
$s = make_db();
$a = $s->enqueue('deploy', 'server:a', []);
$b = $s->enqueue('deploy', 'server:a', []);
$claimed = $s->claim('worker-a');
var_dump($claimed['id'] === $a, $s->claim('worker-b') === null);
$s->db->query_bind('UPDATE jobs SET lease=0 WHERE id=?', [$a], 'statement');
var_dump($s->claim('worker-b')['id'] === $a);
try {
    $s->finish($a, 'worker-a', 'succeeded', []);
    echo "FAIL stale worker finished\n";
} catch (RuntimeException $e) {
    echo "stale=", $e->getMessage(), "\n";
}
$s->finish($a, 'worker-b', 'uncertain', []);
var_dump($s->claim('worker-c') === null);
$s->_retry($a);
var_dump($s->claim('worker-c')['id'] === $a);
$s->finish($a, 'worker-c', 'succeeded', []);
var_dump($s->claim('worker-d')['id'] === $b);

// 2. Parallel resources progress independently and pending keeps its lock.
$s2 = make_db();
$s2->enqueue('build', 'builder:a', []);
$s2->enqueue('deploy', 'server:a', []);
$j = $s2->claim('one');
$k = $s2->claim('two');
var_dump($j['resource'] !== $k['resource']);
$s2->finish($j['id'], 'one', 'pending', []);
var_dump($s2->claim('three')['id'] === $j['id']);

// 3. Unsupported legacy job kind cannot execute feature actions.
$s3 = make_db();
$id = $s3->enqueue('provision', 'legacy-provider', []);
(new Worker($s3))->once();
echo "legacy=", $s3->job($id)['status'], "\n";
var_dump($s3->all('external') === []);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
stale=Worker lease lost
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
legacy=uncertain
bool(true)
