--TEST--
Native Db binding, SQLite lock release, nested module transactions, GitHub pinning, and Records CRUD
--FILE--
<?php
require dirname(__DIR__, 2) . '/bootstrap.php';

// 1. Native database binding preserves values and returns affected rows.
$model = new Model('servers', new PDO('sqlite::memory:'));
$model->db->query('CREATE TABLE binding_test (id INTEGER PRIMARY KEY, label TEXT, enabled INTEGER, optional TEXT)');
$stmt = $model->db->query_bind('INSERT INTO binding_test VALUES(?,?,?,?)', [1, "quote's value", true, null], 'statement');
echo "rows=", $stmt->rowCount(), "\n";
$rows = $model->db->query_bind('SELECT * FROM binding_test WHERE id=:id AND enabled=:enabled', ['id' => 1, ':enabled' => true], 'array');
var_dump($rows === [['id' => 1, 'label' => "quote's value", 'enabled' => 1, 'optional' => null]]);

// 2. Discarded statements release SQLite read locks.
$file = tempnam(sys_get_temp_dir(), 'tc-db-');
try {
    $first = new Model('servers', new PDO('sqlite:' . $file));
    $second = new PDO('sqlite:' . $file);
    $second->setAttribute(PDO::ATTR_TIMEOUT, 0);
    $first->db->query('CREATE TABLE locks (id INTEGER)');
    $first->db->query('INSERT INTO locks VALUES(1),(2)');
    echo "lock_col=", $first->db->query_bind('SELECT id FROM locks', [], 'statement')->fetchColumn(), "\n";
    echo "lock_insert=", $second->exec('INSERT INTO locks VALUES(3)'), "\n";
} finally {
    unset($first, $second);
    unlink($file);
}

// 3. Native model delegation keeps injected transaction connection.
$cwd = getcwd();
chdir(dirname(__DIR__, 2) . '/public');
try {
    $pdo = new PDO('sqlite::memory:');
    $sm = new Servers_model('servers', $pdo);
    (new Setup_model('setup', $pdo))->migrate();
    var_dump($pdo === $sm->db->connection());
    $pdo->beginTransaction();
    $sm->records->_put(['kind' => 'server', 'id' => 'freebsd-1', 'data' => ['host' => '10.99.0.1', 'application_ready' => true]]);
    echo "tx_host=", $sm->records->_get(['kind' => 'server', 'id' => 'freebsd-1'])['host'], "\n";
    $pdo->rollBack();
    var_dump($sm->records->_find(['kind' => 'server', 'id' => 'freebsd-1']));

    // 4. Trongate loads Servers and Apps controllers internally.
    $owner = new Model('jobs');
    $owner->module('servers');
    $owner->module('apps');
    var_dump($owner->servers instanceof Trongate, $owner->servers instanceof Servers, $owner->apps instanceof Apps);
    echo "repo=", Apps::_repository('https://github.com/owner/repo.git'), "\n";
} finally {
    chdir($cwd);
}

// 5. CLI module loading shares one transaction across nested modules outside public.
chdir(sys_get_temp_dir());
try {
    $pdo = new PDO('sqlite::memory:');
    $owner = new Jobs('jobs');
    $owner->_share_database(static fn() => $pdo);
    $owner->model->module('setup');
    $owner->model->setup->model->migrate();
    $owner->model->module('apps');
    $apps = $owner->model->apps->model;
    var_dump($pdo === $apps->db->connection());
    $pdo->beginTransaction();
    $job = $apps->enqueue('deploy', 'server:fixture', []);
    $apps->module('records');
    $apps->records->_insert(['kind' => 'app', 'id' => 'fixture', 'data' => ['server' => 'fixture']]);
    var_dump($apps->records->_get(['kind' => 'app', 'id' => 'fixture']) === ['server' => 'fixture']);
    var_dump($owner->model->job($job)['id'] === $job);
    $pdo->rollBack();
    echo "after_rollback_jobs=", (int) $owner->model->db->query('SELECT COUNT(*) FROM jobs', 'column'), "\n";
    var_dump($apps->records->_find(['kind' => 'app', 'id' => 'fixture']));
    $ref = WeakReference::create($owner->model);
    unset($apps, $owner);
    var_dump($ref->get());
} finally {
    chdir($cwd);
}

// 6. Stateless controller helpers run without opening a database.
$stateless = new Apps('apps');
$stateless->_share_database(static function (): PDO {
    throw new LogicException('Stateless helpers must not open a database');
});
echo "stateless=", Apps::_repository('https://github.com/owner/repo'), "\n";
var_dump(str_contains(Trongate_config::config_php('demo.example.com'), 'BASE_URL'));

