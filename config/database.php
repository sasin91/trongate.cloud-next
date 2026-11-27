<?php
$dsn = trim((string) (getenv('TC_DSN') ?: ''));
$db_user = getenv('TC_DB_USER');
$db_password = getenv('TC_DB_PASSWORD');

$is_production = defined('ENV') && in_array(strtolower((string) ENV), ['prod', 'production'], true);
if ($is_production && ($dsn === '' || $db_user === false || $db_password === false)) {
    throw new RuntimeException('TC_DSN, TC_DB_USER, and TC_DB_PASSWORD must be configured for production.');
}

$databases = [
    'default' => [
        'dsn' => $dsn,
        'host' => '127.0.0.1',
        'port' => '3306',
        'user' => $db_user === false ? 'root' : $db_user,
        'password' => $db_password === false ? '' : $db_password,
        'database' => 'trongate_cloud'
    ]
];