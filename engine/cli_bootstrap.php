<?php
declare(strict_types=1);

if (!defined('APPPATH')) {
    define('APPPATH', str_replace('\\', '/', dirname(__DIR__)) . '/');
}

if (!defined('REQUEST_TYPE')) {
    define('REQUEST_TYPE', 'CLI');
}
if (!defined('SEGMENTS')) {
    define('SEGMENTS', []);
}

if (($state_dir = trim((string) (getenv('TC_STATE') ?: ''))) !== '') {
    $db_env_file = rtrim($state_dir, '/\\') . '/db.env';
    if (is_readable($db_env_file)) {
        $db_environment = parse_ini_file($db_env_file, false, INI_SCANNER_RAW);
        if ($db_environment === false) {
            throw new RuntimeException('Unable to read database environment configuration.');
        }
        foreach (['TC_DSN', 'TC_DB_USER', 'TC_DB_PASSWORD'] as $name) {
            if (isset($db_environment[$name])) {
                putenv($name . '=' . $db_environment[$name]);
            }
        }
    }
}

require_once APPPATH . 'helpers.php';
require_once APPPATH . 'config/config.php';
require_once APPPATH . 'config/database.php';

if (isset($databases)) {
    $GLOBALS['databases'] = $databases;
}

spl_autoload_register(static function (string $class_name): bool {
    $engine_file = APPPATH . 'engine/' . $class_name . '.php';
    if (is_file($engine_file)) {
        require_once $engine_file;
        return true;
    }

    $module_file = APPPATH . 'modules/' . strtolower($class_name) . '/' . $class_name . '.php';
    if (is_file($module_file)) {
        require_once $module_file;
        return true;
    }

    return false;
});

require_once APPPATH . 'engine/tg_helpers/flashdata_helper.php';
require_once APPPATH . 'engine/tg_helpers/form_helper.php';
require_once APPPATH . 'engine/tg_helpers/string_helper.php';
require_once APPPATH . 'engine/tg_helpers/url_helper.php';
require_once APPPATH . 'engine/tg_helpers/utilities_helper.php';
