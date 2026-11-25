<?php
class Templates extends Trongate {
    function _render(string $module,string $view,string $page,array $data=[]): void {
        if (from_trongate_mx() && in_array($page, ['server-details', 'deployment'], true)) {
            $_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
            $inventory = [];
            require __DIR__ . '/views/_helpers.php';
            $data += ['page' => $page, 'message' => null, 'resources_saved' => false];
            $this->{$module}->_display($view, $data + compact('e', 'value', 'csrf', 'field', 'ready'));
            return;
        }
        $this->apps->_restore_draft();
        $_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
        $data+=['page'=>$page,'operation'=>null,'signedIn'=>true,'message'=>null,'deleting_server'=>null,'timeline'=>null,'resource_details'=>null,'resources_saved'=>false,
            'inventory'=>['servers' => Modules::run('records/_all', 'server'), 'apps' => Modules::run('records/_all', 'app'), 'releases' => Modules::run('records/_all', 'release')],'jobs'=>$this->jobs->model->_recent()];
        $data['view_module']=$module; $data['view_file']=$view;
        // view_module selects a view in Trongate::view; the shell itself belongs here.
        $data['content_module']=$module; unset($data['view_module']);
        $this->view('panel',$data);
    }
    function request_error(): void {
        echo '<p class="notice" role="alert">Could not refresh. <a href="/login/login/1">Sign in</a> or reload to retry.</p>';
    }
    function error_404(): void { http_response_code(404); echo 'Not found'; }
}
