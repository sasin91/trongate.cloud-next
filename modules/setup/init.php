<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__, 2) . '/bootstrap.php';
umask(0077);

try {
    if (count($argv) !== 1) {
        throw new RuntimeException('Usage: php modules/setup/init.php < administrator.json');
    }
    Modules::run('setup/_initialize', json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR));
    echo "Installation initialized. Back up the key and database together.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Error: ' . $error->getMessage() . "\n");
    exit(1);
}
