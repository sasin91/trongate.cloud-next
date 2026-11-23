<?php
/** State-machine checks with simulated commands; container behavior is covered by runtime-vm.nix. */
require __DIR__ . '/bootstrap.php';
require dirname(__DIR__) . '/runtime/tc-runtime.php';

class Simulated_runtime extends Runtime {
    public ?string $bad = null;
    public bool $public_bad = false;
    public ?array $current = null;
    public int $starts = 0;
    public array $public_attempts = [];
    public ?string $generation = null;

    public function run(array $args, int $timeout = 120): string {
        return '';
    }

    public function allocate(string $app): array {
        return ['memory_mb' => 512, 'cpus' => 0.25];
    }

    public function stage(string $app, string $operation, array $spec, array $secrets, ?array $files = null, ?string $registry_auth = null): array {
        $this->starts++;
        return $spec + ['operation' => $operation, 'port' => 1234, 'unit' => $operation . '.service', 'container' => $operation];
    }

    public function route(string $app, ?array $release): void {
        $this->current = $release;
    }

    public function healthy(?array $release): bool {
        return $release !== null && $release['operation'] !== $this->bad;
    }

    public function health(string $url, int $attempts = 20): bool {
        $this->public_attempts[] = $attempts;
        if ($this->public_bad) {
            $this->public_bad = false;
            return false;
        }
        return true;
    }

    public function host_inspect(): array {
        return ['generation' => $this->generation, 'services_healthy' => true,
            'reservation' => $this->read($this->root . '/host-reservation.json')];
    }
}

function runtime_request(int $number): array {
    return ['action' => 'deploy', 'operation' => sprintf('%032x', $number), 'app' => 'demo',
        'release' => ['domain' => 'demo.test', 'health' => '/', 'migration' => []]];
}

function runtime_test(string $name, callable $body): void {
    test($name, function () use ($body) {
        $root = sys_get_temp_dir() . '/tc-runtime-test-' . bin2hex(random_bytes(8));
        $runtime = new Simulated_runtime($root);
        try {
            $body($runtime);
        } finally {
            Runtime::remove_tree($root);
        }
    });
}

runtime_test('first deployment waits for Go live without rebuilding', function (Simulated_runtime $runtime) {
    $request = runtime_request(1) + ['defer_public' => true];
    assert_same('waiting', $runtime->dispatch($request)['status']);
    assert_same(null, $runtime->current);
    assert_same('waiting', $runtime->dispatch($request)['status']);
    assert_same(1, $runtime->starts);
    $runtime->public_bad = true;
    assert_same('waiting', $runtime->dispatch($request + ['go_live' => true])['status']);
    assert_same($request['operation'], $runtime->current['operation']);
    assert_same(30, end($runtime->public_attempts));
    assert_same(1, $runtime->starts);
    assert_same('succeeded', $runtime->dispatch($request + ['go_live' => true])['status']);
    assert_same(1, $runtime->starts);
    assert_same(false, is_file($runtime->root . '/apps/demo/staged.json'));
});

runtime_test('pending HTTPS replacement preserves the previous application', function (Simulated_runtime $runtime) {
    $runtime->dispatch(runtime_request(1));
    $runtime->public_bad = true;
    $request = runtime_request(2) + ['defer_public' => true];
    assert_same('waiting', $runtime->dispatch($request)['status']);
    assert_same(sprintf('%032x', 1), $runtime->current['operation']);
    $result = $runtime->dispatch($request + ['go_live' => true]);
    assert_same('succeeded', $result['status']);
    assert_same(false, isset($result['message']));
    assert_same(2, $runtime->starts);
});

runtime_test('waiting deployment allows another app but fences the same app', function (Simulated_runtime $runtime) {
    $runtime->dispatch(runtime_request(1) + ['defer_public' => true]);
    $other = array_replace(runtime_request(2), ['app' => 'other', 'release' => ['domain' => 'other.test', 'health' => '/', 'migration' => []]]);
    assert_same('succeeded', $runtime->dispatch($other)['status']);
    assert_throws(fn() => $runtime->dispatch(runtime_request(3)), 'staged deployment');
});

runtime_test('deploy and rollback preserve idempotent retries', function (Simulated_runtime $runtime) {
    assert_same('succeeded', $runtime->dispatch(runtime_request(1))['status']);
    assert_same('succeeded', $runtime->dispatch(runtime_request(2))['status']);
    assert_same('succeeded', $runtime->dispatch(runtime_request(2))['status']);
    assert_same(2, $runtime->starts);
    assert_same('succeeded', $runtime->dispatch(array_replace(runtime_request(3), ['action' => 'rollback']))['status']);
    assert_same(sprintf('%032x', 1), $runtime->current['operation']);
});

runtime_test('failed candidate preserves the active release', function (Simulated_runtime $runtime) {
    $runtime->dispatch(runtime_request(1));
    $runtime->bad = sprintf('%032x', 2);
    assert_same('failed', $runtime->dispatch(runtime_request(2))['status']);
    assert_same(sprintf('%032x', 1), $runtime->current['operation']);
});

