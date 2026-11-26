--TEST--
Builder runs ephemeral pf+rctl build jails, handles composer/commands, caches retries, and rejects bad inputs
--FILE--
<?php
require dirname(__DIR__) . '/builder.php';

class Freebsd_build_fixture extends Builder {
    public array $calls = [];
    public bool $interrupt = false;
    public bool $include_composer = true;
    public bool $missing_trongate_files = false;
    public bool $bad_commit = false;
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
            $src = $args[2];
            mkdir($src . '/.git', 0700, true);
            file_put_contents($src . '/.git/HEAD', "ref: refs/heads/main\n");
            if (!$this->missing_trongate_files) {
                mkdir($src . '/public', 0700, true);
                mkdir($src . '/config', 0700, true);
                file_put_contents($src . '/public/index.php', "<?php echo 'ok';\n");
                file_put_contents($src . '/config/config.php', "<?php define('ENV', 'production');\n");
            } else {
                file_put_contents($src . '/README.md', "not trongate\n");
            }
            if ($this->include_composer) {
                file_put_contents($src . '/composer.json', '{"name":"demo/app"}');
            }
        }
        if (array_slice($args, 0, 5) === ['zfs', 'list', '-H', '-o', 'name']) {
            throw new RuntimeException('dataset does not exist');
        }
        if (count($args) === 2 && $args[0] === 'rctl' && str_starts_with($args[1], 'jail:')) {
            return $args[1] . ":memoryuse:deny=1536M\n";
        }
        if (in_array('rev-parse', $args, true)) {
            return $this->bad_commit ? str_repeat('9', 40) : str_repeat('a', 40);
        }
        return '';
    }
}

