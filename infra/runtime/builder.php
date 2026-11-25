<?php
declare(strict_types=1);

/**
 * Ephemeral FreeBSD build jail runner.
 *
 * Clones `zroot/tc/runtimes/builder@v1` under strict rctl hard caps, fetches
 * exact commits with symlink-escape and Trongate layout checks, executes
 * Composer and optional build commands strictly inside the jail via `jexec`,
 * stages immutable release directories, and computes a deterministic SHA-256
 * manifest digest.
 */
class Builder {
    public const DEFAULT_POOL = 'zroot';
    public const MIN_FREE_BYTES = 2 * 1024 * 1024 * 1024;
    public const BUILD_MEMORY_LIMIT = '1536M';
    public const BUILD_CPU_PERCENT = 200;
    public const BUILD_MAXPROC = 256;
    public const BUILD_OPENFILES = 2048;
    public const BUILD_JAIL_IP = '10.99.254.1';
    public const MAX_COMMAND_BYTES = 4000;
    public const MAX_COMMAND_LINES = 40;
    public const MAX_LINE_BYTES = 500;
    public const OPTIONAL_EXTENSIONS = [
        'imagick',
        'soap',
        'gmp',
        'pdo_pgsql',
        'pgsql',
        'ldap',
        'sockets',
        'xsl',
        'calendar',
        'imap',
        'pcntl',
        'shmop',
        'sysvsem',
        'ftp',
    ];

    public string $root;
    public string $pool;
    public string $releases_root;
    public string $jails_root;
    public string $jail_conf_dir;
    public string $bases_root;
    public string $runtimes_root;

    public function __construct(
        ?string $root = null,
        string $pool = self::DEFAULT_POOL,
        ?string $releases_root = null,
        ?string $jails_root = null,
        ?string $jail_conf_dir = null,
        ?string $bases_root = null,
        ?string $runtimes_root = null,
    ) {
        $this->root = $root ?? '/var/db/tc/builds';
        $production = $this->root === '/var/db/tc/builds';
        $this->pool = $pool;
        $this->releases_root = $releases_root ?? ($production ? '/' . $pool . '/tc/apps' : $this->root . '/apps');
        $this->jails_root = $jails_root ?? ($production ? '/' . $pool . '/tc/jails' : $this->root . '/jails');
        $this->jail_conf_dir = $jail_conf_dir ?? ($production ? '/etc/jail.conf.d' : $this->root . '/jail.conf.d');
        $this->bases_root = $bases_root ?? ($production ? '/' . $pool . '/tc/bases' : $this->root . '/bases');
        $this->runtimes_root = $runtimes_root ?? ($production ? '/' . $pool . '/tc/runtimes' : $this->root . '/runtimes');
    }

