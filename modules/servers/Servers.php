<?php
declare(strict_types=1);
class Servers extends Trongate {
    function index(): void {
        $this->trongate_security->make_sure_allowed();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->action();
            return;
        }
        $this->templates->_render('servers', 'index', 'servers');
    }
    function connect(): void {
        $this->trongate_security->make_sure_allowed();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->action();
            return;
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
            } elseif (isset($_GET['provider'])) {
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
            $status = $this->providers->model->_status($_SESSION['server_operation'] ?? '');
            $_SESSION['server_status'] = $status;
            if ($status['status'] === 'succeeded') {
                $_SESSION['created_server'] = $status;
            }
        } catch (RuntimeException $error) {
            $message = $error->getMessage();
        }
        $this->templates->_render('servers', 'provision', 'server-progress', ['message' => $message]);
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
        }
        if ($inline) {
            $this->trongate_security->_json($_SESSION['enrollment']);
            return;
        }
        header('Location: /servers/confirm', true, 303);
        exit;
    }
    function _confirm(Model $store, Control $control, string $command, bool $inline): void {
        $pending = $_SESSION['enrollment'] ?? [];
        if ($inline && !hash_equals($pending['enrollment'] ?? '', (string)($_POST['enrollment'] ?? ''))) {
            throw new RuntimeException('This server confirmation expired. Connect again.');
        }
        $confirmation = ['id' => $pending['id'] ?? '', 'enrollment' => $pending['enrollment'] ?? '', 'fingerprint' => trim($_POST['fingerprint'] ?? '')];
        if (isset($pending['provider_id'])) {
            $confirmation += ['provider' => $pending['provider'], 'provider_id' => $pending['provider_id']];
        }
        $result = $control->execute('server-confirm', $confirmation);
        if ($inline) {
            unset($_SESSION['enrollment']);
            $this->trongate_security->_json($result + ['host' => $pending['host']]);
            return;
        }
        unset($_SESSION['enrollment'], $_SESSION['created_server']);
        if ($result['application_ready'] && isset($_SESSION['app_draft'])) {
            $_SESSION['draft_server'] = $result['id'] ?? $pending['id'];
        }
        header('Location: /servers', true, 303);
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
