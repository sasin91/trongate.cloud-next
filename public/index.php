<?php
declare(strict_types=1);
if (PHP_SAPI==='cli-server' && preg_match('~^/assets/[a-z-]+\.(?:css|js)$~D',parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)) && is_file(__DIR__.parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH))) { return false; }
require dirname(__DIR__).'/lib/bootstrap.php';
require dirname(__DIR__).'/engine/Trongate.php';
require dirname(__DIR__).'/engine/Core.php';
define('APPPATH',dirname(__DIR__).'/');
define('ENV','prod'); define('DEFAULT_MODULE','console'); define('DEFAULT_METHOD','index'); define('MODULE_ASSETS_TRIGGER','_module');
define('BASE_URL',rtrim(getenv('TC_BASE_URL') ?: 'http://localhost:8080','/').'/');
define('ASSUMED_URL',BASE_URL); define('SEGMENTS',[]);
function segment(int $n): string { return ''; }
// Only the console's index is exposed; CLI/service methods cannot be URL-dispatched.
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if (!in_array($path,['/','/index.php'])) { http_response_code(404); exit('Not found'); }
header('Content-Security-Policy: default-src \'self\'; style-src \'self\' \'unsafe-inline\'; frame-ancestors \'none\'; form-action \'self\'');
header('X-Content-Type-Options: nosniff'); header('Cache-Control: no-store');
session_set_cookie_params(['secure'=>str_starts_with(BASE_URL,'https://'),'httponly'=>true,'samesite'=>'Strict']);
ini_set('session.use_strict_mode','1'); session_start();
chdir(__DIR__);
try { new Core(); } catch (Throwable $e) { http_response_code(503); echo 'Control plane unavailable. Use the local CLI to inspect database and installation configuration.'; }
