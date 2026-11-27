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
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->trongate_security->_csrf();
            $this->workspace->_require_workspace('jobs.reconcile', 'You do not have permission to reconcile jobs.');
            try {
                if (($_POST['action'] ?? '') !== 'reconcile') {
                    throw new RuntimeException('Unknown job action');
                }
                $this->model->_retry((string) ($_POST['job'] ?? ''));
                $message = 'Job queued for reconciliation.';
            } catch (RuntimeException $error) {
                http_response_code(422);
                $message = $error->getMessage();
            }
        }
        $this->workspace->_render_panel('jobs', 'index', 'jobs', [
            'message' => $message,
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
