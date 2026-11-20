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
function request(?array $body=null,string $path='/login/login/1',bool $mx=false): array {
    global $cookie;
    $c=curl_init('http://127.0.0.1:18781'.$path); curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_TIMEOUT=>5,CURLOPT_HTTPHEADER=>array_merge(['X-Requested-With: XMLHttpRequest'], $mx ? ['Trongate-MX-Request: true'] : [])]);
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
    if (request(['app'=>'demo','mode'=>'automatic','csrf_token'=>'bad'],'/servers/details?server=web')[0]!==403) { throw new RuntimeException('Resource edit bypassed CSRF'); }
    [$code,$page]=request(['command'=>'inventory','csrf_token'=>$csrf,'payload'=>'{}'],'/operations');
    if ($code!==200 || !str_contains($page,'Servers')) { throw new RuntimeException('Authenticated operation failed'); }
    $store->put('server','web',['application_ready'=>true,'host'=>'192.0.2.10','managed_eligible'=>false,'missing'=>[]]);
    [$code,$page]=request(null,'/apps/create');
    if ($code!==200 || !str_contains($page,'Which app are we putting online?')) { throw new RuntimeException('Wizard missing'); }
    if (str_contains($page,'name="builder"') || str_contains($page,'name="tag"') || !str_contains($page,'Build commands') || !str_contains($page,'Extra PHP extensions')) { throw new RuntimeException('Build infrastructure exposed in app wizard'); }
    if (str_contains($page,'id="server-dialog"') || !str_contains($page,'href="/servers"')) { throw new RuntimeException('Wizard must link to server pages'); }
    preg_match('/name="request" value="([a-f0-9]+)"/',$page,$launch);
    if (request(['csrf_token'=>'bad','label'=>'draft'],'/apps/draft')[0]!==403) { throw new RuntimeException('Draft bypassed CSRF'); }
    [$code,$saved]=request(['csrf_token'=>$csrf,'source'=>'image','label'=>'saved-app','environment'=>'PASSWORD=draft-secret','wizard_step'=>'3'],'/apps/draft');
    if ($code!==200 || !json_decode($saved,true)['saved']) { throw new RuntimeException('Draft not saved'); }
    [$code,$page]=request(null,'/servers');
    if (!str_contains($page,'Create at a provider') || !str_contains($page,'Connect an existing server')) { throw new RuntimeException('Server pages missing'); }
    [$code,$page]=request(null,'/apps/create');
    if (!str_contains($page,'saved-app') || !str_contains($page,'PASSWORD=draft-secret') || !str_contains($page,'data-step="3"') || !str_contains($page,$launch[1])) { throw new RuntimeException('App draft or launch identity lost'); }
    $savedRecord=$store->run("SELECT data FROM records WHERE kind='app-draft'")->fetchColumn();
    if (str_contains($savedRecord,'draft-secret')) { throw new RuntimeException('Draft secret stored unencrypted'); }
    request(['csrf_token'=>$csrf],'/login/logout');
    if (request(null, '/servers/details?server=web', true)[0] !== 401) { throw new RuntimeException('MX must reject expired sessions without embedding login'); }
    // Start a fresh browser session as well as revoking the token.
    unlink($cookie);
    [$code,$page]=request();
    preg_match('/name="csrf_token" value="([a-f0-9]+)"/',$page,$m); $csrf=$m[1];
    request(['csrf_token'=>$csrf,'identifier'=>'admin','password'=>'test-only-long-password'],'/login/submit_login/1');
    [$code,$page]=request(null,'/apps');
    if (!str_contains($page,'Finish setting up your app.') || !str_contains($page,'Continue app setup')) { throw new RuntimeException('Dashboard resume missing after login'); }
    [$code,$page]=request(null,'/apps/create');
    if (!str_contains($page,'PASSWORD=draft-secret') || !str_contains($page,'data-step="3"') || !str_contains($page,$launch[1])) { throw new RuntimeException('Draft lost across fresh login'); }
    $draftModel=new Apps_model('apps',$store->connection());
    if ($draftModel->_load_draft(999999)!==null) { throw new RuntimeException('Draft leaked to another account'); }
    $store->put('provider','test-provider',['type'=>'hetzner','label'=>'Test project','credential'=>'test-token']);
    $store->secret('test-token','old-token');
    [$code,$page]=request(null,'/providers');
    if ($code!==200 || !str_contains($page,'Test project') || !str_contains($page,'Validate and update token') || str_contains($page,'old-token')) { throw new RuntimeException('Provider management missing or token exposed'); }
    if (request(['csrf_token'=>'bad','command'=>'update-token','provider'=>'test-provider','token'=>'replacement'],'/providers')[0]!==403) { throw new RuntimeException('Token update bypassed CSRF'); }

    [$code,$error]=request(['command'=>'create-provider-server','inline'=>'server','csrf_token'=>$csrf,'quote'=>'unconfirmed'],'/providers/action');
    if ($code!==422) { throw new RuntimeException('Provider creation without cost confirmation accepted'); }
    [$code,$error]=request(['command'=>'connect-server','inline'=>'server','csrf_token'=>$csrf,'id'=>'new-server','host'=>'192.0.2.11','user'=>'root','port'=>22,'private_key'=>''],'/servers/action');
    if ($code!==422 || !isset(json_decode($error,true)['error'])) { throw new RuntimeException('Inline errors must return JSON without navigating away'); }
    [$code,$error]=request(['command'=>'confirm-server','inline'=>'server','csrf_token'=>$csrf,'enrollment'=>'expired','fingerprint'=>'SHA256:fixture'],'/servers/action');
    if ($code!==422 || !str_contains(json_decode($error,true)['error'],'expired')) { throw new RuntimeException('Inline confirmation must reject stale enrollment'); }
    $provision=$store->enqueue('provision','provider:test-provider',['name'=>'created-web','provider'=>'test-provider','image_kind'=>'snapshot']);
    $store->put('provider-server','12345',['server'=>['id'=>12345,'public_net'=>['ipv4'=>['ip'=>'192.0.2.25']]]]);
    $store->run('UPDATE jobs SET status=?,result=? WHERE id=?',['succeeded',tc_json(['provider_id'=>12345]),$provision]);
    foreach (['/apps','/servers'] as $route) {
        [$code,$page]=request(null,$route);
        if ($code!==200 || !str_contains($page,'created-web') || !str_contains($page,'Finish connecting server') || !str_contains($page,'/servers/connect?operation='.$provision)) { throw new RuntimeException('Provisioned server missing from inventory page'); }
    }
    $store->step($provision,'claimed',['attempt'=>1]);
    $store->step($provision,'observed',['status'=>'succeeded','secret'=>'must-not-be-in-progress']);
    [$code,$progress]=request(null,'/servers/provision?poll=1&operation='.$provision);
    $progressData=json_decode($progress,true);
    if ($code!==200 || $progressData['stage']!==3 || count($progressData['logs'])!==3 || str_contains($progress,'must-not-be-in-progress')) { throw new RuntimeException('Creation progress invalid or leaked raw data'); }
    [$code,$page]=request(null,'/servers/connect?operation='.$provision);
    if ($code!==200 || !str_contains($page,'value="192.0.2.25"') || !str_contains($page,'value="'.$provision.'"')) { throw new RuntimeException('Persisted server connection could not resume'); }
    [$code,$page]=request(null,'/servers/provision?operation='.$provision);
    if ($code!==200 || !str_contains($page,'192.0.2.25')) { throw new RuntimeException('Provision progress could not resume'); }
    if (str_contains($page,'Verified fingerprint') || str_contains($page,'independent console')) { throw new RuntimeException('Manual fingerprint step remains'); }
    [$code,$page]=request(null,'/servers/delete?operation='.$provision);
    if ($code!==200 || !str_contains($page,'Permanently delete server') || !str_contains($page,'created-web')) { throw new RuntimeException('Deletion review missing'); }
    if (request(['operation'=>$provision,'name'=>'created-web','csrf_token'=>'bad'],'/servers/delete')[0]!==403) { throw new RuntimeException('Deletion bypassed CSRF'); }
    request(['operation'=>$provision,'name'=>'wrong','csrf_token'=>$csrf],'/servers/delete');
    if ((int)$store->run("SELECT COUNT(*) FROM jobs WHERE kind='provider-delete'")->fetchColumn()!==0) { throw new RuntimeException('Wrong name triggered deletion'); }
    $store->put('server','created-web',['application_ready'=>true,'provider'=>'test-provider','provider_id'=>12345,'host'=>'192.0.2.25']);
    $serversModel=new Servers_model('servers',$store->connection());
    if ($serversModel->_pending_connections()!==[]) { throw new RuntimeException('Connected provider server listed twice'); }
    unset($serversModel);
    $store->run('DELETE FROM jobs WHERE id=?',[$provision]);
    $fields=['command'=>'launch-app','csrf_token'=>$csrf,'request'=>$launch[1],'source'=>'image','label'=>'web-demo','server'=>'web','domain'=>'demo.example.com','image'=>'registry.example.com/demo@sha256:'.str_repeat('a',64),'environment'=>'PASSWORD=web-fixture-secret'];
    if (request($fields,'/apps/create')[0]!==303 || request($fields,'/apps/create')[0]!==303) { throw new RuntimeException('Wizard launch or duplicate failed'); }
    if ((int)$store->run('SELECT COUNT(*) FROM jobs')->fetchColumn()!==1) { throw new RuntimeException('Repeated HTTP submission duplicated deployment'); }
    if ((int)$store->run("SELECT COUNT(*) FROM records WHERE kind='app-draft'")->fetchColumn()!==0) { throw new RuntimeException('Launched draft retained'); }
    $id=$store->run('SELECT id FROM jobs')->fetchColumn();
    [$code,$page]=request(null,'/deployments?operation='.$id);
    if ($code!==200 || !str_contains($page,'deployment-timeline') || str_contains($page,'web-fixture-secret')) { throw new RuntimeException('Deployment view failed or exposed secret'); }
    [$code,$page]=request(null,'/deployments?poll=1&operation='.$id);
    if ($code!==200 || json_decode($page,true)['job']['status']!=='pending' || str_contains($page,'web-fixture-secret')) { throw new RuntimeException('Deployment polling failed'); }
    [$code, $timelineJson] = request(null, '/deployments?poll=1&timeline=1&operation=' . $id);
    if ($code !== 200 || count(json_decode($timelineJson, true)['stages']) !== 4 || str_contains($timelineJson, 'web-fixture-secret')) { throw new RuntimeException('Timeline API failed'); }
    [$mxCode, $fragment] = request(null, '/deployments?operation=' . $id, true);
    if ($mxCode !== 200 || str_contains($fragment, '<html') || !str_contains($fragment, 'id="deployment-content"') || !str_contains($fragment, 'every 3s')) { throw new RuntimeException('MX timeline must return a polling fragment'); }
    [$mxCode, $fragment] = request(null, '/servers/details?server=missing', true);
    if ($mxCode !== 200 || str_contains($fragment, '<html') || !str_contains($fragment, 'id="resource-workspace"') || !str_contains($fragment, 'role="alert"')) { throw new RuntimeException('MX resource errors must remain inline'); }
    if (request(['csrf_token' => 'bad'], '/servers/details?server=web', true)[0] !== 403) { throw new RuntimeException('MX resource edit bypassed CSRF'); }
    if (request(['operation' => $id, 'csrf_token' => 'bad'], '/deployments/live')[0] !== 403) { throw new RuntimeException('Go live bypassed CSRF'); }
    foreach (['/apps_module/js/flows.js','/templates_module/css/wizard.css','/templates_module/css/panel.css','/templates_module/js/mx.js','/servers_module/js/resources.js','/deployments_module/css/deployments.css'] as $asset) {
        [$code,$body]=request(null,$asset);
        if ($code!==200 || strlen($body)<100 || str_contains($body,'Control plane unavailable')) { throw new RuntimeException('Module asset not served: '.$asset); }
    }
    foreach (['/servers/builds','/apps/create','/apps','/servers','/servers/connect','/servers/create','/servers/confirm','/providers','/operations','/'] as $route) {
        [$code,$body]=request(null,$route);
        if ($code!==200 || str_contains($body,'Warning:') || str_contains($body,'Control plane unavailable')) { throw new RuntimeException('Module view failed: '.$route); }
    }
    foreach (['/servers/_connect','/providers/_handle','/providers/_quote','/apps/_plan_launch','/apps/_deploy_built','/authentication/_logout','/app_flow/launch','/servers_module/views/dialog.php','/providers_module/js/../../Providers.php','/servers_module/js/%2e%2e/Servers.php'] as $route) {
        if (!in_array(request(null,$route)[0],[400,403,404],true)) { throw new RuntimeException('Internal module path exposed: '.$route); }
    }
    request(['csrf_token'=>$csrf],'/login/logout');
    if (request(null, '/servers/details?server=web', true)[0] !== 401) { throw new RuntimeException('MX must reject expired sessions without embedding login'); }
    if ((int)$store->run('SELECT COUNT(*) FROM trongate_tokens')->fetchColumn()!==0) { throw new RuntimeException('Logout did not revoke native token'); }
    [$code,$error]=request(['command'=>'connect-server','inline'=>'server','csrf_token'=>$csrf],'/servers/action');
    // A destroyed session has a new CSRF token, so the request must fail before mutation.
    if ($code!==401) { throw new RuntimeException('Logged-out inline enrollment accepted'); }
    if (request(null,'/servers/provision?poll=1&operation='.$provision)[0]!==401) { throw new RuntimeException('Creation polling bypassed login'); }
    if (request(null,'/deployments?poll=1&operation='.$id)[0]!==401) { throw new RuntimeException('Unauthenticated polling allowed'); }
    if (request(null,'/apps/create')[0]!==302) { throw new RuntimeException('Module route bypassed login'); }
    [$code,$login]=request(null,'/login/login/1');
    if ($code!==200 || !str_contains($login,'Welcome back.') || str_contains($login,'Which app are we putting online?')) { throw new RuntimeException('Module route bypassed login'); }
    preg_match('/name="csrf_token" value="([a-f0-9]+)"/',$login,$m);
    [$code,$error]=request(['command'=>'create-provider-server','inline'=>'server','csrf_token'=>$m[1],'quote'=>'fixture','confirm_cost'=>'1'],'/providers/action');
    if ($code!==401 || !isset(json_decode($error,true)['error'])) { throw new RuntimeException('Provider route bypassed authentication'); }
    if (request(null,'/servers/provision?poll=1&operation='.$provision)[0]!==401) { throw new RuntimeException('Creation polling bypassed login'); }
    if (request(null,'/deployments?poll=1&operation='.$id)[0]!==401) { throw new RuntimeException('Module polling bypassed authentication'); }
    echo "HTTP login, CSRF and authenticated console passed\n";
} finally {
    proc_terminate($proc); fclose($pipes[0]); proc_close($proc); unset($draftModel, $store);
    foreach (glob($dir.'/*') as $f) { unlink($f); } rmdir($dir);
    if ($mysqlAdmin) { $mysqlAdmin->exec('DROP DATABASE '.$testDatabase); }
}
