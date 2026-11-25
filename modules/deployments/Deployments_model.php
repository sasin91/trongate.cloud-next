<?php
require_once APPPATH . 'modules/trongate_security/traits/Encrypted_credentials.php';
require_once APPPATH . 'modules/jobs/traits/Job_queue.php';

class Deployments_model extends Model {
    use Encrypted_credentials, Job_queue;

    function __construct(?string $module_name = null, PDO|Closure|null $connection = null) {
        parent::__construct($module_name, $connection);
        $this->module('records');
    }

    function _timeline(string $id): ?array {
        $job = $this->job($id);
        if (!in_array($job['kind'], ['build', 'deploy', 'rollback'], true)) {
            return null;
        }
        $build = $job['kind'] === 'build' ? $job : null;
        $deploy = $job['kind'] === 'build' ? null : $job;
        foreach ($this->records->_all('build-deployment') as $link) {
            if ($link['id'] === $id || ($link['operation'] ?? '') === $id) {
                $build = $this->job($link['id']);
                $deploy = $this->job($link['operation']);
                break;
            }
        }
        $current = $deploy ?? $build;
        $payload = json_decode($current['payload'], true);
        $app = $payload['app'] ?? $payload['launch']['app'] ?? null;
        $server_id = $payload['server'] ?? $payload['launch']['server'] ?? null;
        $server = $server_id ? ($this->records->_find(['kind' => 'server', 'id' => $server_id]) ?? []) : [];
        $result = json_decode($current['result'], true) ?: [];
        $phase = $result['phase'] ?? '';
        if ($deploy && $deploy['status'] === 'running') {
            $progress = $this->db->query_bind(
                "SELECT data FROM steps WHERE operation=? AND step='progress' ORDER BY at DESC LIMIT 1",
                [$deploy['id']],
                'statement'
            )->fetchColumn();
            if ($progress) {
                $phase = json_decode($progress, true)['phase'] ?? '';
            }
        }
        $status = $current['status'];
        $failed = in_array($status, ['failed', 'uncertain'], true);
        $error_lower = strtolower((string) ($result['error'] ?? ''));
        $public_failure = str_contains($error_lower, 'public health check failed');
        $local_failure = str_contains($error_lower, 'candidate health')
            || str_contains($error_lower, 'health check failed after traffic switch');
        $advanced = in_array(
            $phase,
            ['checking-local', 'switching', 'checking-public', 'retiring-previous', 'accepted', 'awaiting-go-live'],
            true
        );
        $local_passed = $public_failure
            || in_array($phase, ['switching', 'checking-public', 'retiring-previous', 'accepted', 'awaiting-go-live'], true)
            || ($status === 'succeeded' && $deploy);
        $stages = [
            [
                'name' => 'Build',
                'description' => 'Build immutable release and dependencies in an isolated FreeBSD jail.',
                'status' => $build ? $build['status'] : 'skipped',
            ],
            [
                'name' => 'Deploy',
                'description' => 'Stage the release on ZFS and start the standby jail slot.',
                'status' => !$deploy ? 'pending' : ($advanced || $status === 'succeeded' ? 'succeeded' : $status),
            ],
            [
                'name' => 'Check app',
                'description' => 'Verify PHP-FPM responds locally on the server.',
                'status' => $local_passed
                    ? 'succeeded'
                    : ($local_failure ? 'failed' : ($phase === 'checking-local' ? 'running' : 'pending')),
            ],
            [
                'name' => 'Go live',
                'description' => 'Switch Caddy route and verify HTTPS through your public domain.',
                'status' => $status === 'succeeded' && $deploy
                    ? 'succeeded'
                    : ($status === 'waiting'
                        ? 'waiting'
                        : ($public_failure
                            ? 'failed'
                            : (in_array($phase, ['switching', 'checking-public', 'retiring-previous'], true) ? 'running' : 'pending'))),
            ],
        ];
        $domain = $result['candidate']['domain'] ?? '';
        if ($domain === '' && $app) {
            $app_record = $this->records->_find(['kind' => 'app', 'id' => $app]);
            $domain = $app_record['domain'] ?? '';
        }
        $problem = null;
        if ($failed) {
            $problem = match (true) {
                !empty($result['reason']) && $build && !$deploy => [
                    'title' => 'The build needs attention.',
                    'suggestions' => [$result['reason']],
                ],
                $public_failure => [
                    'title' => 'Your app started, but its public address could not be verified.',
                    'suggestions' => [
                        'Point the A record for ' . $domain . ' to ' . ($server['host'] ?? 'your application server') . '.',
                        'Update or remove any old AAAA record. Both IPv4 and IPv6 must reach this server.',
                        'Allow ports 80 and 443. Wait for DNS propagation and HTTPS certificate issuance, then retry.',
                    ],
                ],
                $local_failure => [
                    'title' => 'The app did not respond in its standby jail.',
                    'suggestions' => [
                        'Check the app’s PHP configuration, optional extensions, and MariaDB connection.',
                        'Check whether the app redirects its home page or needs a different health path.',
                    ],
                ],
                $status === 'uncertain' => [
                    'title' => 'The operation needs verification.',
                    'suggestions' => [
                        'Check the operation diagnostics before retrying; a remote runtime step may still be running.',
                    ],
                ],
                default => [
                    'title' => $build && !$deploy ? 'The build could not finish.' : 'Deployment could not finish.',
                    'suggestions' => [
                        $build && !$deploy
                            ? 'Check your build commands, Trongate directory layout, and requested PHP extensions.'
                            : 'Check FreeBSD host readiness, ZFS datasets, and rctl capacity on the target server.',
                        'Copy the diagnostics for the exact operation and failure stage.',
                    ],
                ],
            };
            if ($public_failure && $domain !== '') {
                $addresses = @dns_get_record($domain, DNS_A | DNS_AAAA) ?: [];
                $problem['dns'] = array_values(array_filter(array_map(
                    static fn($r) => $r['ip'] ?? $r['ipv6'] ?? null,
                    $addresses
                )));
            }
        }
        $activity = [];
        $diagnostics = [];
        $diagnostic_keys = array_flip([
            'status',
            'phase',
            'error',
            'errno',
            'recovery',
            'restored',
            'artifact',
            'release_path',
            'commit',
            'deployment',
            'missing',
            'reason',
            'message',
        ]);
        foreach (array_filter([$build, $deploy]) as $item) {
            $data = json_decode($item['result'], true) ?: [];
            $diagnostics[] = [
                'operation' => $item['id'],
                'kind' => $item['kind'],
                'status' => $item['status'],
                'result' => array_intersect_key($data, $diagnostic_keys),
            ];
            $activity[] = ['at' => (int) floor($item['created'] / 1000000), 'text' => ucfirst($item['kind']) . ' queued.'];
            foreach ($this->db->query_bind('SELECT at,step,data FROM steps WHERE operation=? ORDER BY at,id', [$item['id']], 'statement')->fetchAll() as $step) {
                $detail = json_decode($step['data'], true) ?: [];
                $text = match ($step['step']) {
                    'claimed' => ucfirst($item['kind']) . ' started.',
                    'progress' => self::_phase_label($detail['phase'] ?? ''),
                    'observed' => ucfirst($item['kind']) . ': ' . ($detail['status'] ?? $item['status']) . '.',
                    default => null,
                };
                if ($text) {
                    $activity[] = ['at' => (int) $step['at'], 'text' => $text];
                }
            }
            foreach ($data['events'] ?? [] as $event) {
                $activity[] = ['at' => (int) $event['at'], 'text' => self::_phase_label($event['step'])];
            }
        }
        usort($activity, static fn($a, $b) => $a['at'] <=> $b['at']);
        return [
            'id' => ($build ?? $deploy)['id'],
            'app' => $app ?? 'Build verification',
            'domain' => $domain,
            'status' => $status,
            'go_live' => $deploy && $status === 'waiting'
                ? [
                    'operation' => $deploy['id'],
                    'domain' => $domain,
                    'records' => $this->_dns_records($server),
                    'message' => $result['message'] ?? 'Your app is ready. Connect its domain whenever you are ready to go live.',
                ]
                : null,
            'stages' => $stages,
            'activity' => $activity,
            'problem' => $problem,
            'diagnostics' => $diagnostics,
            'retry' => $deploy && $status === 'failed' && ($payload['release'] ?? '') !== ''
                ? ['app' => $app, 'release' => $payload['release']]
                : null,
        ];
    }

