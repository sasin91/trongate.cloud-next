<?php
require __DIR__ . '/bootstrap.php';
require dirname(__DIR__) . '/bootstrap.php';

test('native database binding preserves values and returns affected rows', function () {
    $model = new Model('proxmox', new PDO('sqlite::memory:'));
    $model->db->query('CREATE TABLE binding_test (id INTEGER PRIMARY KEY, label TEXT, enabled INTEGER, optional TEXT)');
    $statement = $model->db->query_bind('INSERT INTO binding_test VALUES(?,?,?,?)', [1, "quote's value", true, null], 'statement');
    assert_same(1, $statement->rowCount());
    assert_same([['id' => 1, 'label' => "quote's value", 'enabled' => 1, 'optional' => null]],
        $model->db->query_bind('SELECT * FROM binding_test WHERE id=:id AND enabled=:enabled', ['id' => 1, ':enabled' => true], 'array'));
});

test('discarded database statements release SQLite read locks', function () {
    $file = tempnam(sys_get_temp_dir(), 'tc-db-');
    try {
        $first = new Model('proxmox', new PDO('sqlite:' . $file));
        $second = new PDO('sqlite:' . $file);
        $second->setAttribute(PDO::ATTR_TIMEOUT, 0);
        $first->db->query('CREATE TABLE locks (id INTEGER)');
        $first->db->query('INSERT INTO locks VALUES(1),(2)');
        assert_same(1, $first->db->query_bind('SELECT id FROM locks', [], 'statement')->fetchColumn());
        // A retained cursor here would block this independent connection immediately.
        assert_same(1, $second->exec('INSERT INTO locks VALUES(3)'));
    } finally {
        unset($first, $second);
        unlink($file);
    }
});

