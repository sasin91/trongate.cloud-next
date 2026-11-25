<?php
declare(strict_types=1);

require_once APPPATH . 'modules/trongate_security/traits/Encrypted_credentials.php';
require_once APPPATH . 'modules/jobs/traits/Job_queue.php';

class Apps_model extends Model {
    use Encrypted_credentials, Job_queue;

    function __construct(?string $module_name = null, PDO|Closure|null $connection = null) {
        parent::__construct($module_name, $connection);
        $this->module('records');
    }

    function _load_draft(int $user): ?array {
        return $this->records->_find(['kind' => 'app-draft', 'id' => (string) $user]);
    }

    function _save_draft(int $user, string $encrypted, string $request): void {
        $this->records->_put([
            'kind' => 'app-draft',
            'id' => (string) $user,
            'data' => ['encrypted' => $encrypted, 'request' => $request],
        ]);
    }

    function _delete_draft(int $user): void {
        $this->records->_delete(['kind' => 'app-draft', 'id' => (string) $user]);
    }

    function _build_target(string $target, string $app): array {
        $application = $this->records->_get(['kind' => 'server', 'id' => $target]);
        if (empty($application['local_builder_ready'])) {
            throw new RuntimeException('Enable local builds on this FreeBSD server from Servers before continuing. Your setup is saved.');
        }
        return [
            'builder' => $target,
            'app' => Validate::id($app),
        ];
    }

