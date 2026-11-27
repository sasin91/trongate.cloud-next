<?php
declare(strict_types=1);

require_once APPPATH . 'modules/validation/services/Validate.php';

/**
 * Validates Trongate Module Registry identifiers, versions, manifests, and CDN/archive URLs.
 */
final class Module_spec {
    public const NAMESPACE_PATTERN = '/^[a-z][a-z0-9-]{1,38}$/D';
    public const NAME_PATTERN = '/^[a-z][a-z0-9_]{0,39}$/D';
    public const VERSION_PATTERN = '/^[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?$/D';
    public const TAG_PATTERN = '/^[a-z0-9][a-z0-9-]{0,31}$/D';
    public const MAX_TAGS = 12;
    public const MAX_APP_MODULES = 30;
    public const MAX_UPLOAD_BYTES = 524288;

    public const CATEGORIES = [
        'general' => 'General',
        'errors' => 'Error Handling',
        'logging' => 'Logging & Observability',
        'auth' => 'Auth & Security',
        'cli' => 'CLI & Tooling',
        'database' => 'Database & Search',
        'ui' => 'UI & Templates',
        'payments' => 'Payments & Billing',
        'integrations' => 'Integrations',
    ];

    public const AUTOWIRE_CAPABLE = ['error_pages', 'log'];

    public static function validate_namespace(string $raw): string {
        $v = strtolower(trim($raw));
        if (preg_match(self::NAMESPACE_PATTERN, $v) !== 1) {
            throw new RuntimeException('Namespace must be 2-39 lowercase letters, digits, or hyphens, starting with a letter.');
        }
        return $v;
    }

    public static function validate_name(string $raw): string {
        $v = strtolower(trim($raw));
        if (preg_match(self::NAME_PATTERN, $v) !== 1) {
            throw new RuntimeException('Module name must be 1-40 lowercase letters, digits, or underscores, starting with a letter.');
        }
        return $v;
    }

    public static function validate_version(string $raw): string {
        $v = ltrim(trim($raw), 'v');
        if (preg_match(self::VERSION_PATTERN, $v) !== 1) {
            throw new RuntimeException('Version must follow semantic versioning (e.g. 1.0.0 or 1.2.0-beta.1).');
        }
        return $v;
    }

    public static function slug(string $namespace, string $name): string {
        return self::validate_namespace($namespace) . '/' . self::validate_name($name);
    }

