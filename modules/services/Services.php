<?php
declare(strict_types=1);

class Services extends Trongate {
    function index(): void {
        $this->trongate_security->make_sure_allowed();
        $this->trongate_security->_require_workspace('services.view', 'You do not have permission to view shared services.');
        $actor = $this->trongate_security->_actor();
        $flash = $_SESSION['services_flash'] ?? null;
        unset($_SESSION['services_flash']);
        $services = array_values(array_filter(
            $this->model->_all(),
            static function (array $svc) use ($actor): bool {
                $svc_app = (string) ($svc['app'] ?? '');
                return $svc_app === ''
                    ? Access_policy::can_workspace($actor, 'services.view')
                    : Access_policy::can_app($actor, $svc_app, 'services_view');
            }
        ));
        $this->templates->_render('services', 'index', 'services', [
            'services' => $services,
            'presets' => Service_spec::presets(),
            'can_add_service' => Access_policy::can_workspace($actor, 'services.manage') && $this->model->_can_add(),
            'message' => is_string($flash) ? $flash : null,
        ]);
    }

    function add(): void {
        $this->trongate_security->make_sure_allowed();
        $this->trongate_security->_csrf();
        $app_raw = trim((string) ($_POST['app'] ?? ''));
        if ($app_raw !== '') {
            $this->trongate_security->_require_app($app_raw, 'services_manage', 'You do not have permission to manage services for this app.');
        } else {
            $this->trongate_security->_require_workspace('services.manage', 'You do not have permission to manage shared services.');
        }
        try {
            $created = $this->model->_add($_POST);
            $var = Service_spec::url_variable($created['name']);
            $target = $created['app'] !== null
                ? $created['app'] . ' to give it ' . $var . '.'
                : 'your apps to give them ' . $var . '.';
            $_SESSION['services_flash'] = $created['name'] . ' is starting. Deploy or redeploy ' . $target;
            header('Location: /deployments?operation=' . rawurlencode($created['operation']), true, 303);
            exit;
        } catch (Throwable $e) {
            $message = $e instanceof RuntimeException ? $e->getMessage() : 'Could not add service.';
            if ($app_raw !== '') {
                try {
                    $this->module('apps');
                    $overview = $this->apps->model->_overview($app_raw);
                    $app = $overview['app'];
                    $app_id = (string) $app['id'];
                    $this->templates->_render('apps', 'services', 'app-services', [
                        'overview' => $overview,
                        'services' => $overview['services'],
                        'presets' => Service_spec::presets(),
                        'can_add_service' => $this->model->_can_add((string) ($app['server'] ?? '')),
                        'message' => $message,
                        'crumbs' => [
                            ['/apps', 'Apps'],
                            ['/apps/show/' . rawurlencode($app_id), (string) ($app['label'] ?? $app_id)],
                            ['/apps/services/' . rawurlencode($app_id), 'Services'],
                        ],
                    ]);
                    return;
                } catch (Throwable) {
                    // Fall through to global services view if app cannot be loaded.
                }
            }
            $this->templates->_render('services', 'index', 'services', [
                'services' => $this->model->_all(),
                'presets' => Service_spec::presets(),
                'can_add_service' => $this->model->_can_add(),
                'message' => $message,
            ]);
        }
    }

    function redeploy(): void {
        $this->trongate_security->make_sure_allowed();
        $this->trongate_security->_csrf();
        $app_raw = trim((string) ($_POST['app'] ?? ''));
        $service_id = trim((string) ($_POST['service'] ?? ''));
        $existing = $service_id !== '' ? $this->records->_find(['kind' => 'service', 'id' => $service_id]) : null;
        $owner_app = is_array($existing) ? (string) ($existing['app'] ?? '') : $app_raw;
        if ($owner_app !== '') {
            $this->trongate_security->_require_app($owner_app, 'services_manage', 'You do not have permission to manage services for this app.');
        } else {
            $this->trongate_security->_require_workspace('services.manage', 'You do not have permission to manage shared services.');
        }
        try {
            $operation = $this->model->_queue_redeploy(
                $service_id,
                $app_raw !== '' ? $app_raw : null
            );
            header('Location: /deployments?operation=' . rawurlencode($operation), true, 303);
            exit;
        } catch (Throwable $e) {
            $error = $e instanceof RuntimeException ? $e->getMessage() : 'Could not redeploy service.';
            if ($app_raw !== '') {
                $_SESSION['services_error'] = $error;
                header('Location: /apps/services/' . rawurlencode($app_raw), true, 303);
            } else {
                $_SESSION['services_flash'] = $error;
                header('Location: /services', true, 303);
            }
            exit;
        }
    }

