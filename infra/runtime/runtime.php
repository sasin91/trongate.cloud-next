<?php
declare(strict_types=1);

/**
 * Root-owned FreeBSD host runtime (protocol v2).
 *
 * Transport is pinned Ed25519 SSH to non-root `tc-agent` with forced-command
 * `/usr/local/bin/doas /usr/local/libexec/tc-runtime`. All host operations pass
 * through structured JSON over stdin/stdout with argument-array execution only.
 */
class Runtime {
    public const PROTOCOL_VERSION = 2;
    public const DEFAULT_POOL = 'zroot';
    public const SUPPORTED_PHP_VERSIONS = ['8.4'];
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

    public ?Closure $progress = null;
    public string $pool;
    public string $jail_conf_dir;
    public string $sockets_root;
    public string $mariadb_socket;
    public string $valkey_socket;
    public string $releases_root;
    public string $jails_root;
    public string $bases_root;
    public string $runtimes_root;

    public function __construct(
        public string $root = '/var/db/tc/runtime',
        string $pool = self::DEFAULT_POOL,
        ?string $jail_conf_dir = null,
        ?string $sockets_root = null,
        string $mariadb_socket = '/var/run/tc/mariadb/mysqld.sock',
        string $valkey_socket = '/var/run/tc/valkey/valkey.sock',
        ?string $releases_root = null,
        ?string $jails_root = null,
        ?string $bases_root = null,
        ?string $runtimes_root = null,
    ) {
        $production = $root === '/var/db/tc/runtime';
        $this->pool = $pool;
        $this->jail_conf_dir = $jail_conf_dir ?? ($production ? '/etc/jail.conf.d' : $root . '/jail.conf.d');
        $this->sockets_root = $sockets_root ?? ($production ? '/var/run/tc/sockets' : $root . '/sockets');
        $this->mariadb_socket = $mariadb_socket;
        $this->valkey_socket = $valkey_socket;
        $this->releases_root = $releases_root ?? ($production ? '/' . $pool . '/tc/apps' : $root . '/releases');
        $this->jails_root = $jails_root ?? ($production ? '/' . $pool . '/tc/jails' : $root . '/jails');
        $this->bases_root = $bases_root ?? ($production ? '/' . $pool . '/tc/bases' : $root . '/bases');
        $this->runtimes_root = $runtimes_root ?? ($production ? '/' . $pool . '/tc/runtimes' : $root . '/runtimes');

        foreach (['', '/jobs', '/apps', '/routes'] as $directory) {
            self::directory($root . $directory);
        }
    }

    public static function directory(string $path, int $mode = 0700): void {
        if (!is_dir($path)) {
            if (!mkdir($path, $mode, true) && !is_dir($path)) {
                throw new RuntimeException('Cannot create runtime directory');
            }
            if (PHP_OS_FAMILY !== 'Windows') {
                chmod($path, $mode);
            }
        }
    }

