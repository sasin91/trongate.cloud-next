--TEST--
Resources ARC-aware budgeting, 32 GiB overcommit, rctl/ZFS updates, shrink guards, recovery, and Metrics
--FILE--
<?php
require __DIR__ . '/inc/mock_freebsd_runtime.php';

// 1. ARC-aware host accounting counts reclaimable ZFS ARC in available memory.
with_resources_fixture(function (Mock_freebsd_runtime $rt, Resources $res) {
    $host = $res->host();
    echo "host=", implode('|', [$host['memory_mb'], $host['arc_reclaimable_mb'], $host['available_mb'], $host['cpus'], $host['zfs_pool'], $host['pool_total_mb'], $host['pool_free_mb']]), "\n";

    // 2. 32 GiB host with 3x overcommit supports 100 to 250+ apps.
    $plan100 = Resources::plan($host, array_map(fn(int $i) => 'app-' . $i, range(1, 100)), []);
    $plan250 = Resources::plan($host, array_map(fn(int $i) => 'app-' . $i, range(1, 250)), []);
    echo "plan100=", $plan100['memory_budget_mb'], '|', $plan100['effective_memory_budget_mb'], '|', $plan100['allocations']['app-1']['memory_mb'], "\n";
    echo "plan250=", $plan250['allocations']['app-1']['memory_mb'], '|', $plan250['allocations']['app-1']['cpu_percent'], '|', $plan250['allocations']['app-1']['disk_quota_mb'], "\n";
});

// 3. Manual allocation updates live rctl + ZFS, excludes platform jails, rejects bad bounds, verifies drift.
with_resources_fixture(function (Mock_freebsd_runtime $rt, Resources $res) {
    $rt->jls['tc_mariadb'] = ['jid' => 1, 'name' => 'tc_mariadb'];
    $rt->jls['tc_valkey'] = ['jid' => 2, 'name' => 'tc_valkey'];
    $rt->jls['tc_panel'] = ['jid' => 3, 'name' => 'tc_panel'];
    $rt->jls['tc_build_abcdef01'] = ['jid' => 4, 'name' => 'tc_build_abcdef01'];

    register_jail($rt, 'one', 'blue', 256, 40, 100, 80, 1024, true);
    register_jail($rt, 'two', 'blue', 256, 40, 100, 80, 1024, true);

    $res->apply(change: ['app' => 'one', 'mode' => 'manual', 'memory_mb' => 1024, 'cpu_percent' => 200, 'disk_quota_mb' => 2048]);
    $snap = $res->snapshot();
    echo "manual=", $snap['status'], '|apps=', count($snap['apps']), '|mode=', $snap['apps'][0]['mode'],
        '|mem=', implode(',', $snap['apps'][0]['limits_mb']),
        '|cpu=', implode(',', $snap['apps'][0]['cpu_caps']),
        '|disk=', $snap['apps'][0]['disk_quota_mb'], '|slot=', $snap['apps'][0]['slot'], "\n";

    $before = count($rt->calls);
    foreach ([
        ['app' => 'one', 'mode' => 'manual', 'memory_mb' => 32, 'cpu_percent' => 100, 'disk_quota_mb' => 1024],
        ['app' => 'one', 'mode' => 'manual', 'memory_mb' => 256, 'cpu_percent' => 5, 'disk_quota_mb' => 1024],
        ['app' => 'one', 'mode' => 'manual', 'memory_mb' => 256, 'cpu_percent' => 1700, 'disk_quota_mb' => 1024],
        ['app' => 'one', 'mode' => 'manual', 'memory_mb' => 256, 'cpu_percent' => 100, 'disk_quota_mb' => 64],
    ] as $invalid) {
        try {
            $res->apply(change: $invalid);
            echo "FAIL invalid bound accepted\n";
        } catch (RuntimeException) {
        }
    }
    $rctlAfter = array_filter(array_slice($rt->calls, $before), fn(array $c) => $c[0] === 'rctl' && $c[1] === '-a');
    echo "invalid_rctl_calls=", count($rctlAfter), "\n";

    $rt->corrupt_rctl_verify = true;
    try {
        $res->update('tc_one_blue', 'one', 512, 100, 1024);
    } catch (RuntimeException $e) {
        echo "drift=", (str_contains($e->getMessage(), 'Kernel rctl limits differ') ? 'caught' : 'missed'), "\n";
    }
});

// 4. Shrink guard protects 25% + 16 MiB RAM headroom and disk usage.
with_resources_fixture(function (Mock_freebsd_runtime $rt, Resources $res) {
    register_jail($rt, 'busy', 'blue', 1024, 500, 100, 900, 2048, true);
    try {
        $res->apply(change: ['app' => 'busy', 'mode' => 'manual', 'memory_mb' => 512, 'cpu_percent' => 100, 'disk_quota_mb' => 2048]);
    } catch (RuntimeException $e) {
        echo "ram_shrink=", (str_contains($e->getMessage(), 'Cannot safely shrink busy') ? 'blocked' : 'other'), "\n";
    }
    try {
        $res->apply(change: ['app' => 'busy', 'mode' => 'manual', 'memory_mb' => 1024, 'cpu_percent' => 100, 'disk_quota_mb' => 512]);
    } catch (RuntimeException $e) {
        echo "disk_shrink=", (str_contains($e->getMessage(), 'Cannot safely shrink disk quota') ? 'blocked' : 'other'), "\n";
    }
});

