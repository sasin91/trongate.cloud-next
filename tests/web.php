<?php
require dirname(__DIR__).'/modules/operations/bootstrap.php';
$dir=sys_get_temp_dir().'/tc-web-'.bin2hex(random_bytes(6)); mkdir($dir);
putenv('TC_DSN=sqlite:'.$dir.'/state.db'); putenv('TC_STATE='.$dir); putenv('TC_BASE_URL=http://127.0.0.1:18781');
$mysqlAdmin=null; $testDatabase=null;
if ($mysqlDsn=getenv('TC_WEB_MYSQL')) {
    $mysqlAdmin=new PDO($mysqlDsn,getenv('TC_DB_USER'),getenv('TC_DB_PASSWORD'));
    $testDatabase='tc_web_'.bin2hex(random_bytes(6));
    $mysqlAdmin->exec('CREATE DATABASE '.$testDatabase);
    putenv('TC_DSN='.$mysqlDsn.';dbname='.$testDatabase);
}
$store=new Operations_model('operations'); (new Setup_model('setup',$store->connection()))->migrate(); $store->run('INSERT INTO admins VALUES(?,?)',['admin',password_hash('test-only-long-password',PASSWORD_DEFAULT)]);
(new Login_model('login',$store->connection()))->_import_legacy_admins();
$originalHash=$store->run('SELECT password FROM admins WHERE name=?',['admin'])->fetchColumn();
(new Login_model('login',$store->connection()))->_import_legacy_admins();
if ($store->run('SELECT password FROM trongate_administrators WHERE username=?',['admin'])->fetchColumn()!==$originalHash || (int)$store->run('SELECT COUNT(*) FROM trongate_users')->fetchColumn()!==1) { throw new RuntimeException('Administrator migration changed credentials or duplicated users'); }
file_put_contents($dir.'/key',Encryptor::generate_key());
$proc=proc_open([PHP_BINARY,'-S','127.0.0.1:18781','-t',dirname(__DIR__).'/public',dirname(__DIR__).'/public/index.php'],[0=>['pipe','r'],1=>['file',$dir.'/server.log','w'],2=>['file',$dir.'/server.log','a']],$pipes);
$cookie=$dir.'/cookies';
function request(?array $body=null,string $path='/login/login/1'): array {
    global $cookie;
    $c=curl_init('http://127.0.0.1:18781'.$path); curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_TIMEOUT=>5,CURLOPT_HTTPHEADER=>['X-Requested-With: XMLHttpRequest']]);
    if ($body!==null) { curl_setopt($c,CURLOPT_POSTFIELDS,http_build_query($body)); }
    $html=curl_exec($c); $code=curl_getinfo($c,CURLINFO_RESPONSE_CODE); curl_close($c); return [$code,$html];
}
try {
    for ($i=0;$i<30;$i++) { usleep(100000); [$code,$page]=request(); if ($code===200) { break; } }
    if (!str_contains((string)$page,'Sign in')) { throw new RuntimeException('Login page failed: '.file_get_contents($dir.'/server.log')); }
    preg_match('/name="csrf_token" value="([a-f0-9]+)"/',$page,$m); $csrf=$m[1];
    if (request(['command'=>'inventory','csrf_token'=>'bad'],'/login/submit_login/1')[0]!==403) { throw new RuntimeException('CSRF not enforced'); }
    request(['csrf_token'=>$csrf,'identifier'=>'admin','password'=>'incorrect-password'],'/login/submit_login/1');
    if ((int)$store->run('SELECT COUNT(*) FROM login_attempts')->fetchColumn()!==1 || (int)$store->run('SELECT COUNT(*) FROM trongate_tokens')->fetchColumn()!==0) { throw new RuntimeException('Native login failure not recorded'); }
    [$code,$page]=request(['csrf_token'=>$csrf,'identifier'=>'admin','password'=>'test-only-long-password'],'/login/submit_login/1');
    if ($code!==302) { throw new RuntimeException('Native login failed: '.$page.' '.file_get_contents($dir.'/server.log')); }
    [$code,$page]=request(null,'/apps');
    if (!str_contains($page,'Your apps.')) { throw new RuntimeException('Login failed'); }
    if ((int)$store->run('SELECT COUNT(*) FROM trongate_tokens')->fetchColumn()!==1) { throw new RuntimeException('Native login token missing'); }
    if (request(['command'=>'inventory','csrf_token'=>'bad'],'/operations')[0]!==403) { throw new RuntimeException('Authenticated mutation bypassed CSRF'); }
    [$code,$page]=request(['command'=>'inventory','csrf_token'=>$csrf,'payload'=>'{}'],'/operations');
    if ($code!==200 || !str_contains($page,'Servers')) { throw new RuntimeException('Authenticated operation failed'); }
    $store->put('server','web',['application_ready'=>true,'host'=>'192.0.2.10','managed_eligible'=>false,'missing'=>[]]);
    [$code,$page]=request(null,'/apps/create');
    if ($code!==200 || !str_contains($page,'Which app are we putting online?')) { throw new RuntimeException('Wizard missing'); }
    if (!str_contains($page,'id="server-dialog"') || str_contains($page,'href="/?page=connect-server">Connect a server</a>')) { throw new RuntimeException('Wizard must enroll servers inline'); }
    preg_match('/name="request" value="([a-f0-9]+)"/',$page,$launch);
    if (!str_contains($page,'Create at a provider') || !str_contains($page,'Connect an existing server')) { throw new RuntimeException('Server onboarding choices missing'); }
    [$code,$error]=request(['command'=>'create-provider-server','inline'=>'server','csrf_token'=>$csrf,'quote'=>'unconfirmed'],'/providers/action');
    if ($code!==422) { throw new RuntimeException('Provider creation without cost confirmation accepted'); }
    [$code,$error]=request(['command'=>'connect-server','inline'=>'server','csrf_token'=>$csrf,'id'=>'new-server','host'=>'192.0.2.11','user'=>'root','port'=>22,'private_key'=>''],'/servers/action');
    if ($code!==422 || !isset(json_decode($error,true)['error'])) { throw new RuntimeException('Inline errors must return JSON without navigating away'); }
    [$code,$error]=request(['command'=>'confirm-server','inline'=>'server','csrf_token'=>$csrf,'enrollment'=>'expired','fingerprint'=>'SHA256:fixture'],'/servers/action');
    if ($code!==422 || !str_contains(json_decode($error,true)['error'],'expired')) { throw new RuntimeException('Inline confirmation must reject stale enrollment'); }
    $fields=['command'=>'launch-app','csrf_token'=>$csrf,'request'=>$launch[1],'source'=>'image','label'=>'web-demo','server'=>'web','domain'=>'demo.example.com','image'=>'registry.example.com/demo@sha256:'.str_repeat('a',64),'environment'=>'PASSWORD=web-fixture-secret'];
    if (request($fields,'/apps/create')[0]!==303 || request($fields,'/apps/create')[0]!==303) { throw new RuntimeException('Wizard launch or duplicate failed'); }
    if ((int)$store->run('SELECT COUNT(*) FROM jobs')->fetchColumn()!==1) { throw new RuntimeException('Repeated HTTP submission duplicated deployment'); }
    $id=$store->run('SELECT id FROM jobs')->fetchColumn();
    [$code,$page]=request(null,'/deployments?operation='.$id);
    if ($code!==200 || !str_contains($page,'Deploy progress') || str_contains($page,'web-fixture-secret')) { throw new RuntimeException('Deployment view failed or exposed secret'); }
    [$code,$page]=request(null,'/deployments?poll=1&operation='.$id);
    if ($code!==200 || json_decode($page,true)['job']['status']!=='pending' || str_contains($page,'web-fixture-secret')) { throw new RuntimeException('Deployment polling failed'); }
    foreach (['/apps_module/js/flows.js','/templates_module/css/wizard.css','/templates_module/css/panel.css','/servers_module/js/server-dialog.js','/providers_module/js/provider-server.js','/deployments_module/js/progress.js','/deployments_module/css/deployments.css'] as $asset) {
        [$code,$body]=request(null,$asset);
        if ($code!==200 || strlen($body)<100 || str_contains($body,'Control plane unavailable')) { throw new RuntimeException('Module asset not served: '.$asset); }
    }
    foreach (['/apps/create','/apps','/servers','/servers/confirm','/providers','/operations','/'] as $route) {
        [$code,$body]=request(null,$route);
        if ($code!==200 || str_contains($body,'Warning:') || str_contains($body,'Control plane unavailable')) { throw new RuntimeException('Module view failed: '.$route); }
    }
    foreach (['/servers/_connect','/providers/_handle','/providers/_quote','/apps/_plan_launch','/apps/_deploy_built','/authentication/_logout','/app_flow/launch','/servers_module/views/dialog.php','/providers_module/js/../../Providers.php','/servers_module/js/%2e%2e/Servers.php'] as $route) {
        if (!in_array(request(null,$route)[0],[400,403,404],true)) { throw new RuntimeException('Internal module path exposed: '.$route); }
    }
    request(['csrf_token'=>$csrf],'/login/logout');
    if ((int)$store->run('SELECT COUNT(*) FROM trongate_tokens')->fetchColumn()!==0) { throw new RuntimeException('Logout did not revoke native token'); }
    [$code,$error]=request(['command'=>'connect-server','inline'=>'server','csrf_token'=>$csrf],'/servers/action');
    // A destroyed session has a new CSRF token, so the request must fail before mutation.
    if ($code!==401) { throw new RuntimeException('Logged-out inline enrollment accepted'); }
    if (request(null,'/deployments?poll=1&operation='.$id)[0]!==401) { throw new RuntimeException('Unauthenticated polling allowed'); }
    if (request(null,'/apps/create')[0]!==302) { throw new RuntimeException('Module route bypassed login'); }
    [$code,$login]=request(null,'/login/login/1');
    if ($code!==200 || !str_contains($login,'Welcome back.') || str_contains($login,'Which app are we putting online?')) { throw new RuntimeException('Module route bypassed login'); }
    preg_match('/name="csrf_token" value="([a-f0-9]+)"/',$login,$m);
    [$code,$error]=request(['command'=>'create-provider-server','inline'=>'server','csrf_token'=>$m[1],'quote'=>'fixture','confirm_cost'=>'1'],'/providers/action');
    if ($code!==401 || !isset(json_decode($error,true)['error'])) { throw new RuntimeException('Provider route bypassed authentication'); }
    if (request(null,'/deployments?poll=1&operation='.$id)[0]!==401) { throw new RuntimeException('Module polling bypassed authentication'); }
    echo "HTTP login, CSRF and authenticated console passed\n";
} finally {
    proc_terminate($proc); fclose($pipes[0]); proc_close($proc); unset($store);
    foreach (glob($dir.'/*') as $f) { unlink($f); } rmdir($dir);
    if ($mysqlAdmin) { $mysqlAdmin->exec('DROP DATABASE '.$testDatabase); }
}
