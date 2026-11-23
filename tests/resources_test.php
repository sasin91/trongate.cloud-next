<?php
require __DIR__ . '/bootstrap.php';
require dirname(__DIR__) . '/runtime/tc-runtime.php';
require_once dirname(__DIR__) . '/runtime/tc_resources.php';

class Resource_store extends Runtime {
    public function __construct(string $root) {
        $this->root = $root;
        mkdir($root . '/apps', 0700, true);
    }

    public function read(string $path, mixed $default = null): mixed {
        return is_file($path) ? json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) : $default;
    }

    public function write(string $path, mixed $value): void {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        file_put_contents($path, json_encode($value, JSON_THROW_ON_ERROR));
    }
}

class Resource_fixture extends Resources {
    public array $limits = [];
    public array $calls = [];
    public ?string $fail = null;
    public array $hardware = ['memory_mb' => 4096, 'available_mb' => 3500, 'cpus' => 4,
        'system_memory_mb' => 512, 'database_memory_mb' => 512, 'build_memory_mb' => 1536,
        'system_cpus' => 0.25, 'database_cpus' => 0.25, 'build_cpus' => 1];

    public function host(): array {
        return $this->hardware;
    }

    public function containers(): array {
        return $this->limits;
    }

    public function update(string $container, int $memory, int $shares, float|int $quota = 0): void {
        $this->calls[] = [$container, $memory, $shares, $quota];
        if ($this->fail === $container) {
            $this->fail = null;
            throw new RuntimeException('simulated update failure');
        }
        foreach ($this->limits as &$item) {
            if ($item['container'] === $container) {
                $item['memory_mb'] = $memory;
                $item['shares'] = $shares;
                $item['quota'] = $quota;
            }
        }
    }

    public function app(string $name, int $memory = 256, int $used = 40, int $versions = 1, bool $staged = false): void {
        $this->runtime->write($this->runtime->root . '/apps/' . $name . ($staged ? '/staged.json' : '/state.json'),
            $staged ? ['operation' => $name] : ['active' => ['operation' => $name]]);
        for ($index = 0; $index < $versions; $index++) {
            $this->limits[] = ['app' => $name, 'container' => $name . '-' . $index, 'running' => true,
                'used_mb' => $used, 'memory_mb' => $memory, 'shares' => 1024, 'quota' => 1];
        }
    }
}

