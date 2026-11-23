<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__, 2) . '/bootstrap.php';
umask(0077);

try {
    if (count($argv) > 2 || (isset($argv[1]) && $argv[1] !== '--once')) {
        throw new RuntimeException('Usage: php modules/jobs/worker.php [--once]');
    }
    Encryptor::validate_key(installation_key());
    $worker = new Worker((new Jobs('jobs'))->model);
    do {
        $worker->once();
        if (($argv[1] ?? '') === '--once') {
            break;
        }
        sleep(3);
    } while (true);
} catch (Throwable $error) {
    fwrite(STDERR, 'Error: ' . $error->getMessage() . "\n");
    exit(1);
}
