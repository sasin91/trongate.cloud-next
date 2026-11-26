<?php
declare(strict_types=1);
define('APPPATH', __DIR__ . '/');
require_once APPPATH . 'engine/Trongate.php';
spl_autoload_register(function (string $class): void {
    $services = [
        'Build' => 'apps',
        'Build_commands' => 'apps',
        'Trongate_config' => 'apps',
        'Worker' => 'jobs',
        'Job_context' => 'jobs',
        'Markdown' => 'news',
        'Posts' => 'news',
        'Ssh' => 'servers',
        'Service_spec' => 'services',
        'Access_policy' => 'team',
        'Mailer' => 'team',
        'Encryptor' => 'trongate_security',
        'Redact' => 'trongate_security',
        'Validate' => 'validation',
    ];
    if (in_array($class, ['Model', 'Modules', 'Http_client', 'Process'], true)) {
        require_once APPPATH . 'engine/' . $class . '.php';
    } elseif (isset($services[$class])) {
        require_once APPPATH . 'modules/' . $services[$class] . '/services/' . $class . '.php';
    } elseif (preg_match('/^[A-Z][A-Za-z_]*$/D', $class)) {
        // CLI and worker processes load the same conventional module controllers and models.
        $module = preg_replace('/_model$/', '', strtolower($class));
        $path = APPPATH . 'modules/' . $module . '/' . $class . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    }
});
function state_dir(): string {
    $path = getenv('TC_STATE') ?: APPPATH . 'var';
    if (!is_dir($path)) {
        mkdir($path, 0700, true);
    }
    return $path;
}
function installation_key(): string {
    $path = getenv('TC_KEY_FILE') ?: state_dir() . '/key';
    if (!is_file($path)) {
        throw new RuntimeException('Installation key missing; run init.');
    }
    return trim(file_get_contents($path));
}
