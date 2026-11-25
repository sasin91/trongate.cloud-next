<main class="panel">
    <p><a href="/servers">← Servers</a></p>
    <h1>Build jails &amp; servers</h1>
    <section class="card">
        <h2>Ephemeral FreeBSD build jails</h2>
        <p>By default, Git builds run directly on your FreeBSD application server inside short-lived, <code>rctl</code>-bounded build jails cloned from <code>zroot/tc/runtimes/builder@v1</code>.</p>
        <p>Each build jail is capped at 1536 MiB RAM, 200% CPU, and a 2 GB ZFS quota with swap disabled. Your active Blue/Green app slot keeps serving traffic uninterrupted while Composer and optional Node/Bun build commands run.</p>
        <p>Verified builds produce immutable ZFS release directories (<code>zroot/tc/apps/&lt;app&gt;/releases/&lt;op&gt;</code>) with a SHA-256 manifest digest, after checking the Git commit, guarding against symlink escapes, and verifying Trongate’s <code>public/index.php</code> and <code>config/config.php</code>. The ephemeral build jail is destroyed as soon as the build finishes.</p>
        <p class="wz-hint">Provision or update the builder snapshot with <code>infra/scripts/build-runtime.sh</code>, then select <strong>Refresh capabilities</strong> on the Servers page.</p>
    </section>
    <section class="card">
        <h2>Build-ready FreeBSD hosts</h2>
        <?php $builders = array_filter(
            $inventory['servers'] ?? [],
            static fn(array $server): bool => !empty($server['local_builder_ready']) || !empty($server['builder_ready'])
        ); ?>
        <?php if (!$builders): ?>
        <p>No build-ready FreeBSD host connected yet. <a href="/servers/connect">Connect a FreeBSD host</a> or run <code>infra/scripts/build-runtime.sh</code> on an existing server.</p>
        <?php else: ?>
        <?php foreach ($builders as $server):
            $facts = is_array($server['facts'] ?? null) ? $server['facts'] : [];
            $mode = !empty($server['application_ready']) ? 'Local build jails' : 'Dedicated builder';
        ?>
        <p>
            <strong><?= $e($server['id']) ?></strong> · <?= $e($mode) ?>
            <?php if (!empty($facts['freebsd_version'])): ?> · FreeBSD <?= $e($facts['freebsd_version']) ?><?php endif ?>
            <?php if (!empty($facts['arch'])): ?> (<?= $e($facts['arch']) ?>)<?php endif ?>
            <?php if (!empty($facts['zfs_pool'])): ?> · ZFS <code><?= $e($facts['zfs_pool']) ?></code><?php endif ?>
        </p>
        <?php endforeach ?>
        <?php endif ?>
    </section>
</main>
