<?php
declare(strict_types=1);
require_once APPPATH . 'modules/validation/services/Validate.php';

final class Ssh {
    private const REQUIRED_CAPABILITIES = [
        'zfs',
        'jail',
        'rctl',
        'pf',
        'caddy',
        'mariadb',
        'valkey',
        'php',
        'base_dataset',
        'runtime_dataset',
        'protocol',
    ];

    function __construct(private Model $model, private Process $process = new Process) {
    }

    function scan(array $server): array {
        $s = Validate::server($server);
        $r = $this->process->run(['ssh-keyscan', '-T', '10', '-p', (string) $s['port'], '-t', 'ed25519', $s['host']]);
        if ($r['exit'] !== 0 || !preg_match('/\S+ (ssh-ed25519) ([A-Za-z0-9+\/=]+)/', $r['out'], $m)) {
            throw new RuntimeException('No ed25519 host key received');
        }
        $key = $m[1] . ' ' . $m[2];
        return [
            'key' => $key,
            'fingerprint' => 'SHA256:' . rtrim(base64_encode(hash('sha256', base64_decode($m[2], true), true)), '='),
        ];
    }

    function with_credentials(array $s, callable $body): mixed {
        Validate::server($s);
        if (!preg_match('/^ssh-ed25519 [A-Za-z0-9+\/=]+$/D', $s['host_key'] ?? '')) {
            throw new RuntimeException('Host identity not confirmed');
        }
        $dir = state_dir() . '/ssh-' . bin2hex(random_bytes(12));
        mkdir($dir, 0700);
        try {
            $key = str_replace(["\r\n", "\r"], "\n", $this->model->secret($s['credential']));
            file_put_contents($dir . '/identity', trim($key) . "\n");
            chmod($dir . '/identity', 0600);
            // HostKeyAlias makes IPv6/port handling independent from known_hosts syntax.
            file_put_contents($dir . '/known_hosts', 'tc-target ' . $s['host_key'] . "\n");
            chmod($dir . '/known_hosts', 0600);
            $opts = [
                '-p', (string) $s['port'],
                '-i', $dir . '/identity',
                '-o', 'BatchMode=yes',
                '-o', 'IdentitiesOnly=yes',
                '-o', 'StrictHostKeyChecking=yes',
                '-o', 'HostKeyAlias=tc-target',
                '-o', 'UserKnownHostsFile=' . $dir . '/known_hosts',
                '-o', 'GlobalKnownHostsFile=/dev/null',
                '-o', 'ConnectTimeout=15',
                '-o', 'ServerAliveInterval=10',
                '-o', 'ServerAliveCountMax=3',
            ];
            return $body($opts, $dir);
        } finally {
            foreach (glob($dir . '/*') ?: [] as $f) {
                unlink($f);
            }
            rmdir($dir);
        }
    }

    function command(array $s, string $fixed_command, string $input = '', int $timeout = 60, ?callable $heartbeat = null): array {
        return $this->with_credentials(
            $s,
            fn($opts) => $this->process->run(
                array_merge(['ssh'], $opts, [$s['user'] . '@' . $s['host'], $fixed_command]),
                $input,
                $timeout,
                $heartbeat
            )
        );
    }

