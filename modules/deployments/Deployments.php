<?php
declare(strict_types=1);

class Deployments extends Trongate {
    function index(): void {
        $this->trongate_security->make_sure_allowed();
        try {
            $operation = $this->jobs->model->_details((string) ($_GET['operation'] ?? ''));
        } catch (Throwable) {
            $this->trongate_security->_json(['error' => 'Deployment not found'], 404);
            return;
        }
        $actor = $this->trongate_security->_actor();
        if (!Access_policy::can_view_job($actor, $operation['job'])) {
            $this->trongate_security->_forbidden('You do not have permission to view this operation.');
        }
        $timeline = $this->model->_timeline($operation['job']['id']);
        if (($_GET['timeline'] ?? '') === '1') {
            $this->trongate_security->_json($timeline ?? ['error' => 'Not an app deployment']);
            return;
        }
        if (($_GET['poll'] ?? '') === '1') {
            $this->trongate_security->_json($operation);
            return;
        }
        $crumbs = [['/apps', 'Apps']];
        if (in_array($operation['job']['kind'] ?? '', ['deploy-service', 'destroy-service'], true)) {
            $job_payload = json_decode((string) ($operation['job']['payload'] ?? ''), true) ?: [];
            if (!empty($job_payload['app'])) {
                $app_id = (string) $job_payload['app'];
                $crumbs[] = ['/apps/show/' . rawurlencode($app_id), $app_id];
                $crumbs[] = ['/apps/services/' . rawurlencode($app_id), 'Services'];
            } else {
                $crumbs[] = ['/services', 'Services'];
            }
            $svc_label = (string) ($timeline['service']['name'] ?? $job_payload['service_name'] ?? 'Service');
            $crumbs[] = ['/deployments?operation=' . rawurlencode((string) $operation['job']['id']), $svc_label];
        } elseif (!empty($timeline['app_id'])) {
            $crumbs[] = ['/apps/show/' . rawurlencode((string) $timeline['app_id']), (string) $timeline['app']];
            $crumbs[] = ['/deployments?operation=' . rawurlencode((string) $operation['job']['id']), 'Deployment'];
        } else {
            $crumbs[] = ['/deployments?operation=' . rawurlencode((string) $operation['job']['id']), 'Deployment'];
        }
        $this->templates->_render(
            'deployments',
            $timeline ? 'timeline' : 'progress',
            'deployment',
            ['operation' => $operation, 'timeline' => $timeline, 'crumbs' => $crumbs]
        );
    }

    function live(): void {
        $this->trongate_security->make_sure_allowed();
        $this->trongate_security->_csrf();
        $id = (string) ($_POST['operation'] ?? '');
        try {
            $job = $this->jobs->model->job($id);
            $target = Access_policy::job_target($job);
            $this->trongate_security->_require_app($target['app'], 'deploy', 'You do not have permission to take this app live.');
            $this->model->_go_live($id);
            header('Location: /deployments?operation=' . rawurlencode($id), true, 303);
        } catch (RuntimeException $error) {
            $this->templates->_render(
                'deployments',
                'timeline',
                'deployment',
                ['timeline' => $this->model->_timeline($id), 'message' => $error->getMessage()]
            );
        }
    }

    function change(): void {
        $this->trongate_security->make_sure_allowed();
        $this->trongate_security->_csrf();
        $raw_app = trim((string) ($_POST['app'] ?? ''));
        $this->trongate_security->_require_app($raw_app, 'deploy', 'You do not have permission to deploy or modify traffic for this app.');
        $command = (string) ($_POST['command'] ?? '');
        try {
            if (!in_array($command, ['redeploy-app', 'canary-app', 'canary-weight', 'canary-promote', 'canary-abort', 'rollback-app'], true)) {
                throw new RuntimeException('Unknown deployment action');
            }
            $this->_change($command);
        } catch (Throwable $e) {
            $this->templates->_render(
                'apps',
                'dashboard',
                'dashboard',
                ['message' => $e instanceof RuntimeException ? $e->getMessage() : 'Invalid input']
            );
        }
    }