// 7. GitHub resolution happens before persistence and retries reuse pinned build.
$dir = sys_get_temp_dir() . '/tc-mod-' . bin2hex(random_bytes(5));
mkdir($dir);
putenv('TC_STATE=' . $dir);
file_put_contents($dir . '/key', Encryptor::generate_key());
try {
    $pdo = new PDO('sqlite::memory:');
    (new Setup_model('setup', $pdo))->migrate();
    $apps = new class('apps') extends Apps {
        public static int $requests = 0;

        function _github(string $path): array {
            self::$requests++;
            if (str_ends_with($path, '/pulls')) {
                return [];
            }
            return [
                'sha' => str_repeat('c', 40),
                'commit' => [
                    'message' => 'Pinned source',
                    'author' => ['name' => 'Fixture', 'date' => '2026-11-22T00:00:00Z'],
                ],
            ];
        }
    };
    $apps->_share_database(static fn() => $pdo);
    $apps->records->_put(['kind' => 'server', 'id' => 'web', 'data' => ['application_ready' => true, 'local_builder_ready' => true]]);
    $input = [
        'label' => 'source-app',
        'server' => 'web',
        'repository' => 'owner/repository',
        'branch' => 'main',
        'domain' => 'source.test',
        'php_version' => '8.4',
        'database' => '1',
    ];
    $op = $apps->_plan_launch($input, 'source-request');
    $payload = json_decode($apps->model->job($op)['payload'], true);
    echo "commit_ok=", ($payload['plan']['commit'] === str_repeat('c', 40) ? 'yes' : 'no'), "\n";
    echo "msg=", $payload['launch']['metadata']['message'], "\n";
    echo "gh_calls_1=", $apps::$requests, "\n";
    echo "retry_same=", ($op === $apps->_plan_launch($input, 'source-request') ? 'yes' : 'no'), "\n";
    echo "gh_calls_2=", $apps::$requests, "\n";
} finally {
    if (is_file($dir . '/key')) {
        unlink($dir . '/key');
    }
    if (is_dir($dir)) {
        rmdir($dir);
    }
}

// 8. Records actions dispatch internally and preserve insert uniqueness and JSON.
$recFile = tempnam(sys_get_temp_dir(), 'records-');
putenv('TC_DSN=sqlite:' . $recFile);
try {
    (new Setup('setup'))->model->migrate();
    $key = ['kind' => 'app', 'id' => 'example'];
    $data = ['url' => 'https://example.com/app', 'label' => 'Example', 'enabled' => true];
    var_dump(Modules::run('records/_find', $key));
    Modules::run('records/_insert', $key + ['data' => $data]);
    var_dump(Modules::run('records/_get', $key) === $data);
    var_dump(Modules::run('records/_all', 'app') === [['id' => 'example'] + $data]);
    try {
        Modules::run('records/_insert', $key + ['data' => []]);
        echo "FAIL duplicate accepted\n";
    } catch (PDOException $e) {
        echo "dup_rejected=", (str_contains($e->getMessage(), 'UNIQUE') ? 'yes' : 'no'), "\n";
    }
    try {
        Modules::run('records/_put', $key + ['data' => ['invalid' => "\xB1"]]);
        echo "FAIL invalid utf8 accepted\n";
    } catch (JsonException $e) {
        echo "utf8_err=", ($e->getCode() === JSON_ERROR_UTF8 ? 'yes' : 'no'), "\n";
    }
    Modules::run('records/_put', $key + ['data' => ['label' => 'Updated']]);
    echo "updated=", Modules::run('records/_get', $key)['label'], "\n";
    Modules::run('records/_delete', $key);
    var_dump(Modules::run('records/_find', $key));
    try {
        Modules::run('records/_get', $key);
        echo "FAIL missing returned\n";
    } catch (RuntimeException $e) {
        echo "missing=", $e->getMessage(), "\n";
    }
} finally {
    unlink($recFile);
}
?>
--EXPECT--
rows=1
bool(true)
lock_col=1
lock_insert=1
bool(true)
tx_host=10.99.0.1
NULL
bool(true)
bool(true)
bool(true)
repo=owner/repo
bool(true)
bool(true)
bool(true)
after_rollback_jobs=0
NULL
NULL
stateless=owner/repo
bool(true)
commit_ok=yes
msg=Pinned source
gh_calls_1=2
retry_same=yes
gh_calls_2=2
NULL
bool(true)
bool(true)
dup_rejected=yes
utf8_err=yes
updated=Updated
NULL
missing=Missing app record
