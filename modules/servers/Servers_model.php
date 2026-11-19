<?php
require_once dirname(__DIR__).'/db/traits/Database_access.php';
require_once dirname(__DIR__).'/operations/traits/Inventory_records.php';
require_once dirname(__DIR__).'/operations/traits/Encrypted_credentials.php';
require_once dirname(__DIR__).'/operations/traits/Operation_queue.php';
class Servers_model extends Model {
    use Database_access, Inventory_records, Encrypted_credentials, Operation_queue;

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
