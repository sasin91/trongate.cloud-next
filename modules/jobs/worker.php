<?php
declare(strict_types=1);

require APPPATH . '/engine/cli_bootstrap.php';
require_once APPPATH . '/modules/security/services/Encryptor.php';
require_once APPPATH . '/modules/jobs/services/Worker.php';
require_once APPPATH . '/modules/log/Log.php';
require_once APPPATH . '/modules/jobs/Jobs.php';
umask(0077);

$log = new Log([new Stream_writer('[worker] ', true, STDERR)]);

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
    $log->error($error->getMessage());
    exit(1);
}
