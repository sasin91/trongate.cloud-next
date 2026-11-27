<?php
declare(strict_types=1);

final class Validate {
    static function match(string $value, string $pattern, string $name): string {
        if (!preg_match($pattern, $value)) {
            throw new RuntimeException("Invalid $name");
        }
        return $value;
    }

    static function id(string $v): string {
        return self::match($v, '/^[a-z][a-z0-9-]{0,39}$/D', 'identifier');
    }

    static function host(string $v): string {
        if (filter_var($v, FILTER_VALIDATE_IP)) {
            return $v;
        }
        return self::match($v, '/^(?=.{1,253}$)[a-zA-Z0-9](?:[a-zA-Z0-9.-]*[a-zA-Z0-9])?$/D', 'hostname');
    }

    static function domain(string $v): string {
        return self::match($v, '/^(?=.{1,253}$)[a-z0-9]+(?:[.-][a-z0-9]+)+$/D', 'domain');
    }

    static function php_version(string $v): string {
        return self::match($v, '/^8\.[3-5]$/D', 'PHP version');
    }

    static function release_digest(string $v): string {
        return self::match($v, '/^sha256:[a-f0-9]{64}$/D', 'release digest');
    }

    static function server(array $p): array {
        return [
            'host' => self::host($p['host']),
            'port' => self::number($p['port'] ?? 22, 1, 65535),
            'user' => self::match($p['user'], '/^[a-z_][a-z0-9_-]{0,31}$/D', 'SSH user'),
            'credential' => self::id($p['credential']),
        ];
    }

    static function number(mixed $v, int $min, int $max): int {
        if (filter_var($v, FILTER_VALIDATE_INT) === false || $v < $min || $v > $max) {
            throw new RuntimeException('Number outside allowed range');
        }
        return (int) $v;
    }

