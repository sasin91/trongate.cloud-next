<?php
declare(strict_types=1);

class Servers extends Trongate {
    function details(): void {
        $this->trongate_security->make_sure_allowed();
        $message = null;
        $details = null;
        $saved = false;
        try {
            $id = Validate::id((string) ($_GET['server'] ?? ''));
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $this->trongate_security->_csrf();
                $details = $this->model->_resources($id, $_POST);
                $saved = true;
            } else {
                $details = $this->model->_resources($id);
            }
        } catch (RuntimeException $error) {
            http_response_code(from_trongate_mx() ? 200 : 422);
            $message = $error->getMessage();
            if (isset($id)) {
                try {
                    $details = $this->model->_resources($id);
                } catch (Throwable) {
                    // An unreachable server must not display stale editable limits.
                }
            }
        }
        $this->templates->_render('servers', 'details', 'server-details', [
            'message' => $message,
            'resource_details' => $details,
            'resources_saved' => $saved,
        ]);
    }

    function index(): void {
        $this->trongate_security->make_sure_allowed();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->action();
            return;
        }
        $capacities = [];
        foreach ($this->records->_all('server') as $server) {
            $capacities[$server['id']] = $this->monitoring->model->_summary($server['id']);
        }
        $this->templates->_render('servers', 'index', 'servers', [
            'capacities' => $capacities,
        ]);
    }

    function builds(): void {
        $this->trongate_security->make_sure_allowed();
        $this->templates->_render('servers', 'builds', 'build-servers');
    }

    function connect(): void {
        $this->trongate_security->make_sure_allowed();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->action();
            return;
        }
        $this->templates->_render('servers', 'confirm', 'connect-server');
    }

    function confirm(): void {
        $this->trongate_security->make_sure_allowed();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->action();
            return;
        }
        $this->templates->_render('servers', 'confirm', 'confirm-server');
    }

    function action(): void {
        $this->trongate_security->make_sure_allowed();
        $this->trongate_security->_csrf();
        $model = $this->model;
        $inline = ($_POST['inline'] ?? '') === 'server';
        $command = (string) ($_POST['command'] ?? '');
        try {
            match ($command) {
                'connect-server' => $this->_connect($model, $command, $inline),
                'confirm-server' => $this->_confirm($model, $command, $inline),
                'inspect-connected-server' => $this->_inspect($model, $command, $inline),
                default => throw new RuntimeException('Unknown server action'),
            };
        } catch (Throwable $e) {
            $message = $e instanceof RuntimeException ? $e->getMessage() : 'Invalid input';
            if ($inline) {
                $this->trongate_security->_json(['error' => $message], 422);
            } else {
                $this->templates->_render('servers', 'confirm', 'confirm-server', ['message' => $message]);
            }
        }
    }

    // Internal HMVC entry points: Core blocks underscore methods from URL dispatch.
    function _connect(Model $model, string $command, bool $inline): void {
        $id = Validate::id($_POST['id'] ?? '');
        $server = Validate::server([
            'host' => $_POST['host'] ?? '',
            'port' => $_POST['port'] ?? 22,
            'user' => $_POST['user'] ?? 'tc-agent',
            'credential' => 'ssh-' . substr(hash('sha256', $id), 0, 32),
        ]);
        foreach ($this->records->_all('server') as $existing) {
            if ($existing['id'] === $id) {
                throw new RuntimeException('Server name is already in use.');
            }
        }
        if (trim($_POST['private_key'] ?? '') === '') {
            throw new RuntimeException('Provide the SSH private key for this server.');
        }
        $model->secret($server['credential'], $_POST['private_key']);
        $_SESSION['enrollment'] = $this->_scan_server($server) + ['id' => $id, 'host' => $server['host']];
        $_POST['enrollment'] = $_SESSION['enrollment']['enrollment'];
        $this->_confirm($model, 'confirm-server', $inline);
    }

    function _confirm(Model $model, string $command, bool $inline): void {
        $pending = $_SESSION['enrollment'] ?? [];
        if ($inline && !hash_equals($pending['enrollment'] ?? '', (string) ($_POST['enrollment'] ?? ''))) {
            throw new RuntimeException('This server confirmation expired. Connect again.');
        }
        $confirmation = [
            'id' => $pending['id'] ?? '',
            'enrollment' => $pending['enrollment'] ?? '',
            'fingerprint' => $pending['fingerprint'] ?? '',
        ];
        if (isset($pending['expected_os'])) {
            $confirmation['expected_os'] = $pending['expected_os'];
        }
        $result = $this->_confirm_server($confirmation);
        $this->apps->_restore_draft();
        $destination = '/servers';
        if ($result['application_ready'] && isset($_SESSION['app_draft'])) {
            $_SESSION['draft_server'] = $result['id'];
            $destination = '/apps/create';
        }
        if ($inline) {
            unset($_SESSION['enrollment']);
            $this->trongate_security->_json($result + ['host' => $pending['host']]);
            return;
        }
        unset($_SESSION['enrollment']);
        header('Location: ' . $destination, true, 303);
        exit;
    }

    function _inspect(Model $model, string $command, bool $inline): void {
        $id = Validate::id($_POST['server'] ?? '');
        $result = $this->_inspect_server(['server' => $id]);
        if (!$inline) {
            if ($result['application_ready'] && isset($_SESSION['app_draft'])) {
                $_SESSION['draft_server'] = $id;
            }
            header('Location: /servers', true, 303);
            return;
        }
        $this->trongate_security->_json(
            $result + ['id' => $id, 'host' => $this->records->_get(['kind' => 'server', 'id' => $id])['host']]
        );
    }

    function _display(string $view, array $data): void {
        $data['viewData'] = $data;
        $this->view($view, $data);
    }

    function _scan_server(array $p): array {
        $s = $this->model;
        $server = Validate::server($p);
        $identity = (new Ssh($s))->scan($server);
        $id = bin2hex(random_bytes(16));
        $this->records->_put([
            'kind' => 'enrollment',
            'id' => $id,
            'data' => [
                'server' => $server,
                'identity' => $identity,
                'expires' => time() + 900,
            ],
        ]);
        return ['enrollment' => $id] + $identity + [
            'instruction' => 'Verify fingerprint using an independent server console, then server-confirm.',
        ];
    }

    function _confirm_server(array $p): array {
        $s = $this->model;
        $pending = $this->records->_get(['kind' => 'enrollment', 'id' => $p['enrollment']]);
        if ($pending['expires'] < time() || !hash_equals($pending['identity']['fingerprint'], $p['fingerprint'])) {
            throw new RuntimeException('Expired enrollment or fingerprint mismatch');
        }
        $id = Validate::id($p['id']);
        foreach ($this->records->_all('server') as $existing) {
            if ($existing['id'] === $id || $existing['host_key'] === $pending['identity']['key']) {
                throw new RuntimeException('Server identity already enrolled; do not create aliases for the same host');
            }
        }
        $server = $pending['server'] + ['host_key' => $pending['identity']['key']];
        $inspection = (new Ssh($s))->inspect($server);
        if (($p['expected_os'] ?? '') === 'freebsd' && ($inspection['facts']['os'] ?? '') !== 'freebsd') {
            throw new RuntimeException('The target server is not running FreeBSD. Check the prepared host before enrolling it.');
        }
        $this->records->_put(['kind' => 'server', 'id' => $id, 'data' => $server + $inspection]);
        return ['id' => $id] + $inspection;
    }

    function _inspect_server(array $p): array {
        $s = $this->model;
        $server = $this->records->_get(['kind' => 'server', 'id' => $p['server']]);
        $inspection = (new Ssh($s))->inspect($server);
        $this->records->_put(['kind' => 'server', 'id' => $p['server'], 'data' => array_replace($server, $inspection)]);
        return $inspection;
    }

    function scan(): void {
        $this->trongate_security->make_sure_allowed();
        $this->trongate_security->_csrf();
        $this->trongate_security->_json($this->_scan_server($_POST));
    }
}
