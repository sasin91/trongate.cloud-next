<?php
declare(strict_types=1);

class Jobs extends Trongate {
    function index(): void {
        $this->trongate_security->make_sure_allowed();
        $actor = $this->trongate_security->_actor();
        if (!Access_policy::can_access_jobs_page($actor)) {
            $this->trongate_security->_forbidden('You do not have permission to view background jobs.');
        }
        $message = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->trongate_security->_csrf();
            $this->trongate_security->_require_workspace('jobs.reconcile', 'You do not have permission to reconcile jobs.');
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
        $this->templates->_render('jobs', 'index', 'jobs', [
            'message' => $message,
            'crumbs' => [['/jobs', 'Jobs']],
        ]);
    }

    function _display(string $view, array $data): void {
        $this->view($view, $data);
    }
}
