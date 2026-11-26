--TEST--
Trongate_config patches only BASE_URL and ENV while preserving custom defines and renders valid database.php
--FILE--
<?php
require dirname(__DIR__, 3) . '/bootstrap.php';

echo "version=", Trongate_config::CONFIG_VERSION, "\n";

$user_config = <<<'PHP'
<?php
// define('BASE_URL', 'http://commented.example/');
define('BASE_URL', getenv('URL') ?: 'http://localhost/app/');
define('ENV', 'dev');
define('DEFAULT_MODULE', 'store');
define('SITE_NAME', 'Acme Store');
PHP;

echo "--- patched ---\n";
echo Trongate_config::patch_config_php($user_config, 'shop.example.com');
echo "\n--- appended ---\n";
echo Trongate_config::patch_config_php("<?php\ndefine('CUSTOM_FLAG', true);\n", 'app.example.com', 'staging');

echo "--- database ---\n";
$db = Trongate_config::database_php('tc_demo', 'tc_demo', "p'w\\\$1\"2");
$tmp = tempnam(sys_get_temp_dir(), 'tc-cfg-');
try {
    file_put_contents($tmp, $db);
    $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($tmp));
    echo "lint=", (str_contains($out, 'No syntax errors detected') ? 'ok' : 'fail'), "\n";
} finally {
    unlink($tmp);
}
echo $db;

foreach ([
    static fn() => Trongate_config::config_php("bad\0.test"),
    static fn() => Trongate_config::database_php('', 'user', 'pass'),
] as $bad) {
    try {
        $bad();
        echo "FAIL accepted\n";
    } catch (InvalidArgumentException $e) {
        echo "rejected=", $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
version=v1
--- patched ---
<?php
// define('BASE_URL', 'http://commented.example/');
define('BASE_URL', 'https://shop.example.com/');
define('ENV', 'production');
define('DEFAULT_MODULE', 'store');
define('SITE_NAME', 'Acme Store');
--- appended ---
<?php
define('CUSTOM_FLAG', true);
define('BASE_URL', 'https://app.example.com/');
define('ENV', 'staging');
--- database ---
lint=ok
<?php
// Managed by trongate.cloud (v1)
$databases = [
    'default' => [
        'host' => 'localhost',
        'port' => '3306',
        'user' => 'tc_demo',
        'password' => 'p\'w\\$1"2',
        'database' => 'tc_demo',
    ],
];
$GLOBALS['databases'] = $databases;
define('HOST', 'localhost');
define('PORT', '3306');
define('USER', 'tc_demo');
define('PASSWORD', 'p\'w\\$1"2');
define('DATABASE', 'tc_demo');
rejected=Invalid Trongate configuration value.
rejected=Database name, username, and host are required.
