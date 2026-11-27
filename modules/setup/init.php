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
        throw new RuntimeException('Usage: php modules/setup/init.php < administrator.json');
    }
    $input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($input)) {
        throw new RuntimeException('Administrator details must be a JSON object.');
    }
    $name = $input['name'] ?? $input['username'] ?? null;
    if (!is_string($name) || trim($name) === '') {
        throw new RuntimeException('Administrator name is required.');
    }
    if (!is_string($input['password'] ?? null) || strlen($input['password']) < 14) {
        throw new RuntimeException('Administrator password must be at least 14 characters.');
    }

    Encryptor::validate_key(APP_KEY);
    $setup = new Setup('setup');
    $setup->model->migrate();
    $setup->model->initialize_administrator(trim($name), password_hash($input['password'], PASSWORD_DEFAULT));
    echo "Installation initialized. Back up the key and database together.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Error: ' . $error->getMessage() . "\n");
    exit(1);
}
