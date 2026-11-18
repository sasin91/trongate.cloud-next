<?php
declare(strict_types=1);
class Authentication extends Trongate {
    function index(): void { $this->console->_page('dashboard'); }
    function _login(Store $store): ?string {
        $name=substr((string)($_POST['name'] ?? ''),0,100);
        $rateId=hash('sha256',($_SERVER['REMOTE_ADDR'] ?? '').':'.$name);
        try { $rate=$store->get('login-rate',$rateId); } catch (RuntimeException) { $rate=['count'=>0,'until'=>0]; }
        if ($rate['until']<time()) { $rate=['count'=>0,'until'=>time()+900]; }
        $hash=$store->run('SELECT password FROM admins WHERE name=?',[$name])->fetchColumn();
        if ($rate['count']<10 && $hash && password_verify((string)($_POST['password'] ?? ''),$hash)) {
            session_regenerate_id(true); $_SESSION['admin']=$name; $_SESSION['expires']=time()+3600;
        } else { $rate['count']++; $store->put('login-rate',$rateId,$rate); return 'Sign-in failed or temporarily limited.'; }
        return null;
    }
    function _signed_in(): bool {
        return !empty($_SESSION['admin']) && ($_SESSION['expires'] ?? 0)>time();
    }
    function _logout(): void {
        $_SESSION=[]; session_destroy(); header('Location: /'); exit;
    }
    function _display(string $view,array $data): void {
        $data['viewData']=$data;
        $this->view($view,$data);
    }
}
