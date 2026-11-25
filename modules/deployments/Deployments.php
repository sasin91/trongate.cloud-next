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
        $timeline = $this->model->_timeline($operation['job']['id']);
        if (($_GET['timeline'] ?? '') === '1') {
            $this->trongate_security->_json($timeline ?? ['error' => 'Not an app deployment']);
            return;
        }
        if (($_GET['poll'] ?? '') === '1') {
            $this->trongate_security->_json($operation);
            return;
        }
        $this->templates->_render(
            'deployments',
            $timeline ? 'timeline' : 'progress',
            'deployment',
            ['operation' => $operation, 'timeline' => $timeline]
        );
    }

    function live(): void {
        $this->trongate_security->make_sure_allowed();
        $this->trongate_security->_csrf();
        $id = (string) ($_POST['operation'] ?? '');
        try {
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
        $command = (string) ($_POST['command'] ?? '');
        try {
            if (!in_array($command, ['redeploy-app', 'rollback-app'], true)) {
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
        $operation = $this->model->_queue(
            $command === 'rollback-app' ? 'rollback' : 'deploy',
            ['app' => $app, 'release' => $_POST['release'] ?? '']
        );
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
            foreach ($release['secrets'] ?? [] as $name => $ref) {
                $secrets[$name] = $s->secret($ref);
            }
            $file_values = [];
            foreach ($release['file_secrets'] ?? [] as $target => $ref) {
                $file_values[$target] = $s->secret($ref);
            }
            $request += [
                'release' => $release,
                'secret_values' => $secrets,
                'file_values' => $file_values,
            ];
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

        return $ssh->runtime($server, $request, $progress);
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
        }

        return $result;
    }

    private static function _encode_json(array $value): string {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
