<?php
declare(strict_types=1);
require_once __DIR__ . '/tc_resources.php';

/** Read-only pressure snapshots; never expose process arguments or environment. */
class Metrics {
    public static function cpu_ticks(): array {
        $line = strtok(file_get_contents('/proc/stat'), "\n");
        $values = array_map('intval', array_slice(preg_split('/\s+/', trim($line)), 1, 8));
        return [array_sum($values), $values[3] + $values[4]];
    }

    public static function collect(string $root, ?Runtime $runtime = null): array {
        $before = self::cpu_ticks();
        sleep(1);
        $after = self::cpu_ticks();
        $total = $after[0] - $before[0];
        $cpu = $total > 0 ? round(100 * (1 - ($after[1] - $before[1]) / $total), 1) : null;
        preg_match_all('/^(\w+):\s+(\d+)/m', file_get_contents('/proc/meminfo'), $matches, PREG_SET_ORDER);
        $memory = [];
        foreach ($matches as $match) {
            $memory[$match[1]] = (int) $match[2] * 1024;
        }
        $diskTotal = disk_total_space($root);
        $diskFree = disk_free_space($root);
        if ($diskTotal === false || $diskFree === false) {
            throw new RuntimeException('Could not inspect host storage.');
        }
        $apps = [];
        $reservedMemory = $reservedCpus = 0;
        foreach (glob($root . '/apps/*/state.json') as $path) {
            $state = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $active = $state['active'] ?? null;
            if ($active) {
                $reservedMemory += ($active['memory_mb'] ?? 0) * Resources::MIB;
                $reservedCpus += $active['cpus'] ?? 0;
                $apps[] = basename(dirname($path));
            }
        }
        $versions = count($apps);
        if ($runtime !== null) {
            $running = array_values(array_filter((new Resources($runtime))->containers(), fn(array $item): bool => $item['running']));
            $apps = array_unique(array_column($running, 'app'));
            $versions = count($running);
            $reservedMemory = array_sum(array_column($running, 'memory_mb')) * Resources::MIB;
            $reservedCpus = array_sum(array_map(fn(array $item) => ($item['shares'] ?: 1024) / 1024, $running));
        }
        return ['at' => time(), 'cpu_percent' => $cpu, 'cpus' => Resources::cpu_count(),
            'load_1m' => sys_getloadavg()[0], 'memory_total' => $memory['MemTotal'],
            'memory_used' => $memory['MemTotal'] - ($memory['MemAvailable'] ?? $memory['MemFree']),
            'disk_total' => (int) $diskTotal, 'disk_used' => (int) ($diskTotal - $diskFree), 'disk_free' => (int) $diskFree,
            'app_memory_limit' => $reservedMemory, 'app_cpu_limit' => $reservedCpus, 'apps' => count($apps), 'app_versions' => $versions];
    }
}