function resource_test(string $name, callable $body): void {
    test($name, function () use ($body) {
        $root = sys_get_temp_dir() . '/tc-resource-' . bin2hex(random_bytes(6));
        $fixture = new Resource_fixture(new Resource_store($root));
        try {
            $body($fixture);
        } finally {
            // Delete only files under this test's freshly created temporary directory.
            $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($items as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($root);
        }
    });
}

resource_test('resources reserve two slots and all host budgets', function (Resource_fixture $r) {
    assert_same(['mode' => 'automatic', 'memory_mb' => 768, 'cpus' => 1.25], $r->apply(incoming: 'one', starting: true));
    $r->app('one');
    assert_same(384, $r->apply(incoming: 'two', starting: true)['memory_mb']);
    assert_same(384, $r->limits[0]['memory_mb']);
    assert_same(0, $r->limits[0]['quota']);
});
resource_test('manual allocation stays fixed and automatic apps share remainder', function (Resource_fixture $r) {
    $r->app('one');
    $r->app('two');
    $r->apply(change: ['app' => 'one', 'mode' => 'manual', 'memory_mb' => 512, 'cpus' => 0.5]);
    assert_same([512, 256], array_column($r->limits, 'memory_mb'));
    assert_same(0.5, $r->snapshot()['allocations']['one']['cpus']);
    $r->apply(change: ['app' => 'one', 'mode' => 'automatic']);
    assert_same([384, 384], array_column($r->limits, 'memory_mb'));
});
resource_test('invalid and over-budget allocations never mutate containers', function (Resource_fixture $r) {
    $r->app('one');
    $r->app('two');
    foreach ([[513, 0.5], [512, 1.21], [255, 0.5], [256, NAN], [256, 0.051], [true, 0.5], [256, true]] as [$memory, $cpu]) {
        assert_throws(fn() => $r->apply(change: ['app' => 'one', 'mode' => 'manual', 'memory_mb' => $memory, 'cpus' => $cpu]), '');
    }
    assert_same([], $r->calls);
    assert_same(false, is_file($r->path));
});
resource_test('live usage prevents unsafe automatic shrink', function (Resource_fixture $r) {
    $r->app('one', memory: 768, used: 400);
    assert_throws(fn() => $r->apply(incoming: 'two', starting: true), 'Cannot safely shrink one');
    assert_same([], $r->calls);
});
resource_test('active and staged versions receive limits and third versions are refused', function (Resource_fixture $r) {
    $r->app('one', versions: 2);
    $r->app('two', staged: true);
    $r->apply(change: ['app' => 'one', 'mode' => 'automatic']);
    assert_same([384, 384, 384], array_column($r->limits, 'memory_mb'));
    assert_same(2, $r->snapshot()['apps'][0]['versions']);
    assert_same(80, $r->snapshot()['apps'][0]['used_mb']);
    assert_throws(fn() => $r->apply(incoming: 'one', starting: true), 'Two versions');
});
resource_test('stopped retained rollback containers also receive limits', function (Resource_fixture $r) {
    $r->app('one', versions: 2);
    $r->limits[1]['running'] = false;
    $r->apply(incoming: 'one', starting: true);
    assert_same([768, 768], array_column($r->limits, 'memory_mb'));
});
resource_test('resource reductions precede increases', function (Resource_fixture $r) {
    $r->app('one', memory: 256);
    $r->app('two', memory: 512);
    $r->apply();
    assert_same(['two-0', 'one-0'], array_column($r->calls, 0));
});
resource_test('failed partial update restores limits and previous policies', function (Resource_fixture $r) {
    $r->app('one');
    $r->app('two');
    $before = $r->limits;
    $r->fail = 'two-0';
    assert_throws(fn() => $r->apply(change: ['app' => 'one', 'mode' => 'manual', 'memory_mb' => 512, 'cpus' => 0.5]), 'simulated');
    assert_same($before, $r->limits);
    assert_same([], $r->runtime->read($r->path));
    assert_same(false, is_file($r->pending));
});
resource_test('interrupted update is recovered before the next mutation', function (Resource_fixture $r) {
    $r->app('one');
    $r->runtime->write($r->pending, ['before' => $r->limits, 'policies' => []]);
    $r->limits[0]['memory_mb'] = 768;
    $r->apply(change: ['app' => 'one', 'mode' => 'manual', 'memory_mb' => 512, 'cpus' => 0.5]);
    assert_same(256, $r->calls[0][1]);
    assert_same(512, $r->limits[0]['memory_mb']);
    assert_same(false, is_file($r->pending));
});
resource_test('host pressure and minimum capacity fail closed', function (Resource_fixture $r) {
    $r->hardware['available_mb'] = 200;
    assert_throws(fn() => $r->apply(incoming: 'one', starting: true), 'Available memory');
    $r->hardware['available_mb'] = 3500;
    $r->hardware['memory_mb'] = 3000;
    assert_throws(fn() => $r->apply(incoming: 'one', starting: true), 'Not enough capacity');
    assert_same([], $r->calls);
});
resource_test('resource recovery never forces an unsafe shrink', function (Resource_fixture $r) {
    $r->app('one');
    $r->runtime->write($r->pending, ['before' => $r->limits, 'policies' => []]);
    $r->limits[0]['memory_mb'] = 768;
    $r->limits[0]['used_mb'] = 500;
    assert_throws(fn() => $r->apply(), 'recovery needs attention');
    assert_same(true, is_file($r->pending));
    assert_same([], $r->calls);
});
resource_test('running release without active journal still consumes capacity', function (Resource_fixture $r) {
    $r->app('one');
    $r->runtime->write($r->runtime->root . '/apps/one/state.json', ['active' => null]);
    assert_same(384, $r->apply(incoming: 'two', starting: true)['memory_mb']);
});
resource_test('removed app returns its budget to remaining apps', function (Resource_fixture $r) {
    $r->app('one');
    $r->app('two');
    $r->apply();
    $r->runtime->write($r->runtime->root . '/apps/two/state.json', ['active' => null]);
    $r->limits[1]['running'] = false;
    $r->apply();
    assert_same(768, $r->limits[0]['memory_mb']);
});
finish_tests();
