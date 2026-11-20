<main class="panel">
    <a href="/servers">← All servers</a>
    <?php if ($resource_details): $resources = $resource_details; $host = $resources['host']; ?>
    <h1><?= $e($resources['server']) ?></h1>
    <p><?= $e($resources['host_address']) ?> · Live resource readings</p>
    <?php if ($resources_saved): ?><p class="wz-help" role="status">Allocations applied. Automatic apps were rebalanced, including their deployment versions.</p><?php endif ?>
    <?php if ($resources['error']): ?><p class="wz-errors" role="alert"><?= $e($resources['error']) ?></p><?php endif ?>
    <?php if ($resources['pending_recovery']): ?><p class="wz-errors" role="alert">A resource update was interrupted. The next update will restore the previous limits before applying changes.</p><?php endif ?>
    <section class="card">
        <h2>Server budget</h2>
        <dl class="capacity-grid">
            <div><dt>Physical memory</dt><dd><?= $e($host['memory_mb']) ?> <small>MiB</small></dd></div>
            <div><dt>Available right now</dt><dd><?= $e($host['available_mb']) ?> <small>MiB</small></dd></div>
            <div><dt>Processors</dt><dd><?= $e($host['cpus']) ?> <small>vCPU</small></dd></div>
        </dl>
        <p>Kept aside: <?= $e($host['system_memory_mb']) ?> MiB for system services, <?= $e($host['database_memory_mb']) ?> MiB for databases, and <?= $e($host['build_memory_mb']) ?> MiB for local builds.</p>
        <p><strong><?= $e($resources['memory_budget_mb'] ?? 0) ?> MiB</strong> and <strong><?= $e($resources['cpu_budget'] ?? 0) ?> CPU shares</strong> remain for apps and deployment overlap.</p>
        <?php if (isset($resources['reserved_memory_mb'])): ?><p>Planned two-version reservations: <strong><?= $e($resources['reserved_memory_mb']) ?> MiB</strong>. Unallocated: <strong><?= $e($resources['memory_budget_mb'] - $resources['reserved_memory_mb']) ?> MiB</strong>.</p><?php endif ?>
        <p class="wz-hint">Each app reserves two copies of its allocation, so a new version can start while the current one serves traffic. Databases use a separate reserve and are not duplicated. CPU shares set relative priority; apps can use spare CPU.</p>
        <p class="wz-hint">Automatic apps split the remaining budget equally. Manual limits stay fixed. Adding an app or changing a limit recalculates automatic allocations. Unsafe reductions are rejected.</p>
        <a href="/servers/details?server=<?= $e(rawurlencode($resources['server'])) ?>">Refresh usage</a>
    </section>
    <h2>Apps and resources</h2>
    <?php if (!$resources['apps']): ?><p>No deployed apps yet. Resources will be allocated automatically on first deployment.</p><?php endif ?>
    <div class="app-resource-grid">
    <?php foreach ($resources['apps'] as $row):
        $allocation = $row['allocation'];
        $editing = !$resources_saved && ($_POST['app'] ?? '') === $row['app'];
        $mode = $editing ? ($_POST['mode'] ?? $row['mode']) : $row['mode'];
        $memoryInput = $editing ? ($_POST['memory_mb'] ?? $allocation['memory_mb'] ?? 256) : ($allocation['memory_mb'] ?? 256);
        $cpuInput = $editing ? ($_POST['cpus'] ?? $allocation['cpus'] ?? 0.05) : ($allocation['cpus'] ?? 0.05);
    ?>
        <section class="card resource-card">
            <h3><?= $e($row['app']) ?></h3>
            <p><?= $e($row['versions']) ?> running version<?= $row['versions'] === 1 ? '' : 's' ?> · <?= $e(ucfirst($row['mode'])) ?></p>
            <dl class="resource-readings">
                <div><dt>Memory used, all versions</dt><dd><?= $e($row['used_mb']) ?> MiB</dd></div>
                <div><dt>CPU used, all versions</dt><dd><?= $row['cpu_percent'] === null ? 'Unavailable' : $e($row['cpu_percent']) . '% of one CPU' ?></dd></div>
                <div><dt>Current limits, per version</dt><dd><?= $row['limits_mb'] ? $e(implode(' / ', $row['limits_mb'])) . ' MiB' : 'Not running' ?></dd></div>
                <div><dt>CPU shares, per version</dt><dd><?= $row['cpu_shares'] ? $e(implode(' / ', $row['cpu_shares'])) : 'Not running' ?></dd></div>
            </dl>
            <?php if (array_sum($row['cpu_caps']) > 0): ?><p class="wz-hint">Legacy CPU caps are still applied. Saving an allocation replaces them with proportional shares.</p><?php endif ?>
            <form method="post" action="/servers/details?server=<?= $e(rawurlencode($resources['server'])) ?>" class="resource-form">
                <?= $csrf() ?>
                <input type="hidden" name="app" value="<?= $e($row['app']) ?>">
                <label class="wz-field"><span class="wz-label">Allocation</span><select name="mode" class="wz-in"><option value="automatic" <?= $mode === 'automatic' ? 'selected' : '' ?>>Automatic fair share</option><option value="manual" <?= $mode === 'manual' ? 'selected' : '' ?>>Manual limits</option></select></label>
                <label class="wz-field"><span class="wz-label">Memory per version (MiB)</span><input class="wz-in" name="memory_mb" type="number" min="256" max="<?= $e($row['max_memory_mb']) ?>" step="1" value="<?= $e($memoryInput) ?>" required></label>
                <label class="wz-field"><span class="wz-label">CPU shares per version</span><input class="wz-in" name="cpus" type="number" min="0.05" max="<?= $e($row['max_cpus']) ?>" step="0.01" value="<?= $e($cpuInput) ?>" required></label>
                <p class="wz-hint">Budget ceiling: <?= $e($row['max_memory_mb']) ?> MiB and <?= $e($row['max_cpus']) ?> CPU shares per version. This may reduce other automatic apps; live memory usage can make the safe maximum lower.</p>
                <p class="resource-feedback wz-hint" role="status" aria-live="polite"></p>
                <button class="wz-ok">Apply allocation</button>
            </form>
        </section>
    <?php endforeach ?>
    </div>
    <?php else: ?><h1>Server resources unavailable</h1><p>No changes can be made until the server responds.</p><?php endif ?>
</main>
<script src="/servers_module/js/resources.js" defer></script>
