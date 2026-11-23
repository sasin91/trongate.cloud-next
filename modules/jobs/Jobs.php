<?php
class Jobs extends Trongate {
    function index(): void {
        $this->trongate_security->make_sure_allowed();
        $message = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->trongate_security->_csrf();
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
        $this->templates->_render('jobs', 'index', 'jobs', ['message' => $message]);
    }

    function _display(string $view, array $data): void {
        $this->view($view, $data);
    }
}