<?php
require_once __DIR__ . '/model_fixture.php';
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/proxmox_http_fixture.php';
require_once dirname(__DIR__) . '/bootstrap.php';

class Proxmox_worker_fixture extends Proxmox_http_fixture {
    public array $mutations = [];
    public string $failAt = '';
    public bool $wrongOwner = false;
    public bool $occupied = false;
    public string $taskStatus = 'OK';
    public array $configuration = [];
    public bool $guestReady = true;

    static function fixture_public_key(): string {
        return 'ssh-ed25519 ' . base64_encode(pack('N', 11) . 'ssh-ed25519' . pack('N', 32) . str_repeat('a', 32));
    }

    function __construct(public Test_model $model, public string $operation) {
    }

    function read(string $endpoint, string $path, string $token): array {
        if ($path === '/cluster/resources?type=vm') {
            return $this->occupied ? [['vmid' => 101]] : [];
        }
        if (str_contains($path, '/storage/')) {
            return ['active' => 1, 'enabled' => 1, 'content' => 'images'];
        }
        if ($path === '/nodes/pve/qemu/100/config') {
            return ['template' => 1, 'tags' => 'tc-nixos', 'agent' => '1', 'ide2' => 'local-lvm:vm-100-cloudinit,media=cdrom', 'net0' => 'virtio=AA:BB:CC:DD:EE:FF,bridge=vmbr0'];
        }
        if ($path === '/nodes/pve/qemu/101/config') {
            return ['name' => 'web', 'description' => 'tc-operation=' . ($this->wrongOwner ? 'other' : $this->operation)] + $this->configuration;
        }
        if ($path === '/nodes/pve/qemu/101/status/current') {
            return ['status' => count($this->mutations) > 2 ? 'running' : 'stopped'];
        }
        if (str_contains($path, '/agent/file-read')) {
            if (!$this->guestReady) {
                throw new Provider_error('Proxmox HTTP 500');
            }
            return ['content' => self::fixture_public_key()];
        }
        if (str_ends_with($path, '/agent/network-get-interfaces')) {
            return ['result' => [['ip-addresses' => [['ip-address' => '192.0.2.10']]]]];
        }
        if (str_contains($path, '/tasks/')) {
            return ['status' => 'stopped', 'exitstatus' => $this->taskStatus];
        }
        throw new RuntimeException('Unexpected fixture read');
    }

    function mutate(string $endpoint, string $path, string $token, array $body): string {
        $phase = str_ends_with($path, '/clone') ? 'clone' : 'start';
        $intent = $this->model->get('external', $this->operation);
        assert_same($phase, $intent['phase']);
        assert_same(false, isset($intent['action']));
        $this->mutations[] = [$path, $body];
        if ($this->failAt === 'forbidden') {
            throw new Provider_error('Proxmox HTTP 403', 403);
        }
        if ($phase === $this->failAt) {
            throw new RuntimeException('ambiguous transport fixture-secret');
        }
        return 'UPID:pve:00000001:00000001:00000001:' . $phase . ':101:reader@pve:';
    }

    function configure(string $endpoint, string $path, string $token, array $body): void {
        assert_same('configure', $this->model->get('external', $this->operation)['phase']);
        $this->mutations[] = [$path, $body];
        $this->configuration = $body;
        if ($this->failAt === 'configure') {
            throw new RuntimeException('ambiguous configuration fixture-secret');
        }
    }
}

class Proxmox_worker_process extends Process {
    public bool $ready = true;
    function run(array $argv, string $input = '', int $timeout = 60, ?callable $heartbeat = null, array $extra_env = []): array {
        assert_same(true, in_array('StrictHostKeyChecking=yes', $argv));
        if (!$this->ready) {
            return ['exit' => 255, 'out' => '', 'err' => 'Connection refused'];
        }
        return ['exit' => 0, 'err' => '', 'out' => "os=nixos\narch=x86_64\n" . implode("\n", array_map(fn($name) => $name . '=yes', ['podman', 'systemctl', 'curl', 'php', 'flock', 'caddy', 'agent', 'privileged', 'proxy', 'podman-ready', 'cgroups', 'protocol', 'nix']))];
    }
}

