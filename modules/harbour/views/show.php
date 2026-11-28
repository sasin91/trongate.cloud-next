<?php
$ns = (string) ($module['namespace'] ?? '');
$name = (string) ($module['name'] ?? '');
$slug = (string) ($module['slug'] ?? ($ns . '/' . $name));
$latest = (string) ($module['latest_version'] ?? '');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= $e($title) ?></title>
    <link rel="stylesheet" href="/css/trongate.css">
    <link rel="stylesheet" href="/templates_module/css/wizard.css">
    <link rel="stylesheet" href="/templates_module/css/panel.css">
    <link rel="stylesheet" href="/harbour_module/css/harbour.css">
    <script src="/harbour_module/js/harbour.js" defer></script>
</head>
<body class="reg-body">
    <header class="wz-top tc-header">
        <div class="wz-top-row tc-header-row">
            <div class="tc-header-left">
                <a class="wz-brand" href="/harbour"><?php require APPPATH . 'modules/templates/views/_mark.php'; ?><span>trongate<b>.cloud</b></span></a>
                <span class="tc-header-sep" aria-hidden="true"></span>
                <nav class="tc-crumbs" aria-label="Breadcrumb">
                    <a href="/harbour">Module Harbour</a>
                    <span class="tc-crumb-sep" aria-hidden="true">/</span>
                    <a href="/harbour/show/<?= $e(rawurlencode($ns)) ?>/<?= $e(rawurlencode($name)) ?>" aria-current="page"><?= $e($slug) ?></a>
                </nav>
            </div>
            <nav class="tc-nav">
                <a href="/harbour">Harbour</a>
                <?php if ($actor !== null): ?>
                    <a href="<?= $e($panel_url) ?>/harbour/manage">Manage &amp; Publish</a>
                    <a href="<?= $e(($panel_url !== '' ? $panel_url : '') . '/apps') ?>">Apps</a>
                <?php else: ?>
                    <a href="<?= $e(($panel_url !== '' ? $panel_url : '') . '/login') ?>">Sign in</a>
                <?php endif ?>
            </nav>
        </div>
    </header>

    <main class="reg-shell">
        <section class="reg-hero">
            <div class="reg-hero-top" style="margin-bottom:0">
                <div>
                    <div class="reg-slug"><?= $e($slug) ?> &middot; by <?= $e($module['author'] ?? $ns) ?> &middot; <?= $e($module['license'] ?? 'MIT') ?></div>
                    <h1><?= $e($module['title'] ?? $name) ?></h1>
                    <p><?= $e(($module['summary'] ?? '') !== '' ? $module['summary'] : 'A free, self-contained Trongate module.') ?></p>
                </div>
                <div class="reg-badges">
                    <?php if ($latest !== ''): ?>
                        <span class="reg-pill reg-pill-ver">v<?= $e($latest) ?></span>
                    <?php endif ?>
                </div>
            </div>
        </section>

        <div class="reg-detail-grid">
            <div class="ov-col">
                <section class="ov-card">
                    <div class="ov-card-head">
                        <h2>Documentation</h2>
                        <span class="muted">ZFS read-only snapshot</span>
                    </div>
                    <div class="ov-pad">
                        <?php if ($readme_html !== ''): ?>
                            <div class="reg-readme"><?= $readme_html ?></div>
                        <?php else: ?>
                            <p class="muted"><?= $e(($module['description'] ?? '') !== '' ? $module['description'] : 'No README.md published for this module yet.') ?></p>
                        <?php endif ?>
                    </div>
                </section>

                <section class="ov-card">
                    <div class="ov-card-head">
                        <h2>Published versions</h2>
                        <span class="muted"><?= count($versions) ?> release(s)</span>
                    </div>
                    <?php if ($versions === []): ?>
                        <p class="ov-empty muted">No versions have docked yet. Push a semver tag (<code>v1.0.0</code>) to this module's Git repository to publish the first one.</p>
                    <?php else: ?>
                        <div class="ov-table-wrap">
                            <table class="deployments-table">
                                <thead>
                                    <tr>
                                        <th>Version</th>
                                        <th>Manifest SHA-256</th>
                                        <th>Archive</th>
                                        <th>Published</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($versions as $ver): ?>
                                        <?php $v = (string) ($ver['version'] ?? ''); ?>
                                        <tr>
                                            <td><span class="reg-pill reg-pill-ver">v<?= $e($v) ?></span></td>
                                            <td><code class="ov-sha" title="<?= $e($ver['digest'] ?? '') ?>"><?= $e(substr((string) ($ver['digest'] ?? ''), 0, 19)) ?>&hellip;</code></td>
                                            <td>
                                                <a href="<?= $e(Module_spec::archive_url($ns, $name, $v)) ?>">.tar.gz</a>
                                                <?php if (!empty($ver['archive_bytes'])): ?>
                                                    <span class="muted">(<?= $e(number_format(((int) $ver['archive_bytes']) / 1024, 1)) ?> KB)</span>
                                                <?php endif ?>
                                            </td>
                                            <td class="muted"><?= !empty($ver['published_at']) ? $e(gmdate('Y-m-d', (int) $ver['published_at'])) : '—' ?></td>
                                        </tr>
                                    <?php endforeach ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif ?>
                </section>
            </div>

            <aside class="ov-col">
                <section class="ov-card">
                    <div class="ov-card-head">
                        <h2>Haul it aboard</h2>
                        <span class="muted">Free &amp; open source</span>
                    </div>
                    <div class="ov-pad">
                        <?php if ($latest === ''): ?>
                            <p class="form-hint" style="margin-top:0">This crate has no published version yet. Check back once the author pushes a release tag.</p>
                        <?php else: ?>
                            <p class="form-hint" style="margin-top:0">Run this from your app's repository root. The module lands in <code>modules/<?= $e($name) ?></code> and becomes your code: read it, tweak it, keep it.</p>
                            <?php if (!empty($module['repo_ready'])): ?>
                                <pre class="reg-code"><?= $e(Module_spec::subtree_command($ns, $name, $latest)) ?></pre>
                                <p class="form-hint">No Git subtree? Unpack the archive instead:</p>
                            <?php endif ?>
                            <pre class="reg-code"><?= $e(Module_spec::archive_command($ns, $name, $latest)) ?></pre>
                            <p class="form-hint">Then commit and push. Your next trongate.cloud deploy carries the module, and the app's <strong>Modules</strong> tab will spot it and keep a lookout for newer versions.</p>
                        <?php endif ?>
                    </div>
                </section>

                <?php if ($latest !== '' && !empty($module['repo_ready'])): ?>
                    <section class="ov-card">
                        <div class="ov-card-head">
                            <h2>Already aboard?</h2>
                            <span class="muted">Update</span>
                        </div>
                        <div class="ov-pad">
                            <p class="form-hint" style="margin-top:0">Pull the latest version into an existing Git subtree install:</p>
                            <pre class="reg-code"><?= $e(Module_spec::subtree_command($ns, $name, $latest, true)) ?></pre>
                        </div>
                    </section>
                <?php endif ?>
            </aside>
        </div>
    </main>
    <?php require __DIR__ . '/_footer.php'; ?>
</body>
</html>
