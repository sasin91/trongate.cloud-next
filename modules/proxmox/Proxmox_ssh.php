<?php
declare(strict_types=1);

/** SSH setup and verification for Proxmox guests. This class does not persist inventory. */
class Proxmox_ssh {
    public function __construct(private Proxmox_client $api) {
    }

    public function configure(array $plan, string $publickey): void {
        $plan['node'] = Validate::match($plan['node'] ?? '', '/^[a-zA-Z0-9][a-zA-Z0-9.-]*$/D', 'Proxmox node');
        $plan['vmid'] = Validate::number($plan['vmid'] ?? null, 100, 999999999);
        $key = self::public_key($publickey);
        $this->api->put('/nodes/' . rawurlencode($plan['node']) . '/qemu/' . $plan['vmid'] . '/config', ['ciuser' => 'root', 'sshkeys' => rawurlencode($key), 'ipconfig0' => 'ip=dhcp']);
    }

    public static function configured(array $config, string $publickey): bool {
        return ($config['ciuser'] ?? '') === 'root' && ($config['ipconfig0'] ?? '') === 'ip=dhcp' &&
            trim(rawurldecode($config['sshkeys'] ?? '')) === self::public_key($publickey);
    }

    public static function public_key(string $value): string {
        if (!preg_match('/^ssh-ed25519 ([A-Za-z0-9+\/=]+)(?: [^\r\n]*)?$/D', trim($value), $match)) {
            throw new RuntimeException('Invalid Ed25519 public key.');
        }
        $blob = base64_decode($match[1], true);
        $prefix = pack('N', 11) . 'ssh-ed25519' . pack('N', 32);
        if ($blob === false || strlen($blob) !== strlen($prefix) + 32 || !str_starts_with($blob, $prefix)) {
            throw new RuntimeException('Invalid Ed25519 public key encoding.');
        }
        return 'ssh-ed25519 ' . base64_encode($blob);
    }

    public function identity(array $plan, ?callable $heartbeat = null): array {
        $plan['node'] = Validate::match($plan['node'] ?? '', '/^[a-zA-Z0-9][a-zA-Z0-9.-]*$/D', 'Proxmox node');
        $plan['vmid'] = Validate::number($plan['vmid'] ?? null, 100, 999999999);
        $base = '/nodes/' . rawurlencode($plan['node']) . '/qemu/' . $plan['vmid'] . '/agent/';
        $file = $this->api->get($base . 'file-read?file=' . rawurlencode('/etc/ssh/ssh_host_ed25519_key.pub'));
        if ($heartbeat !== null) {
            $heartbeat();
        }
        if (!empty($file['truncated']) || !is_string($file['content'] ?? null)) {
            throw new RuntimeException('Guest SSH host key is not available.');
        }
        $key = self::public_key($file['content']);
        $network = $this->api->get($base . 'network-get-interfaces');
        $addresses = [];
        foreach ($network['result'] ?? [] as $interface) {
            foreach ($interface['ip-addresses'] ?? [] as $address) {
                $ip = $address['ip-address'] ?? '';
                if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                    continue;
                }
                $bytes = inet_pton($ip);
                if (strlen($bytes) === 4) {
                    if (ord($bytes[0]) === 0 || ord($bytes[0]) === 127 || ord($bytes[0]) >= 224 ||
                        (ord($bytes[0]) === 169 && ord($bytes[1]) === 254)) {
                        continue;
                    }
                } elseif ($bytes === str_repeat("\0", 16) || $bytes === str_repeat("\0", 15) . "\1" ||
                    ord($bytes[0]) === 255 || (ord($bytes[0]) === 254 && (ord($bytes[1]) & 192) === 128) ||
                    substr($bytes, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
                    continue;
                }
                $addresses[] = inet_ntop($bytes);
            }
        }
        return ['host_key' => $key, 'addresses' => array_values(array_unique($addresses))];
    }

    public static function generate_key(): array {
        $pair = sodium_crypto_sign_keypair();
        $public = sodium_crypto_sign_publickey($pair);
        $secret = sodium_crypto_sign_secretkey($pair);
        $string = static fn(string $value): string => pack('N', strlen($value)) . $value;
        $blob = $string('ssh-ed25519') . $string($public);
        $check = random_bytes(4);
        $private = $check . $check . $blob . $string($secret) . $string('trongate-proxmox');
        $padding = 8 - (strlen($private) % 8);
        for ($index = 1; $index <= $padding; $index++) {
            $private .= chr($index);
        }
        $encoded = "openssh-key-v1\0" . $string('none') . $string('none') . $string('') .
            pack('N', 1) . $string($blob) . $string($private);
        return [
            'private' => "-----BEGIN OPENSSH PRIVATE KEY-----\n" . chunk_split(base64_encode($encoded), 70, "\n") . "-----END OPENSSH PRIVATE KEY-----\n",
            'public' => 'ssh-ed25519 ' . base64_encode($blob) . ' trongate-proxmox',
        ];
    }



    public static function inspect(Ssh $ssh, string $operation, array $plan, array $identity, ?callable $heartbeat = null): ?array {
        $key = self::public_key($identity['host_key'] ?? '');
        foreach (array_slice($identity['addresses'] ?? [], 0, 8) as $address) {
            if (!filter_var($address, FILTER_VALIDATE_IP)) {
                continue;
            }
            if ($heartbeat !== null) {
                $heartbeat();
            }
            $server = ['host' => $address, 'port' => 22, 'user' => 'root',
                'credential' => Validate::id($plan['ssh_credential']), 'host_key' => $key,
                'provision_operation' => $operation, 'proxmox_node' => $plan['node'],
                'proxmox_vmid' => $plan['vmid'], 'proxmox_endpoint' => $plan['endpoint']];
            try {
                $inspection = $ssh->inspect($server);
            } catch (RuntimeException) {
                continue;
            }
            if (!$inspection['application_ready'] || !$inspection['managed_eligible']) {
                continue;
            }
            if ($heartbeat !== null) {
                $heartbeat();
            }
            return $server + $inspection;
        }
        return null;
    }
}
