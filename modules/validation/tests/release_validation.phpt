--TEST--
Validate enforces FreeBSD host, identifier, release digest, PHP version, mount, and migration contracts
--FILE--
<?php
require dirname(__DIR__, 3) . '/bootstrap.php';

foreach ([
    'host' => static fn() => Validate::host('-oProxyCommand=bad'),
    'id' => static fn() => Validate::id('x;touch /tmp/bad'),
    'digest' => static fn() => Validate::release_digest('not-a-digest'),
    'php' => static fn() => Validate::php_version('7.4'),
    'mount' => static fn() => Validate::release([
        'artifact' => 'sha256:' . str_repeat('a', 64),
        'domain' => 'x.test',
        'mounts' => ['uploads' => '/etc/shadow'],
    ]),
    'migration' => static fn() => Validate::release([
        'artifact' => 'sha256:' . str_repeat('a', 64),
        'domain' => 'x.test',
        'migration' => ['php', 'migrate.php'],
    ]),
] as $case => $fn) {
    try {
        $fn();
        echo "FAIL {$case}\n";
    } catch (RuntimeException|InvalidArgumentException $e) {
        echo "{$case}=rejected\n";
    }
}

$valid = Validate::release([
    'artifact' => 'sha256:' . str_repeat('a', 64),
    'domain' => 'app.example.com',
    'php_version' => '8.4',
    'extensions' => ['imagick', 'gmp'],
    'database' => true,
    'memory_mb' => 256,
    'cpu_percent' => 100,
    'disk_quota_mb' => 2048,
    'mounts' => ['uploads' => '/app/public/uploads'],
    'commit' => str_repeat('b', 40),
    'release_path' => '/zroot/tc/apps/demo/releases/' . str_repeat('c', 32),
]);
echo $valid['php_version'], '|', ($valid['database'] ? 'db' : 'nodb'), '|', $valid['disk_quota_mb'], "\n";
?>
--EXPECT--
host=rejected
id=rejected
digest=rejected
php=rejected
mount=rejected
migration=rejected
8.4|db|2048
