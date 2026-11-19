<main class="panel">
<h1>Delete server</h1>
<?php if ($deleting_server !== null): $server = $deleting_server; ?>
<section class="card">
<h2><?= $e($server['name']) ?></h2>
<p><?= $e($server['provider']) ?> · <?= $e($server['host'] ?? '') ?></p>
<?php if ($server['status'] === 'deleted'): ?>
<p>The server has been deleted at the provider.</p>
<a class="wz-ok" href="/servers/create?provider=<?= $e($server['provider']) ?>">Create replacement server</a>
<?php elseif (isset($server['deletion_operation'])): ?>
<p>Deletion: <?= $e($server['deletion_status']) ?>. Wait for confirmed deletion before creating a replacement.</p>
<a class="wz-ok" href="/servers/delete?operation=<?= $e($server['operation']) ?>">Refresh status</a>
<p><a href="/deployments?operation=<?= $e($server['deletion_operation']) ?>">View deletion operation</a></p>
<?php elseif ($server['status'] === 'succeeded'): ?>
<p>This permanently deletes the Hetzner server and its local disk data. Your app setup draft is kept.</p>
<form method="post">
<?= $csrf() ?>
<input type="hidden" name="operation" value="<?= $e($server['operation']) ?>">
<label class="wz-field"><span class="wz-label">Type <?= $e($server['name']) ?> to confirm</span><input class="wz-in" name="name" required autocomplete="off"></label>
<button class="wz-ok">Permanently delete server</button>
</form>
<?php else: ?><p>This server cannot be deleted from this page in its current state.</p><?php endif ?>
</section>
<?php endif ?>
<p><a href="/servers">Back to servers</a></p>
</main>
