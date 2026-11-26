<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/runtime.php';

class Freebsd_simulated_runtime extends Runtime {
    public array $commands = [];
    public array $inputs = [];
    public array $existing_datasets = [];
    public array $existing_paths = [];
    public array $running_jails = [];
    public array $rctl_jails = [];
    public ?string $bad = null;
    public bool $public_bad = false;
    public int $starts = 0;
    public array $public_attempts = [];
    public string $mock_os = 'FreeBSD';
    public bool $mock_rctl_enabled = true;
    public bool $mock_pf_enabled = true;
    public bool $mock_builder_ready = true;
    public array $allocation = ['memory_mb' => 256, 'cpu_percent' => 100, 'disk_quota_mb' => 1024];

    public function __construct(string $root) {
        parent::__construct($root);
        $this->existing_paths[$this->mariadb_socket] = true;
        $this->existing_paths[$this->valkey_socket] = true;
        self::directory($this->bases_root . '/15.1-RELEASE', 0755);
        self::directory($this->runtimes_root . '/php84', 0755);
    }

    public function path_exists(string $path): bool {
        return $this->existing_paths[$path] ?? file_exists($path);
    }

    public function run(array $args, int $timeout = 120): string {
        $this->commands[] = $args;

        if ($args === ['uname', '-s']) {
            return $this->mock_os;
        }
        if ($args === ['uname', '-m']) {
            return 'amd64';
        }
        if ($args === ['freebsd-version', '-u']) {
            return '15.1-RELEASE-p7';
        }
        if ($args === ['which', 'jail']) {
            return '/usr/sbin/jail';
        }
        if ($args === ['sysctl', '-n', 'kern.racct.enable']) {
            return $this->mock_rctl_enabled ? '1' : '0';
        }
        if ($args === ['pfctl', '-s', 'info']) {
            return $this->mock_pf_enabled ? 'Status: Enabled for 1 days' : 'Status: Disabled';
        }
        if ($args === ['caddy', 'version']) {
            return 'v2.9.1';
        }
        if ($args === ['php', '-v']) {
            return 'PHP 8.4.5 (cli)';
        }
        if ($args === ['git', '--version']) {
            return 'git version 2.48.1';
        }
        if ($args === ['zfs', 'list', '-H', '-o', 'name', $this->pool]) {
            return $this->pool;
        }
        if ($args === ['zfs', 'list', '-H', '-d', '1', '-o', 'name', $this->pool . '/tc/bases']) {
            return $this->pool . "/tc/bases\n" . $this->pool . '/tc/bases/15.1-RELEASE';
        }
        if ($args === ['zfs', 'list', '-H', '-o', 'name', $this->pool . '/tc/runtimes/php84']) {
            return $this->pool . '/tc/runtimes/php84';
        }
        if ($args === ['zfs', 'list', '-H', '-d', '1', '-o', 'name', $this->pool . '/tc/runtimes']) {
            return $this->pool . "/tc/runtimes\n" . $this->pool . '/tc/runtimes/php84';
        }
        if ($args === ['zfs', 'list', '-H', '-t', 'snapshot', '-o', 'name', $this->pool . '/tc/runtimes/builder@v1']) {
            if (!$this->mock_builder_ready) {
                throw new RuntimeException('dataset does not exist');
            }
            return $this->pool . '/tc/runtimes/builder@v1';
        }
        if (array_slice($args, 0, 5) === ['zfs', 'list', '-H', '-o', 'name']) {
            $dataset = $args[5] ?? '';
            if (isset($this->existing_datasets[$dataset])) {
                return $dataset;
            }
            throw new RuntimeException('dataset does not exist');
        }
        if (array_slice($args, 0, 2) === ['zfs', 'create'] || array_slice($args, 0, 2) === ['zfs', 'clone']) {
            $this->existing_datasets[end($args)] = true;
            return '';
        }
        if (array_slice($args, 0, 2) === ['zfs', 'destroy']) {
            $target = end($args);
            foreach (array_keys($this->existing_datasets) as $ds) {
                if ($ds === $target || str_starts_with($ds, $target . '/')) {
                    unset($this->existing_datasets[$ds]);
                }
            }
            return '';
        }
        if (array_slice($args, 0, 2) === ['jail', '-c']) {
            $this->starts++;
            $this->running_jails[end($args)] = true;
            return '';
        }
        if (array_slice($args, 0, 2) === ['jail', '-r']) {
            unset($this->running_jails[end($args)]);
            return '';
        }
        if (array_slice($args, 0, 2) === ['jls', '-j']) {
            $jail = $args[2] ?? '';
            if (isset($this->running_jails[$jail])) {
                return $jail;
            }
            throw new RuntimeException('jail not found');
        }
        if (array_slice($args, 0, 2) === ['rctl', '-a']) {
            if (preg_match('/^jail:([^:]+):/', $args[2] ?? '', $m) === 1) {
                $this->rctl_jails[$m[1]][] = $args[2];
            }
            return '';
        }
        if (count($args) === 2 && $args[0] === 'rctl' && str_starts_with($args[1], 'jail:')) {
            $jail = substr($args[1], strlen('jail:'));
            return implode("\n", $this->rctl_jails[$jail] ?? []);
        }
        if (array_slice($args, 0, 2) === ['rctl', '-r']) {
            unset($this->rctl_jails[substr($args[2] ?? '', strlen('jail:'))]);
            return '';
        }
        return '';
    }

    public function run_input(array $args, string $input, int $timeout = 120): string {
        $this->inputs[] = ['args' => $args, 'input' => $input];
        return '';
    }

    public function allocate(string $app): array {
        return $this->allocation;
    }

    public function healthy(?array $release): bool {
        return $release !== null && ($release['operation'] ?? null) !== $this->bad;
    }

    public array $public_headers = [];

    public function health(string $url, int $attempts = 20, array $headers = []): bool {
        $this->public_attempts[] = $attempts;
        $this->public_headers[] = $headers;
        if ($this->public_bad) {
            $this->public_bad = false;
            return false;
        }
        return true;
    }

    public function drain(): void {
    }
}

function runtime_request(int $number, array $overrides = []): array {
    $op = sprintf('%032x', $number);
    $base = [
        'action' => 'deploy',
        'operation' => $op,
        'app' => 'demo',
        'release' => [
            'artifact' => 'sha256:' . str_repeat('a', 64),
            'domain' => 'demo.test',
            'health' => '/',
            'php_version' => '8.4',
            'extensions' => ['imagick', 'gmp'],
            'database' => true,
            'memory_mb' => 256,
            'cpu_percent' => 100,
            'disk_quota_mb' => 1024,
            'mounts' => ['uploads' => '/app/public/uploads'],
            'migration' => [],
        ],
        'secret_values' => ['APP_ENV' => 'production', 'API_KEY' => 'top-secret'],
        'file_values' => ['/app/config/config.php' => "<?php\ndefine('BASE_URL', 'https://demo.test/');\n"],
    ];
    return array_replace_recursive($base, $overrides);
}

function with_simulated_runtime(callable $body): void {
    $root = sys_get_temp_dir() . '/tc-runtime-test-' . bin2hex(random_bytes(8));
    $runtime = new Freebsd_simulated_runtime($root);
    try {
        $body($runtime);
    } finally {
        Runtime::remove_tree($root);
    }
}
