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
            'message' => $message, 'resource_details' => $details, 'resources_saved' => $saved
        ]);
    }

    function index(): void {
        $this->trongate_security->make_sure_allowed();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->action();
            return;
        }
        $this->templates->_render('servers', 'index', 'servers');
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
        if (isset($_GET['operation'])) {
            $created = $this->providers->model->_status((string) $_GET['operation']);
            if ($created['status'] !== 'succeeded') {
                header('Location: /servers/provision?operation=' . rawurlencode($created['operation']), true, 303);
                return;
            }
            $_SESSION['created_server'] = $created;
        }
        if (isset($_SESSION['created_server'])) {
            $created = $_SESSION['created_server'];
            $_POST = ['id' => $created['name'], 'host' => $created['host'], 'provision_operation' => $created['operation']];
        }
        $this->templates->_render('servers', 'confirm', 'connect-server');
    }

    function create(): void {
        $this->trongate_security->make_sure_allowed();
        $message = null;
        try {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $this->trongate_security->_csrf();
                $command = $_POST['command'] ?? '';
                if ($command === 'review') {
                    $_SESSION['server_config'] = array_intersect_key($_POST, array_flip(['provider', 'name', 'location', 'server_type', 'image', 'ssh_key']));
                    $_SESSION['server_review'] = $this->providers->model->_quote($_SESSION['server_config']);
                } elseif ($command === 'create') {
                    $quote = $_SESSION['server_review']['quote'] ?? '';
                    if (!hash_equals($quote, (string) ($_POST['quote'] ?? '')) || $quote === '') {
                        throw new RuntimeException('Review the server configuration first.');
                    }
                    $result = $this->providers->model->_create($quote, ($_POST['confirm_cost'] ?? '') === '1');
                    $_SESSION['server_operation'] = $result['operation'];
                    header('Location: /servers/provision', true, 303);
                    return;
                } elseif ($command === 'edit') {
                    unset($_SESSION['server_review']);
                } else {
                    $_SESSION['server_config'] = ['provider' => $_POST['provider'] ?? ''];
                    unset($_SESSION['server_review']);
                }
            } elseif (isset($_GET['provider']) && ($_SESSION['server_config']['provider'] ?? '') !== $_GET['provider']) {
                $_SESSION['server_config'] = ['provider' => $_GET['provider']];
                unset($_SESSION['server_review']);
            }
            unset($_SESSION['server_options']);
            $provider = $_SESSION['server_config']['provider'] ?? '';
            $_SESSION['server_options'] = $provider !== '' ? $this->providers->model->_options($provider) : null;
        } catch (RuntimeException $error) {
            $message = $error->getMessage();
        }
        $this->templates->_render('servers', 'create', 'create-server', ['message' => $message]);
    }

    function provision(): void {
        $this->trongate_security->make_sure_allowed();
        $message = null;
        try {
            unset($_SESSION['server_status']);
            $status = $this->providers->model->_creation_progress((string) ($_GET['operation'] ?? $_SESSION['server_operation'] ?? ''));
            $_SESSION['server_operation'] = $status['operation'];
            $_SESSION['server_status'] = $status;
            if ($status['status'] === 'succeeded') {
                $_SESSION['created_server'] = $status;
            }
        } catch (RuntimeException $error) {
            if (($_GET['poll'] ?? '') === '1') {
                $this->trongate_security->_json(['error' => 'Server creation not found.'], 404);
                return;
            }
            $message = $error->getMessage();
        }
        if (($_GET['poll'] ?? '') === '1') {
            $this->trongate_security->_json($status);
            return;
        }
        $this->templates->_render('servers', 'provision', 'server-progress', ['message' => $message]);
    }

    function delete(): void {
        $this->trongate_security->make_sure_allowed();
        $message = null;
        $operation = (string) ($_POST['operation'] ?? $_GET['operation'] ?? '');
        try {
            $status = $this->providers->model->_status($operation);
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $this->providers->model->_delete_created_server($operation, (string) ($_POST['name'] ?? ''));
                unset($_SESSION['created_server'], $_SESSION['enrollment'], $_SESSION['server_review'], $_SESSION['server_config'], $_SESSION['server_options'], $_SESSION['server_operation'], $_SESSION['server_status']);
                header('Location: /servers/delete?operation=' . rawurlencode($operation), true, 303);
                return;
            }
        } catch (RuntimeException $error) {
            $message = $error->getMessage();
        }
        $this->templates->_render('servers', 'delete', 'delete-server', ['message' => $message, 'deleting_server' => $status ?? null]);
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
        $store = $this->model;
        $control = new Control($store);
        $inline = ($_POST['inline'] ?? '') === 'server';
        $command = (string)($_POST['command'] ?? '');
        try {
            match ($command) {
                'connect-server' => $this->_connect($store, $control, $command, $inline),
                'confirm-server' => $this->_confirm($store, $control, $command, $inline),
                'inspect-connected-server' => $this->_inspect($store, $control, $command, $inline),
                default => throw new RuntimeException('Unknown server action')
            };
        } catch (Throwable $e) {
            $message = $e instanceof RuntimeException?$e->getMessage():'Invalid input';
            if ($inline) {
                $this->trongate_security->_json(['error' => $message], 422);
            } else {
                $this->templates->_render('servers', 'confirm', 'confirm-server', ['message' => $message]);
            }
        }
    }
    // Internal HMVC entry points: Core blocks underscore methods from URL dispatch.
    function _connect(Model $store, Control $control, string $command, bool $inline): void {
        $id = Validate::id($_POST['id'] ?? '');
        $created = [];
        if (!empty($_POST['provision_operation'])) {
            $created = $this->providers->model->_status($_POST['provision_operation']);
            if ($created['status'] !== 'succeeded') {
                throw new RuntimeException('Wait for server creation to finish.');
            }
            if ($created['host'] !== ($_POST['host'] ?? '')) {
                throw new RuntimeException('Use the address returned by the provider.');
            }
        }
        $server = Validate::server(['host' => $_POST['host'] ?? '', 'port' => $_POST['port'] ?? 22, 'user' => $_POST['user'] ?? 'root', 'credential' => 'ssh-' . substr(hash('sha256', $id), 0, 32)]);
        foreach ($store->all('server') as $existing) {
            if ($existing['id'] === $id) {
                throw new RuntimeException('Server name is already in use.');
            }
        }
        if (trim($_POST['private_key'] ?? '') === '') {
            throw new RuntimeException('Provide the SSH private key for this server.');
        }
        $store->secret($server['credential'], $_POST['private_key']);
        $_SESSION['enrollment'] = $control->execute('server-scan', $server) + ['id' => $id, 'host' => $server['host']];
        if ($created) {
            $_SESSION['enrollment'] += ['provider' => $created['provider'], 'provider_id' => $created['provider_id']];
            $_SESSION['enrollment']['expected_os'] = 'nixos';
        }
        $_POST['enrollment'] = $_SESSION['enrollment']['enrollment'];
        $this->_confirm($store, $control, 'confirm-server', $inline);
    }
    function _confirm(Model $store, Control $control, string $command, bool $inline): void {
        $pending = $_SESSION['enrollment'] ?? [];
        if ($inline && !hash_equals($pending['enrollment'] ?? '', (string)($_POST['enrollment'] ?? ''))) {
            throw new RuntimeException('This server confirmation expired. Connect again.');
        }
        $confirmation = ['id' => $pending['id'] ?? '', 'enrollment' => $pending['enrollment'] ?? '', 'fingerprint' => $pending['fingerprint'] ?? ''];
        if (isset($pending['provider_id'])) {
            $created = $store->get('provider-server', (string) $pending['provider_id']);
            if (isset($created['operation']) && $this->providers->model->_status($created['operation'])['status'] !== 'succeeded') {
                throw new RuntimeException('This server is being deleted or has been deleted. Create a replacement from Servers.');
            }
            $confirmation += ['provider' => $pending['provider'], 'provider_id' => $pending['provider_id']];
        }
        if (isset($pending['expected_os'])) {
            $confirmation['expected_os'] = $pending['expected_os'];
        }
        $result = $control->execute('server-confirm', $confirmation);
        $this->apps->_restore_draft();
        $destination = '/servers';
        if ($result['application_ready'] && isset($_SESSION['app_draft'])) {
            $_SESSION['draft_server'] = $result['id'] ?? $pending['id'];
            $destination = '/apps/create';
        }
        if ($inline) {
            unset($_SESSION['enrollment'], $_SESSION['created_server']);
            $this->trongate_security->_json($result + ['host' => $pending['host']]);
            return;
        }
        unset($_SESSION['enrollment'], $_SESSION['created_server']);
        header('Location: ' . $destination, true, 303);
        exit;
    }
    function _inspect(Model $store, Control $control, string $command, bool $inline): void {
        $id = Validate::id($_POST['server'] ?? '');
        $result = $control->execute('server-inspect', ['server' => $id]);
        if (!$inline) {
            if ($result['application_ready'] && isset($_SESSION['app_draft'])) {
                $_SESSION['draft_server'] = $id;
            }
            header('Location: /servers', true, 303);
            return;
        }
        $this->trongate_security->_json($result + ['id' => $id, 'host' => $store->get('server', $id)['host']]);
        return;
    }
    function _display(string $view, array $data): void {
        $data['viewData'] = $data;
        $this->view($view, $data);
    }
}
