<?php
declare(strict_types=1);

/** Host-authoritative FreeBSD rctl and ZFS resource budgets. All mutations run under the runtime operation lock. */
class Resources {
    public const MIB = 1048576;
    public const MIN_MEMORY = 64;
    public const DEFAULT_MEMORY = 128;
    public const MAX_AUTO_MEMORY = 512;
    public const MIN_CPU_PERCENT = 10;
    public const DEFAULT_CPU_PERCENT = 100;
    public const MAX_CPU_PERCENT = 1600;
    public const MIN_DISK_QUOTA_MB = 128;
    public const DEFAULT_DISK_QUOTA_MB = 128;
    public const OVERCOMMIT_RATIO = 3.0;
    public const DEFAULT_MAXPROC = 64;
    public const DEFAULT_OPENFILES = 1024;
    public const AVAILABLE_HEADROOM_MB = 256;
    public const SHRINK_HEADROOM_MB = 16;
    public const DEFAULT_ZFS_POOL = 'zroot';

    public string $path;
    public string $pending;
    public string $config_path = '/etc/tc-resources.json';

    public function __construct(public Runtime $runtime) {
        $this->path = $runtime->root . '/resources.json';
        $this->pending = $runtime->root . '/resources-pending.json';
    }

    public function sysctl(string $key): string {
        return trim($this->runtime->run(['sysctl', '-n', $key]));
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

    public function cpu_count(): int {
        return max(1, (int) $this->sysctl('hw.ncpu'));
    }

    public function zfs_pool(?array $config = null): string {
        $config ??= $this->runtime->read($this->config_path, []);
        $pool = is_array($config) && isset($config['zfs_pool']) && is_string($config['zfs_pool']) && $config['zfs_pool'] !== ''
            ? $config['zfs_pool']
            : (isset($this->runtime->pool) && $this->runtime->pool !== '' ? $this->runtime->pool : self::DEFAULT_ZFS_POOL);
        return Runtime::match($pool, '[A-Za-z][A-Za-z0-9_.:-]{0,63}');
    }

    public function pool_space(string $pool): array {
        $pool = Runtime::match($pool, '[A-Za-z][A-Za-z0-9_.:-]{0,63}');
        $output = trim($this->runtime->run(['zfs', 'list', '-Hp', '-o', 'used,avail', $pool]));
        $parts = preg_split('/\s+/', $output);
        if (!is_array($parts) || count($parts) < 2) {
            throw new RuntimeException('Could not inspect ZFS pool space for ' . $pool . '.');
        }
        $used = self::parse_bytes($parts[0]);
        $avail = self::parse_bytes($parts[1]);
        return [
            'used_bytes' => $used,
            'avail_bytes' => $avail,
            'total_bytes' => $used + $avail,
        ];
    }

    public function dataset_exists(string $dataset): bool {
        $dataset = Runtime::match($dataset, '[A-Za-z][A-Za-z0-9_.:/-]{0,191}');
        try {
            $this->runtime->run(['zfs', 'list', '-H', '-o', 'name', $dataset]);
            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    public function dataset_usage(string $dataset): array {
        $dataset = Runtime::match($dataset, '[A-Za-z][A-Za-z0-9_.:/-]{0,191}');
        if (!$this->dataset_exists($dataset)) {
            return ['used_mb' => 0, 'quota_mb' => 0];
        }
        $output = trim($this->runtime->run(['zfs', 'list', '-Hp', '-o', 'used,quota', $dataset]));
        $parts = preg_split('/\s+/', $output);
        if (!is_array($parts) || count($parts) < 2) {
            throw new RuntimeException('Could not parse ZFS dataset usage for ' . $dataset . '.');
        }
        $usedBytes = self::parse_bytes($parts[0]);
        $quotaBytes = self::parse_bytes($parts[1]);
        return [
            'used_mb' => $usedBytes > 0 ? (int) ceil($usedBytes / self::MIB) : 0,
            'quota_mb' => $quotaBytes > 0 ? intdiv($quotaBytes, self::MIB) : 0,
        ];
    }

    public function running_jails(): array {
        $output = trim($this->runtime->run(['jls', '-N', '--libxo', 'json']));
        if ($output === '') {
            return [];
        }
        $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $entries = $decoded['jail-information']['jail'] ?? [];
        if (!is_array($entries)) {
            return [];
        }
        $result = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $name = (string) ($entry['name'] ?? $entry['hostname'] ?? '');
            if ($name === '') {
                continue;
            }
            $result[$name] = $entry;
        }
        return $result;
    }

    public function rctl_usage(string $jail): array {
        $jail = Runtime::match($jail, '[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}');
        $output = $this->runtime->run(['rctl', '-u', 'jail:' . $jail]);
        return self::parse_rctl_usage($output);
    }

    public function rctl_limits(string $jail): array {
        $jail = Runtime::match($jail, '[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}');
        $output = $this->runtime->run(['rctl', 'jail:' . $jail]);
        return self::parse_rctl_limits($output);
    }

    public static function parse_bytes(string $value): int {
        $trimmed = trim($value);
        if ($trimmed === '' || $trimmed === '-' || strcasecmp($trimmed, 'none') === 0) {
            return 0;
        }
        if (preg_match('/^(\d+)\s*([kmgt])?(?:i?b)?$/i', $trimmed, $match) !== 1) {
            return (int) $trimmed;
        }
        $amount = (int) $match[1];
        $unit = strtolower($match[2] ?? '');
        $multiplier = match ($unit) {
            'k' => 1024,
            'm' => 1048576,
            'g' => 1073741824,
            't' => 1099511627776,
            default => 1,
        };
        return $amount * $multiplier;
    }

    public static function parse_rctl_usage(string $output): array {
        $values = [];
        foreach (preg_split('/\r?\n/', trim($output)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $raw] = explode('=', $line, 2);
            $key = trim($key);
            if ($key === '') {
                continue;
            }
            $values[$key] = self::parse_bytes($raw);
        }
        return $values;
    }

    public static function parse_rctl_limits(string $output): array {
        $limits = [];
        foreach (preg_split('/\r?\n/', trim($output)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^jail:[^:]+:([a-z0-9_]+):([a-z]+)=([^\s\/]+)/i', $line, $match) === 1) {
                $resource = strtolower($match[1]);
                $action = strtolower($match[2]);
                if ($action === 'deny') {
                    $limits[$resource] = self::parse_bytes($match[3]);
                }
            }
        }
        return $limits;
    }

    public static function parse_jail_name(string $jail, array $knownApps = []): ?array {
        $reserved = ['tc_mariadb', 'tc_valkey', 'tc_panel', 'tc-mariadb', 'tc-valkey', 'tc-panel'];
        if (
            in_array($jail, $reserved, true)
            || str_starts_with($jail, 'tc_build_')
            || str_starts_with($jail, 'tc-build-')
        ) {
            return null;
        }
        if (preg_match('/^tc-([a-z][a-z0-9-]{0,39})-(blue|green)$/', $jail, $match) === 1) {
            return ['app' => $match[1], 'slot' => $match[2]];
        }
        if (preg_match('/^tc_([a-z][a-z0-9_-]{0,39})_(blue|green)$/', $jail, $match) === 1) {
            $raw = $match[1];
            $hyphenated = str_replace('_', '-', $raw);
            if (in_array($raw, $knownApps, true)) {
                $app = $raw;
            } elseif (in_array($hyphenated, $knownApps, true)) {
                $app = $hyphenated;
            } else {
                $app = $hyphenated;
            }
            return ['app' => $app, 'slot' => $match[2]];
        }
        return null;
    }

    public function host(): array {
        $config = $this->runtime->read($this->config_path);
        if (!is_array($config)) {
            throw new RuntimeException('Upgrade this server runtime before allocating application resources.');
        }
        $physmem = $this->sysctl_int('hw.physmem');
        $pagesize = max(1, $this->sysctl_int('hw.pagesize', 4096));
        $freePages = $this->sysctl_int('vm.stats.vm.v_free_count');
        $inactivePages = $this->sysctl_int('vm.stats.vm.v_inactive_count');
        $laundryPages = $this->sysctl_int('vm.stats.vm.v_laundry_count');
        $arcSize = $this->sysctl_int('kstat.zfs.misc.arcstats.size', 0);
        $arcCMin = $this->sysctl_int('kstat.zfs.misc.arcstats.c_min', 0);

        $memoryMb = intdiv($physmem, self::MIB);
        $arcReclaimableBytes = max(0, $arcSize - $arcCMin);
        $arcReclaimableMb = intdiv($arcReclaimableBytes, self::MIB);
        $reclaimablePagesMb = intdiv(($freePages + $inactivePages + $laundryPages) * $pagesize, self::MIB);
        $availableMb = min($memoryMb, $reclaimablePagesMb + $arcReclaimableMb);

        $pool = $this->zfs_pool($config);
        $space = $this->pool_space($pool);
        $poolTotalMb = intdiv($space['total_bytes'], self::MIB);
        $poolFreeMb = intdiv($space['avail_bytes'], self::MIB);

        $overcommit = (float) ($config['memory_overcommit_ratio'] ?? self::OVERCOMMIT_RATIO);
        if (!is_finite($overcommit) || $overcommit < 1.0) {
            $overcommit = self::OVERCOMMIT_RATIO;
        }

        return array_merge($config, [
            'memory_mb' => $memoryMb,
            'available_mb' => $availableMb,
            'arc_reclaimable_mb' => $arcReclaimableMb,
            'cpus' => $this->cpu_count(),
            'system_memory_mb' => (int) ($config['system_memory_mb'] ?? 512),
            'database_memory_mb' => (int) ($config['database_memory_mb'] ?? 512),
            'valkey_memory_mb' => (int) ($config['valkey_memory_mb'] ?? 128),
            'build_memory_mb' => (int) ($config['build_memory_mb'] ?? 1024),
            'memory_overcommit_ratio' => $overcommit,
            'zfs_pool' => $pool,
            'pool_total_mb' => $poolTotalMb,
            'pool_free_mb' => $poolFreeMb,
        ]);
    }

    public function jails(): array {
        $runningMap = $this->running_jails();
        $pool = $this->zfs_pool();
        $discovered = [];
        $knownApps = [];

        foreach (glob($this->runtime->root . '/apps/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $app = basename($directory);
            $knownApps[] = $app;
            $state = $this->runtime->read($directory . '/state.json', []);
            foreach (['active', 'previous'] as $key) {
                $entry = $state[$key] ?? null;
                if (is_array($entry) && !empty($entry['jail'])) {
                    $jailName = Runtime::match($entry['jail'], '[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}');
                    $discovered[$jailName] = [
                        'app' => $app,
                        'jail' => $jailName,
                        'slot' => isset($entry['slot']) ? (string) $entry['slot'] : null,
                        'memory_mb' => (int) ($entry['memory_mb'] ?? self::DEFAULT_MEMORY),
                        'cpu_cap_percent' => (int) ($entry['cpu_percent'] ?? self::DEFAULT_CPU_PERCENT),
                        'disk_quota_mb' => (int) ($entry['disk_quota_mb'] ?? self::DEFAULT_DISK_QUOTA_MB),
                    ];
                }
            }
            $staged = $this->runtime->read($directory . '/staged.json', []);
            if (is_array($staged) && !empty($staged['operation'])) {
                $job = $this->runtime->read($this->runtime->root . '/jobs/' . $staged['operation'] . '.json', []);
                $candidate = $job['candidate'] ?? null;
                if (is_array($candidate) && !empty($candidate['jail'])) {
                    $jailName = Runtime::match($candidate['jail'], '[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}');
                    $discovered[$jailName] ??= [
                        'app' => $app,
                        'jail' => $jailName,
                        'slot' => isset($candidate['slot']) ? (string) $candidate['slot'] : null,
                        'memory_mb' => (int) ($candidate['memory_mb'] ?? self::DEFAULT_MEMORY),
                        'cpu_cap_percent' => (int) ($candidate['cpu_percent'] ?? self::DEFAULT_CPU_PERCENT),
                        'disk_quota_mb' => (int) ($candidate['disk_quota_mb'] ?? self::DEFAULT_DISK_QUOTA_MB),
                    ];
                }
            }
            foreach (glob($directory . '/releases/*/release.json') ?: [] as $releasePath) {
                $release = $this->runtime->read($releasePath, []);
                if (is_array($release) && !empty($release['jail'])) {
                    $jailName = Runtime::match($release['jail'], '[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}');
                    if (isset($runningMap[$jailName]) && !isset($discovered[$jailName])) {
                        $discovered[$jailName] = [
                            'app' => $app,
                            'jail' => $jailName,
                            'slot' => isset($release['slot']) ? (string) $release['slot'] : null,
                            'memory_mb' => (int) ($release['memory_mb'] ?? self::DEFAULT_MEMORY),
                            'cpu_cap_percent' => (int) ($release['cpu_percent'] ?? self::DEFAULT_CPU_PERCENT),
                            'disk_quota_mb' => (int) ($release['disk_quota_mb'] ?? self::DEFAULT_DISK_QUOTA_MB),
                        ];
                    }
                }
            }
        }

        foreach (array_keys($runningMap) as $jailName) {
            if (isset($discovered[$jailName])) {
                continue;
            }
            $parsed = self::parse_jail_name($jailName, $knownApps);
            if ($parsed === null) {
                continue;
            }
            $discovered[$jailName] = [
                'app' => $parsed['app'],
                'jail' => $jailName,
                'slot' => $parsed['slot'],
                'memory_mb' => self::DEFAULT_MEMORY,
                'cpu_cap_percent' => self::DEFAULT_CPU_PERCENT,
                'disk_quota_mb' => self::DEFAULT_DISK_QUOTA_MB,
            ];
        }

        $zfsByApp = [];
        $result = [];
        foreach ($discovered as $jailName => $meta) {
            $app = $meta['app'];
            $running = isset($runningMap[$jailName]);
            $usedMb = 0;
            $cpuPercent = null;
            $cputime = null;
            $maxproc = null;
            $effectiveMemoryMb = null;
            $effectiveCpuPercent = null;

            if ($running) {
                $usage = $this->rctl_usage($jailName);
                $usedMb = isset($usage['memoryuse']) && $usage['memoryuse'] > 0
                    ? (int) ceil($usage['memoryuse'] / self::MIB)
                    : 0;
                $cpuPercent = isset($usage['pcpu']) ? (float) $usage['pcpu'] : 0.0;
                $cputime = $usage['cputime'] ?? 0;
                $maxproc = $usage['maxproc'] ?? 0;

                $limits = $this->rctl_limits($jailName);
                $effectiveMemoryMb = isset($limits['memoryuse']) ? intdiv($limits['memoryuse'], self::MIB) : 0;
                $effectiveCpuPercent = $limits['pcpu'] ?? 0;
            }

            if (!isset($zfsByApp[$app])) {
                $zfsByApp[$app] = $this->dataset_usage($pool . '/tc/apps/' . $app);
            }

            $memoryMb = ($effectiveMemoryMb !== null && $effectiveMemoryMb > 0)
                ? $effectiveMemoryMb
                : $meta['memory_mb'];
            $cpuCap = ($effectiveCpuPercent !== null && $effectiveCpuPercent > 0)
                ? $effectiveCpuPercent
                : $meta['cpu_cap_percent'];
            $diskQuotaMb = $zfsByApp[$app]['quota_mb'] > 0
                ? $zfsByApp[$app]['quota_mb']
                : $meta['disk_quota_mb'];

            $result[] = [
                'app' => $app,
                'jail' => $jailName,
                'slot' => $meta['slot'],
                'running' => $running,
                'used_mb' => $usedMb,
                'memory_mb' => $memoryMb,
                'effective_memory_mb' => $effectiveMemoryMb,
                'cpu_percent' => $cpuPercent,
                'cputime' => $cputime,
                'maxproc' => $maxproc,
                'cpu_cap_percent' => $cpuCap,
                'effective_cpu_percent' => $effectiveCpuPercent,
                'disk_used_mb' => $zfsByApp[$app]['used_mb'],
                'disk_quota_mb' => $diskQuotaMb,
            ];
        }

        return $result;
    }

    public function apps(?string $incoming = null, ?array $jails = null): array {
        $apps = [];
        foreach ($jails ?? $this->jails() as $item) {
            if (!empty($item['running'])) {
                $apps[$item['app']] = true;
            }
        }
        foreach (glob($this->runtime->root . '/apps/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $state = $this->runtime->read($directory . '/state.json', []);
            if (!empty($state['active']) || is_file($directory . '/staged.json')) {
                $apps[basename($directory)] = true;
            }
        }
        if ($incoming !== null) {
            $apps[$incoming] = true;
        }
        $names = array_keys($apps);
        sort($names);
        return $names;
    }

    public static function plan(array $host, array $apps, array $policies): array {
        $memoryBudget = (int) ($host['memory_mb'] ?? 0)
            - (int) ($host['system_memory_mb'] ?? 0)
            - (int) ($host['database_memory_mb'] ?? 0)
            - (int) ($host['valkey_memory_mb'] ?? 0)
            - (int) ($host['build_memory_mb'] ?? 0);
        $overcommitRatio = (float) ($host['memory_overcommit_ratio'] ?? self::OVERCOMMIT_RATIO);
        if (!is_finite($overcommitRatio) || $overcommitRatio < 1.0) {
            $overcommitRatio = 1.0;
        }
        $effectiveMemoryBudget = (int) floor(max(0, $memoryBudget) * $overcommitRatio);
        $cpus = max(1, (int) ($host['cpus'] ?? 1));
        $cpuBudgetPercent = $cpus * 100;
        $maxCpuPercent = min(self::MAX_CPU_PERCENT, $cpuBudgetPercent);
        $poolTotalMb = (int) ($host['pool_total_mb'] ?? 0);

        $manual = [];
        foreach ($apps as $app) {
            if (($policies[$app]['mode'] ?? '') === 'manual') {
                $policy = self::policy($policies[$app]);
                if ($policy['memory_mb'] > $memoryBudget) {
                    throw new RuntimeException('Manual memory limit for ' . $app . ' exceeds physical application memory budget.');
                }
                if ($policy['cpu_percent'] > $maxCpuPercent) {
                    throw new RuntimeException('Manual CPU limit for ' . $app . ' exceeds host CPU capacity (' . $maxCpuPercent . '%).');
                }
                if ($poolTotalMb > 0 && $policy['disk_quota_mb'] > $poolTotalMb) {
                    throw new RuntimeException('Manual disk quota for ' . $app . ' exceeds ZFS pool capacity.');
                }
                $manual[$app] = $policy;
            }
        }

        $autoCount = count($apps) - count($manual);
        $standbyHeadroom = count($apps) > 0 ? self::DEFAULT_MEMORY : 0;
        $remainingMemory = $effectiveMemoryBudget - $standbyHeadroom - array_sum(array_column($manual, 'memory_mb'));
        if (count($apps) > 0 && ($memoryBudget < self::MIN_MEMORY || $remainingMemory < $autoCount * self::MIN_MEMORY)) {
            throw new RuntimeException('Not enough capacity for all applications. Reduce manual allocations, move builds to a dedicated server, or use a larger server.');
        }
        if (count($apps) > 0 && $poolTotalMb > 0 && $poolTotalMb < self::MIN_DISK_QUOTA_MB) {
            throw new RuntimeException('Not enough ZFS pool capacity for application disk quotas.');
        }

        $fairShare = $autoCount > 0 ? (int) floor($remainingMemory / $autoCount) : self::DEFAULT_MEMORY;
        $defaultMemory = max(self::MIN_MEMORY, min(self::MAX_AUTO_MEMORY, $fairShare));
        $defaultCpuPercent = min(self::DEFAULT_CPU_PERCENT, $maxCpuPercent);
        $defaultDiskQuota = $poolTotalMb > 0
            ? max(self::MIN_DISK_QUOTA_MB, min(self::DEFAULT_DISK_QUOTA_MB, $poolTotalMb))
            : self::DEFAULT_DISK_QUOTA_MB;

        $allocations = [];
        foreach ($apps as $app) {
            $allocations[$app] = $manual[$app] ?? [
                'mode' => 'automatic',
                'memory_mb' => $defaultMemory,
                'cpu_percent' => $defaultCpuPercent,
                'disk_quota_mb' => $defaultDiskQuota,
            ];
        }

        return [
            'memory_budget_mb' => $memoryBudget,
            'effective_memory_budget_mb' => $effectiveMemoryBudget,
            'overcommit_ratio' => $overcommitRatio,
            'cpu_budget_percent' => $cpuBudgetPercent,
            'allocations' => $allocations,
            'reserved_memory_mb' => array_sum(array_column($allocations, 'memory_mb')) + $standbyHeadroom,
            'standby_headroom_mb' => $standbyHeadroom,
        ];
    }

    public static function policy(array $request): array {
        $mode = $request['mode'] ?? null;
        if ($mode === 'automatic') {
            return ['mode' => $mode];
        }
        $memory = $request['memory_mb'] ?? null;
        $cpuPercent = $request['cpu_percent'] ?? null;
        $diskQuota = array_key_exists('disk_quota_mb', $request) ? $request['disk_quota_mb'] : self::DEFAULT_DISK_QUOTA_MB;
        if (
            $mode !== 'manual'
            || !is_int($memory) || $memory < self::MIN_MEMORY
            || !is_int($cpuPercent) || $cpuPercent < self::MIN_CPU_PERCENT || $cpuPercent > self::MAX_CPU_PERCENT
            || !is_int($diskQuota) || $diskQuota < self::MIN_DISK_QUOTA_MB
        ) {
            throw new RuntimeException(
                'Use automatic allocation, or at least ' . self::MIN_MEMORY . ' MiB RAM, '
                . self::MIN_CPU_PERCENT . '-' . self::MAX_CPU_PERCENT . '% CPU, and '
                . self::MIN_DISK_QUOTA_MB . ' MiB disk quota.'
            );
        }
        return [
            'mode' => $mode,
            'memory_mb' => $memory,
            'cpu_percent' => $cpuPercent,
            'disk_quota_mb' => $diskQuota,
        ];
    }

    public function snapshot(): array {
        $host = $this->host();
        $policies = $this->runtime->read($this->path, []);
        $jails = $this->jails();
        $apps = $this->apps(jails: $jails);

        $error = null;
        try {
            $plan = self::plan($host, $apps, $policies);
        } catch (RuntimeException $exception) {
            $memoryBudget = (int) ($host['memory_mb'] ?? 0)
                - (int) ($host['system_memory_mb'] ?? 0)
                - (int) ($host['database_memory_mb'] ?? 0)
                - (int) ($host['valkey_memory_mb'] ?? 0)
                - (int) ($host['build_memory_mb'] ?? 0);
            $overcommitRatio = (float) ($host['memory_overcommit_ratio'] ?? self::OVERCOMMIT_RATIO);
            if (!is_finite($overcommitRatio) || $overcommitRatio < 1.0) {
                $overcommitRatio = 1.0;
            }
            $plan = [
                'allocations' => [],
                'memory_budget_mb' => $memoryBudget,
                'effective_memory_budget_mb' => (int) floor(max(0, $memoryBudget) * $overcommitRatio),
                'overcommit_ratio' => $overcommitRatio,
                'cpu_budget_percent' => max(1, (int) ($host['cpus'] ?? 1)) * 100,
                'reserved_memory_mb' => 0,
                'standby_headroom_mb' => count($apps) > 0 ? self::DEFAULT_MEMORY : 0,
            ];
            $error = $exception->getMessage();
        }

        $pool = $host['zfs_pool'] ?? self::DEFAULT_ZFS_POOL;
        $poolTotalMb = (int) ($host['pool_total_mb'] ?? 0);
        $maxCpuPercent = min(self::MAX_CPU_PERCENT, $plan['cpu_budget_percent']);
        $standbyHeadroom = $plan['standby_headroom_mb'];
        $rows = [];

        foreach ($apps as $app) {
            $appJails = array_values(array_filter($jails, fn(array $item): bool => $item['app'] === $app));
            $running = array_values(array_filter($appJails, fn(array $item): bool => !empty($item['running'])));

            $otherMemory = 0;
            foreach ($apps as $other) {
                if ($other !== $app) {
                    $manual = ($policies[$other]['mode'] ?? '') === 'manual';
                    $otherMemory += $manual ? (int) $policies[$other]['memory_mb'] : self::MIN_MEMORY;
                }
            }

            $state = $this->runtime->read($this->runtime->root . '/apps/' . $app . '/state.json', []);
            $slot = $state['active']['slot'] ?? ($running[0]['slot'] ?? ($appJails[0]['slot'] ?? null));

            $cpuSamples = array_values(array_filter(
                array_column($running, 'cpu_percent'),
                static fn(mixed $value): bool => $value !== null
            ));
            $cpuPercent = $cpuSamples !== [] ? round((float) array_sum($cpuSamples), 1) : null;

            if ($appJails !== []) {
                $diskUsedMb = (int) $appJails[0]['disk_used_mb'];
                $diskQuotaMb = (int) $appJails[0]['disk_quota_mb'];
            } else {
                $zfs = $this->dataset_usage($pool . '/tc/apps/' . $app);
                $diskUsedMb = $zfs['used_mb'];
                $diskQuotaMb = $zfs['quota_mb'] > 0
                    ? $zfs['quota_mb']
                    : (int) ($plan['allocations'][$app]['disk_quota_mb'] ?? self::DEFAULT_DISK_QUOTA_MB);
            }

            $maxMemoryMb = min(
                max(0, $plan['memory_budget_mb']),
                max(0, $plan['effective_memory_budget_mb'] - $standbyHeadroom - $otherMemory)
            );

            $rows[] = [
                'app' => $app,
                'mode' => $policies[$app]['mode'] ?? 'automatic',
                'allocation' => $plan['allocations'][$app] ?? null,
                'versions' => count($running),
                'slot' => $slot,
                'used_mb' => array_sum(array_column($running, 'used_mb')),
                'cpu_percent' => $cpuPercent,
                'disk_used_mb' => $diskUsedMb,
                'limits_mb' => array_map(
                    static fn(array $item): int => (int) ($item['effective_memory_mb'] ?? $item['memory_mb']),
                    $running
                ),
                'cpu_caps' => array_map(
                    static fn(array $item): int => (int) ($item['effective_cpu_percent'] ?? $item['cpu_cap_percent']),
                    $running
                ),
                'disk_quota_mb' => $diskQuotaMb,
                'max_memory_mb' => $maxMemoryMb,
                'max_cpu_percent' => $maxCpuPercent,
                'max_disk_quota_mb' => $poolTotalMb > 0 ? $poolTotalMb : 0,
            ];
        }

        return [
            'status' => 'succeeded',
            'host' => $host,
            'apps' => $rows,
            'error' => $error,
            'pending_recovery' => is_file($this->pending),
        ] + $plan;
    }

    public function update(string $jail, string $app, int $memoryMb, int $cpuPercent, int $diskQuotaMb): void {
        $jail = Runtime::match($jail, '[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}');
        $app = Runtime::match($app, '[a-z][a-z0-9-]{0,39}');
        if ($memoryMb < self::MIN_MEMORY || $cpuPercent < self::MIN_CPU_PERCENT || $cpuPercent > self::MAX_CPU_PERCENT || $diskQuotaMb < self::MIN_DISK_QUOTA_MB) {
            throw new RuntimeException('Requested resource limits are outside supported bounds.');
        }

        $rules = [
            'jail:' . $jail . ':memoryuse:deny=' . $memoryMb . 'M',
            'jail:' . $jail . ':swapuse:deny=' . $memoryMb . 'M',
            'jail:' . $jail . ':pcpu:deny=' . $cpuPercent,
            'jail:' . $jail . ':maxproc:deny=' . self::DEFAULT_MAXPROC,
            'jail:' . $jail . ':openfiles:deny=' . self::DEFAULT_OPENFILES,
        ];
        foreach ($rules as $rule) {
            $this->runtime->run(['rctl', '-a', $rule]);
        }

        $pool = $this->zfs_pool();
        $this->runtime->run(['zfs', 'set', 'quota=' . $diskQuotaMb . 'M', $pool . '/tc/apps/' . $app]);

        $observed = $this->rctl_limits($jail);
        $observedMemoryMb = isset($observed['memoryuse']) ? intdiv($observed['memoryuse'], self::MIB) : null;
        $observedCpu = $observed['pcpu'] ?? null;
        $observedSwapMb = isset($observed['swapuse']) ? intdiv($observed['swapuse'], self::MIB) : null;
        $observedMaxproc = $observed['maxproc'] ?? null;
        $observedOpenfiles = $observed['openfiles'] ?? null;
        if (
            $observedMemoryMb !== $memoryMb
            || $observedCpu !== $cpuPercent
            || $observedSwapMb !== $memoryMb
            || $observedMaxproc !== self::DEFAULT_MAXPROC
            || $observedOpenfiles !== self::DEFAULT_OPENFILES
        ) {
            throw new RuntimeException('Kernel rctl limits differ from the requested allocation. Resource recovery is required.');
        }
    }

    protected function find_jail(string $name): array {
        foreach ($this->jails() as $item) {
            if ($item['jail'] === $name) {
                return $item;
            }
        }
        throw new RuntimeException('Managed jail disappeared during resource adjustment.');
    }

    private static function safe_shrink_floor(int $usedMb): int {
        return (int) ceil($usedMb * 1.25) + self::SHRINK_HEADROOM_MB;
    }

    public function recover(): void {
        $pending = $this->runtime->read($this->pending);
        if (!$pending) {
            return;
        }
        $before = $pending['before'] ?? [];
        usort($before, static fn(array $a, array $b): int => $a['memory_mb'] <=> $b['memory_mb']);
        foreach ($before as $item) {
            $current = $this->find_jail($item['jail']);
            if (
                $current['running']
                && $item['memory_mb'] < $current['memory_mb']
                && $item['memory_mb'] < self::safe_shrink_floor((int) $current['used_mb'])
            ) {
                throw new RuntimeException('Resource recovery needs attention: current usage is too high to safely restore the previous limit. No further allocations can be applied until usage falls.');
            }
            $this->update(
                $item['jail'],
                $item['app'],
                (int) $item['memory_mb'],
                (int) $item['cpu_cap_percent'],
                (int) ($item['disk_quota_mb'] ?: self::DEFAULT_DISK_QUOTA_MB)
            );
        }
        $this->runtime->write($this->path, $pending['policies'] ?? []);
        unlink($this->pending);
    }

    public function apply(?string $incoming = null, ?array $change = null, bool $starting = false): ?array {
        $this->recover();
        $host = $this->host();
        $old = $this->runtime->read($this->path, []);
        $policies = $old;
        $observed = $this->jails();
        if ($change !== null) {
            if (!in_array($change['app'] ?? '', $this->apps(jails: $observed), true)) {
                throw new RuntimeException('Deploy this app before adjusting its resources.');
            }
            $policies[$change['app']] = self::policy($change);
        }
        $apps = $this->apps($incoming, $observed);
        $plan = self::plan($host, $apps, $policies);
        $jails = array_values(array_filter($observed, static fn(array $item): bool => in_array($item['app'], $apps, true)));

        foreach ($apps as $app) {
            $running = array_filter($jails, static fn(array $item): bool => $item['app'] === $app && !empty($item['running']));
            if (count($running) + (int) ($starting && $incoming === $app) > 2) {
                throw new RuntimeException('Two slots of ' . $app . ' are already running. Finish or reconcile its deployment first.');
            }
        }

        $growth = 0;
        $before = [];
        foreach ($jails as $item) {
            $target = $plan['allocations'][$item['app']];
            if ($target['mode'] === 'automatic' && $item['disk_quota_mb'] > $target['disk_quota_mb']) {
                $target['disk_quota_mb'] = (int) $item['disk_quota_mb'];
                $plan['allocations'][$item['app']]['disk_quota_mb'] = $target['disk_quota_mb'];
            }
            if (
                !empty($item['running'])
                && $target['memory_mb'] < $item['memory_mb']
                && $target['memory_mb'] < self::safe_shrink_floor((int) $item['used_mb'])
            ) {
                throw new RuntimeException('Cannot safely shrink ' . $item['app'] . ': it uses ' . $item['used_mb'] . ' MiB. Leave 25% plus ' . self::SHRINK_HEADROOM_MB . ' MiB headroom, reduce usage, or add capacity.');
            }
            if ($target['disk_quota_mb'] < $item['disk_quota_mb'] && $target['disk_quota_mb'] < (int) $item['disk_used_mb'] + self::SHRINK_HEADROOM_MB) {
                throw new RuntimeException('Cannot safely shrink disk quota for ' . $item['app'] . ': dataset uses ' . $item['disk_used_mb'] . ' MiB.');
            }
            if (!empty($item['running']) && $target['memory_mb'] > $item['memory_mb']) {
                $growth += $target['memory_mb'] - $item['memory_mb'];
            }
            if (
                $item['memory_mb'] !== $target['memory_mb']
                || $item['cpu_cap_percent'] !== $target['cpu_percent']
                || $item['disk_quota_mb'] !== $target['disk_quota_mb']
            ) {
                $before[] = $item;
            }
        }
        if ($starting && $incoming !== null) {
            $growth += $plan['allocations'][$incoming]['memory_mb'];
        }
        if ($growth + self::AVAILABLE_HEADROOM_MB > (int) $host['available_mb']) {
            throw new RuntimeException('Available memory is too low to safely apply these limits. Wait for other workloads to finish or add capacity.');
        }

        $this->runtime->write($this->pending, ['before' => $before, 'policies' => $old]);
        try {
            usort(
                $before,
                static fn(array $a, array $b): int => ($plan['allocations'][$a['app']]['memory_mb'] - $a['memory_mb'])
                    <=> ($plan['allocations'][$b['app']]['memory_mb'] - $b['memory_mb'])
            );
            foreach ($before as $item) {
                $target = $plan['allocations'][$item['app']];
                if (!empty($item['running']) && $target['memory_mb'] < $item['memory_mb']) {
                    $current = $this->find_jail($item['jail']);
                    if (!empty($current['running']) && $target['memory_mb'] < self::safe_shrink_floor((int) $current['used_mb'])) {
                        throw new RuntimeException('Memory usage increased during adjustment. Previous limits were restored; try again after usage falls.');
                    }
                }
                $this->update(
                    $item['jail'],
                    $item['app'],
                    $target['memory_mb'],
                    $target['cpu_percent'],
                    $target['disk_quota_mb']
                );
            }
            $this->runtime->write($this->path, $policies);
            unlink($this->pending);
        } catch (Throwable $error) {
            $this->recover();
            throw $error;
        }

        return $incoming !== null ? ($plan['allocations'][$incoming] ?? null) : $plan;
    }
}