    public function run(array $args, int $timeout = 1800): string {
        $output = tmpfile();
        $errors = tmpfile();
        if ($output === false || $errors === false) {
            throw new RuntimeException('Cannot allocate builder streams');
        }
        $process = proc_open($args, [0 => ['pipe', 'r'], 1 => $output, 2 => $errors], $pipes);
        if (!is_resource($process)) {
            fclose($output);
            fclose($errors);
            throw new RuntimeException('builder step failed: ' . basename((string) $args[0]));
        }
        fclose($pipes[0]);
        $deadline = microtime(true) + $timeout;
        try {
            do {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    break;
                }
                if (microtime(true) >= $deadline) {
                    proc_terminate($process, 9);
                    throw new RuntimeException('builder step timed out: ' . basename((string) $args[0]));
                }
                usleep(20000);
            } while (true);
            if ($status['exitcode'] !== 0) {
                throw new RuntimeException('builder step failed: ' . basename((string) $args[0]));
            }
            rewind($output);
            return trim((string) stream_get_contents($output));
        } finally {
            proc_close($process);
            fclose($output);
            fclose($errors);
        }
    }

    public function free_space(): float {
        $space = disk_free_space($this->root);
        if ($space === false) {
            throw new RuntimeException('Cannot determine builder free space');
        }
        return $space;
    }

    public function build_in_jail(
        string $operation,
        string $app,
        string $source,
        string $php_version,
        array $extensions,
        string $commands,
    ): void {
        $suffix = substr($operation, 0, 16);
        $jail_name = 'tc_build_' . $suffix;
        $jail_dataset = $this->pool . '/tc/jails/build-' . $suffix;
        $jail_root = $this->jails_root . '/build-' . $suffix;

        self::directory($this->jail_conf_dir, 0755);
        $conf_path = $this->jail_conf_dir . '/' . $jail_name . '.conf';
        $fstab_path = $this->jail_conf_dir . '/' . $jail_name . '.fstab';

        $this->run(['zfs', 'clone', '-o', 'quota=2G', $this->pool . '/tc/skeleton@v1', $jail_dataset], 60);
        $started = false;
        $pf_added = false;
        $rctl_applied = false;
        $primary_error = null;

        try {
            foreach ([
                '/.base' => 0755,
                '/usr/local' => 0755,
                '/app' => 0755,
                '/etc/tc' => 0755,
                '/etc/tc/php.d' => 0755,
                '/tmp' => 01777,
            ] as $sub => $mode) {
                self::directory($jail_root . $sub, $mode);
                if (PHP_OS_FAMILY !== 'Windows') {
                    chmod($jail_root . $sub, $mode);
                }
            }

            $ext_lines = ['; Managed by trongate.cloud builder'];
            foreach ($extensions as $ext) {
                $ext_lines[] = 'extension=' . $ext . '.so';
            }
            $this->write_raw($jail_root . '/etc/tc/php.d/extensions.ini', implode("\n", $ext_lines) . "\n", 0644);

            $base_dir = $this->resolve_base_dir();
            $builder_runtime = $this->runtimes_root . '/builder';
            $fstab = implode("\n", [
                $base_dir . ' ' . $jail_root . '/.base nullfs ro 0 0',
                $builder_runtime . ' ' . $jail_root . '/usr/local nullfs ro 0 0',
                $source . ' ' . $jail_root . '/app nullfs rw 0 0',
            ]) . "\n";
            $this->write_raw($fstab_path, $fstab, 0600);

            $poststart = "/usr/bin/rctl -a jail:{$jail_name}:memoryuse:deny=" . self::BUILD_MEMORY_LIMIT
                . " && /usr/bin/rctl -a jail:{$jail_name}:swapuse:deny=" . self::BUILD_MEMORY_LIMIT
                . " && /usr/bin/rctl -a jail:{$jail_name}:pcpu:deny=" . self::BUILD_CPU_PERCENT
                . " && /usr/bin/rctl -a jail:{$jail_name}:maxproc:deny=" . self::BUILD_MAXPROC
                . " && /usr/bin/rctl -a jail:{$jail_name}:openfiles:deny=" . self::BUILD_OPENFILES;

            $jail_ip = self::BUILD_JAIL_IP;
            $jail_conf = $jail_name . " {\n"
                . "    host.hostname = \"build-{$suffix}.tc.internal\";\n"
                . "    path = \"{$jail_root}\";\n"
                . "    ip4.addr = \"lo1|{$jail_ip}/32\";\n"
                . "    mount.fstab = \"{$fstab_path}\";\n"
                . "    mount.devfs;\n"
                . "    devfs_ruleset = 4;\n"
                . "    allow.raw_sockets = 0;\n"
                . "    allow.mount = 0;\n"
                . "    allow.chflags = 0;\n"
                . "    enforce_statfs = 2;\n"
                . "    exec.clean;\n"
                . "    exec.start = \"/sbin/ldconfig /lib /usr/lib /usr/local/lib\";\n"
                . "    persist;\n"
                . "    exec.poststart = \"{$poststart}\";\n"
                . "    exec.poststop = \"/usr/bin/rctl -r jail:{$jail_name} || true\";\n"
                . "}\n";
            $this->write_raw($conf_path, $jail_conf, 0600);

            $this->run(['pfctl', '-t', 'tc_jails', '-T', 'add', $jail_ip], 30);
            $pf_added = true;

            $this->run(['jail', '-c', '-f', $conf_path, $jail_name], 60);
            $started = true;

            foreach ([
                "jail:{$jail_name}:memoryuse:deny=" . self::BUILD_MEMORY_LIMIT,
                "jail:{$jail_name}:swapuse:deny=" . self::BUILD_MEMORY_LIMIT,
                "jail:{$jail_name}:pcpu:deny=" . self::BUILD_CPU_PERCENT,
                "jail:{$jail_name}:maxproc:deny=" . self::BUILD_MAXPROC,
                "jail:{$jail_name}:openfiles:deny=" . self::BUILD_OPENFILES,
            ] as $rule) {
                $this->run(['rctl', '-a', $rule], 30);
            }
            $rctl_applied = true;

            if (is_file($source . '/composer.json')) {
                $this->run([
                    'jexec',
                    $jail_name,
                    'env',
                    '-i',
                    'HOME=/tmp',
                    'PATH=/usr/local/bin:/usr/bin:/bin',
                    'PHP_INI_SCAN_DIR=/usr/local/etc/php-base:/etc/tc/php.d',
                    'COMPOSER_HOME=/tmp/.composer',
                    'COMPOSER_NO_INTERACTION=1',
                    'composer',
                    'install',
                    '--working-dir=/app',
                    '--no-dev',
                    '--no-interaction',
                    '--prefer-dist',
                    '--optimize-autoloader',
                ], 1200);
            }

            if ($commands !== '') {
                $script = "set -e\ncd /app\n" . $commands . "\n";
                $encoded = base64_encode($script);
                $this->run([
                    'jexec',
                    $jail_name,
                    '/usr/local/bin/php',
                    '-r',
                    'file_put_contents("/tmp/tc-build-cmd.sh", base64_decode($argv[1], true));',
                    '--',
                    $encoded,
                ], 30);
                $this->run([
                    'jexec',
                    $jail_name,
                    'env',
                    '-i',
                    'HOME=/tmp',
                    'PATH=/usr/local/bin:/usr/bin:/bin',
                    'PHP_INI_SCAN_DIR=/usr/local/etc/php-base:/etc/tc/php.d',
                    'CI=true',
                    'COREPACK_ENABLE_DOWNLOAD_PROMPT=0',
                    '/bin/sh',
                    '-e',
                    '/tmp/tc-build-cmd.sh',
                ], 1200);
                $this->run(['jexec', $jail_name, 'rm', '-f', '/tmp/tc-build-cmd.sh'], 30);
            }
        } catch (Throwable $error) {
            $primary_error = $error;
        } finally {
            $cleanup = function () use ($started, $pf_added, $rctl_applied, $jail_name, $jail_dataset, $conf_path, $fstab_path): void {
                if ($started) {
                    $this->run(['jail', '-r', $jail_name], 60);
                }
                if ($pf_added) {
                    $this->run(['pfctl', '-t', 'tc_jails', '-T', 'delete', self::BUILD_JAIL_IP], 30);
                }
                if ($rctl_applied && $this->has_rctl_rules($jail_name)) {
                    $this->run(['rctl', '-r', "jail:{$jail_name}"], 30);
                }
                $this->run(['zfs', 'destroy', '-r', $jail_dataset], 60);
                self::remove_file($conf_path);
                self::remove_file($fstab_path);
            };
            if ($primary_error !== null) {
                try {
                    $cleanup();
                } catch (Throwable $cleanup_error) {
                    fwrite(STDERR, 'tc-build cleanup error: ' . $cleanup_error->getMessage() . "\n");
                }
                throw $primary_error;
            }
            $cleanup();
        }
    }

    public function build(array $request): array {
        $operation = self::match($request['operation'] ?? null, '[a-f0-9]{32}');
        $app = self::match($request['app'] ?? null, '[a-z][a-z0-9-]{0,39}');
        $repository = self::match($request['repository'] ?? null, '[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+');
        $commit = self::match($request['commit'] ?? null, '[a-f0-9]{40}');
        $php_version = self::match($request['php_version'] ?? '8.4', '8\.[3-5]');

        $raw_extensions = $request['extensions'] ?? [];
        if (!is_array($raw_extensions)) {
            throw new InvalidArgumentException('invalid builder extensions');
        }
        $extensions = [];
        foreach ($raw_extensions as $ext) {
            $ext = self::match($ext, '[a-z][a-z0-9_]{0,39}');
            if (!in_array($ext, self::OPTIONAL_EXTENSIONS, true)) {
                throw new InvalidArgumentException('unsupported optional PHP extension');
            }
            $extensions[] = $ext;
        }
        $extensions = array_values(array_unique($extensions));

        $commands = $this->validate_commands($request['commands'] ?? '');

        self::directory($this->root, 0700);
        $result_path = $this->root . '/' . $operation . '.json';
        $lock = fopen($this->root . '/lock', 'ab');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('Cannot lock builder');
        }

        try {
            if (is_file($result_path)) {
                $recorded = json_decode((string) file_get_contents($result_path), true, 512, JSON_THROW_ON_ERROR);
                return ($recorded['status'] ?? '') === 'succeeded'
                    ? $recorded
                    : ['status' => 'uncertain', 'reason' => 'interrupted build; inspect builder before issuing a new operation'];
            }

            if ($this->free_space() < self::MIN_FREE_BYTES) {
                return ['status' => 'failed', 'reason' => 'Build needs at least 2 GB of free disk space. Free space before building.'];
            }

            // Persist before work that may outlive the SSH session; never repeat an interrupted build.
            $this->write($result_path, ['status' => 'running', 'operation' => $operation, 'app' => $app]);

            $work = sys_get_temp_dir() . '/tc-build-' . bin2hex(random_bytes(16));
            if (!mkdir($work, 0700) && !is_dir($work)) {
                throw new RuntimeException('Cannot create build workspace');
            }

            try {
                $source = $work . '/src';
                $this->run(['git', 'init', $source], 120);
                $this->run(['git', '-C', $source, 'remote', 'add', 'origin', 'https://github.com/' . $repository . '.git'], 60);
                $this->run(['git', '-C', $source, '-c', 'protocol.file.allow=never', 'fetch', '--depth=1', 'origin', $commit], 600);
                $this->run(['git', '-C', $source, 'checkout', '--detach', 'FETCH_HEAD'], 120);

                if ($this->run(['git', '-C', $source, 'rev-parse', 'HEAD'], 60) !== $commit) {
                    throw new RuntimeException('source commit mismatch');
                }

                $this->verify_source_tree($source);
                $this->build_in_jail($operation, $app, $source, $php_version, $extensions, $commands);
                $this->verify_source_tree($source);

                $release_path = $this->stage_release($app, $operation, $source);
                $artifact = $this->manifest_digest($release_path);

                $output = [
                    'status' => 'succeeded',
                    'operation' => $operation,
                    'app' => $app,
                    'artifact' => $artifact,
                    'release_path' => $release_path,
                    'commit' => $commit,
                ];
                $this->write($result_path, $output);
                return $output;
            } finally {
                $this->remove_tree($work);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function verify_source_tree(string $source): void {
        $source_root = realpath($source);
        if ($source_root === false) {
            throw new RuntimeException('source directory is missing');
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isLink()) {
                $resolved = realpath($item->getPathname());
                if ($resolved === false || ($resolved !== $source_root && !str_starts_with($resolved, $source_root . DIRECTORY_SEPARATOR))) {
                    throw new RuntimeException('source symlink escapes context');
                }
            }
        }
        if (!is_file($source . '/public/index.php') || !is_file($source . '/config/config.php')) {
            throw new RuntimeException('source must be a Trongate application');
        }
    }

    private function stage_release(string $app, string $operation, string $source): string {
        $release_dataset = $this->pool . '/tc/apps/' . $app . '/releases/' . $operation;
        if (!$this->dataset_exists($release_dataset)) {
            $this->run(['zfs', 'create', '-p', $release_dataset], 30);
        }

        $release_path = $this->releases_root . '/' . $app . '/releases/' . $operation;
        self::directory($release_path, 0755);
        $this->copy_tree($source, $release_path, skip_git: true);

        return $release_path;
    }

    private function copy_tree(string $source, string $target, bool $skip_git = false): void {
        self::directory($target, 0755);
        foreach (new FilesystemIterator($source, FilesystemIterator::SKIP_DOTS) as $entry) {
            $name = $entry->getFilename();
            if ($skip_git && $name === '.git') {
                continue;
            }
            $src_path = $entry->getPathname();
            $dst_path = $target . '/' . $name;
            if ($entry->isLink()) {
                $link_target = readlink($src_path);
                if ($link_target === false || !symlink($link_target, $dst_path)) {
                    throw new RuntimeException('Cannot stage release symlink');
                }
            } elseif ($entry->isDir()) {
                $this->copy_tree($src_path, $dst_path, false);
            } else {
                if (!copy($src_path, $dst_path)) {
                    throw new RuntimeException('Cannot stage release file');
                }
                if (PHP_OS_FAMILY !== 'Windows') {
                    chmod($dst_path, is_executable($src_path) ? 0755 : 0644);
                }
            }
        }
    }

    private function manifest_digest(string $release_path): string {
        $root = realpath($release_path);
        if ($root === false) {
            throw new RuntimeException('Cannot inspect staged release');
        }
        $entries = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $full = $item->getPathname();
            $relative = str_replace('\\', '/', substr($full, strlen($root) + 1));
            if ($item->isLink()) {
                $entries[$relative] = 'L ' . $relative . ' ' . (string) readlink($full);
            } elseif ($item->isDir()) {
                $entries[$relative] = 'D ' . $relative;
            } else {
                $hash = hash_file('sha256', $full);
                if ($hash === false) {
                    throw new RuntimeException('Cannot hash staged release file');
                }
                $entries[$relative] = 'F ' . $relative . ' ' . $hash;
            }
        }
        ksort($entries, SORT_STRING);
        $manifest = implode("\n", $entries) . "\n";
        return 'sha256:' . hash('sha256', $manifest);
    }

    private function validate_commands(mixed $raw): string {
        if (!is_string($raw)) {
            throw new InvalidArgumentException('invalid build commands');
        }
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $lines = array_map('rtrim', $lines);
        $normalized = trim(implode("\n", $lines), "\n");

        if (strlen($normalized) > self::MAX_COMMAND_BYTES) {
            throw new InvalidArgumentException('build commands exceed size limit');
        }
        if (preg_match('/[\x00-\x08\x0B-\x1F\x7F]/', $normalized) === 1) {
            throw new InvalidArgumentException('build commands contain control characters');
        }
        $check_lines = $normalized === '' ? [] : explode("\n", $normalized);
        if (count($check_lines) > self::MAX_COMMAND_LINES) {
            throw new InvalidArgumentException('build commands exceed line limit');
        }
        foreach ($check_lines as $line) {
            if (strlen($line) > self::MAX_LINE_BYTES) {
                throw new InvalidArgumentException('build command line exceeds length limit');
            }
        }
        return $normalized;
    }

    private function write(string $path, array $value): void {
        $this->write_raw($path, json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 0600);
    }

    private function write_raw(string $path, string $data, int $mode): void {
        self::directory(dirname($path), 0700);
        $temporary = $path . '.tmp.' . bin2hex(random_bytes(4));
        $handle = fopen($temporary, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Cannot write build journal');
        }
        try {
            if (PHP_OS_FAMILY !== 'Windows') {
                chmod($temporary, $mode | 0200);
            }
            if (fwrite($handle, $data) !== strlen($data) || !fflush($handle) || !fsync($handle)) {
                throw new RuntimeException('Cannot persist build journal');
            }
        } finally {
            fclose($handle);
        }
        if (!rename($temporary, $path)) {
            self::remove_file($temporary);
            throw new RuntimeException('Cannot publish build journal');
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            chmod($path, $mode);
            $directory = fopen(dirname($path), 'r');
            if ($directory === false) {
                throw new RuntimeException('Cannot sync builder state directory');
            }
            try {
                if (!fsync($directory)) {
                    throw new RuntimeException('Cannot sync builder state directory');
                }
            } finally {
                fclose($directory);
            }
        }
    }

    private function resolve_base_dir(): string {
        $candidates = glob($this->bases_root . '/*', GLOB_ONLYDIR) ?: [];
        if ($candidates !== []) {
            sort($candidates);
            return end($candidates);
        }
        return $this->bases_root . '/current';
    }

    private function dataset_exists(string $dataset): bool {
        try {
            $this->run(['zfs', 'list', '-H', '-o', 'name', $dataset], 15);
            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    private function has_rctl_rules(string $jail_name): bool {
        try {
            return trim($this->run(['rctl', "jail:{$jail_name}"], 15)) !== '';
        } catch (RuntimeException) {
            return false;
        }
    }

    private function remove_tree(string $path): void {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_link($path) || !is_dir($path)) {
            unlink($path);
            return;
        }
        foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $item) {
            $this->remove_tree($item->getPathname());
        }
        rmdir($path);
    }

    private static function directory(string $path, int $mode = 0700): void {
        if (!is_dir($path)) {
            if (!mkdir($path, $mode, true) && !is_dir($path)) {
                throw new RuntimeException('Cannot create builder directory');
            }
            if (PHP_OS_FAMILY !== 'Windows') {
                chmod($path, $mode);
            }
        }
    }

    private static function remove_file(string $path): void {
        if (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }

    private static function match(mixed $value, string $expression): string {
        if (!is_string($value) || preg_match('~\A(?:' . str_replace('~', '\\~', $expression) . ')\z~D', $value) !== 1) {
            throw new InvalidArgumentException('invalid builder input');
        }
        return $value;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    umask(0077);
    try {
        $data = stream_get_contents(STDIN, 1048577);
        if (!is_string($data) || strlen($data) > 1048576) {
            throw new InvalidArgumentException('request too large');
        }
        $request = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        $builder = new Builder();
        if (($request['action'] ?? '') === 'metrics') {
            require_once __DIR__ . '/runtime.php';
            require_once __DIR__ . '/metrics.php';
            $result = Metrics::collect($builder->root);
        } else {
            $result = $builder->build($request);
        }
        echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
    } catch (Throwable $error) {
        // Never disclose request bodies, credentials or customer process output.
        echo json_encode(['status' => 'uncertain', 'error' => get_class($error)], JSON_UNESCAPED_SLASHES) . "\n";
    }
}
