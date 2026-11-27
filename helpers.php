<?php
declare(strict_types=1);

function state_dir(): string {
    $path = getenv('TC_STATE') ?: APPPATH . 'var';
    if (!is_dir($path)) {
        mkdir($path, 0700, true);
    }
    return $path;
}
function installation_key(): string {
    $path = getenv('TC_KEY_FILE') ?: state_dir() . '/key';
    if (!is_file($path)) {
        throw new RuntimeException('Installation key missing; run init.');
    }
    return trim(file_get_contents($path));
}
