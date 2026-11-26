<?php
declare(strict_types=1);

/**
 * Explicit role and granular permission evaluator for workspace owners and team members.
 */
final class Access_policy {
    public const APP_PERMISSIONS = [
        'view' => [
            'label' => 'Overview',
            'description' => 'View app status, domains, and architecture.',
            'group' => 'read',
        ],
        'deployments' => [
            'label' => 'Deployments',
            'description' => 'View deployment history, built releases, and build logs.',
            'group' => 'read',
        ],
        'logs' => [
            'label' => 'Logs',
            'description' => 'View live app logs and 24-hour error rates.',
            'group' => 'read',
        ],
        'traffic' => [
            'label' => 'Traffic',
            'description' => 'View and record live HTTP traffic.',
            'group' => 'read',
        ],
        'services_view' => [
            'label' => 'View services',
            'description' => 'View private services attached to this app.',
            'group' => 'read',
        ],
        'database_read' => [
            'label' => 'Database (read-only)',
            'description' => 'Browse tables, inspect schema, and run read-only SQL.',
            'group' => 'read',
        ],
        'deploy' => [
            'label' => 'Deploy & rollback',
            'description' => 'Trigger deploys, rollbacks, canary rollouts, and DNS go-live.',
            'group' => 'write',
        ],
        'services_manage' => [
            'label' => 'Manage services',
            'description' => 'Add, redeploy, and remove private services for this app.',
            'group' => 'write',
        ],
        'database_write' => [
            'label' => 'Database (write & import)',
            'description' => 'Run mutating SQL and import .sql files or starter tables.',
            'group' => 'write',
        ],
        'settings' => [
            'label' => 'Settings & environment',
            'description' => 'Edit branch, build commands, and encrypted environment variables.',
            'group' => 'write',
        ],
        'delete' => [
            'label' => 'Delete app',
            'description' => 'Delete the app and optionally purge its database and data.',
            'group' => 'write',
        ],
    ];

