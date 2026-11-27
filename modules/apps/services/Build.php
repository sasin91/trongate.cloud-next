<?php
declare(strict_types=1);

/**
 * Validates and prepares FreeBSD build-jail execution plans.
 *
 * Source is never evaluated on the control plane. Git checkout, Composer, and
 * optional customer build commands execute only inside an ephemeral rctl-bounded
 * FreeBSD build jail on the target host.
 */
require_once APPPATH . 'modules/validation/services/Validate.php';
require_once APPPATH . 'modules/apps/services/Build_commands.php';

final class Build {
    static function plan(array $p): array {
        $app = Validate::id((string) ($p['app'] ?? ''));
        $repository = Validate::match((string) ($p['repository'] ?? ''), '~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~D', 'GitHub repository');
        $commit = Validate::match((string) ($p['commit'] ?? ''), '/^[a-f0-9]{40}$/D', 'full source commit');
        $php_version = Validate::php_version((string) ($p['php_version'] ?? '8.4'));

        $raw_commands = (string) ($p['commands'] ?? '');
        $error = Build_commands::error($raw_commands);
        if ($error !== null) {
            throw new RuntimeException($error);
        }
        $commands = Build_commands::script($raw_commands);

        $raw_extensions = $p['extensions'] ?? [];
        if (!is_array($raw_extensions)) {
            throw new RuntimeException('Invalid PHP extensions');
        }
        $extensions = [];
        foreach ($raw_extensions as $extension) {
            if (!is_string($extension)) {
                throw new RuntimeException('Invalid PHP extension');
            }
            $name = Validate::match($extension, '/^[a-z][a-z0-9_]{0,39}$/D', 'PHP extension');
            if (!in_array($name, Build_commands::OPTIONAL_EXTENSIONS, true)) {
                throw new RuntimeException(
                    'Unsupported PHP extension: ' . $name . '. Available optional extensions: '
                    . implode(', ', Build_commands::OPTIONAL_EXTENSIONS) . '.'
                );
            }
            $extensions[] = $name;
        }
        $extensions = array_values(array_unique($extensions));

        return [
            'app' => $app,
            'repository' => $repository,
            'commit' => $commit,
            'php_version' => $php_version,
            'extensions' => $extensions,
            'commands' => $commands,
            'tools' => Build_commands::tools($commands),
        ];
    }
}
