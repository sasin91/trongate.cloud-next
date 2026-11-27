<?php
$environment = strtolower(trim((string) (getenv('TC_ENV') ?: 'dev')));
$base_url = trim((string) (getenv('TC_BASE_URL') ?: ''));
$app_key = trim((string) (getenv('APP_KEY') ?: ''));

if ($environment === 'prod') {
    if ($base_url === '') {
        throw new RuntimeException('TC_BASE_URL must be configured for production.');
    }

    $url_parts = parse_url($base_url);
    if (!is_array($url_parts) || strtolower((string) ($url_parts['scheme'] ?? '')) !== 'https' || empty($url_parts['host'])) {
        throw new RuntimeException('TC_BASE_URL must be an absolute HTTPS URL in production.');
    }
}

if ($base_url === '') {
    $base_url = 'http://trongate-cloud-next.test';
}
$base_url = rtrim($base_url, '/') . '/';

if ($app_key === '') {
    $state_dir = trim((string) (getenv('TC_STATE') ?: ''));
    $key_file = trim((string) (getenv('TC_KEY_FILE') ?: ''));
    if ($key_file === '' && $state_dir !== '') {
        $key_file = rtrim($state_dir, '/\\') . '/key';
    }
    if ($key_file !== '' && is_readable($key_file)) {
        $app_key = trim((string) file_get_contents($key_file));
    }
}

if ($app_key === '') {
    if ($environment === 'prod') {
        throw new RuntimeException('APP_KEY or a readable TC_STATE/key file is required in production.');
    }
    $app_key = 'tgcloud-local-dev-key-0123456789';
}

$decoded_key = base64_decode($app_key, true);
$key_bytes = $decoded_key === false ? $app_key : $decoded_key;
if (strlen($key_bytes) !== 32) {
    throw new RuntimeException('APP_KEY must be 32 bytes or a base64-encoded 32-byte key.');
}

define('BASE_URL', $base_url);
define('ENV', $environment);
define('DEFAULT_MODULE', 'welcome');
define('DEFAULT_METHOD', 'index');
define('MODULE_ASSETS_TRIGGER', '_module');
define('ERROR_404', 'error_pages/not_found');
define('APP_KEY', $app_key);