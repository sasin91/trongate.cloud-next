<?php
declare(strict_types=1);

/**
 * An app's build commands: shell lines executed inside the ephemeral FreeBSD
 * build jail after Composer install (e.g. `npm ci` and `npm run build`).
 *
 * suggest() inspects a repository's root files while an app is created and
 * prefills the commands for the package manager its lockfile names, plus
 * PHP extensions composer.json requires (ext-*) that the shared PHP runtime
 * does not enable globally.
 *
 * Framework-free: the panel and the worker both load it.
 */
final class Build_commands {
    const MAX_BYTES = 4000;
    const MAX_LINES = 40;
    const MAX_LINE_BYTES = 500;

    /** Words in the commands that need Node (pnpm and yarn come from corepack). */
    const NODE_WORDS = ['node', 'npm', 'npx', 'pnpm', 'yarn', 'corepack'];
    const BUN_WORDS = ['bun', 'bunx'];

    /**
     * Core extensions enabled globally in the shared FreeBSD PHP 8.4 runtime
     * (/usr/local/etc/php-base), including PHP's built-in extensions.
     */
    const BASE_EXTENSIONS = [
        'bcmath', 'core', 'ctype', 'curl', 'date', 'dom', 'exif', 'fileinfo', 'filter', 'gd',
        'hash', 'iconv', 'intl', 'json', 'libxml', 'mbstring', 'mysqli', 'mysqlnd', 'opcache',
        'openssl', 'pcre', 'pdo', 'pdo_mysql', 'pdo_sqlite', 'phar', 'posix', 'random',
        'readline', 'redis', 'reflection', 'session', 'simplexml', 'sodium', 'spl', 'sqlite3',
        'standard', 'tokenizer', 'xml', 'xmlreader', 'xmlwriter', 'zip', 'zlib',
    ];

    /**
     * Curated optional PHP extensions preinstalled in the shared FreeBSD runtime
     * and opted in per jail via /etc/tc/php.d/extensions.ini.
     */
    const OPTIONAL_EXTENSIONS = [
        'imagick', 'soap', 'gmp', 'pdo_pgsql', 'pgsql', 'ldap', 'sockets',
        'xsl', 'calendar', 'imap', 'ftp', 'pcntl', 'shmop', 'sysvsem',
    ];

    /** Lockfile => package manager, in the order they win. */
    const LOCKFILES = [
        'bun.lock' => 'bun',
        'bun.lockb' => 'bun',
        'pnpm-lock.yaml' => 'pnpm',
        'yarn.lock' => 'yarn',
        'package-lock.json' => 'npm',
    ];

    /**
     * What the new-app wizard prefills from the repository's root: its file
     * names and the contents of package.json and composer.json ('' when absent).
     *
     * @param list<string> $root_files
     * @return array{commands: string, php_extensions: list<string>, found: list<string>}
     */
    static function suggest(array $root_files, string $package_json, string $composer_json): array {
        $found = [];
        $commands = [];
        $package = self::json_object($package_json);

        if ($package !== null) {
            $manager = self::package_manager($root_files, $package);
            $lockfile = self::lockfile($root_files, $manager);
            $found[] = $lockfile ?? 'package.json';

            // Only a build script makes installing worthwhile for a PHP app.
            if (is_string($package['scripts']['build'] ?? null)) {
                $commands[] = self::install_command($manager, $lockfile !== null, in_array('.yarnrc.yml', $root_files, true));
                $commands[] = $manager === 'npm' ? 'npm run build' : "{$manager} run build";
            }
        }

        $extensions = self::required_extensions(self::json_object($composer_json) ?? []);

        if ($extensions !== []) {
            $found[] = 'composer.json';
        }

        return [
            'commands' => implode("\n", $commands),
            'php_extensions' => $extensions,
            'found' => $found,
        ];
    }

    /**
     * package.json's "packageManager" (e.g. pnpm@9.1.0) when it names one we
     * know, else the first lockfile's, else npm.
     *
     * @param list<string> $root_files
     */
    static function package_manager(array $root_files, array $package): string {
        $declared = strtolower((string) ($package['packageManager'] ?? ''));
        $name = explode('@', $declared)[0];

        if (in_array($name, ['npm', 'pnpm', 'yarn', 'bun'], true)) {
            return $name;
        }

        foreach (self::LOCKFILES as $file => $manager) {
            if (in_array($file, $root_files, true)) {
                return $manager;
            }
        }

        return 'npm';
    }

