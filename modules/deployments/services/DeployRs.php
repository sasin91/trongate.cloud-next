<?php
declare(strict_types=1);
final class DeployRs implements HostDeployer {
    function __construct(private Ssh $ssh, private Process $process = new Process) {
    }
    static function configuration(array $server, array $settings): string {
        // JSON is imported as data, never interpolated as a Nix expression.
        Validate::server($server);
        Validate::id($settings['hostname']);
        if (!in_array($settings['system'], ['x86_64-linux', 'aarch64-linux'], true)) {
            throw new RuntimeException('Unsupported system');
        }
        return tc_json(['hostname' => $settings['hostname'], 'system' => $settings['system'], 'address' => $server['host'], 'port' => $server['port'], 'user' => $server['user']]);
    }
    function inspect(array $server): array {
        return $this->ssh->runtime($server, ['action' => 'host-inspect']);
    }
    function reconcile(array $server, array $deployment): array {
        return $this->ssh->runtime($server, ['action' => 'host-reconcile', 'operation' => $deployment['operation'], 'workers_stopped' => $deployment['workers_stopped'] ?? false]);
    }
    function deploy(array $server, array $deployment, callable $heartbeat): array {
        $revision = Validate::match($deployment['revision'], '/^[a-f0-9]{64}$/D', 'revision');
        $dir = state_dir() . '/revisions/' . $revision;
        if (!is_file($dir . '/flake.lock') || !is_file($dir . '/expected-system')) {
            throw new RuntimeException('Frozen configuration revision missing');
        }
        $expected = trim(file_get_contents($dir . '/expected-system'));
        $before = $this->ssh->runtime($server, ['action' => 'host-begin', 'operation' => $deployment['operation'], 'expected' => $expected, 'revision' => $revision], $heartbeat);
        if (($before['status'] ?? '') !== 'reserved') {
            return $before;
        }
        $r = $this->ssh->with_credentials($server, function ($opts, $credentials) use ($dir, $heartbeat) {
            // deploy-rs owns the use of OpenSSH options; no secrets enter the Nix store.
            // This pinned deploy-rs splits on spaces, not shell quoting.
            foreach ($opts as $opt) {
                if (preg_match('/\s/', $opt)) {
                    throw new RuntimeException('SSH credential directory must not contain whitespace');
                }
            }
            $sshOpts = implode(' ', $opts);
            $result = $this->process->run(['deploy', '--ssh-opts', $sshOpts, $dir . '#target.system'], '', 1800, $heartbeat);
            $result['diagnostic'] = Redact::text($result['out'] . "\n" . $result['err'], [file_get_contents($credentials . '/identity')]);
            return $result;
        });
        return $this->ssh->runtime($server, ['action' => 'host-finish', 'operation' => $deployment['operation'], 'expected' => $expected, 'tool_exit' => $r['exit']], $heartbeat) + ['tool_exit' => $r['exit'], 'diagnostic' => $r['diagnostic']];
    }
    function rollback(array $server, array $deployment, callable $heartbeat): array {
        return $this->ssh->runtime($server, ['action' => 'host-rollback', 'operation' => $deployment['operation'], 'generation' => Validate::match($deployment['generation'],'~^/nix/store/[a-z0-9]{32}-nixos-system-[A-Za-z0-9._-]+$~D','generation')], $heartbeat);
    }
}