test('native model delegation keeps the injected transaction connection', function () {
    $cwd = getcwd();
    chdir(dirname(__DIR__) . '/public');
    try {
        $pdo = new PDO('sqlite::memory:');
        $model = new Model('proxmox', $pdo);
        (new Setup_model('setup', $pdo))->migrate();
        assert_same($pdo, $model->db->connection());
        $pdo->beginTransaction();
        $model->db->query_bind('INSERT INTO records VALUES(?,?,?)',
            ['proxmox-connection', 'local', json_encode(['label' => 'Local Proxmox', 'credential' => 'private-reference'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]);
        assert_same([['id' => 'local', 'label' => 'Local Proxmox']], $model->_connections());
        $pdo->rollBack();
        assert_same([], $model->_connections());
    } finally {
        chdir($cwd);
    }
});

test('Trongate loads the Proxmox controller and its model internally', function () {
    $cwd = getcwd();
    chdir(dirname(__DIR__) . '/public');
    try {
        $owner = new Model('jobs');
        $owner->module('proxmox');
        assert_same(true, $owner->proxmox instanceof Trongate);
        assert_same(true, $owner->proxmox instanceof Proxmox);
        assert_same(true, str_starts_with($owner->proxmox->ssh_key()['public'], 'ssh-ed25519 '));
    } finally {
        chdir($cwd);
    }
});

test('CLI module loading shares one transaction across nested modules outside public', function () {
    $cwd = getcwd();
    chdir(sys_get_temp_dir());
    try {
        $pdo = new PDO('sqlite::memory:');
        $owner = new Jobs('jobs');
        $owner->_share_database(static fn() => $pdo);
        $owner->model->module('setup');
        $owner->model->setup->model->migrate();
        $owner->model->module('apps');
        $apps = $owner->model->apps->model;
        assert_same($pdo, $apps->db->connection());
        $pdo->beginTransaction();
        $job = $apps->enqueue('deploy', 'server:fixture', []);
        $apps->module('records');
        $apps->records->_insert(['kind' => 'app', 'id' => 'fixture', 'data' => ['server' => 'fixture']]);
        assert_same(['server' => 'fixture'], $apps->records->_get(['kind' => 'app', 'id' => 'fixture']));
        assert_same($job, $owner->model->job($job)['id']);
        $pdo->rollBack();
        assert_same(0, (int) $owner->model->db->query('SELECT COUNT(*) FROM jobs', 'column'));
        assert_same(null, $apps->records->_find(['kind' => 'app', 'id' => 'fixture']));
        $reference = WeakReference::create($owner->model);
        unset($apps, $owner);
        assert_same(null, $reference->get());
    } finally {
        chdir($cwd);
    }
});

test('provider actions load without opening an application database', function () {
    $owner = new Proxmox('proxmox');
    $owner->_share_database(static function (): PDO {
        throw new LogicException('Provider actions must not open a database');
    });
    $owner->model->module('proxmox');
    assert_same(true, str_starts_with($owner->model->proxmox->ssh_key()['public'], 'ssh-ed25519 '));
});

test('provider discovery keeps client credentials isolated', function () {
    $transport = new class extends Http_client {
        public array $tokens = [];
        public function request(string $method, string $url, array $headers = [], ?string $body = null, int $connect_timeout = 15, int $timeout = 45): array {
            $this->tokens[] = substr($headers['Authorization'], strlen('PVEAPIToken='));
            return ['status' => 200, 'body' => '{"data":[]}'];
        }
    };
    $module = new Proxmox('proxmox');
    $first = new Proxmox_client('https://first.test', 'user@pve!first=first-secret', $transport);
    $second = new Proxmox_client('https://second.test', 'user@pve!second=second-secret', $transport);
    $module->options($first);
    $module->options($second);
    assert_same(['user@pve!first=first-secret', 'user@pve!first=first-secret',
        'user@pve!second=second-secret', 'user@pve!second=second-secret'], $transport->tokens);
});

test('GitHub resolution happens before persistence and retries reuse the pinned build', function () {
    $pdo = new PDO('sqlite::memory:');
    (new Setup_model('setup', $pdo))->migrate();
    $apps = new class('apps') extends Apps {
        public static int $requests = 0;
        public function _github(string $path): array {
            self::$requests++;
            if (str_ends_with($path, '/pulls')) {
                return [];
            }
            return ['sha' => str_repeat('c', 40), 'commit' => ['message' => 'Pinned source',
                'author' => ['name' => 'Fixture', 'date' => '2026-11-22T00:00:00Z']]];
        }
    };
    $apps->_share_database(static fn() => $pdo);
    $apps->records->_put(['kind' => 'server', 'id' => 'web', 'data' => ['application_ready' => true, 'local_builder_ready' => true]]);
    $input = ['source' => 'git', 'label' => 'source-app', 'server' => 'web',
        'repository' => 'owner/repository', 'branch' => 'main', 'domain' => 'source.test'];
    $operation = $apps->_plan_launch($input, 'source-request');
    $payload = json_decode($apps->model->job($operation)['payload'], true);
    assert_same(str_repeat('c', 40), $payload['plan']['commit']);
    assert_same('Pinned source', $payload['launch']['metadata']['message']);
    assert_same(2, $apps::$requests);
    assert_same($operation, $apps->_plan_launch($input, 'source-request'));
    assert_same(2, $apps::$requests);
});

test('Records actions dispatch internally and preserve insert uniqueness and JSON', function () {
    $file = tempnam(sys_get_temp_dir(), 'records-');
    $previous = getenv('TC_DSN');
    putenv('TC_DSN=sqlite:' . $file);
    try {
        (new Setup('setup'))->model->migrate();
        $key = ['kind' => 'app', 'id' => 'example'];
        $data = ['url' => 'https://example.com/app', 'label' => 'Example', 'enabled' => true];
        assert_same(null, Modules::run('records/_find', $key));
        Modules::run('records/_insert', $key + ['data' => $data]);
        assert_same($data, Modules::run('records/_get', $key));
        assert_same([['id' => 'example'] + $data], Modules::run('records/_all', 'app'));
        assert_throws(fn() => Modules::run('records/_insert', $key + ['data' => []]), 'UNIQUE');
        assert_same($data, Modules::run('records/_get', $key));
        try {
            Modules::run('records/_put', $key + ['data' => ['invalid' => "\xB1"]]);
            throw new LogicException('Invalid JSON was accepted');
        } catch (JsonException $error) {
            assert_same(JSON_ERROR_UTF8, $error->getCode());
        }
        assert_same($data, Modules::run('records/_get', $key));
        Modules::run('records/_put', $key + ['data' => ['label' => 'Updated']]);
        assert_same(['label' => 'Updated'], Modules::run('records/_get', $key));
        Modules::run('records/_delete', $key);
        assert_same(null, Modules::run('records/_find', $key));
        assert_throws(fn() => Modules::run('records/_get', $key), 'Missing app record');
    } finally {
        putenv($previous === false ? 'TC_DSN' : 'TC_DSN=' . $previous);
        unlink($file);
    }
});

finish_tests();