function with_builder(callable $test): void {
    $home = sys_get_temp_dir() . '/tc-build-test-' . bin2hex(random_bytes(8));
    mkdir($home, 0700);
    $request = [
        'operation' => str_repeat('c', 32),
        'app' => 'demo',
        'repository' => 'owner/repo',
        'commit' => str_repeat('a', 40),
        'php_version' => '8.4',
        'extensions' => ['imagick', 'gmp'],
        'commands' => "npm ci\nnpm run build",
    ];
    try {
        $test(new Freebsd_build_fixture($home), $request);
    } finally {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($home, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
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

// 1. Full build lifecycle, pf + rctl + composer + commands + cleanup + idempotent retry.
with_builder(function (Freebsd_build_fixture $b, array $req) {
    $res = $b->build($req);
    $suffix = substr($req['operation'], 0, 16);
    $jail = 'tc_build_' . $suffix;
    $dataset = 'zroot/tc/jails/build-' . $suffix;
    $composerCalls = array_values(array_filter($b->calls, fn(array $c) => ($c[0] ?? '') === 'jexec' && in_array('composer', $c, true)));
    $cmdCalls = array_values(array_filter($b->calls, fn(array $c) => ($c[0] ?? '') === 'jexec' && in_array('/bin/sh', $c, true) && in_array('/tmp/tc-build-cmd.sh', $c, true)));

    echo "build=", $res['status'], '|', $res['app'], '|digest=', preg_match('/^sha256:[a-f0-9]{64}$/', $res['artifact']),
        '|index=', (is_file($res['release_path'] . '/public/index.php') ? 'yes' : 'no'),
        '|nogit=', (!file_exists($res['release_path'] . '/.git') ? 'yes' : 'no'),
        '|quota=', $res['disk_quota_mb'],
        '|uploads=', ($res['detected_uploads'] ? 'yes' : 'no'),
        '|paths=', implode(',', $res['persistent_paths']), "\n";
    var_dump(
        in_array(['zfs', 'clone', '-o', 'quota=2G', 'zroot/tc/skeleton@v1', $dataset], $b->calls, true),
        in_array(['pfctl', '-t', 'tc_jails', '-T', 'add', Builder::BUILD_JAIL_IP], $b->calls, true),
        in_array(['rctl', '-a', "jail:{$jail}:memoryuse:deny=1536M"], $b->calls, true),
        in_array(['rctl', '-a', "jail:{$jail}:swapuse:deny=16384M"], $b->calls, true),
        in_array(['rctl', '-a', "jail:{$jail}:pcpu:deny=200"], $b->calls, true),
        in_array(['rctl', '-a', "jail:{$jail}:maxproc:deny=256"], $b->calls, true),
        in_array(['rctl', '-a', "jail:{$jail}:openfiles:deny=2048"], $b->calls, true),
        count($composerCalls) === 1,
        in_array('PHP_INI_SCAN_DIR=/usr/local/etc/php-base:/etc/tc/php.d', $composerCalls[0], true),
        count($cmdCalls) === 1,
        in_array(['jail', '-r', '-f', $b->jail_conf_dir . '/' . $jail . '.conf', $jail], $b->calls, true),
        in_array(['pfctl', '-t', 'tc_jails', '-T', 'delete', Builder::BUILD_JAIL_IP], $b->calls, true),
        in_array(['rctl', '-r', "jail:{$jail}"], $b->calls, true),
        in_array(['zfs', 'destroy', '-rf', $dataset], $b->calls, true)
    );
    $count = count($b->calls);
    var_dump($b->build($req) === $res, count($b->calls) === $count);
});

// 2. Interrupted build stays uncertain and never repeats commands.
with_builder(function (Freebsd_build_fixture $b, array $req) {
    $b->interrupt = true;
    try {
        $b->build($req);
        echo "FAIL interrupt not thrown\n";
    } catch (RuntimeException $e) {
        echo "interrupted=", $e->getMessage(), "\n";
    }
    $count = count($b->calls);
    $b->interrupt = false;
    echo "after_interrupt=", $b->build($req)['status'], '|same_calls=', (count($b->calls) === $count ? 'yes' : 'no'), "\n";
});

// 3. Disk admission fails before cloning or fetching.
with_builder(function (Freebsd_build_fixture $b, array $req) {
    $b->space = 1024;
    echo "low_disk=", $b->build($req)['status'], '|calls=', count($b->calls), "\n";
});

// 4. Rejects non-Trongate repos, commit mismatch, and unsupported extensions.
with_builder(function (Freebsd_build_fixture $b, array $req) {
    $b->missing_trongate_files = true;
    try {
        $b->build($req);
    } catch (RuntimeException $e) {
        echo "non_trongate=", (str_contains($e->getMessage(), 'Trongate application') ? 'rejected' : 'other'), "\n";
    }
});
with_builder(function (Freebsd_build_fixture $b, array $req) {
    $b->bad_commit = true;
    try {
        $b->build($req);
    } catch (RuntimeException $e) {
        echo "bad_commit=", (str_contains($e->getMessage(), 'commit mismatch') ? 'rejected' : 'other'), "\n";
    }
});
with_builder(function (Freebsd_build_fixture $b, array $req) {
    $req['extensions'] = ['not_curated_ext'];
    try {
        $b->build($req);
    } catch (InvalidArgumentException $e) {
        echo "bad_ext=", (str_contains($e->getMessage(), 'unsupported optional PHP extension') ? 'rejected' : 'other'), "\n";
    }
});

// 5. Repo inspection detects Trongate upload paths, generic PHP uploads, and scales quota from release size.
$inspectDir = sys_get_temp_dir() . '/tc-inspect-test-' . bin2hex(random_bytes(8));
mkdir($inspectDir . '/trongate/modules/items/assets', 0700, true);
mkdir($inspectDir . '/trongate/public', 0700, true);
mkdir($inspectDir . '/trongate/config', 0700, true);
file_put_contents($inspectDir . '/trongate/modules/items/Items.php', "<?php\nclass Items {\n function _init_picture_settings() {\n  \$this->module('image_uploader');\n  \$data['destination'] = 'items_pics';\n  \$data['thumbnail_dir'] = 'items_pics_thumbnails';\n  \$data['upload_to_module'] = true;\n  return \$data;\n }\n}\n");
$insTrongate = Builder::inspect_release($inspectDir . '/trongate');
echo "inspect_trongate=", ($insTrongate['detected_uploads'] ? 'yes' : 'no'), '|', $insTrongate['disk_quota_mb'], '|', implode(',', $insTrongate['persistent_paths']), "\n";

mkdir($inspectDir . '/generic/modules/avatars', 0700, true);
file_put_contents($inspectDir . '/generic/modules/avatars/Avatars.php', "<?php\nmove_uploaded_file(\$_FILES['avatar']['tmp_name'], '/tmp/x');\n");
$insGeneric = Builder::inspect_release($inspectDir . '/generic');
echo "inspect_generic=", ($insGeneric['detected_uploads'] ? 'yes' : 'no'), '|', $insGeneric['disk_quota_mb'], '|', implode(',', $insGeneric['persistent_paths']), "\n";

echo "quota_small=", Builder::recommended_disk_quota_mb(5 * 1048576, false),
    '|quota_large=', Builder::recommended_disk_quota_mb(50 * 1048576, false),
    '|quota_uploads=', Builder::recommended_disk_quota_mb(5 * 1048576, true), "\n";
echo "sanitized=", Builder::sanitize_error("fatal: https://user:topsecret@github.com/org/repo\nAuthorization: Bearer abc123token\n"), "\n";

$rmItems = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($inspectDir, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($rmItems as $rmItem) {
    if ($rmItem->isDir() && !$rmItem->isLink()) {
        rmdir($rmItem->getPathname());
    } else {
        unlink($rmItem->getPathname());
    }
}
rmdir($inspectDir);
?>
--EXPECT--
build=succeeded|demo|digest=1|index=yes|nogit=yes|quota=128|uploads=no|paths=
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
interrupted=interrupted
after_interrupt=uncertain|same_calls=yes
low_disk=failed|calls=0
non_trongate=rejected
bad_commit=rejected
bad_ext=rejected
inspect_trongate=yes|384|modules/items/assets/items_pics,modules/items/assets/items_pics_thumbnails
inspect_generic=yes|384|public/uploads
quota_small=128|quota_large=256|quota_uploads=384
sanitized=fatal: https://[redacted]@github.com/org/repo | Authorization: Bearer [redacted]
