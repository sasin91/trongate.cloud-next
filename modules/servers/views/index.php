<main class="panel">
    <h1>Servers</h1>
    <p><a href="/servers/builds">Build servers</a></p>
    <p><a class="wz-ok" href="/servers/create">Create at a provider</a> <a class="wz-ok" href="/servers/connect">Connect an existing server</a></p>
    <?php if (isset($_SESSION['app_draft'])): ?><p><a href="/apps/create">Continue app setup</a></p><?php endif ?>
    <?php $this->servers->_display('pending', $viewData); ?>
    <?php $monitor = new Monitoring_model('monitoring'); ?>
    <?php foreach ($inventory['servers'] as $server): ?>
    <section class="card">
        <h2><?php if ($server['application_ready']): ?><a href="/servers/details?server=<?= $e(rawurlencode($server['id'])) ?>"><?= $e($server['id']) ?></a><?php else: ?><?= $e($server['id']) ?><?php endif ?></h2>
        <?php if ($server['application_ready']): ?><p><a href="/servers/details?server=<?= $e(rawurlencode($server['id'])) ?>">Apps and resource allocations →</a></p><?php endif ?>
        <p><?= $e($server['host']) ?> · <?= !empty($server['builder_ready']) ? 'Ready for builds' : ($server['application_ready'] ? 'Ready for apps' : 'Runtime preparation required') ?></p>
        <?php if (!empty($server['local_builder_ready'])): ?><p>Local builds enabled</p><?php endif ?>
        <?php $capacity = $monitor->_summary($server['id']); $sample = $capacity['latest']; ?>
        <?php if ($sample): ?>
        <h3>Capacity</h3>
        <p class="wz-hint"><?= $capacity['stale'] ? 'Last known readings — monitoring is stale' : 'Updated ' . $e(gmdate('H:i', $sample['at'])) . ' UTC · sampled every five minutes' ?></p>
        <dl class="capacity-grid">
            <div><dt>CPU usage</dt><dd><?= $sample['cpu_percent'] === null ? 'Unavailable' : $e($sample['cpu_percent']) . '%' ?></dd><?php if ($sample['cpu_percent'] !== null): ?><meter aria-label="CPU usage" min="0" max="100" high="85" optimum="25" value="<?= $e($sample['cpu_percent']) ?>"></meter><?php endif ?></div>
            <div><dt>Memory</dt><dd><?= $e(round($sample['memory_used'] / 1073741824, 1)) ?> <small>/ <?= $e(round($sample['memory_total'] / 1073741824, 1)) ?> GB</small></dd><meter aria-label="Memory used" min="0" max="100" high="85" optimum="25" value="<?= $e(100 * $sample['memory_used'] / max(1, $sample['memory_total'])) ?>"></meter></div>
            <div><dt>Disk</dt><dd><?= $e(round($sample['disk_used'] / 1073741824, 1)) ?> <small>/ <?= $e(round($sample['disk_total'] / 1073741824, 1)) ?> GB</small></dd><meter aria-label="Disk used" min="0" max="100" high="85" optimum="25" value="<?= $e(100 * $sample['disk_used'] / max(1, $sample['disk_total'])) ?>"></meter></div>
        </dl>
        <?php foreach ($capacity['hints'] as $hint): ?><p class="wz-help"><?= $e($hint) ?></p><?php endforeach ?>
        <?php else: ?><p>Waiting for the first monitoring sample.</p><?php endif ?>
        <p class="wz-hint">Scaling suggestions only. Automatic resizing is off; any future cost increase requires your opt-in.</p>
        <?php if (!$server['application_ready'] && empty($server['builder_ready'])): ?><p>Missing: <?= $e(implode(', ', $server['missing'] ?? [])) ?></p><?php endif ?>
        <form method="post" action="/servers/action">
            <?= $csrf() ?>
            <input type="hidden" name="command" value="inspect-connected-server">
            <input type="hidden" name="server" value="<?= $e($server['id']) ?>">
            <button class="link-button">Refresh capabilities</button>
        </form>
    </section>
    <?php endforeach ?>
    <?php if (isset($_SESSION['server_operation'])): ?><p><a href="/servers/provision">View server creation progress</a></p><?php endif ?>
</main>
