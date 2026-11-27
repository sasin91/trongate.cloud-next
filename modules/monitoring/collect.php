<?php
declare(strict_types=1);

require APPPATH . '/engine/cli_bootstrap.php';
require_once APPPATH . '/modules/security/services/Encryptor.php';
require_once APPPATH . '/modules/log/Log.php';
umask(0077);

$log = new Log([new Stream_writer('[monitor] ', true, STDERR)]);

try {
    if (count($argv) !== 1) {
        throw new RuntimeException('Usage: php modules/monitoring/collect.php');
    }
    Encryptor::validate_key(installation_key());
    Modules::run('monitoring/_collect');
    echo "Monitoring collected\n";
} catch (Throwable $error) {
    $log->error($error->getMessage());
    exit(1);
}
