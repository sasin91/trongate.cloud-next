<?php
declare(strict_types=1);

class Proxmox extends Trongate {
    function _discover(array $input): array {
        $credential = Validate::id($input['credential'] ?? '');
        $client = new Proxmox_client($input['endpoint'] ?? '', $this->model->secret($credential), $this->http);
        return $this->options($client);
    }

    function _create_connected(string $connection, string $name, mixed $vmid, bool $confirmed, string $request): string {
        Validate::match($request, '/^[a-f0-9]{32}$/D', 'creation request');
        $config = $this->records->_get(['kind' => 'proxmox-connection', 'id' => Validate::id($connection)]);
        return $this->_queue(array_replace($config, ['name' => $name, 'vmid' => $vmid, 'confirm' => $confirmed]), $request);
    }

    function _queue(array $input, ?string $request = null): string {
        $plan = self::plan($input);
        $credential = Validate::id($input['credential'] ?? '');
        $endpoint = rtrim($input['endpoint'] ?? '', '/');
        new Proxmox_client($endpoint, $this->model->secret($credential), $this->http);
        foreach ($this->model->_servers() as $server) {
            if ($server['id'] === $plan['name']) {
                throw new RuntimeException('Server name is already connected.');
            }
        }
        return $this->model->_queue_provision($plan + ['endpoint' => $endpoint, 'credential' => $credential], self::ssh_key(), $request);
    }

    function options(Proxmox_client $api): array {
        if (PHP_SAPI !== 'cli') {
            block_url('proxmox/options');
        }
        $nodes = $api->get('/nodes');
        $resources = $api->get('/cluster/resources?type=vm');
        if (!array_is_list($nodes) || !array_is_list($resources)) {
            throw new Provider_error('Invalid Proxmox discovery response.');
        }
        $templates = [];
        foreach ($resources as $resource) {
            if (!is_array($resource)) {
                throw new Provider_error('Invalid Proxmox resource.');
            }
            if (($resource['type'] ?? '') === 'qemu' && (int) ($resource['template'] ?? 0) === 1) {
                $templates[] = array_intersect_key($resource, array_flip(['vmid', 'node', 'name', 'status', 'tags']));
            }
        }
        $visibleNodes = [];
        foreach ($nodes as $node) {
            if (!is_array($node) || !is_string($node['node'] ?? null)) {
                throw new Provider_error('Invalid Proxmox node.');
            }
            $visibleNodes[] = array_intersect_key($node, array_flip(['node', 'status', 'maxcpu', 'maxmem', 'mem', 'cpu']));
        }
        return ['nodes' => $visibleNodes, 'templates' => $templates, 'provisioning_supported' => true];
    }

    static function plan(array $p): array {
        if (PHP_SAPI !== 'cli') {
            block_url('proxmox/plan');
        }
        if (($p['confirm'] ?? false) !== true) {
            throw new RuntimeException('Explicit operator confirmation required for Proxmox provisioning.');
        }
        $plan = [
            'node' => Validate::match($p['node'] ?? '', '/^[a-zA-Z0-9][a-zA-Z0-9.-]*$/D', 'Proxmox node'),
            'template' => Validate::number($p['template'] ?? null, 100, 999999999),
            'vmid' => Validate::number($p['vmid'] ?? null, 100, 999999999),
            'name' => Validate::id($p['name'] ?? ''),
            'storage' => Validate::id($p['storage'] ?? ''),
            'confirm' => true,
        ];
        if ($plan['template'] === $plan['vmid']) {
            throw new RuntimeException('Choose a VM ID different from the template.');
        }
        return $plan;
    }

