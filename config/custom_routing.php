<?php
$routes = [
    'tg-admin' => 'login/login/tg-admin',
    'tg-team' => 'login/login/tg-team',
    'auth/github' => 'login-github/start',
    'auth/github/callback' => 'login-github/callback',
    'auth/github/installed' => 'login-github/installed',
    'auth/github/disconnect' => 'login-github/disconnect'
];
define('CUSTOM_ROUTES', $routes);