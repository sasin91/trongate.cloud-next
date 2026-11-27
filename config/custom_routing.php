<?php
$routes = [
    'tg-admin' => 'login/login/tg-admin',
    'tg-team' => 'login/login/tg-team',
    'auth/github' => 'login-github/start',
    'auth/github/callback' => 'login-github/callback',
    'auth/github/installed' => 'login-github/installed',
    'auth/github/disconnect' => 'login-github/disconnect',
    'api/v1/modules' => 'registry/api_modules',
    'api/v1/modules/(:any)/(:any)' => 'registry/api_module/$1/$2',
    'archives/(:any)/(:any)/(:any)' => 'registry/archive/$1/$2/$3',
    'git/(:all)' => 'registry/git'
];
define('CUSTOM_ROUTES', $routes);