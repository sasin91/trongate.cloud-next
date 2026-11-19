<?php
require_once dirname(__DIR__) . '/db/traits/Database_access.php';
require_once dirname(__DIR__) . '/operations/traits/Inventory_records.php';
require_once dirname(__DIR__) . '/operations/traits/Encrypted_credentials.php';
require_once dirname(__DIR__) . '/operations/traits/Operation_queue.php';
class Providers_model extends Model {
    use Database_access, Inventory_records, Encrypted_credentials, Operation_queue;
    function _client(string $id, Http $http = new Http): Hetzner {
        $record = $this->get('provider', Validate::id($id));
        if ($record['type'] !== 'hetzner') {
            throw new RuntimeException('This provider is not supported yet.');
        }
        return new Hetzner($this->secret($record['credential']), $http);
    }
    function _connect_project(string $id, string $token, Http $http = new Http): array {
        $label = trim($id);
        if ($label === '' || strlen($label) > 100) {
            throw new RuntimeException('Enter a project name of 1 to 100 characters.');
        }
        $id = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $label), '-'));
        if (!preg_match('/^[a-z][a-z0-9-]{0,39}$/D', $id)) {
            $id = 'project-' . substr(hash('sha256', $label), 0, 24);
        }
        if (trim($token) === '') {
            throw new RuntimeException('Enter a Hetzner Cloud project API token.');
        }
        foreach ($this->all('provider') as $existing) {
            if ($existing['id'] === $id) {
                throw new RuntimeException('Project name already connected. Choose it from the list.');
            }
        }
        $token = trim($token);
        (new Hetzner($token, $http))->list('locations');
        $ref = 'provider-' . substr(hash('sha256', $id), 0, 30);
        $this->secret($ref, $token);
        $this->put('provider', $id, ['type' => 'hetzner', 'credential' => $ref, 'label' => $label]);
        return ['id' => $id];
    }
    function _update_token(string $id, string $token, Http $http = new Http): void {
        $record = $this->get('provider', Validate::id($id));
        if ($record['type'] !== 'hetzner' || trim($token) === '') {
            throw new RuntimeException('Enter a Hetzner Cloud project API token.');
        }
        (new Hetzner(trim($token), $http))->list('locations');
        $this->secret($record['credential'], trim($token));
    }

    function _options(string $id, Http $http = new Http): array {
        $provider = $this->_client($id, $http);
        $options = $provider->options();
        $options['images'] = array_values(array_filter($options['images'], static fn($image) =>
            ($image['type'] ?? '') === 'snapshot' && ($image['labels']['tc-nixos'] ?? '') === 'true' &&
            ($image['status'] ?? '') === 'available' && empty($image['deprecated'])
        ));
        $options['ssh_keys'] = array_map(fn($key) => ['id' => $key['id'], 'name' => $key['name'], 'fingerprint' => $key['fingerprint']], $provider->list('ssh_keys'));
        $options['pricing'] = $provider->call('GET', '/pricing')['pricing'];
        return $options;
    }
    function _quote(array $p, Http $http = new Http): array {
        $id = Validate::id($p['provider'] ?? '');
        $name = Validate::id($p['name'] ?? '');
        $options = $this->_options($id, $http);
        $find = function (array $items, string $field, mixed $value): array {
            foreach ($items as $item) {
                if ((string)$item[$field] === (string)$value) {
                    return $item;
                }
            }
            throw new RuntimeException('The selected provider option is no longer available. Reload the options.');
        };
        $type = $find($options['server_types'], 'name', $p['server_type'] ?? '');
        $location = $find($options['locations'], 'name', $p['location'] ?? '');
        $image = $find($options['images'], 'id', $p['image'] ?? '');
        $key = $find($options['ssh_keys'], 'id', $p['ssh_key'] ?? '');
        $availability = $find($type['locations'] ?? [], 'name', $location['name']);
        if (empty($availability['available']) || !empty($type['deprecated'])) {
            throw new RuntimeException('That server type is currently unavailable in this location.');
        }
        if ($image['architecture'] !== $type['architecture'] || ($image['status'] ?? '') !== 'available' || !empty($image['deprecated'])) {
            throw new RuntimeException('Select an available image matching the server architecture.');
        }
        if ($image['type'] !== 'snapshot' || ($image['labels']['tc-nixos'] ?? '') !== 'true') {
            throw new RuntimeException('Choose a prepared NixOS snapshot.');
        }
        if ((int)($image['disk_size'] ?? 0) > (int)$type['disk']) {
            throw new RuntimeException('This image needs a larger disk.');
        }
        $price = $find($type['prices'], 'location', $location['name']);
        $ip = $find($options['pricing']['primary_ips'], 'type', 'ipv4');
        $ipPrice = $find($ip['prices'], 'location', $location['name']);
        $plan = ['provider' => $id, 'name' => $name, 'server_type' => $type['name'], 'location' => $location['name'], 'image' => $image['id'], 'image_kind' => $image['type'], 'ssh_keys' => [$key['id']], 'confirm' => true];
        $summary = ['name' => $name, 'provider' => $id, 'type' => $type['name'], 'location' => $location['description'] ?? $location['name'], 'image' => $image['description'], 'ssh_key' => $key['name'],
            'currency' => $options['pricing']['currency'], 'monthly_net' => number_format((float)$price['price_monthly']['net'] + (float)$ipPrice['price_monthly']['net'], 2, '.', ''),
            'hourly_net' => number_format((float)$price['price_hourly']['net'] + (float)$ipPrice['price_hourly']['net'], 4, '.', ''),
            'preparation_required' => false, 'os' => 'NixOS'];
        $quote = bin2hex(random_bytes(16));
        $this->put('server-quote', $quote, ['plan' => $plan, 'summary' => $summary, 'expires' => time() + 600]);
        return ['quote' => $quote] + $summary;
    }
    function _create(string $quote, bool $confirmed): array {
        if (!$confirmed) {
            throw new RuntimeException('Confirm the server configuration and cost first.');
        }
        $s = $this;
        try {
            return $s->get('server-request', $quote);
        } catch (RuntimeException) {
        }
        $record = $s->get('server-quote', $quote);
        if ($record['expires'] < time()) {
            throw new RuntimeException('The review has expired. Review the current options again.');
        }
        if (($record['plan']['image_kind'] ?? '') !== 'snapshot') {
            throw new RuntimeException('This review used a stock OS. Review a NixOS snapshot instead.');
        }
        $s->connection()->beginTransaction();
        try {
            $s->run('INSERT INTO records(kind,id,data) VALUES(?,?,?)', ['server-request', $quote, '{}']);
            $operation = (new Control($s))->execute('provision', $record['plan']);
            $result = ['operation' => $operation];
            $s->put('server-request', $quote, $result);
            $s->connection()->commit();
            return $result;
        } catch (Throwable $e) {
            $s->connection()->rollBack();
            try {
                return $s->get('server-request', $quote);
            } catch (RuntimeException) {
                throw $e;
            }
        }
    }
    function _delete_created_server(string $operation, string $name): string {
        $status = $this->_status($operation);
        if (!hash_equals($status['name'], trim($name))) {
            throw new RuntimeException('Type the server name to confirm deletion.');
        }
        if (isset($status['deletion_operation'])) {
            return $status['deletion_operation'];
        }
        if ($status['status'] !== 'succeeded') {
            throw new RuntimeException('Only a successfully created server can be deleted here.');
        }
        foreach ($this->all('server') as $server) {
            if (($server['provider'] ?? '') === $status['provider'] && (string) ($server['provider_id'] ?? '') === (string) $status['provider_id']) {
                throw new RuntimeException('This server is already connected. Deletion here is only available before connecting it.');
            }
        }
        $this->connection()->beginTransaction();
        try {
            $this->run('INSERT INTO records(kind,id,data) VALUES(?,?,?)', ['server-deletion', $operation, '{}']);
            $job = (new Control($this))->execute('provider-delete', [
                'provider' => $status['provider'], 'provider_id' => $status['provider_id'], 'confirm' => true
            ]);
            $this->put('server-deletion', $operation, ['operation' => $job]);
            $this->connection()->commit();
            return $job;
        } catch (Throwable $error) {
            $this->connection()->rollBack();
            try {
                return $this->get('server-deletion', $operation)['operation'];
            } catch (RuntimeException) {
                throw $error;
            }
        }
    }

    function _creation_progress(string $operation): array {
        $status = $this->_status($operation);
        $job = $this->job($operation);
        $started = (int) floor((int) $job['created'] / 1000000);
        $logs = [['at' => $started, 'message' => 'Server creation queued.']];
        $stage = 1;
        foreach ($this->run('SELECT at,step,data FROM steps WHERE operation=? ORDER BY at,id', [$operation])->fetchAll() as $step) {
            $data = json_decode($step['data'], true, 512, JSON_THROW_ON_ERROR);
            $message = null;
            if ($step['step'] === 'claimed') {
                $stage = 2;
                $message = 'Worker checking provider progress (attempt ' . (int) ($data['attempt'] ?? 1) . ').';
            } elseif ($step['step'] === 'observed') {
                $message = match ($data['status'] ?? '') {
                    'pending' => isset($data['provider_id']) ? 'Hetzner accepted the server request. Waiting for creation to finish.' : 'Waiting for Hetzner to finish the request.',
                    'succeeded' => 'Hetzner confirmed server creation.',
                    'failed' => 'Provider operation failed. Review the operation before retrying.',
                    'uncertain' => 'The provider outcome needs verification. Do not create a duplicate server.',
                    default => null
                };
            }
            if ($message !== null) {
                $logs[] = ['at' => (int) $step['at'], 'message' => $message];
            }
        }
        if ($status['status'] === 'succeeded') {
            $stage = 3;
        }
        $description = match ($status['status']) {
            'succeeded' => 'Server created. Connect over SSH to check application readiness.',
            'failed' => 'Server creation failed. Review the operation details before retrying.',
            'uncertain' => 'Creation could not be verified. Check the provider before creating another server.',
            'deleted' => 'This server has been deleted.',
            'deleting' => 'This server is being deleted.',
            default => $stage === 1 ? 'Queued — waiting for a worker.' : 'Hetzner is creating your server from the NixOS snapshot.'
        };
        // Only curated activity messages leave this endpoint; no payloads or provider responses.
        return $status + ['started' => $started, 'stage' => $stage, 'description' => $description, 'logs' => $logs];
    }

    function _status(string $operation): array {
        $job = $this->job($operation);
        if ($job['kind'] !== 'provision') {
            throw new RuntimeException('Not a server creation operation.');
        }
        $payload = json_decode($job['payload'], true);
        $result = json_decode($job['result'], true);
        $out = ['operation' => $operation, 'status' => $job['status'], 'name' => $payload['name'], 'provider' => $payload['provider']];
        if ($job['status'] === 'succeeded') {
            $server = $this->get('provider-server', (string)$result['provider_id'])['server'];
            $out += ['provider_id' => $server['id'], 'host' => $server['public_net']['ipv4']['ip'], 'preparation_required' => ($payload['image_kind'] ?? '') === 'system'];
        }
        $deletion = $this->run('SELECT data FROM records WHERE kind=? AND id=?', ['server-deletion', $operation])->fetchColumn();
        if ($deletion !== false) {
            $deleteJob = $this->job(json_decode($deletion, true, 512, JSON_THROW_ON_ERROR)['operation']);
            $out['deletion_operation'] = $deleteJob['id'];
            $out['deletion_status'] = $deleteJob['status'];
            $out['status'] = $deleteJob['status'] === 'succeeded' ? 'deleted' : 'deleting';
        }
        if (in_array($job['status'],['failed', 'uncertain'])) {
            $out['message'] = 'Inspect this operation and the provider console before creating another server; a resource may already exist.';
        }
        return $out;
    }
}
