<?php
declare(strict_types=1);
class Deployments extends Trongate {
    // Internal HMVC entry points: Core blocks underscore methods from URL dispatch.
    function _change(Store $store, Control $control, string $command, bool $inline): void {
        $app=Validate::id($_POST['app'] ?? '');
        $operation=$control->execute($command==='rollback-app'?'rollback':'deploy',['app'=>$app,'release'=>$_POST['release'] ?? '']);
        header('Location: /?page=deployment&operation='.rawurlencode($operation),true,303); exit;
    }
    function _display(string $view, array $data): void {
        $data['viewData']=$data;
        $this->view($view, $data);
    }
}