    function inspect(array $s): array {
        $r = $this->command($s, 'tc-runtime', self::_encode_json(['action' => 'inspect-host']), 30);
        if ($r['exit'] !== 0) {
            $error = strtolower($r['err']);
            $message = match (true) {
                str_contains($error, 'invalid format'), str_contains($error, 'error in libcrypto') => 'The SSH private key could not be read. Paste the complete private key, including its BEGIN and END lines.',
                str_contains($error, 'permission denied') => 'The server rejected the SSH key. Check the SSH username and use the private key matching the public key installed on this server.',
                str_contains($error, 'host key verification failed'), str_contains($error, 'remote host identification has changed') => 'The server SSH identity has changed. Reconnect the server to check its new identity.',
                str_contains($error, 'connection refused') => 'The server refused the SSH connection. Check its SSH port and that SSH is running.',
                str_contains($error, 'timed out'), str_contains($error, 'no route to host') => 'The SSH server could not be reached. Check its address, firewall, and SSH port.',
                default => 'The server readiness check failed. Check SSH access and the tc-runtime forced command.',
            };
            throw new RuntimeException($message);
        }

        $data = json_decode($r['out'], true);
        if (!is_array($data) || ($data['status'] ?? '') !== 'succeeded') {
            $remote_error = is_array($data) && is_string($data['error'] ?? null) && $data['error'] !== ''
                ? $data['error']
                : 'The server did not return a valid tc-runtime inspection response. Verify the forced-command SSH setup.';
            throw new RuntimeException($remote_error);
        }

        $os = strtolower((string) ($data['os'] ?? ''));
        $protocol = $data['protocol'] ?? null;
        $checks = is_array($data['checks'] ?? null) ? $data['checks'] : [];
        $protocol_ok = $protocol === 2 && (bool) ($checks['protocol'] ?? true);

        $missing = [];
        if ($os !== 'freebsd') {
            $missing[] = 'os';
        }
        foreach (self::REQUIRED_CAPABILITIES as $capability) {
            $present = $capability === 'protocol'
                ? $protocol_ok
                : !empty($checks[$capability]);
            if (!$present) {
                $missing[] = $capability;
            }
        }

        $facts = [
            'os' => $os,
            'arch' => (string) ($data['arch'] ?? ''),
            'freebsd_version' => (string) ($data['freebsd_version'] ?? ''),
            'zfs_pool' => (string) ($data['zfs_pool'] ?? ''),
            'protocol' => is_scalar($protocol) ? (string) $protocol : '',
        ];
        foreach ($checks as $name => $ok) {
            if (is_string($name) && $name !== 'protocol') {
                $facts[$name] = $ok ? 'yes' : 'no';
            }
        }

        $php_versions = [];
        if (is_array($data['php_versions'] ?? null)) {
            foreach ($data['php_versions'] as $version) {
                if (is_string($version) && preg_match('/^8\.[0-9]+$/D', $version) === 1) {
                    $php_versions[] = $version;
                }
            }
        }
        $php_versions = array_values(array_unique($php_versions));
        sort($php_versions);

        $optional_extensions = [];
        if (is_array($data['optional_extensions'] ?? null)) {
            foreach ($data['optional_extensions'] as $extension) {
                if (is_string($extension) && preg_match('/^[a-z][a-z0-9_]{0,39}$/D', $extension) === 1) {
                    $optional_extensions[] = $extension;
                }
            }
        }
        $optional_extensions = array_values(array_unique($optional_extensions));

        $application_ready = $missing === [];
        $local_builder_ready = $os === 'freebsd'
            && $protocol_ok
            && (bool) ($data['local_builder_ready'] ?? ($checks['builder_ready'] ?? false));
        $builder_ready = $os === 'freebsd'
            && $protocol_ok
            && (bool) ($data['builder_ready'] ?? (
                !$application_ready
                && !empty($checks['zfs'])
                && !empty($checks['jail'])
                && !empty($checks['rctl'])
                && !empty($checks['builder_ready'])
            ));

        $now = time();
        return [
            'local_builder_ready' => $local_builder_ready,
            'builder_ready' => $builder_ready,
            'facts' => $facts,
            'php_versions' => $php_versions,
            'optional_extensions' => $optional_extensions,
            'missing' => $missing,
            'application_ready' => $application_ready,
            'inspected_at' => $now,
            'connected_at' => $now,
        ];
    }

    function inspect_panel_worker(array $server): array {
        Validate::server($server);
        $r = $this->command($server, 'tc-runtime', self::_encode_json(['action' => 'inspect-panel-worker']), 30);
        if ($r['exit'] !== 0) {
            throw new RuntimeException('Could not inspect the panel worker over the pinned SSH connection.');
        }

        $data = json_decode($r['out'], true);
        if (
            !is_array($data)
            || ($data['status'] ?? '') !== 'succeeded'
            || !is_bool($data['panel_jail_running'] ?? null)
            || !is_bool($data['worker_service_active'] ?? null)
            || !is_array($data['worker_process_samples'] ?? null)
        ) {
            throw new RuntimeException('The host returned an invalid panel-worker inspection response.');
        }

        $samples = [];
        foreach ($data['worker_process_samples'] as $sample) {
            if (!is_int($sample) || $sample < 0) {
                throw new RuntimeException('The host returned invalid worker process counts.');
            }
            $samples[] = $sample;
        }

        $source_hash = $data['worker_source_sha256'] ?? null;
        if ($source_hash !== null && (!is_string($source_hash) || preg_match('/\A[a-f0-9]{64}\z/i', $source_hash) !== 1)) {
            throw new RuntimeException('The host returned an invalid worker source fingerprint.');
        }

        return [
            'panel_jail_running' => $data['panel_jail_running'],
            'worker_service_active' => $data['worker_service_active'],
            'worker_process_samples' => $samples,
            'worker_source_sha256' => $source_hash,
        ];
    }

    function runtime(array $server, array $request, ?callable $heartbeat = null): array {
        $r = $this->command($server, 'tc-runtime', self::_encode_json($request), 1800, $heartbeat);
        $data = json_decode($r['out'], true);
        if ($r['exit'] !== 0 || !is_array($data)) {
            throw new RuntimeException('Remote operation interrupted; reconcile required');
        }
        return $data;
    }

    private static function _encode_json(array $value): string {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
