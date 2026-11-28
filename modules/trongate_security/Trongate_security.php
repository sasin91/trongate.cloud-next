<?php
declare(strict_types=1);

/** Delegates access scenarios and provides shared request-response helpers. */

class Trongate_security extends Trongate {
    /**
     * Class constructor.
     *
     * Prevents direct URL access to the security module while allowing
     * internal security checks via application code.
     *
     * @param string|null $module_name The module name (auto-provided by framework)
     */
    public function __construct(?string $module_name = null) {
        parent::__construct($module_name);
        block_url('trongate_security');
    }

    public function make_sure_allowed(string $scenario = 'admin panel', array $params = []): ?string {
        switch ($scenario) {
            case 'admin panel':
            case 'trongate_administrators':
                return $this->trongate_administrators->make_sure_allowed();
            case 'apps':
                $this->apps->make_sure_allowed();
                break;
            case 'deployments':
                $this->deployments->make_sure_allowed();
                break;
            case 'github':
                $this->module('login-github');
                $this->github->make_sure_allowed();
                break;
            case 'jobs':
                $this->jobs->make_sure_allowed();
                break;
            case 'servers':
                $this->servers->make_sure_allowed();
                break;
            case 'services':
                $this->services->make_sure_allowed();
                break;
            case 'harbour':
                $this->workspace->_require_workspace(
                    'registry.manage',
                    'You do not have permission to manage the Module Harbour.'
                );
                break;
            case 'team':
                $this->team->make_sure_allowed();
                break;
            default:
                throw new InvalidArgumentException('Unknown access scenario: ' . $scenario);
        }
        return null;
    }

    function _forbidden(string $message = 'You do not have permission to perform this action.'): never {
        if (
            from_trongate_mx()
            || ($_POST['inline'] ?? '') === 'server'
            || ($_GET['poll'] ?? '') === '1'
            || ($_GET['timeline'] ?? '') === '1'
        ) {
            $this->_json(['error' => $message], 403);
            exit;
        }
        http_response_code(403);
        $this->workspace->_render_panel('team', 'forbidden', 'forbidden', [
            'message' => $message,
            'crumbs' => [['/apps', 'Apps']],
        ]);
        exit;
    }

    function _csrf(): void {
        if (
            $_SERVER['REQUEST_METHOD'] !== 'POST'
            || empty($_SESSION['csrf_token'])
            || !hash_equals($_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))
        ) {
            http_response_code(403);
            exit('Invalid request token');
        }
    }

    function _json(array $data, int $status = 200): void {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_THROW_ON_ERROR);
    }

}