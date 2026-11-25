<?php
declare(strict_types=1);
require_once __DIR__ . '/resources.php';

/** Read-only FreeBSD host and jail pressure snapshots; never expose process arguments or environment. */
class Metrics {
    public function __construct(
        public string $root,
        public ?Runtime $runtime = null,
        public string $config_path = '/etc/tc-resources.json',
        public int $sample_delay_us = 1000000
    ) {
    }

    public static function collect(string $root, ?Runtime $runtime = null): array {
        return (new self($root, $runtime))->sample();
    }

    protected function command(array $args): string {
        return $this->runtime !== null ? $this->runtime->run($args) : Runtime::process($args);
    }

    public function sysctl(string $key): string {
        return trim($this->command(['sysctl', '-n', $key]));
    }

    protected function sysctl_int(string $key, ?int $default = null): int {
        try {
            $raw = $this->sysctl($key);
        } catch (RuntimeException $error) {
            if ($default !== null) {
                return $default;
            }
            throw $error;
        }
        if ($raw === '' || !is_numeric($raw)) {
            if ($default !== null) {
                return $default;
            }
            throw new RuntimeException('Could not read numeric sysctl ' . $key . '.');
        }
        return (int) $raw;
    }

    public function zfs(string $pool): array {
        $pool = Runtime::match($pool, '[A-Za-z][A-Za-z0-9_.:-]{0,63}');
        $output = trim($this->command(['zfs', 'list', '-Hp', '-o', 'used,avail', $pool]));
        $parts = preg_split('/\s+/', $output);
        if (!is_array($parts) || count($parts) < 2) {
            throw new RuntimeException('Could not inspect ZFS pool space for ' . $pool . '.');
        }
        $used = Resources::parse_bytes($parts[0]);
        $free = Resources::parse_bytes($parts[1]);
        return [
            'disk_total' => $used + $free,
            'disk_used' => $used,
            'disk_free' => $free,
        ];
    }

    public function jls(): array {
        $output = trim($this->command(['jls', '-N', '--libxo', 'json']));
        if ($output === '') {
            return [];
        }
        $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $entries = $decoded['jail-information']['jail'] ?? [];
        return is_array($entries) ? $entries : [];
    }

    public function rctl(string $jail): array {
        $jail = Runtime::match($jail, '[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}');
        $output = $this->command(['rctl', 'jail:' . $jail]);
        return Resources::parse_rctl_limits($output);
    }

    public function cpu_ticks(): array {
        $raw = $this->sysctl('kern.cp_time');
        $parts = preg_split('/\s+/', trim($raw));
        if (!is_array($parts) || count($parts) < 5) {
            throw new RuntimeException('Could not parse FreeBSD kern.cp_time counters.');
        }
        $values = array_map('intval', array_slice($parts, 0, 5));
        return [array_sum($values), $values[4]];
    }

    public function load_1m(): float {
        try {
            $raw = $this->sysctl('vm.loadavg');
            if (preg_match('/([0-9]+(?:\.[0-9]+)?)/', $raw, $match) === 1) {
                return (float) $match[1];
            }
        } catch (RuntimeException) {
            // Fall back to sys_getloadavg() when available on POSIX systems.
        }
        if (function_exists('sys_getloadavg')) {
            $avg = sys_getloadavg();
            if (is_array($avg) && isset($avg[0])) {
                return (float) $avg[0];
            }
        }
        return 0.0;
    }

    protected function wait_sample(): void {
        if ($this->sample_delay_us > 0) {
            usleep($this->sample_delay_us);
        }
    }

