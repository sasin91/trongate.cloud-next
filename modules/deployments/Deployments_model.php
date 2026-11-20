<?php
require_once dirname(__DIR__).'/db/traits/Database_access.php';
require_once dirname(__DIR__).'/operations/traits/Inventory_records.php';
require_once dirname(__DIR__).'/operations/traits/Encrypted_credentials.php';
require_once dirname(__DIR__).'/operations/traits/Operation_queue.php';
class Deployments_model extends Model {
    use Database_access, Inventory_records, Encrypted_credentials, Operation_queue;
    function _timeline(string $id): ?array {
        $job = $this->job($id);
        if (!in_array($job['kind'], ['build', 'deploy', 'rollback'], true)) {
            return null;
        }
        $build = $job['kind'] === 'build' ? $job : null;
        $deploy = $job['kind'] === 'build' ? null : $job;
        foreach ($this->all('build-deployment') as $link) {
            if ($link['id'] === $id || $link['operation'] === $id) {
                $build = $this->job($link['id']);
                $deploy = $this->job($link['operation']);
                break;
            }
        }
        $payload = json_decode(($deploy ?? $build)['payload'], true);
        $app = $payload['app'] ?? $payload['launch']['app'] ?? null;
        $serverId = $payload['server'] ?? $payload['launch']['server'] ?? null;
        $server = $serverId ? $this->get('server', $serverId) : [];
        $result = json_decode(($deploy ?? $build)['result'], true) ?: [];
        $phase = $result['phase'] ?? '';
        if ($deploy && $deploy['status'] === 'running') {
            $progress = $this->run("SELECT data FROM steps WHERE operation=? AND step='progress' ORDER BY at DESC LIMIT 1", [$deploy['id']])->fetchColumn();
            if ($progress) {
                $phase = json_decode($progress, true)['phase'] ?? '';
            }
        }
        $status = ($deploy ?? $build)['status'];
        $failed = in_array($status, ['failed', 'uncertain'], true);
        $publicFailure = ($result['error'] ?? '') === 'public health check failed';
        $localFailure = str_contains($result['error'] ?? '', 'candidate health');
        $advanced = in_array($phase, ['checking-local', 'switching', 'checking-public', 'accepted', 'awaiting-go-live'], true);
        $stages = [
            ['name' => 'Build', 'description' => 'Prepare your app and its dependencies.', 'status' => $build ? $build['status'] : 'skipped'],
            ['name' => 'Deploy', 'description' => 'Prepare the image and start it on your server.', 'status' => !$deploy ? 'pending' : ($advanced || $status === 'succeeded' ? 'succeeded' : $status)],
            ['name' => 'Check app', 'description' => 'Make sure the app responds on the server.', 'status' => $publicFailure || in_array($phase, ['switching', 'checking-public', 'accepted', 'awaiting-go-live'], true) || $status === 'succeeded' ? 'succeeded' : ($localFailure ? 'failed' : ($phase === 'checking-local' ? 'running' : 'pending'))],
            ['name' => 'Go live', 'description' => 'Verify HTTPS through your public domain.', 'status' => $status === 'succeeded' && $deploy ? 'succeeded' : ($status === 'waiting' ? 'waiting' : ($publicFailure ? 'failed' : ($phase === 'checking-public' ? 'running' : 'pending')))]
        ];
        $domain = $result['candidate']['domain'] ?? '';
        if ($domain === '' && $app) {
            $domain = $this->get('app', $app)['domain'] ?? '';
        }
        $problem = null;
        if ($failed) {
            $problem = match (true) {
                !empty($result['reason']) && $build && !$deploy => ['title' => 'The build needs attention.', 'suggestions' => [$result['reason']]],
                $publicFailure => ['title' => 'Your app started, but its public address could not be verified.', 'suggestions' => ['Point the A record for ' . $domain . ' to ' . ($server['host'] ?? 'your application server') . '.', 'Update or remove any old AAAA record. Both IPv4 and IPv6 must reach this server.', 'Allow ports 80 and 443. Wait for DNS propagation and HTTPS certificate issuance, then retry.']],
                $localFailure => ['title' => 'The app did not respond on the server.', 'suggestions' => ['Check the app’s startup configuration and database connection.', 'Check whether the app redirects its home page or needs a different readiness path.']],
                $status === 'uncertain' => ['title' => 'The operation needs verification.', 'suggestions' => ['Check the operation diagnostics before retrying; a remote process may still be running.']],
                default => ['title' => $build && !$deploy ? 'The build could not finish.' : 'Deployment could not finish.', 'suggestions' => [$build && !$deploy ? 'Check your build commands and required PHP extensions.' : 'Check server readiness and image registry access.', 'Copy the diagnostics for the exact operation and failure stage.']]
            };
            if ($publicFailure && $domain !== '') {
                $addresses = @dns_get_record($domain, DNS_A | DNS_AAAA) ?: [];
                $problem['dns'] = array_values(array_filter(array_map(static fn($r) => $r['ip'] ?? $r['ipv6'] ?? null, $addresses)));
            }
        }
        $activity = [];
        $diagnostics = [];
        foreach (array_filter([$build, $deploy]) as $item) {
            $data = json_decode($item['result'], true) ?: [];
            $diagnostics[] = ['operation' => $item['id'], 'kind' => $item['kind'], 'status' => $item['status'], 'result' => array_intersect_key($data, array_flip(['status', 'phase', 'error', 'errno', 'recovery', 'restored', 'image', 'commit', 'deployment', 'missing', 'reason', 'message']))];
            $activity[] = ['at' => (int) floor($item['created'] / 1000000), 'text' => ucfirst($item['kind']) . ' queued.'];
            foreach ($this->run('SELECT at,step,data FROM steps WHERE operation=? ORDER BY at,id', [$item['id']])->fetchAll() as $step) {
                $detail = json_decode($step['data'], true) ?: [];
                $text = match ($step['step']) {
                    'claimed' => ucfirst($item['kind']) . ' started.',
                    'progress' => self::_phase_label($detail['phase'] ?? ''),
                    'observed' => ucfirst($item['kind']) . ': ' . ($detail['status'] ?? $item['status']) . '.',
                    default => null
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
        return ['id' => ($build ?? $deploy)['id'], 'app' => $app ?? 'Build verification', 'domain' => $domain, 'status' => $status,
            'go_live' => $deploy && $status === 'waiting' ? ['operation' => $deploy['id'], 'domain' => $domain, 'records' => $this->_dns_records($server), 'message' => $result['message'] ?? 'Your app is ready. Connect its domain whenever you are ready to go live.'] : null,
            'stages' => $stages, 'activity' => $activity, 'problem' => $problem, 'diagnostics' => $diagnostics,
            'retry' => $deploy && $status === 'failed' && ($payload['release'] ?? '') !== '' ? ['app' => $app, 'release' => $payload['release']] : null];
    }

    function _dns_records(array $server): array {
        $records = [];
        if (filter_var($server['host'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $records[] = ['type' => 'A', 'value' => $server['host']];
        } elseif (filter_var($server['host'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $records[] = ['type' => 'AAAA', 'value' => $server['host']];
        }
        if (isset($server['provider_id'])) {
            try {
                $metadata = $this->get('provider-server', (string) $server['provider_id'])['server'];
                $prefix = explode('/', $metadata['public_net']['ipv6']['ip'] ?? '')[0];
                $ip = str_ends_with($prefix, '::') ? $prefix . '1' : $prefix;
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && !in_array($ip, array_column($records, 'value'), true)) {
                    $records[] = ['type' => 'AAAA', 'value' => $ip];
                }
            } catch (RuntimeException) {
            }
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
        $expected = $this->_dns_records($this->get('server', $payload['server']));
        $observed = @dns_get_record($domain, DNS_A | DNS_AAAA) ?: [];
        $addresses = array_values(array_filter(array_map(static fn($record) => $record['ip'] ?? $record['ipv6'] ?? null, $observed)));
        if (!self::_dns_matches($addresses, array_column($expected, 'value'))) {
            throw new RuntimeException('DNS is not ready yet. Update the records below, remove old A or AAAA records, and try again after they propagate. Your deployment is saved.');
        }
        $payload['go_live'] = true;
        $this->run("UPDATE jobs SET payload=?,status='pending',lease=0 WHERE id=? AND status='waiting'", [tc_json($payload), $id]);
        return $id;
    }

    static function _dns_matches(array $observed, array $expected): bool {
        $expected = array_filter(array_map(static fn($ip) => @inet_pton($ip), $expected));
        return $observed !== [] && $expected !== [] && count(array_filter($observed, static fn($ip) => in_array(@inet_pton($ip), $expected, true))) === count($observed);
    }

    static function _phase_label(string $phase): string {
        return match ($phase) {
            'pulling-image' => 'Downloading the built image.',
            'creating-container' => 'Creating the app container.',
            'writing-unit' => 'Saving the app service.',
            'starting-candidate' => 'Starting the app.',
            'checking-local' => 'Checking the app on the server.',
            'switching' => 'Connecting the domain to the app.',
            'checking-public' => 'Checking public HTTPS access.',
            'accepted' => 'Your app is live.',
            'awaiting-go-live' => 'App ready; waiting for you to connect DNS and go live.',
            'preparing-database' => 'Preparing the app database.',
            default => 'Deployment progress updated.'
        };
    }
}
