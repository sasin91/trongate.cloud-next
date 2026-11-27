<?php
$ns = (string) ($module['namespace'] ?? '');
$name = (string) ($module['name'] ?? '');
$slug = (string) ($module['slug'] ?? ($ns . '/' . $name));
$latest = (string) ($module['latest_version'] ?? '');
$can_autowire = Module_spec::can_autowire($name);
$manifest_snippet = json_encode([
    'modules' => [
        $slug => $latest !== '' ? $latest : '1.0.0',
    ],
    'autowire' => $can_autowire ? [$name => true] : new stdClass(),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
$schema = is_array($module['config_schema'] ?? null) ? $module['config_schema'] : [];
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
    <link rel="stylesheet" href="/registry_module/css/registry.css">
</head>
<body class="reg-body">
    <header class="wz-top tc-header">
        <div class="wz-top-row tc-header-row">
            <div class="tc-header-left">
                <a class="wz-brand" href="/registry"><?php require APPPATH . 'modules/templates/views/_mark.php'; ?><span>trongate<b>.cloud</b></span></a>
                <span class="tc-header-sep" aria-hidden="true"></span>
                <nav class="tc-crumbs" aria-label="Breadcrumb">
                    <a href="/registry">Module Registry</a>
                    <span class="tc-crumb-sep" aria-hidden="true">/</span>
                    <a href="/registry/show/<?= $e(rawurlencode($ns)) ?>/<?= $e(rawurlencode($name)) ?>" aria-current="page"><?= $e($slug) ?></a>
                </nav>
            </div>
            <nav class="tc-nav">
                <a href="/registry">Marketplace</a>
                <?php if ($actor !== null): ?>
                    <a href="/registry/manage">Manage &amp; Publish</a>
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
                    <p><?= $e(($module['summary'] ?? '') !== '' ? $module['summary'] : 'Immutable ZFS-backed Trongate module.') ?></p>
                </div>
                <div class="reg-badges">
                    <?php if ($latest !== ''): ?>
                        <span class="reg-pill reg-pill-ver">v<?= $e($latest) ?></span>
                    <?php endif ?>
                    <?php if (!empty($module['autowire'])): ?>
                        <span class="reg-pill reg-pill-autowire">Auto-wire ready</span>
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
                        <p class="ov-empty muted">No immutable versions have been published yet. Push a semver tag (<code>v1.0.0</code>) to this module's Git repository to materialize it on ZFS.</p>
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
                <?php if ($actor !== null && $apps !== [] && $versions !== []): ?>
                    <section class="ov-card">
                        <div class="ov-card-head">
                            <h2>Install in an app</h2>
                            <span class="muted">Deploy to apply</span>
                        </div>
                        <div class="ov-pad">
                            <form method="post" action="<?= $e(($panel_url !== '' ? $panel_url : '') . '/apps/insert_module') ?>" class="settings-form">
                                <?= $csrf() ?>
                                <input type="hidden" name="namespace" value="<?= $e($ns) ?>">
                                <input type="hidden" name="name" value="<?= $e($name) ?>">
                                <div class="settings-field">
                                    <label for="target_app">Application</label>
                                    <select id="target_app" name="app" style="padding:9px 10px;border:1px solid var(--border-strong);border-radius:8px;background:#fff">
                                        <?php foreach ($apps as $app_row): ?>
                                            <option value="<?= $e($app_row['id']) ?>"><?= $e($app_row['label'] ?? $app_row['id']) ?></option>
                                        <?php endforeach ?>
                                    </select>
                                </div>
                                <div class="settings-field">
                                    <label for="target_version">Pinned version</label>
                                    <select id="target_version" name="version" style="padding:9px 10px;border:1px solid var(--border-strong);border-radius:8px;background:#fff">
                                        <?php foreach ($versions as $ver): ?>
                                            <option value="<?= $e($ver['version']) ?>">v<?= $e($ver['version']) ?></option>
                                        <?php endforeach ?>
                                    </select>
                                </div>
                                <?php if ($can_autowire): ?>
                                    <label class="settings-checkbox" style="display:flex;gap:8px;align-items:center;font-size:14px">
                                        <input type="checkbox" name="autowire" value="1" checked>
                                        <span>Auto-wire into app runtime</span>
                                    </label>
                                <?php endif ?>
                                <button type="submit" class="btn-primary">Install module</button>
                            </form>
                        </div>
                    </section>
                <?php endif ?>

                <section class="ov-card">
                    <div class="ov-card-head">
                        <h2>Hosted apps</h2>
                        <span class="muted">trongate-modules.json</span>
                    </div>
                    <div class="ov-pad">
                        <p class="form-hint" style="margin-top:0">Commit this in your repo root so <code>tc-runtime</code> mounts the ZFS snapshot read-only at <code>/app/modules/<?= $e($name) ?></code>.</p>
                        <pre class="reg-code"><?= $e($manifest_snippet) ?></pre>
                    </div>
                </section>

                <section class="ov-card">
                    <div class="ov-card-head">
                        <h2>Local dev</h2>
                        <span class="muted"><?= !empty($module['repo_ready']) ? 'Self-hosted Git' : 'Package archive' ?></span>
                    </div>
                    <div class="ov-pad">
                        <?php if (!empty($module['repo_ready'])): ?>
                            <p class="form-hint" style="margin-top:0">Vendor with <code>git subtree</code> directly from this author's repository:</p>
                            <pre class="reg-code">git subtree add --prefix=modules/<?= $e($name) ?> <?= $e($git_url) ?> <?= $e($latest !== '' ? 'v' . $latest : 'main') ?> --squash</pre>
                        <?php else: ?>
                            <p class="form-hint" style="margin-top:0">Download the published package archive and extract it under <code>modules/<?= $e($name) ?></code>:</p>
                        <?php endif ?>
                        <?php if ($latest_archive_url !== ''): ?>
                            <?php if (!empty($module['repo_ready'])): ?>
                                <p class="form-hint">Or fetch the immutable archive:</p>
                            <?php endif ?>
                            <pre class="reg-code">curl -fsSL <?= $e($latest_archive_url) ?> | tar -xz -C modules/</pre>
                        <?php endif ?>
                    </div>
                </section>

                <?php if ($schema !== []): ?>
                    <section class="ov-card">
                        <div class="ov-card-head">
                            <h2>Configuration options</h2>
                            <span class="muted">config/<?= $e($name) ?>.php</span>
                        </div>
                        <dl class="ov-settings">
                            <?php foreach ($schema as $field): ?>
                                <div>
                                    <dt><code><?= $e($field['key'] ?? '') ?></code></dt>
                                    <dd><?= $e($field['type'] ?? 'string') ?></dd>
                                </div>
                            <?php endforeach ?>
                        </dl>
                    </section>
                <?php endif ?>
            </aside>
        </div>
    </main>
</body>
</html>
