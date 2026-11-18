<?php
declare(strict_types=1);
final class Build {
    static function plan(array $p): array {
        Validate::match($p['repository'], '~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~D', 'GitHub repository');
        Validate::match($p['commit'], '/^[a-f0-9]{40}$/D', 'full source commit');
        Validate::image($p['base']);
        Validate::match($p['tag'], '~^[a-z0-9][a-z0-9.:/_-]+:[a-z0-9._-]+$~D', 'registry tag');
        if ($error = Build_commands::error($p['commands'] ?? '')) {
            throw new RuntimeException($error);
        }
        $extensions = [];
        foreach ($p['extensions'] ?? [] as $extension) {
            $extensions[] = Validate::match($extension, '/^[a-z][a-z0-9_]{0,39}$/D', 'PHP extension');
        }
        // Source is never evaluated on the control plane. Composer and customer commands
        // execute only inside the dedicated builder's bounded Podman build containers.
        $setup = ['COPY . ./', 'RUN if [ -f composer.json ]; then composer install --no-dev --optimize-autoloader --no-scripts --no-interaction; fi'];
        $lines = Build_commands::dockerfile($p['base'], $extensions?['RUN install-php-extensions ' . implode(' ', $extensions)]:[], $setup, [], $p['commands'] ?? '');
        return ['repository' => $p['repository'], 'commit' => $p['commit'], 'tag' => $p['tag'], 'dockerfile' => implode("\n", $lines) . "\n"];
    }
}
