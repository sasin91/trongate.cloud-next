<?php

final class Environment
{
    private static ?Environment $instance = null;

    private array $values = [];

    private function __construct()
    {
        $this->load_env_file();
    }
    
    public static function instance(): Environment
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    function load_env_file(): void
    {
        $lines = file(APPPATH . '/.env', FILE_IGNORE_NEW_LINES);

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

            if (!preg_match('/^([^=]+)=(.*)$/', $line, $entry)) {
                throw new RuntimeException('Invalid .env entry on line ' . ($index + 1) . '.');
            }

            $name = trim($entry[1]);
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name)) {
                throw new RuntimeException('Invalid .env variable name on line ' . ($index + 1) . '.');
            }

            $value = trim($entry[2]);
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
            } else {
                $value = rtrim(preg_replace('/\s+#.*$/', '', $value) ?? $value);
            }

            $this->values[$name] = $value;
        }
    }

    function get(string $key, ?string $default = null): ?string
    {
        return $this->values[$key] ?? $default;
    }
}
