<?php
$app = $overview['app'];
$app_id = (string) $app['id'];
$label = (string) ($app['label'] ?? $app_id);
$domain = (string) ($app['domain'] ?? '');
$repository = (string) ($app['repository'] ?? '');
$branch = (string) ($overview['settings']['branch'] ?? $app['branch'] ?? 'main');
$latest = $overview['latest_release'];
$live = $overview['live_state'];
$canary = $overview['canary'];
$can_rollback = (bool) $overview['can_rollback'];
$php_version = (string) ($latest['spec']['php_version'] ?? $app['php_version'] ?? '8.4');
$active_tab = $active_tab ?? 'overview';

$can_deploy = !empty($can_deploy);
$can_deployments = !empty($can_deployments);
$can_database = !empty($can_database);
$can_logs = !empty($can_logs);
$can_traffic = !empty($can_traffic);
$can_services = !empty($can_services);
$can_settings = !empty($can_settings);
$can_team = !empty($can_team);
$can_jobs = !empty($can_jobs);

$tabs = [
    ['/apps/show/' . rawurlencode($app_id), 'Overview', $active_tab === 'overview'],
];
if ($can_deployments) {
    $tabs[] = ['/apps/deployments/' . rawurlencode($app_id), 'Deployments', $active_tab === 'deployments'];
}
if ($can_database) {
    $tabs[] = ['/apps/database/' . rawurlencode($app_id), 'Database', $active_tab === 'database'];
}
if ($can_logs) {
    $tabs[] = ['/apps/logs/' . rawurlencode($app_id), 'Logs', $active_tab === 'logs'];
}
if ($can_traffic) {
    $tabs[] = ['/apps/traffic/' . rawurlencode($app_id), 'Traffic', $active_tab === 'traffic'];
}
if ($can_services) {
    $tabs[] = ['/apps/services/' . rawurlencode($app_id), 'Services', $active_tab === 'services'];
}
$tabs[] = ['/apps/modules/' . rawurlencode($app_id), 'Modules', $active_tab === 'modules'];
if ($can_jobs) {
    $tabs[] = ['/jobs', 'Jobs', false];
}
if ($can_settings) {
    $tabs[] = ['/apps/settings/' . rawurlencode($app_id), 'Settings', $active_tab === 'settings'];
}
if ($can_team) {
    $tabs[] = ['/apps/access/' . rawurlencode($app_id), 'Access', $active_tab === 'access'];
}
?>
        <div class="ov-head">
            <div class="ov-title">
                <div class="ov-title-row">
                    <h1><?= $e($label) ?></h1>
                    <span class="ov-live">
                        <span class="status-pill live-<?= $e($live['state']) ?>" title="<?= $e($live['detail']) ?>"><?= $e($live['label']) ?></span>
                        <?php if ($live['detail'] !== ''): ?>
                            <span class="muted"><?= $e($live['detail']) ?></span>
                        <?php endif ?>
                    </span>
                </div>
                <div class="ov-meta">
                    <?php if ($domain !== ''): ?>
                        <a href="https://<?= $e($domain) ?>" target="_blank" rel="noopener" class="ov-mono"><?= $e($domain) ?></a>
                    <?php endif ?>
                    <?php if ($repository !== ''): ?>
                        <span><?= $e($repository) ?> &middot; <?= $e($branch) ?></span>
                    <?php endif ?>
                    <span>PHP <?= $e($php_version) ?> &middot; <?= !empty($app['auto_deploy']) ? 'Deploy on push' : 'Manual deploys' ?></span>
                </div>
            </div>
            <?php if ($can_deploy): ?>
                <div class="ov-actions">
                    <?php if ($can_rollback): ?>
                        <form method="post" action="/deployments/change" data-confirm="Roll back <?= $e($label) ?> to its previous release?">
                            <?= $csrf() ?>
                            <input type="hidden" name="app" value="<?= $e($app_id) ?>">
                            <button type="submit" class="btn-secondary" name="command" value="rollback-app">Roll back</button>
                        </form>
                    <?php endif ?>
                    <?php if ($canary === null): ?>
                        <form method="post" action="/apps/deploy">
                            <?= $csrf() ?>
                            <input type="hidden" name="app" value="<?= $e($app_id) ?>">
                            <button type="submit" class="btn-primary">Deploy now</button>
                        </form>
                    <?php endif ?>
                </div>
            <?php endif ?>
        </div>

        <div class="ov-tabs-select">
            <select aria-label="App sections" onchange="window.location.href = this.value">
                <?php foreach ($tabs as [$thref, $tlabel, $tcur]): ?>
                    <option value="<?= $e($thref) ?>"<?= $tcur ? ' selected' : '' ?>><?= $e($tlabel) ?></option>
                <?php endforeach ?>
            </select>
            <svg viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" clip-rule="evenodd" d="M4.22 6.22a.75.75 0 0 1 1.06 0L8 8.94l2.72-2.72a.75.75 0 1 1 1.06 1.06l-3.25 3.25a.75.75 0 0 1-1.06 0L4.22 7.28a.75.75 0 0 1 0-1.06Z"/></svg>
        </div>
        <nav class="ov-tabs" aria-label="App sections">
            <?php foreach ($tabs as [$thref, $tlabel, $tcur]): ?>
                <a href="<?= $e($thref) ?>"<?= $tcur ? ' aria-current="page"' : '' ?>><?= $e($tlabel) ?></a>
            <?php endforeach ?>
        </nav>
