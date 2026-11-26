<?php
declare(strict_types=1);

/**
 * Validates FreeBSD-native project service specifications and provides curated
 * presets (e.g. Laya on native FreeBSD Python 3.12 + PyTorch).
 *
 * Mirrors OG trongate.cloud's Project_service contract: each service runs
 * always-on in an isolated rctl-bounded non-root FreeBSD jail on lo1, and apps
 * receive {NAME}_URL (e.g. LAYA_URL) on their next deploy.
 */
final class Service_spec {
    public const MAX_PER_SERVER = 10;
    public const NAME_PATTERN = '/^[a-z][a-z0-9_]{0,39}$/D';
    public const PKG_PATTERN = '/^[a-zA-Z0-9][a-zA-Z0-9._+-]{0,63}$/D';
    public const ENV_NAME_PATTERN = '/^[A-Z_][A-Z0-9_]*$/D';
    public const MIN_MEMORY_MB = 64;
    public const MAX_MEMORY_MB = 8192;
    public const DEFAULT_MEMORY_MB = 512;
    public const DEFAULT_PORT = 8080;
    public const MAX_PACKAGES = 30;
    public const MAX_ENV_VARS = 64;
    public const MAX_ENV_VALUE_BYTES = 8192;

    static function name_error(string $name): ?string {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            return 'Use a lowercase name of up to 40 letters, digits, and underscores, starting with a letter (e.g. laya).';
        }
        return null;
    }

    static function port_error(int $port): ?string {
        return $port >= 1024 && $port <= 65535
            ? null
            : 'Port must be between 1024 and 65535 (services run without root).';
    }

    static function memory_error(int $memory_mb): ?string {
        return $memory_mb >= self::MIN_MEMORY_MB && $memory_mb <= self::MAX_MEMORY_MB
            ? null
            : 'Memory must be between ' . self::MIN_MEMORY_MB . ' and ' . self::MAX_MEMORY_MB . ' MB.';
    }

    static function command_error(string $command): ?string {
        $command = trim($command);
        if ($command === '' || strlen($command) > 500) {
            return 'Enter a start command up to 500 characters (e.g. python3.12 -m laya.serve).';
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $command)) {
            return 'Start command must be a single line with no control characters.';
        }
        return null;
    }

    static function setup_error(string $setup): ?string {
        return Build_commands::error($setup);
    }

    /**
     * Parses space/comma/newline-separated FreeBSD package names.
     *
     * @return list<string>
     */
    static function parse_packages(string $raw): array {
        $items = preg_split('/[\s,]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($items) > self::MAX_PACKAGES) {
            throw new RuntimeException('A service can install at most ' . self::MAX_PACKAGES . ' packages.');
        }
        $clean = [];
        foreach ($items as $pkg) {
            if (preg_match(self::PKG_PATTERN, $pkg) !== 1) {
                throw new RuntimeException('Invalid FreeBSD package name: ' . $pkg);
            }
            $clean[] = $pkg;
        }
        return array_values(array_unique($clean));
    }

    /**
     * Parses KEY=value lines (blank lines and # comments ignored).
     *
     * @return array<string, string>
     */
    static function parse_env(string $raw): array {
        $env = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            if (!str_contains($trimmed, '=')) {
                throw new RuntimeException('Environment variables must use NAME=value, one per line.');
            }
            [$name, $value] = explode('=', $trimmed, 2);
            $name = trim($name);
            if (preg_match(self::ENV_NAME_PATTERN, $name) !== 1) {
                throw new RuntimeException('Invalid environment variable name "' . $name . '". Use uppercase letters, numbers, and underscores.');
            }
            if (strlen($value) > self::MAX_ENV_VALUE_BYTES) {
                throw new RuntimeException($name . ' is too long.');
            }
            $env[$name] = $value;
        }
        if (count($env) > self::MAX_ENV_VARS) {
            throw new RuntimeException('A service can have at most ' . self::MAX_ENV_VARS . ' environment variables.');
        }
        return $env;
    }

    static function url_variable(string $name): string {
        return strtoupper($name) . '_URL';
    }

    /**
     * Returns a tc-runtime-compatible service identifier ([a-z][a-z0-9_]{0,39}).
     * Global services keep their plain name; app-scoped services get a
     * deterministic, collision-free name scoped to the owning app.
     */
    static function runtime_name(string $name, ?string $app = null): string {
        if ($app === null || $app === '') {
            return $name;
        }
        $app_part = substr(str_replace('-', '_', $app), 0, 15);
        $name_part = substr($name, 0, 14);
        $hash = substr(hash('sha256', $app . '/' . $name), 0, 8);
        return $app_part . '_' . $name_part . '_' . $hash;
    }

    /**
     * Curated native FreeBSD service presets shown in the Add a service form.
     *
     * @return array<string, array<string, mixed>>
     */
    static function presets(): array {
        return [
            'laya' => [
                'label' => 'Laya (AI verdict model)',
                'name' => 'laya',
                'packages' => 'py312-pytorch py312-transformers py312-safetensors py312-tokenizers py312-fastapi py312-uvicorn py312-pip',
                'setup' => implode("\n", [
                    'mkdir -p /var/db/service/venv /var/db/service/hf',
                    'python3.12 -m venv --system-site-packages /var/db/service/venv',
                    '/var/db/service/venv/bin/pip install --no-cache-dir --no-deps "laya==0.4.0"',
                    '/var/db/service/venv/bin/pip install --no-cache-dir python-multipart',
                    'HF_HUB_OFFLINE=0 TRANSFORMERS_OFFLINE=0 HF_HOME=/var/db/service/hf LAYA_REVISION=reviewed /var/db/service/venv/bin/python -c "import soundfile, laya; laya.load(\'convaiinnovations/laya\', device=\'cpu\')"',
                ]),
                'command' => '/var/db/service/venv/bin/python -m laya.serve',
                'port' => 8080,
                'memory_mb' => 4096,
                'env' => implode("\n", [
                    'HF_HOME=/var/db/service/hf',
                    'HF_HUB_OFFLINE=1',
                    'TRANSFORMERS_OFFLINE=1',
                    'OMP_NUM_THREADS=2',
                    'OPENBLAS_NUM_THREADS=2',
                    'LAYA_REVISION=reviewed',
                    'LAYA_MODELS=english',
                    'LAYA_DEFAULT_MODEL=english',
                    'LAYA_DEVICE=cpu',
                    'LAYA_MAX_LOADED=1',
                    'LAYA_THREADS=2',
                    'LAYA_HOST=0.0.0.0',
                    'LAYA_PORT=8080',
                ]),
            ],
            'python' => [
                'label' => 'Python 3.12 HTTP',
                'name' => 'worker',
                'packages' => 'python312 py312-pip py312-uvicorn py312-fastapi',
                'setup' => '',
                'command' => 'python3.12 -m http.server "$PORT" --bind 0.0.0.0',
                'port' => 8080,
                'memory_mb' => 512,
                'env' => '',
            ],
        ];
    }
}