    // Internal HMVC entry points: Core blocks underscore methods from URL dispatch.
    function _change(string $command): void {
        $app = Validate::id($_POST['app'] ?? '');
        if ($command === 'canary-app') {
            $weight = self::_parse_canary_weight($_POST['canary_weight'] ?? 10);
            $operation = $this->model->_queue('deploy', [
                'app' => $app,
                'release' => $_POST['release'] ?? '',
                'canary' => true,
                'canary_weight' => $weight,
            ]);
        } elseif ($command === 'canary-weight') {
            $weight = self::_parse_canary_weight($_POST['canary_weight'] ?? 10);
            $operation = $this->model->_queue('canary-weight', [
                'app' => $app,
                'weight' => $weight,
            ]);
        } elseif (in_array($command, ['canary-promote', 'canary-abort'], true)) {
            $operation = $this->model->_queue($command, ['app' => $app]);
        } else {
            $operation = $this->model->_queue(
                $command === 'rollback-app' ? 'rollback' : 'deploy',
                ['app' => $app, 'release' => $_POST['release'] ?? '']
            );
        }
        header('Location: /deployments?operation=' . rawurlencode($operation), true, 303);
        exit;
    }

    function _display(string $view, array $data): void {
        $data['viewData'] = $data;
        $this->view($view, $data);
    }

    function _run(Job_context $context): array {
        $kind = $context->kind;
        $id = $context->id;
        $p = $context->payload;
        $heartbeat = $context->heartbeat(...);
        $s = $this->model;
        $server = $this->records->_get(['kind' => 'server', 'id' => $p['server']]);
        $ssh = new Ssh($s);

        $inspection = $ssh->inspect($server);
        if (empty($inspection['application_ready'])) {
            return ['status' => 'failed', 'missing' => $inspection['missing'] ?? []];
        }

        $request = [
            'action' => $kind,
            'operation' => $id,
            'app' => $p['app'],
            'public_check' => $p['public_check'] ?? true,
            'defer_public' => true,
            'go_live' => $p['go_live'] ?? false,
        ];

        if ($kind === 'deploy') {
            $release = $this->records->_get(['kind' => 'release', 'id' => $p['release']])['spec'];
            $secrets = [];
            $all_services = $this->records->_all('service');
            // Inject shared server-wide services first, then app-scoped services for this app only.
            foreach ([false, true] as $want_app_scoped) {
                foreach ($all_services as $svc) {
                    if (($svc['server'] ?? '') !== $p['server'] || ($svc['status'] ?? '') !== 'running' || empty($svc['url']) || empty($svc['name'])) {
                        continue;
                    }
                    $svc_app = (string) ($svc['app'] ?? '');
                    if ($want_app_scoped ? $svc_app !== $p['app'] : $svc_app !== '') {
                        continue;
                    }
                    $secrets[Service_spec::url_variable((string) $svc['name'])] = (string) $svc['url'];
                }
            }
            foreach ($release['secrets'] ?? [] as $name => $ref) {
                $secrets[$name] = $s->secret($ref);
            }
            $file_values = [];
            foreach ($release['file_secrets'] ?? [] as $target => $ref) {
                $file_values[$target] = $s->secret($ref);
            }
            $apps_suffix = '.' . Apps_model::apps_domain();
            if (is_string($release['domain'] ?? null) && str_ends_with($release['domain'], $apps_suffix)) {
                $request['defer_public'] = false;
            }
            $request += [
                'release' => $release,
                'secret_values' => $secrets,
                'file_values' => $file_values,
            ];
            if (!empty($p['canary'])) {
                $request['canary'] = true;
                $request['canary_weight'] = self::_parse_canary_weight($p['canary_weight'] ?? 10);
            }
        }

        $last_phase = '';
        $progress = function () use ($heartbeat, $ssh, $server, $s, $id, &$last_phase): void {
            $heartbeat();
            try {
                $response = $ssh->command(
                    $server,
                    'tc-runtime',
                    self::_encode_json(['action' => 'status', 'operation' => $id]),
                    8
                );
                $state = json_decode($response['out'], true);
                if (!empty($state['phase']) && $state['phase'] !== $last_phase) {
                    $last_phase = $state['phase'];
                    $s->step($id, 'progress', ['phase' => $last_phase]);
                }
            } catch (RuntimeException) {
                // Progress is best-effort; the durable result remains authoritative.
            }
        };

        $result = $ssh->runtime($server, $request, $progress);
        if (($result['status'] ?? '') === 'succeeded') {
            if (!empty($result['canary']) && is_array($result['canary'])) {
                $this->_sync_app_canary($p['app'], [
                    'release' => (string) ($p['release'] ?? ''),
                    'operation' => (string) ($result['canary']['operation'] ?? $id),
                    'slot' => (string) ($result['canary']['slot'] ?? ''),
                    'weight' => (int) ($result['canary']['weight'] ?? ($request['canary_weight'] ?? 10)),
                    'commit' => (string) ($result['canary']['commit'] ?? ''),
                    'started_at' => (int) ($result['canary']['started_at'] ?? time()),
                ]);
            } else {
                $this->_sync_app_canary($p['app'], null);
            }
        }
        return $result;
    }

