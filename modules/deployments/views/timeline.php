<?php
$app_id = (string) ($timeline['app_id'] ?? '');
$service_meta = is_array($timeline['service'] ?? null) ? $timeline['service'] : null;
$timeline_kind = (string) ($timeline['kind'] ?? 'deploy');
$back_href = (string) ($timeline['back_href'] ?? ($app_id !== '' ? '/apps/show/' . rawurlencode($app_id) : '/apps'));
$back_label = (string) ($timeline['back_label'] ?? ($app_id !== '' ? 'Back to ' . $timeline['app'] : 'Back to your apps'));
$heading = (string) ($timeline['heading'] ?? ($timeline['app'] . ' · Deployment'));
$actor_ctx = is_array($actor ?? null) ? $actor : null;
$can_deploy = $actor_ctx === null || ($app_id !== '' && Access_policy::can_app($actor_ctx, $app_id, 'deploy'));
$can_manage_service = $actor_ctx === null || ($app_id !== ''
    ? Access_policy::can_app($actor_ctx, $app_id, 'services_manage')
    : Access_policy::can_workspace($actor_ctx, 'services.manage'));
$now_step = '';
foreach ($timeline['stages'] as $idx => $st) {
    if (($st['status'] ?? '') === 'running') {
        $now_step = 'Now: ' . ($idx + 1) . '. ' . $st['name'];
        break;
    }
}
?>
<main id="deployment-content" class="panel tc-panel" data-timeline="<?= $e($timeline['id']) ?>" data-status="<?= $e($timeline['status']) ?>">
    <?php if ($message): ?><p role="alert" class="wz-errors"><?= $e($message) ?></p><?php endif ?>
    <p style="margin-top:0;margin-bottom:18px"><a href="<?= $e($back_href) ?>" style="color:var(--accent-ink);text-decoration:none">&larr; <?= $e($back_label) ?></a></p>

    <section class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
            <h1 style="margin:0;font-size:24px"><?= $e($heading) ?> <code class="ov-sha"><?= $e(substr((string) $timeline['id'], 0, 8)) ?></code></h1>
            <span role="status" class="status-pill status-<?= $e($timeline['status']) ?>"><?= $timeline['status'] === 'waiting' ? 'Ready — waiting to go live' : $e(ucfirst($timeline['status'])) ?></span>
        </div>
        <dl class="deployment-meta">
            <?php if ($service_meta !== null): ?>
                <dt>Service</dt>
                <dd><?= $e($service_meta['name']) ?></dd>
                <?php if ($app_id !== ''): ?>
                    <dt>App</dt>
                    <dd><a href="/apps/show/<?= $e(rawurlencode($app_id)) ?>"><?= $e($timeline['app']) ?></a></dd>
                <?php endif ?>
                <?php if ($timeline_kind !== 'destroy-service'): ?>
                    <dt>Environment variable</dt>
                    <dd><code class="ov-sha"><?= $e($service_meta['env_var']) ?></code></dd>
                <?php endif ?>
                <?php if (!empty($service_meta['memory_mb']) && $timeline_kind !== 'destroy-service'): ?>
                    <dt>Memory</dt>
                    <dd><?= (int) $service_meta['memory_mb'] ?> MB</dd>
                <?php endif ?>
            <?php else: ?>
                <dt>App</dt>
                <dd><?php if ($app_id !== ''): ?><a href="/apps/show/<?= $e(rawurlencode($app_id)) ?>"><?= $e($timeline['app']) ?></a><?php else: ?><?= $e($timeline['app']) ?><?php endif ?></dd>
                <?php if ($timeline['domain']): ?>
                    <dt>Address</dt>
                    <dd><a href="https://<?= $e($timeline['domain']) ?>" target="_blank" rel="noopener" class="ov-mono">https://<?= $e($timeline['domain']) ?></a></dd>
                <?php endif ?>
            <?php endif ?>
        </dl>
    </section>

    <?php if ($timeline['problem']): ?>
        <section class="card" role="alert" style="border-left:4px solid #dc2626">
            <h2 style="margin-top:0;color:#991b1b"><?= $e($timeline['problem']['title']) ?></h2>
            <ul><?php foreach ($timeline['problem']['suggestions'] as $suggestion): ?><li><?= $e($suggestion) ?></li><?php endforeach ?></ul>
        </section>
    <?php endif ?>

    <section class="card steps-card">
        <div class="steps-heading">
            <h2>Steps</h2>
            <?php if ($now_step !== ''): ?><span class="step-now"><?= $e($now_step) ?></span><?php endif ?>
        </div>
        <ol class="steps deployment-timeline">
            <?php foreach ($timeline['stages'] as $idx => $stage):
                $sname = (string) $stage['name'];
                $sstatus = (string) $stage['status'];
                $slogs = is_array($stage['logs'] ?? null) ? $stage['logs'] : [];
                $auto_open = in_array($sstatus, ['running', 'failed'], true);
            ?>
                <li class="step" data-status="<?= $e($sstatus) ?>">
                    <span class="step-dot timeline-dot" aria-hidden="true"><?= $idx + 1 ?></span>
                    <div class="step-head">
                        <h3><?= $e($sname) ?></h3>
                        <span class="step-label"><?= $e($sstatus) ?></span>
                    </div>
                    <div class="step-body">
                        <p><?= $e($stage['description']) ?></p>
                        <?php if ($slogs !== []): ?>
                            <details class="step-about"<?= $auto_open ? ' open' : '' ?>>
                                <summary>Logs (<?= count($slogs) ?>)</summary>
                                <pre class="step-log"><?= $e(implode("\n", $slogs)) ?></pre>
                            </details>
                        <?php endif ?>
                    </div>
                </li>
            <?php endforeach ?>
        </ol>
    </section>

    <?php if ($timeline['go_live']): $live = $timeline['go_live']; ?>
        <section class="card">
            <h2>Connect your domain</h2>
            <p><?= $e($live['message']) ?></p>
            <p>Set these records for <strong><?= $e($live['domain']) ?></strong> at your DNS provider. Remove old A and AAAA records for this hostname. There is no deadline.</p>
            <table><thead><tr><th>Type</th><th>Value</th></tr></thead><tbody><?php foreach ($live['records'] as $record): ?><tr><td><?= $e($record['type']) ?></td><td><code><?= $e($record['value']) ?></code></td></tr><?php endforeach ?></tbody></table>
            <?php if ($can_deploy): ?>
                <form method="post" action="/deployments/live" mx-post="/deployments/live" mx-target="#deployment-content" mx-select="#deployment-content" mx-swap="outerHTML" mx-after-swap="timelineRefresh" mx-on-error="#mx-error-trigger" mx-on-timeout="panelMxTimeout"><?= $csrf() ?><input type="hidden" name="operation" value="<?= $e($live['operation']) ?>"><button class="btn-primary">Check DNS and go live</button></form>
                <p class="wz-hint">We’ll verify DNS and HTTPS before marking your app live. You can leave this page and return later.</p>
            <?php endif ?>
        </section>
    <?php endif ?>

    <?php if (!empty($timeline['canary']) && $app_id !== ''):
        $c = $timeline['canary'];
        $cw = (int) ($c['weight'] ?? 0);
    ?>
        <section class="card canary-box">
            <h2 style="margin-top:0">Canary rollout active &middot; <?= $cw === 0 ? 'Preview only (0%)' : $cw . '% traffic' ?></h2>
            <?php if ($timeline['domain']): ?>
                <p class="ov-links"><a href="https://<?= $e($timeline['domain']) ?>/?tc_canary=1" target="_blank" rel="noopener">Preview canary ↗</a> <a href="https://<?= $e($timeline['domain']) ?>/?tc_canary=0" target="_blank" rel="noopener">View stable ↗</a> <span class="muted">Header <code>X-Canary: 1</code></span></p>
            <?php endif ?>
            <?php if ($can_deploy): ?>
                <div class="canary-weights">
                    <?php foreach ([0 => '0% preview', 10 => '10%', 25 => '25%', 50 => '50%'] as $pct => $label): ?>
                        <form method="post" action="/deployments/change">
                            <?= $csrf() ?>
                            <input type="hidden" name="app" value="<?= $e($app_id) ?>">
                            <input type="hidden" name="canary_weight" value="<?= $pct ?>">
                            <button class="service-preset<?= $cw === $pct ? ' is-active' : '' ?>" name="command" value="canary-weight"<?= $cw === $pct ? ' disabled' : '' ?>><?= $e($label) ?></button>
                        </form>
                    <?php endforeach ?>
                </div>
                <div class="canary-actions">
                    <form method="post" action="/deployments/change" data-confirm="Promote this canary to 100% of traffic?">
                        <?= $csrf() ?>
                        <input type="hidden" name="app" value="<?= $e($app_id) ?>">
                        <button class="btn-primary" name="command" value="canary-promote">Promote to 100%</button>
                    </form>
                    <form method="post" action="/deployments/change" data-confirm="Abort this canary and route 100% of traffic back to the stable release?">
                        <?= $csrf() ?>
                        <input type="hidden" name="app" value="<?= $e($app_id) ?>">
                        <button class="btn-secondary" name="command" value="canary-abort">Abort canary</button>
                    </form>
                </div>
            <?php endif ?>
        </section>
    <?php elseif ($timeline['status'] === 'succeeded' && $service_meta !== null): ?>
        <section class="card" style="display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap">
            <p style="margin:0;font-size:14px">
                <?php if ($timeline_kind === 'destroy-service'): ?>
                    <strong><?= $e($service_meta['name']) ?></strong> has been removed.
                <?php elseif ($app_id !== ''): ?>
                    <strong><?= $e($service_meta['name']) ?></strong> is running. Deploy <strong><?= $e($timeline['app']) ?></strong> to make <code class="ov-sha"><?= $e($service_meta['env_var']) ?></code> available to your app.
                <?php else: ?>
                    <strong><?= $e($service_meta['name']) ?></strong> is running. Deploy your apps to make <code class="ov-sha"><?= $e($service_meta['env_var']) ?></code> available.
                <?php endif ?>
            </p>
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
                <?php if ($can_deploy && $timeline_kind === 'deploy-service' && $app_id !== ''): ?>
                    <form method="post" action="/apps/deploy" style="margin:0">
                        <?= $csrf() ?>
                        <input type="hidden" name="app" value="<?= $e($app_id) ?>">
                        <button type="submit" class="btn-primary">Deploy <?= $e($timeline['app']) ?> now</button>
                    </form>
                <?php endif ?>
                <a class="btn-secondary" href="<?= $e($back_href) ?>"><?= $e($back_label) ?></a>
            </div>
        </section>
    <?php elseif ($timeline['status'] === 'succeeded' && $timeline['domain']): ?>
        <p style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:24px">
            <a class="btn-primary" href="https://<?= $e($timeline['domain']) ?>" target="_blank" rel="noopener">Open your app ↗</a>
            <?php if ($app_id !== ''): ?><a class="btn-secondary" href="/apps/show/<?= $e(rawurlencode($app_id)) ?>">App overview</a><?php endif ?>
        </p>
    <?php endif ?>

    <?php if ($can_manage_service && !empty($service_meta['can_retry'])): ?>
        <p style="margin-bottom:24px">
            <form method="post" action="/services/redeploy">
                <?= $csrf() ?>
                <?php if ($app_id !== ''): ?><input type="hidden" name="app" value="<?= $e($app_id) ?>"><?php endif ?>
                <input type="hidden" name="service" value="<?= $e($service_meta['id']) ?>">
                <button type="submit" class="btn-primary">Retry service deployment</button>
            </form>
        </p>
    <?php endif ?>

    <?php if ($can_deploy && $timeline['retry']): ?>
        <p style="margin-bottom:24px"><form method="post" action="/deployments/change"><?= $csrf() ?><input type="hidden" name="command" value="redeploy-app"><input type="hidden" name="app" value="<?= $e($timeline['retry']['app']) ?>"><input type="hidden" name="release" value="<?= $e($timeline['retry']['release']) ?>"><button class="btn-primary">Retry deployment</button></form></p>
    <?php endif ?>

    <section class="card">
        <h2 style="margin-top:0">Activity</h2>
        <ol class="deployment-activity"><?php foreach ($timeline['activity'] as $activity): ?><li><time><?= $e(date('H:i:s', $activity['at'])) ?></time> <?= $e($activity['text']) ?></li><?php endforeach ?></ol>
    </section>

    <details class="card">
        <summary>Diagnostics</summary>
        <p class="muted">Technical details for troubleshooting.</p>
        <button type="button" class="link-button" data-copy-diagnostics>Copy diagnostics</button>
        <pre id="timeline-diagnostics"><?= $e(json_encode($timeline['diagnostics'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
    </details>
    <p data-timeline-message role="status"></p>
</main>

<span hidden id="timeline-poll" mx-get="/deployments?operation=<?= $e(rawurlencode($timeline['id'])) ?>" mx-trigger="<?= in_array($timeline['status'], ['pending', 'running'], true) ? 'every 3s' : 'none' ?>" mx-target="#deployment-content" mx-select="#deployment-content" mx-swap="outerHTML" mx-after-swap="timelineRefresh" mx-on-error="#mx-error-trigger"></span>
