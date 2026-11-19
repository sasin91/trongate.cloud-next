<?php $status = $_SESSION['server_status'] ?? []; ?>
<main class="panel" <?php if (isset($status['operation'])): ?>data-provision="<?= $e($status['operation']) ?>"<?php endif ?>>
<a href="/servers">← Back to servers</a>
<section class="card">
<div class="panel-heading"><div><p class="eyebrow">SERVER CREATION</p><h1><?= $e($status['name'] ?? 'Server creation') ?></h1></div><span class="status" id="provision-status" data-status="<?= $e($status['status'] ?? '') ?>"><?= $e($status['status'] ?? 'Unavailable') ?></span></div>
<p id="provision-description" role="status"><?= $e($status['description'] ?? '') ?></p>
<ol class="provision-stages" aria-label="Creation stages">
<?php foreach (['Request queued', 'Provider creating server', 'Server created'] as $index => $label): ?>
<li data-stage="<?= $index + 1 ?>" class="<?= ($status['stage'] ?? 0) >= $index + 1 ? 'reached' : '' ?>"><?= $e($label) ?></li>
<?php endforeach ?>
</ol>
<p>Provider: <?= $e($status['provider'] ?? '') ?> · <span id="provision-elapsed" data-started="<?= $e($status['started'] ?? 0) ?>"></span></p>
<p id="provision-address"><?= isset($status['host']) ? 'Server address: ' . $e($status['host']) : '' ?></p>
<a id="provision-connect" class="wz-ok" <?= ($status['status'] ?? '') === 'succeeded' ? '' : 'hidden' ?> href="/servers/connect?operation=<?= $e($status['operation'] ?? '') ?>">Connect created server →</a>
</section>
<section class="card"><div class="panel-heading"><h2>Activity log</h2><span id="provision-updates" role="status">Updates automatically while creation is running</span></div>
<p>Provider activity appears here. SSH readiness is checked after creation.</p>
<div class="log-container" id="provision-logs" role="log" aria-label="Server creation activity"><?php foreach ($status['logs'] ?? [] as $line): ?><div class="log-line"><span class="log-time"><?= $e(date('H:i:s', $line['at'])) ?></span> <?= $e($line['message']) ?></div><?php endforeach ?></div>
</section>
<p><a href="/servers/provision?operation=<?= $e($status['operation'] ?? '') ?>">Refresh status</a> · <a href="/deployments?operation=<?= $e($status['operation'] ?? '') ?>">Operation details</a></p>
</main>
<script src="/servers_module/js/provision.js" defer></script>
