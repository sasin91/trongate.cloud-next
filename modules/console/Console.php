<?php
declare(strict_types=1);
class Console extends Trongate {
    function index(): void {
        $store=Store::open(); $control=new Control($store); $message=null;
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
        $_SESSION['launch_request'] ??= bin2hex(random_bytes(16));
        $page=(string)($_GET['page'] ?? 'dashboard');
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
                    if ($command==='launch-app') {
                        if (!hash_equals($_SESSION['launch_request'],(string)($_POST['request'] ?? ''))) { throw new RuntimeException('This launch form has expired. Reload and try again.'); }
                        $operation=(new AppFlow($store))->launch($_POST,$_SESSION['launch_request']);
                        header('Location: /?page=deployment&operation='.rawurlencode($operation),true,303); exit;
                    } elseif ($command==='connect-server') {
                        $id=Validate::id($_POST['id'] ?? '');
                        $server=Validate::server(['host'=>$_POST['host'] ?? '','port'=>$_POST['port'] ?? 22,'user'=>$_POST['user'] ?? 'root','credential'=>'ssh-'.substr(hash('sha256',$id),0,32)]);
                        foreach ($store->all('server') as $existing) { if ($existing['id']===$id) { throw new RuntimeException('Server name is already in use.'); } }
                        if (trim($_POST['private_key'] ?? '')==='') { throw new RuntimeException('Provide the SSH private key for this server.'); }
                        $store->secret($server['credential'],$_POST['private_key']);
                        $_SESSION['enrollment']=$control->execute('server-scan',$server)+['id'=>$id,'host'=>$server['host']];
                        header('Location: /?page=confirm-server',true,303); exit;
                    } elseif ($command==='confirm-server') {
                        $pending=$_SESSION['enrollment'] ?? [];
                        $control->execute('server-confirm',['id'=>$pending['id'] ?? '', 'enrollment'=>$pending['enrollment'] ?? '', 'fingerprint'=>trim($_POST['fingerprint'] ?? '')]);
                        unset($_SESSION['enrollment']); header('Location: /',true,303); exit;
                    } elseif ($command==='redeploy-app' || $command==='rollback-app') {
                        $app=Validate::id($_POST['app'] ?? '');
                        $operation=$control->execute($command==='rollback-app'?'rollback':'deploy',['app'=>$app,'release'=>$_POST['release'] ?? '']);
                        header('Location: /?page=deployment&operation='.rawurlencode($operation),true,303); exit;
                    } else {
                        $payload=json_decode($_POST['payload'] ?? '{}',true,512,JSON_THROW_ON_ERROR);
                        $message=json_encode($control->execute($command,$payload),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
                    }
                }
                catch (Throwable $e) { $message='Action could not be completed: '.($e instanceof RuntimeException ? $e->getMessage() : 'Invalid input'); }
            } else { http_response_code(403); $message='Sign in to perform this action.'; }
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
}
