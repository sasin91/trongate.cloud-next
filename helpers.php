<?php

declare(strict_types=1);

function state_dir(): string
{
    $path = getenv('TC_STATE') ?: APPPATH . 'var';
    if (!is_dir($path)) {
        mkdir($path, 0700, true);
    }
    return $path;
}

function installation_key(): string
{
    $path = getenv('TC_KEY_FILE') ?: state_dir() . '/key';
    if (!is_file($path)) {
        throw new RuntimeException('Installation key missing; run init.');
    }
    return trim(file_get_contents($path));
}

function env(string $key, ?string $default = null): ?string
{
    static $file_values = null;

    if ($file_values === null) {
        $file_values = [];
        $env_path = __DIR__ . DIRECTORY_SEPARATOR . '.env';
        if (is_file($env_path)) {
            $lines = file($env_path, FILE_IGNORE_NEW_LINES);
            if ($lines === false) {
                throw new RuntimeException('Unable to read the .env file.');
            }

            foreach ($lines as $index => $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                if (str_starts_with($line, 'export ')) {
                    $line = substr($line, 7);
                }

                $separator = strpos($line, '=');
                if ($separator === false) {
                    throw new RuntimeException('Invalid .env entry on line ' . ($index + 1) . '.');
                }

                $name = trim(substr($line, 0, $separator));
                if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name)) {
                    throw new RuntimeException('Invalid .env variable name on line ' . ($index + 1) . '.');
                }

                $value = trim(substr($line, $separator + 1));
                if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
                    $quote = $value[0];
                    $closing_quote = strrpos($value, $quote);
                    if ($closing_quote === 0 || $closing_quote === false) {
                        throw new RuntimeException('Unclosed .env value on line ' . ($index + 1) . '.');
                    }

                    $trailing = trim(substr($value, $closing_quote + 1));
                    if ($trailing !== '' && !str_starts_with($trailing, '#')) {
                        throw new RuntimeException('Unexpected text after .env value on line ' . ($index + 1) . '.');
                    }

                    $value = substr($value, 1, $closing_quote - 1);
                    if ($quote === '"') {
                        $value = stripcslashes($value);
                    } else {
                        $value = str_replace(["\\\\", "\\'"], ["\\", "'"], $value);
                    }
                } elseif (preg_match('/\s+#/', $value, $comment, PREG_OFFSET_CAPTURE)) {
                    $value = rtrim(substr($value, 0, $comment[0][1]));
                }

                $file_values[$name] = $value;
            }
        }
    }

    $process_value = getenv($key);
    if ($process_value !== false) {
        return $process_value;
    }
    if (isset($_ENV[$key]) && is_string($_ENV[$key])) {
        return $_ENV[$key];
    }
    if (isset($_SERVER[$key]) && is_string($_SERVER[$key])) {
        return $_SERVER[$key];
    }

    return $file_values[$key] ?? $default;
}