    protected function read_config(): array {
        if ($this->runtime !== null) {
            $config = $this->runtime->read($this->config_path, []);
            return is_array($config) ? $config : [];
        }
        if (!is_file($this->config_path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($this->config_path), true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : [];
    }

    public function disk_space(?array $config = null): array {
        $config ??= $this->read_config();
        $pool = $config['zfs_pool'] ?? null;
        if (is_string($pool) && $pool !== '') {
            return $this->zfs($pool);
        }
        $diskTotal = @disk_total_space($this->root);
        $diskFree = @disk_free_space($this->root);
        if ($diskTotal === false || $diskFree === false) {
            throw new RuntimeException('Could not inspect host storage.');
        }
        return [
            'disk_total' => (int) $diskTotal,
            'disk_used' => max(0, (int) ($diskTotal - $diskFree)),
            'disk_free' => (int) $diskFree,
        ];
    }

    public function sample(): array {
        $before = $this->cpu_ticks();
        $this->wait_sample();
        $after = $this->cpu_ticks();
        $total = $after[0] - $before[0];
        $idle = $after[1] - $before[1];
        $cpu = $total > 0 ? round(max(0.0, min(100.0, 100 * (1 - $idle / $total))), 1) : null;

        $cpus = max(1, (int) $this->sysctl('hw.ncpu'));
        $load1m = $this->load_1m();

        $memoryTotal = $this->sysctl_int('hw.physmem');
        $pagesize = max(1, $this->sysctl_int('hw.pagesize', 4096));
        $vFree = $this->sysctl_int('vm.stats.vm.v_free_count');
        $vInactive = $this->sysctl_int('vm.stats.vm.v_inactive_count');
        $vLaundry = $this->sysctl_int('vm.stats.vm.v_laundry_count');
        $arcSize = $this->sysctl_int('kstat.zfs.misc.arcstats.size', 0);
        $arcCMin = $this->sysctl_int('kstat.zfs.misc.arcstats.c_min', 0);
        $arcReclaimable = max(0, $arcSize - $arcCMin);
        $available = ($vFree + $vInactive + $vLaundry) * $pagesize + $arcReclaimable;
        $memoryUsed = max(0, $memoryTotal - $available);

        $disk = $this->disk_space();

        $knownApps = [];
        $jailToApp = [];
        $jailMeta = [];
        $stateApps = [];
        $stateMemory = 0;
        $stateCpu = 0;

        foreach (glob($this->root . '/apps/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $app = basename($directory);
            $knownApps[] = $app;
            $statePath = $directory . '/state.json';
            $state = is_file($statePath)
                ? json_decode((string) file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR)
                : [];
            if (is_array($state)) {
                foreach (['active', 'previous'] as $slotKey) {
                    $entry = $state[$slotKey] ?? null;
                    if (is_array($entry) && !empty($entry['jail'])) {
                        $jailName = (string) $entry['jail'];
                        $jailToApp[$jailName] = $app;
                        $jailMeta[$jailName] = $entry;
                    }
                }
                $active = $state['active'] ?? null;
                if (is_array($active)) {
                    $stateApps[$app] = true;
                    $stateMemory += (int) ($active['memory_mb'] ?? 0) * Resources::MIB;
                    $stateCpu += (int) ($active['cpu_percent'] ?? 0);
                }
            }
            foreach (glob($directory . '/releases/*/release.json') ?: [] as $releasePath) {
                $release = json_decode((string) file_get_contents($releasePath), true, 512, JSON_THROW_ON_ERROR);
                if (is_array($release) && !empty($release['jail']) && !isset($jailToApp[$release['jail']])) {
                    $jailName = (string) $release['jail'];
                    $jailToApp[$jailName] = $app;
                    $jailMeta[$jailName] = $release;
                }
            }
        }

        $activeApps = [];
        $runningVersions = 0;
        $reservedMemory = 0;
        $reservedCpu = 0;

        foreach ($this->jls() as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $jailName = (string) ($entry['name'] ?? $entry['hostname'] ?? '');
            if ($jailName === '') {
                continue;
            }
            $app = $jailToApp[$jailName] ?? null;
            if ($app === null) {
                $parsed = Resources::parse_jail_name($jailName, $knownApps);
                if ($parsed === null) {
                    continue;
                }
                $app = $parsed['app'];
            }
            $activeApps[$app] = true;
            $runningVersions++;
            $limits = $this->rctl($jailName);
            $reservedMemory += $limits['memoryuse'] ?? ((int) ($jailMeta[$jailName]['memory_mb'] ?? 0) * Resources::MIB);
            $reservedCpu += $limits['pcpu'] ?? (int) ($jailMeta[$jailName]['cpu_percent'] ?? 0);
        }

        return [
            'at' => time(),
            'cpu_percent' => $cpu,
            'cpus' => $cpus,
            'load_1m' => $load1m,
            'memory_total' => $memoryTotal,
            'memory_used' => $memoryUsed,
            'arc_size' => $arcSize,
            'arc_reclaimable' => $arcReclaimable,
            'disk_total' => $disk['disk_total'],
            'disk_used' => $disk['disk_used'],
            'disk_free' => $disk['disk_free'],
            'app_memory_limit' => $reservedMemory,
            'app_cpu_limit' => $reservedCpu,
            'apps' => count($activeApps),
            'app_versions' => $runningVersions,
        ];
    }
}
