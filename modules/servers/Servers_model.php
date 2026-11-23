<?php
require_once APPPATH . 'modules/trongate_security/traits/Encrypted_credentials.php';
require_once APPPATH . 'modules/jobs/traits/Job_queue.php';
class Servers_model extends Model {
    use Encrypted_credentials, Job_queue;
    public function __construct(?string $module_name = null, PDO|Closure|null $connection = null) {
        parent::__construct($module_name, $connection);
        $this->module('records');
    }


    function _resources(string $id, ?array $change = null): array {
        $server = $this->records->_get(['kind' => 'server', 'id' => Validate::id($id)]);
        if (empty($server['application_ready'])) {
            throw new RuntimeException('Connect an application-ready server to view app resources.');
        }
        $request = ['action' => 'resources'];
        if ($change !== null) {
            $app = Validate::id((string) ($change['app'] ?? ''));
            if ($this->records->_get(['kind' => 'app', 'id' => $app])['server'] !== $id) {
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
        $result = (new Ssh($this))->command($server, 'tc-runtime', self::_encode_json($request), 120);
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
        $this->module('proxmox');
        $pending = [];
        $jobs = $this->db->query_bind(
            'SELECT id FROM jobs WHERE kind=? AND status<>? ORDER BY created DESC',
            ['proxmox-provision', 'succeeded'], 'statement'
        )->fetchAll();
        foreach ($jobs as $job) {
            $pending[] = $this->proxmox->model->_progress($job['id']);
        }
        return $pending;
    }

    private static function _encode_json(array $value): string {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}