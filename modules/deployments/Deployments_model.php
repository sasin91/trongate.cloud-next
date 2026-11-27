<?php
require_once APPPATH . 'modules/security/traits/Encrypted_credentials.php';
require_once APPPATH . 'modules/jobs/traits/Job_queue.php';
require_once APPPATH . 'modules/validation/services/Validate.php';
require_once APPPATH . 'modules/services/services/Service_spec.php';

class Deployments_model extends Model {
    use Encrypted_credentials, Job_queue;

    function __construct(?string $module_name = null, PDO|Closure|null $connection = null) {
        parent::__construct($module_name, $connection);
        $this->module('records');
    }

    function _timeline(string $id): ?array {
        $job = $this->job($id);
        if (in_array($job['kind'], ['deploy-service', 'destroy-service'], true)) {
            return $this->_service_timeline($job);
        }
        if ($job['kind'] === 'destroy-app') {
            return $this->_destroy_app_timeline($job);
        }
        if (!in_array($job['kind'], ['build', 'deploy', 'rollback', 'canary-weight', 'canary-promote', 'canary-abort'], true)) {
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
            ['checking-local', 'routing-canary', 'switching', 'checking-public', 'retiring-previous', 'retiring-canary', 'accepted', 'canary-active', 'canary-updated', 'canary-aborted', 'awaiting-go-live'],
            true
        );
        $local_passed = $public_failure
            || in_array($phase, ['routing-canary', 'switching', 'checking-public', 'retiring-previous', 'retiring-canary', 'accepted', 'canary-active', 'canary-updated', 'canary-aborted', 'awaiting-go-live'], true)
            || ($status === 'succeeded' && $deploy);
        $is_canary = !empty($payload['canary']) || str_starts_with((string) $current['kind'], 'canary-');
        $stages = [
            [
                'name' => 'Build',
                'description' => 'Build immutable release and dependencies.',
                'status' => $build ? $build['status'] : 'skipped',
            ],
            [
                'name' => 'Deploy',
                'description' => $is_canary
                    ? 'Start the canary release alongside the stable version.'
                    : 'Stage the release and start the new app instance.',
                'status' => !$deploy ? 'pending' : ($advanced || $status === 'succeeded' ? 'succeeded' : $status),
            ],
            [
                'name' => 'Check app',
                'description' => 'Verify your app responds to its health check.',
                'status' => $local_passed
                    ? 'succeeded'
                    : ($local_failure ? 'failed' : ($phase === 'checking-local' ? 'running' : 'pending')),
            ],
            [
                'name' => $current['kind'] === 'canary-abort'
                    ? 'Restore stable'
                    : ($is_canary && $current['kind'] !== 'canary-promote' ? 'Route canary' : 'Go live'),
                'description' => $current['kind'] === 'canary-abort'
                    ? 'Return 100% of traffic to the stable release and stop the canary.'
                    : ($is_canary && $current['kind'] !== 'canary-promote'
                        ? 'Route canary traffic while keeping the stable release online.'
                        : 'Switch traffic and verify HTTPS through your public domain.'),
                'status' => $status === 'succeeded' && $deploy
                    ? 'succeeded'
                    : ($status === 'waiting'
                        ? 'waiting'
                        : ($public_failure
                            ? 'failed'
                            : (in_array($phase, ['routing-canary', 'switching', 'checking-public', 'retiring-previous', 'retiring-canary'], true) ? 'running' : 'pending'))),
            ],
        ];
        $domain = $result['candidate']['domain'] ?? '';
        $app_record = $app ? $this->records->_find(['kind' => 'app', 'id' => $app]) : null;
        if ($domain === '' && $app_record) {
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
                        'Point the A record for ' . $domain . ' to ' . ($server['host'] ?? 'the provided IP address') . '.',
                        'Update or remove any old AAAA record. Both IPv4 and IPv6 must point to the same address.',
                        'Wait for DNS propagation and HTTPS certificate issuance, then retry.',
                    ],
                ],
                $local_failure => [
                    'title' => 'The app did not respond to its health check.',
                    'suggestions' => [
                        'Check the app’s PHP configuration, optional extensions, and MariaDB connection.',
                        'Check whether the app redirects its home page or needs a different health path.',
                    ],
                ],
                $status === 'uncertain' => [
                    'title' => 'The operation needs verification.',
                    'suggestions' => [
                        'Check the operation diagnostics before retrying; a deployment step may still be running.',
                    ],
                ],
                default => [
                    'title' => $build && !$deploy ? 'The build could not finish.' : 'Deployment could not finish.',
                    'suggestions' => [
                        $build && !$deploy
                            ? 'Check your build commands, Trongate directory layout, and requested PHP extensions.'
                            : 'Check the diagnostics and retry the deployment.',
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
        $stage_logs = [0 => [], 1 => [], 2 => [], 3 => []];
        $fmt_log = static fn(?int $ts, string $line): string => ($ts && $ts > 0 ? '[' . gmdate('H:i:s', $ts) . '] ' : '') . $line;
        $phase_stage = static fn(string $p): int => match ($p) {
            'checking-local' => 2,
            'routing-canary', 'switching', 'checking-public', 'retiring-previous', 'retiring-canary', 'accepted', 'canary-active', 'canary-updated', 'canary-aborted', 'awaiting-go-live' => 3,
            default => 1,
        };
        $diagnostic_keys = array_flip([
            'status',
            'phase',
            'error',
            'reason',
            'message',
            'missing',
            'restored',
        ]);
        foreach (array_filter([$build, $deploy]) as $item) {
            $data = json_decode($item['result'], true) ?: [];
            $item_payload = json_decode((string) ($item['payload'] ?? ''), true) ?: [];
            $is_build_item = $item['kind'] === 'build';
            $created_ts = (int) floor($item['created'] / 1000000);
            $diagnostics[] = [
                'operation' => $item['id'],
                'kind' => $item['kind'],
                'status' => $item['status'],
                'result' => array_intersect_key($data, $diagnostic_keys),
            ];
            $queued_text = ucfirst($item['kind']) . ' queued.';
            $activity[] = ['at' => $created_ts, 'text' => $queued_text];
            $stage_logs[$is_build_item ? 0 : 1][] = $fmt_log($created_ts, $queued_text . ' (op ' . substr((string) $item['id'], 0, 8) . ')');

            if ($is_build_item) {
                $plan = is_array($item_payload['plan'] ?? null) ? $item_payload['plan'] : [];
                if (!empty($plan['repository'])) {
                    $commit_short = !empty($plan['commit']) ? substr((string) $plan['commit'], 0, 7) : '';
                    $stage_logs[0][] = $fmt_log($created_ts, 'source: ' . $plan['repository'] . ($commit_short !== '' ? ' @ ' . $commit_short : ''));
                }
                if (!empty($plan['commands'])) {
                    $cmds = is_array($plan['commands']) ? implode(' && ', $plan['commands']) : str_replace(["\r\n", "\n"], ' && ', trim((string) $plan['commands']));
                    if ($cmds !== '') {
                        $stage_logs[0][] = $fmt_log($created_ts, 'commands: ' . $cmds);
                    }
                }
            }

            $seen_events = [];
            foreach ($this->db->query_bind('SELECT at,step,data FROM steps WHERE operation=? ORDER BY at,id', [$item['id']], 'statement')->fetchAll() as $step) {
                $detail = json_decode($step['data'], true) ?: [];
                $step_ts = (int) $step['at'];
                $text = match ($step['step']) {
                    'claimed' => ucfirst($item['kind']) . ' started.',
                    'progress' => self::_phase_label($detail['phase'] ?? ''),
                    'observed' => ucfirst($item['kind']) . ': ' . ($detail['status'] ?? $item['status']) . '.',
                    default => null,
                };
                if ($text) {
                    $activity[] = ['at' => $step_ts, 'text' => $text];
                    if ($is_build_item) {
                        $stage_logs[0][] = $fmt_log($step_ts, $text);
                    } elseif ($step['step'] === 'progress' && !empty($detail['phase'])) {
                        $ph = (string) $detail['phase'];
                        $seen_events[$ph] = true;
                        $stage_logs[$phase_stage($ph)][] = $fmt_log($step_ts, $ph . ' — ' . $text);
                    } elseif ($step['step'] === 'claimed') {
                        $stage_logs[1][] = $fmt_log($step_ts, $text);
                    }
                }
            }
            foreach ($data['events'] ?? [] as $event) {
                $ev_step = (string) ($event['step'] ?? '');
                $ev_ts = (int) ($event['at'] ?? 0);
                $label = self::_phase_label($ev_step);
                $activity[] = ['at' => $ev_ts, 'text' => $label];
                if ($is_build_item) {
                    $stage_logs[0][] = $fmt_log($ev_ts, $ev_step . ' — ' . $label);
                } elseif (!isset($seen_events[$ev_step])) {
                    $stage_logs[$phase_stage($ev_step)][] = $fmt_log($ev_ts, $ev_step . ' — ' . $label);
                }
            }

            if ($is_build_item) {
                if (!empty($data['release_path'])) {
                    $stage_logs[0][] = 'release: ' . (string) $data['release_path'];
                }
                if (!empty($data['artifact'])) {
                    $stage_logs[0][] = 'digest: ' . (string) $data['artifact'];
                }
                if (!empty($data['reason']) || !empty($data['error'])) {
                    $stage_logs[0][] = 'ERROR: ' . (string) ($data['reason'] ?? $data['error']);
                }
            } else {
                $cand = is_array($data['candidate'] ?? null) ? $data['candidate'] : null;
                if ($cand !== null) {
                    if (!empty($cand['php_version'])) {
                        $stage_logs[1][] = 'php: ' . $cand['php_version'];
                    }
                    if (!empty($cand['database_name'])) {
                        $stage_logs[1][] = 'database: ' . (string) $cand['database_name'];
                    }
                    if (!empty($cand['health'])) {
                        $stage_logs[2][] = 'health check: GET ' . $cand['health'];
                    }
                }
                if (!empty($data['canary']) && is_array($data['canary'])) {
                    $cw = (int) ($data['canary']['weight'] ?? ($data['weight'] ?? 0));
                    $stage_logs[3][] = 'canary: ' . ($cw === 0 ? 'preview only (0%)' : $cw . '% traffic') . ($domain !== '' ? ' on https://' . $domain : '');
                } elseif ($domain !== '' && ($item['status'] === 'succeeded')) {
                    $stage_logs[3][] = 'route: https://' . $domain . ' (100%)';
                }
                if (!empty($data['retirement'])) {
                    $stage_logs[3][] = 'note: ' . (string) $data['retirement'];
                }
                if (!empty($data['error']) || !empty($data['reason'])) {
                    $err_msg = 'ERROR: ' . (string) ($data['error'] ?? $data['reason']);
                    $err_stage = $public_failure ? 3 : ($local_failure ? 2 : 1);
                    $stage_logs[$err_stage][] = $err_msg;
                }
            }
        }
        if ($build === null) {
            $stage_logs[0][] = 'Skipped — reused existing release.';
        }
        foreach ($stages as $idx => &$st) {
            $st['logs'] = $stage_logs[$idx] ?? [];
        }
        unset($st);
        usort($activity, static fn($a, $b) => $a['at'] <=> $b['at']);
        return [
            'id' => ($build ?? $deploy)['id'],
            'app' => $app ?? 'Build verification',
            'app_id' => $app,
            'domain' => $domain,
            'status' => $status,
            'canary' => is_array($app_record['canary'] ?? null) ? $app_record['canary'] : null,
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

    private function _service_timeline(array $job): array {
        $id = (string) $job['id'];
        $kind = (string) $job['kind'];
        $status = (string) $job['status'];
        $payload = json_decode((string) ($job['payload'] ?? ''), true) ?: [];
        $result = json_decode((string) ($job['result'] ?? ''), true) ?: [];
        $service_id = is_string($payload['service'] ?? null) ? $payload['service'] : '';
        $service_record = $service_id !== '' ? $this->records->_find(['kind' => 'service', 'id' => $service_id]) : null;
        $service_name = (string) ($payload['service_name'] ?? $service_record['name'] ?? ($service_id !== '' ? $service_id : 'service'));
        $app_id = (string) ($payload['app'] ?? $service_record['app'] ?? '');
        $app_record = $app_id !== '' ? $this->records->_find(['kind' => 'app', 'id' => $app_id]) : null;
        $app_label = $app_id !== '' ? (string) ($app_record['label'] ?? $app_id) : '';
        $has_setup = $service_record === null || trim((string) ($service_record['setup'] ?? '')) !== '';
        $memory_mb = (int) ($result['memory_mb'] ?? $service_record['memory_mb'] ?? 0);

        $phase = (string) ($result['phase'] ?? '');
        if ($status === 'running') {
            $progress = $this->db->query_bind(
                "SELECT data FROM steps WHERE operation=? AND step='progress' ORDER BY at DESC LIMIT 1",
                [$id],
                'statement'
            )->fetchColumn();
            if ($progress) {
                $phase = (string) (json_decode((string) $progress, true)['phase'] ?? $phase);
            }
        }

        $created_ts = (int) floor(((int) ($job['created'] ?? 0)) / 1000000);
        $fmt_log = static fn(?int $ts, string $line): string => ($ts && $ts > 0 ? '[' . gmdate('H:i:s', $ts) . '] ' : '') . $line;
        $activity = [['at' => $created_ts, 'text' => $kind === 'destroy-service' ? 'Service removal queued.' : 'Service deployment queued.']];
        $steps = $this->db->query_bind('SELECT at,step,data FROM steps WHERE operation=? ORDER BY at,id', [$id], 'statement')->fetchAll();

        if ($kind === 'destroy-service') {
            $stage_logs = [0 => [$fmt_log($created_ts, 'Removal queued.')], 1 => []];
            $stage_status = static function (int $idx) use ($status, $phase): string {
                if ($status === 'succeeded') {
                    return 'succeeded';
                }
                if ($status === 'pending') {
                    return 'pending';
                }
                $active = $phase === 'destroyed' ? 1 : 0;
                if (in_array($status, ['failed', 'uncertain'], true)) {
                    return $idx < $active ? 'succeeded' : ($idx === $active ? $status : 'pending');
                }
                return $idx < $active ? 'succeeded' : ($idx === $active ? 'running' : 'pending');
            };
            $stages = [
                [
                    'name' => 'Stop service',
                    'description' => 'Stop the running service and disconnect it from private networking.',
                    'status' => $stage_status(0),
                ],
                [
                    'name' => 'Clean up',
                    'description' => 'Remove service files and stored configuration.',
                    'status' => $stage_status(1),
                ],
            ];
            $phase_stage = static fn(string $p): int => $p === 'destroyed' ? 1 : 0;
        } else {
            $stage_logs = [0 => [$fmt_log($created_ts, 'Deployment queued.')], 1 => [], 2 => [], 3 => []];
            $active = match ($phase) {
                'running-setup' => 1,
                'starting-service' => 2,
                'checking-service' => 3,
                'running' => 4,
                default => 0,
            };
            $stage_status = static function (int $idx) use ($status, $active, $has_setup): string {
                if ($idx === 1 && !$has_setup) {
                    return 'skipped';
                }
                if ($status === 'succeeded') {
                    return 'succeeded';
                }
                if ($status === 'pending') {
                    return 'pending';
                }
                if (in_array($status, ['failed', 'uncertain'], true)) {
                    $failed_idx = min(3, $active);
                    return $idx < $failed_idx ? 'succeeded' : ($idx === $failed_idx ? $status : 'pending');
                }
                return $idx < $active ? 'succeeded' : ($idx === $active ? 'running' : 'pending');
            };
            $stages = [
                [
                    'name' => 'Prepare',
                    'description' => 'Set up the service environment and install required packages.',
                    'status' => $stage_status(0),
                ],
                [
                    'name' => 'Setup',
                    'description' => $has_setup ? 'Run one-time setup commands.' : 'No setup commands configured.',
                    'status' => $stage_status(1),
                ],
                [
                    'name' => 'Start',
                    'description' => 'Start the background service process.',
                    'status' => $stage_status(2),
                ],
                [
                    'name' => 'Health check',
                    'description' => 'Verify the service is listening and ready for connections.',
                    'status' => $stage_status(3),
                ],
            ];
            $phase_stage = static fn(string $p): int => match ($p) {
                'running-setup' => 1,
                'starting-service' => 2,
                'checking-service', 'running' => 3,
                default => 0,
            };
        }

        $seen_phases = [];
        foreach ($steps as $step) {
            $detail = json_decode((string) ($step['data'] ?? ''), true) ?: [];
            $step_ts = (int) $step['at'];
            if ($step['step'] === 'claimed') {
                $text = $kind === 'destroy-service' ? 'Service removal started.' : 'Service deployment started.';
                $activity[] = ['at' => $step_ts, 'text' => $text];
                $stage_logs[0][] = $fmt_log($step_ts, $text);
            } elseif ($step['step'] === 'progress' && !empty($detail['phase'])) {
                $ph = (string) $detail['phase'];
                $seen_phases[$ph] = true;
                $label = self::_phase_label($ph);
                $activity[] = ['at' => $step_ts, 'text' => $label];
                $stage_logs[$phase_stage($ph)][] = $fmt_log($step_ts, $label);
            } elseif ($step['step'] === 'observed') {
                $obs = (string) ($detail['status'] ?? $status);
                $text = match ($obs) {
                    'succeeded' => $kind === 'destroy-service' ? 'Service removed.' : 'Service is running and ready.',
                    'failed' => $kind === 'destroy-service' ? 'Service removal failed.' : 'Service deployment failed.',
                    'uncertain' => 'Operation needs verification.',
                    default => 'Status updated: ' . $obs . '.',
                };
                $activity[] = ['at' => $step_ts, 'text' => $text];
            }
        }
        foreach ($result['events'] ?? [] as $event) {
            $ev_step = (string) ($event['step'] ?? '');
            $ev_ts = (int) ($event['at'] ?? 0);
            if ($ev_step === '' || isset($seen_phases[$ev_step])) {
                continue;
            }
            $label = self::_phase_label($ev_step);
            $activity[] = ['at' => $ev_ts, 'text' => $label];
            $stage_logs[$phase_stage($ev_step)][] = $fmt_log($ev_ts, $label);
        }

        $error_text = (string) ($result['error'] ?? $result['reason'] ?? '');
        if ($error_text !== '' && in_array($status, ['failed', 'uncertain'], true)) {
            $err_idx = $phase_stage($phase);
            $stage_logs[$err_idx][] = 'Error: ' . $error_text;
        }
        foreach ($stages as $idx => &$st) {
            $st['logs'] = $stage_logs[$idx] ?? [];
        }
        unset($st);
        usort($activity, static fn($a, $b) => $a['at'] <=> $b['at']);

        $problem = null;
        if (in_array($status, ['failed', 'uncertain'], true)) {
            $problem = match (true) {
                $status === 'uncertain' => [
                    'title' => 'The service operation needs verification.',
                    'suggestions' => [
                        'Check the Services tab before retrying; the operation may still be finishing.',
                    ],
                ],
                $phase === 'installing-packages' => [
                    'title' => 'Could not install service packages.',
                    'suggestions' => [
                        'Verify that every package name is spelled correctly and available.',
                        'Retry the deployment once package names are verified.',
                    ],
                ],
                $phase === 'running-setup' => [
                    'title' => 'Service setup commands did not finish.',
                    'suggestions' => [
                        'Check your setup commands, memory limit, and required packages.',
                        'You can retry the service deployment from the button below.',
                    ],
                ],
                $phase === 'checking-service' => [
                    'title' => 'The service started, but did not respond on its configured port.',
                    'suggestions' => [
                        'Confirm your start command binds to 0.0.0.0 and listens on the configured port (or $PORT).',
                        'Check that memory is sufficient for the service to finish starting.',
                    ],
                ],
                default => [
                    'title' => $kind === 'destroy-service' ? 'Could not finish removing the service.' : 'Service deployment could not finish.',
                    'suggestions' => [
                        'Review the failed step above and retry.',
                    ],
                ],
            };
        }

        $diag = array_filter([
            'operation' => $id,
            'status' => $status,
            'phase' => $phase !== '' ? $phase : null,
            'error' => $error_text !== '' ? $error_text : null,
        ], static fn($v) => $v !== null);

        $back_href = $app_id !== '' ? '/apps/services/' . rawurlencode($app_id) : '/services';
        $back_label = $app_id !== '' ? 'Back to ' . $app_label . ' services' : 'Back to services';

        return [
            'id' => $id,
            'kind' => $kind,
            'heading' => $service_name . ' · ' . ($kind === 'destroy-service' ? 'Remove service' : 'Service deployment'),
            'back_href' => $back_href,
            'back_label' => $back_label,
            'app' => $app_label !== '' ? $app_label : $service_name,
            'app_id' => $app_id !== '' ? $app_id : null,
            'domain' => '',
            'status' => $status,
            'canary' => null,
            'go_live' => null,
            'service' => [
                'id' => $service_id,
                'name' => $service_name,
                'env_var' => Service_spec::url_variable($service_name),
                'memory_mb' => $memory_mb,
                'can_retry' => $kind === 'deploy-service' && $status === 'failed' && $service_record !== null,
            ],
            'stages' => $stages,
            'activity' => $activity,
            'problem' => $problem,
            'diagnostics' => $diag,
            'retry' => null,
        ];
    }

    private function _destroy_app_timeline(array $job): array {
        $id = (string) $job['id'];
        $status = (string) $job['status'];
        $payload = json_decode((string) ($job['payload'] ?? ''), true) ?: [];
        $result = json_decode((string) ($job['result'] ?? ''), true) ?: [];
        $app_id = (string) ($payload['app'] ?? 'app');
        $phase = (string) ($result['phase'] ?? '');
        $created_ts = (int) floor(((int) ($job['created'] ?? 0)) / 1000000);
        $fmt_log = static fn(?int $ts, string $line): string => ($ts && $ts > 0 ? '[' . gmdate('H:i:s', $ts) . '] ' : '') . $line;

        $active = $phase === 'destroyed' ? 1 : 0;
        $stage_status = static function (int $idx) use ($status, $active): string {
            if ($status === 'succeeded') {
                return 'succeeded';
            }
            if ($status === 'pending') {
                return 'pending';
            }
            if (in_array($status, ['failed', 'uncertain'], true)) {
                return $idx < $active ? 'succeeded' : ($idx === $active ? $status : 'pending');
            }
            return $idx < $active ? 'succeeded' : ($idx === $active ? 'running' : 'pending');
        };
        $stages = [
            [
                'name' => 'Stop app',
                'description' => 'Stop running instances and remove domain routing.',
                'status' => $stage_status(0),
                'logs' => [$fmt_log($created_ts, 'Deletion queued.')],
            ],
            [
                'name' => 'Clean up',
                'description' => !empty($payload['purge_data'])
                    ? 'Remove application state, database, and persistent data.'
                    : 'Remove application state and active sessions.',
                'status' => $stage_status(1),
                'logs' => $status === 'succeeded' ? ['Application removed.'] : [],
            ],
        ];
        $activity = [['at' => $created_ts, 'text' => 'App deletion queued.']];
        if ($status === 'succeeded') {
            $activity[] = ['at' => time(), 'text' => 'Application removed.'];
        }
        $error_text = (string) ($result['error'] ?? $result['reason'] ?? '');
        $problem = in_array($status, ['failed', 'uncertain'], true)
            ? ['title' => 'Could not finish deleting the app.', 'suggestions' => ['Review the status and retry if needed.']]
            : null;
        $diag = array_filter([
            'operation' => $id,
            'status' => $status,
            'phase' => $phase !== '' ? $phase : null,
            'error' => $error_text !== '' ? $error_text : null,
        ], static fn($v) => $v !== null);

        return [
            'id' => $id,
            'kind' => 'destroy-app',
            'heading' => $app_id . ' · Delete app',
            'back_href' => '/apps',
            'back_label' => 'Back to your apps',
            'app' => $app_id,
            'app_id' => null,
            'domain' => '',
            'status' => $status,
            'canary' => null,
            'go_live' => null,
            'service' => null,
            'stages' => $stages,
            'activity' => $activity,
            'problem' => $problem,
            'diagnostics' => $diag,
            'retry' => null,
        ];
    }

    static function _phase_label(string $phase): string {
        return match ($phase) {
            'allocating-resources' => 'Allocating application resources.',
            'preparing-database' => 'Preparing MariaDB database.',
            'preparing-sessions' => 'Preparing session store.',
            'staging-release' => 'Staging application release.',
            'starting-standby' => 'Starting new app instance.',
            'checking-local' => 'Checking app health.',
            'routing-canary' => 'Routing canary traffic.',
            'switching' => 'Switching traffic to new release.',
            'checking-public' => 'Verifying public HTTPS access.',
            'retiring-previous' => 'Draining and stopping previous instance.',
            'retiring-canary' => 'Draining and stopping canary instance.',
            'accepted' => 'Your app is live.',
            'canary-active' => 'Canary is live alongside the stable release.',
            'canary-updated' => 'Canary traffic split updated.',
            'canary-aborted' => 'Canary aborted; 100% traffic restored to stable release.',
            'awaiting-go-live' => 'App ready; connect DNS to go live.',
            'staging-service' => 'Preparing service environment.',
            'installing-packages' => 'Installing packages.',
            'running-setup' => 'Running setup commands.',
            'starting-service' => 'Starting service.',
            'checking-service' => 'Checking service health.',
            'running' => 'Service is running and ready.',
            'destroying-service' => 'Stopping and removing service.',
            'destroying' => 'Removing app instances, routing, and sessions.',
            'destroyed' => 'Removed.',
            default => 'Progress updated.',
        };
    }

    function _queue(string $command, array $p): string {
        if (!in_array($command, ['deploy', 'rollback', 'canary-weight', 'canary-promote', 'canary-abort'], true)) {
            throw new RuntimeException('Unknown deployment action');
        }
        $app = $this->records->_get(['kind' => 'app', 'id' => $p['app']]);
        if (in_array($command, ['deploy', 'rollback'], true) && !empty($app['canary'])) {
            throw new RuntimeException('Promote or abort the active canary before starting another deployment.');
        }
        if (in_array($command, ['canary-weight', 'canary-promote', 'canary-abort'], true) && empty($app['canary'])) {
            throw new RuntimeException('This app does not have an active canary deployment.');
        }
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
