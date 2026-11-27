<?php
declare(strict_types=1);

require_once APPPATH . 'modules/trongate_security/Trongate_security.php';

class Workspace extends Trongate {
    private ?string $cached_token = null;
    private ?array $cached_actor = null;

    function request_error(): void {
        echo '<p class="notice" role="alert">Could not refresh. <a href="/login">Sign in</a> or reload to retry.</p>';
    }

    public function index(): void {
        if ($this->_optional_actor() === null) {
            redirect('login');
            return;
        }
        redirect('apps');
        return;
    }

    function _actor(): array {
        $token = $this->trongate_tokens->attempt_get_valid_token(2);
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
        $token = $this->trongate_tokens->attempt_get_valid_token(2);
        return $token === false ? null : $this->_resolve_actor($token);
    }

    function _render_panel(string $content_module, string $view_file, string $page, array $data = []): void {
        $actor = $this->_optional_actor();
        $this->module($content_module);

        if (from_trongate_mx() && in_array($page, ['server-details', 'deployment'], true)) {
            $_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
            $inventory = [];
            require APPPATH . 'modules/templates/views/_helpers.php';
            $data += [
                'page' => $page,
                'message' => null,
                'resources_saved' => false,
                'actor' => $actor,
            ];
            $this->{$content_module}->_display($view_file, $data + compact('e', 'value', 'csrf', 'field', 'ready'));
            return;
        }

        $_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
        $can_create_apps = $actor !== null && $this->can_workspace($actor, 'apps.create');
        $this->module('apps');
        if ($can_create_apps) {
            $this->apps->_restore_draft();
        }
        $this->module('jobs');
        $this->module('services');
        $this->module('deployments');

        $all_apps = Modules::run('records/_all', 'app');
        $all_releases = Modules::run('records/_all', 'release');
        $all_services = Modules::run('records/_all', 'service');
        $all_servers = Modules::run('records/_all', 'server');
        $all_jobs = $this->jobs->model->_recent();

        $visible_apps = [];
        $visible_releases = [];
        $visible_services = [];
        $visible_servers = [];
        $visible_jobs = [];
        if ($actor !== null) {
            $visible_apps = $this->apps->visible_apps($actor, $all_apps);
            $visible_app_ids = array_flip(array_map(
                static fn(array $app): string => (string) ($app['id'] ?? ''),
                $visible_apps
            ));
            $visible_releases = array_values(array_filter(
                $all_releases,
                static fn(array $release): bool => isset($visible_app_ids[(string) ($release['app'] ?? '')])
            ));
            $visible_services = array_values(array_filter(
                $all_services,
                function (array $service) use ($actor): bool {
                    $app_id = (string) ($service['app'] ?? '');
                    return $app_id !== ''
                        ? $this->apps->can_app($actor, $app_id, 'services_view')
                        : $this->can_workspace($actor, 'services.view');
                }
            ));
            $visible_servers = $this->can_workspace($actor, 'servers.manage') ? $all_servers : [];
            $visible_jobs = array_values(array_filter(
                $all_jobs,
                fn(array $job): bool => $this->deployments->_can_view_job($actor, $job)
            ));
        }

        $data += [
            'page' => $page,
            'operation' => null,
            'signedIn' => $actor !== null,
            'actor' => $actor,
            'message' => null,
            'inventory' => [
                'servers' => $visible_servers,
                'apps' => $visible_apps,
                'releases' => $visible_releases,
                'services' => $visible_services,
            ],
            'jobs' => $visible_jobs,
            'pending_servers' => [],
            'deleting_server' => null,
            'timeline' => null,
            'resource_details' => null,
            'resources_saved' => false,
            'content_module' => $content_module,
            'view_file' => $view_file,
            'can_services_nav' => $actor !== null && $this->can_workspace($actor, 'services.view'),
            'can_jobs_nav' => $actor !== null && $this->jobs->_can_access_page($actor),
            'can_team_nav' => $actor !== null && $this->can_workspace($actor, 'team.manage'),
            'can_create_apps' => $can_create_apps,
            'view_module' => 'templates',
        ];
        $this->view('panel', $data);
    }

    function _from_member(array $row): array {
        $role = ($row['role'] ?? 'member') === 'admin' ? 'admin' : 'member';
        $scope = ($row['scope'] ?? 'scoped') === 'all' ? 'all' : 'scoped';
        $raw = is_string($row['permissions'] ?? null)
            ? (json_decode($row['permissions'], true) ?: [])
            : (is_array($row['permissions'] ?? null) ? $row['permissions'] : []);
        $this->module('team');
        $permissions = $this->team->normalize_permissions($raw, $role, $scope);

        return [
            'user_id' => (int) ($row['trongate_user_id'] ?? 0),
            'member_id' => (int) ($row['id'] ?? 0),
            'is_owner' => false,
            'is_admin' => $role === 'admin',
            'role' => $role,
            'scope' => $role === 'admin' ? 'all' : $scope,
            'label' => trim((string) ($row['name'] ?? '')) !== '' ? (string) $row['name'] : (string) ($row['email'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'permissions' => $permissions,
        ];
    }

    function can_workspace(array $actor, string $permission): bool {
        if (!empty($actor['is_admin'])) {
            return true;
        }
        $permissions = $actor['permissions']['workspace'] ?? [];
        return is_array($permissions) && in_array($permission, $permissions, true);
    }

    function _require_workspace(string $permission, string $message = 'You do not have permission to access this area.'): void {
        if (!$this->can_workspace($this->_actor(), $permission)) {
            $this->trongate_security->_forbidden($message);
        }
    }

    private function _resolve_actor(string $token): ?array {
        if ($this->cached_token === $token && $this->cached_actor !== null) {
            return $this->cached_actor;
        }

        $user = $this->trongate_tokens->get_user_obj($token);
        if ($user === false) {
            return null;
        }

        if ((int) ($user->user_level_id ?? 0) !== 2) {
            return null;
        }

        $this->module('team');
        $member = $this->team->model->_find_member_by_user_id((int) ($user->trongate_user_id ?? 0));
        if ($member === null || !$member['active']) {
            return null;
        }
        $actor = $this->_from_member($member);

        $this->cached_token = $token;
        $this->cached_actor = $actor;
        return $actor;
    }

    private function _reject_unauthenticated(): never {
        if (from_trongate_mx() || ($_POST['inline'] ?? '') === 'server' || ($_GET['poll'] ?? '') === '1') {
            $this->trongate_security->_json(['error' => 'Your session expired. Sign in again.'], 401);
            exit;
        }
        redirect('login');
        exit;
    }
}
