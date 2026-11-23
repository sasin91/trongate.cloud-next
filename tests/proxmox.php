<?php
require_once __DIR__ . '/model_fixture.php';
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/proxmox_http_fixture.php';
require_once dirname(__DIR__) . '/bootstrap.php';

class Proxmox_fixture extends Proxmox_http_fixture {
    public array $paths = [];

    function __construct(private array $responses) {
    }

    function read(string $endpoint, string $path, string $token): array {
        assert_same('https://pve.test:8006', $endpoint);
        assert_same('reader@pve!cloud=fixture-secret', $token);
        $this->paths[] = $path;
        return array_shift($this->responses);
    }
}

test('Proxmox discovery filters containers and running VMs without claiming readiness', function () {
    $http = new Proxmox_fixture([
        [['node' => 'pve', 'status' => 'online', 'mem' => 123]],
        [
            ['type' => 'qemu', 'vmid' => 100, 'template' => 1, 'name' => 'base', 'node' => 'pve'],
            ['type' => 'qemu', 'vmid' => 101, 'template' => 0],
            ['type' => 'lxc', 'vmid' => 102, 'template' => 1],
        ],
    ]);
    $result = (new Proxmox('proxmox'))->options(new Proxmox_client('https://pve.test:8006/', 'reader@pve!cloud=fixture-secret', $http));
    assert_same([100], array_column($result['templates'], 'vmid'));
    assert_same(123, $result['nodes'][0]['mem']);
    assert_same(true, $result['provisioning_supported']);
    assert_same(['/nodes', '/cluster/resources?type=vm'], $http->paths);
});

test('Proxmox rejects unsafe origins and header injection before transport', function () {
    foreach (['http://pve.test', 'https://user:secret@pve.test', 'https://pve.test/api2/json', 'https://pve.test?x=1', 'https://pve.test#fragment', "https://pve.test\n"] as $endpoint) {
        assert_throws(fn() => new Proxmox_client($endpoint, 'reader@pve!cloud=fixture-secret'), 'HTTPS origin');
    }
    assert_throws(fn() => new Proxmox_client('https://pve.test', "reader@pve!cloud=secret\r\nInjected: yes"), 'API token');
});

test('Proxmox response errors do not expose remote bodies', function () {
    $transport = new class extends Http_client {
        public int $status = 200;
        public string $body = '';
        public function request(string $method, string $url, array $headers = [], ?string $body = null, int $connect_timeout = 15, int $timeout = 45): array {
            return ['status' => $this->status, 'body' => $this->body];
        }
    };
    $client = new Proxmox_client('https://pve.test:8006', 'user@pve!token=test', $transport);
    $decode = function (int $status, string $body) use ($transport, $client): array {
        $transport->status = $status;
        $transport->body = $body;
        return $client->get('/nodes');
    };
    foreach ([301, 401, 403, 429, 500] as $status) {
        assert_throws(fn() => $decode($status, 'fixture-secret'), 'Proxmox HTTP ' . $status);
    }
    assert_throws(fn() => $decode(200, 'fixture-secret'), 'Invalid Proxmox JSON');
    foreach (['{}', '{"data":null}', '{"data":"fixture-secret"}'] as $raw) {
        assert_throws(fn() => $decode(200, $raw), 'response envelope');
    }
    assert_same([], $decode(200, '{"data":[]}'));
});

test('Proxmox task observation distinguishes completion from missing evidence', function () {
    $http = new Proxmox_fixture([
        ['status' => 'running'], ['status' => 'stopped', 'exitstatus' => 'OK'],
        ['status' => 'stopped', 'exitstatus' => 'error fixture-secret'], ['status' => 'stopped'], [],
    ]);
    $client = new Proxmox_client('https://pve.test:8006', 'reader@pve!cloud=fixture-secret', $http);
    $upid = 'UPID:pve:00000001:00000001:00000001:qmclone:100:reader@pve!cloud:';
    foreach (['pending', 'succeeded', 'failed', 'uncertain', 'uncertain'] as $status) {
        assert_same(['status' => $status, 'provider_action' => $upid, 'node' => 'pve'], (new Proxmox('proxmox'))->action($client, 'pve', $upid));
    }
    assert_contains(rawurlencode($upid), $http->paths[0]);
    assert_throws(fn() => (new Proxmox('proxmox'))->action($client, 'other', $upid), 'task identity');
});

test('Proxmox discovery is read-only and confirmed provisioning queues secret-free intent', function () {
    $dir = sys_get_temp_dir() . '/tc-proxmox-' . bin2hex(random_bytes(5));
    mkdir($dir);
    $previous = getenv('TC_STATE');
    putenv('TC_STATE=' . $dir);
    file_put_contents($dir . '/key', Encryptor::generate_key());
    try {
        $model = new Proxmox_model('proxmox', new PDO('sqlite::memory:'));
        (new Setup_model('setup', $model->db->connection()))->migrate();
        $model->secret('lab-token', 'reader@pve!cloud=fixture-secret');
        $model->module('proxmox');
        $model->proxmox->_use_http_client(new Proxmox_fixture([[], []]));
        $result = $model->proxmox->_discover(['endpoint' => 'https://pve.test:8006', 'credential' => 'lab-token']);
        assert_same([], $result['nodes']);
        assert_same([], $model->records->_all('provider'));
        assert_same(0, (int) $model->db->query_bind('SELECT COUNT(*) FROM jobs', [], 'statement')->fetchColumn());
        $plan = ['endpoint' => 'https://pve.test:8006', 'credential' => 'lab-token',
            'node' => 'pve', 'template' => 9000, 'vmid' => 201, 'name' => 'web', 'storage' => 'local-lvm'];
        $proxmox = $model->proxmox;
        assert_throws(fn() => $proxmox->_queue($plan), 'confirm');
        assert_same(0, (int) $model->db->query_bind('SELECT COUNT(*) FROM jobs', [], 'statement')->fetchColumn());
        $id = $proxmox->_queue($plan + ['confirm' => true, 'untrusted-extra' => 'discard']);
        $job = $model->job($id);
        assert_same('proxmox-provision', $job['kind']);
        assert_same(false, str_contains($job['payload'], 'fixture-secret'));
        assert_same(false, str_contains($job['payload'], 'untrusted-extra'));
        assert_same(201, json_decode($job['payload'], true)['vmid']);
        $model->records->_put(['kind' => 'proxmox-connection', 'id' => 'lab', 'data' => ['label' => 'Local Proxmox', 'endpoint' => $plan['endpoint'],
            'credential' => 'lab-token', 'node' => 'pve', 'template' => 9000, 'storage' => 'local-lvm']]);
        assert_same(false, isset($model->_connections()[0]['credential']));
        $request = str_repeat('a', 32);
        assert_throws(fn() => $model->proxmox->_create_connected('lab', 'web-two', '202', false, $request), 'confirmation');
        $created = $model->proxmox->_create_connected('lab', 'web-two', '202', true, $request);
        assert_same($created, $model->proxmox->_create_connected('lab', 'web-two', '202', true, $request));
        assert_same(2, (int) $model->db->query_bind('SELECT COUNT(*) FROM jobs', [], 'statement')->fetchColumn());
        assert_same('queued', $model->_progress($created)['phase']);
    } finally {
        unlink($dir . '/key');
        rmdir($dir);
        putenv($previous === false ? 'TC_STATE' : 'TC_STATE=' . $previous);
    }
});

finish_tests();
