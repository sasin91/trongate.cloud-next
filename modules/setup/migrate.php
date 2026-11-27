<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__, 2) . '/engine/cli_bootstrap.php';
require_once APPPATH . 'modules/security/services/Encryptor.php';
umask(0077);

try {
    if ($argc !== 1) {
        throw new RuntimeException('Usage: php modules/setup/migrate.php');
    }
    Encryptor::validate_key(APP_KEY);
    (new Setup('setup'))->model->migrate();
    echo "Migrations applied\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Error: ' . $error->getMessage() . "\n");
    exit(1);
}
