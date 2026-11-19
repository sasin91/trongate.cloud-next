<?php
declare(strict_types=1);
final class Hetzner {
    function __construct(private string $token, private Http $http = new Http) {
    }
    function call(string $method, string $path, ?array $body = null): array {
        return $this->http->request($method, $path, $this->token, $body);
    }
    function list(string $collection, string $query = ''): array {
        $result = [];
        $page = 1;
        do {
            $r = $this->call('GET', "/$collection?per_page=50&page=$page" . $query);
            $result = array_merge($result, $r[$collection]);
            $page = $r['meta']['pagination']['next_page'] ?? null;
        } while ($page);
        return $result;
    }
    function options(): array {
        return ['server_types' => $this->list('server_types'), 'locations' => $this->list('locations'), 'images' => $this->list('images', '&type=snapshot&label_selector=tc-nixos%3Dtrue')];
    }
    function reconcile_create(string $operation): ?array {
        $servers = $this->list('servers', '&label_selector=' . rawurlencode('tc-operation=' . $operation));
        if (count($servers) > 1) {
            throw new RuntimeException('Multiple provider resources match operation; operator reconciliation required');
        }
        return $servers[0] ?? null;
    }
    function create(string $operation, array $p): array {
        $image = $this->call('GET', '/images/' . Validate::number($p['image'], 1, PHP_INT_MAX))['image'];
        $bootstrap = ($p['bootstrap'] ?? false) === true;
        if ($bootstrap) {
            if (($image['type'] ?? '') !== 'system' || ($image['status'] ?? 'available') !== 'available') {
                throw new RuntimeException('Select an available stock system image');
            }
        } elseif (($image['type'] ?? '') !== 'snapshot' || ($image['status'] ?? '') !== 'available' || ($image['labels']['tc-nixos'] ?? '') !== 'true') {
            throw new RuntimeException('Select an available tc-nixos=true snapshot');
        }
        $types = $this->list('server_types', '&name=' . rawurlencode(Validate::id($p['server_type'])));
        if (count($types) !== 1 || $types[0]['name'] !== $p['server_type']) {
            throw new RuntimeException('Selected server type is no longer available');
        }
        $type = $types[0];
        if (($type['architecture'] ?? '') !== ($image['architecture'] ?? '')) {
            throw new RuntimeException('Snapshot architecture mismatch');
        }
        return $this->call('POST', '/servers', ['name' => Validate::id($p['name']), 'server_type' => $p['server_type'], 'location' => $p['location'], 'image' => (int)$p['image'], 'ssh_keys' => array_map(fn($n) => Validate::number($n, 1, PHP_INT_MAX), $p['ssh_keys']), 'labels' => ['tc-operation' => $operation, 'tc-temporary' => $bootstrap?'true':'false'], 'public_net' => ['enable_ipv4' => true, 'enable_ipv6' => true]]);
    }
    function action(int $id): array {
        $a = $this->call('GET', "/actions/$id")['action'];
        return ['status' => match ($a['status']) {
            'success' => 'succeeded','error' => 'failed',default => 'pending'
        }, 'provider_action' => $id, 'error_code' => $a['error']['code'] ?? null];
    }
}
