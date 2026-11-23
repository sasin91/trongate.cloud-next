<?php
require_once APPPATH . 'modules/trongate_security/traits/Encrypted_credentials.php';
class Monitoring_model extends Model {
    use Encrypted_credentials;
    public function __construct(?string $module_name = null, PDO|Closure|null $connection = null) {
        parent::__construct($module_name, $connection);
        $this->module('records');
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
            $history = $this->records->_get(['kind' => 'server-metrics', 'id' => $server])['samples'];
        } catch (RuntimeException) {
            $history = [];
        }
        if ($history && $sample['at'] <= end($history)['at']) {
            return;
        }
        $history[] = $sample;
        $this->records->_put(['kind' => 'server-metrics', 'id' => $server, 'data' => ['samples' => array_slice($history, -288)]]);
    }

    function _summary(string $server): array {
        try {
            $samples = $this->records->_get(['kind' => 'server-metrics', 'id' => $server])['samples'];
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
