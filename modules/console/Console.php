<?php
declare(strict_types=1);
class Console extends Trongate {
    function index(): void {
        $store=Store::open(); $control=new Control($store); $message=null;
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
        $_SESSION['launch_request'] ??= bin2hex(random_bytes(16));
        $page=(string)($_GET['page'] ?? 'dashboard');
        $inline=($_POST['inline'] ?? '')==='server';
        if ($_SERVER['REQUEST_METHOD']==='POST') {
            if (!hash_equals($_SESSION['csrf'],(string)($_POST['csrf'] ?? ''))) { http_response_code(403); exit('Invalid request token'); }
            if (($_POST['command'] ?? '')==='login') {
                $name=substr((string)($_POST['name'] ?? ''),0,100);
                $rateId=hash('sha256',($_SERVER['REMOTE_ADDR'] ?? '').':'.$name);
                try { $rate=$store->get('login-rate',$rateId); } catch (RuntimeException) { $rate=['count'=>0,'until'=>0]; }
                if ($rate['until']<time()) { $rate=['count'=>0,'until'=>time()+900]; }
                $hash=$store->run('SELECT password FROM admins WHERE name=?',[$name])->fetchColumn();
                if ($rate['count']<10 && $hash && password_verify((string)($_POST['password'] ?? ''),$hash)) {
                    session_regenerate_id(true); $_SESSION['admin']=$name; $_SESSION['expires']=time()+3600;
                } else { $rate['count']++; $store->put('login-rate',$rateId,$rate); $message='Sign-in failed or temporarily limited.'; }
            } elseif (!empty($_SESSION['admin']) && ($_SESSION['expires'] ?? 0)>time()) {
                if ($_POST['command']==='logout') { $_SESSION=[]; session_destroy(); header('Location: /'); exit; }
                try {
                    $command=(string)($_POST['command'] ?? '');
                    if ($inline && in_array($command,['connect-provider','provider-server-options','quote-provider-server','create-provider-server','provider-server-status'],true)) {
                        $flow=new ServerFlow($store);
                        $result=match($command) {
                            'connect-provider'=>$flow->connect($_POST['provider_name'] ?? '',$_POST['token'] ?? ''),
                            'provider-server-options'=>$flow->options($_POST['provider'] ?? ''),
                            'quote-provider-server'=>$flow->quote($_POST),
                            'create-provider-server'=>$flow->create($_POST['quote'] ?? '',($_POST['confirm_cost'] ?? '')==='1'),
                            'provider-server-status'=>$flow->status($_POST['operation'] ?? '')
                        };
                        $this->_server_response($result); return;
                    } elseif ($command==='launch-app') {
                        if (!hash_equals($_SESSION['launch_request'],(string)($_POST['request'] ?? ''))) { throw new RuntimeException('This launch form has expired. Reload and try again.'); }
                        $operation=(new AppFlow($store))->launch($_POST,$_SESSION['launch_request']);
                        header('Location: /?page=deployment&operation='.rawurlencode($operation),true,303); exit;
                    } elseif ($command==='connect-server') {
                        $id=Validate::id($_POST['id'] ?? '');
                        $created=[];
                        if (!empty($_POST['provision_operation'])) {
                            $created=(new ServerFlow($store))->status($_POST['provision_operation']);
                            if ($created['status']!=='succeeded') { throw new RuntimeException('Wait for server creation to finish.'); }
                            if ($created['host']!==($_POST['host'] ?? '')) { throw new RuntimeException('Use the address returned by the provider.'); }
                        }
                        $server=Validate::server(['host'=>$_POST['host'] ?? '','port'=>$_POST['port'] ?? 22,'user'=>$_POST['user'] ?? 'root','credential'=>'ssh-'.substr(hash('sha256',$id),0,32)]);
                        foreach ($store->all('server') as $existing) { if ($existing['id']===$id) { throw new RuntimeException('Server name is already in use.'); } }
                        if (trim($_POST['private_key'] ?? '')==='') { throw new RuntimeException('Provide the SSH private key for this server.'); }
                        $store->secret($server['credential'],$_POST['private_key']);
                        $_SESSION['enrollment']=$control->execute('server-scan',$server)+['id'=>$id,'host'=>$server['host']];
                        if ($created) { $_SESSION['enrollment']+=['provider'=>$created['provider'],'provider_id'=>$created['provider_id']]; }
                        if ($inline) { $this->_server_response($_SESSION['enrollment']); return; }
                        header('Location: /?page=confirm-server',true,303); exit;
                    } elseif ($command==='confirm-server') {
                        $pending=$_SESSION['enrollment'] ?? [];
                        if ($inline && !hash_equals($pending['enrollment'] ?? '',(string)($_POST['enrollment'] ?? ''))) { throw new RuntimeException('This server confirmation expired. Connect again.'); }
                        $confirmation=['id'=>$pending['id'] ?? '', 'enrollment'=>$pending['enrollment'] ?? '', 'fingerprint'=>trim($_POST['fingerprint'] ?? '')];
                        if (isset($pending['provider_id'])) { $confirmation+=['provider'=>$pending['provider'],'provider_id'=>$pending['provider_id']]; }
                        $result=$control->execute('server-confirm',$confirmation);
                        if ($inline) { unset($_SESSION['enrollment']); $this->_server_response($result+['host'=>$pending['host']]); return; }
                        unset($_SESSION['enrollment']); header('Location: /',true,303); exit;
                    } elseif ($command==='inspect-connected-server' && $inline) {
                        $id=Validate::id($_POST['server'] ?? '');
                        $result=$control->execute('server-inspect',['server'=>$id]);
                        $this->_server_response($result+['id'=>$id,'host'=>$store->get('server',$id)['host']]); return;
                    } elseif ($command==='redeploy-app' || $command==='rollback-app') {
                        $app=Validate::id($_POST['app'] ?? '');
                        $operation=$control->execute($command==='rollback-app'?'rollback':'deploy',['app'=>$app,'release'=>$_POST['release'] ?? '']);
                        header('Location: /?page=deployment&operation='.rawurlencode($operation),true,303); exit;
                    } else {
                        $payload=json_decode($_POST['payload'] ?? '{}',true,512,JSON_THROW_ON_ERROR);
                        $message=json_encode($control->execute($command,$payload),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
                    }
                }
                catch (Throwable $e) { $message='Action could not be completed: '.($e instanceof RuntimeException ? $e->getMessage() : 'Invalid input'); if ($inline) { $this->_server_response(['error'=>$message],422); return; } }
            } else { if ($inline) { $this->_server_response(['error'=>'Your session expired. Sign in again in another tab, then retry.'],401); return; } http_response_code(403); $message='Sign in to perform this action.'; }
        }
        $signedIn=!empty($_SESSION['admin']) && ($_SESSION['expires'] ?? 0)>time();
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
