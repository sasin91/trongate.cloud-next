<?php
$active_tab = 'deployments';
$app = $overview['app'];
$app_id = (string) $app['id'];
$label = (string) ($app['label'] ?? $app_id);
$releases = $overview['releases'];
$deployments = $overview['deployments'];
$canary = $overview['canary'];
$actor_ctx = is_array($actor ?? null) ? $actor : null;
$can_deploy = $actor_ctx === null || Access_policy::can_app($actor_ctx, $app_id, 'deploy');
?>
<main class="panel tc-panel">
    <div class="ov">
        <?php require __DIR__ . '/_header.php'; ?>

        <section class="ov-card" aria-labelledby="all-dep-h">
            <div class="ov-card-head">
                <h2 id="all-dep-h">Deployment history</h2>
                <span class="muted"><?= count($deployments) ?> <?= count($deployments) === 1 ? 'entry' : 'entries' ?></span>
            </div>
            <?php if ($deployments === []): ?>
                <p class="ov-empty muted">No deployments yet. Click <strong>Deploy now</strong> to build and ship your first release.</p>
            <?php else: ?>
                <div class="ov-table-wrap">
                    <table class="deployments-table">
                        <thead>
                            <tr>
                                <th>Status</th>
                                <th>Commit</th>
                                <th>Trigger</th>
                                <th>Started</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($deployments as $d): ?>
                                <tr>
                                    <td>
                                        <a href="/deployments?operation=<?= $e(rawurlencode($d['id'])) ?>" class="status-pill status-<?= $e($d['status']) ?>"><?= $e($d['status']) ?></a>
                                        <?php if ($d['is_live']): ?><span class="current-tag">live</span><?php endif ?>
                                    </td>
                                    <td>
                                        <?php if ($d['commit'] !== ''): ?>
                                            <code class="ov-sha"><?= $e(substr($d['commit'], 0, 7)) ?></code>
                                        <?php else: ?>
                                            <span class="muted">&mdash;</span>
                                        <?php endif ?>
                                    </td>
                                    <td class="muted"><?= $e($d['trigger']) ?></td>
                                    <td class="muted"><?= $d['started_at'] > 0 ? $e(gmdate('Y-m-d H:i:s', $d['started_at'])) . ' UTC' : '—' ?></td>
                                    <td class="row-actions" style="display:flex;gap:14px;align-items:center">
                                        <a href="/deployments?operation=<?= $e(rawurlencode($d['id'])) ?>">Logs</a>
                                        <?php if ($can_deploy && $canary === null && !$d['is_live'] && $d['release_id'] !== '' && $d['status'] === 'succeeded'): ?>
                                            <form method="post" action="/deployments/change" data-confirm="Deploy this release to <?= $e($label) ?>?">
                                                <?= $csrf() ?>
                                                <input type="hidden" name="app" value="<?= $e($app_id) ?>">
                                                <input type="hidden" name="release" value="<?= $e($d['release_id']) ?>">
                                                <button type="submit" class="btn-link" name="command" value="redeploy-app">Roll back to this</button>
                                            </form>
                                        <?php endif ?>
                                    </td>
                                </tr>
                            <?php endforeach ?>
                        </tbody>
                    </table>
                </div>
            <?php endif ?>
        </section>

        <section class="ov-card" aria-labelledby="rel-h">
            <div class="ov-card-head">
                <h2 id="rel-h">Built releases</h2>
                <span class="muted"><?= count($releases) ?> <?= count($releases) === 1 ? 'release' : 'releases' ?></span>
            </div>
            <?php if ($releases === []): ?>
                <p class="ov-empty muted">No releases built yet.</p>
            <?php else: ?>
                <div class="ov-pad">
                    <form method="post" action="/deployments/change">
                        <?= $csrf() ?>
                        <input type="hidden" name="app" value="<?= $e($app_id) ?>">
                        <?php require __DIR__ . '/releases.php'; ?>
                        <?php if ($can_deploy): ?>
                            <?php if ($canary === null): ?>
                                <div class="deploy-actions">
                                    <button class="btn-primary" name="command" value="redeploy-app">Deploy selected release</button>
                                    <div class="canary-launch">
                                        <label class="canary-split-label">
                                            <span>Split</span>
                                            <select name="canary_weight" aria-label="Canary traffic percentage">
                                                <option value="10" selected>10%</option>
                                                <option value="25">25%</option>
                                                <option value="50">50%</option>
                                                <option value="0">0% (preview)</option>
                                            </select>
                                        </label>
                                        <button class="btn-secondary" name="command" value="canary-app">Deploy as canary</button>
                                    </div>
                                </div>
                            <?php else: ?>
                                <p class="muted">Promote or abort the active canary on the <a href="/apps/show/<?= $e(rawurlencode($app_id)) ?>">Overview</a> tab before starting another deployment.</p>
                            <?php endif ?>
                        <?php endif ?>
                    </form>
                </div>
            <?php endif ?>
        </section>
    </div>
</main>
