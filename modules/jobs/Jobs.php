<?php
declare(strict_types=1);

require_once APPPATH . 'modules/trongate_security/Trongate_security.php';

class Jobs extends Trongate {
    function make_sure_allowed(): void {
        block_url('jobs/make_sure_allowed');
        $this->workspace->_actor();
    }

    function _can_access_page(array $actor): bool {
        if (!empty($actor['is_owner']) || !empty($actor['is_admin'])) {
            return true;
        }
        if (
            $this->workspace->can_workspace($actor, 'services.view')
            || $this->workspace->can_workspace($actor, 'servers.manage')
            || $this->workspace->can_workspace($actor, 'jobs.reconcile')
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

    function index(): void {
        $this->trongate_security->make_sure_allowed('jobs');
        $actor = $this->workspace->_actor();
        if (!$this->_can_access_page($actor)) {
            $this->trongate_security->_forbidden('You do not have permission to view background jobs.');
        }
        $message = null;
        $worker_diagnostics = null;
        $inspection_error = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->trongate_security->_csrf();
            $action = (string) ($_POST['action'] ?? '');
            if ($action === 'reconcile') {
                $this->workspace->_require_workspace('jobs.reconcile', 'You do not have permission to reconcile jobs.');
            }
            try {
                if ($action === 'reconcile') {
                    $this->model->_retry((string) ($_POST['job'] ?? ''));
                    $message = 'Job queued for reconciliation.';
                } elseif ($action === 'inspect-worker') {
                    $operation = (string) ($_POST['job'] ?? '');
                    if (preg_match('/\A[a-f0-9]{32}\z/D', $operation) !== 1) {
                        throw new RuntimeException('Invalid job identifier.');
                    }
                    $job = $this->model->job($operation);
                    $this->module('deployments');
                    if (!$this->deployments->_can_view_job($actor, $job)) {
                        $this->trongate_security->_forbidden('You do not have permission to inspect this job.');
                    }
                    if (
                        $job['kind'] !== 'build'
                        || preg_match('/\Abuilder:([^:]+)\z/D', (string) $job['resource'], $server_id) !== 1
                    ) {
                        throw new RuntimeException('Worker inspection is available for build jobs only.');
                    }

                    $queue = $this->model->_diagnostics($operation);
                    $worker = Modules::run('servers/_inspect_panel_worker', $server_id[1]);
                    $local_source_hash = hash_file('sha256', APPPATH . 'modules/jobs/worker.php');
                    if ($local_source_hash === false) {
                        throw new RuntimeException('Could not read the deployed worker source file.');
                    }
                    $worker['worker_source_matches_panel'] = null;
                    if ($worker['panel_jail_running']) {
                        $worker_source_hash = $worker['worker_source_sha256'] ?? null;
                        if (!is_string($worker_source_hash) || preg_match('/\A[a-f0-9]{64}\z/i', $worker_source_hash) !== 1) {
                            throw new RuntimeException('The host did not return a valid worker source fingerprint.');
                        }
                        $worker['worker_source_matches_panel'] = hash_equals(strtolower($local_source_hash), strtolower($worker_source_hash));
                    }
                    unset($worker['worker_source_sha256']);
                    $worker_diagnostics = ['queue' => $queue, 'worker' => $worker];
                    $message = 'Read-only worker inspection completed.';
                } else {
                    throw new RuntimeException('Unknown job action.');
                }
            } catch (RuntimeException $error) {
                http_response_code(422);
                $message = $error->getMessage();
            } catch (Throwable $error) {
                if ($action !== 'inspect-worker') {
                    throw $error;
                }
                $type_parts = explode('\\', get_class($error));
                $path = str_replace('\\', '/', $error->getFile());
                $app_path = strrpos($path, '/app/');
                $file = $app_path === false ? basename($path) : substr($path, $app_path + 5);
                $inspection_error = [
                    'type' => end($type_parts),
                    'file' => $file,
                    'line' => $error->getLine(),
                ];
                error_log('Worker inspection failed: ' . $inspection_error['type'] . ' in ' . $file . ':' . $error->getLine());
                http_response_code(422);
                $message = 'Worker inspection failed unexpectedly.';
            }
        }
        $this->workspace->_render_panel('jobs', 'index', 'jobs', [
            'message' => $message,
            'worker_diagnostics' => $worker_diagnostics,
            'inspection_error' => $inspection_error,
            'crumbs' => [['/jobs', 'Jobs']],
        ]);
    }

    function _display(string $view, array $data): void {
        $actor = is_array($data['actor'] ?? null) ? $data['actor'] : null;
        $data += [
            'actor' => $actor,
            'can_reconcile' => $actor === null || $this->workspace->can_workspace($actor, 'jobs.reconcile'),
        ];
        $this->view($view, $data);
    }
}
