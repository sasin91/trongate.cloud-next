<?php
declare(strict_types=1);

require_once APPPATH . 'modules/trongate_security/Trongate_security.php';
require_once APPPATH . 'modules/validation/services/Validate.php';
require_once APPPATH . 'modules/security/services/Encryptor.php';
require_once APPPATH . 'modules/apps/services/Build.php';
require_once APPPATH . 'modules/apps/services/Build_commands.php';
require_once APPPATH . 'modules/services/services/Service_spec.php';
require_once APPPATH . 'modules/harbour/services/Module_spec.php';
require_once APPPATH . 'modules/login/github/services/Github_app.php';
require_once APPPATH . 'modules/servers/services/Ssh.php';

class Apps extends Trongate {
    /** @var array<string, array{token: string, source: string}> */
    private array $github_repo_tokens = [];

    function can_app(array $actor, string $app_id, string $permission = 'view'): bool {
        if ($app_id === '') {
            return false;
        }
        if (!empty($actor['is_admin'])) {
            return true;
        }
        return in_array($permission, $this->app_permissions($actor, $app_id), true);
    }

    function app_permissions(array $actor, string $app_id): array {
        $this->module('team');
        if (!empty($actor['is_admin'])) {
            return array_keys(Team::APP_PERMISSIONS);
        }
        $permissions = [];
        if (($actor['scope'] ?? 'scoped') === 'all') {
            $permissions = is_array($actor['permissions']['all_apps'] ?? null)
                ? $actor['permissions']['all_apps']
                : [];
        }
        $specific = $actor['permissions']['apps'][$app_id] ?? null;
        if (is_array($specific) && $specific !== []) {
            $permissions = array_merge($permissions, $specific);
        }
        return $this->team->normalize_app_permissions($permissions);
    }

    function effective_app_permissions(array $input): array {
        if (!is_array($input['actor'] ?? null) || !is_string($input['app_id'] ?? null) || $input['app_id'] === '') {
            throw new InvalidArgumentException('An actor and app ID are required to resolve app permissions.');
        }
        return $this->app_permissions($input['actor'], $input['app_id']);
    }

    function visible_apps(array $actor, array $apps): array {
        if (!empty($actor['is_admin'])) {
            return array_values($apps);
        }
        return array_values(array_filter(
            $apps,
            fn(array $app): bool => $this->can_app($actor, (string) ($app['id'] ?? ''), 'view')
        ));
    }

    function _require_app(string $app_id, string $permission = 'view', string $message = 'You do not have permission to access this application.'): void {
        if (!$this->can_app($this->workspace->_actor(), $app_id, $permission)) {
            $this->trongate_security->_forbidden($message);
        }
    }

    function make_sure_allowed(): void {
        block_url('apps/make_sure_allowed');
        $this->workspace->_actor();
    }

    function index(): void {
        $this->trongate_security->make_sure_allowed('apps');
        $actor = $this->workspace->_actor();
        $all_summaries = $this->model->_app_summaries();
        $summaries = [];
        foreach ($all_summaries as $app_id => $summary) {
            if ($this->can_app($actor, (string) $app_id, 'view')) {
                $summaries[$app_id] = $summary;
            }
        }
        $this->workspace->_render_panel('apps', 'dashboard', 'dashboard', [
            'app_summaries' => $summaries,
        ]);
    }

    function show(): void {
        $this->trongate_security->make_sure_allowed('apps');
        $id = (string) segment(3);
        $this->_require_app($id, 'view');
        try {
            $overview = $this->model->_overview($id);
        } catch (Throwable) {
            http_response_code(404);
            $this->workspace->_render_panel('apps', 'dashboard', 'dashboard', [
                'app_summaries' => $this->_visible_summaries(),
                'message' => 'Application not found.',
            ]);
            return;
        }

        $actor = $this->workspace->_actor();
        $app = $overview['app'];
        $log_data = $this->can_app($actor, (string) $app['id'], 'logs')
            ? $this->_runtime_read($app, ['action' => 'app-logs', 'app' => (string) $app['id'], 'limit' => 1])
            : null;
        $this->workspace->_render_panel('apps', 'show', 'app-overview', [
            'overview' => $overview,
            'log_rates' => is_array($log_data['rates'] ?? null) ? $log_data['rates'] : null,
            'crumbs' => [
                ['/apps', 'Apps'],
                ['/apps/show/' . rawurlencode((string) $app['id']), (string) ($app['label'] ?? $app['id'])],
            ],
        ]);
    }

    function logs(): void {
        $this->trongate_security->make_sure_allowed('apps');
        $id = (string) segment(3);
        $this->_require_app($id, 'logs', 'You do not have permission to view logs for this app.');
        try {
            $overview = $this->model->_overview($id);
        } catch (Throwable) {
            http_response_code(404);
            $this->workspace->_render_panel('apps', 'dashboard', 'dashboard', [
                'app_summaries' => $this->_visible_summaries(),
                'message' => 'Application not found.',
            ]);
            return;
        }

        $app = $overview['app'];
        $logs = $this->_runtime_read($app, ['action' => 'app-logs', 'app' => (string) $app['id'], 'limit' => 250]);
        $this->workspace->_render_panel('apps', 'logs', 'app-logs', [
            'overview' => $overview,
            'app_logs' => $logs,
            'crumbs' => [
                ['/apps', 'Apps'],
                ['/apps/show/' . rawurlencode((string) $app['id']), (string) ($app['label'] ?? $app['id'])],
                ['/apps/logs/' . rawurlencode((string) $app['id']), 'Logs'],
            ],
        ]);
    }

    function logs_poll(): void {
        $this->trongate_security->make_sure_allowed('apps');
        $id = (string) segment(3);
        $this->_require_app($id, 'logs', 'You do not have permission to view logs for this app.');
        try {
            $app_id = Validate::id($id);
            $app = $this->records->_get(['kind' => 'app', 'id' => $app_id]);
        } catch (Throwable) {
            http_response_code(404);
            return;
        }

        $since = is_string($_GET['since'] ?? null) ? trim($_GET['since']) : '';
        $data = $this->_runtime_read($app, [
            'action' => 'app-logs',
            'app' => $app_id,
            'since' => $since,
            'limit' => 250,
        ]);

        $e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $lines = is_array($data['lines'] ?? null) ? $data['lines'] : [];
        $cursor = (string) ($data['cursor'] ?? $since);

        header('Content-Type: text/html; charset=UTF-8');
        echo '<div id="new-log-lines">';
        require __DIR__ . '/views/_log_lines.php';
        echo '</div>';
        echo '<div id="log-cursor">' . $e($cursor) . '</div>';
    }

    function traffic(): void {
        $this->trongate_security->make_sure_allowed('apps');
        $id = (string) segment(3);
        $this->_require_app($id, 'traffic', 'You do not have permission to view live traffic for this app.');
        try {
            $overview = $this->model->_overview($id);
        } catch (Throwable) {
            http_response_code(404);
            $this->workspace->_render_panel('apps', 'dashboard', 'dashboard', [
                'app_summaries' => $this->_visible_summaries(),
                'message' => 'Application not found.',
            ]);
            return;
        }

        $app = $overview['app'];
        $this->workspace->_render_panel('apps', 'traffic', 'app-traffic', [
            'overview' => $overview,
            'crumbs' => [
                ['/apps', 'Apps'],
                ['/apps/show/' . rawurlencode((string) $app['id']), (string) ($app['label'] ?? $app['id'])],
                ['/apps/traffic/' . rawurlencode((string) $app['id']), 'Traffic'],
            ],
        ]);
    }

