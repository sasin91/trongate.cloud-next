<?php
$resources = $resource_details;
$resourceUrl = '/servers/details?server=' . rawurlencode($resources['server'] ?? ($_GET['server'] ?? ''));
$swaps = [
    ['select' => '#server-budget', 'target' => '#server-budget', 'swap' => 'outerHTML'],
    ['select' => '#resource-message', 'target' => '#resource-message', 'swap' => 'outerHTML'],
];
foreach ($resources['apps'] ?? [] as $app) {
    $swaps[] = ['select' => '#readings-' . $app['app'], 'target' => '#readings-' . $app['app'], 'swap' => 'outerHTML'];
}
?>
<main class="panel" id="resource-workspace">
    <a href="/servers">← All servers</a>
    <section id="resource-message" aria-live="polite"><?php if ($message): ?><p class="wz-errors" role="alert"><?= $e($message) ?></p><?php endif ?></section>
    <?php if ($resources):
        $host = $resources['host'];
        $physicalBudget = (int) ($resources['memory_budget_mb'] ?? 0);
        $effectiveBudget = (int) ($resources['effective_memory_budget_mb'] ?? $physicalBudget);
        $reservedMemory = (int) ($resources['reserved_memory_mb'] ?? 0);
        $standbyHeadroom = (int) ($resources['standby_headroom_mb'] ?? 0);
        $overcommitRatio = (float) ($resources['overcommit_ratio'] ?? ($host['memory_overcommit_ratio'] ?? 1.0));
        $arcReclaimable = (int) ($host['arc_reclaimable_mb'] ?? 0);
        $zfsPool = (string) ($host['zfs_pool'] ?? 'zroot');
        $poolTotalMb = (int) ($host['pool_total_mb'] ?? 0);
        $poolFreeMb = (int) ($host['pool_free_mb'] ?? 0);
        $cpuBudgetPercent = (int) ($resources['cpu_budget_percent'] ?? (max(1, (int) ($host['cpus'] ?? 1)) * 100));
    ?>
    <h1><?= $e($resources['server']) ?></h1>
    <p><?= $e($resources['host_address']) ?> · FreeBSD rctl &amp; ZFS resources</p>
    <?php if ($resources_saved): ?><p class="text-success" role="status">✓ Saved</p><?php endif ?>
    <?php if (!empty($resources['error'])): ?><p class="wz-errors" role="alert"><?= $e($resources['error']) ?></p><?php endif ?>
    <?php if (!empty($resources['pending_recovery'])): ?><p class="wz-errors" role="alert">Interrupted update. Save again to recover the previous limits.</p><?php endif ?>
    <section class="card" id="server-budget">
        <div class="panel-heading"><h2>Server budget</h2><a href="<?= $e($resourceUrl) ?>" mx-get="<?= $e($resourceUrl) ?>" mx-target="none" mx-select-oob="<?= $e(json_encode($swaps)) ?>" mx-after-swap="resourceRefresh" mx-on-error="#mx-error-trigger">↻ Refresh</a></div>
        <dl class="capacity-grid">
            <div><dt>Physical RAM</dt><dd><?= $e($host['memory_mb']) ?> <small>MiB</small></dd></div>
            <div><dt>Available (ARC-aware)</dt><dd><?= $e($host['available_mb']) ?> <small>MiB</small></dd></div>
            <div><dt>Processors</dt><dd><?= $e($host['cpus']) ?> <small>CPU (<?= $e($cpuBudgetPercent) ?>% cap budget)</small></dd></div>
        </dl>
        <p class="wz-hint">
            FreeBSD ARC-aware accounting counts <?= $e($arcReclaimable) ?> MiB of reclaimable ZFS ARC as available headroom.
            <?php if ($overcommitRatio > 1.0): ?>Overcommit budget: <?= $e($effectiveBudget) ?> MiB (<?= $e(rtrim(rtrim(number_format($overcommitRatio, 2, '.', ''), '0'), '.')) ?>× physical app budget, <?= $e($standbyHeadroom) ?> MiB standby deploy headroom).<?php endif ?>
            <?php if ($poolTotalMb > 0): ?> ZFS pool <code><?= $e($zfsPool) ?></code>: <?= $e($poolFreeMb) ?> / <?= $e($poolTotalMb) ?> MiB free.<?php endif ?>
        </p>
        <?php $segments = [
            ['System', (int) ($host['system_memory_mb'] ?? 0), '#64748b'],
            ['MariaDB', (int) ($host['database_memory_mb'] ?? 0), '#0d9488'],
            ['Valkey', (int) ($host['valkey_memory_mb'] ?? 0), '#0891b2'],
            ['Build jails', (int) ($host['build_memory_mb'] ?? 0), '#d97706'],
            ['Apps · rctl caps + standby', min(max(0, $physicalBudget), $reservedMemory), '#4f46e5'],
            ['Free physical budget', max(0, $physicalBudget - $reservedMemory), '#dfe2ed'],
        ]; ?>
        <div class="budget-bar" role="img" aria-label="FreeBSD host memory budget across system, platform jails, builds, and apps">
            <?php foreach ($segments as [$label, $amount, $colour]): ?><span style="flex:<?= $e(max(0, $amount)) ?>;background:<?= $e($colour) ?>" title="<?= $e($label . ': ' . $amount . ' MiB') ?>"></span><?php endforeach ?>
        </div>
        <ul class="budget-legend">
            <?php foreach ($segments as [$label, $amount, $colour]): ?>
            <li><i style="background:<?= $e($colour) ?>" aria-hidden="true"></i><?= $e($label) ?> <strong><?= $e($label === 'Apps · rctl caps + standby' ? $reservedMemory : $amount) ?> MiB</strong></li>
            <?php endforeach ?>
        </ul>
    </section>
    <h2>Apps</h2>
    <?php if (empty($resources['apps'])): ?><p>No deployed apps yet.</p><?php endif ?>
    <div class="app-resource-grid">
    <?php foreach ($resources['apps'] as $row):
        $allocation = is_array($row['allocation'] ?? null) ? $row['allocation'] : [];
        $editing = !$resources_saved && ($_POST['app'] ?? '') === $row['app'];
        $mode = $editing ? ($_POST['mode'] ?? $row['mode']) : $row['mode'];
        $memoryInput = $editing ? ($_POST['memory_mb'] ?? $allocation['memory_mb'] ?? 128) : ($allocation['memory_mb'] ?? 128);
        $cpuInput = $editing ? ($_POST['cpu_percent'] ?? $allocation['cpu_percent'] ?? 100) : ($allocation['cpu_percent'] ?? 100);
        $diskInput = $editing ? ($_POST['disk_quota_mb'] ?? $allocation['disk_quota_mb'] ?? $row['disk_quota_mb'] ?? 1024) : ($allocation['disk_quota_mb'] ?? $row['disk_quota_mb'] ?? 1024);
        $slot = $row['slot'] ?? null;
        $maxMemoryMb = (int) ($row['max_memory_mb'] ?? 64);
        $maxCpuPercent = (int) ($row['max_cpu_percent'] ?? 100);
        $maxDiskQuotaMb = (int) ($row['max_disk_quota_mb'] ?? 0);
        $limitsMb = is_array($row['limits_mb'] ?? null) ? $row['limits_mb'] : [];
        $cpuCaps = is_array($row['cpu_caps'] ?? null) ? $row['cpu_caps'] : [];
        $meterMax = max(1, array_sum($limitsMb) ?: (int) ($allocation['memory_mb'] ?? 128));
    ?>
        <section class="card resource-card">
            <h3><?= $e($row['app']) ?></h3>
            <section id="readings-<?= $e($row['app']) ?>" data-max-memory="<?= $e($maxMemoryMb) ?>" data-max-cpu="<?= $e($maxCpuPercent) ?>" data-max-disk="<?= $e($maxDiskQuotaMb) ?>">
                <p>
                    <?php if ($slot !== null && $slot !== ''): ?><span class="status">Slot <?= $e($slot) ?></span> · <?php endif ?>
                    <?= $e($row['versions']) ?> running jail<?= (int) $row['versions'] === 1 ? '' : 's' ?> · <?= $e(ucfirst($row['mode'])) ?>
                </p>
                <dl class="resource-readings">
                    <div><dt>Memory used</dt><dd><?= $e($row['used_mb']) ?> MiB</dd></div>
                    <div><dt>CPU used</dt><dd><?= $row['cpu_percent'] === null ? 'Unavailable' : $e($row['cpu_percent']) . '%' ?></dd></div>
                    <div><dt>ZFS disk used</dt><dd><?= $e($row['disk_used_mb'] ?? 0) ?> / <?= $e($row['disk_quota_mb'] ?? 0) ?> MiB</dd></div>
                    <div><dt>rctl memory cap</dt><dd><?= $limitsMb ? $e(implode(' / ', $limitsMb)) . ' MiB' : 'Stopped' ?></dd></div>
                    <div><dt>rctl CPU cap</dt><dd><?= $cpuCaps ? $e(implode(' / ', array_map(static fn($cap): string => $cap . '%', $cpuCaps))) : 'Stopped' ?></dd></div>
                </dl>
                <meter class="resource-meter" aria-label="Memory usage" min="0" max="<?= $e($meterMax) ?>" value="<?= $e($row['used_mb']) ?>"></meter>
            </section>
            <form method="post" action="<?= $e($resourceUrl) ?>" class="resource-form" mx-post="<?= $e($resourceUrl) ?>" mx-target="#resource-workspace" mx-select="#resource-workspace" mx-swap="outerHTML" mx-on-trigger="resourceBeforeSave" mx-after-swap="resourceAfterSave" mx-on-timeout="panelMxTimeout" mx-on-error="#mx-error-trigger">
                <?= $csrf() ?>
                <input type="hidden" name="app" value="<?= $e($row['app']) ?>">
                <label>Allocation<select name="mode"><option value="automatic" <?= $mode === 'automatic' ? 'selected' : '' ?>>Automatic fair share</option><option value="manual" <?= $mode === 'manual' ? 'selected' : '' ?>>Manual rctl &amp; ZFS caps</option></select></label>
                <div class="resource-inputs">
                    <label>Memory cap (MiB)<input name="memory_mb" type="number" min="64" max="<?= $e($maxMemoryMb) ?>" step="1" value="<?= $e($memoryInput) ?>" required></label>
                    <label>CPU cap (%)<input name="cpu_percent" type="number" min="10" max="<?= $e($maxCpuPercent) ?>" step="1" value="<?= $e($cpuInput) ?>" required></label>
                    <label>ZFS disk quota (MiB)<input name="disk_quota_mb" type="number" min="128"<?= $maxDiskQuotaMb > 0 ? ' max="' . $e($maxDiskQuotaMb) . '"' : '' ?> step="1" value="<?= $e($diskInput) ?>" required></label>
                </div>
                <p class="resource-ceiling wz-hint">Up to <?= $e($maxMemoryMb) ?> MiB RAM · <?= $e($maxCpuPercent) ?>% CPU<?= $maxDiskQuotaMb > 0 ? ' · ' . $e($maxDiskQuotaMb) . ' MiB ZFS' : '' ?></p>
                <p class="resource-feedback wz-hint" role="status" aria-live="polite"></p>
                <button>Save allocation</button>
            </form>
        </section>
    <?php endforeach ?>
    </div>
    <?php else: ?><h1>Resources unavailable</h1><p>Refresh to reconnect.</p><?php endif ?>
</main>
<?php if ($resources): ?><span hidden id="resource-poll" mx-get="<?= $e($resourceUrl) ?>" mx-target="none" mx-trigger="every 15s" mx-select-oob="<?= $e(json_encode($swaps)) ?>" mx-after-swap="resourceRefresh" mx-on-error="#mx-error-trigger"></span><?php endif ?>
