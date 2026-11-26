<?php
$actor_ctx = is_array($actor ?? null) ? $actor : null;
$can_services_nav = $actor_ctx === null || Access_policy::can_workspace($actor_ctx, 'services.view');
?>
<section class="ov-card">
    <div class="ov-card-head">
        <h2>Recent deployments</h2>
        <a href="/jobs">All jobs</a>
    </div>
    <?php if (!$jobs): ?>
        <p class="ov-empty muted">Your first deployment will appear here.</p>
    <?php else: ?>
        <div class="ov-table-wrap">
            <table class="deployments-table">
                <thead>
                    <tr>
                        <th>Status</th>
                        <th>Operation</th>
                        <th>App</th>
                        <th>Started</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $app_ids = array_flip(array_map(static fn($a) => (string) ($a['id'] ?? ''), $inventory['apps'] ?? []));
                foreach (array_slice($jobs, 0, 12) as $op):
                    $opTarget = (string) ($op['target'] ?? $op['kind']);
                    $opTargetKind = (string) ($op['target_kind'] ?? 'other');
                    $started = (int) ($op['started_at'] ?? floor(((int) ($op['created'] ?? 0)) / 1000000));
                ?>
                    <tr>
                        <td><a href="/deployments?operation=<?= $e($op['id']) ?>" class="status-pill status-<?= $e($op['status']) ?>"><?= $e($op['status']) ?></a></td>
                        <td><a href="/deployments?operation=<?= $e($op['id']) ?>"><?= $e($op['title'] ?? $op['kind']) ?></a></td>
                        <td>
                            <?php if ($opTargetKind === 'app' && isset($app_ids[$opTarget])): ?>
                                <a href="/apps/show/<?= $e(rawurlencode($opTarget)) ?>"><?= $e($opTarget) ?></a>
                            <?php elseif ($opTargetKind === 'service' && $can_services_nav): ?>
                                <a href="/services"><?= $e($opTarget) ?></a>
                            <?php else: ?>
                                <?= $e($opTarget) ?>
                            <?php endif ?>
                        </td>
                        <td class="muted"><?= $started > 0 ? $e(gmdate('Y-m-d H:i', $started)) . ' UTC' : '—' ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    <?php endif ?>
</section>
