<?php
class Operations extends Trongate {
    function index(): void {
        $this->trongate_security->make_sure_allowed();
        $control=new Control(Store::open()); $message=null;
        if ($_SERVER['REQUEST_METHOD']==='POST') {
            try { $message=json_encode($control->execute((string)($_POST['command'] ?? ''),json_decode($_POST['payload'] ?? '{}',true,512,JSON_THROW_ON_ERROR)),JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR); }
            catch (Throwable $e) { $message=$e instanceof RuntimeException?$e->getMessage():'Invalid input'; }
        }
        $this->templates->_render('operations','advanced','advanced',['message'=>$message]);
    }
    function _display(string $view,array $data): void { $this->view($view,$data); }
}
