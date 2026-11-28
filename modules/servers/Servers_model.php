<?php
declare(strict_types=1);

require_once APPPATH . 'modules/security/traits/Encrypted_credentials.php';
require_once APPPATH . 'modules/validation/services/Validate.php';
require_once APPPATH . 'modules/servers/services/Ssh.php';

class Servers_model extends Model {
    use Encrypted_credentials;

    function __construct(?string $module_name = null, PDO|Closure|null $connection = null) {
        parent::__construct($module_name, $connection);
        $this->module('records');
    }

    function _resources(string $id, ?array $change = null): array {
        $server = $this->records->_get(['kind' => 'server', 'id' => Validate::id($id)]);
        if (empty($server['application_ready'])) {
            throw new RuntimeException('Connect an application-ready FreeBSD host to view app resources.');
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
                $memory = filter_var($change['memory_mb'] ?? '', FILTER_VALIDATE_INT);
                if ($memory === false || $memory < 64) {
                    throw new RuntimeException('Memory cap must be a whole number of at least 64 MiB.');
                }
                $cpu_percent = filter_var($change['cpu_percent'] ?? '', FILTER_VALIDATE_INT);
                if ($cpu_percent === false || $cpu_percent < 10 || $cpu_percent > 1600) {
                    throw new RuntimeException('CPU cap must be a whole percentage between 10% and 1600%.');
                }
                $disk_quota = filter_var($change['disk_quota_mb'] ?? '', FILTER_VALIDATE_INT);
                if ($disk_quota === false || $disk_quota < 128) {
                    throw new RuntimeException('ZFS disk quota must be a whole number of at least 128 MiB.');
                }
                $request += [
                    'memory_mb' => (int) $memory,
                    'cpu_percent' => (int) $cpu_percent,
                    'disk_quota_mb' => (int) $disk_quota,
                ];
            }
        }

        $result = (new Ssh($this))->command($server, 'tc-runtime', self::_encode_json($request), 120);
        $data = json_decode($result['out'], true);
        if ($result['exit'] !== 0 || !is_array($data) || ($data['status'] ?? '') !== 'succeeded') {
            $error = ($data['status'] ?? '') === 'failed' ? ($data['error'] ?? null) : null;
            throw new RuntimeException(
                $error ?? 'Could not read or update resources. The server may be busy or need a tc-runtime upgrade. Refresh and try again.'
            );
        }

        $data['server'] = $id;
        $data['host_address'] = $server['host'];
        return $data;
    }

    private static function _encode_json(array $value): string {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