    function _canary(Job_context $context): array {
        $kind = $context->kind;
        $id = $context->id;
        $p = $context->payload;
        $heartbeat = $context->heartbeat(...);
        $s = $this->model;
        $server = $this->records->_get(['kind' => 'server', 'id' => $p['server']]);
        $ssh = new Ssh($s);

        $request = [
            'action' => $kind,
            'operation' => $id,
            'app' => $p['app'],
            'public_check' => $p['public_check'] ?? true,
        ];
        if ($kind === 'canary-weight') {
            $request['weight'] = self::_parse_canary_weight($p['weight'] ?? 10);
        }

        $result = $ssh->runtime($server, $request, $heartbeat);
        if (($result['status'] ?? '') === 'succeeded') {
            if ($kind === 'canary-weight') {
                $app_record = $this->records->_find(['kind' => 'app', 'id' => $p['app']]);
                $existing = is_array($app_record['canary'] ?? null) ? $app_record['canary'] : [];
                $this->_sync_app_canary($p['app'], array_replace($existing, [
                    'weight' => (int) ($result['weight'] ?? $request['weight']),
                    'slot' => (string) ($result['canary']['slot'] ?? ($existing['slot'] ?? '')),
                    'operation' => (string) ($result['canary']['operation'] ?? ($existing['operation'] ?? '')),
                    'commit' => (string) ($result['canary']['commit'] ?? ($existing['commit'] ?? '')),
                    'started_at' => (int) ($result['canary']['started_at'] ?? ($existing['started_at'] ?? time())),
                ]));
            } else {
                $this->_sync_app_canary($p['app'], null);
            }
        }

        return $result;
    }

    private function _sync_app_canary(string $app, ?array $canary): void {
        $record = $this->records->_find(['kind' => 'app', 'id' => $app]);
        if ($record === null) {
            return;
        }
        unset($record['id']);
        if ($canary === null) {
            unset($record['canary']);
        } else {
            $record['canary'] = $canary;
        }
        $this->records->_put(['kind' => 'app', 'id' => $app, 'data' => $record]);
    }

    private static function _parse_canary_weight(mixed $raw): int {
        if (!is_numeric($raw)) {
            throw new RuntimeException('Canary traffic weight must be a number between 0 and 90.');
        }
        $weight = (int) $raw;
        if ($weight < 0 || $weight > 90) {
            throw new RuntimeException('Canary traffic weight must be between 0% and 90%.');
        }
        return $weight;
    }

    function _destroy(Job_context $context): array {
        $id = $context->id;
        $p = $context->payload;
        $heartbeat = $context->heartbeat(...);
        $s = $this->model;
        $server = $this->records->_get(['kind' => 'server', 'id' => $p['server']]);
        $ssh = new Ssh($s);

        $request = [
            'action' => 'destroy-app',
            'operation' => $id,
            'app' => $p['app'],
            'purge_data' => (bool) ($p['purge_data'] ?? false),
        ];

        $result = $ssh->runtime($server, $request, $heartbeat);
        if (($result['status'] ?? '') === 'succeeded') {
            $this->records->_delete(['kind' => 'app', 'id' => $p['app']]);
            $this->module('team');
            $this->team->model->_prune_app((string) $p['app']);
            $this->module('services');
            foreach ($this->services->model->_all((string) $p['app']) as $svc) {
                $this->services->model->_queue_remove((string) ($svc['id'] ?? $svc['name']), (string) $p['app']);
            }
        }

        return $result;
    }

    private static function _encode_json(array $value): string {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
