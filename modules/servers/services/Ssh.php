<?php
declare(strict_types=1);
final class Ssh {
    function __construct(private Model $store, private Process $process = new Process) {
    }
    function scan(array $server): array {
        $s = Validate::server($server);
        $r = $this->process->run(['ssh-keyscan', '-T', '10', '-p', (string)$s['port'], '-t', 'ed25519', $s['host']]);
        if ($r['exit'] !== 0 || !preg_match('/\S+ (ssh-ed25519) ([A-Za-z0-9+\/=]+)/', $r['out'], $m)) {
            throw new RuntimeException('No ed25519 host key received');
        }
        $key = $m[1] . ' ' . $m[2];
        return ['key' => $key, 'fingerprint' => 'SHA256:' . rtrim(base64_encode(hash('sha256', base64_decode($m[2], true), true)), '=')];
    }
    function with_credentials(array $s, callable $body): mixed {
        Validate::server($s);
        if (!preg_match('/^ssh-ed25519 [A-Za-z0-9+\/=]+$/D', $s['host_key'] ?? '')) {
            throw new RuntimeException('Host identity not confirmed');
        }
        $dir = state_dir() . '/ssh-' . bin2hex(random_bytes(12));
        mkdir($dir, 0700);
        try {
            $key = str_replace(["\r\n", "\r"], "\n", $this->store->secret($s['credential']));
            file_put_contents($dir . '/identity', trim($key) . "\n");
            chmod($dir . '/identity', 0600);
            // HostKeyAlias makes IPv6/port handling independent from known_hosts syntax.
            file_put_contents($dir . '/known_hosts', 'tc-target ' . $s['host_key'] . "\n");
            chmod($dir . '/known_hosts', 0600);
            $opts = ['-p', (string)$s['port'], '-i', $dir . '/identity', '-o', 'BatchMode=yes', '-o', 'IdentitiesOnly=yes', '-o', 'StrictHostKeyChecking=yes', '-o', 'HostKeyAlias=tc-target', '-o', 'UserKnownHostsFile=' . $dir . '/known_hosts', '-o', 'GlobalKnownHostsFile=/dev/null', '-o', 'ConnectTimeout=15', '-o', 'ServerAliveInterval=10', '-o', 'ServerAliveCountMax=3'];
            return $body($opts, $dir);
        } finally {
            foreach (glob($dir . '/*') as $f) {
                unlink($f);
            } rmdir($dir);
        }
    }
    function command(array $s, string $fixed_command, string $input = '', int $timeout = 60, ?callable $heartbeat = null): array {
        return $this->with_credentials($s, fn($opts) => $this->process->run(array_merge(['ssh'], $opts, [$s['user'] . '@' . $s['host'], $fixed_command]), $input, $timeout, $heartbeat));
    }
    function inspect(array $s): array {
        $script = <<<'SH'
set -eu
. /etc/os-release
printf 'os=%s\narch=%s\n' "$ID" "$(uname -m)"
for tool in podman systemctl curl python3 flock caddy nix; do
 if command -v "$tool" >/dev/null 2>&1; then printf '%s=yes\n' "$tool"; fi
done
if test -x /run/current-system/sw/bin/tc-runtime || command -v tc-runtime >/dev/null 2>&1; then echo agent=yes; fi
if test "$(id -u)" = 0; then echo privileged=yes; fi
if systemctl is-active --quiet tc-proxy; then echo proxy=yes; fi
if podman info >/dev/null 2>&1; then echo podman-ready=yes; fi
if test -f /sys/fs/cgroup/cgroup.controllers && grep -qw memory /sys/fs/cgroup/cgroup.controllers && grep -qw cpu /sys/fs/cgroup/cgroup.controllers; then echo cgroups=yes; fi
if test "$(tc-runtime --version 2>/dev/null)" = 1; then echo protocol=yes; fi
if test "$(id -u)" != 0 && command -v tc-build >/dev/null 2>&1 && command -v git >/dev/null 2>&1 && command -v python3 >/dev/null 2>&1 && podman info >/dev/null 2>&1 && systemctl --user show-environment >/dev/null 2>&1; then echo builder=yes; fi
SH;
        $r = $this->command($s, 'sh -s', $script);
        if ($r['exit'] !== 0) {
            $error = strtolower($r['err']);
            $message = match (true) {
                str_contains($error, 'invalid format'), str_contains($error, 'error in libcrypto') => 'The SSH private key could not be read. Paste the complete private key, including its BEGIN and END lines.',
                str_contains($error, 'permission denied') => 'The server rejected the SSH key. Check the SSH username and use the private key matching the public key installed on this server.',
                str_contains($error, 'host key verification failed'), str_contains($error, 'remote host identification has changed') => 'The server SSH identity has changed. Reconnect the server to check its new identity.',
                str_contains($error, 'connection refused') => 'The server refused the SSH connection. Check its SSH port and that SSH is running.',
                str_contains($error, 'timed out'), str_contains($error, 'no route to host') => 'The SSH server could not be reached. Check its address, firewall, and SSH port.',
                default => 'The server readiness check failed. Check SSH access and the application runtime.'
            };
            throw new RuntimeException($message);
        }
        $facts = [];
        foreach (explode("\n", trim($r['out'])) as $line) {
            $parts = explode('=', $line, 2);
            if (count($parts) === 2) {
                $facts[$parts[0]] = $parts[1];
            }
        }
        $missing = [];
        foreach (['podman', 'systemctl', 'curl', 'python3', 'flock', 'caddy', 'agent', 'privileged', 'proxy', 'podman-ready', 'cgroups', 'protocol'] as $tool) {
            if (($facts[$tool] ?? '') !== 'yes') {
                $missing[] = $tool;
            }
        }
        return ['builder_ready' => ($facts['builder'] ?? '') === 'yes', 'facts' => $facts, 'missing' => $missing, 'application_ready' => $missing === [], 'managed_eligible' => ($facts['os'] ?? '') === 'nixos' && ($facts['nix'] ?? '') === 'yes' && ($facts['privileged'] ?? '') === 'yes', 'inspected_at' => time(), 'connected_at' => time()];
    }
    function runtime(array $server, array $request, ?callable $heartbeat = null): array {
        $r = $this->command($server, 'tc-runtime', tc_json($request), 1800, $heartbeat);
        $data = json_decode($r['out'], true);
        if ($r['exit'] !== 0 || !is_array($data)) {
            throw new RuntimeException('Remote operation interrupted; reconcile required');
        }
        return $data;
    }
}
