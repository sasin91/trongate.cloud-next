<?php
declare(strict_types=1);

/**
 * Renders and patches Trongate configuration files mounted over an application release.
 *
 * Framework-free: loaded by the control plane and worker.
 */
final class Trongate_config {
    const CONFIG_VERSION = 'v1';

    /**
     * Patch only BASE_URL and ENV in an existing Trongate config/config.php,
     * preserving all user-defined constants, comments, and routing defaults.
     */
    static function patch_config_php(string $source, string $domain, string $env = 'production', bool $https = true): string {
        $domain = trim($domain);
        if ($domain === '' || str_contains($domain, "\0") || str_contains($env, "\0")) {
            throw new InvalidArgumentException('Invalid Trongate configuration value.');
        }

        $replacements = [
            'BASE_URL' => ($https ? 'https' : 'http') . '://' . trim($domain, '/') . '/',
            'ENV' => $env,
        ];
        $found = [];
        $tokens = token_get_all($source);
        $count = count($tokens);
        $out = '';
        $i = 0;

        $next_meaningful = static function (int $idx) use ($tokens, $count): ?int {
            while ($idx < $count) {
                $t = $tokens[$idx];
                if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    $idx++;
                    continue;
                }
                return $idx;
            }
            return null;
        };

        while ($i < $count) {
            $tok = $tokens[$i];
            if (is_array($tok) && $tok[0] === T_STRING && strcasecmp($tok[1], 'define') === 0) {
                $lparen = $next_meaningful($i + 1);
                if ($lparen !== null && $tokens[$lparen] === '(') {
                    $arg1 = $next_meaningful($lparen + 1);
                    if ($arg1 !== null && is_array($tokens[$arg1]) && $tokens[$arg1][0] === T_CONSTANT_ENCAPSED_STRING) {
                        $raw_name = $tokens[$arg1][1];
                        $quote = $raw_name[0];
                        $const_name = ($quote === "'" || $quote === '"') ? substr($raw_name, 1, -1) : '';
                        if (array_key_exists($const_name, $replacements)) {
                            $comma = $next_meaningful($arg1 + 1);
                            if ($comma !== null && $tokens[$comma] === ',') {
                                for ($k = $i; $k <= $comma; $k++) {
                                    $out .= is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];
                                }
                                $out .= ' ' . var_export($replacements[$const_name], true);
                                $found[$const_name] = true;

                                $depth = 1;
                                $k = $comma + 1;
                                while ($k < $count && $depth > 0) {
                                    $tk = $tokens[$k];
                                    if ($tk === '(') {
                                        $depth++;
                                    } elseif ($tk === ')') {
                                        $depth--;
                                        if ($depth === 0) {
                                            break;
                                        }
                                    }
                                    $k++;
                                }
                                $i = $k;
                                continue;
                            }
                        }
                    }
                }
            }
            $out .= is_array($tok) ? $tok[1] : $tok;
            $i++;
        }

        $missing = [];
        foreach ($replacements as $name => $value) {
            if (!isset($found[$name])) {
                $missing[] = "define('" . $name . "', " . var_export($value, true) . ");";
            }
        }
        if ($missing !== []) {
            $out = preg_replace('/\?>\s*\z/', '', $out);
            $out = rtrim((string) $out, "\r\n") . "\n" . implode("\n", $missing) . "\n";
        }

        return $out;
    }

    /**
     * Render a fallback Trongate config/config.php when no source file exists.
     */
    static function config_php(string $domain, string $env = 'production', bool $https = true): string {
        $domain = trim($domain);
        if ($domain === '' || str_contains($domain, "\0") || str_contains($env, "\0")) {
            throw new InvalidArgumentException('Invalid Trongate configuration value.');
        }

        $scheme = $https ? 'https' : 'http';
        $base_url = $scheme . '://' . trim($domain, '/') . '/';

        return "<?php\n"
            . "// Managed by trongate.cloud (" . self::CONFIG_VERSION . ")\n"
            . "define('BASE_URL', " . var_export($base_url, true) . ");\n"
            . "define('ENV', " . var_export($env, true) . ");\n"
            . "define('DEFAULT_MODULE', " . var_export('welcome', true) . ");\n"
            . "define('DEFAULT_CONTROLLER', " . var_export('Welcome', true) . ");\n"
            . "define('DEFAULT_METHOD', " . var_export('index', true) . ");\n"
            . "define('MODULE_ASSETS_TRIGGER', " . var_export('_module', true) . ");\n";
    }

    /**
     * Render a Trongate config/database.php file for Model.php.
     *
     * Defaults to 'localhost' so PHP connects via the jail's pdo_mysql.default_socket
     * to the shared tc_mariadb Unix socket.
     */
    static function database_php(string $database, string $username, string $password, string $host = 'localhost'): string {
        foreach ([$database, $username, $password, $host] as $value) {
            if (str_contains($value, "\0")) {
                throw new InvalidArgumentException('Invalid Trongate database value.');
            }
        }
        if (trim($database) === '' || trim($username) === '' || trim($host) === '') {
            throw new InvalidArgumentException('Database name, username, and host are required.');
        }

        return "<?php\n"
            . "// Managed by trongate.cloud (" . self::CONFIG_VERSION . ")\n"
            . "define('HOST', " . var_export($host, true) . ");\n"
            . "define('PORT', '3306');\n"
            . "define('USER', " . var_export($username, true) . ");\n"
            . "define('PASSWORD', " . var_export($password, true) . ");\n"
            . "define('DATABASE', " . var_export($database, true) . ");\n";
    }
}
