<?php
declare(strict_types=1);
class Providers extends Trongate {
    // Internal HMVC entry points: Core blocks underscore methods from URL dispatch.
    function _handle(Store $store, Control $control, string $command, bool $inline): void {
        $flow=new Server_flow($store);
        $result=match($command) {
            'connect-provider'=>$flow->connect($_POST['provider_name'] ?? '',$_POST['token'] ?? ''),
            'provider-server-options'=>$flow->options($_POST['provider'] ?? ''),
            'quote-provider-server'=>$flow->quote($_POST),
            'create-provider-server'=>$flow->create($_POST['quote'] ?? '',($_POST['confirm_cost'] ?? '')==='1'),
            'provider-server-status'=>$flow->status($_POST['operation'] ?? '')
        };
        $this->console->_server_response($result); return;
    }
    function _display(string $view, array $data): void {
        $data['viewData']=$data;
        $this->view($view, $data);
    }
}
