<?php
require_once dirname(__DIR__).'/db/traits/Database_access.php';
require_once dirname(__DIR__).'/operations/traits/Inventory_records.php';
require_once dirname(__DIR__).'/operations/traits/Encrypted_credentials.php';
require_once dirname(__DIR__).'/operations/traits/Operation_queue.php';
class Servers_model extends Model {
    use Database_access, Inventory_records, Encrypted_credentials, Operation_queue;

    function _resources(string $id, ?array $change = null): array {
        $server = $this->get('server', Validate::id($id));
        if (empty($server['application_ready'])) {
            throw new RuntimeException('Connect an application-ready server to view app resources.');
        }
        $request = ['action' => 'resources'];
        if ($change !== null) {
            $app = Validate::id((string) ($change['app'] ?? ''));
            if ($this->get('app', $app)['server'] !== $id) {
                throw new RuntimeException('This app belongs to a different server.');
            }
            $mode = (string) ($change['mode'] ?? '');
            if (!in_array($mode, ['automatic', 'manual'], true)) {
                throw new RuntimeException('Choose automatic or manual allocation.');
            }
            $request = ['action' => 'resources-update', 'app' => $app, 'mode' => $mode];
            if ($mode === 'manual') {
                $memory = Validate::number($change['memory_mb'] ?? '', 256, PHP_INT_MAX);
                $cpu = filter_var($change['cpus'] ?? '', FILTER_VALIDATE_FLOAT);
                if ($cpu === false || !is_finite($cpu) || $cpu < 0.05 || round($cpu, 2) !== $cpu) {
                    throw new RuntimeException('CPU shares must be at least 0.05, with up to two decimal places.');
                }
                $request += ['memory_mb' => $memory, 'cpus' => $cpu];
            }
        }
        $result = (new Ssh($this))->command($server, 'tc-runtime', tc_json($request), 120);
        $data = json_decode($result['out'], true);
        if ($result['exit'] !== 0 || !is_array($data) || ($data['status'] ?? '') !== 'succeeded') {
            $error = ($data['status'] ?? '') === 'failed' ? ($data['error'] ?? null) : null;
            throw new RuntimeException($error ?? 'Could not read or update resources. The server may be busy or need a runtime upgrade. Refresh and try again.');
        }
        $data['server'] = $id;
        $data['host_address'] = $server['host'];
        return $data;
    }

    function _pending_connections(): array {
        $connected = $this->all('server');
        $providers = new Providers_model('providers', $this->connection());
        $pending = [];
        $jobs = $this->run('SELECT id FROM jobs WHERE kind=? ORDER BY created DESC', ['provision'])->fetchAll();
        foreach ($jobs as $job) {
            $status = $providers->_status($job['id']);
            if ($status['status'] === 'deleted') {
                continue;
            }
            $matched = false;
            foreach ($connected as $server) {
                if (isset($status['provider_id']) && ($server['provider'] ?? '') === $status['provider'] && (string) ($server['provider_id'] ?? '') === (string) $status['provider_id']) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                $pending[] = $status;
            }
        }
        return $pending;
    }
}
