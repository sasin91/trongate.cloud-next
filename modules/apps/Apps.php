<?php
declare(strict_types=1);
class Apps extends Trongate {
    function index(): void {
        $this->trongate_security->make_sure_allowed();
        $this->templates->_render('apps','dashboard','dashboard');
    }
    function create(): void {
        $this->trongate_security->make_sure_allowed();
        $_SESSION['launch_request'] ??= bin2hex(random_bytes(16));
        $message=null;
        if ($_SERVER['REQUEST_METHOD']==='POST') {
            try { $store=$this->model; $this->_launch($store,new Control($store),'launch-app',false); }
            catch (Throwable $e) { $message=$e instanceof RuntimeException?$e->getMessage():'Invalid input'; }
        } else { $_SESSION['launch_request']=bin2hex(random_bytes(16)); }
        $this->templates->_render('apps','wizard','new-app',['message'=>$message]);
    }
    // Internal HMVC entry points: Core blocks underscore methods from URL dispatch.
    function _launch(Model $store, Control $control, string $command, bool $inline): void {
        if (!hash_equals($_SESSION['launch_request'],(string)($_POST['request'] ?? ''))) { throw new RuntimeException('This launch form has expired. Reload and try again.'); }
        $operation=$this->model->_plan_launch($_POST,$_SESSION['launch_request']);
        header('Location: /deployments?operation='.rawurlencode($operation),true,303); exit;
    }
    function _display(string $view, array $data): void {
        $data['viewData']=$data;
        $this->view($view, $data);
    }
}
