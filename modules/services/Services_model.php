<?php
declare(strict_types=1);

require_once APPPATH . 'modules/security/traits/Encrypted_credentials.php';
require_once APPPATH . 'modules/jobs/traits/Job_queue.php';
require_once APPPATH . 'modules/apps/services/Build_commands.php';
require_once APPPATH . 'modules/validation/services/Validate.php';
require_once APPPATH . 'modules/services/services/Service_spec.php';

class Services_model extends Model {
    use Encrypted_credentials, Job_queue;

    function __construct(?string $module_name = null, PDO|Closure|null $connection = null) {
        parent::__construct($module_name, $connection);
        $this->module('records');
    }

    function _all(?string $app = null): array {
        $services = $this->records->_all('service');
        if ($app !== null) {
            $app = Validate::id($app);
            $services = array_values(array_filter(
                $services,
                static fn(array $svc): bool => ($svc['app'] ?? '') === $app
            ));
        }
        usort($services, static fn(array $a, array $b) => strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''))
            ?: strcmp((string) ($a['app'] ?? ''), (string) ($b['app'] ?? '')));
        return $services;
    }

    function _can_add(?string $server = null): bool {
        $services = $this->records->_all('service');
        if ($server !== null && $server !== '') {
            $services = array_filter(
                $services,
                static fn(array $svc): bool => ($svc['server'] ?? '') === $server
            );
        }
        return count($services) < Service_spec::MAX_PER_SERVER;
    }

    function _ready_server(): string {
        foreach ($this->records->_all('server') as $candidate) {
            if (!empty($candidate['application_ready'])) {
                return Validate::id((string) $candidate['id']);
            }
        }
        throw new RuntimeException('Application hosting is not ready yet. Connect a FreeBSD host first.');
    }

    /**
     * Validates, persists, and queues deployment of a service (app-scoped when
     * `app` is supplied, or server-wide when omitted).
     *
     * @return array{service: string, name: string, app: ?string, operation: string}
     */
    function _add(array $input): array {
        $name = strtolower(trim((string) ($input['service_name'] ?? '')));
        if ($err = Service_spec::name_error($name)) {
            throw new RuntimeException($err);
        }

        $app_raw = trim((string) ($input['app'] ?? ''));
        $app = null;
        if ($app_raw !== '') {
            $app = Validate::id($app_raw);
            $app_record = $this->records->_get(['kind' => 'app', 'id' => $app]);
            $server = Validate::id((string) ($app_record['server'] ?? ''));
        } else {
            $server = trim((string) ($input['server'] ?? '')) !== ''
                ? Validate::id((string) $input['server'])
                : $this->_ready_server();
        }

        $id = Service_spec::runtime_name($name, $app);
        $existing = $this->records->_all('service');
        $on_server = array_filter($existing, static fn(array $row): bool => ($row['server'] ?? '') === $server);
        if (count($on_server) >= Service_spec::MAX_PER_SERVER) {
            throw new RuntimeException('You can run at most ' . Service_spec::MAX_PER_SERVER . ' services.');
        }
        foreach ($existing as $row) {
            $row_app = (string) ($row['app'] ?? '');
            $same_scope = $app !== null ? $row_app === $app : $row_app === '';
            if (($same_scope && ($row['name'] ?? '') === $name) || ($row['id'] ?? '') === $id) {
                throw new RuntimeException(
                    $app !== null
                        ? 'A service named "' . $name . '" already exists for this app.'
                        : 'A service named "' . $name . '" already exists.'
                );
            }
        }

        $packages = Service_spec::parse_packages((string) ($input['service_packages'] ?? ''));
        $setup = Build_commands::script((string) ($input['service_setup'] ?? ''));
        $command = trim((string) ($input['service_command'] ?? ''));
        if ($err = Service_spec::command_error($command)) {
            throw new RuntimeException($err);
        }

        $port_raw = trim((string) ($input['service_port'] ?? ''));
        $port = $port_raw === '' ? Service_spec::DEFAULT_PORT : (int) $port_raw;
        if ($err = Service_spec::port_error($port)) {
            throw new RuntimeException($err);
        }

        $mem_raw = trim((string) ($input['service_memory_mb'] ?? ''));
        $memory_mb = $mem_raw === '' ? Service_spec::DEFAULT_MEMORY_MB : (int) $mem_raw;
        if ($err = Service_spec::memory_error($memory_mb)) {
            throw new RuntimeException($err);
        }

        $env = Service_spec::parse_env((string) ($input['service_env'] ?? ''));

        $this->db->connection()->beginTransaction();
        try {
            $env_refs = [];
            foreach ($env as $key => $value) {
                $ref = 'svc-' . substr(hash('sha256', $id . ':' . $key), 0, 32);
                $this->secret($ref, $value);
                $env_refs[$key] = $ref;
            }

            $record = [
                'name' => $name,
                'runtime_name' => $id,
                'app' => $app,
                'server' => $server,
                'packages' => $packages,
                'setup' => $setup,
                'command' => $command,
                'port' => $port,
                'memory_mb' => $memory_mb,
                'env_refs' => $env_refs,
                'status' => 'queued',
                'ip' => null,
                'url' => null,
                'error' => null,
                'created_at' => time(),
                'deployed_at' => null,
            ];

            $this->records->_insert([
                'kind' => 'service',
                'id' => $id,
                'data' => $record,
            ]);

            $payload = [
                'service' => $id,
                'service_name' => $name,
                'runtime_name' => $id,
                'server' => $server,
            ];
            if ($app !== null) {
                $payload['app'] = $app;
            }

            $operation = $this->enqueue('deploy-service', 'server:' . $server, $payload);

            $this->db->connection()->commit();
            return ['service' => $id, 'name' => $name, 'app' => $app, 'operation' => $operation];
        } catch (Throwable $e) {
            $this->db->connection()->rollBack();
            if ($e instanceof PDOException) {
                throw new RuntimeException(
                    $app !== null
                        ? 'A service named "' . $name . '" already exists for this app.'
                        : 'A service named "' . $name . '" already exists.'
                );
            }
            throw $e;
        }
    }

    function _queue_redeploy(string $id, ?string $app = null): string {
        $id = strtolower(trim($id));
        if ($err = Service_spec::name_error($id)) {
            throw new RuntimeException($err);
        }
        $record = $this->records->_get(['kind' => 'service', 'id' => $id]);
        if ($app !== null && $app !== '') {
            $app_id = Validate::id($app);
            if ((string) ($record['app'] ?? '') !== $app_id) {
                throw new RuntimeException('Service does not belong to this application.');
            }
        }
        $record['status'] = 'queued';
        $record['error'] = null;
        $this->records->_put(['kind' => 'service', 'id' => $id, 'data' => $record]);

        $payload = [
            'service' => $id,
            'service_name' => (string) ($record['name'] ?? $id),
            'runtime_name' => (string) ($record['runtime_name'] ?? $id),
            'server' => $record['server'],
        ];
        if (!empty($record['app'])) {
            $payload['app'] = (string) $record['app'];
        }
        return $this->enqueue('deploy-service', 'server:' . $record['server'], $payload);
    }

    function _queue_remove(string $id, ?string $app = null): string {
        $id = strtolower(trim($id));
        if ($err = Service_spec::name_error($id)) {
            throw new RuntimeException($err);
        }
        $record = $this->records->_get(['kind' => 'service', 'id' => $id]);
        if ($app !== null && $app !== '') {
            $app_id = Validate::id($app);
            if ((string) ($record['app'] ?? '') !== $app_id) {
                throw new RuntimeException('Service does not belong to this application.');
            }
        }
        $record['status'] = 'removing';
        $this->records->_put(['kind' => 'service', 'id' => $id, 'data' => $record]);

        $payload = [
            'service' => $id,
            'service_name' => (string) ($record['name'] ?? $id),
            'runtime_name' => (string) ($record['runtime_name'] ?? $id),
            'server' => $record['server'],
        ];
        if (!empty($record['app'])) {
            $payload['app'] = (string) $record['app'];
        }
        return $this->enqueue('destroy-service', 'server:' . $record['server'], $payload);
    }

    function _mark_deployed(string $name, array $result): void {
        $record = $this->records->_find(['kind' => 'service', 'id' => $name]);
        if ($record === null) {
            return;
        }
        if (($result['status'] ?? '') === 'succeeded') {
            $record['status'] = 'running';
            $record['ip'] = $result['ip'] ?? $record['ip'];
            $record['url'] = $result['url'] ?? $record['url'];
            $record['error'] = null;
            $record['deployed_at'] = time();
        } else {
            $record['status'] = 'failed';
            $record['error'] = is_string($result['error'] ?? null) ? $result['error'] : 'Service deployment failed';
        }
        $this->records->_put(['kind' => 'service', 'id' => $name, 'data' => $record]);
    }

    function _delete_record(string $name): void {
        $record = $this->records->_find(['kind' => 'service', 'id' => $name]);
        if ($record === null) {
            return;
        }
        $this->db->connection()->beginTransaction();
        try {
            foreach ($record['env_refs'] ?? [] as $ref) {
                if (is_string($ref)) {
                    $this->db->query_bind('DELETE FROM credentials WHERE id=?', [$ref], 'statement');
                }
            }
            $this->records->_delete(['kind' => 'service', 'id' => $name]);
            $this->db->connection()->commit();
        } catch (Throwable $e) {
            $this->db->connection()->rollBack();
            throw $e;
        }
    }
}
