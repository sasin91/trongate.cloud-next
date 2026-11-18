<?php
declare(strict_types=1);
class Console extends Trongate {
    function _page(string $page): void {
        $_GET['page']=$page;
        $this->index();
    }
    function index(): void {
        $store=Store::open(); $control=new Control($store); $message=null;
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
        $_SESSION['launch_request'] ??= bin2hex(random_bytes(16));
        $page=(string)($_GET['page'] ?? 'dashboard');
        $inline=($_POST['inline'] ?? '')==='server';
        if ($_SERVER['REQUEST_METHOD']==='POST') {
            if (!hash_equals($_SESSION['csrf'],(string)($_POST['csrf'] ?? ''))) { http_response_code(403); exit('Invalid request token'); }
            if (($_POST['command'] ?? '')==='login') {
                $message=$this->authentication->_login($store);
            } elseif ($this->authentication->_signed_in()) {
                if ($_POST['command']==='logout') { $this->authentication->_logout(); }
                try {
                    $command=(string)($_POST['command'] ?? '');
                    if ($inline && in_array($command,['connect-provider','provider-server-options','quote-provider-server','create-provider-server','provider-server-status'],true)) {
                        $this->providers->_handle($store,$control,$command,$inline); return;
                    } elseif ($command==='launch-app') {
                        $this->apps->_launch($store,$control,$command,$inline); return;
                    } elseif ($command==='connect-server') {
                        $this->servers->_connect($store,$control,$command,$inline); return;
                    } elseif ($command==='confirm-server') {
                        $this->servers->_confirm($store,$control,$command,$inline); return;
                    } elseif ($command==='inspect-connected-server' && $inline) {
                        $this->servers->_inspect($store,$control,$command,$inline); return;
                    } elseif ($command==='redeploy-app' || $command==='rollback-app') {
                        $this->deployments->_change($store,$control,$command,$inline); return;
                    } else {
                        $payload=json_decode($_POST['payload'] ?? '{}',true,512,JSON_THROW_ON_ERROR);
                        $message=json_encode($control->execute($command,$payload),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
                    }
                }
                catch (Throwable $e) { $message='Action could not be completed: '.($e instanceof RuntimeException ? $e->getMessage() : 'Invalid input'); if ($inline) { $this->_server_response(['error'=>$message],422); return; } }
            } else { if ($inline) { $this->_server_response(['error'=>'Your session expired. Sign in again in another tab, then retry.'],401); return; } http_response_code(403); $message='Sign in to perform this action.'; }
        }
        $signedIn=$this->authentication->_signed_in();
        $operation=null;
        if ($signedIn && $page==='deployment') {
            try { $operation=$control->execute('operation',['operation'=>$_GET['operation'] ?? '']); }
            catch (Throwable) { http_response_code(404); $message='Deployment not found.'; $page='dashboard'; }
        }
        if (($_GET['poll'] ?? '')==='1') {
            header('Content-Type: application/json');
            if (!$signedIn) { http_response_code(401); echo '{"error":"Sign in again"}'; }
            else { echo json_encode($operation,JSON_THROW_ON_ERROR); }
            return;
        }
        if ($signedIn && $page==='new-app' && $_SERVER['REQUEST_METHOD']==='GET') { $_SESSION['launch_request']=bin2hex(random_bytes(16)); }
        if (!in_array($page,['dashboard','new-app','connect-server','confirm-server','deployment','advanced'])) { $page='dashboard'; }
        $this->view($page==='advanced'?'advanced':'index',['page'=>$page,'operation'=>$operation,'signedIn'=>$signedIn,'message'=>$message,'inventory'=>$signedIn?$control->execute('inventory',[]):[],'operations'=>$signedIn?$control->execute('operations',[]):[]]);
    }
    function _server_response(array $data,int $status=200): void {
        http_response_code($status); header('Content-Type: application/json');
        echo json_encode($data,JSON_THROW_ON_ERROR);
    }
}
