<?php
declare(strict_types=1);
require_once __DIR__ . '/../../modules/error_pages/Error_report.php';
require_once __DIR__ . '/environment.php';

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
    public bool $behind_tunnel;
    public Runtime_environment $environment;

    public function __construct(
        public string $root = '/var/db/tc/runtime',
        ?string $pool = null,
        ?string $jail_conf_dir = null,
        ?string $sockets_root = null,
        string $mariadb_socket = '/var/run/tc/mariadb/mysqld.sock',
        string $valkey_socket = '/var/run/tc/valkey/valkey.sock',
        ?string $releases_root = null,
        ?string $jails_root = null,
        ?string $bases_root = null,
        ?string $runtimes_root = null,
        ?bool $behind_tunnel = null,
        ?Runtime_environment $environment = null,
    ) {
        $this->environment = $environment ?? Runtime_environment::for_root($root);
        if ($this->environment->root !== $root) {
            throw new InvalidArgumentException('Runtime environment root mismatch');
        }
        $production = $this->environment->production;
        $pool = $pool ?? ($production ? self::configured_pool() : self::DEFAULT_POOL);
        $this->pool = $pool;
        $this->behind_tunnel = $behind_tunnel ?? ($production ? self::configured_behind_tunnel() : false);
        $this->jail_conf_dir = $jail_conf_dir ?? ($production ? '/etc/jail.conf.d' : $root . '/jail.conf.d');
        $this->sockets_root = $sockets_root ?? ($production ? '/var/run/tc/sockets' : $root . '/sockets');
        $this->mariadb_socket = $mariadb_socket;
        $this->valkey_socket = $valkey_socket;
        $this->releases_root = $releases_root ?? ($production ? '/' . $pool . '/tc/apps' : $root . '/releases');
        $this->jails_root = $jails_root ?? ($production ? '/' . $pool . '/tc/jails' : $root . '/jails');
        $this->bases_root = $bases_root ?? ($production ? '/' . $pool . '/tc/bases' : $root . '/bases');
        $this->runtimes_root = $runtimes_root ?? ($production ? '/' . $pool . '/tc/runtimes' : $root . '/runtimes');

        foreach (['', '/jobs', '/apps', '/routes', '/services'] as $directory) {
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
        $registry_ready = $this->dataset_exists($this->pool . '/tc/registry');

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
            'registry_dataset' => $registry_ready,
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
            'registry_ready' => $registry_ready,
            'missing' => $missing,
        ];
    }

    public function inspect_panel_worker(): array {
        $latest_php_error = $this->latest_panel_php_error();
        if ($this->probe(['jls', '-j', 'tc_panel', 'name'], 5) === null) {
            return [
                'status' => 'succeeded',
                'panel_jail_running' => false,
                'worker_service_active' => false,
                'worker_process_samples' => [],
                'worker_source_sha256' => null,
                'latest_php_error' => $latest_php_error,
            ];
        }

        $service_active = $this->probe(['jexec', 'tc_panel', 'service', 'tc_worker', 'onestatus'], 5) !== null;
        $process_samples = [];
        for ($sample = 0; $sample < 3; $sample++) {
            $output = $this->run(['jexec', 'tc_panel', 'ps', '-axo', 'command'], 5);
            $worker_count = 0;
            // The daemon supervisor also carries worker.php in argv; count only the PHP child.
            foreach (explode("\n", $output) as $command) {
                $parts = preg_split('/\s+/', trim($command), 2);
                if (
                    is_array($parts)
                    && count($parts) === 2
                    && basename($parts[0]) === 'php'
                    && str_contains($parts[1], '/app/modules/jobs/worker.php')
                ) {
                    $worker_count++;
                }
            }
            $process_samples[] = $worker_count;
            if ($sample < 2) {
                usleep(250000);
            }
        }

        $source_path = $this->jails_root . '/tc_panel/app/modules/jobs/worker.php';
        $source_hash = hash_file('sha256', $source_path);
        if (!is_string($source_hash)) {
            throw new RuntimeException('Could not verify the deployed worker source file.');
        }

        return [
            'status' => 'succeeded',
            'panel_jail_running' => true,
            'worker_service_active' => $service_active,
            'worker_process_samples' => $process_samples,
            'worker_source_sha256' => $source_hash,
            'latest_php_error' => $latest_php_error,
        ];
    }

    private function latest_panel_php_error(): ?array {
        $path = $this->jails_root . '/tc_panel/var/log/php-fpm.log';
        if (!is_file($path)) {
            return null;
        }
        if (!is_readable($path)) {
            throw new RuntimeException('Could not read the panel PHP-FPM error log.');
        }

        $lines = preg_split('/\r\n|\n|\r/', self::read_tail($path, 262144)) ?: [];
        for ($index = count($lines) - 1; $index >= 0; $index--) {
            $line = $lines[$index];
            $patterns = [
                '/\bUncaught\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\b.*\s+in\s+\/app\/([A-Za-z0-9_.\/-]+):([0-9]+)\s*$/D',
                '/\bUncaught\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\b.*\s+in\s+\/app\/([A-Za-z0-9_.\/-]+)\s+on line\s+([0-9]+)\s*$/D',
            ];
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $line, $matches) !== 1) {
                    continue;
                }

                $file = $matches[2];
                if (preg_match('~(?:\A|/)\.\.?(/|\z)~', $file) === 1) {
                    continue;
                }
                $type_parts = explode('\\', $matches[1]);
                return [
                    'type' => end($type_parts),
                    'file' => $file,
                    'line' => (int) $matches[3],
                ];
            }
        }

        return null;
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

    public function health(string $url, int $attempts = 20, array $headers = []): bool {
        for ($i = 0; $i < $attempts; $i++) {
            try {
                $args = ['curl', '--fail', '--silent', '--max-time', '5', '--output', '/dev/null'];
                foreach ($headers as $header) {
                    $args[] = '-H';
                    $args[] = $header;
                }
                $args[] = $url;
                $this->run($args, 10);
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

    public static function canary_uuid_regex(int $weight): ?string {
        if ($weight <= 0) {
            return null;
        }
        $buckets = max(1, min(255, (int) round($weight * 256 / 100)));
        $max = $buckets - 1;
        $hi = intdiv($max, 16);
        $lo = $max % 16;
        $hex = static fn(int $n): string => dechex($n);
        $range = static function (int $start, int $end) use ($hex): string {
            if ($start === $end) {
                return $hex($start);
            }
            if ($end <= 9 || $start >= 10) {
                return '[' . $hex($start) . '-' . $hex($end) . ']';
            }
            $digits = $start === 9 ? '9' : ($start . '-9');
            $letters = $end === 10 ? 'a' : ('a-' . $hex($end));
            return '[' . $digits . $letters . ']';
        };

        if ($lo === 15) {
            return '^' . $range(0, $hi);
        }
        $parts = [];
        if ($hi > 0) {
            $parts[] = $range(0, $hi - 1) . '[0-9a-f]';
        }
        $parts[] = $hex($hi) . $range(0, $lo);
        return count($parts) === 1 ? '^' . $parts[0] : '^(?:' . implode('|', $parts) . ')';
    }

    private function render_slot_handler(string $app, array $release, string $role, ?string $set_cookie = null): string {
        $release_base = (string) ($release['release_path'] ?? ($this->releases_root . '/' . $app . '/releases/' . $release['operation']));
        $public_root = rtrim($release_base, '/') . '/public';
        $socket = 'unix//' . ltrim((string) $release['socket'], '/');
        $slot = self::match($release['slot'] ?? 'blue', 'blue|green');

        $lines = [];
        if ($set_cookie !== null) {
            $lines[] = '        header +Set-Cookie "' . $set_cookie . '"';
        }
        $lines[] = '        header X-TC-Slot "' . $slot . '"';
        $lines[] = '        header X-TC-Release "' . $role . '"';
        $lines[] = '        root * ' . $public_root;
        $lines[] = '        php_fastcgi ' . $socket . ' {';
        $lines[] = '            root /app/public';
        $lines[] = '        }';
        $lines[] = '        file_server';
        $lines[] = '        header {';
        $lines[] = "            Content-Security-Policy \"frame-ancestors 'self'\"";
        $lines[] = '        }';
        return implode("\n", $lines) . "\n";
    }

    public function route(string $app, ?array $release, ?array $canary = null, int $canary_weight = 0): void {
        $app = self::match($app, '[a-z][a-z0-9-]{0,39}');
        $path = $this->root . '/routes/' . $app . '.caddy';
        $logs_dir = $this->root . '/logs';
        $access_log = $logs_dir . '/' . $app . '-access.log';

        if ($release === null) {
            self::remove($path);
        } elseif ($canary !== null) {
            self::directory($logs_dir, 0700);
            $domain = self::match($release['domain'], '[a-z0-9]+(?:[.-][a-z0-9]+)+');
            $weight = max(0, min(90, $canary_weight));
            $sample_regex = self::canary_uuid_regex($weight);
            $site = $this->behind_tunnel ? 'http://' . $domain : $domain;
            $stable_cookie = 'tc_canary=0; Path=/; Max-Age=1800; SameSite=Lax';
            $canary_cookie = 'tc_canary=1; Path=/; Max-Age=1800; SameSite=Lax';

            $block = $site . " {\n"
                . "    log {\n"
                . "        output file " . $access_log . " {\n"
                . "            roll_size 10MiB\n"
                . "            roll_keep 2\n"
                . "            roll_keep_for 24h\n"
                . "        }\n"
                . "        format json\n"
                . "    }\n"
                . "    @force_stable expression `{http.request.header.X-Canary} == \"0\" || {query.tc_canary} == \"0\"`\n"
                . "    @force_canary expression `{http.request.header.X-Canary} == \"1\" || {query.tc_canary} == \"1\"`\n"
                . "    @cookie_stable header Cookie *tc_canary=0*\n"
                . "    @cookie_canary header Cookie *tc_canary=1*\n";
            if ($sample_regex !== null) {
                $block .= "    @sample_canary expression `{http.request.uuid}.matches(\"" . $sample_regex . "\")`\n";
            }
            $block .= "\n"
                . "    handle @force_stable {\n"
                . $this->render_slot_handler($app, $release, 'stable', $stable_cookie)
                . "    }\n"
                . "    handle @force_canary {\n"
                . $this->render_slot_handler($app, $canary, 'canary', $canary_cookie)
                . "    }\n"
                . "    handle @cookie_stable {\n"
                . $this->render_slot_handler($app, $release, 'stable')
                . "    }\n"
                . "    handle @cookie_canary {\n"
                . $this->render_slot_handler($app, $canary, 'canary')
                . "    }\n";
            if ($sample_regex !== null) {
                $block .= "    handle @sample_canary {\n"
                    . $this->render_slot_handler($app, $canary, 'canary', $canary_cookie)
                    . "    }\n";
            }
            $block .= "    handle {\n"
                . $this->render_slot_handler($app, $release, 'stable', $sample_regex !== null ? $stable_cookie : null)
                . "    }\n"
                . "}\n";

            $this->write($path, $block, 0644);
        } else {
            self::directory($logs_dir, 0700);
            $domain = self::match($release['domain'], '[a-z0-9]+(?:[.-][a-z0-9]+)+');
            $release_base = (string) ($release['release_path'] ?? ($this->releases_root . '/' . $app . '/releases/' . $release['operation']));
            $public_root = rtrim($release_base, '/') . '/public';
            $socket = 'unix//' . ltrim((string) $release['socket'], '/');

            $site = $this->behind_tunnel ? 'http://' . $domain : $domain;
            $block = $site . " {\n"
                . "    log {\n"
                . "        output file " . $access_log . " {\n"
                . "            roll_size 10MiB\n"
                . "            roll_keep 2\n"
                . "            roll_keep_for 24h\n"
                . "        }\n"
                . "        format json\n"
                . "    }\n"
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
        $disk_quota_mb = max(128, (int) ($spec['disk_quota_mb'] ?? 128));

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

        // Resolve and verify ZFS-backed registry modules for zero-copy read-only nullfs mounting.
        $resolved_modules = [];
        $autowire_error_pages = false;
        $autowire_error_404 = 'error_pages/not_found';
        $autowire_log = false;
        $autowire_log_channel = 'app';
        if (!empty($spec['modules']) && is_array($spec['modules'])) {
            require_once __DIR__ . '/registry.php';
            $registry = new Registry_runtime($this);
            $seen_mod_names = [];
            foreach ($spec['modules'] as $mod_entry) {
                if (!is_array($mod_entry)) {
                    throw new InvalidArgumentException('Invalid registry module entry');
                }
                $mod = $registry->resolve_mount($mod_entry);
                if (isset($seen_mod_names[$mod['name']])) {
                    throw new InvalidArgumentException('Duplicate registry module mount: ' . $mod['name']);
                }
                $seen_mod_names[$mod['name']] = true;
                $mod_config = is_array($mod_entry['config'] ?? null) ? $mod_entry['config'] : [];
                $mod['config'] = $mod_config;
                $resolved_modules[] = $mod;
                if ($mod['autowire'] && $mod['name'] === 'error_pages') {
                    $autowire_error_pages = true;
                    if (isset($mod_config['error_404_route']) && is_string($mod_config['error_404_route']) && trim($mod_config['error_404_route']) !== '') {
                        $autowire_error_404 = trim($mod_config['error_404_route']);
                    }
                }
                if ($mod['autowire'] && $mod['name'] === 'log') {
                    $autowire_log = true;
                    if (isset($mod_config['channel_prefix']) && is_string($mod_config['channel_prefix']) && trim($mod_config['channel_prefix']) !== '') {
                        $autowire_log_channel = trim($mod_config['channel_prefix']);
                    }
                }
                // Materialize per-module PHP config file in /app/config/<module>.php when configured.
                $cfg_target = '/app/config/' . $mod['name'] . '.php';
                if ($mod_config !== [] && !array_key_exists($cfg_target, $files) && !in_array($mod['name'], ['config', 'database', 'custom_routing', 'site_owner'], true)) {
                    $files[$cfg_target] = self::render_module_config_php($mod['namespace'] . '/' . $mod['name'] . '@' . $mod['version'], $mod_config);
                }
            }
        }

        // Patch only BASE_URL, ENV (and ERROR_404 when error_pages is auto-wired) in config/config.php.
        if (!array_key_exists('/app/config/config.php', $files)) {
            $source_config = $release_dir . '/config/config.php';
            if (is_file($source_config)) {
                $raw_config = file_get_contents($source_config);
                if ($raw_config === false) {
                    throw new RuntimeException('Cannot read release config/config.php');
                }
                $trongate_env = (string) ($secrets['ENV'] ?? $secrets['APP_ENV'] ?? ($spec['env'] ?? 'production'));
                $files['/app/config/config.php'] = self::patch_config_php(
                    $raw_config,
                    $domain,
                    $trongate_env,
                    true,
                    $autowire_error_pages ? $autowire_error_404 : null
                );
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

        $this->write($jail_root . '/var/log/php-fpm.log', '', 0640);
        $this->grant_www($jail_root . '/var/log/php-fpm.log', 0640, writable: true);

        // Optional auto-prepend bootstrap for auto-wired registry modules (error_pages, log).
        $auto_prepend = null;
        if ($autowire_error_pages || $autowire_log) {
            $boot_code = self::render_modules_boot_php($autowire_error_pages, $autowire_log, $autowire_log_channel);
            $this->write($jail_root . '/etc/tc/tc_modules_boot.php', $boot_code, 0444);
            $auto_prepend = '/etc/tc/tc_modules_boot.php';
        }

        // Write jail-local php-fpm.conf with ondemand PM, socket bindings, and secrets.
        $php_fpm_conf = $this->render_php_fpm_conf($memory_mb, $secrets, $valkey, $auto_prepend);
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
        foreach ($resolved_modules as $mod) {
            $mod_target = '/app/modules/' . $mod['name'];
            self::directory($jail_root . $mod_target, 0755);
            if (is_dir($release_dir)) {
                $release_mod_dir = $release_dir . '/modules/' . $mod['name'];
                if (is_dir($release_mod_dir)) {
                    $has_entries = (new FilesystemIterator($release_mod_dir, FilesystemIterator::SKIP_DOTS))->valid();
                    if ($has_entries) {
                        throw new RuntimeException('Cannot mount registry module "' . $mod['name'] . '": release already contains modules/' . $mod['name']);
                    }
                } else {
                    self::directory($release_mod_dir, 0755);
                    $this->ensure_mode($release_mod_dir, 0755);
                }
            }
            $fstab_lines[] = $mod['path'] . ' ' . $jail_root . $mod_target . ' nullfs ro 0 0';
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
            'modules' => array_map(
                static fn(array $m): array => [
                    'namespace' => $m['namespace'],
                    'name' => $m['name'],
                    'version' => $m['version'],
                    'digest' => $m['digest'],
                    'autowire' => $m['autowire'],
                ],
                $resolved_modules
            ),
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
        if (!empty($state['canary'])) {
            throw new RuntimeException('Promote or abort the active canary before starting another deployment');
        }
        if (!empty($request['canary']) && $request['action'] === 'deploy' && empty($state['active'])) {
            throw new RuntimeException('Cannot start a canary before the app has an active release');
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
                    if (($allocation['mode'] ?? '') === 'manual' || !isset($spec['disk_quota_mb'])) {
                        $merged['disk_quota_mb'] = (int) $allocation['disk_quota_mb'];
                    }
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

        if (!empty($request['canary']) && $previous !== null) {
            $weight = max(0, min(90, (int) ($request['canary_weight'] ?? 10)));
            $this->checkpoint('routing-canary');
            $this->route($app, $previous, $candidate, $weight);
            if (!$this->healthy($candidate)) {
                throw new RuntimeException('Health check failed after traffic switch');
            }

            $this->checkpoint('checking-public');
            if (($request['public_check'] ?? true) && !$this->health('https://' . $candidate['domain'] . $candidate['health'], 30, ['X-Canary: 1'])) {
                throw new RuntimeException('Public health check failed');
            }

            self::remove($this->root . '/apps/' . $app . '/staged.json');
            $started_at = time();
            $this->write($state_path, [
                'active' => $previous,
                'previous' => $journal['before']['previous'] ?? null,
                'canary' => [
                    'release' => $candidate,
                    'weight' => $weight,
                    'started_at' => $started_at,
                ],
            ]);
            unset($journal['message']);

            $journal = array_replace($journal, [
                'status' => 'succeeded',
                'phase' => 'canary-active',
                'active' => $previous['operation'],
                'canary' => [
                    'operation' => $candidate['operation'],
                    'slot' => $candidate['slot'],
                    'weight' => $weight,
                    'commit' => $candidate['commit'] ?? null,
                    'started_at' => $started_at,
                ],
            ]);
            $journal['events'][] = ['step' => 'canary-active', 'at' => $started_at];
            $this->write($journal_path, $journal);

            return $journal;
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
        $this->write($state_path, ['active' => $candidate, 'previous' => $previous, 'canary' => null]);
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

    public function canary_operation(array $request): array {
        $action = (string) ($request['action'] ?? '');
        $operation = self::match($request['operation'] ?? null, '[a-f0-9]{32}');
        $app = self::match($request['app'] ?? null, '[a-z][a-z0-9-]{0,39}');
        $journal_path = $this->root . '/jobs/' . $operation . '.json';
        $state_path = $this->root . '/apps/' . $app . '/state.json';

        $recorded = $this->read($journal_path);
        if (is_array($recorded) && in_array($recorded['status'] ?? '', ['succeeded', 'failed'], true)) {
            return $recorded;
        }

        $state = $this->read($state_path, ['active' => null, 'previous' => null, 'canary' => null]);
        $active = is_array($state['active'] ?? null) ? $state['active'] : null;
        $canary_entry = is_array($state['canary'] ?? null) ? $state['canary'] : null;
        $canary = is_array($canary_entry['release'] ?? null) ? $canary_entry['release'] : null;

        $journal = [
            'operation' => $operation,
            'app' => $app,
            'action' => $action,
            'status' => 'running',
            'phase' => 'intent',
            'before' => $state,
            'events' => [],
        ];
        $this->write($journal_path, $journal);

        $this->progress = function (string $phase) use (&$journal, $journal_path): void {
            $journal['phase'] = $phase;
            $journal['events'][] = ['step' => $phase, 'at' => time()];
            $this->write($journal_path, $journal);
        };

        try {
            if ($active === null || $canary === null) {
                throw new RuntimeException('No active canary deployment for this application');
            }

            if ($action === 'canary-weight') {
                $weight = max(0, min(90, (int) ($request['weight'] ?? 10)));
                $this->checkpoint('checking-local');
                if (!$this->healthy($canary)) {
                    throw new RuntimeException('Canary health check failed');
                }
                $this->checkpoint('routing-canary');
                $this->route($app, $active, $canary, $weight);
                $canary_entry['weight'] = $weight;
                $state['canary'] = $canary_entry;
                $this->write($state_path, $state);

                $journal = array_replace($journal, [
                    'status' => 'succeeded',
                    'phase' => 'canary-updated',
                    'active' => $active['operation'],
                    'weight' => $weight,
                    'canary' => [
                        'operation' => $canary['operation'],
                        'slot' => $canary['slot'],
                        'weight' => $weight,
                        'commit' => $canary['commit'] ?? null,
                        'started_at' => $canary_entry['started_at'] ?? time(),
                    ],
                ]);
                $journal['events'][] = ['step' => 'canary-updated', 'at' => time()];
                $this->write($journal_path, $journal);
                return $journal;
            }

            if ($action === 'canary-promote') {
                $this->checkpoint('checking-local');
                if (!$this->healthy($canary)) {
                    throw new RuntimeException('Canary health check failed');
                }
                $this->checkpoint('switching');
                $this->route($app, $canary);
                if (!$this->healthy($canary)) {
                    $this->route($app, $active, $canary, (int) ($canary_entry['weight'] ?? 10));
                    throw new RuntimeException('Health check failed after traffic switch');
                }
                $this->checkpoint('checking-public');
                if (($request['public_check'] ?? true) && !$this->health('https://' . $canary['domain'] . $canary['health'], 30)) {
                    $this->route($app, $active, $canary, (int) ($canary_entry['weight'] ?? 10));
                    throw new RuntimeException('Public health check failed');
                }

                $this->write($state_path, [
                    'active' => $canary,
                    'previous' => $active,
                    'canary' => null,
                ]);

                try {
                    if (($active['operation'] ?? '') !== ($canary['operation'] ?? '')) {
                        $this->checkpoint('retiring-previous');
                        $this->drain();
                        $this->stop($active);
                    }
                } catch (Throwable) {
                    $journal['retirement'] = 'previous jail still running; safe to retire later';
                }

                $journal = array_replace($journal, [
                    'status' => 'succeeded',
                    'phase' => 'accepted',
                    'active' => $canary['operation'],
                    'candidate' => $canary,
                ]);
                $journal['events'][] = ['step' => 'accepted', 'at' => time()];
                $this->write($journal_path, $journal);
                return $journal;
            }

            if ($action === 'canary-abort') {
                $this->checkpoint('switching');
                $this->route($app, $active);
                $this->write($state_path, [
                    'active' => $active,
                    'previous' => $state['previous'] ?? null,
                    'canary' => null,
                ]);

                try {
                    if (($canary['operation'] ?? '') !== ($active['operation'] ?? '')) {
                        $this->checkpoint('retiring-canary');
                        $this->drain();
                        $this->stop($canary);
                    }
                } catch (Throwable) {
                    $journal['retirement'] = 'canary jail still running; safe to retire later';
                }

                $journal = array_replace($journal, [
                    'status' => 'succeeded',
                    'phase' => 'canary-aborted',
                    'active' => $active['operation'],
                ]);
                $journal['events'][] = ['step' => 'canary-aborted', 'at' => time()];
                $this->write($journal_path, $journal);
                return $journal;
            }

            throw new InvalidArgumentException('Unknown canary action');
        } catch (Throwable $error) {
            $journal['status'] = 'failed';
            $journal['error'] = $error instanceof RuntimeException || $error instanceof InvalidArgumentException
                ? $error->getMessage()
                : get_class($error);
            $this->write($journal_path, $journal);
            return $journal;
        }
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
            $this->write($this->root . '/apps/' . $app . '/state.json', ['active' => null, 'previous' => null, 'canary' => null]);

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
        $production = $this->environment->production;
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
            try {
                $result = $this->builder()->build($request);
            } catch (RuntimeException|InvalidArgumentException $error) {
                $reason = self::safe_error($error->getMessage());
                $result = [
                    'status' => 'failed',
                    'operation' => $operation,
                    'reason' => $reason !== '' ? $reason : get_class($error),
                    'error' => $reason !== '' ? $reason : get_class($error),
                ];
            }
            if (in_array($result['status'] ?? '', ['succeeded', 'failed'], true)) {
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
            $app_state = $this->read($directory . '/state.json', []);
            foreach ($app_state as $release) {
                if (is_array($release) && isset($release['operation'])) {
                    $keep[] = $release['operation'];
                } elseif (is_array($release) && isset($release['release']['operation'])) {
                    $keep[] = $release['release']['operation'];
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
        if ($action === 'inspect-panel-worker') {
            return $this->inspect_panel_worker();
        }
        if ($action === 'build') {
            return $this->local_build($request);
        }
        if ($action === 'metrics') {
            require_once __DIR__ . '/metrics.php';
            return Metrics::collect($this->root, $this);
        }
        if ($action === 'app-logs') {
            return $this->app_logs($request);
        }
        if ($action === 'app-traffic') {
            return $this->app_traffic($request);
        }
        if ($action === 'app-db-inspect') {
            return $this->app_db_inspect($request);
        }
        if ($action === 'app-db-query') {
            return $this->app_db_query($request);
        }
        if ($action === 'registry-list') {
            require_once __DIR__ . '/registry.php';
            return (new Registry_runtime($this))->list_modules();
        }
        if ($action === 'registry-inspect') {
            require_once __DIR__ . '/registry.php';
            return (new Registry_runtime($this))->inspect_module($request);
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
            if ($action === 'refresh-routes') {
                return $this->refresh_routes();
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
            if ($action === 'deploy-service') {
                return $this->deploy_service($request);
            }
            if ($action === 'destroy-service') {
                return $this->destroy_service($request);
            }
            if ($action === 'registry-init-repo') {
                require_once __DIR__ . '/registry.php';
                return (new Registry_runtime($this))->init_repo($request);
            }
            if ($action === 'registry-publish') {
                require_once __DIR__ . '/registry.php';
                return (new Registry_runtime($this))->publish($request);
            }
            if ($action === 'registry-yank') {
                require_once __DIR__ . '/registry.php';
                return (new Registry_runtime($this))->yank($request);
            }
            if (in_array($action, ['canary-weight', 'canary-promote', 'canary-abort'], true)) {
                return $this->canary_operation($request);
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

    public function allocate_service_ip(string $name): string {
        $name = self::match($name, '[a-z][a-z0-9_]{0,39}');
        $path = $this->root . '/ips.json';
        $ips = $this->read($path, []);
        $key = 'svc:' . $name;
        if (isset($ips[$key]) && is_string($ips[$key])) {
            return $ips[$key];
        }

        $used = array_flip(array_values(array_filter($ips, 'is_string')));
        for ($fourth = 1; $fourth <= 250; $fourth++) {
            $candidate = "10.99.251.{$fourth}";
            if (!isset($used[$candidate])) {
                $ips[$key] = $candidate;
                $this->write($path, $ips);
                return $candidate;
            }
        }

        throw new RuntimeException('No free loopback service IP addresses remaining in 10.99.251.1-10.99.251.250');
    }

    public function deploy_service(array $request): array {
        $operation = self::match($request['operation'] ?? null, '[a-f0-9]{32}');
        $spec = is_array($request['service'] ?? null) ? $request['service'] : [];
        $name = self::match($spec['name'] ?? null, '[a-z][a-z0-9_]{0,39}');
        $port = (int) ($spec['port'] ?? 8080);
        if ($port < 1024 || $port > 65535) {
            throw new InvalidArgumentException('Service port must be between 1024 and 65535');
        }
        $memory_mb = (int) ($spec['memory_mb'] ?? 512);
        if ($memory_mb < 64 || $memory_mb > 8192) {
            throw new InvalidArgumentException('Service memory must be between 64 and 8192 MiB');
        }
        $command = trim((string) ($spec['command'] ?? ''));
        if ($command === '' || strlen($command) > 500 || preg_match('/[\x00-\x1F\x7F]/', $command)) {
            throw new InvalidArgumentException('Service start command must be a single line up to 500 characters');
        }
        $setup = trim(str_replace(["\r\n", "\r"], "\n", (string) ($spec['setup'] ?? '')));
        if (strlen($setup) > 4000 || preg_match('/[\x00-\x08\x0B-\x1F\x7F]/', $setup)) {
            throw new InvalidArgumentException('Service setup commands are invalid or too long');
        }
        $raw_packages = $spec['packages'] ?? [];
        if (!is_array($raw_packages) || count($raw_packages) > 30) {
            throw new InvalidArgumentException('Service packages must be a list of at most 30 package names');
        }
        $packages = [];
        foreach ($raw_packages as $pkg) {
            if (!is_string($pkg)) {
                throw new InvalidArgumentException('Invalid package name');
            }
            $packages[] = self::match(trim($pkg), '[a-zA-Z0-9][a-zA-Z0-9._+-]{0,63}');
        }
        $packages = array_values(array_unique($packages));

        $env = is_array($request['env'] ?? null) ? $request['env'] : [];
        $clean_env = [];
        foreach ($env as $k => $v) {
            $key = self::match((string) $k, '[A-Z_][A-Z0-9_]*');
            $val = (string) $v;
            if (strlen($val) > 8192 || str_contains($val, "\0") || str_contains($val, "\n") || str_contains($val, "\r")) {
                throw new InvalidArgumentException('Invalid service environment variable: ' . $key);
            }
            $clean_env[$key] = $val;
        }

        $journal_path = $this->root . '/jobs/' . $operation . '.json';
        $journal = [
            'operation' => $operation,
            'status' => 'running',
            'phase' => 'staging-service',
            'service' => $name,
            'events' => [['step' => 'staging-service', 'at' => time()]],
        ];
        $this->write($journal_path, $journal);

        $jail_name = 'tc_svc_' . $name;
        $ip = $this->allocate_service_ip($name);
        $jail_dataset = $this->pool . '/tc/jails/svc-' . $name;
        $data_dataset = $this->pool . '/tc/services/custom/' . $name;
        $jail_root = $this->jails_root . '/svc-' . $name;
        $data_root = '/' . $data_dataset;
        if (!$this->environment->production) {
            $data_root = $this->root . '/services/' . $name . '/data';
        }
        $base_dir = $this->resolve_base_dir();
        $conf_path = $this->jail_conf_dir . '/' . $jail_name . '.conf';
        $fstab_path = $this->jail_conf_dir . '/' . $jail_name . '.fstab';

        try {
            if ($this->jail_running($jail_name)) {
                $this->run(['jail', '-r', $jail_name], 60);
            }
            $this->remove_service_rctl($jail_name);
            if ($this->environment->production) {
                foreach ([$jail_root . '/dev', $jail_root . '/.base', $jail_root . '/var/db/service'] as $mnt) {
                    $this->probe(['umount', '-f', $mnt], 15);
                }
            }

            if (!$this->dataset_exists($jail_dataset)) {
                $this->run(['zfs', 'clone', $this->pool . '/tc/skeleton@v1', $jail_dataset], 60);
            }
            $this->ensure_zfs_dataset($data_dataset, ['atime' => 'off', 'compression' => 'lz4']);

            foreach ([$jail_root . '/.base', $jail_root . '/dev', $jail_root . '/etc/tc', $jail_root . '/var/db/service', $jail_root . '/var/log', $jail_root . '/var/run', $data_root] as $dir) {
                self::directory($dir, 0755);
            }
            $this->grant_www($data_root, 0750, true);
            $this->grant_www($jail_root . '/var/log', 0755, true);

            if ($packages !== []) {
                $journal['phase'] = 'installing-packages';
                $this->write($journal_path, $journal);
                $ports_repos = $this->detect_ports_repos();
                $mounted_base = false;
                $mounted_dev = false;
                $repos_dir = null;
                try {
                    if ($this->environment->production) {
                        $this->run(['mount', '-t', 'nullfs', '-o', 'ro', $base_dir, $jail_root . '/.base'], 30);
                        $mounted_base = true;
                        $this->run(['mount', '-t', 'devfs', 'devfs', $jail_root . '/dev'], 30);
                        $mounted_dev = true;
                    }
                    if (count($ports_repos) === 1) {
                        $this->run(['env', 'ASSUME_ALWAYS_YES=yes', 'pkg', '-r', $jail_root, 'update', '-r', $ports_repos[0]], 300);
                        $this->run(array_merge(['env', 'ASSUME_ALWAYS_YES=yes', 'pkg', '-r', $jail_root, 'install', '-r', $ports_repos[0], '-y'], $packages), 900);
                    } else {
                        $repos_dir = $this->prepare_service_pkg_repos($jail_root);
                        $this->run(['env', 'ASSUME_ALWAYS_YES=yes', 'pkg', '-r', $jail_root, '-o', 'REPOS_DIR=' . $repos_dir, 'update'], 300);
                        $this->run(array_merge(['env', 'ASSUME_ALWAYS_YES=yes', 'pkg', '-r', $jail_root, '-o', 'REPOS_DIR=' . $repos_dir, 'install', '-y'], $packages), 900);
                    }
                } finally {
                    if ($repos_dir !== null && is_dir($repos_dir)) {
                        self::remove_tree($repos_dir);
                    }
                    if ($mounted_dev) {
                        $this->probe(['umount', $jail_root . '/dev'], 30);
                    }
                    if ($mounted_base) {
                        $this->probe(['umount', $jail_root . '/.base'], 30);
                    }
                }
            }

            $all_env = array_merge([
                'PATH' => '/usr/local/bin:/usr/bin:/bin:/usr/local/sbin:/usr/sbin:/sbin',
                'HOME' => '/var/db/service',
                'TMPDIR' => '/tmp',
                'PORT' => (string) $port,
                'HOST' => '0.0.0.0',
            ], $clean_env);
            $env_lines = [];
            foreach ($all_env as $k => $v) {
                $escaped = str_replace("'", "'\"'\"'", $v);
                $env_lines[] = "export {$k}='{$escaped}'";
            }
            $this->write($jail_root . '/etc/tc/service.env', implode("\n", $env_lines) . "\n", 0600);
            $this->grant_www($jail_root . '/etc/tc/service.env', 0600, true);

            $start_script = "#!/bin/sh\nset -eu\n. /etc/tc/service.env\ncd /var/db/service\nexec /bin/sh -c '"
                . str_replace("'", "'\"'\"'", $command) . "'\n";
            $this->write($jail_root . '/etc/tc/start.sh', $start_script, 0755);

            if ($setup !== '') {
                $setup_script = "#!/bin/sh\nset -eu\nexec >/var/log/setup.log 2>&1\n. /etc/tc/service.env\ncd /var/db/service\n{$setup}\n";
                $this->write($jail_root . '/etc/tc/setup.sh', $setup_script, 0755);
            } else {
                self::remove($jail_root . '/etc/tc/setup.sh');
            }

            $fstab = "{$base_dir} {$jail_root}/.base nullfs ro 0 0\n"
                . "{$data_root} {$jail_root}/var/db/service nullfs rw 0 0\n";
            $this->write($fstab_path, $fstab, 0644);

            $conf = $this->render_service_jail_conf($jail_name, $name, $jail_root, $ip, $fstab_path, $memory_mb);
            $this->write($conf_path, $conf, 0644);

            $this->run(['pfctl', '-t', 'tc_services', '-T', 'add', $ip], 30);

            if ($setup !== '') {
                $journal['phase'] = 'running-setup';
                $this->write($journal_path, $journal);
                // Give one-time setup 2x memory headroom (capped at 8192M) so fork()
                // after large downloads/model loads does not hit rctl ENOMEM.
                $setup_memory_mb = min(8192, max($memory_mb * 2, 2048));
                $setup_conf = $this->render_service_jail_conf($jail_name, $name, $jail_root, $ip, $fstab_path, $setup_memory_mb, true);
                $setup_conf_path = $this->jail_conf_dir . '/' . $jail_name . '.setup.conf';
                $this->write($setup_conf_path, $setup_conf, 0644);
                try {
                    $this->run(['jail', '-c', '-f', $setup_conf_path, $jail_name], 60);
                    $this->run(['jexec', $jail_name, '/sbin/ldconfig', '/lib', '/usr/lib', '/usr/local/lib'], 30);
                    $this->run(['jexec', $jail_name, '/bin/sh', '/etc/tc/setup.sh'], 1800);
                    $this->run(['jexec', $jail_name, 'chown', '-R', 'www:www', '/var/db/service'], 120);
                } finally {
                    if ($this->jail_running($jail_name)) {
                        $this->probe(['jail', '-r', $jail_name], 60);
                    }
                    $this->remove_service_rctl($jail_name);
                    self::remove($setup_conf_path);
                }
            }

            $journal['phase'] = 'starting-service';
            $this->write($journal_path, $journal);
            $this->run(['jail', '-c', '-f', $conf_path, $jail_name], 60);
            $this->apply_service_rctl($jail_name, $memory_mb);

            $journal['phase'] = 'checking-service';
            $this->write($journal_path, $journal);
            $ready = $this->tcp_ready($ip, $port, 90);
            if (!$ready) {
                throw new RuntimeException("Service {$name} did not begin listening on {$ip}:{$port}");
            }

            $state = [
                'name' => $name,
                'jail' => $jail_name,
                'ip' => $ip,
                'port' => $port,
                'url' => "http://{$ip}:{$port}",
                'memory_mb' => $memory_mb,
                'jail_conf' => $conf_path,
                'packages' => $packages,
                'command' => $command,
                'deployed_at' => time(),
            ];
            $this->write($this->root . '/services/' . $name . '/state.json', $state, 0600);

            $journal = array_replace($journal, [
                'status' => 'succeeded',
                'phase' => 'running',
                'name' => $name,
                'jail' => $jail_name,
                'ip' => $ip,
                'port' => $port,
                'url' => $state['url'],
                'memory_mb' => $memory_mb,
            ]);
            $journal['events'][] = ['step' => 'running', 'at' => time()];
            $this->write($journal_path, $journal);
            return $journal;
        } catch (Throwable $error) {
            if ($this->jail_running($jail_name)) {
                $this->probe(['jail', '-r', $jail_name], 60);
            }
            $this->remove_service_rctl($jail_name);
            $journal['status'] = 'failed';
            $journal['error'] = $error instanceof RuntimeException || $error instanceof InvalidArgumentException
                ? $error->getMessage()
                : get_class($error);
            $this->write($journal_path, $journal);
            return $journal;
        }
    }

    public function destroy_service(array $request): array {
        $operation = self::match($request['operation'] ?? null, '[a-f0-9]{32}');
        $name = self::match($request['name'] ?? null, '[a-z][a-z0-9_]{0,39}');
        $journal_path = $this->root . '/jobs/' . $operation . '.json';
        $journal = [
            'operation' => $operation,
            'status' => 'running',
            'phase' => 'destroying-service',
            'service' => $name,
            'events' => [['step' => 'destroying-service', 'at' => time()]],
        ];
        $this->write($journal_path, $journal);

        try {
            $jail_name = 'tc_svc_' . $name;
            $ips_path = $this->root . '/ips.json';
            $ips = $this->read($ips_path, []);
            $ip = $ips['svc:' . $name] ?? null;

            if ($this->jail_running($jail_name)) {
                $this->run(['jail', '-r', $jail_name], 60);
            }
            if (is_string($ip) && $ip !== '') {
                $this->probe(['pfctl', '-t', 'tc_services', '-T', 'delete', $ip], 30);
            }
            unset($ips['svc:' . $name]);
            $this->write($ips_path, $ips);
            $this->remove_service_rctl($jail_name);

            self::remove($this->jail_conf_dir . '/' . $jail_name . '.conf');
            self::remove($this->jail_conf_dir . '/' . $jail_name . '.fstab');
            self::remove($this->jail_conf_dir . '/' . $jail_name . '.setup.conf');

            foreach ([$this->pool . '/tc/jails/svc-' . $name, $this->pool . '/tc/services/custom/' . $name] as $ds) {
                if ($this->dataset_exists($ds)) {
                    $this->run(['zfs', 'destroy', '-r', $ds], 60);
                }
            }

            $meta = $this->root . '/services/' . $name;
            if (is_dir($meta)) {
                self::remove_tree($meta);
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

    public function apply_service_rctl(string $jail, int $memory_mb): void {
        $jail = self::match($jail, 'tc_svc_[a-z][a-z0-9_]{0,39}');
        $memory_mb = max(64, min(8192, $memory_mb));
        foreach ([
            "jail:{$jail}:memoryuse:deny={$memory_mb}M",
            "jail:{$jail}:swapuse:deny={$memory_mb}M",
            "jail:{$jail}:pcpu:deny=200",
            "jail:{$jail}:maxproc:deny=256",
            "jail:{$jail}:openfiles:deny=4096",
        ] as $rule) {
            $this->run(['rctl', '-a', $rule], 30);
        }
    }

    public function remove_service_rctl(string $jail): void {
        $jail = self::match($jail, 'tc_svc_[a-z][a-z0-9_]{0,39}');
        $rules = $this->probe(['rctl', "jail:{$jail}"], 15);
        if ($rules !== null && trim($rules) !== '') {
            $this->run(['rctl', '-r', "jail:{$jail}"], 30);
        }
    }

    public function tcp_ready(string $ip, int $port, int $attempts = 90): bool {
        $context = $this->environment->production
            ? stream_context_create(['socket' => ['bindto' => '10.99.0.1:0']])
            : null;
        for ($i = 0; $i < $attempts; $i++) {
            $conn = $context !== null
                ? @stream_socket_client("tcp://{$ip}:{$port}", $errno, $errstr, 1.0, STREAM_CLIENT_CONNECT, $context)
                : @fsockopen($ip, $port, $errno, $errstr, 1);
            if (is_resource($conn)) {
                fclose($conn);
                return true;
            }
            if ($i + 1 < $attempts) {
                sleep(1);
            }
        }
        return false;
    }

    /**
     * Returns enabled package repositories excluding FreeBSD base/kmod repos so
     * local Poudriere repos take priority alongside FreeBSD-ports without
     * pulling pkgbase packages into service jails.
     *
     * @return list<string>
     */
    private function detect_ports_repos(): array {
        $raw = $this->probe(['pkg', '-vv'], 15) ?? '';
        $repos = [];
        $current = null;
        $enabled = true;
        $flush = static function () use (&$repos, &$current, &$enabled): void {
            if ($current !== null && $enabled && !in_array($current, ['FreeBSD-base', 'FreeBSD-ports-kmods'], true)) {
                $repos[] = $current;
            }
            $current = null;
            $enabled = true;
        };
        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            if (preg_match('/^\s{2}([A-Za-z0-9._-]+)\s*:\s*\{/', $line, $match) === 1) {
                $flush();
                $current = $match[1];
                continue;
            }
            if ($current !== null) {
                if (preg_match('/^\s*enabled\s*:\s*(no|false|off)\b/i', $line) === 1) {
                    $enabled = false;
                } elseif (preg_match('/^\s*\}/', $line) === 1) {
                    $flush();
                }
            }
        }
        $flush();
        if ($repos !== []) {
            return array_values(array_unique($repos));
        }
        return ['FreeBSD'];
    }

    /**
     * Stages a temporary pkg REPOS_DIR combining host repo configs with
     * FreeBSD-base and FreeBSD-ports-kmods disabled so multi-repo installs
     * (e.g. Poudriere + FreeBSD-ports) resolve cleanly by priority.
     */
    private function prepare_service_pkg_repos(string $jail_root): string {
        $dir = $jail_root . '/etc/tc/pkg-repos';
        if (is_dir($dir)) {
            self::remove_tree($dir);
        }
        self::directory($dir, 0755);
        foreach (['/etc/pkg' => '00-etc-', '/usr/local/etc/pkg/repos' => '50-local-'] as $src => $prefix) {
            if (!is_dir($src)) {
                continue;
            }
            foreach (glob($src . '/*.conf') ?: [] as $file) {
                if (is_file($file)) {
                    $contents = @file_get_contents($file);
                    if (is_string($contents)) {
                        $this->write($dir . '/' . $prefix . basename($file), $contents, 0644);
                    }
                }
            }
        }
        $this->write(
            $dir . '/99-tc-nobase.conf',
            "FreeBSD-base: { enabled: no }\nFreeBSD-ports-kmods: { enabled: no }\n",
            0644
        );
        return $dir;
    }

    private function render_service_jail_conf(
        string $jail_name,
        string $name,
        string $jail_root,
        string $ip,
        string $fstab_path,
        int $memory_mb,
        bool $setup_only = false,
    ): string {
        $maxproc = $setup_only ? 512 : 256;
        $poststart = "if [ \\$(sysctl -n kern.racct.enable 2>/dev/null || echo 0) = 1 ]; then "
            . "/usr/bin/rctl -a jail:{$jail_name}:memoryuse:deny={$memory_mb}M; "
            . "/usr/bin/rctl -a jail:{$jail_name}:swapuse:deny={$memory_mb}M; "
            . "/usr/bin/rctl -a jail:{$jail_name}:pcpu:deny=200; "
            . "/usr/bin/rctl -a jail:{$jail_name}:maxproc:deny={$maxproc}; "
            . "/usr/bin/rctl -a jail:{$jail_name}:openfiles:deny=4096; fi";

        $start_cmd = $setup_only
            ? '/sbin/ldconfig /lib /usr/lib /usr/local/lib'
            : "/bin/sh -c '/sbin/ldconfig /lib /usr/lib /usr/local/lib && /usr/sbin/daemon -f -r -P /var/run/tc_service.pid -o /var/log/service.log -u www /bin/sh /etc/tc/start.sh'";

        $stop_cmd = $setup_only
            ? '/usr/bin/true'
            : "/bin/sh -c 'test -f /var/run/tc_service.pid && kill -TERM $(cat /var/run/tc_service.pid) || true'";

        return "{$jail_name} {\n"
            . "    host.hostname = \"{$name}.svc.tc.internal\";\n"
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
            . "    exec.start = \"{$start_cmd}\";\n"
            . "    exec.stop = \"{$stop_cmd}\";\n"
            . "    exec.poststart = \"{$poststart}\";\n"
            . "    exec.poststop = \"/usr/bin/rctl -r jail:{$jail_name} || true\";\n"
            . "    persist;\n"
            . "}\n";
    }

    public function refresh_routes(): array {
        $refreshed = [];
        $reloaded_fpm = [];
        foreach (glob($this->root . '/apps/*/state.json') ?: [] as $state_path) {
            $app = basename(dirname($state_path));
            $state = $this->read($state_path, []);
            $active = is_array($state['active'] ?? null) ? $state['active'] : null;
            if ($active === null) {
                continue;
            }
            $canary = is_array($state['canary']['release'] ?? null) ? $state['canary']['release'] : null;
            $weight = (int) ($state['canary']['weight'] ?? 0);
            $this->route($app, $active, $canary, $weight);
            $refreshed[] = $app;

            foreach (array_filter([$active, $canary]) as $slot_release) {
                if ($this->ensure_slot_logging($app, $slot_release)) {
                    $reloaded_fpm[] = $app . ':' . (string) ($slot_release['slot'] ?? '');
                }
            }
        }
        return ['status' => 'succeeded', 'refreshed' => $refreshed, 'reloaded_fpm' => $reloaded_fpm];
    }

    private function ensure_slot_logging(string $app, array $release): bool {
        $slot = (string) ($release['slot'] ?? '');
        if ($slot !== 'blue' && $slot !== 'green') {
            return false;
        }
        $jail_root = $this->jails_root . '/' . $app . '-' . $slot;
        $fpm_conf_path = $jail_root . '/etc/tc/php-fpm.conf';
        if (!is_file($fpm_conf_path)) {
            return false;
        }

        $log_file = $jail_root . '/var/log/php-fpm.log';
        if (!is_file($log_file)) {
            $this->write($log_file, '', 0640);
        }
        $this->grant_www($log_file, 0640, writable: true);

        $conf = (string) file_get_contents($fpm_conf_path);
        $additions = [];
        if (!str_contains($conf, 'catch_workers_output')) {
            $additions[] = 'catch_workers_output = yes';
        }
        if (!str_contains($conf, 'decorate_workers_output')) {
            $additions[] = 'decorate_workers_output = no';
        }
        if (!str_contains($conf, 'php_admin_flag[log_errors]')) {
            $additions[] = 'php_admin_flag[log_errors] = on';
        }
        if (!str_contains($conf, 'php_admin_value[error_log]')) {
            $additions[] = 'php_admin_value[error_log] = /var/log/php-fpm.log';
        }
        if ($additions === []) {
            return false;
        }

        $updated = rtrim($conf) . "\n" . implode("\n", $additions) . "\n";
        $this->write($fpm_conf_path, $updated, 0600);

        $jail = (string) ($release['jail'] ?? '');
        if ($jail !== '' && $this->jail_running($jail)) {
            $this->probe(['jexec', $jail, '/bin/sh', '-c', 'test -f /var/run/php-fpm/php-fpm.pid && kill -USR2 $(cat /var/run/php-fpm/php-fpm.pid)'], 15);
            return true;
        }
        return false;
    }

    public function app_logs(array $request): array {
        $app = self::match($request['app'] ?? null, '[a-z][a-z0-9-]{0,39}');
        $limit = max(1, min(500, (int) ($request['limit'] ?? 250)));
        $since = is_string($request['since'] ?? null) ? trim($request['since']) : '';
        $state = $this->read($this->root . '/apps/' . $app . '/state.json', []);

        $sources = [];
        if (is_array($state['active'] ?? null) && !empty($state['active']['slot'])) {
            $slot = self::match((string) $state['active']['slot'], 'blue|green');
            $sources['stable'] = $this->jails_root . '/' . $app . '-' . $slot . '/var/log/php-fpm.log';
        }
        if (is_array($state['canary']['release'] ?? null) && !empty($state['canary']['release']['slot'])) {
            $slot = self::match((string) $state['canary']['release']['slot'], 'blue|green');
            $sources['canary'] = $this->jails_root . '/' . $app . '-' . $slot . '/var/log/php-fpm.log';
        }

        // Build 24 hourly buckets ending at the current UTC hour.
        $now_hour = (int) floor(time() / 3600) * 3600;
        $rates_map = [];
        for ($i = 23; $i >= 0; $i--) {
            $h = gmdate('Y-m-d\TH:00:00\Z', $now_hour - $i * 3600);
            $rates_map[$h] = ['hour' => $h, 'errors' => 0, 'warnings' => 0, 'total' => 0];
        }

        $entries = [];
        $seq = 0;
        foreach ($sources as $label => $log_file) {
            if (!is_file($log_file) || !is_readable($log_file)) {
                continue;
            }
            $raw = self::read_tail($log_file, 524288);
            if ($raw === '') {
                continue;
            }
            $file_mtime = (int) (@filemtime($log_file) ?: time());
            $last_ts = $file_mtime;
            foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
                $line = rtrim($line);
                if ($line === '') {
                    continue;
                }
                $parsed = self::parse_log_line($line, $last_ts);
                $last_ts = $parsed['ts'];
                $hour_key = gmdate('Y-m-d\TH:00:00\Z', $parsed['ts']);
                if (isset($rates_map[$hour_key])) {
                    $rates_map[$hour_key]['total']++;
                    if ($parsed['level'] === 'error') {
                        $rates_map[$hour_key]['errors']++;
                    } elseif ($parsed['level'] === 'warn') {
                        $rates_map[$hour_key]['warnings']++;
                    }
                }
                $seq++;
                $key = sprintf('%012d:%06d:%s', $parsed['ts'], $seq, substr(hash('sha256', $label . '|' . $parsed['text']), 0, 8));
                $entries[] = [
                    'key' => $key,
                    'ts' => $parsed['ts'],
                    'time' => gmdate('Y-m-d\TH:i:s\Z', $parsed['ts']),
                    'level' => $parsed['level'],
                    'text' => $parsed['text'],
                    'source' => $label,
                ];
            }
        }

        usort($entries, static fn(array $a, array $b): int => $a['ts'] <=> $b['ts'] ?: strcmp($a['key'], $b['key']));

        if ($since !== '') {
            $idx = null;
            foreach ($entries as $i => $entry) {
                if ($entry['key'] === $since) {
                    $idx = $i;
                    break;
                }
            }
            if ($idx !== null) {
                $entries = array_slice($entries, $idx + 1);
            } else {
                $since_ts = (int) strtok($since, ':');
                if ($since_ts > 0) {
                    $entries = array_values(array_filter($entries, static fn(array $e): bool => $e['ts'] > $since_ts));
                }
            }
        }

        if (count($entries) > $limit) {
            $entries = array_slice($entries, -$limit);
        }

        $cursor = $entries !== [] ? $entries[count($entries) - 1]['key'] : $since;
        $lines = array_map(static fn(array $e): array => [
            'time' => $e['time'],
            'level' => $e['level'],
            'text' => $e['text'],
            'source' => $e['source'],
        ], $entries);

        return [
            'ok' => true,
            'lines' => $lines,
            'cursor' => $cursor,
            'rates' => array_values($rates_map),
        ];
    }

    public function app_traffic(array $request): array {
        $app = self::match($request['app'] ?? null, '[a-z][a-z0-9-]{0,39}');
        $since_raw = is_string($request['since'] ?? null) ? trim($request['since']) : '';
        $since_ts = is_numeric($since_raw) ? (float) $since_raw : 0.0;
        $cutoff = $since_ts > 0.0 ? $since_ts : (float) (time() - 300);

        $log_file = $this->root . '/logs/' . $app . '-access.log';
        $requests = [];
        $max_ts = $since_ts;

        if (is_file($log_file) && is_readable($log_file)) {
            $raw = self::read_tail($log_file, 2097152);
            foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] !== '{') {
                    continue;
                }
                $row = json_decode($line, true);
                if (!is_array($row) || !isset($row['ts'])) {
                    continue;
                }
                $ts = (float) $row['ts'];
                if ($since_ts > 0.0 ? $ts <= $since_ts : $ts < $cutoff) {
                    continue;
                }
                if ($ts > $max_ts) {
                    $max_ts = $ts;
                }
                $req = is_array($row['request'] ?? null) ? $row['request'] : [];
                $sec = (int) floor($ts);
                $msec = (int) round(($ts - $sec) * 1000);
                if ($msec >= 1000) {
                    $sec++;
                    $msec = 0;
                }
                $requests[] = [
                    'time' => gmdate('Y-m-d\TH:i:s', $sec) . sprintf('.%03dZ', $msec),
                    'method' => (string) ($req['method'] ?? 'GET'),
                    'host' => (string) ($req['host'] ?? ''),
                    'path' => (string) ($req['uri'] ?? '/'),
                    'status' => (int) ($row['status'] ?? 0),
                    'ms' => round((float) ($row['duration'] ?? 0) * 1000, 1),
                    'bytes' => (int) ($row['size'] ?? 0),
                ];
            }
        }

        if (count($requests) > 2000) {
            $requests = array_slice($requests, -2000);
        }

        return [
            'ok' => true,
            'cursor' => $max_ts > 0.0 ? sprintf('%.6f', $max_ts) : $since_raw,
            'requests' => $requests,
        ];
    }

    public function app_db_inspect(array $request): array {
        require_once __DIR__ . '/app_database.php';
        $app = self::match($request['app'] ?? null, '[a-z][a-z0-9-]{0,39}');
        $ctx = $this->app_db_context($app);
        if (isset($ctx['error'])) {
            return ['ok' => false, 'error' => $ctx['error']];
        }

        $table = isset($request['table']) && is_string($request['table']) && $request['table'] !== '' ? $request['table'] : null;
        $view = ($request['view'] ?? 'data') === 'structure' ? 'structure' : 'data';
        $page = max(1, (int) ($request['page'] ?? 1));
        $sql_files = App_database::discover_sql_files($ctx['release_dir']);

        try {
            $pdo = App_database::open($this->mariadb_socket, $ctx['database'], $ctx['user'], $ctx['password']);
            return App_database::inspect($pdo, $ctx['database'], $table, $view, $page, $sql_files);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => self::safe_error($e->getMessage(), 300)];
        }
    }

    public function app_db_query(array $request): array {
        require_once __DIR__ . '/app_database.php';
        $app = self::match($request['app'] ?? null, '[a-z][a-z0-9-]{0,39}');
        $ctx = $this->app_db_context($app);
        if (isset($ctx['error'])) {
            return ['ok' => false, 'error' => $ctx['error']];
        }

        $mode = ($request['mode'] ?? 'query') === 'import' ? 'import' : 'query';
        $allow_changes = !empty($request['allow_changes']) || $mode === 'import';
        $sql = is_string($request['sql'] ?? null) ? $request['sql'] : '';

        if (!empty($request['release_sql_file']) && is_string($request['release_sql_file'])) {
            $rel = ltrim(str_replace('\\', '/', trim($request['release_sql_file'])), '/');
            if (!App_database::is_offered_sql_path($rel)) {
                return ['ok' => false, 'error' => 'Invalid repository SQL file path.'];
            }
            $release_real = realpath($ctx['release_dir']);
            $target_real = $release_real !== false ? realpath($release_real . '/' . $rel) : false;
            if ($release_real === false || $target_real === false || !str_starts_with($target_real, $release_real . DIRECTORY_SEPARATOR) || !is_file($target_real)) {
                return ['ok' => false, 'error' => 'SQL file not found in the active release.'];
            }
            $size = (int) (@filesize($target_real) ?: 0);
            if ($size > App_database::MAX_IMPORT_BYTES) {
                return ['ok' => false, 'error' => 'SQL file exceeds the 512 KB import limit.'];
            }
            $sql = (string) file_get_contents($target_real);
            $mode = 'import';
            $allow_changes = true;
        }

        try {
            $pdo = App_database::open($this->mariadb_socket, $ctx['database'], $ctx['user'], $ctx['password']);
            return App_database::execute($pdo, $sql, $allow_changes, $mode);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => self::safe_error($e->getMessage(), 300)];
        }
    }

    /** @return array{database?: string, user?: string, password?: string, release_dir?: string, error?: string} */
    private function app_db_context(string $app): array {
        $state = $this->read($this->root . '/apps/' . $app . '/state.json', []);
        $active = is_array($state['active'] ?? null) ? $state['active'] : null;
        if ($active === null) {
            return ['error' => 'Deploy this app first to provision its database.'];
        }
        $op = (string) ($active['operation'] ?? '');
        $overlay_db = $this->root . '/apps/' . $app . '/releases/' . $op . '/config/database.php';
        $creds = App_database::credentials_from_overlay($overlay_db);
        if ($creds === null) {
            return ['error' => 'Database credentials are not ready yet. Redeploy the app to provision them.'];
        }
        $release_dir = (string) ($active['release_path'] ?? ($this->releases_root . '/' . $app . '/releases/' . $op));
        return $creds + ['release_dir' => $release_dir];
    }

    private static function read_tail(string $path, int $max_bytes): string {
        $size = @filesize($path);
        if (!is_int($size) || $size <= 0) {
            return '';
        }
        $fp = @fopen($path, 'rb');
        if ($fp === false) {
            return '';
        }
        try {
            if ($size > $max_bytes) {
                fseek($fp, -$max_bytes, SEEK_END);
                fgets($fp); // discard partial first line
            }
            return (string) stream_get_contents($fp);
        } finally {
            fclose($fp);
        }
    }

    /** @return array{ts: int, level: string, text: string} */
    private static function parse_log_line(string $line, int $fallback_ts): array {
        $ts = $fallback_ts;
        $text = $line;

        if (preg_match('/^\[(\d{2}-[A-Za-z]{3}-\d{4}\s+\d{2}:\d{2}:\d{2}(?:\s+[A-Za-z0-9_\/+-]+)?)\]\s*(.*)$/', $line, $m) === 1) {
            $parsed_ts = strtotime($m[1]);
            if ($parsed_ts !== false) {
                $ts = $parsed_ts;
            }
            $text = $m[2];
        } elseif (preg_match('/^(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?Z?)\s+(.*)$/', $line, $m) === 1) {
            $parsed_ts = strtotime($m[1]);
            if ($parsed_ts !== false) {
                $ts = $parsed_ts;
            }
            $text = $m[2];
        }

        $text = self::safe_error($text, 1000);
        $level = '';
        if (preg_match('/^NOTICE:\s/', $text) === 1) {
            // PHP-FPM daemon lifecycle notices (fpm is running, ready to handle connections, etc.).
            $level = '';
        } elseif (preg_match('/\b(Fatal error|Parse error|Core error|Uncaught|CRITICAL|ALERT|EMERGENCY|ERROR)\b/i', $text) === 1) {
            $level = 'error';
        } elseif (preg_match('/\b(PHP Notice|Warning|Deprecated|WARNING)\b/i', $text) === 1) {
            $level = 'warn';
        }

        return ['ts' => $ts, 'level' => $level, 'text' => $text];
    }

    public function boot_active(): array {
        $started = [];
        $already_running = [];
        $failed = [];
        foreach (glob($this->root . '/services/*/state.json') ?: [] as $svc_path) {
            $svc_name = basename(dirname($svc_path));
            $svc = $this->read($svc_path, []);
            if (!is_array($svc) || empty($svc['jail']) || empty($svc['ip'])) {
                continue;
            }
            $jail = (string) $svc['jail'];
            $ip = (string) $svc['ip'];
            $conf = (string) ($svc['jail_conf'] ?? ($this->jail_conf_dir . '/' . $jail . '.conf'));
            $memory_mb = max(64, (int) ($svc['memory_mb'] ?? 512));
            try {
                if ($this->jail_running($jail)) {
                    $this->run(['pfctl', '-t', 'tc_services', '-T', 'add', $ip], 30);
                    $already_running[] = 'service:' . $svc_name;
                } else {
                    $this->run(['pfctl', '-t', 'tc_services', '-T', 'add', $ip], 30);
                    $this->run(['jail', '-c', '-f', $conf, $jail], 60);
                    $this->apply_service_rctl($jail, $memory_mb);
                    $started[] = 'service:' . $svc_name;
                }
            } catch (Throwable $error) {
                $failed['service:' . $svc_name] = $error instanceof RuntimeException || $error instanceof InvalidArgumentException
                    ? $error->getMessage()
                    : get_class($error);
            }
        }
        foreach (glob($this->root . '/apps/*/state.json') ?: [] as $state_path) {
            $app = basename(dirname($state_path));
            $state = $this->read($state_path, []);
            $slots = [];
            if (is_array($state) && is_array($state['active'] ?? null)) {
                $slots[$app] = $state['active'];
            }
            if (is_array($state) && is_array($state['canary']['release'] ?? null)) {
                $slots[$app . ':canary'] = $state['canary']['release'];
            }
            foreach ($slots as $label => $slot_release) {
                if (empty($slot_release['jail']) || empty($slot_release['ip'])) {
                    continue;
                }
                $jail = (string) $slot_release['jail'];
                $ip = (string) $slot_release['ip'];
                try {
                    if ($this->jail_running($jail)) {
                        $this->run(['pfctl', '-t', 'tc_jails', '-T', 'add', $ip], 30);
                        $already_running[] = $label;
                    } else {
                        $this->start($slot_release);
                        $started[] = $label;
                    }
                } catch (Throwable $error) {
                    $failed[$label] = $error instanceof RuntimeException || $error instanceof InvalidArgumentException
                        ? $error->getMessage()
                        : get_class($error);
                }
            }
        }
        return [
            'status' => $failed === [] ? 'succeeded' : 'degraded',
            'started' => $started,
            'already_running' => $already_running,
            'failed' => $failed,
        ];
    }

    public static function patch_config_php(string $source, string $domain, string $env = 'production', bool $https = true, ?string $error_404 = null): string {
        $domain = trim($domain);
        if ($domain === '' || str_contains($domain, "\0") || str_contains($env, "\0")) {
            throw new InvalidArgumentException('Invalid Trongate configuration value.');
        }
        $replacements = [
            'BASE_URL' => ($https ? 'https' : 'http') . '://' . trim($domain, '/') . '/',
            'ENV' => $env,
        ];
        if ($error_404 !== null && trim($error_404) !== '') {
            if (str_contains($error_404, "\0")) {
                throw new InvalidArgumentException('Invalid Trongate ERROR_404 value.');
            }
            $replacements['ERROR_404'] = trim($error_404);
        }
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
        $user = $export((string) $db['username']);
        $pass = $export((string) $db['password']);
        $database = $export((string) $db['database']);
        return "<?php\n"
            . "// Managed by trongate.cloud runtime\n"
            . "\$databases = [\n"
            . "    'default' => [\n"
            . "        'host' => 'localhost',\n"
            . "        'port' => '3306',\n"
            . "        'user' => " . $user . ",\n"
            . "        'password' => " . $pass . ",\n"
            . "        'database' => " . $database . ",\n"
            . "    ],\n"
            . "];\n"
            . "\$GLOBALS['databases'] = \$databases;\n"
            . "define('HOST', 'localhost');\n"
            . "define('PORT', '3306');\n"
            . "define('USER', " . $user . ");\n"
            . "define('PASSWORD', " . $pass . ");\n"
            . "define('DATABASE', " . $database . ");\n";
    }

    private function render_php_fpm_conf(int $memory_mb, array $secrets, ?array $valkey, ?string $auto_prepend = null): string {
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
            'catch_workers_output = yes',
            'decorate_workers_output = no',
            'env[PHP_INI_SCAN_DIR] = /usr/local/etc/php-base:/etc/tc/php.d',
            'env[PATH] = /usr/local/bin:/usr/bin:/bin',
            'env[TMPDIR] = /tmp',
            'php_admin_flag[log_errors] = on',
            'php_admin_value[error_log] = /var/log/php-fpm.log',
            'php_admin_value[memory_limit] = ' . $worker_memory . 'M',
            'php_admin_value[pdo_mysql.default_socket] = /var/run/tc/mariadb/mysqld.sock',
            'php_admin_value[mysqli.default_socket] = /var/run/tc/mariadb/mysqld.sock',
        ];

        if ($auto_prepend !== null) {
            $lines[] = 'php_admin_value[auto_prepend_file] = ' . $auto_prepend;
        }

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

    public static function render_module_config_php(string $label, array $config): string {
        $clean = [];
        foreach ($config as $k => $v) {
            if (!is_string($k) || preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]{0,63}\z/', $k) !== 1) {
                continue;
            }
            if (is_string($v) && str_contains($v, "\0")) {
                throw new InvalidArgumentException('Invalid module configuration value');
            }
            if (is_scalar($v) || $v === null) {
                $clean[$k] = $v;
            }
        }
        return "<?php\n// Managed by trongate.cloud for module " . preg_replace('/[\r\n]/', '', $label) . "\nreturn " . var_export($clean, true) . ";\n";
    }

    public static function render_modules_boot_php(bool $error_pages, bool $log, string $default_log_channel = 'app'): string {
        $code = "<?php\n// Managed by trongate.cloud ZFS module registry auto-wiring.\n";
        if ($log) {
            $ch = var_export($default_log_channel, true);
            $code .= "if (is_file('/app/modules/log/Log.php')) {\n"
                . "    require_once '/app/modules/log/Log.php';\n"
                . "    if (!function_exists('tc_log')) {\n"
                . "        function tc_log(string \$channel = " . $ch . "): Log {\n"
                . "            static \$loggers = [];\n"
                . "            return \$loggers[\$channel] ??= new Log([new Error_log_writer('[' . \$channel . '] ')]);\n"
                . "        }\n"
                . "    }\n"
                . "}\n\n";
        }
        if ($error_pages) {
            $code .= <<<'PHP'
if (is_file('/app/modules/error_pages/Error_report.php')) {
    require_once '/app/modules/error_pages/Error_report.php';
    $tc_send_error = static function (Error_report $report): void {
        if (!headers_sent()) {
            http_response_code($report->status);
            if ($report->status === 503) {
                header('Retry-After: 60');
            }
        }
        if (Error_report::wants_json($_SERVER)) {
            if (!headers_sent()) {
                header('Content-Type: application/json');
            }
            echo json_encode(['error' => $report->message]);
            return;
        }
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }
        $home = defined('BASE_URL') ? BASE_URL : '/';
        $view = '/app/modules/error_pages/views/server_error.php';
        if (is_file($view)) {
            ob_start();
            try {
                require $view;
                echo ob_get_clean();
                return;
            } catch (Throwable) {
                ob_end_clean();
            }
        }
        echo htmlspecialchars($report->title . '. ' . $report->message, ENT_QUOTES, 'UTF-8');
    };
    set_exception_handler(static function (Throwable $e) use ($tc_send_error): void {
        error_log('Uncaught ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        $detail = Error_report::shows_detail(defined('ENV') ? ENV : '', $_SERVER);
        $tc_send_error(Error_report::from_exception($e, $detail));
    });
    register_shutdown_function(static function () use ($tc_send_error): void {
        $error = error_get_last();
        if ($error === null || ($error['type'] & Error_report::FATAL) === 0 || headers_sent()) {
            return;
        }
        $detail = Error_report::shows_detail(defined('ENV') ? ENV : '', $_SERVER);
        $tc_send_error(Error_report::from_fatal($error, $detail));
    });
}

PHP;
        }
        return $code;
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
        if ($this->environment->production) {
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

    private static function configured_pool(string $config_path = '/etc/tc-resources.json'): string {
        $env_pool = 'zroot';
        if (is_string($env_pool) && $env_pool !== '' && preg_match('/\A[A-Za-z][A-Za-z0-9_.:-]{0,63}\z/', $env_pool) === 1) {
            return $env_pool;
        }
        if (is_file($config_path)) {
            $raw = @file_get_contents($config_path);
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                $pool = is_array($decoded) ? ($decoded['zfs_pool'] ?? null) : null;
                if (is_string($pool) && $pool !== '' && preg_match('/\A[A-Za-z][A-Za-z0-9_.:-]{0,63}\z/', $pool) === 1) {
                    return $pool;
                }
            }
        }
        return self::DEFAULT_POOL;
    }

    public static function safe_error(string $raw, int $max_chars = 500): string {
        $text = preg_replace('/\x1b\[[0-9;]*[a-zA-Z]/', '', $raw) ?? $raw;
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text) ?? $text;
        $text = preg_replace('~(https?://)[^\s/@]+:[^\s/@]+@~i', '$1[redacted]@', $text) ?? $text;
        $text = preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/-]+=*/i', '$1 [redacted]', $text) ?? $text;
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        if (strlen($text) > $max_chars) {
            $text = substr($text, 0, $max_chars - 1) . '…';
        }
        return $text;
    }

    private static function configured_behind_tunnel(string $config_path = '/etc/tc-resources.json'): bool {
        $env_flag = getenv('TC_BEHIND_TUNNEL');
        if ($env_flag !== false && $env_flag !== '') {
            return in_array(strtolower($env_flag), ['1', 'true', 'yes'], true);
        }
        if (is_file($config_path)) {
            $raw = @file_get_contents($config_path);
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded) && isset($decoded['behind_tunnel'])) {
                    return (bool) $decoded['behind_tunnel'];
                }
            }
        }
        return false;
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
        // Never disclose request bodies or credentials, while surfacing safe failure reasons.
        $payload = ['status' => 'uncertain', 'error' => get_class($error)];
        if ($error instanceof RuntimeException || $error instanceof InvalidArgumentException) {
            $reason = Runtime::safe_error($error->getMessage());
            if ($reason !== '') {
                $payload['reason'] = $reason;
            }
        }
        echo json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n";
    }
}