    function _plan_launch(array $p, string $request, ?array $source_data = null): string {
        $existing_launch = $this->records->_find(['kind' => 'launch', 'id' => $request]);
        if ($existing_launch !== null) {
            return $existing_launch['operation'];
        }

        $id = Validate::id(trim((string) ($p['label'] ?? '')));
        $server = Validate::id((string) ($p['server'] ?? ''));
        $server_record = $this->records->_get(['kind' => 'server', 'id' => $server]);
        if (empty($server_record['application_ready'])) {
            throw new RuntimeException('Choose a FreeBSD server that is ready for applications.');
        }

        $repository = Apps::_repository((string) ($p['repository'] ?? ''));
        $branch = trim((string) ($p['branch'] ?? 'main'));
        if ($branch === '' || strlen($branch) > 200) {
            throw new RuntimeException('Choose a branch or commit.');
        }
        if ($source_data === null || ($source_data['repository'] ?? '') !== $repository || ($source_data['branch'] ?? '') !== $branch) {
            throw new RuntimeException('Resolve the source revision before planning a build.');
        }
        $commit = Validate::match((string) ($source_data['commit'] ?? ''), '/^[a-f0-9]{40}$/D', 'source commit');
        $metadata = $source_data;

        $target = $this->_build_target($server, $id);
        $builder = $target['builder'];

        $php_version = trim((string) ($p['php_version'] ?? '8.4'));
        if ($php_version === '') {
            $php_version = '8.4';
        }
        $php_version = Validate::php_version($php_version);

        $raw_database = $p['database'] ?? '1';
        if (is_bool($raw_database)) {
            $database = $raw_database;
        } elseif (in_array((string) $raw_database, ['1', 'true', 'on'], true)) {
            $database = true;
        } elseif (in_array((string) $raw_database, ['0', 'false', 'off', ''], true)) {
            $database = false;
        } else {
            throw new RuntimeException('Invalid database option');
        }

        $raw_quota = trim((string) ($p['disk_quota_mb'] ?? ''));
        $disk_quota_mb = $raw_quota === '' ? 1024 : Validate::number($raw_quota, 128, 1048576);

        $health = trim((string) ($p['health'] ?? '/'));
        if ($health === '') {
            $health = '/';
        }

        $raw_extensions = preg_split('/[\s,]+/', trim((string) ($p['php_extensions'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $extensions = [];
        foreach ($raw_extensions as $extension) {
            $normalized = strtolower($extension);
            if (in_array($normalized, Build_commands::BASE_EXTENSIONS, true)) {
                continue;
            }
            $extensions[] = $normalized;
        }
        $extensions = array_values(array_unique($extensions));

        $mounts = [];
        foreach (preg_split('/\r?\n/', trim((string) ($p['persistent_paths'] ?? ''))) ?: [] as $i => $path) {
            $path = trim($path);
            if ($path !== '') {
                $mounts['data-' . $i] = '/app/' . ltrim($path, '/');
            }
        }

        $environment = [];
        foreach (preg_split('/\r?\n/', trim((string) ($p['environment'] ?? ''))) ?: [] as $line) {
            if ($line === '') {
                continue;
            }
            if (!str_contains($line, '=')) {
                throw new RuntimeException('Environment variables must use NAME=value, one per line.');
            }
            [$name, $value] = explode('=', $line, 2);
            Validate::match($name, '/^[A-Z_][A-Z0-9_]*$/D', 'environment name');
            $environment[$name] = $value;
        }

        $spec = Validate::release([
            'artifact' => 'pending',
            'domain' => trim((string) ($p['domain'] ?? '')),
            'health' => $health,
            'config_version' => Trongate_config::CONFIG_VERSION,
            'php_version' => $php_version,
            'extensions' => $extensions,
            'database' => $database,
            'disk_quota_mb' => $disk_quota_mb,
            'mounts' => $mounts,
            'commit' => $commit,
        ]);

        $build = [
            'builder' => $builder,
            'plan' => Build::plan([
                'app' => $id,
                'repository' => $repository,
                'commit' => $commit,
                'php_version' => $spec['php_version'],
                'extensions' => $spec['extensions'],
                'commands' => (string) ($p['build_commands'] ?? ''),
            ]),
        ];

        $this->db->connection()->beginTransaction();
        try {
            // Unique records reject concurrent submissions instead of replacing another app.
            $this->records->_insert([
                'kind' => 'app',
                'id' => $id,
                'data' => [
                    'server' => $server,
                    'label' => $id,
                    'domain' => $spec['domain'],
                    'repository' => $repository,
                    'branch' => $branch,
                    'php_version' => $spec['php_version'],
                    'database' => $spec['database'],
                ],
            ]);

            foreach ($environment as $name => $value) {
                $ref = 'env-' . substr(hash('sha256', $id . ':' . $name), 0, 32);
                $this->secret($ref, $value);
                $spec['secrets'][$name] = $ref;
            }

            $spec = Validate::release($spec);

            $operation = $this->enqueue(
                'build',
                'builder:' . $build['builder'],
                $build + [
                    'launch' => [
                        'app' => $id,
                        'server' => $server,
                        'release' => $spec,
                        'metadata' => $metadata,
                    ],
                ]
            );

            $this->records->_insert([
                'kind' => 'launch',
                'id' => $request,
                'data' => ['operation' => $operation],
            ]);

            $this->db->connection()->commit();
            return $operation;
        } catch (Throwable $e) {
            $this->db->connection()->rollBack();
            $existing_launch = $this->records->_find(['kind' => 'launch', 'id' => $request]);
            if ($existing_launch !== null) {
                return $existing_launch['operation'];
            }
            if ($e instanceof PDOException) {
                throw new RuntimeException('This app name is already in use. Choose another name.');
            }
            throw $e;
        }
    }

    function _deploy_built(string $build, array $launch, array $result): string {
        $existing_deployment = $this->records->_find(['kind' => 'build-deployment', 'id' => $build]);
        if ($existing_deployment !== null && isset($existing_deployment['operation'])) {
            return $existing_deployment['operation'];
        }

        $updates = ['artifact' => (string) ($result['artifact'] ?? '')];
        if (!empty($result['release_path']) && is_string($result['release_path'])) {
            $updates['release_path'] = $result['release_path'];
        }
        $spec = Validate::release(array_replace($launch['release'], $updates));

        $this->db->connection()->beginTransaction();
        try {
            $this->records->_insert(['kind' => 'build-deployment', 'id' => $build, 'data' => []]);
            $release = $this->_release([
                'app' => $launch['app'],
                'release' => $spec,
                'metadata' => $launch['metadata'] ?? [],
            ]);
            $this->module('deployments');
            $operation = $this->deployments->model->_queue('deploy', [
                'app' => $launch['app'],
                'release' => $release,
            ]);
            $this->records->_put([
                'kind' => 'build-deployment',
                'id' => $build,
                'data' => ['operation' => $operation],
            ]);
            $this->db->connection()->commit();
            return $operation;
        } catch (Throwable $e) {
            $this->db->connection()->rollBack();
            $existing_deployment = $this->records->_find(['kind' => 'build-deployment', 'id' => $build]);
            if ($existing_deployment !== null && isset($existing_deployment['operation'])) {
                return $existing_deployment['operation'];
            }
            throw $e;
        }
    }

    function _create(array $p): string {
        $id = Validate::id((string) ($p['id'] ?? ''));
        $server = Validate::id((string) ($p['server'] ?? ''));
        $this->records->_get(['kind' => 'server', 'id' => $server]);
        foreach ($this->records->_all('app') as $existing) {
            if ($existing['id'] === $id) {
                throw new RuntimeException('Application exists');
            }
        }
        $this->records->_put(['kind' => 'app', 'id' => $id, 'data' => ['server' => $server]]);
        return $id;
    }

    function _release(array $p): string {
        $app = Validate::id((string) ($p['app'] ?? ''));
        $this->records->_get(['kind' => 'app', 'id' => $app]);
        $release = Validate::release($p['release']);
        $id = hash('sha256', self::_encode_json($release));
        $existing = $this->records->_find(['kind' => 'release', 'id' => $id]) ?? [];
        $this->records->_put([
            'kind' => 'release',
            'id' => $id,
            'data' => [
                'app' => $app,
                'spec' => $release,
                'created' => $existing['created'] ?? time(),
                'metadata' => $existing['metadata'] ?? ($p['metadata'] ?? []),
            ],
        ]);
        return $id;
    }

    private static function _encode_json(array $value): string {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
