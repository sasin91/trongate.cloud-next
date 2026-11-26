<?php
declare(strict_types=1);
$request_path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
if (PHP_SAPI === 'cli-server' && $request_path !== '/' && $request_path !== '/index.php') {
    $public_root = realpath(__DIR__);
    $candidate = realpath(__DIR__ . $request_path);
    if (
        $public_root !== false
        && $candidate !== false
        && str_starts_with($candidate, $public_root . DIRECTORY_SEPARATOR)
        && is_file($candidate)
    ) {
        return false;
    }
}

require dirname(__DIR__) . '/bootstrap.php';
require_once APPPATH . 'engine/Trongate.php';
require APPPATH . 'engine/Core.php';

define('ENV', getenv('ENV') ?: 'prod');
define('DEFAULT_MODULE', 'welcome');
define('DEFAULT_METHOD', 'index');
define('MODULE_ASSETS_TRIGGER', '_module');
define('BASE_URL', rtrim(getenv('TC_BASE_URL') ?: 'http://localhost:8080', '/') . '/');
define('ERROR_404', 'error_pages/not_found');

$path = $request_path === '/index.php' ? '/' : $request_path;
$trimmed = trim($path, '/');

// Explicit public and shortcut routes (first match wins).
if ($trimmed === 'login') {
    $path = '/login/login/1';
} elseif ($trimmed === 'register') {
    $path = '/apps/create';
} elseif ($trimmed === 'dashboard') {
    $path = '/apps';
} elseif ($trimmed === 'news/changelog') {
    $path = '/news/index/changelog';
} elseif ($trimmed === 'news/writing') {
    $path = '/news/index/writing';
} elseif (preg_match('#^news/([a-z0-9-]+)$#D', $trimmed, $match) && !in_array($match[1], ['feed', 'index', 'show'], true)) {
    $path = '/news/show/' . $match[1];
} elseif (preg_match('#^blog/([a-z0-9-]+)$#D', $trimmed, $match) && !in_array($match[1], ['feed', 'index', 'show'], true)) {
    $path = '/blog/show/' . $match[1];
}

define('ASSUMED_URL', rtrim(BASE_URL, '/') . $path);
// Core reads module/method at indexes 1 and 2; retain the leading empty segment.
define('SEGMENTS', explode('/', $path));
define('REQUEST_TYPE', $_SERVER['REQUEST_METHOD']);
foreach (['flashdata', 'form', 'string', 'url', 'utilities'] as $helper) {
    require_once APPPATH . 'engine/tg_helpers/' . $helper . '_helper.php';
}

header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; frame-ancestors 'none'; form-action 'self'");
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
session_set_cookie_params(['secure' => str_starts_with(BASE_URL, 'https://'), 'httponly' => true, 'samesite' => 'Strict']);
ini_set('session.use_strict_mode', '1');
session_start();
chdir(__DIR__);
try {
    new Core();
} catch (Throwable $e) {
    http_response_code(503);
    echo 'Control plane unavailable. Use the local CLI to inspect database and installation configuration.';
}
