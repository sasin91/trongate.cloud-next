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
                    <a href="/registry" aria-current="page">Module Registry</a>
                </nav>
            </div>
            <nav class="tc-nav">
                <a href="/registry" aria-current="page">Marketplace</a>
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
            <div class="reg-hero-top">
                <div>
                    <p class="eyebrow" style="margin:0 0 6px">THE TRONGATE MODULE MARKETPLACE</p>
                    <h1>Modules for your apps</h1>
                    <p>Find ready-to-use modules, install a version into an app, and keep track of updates from its Modules tab. You can also publish your own module for others to use.</p>
                </div>
                <div class="reg-badges">
                    <?php if (!empty($embeddings_configured)): ?>
                        <span class="reg-pill reg-pill-both" title="Semantic search powered by EmbeddingGemma 2">
                            EmbeddingGemma 2 &middot; <?= (int) ($embedding_stats['indexed'] ?? 0) ?>/<?= (int) ($embedding_stats['total'] ?? 0) ?> indexed
                        </span>
                    <?php else: ?>
                        <span class="reg-pill reg-pill-keyword">Keyword search</span>
                    <?php endif ?>
                    <?php if ($actor !== null): ?>
                        <a href="/registry/manage" class="btn-secondary">Publish module</a>
                    <?php endif ?>
                </div>
            </div>

            <form method="get" action="/registry" class="reg-search-form">
                <input
                    type="search"
                    name="q"
                    value="<?= $e($query) ?>"
                    class="reg-search-input"
                    placeholder="Search by intent or name — e.g. friendly 500 error page, structured logging, rate limiting…"
                    autocomplete="off"
                >
                <?php if ($category !== ''): ?>
                    <input type="hidden" name="category" value="<?= $e($category) ?>">
                <?php endif ?>
                <select name="mode" class="reg-mode-select" aria-label="Search mode">
                    <option value="hybrid"<?= $mode === 'hybrid' ? ' selected' : '' ?>>Hybrid (Semantic + Keyword)</option>
                    <option value="semantic"<?= $mode === 'semantic' ? ' selected' : '' ?>>Semantic only</option>
                    <option value="keyword"<?= $mode === 'keyword' ? ' selected' : '' ?>>Keyword only</option>
                </select>
                <button type="submit" class="btn-primary">Search</button>
            </form>

            <nav class="reg-cats" aria-label="Module categories">
                <?php
                $base_q = $query !== '' ? '&q=' . rawurlencode($query) : '';
                $mode_q = $mode !== 'hybrid' ? '&mode=' . rawurlencode($mode) : '';
                ?>
                <a href="/registry<?= ($base_q !== '' || $mode_q !== '') ? '?' . ltrim($base_q . $mode_q, '&') : '' ?>" class="reg-cat"<?= $category === '' ? ' aria-current="page"' : '' ?>>All</a>
                <?php foreach ($categories as $cat_key => $cat_label): ?>
                    <a href="/registry?category=<?= $e(rawurlencode($cat_key)) . $e($base_q . $mode_q) ?>" class="reg-cat"<?= $category === $cat_key ? ' aria-current="page"' : '' ?>><?= $e($cat_label) ?></a>
                <?php endforeach ?>
            </nav>
        </section>

        <?php if (!empty($fallback_notice)): ?>
            <p class="reg-notice" role="status"><?= $e($fallback_notice) ?></p>
        <?php endif ?>

        <?php if ($results === []): ?>
            <section class="ov-card">
                <div class="ov-pad">
                    <h2 style="margin:0"><?= $query !== '' ? 'No modules matched "' . $e($query) . '"' : 'No modules published yet' ?></h2>
                    <p class="muted" style="font-size:14px">
                        <?php if ($query !== ''): ?>
                            Try a broader phrase, switch search modes, or clear the category filter.
                        <?php else: ?>
                            Register a namespaced module repository (for example <code>sasin91/error_pages</code> or <code>sasin91/log</code>) and push a semver tag to publish your first immutable ZFS module release.
                        <?php endif ?>
                    </p>
                    <?php if ($actor !== null): ?>
                        <div><a href="/registry/manage" class="btn-primary">Open Registry Management</a></div>
                    <?php endif ?>
                </div>
            </section>
        <?php else: ?>
            <div class="reg-grid">
                <?php foreach ($results as $mod): ?>
                    <?php
                    $ns = (string) ($mod['namespace'] ?? '');
                    $name = (string) ($mod['name'] ?? '');
                    $latest = (string) ($mod['latest_version'] ?? '');
                    $match = $mod['match'] ?? null;
                    $sem = $mod['semantic_score'] ?? null;
                    $tags = is_array($mod['tags'] ?? null) ? $mod['tags'] : [];
                    ?>
                    <a class="reg-card" href="/registry/show/<?= $e(rawurlencode($ns)) ?>/<?= $e(rawurlencode($name)) ?>">
                        <div class="reg-card-head">
                            <div>
                                <div class="reg-slug"><?= $e($ns) ?> / <?= $e($name) ?></div>
                                <h2><?= $e($mod['title'] ?? $name) ?></h2>
                            </div>
                            <div class="reg-badges">
                                <?php if ($latest !== ''): ?>
                                    <span class="reg-pill reg-pill-ver">v<?= $e($latest) ?></span>
                                <?php endif ?>
                            </div>
                        </div>
                        <p class="reg-summary"><?= $e(($mod['summary'] ?? '') !== '' ? $mod['summary'] : 'Self-contained Trongate PHP module.') ?></p>
                        <div class="reg-badges">
                            <?php if ($match === 'both'): ?>
                                <span class="reg-pill reg-pill-both">Both<?= $sem !== null ? ' · ' . $e(number_format((float) $sem, 2)) : '' ?></span>
                            <?php elseif ($match === 'semantic'): ?>
                                <span class="reg-pill reg-pill-semantic">Semantic<?= $sem !== null ? ' · ' . $e(number_format((float) $sem, 2)) : '' ?></span>
                            <?php elseif ($match === 'keyword'): ?>
                                <span class="reg-pill reg-pill-keyword">Keyword</span>
                            <?php endif ?>
                            <?php if (!empty($mod['autowire'])): ?>
                                <span class="reg-pill reg-pill-autowire">Auto-wire</span>
                            <?php endif ?>
                        </div>
                        <div class="reg-tags">
                            <span><?= $e($mod['license'] ?? 'MIT') ?></span>
                            <span>&middot;</span>
                            <span><?= $e($categories[$mod['category'] ?? 'general'] ?? 'General') ?></span>
                            <?php foreach (array_slice($tags, 0, 4) as $tag): ?>
                                <span class="reg-tag"><?= $e($tag) ?></span>
                            <?php endforeach ?>
                        </div>
                    </a>
                <?php endforeach ?>
            </div>
        <?php endif ?>
    </main>
</body>
</html>
