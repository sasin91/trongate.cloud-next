<?php
require_once __DIR__ . '/model_fixture.php';
require __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/bootstrap.php';

test('generated SSH private keys are accepted by OpenSSH and match the public key', function () {
    $keys = Proxmox::ssh_key();
    $path = tempnam(sys_get_temp_dir(), 'tc-key-');
    try {
        file_put_contents($path, $keys['private']);
        chmod($path, 0600);
        if (PHP_OS_FAMILY === 'Windows') {
            $acl = proc_open(['icacls', $path, '/inheritance:r', '/grant:r', getenv('USERNAME') . ':F'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $aclPipes);
            foreach ($aclPipes as $pipe) {
                fclose($pipe);
            }
            assert_same(0, proc_close($acl));
        }
        $child = proc_open(['ssh-keygen', '-y', '-f', $path], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]);
        $result = ['out' => stream_get_contents($pipes[1]), 'err' => stream_get_contents($pipes[2])];
        fclose($pipes[1]);
        fclose($pipes[2]);
        $result['exit'] = proc_close($child);
        if ($result['exit'] !== 0) {
            throw new RuntimeException($result['err']);
        }
        assert_same(0, $result['exit']);
        assert_same(implode(' ', array_slice(explode(' ', $keys['public']), 0, 2)),
            implode(' ', array_slice(explode(' ', trim($result['out'])), 0, 2)));
    } finally {
        unlink($path);
    }
});

class Enrollment_process extends Process {
    public bool $ready = true;
    public array $arguments = [];

    function run(array $argv, string $input = '', int $timeout = 60, ?callable $heartbeat = null, array $extra_env = []): array {
        $this->arguments = $argv;
        $facts = "os=nixos\nnix=yes\n";
        foreach (['podman', 'systemctl', 'curl', 'php', 'flock', 'caddy', 'agent', 'privileged', 'proxy', 'podman-ready', 'cgroups', 'protocol'] as $fact) {
            $facts .= $fact . '=yes' . "\n";
        }
        return ['exit' => $this->ready ? 0 : 255, 'out' => $facts, 'err' => ''];
    }
}

test('automatic enrollment pins provider identity and waits for readiness', function () {
    $directory = sys_get_temp_dir() . '/tc-enrollment-' . bin2hex(random_bytes(5));
    mkdir($directory);
    $previous = getenv('TC_STATE');
    putenv('TC_STATE=' . $directory);
    file_put_contents($directory . '/key', Encryptor::generate_key());
    try {
        $model = new Test_model('jobs', new PDO('sqlite::memory:'));
        (new Setup_model('setup', $model->db->connection()))->migrate();
        $keys = Proxmox::ssh_key();
        $model->secret('server-key', $keys['private']);
        $identity = ['host_key' => implode(' ', array_slice(explode(' ', $keys['public']), 0, 2)), 'addresses' => ['192.0.2.20']];
        $plan = ['name' => 'web', 'ssh_credential' => 'server-key', 'endpoint' => 'https://pve.test:8006', 'node' => 'pve', 'vmid' => 201];
        $process = new Enrollment_process;
        $enrollment = new Proxmox('proxmox');
        $enrollment->_share_database($model->_database_source());
        $process->ready = false;
        assert_same('pending', $enrollment->enroll($model, 'operation', $plan, $identity, $process)['status']);
        assert_same([], $model->all('server'));
        $process->ready = true;
        assert_same('succeeded', $enrollment->enroll($model, 'operation', $plan, $identity, $process)['status']);
        assert_same(true, in_array('StrictHostKeyChecking=yes', $process->arguments, true));
        assert_same($identity['host_key'], $model->get('server', 'web')['host_key']);
        assert_same('succeeded', $enrollment->enroll($model, 'operation', $plan, $identity, $process)['status']);
        assert_throws(fn() => $enrollment->enroll($model, 'other', $plan, $identity, $process), 'already enrolled');
    } finally {
        unlink($directory . '/key');
        rmdir($directory);
        putenv($previous === false ? 'TC_STATE' : 'TC_STATE=' . $previous);
    }
});

finish_tests();
