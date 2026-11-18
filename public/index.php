<?php
declare(strict_types=1);
require dirname(__DIR__).'/lib/bootstrap.php';
require_once dirname(__DIR__).'/engine/Trongate.php';
require dirname(__DIR__).'/engine/Core.php';
define('APPPATH',dirname(__DIR__).'/');
define('ENV','prod'); define('DEFAULT_MODULE','console'); define('DEFAULT_METHOD','index'); define('MODULE_ASSETS_TRIGGER','_module');
define('BASE_URL',rtrim(getenv('TC_BASE_URL') ?: 'http://localhost:8080','/').'/');
define('ERROR_404','error_pages/not_found');
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if ($path==='/index.php') { $path='/'; }
define('ASSUMED_URL',rtrim(BASE_URL,'/').$path);
// Core reads module/method at indexes 1 and 2; retain the leading empty segment.
define('SEGMENTS',explode('/',$path));
function segment(int $n): string { return SEGMENTS[$n] ?? ''; }
header('Content-Security-Policy: default-src \'self\'; style-src \'self\' \'unsafe-inline\'; frame-ancestors \'none\'; form-action \'self\'');
header('X-Content-Type-Options: nosniff'); header('Cache-Control: no-store');
session_set_cookie_params(['secure'=>str_starts_with(BASE_URL,'https://'),'httponly'=>true,'samesite'=>'Strict']);
ini_set('session.use_strict_mode','1'); session_start();
chdir(__DIR__);
try { new Core(); } catch (Throwable $e) { http_response_code(503); echo 'Control plane unavailable. Use the local CLI to inspect database and installation configuration.'; }