// 5. Failed partial update restores previous limits and honors resources-pending.json.
with_resources_fixture(function (Mock_freebsd_runtime $rt, Resources $res) {
    register_jail($rt, 'one', 'blue', 256, 40, 100, 50, 1024, true);
    register_jail($rt, 'two', 'blue', 256, 40, 100, 50, 1024, true);

    $rt->fail_rctl_jail = 'tc_two_blue';
    try {
        $res->apply(change: ['app' => 'one', 'mode' => 'manual', 'memory_mb' => 1024, 'cpu_percent' => 150, 'disk_quota_mb' => 2048]);
    } catch (RuntimeException $e) {
        echo "partial_fail=", $e->getMessage(), "\n";
    }
    echo "restored_mem=", intdiv($res->rctl_limits('tc_one_blue')['memoryuse'], Resources::MIB),
        '|pending=', (is_file($res->pending) ? 'yes' : 'no'), "\n";

    $rt->write($res->pending, [
        'before' => [['app' => 'one', 'jail' => 'tc_one_blue', 'memory_mb' => 256, 'cpu_cap_percent' => 100, 'disk_quota_mb' => 1024]],
        'policies' => [],
    ]);
    $rt->rctl_limits_map['tc_one_blue'] = "jail:tc_one_blue:memoryuse:deny=768M\njail:tc_one_blue:swapuse:deny=768M\njail:tc_one_blue:pcpu:deny=100\njail:tc_one_blue:maxproc:deny=64\njail:tc_one_blue:openfiles:deny=1024\n";
    $rt->rctl_usage_map['tc_one_blue'] = 'memoryuse=' . (500 * Resources::MIB) . "\n";
    try {
        $res->apply();
    } catch (RuntimeException $e) {
        echo "unsafe_recover=", (str_contains($e->getMessage(), 'recovery needs attention') ? 'paused' : 'other'), '|pending=', (is_file($res->pending) ? 'yes' : 'no'), "\n";
    }
    $rt->rctl_usage_map['tc_one_blue'] = 'memoryuse=' . (40 * Resources::MIB) . "\n";
    $res->apply(change: ['app' => 'one', 'mode' => 'manual', 'memory_mb' => 512, 'cpu_percent' => 100, 'disk_quota_mb' => 1024]);
    echo "recovered_mem=", intdiv($res->rctl_limits('tc_one_blue')['memoryuse'], Resources::MIB),
        '|pending=', (is_file($res->pending) ? 'yes' : 'no'), "\n";
});

// 6. Low available memory fails closed.
with_resources_fixture(function (Mock_freebsd_runtime $rt, Resources $res) {
    $rt->sysctls['vm.stats.vm.v_free_count'] = 100;
    $rt->sysctls['vm.stats.vm.v_inactive_count'] = 0;
    $rt->sysctls['vm.stats.vm.v_laundry_count'] = 0;
    $rt->sysctls['kstat.zfs.misc.arcstats.size'] = 2 * 1024 * Resources::MIB;
    $rt->sysctls['kstat.zfs.misc.arcstats.c_min'] = 2 * 1024 * Resources::MIB;
    try {
        $res->apply(incoming: 'one', starting: true);
    } catch (RuntimeException $e) {
        echo "low_mem=", (str_contains($e->getMessage(), 'Available memory is too low') ? 'blocked' : 'other'), "\n";
    }
});

// 7. Metrics::collect computes ARC-aware memory, ZFS space, CPU delta, and active jails.
with_resources_fixture(function (Mock_freebsd_runtime $rt, Resources $res) {
    $rt->cp_samples = ['100 0 50 0 850', '125 0 75 0 900'];
    $rt->jls['tc_mariadb'] = ['jid' => 1, 'name' => 'tc_mariadb'];
    register_jail($rt, 'alpha', 'blue', 256, 60, 100, 100, 1024, true);
    register_jail($rt, 'beta', 'green', 512, 90, 200, 200, 2048, true);

    $sample = (new Metrics($rt->root, $rt, $rt->root . '/etc-resources.json', 0))->sample();
    $gib = 1024 * Resources::MIB;
    echo "metrics=", implode('|', [
        $sample['cpu_percent'],
        $sample['cpus'],
        $sample['load_1m'],
        intdiv($sample['memory_total'], $gib),
        intdiv($sample['memory_used'], $gib),
        intdiv($sample['arc_size'], $gib),
        intdiv($sample['arc_reclaimable'], $gib),
        intdiv($sample['disk_total'], $gib),
        intdiv($sample['disk_used'], $gib),
        intdiv($sample['disk_free'], $gib),
        intdiv($sample['app_memory_limit'], Resources::MIB),
        $sample['app_cpu_limit'],
        $sample['apps'],
        $sample['app_versions'],
    ]), "\n";
});
?>
--EXPECT--
host=32768|10240|20480|8|zroot|1024000|921600
plan100=24576|73728|512
plan250=294|100|128
manual=succeeded|apps=2|mode=manual|mem=1024|cpu=200|disk=2048|slot=blue
invalid_rctl_calls=0
drift=caught
ram_shrink=blocked
disk_shrink=blocked
partial_fail=simulated rctl failure
restored_mem=256|pending=no
unsafe_recover=paused|pending=yes
recovered_mem=512|pending=no
low_mem=blocked
metrics=50|8|0.42|32|12|12|10|1000|100|900|768|300|2|2
