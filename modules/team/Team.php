<?php
declare(strict_types=1);

require_once APPPATH . 'modules/trongate_security/Trongate_security.php';
require_once APPPATH . 'modules/team/services/Mailer.php';
require_once APPPATH . 'modules/validation/services/Validate.php';

class Team extends Trongate {
    public const APP_PERMISSIONS = [
        'view' => ['label' => 'Overview', 'description' => 'View app status, domains, and architecture.', 'group' => 'read'],
        'deployments' => ['label' => 'Deployments', 'description' => 'View deployment history, built releases, and build logs.', 'group' => 'read'],
        'logs' => ['label' => 'Logs', 'description' => 'View live app logs and 24-hour error rates.', 'group' => 'read'],
        'traffic' => ['label' => 'Traffic', 'description' => 'View and record live HTTP traffic.', 'group' => 'read'],
        'services_view' => ['label' => 'View services', 'description' => 'View private services attached to this app.', 'group' => 'read'],
        'database_read' => ['label' => 'Database (read-only)', 'description' => 'Browse tables, inspect schema, and run read-only SQL.', 'group' => 'read'],
        'deploy' => ['label' => 'Deploy & rollback', 'description' => 'Trigger deploys, rollbacks, canary rollouts, and DNS go-live.', 'group' => 'write'],
        'services_manage' => ['label' => 'Manage services', 'description' => 'Add, redeploy, and remove private services for this app.', 'group' => 'write'],
        'database_write' => ['label' => 'Database (write & import)', 'description' => 'Run mutating SQL and import .sql files or starter tables.', 'group' => 'write'],
        'settings' => ['label' => 'Settings & environment', 'description' => 'Edit branch, build commands, and encrypted environment variables.', 'group' => 'write'],
        'delete' => ['label' => 'Delete app', 'description' => 'Delete the app and optionally purge its database and data.', 'group' => 'write'],
    ];

    public const WORKSPACE_PERMISSIONS = [
        'apps.create' => ['label' => 'Create apps', 'description' => 'Launch new apps from the setup wizard.'],
        'services.view' => ['label' => 'View shared services', 'description' => 'View server-wide private services.'],
        'services.manage' => ['label' => 'Manage shared services', 'description' => 'Add, redeploy, and remove server-wide private services.'],
        'servers.manage' => ['label' => 'Manage servers', 'description' => 'View, inspect, connect servers, and edit resource limits.'],
        'registry.manage' => ['label' => 'Manage module registry', 'description' => 'Register modules, mint namespace tokens, publish archives, and yank releases.'],
        'jobs.reconcile' => ['label' => 'Reconcile jobs', 'description' => 'Reconcile uncertain background jobs.'],
        'team.manage' => ['label' => 'Manage team', 'description' => 'Invite members and manage roles and access.'],
    ];

    public const APP_PRESETS = [
        'viewer' => [
            'label' => 'Viewer',
            'description' => 'Read-only overview, deployments, logs, traffic, and attached services.',
            'permissions' => ['view', 'deployments', 'logs', 'traffic', 'services_view'],
        ],
        'developer' => [
            'label' => 'Developer',
            'description' => 'Viewer access plus deploys, rollbacks, read-only database, and app services.',
            'permissions' => ['view', 'deployments', 'deploy', 'logs', 'traffic', 'database_read', 'services_view', 'services_manage'],
        ],
        'maintainer' => [
            'label' => 'Maintainer',
            'description' => 'Full control over the app including settings, database writes, and deletion.',
            'permissions' => ['view', 'deployments', 'deploy', 'logs', 'traffic', 'database_read', 'database_write', 'services_view', 'services_manage', 'settings', 'delete'],
        ],
    ];

    public function normalize_app_permissions(array $list): array {
        $allowed = array_keys(self::APP_PERMISSIONS);
        $set = [];
        foreach ($list as $item) {
            if (is_string($item) && in_array($item, $allowed, true)) {
                $set[$item] = true;
            }
        }
        if ($set === []) {
            return [];
        }
        $set['view'] = true;
        if (!empty($set['deploy'])) {
            $set['deployments'] = true;
        }
        if (!empty($set['database_write'])) {
            $set['database_read'] = true;
        }
        if (!empty($set['services_manage'])) {
            $set['services_view'] = true;
        }
        return array_values(array_filter($allowed, static fn(string $key): bool => !empty($set[$key])));
    }

