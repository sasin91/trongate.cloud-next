<?php
declare(strict_types=1);

/** Host-authoritative budgets. All mutations run under the runtime operation lock. */
class Resources {
    public const MIB = 1048576;
    public const MIN_MEMORY = 256;
    public const MIN_CPU = 0.05;
    public string $path;
    public string $pending;

    public function __construct(public Runtime $runtime) {
        $this->path = $runtime->root . '/resources.json';
        $this->pending = $runtime->root . '/resources-pending.json';
    }

    public static function cpu_count(): int {
        $stat = file_get_contents('/proc/stat');
        preg_match_all('/^cpu[0-9]+\s/m', $stat, $matches);
        return max(1, count($matches[0]));
    }

    public function host(): array {
        $config = $this->runtime->read('/etc/tc-resources.json');
        if ($config === null) {
            throw new RuntimeException('Upgrade this server runtime before allocating application resources.');
        }
        preg_match_all('/^(\w+):\s+(\d+)/m', file_get_contents('/proc/meminfo'), $matches, PREG_SET_ORDER);
        $memory = [];
        foreach ($matches as $match) {
            $memory[$match[1]] = intdiv((int) $match[2], 1024);
        }
        return array_merge(['memory_mb' => $memory['MemTotal'], 'available_mb' => $memory['MemAvailable'], 'cpus' => self::cpu_count()], $config);
    }

    public function containers(): array {
        $result = [];
        $present = explode("\n", $this->runtime->run(['podman', 'ps', '--all', '--format', '{{.Names}}']));
        foreach (glob($this->runtime->root . '/apps/*/releases/*', GLOB_ONLYDIR) as $directory) {
            $app = basename(dirname($directory, 2));
            $release = $this->runtime->read($directory . '/release.json', []);
            $name = $release['container'] ?? 'tc-' . $app . '-' . basename($directory);
            $this->runtime->match($name, 'tc-[a-z][a-z0-9-]{0,39}-[a-f0-9]{32}');
            if (!in_array($name, $present, true)) {
                continue;
            }
            $info = json_decode($this->runtime->run(['podman', 'inspect', $name]), true, 512, JSON_THROW_ON_ERROR)[0];
            $running = $info['State']['Running'];
            $used = 0;
            $cpuUsec = $effectiveMemory = $effectiveQuota = null;
            if ($running) {
                $group = realpath('/sys/fs/cgroup/' . ltrim($info['State']['CgroupPath'], '/'));
                if ($group === false || !str_starts_with($group . '/', '/sys/fs/cgroup/')) {
                    throw new RuntimeException('Could not verify container resource usage.');
                }
                $used = (int) ceil((int) file_get_contents($group . '/memory.current') / self::MIB);
                if (!preg_match('/^usage_usec\s+(\d+)$/m', file_get_contents($group . '/cpu.stat'), $match)) {
                    throw new RuntimeException('Could not verify container CPU usage.');
                }
                $cpuUsec = (int) $match[1];
                $memoryMax = trim(file_get_contents($group . '/memory.max'));
                $effectiveMemory = $memoryMax === 'max' ? 0 : intdiv((int) $memoryMax, self::MIB);
                [$quota, $period] = preg_split('/\s+/', trim(file_get_contents($group . '/cpu.max')));
                $effectiveQuota = $quota === 'max' ? 0 : (int) $quota / (int) $period;
            }
            $limits = $info['HostConfig'];
            $result[] = ['app' => $app, 'container' => $name, 'running' => $running,
                'used_mb' => $used, 'memory_mb' => intdiv($limits['Memory'], self::MIB),
                'cpu_usec' => $cpuUsec, 'sample_ns' => hrtime(true),
                'effective_memory_mb' => $effectiveMemory, 'effective_quota' => $effectiveQuota,
                'shares' => $limits['CpuShares'], 'quota' => max(0, $limits['CpuQuota']) / ($limits['CpuPeriod'] ?: 100000)];
        }
        return $result;
    }

