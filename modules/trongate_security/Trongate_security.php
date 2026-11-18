<?php
/** Application access scenario backed by Trongate's bundled login/token modules. */
class Trongate_security extends Trongate {
    function __construct(?string $module_name=null) {
        parent::__construct($module_name);
        block_url('trongate_security');
    }
    function make_sure_allowed(string $scenario='admin panel',array $params=[]): string {
        $token=$this->trongate_tokens->attempt_get_valid_token(1);
        if ($token===false) {
            if (($_POST['inline'] ?? '')==='server' || ($_GET['poll'] ?? '')==='1') {
                $this->_json(['error'=>'Your session expired. Sign in again.'],401); exit;
            }
            redirect('login/login/1'); exit;
        }
        if ($_SERVER['REQUEST_METHOD']==='POST') { $this->_csrf(); }
        return $token;
    }
    function _csrf(): void {
        if ($_SERVER['REQUEST_METHOD']!=='POST' || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'],(string)($_POST['csrf_token'] ?? ''))) {
            http_response_code(403); exit('Invalid request token');
        }
    }
    function _json(array $data,int $status=200): void {
        http_response_code($status); header('Content-Type: application/json');
        echo json_encode($data,JSON_THROW_ON_ERROR);
    }
}
