<?php
require dirname(__DIR__).'/lib/bootstrap.php';
$dir=sys_get_temp_dir().'/tc-web-'.bin2hex(random_bytes(6)); mkdir($dir);
putenv('TC_DSN=sqlite:'.$dir.'/state.db'); putenv('TC_STATE='.$dir); putenv('TC_BASE_URL=http://127.0.0.1:18781');
$store=Store::open(); $store->migrate(); $store->run('INSERT INTO admins VALUES(?,?)',['admin',password_hash('test-only-long-password',PASSWORD_DEFAULT)]);
file_put_contents($dir.'/key',Encryptor::generate_key());
$proc=proc_open([PHP_BINARY,'-S','127.0.0.1:18781','-t',dirname(__DIR__).'/public',dirname(__DIR__).'/public/index.php'],[0=>['pipe','r'],1=>['file',$dir.'/server.log','w'],2=>['file',$dir.'/server.log','a']],$pipes);
$cookie=$dir.'/cookies';
function request(?array $body=null,string $path='/'): array {
    global $cookie;
    $c=curl_init('http://127.0.0.1:18781'.$path); curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_TIMEOUT=>5]);
    if ($body!==null) { curl_setopt($c,CURLOPT_POSTFIELDS,http_build_query($body)); }
    $html=curl_exec($c); $code=curl_getinfo($c,CURLINFO_RESPONSE_CODE); curl_close($c); return [$code,$html];
}
try {
    for ($i=0;$i<30;$i++) { usleep(100000); [$code,$page]=request(); if ($code===200) { break; } }
    if (!str_contains((string)$page,'Sign in')) { throw new RuntimeException('Login page failed: '.file_get_contents($dir.'/server.log')); }
    preg_match('/name="csrf" value="([a-f0-9]+)"/',$page,$m); $csrf=$m[1];
    if (request(['command'=>'inventory','csrf'=>'bad'])[0]!==403) { throw new RuntimeException('CSRF not enforced'); }
    [$code,$page]=request(['command'=>'login','csrf'=>$csrf,'name'=>'admin','password'=>'test-only-long-password']);
    if (!str_contains($page,'Your apps.')) { throw new RuntimeException('Login failed'); }
    [$code,$page]=request(['command'=>'inventory','csrf'=>$csrf,'payload'=>'{}']);
    if ($code!==200 || !str_contains($page,'Servers')) { throw new RuntimeException('Authenticated operation failed'); }
    $store->put('server','web',['application_ready'=>true,'host'=>'192.0.2.10','managed_eligible'=>false,'missing'=>[]]);
    [$code,$page]=request(null,'/?page=new-app');
    if ($code!==200 || !str_contains($page,'Which app are we putting online?')) { throw new RuntimeException('Wizard missing'); }
    if (!str_contains($page,'id="server-dialog"') || str_contains($page,'href="/?page=connect-server">Connect a server</a>')) { throw new RuntimeException('Wizard must enroll servers inline'); }
    preg_match('/name="request" value="([a-f0-9]+)"/',$page,$launch);
    if (!str_contains($page,'Create at a provider') || !str_contains($page,'Connect an existing server')) { throw new RuntimeException('Server onboarding choices missing'); }
    [$code,$error]=request(['command'=>'create-provider-server','inline'=>'server','csrf'=>$csrf,'quote'=>'unconfirmed'],'/?page=connect-server');
    if ($code!==422) { throw new RuntimeException('Provider creation without cost confirmation accepted'); }
    [$code,$error]=request(['command'=>'connect-server','inline'=>'server','csrf'=>$csrf,'id'=>'new-server','host'=>'192.0.2.11','user'=>'root','port'=>22,'private_key'=>''],'/?page=connect-server');
    if ($code!==422 || !isset(json_decode($error,true)['error'])) { throw new RuntimeException('Inline errors must return JSON without navigating away'); }
    [$code,$error]=request(['command'=>'confirm-server','inline'=>'server','csrf'=>$csrf,'enrollment'=>'expired','fingerprint'=>'SHA256:fixture'],'/?page=connect-server');
    if ($code!==422 || !str_contains(json_decode($error,true)['error'],'expired')) { throw new RuntimeException('Inline confirmation must reject stale enrollment'); }
    $fields=['command'=>'launch-app','csrf'=>$csrf,'request'=>$launch[1],'source'=>'image','label'=>'web-demo','server'=>'web','domain'=>'demo.example.com','image'=>'registry.example.com/demo@sha256:'.str_repeat('a',64),'environment'=>'PASSWORD=web-fixture-secret'];
    if (request($fields,'/?page=new-app')[0]!==303 || request($fields,'/?page=new-app')[0]!==303) { throw new RuntimeException('Wizard launch or duplicate failed'); }
    if ((int)$store->run('SELECT COUNT(*) FROM jobs')->fetchColumn()!==1) { throw new RuntimeException('Repeated HTTP submission duplicated deployment'); }
    $id=$store->run('SELECT id FROM jobs')->fetchColumn();
    [$code,$page]=request(null,'/?page=deployment&operation='.$id);
    if ($code!==200 || !str_contains($page,'Deploy progress') || str_contains($page,'web-fixture-secret')) { throw new RuntimeException('Deployment view failed or exposed secret'); }
    [$code,$page]=request(null,'/?page=deployment&poll=1&operation='.$id);
    if ($code!==200 || json_decode($page,true)['job']['status']!=='pending' || str_contains($page,'web-fixture-secret')) { throw new RuntimeException('Deployment polling failed'); }
    foreach (['/apps_module/js/flows.js','/console_module/css/wizard.css','/console_module/css/panel.css','/servers_module/js/server-dialog.js','/providers_module/js/provider-server.js','/deployments_module/js/progress.js','/deployments_module/css/deployments.css'] as $asset) {
        [$code,$body]=request(null,$asset);
        if ($code!==200 || strlen($body)<100 || str_contains($body,'Control plane unavailable')) { throw new RuntimeException('Module asset not served: '.$asset); }
    }
    foreach (['/?page=new-app','/?page=connect-server','/?page=confirm-server','/?page=advanced','/'] as $route) {
        [$code,$body]=request(null,$route);
        if ($code!==200 || str_contains($body,'Warning:') || str_contains($body,'Control plane unavailable')) { throw new RuntimeException('Module view failed: '.$route); }
    }
    foreach (['/servers/_connect','/providers/_handle','/authentication/_logout','/app_flow/launch','/console/index','/servers_module/views/dialog.php','/providers_module/js/../../Providers.php','/servers_module/js/%2e%2e/Servers.php'] as $route) {
        if (request(null,$route)[0]!==404) { throw new RuntimeException('Internal module path exposed: '.$route); }
    }
    request(['command'=>'logout','csrf'=>$csrf]);
    [$code,$error]=request(['command'=>'connect-server','inline'=>'server','csrf'=>$csrf],'/?page=connect-server');
    // A destroyed session has a new CSRF token, so the request must fail before mutation.
    if ($code!==403) { throw new RuntimeException('Logged-out inline enrollment accepted'); }
    if (request(null,'/?page=deployment&poll=1&operation='.$id)[0]!==401) { throw new RuntimeException('Unauthenticated polling allowed'); }
    echo "HTTP login, CSRF and authenticated console passed\n";
} finally {
    proc_terminate($proc); fclose($pipes[0]); proc_close($proc); unset($store);
    foreach (glob($dir.'/*') as $f) { unlink($f); } rmdir($dir);
}
