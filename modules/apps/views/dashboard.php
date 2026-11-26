<?php
$actor_ctx = is_array($actor ?? null) ? $actor : null;
$can_create_apps = $actor_ctx === null || Access_policy::can_workspace($actor_ctx, 'apps.create');
$can_view_jobs = $actor_ctx === null || Access_policy::can_access_jobs_page($actor_ctx);
?>
<main class="panel tc-panel">
    <div class="section-header">
        <div>
            <p class="eyebrow">YOUR WORKSPACE</p>
            <h1>Your apps.</h1>
        </div>
        <?php if ($can_create_apps): ?>
            <a class="btn-create" href="/apps/create">+ New app</a>
        <?php endif ?>
    </div>

    <?php if ($can_create_apps && isset($_SESSION['app_draft'])): ?>
        <section class="welcome card">
            <div class="wz-q">
                <p class="eyebrow">PICK UP WHERE YOU LEFT OFF</p>
                <h2>Finish setting up your app.</h2>
                <p>Your saved repository, settings, and wizard progress are ready to continue.</p>
            </div>
            <a class="btn-primary" href="/apps/create">Continue app setup →</a>
        </section>
    <?php endif ?>

    <?php if (!$inventory['apps']): ?>
        <?php if ($can_create_apps): ?>
            <section class="welcome card">
                <div class="wz-q">
                    <h2>Welcome aboard. Let’s put your first app online.</h2>
                    <p>Paste a public GitHub repository, confirm build commands and environment variables, and go live with automatic HTTPS.</p>
                </div>
                <div class="onboarding">
                    <a class="onboarding-step" href="/apps/create">
                        <span>01</span>
                        <strong>Connect your repository</strong>
                        <small>Paste a public GitHub URL and choose the branch to deploy</small>
                    </a>
                    <a class="onboarding-step" href="/apps/create">
                        <span>02</span>
                        <strong>Configure and build</strong>
                        <small>Auto-detected build commands, MariaDB, and encrypted secrets</small>
                    </a>
                    <div class="onboarding-step">
                        <span>03</span>
                        <strong>Go live with zero downtime</strong>
                        <small>Health-checked rollout, instant rollback, and canary splits</small>
                    </div>
                </div>
                <p style="margin-top:24px;margin-bottom:0"><a class="btn-primary" href="/apps/create">Deploy your first app →</a></p>
            </section>
        <?php else: ?>
            <section class="welcome card">
                <div class="wz-q">
                    <h2>No apps shared with you yet.</h2>
                    <p>Ask a workspace administrator to grant you access to an app.</p>
                </div>
            </section>
        <?php endif ?>
    <?php else: ?>
        <div class="dash-grid">
            <?php foreach ($inventory['apps'] as $app):
                $app_id = (string) $app['id'];
                $releases = array_values(array_filter($inventory['releases'], fn($r) => ($r['app'] ?? '') === $app_id));
                usort($releases, fn($a, $b) => ($b['created'] ?? 0) <=> ($a['created'] ?? 0));
                $latest = $releases[0] ?? null;
                $summary = $app_summaries[$app_id] ?? null;
                $live_state = is_array($summary) ? $summary : [
                    'state' => !empty($app['canary']) ? 'canary' : ($latest ? 'running' : 'not_live'),
                    'label' => !empty($app['canary']) ? 'Canary ' . (int) ($app['canary']['weight'] ?? 0) . '%' : ($latest ? 'Ready' : 'Not live yet'),
                    'detail' => '',
                    'commit' => (string) ($latest['metadata']['commit'] ?? $latest['spec']['commit'] ?? ''),
                ];
                $active_commit = (string) ($live_state['commit'] ?: ($latest['metadata']['commit'] ?? $latest['spec']['commit'] ?? ''));
                $php_version = (string) ($latest['spec']['php_version'] ?? $app['php_version'] ?? '8.4');
            ?>
                <a href="/apps/show/<?= $e(rawurlencode($app_id)) ?>" class="card app-card">
                    <div class="app-card-head">
                        <h3><?= $e($app['label'] ?? $app_id) ?></h3>
                        <span class="status-pill live-<?= $e($live_state['state']) ?>" title="<?= $e($live_state['detail']) ?>"><?= $e($live_state['label']) ?></span>
                    </div>
                    <div class="card-meta">
                        <span class="ov-mono"><?= $e($app['domain'] ?? '') ?></span>
                        <?php if ($active_commit !== ''): ?>
                            <span>&bull;</span>
                            <code><?= $e(substr($active_commit, 0, 7)) ?></code>
                        <?php endif ?>
                    </div>
                    <div class="card-meta app-card-foot">
                        <span>PHP <?= $e($php_version) ?></span>
                        <?php if (!empty($app['repository'])): ?>
                            <span>&bull;</span>
                            <span><?= $e($app['repository']) ?><?php if (!empty($app['branch'])): ?> · <?= $e($app['branch']) ?><?php endif ?></span>
                        <?php endif ?>
                    </div>
                </a>
            <?php endforeach ?>
        </div>
    <?php endif ?>

    <?php if ($can_view_jobs): ?>
        <?php $this->deployments->_display('list', $viewData); ?>
    <?php endif ?>
</main>
