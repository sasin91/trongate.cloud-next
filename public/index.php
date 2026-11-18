<?php
declare(strict_types=1);
require dirname(__DIR__).'/lib/bootstrap.php';
require dirname(__DIR__).'/engine/Trongate.php';
require dirname(__DIR__).'/engine/Core.php';
define('APPPATH',dirname(__DIR__).'/');
define('ENV','prod'); define('DEFAULT_MODULE','console'); define('DEFAULT_METHOD','index'); define('MODULE_ASSETS_TRIGGER','_module');
define('BASE_URL',rtrim(getenv('TC_BASE_URL') ?: 'http://localhost:8080','/').'/');
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
// Core owns module asset serving. Only known presentation assets enter that path;
// all controller requests enter Console::index and its authenticated command map.
$moduleAsset=preg_match('~^/(console|apps|servers|providers|deployments)_module/(css/[a-z-]+\.css|js/[a-z-]+\.js)$~D',$path)===1;
if (!$moduleAsset && !in_array($path,['/','/index.php'],true)) { http_response_code(404); exit('Not found'); }
define('ASSUMED_URL',$moduleAsset?rtrim(BASE_URL,'/').$path:BASE_URL);
define('SEGMENTS',$moduleAsset?explode('/',trim($path,'/')):[]);
function segment(int $n): string { return SEGMENTS[$n-1] ?? ''; }
header('Content-Security-Policy: default-src \'self\'; style-src \'self\' \'unsafe-inline\'; frame-ancestors \'none\'; form-action \'self\'');
header('X-Content-Type-Options: nosniff'); header('Cache-Control: no-store');
session_set_cookie_params(['secure'=>str_starts_with(BASE_URL,'https://'),'httponly'=>true,'samesite'=>'Strict']);
ini_set('session.use_strict_mode','1'); session_start();
chdir(__DIR__);
try { new Core(); } catch (Throwable $e) { http_response_code(503); echo 'Control plane unavailable. Use the local CLI to inspect database and installation configuration.'; }
