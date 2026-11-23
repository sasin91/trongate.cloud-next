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
        throw new RuntimeException('Usage: php modules/monitoring/collect.php');
    }
    Encryptor::validate_key(installation_key());
    Modules::run('monitoring/_collect');
    echo "Monitoring collected\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Error: ' . $error->getMessage() . "\n");
    exit(1);
}
