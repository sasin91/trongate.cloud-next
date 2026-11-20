<?php
$resources = $resource_details;
$resourceUrl = '/servers/details?server=' . rawurlencode($resources['server'] ?? ($_GET['server'] ?? ''));
$swaps = [['select' => '#server-budget', 'target' => '#server-budget', 'swap' => 'outerHTML'], ['select' => '#resource-message', 'target' => '#resource-message', 'swap' => 'outerHTML']];
foreach ($resources['apps'] ?? [] as $app) {
    $swaps[] = ['select' => '#readings-' . $app['app'], 'target' => '#readings-' . $app['app'], 'swap' => 'outerHTML'];
}
?>
<main class="panel" id="resource-workspace">
    <a href="/servers">← All servers</a>
    <section id="resource-message" aria-live="polite"><?php if ($message): ?><p class="wz-errors" role="alert"><?= $e($message) ?></p><?php endif ?></section>
    <?php if ($resources): $host = $resources['host']; ?>
    <h1><?= $e($resources['server']) ?></h1>
    <p><?= $e($resources['host_address']) ?> · Resources</p>
    <?php if ($resources_saved): ?><p class="text-success" role="status">✓ Saved</p><?php endif ?>
    <?php if ($resources['error']): ?><p class="wz-errors" role="alert"><?= $e($resources['error']) ?></p><?php endif ?>
    <?php if ($resources['pending_recovery']): ?><p class="wz-errors" role="alert">Interrupted update. Save again to recover the previous limits.</p><?php endif ?>
    <section class="card" id="server-budget">
        <div class="panel-heading"><h2>Server budget</h2><a href="<?= $e($resourceUrl) ?>" mx-get="<?= $e($resourceUrl) ?>" mx-target="none" mx-select-oob="<?= $e(json_encode($swaps)) ?>" mx-after-swap="resourceRefresh" mx-on-error="#mx-error-trigger">↻ Refresh</a></div>
        <dl class="capacity-grid">
            <div><dt>Memory</dt><dd><?= $e($host['memory_mb']) ?> <small>MiB</small></dd></div>
            <div><dt>Available now</dt><dd><?= $e($host['available_mb']) ?> <small>MiB</small></dd></div>
            <div><dt>Processors</dt><dd><?= $e($host['cpus']) ?> <small>vCPU</small></dd></div>
        </dl>
        <?php $segments = [
            ['System', $host['system_memory_mb'], '#64748b'],
            ['Database', $host['database_memory_mb'], '#0d9488'],
            ['Builds', $host['build_memory_mb'], '#d97706'],
            ['Apps · live + next', $resources['reserved_memory_mb'] ?? 0, '#4f46e5'],
            ['Free budget', max(0, ($resources['memory_budget_mb'] ?? 0) - ($resources['reserved_memory_mb'] ?? 0)), '#dfe2ed']
        ]; ?>
        <div class="budget-bar" role="img" aria-label="Memory budget with room for two versions per app">
            <?php foreach ($segments as [$label, $amount, $colour]): ?><span style="flex:<?= $e(max(0, $amount)) ?>;background:<?= $e($colour) ?>" title="<?= $e($label . ': ' . $amount . ' MiB') ?>"></span><?php endforeach ?>
        </div>
        <ul class="budget-legend"><?php foreach ($segments as [$label, $amount, $colour]): ?><li><i style="background:<?= $e($colour) ?>" aria-hidden="true"></i><?= $e($label) ?> <strong><?= $e($amount) ?> MiB</strong></li><?php endforeach ?></ul>
    </section>
    <h2>Apps</h2>
    <?php if (!$resources['apps']): ?><p>No deployed apps yet.</p><?php endif ?>
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
            <section id="readings-<?= $e($row['app']) ?>" data-max-memory="<?= $e($row['max_memory_mb']) ?>" data-max-cpu="<?= $e($row['max_cpus']) ?>">
                <p><?= $e($row['versions']) ?> running version<?= $row['versions'] === 1 ? '' : 's' ?> · <?= $e(ucfirst($row['mode'])) ?></p>
                <dl class="resource-readings">
                    <div><dt>Memory used</dt><dd><?= $e($row['used_mb']) ?> MiB</dd></div>
                    <div><dt>CPU used</dt><dd><?= $row['cpu_percent'] === null ? 'Unavailable' : $e($row['cpu_percent']) . '% of one CPU' ?></dd></div>
                    <div><dt>Memory / version</dt><dd><?= $row['limits_mb'] ? $e(implode(' / ', $row['limits_mb'])) . ' MiB' : 'Not running' ?></dd></div>
                    <div><dt>CPU shares / version</dt><dd><?= $row['cpu_shares'] ? $e(implode(' / ', $row['cpu_shares'])) : 'Not running' ?></dd></div>
                </dl>
                <meter class="resource-meter" aria-label="Memory usage" min="0" max="<?= $e(max(1, array_sum($row['limits_mb']))) ?>" value="<?= $e($row['used_mb']) ?>"></meter>
            </section>
            <form method="post" action="<?= $e($resourceUrl) ?>" class="resource-form" mx-post="<?= $e($resourceUrl) ?>" mx-target="#resource-workspace" mx-select="#resource-workspace" mx-swap="outerHTML" mx-on-trigger="resourceBeforeSave" mx-after-swap="resourceAfterSave" mx-on-timeout="panelMxTimeout" mx-on-error="#mx-error-trigger">
                <?= $csrf() ?>
                <input type="hidden" name="app" value="<?= $e($row['app']) ?>">
                <label>Allocation<select name="mode"><option value="automatic" <?= $mode === 'automatic' ? 'selected' : '' ?>>Automatic fair share</option><option value="manual" <?= $mode === 'manual' ? 'selected' : '' ?>>Manual limits</option></select></label>
                <div class="resource-inputs">
                    <label>Memory / version (MiB)<input name="memory_mb" type="number" min="256" max="<?= $e($row['max_memory_mb']) ?>" step="1" value="<?= $e($memoryInput) ?>" required></label>
                    <label>CPU shares / version<input name="cpus" type="number" min="0.05" max="<?= $e($row['max_cpus']) ?>" step="0.01" value="<?= $e($cpuInput) ?>" required></label>
                </div>
                <p class="resource-ceiling wz-hint">Up to <?= $e($row['max_memory_mb']) ?> MiB · <?= $e($row['max_cpus']) ?> CPU shares / version</p>
                <p class="resource-feedback wz-hint" role="status" aria-live="polite"></p>
                <button>Save allocation</button>
            </form>
        </section>
    <?php endforeach ?>
    </div>
    <?php else: ?><h1>Resources unavailable</h1><p>Refresh to reconnect.</p><?php endif ?>
</main>
<?php if ($resources): ?><span hidden id="resource-poll" mx-get="<?= $e($resourceUrl) ?>" mx-target="none" mx-trigger="every 15s" mx-select-oob="<?= $e(json_encode($swaps)) ?>" mx-after-swap="resourceRefresh" mx-on-error="#mx-error-trigger"></span><?php endif ?>
