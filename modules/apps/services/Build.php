<?php
declare(strict_types=1);
final class Build {
    const DEFAULT_BASE = 'docker.io/dunglas/frankenphp@sha256:035fcb2fab91aacf77d70ee555d51b9bb084d057b16cf8a644bf1f93e28d4e3e';
    const COMPOSER_IMAGE = 'docker.io/library/composer@sha256:919efe8e62179c71dfd31fad035fd671fe7454211c6b2aa30a10af0ab4acedba';

    static function plan(array $p): array {
        Validate::match($p['repository'], '~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~D', 'GitHub repository');
        Validate::match($p['commit'], '/^[a-f0-9]{40}$/D', 'full source commit');
        $defaultBase = trim($p['base'] ?? '') === '';
        $p['base'] = $defaultBase ? self::DEFAULT_BASE : trim($p['base']);
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
        $preparation = [];
        if ($defaultBase) {
            $extensions = array_unique(array_merge(['pdo_mysql', 'redis', 'gd', 'intl', 'zip', 'bcmath', 'exif'], $extensions));
            $port = Validate::number($p['container_port'] ?? 8080, 1, 65535);
            $preparation = [
                'WORKDIR /app',
                'RUN apt-get update && apt-get install -y --no-install-recommends git unzip zip && rm -rf /var/lib/apt/lists/*',
                'COPY --from=' . self::COMPOSER_IMAGE . ' /usr/bin/composer /usr/bin/composer',
                'RUN cp /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini',
                'ENV SERVER_NAME=:' . $port . ' SERVER_ROOT=/app/public COMPOSER_ALLOW_SUPERUSER=1 XDG_CONFIG_HOME=/tmp/caddy/config XDG_DATA_HOME=/tmp/caddy/data'
            ];
        }
        if ($extensions) {
            $preparation[] = 'RUN install-php-extensions ' . implode(' ', $extensions);
        }
        $lines = Build_commands::dockerfile($p['base'], $preparation, $setup, [], $p['commands'] ?? '');
        return ['repository' => $p['repository'], 'commit' => $p['commit'], 'tag' => $p['tag'], 'dockerfile' => implode("\n", $lines) . "\n"];
    }
}