    /**
     * The lockfile of $manager in the root, or null.
     *
     * @param list<string> $root_files
     */
    static function lockfile(array $root_files, string $manager): ?string {
        foreach (self::LOCKFILES as $file => $file_manager) {
            if ($file_manager === $manager && in_array($file, $root_files, true)) {
                return $file;
            }
        }

        return null;
    }

    /**
     * Install exactly the locked versions when there is a lockfile.
     */
    static function install_command(string $manager, bool $locked, bool $yarn_berry): string {
        if (!$locked) {
            return "{$manager} install";
        }

        if ($manager === 'npm') {
            return 'npm ci';
        }

        if ($manager === 'yarn' && $yarn_berry) {
            return 'yarn install --immutable';
        }

        return "{$manager} install --frozen-lockfile";
    }

    /**
     * composer.json's "require" ext-* entries the base runtime lacks, lowercase.
     *
     * @return list<string>
     */
    static function required_extensions(array $composer): array {
        $require = $composer['require'] ?? [];

        if (!is_array($require)) {
            return [];
        }

        $extensions = [];

        foreach (array_keys($require) as $package) {
            $name = strtolower((string) $package);

            if (!str_starts_with($name, 'ext-')) {
                continue;
            }

            $extension = substr($name, 4);

            if (preg_match('/^[a-z][a-z0-9_]{0,39}$/', $extension) && !in_array($extension, self::BASE_EXTENSIONS, true)) {
                $extensions[] = $extension;
            }
        }

        return array_values(array_unique($extensions));
    }

    /**
     * The commands as stored: LF line ends, each line trimmed on the right,
     * no blank lines at either end.
     */
    static function normalise(string $commands): string {
        $lines = preg_split('/\r\n|\r|\n/', $commands) ?: [];
        $lines = array_map('rtrim', $lines);

        return trim(implode("\n", $lines), "\n");
    }

    /**
     * Why the commands cannot be saved, or null.
     */
    static function error(string $commands): ?string {
        $commands = self::normalise($commands);

        if (strlen($commands) > self::MAX_BYTES) {
            return 'Build commands can be at most ' . self::MAX_BYTES . ' characters.';
        }

        if (preg_match('/[\x00-\x08\x0B-\x1F\x7F]/', $commands)) {
            return 'Build commands cannot contain control characters.';
        }

        $lines = $commands === '' ? [] : explode("\n", $commands);

        if (count($lines) > self::MAX_LINES) {
            return 'Build commands can be at most ' . self::MAX_LINES . ' lines.';
        }

        foreach ($lines as $line) {
            if (strlen($line) > self::MAX_LINE_BYTES) {
                return 'Each build command line can be at most ' . self::MAX_LINE_BYTES . ' characters.';
            }
        }

        return null;
    }

    /**
     * Normalized shell commands for execution inside the FreeBSD build jail.
     */
    static function script(string $commands): string {
        $error = self::error($commands);
        if ($error !== null) {
            throw new RuntimeException($error);
        }

        return self::normalise($commands);
    }

    /**
     * The tools the commands call: node (with npm, npx, pnpm and yarn) and bun.
     *
     * @return list<string>
     */
    static function tools(string $commands): array {
        $words = preg_split('/[^a-z0-9_-]+/', strtolower($commands)) ?: [];
        $tools = [];

        if (array_intersect($words, self::NODE_WORDS) !== []) {
            $tools[] = 'node';
        }

        if (array_intersect($words, self::BUN_WORDS) !== []) {
            $tools[] = 'bun';
        }

        return $tools;
    }

    /**
     * A JSON object as an array, or null when it is not one.
     */
    static function json_object(string $json): ?array {
        if (trim($json) === '') {
            return null;
        }

        $value = json_decode($json, true);

        return is_array($value) && !array_is_list($value) ? $value : null;
    }
}
