<?php
declare(strict_types=1);

require_once APPPATH . 'modules/trongate_security/Trongate_security.php';
require_once APPPATH . 'modules/validation/services/Validate.php';
require_once APPPATH . 'modules/servers/services/Ssh.php';
require_once APPPATH . 'modules/news/services/Markdown.php';
require_once APPPATH . 'modules/harbour/services/Module_spec.php';
require_once APPPATH . 'modules/harbour/services/Registry_embeddings.php';
require_once APPPATH . 'modules/harbour/services/Git_http_backend.php';

/**
 * ZFS-backed Trongate Module Harbour.
 *
 * Serves:
 *  - Public Harbour UI (`/harbour`, `/harbour/show/<ns>/<name>`) with EmbeddingGemma 2 hybrid search
 *  - Public JSON API (`/api/v1/modules`, `/api/v1/modules/<ns>/<name>`)
 *  - Immutable archive download fallback (`/archives/<ns>/<name>/<ver>.tar.gz`)
 *  - Unprivileged Smart HTTP Git (`/git/<ns>/<name>.git/...`) with author-scoped tokens
 *  - Authenticated management UI (`/harbour/manage`) & worker jobs (`_init_repo`, `_publish`, `_yank`)
 */
class Harbour extends Trongate {
    private ?Http_client $http_override = null;

    function make_sure_allowed(): void {
        block_url('harbour/make_sure_allowed');
        $this->workspace->_actor();
    }

    function _use_http_client(Http_client $client): void {
        $this->http_override = $client;
    }

    function index(): void {
        $query = trim((string) ($_GET['q'] ?? ''));
        $category = trim((string) ($_GET['category'] ?? ''));
        $mode = trim((string) ($_GET['mode'] ?? 'hybrid'));

        $embeddings = $this->_embeddings_client();
        $search = $this->model->_search($query, $category, $mode, $embeddings);
        $stats = $this->model->_embedding_stats($embeddings);

        $this->_render_harbour('index', [
            'title' => 'Module Harbour · trongate.cloud',
            'query' => $query,
            'category' => $category,
            'mode' => $search['mode'],
            'effective_mode' => $search['effective_mode'],
            'fallback_notice' => $search['fallback_notice'],
            'results' => $search['results'],
            'categories' => Module_spec::CATEGORIES,
            'embeddings_configured' => $embeddings->configured(),
            'embedding_stats' => $stats,
            'actor' => $this->workspace->_optional_actor(),
        ]);
    }

    function show(): void {
        $namespace = (string) segment(3);
        $name = (string) segment(4);
        try {
            $namespace = Module_spec::validate_namespace($namespace);
            $name = Module_spec::validate_name($name);
        } catch (RuntimeException) {
            http_response_code(404);
            $this->module('error_pages');
            $this->error_pages->not_found();
            return;
        }

        $module = $this->model->_find_module($namespace, $name);
        if ($module === null) {
            http_response_code(404);
            $this->module('error_pages');
            $this->error_pages->not_found();
            return;
        }

        $versions = $this->model->_versions_for($namespace, $name);
        $readme_html = trim((string) ($module['readme'] ?? '')) !== ''
            ? Markdown::render((string) $module['readme'])
            : '';

        $this->_render_harbour('show', [
            'title' => $module['slug'] . ' · Module Harbour · trongate.cloud',
            'module' => $module,
            'versions' => $versions,
            'readme_html' => $readme_html,
            'actor' => $this->workspace->_optional_actor(),
        ]);
    }

