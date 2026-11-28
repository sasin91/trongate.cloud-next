<?php
$active_tab = 'modules';
$app = $overview['app'];
$app_id = (string) $app['id'];
$label = (string) ($app['label'] ?? $app_id);
$installed = is_array($harbour_installed ?? null) ? $harbour_installed : [];
$own = is_array($own_modules ?? null) ? $own_modules : [];
$scanned = !empty($modules_scanned);
$catalog_results = is_array($registry_results ?? null) ? $registry_results : [];
$categories = is_array($registry_categories ?? null) ? $registry_categories : Module_spec::CATEGORIES;
$q = (string) ($registry_query ?? '');
$cat = (string) ($registry_category ?? '');
$installed_by_slug = [];
foreach ($installed as $item) {
    $installed_by_slug[$item['namespace'] . '/' . $item['name']] = $item;
}
$outdated = count(array_filter($installed, static fn(array $m): bool => $m['status'] === 'outdated'));
?>
<link rel="stylesheet" href="/harbour_module/css/harbour.css">
<main class="panel tc-panel">
    <div class="ov">
        <?php require __DIR__ . '/_header.php'; ?>

        <section class="ov-card">
            <div class="ov-card-head">
                <h2>Harbour crates aboard</h2>
                <span class="muted">
                    <?= count($installed) ?> aboard<?= $outdated > 0 ? ' &middot; ' . $outdated . ' with updates' : '' ?>
                </span>
            </div>
            <div class="ov-pad">
                <?php if (!$scanned): ?>
                    <p class="muted" style="font-size:14px">The lookout hasn't checked this app's hold yet. Modules are spotted during a build, so they'll show up here after the next deploy.</p>
                <?php elseif ($installed === []): ?>
                    <p class="muted" style="font-size:14px">No Harbour crates aboard yet. Pick one below, run its install command in your repository, then commit and push. The next deploy carries it, and it shows up here.</p>
                <?php else: ?>
                    <div class="mod-list">
                        <?php foreach ($installed as $mod): ?>
                            <?php
                            $ns = (string) $mod['namespace'];
                            $name = (string) $mod['name'];
                            $slug = $ns . '/' . $name;
                            $latest = (string) $mod['latest_version'];
                            ?>
                            <article class="mod-item" id="module-<?= $e($mod['folder']) ?>">
                                <div class="mod-item-head">
                                    <div>
                                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                                            <a href="/harbour/show/<?= $e(rawurlencode($ns)) ?>/<?= $e(rawurlencode($name)) ?>" style="font-weight:600;text-decoration:none;color:var(--text-main)"><?= $e($mod['title']) ?></a>
                                            <?php if ($mod['version'] !== ''): ?>
                                                <span class="reg-pill reg-pill-ver">v<?= $e($mod['version']) ?></span>
                                            <?php endif ?>
                                            <?php if ($mod['status'] === 'current'): ?>
                                                <span class="status-pill live-running">Up to date</span>
                                            <?php elseif ($mod['status'] === 'outdated'): ?>
                                                <span class="status-pill live-not_live">v<?= $e($latest) ?> available</span>
                                            <?php else: ?>
                                                <span class="reg-pill reg-pill-keyword" title="This folder doesn't match any published version of <?= $e($slug) ?>.">Changed locally</span>
                                            <?php endif ?>
                                        </div>
                                        <div class="muted" style="font-size:12px;margin-top:3px">
                                            <?= $e($slug) ?> &middot; in <code>modules/<?= $e($mod['folder']) ?></code>
                                        </div>
                                    </div>
                                </div>
                                <?php if ($mod['status'] === 'outdated' && $latest !== ''): ?>
                                    <div class="mod-item-body">
                                        <p class="form-hint" style="margin-top:0">Update in your repository, then commit and push:</p>
                                        <?php if (!empty($mod['repo_ready'])): ?>
                                            <pre class="reg-code"><?= $e(Module_spec::subtree_command($ns, $name, $latest, true)) ?></pre>
                                        <?php else: ?>
                                            <pre class="reg-code">rm -rf modules/<?= $e($name) ?> &amp;&amp; <?= $e(Module_spec::archive_command($ns, $name, $latest)) ?></pre>
                                        <?php endif ?>
                                    </div>
                                <?php elseif ($mod['status'] === 'modified'): ?>
                                    <div class="mod-item-body">
                                        <p class="form-hint" style="margin:0">You've made this crate your own, which is exactly what Trongate modules are for. The lookout can't tell which version it started from, so it won't suggest updates. Compare with v<?= $e($latest) ?> on its Harbour page if you want to pull in changes.</p>
                                    </div>
                                <?php endif ?>
                            </article>
                        <?php endforeach ?>
                    </div>
                <?php endif ?>
                <?php if ($own !== []): ?>
                    <p class="muted" style="font-size:13px;margin:14px 0 0">
                        Also aboard, your own modules: <?= $e(implode(', ', $own)) ?>.
                    </p>
                <?php endif ?>
            </div>
        </section>

        <section class="ov-card">
            <div class="ov-card-head">
                <h2>Browse the Harbour</h2>
                <a href="/harbour" class="btn-link" style="font-size:13px">Open the Harbour &rarr;</a>
            </div>
            <div class="ov-pad">
                <form method="get" action="/apps/modules/<?= $e(rawurlencode($app_id)) ?>" class="reg-search-form" data-helm="Searching the seas…">
                    <input
                        type="search"
                        name="q"
                        value="<?= $e($q) ?>"
                        class="reg-search-input"
                        placeholder="Search by intent or name (e.g. error pages, logging, auth)…"
                    >
                    <select name="category" class="reg-mode-select" aria-label="Category">
                        <option value="">All categories</option>
                        <?php foreach ($categories as $ck => $cl): ?>
                            <option value="<?= $e($ck) ?>"<?= $cat === $ck ? ' selected' : '' ?>><?= $e($cl) ?></option>
                        <?php endforeach ?>
                    </select>
                    <button type="submit" class="btn-secondary">Search</button>
                </form>

                <?php if (!empty($registry_fallback_notice)): ?>
                    <p class="form-hint"><?= $e($registry_fallback_notice) ?></p>
                <?php endif ?>

                <?php if ($catalog_results === []): ?>
                    <p class="muted" style="font-size:14px">
                        No crates matched. Try another search or <a href="/harbour/manage">moor your own module</a>.
                    </p>
                <?php else: ?>
                    <div class="mod-browser-grid">
                        <?php foreach ($catalog_results as $mod): ?>
                            <?php
                            $mns = (string) ($mod['namespace'] ?? '');
                            $mname = (string) ($mod['name'] ?? '');
                            $mslug = $mns . '/' . $mname;
                            $mlatest = (string) ($mod['latest_version'] ?? '');
                            $aboard = $installed_by_slug[$mslug] ?? null;
                            ?>
                            <div class="mod-browser-card">
                                <div>
                                    <div class="reg-slug"><?= $e($mslug) ?></div>
                                    <h3><a href="/harbour/show/<?= $e(rawurlencode($mns)) ?>/<?= $e(rawurlencode($mname)) ?>" style="text-decoration:none;color:inherit"><?= $e($mod['title'] ?? $mname) ?></a></h3>
                                </div>
                                <p><?= $e(($mod['summary'] ?? '') !== '' ? $mod['summary'] : 'Self-contained Trongate module.') ?></p>
                                <div class="mod-browser-foot">
                                    <?php if ($aboard !== null): ?>
                                        <span class="status-pill live-running" style="align-self:flex-start">Aboard<?= $aboard['version'] !== '' ? ' · v' . $e($aboard['version']) : '' ?></span>
                                    <?php elseif ($mlatest === ''): ?>
                                        <span class="muted" style="font-size:12px">No versions published yet</span>
                                    <?php else: ?>
                                        <details>
                                            <summary style="cursor:pointer;font-size:13px;color:var(--primary)">Haul v<?= $e($mlatest) ?> aboard</summary>
                                            <pre class="reg-code" style="margin-top:8px"><?= $e(!empty($mod['repo_ready']) ? Module_spec::subtree_command($mns, $mname, $mlatest) : Module_spec::archive_command($mns, $mname, $mlatest)) ?></pre>
                                            <p class="form-hint" style="margin:6px 0 0">Run it in your repository, then commit and push.</p>
                                        </details>
                                    <?php endif ?>
                                </div>
                            </div>
                        <?php endforeach ?>
                    </div>
                <?php endif ?>
            </div>
        </section>
    </div>
</main>
