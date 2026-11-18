<?php
declare(strict_types=1);
class Apps extends Trongate {
    // Internal HMVC entry points: Core blocks underscore methods from URL dispatch.
    function _launch(Store $store, Control $control, string $command, bool $inline): void {
        if (!hash_equals($_SESSION['launch_request'],(string)($_POST['request'] ?? ''))) { throw new RuntimeException('This launch form has expired. Reload and try again.'); }
        $operation=(new App_flow($store))->launch($_POST,$_SESSION['launch_request']);
        header('Location: /?page=deployment&operation='.rawurlencode($operation),true,303); exit;
    }
    function _display(string $view, array $data): void {
        $data['viewData']=$data;
        $this->view($view, $data);
    }
}