    function api_modules(): void {
        $query = trim((string) ($_GET['q'] ?? ''));
        $category = trim((string) ($_GET['category'] ?? ''));
        $mode = trim((string) ($_GET['mode'] ?? 'hybrid'));
        $embeddings = $this->_embeddings_client();
        $search = $this->model->_search($query, $category, $mode, $embeddings);

        $items = [];
        foreach ($search['results'] as $mod) {
            $ns = (string) $mod['namespace'];
            $name = (string) $mod['name'];
            $latest = (string) ($mod['latest_version'] ?? '');
            $items[] = [
                'slug' => $mod['slug'],
                'namespace' => $ns,
                'name' => $name,
                'title' => $mod['title'],
                'summary' => $mod['summary'],
                'license' => $mod['license'],
                'author' => $mod['author'],
                'category' => $mod['category'],
                'tags' => $mod['tags'],
                'latest_version' => $latest,
                'git_url' => !empty($mod['repo_ready']) ? Module_spec::git_url($ns, $name) : null,
                'archive_url' => $latest !== '' ? Module_spec::archive_url($ns, $name, $latest) : null,
                'match' => $mod['match'] ?? null,
                'score' => $mod['score'] ?? null,
                'semantic_score' => $mod['semantic_score'] ?? null,
            ];
        }

        header('Cache-Control: public, max-age=60');
        $this->trongate_security->_json([
            'mode' => $search['effective_mode'],
            'fallback_notice' => $search['fallback_notice'],
            'count' => count($items),
            'modules' => $items,
        ]);
    }

    function api_module(): void {
        $namespace = (string) segment(3);
        $name = (string) segment(4);
        try {
            $namespace = Module_spec::validate_namespace($namespace);
            $name = Module_spec::validate_name($name);
        } catch (RuntimeException) {
            $this->trongate_security->_json(['error' => 'Invalid module identifier'], 400);
            return;
        }

        $module = $this->model->_find_module($namespace, $name);
        if ($module === null) {
            $this->trongate_security->_json(['error' => 'Module not found'], 404);
            return;
        }

        $versions = [];
        foreach ($this->model->_versions_for($namespace, $name) as $ver) {
            $v = (string) $ver['version'];
            $versions[] = [
                'version' => $v,
                'commit' => $ver['commit'] ?? '',
                'digest' => $ver['digest'] ?? '',
                'archive_sha256' => $ver['archive_sha256'] ?? '',
                'archive_bytes' => (int) ($ver['archive_bytes'] ?? 0),
                'archive_url' => Module_spec::archive_url($namespace, $name, $v),
                'published_at' => (int) ($ver['published_at'] ?? 0),
            ];
        }

        header('Cache-Control: public, max-age=60');
        $this->trongate_security->_json([
            'slug' => $module['slug'],
            'namespace' => $namespace,
            'name' => $name,
            'title' => $module['title'],
            'summary' => $module['summary'],
            'description' => $module['description'],
            'license' => $module['license'],
            'author' => $module['author'],
            'category' => $module['category'],
            'tags' => $module['tags'],
            'latest_version' => $module['latest_version'],
            'git_url' => !empty($module['repo_ready']) ? Module_spec::git_url($namespace, $name) : null,
            'versions' => $versions,
        ]);
    }

    function archive(): void {
        $namespace = (string) segment(3);
        $name = (string) segment(4);
        $raw_ver = preg_replace('/\.tar\.gz$/i', '', (string) segment(5));
        try {
            $namespace = Module_spec::validate_namespace($namespace);
            $name = Module_spec::validate_name($name);
            $version = Module_spec::validate_version((string) $raw_ver);
        } catch (RuntimeException) {
            http_response_code(400);
            echo 'Invalid module archive path.';
            return;
        }

        $cdn = trim((string) self::_env('TC_REGISTRY_ARCHIVE_BASE_URL', ''));
        if ($cdn !== '') {
            header('Location: ' . Module_spec::archive_url($namespace, $name, $version), true, 302);
            return;
        }

        $root = rtrim((string) self::_env('TC_REGISTRY_ROOT', '/var/db/tc/registry'), '/');
        $path = $root . '/archives/' . $namespace . '/' . $name . '/' . $version . '.tar.gz';
        if (!is_file($path)) {
            http_response_code(404);
            echo 'Module archive not found.';
            return;
        }

        $etag = '"' . hash_file('sha256', $path) . '"';
        if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
            http_response_code(304);
            return;
        }