    function traffic_poll(): void {
        $this->trongate_security->make_sure_allowed('apps');
        $id = (string) segment(3);
        $this->_require_app($id, 'traffic', 'You do not have permission to view live traffic for this app.');
        try {
            $app_id = Validate::id($id);
            $app = $this->records->_get(['kind' => 'app', 'id' => $app_id]);
        } catch (Throwable) {
            $this->trongate_security->_json(['ok' => false], 404);
            return;
        }

        $since = is_string($_GET['since'] ?? null) ? trim($_GET['since']) : '';
        $data = $this->_runtime_read($app, [
            'action' => 'app-traffic',
            'app' => $app_id,
            'since' => $since,
        ]);

        if (!is_array($data) || empty($data['ok'])) {
            $this->trongate_security->_json(['ok' => false, 'cursor' => $since, 'requests' => []]);
            return;
        }

        $this->trongate_security->_json([
            'ok' => true,
            'cursor' => (string) ($data['cursor'] ?? $since),
            'requests' => is_array($data['requests'] ?? null) ? $data['requests'] : [],
        ]);
    }

    private const STARTER_SQL_SETS = [
        'trongate_auth_core' => [
            'label' => 'Auth core',
            'tables' => 'trongate_user_levels, trongate_users, trongate_tokens',
        ],
        'trongate_administrators' => [
            'label' => 'Administrators',
            'tables' => 'trongate_administrators',
        ],
        'trongate_login' => [
            'label' => 'Login & rate limiting',
            'tables' => 'login_attempts, password_resets',
        ],
    ];

    function database(): void {
        $this->trongate_security->make_sure_allowed('apps');
        $id = (string) segment(3);
        $this->_require_app($id, 'database_read', 'You do not have permission to access this app\'s database.');
        try {
            $overview = $this->model->_overview($id);
        } catch (Throwable) {
            http_response_code(404);
            $this->workspace->_render_panel('apps', 'dashboard', 'dashboard', [
                'app_summaries' => $this->_visible_summaries(),
                'message' => 'Application not found.',
            ]);
            return;
        }

        $actor = $this->workspace->_actor();
        $can_write = $this->can_app($actor, (string) $overview['app']['id'], 'database_write');

        $table = is_string($_GET['table'] ?? null) ? trim($_GET['table']) : '';
        $raw_view = is_string($_GET['view'] ?? null) ? trim($_GET['view']) : '';
        if ($raw_view === 'import' && !$can_write) {
            $this->trongate_security->_forbidden('You only have read-only database access on this app.');
        }
        $view = in_array($raw_view, ['data', 'structure', 'sql', 'import'], true)
            ? $raw_view
            : ($table !== '' ? 'data' : 'sql');
        $page = max(1, (int) ($_GET['page'] ?? 1));

        $imported = (string) ($_GET['imported'] ?? '');
        $import_notice = null;
        if ($imported !== '' && ctype_digit($imported)) {
            $n = (int) $imported;
            $import_notice = 'Ran ' . $n . ' SQL ' . ($n === 1 ? 'statement' : 'statements') . ' successfully.';
        }

        $this->_render_database($overview, [
            'db_view' => $view,
            'db_table' => $table !== '' ? $table : null,
            'db_page' => $page,
            'import_notice' => $import_notice,
        ]);
    }

    function database_query(): void {
        $this->trongate_security->make_sure_allowed('apps');
        $this->trongate_security->_csrf();
        $app_id = '';
        $raw_app = trim((string) ($_POST['app'] ?? ''));
        $this->_require_app($raw_app, 'database_read', 'You do not have permission to query this app\'s database.');
        $requested_changes = ($_POST['allow_changes'] ?? '') === '1';
        if ($requested_changes) {
            $this->_require_app($raw_app, 'database_write', 'You only have read-only database access on this app.');
        }
        try {
            $app_id = Validate::id($raw_app);
            $overview = $this->model->_overview($app_id);
        } catch (Throwable) {
            http_response_code(404);
            $this->workspace->_render_panel('apps', 'dashboard', 'dashboard', [
                'app_summaries' => $this->_visible_summaries(),
                'message' => 'Application not found.',
            ]);
            return;
        }

        $actor = $this->workspace->_actor();
        $sql = (string) ($_POST['sql'] ?? '');
        $allow_changes = $requested_changes && $this->can_app($actor, $app_id, 'database_write');
        $query_result = null;
        $query_error = null;

        if (trim($sql) === '') {
            $query_error = 'Enter at least one SQL statement.';
        } else {
            $res = $this->_runtime_read($overview['app'], [
                'action' => 'app-db-query',
                'app' => $app_id,
                'sql' => $sql,
                'allow_changes' => $allow_changes,
                'mode' => 'query',
            ], 25);
            if (!is_array($res)) {
                $query_error = 'Database is unavailable right now. Try again shortly.';
            } elseif (empty($res['ok'])) {
                $query_error = (string) ($res['error'] ?? 'Query failed.');
            } else {
                $query_result = is_array($res['results'] ?? null) ? $res['results'] : [];
            }
        }

        $this->_render_database($overview, [
            'db_view' => 'sql',
            'db_table' => null,
            'db_page' => 1,
            'db_sql' => $sql,
            'db_allow_changes' => $allow_changes,
            'query_result' => $query_result,
            'query_error' => $query_error,
        ]);
    }

