<?php
declare(strict_types=1);
final class Validate {
    static function match(string $value, string $pattern, string $name): string {
        if (!preg_match($pattern, $value)) { throw new RuntimeException("Invalid $name"); } return $value;
    }
    static function id(string $v): string { return self::match($v,'/^[a-z][a-z0-9-]{0,39}$/D','identifier'); }
    static function host(string $v): string {
        if (filter_var($v,FILTER_VALIDATE_IP)) { return $v; }
        return self::match($v,'/^(?=.{1,253}$)[a-zA-Z0-9](?:[a-zA-Z0-9.-]*[a-zA-Z0-9])?$/D','hostname');
    }
    static function domain(string $v): string { return self::match($v,'/^(?=.{1,253}$)[a-z0-9]+(?:[.-][a-z0-9]+)+$/D','domain'); }
    static function image(string $v): string { return self::match($v,'~^[a-z0-9][a-z0-9./:_-]+@sha256:[a-f0-9]{64}$~D','image digest'); }
    static function server(array $p): array {
        return ['host'=>self::host($p['host']), 'port'=>self::number($p['port'] ?? 22,1,65535), 'user'=>self::match($p['user'],'/^[a-z_][a-z0-9_-]{0,31}$/D','SSH user'), 'credential'=>self::id($p['credential'])];
    }
    static function number(mixed $v, int $min, int $max): int {
        if (filter_var($v,FILTER_VALIDATE_INT) === false || $v < $min || $v > $max) { throw new RuntimeException('Number outside allowed range'); } return (int)$v;
    }
    static function release(array $p): array {
        $p['image'] = self::image($p['image']); $p['domain'] = self::domain($p['domain']);
        $p['health'] = self::match($p['health'] ?? '/', '~^/[a-zA-Z0-9/_.-]*$~D','health path');
        $p['config_version'] = self::id($p['config_version'] ?? 'v1');
        $p['memory_mb'] = self::number($p['memory_mb'] ?? 256,64,32768);
        $p['cpus'] = self::number($p['cpus'] ?? 1,1,32);
        $p['container_port'] = self::number($p['container_port'] ?? 8080,1,65535);
        $p['secrets'] = $p['secrets'] ?? [];
        foreach ($p['secrets'] as $name=>$ref) { self::match($name,'/^[A-Z_][A-Z0-9_]*$/D','environment name'); self::id($ref); }
        $p['file_secrets'] = $p['file_secrets'] ?? [];
        foreach ($p['file_secrets'] as $target=>$ref) { self::match($target,'~^/app/config/[a-zA-Z0-9_-]+\.php$~D','configuration file'); self::id($ref); }
        if (isset($p['registry_secret'])) { self::id($p['registry_secret']); }
        $p['mounts'] = $p['mounts'] ?? [];
        foreach ($p['mounts'] as $name=>$dest) { self::id($name); self::match($dest,'~^/app/(?:[a-zA-Z0-9_-]+/)*[a-zA-Z0-9_-]+$~D','persistent mount'); }
        if (isset($p['commit'])) { self::match($p['commit'],'/^[a-f0-9]{40}$/D','commit'); }
        $p['migration'] = $p['migration'] ?? [];
        if (!is_array($p['migration']) || count($p['migration']) > 30) { throw new RuntimeException('Migration must be an argument array'); }
        foreach ($p['migration'] as $arg) { if (!is_string($arg) || strlen($arg)>1000 || str_contains($arg,"\0")) { throw new RuntimeException('Invalid migration argument'); } }
        if ($p['migration'] && ($p['schema_compatible'] ?? false) !== true) { throw new RuntimeException('PoC requires backward-compatible migrations'); }
        return $p;
    }
}