    function clone_vm(Proxmox_client $api, string $operation, array $plan, ?callable $heartbeat = null, ?callable $beforeSubmit = null): string {
        if (PHP_SAPI !== 'cli') {
            block_url('proxmox/clone_vm');
        }
        $plan = self::plan($plan);
        Validate::match($operation, '/^[a-f0-9]{32}$/D', 'operation');
        $resources = $api->get('/cluster/resources?type=vm');
        if ($heartbeat !== null) {
            $heartbeat();
        }
        foreach ($resources as $resource) {
            if ((int) ($resource['vmid'] ?? 0) === $plan['vmid']) {
                throw new RuntimeException('The requested VM ID is already in use.');
            }
        }
        $storage = $api->get('/nodes/' . rawurlencode($plan['node']) . '/storage/' . rawurlencode($plan['storage']) . '/status');
        if ($heartbeat !== null) {
            $heartbeat();
        }
        if (empty($storage['active']) || empty($storage['enabled']) ||
            !in_array('images', explode(',', $storage['content'] ?? ''), true)) {
            throw new RuntimeException('Choose enabled, active storage supporting VM images.');
        }
        $base = '/nodes/' . rawurlencode($plan['node']) . '/qemu/' . $plan['template'];
        $template = $api->get($base . '/config');
        if ($heartbeat !== null) {
            $heartbeat();
        }
        if ((int) ($template['template'] ?? 0) !== 1 ||
            !in_array('tc-nixos', explode(';', $template['tags'] ?? ''), true)) {
            throw new RuntimeException('Choose a prepared NixOS VM template tagged tc-nixos.');
        }
        $cloudinit = false;
        foreach ($template as $key => $value) {
            if (preg_match('/^(?:ide|scsi|sata)\d+$/D', $key) && is_string($value) && str_contains(explode(',', $value)[0], 'cloudinit')) {
                $cloudinit = true;
            }
        }
        if (!$cloudinit || !isset($template['net0']) || !empty($template['cicustom']) ||
            !preg_match('/^(?:1|enabled=1)(?:,|$)/D', (string) ($template['agent'] ?? ''))) {
            throw new RuntimeException('The template requires cloud-init without custom overrides, net0 and an enabled QEMU guest agent for automatic SSH setup.');
        }
        if ($beforeSubmit !== null) {
            $beforeSubmit();
        }
        return $api->post($base . '/clone', [
            'newid' => $plan['vmid'], 'name' => $plan['name'], 'full' => 1,
            'storage' => $plan['storage'], 'description' => 'tc-operation=' . $operation,
        ]);
    }

    function start_vm(Proxmox_client $api, array $plan): string {
        if (PHP_SAPI !== 'cli') {
            block_url('proxmox/start_vm');
        }
        $plan = self::plan($plan);
        return $api->post('/nodes/' . rawurlencode($plan['node']) . '/qemu/' . $plan['vmid'] . '/status/start', []);
    }

    function configure_ssh(Proxmox_ssh $ssh, array $plan, string $publickey): void {
        if (PHP_SAPI !== 'cli') {
            block_url('proxmox/configure_ssh');
        }
        $ssh->configure(self::plan($plan), $publickey);
    }

    function ssh_configured(array $config, string $publickey): bool {
        if (PHP_SAPI !== 'cli') {
            block_url('proxmox/ssh_configured');
        }
        return Proxmox_ssh::configured($config, $publickey);
    }

    static function public_key(string $value): string {
        if (PHP_SAPI !== 'cli') {
            block_url('proxmox/public_key');
        }
        return Proxmox_ssh::public_key($value);
    }

    function guest_identity(Proxmox_ssh $ssh, array $plan, ?callable $heartbeat = null): array {
        if (PHP_SAPI !== 'cli') {
            block_url('proxmox/guest_identity');
        }
        return $ssh->identity(self::plan($plan), $heartbeat);
    }

    function inspect_vm(Proxmox_client $api, array $plan, ?callable $heartbeat = null): array {
        if (PHP_SAPI !== 'cli') {
            block_url('proxmox/inspect_vm');
        }
        $plan = self::plan($plan);
        $base = '/nodes/' . rawurlencode($plan['node']) . '/qemu/' . $plan['vmid'];
        $config = $api->get($base . '/config');
        if ($heartbeat !== null) {
            $heartbeat();
        }
        return [
            'config' => $config,
            'status' => $api->get($base . '/status/current'),
        ];
    }

