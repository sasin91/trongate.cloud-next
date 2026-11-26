<?php
declare(strict_types=1);

/** Application access scenario backed by Trongate's bundled login/token modules and granular team policy. */
class Trongate_security extends Trongate {
    private ?string $cached_token = null;
    private ?array $cached_actor = null;

    function __construct(?string $module_name = null) {
        parent::__construct($module_name);
        block_url('trongate_security');
    }

    function make_sure_allowed(string $scenario = 'admin panel', array $params = []): string {
        $token = $this->trongate_tokens->attempt_get_valid_token([1, 2]);
        if ($token === false) {
            $this->_reject_unauthenticated();
        }

        $actor = $this->_resolve_actor($token);
        if ($actor === null) {
            $this->trongate_tokens->destroy();
            $this->_reject_unauthenticated();
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->_csrf();
        }
        return $token;
    }

    function _actor(): array {
        $token = $this->trongate_tokens->attempt_get_valid_token([1, 2]);
        if ($token === false) {
            $this->_reject_unauthenticated();
        }
        $actor = $this->_resolve_actor($token);
        if ($actor === null) {
            $this->trongate_tokens->destroy();
            $this->_reject_unauthenticated();
        }
        return $actor;
    }

    function _optional_actor(): ?array {
        $token = $this->trongate_tokens->attempt_get_valid_token([1, 2]);
        if ($token === false) {
            return null;
        }
        return $this->_resolve_actor($token);
    }

    function _require_workspace(string $permission, string $message = 'You do not have permission to access this area.'): void {
        if (!Access_policy::can_workspace($this->_actor(), $permission)) {
            $this->_forbidden($message);
        }
    }

    function _require_app(string $app_id, string $permission = 'view', string $message = 'You do not have permission to access this application.'): void {
        if (!Access_policy::can_app($this->_actor(), $app_id, $permission)) {
            $this->_forbidden($message);
        }
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
        $this->templates->_render('team', 'forbidden', 'forbidden', [
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

    private function _resolve_actor(string $token): ?array {
        if ($this->cached_token === $token && $this->cached_actor !== null) {
            return $this->cached_actor;
        }
        $user = $this->trongate_tokens->get_user_obj($token);
        if ($user === false) {
            return null;
        }
        $level = (int) ($user->user_level_id ?? 0);
        $user_id = (int) ($user->trongate_user_id ?? 0);
        $this->module('team');

        if ($level === 1) {
            $admin = $this->team->model->_administrator_by_user_id($user_id);
            $label = is_array($admin) ? (string) ($admin['username'] ?? 'Administrator') : 'Administrator';
            $actor = Access_policy::owner($user_id, $label);
        } elseif ($level === 2) {
            $member = $this->team->model->_find_member_by_user_id($user_id);
            if ($member === null || !$member['active']) {
                return null;
            }
            $actor = Access_policy::from_member($member);
        } else {
            return null;
        }

        $this->cached_token = $token;
        $this->cached_actor = $actor;
        return $actor;
    }

    private function _reject_unauthenticated(): never {
        if (from_trongate_mx() || ($_POST['inline'] ?? '') === 'server' || ($_GET['poll'] ?? '') === '1') {
            $this->_json(['error' => 'Your session expired. Sign in again.'], 401);
            exit;
        }
        redirect('login/login/1');
        exit;
    }
}