    function database_import(): void {
        $this->trongate_security->make_sure_allowed('apps');
        $this->trongate_security->_csrf();
        $app_id = '';
        $raw_app = trim((string) ($_POST['app'] ?? ''));
        $this->_require_app($raw_app, 'database_write', 'You do not have permission to import SQL into this app\'s database.');
        try {
            $app_id = Validate::id($raw_app);
            $overview = $this->model->_overview($app_id);
        } catch (Throwable) {
            http_response_code(404);
            $this->workspace->_render_panel('apps', 'dashboard', 'dashboard', [
                'app_summaries' => $this->_visible_summaries(),
                'message' => 'Application not found.',
            ]);
            return;
        }

        $payload = [
            'action' => 'app-db-query',
            'app' => $app_id,
            'mode' => 'import',
            'allow_changes' => true,
        ];
        $import_error = null;

        $starter = trim((string) ($_POST['starter_set'] ?? ''));
        $release_file = trim((string) ($_POST['release_sql_file'] ?? ''));

        if ($starter !== '') {
            if (!isset(self::STARTER_SQL_SETS[$starter])) {
                $import_error = 'Unknown starter table set.';
            } else {
                $path = __DIR__ . '/sql/' . $starter . '.sql';
                $payload['sql'] = (string) file_get_contents($path);
            }
        } elseif ($release_file !== '') {
            $payload['release_sql_file'] = $release_file;
        } elseif (isset($_FILES['sql_file']) && is_array($_FILES['sql_file']) && (int) ($_FILES['sql_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $err = (int) ($_FILES['sql_file']['error'] ?? UPLOAD_ERR_OK);
            $size = (int) ($_FILES['sql_file']['size'] ?? 0);
            $tmp = (string) ($_FILES['sql_file']['tmp_name'] ?? '');
            if ($err !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
                $import_error = 'Could not read the uploaded SQL file.';
            } elseif ($size > 524288) {
                $import_error = 'SQL file exceeds the 512 KB limit.';
            } else {
                $payload['sql'] = (string) file_get_contents($tmp);
            }
        } else {
            $raw_sql = trim((string) ($_POST['import_sql'] ?? ''));
            if ($raw_sql === '') {
                $import_error = 'Upload a .sql file, paste SQL, or choose a starter set.';
            } else {
                $payload['sql'] = $raw_sql;
            }
        }

        if ($import_error === null) {
            $res = $this->_runtime_read($overview['app'], $payload, 35);
            if (!is_array($res)) {
                $import_error = 'Database is unavailable right now. Try again shortly.';
            } elseif (empty($res['ok'])) {
                $import_error = (string) ($res['error'] ?? 'SQL import failed.');
            } else {
                $executed = (int) ($res['executed'] ?? 0);
                header('Location: /apps/database/' . rawurlencode($app_id) . '?view=import&imported=' . $executed, true, 303);
                exit;
            }
        }

        $this->_render_database($overview, [
            'db_view' => 'import',
            'db_table' => null,
            'db_page' => 1,
            'import_sql' => (string) ($_POST['import_sql'] ?? ''),
            'import_error' => $import_error,
        ]);
    }

    private function _render_database(array $overview, array $extra): void {
        $app = $overview['app'];
        $app_id = (string) $app['id'];
        $table = $extra['db_table'] ?? null;
        $view = (string) ($extra['db_view'] ?? 'sql');
        $page = max(1, (int) ($extra['db_page'] ?? 1));

        $inspect_req = [
            'action' => 'app-db-inspect',
            'app' => $app_id,
            'view' => $view === 'structure' ? 'structure' : 'data',
            'page' => $page,
        ];
        if (is_string($table) && $table !== '') {
            $inspect_req['table'] = $table;
        }
        $db_info = $this->_runtime_read($app, $inspect_req, 12);

        $this->workspace->_render_panel('apps', 'database', 'app-database', array_merge([
            'overview' => $overview,
            'db_info' => $db_info,
            'starter_sets' => self::STARTER_SQL_SETS,
            'crumbs' => [
                ['/apps', 'Apps'],
                ['/apps/show/' . rawurlencode($app_id), (string) ($app['label'] ?? $app_id)],
                ['/apps/database/' . rawurlencode($app_id), 'Database'],
            ],
        ], $extra));
    }

    private function _runtime_read(array $app, array $request, int $timeout = 8): ?array {
        $server_id = (string) ($app['server'] ?? '');
        if ($server_id === '') {
            return null;
        }
        $server = $this->records->_find(['kind' => 'server', 'id' => $server_id]);
        if ($server === null || empty($server['host'])) {
            return null;
        }
        try {
            $response = (new Ssh($this->model))->command($server, 'tc-runtime', self::_encode_json($request), $timeout);
            if ($response['exit'] !== 0) {
                return null;
            }
            $decoded = json_decode($response['out'], true);
            return is_array($decoded) ? $decoded : null;
        } catch (Throwable) {
            return null;
        }
    }

    function deployments(): void {
        $this->trongate_security->make_sure_allowed('apps');
        $id = (string) segment(3);
        $this->_require_app($id, 'deployments', 'You do not have permission to view deployments for this app.');
        try {
            $overview = $this->model->_overview($id, 100);
        } catch (Throwable) {
            http_response_code(404);
            $this->workspace->_render_panel('apps', 'dashboard', 'dashboard', [
                'app_summaries' => $this->_visible_summaries(),
                'message' => 'Application not found.',
            ]);
            return;
        }

        $app = $overview['app'];
        $this->workspace->_render_panel('apps', 'deployments', 'app-deployments', [
            'overview' => $overview,
            'crumbs' => [
                ['/apps', 'Apps'],
                ['/apps/show/' . rawurlencode((string) $app['id']), (string) ($app['label'] ?? $app['id'])],
                ['/apps/deployments/' . rawurlencode((string) $app['id']), 'Deployments'],
            ],
        ]);
    }

    function services(): void {
        $this->trongate_security->make_sure_allowed('apps');
        $id = (string) segment(3);
        $this->_require_app($id, 'services_view', 'You do not have permission to view services for this app.');
        try {
            $overview = $this->model->_overview($id);
        } catch (Throwable) {
            http_response_code(404);
            $this->workspace->_render_panel('apps', 'dashboard', 'dashboard', [
                'app_summaries' => $this->_visible_summaries(),
                'message' => 'Application not found.',
            ]);
            return;
        }

        $flash = $_SESSION['services_flash'] ?? null;
        $error = $_SESSION['services_error'] ?? null;
        unset($_SESSION['services_flash'], $_SESSION['services_error']);

        $this->module('services');
        $actor = $this->workspace->_actor();
        $app = $overview['app'];
        $app_id = (string) $app['id'];
        $can_manage = $this->can_app($actor, $app_id, 'services_manage');
        $this->workspace->_render_panel('apps', 'services', 'app-services', [
            'overview' => $overview,
            'services' => $overview['services'],
            'shared_services' => $overview['shared_services'],
            'presets' => Service_spec::presets(),
            'can_add_service' => $can_manage && $this->services->model->_can_add((string) ($app['server'] ?? '')),
            'saved_notice' => is_string($flash) ? $flash : null,
            'message' => is_string($error) ? $error : null,
            'crumbs' => [
                ['/apps', 'Apps'],
                ['/apps/show/' . rawurlencode($app_id), (string) ($app['label'] ?? $app_id)],
                ['/apps/services/' . rawurlencode($app_id), 'Services'],
            ],
        ]);
    }

    function access(): void {
        $this->trongate_security->make_sure_allowed('apps');
        $this->workspace->_require_workspace('team.manage', 'You do not have permission to manage team access.');
        $id = (string) segment(3);
        try {
            $overview = $this->model->_overview($id);
        } catch (Throwable) {
            http_response_code(404);
            $this->workspace->_render_panel('apps', 'dashboard', 'dashboard', [
                'app_summaries' => $this->_visible_summaries(),
                'message' => 'Application not found.',
            ]);
            return;
        }

        $flash = $_SESSION['team_flash'] ?? null;
        $error = $_SESSION['team_error'] ?? null;
        $invite_link = $_SESSION['team_invite_link'] ?? null;
        unset($_SESSION['team_flash'], $_SESSION['team_error'], $_SESSION['team_invite_link']);

        $this->module('team');
        $app = $overview['app'];
        $app_id = (string) $app['id'];
        $this->workspace->_render_panel('apps', 'access', 'app-access', [
            'overview' => $overview,
            'app_members' => $this->team->model->_app_members($app_id),
            'all_members' => $this->team->model->_members(),
            'app_invitations' => $this->team->model->_pending_invitations($app_id),
            'invite_link' => is_array($invite_link) ? $invite_link : null,
            'saved_notice' => is_string($flash) ? $flash : null,
            'message' => is_string($error) ? $error : null,
            'crumbs' => [
                ['/apps', 'Apps'],
                ['/apps/show/' . rawurlencode($app_id), (string) ($app['label'] ?? $app_id)],
                ['/apps/access/' . rawurlencode($app_id), 'Access'],
            ],
        ]);
    }

    function settings(): void {
        $this->trongate_security->make_sure_allowed('apps');
        $id = (string) segment(3);
        $this->_require_app($id, 'settings', 'You do not have permission to view or edit settings for this app.');
        try {
            $overview = $this->model->_overview($id);
        } catch (Throwable) {
            http_response_code(404);
            $this->workspace->_render_panel('apps', 'dashboard', 'dashboard', [
                'app_summaries' => $this->_visible_summaries(),
                'message' => 'Application not found.',
            ]);
            return;
        }

        $saved = (string) ($_GET['saved'] ?? '');
        $saved_notice = match ($saved) {
            'settings' => 'Settings saved. Deploy now to apply them.',
            'env' => 'Environment variables saved. Deploy now to apply them.',
            'env-deleted' => 'Environment variable removed. Deploy now to apply the change.',
            default => null,
        };
        $gh_flash = $_SESSION['github_flash'] ?? null;
        $gh_error = $_SESSION['github_error'] ?? null;
        unset($_SESSION['github_flash'], $_SESSION['github_error']);
        if ($saved_notice === null && is_string($gh_flash)) {
            $saved_notice = $gh_flash;
        }

        $app = $overview['app'];
        $app_id = (string) $app['id'];
        $this->workspace->_render_panel('apps', 'settings', 'app-settings', [
            'overview' => $overview,
            'saved_notice' => $saved_notice,
            'message' => is_string($gh_error) ? $gh_error : null,
            'github' => $this->_github_ui('apps/settings/' . rawurlencode($app_id), false),
            'crumbs' => [
                ['/apps', 'Apps'],
                ['/apps/show/' . rawurlencode($app_id), (string) ($app['label'] ?? $app_id)],
                ['/apps/settings/' . rawurlencode($app_id), 'Settings'],
            ],
        ]);
    }

    function save_settings(): void {
        $this->trongate_security->make_sure_allowed('apps');
        $this->trongate_security->_csrf();
        $raw_app = trim((string) ($_POST['app'] ?? ''));
        $this->_require_app($raw_app, 'settings', 'You do not have permission to edit settings for this app.');
        $app_id = '';
        try {
            $app_id = Validate::id($raw_app);
            $this->model->_save_settings($app_id, $_POST);
            header('Location: /apps/settings/' . rawurlencode($app_id) . '?saved=settings', true, 303);
            exit;
        } catch (Throwable $e) {
            $this->_render_settings_error($app_id, $e instanceof RuntimeException ? $e->getMessage() : 'Could not save settings.');
        }
    }

    function save_env(): void {
        $this->trongate_security->make_sure_allowed('apps');
        $this->trongate_security->_csrf();
        $raw_app = trim((string) ($_POST['app'] ?? ''));
        $this->_require_app($raw_app, 'settings', 'You do not have permission to edit environment variables for this app.');
        $app_id = '';
        try {
            $app_id = Validate::id($raw_app);
            $this->model->_save_env($app_id, (string) ($_POST['environment'] ?? ''));
            header('Location: /apps/settings/' . rawurlencode($app_id) . '?saved=env', true, 303);
            exit;
        } catch (Throwable $e) {
            $this->_render_settings_error($app_id, $e instanceof RuntimeException ? $e->getMessage() : 'Could not save environment variables.');
        }
    }

    function delete_env(): void {
        $this->trongate_security->make_sure_allowed('apps');
        $this->trongate_security->_csrf();
        $raw_app = trim((string) ($_POST['app'] ?? ''));
        $this->_require_app($raw_app, 'settings', 'You do not have permission to edit environment variables for this app.');
        $app_id = '';
        try {
            $app_id = Validate::id($raw_app);
            $name = (string) ($_POST['name'] ?? '');
            $this->model->_delete_env($app_id, $name);
            header('Location: /apps/settings/' . rawurlencode($app_id) . '?saved=env-deleted', true, 303);
            exit;
        } catch (Throwable $e) {
            $this->_render_settings_error($app_id, $e instanceof RuntimeException ? $e->getMessage() : 'Could not remove environment variable.');
        }
    }

    function modules(): void {
        $this->trongate_security->make_sure_allowed('apps');
        $id = (string) segment(3);
        $this->_require_app($id, 'view');
        try {
            $overview = $this->model->_overview($id);
        } catch (Throwable) {
            http_response_code(404);
            $this->workspace->_render_panel('apps', 'dashboard', 'dashboard', [
                'app_summaries' => $this->_visible_summaries(),
                'message' => 'Application not found.',
            ]);
            return;
        }

        $app = $overview['app'];
        $app_id = (string) $app['id'];
        $metadata = is_array($overview['latest_release']['metadata'] ?? null) ? $overview['latest_release']['metadata'] : [];

        $this->module('harbour');
        $aboard = $this->harbour->model->_identify(is_array($metadata['modules'] ?? null) ? $metadata['modules'] : []);
        $q = trim((string) ($_GET['q'] ?? ''));
        $cat = trim((string) ($_GET['category'] ?? ''));
        $search = $this->harbour->model->_search($q, $cat, 'hybrid', $this->harbour->_embeddings_client());

        $this->workspace->_render_panel('apps', 'modules', 'app-modules', [
            'overview' => $overview,
            'modules_scanned' => array_key_exists('modules', $metadata),
            'harbour_installed' => $aboard['installed'],
            'own_modules' => $aboard['own'],
            'registry_results' => $search['results'],
            'registry_categories' => Module_spec::CATEGORIES,
            'registry_query' => $q,
            'registry_category' => $cat,
            'registry_fallback_notice' => $search['fallback_notice'],
            'crumbs' => [
                ['/apps', 'Apps'],
                ['/apps/show/' . rawurlencode($app_id), (string) ($app['label'] ?? $app_id)],
                ['/apps/modules/' . rawurlencode($app_id), 'Modules'],
            ],
        ]);
    }

    private function _render_settings_error(string $app_id, string $message): void {
        if ($app_id !== '') {
            try {
                $overview = $this->model->_overview($app_id);
                $this->workspace->_render_panel('apps', 'settings', 'app-settings', [
                    'overview' => $overview,
                    'message' => $message,
                    'github' => $this->_github_ui('apps/settings/' . rawurlencode($app_id), false),
                    'crumbs' => [
                        ['/apps', 'Apps'],
                        ['/apps/show/' . rawurlencode($app_id), (string) ($overview['app']['label'] ?? $app_id)],
                        ['/apps/settings/' . rawurlencode($app_id), 'Settings'],
                    ],
                ]);
                return;
            } catch (Throwable) {
                // Fall through to dashboard.
            }
        }
        $this->workspace->_render_panel('apps', 'dashboard', 'dashboard', [
            'app_summaries' => $this->_visible_summaries(),
            'message' => $message,
        ]);
    }

    function deploy(): void {
        $this->trongate_security->make_sure_allowed('apps');
        $this->trongate_security->_csrf();
        $raw_app = trim((string) ($_POST['app'] ?? ''));
        $this->_require_app($raw_app, 'deploy', 'You do not have permission to deploy this app.');
        $app_id = '';
        try {
            $app_id = Validate::id($raw_app);
            $app = $this->records->_get(['kind' => 'app', 'id' => $app_id]);
            $canary = ($_POST['canary'] ?? '') === '1';
            $canary_weight = max(0, min(90, (int) ($_POST['canary_weight'] ?? 10)));

            $repository = trim((string) ($app['repository'] ?? ''));
            $branch = trim((string) ($app['branch'] ?? 'main'));
            if ($repository !== '') {
                $repo_clean = self::_repository($repository);
                $commit = $this->_github($repo_clean . '/commits/' . rawurlencode($branch !== '' ? $branch : 'main'));
                $source = $this->_release_metadata($repo_clean, $commit, $branch !== '' ? $branch : 'main');
                $operation = $this->model->_queue_redeploy_latest($app_id, $source, $canary, $canary_weight);
            } else {
                $releases = array_values(array_filter(
                    $this->records->_all('release'),
                    static fn($r) => ($r['app'] ?? '') === $app_id
                ));
                usort($releases, static fn($a, $b) => ($b['created'] ?? 0) <=> ($a['created'] ?? 0));
                if ($releases === []) {
                    throw new RuntimeException('No release available to deploy yet.');
                }
                $this->module('deployments');
                $payload = ['app' => $app_id, 'release' => (string) $releases[0]['id']];
                if ($canary) {
                    $payload['canary'] = true;
                    $payload['canary_weight'] = $canary_weight;
                }
                $operation = $this->deployments->model->_queue('deploy', $payload);
            }
            header('Location: /deployments?operation=' . rawurlencode($operation), true, 303);
            exit;
        } catch (Throwable $e) {
            $message = $e instanceof RuntimeException ? $e->getMessage() : 'Could not start deployment.';
            if ($app_id !== '') {
                try {
                    $overview = $this->model->_overview($app_id);
                    $this->workspace->_render_panel('apps', 'show', 'app-overview', [
                        'overview' => $overview,
                        'message' => $message,
                        'crumbs' => [
                            ['/apps', 'Apps'],
                            ['/apps/show/' . rawurlencode($app_id), (string) ($overview['app']['label'] ?? $app_id)],
                        ],
                    ]);
                    return;
                } catch (Throwable) {
                    // Fall through to dashboard.
                }
            }
            $this->workspace->_render_panel('apps', 'dashboard', 'dashboard', [
                'app_summaries' => $this->_visible_summaries(),
                'message' => $message,
            ]);
        }
    }

    function create(): void {
        $this->trongate_security->make_sure_allowed('apps');
        $this->workspace->_require_workspace('apps.create', 'You do not have permission to create new apps.');
        $this->_restore_draft();
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_SESSION['app_draft']) && isset($_SESSION['launch_complete'])) {
            unset($_SESSION['launch_request'], $_SESSION['launch_complete']);
        }
        $_SESSION['launch_request'] ??= bin2hex(random_bytes(16));
        $message = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                $this->_launch();
            } catch (Throwable $e) {
                $message = $e instanceof RuntimeException ? $e->getMessage() : 'Invalid input';
            }
        } elseif (isset($_SESSION['app_draft'])) {
            $_POST = json_decode(Encryptor::decrypt($_SESSION['app_draft'], APP_KEY), true, 512, JSON_THROW_ON_ERROR);
            if (isset($_SESSION['draft_server'])) {
                $_POST['server'] = $_SESSION['draft_server'];
                unset($_SESSION['draft_server']);
            }
        }
        $gh_flash = $_SESSION['github_flash'] ?? null;
        $gh_error = $_SESSION['github_error'] ?? null;
        unset($_SESSION['github_flash'], $_SESSION['github_error']);
        if ($message === null && is_string($gh_error)) {
            $message = $gh_error;
        }
        $this->workspace->_render_panel('apps', 'wizard', 'new-app', [
            'message' => $message,
            'saved_notice' => is_string($gh_flash) ? $gh_flash : null,
            'github' => $this->_github_ui('apps/create', true),
        ]);
    }

    function branches(): void {
        $this->trongate_security->make_sure_allowed('apps');
        $this->workspace->_require_workspace('apps.create', 'You do not have permission to list repository branches.');
        try {
            $repository = self::_repository((string) ($_GET['repository'] ?? ''));
            $raw = $this->_github($repository . '/branches?per_page=30');
            $names = [];
            if (is_array($raw) && array_is_list($raw)) {
                foreach ($raw as $entry) {
                    if (is_array($entry) && is_string($entry['name'] ?? null) && $entry['name'] !== '') {
                        $names[] = $entry['name'];
                    }
                }
            }
            $this->trongate_security->_json(['branches' => $names]);
        } catch (Throwable) {
            $this->trongate_security->_json(['branches' => []]);
        }
    }

    function draft(): void {
        $this->trongate_security->make_sure_allowed('apps');
        $this->trongate_security->_csrf();
        $this->workspace->_require_workspace('apps.create', 'You do not have permission to create new apps.');
        $fields = ['repository', 'branch', 'auto_deploy', 'environment', 'label', 'php_version', 'build_commands', 'php_extensions', 'health', 'wizard_step'];
        $draft = array_intersect_key($_POST, array_flip($fields));
        if (strlen(self::_encode_json($draft)) > 131072) {
            $this->trongate_security->_json(['error' => 'Draft is too large.'], 422);
            return;
        }
        $_SESSION['app_draft'] = Encryptor::encrypt(self::_encode_json($draft), APP_KEY);
        $_SESSION['launch_request'] ??= bin2hex(random_bytes(16));
        $this->model->_save_draft($this->_draft_owner(), $_SESSION['app_draft'], $_SESSION['launch_request']);
        $this->trongate_security->_json(['saved' => true]);
    }

    function validate_step(): void {
        $this->trongate_security->make_sure_allowed('apps');
        $this->trongate_security->_csrf();
        $this->workspace->_require_workspace('apps.create', 'You do not have permission to create new apps.');

        $step = (int) ($_POST['wizard_step'] ?? 0);
        if ($step < 0 || $step > 5) {
            $this->trongate_security->_json(['repository' => ['Unknown setup step.']], 422);
            return;
        }

        $errors = [];
        $attrs = [
            'data-ok' => '1',
            'data-step' => (string) $step,
            'data-next-step' => (string) min(5, $step + 1),
        ];

        $draft_updates = [];

        if ($step === 0) {
            try {
                $parsed = self::_repository_and_branch((string) ($_POST['repository'] ?? ''));
                $repository = $parsed['repository'];
            } catch (RuntimeException) {
                $this->trongate_security->_json([
                    'repository' => ['Enter a public GitHub repository as owner/repository or https://github.com/owner/repository.'],
                ], 422);
                return;
            }

            try {
                $repo_info = $this->_github($repository);
            } catch (RuntimeException $e) {
                $this->trongate_security->_json(['repository' => [$e->getMessage()]], 422);
                return;
            }

            $attrs['data-repository'] = $repository;
            $draft_updates['repository'] = $repository;

            $repo_auth = $this->_github_repo_auth($repository);
            if ($repo_auth['source'] === 'installation') {
                $attrs['data-github-installed'] = '1';
            }

            $suggested_branch = $parsed['branch'] ?? (is_string($repo_info['default_branch'] ?? null) ? $repo_info['default_branch'] : '');
            if ($suggested_branch !== '') {
                $attrs['data-branch'] = $suggested_branch;
                if ($parsed['branch'] !== null) {
                    $attrs['data-branch-explicit'] = '1';
                }
            }

            try {
                $branch_rows = $this->_github($repository . '/branches?per_page=30');
                if (is_array($branch_rows) && array_is_list($branch_rows)) {
                    $branch_names = [];
                    foreach ($branch_rows as $entry) {
                        if (is_array($entry) && is_string($entry['name'] ?? null) && $entry['name'] !== '') {
                            $branch_names[] = $entry['name'];
                        }
                    }
                    if ($branch_names !== []) {
                        $attrs['data-branches'] = implode("\n", $branch_names);
                    }
                }
            } catch (RuntimeException) {
                // Branch suggestions are optional; manual entry always works.
            }

            $suggested_label = self::_suggest_label($repository);
            if ($suggested_label !== '') {
                $attrs['data-label'] = $suggested_label;
            }
        } elseif ($step === 1) {
            try {
                $repository = self::_repository((string) ($_POST['repository'] ?? ''));
            } catch (RuntimeException) {
                $this->trongate_security->_json([
                    'repository' => ['Enter a valid public GitHub repository before choosing a branch.'],
                ], 422);
                return;
            }

            $branch = trim((string) ($_POST['branch'] ?? ''));
            if ($branch === '' || strlen($branch) > 200) {
                $this->trongate_security->_json(['branch' => ['Choose a branch or commit.']], 422);
                return;
            }

            try {
                $commit = $this->_github($repository . '/commits/' . rawurlencode($branch));
                Validate::match((string) ($commit['sha'] ?? ''), '/^[a-f0-9]{40}$/D', 'source commit');
            } catch (RuntimeException) {
                $this->trongate_security->_json([
                    'branch' => ['Could not find branch or commit "' . $branch . '" in ' . $repository . '.'],
                ], 422);
                return;
            }

            $attrs['data-repository'] = $repository;
            $attrs['data-branch'] = $branch;
            $attrs['data-commit'] = substr((string) $commit['sha'], 0, 7);
            $draft_updates['repository'] = $repository;
            $draft_updates['branch'] = $branch;

            $suggestion = $this->_inspect_repo_build($repository, $branch);
            if ($suggestion['commands'] !== '') {
                $attrs['data-build-commands'] = $suggestion['commands'];
            }
            if ($suggestion['php_extensions'] !== []) {
                $attrs['data-php-extensions'] = implode(', ', $suggestion['php_extensions']);
            }
            if ($suggestion['found'] !== []) {
                $attrs['data-build-found'] = implode(', ', $suggestion['found']);
            }
        } elseif ($step === 2) {
            $env_error = self::_validate_environment((string) ($_POST['environment'] ?? ''));
            if ($env_error !== null) {
                $this->trongate_security->_json(['environment' => [$env_error]], 422);
                return;
            }
        } elseif ($step === 3) {
            $label_error = $this->_validate_label((string) ($_POST['label'] ?? ''));
            if ($label_error !== null) {
                $this->trongate_security->_json(['label' => [$label_error]], 422);
                return;
            }
            $label = trim((string) ($_POST['label'] ?? ''));
            $attrs['data-label'] = $label;
            require_once APPPATH . 'modules/apps/Apps_model.php';
            $attrs['data-domain'] = $label . '.' . Apps_model::apps_domain();
            $draft_updates['label'] = $label;
        } elseif ($step === 4) {
            $cmd_error = Build_commands::error((string) ($_POST['build_commands'] ?? ''));
            if ($cmd_error !== null) {
                $errors['build_commands'] = [$cmd_error];
            }
            $ext_error = self::_validate_extensions((string) ($_POST['php_extensions'] ?? ''));
            if ($ext_error !== null) {
                $errors['php_extensions'] = [$ext_error];
            }
            if ($errors !== []) {
                $this->trongate_security->_json($errors, 422);
                return;
            }
        } elseif ($step === 5) {
            try {
                self::_repository((string) ($_POST['repository'] ?? ''));
            } catch (RuntimeException) {
                $errors['repository'] = ['Enter a public GitHub repository as owner/repository or https://github.com/owner/repository.'];
            }
            $branch = trim((string) ($_POST['branch'] ?? ''));
            if ($branch === '' || strlen($branch) > 200) {
                $errors['branch'] = ['Choose a branch or commit.'];
            }
            $env_error = self::_validate_environment((string) ($_POST['environment'] ?? ''));
            if ($env_error !== null) {
                $errors['environment'] = [$env_error];
            }
            $label_error = $this->_validate_label((string) ($_POST['label'] ?? ''));
            if ($label_error !== null) {
                $errors['label'] = [$label_error];
            }
            $cmd_error = Build_commands::error((string) ($_POST['build_commands'] ?? ''));
            if ($cmd_error !== null) {
                $errors['build_commands'] = [$cmd_error];
            }
            $ext_error = self::_validate_extensions((string) ($_POST['php_extensions'] ?? ''));
            if ($ext_error !== null) {
                $errors['php_extensions'] = [$ext_error];
            }
            $health = trim((string) ($_POST['health'] ?? '/'));
            if ($health === '') {
                $health = '/';
            }
            try {
                Validate::match($health, '~^/[a-zA-Z0-9/_.-]*$~D', 'health check path');
            } catch (RuntimeException) {
                $errors['health'] = ['Health check path must start with / and contain only letters, numbers, /, _, ., or -.'];
            }
            if ($errors === []) {
                $ready_host = false;
                foreach ($this->records->_all('server') as $candidate) {
                    if (!empty($candidate['application_ready']) && !empty($candidate['local_builder_ready'])) {
                        $ready_host = true;
                        break;
                    }
                }
                if (!$ready_host) {
                    $errors['health'] = ['Application hosting is not ready yet. Try again shortly.'];
                }
            }
            if ($errors !== []) {
                $this->trongate_security->_json($errors, 422);
                return;
            }
            $attrs['data-launch'] = '1';
        }

        // Persist the validated draft state so leaving and returning keeps progress.
        $fields = ['repository', 'branch', 'auto_deploy', 'environment', 'label', 'php_version', 'build_commands', 'php_extensions', 'health'];
        $draft = array_intersect_key($_POST, array_flip($fields));
        foreach ($draft_updates as $k => $v) {
            $draft[$k] = $v;
        }
        if ($step === 0 && isset($attrs['data-branch']) && (trim((string) ($draft['branch'] ?? '')) === '' || isset($attrs['data-branch-explicit']))) {
            $draft['branch'] = $attrs['data-branch'];
        }
        if ($step === 0 && isset($attrs['data-label']) && trim((string) ($draft['label'] ?? '')) === '') {
            $draft['label'] = $attrs['data-label'];
        }
        if ($step === 1 && isset($attrs['data-build-commands']) && trim((string) ($draft['build_commands'] ?? '')) === '') {
            $draft['build_commands'] = $attrs['data-build-commands'];
        }
        if ($step === 1 && isset($attrs['data-php-extensions']) && trim((string) ($draft['php_extensions'] ?? '')) === '') {
            $draft['php_extensions'] = $attrs['data-php-extensions'];
        }
        $draft['wizard_step'] = $attrs['data-next-step'];
        if (strlen(self::_encode_json($draft)) <= 131072) {
            $_SESSION['app_draft'] = Encryptor::encrypt(self::_encode_json($draft), APP_KEY);
            $_SESSION['launch_request'] ??= bin2hex(random_bytes(16));
            $this->model->_save_draft($this->_draft_owner(), $_SESSION['app_draft'], $_SESSION['launch_request']);
        }

        header('Content-Type: text/html; charset=UTF-8');
        $html = '<div id="wz-step-state" hidden';
        foreach ($attrs as $name => $val) {
            $encoded = str_replace("\n", '&#10;', htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
            $html .= ' ' . $name . '="' . $encoded . '"';
        }
        echo $html . '></div>';
    }

    function destroy(): void {
        $this->trongate_security->make_sure_allowed('apps');
        $this->trongate_security->_csrf();
        $raw_app = trim((string) ($_POST['app'] ?? ''));
        $this->_require_app($raw_app, 'delete', 'You do not have permission to delete this app.');
        try {
            $app = Validate::id($raw_app);
            $purge_data = ($_POST['purge_data'] ?? '') === '1';
            $this->module('deployments');
            $operation = $purge_data
                ? $this->deployments->model->_queue_destroy($app, true)
                : $this->deployments->model->_queue_destroy($app);
            header('Location: /deployments?operation=' . rawurlencode($operation), true, 303);
            exit;
        } catch (Throwable $e) {
            $message = $e instanceof RuntimeException ? $e->getMessage() : 'Could not delete app.';
            $this->workspace->_render_panel('apps', 'dashboard', 'dashboard', ['message' => $message]);
        }
    }

    private function _visible_summaries(): array {
        $actor = $this->workspace->_actor();
        $out = [];
        foreach ($this->model->_app_summaries() as $app_id => $summary) {
            if ($this->can_app($actor, (string) $app_id, 'view')) {
                $out[$app_id] = $summary;
            }
        }
        return $out;
    }

    // Internal HMVC entry points: Core blocks underscore methods from URL dispatch.
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

    function _launch(): void {
        if (!hash_equals($_SESSION['launch_request'], (string) ($_POST['request'] ?? ''))) {
            throw new RuntimeException('This launch form has expired. Reload and try again.');
        }
        $operation = $this->_plan_launch($_POST, $_SESSION['launch_request']);
        $actor = $this->workspace->_optional_actor();
        if (is_array($actor) && empty($actor['is_owner']) && empty($actor['is_admin']) && (int) ($actor['member_id'] ?? 0) > 0) {
            $app_id = Validate::id(trim((string) ($_POST['label'] ?? '')));
            $this->module('team');
            $this->team->model->_set_member_app_permissions(
                (int) $actor['member_id'],
                $app_id,
                Team::APP_PRESETS['maintainer']['permissions']
            );
        }
        $this->model->_delete_draft($this->_draft_owner());
        unset($_SESSION['app_draft']);
        $_SESSION['launch_complete'] = true;
        header('Location: /deployments?operation=' . rawurlencode($operation), true, 303);
        exit;
    }

    function _display(string $view, array $data): void {
        $actor = is_array($data['actor'] ?? null) ? $data['actor'] : null;
        $app_id = (string) ($data['overview']['app']['id'] ?? '');
        $app_permissions = [
            'can_deploy' => 'deploy',
            'can_deployments' => 'deployments',
            'can_database' => 'database_read',
            'can_db_write' => 'database_write',
            'can_logs' => 'logs',
            'can_traffic' => 'traffic',
            'can_services' => 'services_view',
            'can_services_view' => 'services_view',
            'can_services_manage' => 'services_manage',
            'can_manage_services' => 'services_manage',
            'can_settings' => 'settings',
            'can_delete' => 'delete',
        ];
        foreach ($app_permissions as $key => $permission) {
            $data[$key] = $actor === null || ($app_id !== '' && $this->can_app($actor, $app_id, $permission));
        }
        $data += [
            'actor' => $actor,
            'can_team' => $actor === null || $this->workspace->can_workspace($actor, 'team.manage'),
            'can_shared_services_view' => $actor === null || $this->workspace->can_workspace($actor, 'services.view'),
            'can_shared_services_manage' => $actor === null || $this->workspace->can_workspace($actor, 'services.manage'),
            'can_create_apps' => $actor === null || $this->workspace->can_workspace($actor, 'apps.create'),
            'can_jobs' => $actor === null || $this->jobs->_can_access_page($actor),
        ];
        $data['viewData'] = $data;
        $this->view($view, $data);
    }

    static function _repository(string $value): string {
        return self::_repository_and_branch($value)['repository'];
    }

    /**
     * Parses owner/repo or a github.com URL (with optional .git or /tree/<branch>).
     *
     * @return array{repository: string, branch: ?string}
     */
    static function _repository_and_branch(string $value): array {
        $trimmed = trim($value);
        if (preg_match('~^https?://~i', $trimmed)) {
            if (!preg_match('~^https?://(?:www\.)?github\.com/(.+)$~i', $trimmed, $m)) {
                throw new RuntimeException('Invalid public GitHub repository');
            }
            $trimmed = $m[1];
        } else {
            $trimmed = (string) preg_replace('~^(?:www\.)?github\.com/~i', '', $trimmed);
        }

        $trimmed = ltrim(rtrim($trimmed, '/'), '/');
        $branch = null;
        if (preg_match('~^([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+)/tree/(.+)$~', $trimmed, $m)) {
            $trimmed = $m[1];
            $branch = trim($m[2], '/');
            if ($branch === '' || strlen($branch) > 200) {
                $branch = null;
            }
        }

        $repository = Validate::match(
            (string) preg_replace('~\.git$~i', '', $trimmed),
            '~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~D',
            'public GitHub repository'
        );

        return ['repository' => $repository, 'branch' => $branch];
    }

    static function _suggest_label(string $repository): string {
        $parts = explode('/', $repository, 2);
        $name = strtolower($parts[1] ?? $repository);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $name), '-');
        if ($slug === '') {
            return '';
        }
        if (!preg_match('/^[a-z]/', $slug)) {
            $slug = 'app-' . $slug;
        }
        return rtrim(substr($slug, 0, 40), '-');
    }

    static function _validate_environment(string $raw): ?string {
        foreach (preg_split('/\r?\n/', trim($raw)) ?: [] as $line) {
            if ($line === '') {
                continue;
            }
            if (!str_contains($line, '=')) {
                return 'Environment variables must use NAME=value, one per line.';
            }
            [$name] = explode('=', $line, 2);
            if (!preg_match('/^[A-Z_][A-Z0-9_]*$/D', $name)) {
                return 'Invalid environment variable name "' . $name . '". Use uppercase letters, numbers, and underscores.';
            }
        }
        return null;
    }

    static function _validate_extensions(string $raw): ?string {
        $items = preg_split('/[\s,]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($items as $item) {
            $name = strtolower($item);
            if (in_array($name, Build_commands::BASE_EXTENSIONS, true)) {
                continue;
            }
            if (!preg_match('/^[a-z][a-z0-9_]{0,39}$/', $name) || !in_array($name, Build_commands::OPTIONAL_EXTENSIONS, true)) {
                return 'Unsupported PHP extension: ' . $name . '. Available optional extensions: '
                    . implode(', ', Build_commands::OPTIONAL_EXTENSIONS) . '.';
            }
        }
        return null;
    }

    function _validate_label(string $raw): ?string {
        $label = trim($raw);
        if (!preg_match('/^[a-z][a-z0-9-]{0,39}$/D', $label)) {
            return 'App name must start with a lowercase letter and use only lowercase letters, numbers, and hyphens (max 40 characters).';
        }
        foreach ($this->records->_all('app') as $existing) {
            if (($existing['id'] ?? '') === $label) {
                return 'This app name is already in use. Choose another name.';
            }
        }
        return null;
    }

    /**
     * Inspects a public GitHub repo root at $branch for package.json/lockfiles/composer.json.
     * Failures are non-fatal so API rate limits never block the wizard.
     *
     * @return array{commands: string, php_extensions: list<string>, found: list<string>}
     */
    function _inspect_repo_build(string $repository, string $branch): array {
        $empty = ['commands' => '', 'php_extensions' => [], 'found' => []];
        try {
            $entries = $this->_github($repository . '/contents?ref=' . rawurlencode($branch));
        } catch (RuntimeException) {
            return $empty;
        }
        if (!is_array($entries) || !array_is_list($entries)) {
            return $empty;
        }

        $root_files = [];
        foreach ($entries as $entry) {
            if (is_array($entry) && ($entry['type'] ?? '') === 'file' && is_string($entry['name'] ?? null)) {
                $root_files[] = $entry['name'];
            }
        }

        $package_json = in_array('package.json', $root_files, true)
            ? $this->_github_file($repository, 'package.json', $branch)
            : '';
        $composer_json = in_array('composer.json', $root_files, true)
            ? $this->_github_file($repository, 'composer.json', $branch)
            : '';

        return Build_commands::suggest($root_files, $package_json, $composer_json);
    }

    function _github_file(string $repository, string $path, string $ref): string {
        try {
            $file = $this->_github($repository . '/contents/' . $path . '?ref=' . rawurlencode($ref));
        } catch (RuntimeException) {
            return '';
        }
        if (!is_array($file) || ($file['encoding'] ?? '') !== 'base64' || !is_string($file['content'] ?? null)) {
            return '';
        }
        if ((int) ($file['size'] ?? 0) > 262144) {
            return '';
        }
        $decoded = base64_decode($file['content'], true);
        return is_string($decoded) ? $decoded : '';
    }

    function _github(string $path): array {
        $headers = [
            'Accept' => 'application/vnd.github+json',
            'User-Agent' => 'Trongate-Cloud-Next',
        ];
        $repo = '';
        if (preg_match('~^([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+)(?:[/?]|$)~', ltrim($path, '/'), $match) === 1) {
            $repo = $match[1];
        }
        $auth = $repo !== '' ? $this->_github_repo_auth($repo) : ['token' => '', 'source' => 'anonymous'];
        if ($auth['token'] !== '') {
            $headers['Authorization'] = 'Bearer ' . $auth['token'];
        }
        $is_authenticated = $auth['token'] !== '';

        try {
            $response = $this->http->request(
                'GET',
                'https://api.github.com/repos/' . $path,
                $headers,
                null,
                5,
                15
            );
        } catch (RuntimeException) {
            throw new RuntimeException(
                $is_authenticated
                    ? 'Could not read the GitHub repository. Check connectivity and try again.'
                    : 'Could not read the public GitHub repository. Check connectivity and try again.'
            );
        }
        if ($response['status'] !== 200) {
            throw new RuntimeException(
                $is_authenticated
                    ? 'Could not read the GitHub repository. Check that the trongate.cloud GitHub App has access to this repository and branch.'
                    : 'Could not read the public GitHub repository. Check the repository and branch, or try again after the API rate limit resets.'
            );
        }
        return json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Resolves an optional GitHub bearer token (App installation token or connected user token)
     * without touching the database when no user session exists.
     *
     * @return array{token: string, source: string}
     */
    private function _github_repo_auth(string $repository): array {
        $key = strtolower($repository);
        if (isset($this->github_repo_tokens[$key])) {
            return $this->github_repo_tokens[$key];
        }

        $app = Github_app::from_config();
        try {
            $token = $app->repository_token($repository, ['contents' => 'read']);
            if ($token !== '') {
                return $this->github_repo_tokens[$key] = ['token' => $token, 'source' => 'installation'];
            }
        } catch (Throwable) {
            // Fall through to user token or anonymous public read.
        }

        $has_session_token = !empty($_SESSION['trongatetoken'])
            || !empty($_COOKIE['trongatetoken'])
            || !empty($_SERVER['HTTP_TRONGATETOKEN']);
        if ($has_session_token) {
            try {
                $actor = $this->workspace->_optional_actor();
                $user_id = is_array($actor) ? (int) ($actor['user_id'] ?? 0) : 0;
                if ($user_id > 0) {
                    $this->module('login-github');
                    $this->github->_use_http_client($this->http);
                    $token_data = $this->github->model->_load_token($user_id);
                    if (is_array($token_data) && !empty($token_data['token'])) {
                        if (!empty($token_data['expires_at']) && (int) $token_data['expires_at'] < time() + 60) {
                            $token_data = $this->github->_refresh_user_token($user_id, $token_data);
                        }
                        if (is_array($token_data) && !empty($token_data['token'])) {
                            return $this->github_repo_tokens[$key] = ['token' => (string) $token_data['token'], 'source' => 'user'];
                        }
                    }
                }
            } catch (Throwable) {
                // Fall through to anonymous public read.
            }
        }

        return $this->github_repo_tokens[$key] = ['token' => '', 'source' => 'anonymous'];
    }

    private function _github_ui(string $return_path, bool $load_repositories): array {
        $actor = $this->workspace->_optional_actor();
        $user_id = is_array($actor) ? (int) ($actor['user_id'] ?? 0) : 0;
        $this->module('login-github');
        $this->github->_use_http_client($this->http);
        return $this->github->_ui_context($user_id, $return_path, $load_repositories);
    }

    function _release_metadata(string $repository, array $commit, string $branch = ''): array {
        $repository = self::_repository($repository);
        $sha = Validate::match((string) ($commit['sha'] ?? ''), '/^[a-f0-9]{40}$/D', 'source commit');
        $metadata = [
            'repository' => $repository,
            'commit' => $sha,
            'branch' => $branch,
            'message' => substr((string) ($commit['commit']['message'] ?? ''), 0, 8000),
            'author' => substr((string) ($commit['commit']['author']['name'] ?? ''), 0, 200),
            'committed_at' => (string) ($commit['commit']['committer']['date'] ?? ''),
        ];
        // PR enrichment is optional: rate limits must never block a build.
        try {
            $pulls = $this->_github($repository . '/commits/' . $sha . '/pulls');
            foreach ($pulls as $pull) {
                if (!empty($pull['merged_at']) && ($pull['merge_commit_sha'] ?? '') === $sha) {
                    $metadata['pull_request'] = [
                        'number' => (int) $pull['number'],
                        'title' => substr((string) $pull['title'], 0, 500),
                    ];
                    break;
                }
            }
        } catch (RuntimeException) {
            // The immutable commit message remains available without PR metadata.
        }
        return $metadata;
    }

    function _plan_launch(array $input, string $request): string {
        $existing = $this->records->_find(['kind' => 'launch', 'id' => $request]);
        if ($existing !== null) {
            return $existing['operation'];
        }
        $repository = self::_repository((string) ($input['repository'] ?? ''));
        $branch = trim((string) ($input['branch'] ?? 'main'));
        if ($branch === '' || strlen($branch) > 200) {
            throw new RuntimeException('Choose a branch or commit.');
        }
        $commit = $this->_github($repository . '/commits/' . rawurlencode($branch));
        $source = $this->_release_metadata($repository, $commit, $branch);
        return $this->model->_plan_launch($input, $request, $source);
    }

    function _queue_build(array $p): string {
        $builder = Validate::id((string) ($p['builder'] ?? ''));
        $this->records->_get(['kind' => 'server', 'id' => $builder]);
        return $this->model->enqueue('build', 'builder:' . $builder, [
            'builder' => $builder,
            'plan' => Build::plan($p),
        ]);
    }

    function _build(Job_context $context): array {
        $id = $context->id;
        $p = $context->payload;
        $heartbeat = $context->heartbeat(...);
        $server = $this->records->_get(['kind' => 'server', 'id' => $p['builder']]);
        $input = $p['plan'] + ['action' => 'build', 'operation' => $id];
        $repository = (string) ($p['plan']['repository'] ?? '');
        if ($repository !== '') {
            $app = Github_app::from_config();
            try {
                $token = $app->repository_token($repository, ['contents' => 'read']);
                if ($token !== '') {
                    $input['github_token'] = $token;
                }
            } catch (Github_api_error $e) {
                if ($e->getCode() !== 404) {
                    throw new RuntimeException('Could not authenticate with GitHub for ' . $repository . '.');
                }
            }
        }
        $r = (new Ssh($this->model))->command($server, 'tc-runtime', self::_encode_json($input), 3600, $heartbeat);
        $result = json_decode($r['out'], true);
        if ($r['exit'] !== 0 || !is_array($result)) {
            throw new RuntimeException('Builder interrupted');
        }
        if (($result['status'] ?? '') === 'succeeded' && isset($p['launch'])) {
            $result['deployment'] = $this->model->_deploy_built($id, $p['launch'], $result);
        }
        return $result;
    }

    private static function _encode_json(array $value): string {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
