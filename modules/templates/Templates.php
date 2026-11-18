<?php
class Templates extends Trongate {
    function _render(string $module,string $view,string $page,array $data=[]): void {
        $_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
        $control=new Control(new Operations_model('operations'));
        $data+=['page'=>$page,'operation'=>null,'signedIn'=>true,'message'=>null,
            'inventory'=>$control->execute('inventory',[]),'operations'=>$control->execute('operations',[])];
        $data['view_module']=$module; $data['view_file']=$view;
        // view_module selects a view in Trongate::view; the shell itself belongs here.
        $data['content_module']=$module; unset($data['view_module']);
        $this->view('panel',$data);
    }
    function error_404(): void { http_response_code(404); echo 'Not found'; }
}
