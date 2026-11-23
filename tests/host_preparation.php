<?php
require_once __DIR__ . '/model_fixture.php';
require __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/bootstrap.php';

function host_model(): Model {
    $model = new Test_model('jobs', new PDO('sqlite::memory:'));
    (new Setup_model('setup', $model->db->connection()))->migrate();
    return $model;
}

function host_fixture(): array {
    return ['host' => 'web.test', 'port' => 22, 'user' => 'root', 'credential' => 'key', 'managed_eligible' => true];
}

test('host updates queue installation settings and reject request-supplied Nix inputs', function () {
    $model = host_model();
    $server = host_fixture();
    $model->put('server', 'web', $server);
    $model->module('deployments');
    $deployments_model = $model->deployments->model;
    assert_throws(fn() => $deployments_model->_queue_host('host-update', ['server' => 'web', 'hardware_file' => '/untrusted']), 'Configure local NixOS settings');
    $settings = ['hostname' => 'web', 'system' => 'x86_64-linux', 'hardware_file' => '/trusted/hardware.nix'];
    $model->put('server', 'web', $server + ['nixos' => $settings]);
    $id = $deployments_model->_queue_host('host-update', ['server' => 'web', 'configuration' => ['hardware_file' => '/untrusted']]);
    assert_same(['server' => 'web', 'configuration' => $settings], json_decode($model->job($id)['payload'], true));
});

test('host preparation freezes inputs, keeps heartbeats and reuses completed builds', function () {
    $directory = sys_get_temp_dir() . '/tc-host-preparation-' . bin2hex(random_bytes(6));
    mkdir($directory);
    $previous = getenv('TC_STATE');
    putenv('TC_STATE=' . $directory);
    file_put_contents($directory . '/hardware.nix', '{ ... }: { system.stateVersion = "26.05"; }');
    $process = new class extends Process {
        public array $calls = [];
        public bool $fail = false;
        public function run(array $argv, string $input = '', int $timeout = 60, ?callable $heartbeat = null, array $extra_env = []): array {
            $this->calls[] = $argv;
            $heartbeat();
            if ($argv[1] === 'flake') {
                file_put_contents($argv[3] . '/flake.lock', '{}');
            }
            return ['exit' => $this->fail ? 1 : 0, 'out' => '/nix/store/' . str_repeat('a', 32) . '-nixos-system-web-26.05', 'err' => 'private diagnostic'];
        }
    };
    try {
        $backend = new DeployRs(new Ssh(host_model()), $process);
        $settings = ['hostname' => 'web', 'system' => 'x86_64-linux', 'hardware_file' => $directory . '/hardware.nix'];
        $heartbeats = 0;
        $heartbeat = function () use (&$heartbeats): void { $heartbeats++; };
        $revision = $backend->prepare(host_fixture(), $settings, $heartbeat);
        assert_same(2, $heartbeats);
        assert_same(['flake', 'build'], array_column($process->calls, 1));
        assert_same(file_get_contents($settings['hardware_file']), file_get_contents($directory . '/revisions/' . $revision . '/hardware.nix'));
        assert_same('web.test', json_decode(file_get_contents($directory . '/revisions/' . $revision . '/inventory.json'), true)['address']);
        assert_same($revision, $backend->prepare(host_fixture(), $settings, $heartbeat));
        assert_same(2, count($process->calls));
        $process->fail = true;
        file_put_contents($settings['hardware_file'], '{ ... }: { system.stateVersion = "25.11"; }');
        assert_throws(fn() => $backend->prepare(host_fixture(), $settings, $heartbeat), 'Configuration lock failed');
        assert_same(1, count(glob($directory . '/revisions/*/expected-system')));
    } finally {
        putenv($previous === false ? 'TC_STATE' : 'TC_STATE=' . $previous);
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        unset($files);
        rmdir($directory);
    }
});

finish_tests();
