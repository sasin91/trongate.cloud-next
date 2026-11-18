<?php
declare(strict_types=1);
spl_autoload_register(function (string $class): void {
    // Standalone Trongate utility modules are also shared by CLI/worker entry points.
    $utilities=['App_flow'=>'app_flow','Server_flow'=>'server_flow','Hetzner'=>'hetzner'];
    if (isset($utilities[$class])) {
        require_once dirname(__DIR__).'/modules/'.$utilities[$class].'/'.$class.'.php';
        return;
    }
    if (preg_match('/^[A-Za-z_]+$/D', $class) && is_file(__DIR__ . "/$class.php")) {
        require_once __DIR__ . "/$class.php";
    }
});
function state_dir(): string {
    $path = getenv('TC_STATE') ?: dirname(__DIR__) . '/var';
    if (!is_dir($path)) { mkdir($path, 0700, true); }
    return $path;
}
function json(array $value): string { return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES); }
function installation_key(): string {
    $path = getenv('TC_KEY_FILE') ?: state_dir() . '/key';
    if (!is_file($path)) { throw new RuntimeException('Installation key missing; run init.'); }
    return trim(file_get_contents($path));
}