runtime_test('failed public check restores the previous route', function (Simulated_runtime $runtime) {
    $runtime->dispatch(runtime_request(1));
    $runtime->public_bad = true;
    assert_same('failed', $runtime->dispatch(runtime_request(2))['status']);
    assert_same(sprintf('%032x', 1), $runtime->current['operation']);
});

runtime_test('crash after switch restores the recorded previous release', function (Simulated_runtime $runtime) {
    $runtime->dispatch(runtime_request(1));
    $before = $runtime->read($runtime->root . '/apps/demo/state.json');
    $runtime->write($runtime->root . '/jobs/' . sprintf('%032x', 2) . '.json', ['status' => 'running', 'phase' => 'switching', 'before' => $before]);
    assert_same('failed', $runtime->dispatch(runtime_request(2))['status']);
    assert_same(sprintf('%032x', 1), $runtime->current['operation']);
    assert_same(1, $runtime->starts);
});

runtime_test('unknown migration is never repeated', function (Simulated_runtime $runtime) {
    $runtime->dispatch(runtime_request(1));
    $before = $runtime->read($runtime->root . '/apps/demo/state.json');
    $runtime->write($runtime->root . '/jobs/' . sprintf('%032x', 2) . '.json', ['status' => 'running', 'phase' => 'migration-started', 'before' => $before]);
    assert_same('uncertain', $runtime->dispatch(runtime_request(2))['status']);
    assert_same(1, $runtime->starts);
});

runtime_test('host reservation blocks application work after worker loss', function (Simulated_runtime $runtime) {
    $runtime->write($runtime->root . '/host-reservation.json', ['operation' => sprintf('%032x', 9)]);
    assert_same('uncertain', $runtime->dispatch(runtime_request(1))['status']);
    assert_same(0, $runtime->starts);
});

runtime_test('operation flock blocks a concurrent worker', function (Simulated_runtime $runtime) {
    $lock = fopen($runtime->root . '/operation.lock', 'ab');
    flock($lock, LOCK_EX);
    try {
        assert_same('uncertain', $runtime->dispatch(runtime_request(1))['status']);
        assert_same(0, $runtime->starts);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
});

runtime_test('persistent mounts survive rollback', function (Simulated_runtime $runtime) {
    $data = $runtime->root . '/apps/demo/data/uploads/example.txt';
    Runtime::directory(dirname($data));
    file_put_contents($data, 'keep me');
    $runtime->dispatch(runtime_request(1));
    $runtime->dispatch(runtime_request(2));
    $runtime->dispatch(array_replace(runtime_request(3), ['action' => 'rollback']));
    assert_same('keep me', file_get_contents($data));
});

runtime_test('cleanup preserves containers needed for resource recovery', function (Simulated_runtime $runtime) {
    $runtime->write($runtime->root . '/resources-pending.json', ['before' => [], 'policies' => []]);
    assert_same('waiting', $runtime->dispatch(['action' => 'cleanup'])['status']);
});

runtime_test('host recovery requires quiescence and an observed previous generation', function (Simulated_runtime $runtime) {
    $before = '/nix/store/' . str_repeat('a', 32) . '-nixos-system-before';
    $expected = '/nix/store/' . str_repeat('b', 32) . '-nixos-system-after';
    $runtime->generation = $before;
    $operation = sprintf('%032x', 9);
    $begin = ['action' => 'host-begin', 'operation' => $operation, 'expected' => $expected];
    assert_same('reserved', $runtime->dispatch($begin)['status']);
    assert_same('uncertain', $runtime->dispatch($begin)['status']);
    assert_same('uncertain', $runtime->dispatch(['action' => 'host-reconcile', 'operation' => $operation])['status']);
    $restored = $runtime->dispatch(['action' => 'host-reconcile', 'operation' => $operation, 'workers_stopped' => true]);
    assert_same('failed', $restored['status']);
    assert_same($before, $restored['restored_generation']);
    assert_same(false, is_file($runtime->root . '/host-reservation.json'));
});

test('runtime process sends stdin and collects output without exposing stderr', function () {
    $input = json_encode(['operation' => str_repeat('a', 32), 'data' => 'fixture'], JSON_THROW_ON_ERROR);
    assert_same(hash('sha256', $input), Runtime::process([PHP_BINARY, '-r', 'echo hash("sha256", stream_get_contents(STDIN));'], $input, 5));
    assert_throws(fn() => Runtime::process([PHP_BINARY, '-r', 'fwrite(STDERR, "fixture-private-output"); exit(1);']), 'Controlled command failed:');
});

test('runtime process terminates commands at their deadline', function () {
    assert_throws(fn() => Runtime::process([PHP_BINARY, '-r', 'sleep(5);'], '', 1), 'timed out');
});

finish_tests();
