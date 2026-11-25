<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/runtime.php';
require_once dirname(__DIR__, 2) . '/resources.php';
require_once dirname(__DIR__, 2) . '/metrics.php';

class Mock_freebsd_runtime extends Runtime {
    public array $sysctls = [];
    public array $jls = [];
    public array $rctl_usage_map = [];
    public array $rctl_limits_map = [];
    public array $zfs_datasets = [];
    public array $calls = [];
    public array $cp_samples = [];
    public ?string $fail_rctl_jail = null;
    public bool $corrupt_rctl_verify = false;

    public function run(array $args, int $timeout = 120): string {
        $this->calls[] = $args;

        if (array_slice($args, 0, 2) === ['sysctl', '-n']) {
            $key = $args[2];
            if ($key === 'kern.cp_time' && $this->cp_samples !== []) {
                return array_shift($this->cp_samples);
            }
            if (!array_key_exists($key, $this->sysctls)) {
                throw new RuntimeException('Unknown sysctl: ' . $key);
            }
            return (string) $this->sysctls[$key];
        }

        if ($args === ['jls', '-N', '--libxo', 'json']) {
            return json_encode(['jail-information' => ['jail' => array_values($this->jls)]], JSON_THROW_ON_ERROR);
        }

        if (array_slice($args, 0, 2) === ['rctl', '-u']) {
            return $this->rctl_usage_map[substr($args[2], strlen('jail:'))] ?? '';
        }

        if (count($args) === 2 && $args[0] === 'rctl' && str_starts_with($args[1], 'jail:')) {
            return $this->rctl_limits_map[substr($args[1], strlen('jail:'))] ?? '';
        }

        if (array_slice($args, 0, 2) === ['rctl', '-a']) {
            if (preg_match('/^jail:([^:]+):([a-z0-9_]+):deny=(.+)$/', $args[2], $m) === 1) {
                $jail = $m[1];
                if ($this->fail_rctl_jail === $jail) {
                    $this->fail_rctl_jail = null;
                    throw new RuntimeException('simulated rctl failure');
                }
                $lines = array_filter(explode("\n", trim($this->rctl_limits_map[$jail] ?? '')));
                $prefix = 'jail:' . $jail . ':' . $m[2] . ':deny=';
                $lines = array_values(array_filter($lines, fn(string $l) => !str_starts_with($l, $prefix)));
                $value = ($this->corrupt_rctl_verify && $m[2] === 'memoryuse') ? '999M' : $m[3];
                $lines[] = $prefix . $value;
                $this->rctl_limits_map[$jail] = implode("\n", $lines) . "\n";
            }
            return '';
        }

        if (array_slice($args, 0, 5) === ['zfs', 'list', '-H', '-o', 'name']) {
            if (!isset($this->zfs_datasets[$args[5]])) {
                throw new RuntimeException('dataset not found');
            }
            return $args[5];
        }

        if (array_slice($args, 0, 4) === ['zfs', 'list', '-Hp', '-o']) {
            $cols = $args[4];
            $dataset = $args[5];
            if ($cols === 'used,avail') {
                $info = $this->zfs_datasets[$dataset] ?? ['used' => 0, 'avail' => 0];
                return $info['used'] . "\t" . $info['avail'];
            }
            if ($cols === 'used,quota') {
                if (!isset($this->zfs_datasets[$dataset])) {
                    throw new RuntimeException('dataset not found');
                }
                $info = $this->zfs_datasets[$dataset];
                return $info['used'] . "\t" . ($info['quota'] ?? 0);
            }
        }

        if (array_slice($args, 0, 2) === ['zfs', 'set']) {
            if (preg_match('/^quota=(\d+)M$/', $args[2], $m) === 1) {
                $this->zfs_datasets[$args[3]]['quota'] = (int) $m[1] * Resources::MIB;
            }
            return '';
        }

        return '';
    }
}

function make_32gb_runtime(string $root): Mock_freebsd_runtime {
    $rt = new Mock_freebsd_runtime($root);
    $gib = 1024 * Resources::MIB;
    $rt->sysctls = [
        'hw.physmem' => 32 * $gib,
        'hw.pagesize' => 4096,
        'vm.stats.vm.v_free_count' => intdiv(4 * $gib, 4096),
        'vm.stats.vm.v_inactive_count' => intdiv(4 * $gib, 4096),
        'vm.stats.vm.v_laundry_count' => intdiv(2 * $gib, 4096),
        'kstat.zfs.misc.arcstats.size' => 12 * $gib,
        'kstat.zfs.misc.arcstats.c_min' => 2 * $gib,
        'hw.ncpu' => 8,
        'vm.loadavg' => '{ 0.42 0.35 0.28 }',
    ];
    $rt->zfs_datasets['zroot'] = ['used' => 100 * $gib, 'avail' => 900 * $gib];
    $rt->write($root . '/etc-resources.json', [
        'system_memory_mb' => 2048,
        'database_memory_mb' => 4096,
        'valkey_memory_mb' => 512,
        'build_memory_mb' => 1536,
        'memory_overcommit_ratio' => 3.0,
        'zfs_pool' => 'zroot',
    ]);
    return $rt;
}

function register_jail(
    Mock_freebsd_runtime $rt,
    string $app,
    string $slot = 'blue',
    int $memoryMb = 256,
    int $usedMb = 40,
    int $cpuPercent = 100,
    int $diskUsedMb = 50,
    int $diskQuotaMb = 1024,
    bool $running = true
): string {
    $jail = 'tc_' . str_replace('-', '_', $app) . '_' . $slot;
    $entry = [
        'operation' => substr(hash('sha256', $app . ':' . $slot), 0, 32),
        'jail' => $jail,
        'slot' => $slot,
        'memory_mb' => $memoryMb,
        'cpu_percent' => $cpuPercent,
        'disk_quota_mb' => $diskQuotaMb,
    ];
    $statePath = $rt->root . '/apps/' . $app . '/state.json';
    $state = $rt->read($statePath, ['active' => null, 'previous' => null]);
    if ($state['active'] === null) {
        $state['active'] = $entry;
    } else {
        $state['previous'] = $entry;
    }
    $rt->write($statePath, $state);
    if ($running) {
        $rt->jls[$jail] = ['jid' => count($rt->jls) + 1, 'name' => $jail];
    }
    $rt->rctl_usage_map[$jail] = "memoryuse=" . ($usedMb * Resources::MIB) . "\npcpu=12\ncputime=100\nmaxproc=4\n";
    $rt->rctl_limits_map[$jail] = implode("\n", [
        "jail:{$jail}:memoryuse:deny={$memoryMb}M",
        "jail:{$jail}:swapuse:deny={$memoryMb}M",
        "jail:{$jail}:pcpu:deny={$cpuPercent}",
        "jail:{$jail}:maxproc:deny=64",
        "jail:{$jail}:openfiles:deny=1024",
    ]) . "\n";
    $rt->zfs_datasets['zroot/tc/apps/' . $app] = [
        'used' => $diskUsedMb * Resources::MIB,
        'quota' => $diskQuotaMb * Resources::MIB,
    ];
    return $jail;
}

function with_resources_fixture(callable $body): void {
    $root = sys_get_temp_dir() . '/tc-resources-' . bin2hex(random_bytes(6));
    $rt = make_32gb_runtime($root);
    $resources = new Resources($rt);
    $resources->config_path = $root . '/etc-resources.json';
    try {
        $body($rt, $resources);
    } finally {
        Runtime::remove_tree($root);
    }
}
