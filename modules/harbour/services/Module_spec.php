<?php
declare(strict_types=1);

require_once APPPATH . 'modules/validation/services/Validate.php';

/**
 * Validates Module Harbour identifiers, versions, release fingerprints, install commands, and archive URLs.
 */
final class Module_spec {
    public const NAMESPACE_PATTERN = '/^[a-z][a-z0-9-]{1,38}$/D';
    public const NAME_PATTERN = '/^[a-z][a-z0-9_]{0,39}$/D';
    public const VERSION_PATTERN = '/^[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?$/D';
    public const TAG_PATTERN = '/^[a-z0-9][a-z0-9-]{0,31}$/D';
    public const MAX_TAGS = 12;
    public const MAX_APP_MODULES = 200;
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

    /**
     * Cleans the `modules/<name>` fingerprints a build reports, so release
     * metadata only ever holds a module directory name and its manifest digest.
     *
     * @return list<array{name: string, digest: string}>
     */
    public static function fingerprints(mixed $raw): array {
        if (!is_array($raw)) {
            return [];
        }
        $clean = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $name = (string) ($entry['name'] ?? '');
            $digest = (string) ($entry['digest'] ?? '');
            if (preg_match(self::NAME_PATTERN, $name) !== 1 || preg_match('/^sha256:[a-f0-9]{64}$/D', $digest) !== 1) {
                continue;
            }
            $clean[$name] = ['name' => $name, 'digest' => $digest];
            if (count($clean) >= self::MAX_APP_MODULES) {
                break;
            }
        }
        ksort($clean, SORT_STRING);
        return array_values($clean);
    }

    /**
     * The `git subtree` command that brings a published version into `modules/<name>`.
     * Pass `$update = true` for the `git subtree pull` form used on an existing install.
     */
    public static function subtree_command(string $namespace, string $name, string $version, bool $update = false): string {
        return 'git subtree ' . ($update ? 'pull' : 'add')
            . ' --prefix=modules/' . self::validate_name($name)
            . ' ' . self::git_url($namespace, $name)
            . ' v' . self::validate_version($version) . ' --squash';
    }

    /**
     * The shell command that unpacks a published archive into `modules/<name>`.
     * Archives already carry a top-level `<name>/` folder.
     */
    public static function archive_command(string $namespace, string $name, string $version): string {
        return 'curl -fsSL ' . self::archive_url($namespace, $name, $version) . ' | tar -xzf - -C modules';
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
