<?php
$app_ids = array_flip(array_map(static fn($a) => (string) ($a['id'] ?? ''), $inventory['apps'] ?? []));
$groups = [];
$counts = ['active' => 0, 'failed' => 0];
$today = gmdate('Y-m-d');
$yesterday = gmdate('Y-m-d', time() - 86400);
$can_reconcile = !empty($can_reconcile);
$worker_diagnostics = is_array($worker_diagnostics ?? null) ? $worker_diagnostics : null;

foreach ($jobs as $job) {
    $st = (string) ($job['status'] ?? '');
    if (in_array($st, ['pending', 'running', 'waiting'], true)) {
        $counts['active']++;
    } elseif (in_array($st, ['failed', 'uncertain'], true)) {
        $counts['failed']++;
    }
    $ts = (int) ($job['started_at'] ?? floor(((int) ($job['created'] ?? 0)) / 1000000));
    $day_key = $ts > 0 ? gmdate('Y-m-d', $ts) : 'unknown';
    if (!isset($groups[$day_key])) {
        $label = match ($day_key) {
            $today => 'Today · ' . gmdate('j M Y', $ts),
            $yesterday => 'Yesterday · ' . gmdate('j M Y', $ts),
            'unknown' => 'Earlier',
            default => gmdate('j M Y', $ts),
        };
        $groups[$day_key] = ['label' => $label, 'items' => []];
    }
    $groups[$day_key]['items'][] = $job;
}
?>
<main class="panel tc-panel">
    <div class="section-header">
        <div>
            <p class="eyebrow">BACKGROUND ACTIVITY</p>
            <h1>Jobs</h1>
            <p class="muted" style="margin:6px 0 0">Builds, deployments, canary updates, and services running in the background.</p>
        </div>
        <div class="job-stats">
            <span class="ov-badge"><?= count($jobs) ?> recent</span>
            <?php if ($counts['active'] > 0): ?>
                <span class="status-pill status-running"><?= $counts['active'] ?> active</span>
            <?php endif ?>
            <?php if ($counts['failed'] > 0): ?>
                <span class="status-pill status-failed"><?= $counts['failed'] ?> need attention</span>
            <?php endif ?>
        </div>
    </div>

    <?php if ($message !== null): ?><p role="status" class="card" style="padding:14px 18px"><?= $e($message) ?></p><?php endif ?>
    <?php if (is_array($inspection_error ?? null)): ?>
        <p role="alert" class="notice wz-errors">Inspection error: <?= $e($inspection_error['type'] . ' at ' . $inspection_error['file'] . ':' . $inspection_error['line']) ?></p>
    <?php endif ?>

    <?php if ($worker_diagnostics !== null):
        $queue = $worker_diagnostics['queue'];
        $worker = $worker_diagnostics['worker'];
        $samples = array_map('intval', $worker['worker_process_samples'] ?? []);
    ?>
        <section class="ov-card ov-pad" aria-labelledby="worker-diagnostics-heading">
            <h2 id="worker-diagnostics-heading">Worker diagnostics</h2>
            <p>Queue: <?= $e($queue['status']) ?>; claim attempts: <?= (int) $queue['attempts'] ?>; lease active: <?= $queue['lease_active'] ? 'yes' : 'no' ?>.</p>
            <p>Resource lock: <?= $e($queue['resource_lock']) ?><?= $queue['blocking_job_status'] !== null ? ' (blocking job: ' . $e($queue['blocking_job_status']) . ')' : '' ?>.</p>
            <p>tc_panel jail: <?= $worker['panel_jail_running'] ? 'running' : 'not running' ?>; tc_worker service: <?= $worker['worker_service_active'] ? 'active' : 'inactive' ?>.</p>
            <p>PHP worker process counts across three samples: <?= $samples ? $e(implode(', ', $samples)) : 'not sampled (panel jail is stopped)' ?>.</p>
            <p>Deployed worker file matches this panel deployment: <?= $worker['worker_source_matches_panel'] === null ? 'unavailable' : ($worker['worker_source_matches_panel'] ? 'yes' : 'no') ?>.</p>
            <?php $php_error = $worker['latest_php_error'] ?? null; ?>
            <p>Latest uncaught panel PHP error: <?= $php_error === null ? 'not found in the log' : $e($php_error['type'] . ' at ' . $php_error['file'] . ':' . $php_error['line']) ?>.</p>
        </section>
    <?php endif ?>

    <?php if (!$jobs): ?>
        <section class="ov-card ov-pad">
            <p class="muted">No jobs yet. Deploy an app or add a service to see activity here.</p>
        </section>
    <?php else: ?>
        <?php foreach ($groups as $group): ?>
            <section class="ov-card job-day-card">
                <div class="ov-card-head">
                    <h2><?= $e($group['label']) ?></h2>
                    <span class="muted"><?= count($group['items']) ?> <?= count($group['items']) === 1 ? 'job' : 'jobs' ?></span>
                </div>
                <ol class="job-timeline">
                    <?php foreach ($group['items'] as $job):
                        $st = (string) ($job['status'] ?? 'pending');
                        $ts = (int) ($job['started_at'] ?? 0);
                        $target = (string) ($job['target'] ?? $job['kind']);
                        $target_kind = (string) ($job['target_kind'] ?? 'other');
                        $title = (string) ($job['title'] ?? $job['kind']);
                        $commit = (string) ($job['commit'] ?? '');
                        $slot = (string) ($job['slot'] ?? '');
                        $error = (string) ($job['error'] ?? '');
                    ?>
                        <li class="job-item" data-status="<?= $e($st) ?>">
                            <span class="job-time"><?= $ts > 0 ? $e(gmdate('H:i:s', $ts)) : '—' ?></span>
                            <span class="job-dot" aria-hidden="true"></span>
                            <div class="job-body">
                                <div class="job-head">
                                    <a class="job-title" href="/deployments?operation=<?= $e(rawurlencode($job['id'])) ?>"><?= $e($title) ?></a>
                                    <span class="status-pill status-<?= $e($st) ?>"><?= $e($st) ?></span>
                                </div>
                                <div class="job-meta">
                                    <?php if ($target_kind === 'app' && isset($app_ids[$target])): ?>
                                        <a href="/apps/show/<?= $e(rawurlencode($target)) ?>"><?= $e($target) ?></a>
                                    <?php elseif ($target_kind === 'service'): ?>
                                        <a href="/services"><?= $e($target) ?></a>
                                    <?php else: ?>
                                        <span><?= $e($target) ?></span>
                                    <?php endif ?>
                                    <span>&middot;</span>
                                    <span><?= $e($job['kind']) ?></span>
                                    <?php if ($commit !== ''): ?>
                                        <span>&middot;</span>
                                        <code class="ov-sha"><?= $e(substr($commit, 0, 7)) ?></code>
                                    <?php endif ?>
                                    <span>&middot;</span>
                                    <code class="ov-mono"><?= $e(substr((string) $job['id'], 0, 8)) ?></code>
                                </div>
                                <?php if ($error !== '' && in_array($st, ['failed', 'uncertain'], true)): ?>
                                    <p class="job-error"><?= $e($error) ?></p>
                                <?php endif ?>
                                <?php if ($can_reconcile && $st === 'uncertain'): ?>
                                    <div class="job-reconcile">
                                        <span class="muted">Check remote state before reconciling; the resource lock stays held until verified.</span>
                                        <form method="post">
                                            <?= $csrf() ?>
                                            <input type="hidden" name="action" value="reconcile">
                                            <input type="hidden" name="job" value="<?= $e($job['id']) ?>">
                                            <button class="btn-secondary">Reconcile job</button>
                                        </form>
                                    </div>
                                <?php endif ?>
                                <?php if ($job['kind'] === 'build' && in_array($st, ['pending', 'running'], true)): ?>
                                    <form method="post">
                                        <?= $csrf() ?>
                                        <input type="hidden" name="action" value="inspect-worker">
                                        <input type="hidden" name="job" value="<?= $e($job['id']) ?>">
                                        <button class="btn-secondary">Inspect worker</button>
                                    </form>
                                <?php endif ?>
                            </div>
                            <a class="job-link" href="/deployments?operation=<?= $e(rawurlencode($job['id'])) ?>">View &rarr;</a>
                        </li>
                    <?php endforeach ?>
                </ol>
            </section>
        <?php endforeach ?>
    <?php endif ?>
</main>
