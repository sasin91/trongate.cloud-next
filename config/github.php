<?php

require_once dirname(__DIR__) . '/helpers.php';

define('GITHUB_APP_CLIENT_ID', env('GITHUB_APP_CLIENT_ID', ''));
define('GITHUB_APP_CLIENT_SECRET', env('GITHUB_APP_CLIENT_SECRET', ''));
define('GITHUB_APP_SLUG', env('GITHUB_APP_SLUG', ''));
define('GITHUB_APP_WEBHOOK_SECRET', env('GITHUB_APP_WEBHOOK_SECRET', ''));
define('GITHUB_APP_PRIVATE_KEY', env('GITHUB_APP_PRIVATE_KEY', ''));
define('GITHUB_APP_PRIVATE_KEY_FILE', env('GITHUB_APP_PRIVATE_KEY_FILE', ''));