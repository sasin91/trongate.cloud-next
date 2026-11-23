<?php

require_once APPPATH . 'modules/trongate_security/traits/Encrypted_credentials.php';
require_once APPPATH . 'modules/jobs/traits/Job_queue.php';

class Proxmox_model extends Model {
    use Encrypted_credentials, Job_queue;
    public function __construct(?string $module_name = null, PDO|Closure|null $connection = null) {
        parent::__construct($module_name, $connection);
        $this->module('records');
    }



    function _connections(): array {
        return array_map(static fn(array $connection): array => array_intersect_key($connection,
            array_flip(['id', 'label', 'node', 'template', 'storage', 'memory_mb', 'cpus'])), $this->records->_all('proxmox-connection'));
    }

    function _progress(string $operation): array {
        $job = $this->job($operation);
        if ($job['kind'] !== 'proxmox-provision') {
            throw new RuntimeException('Not a Proxmox server creation.');
        }
        $plan = json_decode($job['payload'], true, 512, JSON_THROW_ON_ERROR);
        $result = json_decode($job['result'], true, 512, JSON_THROW_ON_ERROR);
        return ['operation' => $operation, 'name' => $plan['name'], 'status' => $job['status'],
            'phase' => $result['phase'] ?? 'queued', 'server_id' => $result['server_id'] ?? null];
    }

    function _queue_provision(array $plan, array $keys, ?string $request): string {
        $this->db->connection()->beginTransaction();
        try {
            if ($request !== null) {
                $this->records->_insert(['kind' => 'proxmox-request', 'id' => $request, 'data' => []]);
            }
            $reference = 'pve-' . bin2hex(random_bytes(16));
            $this->secret($reference, $keys['private']);
            $operation = $this->enqueue('proxmox-provision', 'proxmox:' . hash('sha256', strtolower($plan['endpoint'])),
                $plan + ['ssh_credential' => $reference, 'ssh_public_key' => $keys['public']]);
            if ($request !== null) {
                $this->records->_put(['kind' => 'proxmox-request', 'id' => $request, 'data' => ['operation' => $operation]]);
            }
            $this->db->connection()->commit();
            return $operation;
        } catch (Throwable $error) {
            $this->db->connection()->rollBack();
            if ($request !== null) {
                $saved = $this->records->_find(['kind' => 'proxmox-request', 'id' => $request]);
                if ($saved !== null) {
                    return $saved['operation'];
                }
            }
            throw $error;
        }
    }

    public function _servers(): array {
        return $this->records->_all('server');
    }

    public function _register_server(string $name, array $server): void {
        $this->records->_insert(['kind' => 'server', 'id' => $name, 'data' => $server]);
    }

    public function _intent(string $operation): array {
        return $this->records->_find(['kind' => 'external', 'id' => $operation]) ?? [];
    }

    public function _save_intent(string $operation, array $intent): void {
        $this->records->_put(['kind' => 'external', 'id' => $operation, 'data' => $intent]);
    }

    public function _save_provider_server(string $key, array $server): void {
        $this->records->_put(['kind' => 'provider-server', 'id' => $key, 'data' => $server]);
    }

}