    /**
     * @return list<string>
     */
    public static function parse_tags(string|array $raw): array {
        $items = is_array($raw)
            ? $raw
            : (preg_split('/[\s,]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $clean = [];
        foreach ($items as $item) {
            if (!is_string($item)) {
                continue;
            }
            $tag = strtolower(trim($item));
            if ($tag !== '' && preg_match(self::TAG_PATTERN, $tag) === 1) {
                $clean[] = $tag;
            }
            if (count($clean) >= self::MAX_TAGS) {
                break;
            }
        }
        return array_values(array_unique($clean));
    }

    public static function normalize_category(string $raw): string {
        $cat = strtolower(trim($raw));
        return array_key_exists($cat, self::CATEGORIES) ? $cat : 'general';
    }

    public static function can_autowire(string $name): bool {
        return in_array($name, self::AUTOWIRE_CAPABLE, true);
    }

    /**
     * Merges declarative `trongate-modules.json` entries from a build with panel-selected modules.
     * Panel toggles take precedence per module directory name so operators can pin or override
     * versions without committing to Git.
     *
     * @param list<array<string, mixed>> $manifest_modules
     * @param list<array<string, mixed>> $panel_modules
     * @return list<array{namespace: string, name: string, version: string, digest?: string, autowire: bool}>
     */
    public static function merge_app_modules(array $manifest_modules, array $panel_modules): array {
        $merged = [];
        foreach ([$manifest_modules, $panel_modules] as $source) {
            foreach ($source as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $ns = self::validate_namespace((string) ($entry['namespace'] ?? ''));
                $name = self::validate_name((string) ($entry['name'] ?? ''));
                $ver = self::validate_version((string) ($entry['version'] ?? ''));
                $item = [
                    'namespace' => $ns,
                    'name' => $name,
                    'version' => $ver,
                    'autowire' => !empty($entry['autowire']),
                ];
                if (!empty($entry['digest']) && is_string($entry['digest'])) {
                    $item['digest'] = Validate::release_digest($entry['digest']);
                }
                if (isset($entry['config']) && is_array($entry['config']) && $entry['config'] !== []) {
                    $item['config'] = self::sanitize_config($entry['config']);
                }
                if (isset($entry['secret_refs']) && is_array($entry['secret_refs']) && $entry['secret_refs'] !== []) {
                    $item['secret_refs'] = $entry['secret_refs'];
                }
                $merged[$name] = $item;
            }
        }
        ksort($merged, SORT_STRING);
        return array_slice(array_values($merged), 0, self::MAX_APP_MODULES);
    }

    /**
     * Parses `key=value` lines for custom per-module configuration.
     *
     * @return array<string, string>
     */
    public static function parse_config_lines(string $raw): array {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', trim($raw)) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            if (!str_contains($trimmed, '=')) {
                throw new RuntimeException('Module configuration must use key=value, one per line.');
            }
            [$key, $val] = explode('=', $trimmed, 2);
            $key = trim($key);
            if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/D', $key) !== 1) {
                throw new RuntimeException('Invalid configuration key "' . $key . '". Use letters, digits, and underscores.');
            }
            if (strlen($val) > 8192 || str_contains($val, "\0")) {
                throw new RuntimeException('Configuration value for "' . $key . '" is invalid or too long.');
            }
            $out[$key] = trim($val);
        }
        if (count($out) > 64) {
            throw new RuntimeException('A module can have at most 64 configuration keys.');
        }
        return $out;
    }

    /**
     * Sanitizes a scalar configuration map for a module.
     *
     * @return array<string, scalar|null>
     */
    public static function sanitize_config(array $raw): array {
        $clean = [];
        foreach ($raw as $k => $v) {
            if (!is_string($k) || preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/D', $k) !== 1) {
                continue;
            }
            if (is_string($v) && (strlen($v) > 8192 || str_contains($v, "\0"))) {
                throw new RuntimeException('Invalid configuration value for "' . $k . '".');
            }
            if (is_scalar($v) || $v === null) {
                $clean[$k] = $v;
            }
            if (count($clean) >= 64) {
                break;
            }
        }
        return $clean;
    }

    /**
     * Renders a read-only PHP configuration array file (`/app/config/<module>.php`).
     */
    public static function render_config_php(string $label, array $config): string {
        $clean = self::sanitize_config($config);
        return "<?php\n// Managed by trongate.cloud for module " . preg_replace('/[\r\n]/', '', $label) . "\nreturn " . var_export($clean, true) . ";\n";
    }

    /**
     * Returns the public registry base URL (e.g. https://registry.trongate.cloud).
     */
    public static function registry_base_url(): string {
        $url = trim((string) (getenv('TC_REGISTRY_URL') ?: ''));
        if ($url !== '') {
            return rtrim($url, '/');
        }
        if (defined('BASE_URL')) {
            return rtrim((string) BASE_URL, '/');
        }
        return 'https://registry.trongate.cloud';
    }

    /**
     * Returns the immutable archive download URL for a published module version.
     * Supports optional CDN/S3 offloading via TC_REGISTRY_ARCHIVE_BASE_URL while
     * defaulting to Caddy's ZFS ARC-backed /archives/ path (Cache-Control: immutable).
     */
    public static function archive_url(string $namespace, string $name, string $version): string {
        $ns = rawurlencode($namespace);
        $mod = rawurlencode($name);
        $ver = rawurlencode($version);
        $cdn = trim((string) (getenv('TC_REGISTRY_ARCHIVE_BASE_URL') ?: ''));
        if ($cdn !== '') {
            return rtrim($cdn, '/') . "/{$ns}/{$mod}/{$ver}.tar.gz";
        }
        return self::registry_base_url() . "/archives/{$ns}/{$mod}/{$ver}.tar.gz";
    }

    /**
     * Returns the author-namespaced Smart HTTP Git clone/subtree URL for a module.
     */
    public static function git_url(string $namespace, string $name): string {
        $ns = rawurlencode($namespace);
        $mod = rawurlencode($name);
        return self::registry_base_url() . "/git/{$ns}/{$mod}.git";
    }
}
