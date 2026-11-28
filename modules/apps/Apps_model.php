<?php
declare(strict_types=1);

require_once APPPATH . 'modules/security/traits/Encrypted_credentials.php';
require_once APPPATH . 'modules/jobs/traits/Job_queue.php';
require_once APPPATH . 'modules/validation/services/Validate.php';
require_once APPPATH . 'modules/apps/services/Build.php';
require_once APPPATH . 'modules/apps/services/Build_commands.php';
require_once APPPATH . 'modules/apps/services/Trongate_config.php';
require_once APPPATH . 'modules/harbour/services/Module_spec.php';

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

    static function apps_domain(): string {
        $domain = trim((string) (getenv('TC_APPS_DOMAIN') ?: 'trongate.dev'));
        return Validate::domain($domain);
    }

    function _plan_launch(array $p, string $request, ?array $source_data = null): string {
        $existing_launch = $this->records->_find(['kind' => 'launch', 'id' => $request]);
        if ($existing_launch !== null) {
            return $existing_launch['operation'];
        }

        $id = Validate::id(trim((string) ($p['label'] ?? '')));
        $requested_server = trim((string) ($p['server'] ?? ''));
        if ($requested_server !== '') {
            $server = Validate::id($requested_server);
            $server_record = $this->records->_get(['kind' => 'server', 'id' => $server]);
            if (empty($server_record['application_ready'])) {
                throw new RuntimeException('Selected hosting capacity is not ready for applications.');
            }
        } else {
            $server_record = null;
            foreach ($this->records->_all('server') as $candidate) {
                if (!empty($candidate['application_ready']) && !empty($candidate['local_builder_ready'])) {
                    $server_record = $candidate;
                    break;
                }
            }
            if ($server_record === null) {
                throw new RuntimeException('Application hosting is not ready yet. Try again shortly.');
            }
            $server = Validate::id((string) $server_record['id']);
        }

        require_once APPPATH . 'modules/apps/Apps.php';
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

        $database = true;

        $raw_quota = trim((string) ($p['disk_quota_mb'] ?? ''));
        $disk_quota_mb = $raw_quota === '' ? 128 : Validate::number($raw_quota, 128, 1048576);

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

        $domain = trim((string) ($p['domain'] ?? ''));
        if ($domain === '') {
            $domain = $id . '.' . self::apps_domain();
        }

        $spec = Validate::release([
            'artifact' => 'pending',
            'domain' => $domain,
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
            $auto_deploy = !empty($p['auto_deploy']) && (string) $p['auto_deploy'] !== '0';
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
                    'auto_deploy' => $auto_deploy,
                    'php_version' => $spec['php_version'],
                    'database' => $spec['database'],
                    'build_commands' => (string) ($p['build_commands'] ?? ''),
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
        if (isset($result['disk_quota_mb']) && is_int($result['disk_quota_mb']) && $result['disk_quota_mb'] >= 128) {
            $planned_quota = (int) ($launch['release']['disk_quota_mb'] ?? 128);
            $updates['disk_quota_mb'] = max($planned_quota, min(1048576, $result['disk_quota_mb']));
        }
        if (!empty($result['persistent_paths']) && is_array($result['persistent_paths'])) {
            $mounts = is_array($launch['release']['mounts'] ?? null) ? $launch['release']['mounts'] : [];
            $existing_dests = array_values($mounts);
            foreach ($result['persistent_paths'] as $path) {
                if (!is_string($path)) {
                    continue;
                }
                $clean = trim($path, '/');
                if ($clean === '') {
                    continue;
                }
                $dest = '/app/' . $clean;
                if (in_array($dest, $existing_dests, true)) {
                    continue;
                }
                $volume = 'upload-' . substr(hash('sha256', $dest), 0, 12);
                $mounts[$volume] = $dest;
                $existing_dests[] = $dest;
            }
            $updates['mounts'] = $mounts;
        }
        $spec = Validate::release(array_replace($launch['release'], $updates));
        $metadata = is_array($launch['metadata'] ?? null) ? $launch['metadata'] : [];
        $metadata['modules'] = Module_spec::fingerprints($result['modules'] ?? []);

        $this->db->connection()->beginTransaction();
        try {
            $this->records->_insert(['kind' => 'build-deployment', 'id' => $build, 'data' => []]);
            $release = $this->_release([
                'app' => $launch['app'],
                'release' => $spec,
                'metadata' => $metadata,
            ]);
            $this->module('deployments');
            $deploy_payload = [
                'app' => $launch['app'],
                'release' => $release,
            ];
            if (!empty($launch['trigger']) && is_string($launch['trigger'])) {
                $deploy_payload['trigger'] = $launch['trigger'];
            }
            if (!empty($launch['canary'])) {
                $deploy_payload['canary'] = true;
                $deploy_payload['canary_weight'] = max(0, min(90, (int) ($launch['canary_weight'] ?? 10)));
            }
            $operation = $this->deployments->model->_queue('deploy', $deploy_payload);
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

    function _app_summaries(): array {
        $apps = $this->records->_all('app');
        $releases = $this->records->_all('release');
        $jobs = $this->db->query_bind(
            "SELECT id,kind,status,payload,result,created FROM jobs WHERE kind IN ('build','deploy','rollback','canary-weight','canary-promote','canary-abort') ORDER BY created DESC LIMIT 200",
            [],
            'statement'
        )->fetchAll();

        $summaries = [];
        foreach ($apps as $app) {
            $id = (string) $app['id'];
            $app_releases = array_values(array_filter($releases, static fn($r) => ($r['app'] ?? '') === $id));
            usort($app_releases, static fn($a, $b) => ($b['created'] ?? 0) <=> ($a['created'] ?? 0));
            $latest_release = $app_releases[0] ?? null;

            $app_jobs = [];
            foreach ($jobs as $job) {
                $payload = json_decode((string) ($job['payload'] ?? ''), true) ?: [];
                $target = $payload['app'] ?? $payload['launch']['app'] ?? $payload['plan']['app'] ?? null;
                if ($target === $id) {
                    $app_jobs[] = ['job' => $job, 'payload' => $payload];
                }
            }

            $summaries[$id] = self::_derive_live_state($app, $latest_release, $app_jobs);
        }
        return $summaries;
    }

    function _overview(string $id, int $deploy_limit = 20): array {
        $app_id = Validate::id($id);
        $app = $this->records->_find(['kind' => 'app', 'id' => $app_id]);
        if ($app === null) {
            throw new RuntimeException('Application not found.');
        }
        $app = ['id' => $app_id] + $app;

        $releases = array_values(array_filter(
            $this->records->_all('release'),
            static fn($r) => ($r['app'] ?? '') === $app_id
        ));
        usort($releases, static fn($a, $b) => ($b['created'] ?? 0) <=> ($a['created'] ?? 0));
        $releases_by_id = [];
        foreach ($releases as $rel) {
            $releases_by_id[(string) $rel['id']] = $rel;
        }

        $all_jobs = $this->db->query_bind(
            "SELECT id,kind,status,payload,result,created FROM jobs WHERE kind IN ('build','deploy','rollback','canary-weight','canary-promote','canary-abort') ORDER BY created DESC LIMIT 100",
            [],
            'statement'
        )->fetchAll();

        $raw_app_jobs = [];
        $deployments = [];
        $current = null;
        $live_op = null;
        $succeeded_deploys = 0;

        foreach ($all_jobs as $job) {
            $payload = json_decode((string) ($job['payload'] ?? ''), true) ?: [];
            $target = $payload['app'] ?? $payload['launch']['app'] ?? $payload['plan']['app'] ?? null;
            if ($target !== $app_id) {
                continue;
            }
            $raw_app_jobs[] = ['job' => $job, 'payload' => $payload];
            $result = json_decode((string) ($job['result'] ?? ''), true) ?: [];
            $release_id = (string) ($payload['release'] ?? '');
            $rel = $release_id !== '' ? ($releases_by_id[$release_id] ?? null) : null;
            $commit = (string) (
                $result['candidate']['commit']
                ?? $result['canary']['commit']
                ?? $rel['metadata']['commit']
                ?? $rel['spec']['commit']
                ?? $payload['plan']['commit']
                ?? $payload['launch']['release']['commit']
                ?? ''
            );
            $slot = (string) (
                $result['candidate']['slot']
                ?? $result['canary']['slot']
                ?? ''
            );
            $source_trigger = (string) ($payload['trigger'] ?? $payload['launch']['trigger'] ?? '');
            $trigger = match ($job['kind']) {
                'deploy' => !empty($payload['canary'])
                    ? 'canary ' . (int) ($payload['canary_weight'] ?? 10) . '%'
                    : ($source_trigger === 'webhook' ? 'push' : 'deploy'),
                'build' => $source_trigger === 'webhook' ? 'push' : 'build',
                'canary-promote' => 'promote',
                'canary-abort' => 'abort canary',
                'canary-weight' => 'split ' . (int) ($payload['weight'] ?? 0) . '%',
                default => (string) $job['kind'],
            };
            $started_at = (int) floor(((int) $job['created']) / 1000000);
            $events = is_array($result['events'] ?? null) ? $result['events'] : [];
            $last_event = $events !== [] ? end($events) : null;
            $completed_at = is_array($last_event) && isset($last_event['at']) ? (int) $last_event['at'] : $started_at;
            $is_live_kind = in_array($job['kind'], ['deploy', 'rollback', 'canary-promote'], true) && empty($payload['canary']);
            if ($is_live_kind && $job['status'] === 'succeeded') {
                $succeeded_deploys++;
                if ($live_op === null) {
                    $live_op = (string) $job['id'];
                    $current = [
                        'id' => (string) $job['id'],
                        'commit' => $commit,
                        'slot' => $slot,
                        'trigger' => $trigger,
                        'release_id' => $release_id,
                        'started_at' => $started_at,
                        'completed_at' => $completed_at,
                    ];
                }
            }

            $deployments[] = [
                'id' => (string) $job['id'],
                'kind' => (string) $job['kind'],
                'status' => (string) $job['status'],
                'commit' => $commit,
                'slot' => $slot,
                'trigger' => $trigger,
                'release_id' => $release_id,
                'is_live' => $live_op !== null && $live_op === (string) $job['id'],
                'started_at' => $started_at,
                'completed_at' => $completed_at,
                'is_canary' => !empty($payload['canary']),
            ];
        }

        $all_services = $this->records->_all('service');
        $app_server = (string) ($app['server'] ?? '');
        $services = array_values(array_filter(
            $all_services,
            static fn($svc) => ($svc['app'] ?? '') === $app_id
        ));
        usort($services, static fn($a, $b) => strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));

        $shared_services = array_values(array_filter(
            $all_services,
            static fn($svc) => ($svc['app'] ?? '') === '' && ($svc['server'] ?? '') === $app_server
        ));
        usort($shared_services, static fn($a, $b) => strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));

        $latest_release = $releases[0] ?? null;
        $live_state = self::_derive_live_state($app, $latest_release, $raw_app_jobs);
        $canary = is_array($app['canary'] ?? null) ? $app['canary'] : null;

        $secret_refs = $this->_effective_secret_refs($app, $latest_release);
        $env_names = array_keys($secret_refs);
        sort($env_names);

        $extensions = is_array($app['extensions'] ?? null)
            ? $app['extensions']
            : (is_array($latest_release['spec']['extensions'] ?? null) ? $latest_release['spec']['extensions'] : []);
        $health = (string) ($app['health'] ?? $latest_release['spec']['health'] ?? '/');
        $build_commands = (string) ($app['build_commands'] ?? '');
        if ($build_commands === '') {
            $build_commands = $this->_last_build_commands($app_id);
        }

        return [
            'app' => $app,
            'releases' => $releases,
            'latest_release' => $latest_release,
            'deployments' => array_slice($deployments, 0, max(1, $deploy_limit)),
            'current' => $current,
            'live_state' => $live_state,
            'canary' => $canary,
            'services' => $services,
            'shared_services' => $shared_services,
            'can_rollback' => $canary === null && ($succeeded_deploys >= 2 || count($releases) >= 2),
            'database_name' => 'tc_' . str_replace('-', '_', $app_id),
            'settings' => [
                'branch' => (string) ($app['branch'] ?? 'main'),
                'auto_deploy' => !empty($app['auto_deploy']),
                'health' => $health !== '' ? $health : '/',
                'extensions' => $extensions,
                'build_commands' => $build_commands,
                'migration_command' => (string) ($app['migration_command'] ?? ''),
                'env_names' => $env_names,
            ],
        ];
    }

    function _save_settings(string $id, array $input): void {
        $app_id = Validate::id($id);
        $app = $this->records->_get(['kind' => 'app', 'id' => $app_id]);

        $branch = trim((string) ($input['branch'] ?? ($app['branch'] ?? 'main')));
        if ($branch === '' || strlen($branch) > 200 || preg_match('/\s/', $branch)) {
            throw new RuntimeException('Branch name must be a non-empty branch or commit with no spaces.');
        }

        $health = trim((string) ($input['health'] ?? '/'));
        if ($health === '') {
            $health = '/';
        }
        Validate::match($health, '~^/[a-zA-Z0-9/_.-]*$~D', 'health check path');

        $build_commands = trim((string) ($input['build_commands'] ?? ''));
        $cmd_error = Build_commands::error($build_commands);
        if ($cmd_error !== null) {
            throw new RuntimeException($cmd_error);
        }

        $migration_command = trim((string) ($input['migration_command'] ?? ''));
        if ($migration_command !== '') {
            if (strlen($migration_command) > 500 || preg_match('/[\r\n\0;|&`$<>]/', $migration_command)) {
                throw new RuntimeException('Migration command must be a single command (for example: php /app/migrate.php) without shell operators.');
            }
            $parts = preg_split('/\s+/', $migration_command, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (count($parts) > 30) {
                throw new RuntimeException('Migration command has too many arguments.');
            }
        }

        $raw_ext = (string) ($input['php_extensions'] ?? '');
        require_once APPPATH . 'modules/apps/Apps.php';
        $ext_error = Apps::_validate_extensions($raw_ext);
        if ($ext_error !== null) {
            throw new RuntimeException($ext_error);
        }
        $extensions = [];
        foreach (preg_split('/[\s,]+/', trim($raw_ext), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ext) {
            $name = strtolower($ext);
            if (!in_array($name, Build_commands::BASE_EXTENSIONS, true)) {
                $extensions[] = $name;
            }
        }
        $extensions = array_values(array_unique($extensions));

        unset($app['id']);
        $app['branch'] = $branch;
        if (array_key_exists('auto_deploy', $input)) {
            $app['auto_deploy'] = !empty($input['auto_deploy']) && (string) $input['auto_deploy'] !== '0';
        }
        $app['health'] = $health;
        $app['build_commands'] = $build_commands;
        $app['migration_command'] = $migration_command;
        $app['extensions'] = $extensions;
        $this->records->_put(['kind' => 'app', 'id' => $app_id, 'data' => $app]);
    }

    function _save_env(string $id, string $raw): int {
        $app_id = Validate::id($id);
        $app = $this->records->_get(['kind' => 'app', 'id' => $app_id]);
        require_once APPPATH . 'modules/apps/Apps.php';
        $err = Apps::_validate_environment($raw);
        if ($err !== null) {
            throw new RuntimeException($err);
        }

        $releases = array_values(array_filter(
            $this->records->_all('release'),
            static fn($r) => ($r['app'] ?? '') === $app_id
        ));
        usort($releases, static fn($a, $b) => ($b['created'] ?? 0) <=> ($a['created'] ?? 0));
        $secrets = $this->_effective_secret_refs($app, $releases[0] ?? null);

        $updated = 0;
        foreach (preg_split('/\r?\n/', trim($raw)) ?: [] as $line) {
            if ($line === '') {
                continue;
            }
            [$name, $value] = explode('=', $line, 2);
            Validate::match($name, '/^[A-Z_][A-Z0-9_]*$/D', 'environment name');
            $ref = 'env-' . substr(hash('sha256', $app_id . ':' . $name), 0, 32);
            $this->secret($ref, $value);
            $secrets[$name] = $ref;
            $updated++;
        }

        if ($updated === 0) {
            throw new RuntimeException('Enter at least one NAME=value line to save.');
        }

        unset($app['id']);
        $app['secrets'] = $secrets;
        $this->records->_put(['kind' => 'app', 'id' => $app_id, 'data' => $app]);
        return $updated;
    }

    function _delete_env(string $id, string $name): void {
        $app_id = Validate::id($id);
        Validate::match($name, '/^[A-Z_][A-Z0-9_]*$/D', 'environment name');
        $app = $this->records->_get(['kind' => 'app', 'id' => $app_id]);

        $releases = array_values(array_filter(
            $this->records->_all('release'),
            static fn($r) => ($r['app'] ?? '') === $app_id
        ));
        usort($releases, static fn($a, $b) => ($b['created'] ?? 0) <=> ($a['created'] ?? 0));
        $secrets = $this->_effective_secret_refs($app, $releases[0] ?? null);
        unset($secrets[$name]);

        unset($app['id']);
        $app['secrets'] = $secrets;
        $this->records->_put(['kind' => 'app', 'id' => $app_id, 'data' => $app]);
    }

    private function _effective_secret_refs(array $app, ?array $latest_release): array {
        if (array_key_exists('secrets', $app) && is_array($app['secrets'])) {
            return $app['secrets'];
        }
        return is_array($latest_release['spec']['secrets'] ?? null) ? $latest_release['spec']['secrets'] : [];
    }

    private function _last_build_commands(string $app_id): string {
        $prev_builds = $this->db->query_bind(
            "SELECT payload FROM jobs WHERE kind='build' ORDER BY created DESC LIMIT 40",
            [],
            'statement'
        )->fetchAll();
        foreach ($prev_builds as $row) {
            $p = json_decode((string) ($row['payload'] ?? ''), true) ?: [];
            if (($p['launch']['app'] ?? $p['plan']['app'] ?? '') === $app_id && !empty($p['plan']['commands'])) {
                return is_array($p['plan']['commands'])
                    ? implode("\n", $p['plan']['commands'])
                    : (string) $p['plan']['commands'];
            }
        }
        return '';
    }

    function _queue_redeploy_latest(
        string $id,
        array $source_data,
        bool $canary = false,
        int $canary_weight = 10,
        string $trigger = 'manual',
    ): string {
        $app_id = Validate::id($id);
        $app = $this->records->_get(['kind' => 'app', 'id' => $app_id]);
        if (!empty($app['canary'])) {
            throw new RuntimeException('Promote or abort the active canary before starting another deployment.');
        }

        $commit = Validate::match((string) ($source_data['commit'] ?? ''), '/^[a-f0-9]{40}$/D', 'source commit');
        if ($trigger === 'webhook') {
            $active_builds = $this->db->query_bind(
                "SELECT id, payload FROM jobs WHERE kind='build' AND status IN ('pending','running') ORDER BY created DESC LIMIT 20",
                [],
                'statement'
            )->fetchAll();
            foreach ($active_builds as $active) {
                $ap = json_decode((string) ($active['payload'] ?? ''), true) ?: [];
                $target_app = (string) ($ap['launch']['app'] ?? $ap['plan']['app'] ?? '');
                $target_commit = (string) ($ap['plan']['commit'] ?? '');
                if ($target_app === $app_id && $target_commit === $commit) {
                    return (string) $active['id'];
                }
            }
        }
        $releases = array_values(array_filter(
            $this->records->_all('release'),
            static fn($r) => ($r['app'] ?? '') === $app_id
        ));
        usort($releases, static fn($a, $b) => ($b['created'] ?? 0) <=> ($a['created'] ?? 0));

        $server = Validate::id((string) ($app['server'] ?? ''));
        $target = $this->_build_target($server, $app_id);
        $base_spec = is_array($releases[0]['spec'] ?? null) ? $releases[0]['spec'] : [];
        require_once APPPATH . 'modules/apps/Apps.php';
        $repository = Apps::_repository((string) ($app['repository'] ?? ($source_data['repository'] ?? '')));

        $commands = array_key_exists('build_commands', $app)
            ? (string) $app['build_commands']
            : $this->_last_build_commands($app_id);
        $effective_extensions = is_array($app['extensions'] ?? null)
            ? $app['extensions']
            : (is_array($base_spec['extensions'] ?? null) ? $base_spec['extensions'] : []);
        $effective_health = (string) ($app['health'] ?? $base_spec['health'] ?? '/');
        $effective_secrets = $this->_effective_secret_refs($app, $releases[0] ?? null);
        $migration_raw = trim((string) ($app['migration_command'] ?? ''));
        $effective_migration = $migration_raw !== ''
            ? (preg_split('/\s+/', $migration_raw, -1, PREG_SPLIT_NO_EMPTY) ?: [])
            : [];

        // Reuse an already-built immutable release if commit, extensions, health, secrets, and migration all match.
        foreach ($releases as $existing) {
            $rel_spec = is_array($existing['spec'] ?? null) ? $existing['spec'] : [];
            $rel_commit = (string) ($rel_spec['commit'] ?? $existing['metadata']['commit'] ?? '');
            $artifact = (string) ($rel_spec['artifact'] ?? '');
            $same_ext = ($rel_spec['extensions'] ?? []) === $effective_extensions;
            $same_health = ($rel_spec['health'] ?? '/') === $effective_health;
            $same_secrets = ($rel_spec['secrets'] ?? []) === $effective_secrets;
            $same_migration = ($rel_spec['migration'] ?? []) === $effective_migration;
            if ($rel_commit === $commit && $artifact !== '' && $artifact !== 'pending' && $same_ext && $same_health && $same_secrets && $same_migration) {
                $this->module('deployments');
                $payload = ['app' => $app_id, 'release' => (string) $existing['id']];
                if ($trigger !== 'manual') {
                    $payload['trigger'] = $trigger;
                }
                if ($canary) {
                    $payload['canary'] = true;
                    $payload['canary_weight'] = max(0, min(90, $canary_weight));
                }
                return $this->deployments->model->_queue('deploy', $payload);
            }
        }

        $spec_input = [
            'artifact' => 'pending',
            'domain' => (string) ($base_spec['domain'] ?? $app['domain'] ?? ($app_id . '.' . self::apps_domain())),
            'health' => $effective_health,
            'config_version' => (string) ($base_spec['config_version'] ?? Trongate_config::CONFIG_VERSION),
            'php_version' => (string) ($base_spec['php_version'] ?? $app['php_version'] ?? '8.4'),
            'extensions' => $effective_extensions,
            'database' => (bool) ($base_spec['database'] ?? $app['database'] ?? true),
            'disk_quota_mb' => (int) ($base_spec['disk_quota_mb'] ?? 128),
            'mounts' => is_array($base_spec['mounts'] ?? null) ? $base_spec['mounts'] : [],
            'secrets' => $effective_secrets,
            'file_secrets' => is_array($base_spec['file_secrets'] ?? null) ? $base_spec['file_secrets'] : [],
            'commit' => $commit,
        ];
        if ($effective_migration !== []) {
            $spec_input['migration'] = $effective_migration;
            $spec_input['schema_compatible'] = true;
        }
        $spec = Validate::release($spec_input);

        $plan = Build::plan([
            'app' => $app_id,
            'repository' => $repository,
            'commit' => $commit,
            'php_version' => $spec['php_version'],
            'extensions' => $spec['extensions'],
            'commands' => $commands,
        ]);

        $launch = [
            'app' => $app_id,
            'server' => $server,
            'release' => $spec,
            'metadata' => $source_data,
        ];
        if ($trigger !== 'manual') {
            $launch['trigger'] = $trigger;
        }
        if ($canary) {
            $launch['canary'] = true;
            $launch['canary_weight'] = max(0, min(90, $canary_weight));
        }

        $build_payload = [
            'builder' => $target['builder'],
            'plan' => $plan,
            'launch' => $launch,
        ];
        if ($trigger !== 'manual') {
            $build_payload['trigger'] = $trigger;
        }

        return $this->enqueue(
            'build',
            'builder:' . $target['builder'],
            $build_payload
        );
    }

    private static function _derive_live_state(array $app, ?array $latest_release, array $app_jobs): array {
        $canary = is_array($app['canary'] ?? null) ? $app['canary'] : null;
        $latest_job = $app_jobs[0]['job'] ?? null;
        $commit = (string) ($latest_release['metadata']['commit'] ?? $latest_release['spec']['commit'] ?? '');

        if ($latest_job && in_array($latest_job['status'], ['pending', 'running'], true)) {
            $label = $latest_job['kind'] === 'build' ? 'Building' : 'Deploying';
            return [
                'state' => 'deploying',
                'label' => $label,
                'detail' => ucfirst((string) $latest_job['kind']) . ' in progress',
                'commit' => $commit,
                'last_deploy_at' => (int) floor(((int) $latest_job['created']) / 1000000),
            ];
        }

        if ($canary !== null) {
            $w = (int) ($canary['weight'] ?? 0);
            return [
                'state' => 'canary',
                'label' => $w === 0 ? 'Canary preview' : 'Canary ' . $w . '%',
                'detail' => 'Serving alongside the stable release',
                'commit' => (string) ($canary['commit'] ?: $commit),
                'last_deploy_at' => (int) ($canary['started_at'] ?? 0),
            ];
        }

        foreach ($app_jobs as $entry) {
            $j = $entry['job'];
            if (in_array($j['kind'], ['deploy', 'rollback', 'canary-promote'], true) && $j['status'] === 'succeeded') {
                $res = json_decode((string) ($j['result'] ?? ''), true) ?: [];
                return [
                    'state' => 'running',
                    'label' => 'Running',
                    'detail' => '',
                    'commit' => (string) ($res['candidate']['commit'] ?? $commit),
                    'last_deploy_at' => (int) floor(((int) $j['created']) / 1000000),
                ];
            }
        }

        if ($latest_job && in_array($latest_job['status'], ['failed', 'uncertain'], true)) {
            return [
                'state' => 'failed',
                'label' => 'Deploy failed',
                'detail' => 'Last deployment did not finish',
                'commit' => $commit,
                'last_deploy_at' => (int) floor(((int) $latest_job['created']) / 1000000),
            ];
        }

        return [
            'state' => 'not_live',
            'label' => 'Not live yet',
            'detail' => '',
            'commit' => $commit,
            'last_deploy_at' => 0,
        ];
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
