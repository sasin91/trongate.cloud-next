<?php
require_once APPPATH . 'modules/trongate_security/traits/Encrypted_credentials.php';

class Monitoring_model extends Model {
    use Encrypted_credentials;

    function __construct(?string $module_name = null, PDO|Closure|null $connection = null) {
        parent::__construct($module_name, $connection);
        $this->module('records');
    }

    function _record(string $server, array $sample): void {
        $allowed = [
            'at',
            'cpu_percent',
            'cpus',
            'load_1m',
            'memory_total',
            'memory_used',
            'arc_size',
            'arc_reclaimable',
            'disk_total',
            'disk_used',
            'disk_free',
            'app_memory_limit',
            'app_cpu_limit',
            'apps',
            'app_versions',
        ];
        $sample = array_intersect_key($sample, array_flip($allowed));
        foreach ($sample as $value) {
            if ($value !== null && (!is_numeric($value) || $value < 0)) {
                throw new RuntimeException('Invalid metrics');
            }
        }
        $record = $this->records->_find(['kind' => 'server-metrics', 'id' => $server]);
        $history = is_array($record['samples'] ?? null) ? $record['samples'] : [];
        if ($history && $sample['at'] <= end($history)['at']) {
            return;
        }
        $history[] = $sample;
        $this->records->_put(['kind' => 'server-metrics', 'id' => $server, 'data' => ['samples' => array_slice($history, -288)]]);
    }

    function _summary(string $server): array {
        $record = $this->records->_find(['kind' => 'server-metrics', 'id' => $server]);
        $samples = is_array($record['samples'] ?? null) ? $record['samples'] : [];
        $latest = $samples ? end($samples) : null;
        $stale = !$latest || $latest['at'] < time() - 900;
        $hints = [];
        $recent = array_slice($samples, -3);
        $sustained = !$stale
            && count($recent) === 3
            && $recent[0]['at'] >= time() - 1200
            && $latest['at'] - $recent[0]['at'] >= 500;
        if ($sustained) {
            foreach (['cpu' => 85, 'memory' => 85, 'disk' => 85] as $metric => $threshold) {
                $high = array_filter($recent, static function ($sample) use ($metric, $threshold): bool {
                    $value = $metric === 'cpu'
                        ? ($sample['cpu_percent'] ?? null)
                        : 100 * $sample[$metric . '_used'] / max(1, $sample[$metric . '_total']);
                    return $value !== null && $value >= $threshold;
                });
                if (count($high) === 3) {
                    $hints[] = match ($metric) {
                        'cpu' => 'CPU usage has stayed above 85%. Review app rctl CPU limits or add another FreeBSD host if traffic keeps it busy.',
                        'memory' => 'Memory usage has stayed above 85% (after accounting for reclaimable ZFS ARC). Review app rctl memory caps or add RAM.',
                        'disk' => 'ZFS pool usage has stayed above 85%. Review stored uploads, per-app quotas, and old releases before adding storage.',
                    };
                }
            }
        }
        if (!$stale && ($latest['disk_free'] ?? PHP_INT_MAX) < 5 * 1024 ** 3) {
            $hints[] = 'Less than 5 GB is free on the ZFS pool. Run release cleanup or free space before starting another build.';
        }
        return ['latest' => $latest, 'stale' => $stale, 'hints' => $hints, 'autoscaling' => false];
    }
}