    function action(Proxmox_client $api, string $node, string $upid): array {
        if (PHP_SAPI !== 'cli') {
            block_url('proxmox/action');
        }
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.-]*$/D', $node) ||
            !str_starts_with($upid, 'UPID:' . $node . ':') || preg_match('/[\s\/\\\\]/', $upid)) {
            throw new RuntimeException('Invalid Proxmox task identity.');
        }
        $task = $api->get('/nodes/' . rawurlencode($node) . '/tasks/' . rawurlencode($upid) . '/status');
        $status = match ($task['status'] ?? '') {
            'running' => 'pending',
            'stopped' => !isset($task['exitstatus']) ? 'uncertain' : ($task['exitstatus'] === 'OK' ? 'succeeded' : 'failed'),
            default => 'uncertain',
        };
        // Task error text can contain user-supplied arguments; do not expose it.
        return ['status' => $status, 'provider_action' => $upid, 'node' => $node];
    }
    public function enroll(Model $model, string $operation, array $plan, array $identity, Process $process = new Process, ?callable $heartbeat = null): array {
        if (PHP_SAPI !== 'cli') {
            block_url('proxmox/enroll');
        }
        $name = Validate::id($plan['name']);
        $key = Proxmox::public_key($identity['host_key'] ?? '');
        foreach ($this->model->_servers() as $existing) {
            if ($existing['id'] === $name || ($existing['host_key'] ?? '') === $key) {
                if (($existing['provision_operation'] ?? '') === $operation && ($existing['host_key'] ?? '') === $key) {
                    return ['status' => 'succeeded', 'server_id' => $existing['id']];
                }
                throw new RuntimeException('Server name or SSH identity is already enrolled.');
            }
        }
        $server = Proxmox_ssh::inspect(new Ssh($model, $process), $operation, $plan, $identity, $heartbeat);
        if ($server === null) {
            return ['status' => 'pending', 'reason' => 'Waiting for pinned SSH access and NixOS application runtime readiness.'];
        }
        $this->model->_register_server($name, $server);
        return ['status' => 'succeeded', 'server_id' => $name];
    }

    public function provision(Job_context $context): array {
        $model = $this->model;
        $operation = $context->id;
        $plan = $context->payload;
        $heartbeat = $context->heartbeat(...);
        if (PHP_SAPI !== 'cli') {
            block_url('proxmox/provision');
        }
        $api = new Proxmox_client($plan['endpoint'], $model->secret($plan['credential']), $this->http);
        $ssh = new Proxmox_ssh($api);

        if (empty($plan['ssh_credential']) || empty($plan['ssh_public_key'])) {
            return ['status' => 'uncertain', 'reason' => 'This older provisioning request has no SSH enrollment credentials. Operator review is required.'];
        }
        $intent = $this->model->_intent($operation);
        $identity = ['provider_id' => $plan['vmid'], 'node' => $plan['node']];
        if (!$intent) {
            $heartbeat();
            $intent = ['phase' => 'clone', 'submitted' => time()] + $identity;
            $beforeSubmit = function () use ($operation, $intent, $heartbeat): void {
                $heartbeat();
                $this->model->_save_intent($operation, $intent);
            };
            try {
                $intent['action'] = $this->clone_vm($api, $operation, $plan, $heartbeat, $beforeSubmit);
            } catch (Provider_error $error) {
                if (in_array($error->getCode(), [400, 401, 403, 404, 405, 409, 422], true)) {
                    return ['status' => 'failed', 'reason' => 'Proxmox rejected the clone request. Check permissions and template settings before creating another request.', 'http_status' => $error->getCode()] + $intent;
                }
                throw $error;
            }
            $heartbeat();
            $this->model->_save_intent($operation, $intent);
            return ['status' => 'pending'] + $intent;
        }
        $phase = $intent['phase'];
        if (!in_array($phase, ['clone', 'configure', 'start', 'enroll'], true)) {
            throw new RuntimeException('Invalid Proxmox provisioning journal');
        }
        if (in_array($phase, ['clone', 'start'], true)) {
            if (!isset($intent['action'])) {
                return ['status' => 'uncertain', 'reason' => 'Proxmox mutation may have been accepted. No task identity was saved; no second mutation issued.'] + $intent;
            }
            $heartbeat();
            $observed = $this->action($api, $plan['node'], $intent['action']);
            $heartbeat();
            if ($observed['status'] !== 'succeeded') {
                return $observed + $intent;
            }
        }
        $vm = $this->inspect_vm($api, $plan, $heartbeat);
        $heartbeat();
        if (!$this->owned($vm, $operation, $plan)) {
            return ['status' => 'uncertain', 'reason' => 'VM identity, ownership or clone completion could not be verified.'] + $intent;
        }
        if ($phase === 'clone') {
            if (($vm['status']['status'] ?? '') !== 'stopped') {
                return ['status' => 'uncertain', 'reason' => 'The cloned VM must remain stopped until its SSH access is configured.'] + $intent;
            }
            $intent = ['phase' => 'configure', 'submitted' => time(), 'clone_action' => $intent['action']] + $identity;
            $this->model->_save_intent($operation, $intent);
            $heartbeat();
            $this->configure_ssh($ssh, $plan, $plan['ssh_public_key']);
            $heartbeat();
            return ['status' => 'pending'] + $intent;
        }
        if (!$this->ssh_configured($vm['config'], $plan['ssh_public_key'])) {
            return ['status' => 'uncertain', 'reason' => 'The requested cloud-init SSH configuration could not be verified. No configuration mutation was repeated.'] + $intent;
        }
        if ($phase === 'configure') {
            if (($vm['status']['status'] ?? '') !== 'stopped') {
                return ['status' => 'uncertain', 'reason' => 'The VM was started outside this provisioning operation before SSH configuration was verified.'] + $intent;
            }
            $intent = ['phase' => 'start', 'submitted' => time(), 'clone_action' => $intent['clone_action']] + $identity;
            $this->model->_save_intent($operation, $intent);
            $heartbeat();
            $intent['action'] = $this->start_vm($api, $plan);
            $heartbeat();
            $this->model->_save_intent($operation, $intent);
            return ['status' => 'pending'] + $intent;
        }
        if (($vm['status']['status'] ?? '') !== 'running') {
            return ['status' => 'uncertain', 'reason' => 'Proxmox completed the start task but the VM is not running.'] + $intent;
        }
        if ($phase === 'start') {
            $intent = ['phase' => 'enroll', 'start_action' => $intent['action'], 'clone_action' => $intent['clone_action']] + $identity;
            $this->model->_save_intent($operation, $intent);
        }
        try {
            $guest = $this->guest_identity($ssh, $plan, $heartbeat);
        } catch (Provider_error) {
            return ['status' => 'pending', 'reason' => 'Waiting for the guest agent to report SSH identity and a network address.'] + $intent;
        }
        $heartbeat();
        $result = $this->enroll($model, $operation, $plan, $guest, heartbeat: $heartbeat);
        $heartbeat();
        if ($result['status'] !== 'succeeded') {
            return $result + $intent;
        }
        $server = $identity + ['name' => $plan['name'], 'status' => 'running', 'server_id' => $result['server_id']];
        $key = 'proxmox-' . substr(hash('sha256', rtrim($plan['endpoint'], '/')), 0, 32) . '-' . $plan['vmid'];
        $this->model->_save_provider_server($key, [
            'type' => 'proxmox', 'endpoint' => $plan['endpoint'], 'server' => $server,
            'operation' => $operation, 'enrollment' => 'enrolled',
        ]);
        return $result + ['server' => $server, 'enrollment' => 'enrolled'] + $intent;
    }

    private function owned(array $vm, string $operation, array $plan): bool {
        $config = $vm['config'];
        return ($config['description'] ?? '') === 'tc-operation=' . $operation &&
            ($config['name'] ?? '') === $plan['name'] && empty($config['template']) && empty($config['lock']);
    }


    public static function ssh_key(): array {
        if (PHP_SAPI !== 'cli') {
            block_url('proxmox/ssh_key');
        }
        return Proxmox_ssh::generate_key();
    }
}
