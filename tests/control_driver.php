<?php
// Integration-test driver; not part of the installed command-line interface.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/model_fixture.php';
umask(0077);
try {
    Encryptor::validate_key(installation_key());
    $payload = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $model = new Test_model('jobs');
    foreach (['servers', 'apps', 'deployments'] as $module) {
        $model->module($module);
    }
    echo json_encode(match ($argv[1]) {
        'credential' => $model->secret($payload['id'], $payload['value']),
        'server-scan' => $model->servers->_scan_server($payload),
        'server-confirm' => $model->servers->_confirm_server($payload),
        'server-inspect' => $model->servers->_inspect_server($payload),
        'app-create' => $model->apps->model->_create($payload),
        'release' => $model->apps->model->_release($payload),
        'deploy', 'rollback' => $model->deployments->model->_queue($argv[1], $payload),
        'operation' => $model->_details($payload['operation']),
        'reconcile' => $model->_retry($payload['operation']),
        default => throw new RuntimeException('Unsupported test command'),
    }, JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
