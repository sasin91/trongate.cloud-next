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
        return self::_encode_json(['hostname' => $settings['hostname'], 'system' => $settings['system'], 'address' => $server['host'], 'port' => $server['port'], 'user' => $server['user']]);
    }
    function prepare(array $server, array $settings, callable $heartbeat): string {
        if (!is_string($settings['hardware_file'] ?? null) || !is_readable($settings['hardware_file'])) {
            throw new RuntimeException('Configure a readable local NixOS hardware file for this server.');
        }
        $data = self::configuration($server, $settings);
        // Only installation-owned inventory supplies this trusted Nix code.
        $hardware = file_get_contents($settings['hardware_file']);
        if (!is_file(APPPATH . 'flake.lock')) {
            throw new RuntimeException('Run nix flake lock before freezing a revision');
        }
        $files = array_merge(['flake.nix', 'flake.lock'],
            array_map(fn($path) => 'runtime/' . basename($path), glob(APPPATH . 'runtime/*.php')),
            ['runtime/tc-systemd-generator.sh'],
            array_map(fn($path) => 'nix/' . basename($path), glob(APPPATH . 'nix/*.nix')));
        $contents = $data . $hardware . file_get_contents(__FILE__);
        foreach ($files as $file) {
            $contents .= $file . file_get_contents(APPPATH . $file);
        }
        $revision = hash('sha256', $contents);
        $dir = state_dir() . '/revisions/' . $revision;
        if (is_file($dir . '/expected-system')) {
            return $revision;
        }
        foreach (['runtime', 'nix'] as $directory) {
            if (!is_dir($dir . '/source/' . $directory)) {
                mkdir($dir . '/source/' . $directory, 0700, true);
            }
        }
        foreach ($files as $file) {
            copy(APPPATH . $file, $dir . '/source/' . $file);
        }
        file_put_contents($dir . '/inventory.json', $data);
        file_put_contents($dir . '/hardware.nix', $hardware);
        $flake = <<<'NIX'
{
  inputs.product.url = "path:./source";
  inputs.nixpkgs.follows = "product/nixpkgs";
  inputs.deploy-rs.follows = "product/deploy-rs";
  outputs = { self, product, nixpkgs, deploy-rs, ... }: let
    inventory = builtins.fromJSON (builtins.readFile ./inventory.json);
  in {
    nixosConfigurations.target = nixpkgs.lib.nixosSystem {
      system = inventory.system;
      modules = [ ./hardware.nix product.nixosModules.runtime {
        networking.hostName = inventory.hostname;
        services.trongate-runtime.enable = true;
        services.openssh.ports = [ inventory.port ];
        system.stateVersion = nixpkgs.lib.mkDefault "26.05";
      } ];
    };
    deploy.nodes.target = {
      hostname = inventory.address; sshUser = inventory.user;
      magicRollback = true; autoRollback = true; activationTimeout = 240; confirmTimeout = 60;
      profiles.system = {
        user = "root";
        path = deploy-rs.lib.${inventory.system}.activate.nixos self.nixosConfigurations.target;
      };
    };
    checks = builtins.mapAttrs (_: lib: lib.deployChecks self.deploy) deploy-rs.lib;
  };
}
NIX;
        file_put_contents($dir . '/flake.nix', $flake);
        $result = $this->process->run(['nix', 'flake', 'lock', $dir], '', 600, $heartbeat);
        if ($result['exit'] !== 0) {
            throw new RuntimeException('Configuration lock failed');
        }
        $result = $this->process->run(['nix', 'build', '--out-link', $dir . '/system', '--print-out-paths',
            $dir . '#nixosConfigurations.target.config.system.build.toplevel'], '', 3600, $heartbeat);
        if ($result['exit'] !== 0) {
            throw new RuntimeException('Configuration build failed');
        }
        $expected = trim($result['out']);
        if (!preg_match('#^/nix/store/[a-z0-9]{32}-nixos-system-[^/\s]+$#D', $expected)) {
            throw new RuntimeException('Invalid built system path');
        }
        file_put_contents($dir . '/expected-system', $expected);
        return $revision;
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

    private static function _encode_json(array $value): string {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