function proxmox_worker_test(callable $body): void {
    $directory = sys_get_temp_dir() . '/tc-proxmox-worker-' . bin2hex(random_bytes(5));
    mkdir($directory);
    $previous = getenv('TC_STATE');
    putenv('TC_STATE=' . $directory);
    file_put_contents($directory . '/key', Encryptor::generate_key());
    try {
        $model = new Test_model('jobs', new PDO('sqlite::memory:'));
        (new Setup_model('setup', $model->db->connection()))->migrate();
        $model->secret('lab-token', 'reader@pve!cloud=fixture-secret');
        $model->secret('ssh-key', 'fixture-private-key');
        $plan = ['endpoint' => 'https://pve.test:8006', 'credential' => 'lab-token', 'node' => 'pve', 'template' => 100,
            'vmid' => 101, 'name' => 'web', 'storage' => 'local-lvm', 'confirm' => true,
            'ssh_credential' => 'ssh-key', 'ssh_public_key' => Proxmox_worker_fixture::fixture_public_key()];
        $operation = $model->enqueue('proxmox-provision', 'proxmox:lab', $plan);
        $http = new Proxmox_worker_fixture($model, $operation);
        $process = new Proxmox_worker_process;
        $jobs = new Jobs_model('jobs', $model->db->connection());
        $proxmox = new class($process) extends Proxmox {
            function __construct(private Process $fixture_process) {
                parent::__construct('proxmox');
            }
            function enroll(Model $model, string $operation, array $plan, array $identity, Process $process = new Process, ?callable $heartbeat = null): array {
                return parent::enroll($model, $operation, $plan, $identity, $this->fixture_process, $heartbeat);
            }
        };
        $proxmox->_share_database($model->_database_source());
        $proxmox->_use_http_client($http);
        $worker = new class($jobs, $proxmox) extends Worker {
            function __construct(Model $jobs, private Proxmox $fixture_proxmox) {
                parent::__construct($jobs);
            }
            function perform(Job_context $context): array {
                return $this->fixture_proxmox->provision($context);
            }
        };
        $body($model, $operation, $http, $worker, $process);
    } finally {
        unlink($directory . '/key');
        rmdir($directory);
        putenv($previous === false ? 'TC_STATE' : 'TC_STATE=' . $previous);
    }
}

test('Proxmox worker journals clone, SSH configuration and start then enrolls pinned ready VM', function () {
    proxmox_worker_test(function ($model, $id, $http, $worker) {
        $worker->once();
        assert_same('pending', $model->job($id)['status']);
        $worker->once();
        assert_same('pending', $model->job($id)['status']);
        $worker->once();
        assert_same('pending', $model->job($id)['status']);
        $worker->once();
        assert_same('succeeded', $model->job($id)['status']);
        assert_same(3, count($http->mutations));
        assert_same('tc-operation=' . $id, $http->mutations[0][1]['description']);
        assert_same(1, count($model->all('provider-server')));
        assert_same('web', $model->all('server')[0]['id']);
    });
});

foreach (['clone', 'start'] as $phase) {
    test('Proxmox ambiguous ' . $phase . ' never repeats after reconcile', function () use ($phase) {
        proxmox_worker_test(function ($model, $id, $http, $worker) use ($phase) {
            $http->failAt = $phase;
            $worker->once();
            if ($phase === 'start') {
                $worker->once();
                $worker->once();
            }
            assert_same('uncertain', $model->job($id)['status']);
            assert_same(false, str_contains($model->job($id)['result'], 'fixture-secret'));
            $count = count($http->mutations);
            assert_same($phase === 'clone' ? 1 : 3, $count);
            $model->_retry($id);
            $worker->once();
            assert_same('uncertain', $model->job($id)['status']);
            assert_same($count, count($http->mutations));
        });
    });
}