    public function apps(?string $incoming = null, ?array $containers = null): array {
        $apps = [];
        foreach ($containers ?? $this->containers() as $item) {
            if ($item['running']) {
                $apps[$item['app']] = true;
            }
        }
        foreach (glob($this->runtime->root . '/apps/*', GLOB_ONLYDIR) as $directory) {
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
        $memory = $host['memory_mb'] - $host['system_memory_mb'] - $host['database_memory_mb'] - $host['build_memory_mb'];
        $cpu = round($host['cpus'] - $host['system_cpus'] - $host['database_cpus'] - $host['build_cpus'], 2);
        $manual = [];
        foreach ($apps as $app) {
            if (($policies[$app]['mode'] ?? '') === 'manual') {
                $manual[$app] = $policies[$app];
            }
        }
        $count = count($apps) - count($manual);
        $remainingMemory = $memory - 2 * array_sum(array_column($manual, 'memory_mb'));
        $remainingCpu = round($cpu - 2 * array_sum(array_column($manual, 'cpus')), 2);
        if ($remainingMemory < 2 * $count * self::MIN_MEMORY || $remainingCpu < 2 * $count * self::MIN_CPU) {
            throw new RuntimeException('Not enough capacity for two versions of every app. Reduce manual allocations, move builds to a dedicated server, or use a larger server.');
        }
        $defaultMemory = (int) floor($remainingMemory / (2 * max(1, $count)));
        $defaultCpu = floor(($remainingCpu + 1e-8) * 100 / (2 * max(1, $count))) / 100;
        $allocations = [];
        foreach ($apps as $app) {
            $allocations[$app] = $manual[$app] ?? ['mode' => 'automatic', 'memory_mb' => $defaultMemory, 'cpus' => $defaultCpu];
        }
        return ['memory_budget_mb' => $memory, 'cpu_budget' => $cpu, 'allocations' => $allocations,
            'reserved_memory_mb' => 2 * array_sum(array_column($allocations, 'memory_mb')),
            'reserved_cpus' => round(2 * array_sum(array_column($allocations, 'cpus')), 2)];
    }

    public static function policy(array $request): array {
        $mode = $request['mode'] ?? null;
        if ($mode === 'automatic') {
            return ['mode' => $mode];
        }
        $memory = $request['memory_mb'] ?? null;
        $cpu = $request['cpus'] ?? null;
        if ($mode !== 'manual' || !is_int($memory) || $memory < self::MIN_MEMORY ||
            !(is_int($cpu) || is_float($cpu)) || !is_finite((float) $cpu) || $cpu < self::MIN_CPU || round($cpu, 2) != $cpu) {
            throw new RuntimeException('Use automatic allocation, or at least 256 MiB and 0.05 CPU shares (two decimal places).');
        }
        return ['mode' => $mode, 'memory_mb' => $memory, 'cpus' => $cpu];
    }

    public function snapshot(): array {
        $host = $this->host();
        $policies = $this->runtime->read($this->path, []);
        $containers = $this->containers();
        $apps = $this->apps(containers: $containers);
        $cpuUsage = [];
        if (array_filter($containers, fn(array $item): bool => isset($item['cpu_usec']))) {
            usleep(200000);
            $second = array_column($this->containers(), null, 'container');
            foreach ($containers as $item) {
                $after = $second[$item['container']] ?? [];
                if (isset($item['cpu_usec'], $after['cpu_usec']) && $after['sample_ns'] > $item['sample_ns']) {
                    $percent = max(0, ($after['cpu_usec'] - $item['cpu_usec']) * 100000 / ($after['sample_ns'] - $item['sample_ns']));
                    $cpuUsage[$item['app']] = ($cpuUsage[$item['app']] ?? 0) + $percent;
                }
            }
        }
        $error = null;
        try {
            $plan = self::plan($host, $apps, $policies);
        } catch (RuntimeException $exception) {
            $plan = ['allocations' => [],
                'memory_budget_mb' => $host['memory_mb'] - $host['system_memory_mb'] - $host['database_memory_mb'] - $host['build_memory_mb'],
                'cpu_budget' => round($host['cpus'] - $host['system_cpus'] - $host['database_cpus'] - $host['build_cpus'], 2)];
            $error = $exception->getMessage();
        }
        $rows = [];
        foreach ($apps as $app) {
            $running = array_values(array_filter($containers, fn(array $item): bool => $item['app'] === $app && $item['running']));
            $otherMemory = 0;
            $otherCpu = 0;
            foreach ($apps as $other) {
                if ($other !== $app) {
                    $manual = ($policies[$other]['mode'] ?? '') === 'manual';
                    $otherMemory += $manual ? $policies[$other]['memory_mb'] : self::MIN_MEMORY;
                    $otherCpu += $manual ? $policies[$other]['cpus'] : self::MIN_CPU;
                }
            }
            $rows[] = ['app' => $app, 'mode' => $policies[$app]['mode'] ?? 'automatic',
                'allocation' => $plan['allocations'][$app] ?? null, 'versions' => count($running),
                'used_mb' => array_sum(array_column($running, 'used_mb')),
                'cpu_percent' => isset($cpuUsage[$app]) ? round($cpuUsage[$app], 1) : null,
                'limits_mb' => array_map(fn(array $item) => array_key_exists('effective_memory_mb', $item) ? $item['effective_memory_mb'] : $item['memory_mb'], $running),
                'cpu_shares' => array_map(fn(array $item) => round(($item['shares'] ?: 1024) / 1024, 2), $running),
                'cpu_caps' => array_map(fn(array $item) => $item['quota'] > 0 && $item['quota'] < $host['cpus'] ? $item['quota'] : 0, $running),
                'max_memory_mb' => max(0, (int) floor($plan['memory_budget_mb'] / 2) - $otherMemory),
                'max_cpus' => max(0, floor(($plan['cpu_budget'] / 2 - $otherCpu + 1e-8) * 100) / 100)];
        }
        return ['status' => 'succeeded', 'host' => $host, 'apps' => $rows, 'error' => $error,
            'pending_recovery' => is_file($this->pending)] + $plan;
    }

    public function update(string $container, int $memory, int $shares, float|int $quota = 0): void {
        // A whole-host quota resets an old CPU cap without limiting bursting.
        $quota = $quota ?: self::cpu_count();
        $shares = max(2, $shares ?: 1024);
        $this->runtime->run(['podman', 'update', '--memory', $memory . 'm', '--memory-swap', ($memory * 2) . 'm',
            '--cpus', (string) $quota, '--cpu-shares', (string) $shares, $container]);
        $observed = $this->find_container($container);
        if (!$observed['running']) {
            return;
        }
        if ($observed['memory_mb'] !== $memory || $observed['shares'] !== $shares || $observed['quota'] != $quota) {
            throw new RuntimeException('The container runtime did not apply the requested limits. Resource recovery is required.');
        }
        if ($observed['effective_memory_mb'] !== $memory || $observed['effective_quota'] != $quota) {
            throw new RuntimeException('Kernel limits differ from the requested allocation. Resource recovery is required.');
        }
    }

    protected function find_container(string $name): array {
        foreach ($this->containers() as $item) {
            if ($item['container'] === $name) {
                return $item;
            }
        }
        throw new RuntimeException('Managed container disappeared during resource adjustment.');
    }

    public function recover(): void {
        $pending = $this->runtime->read($this->pending);
        if (!$pending) {
            return;
        }
        usort($pending['before'], fn(array $a, array $b): int => $a['memory_mb'] <=> $b['memory_mb']);
        foreach ($pending['before'] as $item) {
            $current = $this->find_container($item['container']);
            if ($current['running'] && $item['memory_mb'] < $current['memory_mb'] && $item['memory_mb'] < ceil($current['used_mb'] * 1.25) + 32) {
                throw new RuntimeException('Resource recovery needs attention: current usage is too high to safely restore the previous limit. No further allocations can be applied until usage falls.');
            }
            $this->update($item['container'], $item['memory_mb'], $item['shares'], $item['quota']);
        }
        $this->runtime->write($this->path, $pending['policies']);
        unlink($this->pending);
    }

    public function apply(?string $incoming = null, ?array $change = null, bool $starting = false): ?array {
        $this->recover();
        $host = $this->host();
        $old = $this->runtime->read($this->path, []);
        $policies = $old;
        if ($change !== null) {
            if (!in_array($change['app'], $this->apps(), true)) {
                throw new RuntimeException('Deploy this app before adjusting its resources.');
            }
            $policies[$change['app']] = self::policy($change);
        }
        $observed = $this->containers();
        $apps = $this->apps($incoming, $observed);
        $plan = self::plan($host, $apps, $policies);
        $containers = array_values(array_filter($observed, fn(array $item): bool => in_array($item['app'], $apps, true)));
        foreach ($apps as $app) {
            $running = array_filter($containers, fn(array $item): bool => $item['app'] === $app && $item['running']);
            if (count($running) + (int) ($starting && $incoming === $app) > 2) {
                throw new RuntimeException('Two versions of ' . $app . ' are already running. Finish or reconcile its deployment first.');
            }
        }
        $growth = 0;
        $before = [];
        foreach ($containers as $item) {
            $target = $plan['allocations'][$item['app']];
            if ($item['running'] && $target['memory_mb'] < $item['memory_mb'] && $target['memory_mb'] < ceil($item['used_mb'] * 1.25) + 32) {
                throw new RuntimeException('Cannot safely shrink ' . $item['app'] . ': it uses ' . $item['used_mb'] . ' MiB. Leave 25% plus 32 MiB headroom, reduce usage, or add capacity.');
            }
            if ($item['running']) {
                $growth += max(0, $target['memory_mb'] - $item['used_mb']);
            }
            if ($item['memory_mb'] !== $target['memory_mb'] || $item['shares'] !== max(2, (int) round($target['cpus'] * 1024)) || ($item['quota'] > 0 && $item['quota'] < $host['cpus'])) {
                $before[] = $item;
            }
        }
        if ($starting) {
            $growth += $plan['allocations'][$incoming]['memory_mb'];
        }
        if ($growth + 128 > $host['available_mb']) {
            throw new RuntimeException('Available memory is too low to safely apply these limits. Wait for other workloads to finish or add capacity.');
        }
        $this->runtime->write($this->pending, ['before' => $before, 'policies' => $old]);
        try {
            usort($before, fn(array $a, array $b): int => ($plan['allocations'][$a['app']]['memory_mb'] - $a['memory_mb']) <=> ($plan['allocations'][$b['app']]['memory_mb'] - $b['memory_mb']));
            foreach ($before as $item) {
                $target = $plan['allocations'][$item['app']];
                if ($item['running'] && $target['memory_mb'] < $item['memory_mb']) {
                    $current = $this->find_container($item['container']);
                    if ($target['memory_mb'] < ceil($current['used_mb'] * 1.25) + 32) {
                        throw new RuntimeException('Memory usage increased during adjustment. Previous limits were restored; try again after usage falls.');
                    }
                }
                $this->update($item['container'], $target['memory_mb'], (int) round($target['cpus'] * 1024));
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