    public static function process(array $args, string $input = '', int $timeout = 120, ?string $cwd = null): string {
        // File descriptors avoid pipe deadlocks and work on Windows, Linux, and FreeBSD.
        $stdin = tmpfile();
        $stdout = tmpfile();
        $stderr = tmpfile();
        if ($stdin === false || $stdout === false || $stderr === false) {
            throw new RuntimeException('Cannot allocate controlled command streams');
        }
        $process = null;
        try {
            if ($input !== '' && fwrite($stdin, $input) !== strlen($input)) {
                throw new RuntimeException('Controlled command input failed');
            }
            rewind($stdin);
            $process = proc_open($args, [0 => $stdin, 1 => $stdout, 2 => $stderr], $pipes, $cwd);
            if (!is_resource($process)) {
                throw new RuntimeException('Cannot start controlled command');
            }
            $deadline = microtime(true) + $timeout;
            do {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    if ($status['exitcode'] !== 0) {
                        throw new RuntimeException('Controlled command failed: ' . basename((string) $args[0]));
                    }
                    rewind($stdout);
                    return trim((string) stream_get_contents($stdout));
                }
                if (microtime(true) > $deadline) {
                    proc_terminate($process, 9);
                    throw new RuntimeException('Controlled command timed out');
                }
                usleep(10000);
            } while (true);
        } finally {
            if (is_resource($process)) {
                proc_close($process);
            }
            fclose($stdin);
            fclose($stdout);
            fclose($stderr);
        }
    }

    public function run(array $args, int $timeout = 120): string {
        return self::process($args, '', $timeout);
    }

    public function run_input(array $args, string $input, int $timeout = 120): string {
        return self::process($args, $input, $timeout);
    }

    public function path_exists(string $path): bool {
        return file_exists($path);
    }

    public function checkpoint(string $phase): void {
        if ($this->progress !== null) {
            ($this->progress)($phase);
        }
    }

    public function write(string $path, mixed $value, int $mode = 0600): void {
        self::directory(dirname($path));
        $temporary = $path . '.tmp.' . bin2hex(random_bytes(4));
        $handle = fopen($temporary, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Cannot write runtime journal');
        }
        try {
            if (PHP_OS_FAMILY !== 'Windows') {
                chmod($temporary, $mode | 0200);
            }
            $data = is_string($value) ? $value : self::encode_json($value);
            $offset = 0;
            $length = strlen($data);
            while ($offset < $length) {
                $written = fwrite($handle, substr($data, $offset));
                if (!$written) {
                    throw new RuntimeException('Runtime journal write failed');
                }
                $offset += $written;
            }
            if (!fflush($handle) || !fsync($handle)) {
                throw new RuntimeException('Runtime journal sync failed');
            }
        } finally {
            fclose($handle);
        }
        if (!rename($temporary, $path)) {
            self::remove($temporary);
            throw new RuntimeException('Runtime journal replace failed');
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            chmod($path, $mode);
            $directory = fopen(dirname($path), 'r');
            if ($directory === false) {
                throw new RuntimeException('Runtime directory sync failed');
            }
            try {
                if (!fsync($directory)) {
                    throw new RuntimeException('Runtime directory sync failed');
                }
            } finally {
                fclose($directory);
            }
        }
    }

    public function read(string $path, mixed $default = null): mixed {
        if (!is_file($path)) {
            return $default;
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Cannot read runtime state');
        }
        return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    }

    public static function match(mixed $value, string $expression): string {
        if (!is_string($value) || preg_match('~\A(?:' . str_replace('~', '\\~', $expression) . ')\z~D', $value) !== 1) {
            throw new InvalidArgumentException('Invalid runtime input');
        }
        return $value;
    }

    public static function remove(string $path): void {
        if ((file_exists($path) || is_link($path)) && !unlink($path)) {
            throw new RuntimeException('Cannot remove runtime file');
        }
    }

    public static function remove_tree(string $path): void {
        if (is_link($path) || !is_dir($path)) {
            self::remove($path);
            return;
        }
        foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) {
            self::remove_tree($entry->getPathname());
        }
        if (!rmdir($path)) {
            throw new RuntimeException('Cannot remove runtime directory');
        }
    }

    public function inspect_host(): array {
        $os = strtolower((string) ($this->probe(['uname', '-s'], 10) ?? ''));
        $arch = (string) ($this->probe(['uname', '-m'], 10) ?? '');
        $freebsd_version = (string) ($this->probe(['freebsd-version', '-u'], 10) ?? '');

        $zfs_ok = $this->dataset_exists($this->pool);
        $jail_ok = $this->probe(['which', 'jail'], 10) !== null;
        $rctl_ok = trim((string) ($this->probe(['sysctl', '-n', 'kern.racct.enable'], 10) ?? '')) === '1';
        $pf_info = $this->probe(['pfctl', '-s', 'info'], 10);
        $pf_ok = $pf_info !== null && str_contains(strtolower($pf_info), 'enabled');
        $caddy_ok = $this->probe(['caddy', 'version'], 10) !== null;
        $mariadb_ok = $this->path_exists($this->mariadb_socket);
        $valkey_ok = $this->path_exists($this->valkey_socket);
        $php_ok = $this->probe(['php', '-v'], 10) !== null;

        $bases_list = $this->probe(['zfs', 'list', '-H', '-d', '1', '-o', 'name', $this->pool . '/tc/bases'], 15);
        $base_ok = false;
        if ($bases_list !== null) {
            $lines = array_values(array_filter(explode("\n", $bases_list), fn(string $line): bool => trim($line) !== '' && trim($line) !== $this->pool . '/tc/bases'));
            $base_ok = $lines !== [];
        }

        $runtime_ok = $this->dataset_exists($this->pool . '/tc/runtimes/php84');
        $php_versions = [];
        $runtimes_list = $this->probe(['zfs', 'list', '-H', '-d', '1', '-o', 'name', $this->pool . '/tc/runtimes'], 15);
        if ($runtimes_list !== null) {
            foreach (explode("\n", $runtimes_list) as $line) {
                if (preg_match('~/php([0-9])([0-9]+)\z~', trim($line), $matches) === 1) {
                    $php_versions[] = $matches[1] . '.' . $matches[2];
                }
            }
        }
        if ($php_versions === [] && $runtime_ok) {
            $php_versions = self::SUPPORTED_PHP_VERSIONS;
        }
        $php_versions = array_values(array_unique($php_versions));
        sort($php_versions);

        $builder_snap = $this->probe(['zfs', 'list', '-H', '-t', 'snapshot', '-o', 'name', $this->pool . '/tc/runtimes/builder@v1'], 15);
        $git_ok = $this->probe(['git', '--version'], 10) !== null;
        $builder_ready = $builder_snap !== null && $git_ok;

        $checks = [
            'zfs' => $zfs_ok,
            'jail' => $jail_ok,
            'rctl' => $rctl_ok,
            'pf' => $pf_ok,
            'caddy' => $caddy_ok,
            'mariadb' => $mariadb_ok,
            'valkey' => $valkey_ok,
            'php' => $php_ok,
            'base_dataset' => $base_ok,
            'runtime_dataset' => $runtime_ok,
            'builder_ready' => $builder_ready,
            'protocol' => true,
        ];

        $missing = [];
        if ($os !== 'freebsd') {
            $missing[] = 'os';
        }
        foreach (['zfs', 'jail', 'rctl', 'pf', 'caddy', 'mariadb', 'valkey', 'php', 'base_dataset', 'runtime_dataset', 'protocol'] as $required) {
            if (!$checks[$required]) {
                $missing[] = $required;
            }
        }

        return [
            'status' => 'succeeded',
            'protocol' => self::PROTOCOL_VERSION,
            'os' => $os,
            'arch' => $arch,
            'freebsd_version' => $freebsd_version,
            'zfs_pool' => $this->pool,
            'checks' => $checks,
            'php_versions' => $php_versions,
            'optional_extensions' => self::OPTIONAL_EXTENSIONS,
            'application_ready' => $missing === [],
            'local_builder_ready' => $builder_ready,
            'missing' => $missing,
        ];
    }

    public function allocate_ip(string $app, string $slot): string {
        $app = self::match($app, '[a-z][a-z0-9-]{0,39}');
        $slot = self::match($slot, 'blue|green');
        $path = $this->root . '/ips.json';
        $ips = $this->read($path, []);
        $key = $app . ':' . $slot;
        if (isset($ips[$key]) && is_string($ips[$key])) {
            return $ips[$key];
        }

        $used = array_flip(array_values($ips));
        $total = 250 * 254;
        $hash = unpack('N', hash('sha256', $key, true))[1];
        $start = $hash % $total;

        for ($offset = 0; $offset < $total; $offset++) {
            $index = ($start + $offset) % $total;
            $third = intdiv($index, 254) + 1;
            $fourth = ($index % 254) + 1;
            $candidate = "10.99.{$third}.{$fourth}";
            if (!isset($used[$candidate])) {
                $ips[$key] = $candidate;
                $this->write($path, $ips);
                return $candidate;
            }
        }

        throw new RuntimeException('No free loopback jail IP addresses remaining in 10.99.1.1-10.99.250.254');
    }

    public function provision_database(string $app): array {
        $app = self::match($app, '[a-z][a-z0-9-]{0,39}');
        $app_sql = str_replace('-', '_', $app);
        $db_name = 'tc_' . $app_sql;
        $path = $this->root . '/apps/' . $app . '/db.json';
        $creds = $this->read($path);

        if (!is_array($creds) || empty($creds['password'])) {
            $creds = [
                'database' => $db_name,
                'username' => $db_name,
                'password' => bin2hex(random_bytes(16)),
                'socket' => '/var/run/tc/mariadb/mysqld.sock',
            ];
            $this->write($path, $creds, 0600);
        }

        $password = self::match($creds['password'], '[A-Za-z0-9]{16,128}');
        $sql = implode("\n", [
            "CREATE DATABASE IF NOT EXISTS `{$db_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;",
            "CREATE USER IF NOT EXISTS '{$db_name}'@'localhost' IDENTIFIED BY '{$password}';",
            "ALTER USER '{$db_name}'@'localhost' IDENTIFIED BY '{$password}' WITH MAX_USER_CONNECTIONS 20;",
            "GRANT ALL PRIVILEGES ON `{$db_name}`.* TO '{$db_name}'@'localhost';",
            'FLUSH PRIVILEGES;',
            '',
        ]);

        $this->run_input(
            ['mariadb', '--socket=' . $this->mariadb_socket, '--batch', '--raw'],
            $sql,
            30
        );

        return $creds;
    }

    public function provision_valkey_acl(string $app): array {
        $app = self::match($app, '[a-z][a-z0-9-]{0,39}');
        $user = 'tc_' . $app;
        $path = $this->root . '/apps/' . $app . '/valkey.json';
        $creds = $this->read($path);

        if (!is_array($creds) || empty($creds['password'])) {
            $creds = [
                'user' => $user,
                'password' => bin2hex(random_bytes(16)),
                'prefix' => 'tc:' . $app . ':sess:',
                'socket' => '/var/run/tc/valkey/valkey.sock',
            ];
            $this->write($path, $creds, 0600);
        }

        $password = self::match($creds['password'], '[A-Za-z0-9]{16,128}');
        $commands = $this->valkey_auth_prefix()
            . "ACL SETUSER {$user} on >{$password} ~tc:{$app}:* -@all"
            . " +get +set +setex +psetex +del +unlink +expire +pexpire +ttl +pttl +exists +ping +auth +hello\n"
            . "ACL SAVE\n";

        $this->run_input(
            ['valkey-cli', '-s', $this->valkey_socket],
            $commands,
            30
        );

        return $creds;
    }

    public function health(string $url, int $attempts = 20): bool {
        for ($i = 0; $i < $attempts; $i++) {
            try {
                $this->run(['curl', '--fail', '--silent', '--max-time', '5', '--output', '/dev/null', $url], 10);
                return true;
            } catch (RuntimeException) {
                if ($i + 1 < $attempts) {
                    sleep(1);
                }
            }
        }
        return false;
    }

    public function healthy(?array $release): bool {
        if ($release === null || empty($release['socket'])) {
            return false;
        }
        $socket = (string) $release['socket'];
        $health = self::match($release['health'] ?? '/', '/[a-zA-Z0-9/_.-]*');
        $domain = self::match($release['domain'] ?? 'localhost', '[a-z0-9]+(?:[.-][a-z0-9]+)*');

        for ($i = 0; $i < 20; $i++) {
            try {
                $output = $this->run([
                    'env',
                    'SCRIPT_FILENAME=/app/public/index.php',
                    'SCRIPT_NAME=/index.php',
                    'REQUEST_METHOD=GET',
                    'REQUEST_URI=' . $health,
                    'QUERY_STRING=',
                    'HTTP_HOST=' . $domain,
                    'DOCUMENT_ROOT=/app/public',
                    'SERVER_PROTOCOL=HTTP/1.1',
                    'GATEWAY_INTERFACE=CGI/1.1',
                    'cgi-fcgi',
                    '-bind',
                    '-connect',
                    $socket,
                ], 10);

                if (preg_match('/^Status:\s*(\d{3})\b/mi', $output, $matches) === 1) {
                    $code = (int) $matches[1];
                    if ($code >= 200 && $code < 400) {
                        return true;
                    }
                } elseif ($output !== '') {
                    return true;
                }
            } catch (RuntimeException) {
                // Retry until PHP-FPM finishes binding and accepts FastCGI requests.
            }
            if ($i < 19) {
                sleep(1);
            }
        }

        return false;
    }

    public function proxy(): void {
        $text = "{\n    admin 127.0.0.1:2019\n}\n";
        foreach (glob($this->root . '/routes/*.caddy') ?: [] as $path) {
            $text .= "\n" . trim((string) file_get_contents($path)) . "\n";
        }
        $caddyfile = $this->root . '/Caddyfile';
        $this->write($caddyfile, $text, 0644);

        foreach (['validate', 'reload'] as $command) {
            $this->run(['caddy', $command, '--config', $caddyfile, '--adapter', 'caddyfile']);
        }
    }

    public function route(string $app, ?array $release): void {
        $app = self::match($app, '[a-z][a-z0-9-]{0,39}');
        $path = $this->root . '/routes/' . $app . '.caddy';

        if ($release === null) {
            self::remove($path);
        } else {
            $domain = self::match($release['domain'], '[a-z0-9]+(?:[.-][a-z0-9]+)+');
            $release_base = (string) ($release['release_path'] ?? ($this->releases_root . '/' . $app . '/releases/' . $release['operation']));
            $public_root = rtrim($release_base, '/') . '/public';
            $socket = 'unix//' . ltrim((string) $release['socket'], '/');

            $block = $domain . " {\n"
                . "    root * " . $public_root . "\n"
                . "    php_fastcgi " . $socket . " {\n"
                . "        root /app/public\n"
                . "    }\n"
                . "    file_server\n"
                . "    header {\n"
                . "        Content-Security-Policy \"frame-ancestors 'self'\"\n"
                . "    }\n"
                . "}\n";

            $this->write($path, $block, 0644);
        }

        $this->proxy();
    }

    public function apply_rctl(array $release): void {
        $jail = self::match($release['jail'] ?? null, 'tc_[a-z][a-z0-9_]{0,39}_(?:blue|green)');
        $memory_mb = max(64, (int) ($release['memory_mb'] ?? 128));
        $cpu_percent = max(10, min(1600, (int) ($release['cpu_percent'] ?? 100)));

        foreach ([
            "jail:{$jail}:memoryuse:deny={$memory_mb}M",
            "jail:{$jail}:swapuse:deny={$memory_mb}M",
            "jail:{$jail}:pcpu:deny={$cpu_percent}",
            "jail:{$jail}:maxproc:deny=64",
            "jail:{$jail}:openfiles:deny=1024",
        ] as $rule) {
            $this->run(['rctl', '-a', $rule], 30);
        }
    }

    public function remove_rctl(string $jail): void {
        $jail = self::match($jail, 'tc_[a-z][a-z0-9_]{0,39}_(?:blue|green)');
        $rules = $this->probe(['rctl', "jail:{$jail}"], 15);
        if ($rules !== null && trim($rules) !== '') {
            $this->run(['rctl', '-r', "jail:{$jail}"], 30);
        }
    }

    public function start(array $release): void {
        $jail = self::match($release['jail'] ?? null, 'tc_[a-z][a-z0-9_]{0,39}_(?:blue|green)');
        $ip = self::match($release['ip'] ?? null, '10\.99\.(?:[1-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|250)\.(?:[1-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-4])');
        $conf = (string) ($release['jail_conf'] ?? ($this->jail_conf_dir . '/' . $jail . '.conf'));

        $this->run(['pfctl', '-t', 'tc_jails', '-T', 'add', $ip], 30);
        $this->run(['jail', '-c', '-f', $conf, $jail], 60);
        $this->apply_rctl($release);
    }

    public function stop(?array $release): void {
        if ($release === null || empty($release['jail'])) {
            return;
        }
        $jail = self::match($release['jail'], 'tc_[a-z][a-z0-9_]{0,39}_(?:blue|green)');
        if ($this->jail_running($jail)) {
            $this->run(['jail', '-r', $jail], 60);
        }
        if (!empty($release['ip'])) {
            $ip = self::match($release['ip'], '10\.99\.(?:[1-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|250)\.(?:[1-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-4])');
            $this->run(['pfctl', '-t', 'tc_jails', '-T', 'delete', $ip], 30);
        }
        $this->remove_rctl($jail);
    }

    public function stage(
        string $app,
        string $operation,
        array $spec,
        array $secrets,
        array $files = [],
        ?array $db = null,
        ?array $valkey = null,
    ): array {
        $app = self::match($app, '[a-z][a-z0-9-]{0,39}');
        $operation = self::match($operation, '[a-f0-9]{32}');
        $domain = self::match($spec['domain'] ?? null, '[a-z0-9]+(?:[.-][a-z0-9]+)+');
        $health = self::match($spec['health'] ?? '/', '/[a-zA-Z0-9/_.-]*');
        $php_version = self::match($spec['php_version'] ?? '8.4', '8\.[3-5]');
        if (isset($spec['artifact']) && $spec['artifact'] !== 'pending') {
            self::match($spec['artifact'], 'sha256:[a-f0-9]{64}');
        }

        $memory_mb = max(64, (int) ($spec['memory_mb'] ?? 128));
        $cpu_percent = max(10, min(1600, (int) ($spec['cpu_percent'] ?? 100)));
        $disk_quota_mb = max(128, (int) ($spec['disk_quota_mb'] ?? 1024));

        $extensions = [];
        foreach ($spec['extensions'] ?? [] as $ext) {
            $ext = self::match($ext, '[a-z][a-z0-9_]{0,39}');
            if (!in_array($ext, self::OPTIONAL_EXTENSIONS, true)) {
                throw new InvalidArgumentException('Unsupported optional PHP extension: ' . $ext);
            }
            $extensions[] = $ext;
        }
        $extensions = array_values(array_unique($extensions));

        $state = $this->read($this->root . '/apps/' . $app . '/state.json', ['active' => null, 'previous' => null]);
        $active_slot = $state['active']['slot'] ?? null;
        $slot = $active_slot === 'blue' ? 'green' : 'blue';
        $jail_name = 'tc_' . str_replace('-', '_', $app) . '_' . $slot;
        $ip = $this->allocate_ip($app, $slot);

        $this->checkpoint('staging-release');

        $app_dataset = $this->pool . '/tc/apps/' . $app;
        $data_dataset = $app_dataset . '/data';
        $release_dataset = $app_dataset . '/releases/' . $operation;

        $this->ensure_zfs_dataset($app_dataset, ['quota' => $disk_quota_mb . 'M']);
        $this->ensure_zfs_dataset($data_dataset, ['exec' => 'off', 'setuid' => 'off']);
        $this->ensure_zfs_dataset($release_dataset);

        $release_dir = isset($spec['release_path'])
            ? self::match($spec['release_path'], '/(?:[a-zA-Z0-9][a-zA-Z0-9_.-]*/)*[a-zA-Z0-9][a-zA-Z0-9_.-]*')
            : ($this->releases_root . '/' . $app . '/releases/' . $operation);
        $meta_dir = $this->root . '/apps/' . $app . '/releases/' . $operation;
        self::directory($meta_dir);

        $data_root = $this->releases_root . '/' . $app . '/data';
        self::directory($data_root, 0755);
        $this->ensure_mode($data_root, 0755);

        // Patch only BASE_URL and ENV in the release's config/config.php so custom defines stay intact.
        if (!array_key_exists('/app/config/config.php', $files)) {
            $source_config = $release_dir . '/config/config.php';
            if (is_file($source_config)) {
                $raw_config = file_get_contents($source_config);
                if ($raw_config === false) {
                    throw new RuntimeException('Cannot read release config/config.php');
                }
                $trongate_env = (string) ($secrets['ENV'] ?? $secrets['APP_ENV'] ?? ($spec['env'] ?? 'production'));
                $files['/app/config/config.php'] = self::patch_config_php($raw_config, $domain, $trongate_env);
            }
        }

        // Materialize config overlays (including auto-generated database.php when DB is provisioned).
        if ($db !== null && !array_key_exists('/app/config/database.php', $files)) {
            $files['/app/config/database.php'] = $this->render_database_config($db);
        }

        $overlay_dir = null;
        if ($files !== []) {
            $overlay_dir = $meta_dir . '/config';
            self::directory($overlay_dir, 0750);
            $this->grant_www($overlay_dir, 0750, writable: false);
            $source_config_dir = $release_dir . '/config';
            if (is_dir($source_config_dir)) {
                foreach (new FilesystemIterator($source_config_dir, FilesystemIterator::SKIP_DOTS) as $item) {
                    if ($item->isFile() && !$item->isLink()) {
                        $dest = $overlay_dir . '/' . $item->getFilename();
                        if (!copy($item->getPathname(), $dest)) {
                            throw new RuntimeException('Cannot prepare release configuration overlay');
                        }
                        $this->grant_www($dest, 0440, writable: false);
                    }
                }
            }
            foreach ($files as $target => $content) {
                self::match($target, '/app/config/[A-Za-z0-9_-]+\.php');
                if (!is_string($content)) {
                    throw new InvalidArgumentException('Configuration file content must be a string');
                }
                $dest = $overlay_dir . '/' . basename($target);
                $this->write($dest, $content, 0440);
                $this->grant_www($dest, 0440, writable: false);
            }
        }

        // Clone or reset the inactive slot's thin jail dataset from skeleton@v1.
        $jail_dataset = $this->pool . '/tc/jails/' . $app . '-' . $slot;
        $jail_root = $this->jails_root . '/' . $app . '-' . $slot;
        if ($this->jail_running($jail_name)) {
            $this->run(['jail', '-r', $jail_name], 60);
        }
        if ($this->dataset_exists($jail_dataset)) {
            $this->run(['zfs', 'destroy', '-r', $jail_dataset], 60);
        }
        $this->run(['zfs', 'clone', '-o', 'quota=128M', $this->pool . '/tc/skeleton@v1', $jail_dataset], 60);

        foreach ([
            '/.base' => 0755,
            '/etc/tc' => 0755,
            '/etc/tc/php.d' => 0755,
            '/usr/local' => 0755,
            '/app' => 0755,
            '/var/run/php-fpm' => 0755,
            '/var/run/tc' => 0755,
            '/var/run/tc/mariadb' => 0755,
            '/var/run/tc/valkey' => 0755,
            '/var/log' => 0755,
            '/tmp' => 01777,
        ] as $sub => $mode) {
            self::directory($jail_root . $sub, $mode);
            $this->ensure_mode($jail_root . $sub, $mode);
        }

        $socket_dir = $this->sockets_root . '/' . $app . '-' . $slot;
        self::directory($socket_dir, 0755);
        $this->ensure_mode($socket_dir, 0755);
        $socket_path = $socket_dir . '/php-fpm.sock';

        // Write jail-local optional PHP extensions config.
        $ext_lines = ['; Managed by trongate.cloud runtime'];
        foreach ($extensions as $ext) {
            $ext_lines[] = 'extension=' . $ext . '.so';
        }
        $this->write($jail_root . '/etc/tc/php.d/extensions.ini', implode("\n", $ext_lines) . "\n", 0644);

        // Write jail-local php-fpm.conf with ondemand PM, socket bindings, and secrets.
        $php_fpm_conf = $this->render_php_fpm_conf($memory_mb, $secrets, $valkey);
        $this->write($jail_root . '/etc/tc/php-fpm.conf', $php_fpm_conf, 0600);

        // Build nullfs fstab for the jail.
        $base_dir = $this->resolve_base_dir();
        $runtime_dir = $this->runtimes_root . '/php' . str_replace('.', '', $php_version);
        $fstab_lines = [
            $base_dir . ' ' . $jail_root . '/.base nullfs ro 0 0',
            $runtime_dir . ' ' . $jail_root . '/usr/local nullfs ro 0 0',
            $release_dir . ' ' . $jail_root . '/app nullfs ro 0 0',
        ];
        if ($overlay_dir !== null) {
            self::directory($jail_root . '/app/config', 0755);
            if (is_dir($release_dir)) {
                self::directory($release_dir . '/config', 0755);
            }
            $fstab_lines[] = $overlay_dir . ' ' . $jail_root . '/app/config nullfs ro 0 0';
        }
        foreach (($spec['mounts'] ?? []) as $volume => $target) {
            self::match($volume, '[a-z][a-z0-9-]{0,39}');
            self::match($target, '/app/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+');
            $host_volume = $data_root . '/' . $volume;
            self::directory($host_volume, 0750);
            $this->grant_www($host_volume, 0750, writable: true);
            self::directory($jail_root . $target, 0755);
            if (is_dir($release_dir)) {
                $release_mount = $release_dir . substr($target, 4);
                self::directory($release_mount, 0755);
                $this->ensure_mode($release_mount, 0755);
            }
            $fstab_lines[] = $host_volume . ' ' . $jail_root . $target . ' nullfs rw 0 0';
        }
        $fstab_lines[] = $socket_dir . ' ' . $jail_root . '/var/run/php-fpm nullfs rw 0 0';
        $fstab_lines[] = dirname($this->mariadb_socket) . ' ' . $jail_root . '/var/run/tc/mariadb nullfs rw 0 0';
        $fstab_lines[] = dirname($this->valkey_socket) . ' ' . $jail_root . '/var/run/tc/valkey nullfs rw 0 0';

        self::directory($this->jail_conf_dir, 0755);
        $fstab_path = $this->jail_conf_dir . '/' . $jail_name . '.fstab';
        $this->write($fstab_path, implode("\n", $fstab_lines) . "\n", 0600);

        // Write /etc/jail.conf.d/<jail>.conf with exec.poststart rctl rules for reboot durability.
        $conf_path = $this->jail_conf_dir . '/' . $jail_name . '.conf';
        $jail_conf = $this->render_jail_conf($jail_name, $app, $slot, $jail_root, $ip, $fstab_path, $memory_mb, $cpu_percent);
        $this->write($conf_path, $jail_conf, 0600);

        unset($spec['secrets'], $spec['file_secrets']);
        $release = array_replace($spec, [
            'operation' => $operation,
            'app' => $app,
            'slot' => $slot,
            'jail' => $jail_name,
            'jail_conf' => $conf_path,
            'jail_dataset' => $jail_dataset,
            'fstab' => $fstab_path,
            'ip' => $ip,
            'socket' => $socket_path,
            'domain' => $domain,
            'health' => $health,
            'php_version' => $php_version,
            'extensions' => $extensions,
            'release_path' => $release_dir,
            'memory_mb' => $memory_mb,
            'cpu_percent' => $cpu_percent,
            'disk_quota_mb' => $disk_quota_mb,
            'database_name' => $db['database'] ?? null,
        ]);

        $this->checkpoint('starting-standby');
        $this->start($release);
        $this->write($meta_dir . '/release.json', $release);

        return $release;
    }

    public function allocate(string $app): array {
        require_once __DIR__ . '/resources.php';
        return (new Resources($this))->apply(incoming: $app, starting: true);
    }

    public function app_operation(array $request): array {
        $operation = self::match($request['operation'] ?? null, '[a-f0-9]{32}');
        $app = self::match($request['app'] ?? null, '[a-z][a-z0-9-]{0,39}');
        $journal_path = $this->root . '/jobs/' . $operation . '.json';
        $state_path = $this->root . '/apps/' . $app . '/state.json';
        $state = $this->read($state_path, ['active' => null, 'previous' => null]);
        $journal = $this->read($journal_path);

        $this->progress = function (string $phase) use (&$journal, $journal_path): void {
            if ($journal) {
                $journal['phase'] = $phase;
                $journal['events'][] = ['step' => $phase, 'at' => time()];
                $this->write($journal_path, $journal);
            }
        };

        if ($journal && ($journal['status'] ?? '') === 'waiting') {
            if (empty($request['go_live'])) {
                return $journal;
            }
            if ($state != $journal['before']) {
                throw new RuntimeException('The active release changed while waiting to go live');
            }
            try {
                return $this->complete_candidate($app, $state_path, $journal_path, $journal, $request);
            } catch (Throwable $error) {
                $journal['error'] = $error instanceof RuntimeException || $error instanceof InvalidArgumentException
                    ? $error->getMessage()
                    : get_class($error);
                return $this->recover($app, $journal_path, $state_path, $journal);
            }
        }

        if ($journal) {
            return in_array($journal['status'] ?? '', ['succeeded', 'failed'], true)
                ? $journal
                : $this->recover($app, $journal_path, $state_path, $journal);
        }

        $staged = $this->read($this->root . '/apps/' . $app . '/staged.json');
        if ($staged && ($staged['operation'] ?? '') !== $operation) {
            throw new RuntimeException('Finish the staged deployment before starting another one');
        }

        $journal = [
            'operation' => $operation,
            'status' => 'running',
            'phase' => 'intent',
            'before' => $state,
            'public_check' => $request['public_check'] ?? true,
            'events' => [],
        ];
        $this->write($journal_path, $journal);

        try {
            if ($request['action'] === 'rollback') {
                $candidate = $state['previous'] ?? null;
                if (!$candidate) {
                    throw new RuntimeException('No retained previous release');
                }
                $this->checkpoint('allocating-resources');
                $this->allocate($app);
                $this->checkpoint('starting-standby');
                $this->start($candidate);
            } else {
                $spec = $request['release'];
                foreach (glob($this->root . '/apps/*', GLOB_ONLYDIR) ?: [] as $other) {
                    if (basename($other) === $app) {
                        continue;
                    }
                    $active = $this->read($other . '/state.json', [])['active'] ?? null;
                    $staged_other = $this->read($other . '/staged.json', []);
                    $pending = $this->read($this->root . '/jobs/' . ($staged_other['operation'] ?? '') . '.json', [])['candidate'] ?? null;
                    foreach ([$active, $pending] as $item) {
                        if ($item && ($item['domain'] ?? '') === ($spec['domain'] ?? '')) {
                            throw new RuntimeException('Domain is already assigned');
                        }
                    }
                }

                $this->checkpoint('allocating-resources');
                $allocation = $this->allocate($app);
                $merged = [];
                if (isset($allocation['memory_mb'])) {
                    $merged['memory_mb'] = (int) $allocation['memory_mb'];
                }
                if (isset($allocation['cpu_percent'])) {
                    $merged['cpu_percent'] = (int) $allocation['cpu_percent'];
                } elseif (isset($allocation['cpus'])) {
                    $merged['cpu_percent'] = max(10, (int) round((float) $allocation['cpus'] * 100));
                }
                if (isset($allocation['disk_quota_mb'])) {
                    $merged['disk_quota_mb'] = (int) $allocation['disk_quota_mb'];
                }
                $spec = array_replace($spec, $merged);

                $db = null;
                if (($spec['database'] ?? true) === true) {
                    $this->checkpoint('preparing-database');
                    $db = $this->provision_database($app);
                }

                $this->checkpoint('preparing-sessions');
                $valkey = $this->provision_valkey_acl($app);

                $candidate = $this->stage(
                    $app,
                    $operation,
                    $spec,
                    $request['secret_values'] ?? [],
                    $request['file_values'] ?? [],
                    $db,
                    $valkey
                );
            }

            $journal['candidate'] = $candidate;
            $journal['phase'] = 'candidate';
            $this->write($journal_path, $journal);

            if ($request['action'] !== 'rollback' && !empty($request['release']['migration'])) {
                if (($request['release']['schema_compatible'] ?? null) !== true) {
                    throw new RuntimeException('Migration must be backward compatible');
                }
                $journal['phase'] = 'migration-started';
                $this->write($journal_path, $journal);
                $this->run(array_merge(
                    ['jexec', $candidate['jail'], 'env', 'PHP_INI_SCAN_DIR=/usr/local/etc/php-base:/etc/tc/php.d'],
                    $request['release']['migration']
                ), 600);
                $journal['phase'] = 'migration-completed';
                $journal['migration'] = 'completed; application rollback does not undo data changes';
                $this->write($journal_path, $journal);
            }

            return $this->complete_candidate($app, $state_path, $journal_path, $journal, $request);
        } catch (Throwable $error) {
            $journal['error'] = $error instanceof RuntimeException || $error instanceof InvalidArgumentException
                ? $error->getMessage()
                : get_class($error);
            $this->write($journal_path, $journal);
            return $this->recover($app, $journal_path, $state_path, $journal);
        }
    }

    public function complete_candidate(string $app, string $state_path, string $journal_path, array &$journal, array $request): array {
        $candidate = $journal['candidate'];
        $previous = $journal['before']['active'] ?? null;

        $this->checkpoint('checking-local');
        if (!$this->healthy($candidate)) {
            throw new RuntimeException('Candidate health check failed');
        }

        if (!empty($request['defer_public']) && ($request['public_check'] ?? true) && !$previous && empty($request['go_live'])) {
            return $this->waiting($app, $journal_path, $journal);
        }

        $this->checkpoint('switching');
        $this->route($app, $candidate);
        if (!$this->healthy($candidate)) {
            throw new RuntimeException('Health check failed after traffic switch');
        }

        $this->checkpoint('checking-public');
        if (($request['public_check'] ?? true) && !$this->health('https://' . $candidate['domain'] . $candidate['health'], 30)) {
            if (!empty($request['defer_public'])) {
                if ($previous) {
                    $this->route($app, $previous);
                }
                $journal['message'] = 'Public HTTPS verification is still pending. Certificate issuance may take a little longer; try again shortly.';
                return $this->waiting($app, $journal_path, $journal);
            }
            throw new RuntimeException('Public health check failed');
        }

        self::remove($this->root . '/apps/' . $app . '/staged.json');
        $this->write($state_path, ['active' => $candidate, 'previous' => $previous]);
        unset($journal['message']);

        try {
            if ($previous && ($previous['operation'] ?? '') !== ($candidate['operation'] ?? '')) {
                $this->checkpoint('retiring-previous');
                $this->drain();
                $this->stop($previous);
            }
        } catch (Throwable) {
            $journal['retirement'] = 'previous jail still running; safe to retire later';
        }

        $journal = array_replace($journal, [
            'status' => 'succeeded',
            'phase' => 'accepted',
            'active' => $candidate['operation'],
        ]);
        $journal['events'][] = ['step' => 'accepted', 'at' => time()];
        $this->write($journal_path, $journal);

        return $journal;
    }

    public function drain(): void {
        usleep(200000);
    }

    private function waiting(string $app, string $journal_path, array &$journal): array {
        $journal['status'] = 'waiting';
        $journal['phase'] = 'awaiting-go-live';
        $journal['events'][] = ['step' => 'awaiting-go-live', 'at' => time()];
        $this->write($this->root . '/apps/' . $app . '/staged.json', ['operation' => $journal['operation']]);
        $this->write($journal_path, $journal);
        return $journal;
    }

    public function recover(string $app, string $journal_path, string $state_path, array $journal): array {
        $previous = $journal['before']['active'] ?? null;
        try {
            if ($previous) {
                $this->start($previous);
                if (!$this->healthy($previous)) {
                    throw new RuntimeException('Previous release unhealthy');
                }
            }
            $this->route($app, $previous);
            if ($previous && ($journal['public_check'] ?? true) && !$this->health('https://' . $previous['domain'] . $previous['health'], 3)) {
                throw new RuntimeException('Restored route not verified publicly');
            }
            $this->write($state_path, $journal['before']);
            $candidate = $journal['candidate'] ?? null;
            if ($candidate && (!$previous || ($candidate['operation'] ?? '') !== ($previous['operation'] ?? ''))) {
                $this->stop($candidate);
            } elseif (!$candidate) {
                $inactive_slot = ($previous['slot'] ?? null) === 'blue' ? 'green' : 'blue';
                $jail_name = 'tc_' . str_replace('-', '_', $app) . '_' . $inactive_slot;
                $ip = $this->read($this->root . '/ips.json', [])[$app . ':' . $inactive_slot] ?? null;
                $this->stop(['jail' => $jail_name, 'ip' => $ip]);
            }
            $journal['status'] = ($journal['phase'] ?? '') === 'migration-started' ? 'uncertain' : 'failed';
            $journal['restored'] = $previous['operation'] ?? null;
            if ($journal['status'] === 'failed') {
                self::remove($this->root . '/apps/' . $app . '/staged.json');
            }
        } catch (Throwable) {
            $journal['status'] = 'uncertain';
            $journal['recovery'] = 'cannot verify restored route; operator reconciliation required';
        }
        $this->write($journal_path, $journal);
        return $journal;
    }

    public function destroy_app(array $request): array {
        $operation = self::match($request['operation'] ?? null, '[a-f0-9]{32}');
        $app = self::match($request['app'] ?? null, '[a-z][a-z0-9-]{0,39}');
        $purge_data = (bool) ($request['purge_data'] ?? false);
        $journal_path = $this->root . '/jobs/' . $operation . '.json';

        $recorded = $this->read($journal_path);
        if (is_array($recorded) && ($recorded['status'] ?? '') === 'succeeded') {
            return $recorded;
        }

        $journal = [
            'operation' => $operation,
            'app' => $app,
            'status' => 'running',
            'phase' => 'destroying',
            'purge_data' => $purge_data,
            'events' => [['step' => 'destroying', 'at' => time()]],
        ];
        $this->write($journal_path, $journal);

        try {
            $this->route($app, null);

            $ips_path = $this->root . '/ips.json';
            $ips = $this->read($ips_path, []);
            $app_sql = str_replace('-', '_', $app);

            foreach (['blue', 'green'] as $slot) {
                $jail_name = 'tc_' . $app_sql . '_' . $slot;
                $ip = $ips[$app . ':' . $slot] ?? null;
                $this->stop(['jail' => $jail_name, 'ip' => $ip]);
                unset($ips[$app . ':' . $slot]);

                $jail_dataset = $this->pool . '/tc/jails/' . $app . '-' . $slot;
                if ($this->dataset_exists($jail_dataset)) {
                    $this->run(['zfs', 'destroy', '-r', $jail_dataset], 60);
                }

                self::remove($this->jail_conf_dir . '/' . $jail_name . '.conf');
                self::remove($this->jail_conf_dir . '/' . $jail_name . '.fstab');

                $socket_dir = $this->sockets_root . '/' . $app . '-' . $slot;
                if (is_dir($socket_dir)) {
                    self::remove_tree($socket_dir);
                }
            }
            $this->write($ips_path, $ips);

            $valkey_user = 'tc_' . $app;
            $this->run_input(
                ['valkey-cli', '-s', $this->valkey_socket],
                $this->valkey_auth_prefix() . "ACL DELUSER {$valkey_user}\nACL SAVE\n",
                30
            );
            self::remove($this->root . '/apps/' . $app . '/valkey.json');
            self::remove($this->root . '/apps/' . $app . '/staged.json');
            $this->write($this->root . '/apps/' . $app . '/state.json', ['active' => null, 'previous' => null]);

            if ($purge_data) {
                $db_name = 'tc_' . $app_sql;
                $sql = implode("\n", [
                    "DROP DATABASE IF EXISTS `{$db_name}`;",
                    "DROP USER IF EXISTS '{$db_name}'@'localhost';",
                    'FLUSH PRIVILEGES;',
                    '',
                ]);
                $this->run_input(
                    ['mariadb', '--socket=' . $this->mariadb_socket, '--batch', '--raw'],
                    $sql,
                    30
                );
                self::remove($this->root . '/apps/' . $app . '/db.json');

                $app_dataset = $this->pool . '/tc/apps/' . $app;
                if ($this->dataset_exists($app_dataset)) {
                    $this->run(['zfs', 'destroy', '-r', $app_dataset], 60);
                }

                $app_meta = $this->root . '/apps/' . $app;
                if (is_dir($app_meta)) {
                    self::remove_tree($app_meta);
                }
            }

            $journal['status'] = 'succeeded';
            $journal['phase'] = 'destroyed';
            $journal['events'][] = ['step' => 'destroyed', 'at' => time()];
            $this->write($journal_path, $journal);
            return $journal;
        } catch (Throwable $error) {
            $journal['status'] = 'failed';
            $journal['error'] = $error instanceof RuntimeException || $error instanceof InvalidArgumentException
                ? $error->getMessage()
                : get_class($error);
            $this->write($journal_path, $journal);
            return $journal;
        }
    }

    public function builder(): Builder {
        require_once __DIR__ . '/builder.php';
        $production = $this->root === '/var/db/tc/runtime';
        return new Builder(
            root: $production ? '/var/db/tc/builds' : $this->root . '/builds',
            pool: $this->pool,
            releases_root: $this->releases_root,
            jails_root: $this->jails_root,
            jail_conf_dir: $this->jail_conf_dir,
            bases_root: $this->bases_root,
            runtimes_root: $this->runtimes_root,
        );
    }

    public function local_build(array $request): array {
        $operation = self::match($request['operation'] ?? null, '[a-f0-9]{32}');
        $journal_path = $this->root . '/jobs/' . $operation . '.json';
        $lock = fopen($this->root . '/build.lock', 'a');
        if ($lock === false) {
            throw new RuntimeException('Cannot open build lock');
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                return ['status' => 'pending', 'reason' => 'Another local build is running.'];
            }
            $recorded = $this->read($journal_path);
            if (is_array($recorded) && ($recorded['status'] ?? '') === 'succeeded') {
                return $recorded;
            }
            $result = $this->builder()->build($request);
            if (($result['status'] ?? '') === 'succeeded') {
                $this->write($journal_path, $result);
            }
            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function cleanup(): array {
        if (is_file($this->root . '/resources-pending.json')) {
            return ['status' => 'waiting', 'reason' => 'Reconcile the interrupted resource update before retiring releases.'];
        }
        $removed = 0;
        foreach (glob($this->root . '/apps/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $app = basename($directory);
            $keep = [];
            foreach ($this->read($directory . '/state.json', []) as $release) {
                if (is_array($release) && isset($release['operation'])) {
                    $keep[] = $release['operation'];
                }
            }
            $staged = $this->read($directory . '/staged.json', []);
            if (is_array($staged) && !empty($staged['operation'])) {
                $keep[] = $staged['operation'];
            }
            foreach (glob($directory . '/releases/*/release.json') ?: [] as $path) {
                $release = $this->read($path);
                if (!is_array($release) || empty($release['operation'])) {
                    continue;
                }
                $operation = $release['operation'];
                $journal = $this->read($this->root . '/jobs/' . $operation . '.json', []);
                if (
                    in_array($operation, $keep, true)
                    || !in_array($journal['status'] ?? '', ['succeeded', 'failed'], true)
                    || filemtime($path) > time() - 86400
                ) {
                    continue;
                }

                $release_dataset = $this->pool . '/tc/apps/' . $app . '/releases/' . $operation;
                if ($this->dataset_exists($release_dataset)) {
                    $this->run(['zfs', 'destroy', '-r', $release_dataset], 60);
                }

                $release_path = $release['release_path'] ?? null;
                if (is_string($release_path) && is_dir($release_path)) {
                    self::remove_tree($release_path);
                }

                self::remove_tree(dirname($path));
                $removed++;
            }
        }
        return ['status' => 'succeeded', 'removed_releases' => $removed];
    }

    public function dispatch(array $request): array {
        $action = $request['action'] ?? null;
        if (!is_string($action)) {
            throw new InvalidArgumentException('Unknown operation');
        }
        if ($action === 'inspect-host') {
            return $this->inspect_host();
        }
        if ($action === 'build') {
            return $this->local_build($request);
        }
        if ($action === 'metrics') {
            require_once __DIR__ . '/metrics.php';
            return Metrics::collect($this->root, $this);
        }
        if ($action === 'status') {
            $operation = self::match($request['operation'] ?? null, '[a-f0-9]{32}');
            return array_intersect_key(
                $this->read($this->root . '/jobs/' . $operation . '.json', []),
                array_flip(['status', 'phase', 'events'])
            );
        }

        $lock = fopen($this->root . '/operation.lock', 'a');
        if ($lock === false) {
            throw new RuntimeException('Cannot open runtime operation lock');
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                return ['status' => 'uncertain', 'reason' => 'remote operation still executing'];
            }
            if (in_array($action, ['resources', 'resources-update'], true)) {
                require_once __DIR__ . '/resources.php';
                $resources = new Resources($this);
                if ($action === 'resources-update') {
                    try {
                        $resources->apply(change: $request);
                    } catch (RuntimeException $error) {
                        return ['status' => 'failed', 'error' => $error->getMessage()];
                    }
                }
                return $resources->snapshot();
            }
            if ($action === 'boot') {
                return $this->boot_active();
            }
            if ($action === 'cleanup') {
                return $this->cleanup();
            }
            if ($action === 'inspect') {
                return [
                    'status' => 'succeeded',
                    'applications' => array_map(
                        fn(string $path) => $this->read($path),
                        glob($this->root . '/apps/*/state.json') ?: []
                    ),
                ];
            }
            if ($action === 'destroy-app') {
                return $this->destroy_app($request);
            }
            if (!in_array($action, ['deploy', 'rollback'], true)) {
                throw new InvalidArgumentException('Unknown operation');
            }
            return $this->app_operation($request);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function ensure_zfs_dataset(string $dataset, array $properties = []): void {
        if (!$this->dataset_exists($dataset)) {
            $args = ['zfs', 'create', '-p'];
            foreach ($properties as $key => $value) {
                $args[] = '-o';
                $args[] = $key . '=' . $value;
            }
            $args[] = $dataset;
            $this->run($args, 30);
            return;
        }
        foreach ($properties as $key => $value) {
            $this->run(['zfs', 'set', $key . '=' . $value, $dataset], 30);
        }
    }

    private function resolve_base_dir(): string {
        $candidates = glob($this->bases_root . '/*', GLOB_ONLYDIR) ?: [];
        if ($candidates !== []) {
            rsort($candidates);
            return $candidates[0];
        }
        return $this->bases_root . '/current';
    }

    public function boot_active(): array {
        $started = [];
        $already_running = [];
        $failed = [];
        foreach (glob($this->root . '/apps/*/state.json') ?: [] as $state_path) {
            $app = basename(dirname($state_path));
            $state = $this->read($state_path, []);
            $active = is_array($state) ? ($state['active'] ?? null) : null;
            if (!is_array($active) || empty($active['jail']) || empty($active['ip'])) {
                continue;
            }
            $jail = (string) $active['jail'];
            $ip = (string) $active['ip'];
            try {
                if ($this->jail_running($jail)) {
                    $this->run(['pfctl', '-t', 'tc_jails', '-T', 'add', $ip], 30);
                    $already_running[] = $app;
                } else {
                    $this->start($active);
                    $started[] = $app;
                }
            } catch (Throwable $error) {
                $failed[$app] = $error instanceof RuntimeException || $error instanceof InvalidArgumentException
                    ? $error->getMessage()
                    : get_class($error);
            }
        }
        return [
            'status' => $failed === [] ? 'succeeded' : 'degraded',
            'started' => $started,
            'already_running' => $already_running,
            'failed' => $failed,
        ];
    }

    public static function patch_config_php(string $source, string $domain, string $env = 'production', bool $https = true): string {
        $domain = trim($domain);
        if ($domain === '' || str_contains($domain, "\0") || str_contains($env, "\0")) {
            throw new InvalidArgumentException('Invalid Trongate configuration value.');
        }
        $replacements = [
            'BASE_URL' => ($https ? 'https' : 'http') . '://' . trim($domain, '/') . '/',
            'ENV' => $env,
        ];
        $found = [];
        $tokens = token_get_all($source);
        $count = count($tokens);
        $out = '';
        $i = 0;

        $next_meaningful = static function (int $idx) use ($tokens, $count): ?int {
            while ($idx < $count) {
                $t = $tokens[$idx];
                if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    $idx++;
                    continue;
                }
                return $idx;
            }
            return null;
        };

        while ($i < $count) {
            $tok = $tokens[$i];
            if (is_array($tok) && $tok[0] === T_STRING && strcasecmp($tok[1], 'define') === 0) {
                $lparen = $next_meaningful($i + 1);
                if ($lparen !== null && $tokens[$lparen] === '(') {
                    $arg1 = $next_meaningful($lparen + 1);
                    if ($arg1 !== null && is_array($tokens[$arg1]) && $tokens[$arg1][0] === T_CONSTANT_ENCAPSED_STRING) {
                        $raw_name = $tokens[$arg1][1];
                        $quote = $raw_name[0];
                        $const_name = ($quote === "'" || $quote === '"') ? substr($raw_name, 1, -1) : '';
                        if (array_key_exists($const_name, $replacements)) {
                            $comma = $next_meaningful($arg1 + 1);
                            if ($comma !== null && $tokens[$comma] === ',') {
                                // Emit everything up through the comma and a single space.
                                for ($k = $i; $k <= $comma; $k++) {
                                    $out .= is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];
                                }
                                $out .= ' ' . var_export($replacements[$const_name], true);
                                $found[$const_name] = true;

                                // Skip the original second argument expression up to matching ')'.
                                $depth = 1;
                                $k = $comma + 1;
                                while ($k < $count && $depth > 0) {
                                    $tk = $tokens[$k];
                                    if ($tk === '(') {
                                        $depth++;
                                    } elseif ($tk === ')') {
                                        $depth--;
                                        if ($depth === 0) {
                                            break;
                                        }
                                    }
                                    $k++;
                                }
                                $i = $k;
                                continue;
                            }
                        }
                    }
                }
            }
            $out .= is_array($tok) ? $tok[1] : $tok;
            $i++;
        }

        $missing = [];
        foreach ($replacements as $name => $value) {
            if (!isset($found[$name])) {
                $missing[] = "define('" . $name . "', " . var_export($value, true) . ");";
            }
        }
        if ($missing !== []) {
            $out = preg_replace('/\?>\s*\z/', '', $out);
            $out = rtrim((string) $out, "\r\n") . "\n" . implode("\n", $missing) . "\n";
        }

        return $out;
    }

    private function render_database_config(array $db): string {
        $export = static fn(string $value): string => var_export($value, true);
        return "<?php\n"
            . "// Managed by trongate.cloud runtime\n"
            . "define('HOST', 'localhost');\n"
            . "define('PORT', '3306');\n"
            . "define('USER', " . $export((string) $db['username']) . ");\n"
            . "define('PASSWORD', " . $export((string) $db['password']) . ");\n"
            . "define('DATABASE', " . $export((string) $db['database']) . ");\n";
    }

    private function render_php_fpm_conf(int $memory_mb, array $secrets, ?array $valkey): string {
        $max_children = max(2, min(64, intdiv($memory_mb, 32)));
        $worker_memory = max(32, min(256, intdiv($memory_mb, 2)));

        $lines = [
            '[global]',
            'pid = /var/run/php-fpm/php-fpm.pid',
            'error_log = /var/log/php-fpm.log',
            'daemonize = yes',
            '',
            '[www]',
            'user = www',
            'group = www',
            'listen = /var/run/php-fpm/php-fpm.sock',
            'listen.owner = www',
            'listen.group = www',
            'listen.mode = 0660',
            'pm = ondemand',
            'pm.max_children = ' . $max_children,
            'pm.process_idle_timeout = 10s',
            'pm.max_requests = 500',
            'clear_env = yes',
            'env[PHP_INI_SCAN_DIR] = /usr/local/etc/php-base:/etc/tc/php.d',
            'env[PATH] = /usr/local/bin:/usr/bin:/bin',
            'env[TMPDIR] = /tmp',
            'php_admin_value[memory_limit] = ' . $worker_memory . 'M',
            'php_admin_value[pdo_mysql.default_socket] = /var/run/tc/mariadb/mysqld.sock',
            'php_admin_value[mysqli.default_socket] = /var/run/tc/mariadb/mysqld.sock',
        ];

        if ($valkey !== null) {
            $save_path = 'unix:///var/run/tc/valkey/valkey.sock'
                . '?auth[user]=' . rawurlencode((string) $valkey['user'])
                . '&auth[pass]=' . rawurlencode((string) $valkey['password'])
                . '&prefix=' . rawurlencode((string) $valkey['prefix'])
                . '&persistent=1';
            $lines[] = 'php_admin_value[session.save_handler] = redis';
            $lines[] = 'php_admin_value[session.save_path] = "' . $save_path . '"';
        }

        foreach ($secrets as $key => $value) {
            self::match($key, '[A-Z_][A-Z0-9_]*');
            if (!is_string($value) || strpbrk($value, "\r\n\0") !== false) {
                throw new InvalidArgumentException('Environment secrets must be single-line strings');
            }
            $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
            $lines[] = 'env[' . $key . '] = "' . $escaped . '"';
        }

        return implode("\n", $lines) . "\n";
    }

    private function render_jail_conf(
        string $jail_name,
        string $app,
        string $slot,
        string $jail_root,
        string $ip,
        string $fstab_path,
        int $memory_mb,
        int $cpu_percent,
    ): string {
        $poststart = "/usr/bin/rctl -a jail:{$jail_name}:memoryuse:deny={$memory_mb}M"
            . " && /usr/bin/rctl -a jail:{$jail_name}:swapuse:deny={$memory_mb}M"
            . " && /usr/bin/rctl -a jail:{$jail_name}:pcpu:deny={$cpu_percent}"
            . " && /usr/bin/rctl -a jail:{$jail_name}:maxproc:deny=64"
            . " && /usr/bin/rctl -a jail:{$jail_name}:openfiles:deny=1024";

        return $jail_name . " {\n"
            . "    host.hostname = \"{$app}-{$slot}.tc.internal\";\n"
            . "    path = \"{$jail_root}\";\n"
            . "    ip4.addr = \"lo1|{$ip}/32\";\n"
            . "    mount.fstab = \"{$fstab_path}\";\n"
            . "    mount.devfs;\n"
            . "    devfs_ruleset = 4;\n"
            . "    allow.raw_sockets = 0;\n"
            . "    allow.mount = 0;\n"
            . "    allow.chflags = 0;\n"
            . "    enforce_statfs = 2;\n"
            . "    exec.clean;\n"
            . "    exec.start = \"/bin/sh -c '/sbin/ldconfig /lib /usr/lib /usr/local/lib && exec /usr/bin/env PHP_INI_SCAN_DIR=/usr/local/etc/php-base:/etc/tc/php.d /usr/local/sbin/php-fpm -y /etc/tc/php-fpm.conf'\";\n"
            . "    exec.stop = \"/bin/sh -c 'test -f /var/run/php-fpm/php-fpm.pid && kill -QUIT $(cat /var/run/php-fpm/php-fpm.pid) || true'\";\n"
            . "    exec.poststart = \"{$poststart}\";\n"
            . "    exec.poststop = \"/usr/bin/rctl -r jail:{$jail_name} || true\";\n"
            . "}\n";
    }

    private function ensure_mode(string $path, int $mode): void {
        if (PHP_OS_FAMILY !== 'Windows' && !chmod($path, $mode)) {
            throw new RuntimeException('Cannot set runtime directory permissions');
        }
    }

    private function grant_www(string $path, int $mode, bool $writable): void {
        if (PHP_OS_FAMILY === 'Windows') {
            return;
        }
        if ($this->root === '/var/db/tc/runtime') {
            if ($writable && !chown($path, 'www')) {
                throw new RuntimeException('Cannot set www owner on runtime path');
            }
            if (!chgrp($path, 'www')) {
                throw new RuntimeException('Cannot set www group on runtime path');
            }
        }
        if (!chmod($path, $mode)) {
            throw new RuntimeException('Cannot set permissions on runtime path');
        }
    }

    private function valkey_auth_prefix(): string {
        $pass_file = $this->root . '/valkey-admin.pass';
        if (!is_file($pass_file)) {
            return '';
        }
        $pass = trim((string) file_get_contents($pass_file));
        if ($pass === '') {
            return '';
        }
        $pass = self::match($pass, '[A-Za-z0-9._-]{8,128}');
        return "AUTH tc_admin {$pass}\n";
    }

    /** Read-only existence/capability probe; never use for mutating commands. */
    private function probe(array $args, int $timeout = 30): ?string {
        try {
            return $this->run($args, $timeout);
        } catch (RuntimeException) {
            return null;
        }
    }

    private function dataset_exists(string $dataset): bool {
        return $this->probe(['zfs', 'list', '-H', '-o', 'name', $dataset], 15) !== null;
    }

    private function jail_running(string $jail): bool {
        return $this->probe(['jls', '-j', $jail, 'name'], 10) !== null;
    }

    private static function encode_json(mixed $value): string {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    umask(0077);
    if (array_slice($argv, 1) === ['--version']) {
        echo Runtime::PROTOCOL_VERSION . "\n";
        exit;
    }
    try {
        $data = stream_get_contents(STDIN, 1048577);
        if (!is_string($data) || strlen($data) > 1048576) {
            throw new InvalidArgumentException('Request too large');
        }
        $result = (new Runtime())->dispatch(json_decode($data, true, 512, JSON_THROW_ON_ERROR));
        echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
    } catch (Throwable $error) {
        // Never disclose request bodies, credentials, or stderr output.
        echo json_encode(['status' => 'uncertain', 'error' => get_class($error)], JSON_UNESCAPED_SLASHES) . "\n";
    }
}
