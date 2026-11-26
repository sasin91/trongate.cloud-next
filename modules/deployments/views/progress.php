<?php
$job = $operation['job'];
$result = json_decode((string) $job['result'], true) ?: [];
$payload = json_decode((string) $job['payload'], true) ?: [];
$target_display = (string) ($payload['app'] ?? $payload['launch']['app'] ?? $payload['plan']['repository'] ?? $job['kind']);
$diag = array_filter([
    'operation' => $job['id'],
    'status' => $job['status'],
    'phase' => $result['phase'] ?? null,
    'error' => $result['error'] ?? $result['reason'] ?? null,
], static fn($v) => $v !== null && $v !== '');
?>
<main id="deployment-content" data-status="<?= $e($job['status']) ?>" class="panel tc-panel" data-operation="<?= $e($job['id']) ?>">
    <p style="margin-top:0;margin-bottom:18px"><a href="/apps" style="color:var(--accent-ink);text-decoration:none">&larr; Back to your apps</a></p>
    <section class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
            <h1 style="margin:0;font-size:24px"><?= $e(ucfirst(str_replace('-', ' ', (string) $job['kind']))) ?> <code class="ov-sha"><?= $e(substr((string) $job['id'], 0, 8)) ?></code></h1>
            <span class="status-pill status-<?= $e($job['status']) ?>" id="deployment-status"><?= $e(ucfirst((string) $job['status'])) ?></span>
        </div>
        <dl class="deployment-meta">
            <dt>Target</dt><dd><?= $e($target_display) ?></dd>
            <?php if (isset($payload['plan']['commit'])): ?>
                <dt>Commit</dt><dd><code class="ov-sha"><?= $e(substr((string) $payload['plan']['commit'], 0, 7)) ?></code></dd>
            <?php endif ?>
        </dl>
    </section>
    <p id="poll-message" role="status"></p>
    <section class="card">
        <h2 style="margin-top:0">Activity</h2>
        <ol class="deployment-activity" id="logs-content">
            <?php foreach ($operation['steps'] as $step):
                $sdata = json_decode((string) ($step['data'] ?? ''), true) ?: [];
                $stext = match ($step['step']) {
                    'claimed' => 'Operation started.',
                    'progress' => Deployments_model::_phase_label((string) ($sdata['phase'] ?? '')),
                    'observed' => 'Finished with status: ' . (string) ($sdata['status'] ?? $job['status']) . '.',
                    default => ucfirst((string) $step['step']) . '.',
                };
            ?>
                <li><time><?= $e(gmdate('H:i:s', (int) $step['at'])) ?></time> <?= $e($stext) ?></li>
            <?php endforeach ?>
        </ol>
        <?php if (!empty($result['deployment'])): ?>
            <p style="margin-top:16px"><a id="next-deployment" class="btn-primary" href="/deployments?operation=<?= $e(rawurlencode((string) $result['deployment'])) ?>">Follow deployment &rarr;</a></p>
        <?php endif ?>
        <?php if ($job['status'] === 'uncertain'): ?>
            <p id="uncertain-help" class="muted" style="margin-top:12px">The outcome needs verification before using reconciliation on the Jobs page. Do not start a duplicate operation.</p>
        <?php endif ?>
    </section>
    <details class="card">
        <summary>Diagnostics</summary>
        <p class="muted">Technical details for troubleshooting.</p>
        <pre id="deployment-result"><?= $e(json_encode($diag, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
    </details>
</main>

<span hidden id="timeline-poll" mx-get="/deployments?operation=<?= $e(rawurlencode($job['id'])) ?>" mx-trigger="<?= in_array($job['status'], ['pending', 'running'], true) ? 'every 3s' : 'none' ?>" mx-target="#deployment-content" mx-select="#deployment-content" mx-swap="outerHTML" mx-after-swap="timelineRefresh" mx-on-error="#mx-error-trigger"></span>