    function remove(): void {
        $this->trongate_security->make_sure_allowed();
        $this->trongate_security->_csrf();
        $app_raw = trim((string) ($_POST['app'] ?? ''));
        $service_id = trim((string) ($_POST['service'] ?? ''));
        $existing = $service_id !== '' ? $this->records->_find(['kind' => 'service', 'id' => $service_id]) : null;
        $owner_app = is_array($existing) ? (string) ($existing['app'] ?? '') : $app_raw;
        if ($owner_app !== '') {
            $this->trongate_security->_require_app($owner_app, 'services_manage', 'You do not have permission to manage services for this app.');
        } else {
            $this->trongate_security->_require_workspace('services.manage', 'You do not have permission to manage shared services.');
        }
        try {
            $operation = $this->model->_queue_remove(
                $service_id,
                $app_raw !== '' ? $app_raw : null
            );
            header('Location: /deployments?operation=' . rawurlencode($operation), true, 303);
            exit;
        } catch (Throwable $e) {
            $error = $e instanceof RuntimeException ? $e->getMessage() : 'Could not remove service.';
            if ($app_raw !== '') {
                $_SESSION['services_error'] = $error;
                header('Location: /apps/services/' . rawurlencode($app_raw), true, 303);
            } else {
                $_SESSION['services_flash'] = $error;
                header('Location: /services', true, 303);
            }
            exit;
        }
    }

    // Internal HMVC entry points: Core blocks underscore methods from URL dispatch.
    function _display(string $view, array $data): void {
        $data['viewData'] = $data;
        $this->view($view, $data);
    }

    function _deploy(Job_context $context): array {
        $id = $context->id;
        $p = $context->payload;
        $heartbeat = $context->heartbeat(...);
        $service_id = (string) ($p['service'] ?? '');
        $record = $this->records->_get(['kind' => 'service', 'id' => $service_id]);
        $server = $this->records->_get(['kind' => 'server', 'id' => $p['server']]);
        $runtime_name = (string) ($record['runtime_name'] ?? $p['runtime_name'] ?? $service_id);

        $env = [];
        foreach ($record['env_refs'] ?? [] as $key => $ref) {
            $env[$key] = $this->model->secret((string) $ref);
        }

        $request = [
            'action' => 'deploy-service',
            'operation' => $id,
            'service' => [
                'name' => $runtime_name,
                'packages' => $record['packages'] ?? [],
                'setup' => $record['setup'] ?? '',
                'command' => $record['command'],
                'port' => (int) ($record['port'] ?? Service_spec::DEFAULT_PORT),
                'memory_mb' => (int) ($record['memory_mb'] ?? Service_spec::DEFAULT_MEMORY_MB),
            ],
            'env' => $env,
        ];

        $ssh = new Ssh($this->model);
        $last_phase = '';
        $s = $this->model;
        $progress = function () use ($heartbeat, $ssh, $server, $s, $id, &$last_phase): void {
            $heartbeat();
            try {
                $response = $ssh->command(
                    $server,
                    'tc-runtime',
                    json_encode(['action' => 'status', 'operation' => $id], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    8
                );
                $state = json_decode($response['out'], true);
                if (!empty($state['phase']) && $state['phase'] !== $last_phase) {
                    $last_phase = (string) $state['phase'];
                    $s->step($id, 'progress', ['phase' => $last_phase]);
                }
            } catch (RuntimeException) {
                // Progress is best-effort; the durable result remains authoritative.
            }
        };

        $result = $ssh->runtime($server, $request, $progress);
        $this->model->_mark_deployed($service_id, $result);
        return $result;
    }

    function _destroy(Job_context $context): array {
        $id = $context->id;
        $p = $context->payload;
        $heartbeat = $context->heartbeat(...);
        $service_id = (string) ($p['service'] ?? '');
        $record = $this->records->_find(['kind' => 'service', 'id' => $service_id]);
        $runtime_name = (string) ($p['runtime_name'] ?? $record['runtime_name'] ?? $service_id);
        $server = $this->records->_get(['kind' => 'server', 'id' => $p['server']]);

        $result = (new Ssh($this->model))->runtime($server, [
            'action' => 'destroy-service',
            'operation' => $id,
            'name' => $runtime_name,
        ], $heartbeat);

        if (($result['status'] ?? '') === 'succeeded') {
            $this->model->_delete_record($service_id);
        }
        return $result;
    }
}
