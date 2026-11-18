<?php
declare(strict_types=1);
class Providers extends Trongate {
    function index(): void {
        $this->trongate_security->make_sure_allowed();
        $this->templates->_render('servers','connect','connect-server');
    }
    function action(): void {
        $this->trongate_security->make_sure_allowed(); $this->trongate_security->_csrf();
        $store=$this->model;
        try { $this->_handle($store,new Control($store),(string)($_POST['command'] ?? ''),true); }
        catch (Throwable $e) { $this->trongate_security->_json(['error'=>$e instanceof RuntimeException?$e->getMessage():'Invalid input'],422); }
    }
    // Internal HMVC entry points: Core blocks underscore methods from URL dispatch.
    function _handle(Model $store, Control $control, string $command, bool $inline): void {
        $result=match($command) {
            'connect-provider'=>$this->model->_connect_project($_POST['provider_name'] ?? '',$_POST['token'] ?? ''),
            'provider-server-options'=>$this->model->_options($_POST['provider'] ?? ''),
            'quote-provider-server'=>$this->model->_quote($_POST),
            'create-provider-server'=>$this->model->_create($_POST['quote'] ?? '',($_POST['confirm_cost'] ?? '')==='1'),
            'provider-server-status'=>$this->model->_status($_POST['operation'] ?? '')
        };
        $this->trongate_security->_json($result); return;
    }
    function _display(string $view, array $data): void {
        $data['viewData']=$data;
        $this->view($view, $data);
    }
}
