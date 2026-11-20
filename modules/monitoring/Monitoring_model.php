<?php
require_once dirname(__DIR__) . '/db/traits/Database_access.php';
require_once dirname(__DIR__) . '/operations/traits/Inventory_records.php';
require_once dirname(__DIR__) . '/operations/traits/Encrypted_credentials.php';
class Monitoring_model extends Model {
    use Database_access, Inventory_records, Encrypted_credentials;

    function _collect(): void {
        $ssh = new Ssh($this);
        foreach ($this->all('server') as $server) {
            if (empty($server['application_ready']) && empty($server['builder_ready'])) {
                continue;
            }
            try {
                $response = $ssh->command($server, !empty($server['application_ready']) ? 'tc-runtime' : 'tc-build', tc_json(['action' => 'metrics']), 20);
                $sample = json_decode($response['out'], true, 512, JSON_THROW_ON_ERROR);
                if ($response['exit'] !== 0 || !isset($sample['memory_total'], $sample['disk_total'], $sample['at'])) {
                    throw new RuntimeException('Metrics unavailable');
                }
                $this->_record($server['id'], $sample);
            } catch (Throwable) {
                // Preserve the last sample; the view marks stale data instead of showing zero.
            }
        }
    }

    function _record(string $server, array $sample): void {
        $allowed = ['at', 'cpu_percent', 'cpus', 'load_1m', 'memory_total', 'memory_used', 'disk_total', 'disk_used', 'disk_free', 'app_memory_limit', 'app_cpu_limit', 'apps', 'app_versions'];
        $sample = array_intersect_key($sample, array_flip($allowed));
        foreach ($sample as $value) {
            if ($value !== null && (!is_numeric($value) || $value < 0)) {
                throw new RuntimeException('Invalid metrics');
            }
        }
        try {
            $history = $this->get('server-metrics', $server)['samples'];
        } catch (RuntimeException) {
            $history = [];
        }
        if ($history && $sample['at'] <= end($history)['at']) {
            return;
        }
        $history[] = $sample;
        $this->put('server-metrics', $server, ['samples' => array_slice($history, -288)]);
    }

    function _summary(string $server): array {
        try {
            $samples = $this->get('server-metrics', $server)['samples'];
        } catch (RuntimeException) {
            $samples = [];
        }
        $latest = $samples ? end($samples) : null;
        $stale = !$latest || $latest['at'] < time() - 900;
        $hints = [];
        $recent = array_slice($samples, -3);
        $sustained = !$stale && count($recent) === 3 && $recent[0]['at'] >= time() - 1200 && $latest['at'] - $recent[0]['at'] >= 500;
        if ($sustained) {
            foreach (['cpu' => 85, 'memory' => 85, 'disk' => 85] as $metric => $threshold) {
                $high = array_filter($recent, static function ($sample) use ($metric, $threshold): bool {
                    $value = $metric === 'cpu' ? ($sample['cpu_percent'] ?? null) : 100 * $sample[$metric . '_used'] / max(1, $sample[$metric . '_total']);
                    return $value !== null && $value >= $threshold;
                });
                if (count($high) === 3) {
                    $hints[] = match ($metric) {
                        'cpu' => 'CPU usage has stayed above 85%. Consider a dedicated builder if this coincides with builds, or a larger server if traffic keeps it busy.',
                        'memory' => 'Memory usage has stayed above 85%. Consider moving builds to a dedicated server or increasing server memory.',
                        'disk' => 'Disk usage has stayed above 85%. Review stored uploads and old releases before increasing storage.'
                    };
                }
            }
        }
        if (!$stale && ($latest['disk_free'] ?? PHP_INT_MAX) < 5 * 1024 ** 3) {
            $hints[] = 'Less than 5 GB is free. Free space or choose a dedicated builder before starting another local build.';
        }
        return ['latest' => $latest, 'stale' => $stale, 'hints' => $hints, 'autoscaling' => false];
    }
}
