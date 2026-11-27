<?php
declare(strict_types=1);

/**
 * Queries applications matching a pushed GitHub repository and branch.
 */
class Webhooks_model extends Model {
    function __construct(?string $module_name = null, PDO|Closure|null $connection = null) {
        parent::__construct($module_name, $connection);
        $this->module('records');
    }

    /**
     * Returns all apps configured to deploy from $repository (owner/name, case-insensitive)
     * and $branch (defaulting to 'main' when blank).
     *
     * @return list<array<string, mixed>>
     */
    function _apps_on_branch(string $repository, string $branch): array {
        $needle = strtolower(trim($repository, '/'));
        if ($needle === '' || $branch === '') {
            return [];
        }

        $matches = [];
        foreach ($this->records->_all('app') as $app) {
            $raw_repo = trim((string) ($app['repository'] ?? ''));
            if ($raw_repo === '') {
                continue;
            }
            try {
                $app_repo = strtolower(Apps::_repository($raw_repo));
            } catch (RuntimeException) {
                continue;
            }
            $app_branch = trim((string) ($app['branch'] ?? ''));
            if ($app_branch === '') {
                $app_branch = 'main';
            }
            if ($app_repo === $needle && $app_branch === $branch) {
                $matches[] = $app;
            }
        }

        usort($matches, static fn(array $a, array $b): int => strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? '')));
        return $matches;
    }
}
