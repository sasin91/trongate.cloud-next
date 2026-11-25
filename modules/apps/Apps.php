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
                $this->_launch();
            } catch (Throwable $e) {
                $message = $e instanceof RuntimeException ? $e->getMessage() : 'Invalid input';
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
        $fields = ['repository', 'branch', 'environment', 'label', 'domain', 'server', 'php_version', 'database', 'build_commands', 'php_extensions', 'health', 'persistent_paths', 'disk_quota_mb', 'wizard_step'];
        $draft = array_intersect_key($_POST, array_flip($fields));
        if (strlen(self::_encode_json($draft)) > 131072) {
            $this->trongate_security->_json(['error' => 'Draft is too large.'], 422);
            return;
        }
        $_SESSION['app_draft'] = Encryptor::encrypt(self::_encode_json($draft), installation_key());
        $_SESSION['launch_request'] ??= bin2hex(random_bytes(16));
        $this->model->_save_draft($this->_draft_owner(), $_SESSION['app_draft'], $_SESSION['launch_request']);
        $this->trongate_security->_json(['saved' => true]);
    }

    function destroy(): void {
        $this->trongate_security->make_sure_allowed();
        $this->trongate_security->_csrf();
        try {
            $app = Validate::id((string) ($_POST['app'] ?? ''));
            $purge_data = ($_POST['purge_data'] ?? '') === '1';
            $this->module('deployments');
            $operation = $purge_data
                ? $this->deployments->model->_queue_destroy($app, true)
                : $this->deployments->model->_queue_destroy($app);
            header('Location: /deployments?operation=' . rawurlencode($operation), true, 303);
            exit;
        } catch (Throwable $e) {
            $message = $e instanceof RuntimeException ? $e->getMessage() : 'Could not delete app.';
            $this->templates->_render('apps', 'dashboard', 'dashboard', ['message' => $message]);
        }
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

    static function _repository(string $value): string {
        $value = preg_replace('~^https://github\.com/~i', '', trim($value));
        return Validate::match(
            preg_replace('~\.git$~', '', rtrim((string) $value, '/')),
            '~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~D',
            'public GitHub repository'
        );
    }

    function _github(string $path): array {
        try {
            $response = $this->http->request(
                'GET',
                'https://api.github.com/repos/' . $path,
                ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'Trongate-Cloud-Next'],
                null,
                5,
                15
            );
        } catch (RuntimeException) {
            throw new RuntimeException('Could not read the public GitHub repository. Check connectivity and try again.');
        }
        if ($response['status'] !== 200) {
            throw new RuntimeException('Could not read the public GitHub repository. Check the repository and branch, or try again after the API rate limit resets.');
        }
        return json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
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
