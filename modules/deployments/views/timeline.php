<main class="panel" data-timeline="<?= $e($timeline['id']) ?>" data-status="<?= $e($timeline['status']) ?>">
    <p><a href="/">← Back to your apps</a></p>
    <h1><?= $e($timeline['app']) ?> · Deployment</h1>
    <p role="status"><?= $timeline['status'] === 'waiting' ? 'Ready — waiting to go live' : $e(ucfirst($timeline['status'])) ?></p>
    <ol class="deployment-timeline">
    <?php foreach ($timeline['stages'] as $stage): ?>
        <li data-status="<?= $e($stage['status']) ?>"><span class="timeline-dot" aria-hidden="true"></span><div><h2><?= $e($stage['name']) ?> <small><?= $e($stage['status']) ?></small></h2><p><?= $e($stage['description']) ?></p></div></li>
    <?php endforeach ?>
    </ol>
    <?php if ($timeline['go_live']): $live = $timeline['go_live']; ?>
    <section class="card"><h2>Connect your domain</h2><p><?= $e($live['message']) ?></p>
        <p>Set these records for <strong><?= $e($live['domain']) ?></strong> at your DNS provider. Remove old A and AAAA records for this hostname. There is no deadline.</p>
        <table><thead><tr><th>Type</th><th>Value</th></tr></thead><tbody><?php foreach ($live['records'] as $record): ?><tr><td><?= $e($record['type']) ?></td><td><code><?= $e($record['value']) ?></code></td></tr><?php endforeach ?></tbody></table>
        <form method="post" action="/deployments/live"><?= $csrf() ?><input type="hidden" name="operation" value="<?= $e($live['operation']) ?>"><button class="wz-ok">Check DNS and go live</button></form>
        <p class="wz-hint">We’ll verify DNS and HTTPS before marking your app live. You can leave this page and return later.</p>
    </section>
    <?php endif ?>
    <?php if ($timeline['problem']): ?><section class="card" role="alert"><h2><?= $e($timeline['problem']['title']) ?></h2><ul><?php foreach ($timeline['problem']['suggestions'] as $suggestion): ?><li><?= $e($suggestion) ?></li><?php endforeach ?></ul></section><?php endif ?>
    <?php if ($timeline['status'] === 'succeeded' && $timeline['domain']): ?><p><a class="wz-ok" href="https://<?= $e($timeline['domain']) ?>">Open your app ↗</a></p><?php endif ?>
    <?php if ($timeline['retry']): ?><form method="post" action="/deployments/change"><?= $csrf() ?><input type="hidden" name="command" value="redeploy-app"><input type="hidden" name="app" value="<?= $e($timeline['retry']['app']) ?>"><input type="hidden" name="release" value="<?= $e($timeline['retry']['release']) ?>"><button class="wz-ok">Retry deployment</button></form><?php endif ?>
    <section class="card"><h2>Activity</h2><ol class="deployment-activity"><?php foreach ($timeline['activity'] as $activity): ?><li><time><?= $e(date('H:i:s', $activity['at'])) ?></time> <?= $e($activity['text']) ?></li><?php endforeach ?></ol></section>
    <details class="card"><summary>Diagnostics</summary><p>Technical details for troubleshooting.</p><button type="button" class="link-button" data-copy-diagnostics>Copy diagnostics</button><pre id="timeline-diagnostics"><?= $e(json_encode($timeline['diagnostics'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre></details>
    <p data-timeline-message role="status"></p>
</main>
