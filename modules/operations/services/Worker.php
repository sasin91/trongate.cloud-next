<?php
declare(strict_types=1);
final class Worker {
    function __construct(private Model $store, private Http $http = new Http) {
    }
    function once(): bool {
        $owner = bin2hex(random_bytes(16));
        $job = $this->store->claim($owner);
        if (!$job) {
            return false;
        }
        $id = $job['id'];
        $heartbeat = fn() => $this->store->heartbeat($id, $owner);
        try {
            if ($job['attempts'] > 30) {
                throw new RuntimeException('Retry budget exhausted; reconcile required');
            }
            $this->store->step($id, 'claimed', ['attempt' => (int)$job['attempts']]);
            $result = $this->perform($job, $heartbeat);
            $status = $result['status'] ?? 'uncertain';
            if (!in_array($status, ['pending', 'succeeded', 'failed', 'uncertain', 'waiting'])) {
                $status = 'uncertain';
            }
            $this->store->step($id, 'observed', $result);
            $this->store->finish($id, $owner, $status, $result);
        } catch (Throwable $e) {
            // Exceptions may originate in libraries with credential-bearing messages.
            // Keep a safe class and step; raw provider/build/customer output is not logged.
            $this->store->finish($id, $owner, 'uncertain', ['error' => get_class($e), 'message' => $e instanceof ProviderError ? $e->getMessage() : 'Operation not verified; inspect remote/provider state and reconcile.']);
        }
        return true;
    }
    function perform(array $job, callable $heartbeat): array {
        $s = $this->store;
        $p = json_decode($job['payload'], true, 512, JSON_THROW_ON_ERROR);
        $id = $job['id'];
        $kind = $job['kind'];
        if ($kind === 'build') {
            $local = ($p['mode'] ?? 'dedicated') === 'local';
            $input = $p['plan'] + ['operation' => $id];
            if ($local) {
                $input['action'] = 'build';
            }
            $r = (new Ssh($s))->command($s->get('server', $p['builder']), $local ? 'tc-runtime' : 'tc-build', tc_json($input), 3600, $heartbeat);
            $result = json_decode($r['out'], true);
            if ($r['exit'] !== 0 || !is_array($result)) {
                throw new RuntimeException('Builder interrupted');
            }
            if (($result['status'] ?? '') === 'succeeded' && isset($p['launch'])) {
                $result['deployment'] = (new Apps_model('apps', $s->connection()))->_deploy_built($id, $p['launch'], $result);
            }
            return $result;
        }
        if (in_array($kind, ['deploy', 'rollback', 'host-update', 'host-rollback'])) {
            $server = $s->get('server', $p['server']);
            $ssh = new Ssh($s);
            if (str_starts_with($kind, 'host-')) {
                $backend = new DeployRs($ssh);
                $result = $kind === 'host-update' ? $backend->deploy($server, $p + ['operation' => $id], $heartbeat) : $backend->rollback($server, $p + ['operation' => $id], $heartbeat);
                $s->put('host-operation', $id, ['server' => $p['server'], 'desired' => $p, 'observed' => $result]);
                return $result;
            }
            $inspection = $ssh->inspect($server);
            if (!$inspection['application_ready']) {
                return ['status' => 'failed', 'missing' => $inspection['missing']];
            }
            $request = ['action' => $kind, 'operation' => $id, 'app' => $p['app'], 'public_check' => $p['public_check'] ?? true, 'defer_public' => true, 'go_live' => $p['go_live'] ?? false];
            if ($kind === 'deploy') {
                $release = $s->get('release', $p['release'])['spec'];
                $secrets = [];
                foreach ($release['secrets'] as $name => $ref) {
                    $secrets[$name] = $s->secret($ref);
                }
                $request += ['release' => $release, 'secret_values' => $secrets];
                $request['file_values'] = [];
                foreach ($release['file_secrets'] ?? [] as $target => $ref) {
                    $request['file_values'][$target] = $s->secret($ref);
                }
                if (isset($release['registry_secret'])) {
                    $request['registry_auth'] = $s->secret($release['registry_secret']);
                }
            }
            $lastPhase = '';
            $progress = function () use ($heartbeat, $ssh, $server, $s, $id, &$lastPhase): void {
                $heartbeat();
                try {
                    $response = $ssh->command($server, 'tc-runtime', tc_json(['action' => 'status', 'operation' => $id]), 8);
                    $state = json_decode($response['out'], true);
                    if (!empty($state['phase']) && $state['phase'] !== $lastPhase) {
                        $lastPhase = $state['phase'];
                        $s->step($id, 'progress', ['phase' => $lastPhase]);
                    }
                } catch (RuntimeException) {
                    // Progress is best-effort; the durable result remains authoritative.
                }
            };
            return $ssh->runtime($server, $request, $progress);
        }
        $provider = new Hetzner($s->secret($s->get('provider', $p['provider'])['credential']), $this->http);
        try {
            $intent = $s->get('external', $id);
        } catch (RuntimeException) {
            $intent = [];
        }
        if (isset($intent['action'])) {
            $observed = $provider->action($intent['action']);
            if ($observed['status'] === 'succeeded' && in_array($kind, ['provision', 'bootstrap-create'])) {
                $server = $provider->call('GET', '/servers/' . $intent['provider_id'])['server'];
                $s->put('provider-server', (string)$server['id'], ['provider' => $p['provider'], 'server' => $server, 'operation' => $id, 'enrollment' => 'pending independent host-key confirmation']);
                $observed['server'] = $server;
            }
            return $observed + $intent;
        }
        if (in_array($kind, ['provision', 'bootstrap-create'])) {
            $found = $provider->reconcile_create($id);
            if ($found) {
                $s->put('provider-server', (string)$found['id'], ['provider' => $p['provider'], 'server' => $found, 'operation' => $id]);
                // A running VM is insufficient proof that provisioning/SSH readiness completed.
                $actions = $provider->call('GET', '/servers/' . $found['id'] . '/actions?sort=id:desc&per_page=50')['actions'];
                foreach ($actions as $action) {
                    if ($action['command'] === 'create_server') {
                        $s->put('external', $id, ['provider_id' => $found['id'], 'action' => $action['id']]);
                        return ['status' => 'pending', 'provider_id' => $found['id']];
                    }
                }
                return ['status' => 'uncertain', 'provider_id' => $found['id'], 'reason' => 'Create action not yet visible'];
            }
            if ($intent) {
                return ['status' => 'uncertain', 'reason' => 'Create may have been accepted. No resource visible; no second create issued.'];
            }
            $s->put('external', $id, ['submitted' => time(), 'kind' => $kind]);
            $p['bootstrap'] = $kind === 'bootstrap-create';
            $response = $provider->create($id, $p);
            $s->put('external', $id, ['provider_id' => $response['server']['id'], 'action' => $response['action']['id']]);
            return ['status' => 'pending', 'provider_id' => $response['server']['id']];
        }
        if ($intent) {
            return ['status' => 'uncertain', 'reason' => 'Mutation already submitted; inspect provider action history before resolving'];
        }
        if ($kind === 'snapshot-create' && $provider->call('GET', '/servers/' . (int)$p['provider_id'])['server']['status'] !== 'off') {
            return ['status' => 'failed', 'reason' => 'Power off the selected snapshot source before capture'];
        }
        $s->put('external', $id, ['submitted' => time(), 'kind' => $kind, 'provider_id' => $p['provider_id']]);
        $resource = (int)$p['provider_id'];
        $response = match ($kind) {
            'provider-rescue' => $provider->call('POST', "/servers/$resource/actions/enable_rescue", ['type' => 'linux64', 'ssh_keys' => array_map(fn($n) => Validate::number($n, 1, PHP_INT_MAX), $p['ssh_keys'])]),
            'provider-poweroff' => $provider->call('POST', "/servers/$resource/actions/poweroff", []),
            'provider-reboot' => $provider->call('POST', "/servers/$resource/actions/reboot", []),
            'provider-delete' => $provider->call('DELETE', "/servers/$resource"),
            'snapshot-create' => $provider->call('POST', "/servers/$resource/actions/create_image", ['type' => 'snapshot', 'description' => 'Trongate NixOS ' . $id, 'labels' => ['tc-operation' => $id, 'tc-nixos' => 'true']]),
            'snapshot-delete' => $provider->call('DELETE', "/images/$resource"),
            default => throw new RuntimeException('Unknown job kind')
        };
        $record = ['provider_id' => $resource, 'kind' => $kind];
        if (isset($response['image'])) {
            $record['image_id'] = $response['image']['id'];
        }
        if (isset($response['action'])) {
            $record['action'] = $response['action']['id'];
        }
        $s->put('external',$id,$record);
        if (!isset($record['action'])) {
            return ['status' => 'succeeded'] + $record;
        }
        return ['status' => 'pending'] + $record;
    }
}
