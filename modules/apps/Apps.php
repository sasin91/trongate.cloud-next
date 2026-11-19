<?php
declare(strict_types=1);
class Apps extends Trongate {
    function index(): void {
        $this->trongate_security->make_sure_allowed();
        $this->templates->_render('apps', 'dashboard', 'dashboard');
    }
    function create(): void {
        $this->trongate_security->make_sure_allowed();
        $this->_restore_draft();
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_SESSION['app_draft']) && isset($_SESSION['launch_complete'])) {
            unset($_SESSION['launch_request'], $_SESSION['launch_complete']);
        }
        $_SESSION['launch_request'] ??= bin2hex(random_bytes(16));
        $message = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                $store = $this->model;
                $this->_launch($store, new Control($store), 'launch-app', false);
            } catch (Throwable $e) {
                $message = $e instanceof RuntimeException?$e->getMessage():'Invalid input';
            }
        } elseif (isset($_SESSION['app_draft'])) {
            $_POST = json_decode(Encryptor::decrypt($_SESSION['app_draft'], installation_key()), true, 512, JSON_THROW_ON_ERROR);
            if (isset($_SESSION['draft_server'])) {
                $_POST['server'] = $_SESSION['draft_server'];
                unset($_SESSION['draft_server']);
            }
        }
        $this->templates->_render('apps', 'wizard', 'new-app', ['message' => $message]);
    }
    function draft(): void {
        $this->trongate_security->make_sure_allowed();
        $this->trongate_security->_csrf();
        $fields = ['source', 'repository', 'image', 'branch', 'environment', 'label', 'domain', 'server', 'base', 'build_commands', 'php_extensions', 'health', 'container_port', 'memory_mb', 'cpus', 'persistent_paths', 'wizard_step'];
        $draft = array_intersect_key($_POST, array_flip($fields));
        if (strlen(tc_json($draft)) > 131072) {
            $this->trongate_security->_json(['error' => 'Draft is too large.'], 422);
            return;
        }
        $_SESSION['app_draft'] = Encryptor::encrypt(tc_json($draft), installation_key());
        $_SESSION['launch_request'] ??= bin2hex(random_bytes(16));
        $this->model->_save_draft($this->_draft_owner(), $_SESSION['app_draft'], $_SESSION['launch_request']);
        $this->trongate_security->_json(['saved' => true]);
    }

    function _draft_owner(): int {
        $user = $this->trongate_tokens->get_user_id();
        if ($user === false) {
            throw new RuntimeException('Sign in to save your app setup.');
        }
        return $user;
    }

    function _restore_draft(): void {
        if (isset($_SESSION['app_draft'])) {
            return;
        }
        $draft = $this->model->_load_draft($this->_draft_owner());
        if ($draft !== null) {
            $_SESSION['app_draft'] = $draft['encrypted'];
            $_SESSION['launch_request'] = $draft['request'];
        }
    }

    // Internal HMVC entry points: Core blocks underscore methods from URL dispatch.
    function _launch(Model $store, Control $control, string $command, bool $inline): void {
        if (!hash_equals($_SESSION['launch_request'], (string)($_POST['request'] ?? ''))) {
            throw new RuntimeException('This launch form has expired. Reload and try again.');
        }
        $operation = $this->model->_plan_launch($_POST, $_SESSION['launch_request']);
        $this->model->_delete_draft($this->_draft_owner());
        unset($_SESSION['app_draft']);
        $_SESSION['launch_complete'] = true;
        header('Location: /deployments?operation=' . rawurlencode($operation), true, 303);
        exit;
    }
    function _display(string $view, array $data): void {
        $data['viewData'] = $data;
        $this->view($view, $data);
    }
}
