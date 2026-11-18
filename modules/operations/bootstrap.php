<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/engine/Trongate.php';
spl_autoload_register(function (string $class): void {
    $services = [
        'Build' => 'apps',
        'Build_commands' => 'apps',
        'Control' => 'operations',
        'Worker' => 'operations',
        'Process' => 'operations',
        'DeployRs' => 'deployments',
        'HostDeployer' => 'deployments',
        'Ssh' => 'servers',
        'Hetzner' => 'providers',
        'Http' => 'providers',
        'ProviderError' => 'providers',
        'Encryptor' => 'trongate_security',
        'Redact' => 'trongate_security',
        'Validate' => 'validation',
    ];
    if (in_array($class, ['Model', 'Modules'], true)) {
        require_once dirname(__DIR__, 2) . '/engine/' . $class . '.php';
    } elseif (isset($services[$class])) {
        require_once dirname(__DIR__) . '/' . $services[$class] . '/services/' . $class . '.php';
    } elseif (preg_match('/^[A-Z][A-Za-z_]*$/D', $class)) {
        // CLI and worker processes load the same conventional module controllers and models.
        $module = preg_replace('/_model$/', '', strtolower($class));
        $path = dirname(__DIR__, 2) . '/modules/' . $module . '/' . $class . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    }
});
function state_dir(): string {
    $path = getenv('TC_STATE') ?: dirname(__DIR__, 2) . '/var';
    if (!is_dir($path)) {
        mkdir($path, 0700, true);
    }
    return $path;
}
function tc_json(array $value): string {
    return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}
function installation_key(): string {
    $path = getenv('TC_KEY_FILE') ?: state_dir() . '/key';
    if (!is_file($path)) {
        throw new RuntimeException('Installation key missing; run init.');
    }
    return trim(file_get_contents($path));
}
