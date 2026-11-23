<?php
require __DIR__ . '/bootstrap.php';
require dirname(__DIR__) . '/runtime/tc-build.php';

class Build_fixture extends Builder {
    public array $calls = [];
    public bool $interrupt = false;
    public float $space = 10737418240;

    public function free_space(): float {
        return $this->space;
    }

    public function run(array $args, int $timeout = 1800): string {
        $this->calls[] = $args;
        if (array_slice($args, 0, 2) === ['git', 'init']) {
            if ($this->interrupt) {
                throw new RuntimeException('interrupted');
            }
            mkdir($args[2] . '/public', 0700, true);
            mkdir($args[2] . '/config');
            touch($args[2] . '/public/index.php');
            touch($args[2] . '/config/config.php');
        }
        if (in_array('rev-parse', $args, true)) {
            return str_repeat('a', 40);
        }
        if (array_slice($args, 0, 3) === ['podman', 'image', 'inspect']) {
            return 'sha256:' . str_repeat('b', 64);
        }
        if (array_slice($args, 0, 2) === ['podman', 'save']) {
            file_put_contents($args[array_search('--output', $args, true) + 1], 'fixture archive');
        }
        if (array_slice($args, 0, 2) === ['podman', 'push']) {
            file_put_contents($args[array_search('--digestfile', $args, true) + 1], 'sha256:' . str_repeat('e', 64));
        }
        return '';
    }
}

function with_builder(callable $test): void {
    $home = sys_get_temp_dir() . '/tc-build-test-' . bin2hex(random_bytes(8));
    mkdir($home, 0700);
    $request = ['operation' => str_repeat('c', 32), 'repository' => 'owner/repo', 'commit' => str_repeat('a', 40),
        'tag' => 'localhost/tc/demo:build-' . str_repeat('d', 24), 'dockerfile' => 'FROM example', 'output' => 'archive'];
    try {
        $test(new Build_fixture($home), $request);
    } finally {
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($home, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            if ($item->isDir() && !$item->isLink()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($home);
    }
}

test('local build archives instead of pushing and retries return the recorded result', function () {
    with_builder(function (Build_fixture $builder, array $request) {
        $result = $builder->build($request);
        assert_same('succeeded', $result['status']);
        assert_same('localhost/tc/demo@sha256:' . str_repeat('b', 64), $result['image']);
        assert_same([], array_values(array_filter($builder->calls, fn($args) => array_slice($args, 0, 2) === ['podman', 'push'])));
        assert_same(true, in_array(['podman', 'rmi', '--no-prune', $request['tag']], $builder->calls, true));
        assert_same(1, count(array_filter($builder->calls, fn($args) => in_array('--property=MemoryMax=1G', $args, true) && in_array('--property=CPUQuota=100%', $args, true))));
        $count = count($builder->calls);
        assert_same($result, $builder->build($request));
        assert_same($count, count($builder->calls));
    });
});

test('interrupted builds preserve intent and never repeat commands', function () {
    with_builder(function (Build_fixture $builder, array $request) {
        $builder->interrupt = true;
        assert_throws(fn() => $builder->build($request), 'interrupted');
        $count = count($builder->calls);
        $builder->interrupt = false;
        assert_same('uncertain', $builder->build($request)['status']);
        assert_same($count, count($builder->calls));
    });
});

test('dedicated builds use a registry digest and larger isolated limits', function () {
    with_builder(function (Build_fixture $builder, array $request) {
        unset($request['output']);
        $request['tag'] = 'registry.example.com/demo:build-test';
        $result = $builder->build($request);
        assert_same('registry.example.com/demo@sha256:' . str_repeat('e', 64), $result['image']);
        assert_same(1, count(array_filter($builder->calls, fn($args) => in_array('--property=MemoryMax=2G', $args, true) && in_array('--property=CPUQuota=200%', $args, true))));
    });
});

test('disk admission fails before pruning or building', function () {
    with_builder(function (Build_fixture $builder, array $request) {
        $builder->space = 1024;
        assert_same('failed', $builder->build($request)['status']);
        assert_same([], $builder->calls);
    });
});

finish_tests();