test('Proxmox rejects changed ownership before starting a VM', function () {
    proxmox_worker_test(function ($model, $id, $http, $worker) {
        $worker->once();
        $http->wrongOwner = true;
        $worker->once();
        assert_same('uncertain', $model->job($id)['status']);
        assert_same(1, count($http->mutations));
    });
});

test('Proxmox preflight rejection saves no submitted intent and can reconcile safely', function () {
    proxmox_worker_test(function ($model, $id, $http, $worker) {
        $http->occupied = true;
        $worker->once();
        assert_same('uncertain', $model->job($id)['status']);
        assert_same([], $model->all('external'));
        assert_same([], $http->mutations);
        $http->occupied = false;
        $model->_retry($id);
        $worker->once();
        assert_same('pending', $model->job($id)['status']);
        assert_same(1, count($http->mutations));
    });
});

test('Proxmox task failure is not successful provisioning', function () {
    proxmox_worker_test(function ($model, $id, $http, $worker) {
        $worker->once();
        $http->taskStatus = 'error fixture-secret';
        $worker->once();
        assert_same('failed', $model->job($id)['status']);
        assert_same(false, str_contains($model->job($id)['result'], 'fixture-secret'));
        assert_same([], $model->all('provider-server'));
    });
});

test('Proxmox configuration timeout recovers by reading observed configuration without another PUT', function () {
    proxmox_worker_test(function ($model, $id, $http, $worker) {
        $http->failAt = 'configure';
        $worker->once();
        $worker->once();
        assert_same('uncertain', $model->job($id)['status']);
        assert_same(2, count($http->mutations));
        $model->_retry($id);
        $worker->once();
        assert_same('pending', $model->job($id)['status']);
        $worker->once();
        assert_same('succeeded', $model->job($id)['status']);
        assert_same(3, count($http->mutations));
    });
});

test('Proxmox uncertain SSH configuration is never blindly repeated', function () {
    proxmox_worker_test(function ($model, $id, $http, $worker) {
        $http->failAt = 'configure';
        $worker->once();
        $worker->once();
        $http->configuration = [];
        $model->_retry($id);
        $worker->once();
        assert_same('uncertain', $model->job($id)['status']);
        assert_same(2, count($http->mutations));
    });
});

test('Proxmox waits for guest agent and SSH readiness without another configuration or start', function () {
    proxmox_worker_test(function ($model, $id, $http, $worker, $process) {
        $http->guestReady = false;
        $process->ready = false;
        for ($i = 0; $i < 4; $i++) {
            $worker->once();
        }
        assert_same('pending', $model->job($id)['status']);
        assert_same('enroll', $model->get('external', $id)['phase']);
        assert_same([], $model->all('server'));
        $http->guestReady = true;
        $worker->once();
        assert_same('pending', $model->job($id)['status']);
        assert_same([], $model->all('server'));
        $process->ready = true;
        $worker->once();
        assert_same('succeeded', $model->job($id)['status']);
        assert_same(3, count($http->mutations));
        assert_same('web', $model->all('server')[0]['id']);
    });
});

test('Proxmox older jobs without SSH credentials stop before any mutation', function () {
    proxmox_worker_test(function ($model, $id, $http, $worker) {
        $plan = json_decode($model->job($id)['payload'], true);
        unset($plan['ssh_credential'], $plan['ssh_public_key']);
        $model->db->query_bind('UPDATE jobs SET payload=? WHERE id=?', [json_encode($plan, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $id], 'statement');
        $worker->once();
        assert_same('uncertain', $model->job($id)['status']);
        assert_contains('older provisioning request', $model->job($id)['result']);
        assert_same([], $http->mutations);
    });
});

test('Proxmox definitive clone rejection fails without an ambiguous retry', function () {
    proxmox_worker_test(function ($model, $id, $http, $worker) {
        $http->failAt = 'forbidden';
        $worker->once();
        assert_same('failed', $model->job($id)['status']);
        assert_same(403, json_decode($model->job($id)['result'], true)['http_status']);
        assert_same(1, count($http->mutations));
    });
});

finish_tests();