    public const WORKSPACE_PERMISSIONS = [
        'apps.create' => [
            'label' => 'Create apps',
            'description' => 'Launch new apps from the setup wizard.',
        ],
        'services.view' => [
            'label' => 'View shared services',
            'description' => 'View server-wide private services.',
        ],
        'services.manage' => [
            'label' => 'Manage shared services',
            'description' => 'Add, redeploy, and remove server-wide private services.',
        ],
        'servers.manage' => [
            'label' => 'Manage servers',
            'description' => 'View, inspect, connect servers, and edit resource limits.',
        ],
        'jobs.reconcile' => [
            'label' => 'Reconcile jobs',
            'description' => 'Reconcile uncertain background jobs.',
        ],
        'team.manage' => [
            'label' => 'Manage team',
            'description' => 'Invite members and manage roles and access.',
        ],
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

    public static function owner(int $user_id, string $username): array {
        return [
            'user_id' => $user_id,
            'member_id' => 0,
            'is_owner' => true,
            'is_admin' => true,
            'role' => 'owner',
            'scope' => 'all',
            'label' => $username,
            'email' => '',
            'permissions' => [
                'workspace' => array_keys(self::WORKSPACE_PERMISSIONS),
                'all_apps' => array_keys(self::APP_PERMISSIONS),
                'apps' => [],
            ],
        ];
    }

    public static function from_member(array $row): array {
        $role = ($row['role'] ?? 'member') === 'admin' ? 'admin' : 'member';
        $scope = ($row['scope'] ?? 'scoped') === 'all' ? 'all' : 'scoped';
        $raw = is_string($row['permissions'] ?? null)
            ? (json_decode($row['permissions'], true) ?: [])
            : (is_array($row['permissions'] ?? null) ? $row['permissions'] : []);
        $perms = self::normalize_permissions($raw, $role, $scope);

        return [
            'user_id' => (int) ($row['trongate_user_id'] ?? 0),
            'member_id' => (int) ($row['id'] ?? 0),
            'is_owner' => false,
            'is_admin' => $role === 'admin',
            'role' => $role,
            'scope' => $role === 'admin' ? 'all' : $scope,
            'label' => trim((string) ($row['name'] ?? '')) !== '' ? (string) $row['name'] : (string) ($row['email'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'permissions' => $perms,
        ];
    }

    public static function normalize_app_permissions(array $list): array {
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
        $ordered = [];
        foreach ($allowed as $key) {
            if (!empty($set[$key])) {
                $ordered[] = $key;
            }
        }
        return $ordered;
    }

    public static function normalize_workspace_permissions(array $list): array {
        $allowed = array_keys(self::WORKSPACE_PERMISSIONS);
        $set = [];
        foreach ($list as $item) {
            if (is_string($item) && in_array($item, $allowed, true)) {
                $set[$item] = true;
            }
        }
        if (!empty($set['services.manage'])) {
            $set['services.view'] = true;
        }
        $ordered = [];
        foreach ($allowed as $key) {
            if (!empty($set[$key])) {
                $ordered[] = $key;
            }
        }
        return $ordered;
    }

    public static function normalize_permissions(array $raw, string $role = 'member', string $scope = 'scoped'): array {
        if ($role === 'admin') {
            return [
                'workspace' => array_keys(self::WORKSPACE_PERMISSIONS),
                'all_apps' => array_keys(self::APP_PERMISSIONS),
                'apps' => [],
            ];
        }

        $workspace = self::normalize_workspace_permissions(is_array($raw['workspace'] ?? null) ? $raw['workspace'] : []);
        $all_apps = $scope === 'all'
            ? self::normalize_app_permissions(is_array($raw['all_apps'] ?? null) ? $raw['all_apps'] : [])
            : [];
        $apps = [];
        if (is_array($raw['apps'] ?? null)) {
            foreach ($raw['apps'] as $app_id => $perms) {
                if (!is_string($app_id) || !preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $app_id) || !is_array($perms)) {
                    continue;
                }
                $clean = self::normalize_app_permissions($perms);
                if ($clean !== []) {
                    $apps[$app_id] = $clean;
                }
            }
        }
        ksort($apps);

        return [
            'workspace' => $workspace,
            'all_apps' => $all_apps,
            'apps' => $apps,
        ];
    }

    public static function can_workspace(array $actor, string $permission): bool {
        if (!empty($actor['is_owner']) || !empty($actor['is_admin'])) {
            return true;
        }
        $workspace = $actor['permissions']['workspace'] ?? [];
        return is_array($workspace) && in_array($permission, $workspace, true);
    }

    public static function app_permissions(array $actor, string $app_id): array {
        if (!empty($actor['is_owner']) || !empty($actor['is_admin'])) {
            return array_keys(self::APP_PERMISSIONS);
        }
        $perms = [];
        if (($actor['scope'] ?? 'scoped') === 'all') {
            $perms = is_array($actor['permissions']['all_apps'] ?? null) ? $actor['permissions']['all_apps'] : [];
        }
        $specific = $actor['permissions']['apps'][$app_id] ?? null;
        if (is_array($specific) && $specific !== []) {
            $perms = array_merge($perms, $specific);
        }
        return self::normalize_app_permissions($perms);
    }

    public static function can_app(array $actor, string $app_id, string $permission = 'view'): bool {
        if ($app_id === '') {
            return false;
        }
        if (!empty($actor['is_owner']) || !empty($actor['is_admin'])) {
            return true;
        }
        return in_array($permission, self::app_permissions($actor, $app_id), true);
    }

    public static function visible_apps(array $actor, array $apps): array {
        if (!empty($actor['is_owner']) || !empty($actor['is_admin'])) {
            return array_values($apps);
        }
        return array_values(array_filter(
            $apps,
            static fn(array $app): bool => self::can_app($actor, (string) ($app['id'] ?? ''), 'view')
        ));
    }

    public static function can_access_jobs_page(array $actor, array $apps = []): bool {
        if (!empty($actor['is_owner']) || !empty($actor['is_admin'])) {
            return true;
        }
        if (
            self::can_workspace($actor, 'services.view')
            || self::can_workspace($actor, 'servers.manage')
            || self::can_workspace($actor, 'jobs.reconcile')
        ) {
            return true;
        }
        if (($actor['scope'] ?? 'scoped') === 'all') {
            $all = is_array($actor['permissions']['all_apps'] ?? null) ? $actor['permissions']['all_apps'] : [];
            if (in_array('deployments', $all, true) || in_array('services_view', $all, true)) {
                return true;
            }
        }
        $granted_apps = is_array($actor['permissions']['apps'] ?? null) ? $actor['permissions']['apps'] : [];
        foreach ($granted_apps as $perms) {
            if (is_array($perms) && (in_array('deployments', $perms, true) || in_array('services_view', $perms, true))) {
                return true;
            }
        }
        return false;
    }

    public static function job_target(array $job): array {
        $kind = (string) ($job['kind'] ?? '');
        $payload = [];
        if (isset($job['payload'])) {
            $payload = is_string($job['payload'])
                ? (json_decode($job['payload'], true) ?: [])
                : (is_array($job['payload']) ? $job['payload'] : []);
        }
        $app = (string) ($payload['app'] ?? $payload['launch']['app'] ?? $payload['plan']['app'] ?? '');
        if ($app === '' && ($job['target_kind'] ?? '') === 'app') {
            $app = (string) ($job['target'] ?? '');
        }
        $service = (string) (
            $payload['service_name']
            ?? (is_string($payload['service'] ?? null)
                ? $payload['service']
                : ($payload['service']['name'] ?? $payload['name'] ?? ''))
        );
        if ($service === '' && ($job['target_kind'] ?? '') === 'service') {
            $service = (string) ($job['target'] ?? '');
        }
        return [
            'kind' => $kind,
            'app' => $app,
            'service' => $service,
        ];
    }

    public static function can_view_job(array $actor, array $job): bool {
        if (!empty($actor['is_owner']) || !empty($actor['is_admin'])) {
            return true;
        }
        $target = self::job_target($job);
        $kind = $target['kind'];
        $app = $target['app'];

        if (in_array($kind, ['deploy-service', 'destroy-service'], true)) {
            if ($app !== '') {
                return self::can_app($actor, $app, 'services_view') || self::can_app($actor, $app, 'deployments');
            }
            return self::can_workspace($actor, 'services.view');
        }

        if ($app !== '') {
            return self::can_app($actor, $app, 'deployments');
        }

        return self::can_workspace($actor, 'servers.manage') || self::can_workspace($actor, 'jobs.reconcile');
    }

    public static function preset_label(array $permissions): string {
        $norm = self::normalize_app_permissions($permissions);
        if ($norm === []) {
            return 'No access';
        }
        foreach (self::APP_PRESETS as $preset) {
            $expected = self::normalize_app_permissions($preset['permissions']);
            if ($norm === $expected) {
                return $preset['label'];
            }
        }
        return 'Custom (' . count($norm) . ')';
    }

    public static function permission_labels(array $permissions): array {
        $labels = [];
        foreach (self::normalize_app_permissions($permissions) as $perm) {
            $labels[] = self::APP_PERMISSIONS[$perm]['label'] ?? $perm;
        }
        return $labels;
    }

    /**
     * Produces short human-readable summary lines for UI tables and invite screens.
     *
     * @return list<string>
     */
    public static function summary_lines(string $role, string $scope, array $permissions): array {
        if ($role === 'admin' || $role === 'owner') {
            return ['Full workspace access (all apps, services, servers, and team)'];
        }
        $norm = self::normalize_permissions($permissions, $role, $scope);
        $lines = [];
        if ($scope === 'all' && $norm['all_apps'] !== []) {
            $lines[] = 'All apps · ' . self::preset_label($norm['all_apps']);
        }
        foreach ($norm['apps'] as $app_id => $app_perms) {
            $lines[] = $app_id . ' · ' . self::preset_label($app_perms);
        }
        foreach ($norm['workspace'] as $wp) {
            $lines[] = self::WORKSPACE_PERMISSIONS[$wp]['label'] ?? $wp;
        }
        return $lines !== [] ? $lines : ['No access granted'];
    }

    /**
     * Merges a newly accepted invitation grant into an existing member without revoking prior access.
     *
     * @return array{role: string, scope: string, permissions: array}
     */
    public static function merge_grant(array $existing, array $grant): array {
        $existing_role = ($existing['role'] ?? 'member') === 'admin' ? 'admin' : 'member';
        $grant_role = ($grant['role'] ?? 'member') === 'admin' ? 'admin' : 'member';
        if ($existing_role === 'admin' || $grant_role === 'admin') {
            return [
                'role' => 'admin',
                'scope' => 'all',
                'permissions' => self::normalize_permissions([], 'admin', 'all'),
            ];
        }

        $existing_scope = ($existing['scope'] ?? 'scoped') === 'all' ? 'all' : 'scoped';
        $grant_scope = ($grant['scope'] ?? 'scoped') === 'all' ? 'all' : 'scoped';
        $scope = ($existing_scope === 'all' || $grant_scope === 'all') ? 'all' : 'scoped';

        $e_perms = self::normalize_permissions(
            is_string($existing['permissions'] ?? null) ? (json_decode($existing['permissions'], true) ?: []) : (array) ($existing['permissions'] ?? []),
            'member',
            $existing_scope
        );
        $g_perms = self::normalize_permissions(
            is_string($grant['permissions'] ?? null) ? (json_decode($grant['permissions'], true) ?: []) : (array) ($grant['permissions'] ?? []),
            'member',
            $grant_scope
        );

        $merged_apps = $e_perms['apps'];
        foreach ($g_perms['apps'] as $app_id => $perms) {
            $merged_apps[$app_id] = self::normalize_app_permissions(array_merge($merged_apps[$app_id] ?? [], $perms));
        }

        return [
            'role' => 'member',
            'scope' => $scope,
            'permissions' => self::normalize_permissions([
                'workspace' => array_merge($e_perms['workspace'], $g_perms['workspace']),
                'all_apps' => array_merge($e_perms['all_apps'], $g_perms['all_apps']),
                'apps' => $merged_apps,
            ], 'member', $scope),
        ];
    }

    /**
     * Parses invite / member-edit form submission into validated role, scope, and permissions.
     *
     * @param list<string> $valid_app_ids
     * @return array{role: string, scope: string, permissions: array}
     */
    public static function parse_form_grant(array $post, array $valid_app_ids): array {
        $access_mode = trim((string) ($post['access_mode'] ?? 'scoped'));
        if ($access_mode === 'admin') {
            return [
                'role' => 'admin',
                'scope' => 'all',
                'permissions' => self::normalize_permissions([], 'admin', 'all'),
            ];
        }

        $scope = $access_mode === 'all' ? 'all' : 'scoped';
        $workspace_in = is_array($post['workspace_perms'] ?? null) ? $post['workspace_perms'] : [];
        $all_apps_in = is_array($post['all_app_perms'] ?? null) ? $post['all_app_perms'] : [];
        $apps_in = is_array($post['app_perms'] ?? null) ? $post['app_perms'] : [];

        // Allow quick preset selection when explicit checkboxes were not posted.
        $all_preset = trim((string) ($post['all_app_preset'] ?? ''));
        if ($scope === 'all' && $all_apps_in === [] && isset(self::APP_PRESETS[$all_preset])) {
            $all_apps_in = self::APP_PRESETS[$all_preset]['permissions'];
        }

        $apps = [];
        $allowed_map = array_flip($valid_app_ids);
        foreach ($apps_in as $app_id => $perms) {
            if (!is_string($app_id) || !isset($allowed_map[$app_id]) || !is_array($perms)) {
                continue;
            }
            $clean = self::normalize_app_permissions($perms);
            if ($clean !== []) {
                $apps[$app_id] = $clean;
            }
        }

        $normalized = self::normalize_permissions([
            'workspace' => $workspace_in,
            'all_apps' => $all_apps_in,
            'apps' => $apps,
        ], 'member', $scope);

        if (
            $normalized['workspace'] === []
            && $normalized['all_apps'] === []
            && $normalized['apps'] === []
        ) {
            throw new RuntimeException('Select at least one app or workspace permission.');
        }

        return [
            'role' => 'member',
            'scope' => $scope,
            'permissions' => $normalized,
        ];
    }

    /**
     * Returns the single app ID if a grant targets exactly one app (used for post-accept redirect).
     */
    public static function single_app_target(string $role, string $scope, array $permissions): ?string {
        if ($role === 'admin' || $scope === 'all') {
            return null;
        }
        $apps = is_array($permissions['apps'] ?? null) ? array_keys($permissions['apps']) : [];
        return count($apps) === 1 ? (string) $apps[0] : null;
    }
}