        header('Content-Type: application/gzip');
        header('Content-Length: ' . (string) filesize($path));
        header('Cache-Control: public, max-age=31536000, immutable');
        header('ETag: ' . $etag);
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="' . $name . '-' . $version . '.tar.gz"');
        readfile($path);
    }

    /**
     * Self-hosted Smart HTTP Git endpoint (`/git/<namespace>/<name>.git/...`).
     * Read/clone is public; push (`git-receive-pack`) requires a scoped namespace token
     * and automatically enqueues a root-verified `registry-publish` job on semver tag push.
     */
    function git(): void {
        $req_path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
        $parsed = Git_http_backend::parse_path($req_path);
        if ($parsed === null) {
            http_response_code(404);
            echo 'Repository not found.';
            return;
        }

        $ns = $parsed['namespace'];
        $name = $parsed['name'];
        $subpath = $parsed['subpath'];
        $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
        $needs_auth = Git_http_backend::requires_auth($subpath, $query);

        $module = $this->model->_find_module($ns, $name);
        if ($module === null) {
            http_response_code(404);
            echo 'Module repository not registered.';
            return;
        }

        $remote_user = null;
        if ($needs_auth) {
            $token = Git_http_backend::extract_token($_SERVER);
            $verified = $token !== null ? $this->model->_verify_token($ns, $token) : null;
            if ($verified === null) {
                header('WWW-Authenticate: Basic realm="trongate.cloud module registry"');
                http_response_code(401);
                echo 'Authentication required with a token scoped to namespace "' . $ns . '".';
                return;
            }
            $remote_user = $ns;
        }

        $body = file_get_contents('php://input');
        $body = is_string($body) ? $body : '';
        $repos_root = rtrim((string) self::_env('TC_REGISTRY_ROOT', '/var/db/tc/registry'), '/') . '/repos';

        try {
            $res = Git_http_backend::execute(
                $repos_root,
                $ns,
                $name,
                $subpath,
                (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
                $query,
                (string) ($_SERVER['CONTENT_TYPE'] ?? ''),
                $body,
                $remote_user
            );
        } catch (RuntimeException $e) {
            http_response_code(503);
            echo $e->getMessage();
            return;
        }

        http_response_code($res['status']);
        foreach ($res['headers'] as $header => $val) {
            header($header . ': ' . $val);
        }
        echo $res['body'];

        if ($subpath === 'git-receive-pack' && $res['status'] >= 200 && $res['status'] < 300 && $body !== '') {
            $server = (string) ($module['server'] ?? $this->model->_default_registry_server());
            if ($server !== '') {
                foreach (Git_http_backend::extract_pushed_tags($body) as $tag) {
                    try {
                        $this->model->_queue_publish($server, $ns, $name, $tag['version'], $tag['ref']);
                    } catch (Throwable) {
                        // Duplicate or busy queue lock; author can retry publish from the panel.
                    }
                }
            }
        }
    }

    // -----------------------------------------------------------------------
    // Authenticated Author / Admin Management UI
    // -----------------------------------------------------------------------

    function manage(): void {
        $this->trongate_security->make_sure_allowed('harbour');
        $embeddings = $this->_embeddings_client();
        $notice = $_SESSION['registry_notice'] ?? null;
        $new_token = $_SESSION['registry_new_token'] ?? null;
        unset($_SESSION['registry_notice'], $_SESSION['registry_new_token']);

        $this->workspace->_render_panel('harbour', 'manage', 'harbour-manage', [
            'registry_modules' => $this->model->_all_modules(),
            'registry_tokens' => $this->model->_list_tokens(),
            'registry_servers' => $this->records->_all('server'),
            'registry_categories' => Module_spec::CATEGORIES,
            'embeddings_health' => $embeddings->health(),
            'embedding_stats' => $this->model->_embedding_stats($embeddings),
            'saved_notice' => is_string($notice) ? $notice : null,
            'new_token' => is_array($new_token) ? $new_token : null,
            'crumbs' => [
                ['/harbour', 'Harbour'],
                ['/harbour/manage', 'Manage'],
            ],
        ]);
    }

    function create_repo(): void {
        $this->trongate_security->make_sure_allowed('harbour');
        $this->trongate_security->_csrf();
        try {
            $module = $this->model->_save_module_repo($_POST);
            $server = (string) ($module['server'] ?? '');
            if ($server !== '') {
                $op = $this->model->_queue_init_repo($server, (string) $module['namespace'], (string) $module['name']);
                $_SESSION['registry_notice'] = 'Module repository ' . $module['slug'] . ' registered and ZFS bare repo job queued (' . substr($op, 0, 8) . ').';
            } else {
                $_SESSION['registry_notice'] = 'Module repository ' . $module['slug'] . ' registered. Connect a FreeBSD server to initialize its bare Git repo on ZFS.';
            }
        } catch (Throwable $e) {
            $_SESSION['registry_notice'] = $e instanceof RuntimeException ? $e->getMessage() : 'Could not create module repository.';
        }
        header('Location: /harbour/manage', true, 303);
        exit;
    }

    function create_token(): void {
        $this->trongate_security->make_sure_allowed('harbour');
        $this->trongate_security->_csrf();
        try {
            $actor = $this->workspace->_actor();
            $created = $this->model->_create_token(
                (string) ($_POST['namespace'] ?? ''),
                (string) ($_POST['label'] ?? ''),
                (string) ($actor['email'] !== '' ? $actor['email'] : $actor['label'])
            );
            $_SESSION['registry_new_token'] = $created;
            $_SESSION['registry_notice'] = 'Push token created for namespace "' . $created['namespace'] . '". Copy it now; it will not be shown again.';
        } catch (Throwable $e) {
            $_SESSION['registry_notice'] = $e instanceof RuntimeException ? $e->getMessage() : 'Could not create push token.';
        }
        header('Location: /harbour/manage', true, 303);
        exit;
    }

    function revoke_token(): void {
        $this->trongate_security->make_sure_allowed('harbour');
        $this->trongate_security->_csrf();
        try {
            $this->model->_revoke_token((string) ($_POST['id'] ?? ''));
            $_SESSION['registry_notice'] = 'Push token revoked.';
        } catch (Throwable $e) {
            $_SESSION['registry_notice'] = $e instanceof RuntimeException ? $e->getMessage() : 'Could not revoke token.';
        }
        header('Location: /harbour/manage', true, 303);
        exit;
    }

    function publish(): void {
        $this->trongate_security->make_sure_allowed('harbour');
        $this->trongate_security->_csrf();
        try {
            $ns = Module_spec::validate_namespace((string) ($_POST['namespace'] ?? ''));
            $name = Module_spec::validate_name((string) ($_POST['name'] ?? ''));
            $version = Module_spec::validate_version((string) ($_POST['version'] ?? ''));
            $ref = trim((string) ($_POST['ref'] ?? ''));
            $module = $this->model->_find_module($ns, $name);
            $server = trim((string) ($_POST['server'] ?? ($module['server'] ?? $this->model->_default_registry_server())));
            if ($server === '') {
                throw new RuntimeException('No FreeBSD server available to materialize the ZFS module dataset.');
            }
            $op = $this->model->_queue_publish($server, $ns, $name, $version, $ref);
            header('Location: /deployments?operation=' . rawurlencode($op), true, 303);
            exit;
        } catch (Throwable $e) {
            $_SESSION['registry_notice'] = $e instanceof RuntimeException ? $e->getMessage() : 'Could not queue module publish.';
            header('Location: /harbour/manage', true, 303);
            exit;
        }
    }

    function upload(): void {
        $this->trongate_security->make_sure_allowed('harbour');
        $this->trongate_security->_csrf();
        try {
            $upload = $_FILES['archive'] ?? null;
            if (!is_array($upload)) {
                throw new RuntimeException('Choose a .tar.gz module archive to upload.');
            }

            $upload_error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
            if (in_array($upload_error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                throw new RuntimeException('Module archive exceeds the 512 KiB upload limit.');
            }
            if ($upload_error !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Could not read the uploaded module archive.');
            }

            $tmp = (string) ($upload['tmp_name'] ?? '');
            if ($tmp === '' || !is_uploaded_file($tmp)) {
                throw new RuntimeException('The uploaded module archive is not valid.');
            }
            $size = filesize($tmp);
            if ($size === false || $size < 1 || $size > Module_spec::MAX_UPLOAD_BYTES) {
                throw new RuntimeException('Module archive must be between 1 byte and 512 KiB.');
            }
            if (!str_ends_with(strtolower((string) ($upload['name'] ?? '')), '.tar.gz')) {
                throw new RuntimeException('Upload a gzip-compressed tar archive (.tar.gz).');
            }

            $archive = file_get_contents($tmp);
            if (!is_string($archive) || !str_starts_with($archive, "\x1f\x8b")) {
                throw new RuntimeException('The uploaded file is not a valid gzip archive.');
            }

            $server = trim((string) ($_POST['server'] ?? $this->model->_default_registry_server()));
            if ($server === '') {
                throw new RuntimeException('Connect a FreeBSD server before uploading modules.');
            }
            $operation = $this->model->_queue_upload(
                $server,
                (string) ($_POST['namespace'] ?? ''),
                (string) ($_POST['name'] ?? ''),
                (string) ($_POST['version'] ?? ''),
                $archive
            );
            header('Location: /deployments?operation=' . rawurlencode($operation), true, 303);
            exit;
        } catch (Throwable $e) {
            $_SESSION['registry_notice'] = $e instanceof RuntimeException ? $e->getMessage() : 'Could not upload module archive.';
            header('Location: /harbour/manage', true, 303);
            exit;
        }
    }

    function reindex(): void {
        $this->trongate_security->make_sure_allowed('harbour');
        $this->trongate_security->_csrf();
        try {
            $client = $this->_embeddings_client();
            if (!$client->configured()) {
                throw new RuntimeException('EMBEDDINGS_URL is not configured. Add the EmbeddingGemma 2 service first.');
            }
            $force = ($_POST['force'] ?? '') === '1';
            $counts = $this->model->_index_modules($client, $force);
            $_SESSION['registry_notice'] = 'Embedded ' . $counts['embedded'] . ' module(s) with ' . $client->model() . ' (' . $counts['skipped'] . ' up to date).';
        } catch (Throwable $e) {
            $_SESSION['registry_notice'] = $e instanceof RuntimeException ? $e->getMessage() : 'Could not embed registry modules.';
        }
        header('Location: /harbour/manage', true, 303);
        exit;
    }

    function yank(): void {
        $this->trongate_security->make_sure_allowed('harbour');
        $this->trongate_security->_csrf();
        try {
            $ns = Module_spec::validate_namespace((string) ($_POST['namespace'] ?? ''));
            $name = Module_spec::validate_name((string) ($_POST['name'] ?? ''));
            $version = Module_spec::validate_version((string) ($_POST['version'] ?? ''));
            $ver = $this->model->_find_version($ns, $name, $version);
            $server = trim((string) ($ver['server'] ?? $this->model->_default_registry_server()));
            if ($server === '') {
                throw new RuntimeException('No FreeBSD server configured for this module.');
            }
            $op = $this->model->_queue_yank($server, $ns, $name, $version);
            header('Location: /deployments?operation=' . rawurlencode($op), true, 303);
            exit;
        } catch (Throwable $e) {
            $_SESSION['registry_notice'] = $e instanceof RuntimeException ? $e->getMessage() : 'Could not queue yank.';
            header('Location: /harbour/manage', true, 303);
            exit;
        }
    }

    // -----------------------------------------------------------------------
    // Internal Worker Handlers & Helpers (blocked from URL dispatch by _)
    // -----------------------------------------------------------------------

    function _init_repo(Job_context $context): array {
        $p = $context->payload;
        $server = $this->records->_get(['kind' => 'server', 'id' => (string) $p['server']]);
        $ssh = new Ssh($this->model);
        $result = $ssh->runtime($server, [
            'action' => 'registry-init-repo',
            'namespace' => $p['namespace'],
            'name' => $p['name'],
        ], $context->heartbeat(...));

        if (($result['status'] ?? '') === 'succeeded') {
            $this->model->_save_module_repo([
                'namespace' => $p['namespace'],
                'name' => $p['name'],
                'server' => (string) $p['server'],
                'repo_ready' => true,
            ]);
        }
        return $result;
    }

    function _publish(Job_context $context): array {
        $p = $context->payload;
        $server_id = (string) $p['server'];
        $server = $this->records->_get(['kind' => 'server', 'id' => $server_id]);
        $ssh = new Ssh($this->model);
        $req = [
            'action' => 'registry-publish',
            'namespace' => $p['namespace'],
            'name' => $p['name'],
            'version' => $p['version'],
            'ref' => $p['ref'] ?? ('v' . $p['version']),
        ];
        if (isset($p['archive_b64']) && is_string($p['archive_b64'])) {
            $req['archive_b64'] = $p['archive_b64'];
        }
        if (!empty($p['subdir'])) {
            $req['subdir'] = $p['subdir'];
        }
        $result = $ssh->runtime($server, $req, $context->heartbeat(...));

        if (($result['status'] ?? '') === 'succeeded') {
            $this->model->_record_published($result, $server_id);
            $client = $this->_embeddings_client();
            if ($client->configured()) {
                try {
                    $this->model->_index_modules($client, false);
                } catch (Throwable) {
                    // Best-effort semantic indexing; publish remains succeeded.
                }
            }
        }
        return $result;
    }

    function _yank(Job_context $context): array {
        $p = $context->payload;
        $server = $this->records->_get(['kind' => 'server', 'id' => (string) $p['server']]);
        $ssh = new Ssh($this->model);
        $result = $ssh->runtime($server, [
            'action' => 'registry-yank',
            'namespace' => $p['namespace'],
            'name' => $p['name'],
            'version' => $p['version'],
        ], $context->heartbeat(...));

        if (($result['status'] ?? '') === 'succeeded') {
            $this->model->_remove_version((string) $p['namespace'], (string) $p['name'], (string) $p['version']);
        }
        return $result;
    }

    function _embeddings_client(): Registry_embeddings {
        $url = trim((string) self::_env('EMBEDDINGS_URL', ''));
        if ($url === '') {
            foreach ($this->records->_all('service') as $svc) {
                if (($svc['name'] ?? '') === 'embeddings' && ($svc['app'] ?? '') === '' && ($svc['status'] ?? '') === 'running' && !empty($svc['url'])) {
                    $url = (string) $svc['url'];
                    break;
                }
            }
        }
        return new Registry_embeddings($this->http_override ?? $this->http, $url);
    }

    function _display(string $view, array $data): void {
        $data['viewData'] = $data;
        $this->view($view, $data);
    }

    private function _render_harbour(string $view, array $data): void {
        $_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
        $e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $csrf = static fn(): string => '<input type="hidden" name="csrf_token" value="' . htmlspecialchars((string) $_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') . '">';
        $data['e'] = $e;
        $data['csrf'] = $csrf;
        $data['panel_url'] = rtrim((string) self::_env('TC_PANEL_URL', ''), '/');
        $this->view($view, $data);
    }

    private static function _env(string $key, string $default = ''): string {
        $val = getenv($key);
        return $val === false ? $default : $val;
    }
}
