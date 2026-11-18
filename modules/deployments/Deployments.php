<?php
declare(strict_types=1);
class Deployments extends Trongate {
    function index(): void {
        $this->trongate_security->make_sure_allowed();
        try { $operation=(new Control($this->model))->execute('operation',['operation'=>$_GET['operation'] ?? '']); }
        catch (Throwable) { $this->trongate_security->_json(['error'=>'Deployment not found'],404); return; }
        if (($_GET['poll'] ?? '')==='1') { $this->trongate_security->_json($operation); return; }
        $this->templates->_render('deployments','progress','deployment',['operation'=>$operation]);
    }
    function change(): void {
        $this->trongate_security->make_sure_allowed(); $this->trongate_security->_csrf();
        $store=$this->model; $command=(string)($_POST['command'] ?? '');
        try {
            if (!in_array($command,['redeploy-app','rollback-app'],true)) { throw new RuntimeException('Unknown deployment action'); }
            $this->_change($store,new Control($store),$command,false);
        } catch (Throwable $e) { $this->templates->_render('apps','dashboard','dashboard',['message'=>$e instanceof RuntimeException?$e->getMessage():'Invalid input']); }
    }
    // Internal HMVC entry points: Core blocks underscore methods from URL dispatch.
    function _change(Model $store, Control $control, string $command, bool $inline): void {
        $app=Validate::id($_POST['app'] ?? '');
        $operation=$control->execute($command==='rollback-app'?'rollback':'deploy',['app'=>$app,'release'=>$_POST['release'] ?? '']);
        header('Location: /deployments?operation='.rawurlencode($operation),true,303); exit;
    }
    function _display(string $view, array $data): void {
        $data['viewData']=$data;
        $this->view($view, $data);
    }
}