    function _dns_records(array $server): array {
        $records = [];
        if (filter_var($server['host'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $records[] = ['type' => 'A', 'value' => $server['host']];
        } elseif (filter_var($server['host'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $records[] = ['type' => 'AAAA', 'value' => $server['host']];
        }
        return $records;
    }

    function _go_live(string $id): string {
        $job = $this->job($id);
        if (!in_array($job['kind'], ['deploy', 'rollback'], true)) {
            throw new RuntimeException('Choose an application deployment.');
        }
        if ($job['status'] !== 'waiting') {
            return $id;
        }
        $payload = json_decode($job['payload'], true, 512, JSON_THROW_ON_ERROR);
        $result = json_decode($job['result'], true, 512, JSON_THROW_ON_ERROR);
        $domain = $result['candidate']['domain'] ?? '';
        Validate::match($domain, '/^[a-z0-9]+(?:[.-][a-z0-9]+)+$/D', 'domain');
        $expected = $this->_dns_records($this->records->_get(['kind' => 'server', 'id' => $payload['server']]));
        $observed = @dns_get_record($domain, DNS_A | DNS_AAAA) ?: [];
        $addresses = array_values(array_filter(array_map(
            static fn($record) => $record['ip'] ?? $record['ipv6'] ?? null,
            $observed
        )));
        if (!self::_dns_matches($addresses, array_column($expected, 'value'))) {
            throw new RuntimeException('DNS is not ready yet. Update the records below, remove old A or AAAA records, and try again after they propagate. Your deployment is saved.');
        }
        $payload['go_live'] = true;
        $this->db->query_bind(
            "UPDATE jobs SET payload=?,status='pending',lease=0 WHERE id=? AND status='waiting'",
            [self::_encode_json($payload), $id],
            'statement'
        );
        return $id;
    }

    static function _dns_matches(array $observed, array $expected): bool {
        $expected = array_filter(array_map(static fn($ip) => @inet_pton($ip), $expected));
        return $observed !== []
            && $expected !== []
            && count(array_filter($observed, static fn($ip) => in_array(@inet_pton($ip), $expected, true))) === count($observed);
    }

    static function _phase_label(string $phase): string {
        return match ($phase) {
            'allocating-resources' => 'Checking host capacity and setting rctl limits.',
            'preparing-database' => 'Provisioning shared MariaDB database and grants.',
            'preparing-sessions' => 'Configuring Valkey session ACL.',
            'staging-release' => 'Staging immutable release on ZFS and configuring jail.',
            'starting-standby' => 'Starting standby jail slot.',
            'checking-local' => 'Checking PHP-FPM health locally.',
            'switching' => 'Switching Caddy route atomically.',
            'checking-public' => 'Verifying public HTTPS access.',
            'retiring-previous' => 'Draining and stopping previous jail slot.',
            'accepted' => 'Your app is live.',
            'awaiting-go-live' => 'App ready; connect DNS to go live.',
            'destroying' => 'Removing jail slots, Caddy route, and session ACL.',
            'destroyed' => 'Application removed from server.',
            default => 'Deployment progress updated.',
        };
    }

    function _queue(string $command, array $p): string {
        if (!in_array($command, ['deploy', 'rollback'], true)) {
            throw new RuntimeException('Unknown deployment action');
        }
        $app = $this->records->_get(['kind' => 'app', 'id' => $p['app']]);
        if ($command === 'deploy' && $this->records->_get(['kind' => 'release', 'id' => $p['release']])['app'] !== $p['app']) {
            throw new RuntimeException('Release belongs to another application');
        }
        return $this->enqueue($command, 'server:' . $app['server'], $p + ['server' => $app['server']]);
    }

    function _queue_destroy(string $app, bool $purgeData = false): string {
        $app_id = Validate::id($app);
        $record = $this->records->_get(['kind' => 'app', 'id' => $app_id]);
        return $this->enqueue(
            'destroy-app',
            'server:' . $record['server'],
            [
                'app' => $app_id,
                'server' => $record['server'],
                'purge_data' => $purgeData,
            ]
        );
    }

    private static function _encode_json(array $value): string {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