    static function release(array $p): array {
        if (!isset($p['artifact']) || !is_string($p['artifact'])) {
            throw new RuntimeException('Invalid release artifact');
        }
        $p['artifact'] = $p['artifact'] === 'pending' ? 'pending' : self::release_digest($p['artifact']);
        $p['domain'] = self::domain($p['domain']);
        $p['health'] = self::match($p['health'] ?? '/', '~^/[a-zA-Z0-9/_.-]*$~D', 'health path');
        $p['config_version'] = self::id($p['config_version'] ?? 'v1');
        $p['php_version'] = self::php_version($p['php_version'] ?? '8.4');

        $p['extensions'] = $p['extensions'] ?? [];
        if (!is_array($p['extensions'])) {
            throw new RuntimeException('Invalid PHP extensions');
        }
        $extensions = [];
        foreach ($p['extensions'] as $ext) {
            if (!is_string($ext)) {
                throw new RuntimeException('Invalid PHP extension');
            }
            $extensions[] = self::match($ext, '/^[a-z][a-z0-9_]{0,39}$/D', 'PHP extension');
        }
        $p['extensions'] = array_values(array_unique($extensions));

        $database = $p['database'] ?? true;
        if (!is_bool($database)) {
            throw new RuntimeException('Invalid database option');
        }
        $p['database'] = $database;

        $p['memory_mb'] = self::number($p['memory_mb'] ?? 128, 64, 32768);
        $p['cpu_percent'] = self::number($p['cpu_percent'] ?? 100, 10, 1600);
        $p['disk_quota_mb'] = self::number($p['disk_quota_mb'] ?? 128, 128, 1048576);

        $p['secrets'] = $p['secrets'] ?? [];
        if (!is_array($p['secrets'])) {
            throw new RuntimeException('Invalid secrets map');
        }
        foreach ($p['secrets'] as $name => $ref) {
            self::match((string) $name, '/^[A-Z_][A-Z0-9_]*$/D', 'environment name');
            self::id((string) $ref);
        }

        $p['file_secrets'] = $p['file_secrets'] ?? [];
        if (!is_array($p['file_secrets'])) {
            throw new RuntimeException('Invalid file secrets map');
        }
        foreach ($p['file_secrets'] as $target => $ref) {
            self::match((string) $target, '~^/app/config/[a-zA-Z0-9_-]+\.php$~D', 'configuration file');
            self::id((string) $ref);
        }

        $p['mounts'] = $p['mounts'] ?? [];
        if (!is_array($p['mounts'])) {
            throw new RuntimeException('Invalid mounts map');
        }
        foreach ($p['mounts'] as $name => $dest) {
            self::id((string) $name);
            self::match((string) $dest, '~^/app/(?:[a-zA-Z0-9_-]+/)*[a-zA-Z0-9_-]+$~D', 'persistent mount');
        }

        if (isset($p['commit'])) {
            if (!is_string($p['commit'])) {
                throw new RuntimeException('Invalid commit');
            }
            self::match($p['commit'], '/^[a-f0-9]{40}$/D', 'commit');
        }

        if (isset($p['release_path'])) {
            if (!is_string($p['release_path'])) {
                throw new RuntimeException('Invalid release path');
            }
            self::match($p['release_path'], '~^/(?:[a-zA-Z0-9][a-zA-Z0-9_.-]*/)*[a-zA-Z0-9][a-zA-Z0-9_.-]*$~D', 'release path');
        }

        $p['migration'] = $p['migration'] ?? [];
        if (!is_array($p['migration']) || count($p['migration']) > 30) {
            throw new RuntimeException('Migration must be an argument array');
        }
        foreach ($p['migration'] as $arg) {
            if (!is_string($arg) || strlen($arg) > 1000 || str_contains($arg, "\0")) {
                throw new RuntimeException('Invalid migration argument');
            }
        }
        if ($p['migration'] && ($p['schema_compatible'] ?? false) !== true) {
            throw new RuntimeException('Migrations require schema_compatible=true');
        }

        $p['modules'] = $p['modules'] ?? [];
        if (!is_array($p['modules']) || count($p['modules']) > 30) {
            throw new RuntimeException('Invalid registry modules list');
        }
        $clean_modules = [];
        $seen_names = [];
        foreach ($p['modules'] as $mod) {
            if (!is_array($mod)) {
                throw new RuntimeException('Invalid registry module entry');
            }
            $ns = self::match((string) ($mod['namespace'] ?? ''), '/^[a-z][a-z0-9-]{1,38}$/D', 'module namespace');
            $name = self::match((string) ($mod['name'] ?? ''), '/^[a-z][a-z0-9_]{0,39}$/D', 'module name');
            $ver = self::match((string) ($mod['version'] ?? ''), '/^[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?$/D', 'module version');
            if (isset($seen_names[$name])) {
                throw new RuntimeException('Duplicate registry module mount: ' . $name);
            }
            $seen_names[$name] = true;
            $entry = [
                'namespace' => $ns,
                'name' => $name,
                'version' => $ver,
                'autowire' => !empty($mod['autowire']),
            ];
            if (isset($mod['digest']) && is_string($mod['digest']) && $mod['digest'] !== '') {
                $entry['digest'] = self::release_digest($mod['digest']);
            }
            if (isset($mod['config'])) {
                if (!is_array($mod['config']) || count($mod['config']) > 64) {
                    throw new RuntimeException('Invalid module configuration');
                }
                $cfg = [];
                foreach ($mod['config'] as $k => $v) {
                    $key = self::match((string) $k, '/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/D', 'module config key');
                    if (is_string($v) && (strlen($v) > 8192 || str_contains($v, "\0"))) {
                        throw new RuntimeException('Invalid module config value');
                    }
                    if (!is_scalar($v) && $v !== null) {
                        throw new RuntimeException('Module config values must be scalar');
                    }
                    $cfg[$key] = $v;
                }
                $entry['config'] = $cfg;
            }
            $clean_modules[] = $entry;
        }
        $p['modules'] = $clean_modules;

        unset($p['image'], $p['container_port'], $p['registry_secret']);
        return $p;
    }
}
