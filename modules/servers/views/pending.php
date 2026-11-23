<?php foreach ($pending_servers as $server): ?>
<section class="card">
    <p class="eyebrow">PROXMOX SERVER</p>
    <h2><?= $e($server['name']) ?></h2>
    <p>Server creation: <?= $e($server['status']) ?></p>
    <a class="wz-ok" href="/servers/proxmox?operation=<?= $e($server['operation']) ?>">View server creation</a>
</section>
<?php endforeach ?>