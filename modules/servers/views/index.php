<main class="panel">
    <h1>Servers</h1>
    <p><a href="/servers/builds">Build jails &amp; servers</a></p>
    <p><a class="wz-ok" href="/servers/connect">Connect a FreeBSD host</a></p>
    <?php if (isset($_SESSION['app_draft'])): ?><p><a href="/apps/create">Continue app setup</a></p><?php endif ?>
    <?php if (empty($inventory['servers'])): ?>
    <section class="card">
        <h2>No FreeBSD hosts connected yet</h2>
        <p>Bootstrap a bare-metal FreeBSD server with ZFS, jails, rctl, pf, Caddy, MariaDB, and Valkey, then connect it over pinned Ed25519 SSH.</p>
    </section>
    <?php endif ?>
    <?php foreach ($inventory['servers'] as $server):
        $facts = is_array($server['facts'] ?? null) ? $server['facts'] : [];
        $phpVersions = is_array($server['php_versions'] ?? null) ? $server['php_versions'] : [];
        $zfsPool = (string) ($facts['zfs_pool'] ?? '');
        $freebsdVersion = (string) ($facts['freebsd_version'] ?? '');
        $arch = (string) ($facts['arch'] ?? '');
        $statusLabel = !empty($server['application_ready'])
            ? 'Ready for apps'
            : (!empty($server['builder_ready']) ? 'Ready for builds' : 'Runtime preparation required');
        $capacity = $capacities[$server['id']] ?? ['latest' => null, 'stale' => true, 'hints' => []];
        $sample = $capacity['latest'] ?? null;
    ?>
    <section class="card">
        <h2><?php if (!empty($server['application_ready'])): ?><a href="/servers/details?server=<?= $e(rawurlencode($server['id'])) ?>"><?= $e($server['id']) ?></a><?php else: ?><?= $e($server['id']) ?><?php endif ?></h2>
        <?php if (!empty($server['application_ready'])): ?><p><a href="/servers/details?server=<?= $e(rawurlencode($server['id'])) ?>">Apps and resource allocations →</a></p><?php endif ?>
        <p><?= $e($server['host']) ?> · <?= $e($statusLabel) ?></p>
        <p class="wz-hint">
            <?php if (($facts['os'] ?? '') === 'freebsd'): ?>FreeBSD<?= $freebsdVersion !== '' ? ' ' . $e($freebsdVersion) : '' ?><?= $arch !== '' ? ' (' . $e($arch) . ')' : '' ?><?php else: ?>Unverified host OS<?php endif ?>
            <?php if ($zfsPool !== ''): ?> · ZFS pool <code><?= $e($zfsPool) ?></code><?php endif ?>
            <?php if ($phpVersions): ?> · PHP <?= $e(implode(', ', $phpVersions)) ?><?php endif ?>
            <?php if (!empty($server['local_builder_ready'])): ?> · Ephemeral build jails enabled<?php endif ?>
        </p>
        <?php if ($sample): ?>
        <h3>Capacity</h3>
        <p class="wz-hint"><?= $capacity['stale'] ? 'Last known readings — monitoring is stale' : 'Updated ' . $e(gmdate('H:i', $sample['at'])) . ' UTC · sampled every five minutes' ?></p>
        <dl class="capacity-grid">
            <div><dt>CPU usage</dt><dd><?= $sample['cpu_percent'] === null ? 'Unavailable' : $e($sample['cpu_percent']) . '%' ?></dd><?php if ($sample['cpu_percent'] !== null): ?><meter aria-label="CPU usage" min="0" max="100" high="85" optimum="25" value="<?= $e($sample['cpu_percent']) ?>"></meter><?php endif ?></div>
            <div><dt>Memory (ARC-aware)</dt><dd><?= $e(round($sample['memory_used'] / 1073741824, 1)) ?> <small>/ <?= $e(round($sample['memory_total'] / 1073741824, 1)) ?> GB</small></dd><meter aria-label="Memory used" min="0" max="100" high="85" optimum="25" value="<?= $e(100 * $sample['memory_used'] / max(1, $sample['memory_total'])) ?>"></meter><?php if (isset($sample['arc_reclaimable']) && is_numeric($sample['arc_reclaimable'])): ?><small class="wz-hint"><?= $e(round($sample['arc_reclaimable'] / 1073741824, 1)) ?> GB ZFS ARC reclaimable</small><?php endif ?></div>
            <div><dt><?= $zfsPool !== '' ? 'ZFS pool (' . $e($zfsPool) . ')' : 'ZFS disk' ?></dt><dd><?= $e(round($sample['disk_used'] / 1073741824, 1)) ?> <small>/ <?= $e(round($sample['disk_total'] / 1073741824, 1)) ?> GB</small></dd><meter aria-label="Disk used" min="0" max="100" high="85" optimum="25" value="<?= $e(100 * $sample['disk_used'] / max(1, $sample['disk_total'])) ?>"></meter></div>
        </dl>
        <?php foreach ($capacity['hints'] as $hint): ?><p class="wz-help"><?= $e($hint) ?></p><?php endforeach ?>
        <?php else: ?><p>Waiting for the first monitoring sample.</p><?php endif ?>
        <p class="wz-hint">Scaling suggestions only. Automatic resizing is off; resource caps stay under your control.</p>
        <?php if (empty($server['application_ready']) && empty($server['builder_ready'])): ?><p>Missing: <?= $e(implode(', ', $server['missing'] ?? [])) ?></p><?php endif ?>
        <form method="post" action="/servers/action">
            <?= $csrf() ?>
            <input type="hidden" name="command" value="inspect-connected-server">
            <input type="hidden" name="server" value="<?= $e($server['id']) ?>">
            <button class="link-button">Refresh capabilities</button>
        </form>
    </section>
    <?php endforeach ?>
</main>