    public function normalize_workspace_permissions(array $list): array {
        $allowed = array_keys(self::WORKSPACE_PERMISSIONS);
        $set = array_fill_keys(array_filter($list, static fn($permission): bool => is_string($permission) && in_array($permission, $allowed, true)), true);
        if (!empty($set['services.manage'])) {
            $set['services.view'] = true;
        }
        return array_values(array_filter($allowed, static fn(string $key): bool => !empty($set[$key])));
    }

    public function normalize_permissions(array $raw, string $role = 'member', string $scope = 'scoped'): array {
        if ($role === 'admin') {
            return [
                'workspace' => array_keys(self::WORKSPACE_PERMISSIONS),
                'all_apps' => array_keys(self::APP_PERMISSIONS),
                'apps' => [],
            ];
        }

        $workspace = $this->normalize_workspace_permissions(is_array($raw['workspace'] ?? null) ? $raw['workspace'] : []);
        $all_apps = $scope === 'all'
            ? $this->normalize_app_permissions(is_array($raw['all_apps'] ?? null) ? $raw['all_apps'] : [])
            : [];
        $apps = [];
        foreach (is_array($raw['apps'] ?? null) ? $raw['apps'] : [] as $app_id => $permissions) {
            if (!is_string($app_id) || !preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $app_id) || !is_array($permissions)) {
                continue;
            }
            $clean = $this->normalize_app_permissions($permissions);
            if ($clean !== []) {
                $apps[$app_id] = $clean;
            }
        }
        ksort($apps);
        return ['workspace' => $workspace, 'all_apps' => $all_apps, 'apps' => $apps];
    }

    public function preset_label(array $permissions): string {
        $normalized = $this->normalize_app_permissions($permissions);
        if ($normalized === []) {
            return 'No access';
        }
        foreach (self::APP_PRESETS as $preset) {
            if ($normalized === $this->normalize_app_permissions($preset['permissions'])) {
                return $preset['label'];
            }
        }
        return 'Custom (' . count($normalized) . ')';
    }

    public function permission_labels(array $permissions): array {
        $labels = [];
        foreach ($this->normalize_app_permissions($permissions) as $permission) {
            $labels[] = self::APP_PERMISSIONS[$permission]['label'] ?? $permission;
        }
        return $labels;
    }

    public function summary_lines(string $role, string $scope, array $permissions): array {
        if ($role === 'admin') {
            return ['Full workspace access (all apps, services, servers, and team)'];
        }
        $normalized = $this->normalize_permissions($permissions, $role, $scope);
        $lines = [];
        if ($scope === 'all' && $normalized['all_apps'] !== []) {
            $lines[] = 'All apps · ' . $this->preset_label($normalized['all_apps']);
        }
        foreach ($normalized['apps'] as $app_id => $app_permissions) {
            $lines[] = $app_id . ' · ' . $this->preset_label($app_permissions);
        }
        foreach ($normalized['workspace'] as $permission) {
            $lines[] = self::WORKSPACE_PERMISSIONS[$permission]['label'] ?? $permission;
        }
        return $lines !== [] ? $lines : ['No access granted'];
    }

    public function merge_grant(array $existing, array $grant): array {
        $existing_role = ($existing['role'] ?? 'member') === 'admin' ? 'admin' : 'member';
        $grant_role = ($grant['role'] ?? 'member') === 'admin' ? 'admin' : 'member';
        if ($existing_role === 'admin' || $grant_role === 'admin') {
            return ['role' => 'admin', 'scope' => 'all', 'permissions' => $this->normalize_permissions([], 'admin', 'all')];
        }

        $existing_scope = ($existing['scope'] ?? 'scoped') === 'all' ? 'all' : 'scoped';
        $grant_scope = ($grant['scope'] ?? 'scoped') === 'all' ? 'all' : 'scoped';
        $scope = $existing_scope === 'all' || $grant_scope === 'all' ? 'all' : 'scoped';
        $existing_permissions = $this->normalize_permissions(
            is_string($existing['permissions'] ?? null) ? (json_decode($existing['permissions'], true) ?: []) : (array) ($existing['permissions'] ?? []),
            'member',
            $existing_scope
        );
        $grant_permissions = $this->normalize_permissions(
            is_string($grant['permissions'] ?? null) ? (json_decode($grant['permissions'], true) ?: []) : (array) ($grant['permissions'] ?? []),
            'member',
            $grant_scope
        );

        $apps = $existing_permissions['apps'];
        foreach ($grant_permissions['apps'] as $app_id => $permissions) {
            $apps[$app_id] = $this->normalize_app_permissions(array_merge($apps[$app_id] ?? [], $permissions));
        }
        return [
            'role' => 'member',
            'scope' => $scope,
            'permissions' => $this->normalize_permissions([
                'workspace' => array_merge($existing_permissions['workspace'], $grant_permissions['workspace']),
                'all_apps' => array_merge($existing_permissions['all_apps'], $grant_permissions['all_apps']),
                'apps' => $apps,
            ], 'member', $scope),
        ];
    }

    public function parse_form_grant(array $post, array $valid_app_ids): array {
        $access_mode = trim((string) ($post['access_mode'] ?? 'scoped'));
        if ($access_mode === 'admin') {
            return ['role' => 'admin', 'scope' => 'all', 'permissions' => $this->normalize_permissions([], 'admin', 'all')];
        }

        $scope = $access_mode === 'all' ? 'all' : 'scoped';
        $workspace = is_array($post['workspace_perms'] ?? null) ? $post['workspace_perms'] : [];
        $all_apps = is_array($post['all_app_perms'] ?? null) ? $post['all_app_perms'] : [];
        $apps_in = is_array($post['app_perms'] ?? null) ? $post['app_perms'] : [];
        $preset = trim((string) ($post['all_app_preset'] ?? ''));
        if ($scope === 'all' && $all_apps === [] && isset(self::APP_PRESETS[$preset])) {
            $all_apps = self::APP_PRESETS[$preset]['permissions'];
        }

        $valid_ids = array_flip($valid_app_ids);
        $apps = [];
        foreach ($apps_in as $app_id => $permissions) {
            if (!is_string($app_id) || !isset($valid_ids[$app_id]) || !is_array($permissions)) {
                continue;
            }
            $clean = $this->normalize_app_permissions($permissions);
            if ($clean !== []) {
                $apps[$app_id] = $clean;
            }
        }
        $normalized = $this->normalize_permissions(['workspace' => $workspace, 'all_apps' => $all_apps, 'apps' => $apps], 'member', $scope);
        if ($normalized['workspace'] === [] && $normalized['all_apps'] === [] && $normalized['apps'] === []) {
            throw new RuntimeException('Select at least one app or workspace permission.');
        }
        return ['role' => 'member', 'scope' => $scope, 'permissions' => $normalized];
    }

    public function single_app_target(string $role, string $scope, array $permissions): ?string {
        if ($role === 'admin' || $scope === 'all') {
            return null;
        }
        $apps = is_array($permissions['apps'] ?? null) ? array_keys($permissions['apps']) : [];
        return count($apps) === 1 ? (string) $apps[0] : null;
    }

    function make_sure_allowed(): void {
        block_url('team/make_sure_allowed');
        $this->_actor();
    }

    function index(): void {
        $this->trongate_security->make_sure_allowed('team');
        $this->workspace->_require_workspace('team.manage');

        $flash = $_SESSION['team_flash'] ?? null;
        $error = $_SESSION['team_error'] ?? null;
        $invite_link = $_SESSION['team_invite_link'] ?? null;
        unset($_SESSION['team_flash'], $_SESSION['team_error'], $_SESSION['team_invite_link']);

        $this->workspace->_render_panel('team', 'index', 'team', [
            'members' => $this->model->_members(),
            'invitations' => $this->model->_pending_invitations(),
            'all_apps' => $this->records->_all('app'),
            'saved_notice' => is_string($flash) ? $flash : null,
            'message' => is_string($error) ? $error : null,
            'invite_link' => is_array($invite_link) ? $invite_link : null,
            'crumbs' => [['/team', 'Team']],
        ]);
    }

    function invite(): void {
        $this->trongate_security->make_sure_allowed('team');
        $this->trongate_security->_csrf();
        $this->workspace->_require_workspace('team.manage');

        $return_app = trim((string) ($_POST['return_app'] ?? ''));
        $redirect_to = $return_app !== ''
            ? '/apps/access/' . rawurlencode($return_app)
            : '/team';

        try {
            $email = (string) ($_POST['email'] ?? '');
            $note = (string) ($_POST['note'] ?? '');
            $ttl_days = (int) ($_POST['expires_days'] ?? 7);
            $valid_app_ids = array_map(static fn(array $a): string => (string) ($a['id'] ?? ''), $this->records->_all('app'));
            $grant = $this->parse_form_grant($_POST, $valid_app_ids);

            $actor = $this->_actor();
            $invited_by = $actor['email'] !== '' ? $actor['email'] : $actor['label'];
            $created = $this->model->_create_invitation($email, $grant, $note, $invited_by, $ttl_days);

            $url = rtrim(BASE_URL, '/') . '/team/accept/' . $created['token'];
            $summary = $this->summary_lines($created['role'], $created['scope'], $created['permissions']);
            $mail = Mailer::send_invite(
                $created['email'],
                $url,
                $invited_by,
                $summary,
                $created['note'],
                $created['expires_at']
            );

            $_SESSION['team_invite_link'] = [
                'email' => $created['email'],
                'url' => $url,
                'expires_at' => $created['expires_at'],
                'sent' => !empty($mail['sent']),
                'mail_reason' => (string) ($mail['reason'] ?? ''),
                'mail_error' => (string) ($mail['error'] ?? ''),
            ];
            $_SESSION['team_flash'] = !empty($mail['sent'])
                ? 'Invitation sent to ' . $created['email'] . '.'
                : 'Invitation created for ' . $created['email'] . '. Share the invite link below.';
        } catch (Throwable $e) {
            $_SESSION['team_error'] = $e instanceof RuntimeException ? $e->getMessage() : 'Could not create invitation.';
        }

        header('Location: ' . $redirect_to, true, 303);
        exit;
    }

    function resend_invite(): void {
        $this->trongate_security->make_sure_allowed('team');
        $this->trongate_security->_csrf();
        $this->workspace->_require_workspace('team.manage');

        $return_app = trim((string) ($_POST['return_app'] ?? ''));
        $redirect_to = $return_app !== ''
            ? '/apps/access/' . rawurlencode($return_app)
            : '/team';

        try {
            $id = trim((string) ($_POST['invitation'] ?? ''));
            $rotated = $this->model->_rotate_invitation($id);
            $url = rtrim(BASE_URL, '/') . '/team/accept/' . $rotated['token'];
            $summary = $this->summary_lines($rotated['role'], $rotated['scope'], $rotated['permissions']);
            $mail = Mailer::send_invite(
                $rotated['email'],
                $url,
                $rotated['invited_by'],
                $summary,
                $rotated['note'],
                $rotated['expires_at']
            );

            $_SESSION['team_invite_link'] = [
                'email' => $rotated['email'],
                'url' => $url,
                'expires_at' => $rotated['expires_at'],
                'sent' => !empty($mail['sent']),
                'mail_reason' => (string) ($mail['reason'] ?? ''),
                'mail_error' => (string) ($mail['error'] ?? ''),
            ];
            $_SESSION['team_flash'] = !empty($mail['sent'])
                ? 'Fresh invitation link sent to ' . $rotated['email'] . '.'
                : 'Generated a fresh invite link for ' . $rotated['email'] . '. The previous link has been invalidated.';
        } catch (Throwable $e) {
            $_SESSION['team_error'] = $e instanceof RuntimeException ? $e->getMessage() : 'Could not regenerate invitation.';
        }

        header('Location: ' . $redirect_to, true, 303);
        exit;
    }

    function revoke_invite(): void {
        $this->trongate_security->make_sure_allowed('team');
        $this->trongate_security->_csrf();
        $this->workspace->_require_workspace('team.manage');

        $return_app = trim((string) ($_POST['return_app'] ?? ''));
        $redirect_to = $return_app !== ''
            ? '/apps/access/' . rawurlencode($return_app)
            : '/team';

        try {
            $id = trim((string) ($_POST['invitation'] ?? ''));
            $this->model->_revoke_invitation($id);
            $_SESSION['team_flash'] = 'Invitation revoked.';
        } catch (Throwable $e) {
            $_SESSION['team_error'] = $e instanceof RuntimeException ? $e->getMessage() : 'Could not revoke invitation.';
        }

        header('Location: ' . $redirect_to, true, 303);
        exit;
    }

    function member(): void {
        $this->trongate_security->make_sure_allowed('team');
        $this->workspace->_require_workspace('team.manage');

        $id = segment(3, 'int');
        $member = $id > 0 ? $this->model->_find_member($id) : null;
        if ($member === null) {
            http_response_code(404);
            $this->workspace->_render_panel('team', 'index', 'team', [
                'members' => $this->model->_members(),
                'invitations' => $this->model->_pending_invitations(),
                'all_apps' => $this->records->_all('app'),
                'message' => 'Team member not found.',
                'crumbs' => [['/team', 'Team']],
            ]);
            return;
        }

        $flash = $_SESSION['team_flash'] ?? null;
        $error = $_SESSION['team_error'] ?? null;
        unset($_SESSION['team_flash'], $_SESSION['team_error']);

        $this->workspace->_render_panel('team', 'member', 'team-member', [
            'member' => $member,
            'all_apps' => $this->records->_all('app'),
            'saved_notice' => is_string($flash) ? $flash : null,
            'message' => is_string($error) ? $error : null,
            'crumbs' => [
                ['/team', 'Team'],
                ['/team/member/' . $member['id'], $member['name'] !== '' ? $member['name'] : $member['email']],
            ],
        ]);
    }

    function update_member(): void {
        $this->trongate_security->make_sure_allowed('team');
        $this->trongate_security->_csrf();
        $this->workspace->_require_workspace('team.manage');

        $member_id = (int) ($_POST['member_id'] ?? 0);
        try {
            $member = $this->model->_find_member($member_id);
            if ($member === null) {
                throw new RuntimeException('Team member not found.');
            }
            $valid_app_ids = array_map(static fn(array $a): string => (string) ($a['id'] ?? ''), $this->records->_all('app'));
            $grant = $this->parse_form_grant($_POST, $valid_app_ids);

            $actor = $this->_actor();
            if ((int) ($actor['member_id'] ?? 0) === $member_id) {
                $simulated = $this->actor_from_member([
                    'id' => $member_id,
                    'email' => $member['email'],
                    'name' => $member['name'],
                    'trongate_user_id' => $member['trongate_user_id'],
                    'role' => $grant['role'],
                    'scope' => $grant['scope'],
                    'permissions' => $grant['permissions'],
                ]);
                if (!$this->workspace->can_workspace($simulated, 'team.manage')) {
                    throw new RuntimeException('You cannot remove your own team management permission.');
                }
            }

            $name = isset($_POST['name']) ? (string) $_POST['name'] : null;
            $this->model->_update_member_access($member_id, $grant, $name);
            $_SESSION['team_flash'] = 'Access updated for ' . $member['email'] . '.';
        } catch (Throwable $e) {
            $_SESSION['team_error'] = $e instanceof RuntimeException ? $e->getMessage() : 'Could not update team member.';
        }

        header('Location: /team/member/' . $member_id, true, 303);
        exit;
    }

    function update_app_member(): void {
        $this->trongate_security->make_sure_allowed('team');
        $this->trongate_security->_csrf();
        $this->workspace->_require_workspace('team.manage');

        $app_id = '';
        try {
            $app_id = Validate::id((string) ($_POST['app'] ?? ''));
            $this->records->_get(['kind' => 'app', 'id' => $app_id]);
            $member_id = (int) ($_POST['member_id'] ?? 0);
            $mode = trim((string) ($_POST['mode'] ?? 'update'));
            if ($mode === 'revoke') {
                $perms = [];
            } else {
                $perms = is_array($_POST['perms'] ?? null) ? $_POST['perms'] : [];
                $preset = trim((string) ($_POST['preset'] ?? ''));
                if ($perms === [] && isset(self::APP_PRESETS[$preset])) {
                    $perms = self::APP_PRESETS[$preset]['permissions'];
                }
            }
            $updated = $this->model->_set_member_app_permissions($member_id, $app_id, $perms);
            $_SESSION['team_flash'] = $perms === []
                ? 'Removed direct access to ' . $app_id . ' for ' . $updated['email'] . '.'
                : 'Updated ' . $app_id . ' access for ' . $updated['email'] . '.';
        } catch (Throwable $e) {
            $_SESSION['team_error'] = $e instanceof RuntimeException ? $e->getMessage() : 'Could not update app access.';
        }

        header('Location: ' . ($app_id !== '' ? '/apps/access/' . rawurlencode($app_id) : '/team'), true, 303);
        exit;
    }

    function toggle_member(): void {
        $this->trongate_security->make_sure_allowed('team');
        $this->trongate_security->_csrf();
        $this->workspace->_require_workspace('team.manage');

        $member_id = (int) ($_POST['member_id'] ?? 0);
        $active = ($_POST['active'] ?? '0') === '1';
        $actor = $this->_actor();
        try {
            if ((int) ($actor['member_id'] ?? 0) === $member_id && !$active) {
                throw new RuntimeException('You cannot suspend your own account.');
            }
            $updated = $this->model->_set_member_active($member_id, $active);
            $_SESSION['team_flash'] = $active
                ? 'Reactivated ' . $updated['email'] . '.'
                : 'Suspended ' . $updated['email'] . ' and ended their active sessions.';
        } catch (Throwable $e) {
            $_SESSION['team_error'] = $e instanceof RuntimeException ? $e->getMessage() : 'Could not update member status.';
        }

        header('Location: /team/member/' . $member_id, true, 303);
        exit;
    }

    function remove_member(): void {
        $this->trongate_security->make_sure_allowed('team');
        $this->trongate_security->_csrf();
        $this->workspace->_require_workspace('team.manage');

        $member_id = (int) ($_POST['member_id'] ?? 0);
        $actor = $this->_actor();
        try {
            if ((int) ($actor['member_id'] ?? 0) === $member_id) {
                throw new RuntimeException('You cannot remove your own account.');
            }
            $this->model->_remove_member($member_id);
            $_SESSION['team_flash'] = 'Team member removed and active sessions ended.';
        } catch (Throwable $e) {
            $_SESSION['team_error'] = $e instanceof RuntimeException ? $e->getMessage() : 'Could not remove team member.';
        }

        header('Location: /team', true, 303);
        exit;
    }

    /**
     * Public invitation acceptance screen.
     */
    function accept(): void {
        header('Referrer-Policy: no-referrer');
        $_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
        $token = trim((string) segment(3));
        $invite = $this->model->_find_valid_invitation_by_token($token);
        $existing = $invite !== null ? $this->model->_find_member_by_email($invite['email']) : null;
        $current_actor = $this->_optional_actor();
        $signed_in_as_invitee = $existing !== null
            && $current_actor !== null
            && (int) ($current_actor['member_id'] ?? 0) === (int) $existing['id'];

        $error = $_SESSION['invite_accept_error'] ?? null;
        unset($_SESSION['invite_accept_error']);

        $this->_render_accept([
            'token' => $token,
            'invitation' => $invite,
            'existing_member' => $existing !== null,
            'signed_in_as_invitee' => $signed_in_as_invitee,
            'error' => is_string($error) ? $error : null,
        ]);
    }

    /**
     * Public POST endpoint to complete an invitation and sign the user in.
     */
    function complete_invite(): void {
        header('Referrer-Policy: no-referrer');
        $this->trongate_security->_csrf();

        $token = trim((string) ($_POST['token'] ?? ''));
        $invite = $this->model->_find_valid_invitation_by_token($token);
        if ($invite === null) {
            $_SESSION['invite_accept_error'] = 'This invitation link is invalid or has expired.';
            header('Location: /team/accept/' . rawurlencode($token), true, 303);
            exit;
        }

        try {
            $existing = $this->model->_find_member_by_email($invite['email']);
            $current_actor = $this->_optional_actor();
            $signed_in_as_invitee = $existing !== null
                && $current_actor !== null
                && (int) ($current_actor['member_id'] ?? 0) === (int) $existing['id'];

            $password_hash = null;
            $name = trim((string) ($_POST['name'] ?? ''));
            $authenticated_member_id = null;

            if ($existing !== null) {
                if ($signed_in_as_invitee) {
                    $authenticated_member_id = (int) $existing['id'];
                } else {
                    $password = (string) ($_POST['password'] ?? '');
                    if (!password_verify($password, (string) ($existing['password'] ?? ''))) {
                        throw new RuntimeException('Enter the password for your existing ' . $invite['email'] . ' account to accept this invitation.');
                    }
                    $authenticated_member_id = (int) $existing['id'];
                }
            } else {
                $password = (string) ($_POST['password'] ?? '');
                $confirm = (string) ($_POST['password_confirm'] ?? '');
                if (strlen($password) < 12) {
                    throw new RuntimeException('Choose a password with at least 12 characters.');
                }
                if ($password !== $confirm) {
                    throw new RuntimeException('Password confirmation does not match.');
                }
                $this->module('login');
                $password_hash = $this->login->model->hash_password($password);
            }

            $accepted = $this->model->_accept_invitation($token, $name, $password_hash, $authenticated_member_id);
            $member = $accepted['member'];

            // Replace any previous session token with a fresh session for the invited member.
            $this->trongate_tokens->destroy();
            session_regenerate_id(true);
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $this->trongate_tokens->generate_token([
                'user_id' => (int) $member['trongate_user_id'],
                'expiry_date' => time() + 86400,
            ]);

            $single_app = $this->single_app_target(
                $accepted['invitation']['role'],
                $accepted['invitation']['scope'],
                $accepted['invitation']['permissions']
            );
            $target = $single_app !== null ? '/apps/show/' . rawurlencode($single_app) : '/apps';
            header('Location: ' . $target, true, 303);
            exit;
        } catch (Throwable $e) {
            $_SESSION['invite_accept_error'] = $e instanceof RuntimeException ? $e->getMessage() : 'Could not accept invitation.';
            header('Location: /team/accept/' . rawurlencode($token), true, 303);
            exit;
        }
    }

    function _display(string $view, array $data): void {
        $data['viewData'] = $data;
        $this->view($view, $data);
    }

    private function _optional_actor(): ?array {
        $token = $this->trongate_tokens->attempt_get_valid_token(2);
        if ($token === false) {
            return null;
        }
        $user = $this->trongate_tokens->get_user_obj($token);
        if ($user === false) {
            return null;
        }
        $member = $this->model->_find_member_by_user_id((int) $user->trongate_user_id);
        if ($member === null || !$member['active']) {
            return null;
        }
        return $this->actor_from_member($member);
    }

    private function _actor(): array {
        $actor = $this->_optional_actor();
        if ($actor !== null) {
            return $actor;
        }

        if (from_trongate_mx() || ($_POST['inline'] ?? '') === 'server' || ($_GET['poll'] ?? '') === '1') {
            $this->trongate_security->_json(['error' => 'Your session expired. Sign in again.'], 401);
            exit;
        }
        redirect('login');
        exit;
    }

    public function actor_from_member(array $row): array {
        $role = ($row['role'] ?? 'member') === 'admin' ? 'admin' : 'member';
        $scope = ($row['scope'] ?? 'scoped') === 'all' ? 'all' : 'scoped';
        $raw = is_string($row['permissions'] ?? null)
            ? (json_decode($row['permissions'], true) ?: [])
            : (is_array($row['permissions'] ?? null) ? $row['permissions'] : []);
        $permissions = $this->normalize_permissions($raw, $role, $scope);
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

    private function _render_accept(array $data): void {
        $e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $csrf = static fn(): string => '<input type="hidden" name="csrf_token" value="' . $e($_SESSION['csrf_token'] ?? '') . '">';
        $data['e'] = $e;
        $data['csrf'] = $csrf;
        $this->view('accept', $data);
    }
}
