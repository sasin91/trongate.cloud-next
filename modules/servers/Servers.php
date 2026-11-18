<?php
declare(strict_types=1);
class Servers extends Trongate {
    function confirm(): void { $this->console->_page('confirm-server'); }
    function index(): void { $this->console->_page('connect-server'); }
    // Internal HMVC entry points: Core blocks underscore methods from URL dispatch.
    function _connect(Store $store, Control $control, string $command, bool $inline): void {
        $id=Validate::id($_POST['id'] ?? '');
        $created=[];
        if (!empty($_POST['provision_operation'])) {
            $created=$this->providers->_status($store,$_POST['provision_operation']);
            if ($created['status']!=='succeeded') { throw new RuntimeException('Wait for server creation to finish.'); }
            if ($created['host']!==($_POST['host'] ?? '')) { throw new RuntimeException('Use the address returned by the provider.'); }
        }
        $server=Validate::server(['host'=>$_POST['host'] ?? '','port'=>$_POST['port'] ?? 22,'user'=>$_POST['user'] ?? 'root','credential'=>'ssh-'.substr(hash('sha256',$id),0,32)]);
        foreach ($store->all('server') as $existing) { if ($existing['id']===$id) { throw new RuntimeException('Server name is already in use.'); } }
        if (trim($_POST['private_key'] ?? '')==='') { throw new RuntimeException('Provide the SSH private key for this server.'); }
        $store->secret($server['credential'],$_POST['private_key']);
        $_SESSION['enrollment']=$control->execute('server-scan',$server)+['id'=>$id,'host'=>$server['host']];
        if ($created) { $_SESSION['enrollment']+=['provider'=>$created['provider'],'provider_id'=>$created['provider_id']]; }
        if ($inline) { $this->console->_server_response($_SESSION['enrollment']); return; }
        header('Location: /servers/confirm',true,303); exit;
    }
    function _confirm(Store $store, Control $control, string $command, bool $inline): void {
        $pending=$_SESSION['enrollment'] ?? [];
        if ($inline && !hash_equals($pending['enrollment'] ?? '',(string)($_POST['enrollment'] ?? ''))) { throw new RuntimeException('This server confirmation expired. Connect again.'); }
        $confirmation=['id'=>$pending['id'] ?? '', 'enrollment'=>$pending['enrollment'] ?? '', 'fingerprint'=>trim($_POST['fingerprint'] ?? '')];
        if (isset($pending['provider_id'])) { $confirmation+=['provider'=>$pending['provider'],'provider_id'=>$pending['provider_id']]; }
        $result=$control->execute('server-confirm',$confirmation);
        if ($inline) { unset($_SESSION['enrollment']); $this->console->_server_response($result+['host'=>$pending['host']]); return; }
        unset($_SESSION['enrollment']); header('Location: /',true,303); exit;
    }
    function _inspect(Store $store, Control $control, string $command, bool $inline): void {
        $id=Validate::id($_POST['server'] ?? '');
        $result=$control->execute('server-inspect',['server'=>$id]);
        $this->console->_server_response($result+['id'=>$id,'host'=>$store->get('server',$id)['host']]); return;
    }
    function _display(string $view, array $data): void {
        $data['viewData']=$data;
        $this->view($view, $data);
    }
}
