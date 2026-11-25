<section class="card">
    <h2>Servers</h2>
    <?php if (empty($inventory['servers'])): ?>
    <p>No FreeBSD servers connected yet. <a href="/servers/connect">Connect your first server</a>.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Server</th><th>Address</th><th>Readiness</th></tr>
            </thead>
            <tbody>
            <?php foreach ($inventory['servers'] as $server): ?>
                <tr>
                    <td><?php if (!empty($server['application_ready'])): ?><a href="/servers/details?server=<?= $e(rawurlencode($server['id'])) ?>"><?= $e($server['id']) ?></a><?php else: ?><?= $e($server['id']) ?><?php endif ?></td>
                    <td><?= $e($server['host']) ?></td>
                    <td><?= $e(!empty($server['application_ready']) ? 'Ready for apps' : (!empty($server['builder_ready']) ? 'Ready for builds' : implode(', ', $server['missing'] ?? []))) ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
    <?php endif ?>
</section>
