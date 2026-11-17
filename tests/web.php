<?php
require dirname(__DIR__).'/lib/bootstrap.php';
$dir=sys_get_temp_dir().'/tc-web-'.bin2hex(random_bytes(6)); mkdir($dir);
putenv('TC_DSN=sqlite:'.$dir.'/state.db'); putenv('TC_STATE='.$dir); putenv('TC_BASE_URL=http://127.0.0.1:18781');
$store=Store::open(); $store->migrate(); $store->run('INSERT INTO admins VALUES(?,?)',['admin',password_hash('test-only-long-password',PASSWORD_DEFAULT)]);
file_put_contents($dir.'/key',Encryptor::generate_key());
$proc=proc_open([PHP_BINARY,'-S','127.0.0.1:18781','-t',dirname(__DIR__).'/public',dirname(__DIR__).'/public/index.php'],[0=>['pipe','r'],1=>['file',$dir.'/server.log','w'],2=>['file',$dir.'/server.log','a']],$pipes);
$cookie=$dir.'/cookies';
function request(?array $body=null): array {
    global $cookie;
    $c=curl_init('http://127.0.0.1:18781/'); curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_TIMEOUT=>5]);
    if ($body!==null) { curl_setopt($c,CURLOPT_POSTFIELDS,http_build_query($body)); }
    $html=curl_exec($c); $code=curl_getinfo($c,CURLINFO_RESPONSE_CODE); curl_close($c); return [$code,$html];
}
try {
    for ($i=0;$i<30;$i++) { usleep(100000); [$code,$page]=request(); if ($code===200) { break; } }
    if (!str_contains((string)$page,'Sign in')) { throw new RuntimeException('Login page failed: '.file_get_contents($dir.'/server.log')); }
    preg_match('/name="csrf" value="([a-f0-9]+)"/',$page,$m); $csrf=$m[1];
    if (request(['command'=>'inventory','csrf'=>'bad'])[0]!==403) { throw new RuntimeException('CSRF not enforced'); }
    [$code,$page]=request(['command'=>'login','csrf'=>$csrf,'name'=>'admin','password'=>'test-only-long-password']);
    if (!str_contains($page,'Perform an operation')) { throw new RuntimeException('Login failed'); }
    [$code,$page]=request(['command'=>'inventory','csrf'=>$csrf,'payload'=>'{}']);
    if ($code!==200 || !str_contains($page,'Servers')) { throw new RuntimeException('Authenticated operation failed'); }
    echo "HTTP login, CSRF and authenticated console passed\n";
} finally {
    proc_terminate($proc); fclose($pipes[0]); proc_close($proc); unset($store);
    foreach (glob($dir.'/*') as $f) { unlink($f); } rmdir($dir);
}
