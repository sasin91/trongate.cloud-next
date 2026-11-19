<?php foreach ($pending_servers as $server): ?>
<section class="card">
    <p class="eyebrow">PROVIDER SERVER · <?= $e($server['provider']) ?></p>
    <h2><?= $e($server['name']) ?></h2>
    <?php if ($server['status'] === 'succeeded'): ?>
    <p><?= $e($server['host']) ?> · Created — SSH connection required</p>
    <p>Your server exists. Connect over SSH to check readiness for app deployment.</p>
    <a class="wz-ok" href="/servers/connect?operation=<?= $e($server['operation']) ?>">Finish connecting server →</a>
    <?php else: ?>
    <p>Server creation: <?= $e($server['status']) ?></p>
    <a class="wz-ok" href="/servers/provision?operation=<?= $e($server['operation']) ?>">View server creation</a>
    <?php endif ?>
</section>
<?php endforeach ?>
