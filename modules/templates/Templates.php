<?php
declare(strict_types=1);

class Templates extends Trongate {
    function _render(string $module, string $view, string $page, array $data = []): void {
        $actor = $this->trongate_security->_optional_actor();
        if (from_trongate_mx() && in_array($page, ['server-details', 'deployment'], true)) {
            $_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
            $inventory = [];
            require __DIR__ . '/views/_helpers.php';
            $data += ['page' => $page, 'message' => null, 'resources_saved' => false, 'actor' => $actor];
            $this->{$module}->_display($view, $data + compact('e', 'value', 'csrf', 'field', 'ready'));
            return;
        }
        if ($actor !== null && Access_policy::can_workspace($actor, 'apps.create')) {
            $this->apps->_restore_draft();
        }
        $_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));

        $all_apps = Modules::run('records/_all', 'app');
        $all_releases = Modules::run('records/_all', 'release');
        $all_services = Modules::run('records/_all', 'service');
        $all_servers = Modules::run('records/_all', 'server');
        $all_jobs = $this->jobs->model->_recent();

        if ($actor !== null) {
            $visible_apps = Access_policy::visible_apps($actor, $all_apps);
            $visible_app_map = array_flip(array_map(static fn(array $a): string => (string) ($a['id'] ?? ''), $visible_apps));
            $visible_releases = array_values(array_filter(
                $all_releases,
                static fn(array $r): bool => isset($visible_app_map[(string) ($r['app'] ?? '')])
            ));
            $visible_services = array_values(array_filter(
                $all_services,
                static function (array $s) use ($actor): bool {
                    $svc_app = (string) ($s['app'] ?? '');
                    return $svc_app !== ''
                        ? Access_policy::can_app($actor, $svc_app, 'services_view')
                        : Access_policy::can_workspace($actor, 'services.view');
                }
            ));
            $visible_servers = Access_policy::can_workspace($actor, 'servers.manage') ? $all_servers : [];
            $visible_jobs = array_values(array_filter(
                $all_jobs,
                static fn(array $j): bool => Access_policy::can_view_job($actor, $j)
            ));
        } else {
            $visible_apps = [];
            $visible_releases = [];
            $visible_services = [];
            $visible_servers = [];
            $visible_jobs = [];
        }

        $data += [
            'page' => $page,
            'operation' => null,
            'signedIn' => $actor !== null,
            'actor' => $actor,
            'message' => null,
            'deleting_server' => null,
            'timeline' => null,
            'resource_details' => null,
            'resources_saved' => false,
            'crumbs' => [],
            'app_summaries' => [],
            'overview' => null,
            'saved_notice' => null,
            'app_logs' => null,
            'log_rates' => null,
            'inventory' => [
                'servers' => $visible_servers,
                'apps' => $visible_apps,
                'releases' => $visible_releases,
                'services' => $visible_services,
            ],
            'jobs' => $visible_jobs,
        ];
        $data['view_module'] = $module;
        $data['view_file'] = $view;
        // view_module selects a view in Trongate::view; the shell itself belongs here.
        $data['content_module'] = $module;
        unset($data['view_module']);
        $this->view('panel', $data);
    }

    function request_error(): void {
        echo '<p class="notice" role="alert">Could not refresh. <a href="/login/login/1">Sign in</a> or reload to retry.</p>';
    }

    function error_404(): void {
        http_response_code(404);
        $this->view('error_404');
    }
}
