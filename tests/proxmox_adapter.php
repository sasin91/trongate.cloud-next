<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/proxmox_http_fixture.php';
require_once dirname(__DIR__) . '/bootstrap.php';

class Proxmox_adapter_fixture extends Proxmox_http_fixture {
    public array $posts = [];
    public array $puts = [];
    public array $gets = [];

    function __construct(private array $responses) {
    }

    function read(string $endpoint, string $path, string $token): array {
        $this->gets[] = $path;
        return array_shift($this->responses);
    }

    function configure(string $endpoint, string $path, string $token, array $body): void {
        $this->puts[] = ['path' => $path, 'body' => $body];
    }

    function mutate(string $endpoint, string $path, string $token, array $body): string {
        $this->posts[] = ['path' => $path, 'body' => $body];
        return 'UPID:pve:00000001:00000001:00000001:qmclone:101:reader@pve:';
    }
}

function proxmox_plan(): array {
    return ['node' => 'pve', 'template' => 100, 'vmid' => 101, 'name' => 'web', 'storage' => 'local-lvm', 'confirm' => true];
}

test('Proxmox plans require confirmation and distinct VM identity', function () {
    assert_throws(fn() => Proxmox::plan(array_replace(proxmox_plan(), ['confirm' => false])), 'confirmation');
    assert_throws(fn() => Proxmox::plan(array_replace(proxmox_plan(), ['vmid' => 100])), 'different');
    assert_same(proxmox_plan(), Proxmox::plan(proxmox_plan() + ['secret' => 'ignored']));
});

test('Proxmox preflight refuses collision, disabled storage and unprepared template without mutation', function () {
    $ready = ['active' => 1, 'enabled' => 1, 'content' => 'images,rootdir'];
    foreach ([
        [[['vmid' => 101]]],
        [[], ['active' => 1, 'enabled' => 0, 'content' => 'images']],
        [[], $ready, ['template' => 1, 'tags' => 'unprepared']],
        [[], $ready, ['template' => 1, 'tags' => 'tc-nixos']],
    ] as $responses) {
        $http = new Proxmox_adapter_fixture($responses);
        $provider = new Proxmox('proxmox');
        $api = new Proxmox_client('https://pve.test:8006', 'reader@pve!cloud=secret', $http);
        $journaled = false;
        try {
            $provider->clone_vm($api, str_repeat('a', 32), proxmox_plan(), null, function () use (&$journaled) { $journaled = true; });
            throw new LogicException('Expected preflight rejection');
        } catch (RuntimeException) {
            assert_same([], $http->posts);
            assert_same(false, $journaled);
        }
    }
});

test('Proxmox full clone includes stable operation ownership and selected storage', function () {
    $http = new Proxmox_adapter_fixture([[], ['active' => 1, 'enabled' => 1, 'content' => 'images'], ['template' => 1, 'tags' => 'base;tc-nixos', 'agent' => '1', 'ide2' => 'local-lvm:vm-100-cloudinit,media=cdrom', 'net0' => 'virtio,bridge=vmbr0']]);
    $provider = new Proxmox('proxmox');
    $api = new Proxmox_client('https://pve.test:8006', 'reader@pve!cloud=secret', $http);
    $journaled = false;
    $heartbeats = 0;
    $provider->clone_vm($api, str_repeat('a', 32), proxmox_plan(), function () use (&$heartbeats) { $heartbeats++; }, function () use (&$journaled, $http) {
        assert_same([], $http->posts);
        $journaled = true;
    });
    assert_same(true, $journaled);
    assert_same(3, $heartbeats);
    assert_same([['path' => '/nodes/pve/qemu/100/clone', 'body' => [
        'newid' => '101', 'name' => 'web', 'full' => '1', 'storage' => 'local-lvm', 'description' => 'tc-operation=' . str_repeat('a', 32),
    ]]], $http->posts);
});

test('Proxmox configures only the public key and verifies cloud-init settings', function () {
    $http = new Proxmox_adapter_fixture([]);
    $provider = new Proxmox('proxmox');
    $api = new Proxmox_client('https://pve.test:8006', 'reader@pve!cloud=secret', $http);
    $key = 'ssh-ed25519 ' . base64_encode(pack('N', 11) . 'ssh-ed25519' . pack('N', 32) . str_repeat('a', 32));
    $provider->configure_ssh(new Proxmox_ssh($api), proxmox_plan(), $key . ' generated');
    $body = ['ciuser' => 'root', 'sshkeys' => rawurlencode($key), 'ipconfig0' => 'ip=dhcp'];
    assert_same([['path' => '/nodes/pve/qemu/101/config', 'body' => $body]], $http->puts);
    assert_same(true, $provider->ssh_configured($body, $key));
    assert_same(false, $provider->ssh_configured(array_replace($body, ['ipconfig0' => 'ip=manual']), $key));
    assert_throws(fn() => $provider->configure_ssh(new Proxmox_ssh($api), proxmox_plan(), 'ssh-ed25519 Zm9v'), 'encoding');
});

test('Proxmox obtains validated SSH identity through guest agent and filters unusable addresses', function () {
    $key = 'ssh-ed25519 ' . base64_encode(pack('N', 11) . 'ssh-ed25519' . pack('N', 32) . str_repeat('b', 32));
    $http = new Proxmox_adapter_fixture([
        ['content' => $key . " root@web\n"],
        ['result' => [['ip-addresses' => array_map(fn($ip) => ['ip-address' => $ip], ['127.0.0.1', '169.254.2.3', '::1', 'fe80::1', 'ff02::1', '192.168.1.8', 'fd01::8'])]]],
    ]);
    $provider = new Proxmox('proxmox');
    $api = new Proxmox_client('https://pve.test:8006', 'reader@pve!cloud=secret', $http);
    assert_same(['host_key' => $key, 'addresses' => ['192.168.1.8', 'fd01::8']], $provider->guest_identity(new Proxmox_ssh($api), proxmox_plan()));
    assert_same('/nodes/pve/qemu/101/agent/file-read?file=%2Fetc%2Fssh%2Fssh_host_ed25519_key.pub', $http->gets[0]);
    assert_contains('/agent/network-get-interfaces', $http->gets[1]);
});

finish_tests();
