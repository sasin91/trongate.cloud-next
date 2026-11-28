<?php
$modules = is_array($registry_modules ?? null) ? $registry_modules : [];
$tokens = is_array($registry_tokens ?? null) ? $registry_tokens : [];
$servers = is_array($registry_servers ?? null) ? $registry_servers : [];
$categories = is_array($registry_categories ?? null) ? $registry_categories : Module_spec::CATEGORIES;
$emb_health = is_array($embeddings_health ?? null) ? $embeddings_health : ['configured' => false, 'ok' => false];
$emb_stats = is_array($embedding_stats ?? null) ? $embedding_stats : ['total' => 0, 'indexed' => 0, 'outdated' => 0];
?>
<link rel="stylesheet" href="/harbour_module/css/harbour.css">
<main class="panel tc-panel">
    <div class="section-header">
        <div>
            <p class="eyebrow">ZFS MODULE REGISTRY</p>
            <h1>Manage Registry &amp; Publishers</h1>
        </div>
        <div class="ov-actions">
            <a href="/harbour" class="btn-secondary">Browse the Harbour &rarr;</a>
        </div>
    </div>

    <?php if (!empty($saved_notice)): ?>
        <p class="flash" role="status" style="margin-bottom:18px"><?= $e($saved_notice) ?></p>
    <?php endif ?>

    <?php if (!empty($new_token) && is_array($new_token)): ?>
        <div class="reg-token-box" role="status">
            <strong>New push token for namespace <code><?= $e($new_token['namespace']) ?></code></strong> — copy this secret now; it is stored SHA-256 hashed and cannot be viewed again:
            <code><?= $e($new_token['token']) ?></code>
            <p style="margin:10px 0 0;font-size:13px">Push a version tag with:<br>
                <code>git push https://<?= $e($new_token['namespace']) ?>:&lt;token&gt;@<?= $e(parse_url(Module_spec::registry_base_url(), PHP_URL_HOST) ?: 'registry.trongate.cloud') ?>/git/<?= $e($new_token['namespace']) ?>/&lt;module&gt;.git main --tags</code>
            </p>
        </div>
    <?php endif ?>

    <div class="ov-main">
        <div class="ov-col">
            <section class="ov-card">
                <div class="ov-card-head">
                    <h2>Registered module repositories</h2>
                    <span class="muted"><?= count($modules) ?> module(s)</span>
                </div>
                <?php if ($modules === []): ?>
                    <p class="ov-empty muted">No module repositories registered yet. Use the form on the right to create a namespaced bare Git repository on ZFS.</p>
                <?php else: ?>
                    <div class="ov-table-wrap">
                        <table class="deployments-table">
                            <thead>
                                <tr>
                                    <th>Module</th>
                                    <th>Latest</th>
                                    <th>License</th>
                                    <th>Publish tag</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($modules as $mod): ?>
                                    <?php
                                    $ns = (string) ($mod['namespace'] ?? '');
                                    $name = (string) ($mod['name'] ?? '');
                                    $latest = (string) ($mod['latest_version'] ?? '');
                                    ?>
                                    <tr>
                                        <td>
                                            <a href="/harbour/show/<?= $e(rawurlencode($ns)) ?>/<?= $e(rawurlencode($name)) ?>"><strong><?= $e($ns) ?>/<?= $e($name) ?></strong></a>
                                            <div class="muted" style="font-size:12px"><?= !empty($mod['repo_ready']) ? $e(Module_spec::git_url($ns, $name)) : 'Uploaded package' ?></div>
                                        </td>
                                        <td>
                                            <?php if ($latest !== ''): ?>
                                                <span class="reg-pill reg-pill-ver">v<?= $e($latest) ?></span>
                                            <?php else: ?>
                                                <span class="muted">Unpublished</span>
                                            <?php endif ?>
                                        </td>
                                        <td><?= $e($mod['license'] ?? 'MIT') ?></td>
                                        <td>
                                            <?php if (!empty($mod['repo_ready'])): ?>
                                                <form method="post" action="/harbour/publish" data-helm="Setting sail…" style="display:flex;gap:6px;align-items:center;margin:0">
                                                    <?= $csrf() ?>
                                                    <input type="hidden" name="namespace" value="<?= $e($ns) ?>">
                                                    <input type="hidden" name="name" value="<?= $e($name) ?>">
                                                    <input type="text" name="version" placeholder="1.0.0" required style="width:90px;padding:5px 8px;margin:0;border:1px solid var(--border-strong);border-radius:6px;font-size:13px">
                                                    <button type="submit" class="btn-secondary" style="padding:5px 10px;font-size:13px">Publish</button>
                                                </form>
                                            <?php else: ?>
                                                <span class="muted">Upload a new version above</span>
                                            <?php endif ?>
                                        </td>
                                    </tr>
                                <?php endforeach ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif ?>
            </section>

            <section class="ov-card">
                <div class="ov-card-head">
                    <h2>Author-scoped Git push tokens</h2>
                    <span class="muted">SHA-256 hashed</span>
                </div>
                <div class="ov-pad">
                    <p class="form-hint" style="margin-top:0">Each author publishes only to their own namespace (<code>/git/&lt;namespace&gt;/&lt;module&gt;.git</code>). Pushing a semver tag (<code>v1.0.0</code>) automatically queues a root-verified ZFS dataset &amp; archive release.</p>

                    <form method="post" action="/harbour/create_token" class="settings-form" style="margin-bottom:14px">
                        <?= $csrf() ?>
                        <div class="service-row-2">
                            <div class="settings-field">
                                <label for="tok_ns">Author namespace</label>
                                <input id="tok_ns" type="text" name="namespace" placeholder="sasin91" required pattern="[a-z][a-z0-9-]{1,38}">
                            </div>
                            <div class="settings-field">
                                <label for="tok_label">Token label</label>
                                <input id="tok_label" type="text" name="label" placeholder="CI or workstation key">
                            </div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn-primary">Mint push token</button>
                        </div>
                    </form>

                    <?php if ($tokens !== []): ?>
                        <div class="ov-table-wrap">
                            <table class="deployments-table">
                                <thead>
                                    <tr>
                                        <th>Namespace</th>
                                        <th>Prefix</th>
                                        <th>Label</th>
                                        <th>Status</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($tokens as $tok): ?>
                                        <?php $revoked = (int) ($tok['revoked_at'] ?? 0) > 0; ?>
                                        <tr>
                                            <td><code class="ov-sha"><?= $e($tok['namespace']) ?></code></td>
                                            <td><code><?= $e($tok['token_prefix']) ?>&hellip;</code></td>
                                            <td><?= $e($tok['label']) ?></td>
                                            <td>
                                                <?php if ($revoked): ?>
                                                    <span class="status-pill live-not_live">Revoked</span>
                                                <?php else: ?>
                                                    <span class="status-pill live-running">Active</span>
                                                <?php endif ?>
                                            </td>
                                            <td>
                                                <?php if (!$revoked): ?>
                                                    <form method="post" action="/harbour/revoke_token" style="margin:0" data-confirm="Revoke token <?= $e($tok['token_prefix']) ?>…?">
                                                        <?= $csrf() ?>
                                                        <input type="hidden" name="id" value="<?= $e($tok['id']) ?>">
                                                        <button type="submit" class="btn-link">Revoke</button>
                                                    </form>
                                                <?php endif ?>
                                            </td>
                                        </tr>
                                    <?php endforeach ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif ?>
                </div>
            </section>
        </div>

        <aside class="ov-col">
            <section class="ov-card">
                <div class="ov-card-head">
                    <h2>Upload a module</h2>
                    <span class="muted">Publish to the Harbour</span>
                </div>
                <div class="ov-pad">
                    <p class="form-hint" style="margin-top:0">Upload a <code>.tar.gz</code> archive containing your module files at its root. Archives are limited to 512 KiB and are verified before becoming an immutable release.</p>
                    <form method="post" action="/harbour/upload" enctype="multipart/form-data" class="settings-form" data-helm="Loading the crate…">
                        <?= $csrf() ?>
                        <input type="hidden" name="MAX_FILE_SIZE" value="<?= Module_spec::MAX_UPLOAD_BYTES ?>">
                        <div class="service-row-2">
                            <div class="settings-field">
                                <label for="upload_ns">Namespace</label>
                                <input id="upload_ns" type="text" name="namespace" placeholder="sasin91" required pattern="[a-z][a-z0-9-]{1,38}">
                            </div>
                            <div class="settings-field">
                                <label for="upload_name">Module name</label>
                                <input id="upload_name" type="text" name="name" placeholder="error_pages" required pattern="[a-z][a-z0-9_]{0,39}">
                            </div>
                        </div>
                        <div class="service-row-2">
                            <div class="settings-field">
                                <label for="upload_version">Version</label>
                                <input id="upload_version" type="text" name="version" placeholder="1.0.0" required pattern="[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?">
                            </div>
                            <div class="settings-field">
                                <label for="upload_archive">Module archive</label>
                                <input id="upload_archive" type="file" name="archive" accept=".tar.gz,application/gzip" required>
                            </div>
                        </div>
                        <?php if ($servers !== []): ?>
                            <div class="settings-field">
                                <label for="upload_server">Host server</label>
                                <select id="upload_server" name="server" style="padding:9px 10px;border:1px solid var(--border-strong);border-radius:8px;background:#fff">
                                    <?php foreach ($servers as $srv): ?>
                                        <option value="<?= $e($srv['id']) ?>"><?= $e($srv['id']) ?> (<?= $e($srv['host'] ?? '') ?>)</option>
                                    <?php endforeach ?>
                                </select>
                            </div>
                        <?php endif ?>
                        <button type="submit" class="btn-primary">Upload and publish</button>
                    </form>
                </div>
            </section>

            <section class="ov-card">
                <div class="ov-card-head">
                    <h2>Register module repo</h2>
                    <span class="muted">ZFS bare Git</span>
                </div>
                <div class="ov-pad">
                    <form method="post" action="/harbour/create_repo" class="settings-form" data-helm="Building the dock…">
                        <?= $csrf() ?>
                        <div class="service-row-2">
                            <div class="settings-field">
                                <label for="mod_ns">Namespace</label>
                                <input id="mod_ns" type="text" name="namespace" placeholder="sasin91" required pattern="[a-z][a-z0-9-]{1,38}">
                            </div>
                            <div class="settings-field">
                                <label for="mod_name">Module name</label>
                                <input id="mod_name" type="text" name="name" placeholder="error_pages" required pattern="[a-z][a-z0-9_]{0,39}">
                            </div>
                        </div>
                        <div class="settings-field">
                            <label for="mod_title">Display title</label>
                            <input id="mod_title" type="text" name="title" placeholder="Error Pages">
                        </div>
                        <div class="service-row-2">
                            <div class="settings-field">
                                <label for="mod_cat">Category</label>
                                <select id="mod_cat" name="category" style="padding:9px 10px;border:1px solid var(--border-strong);border-radius:8px;background:#fff">
                                    <?php foreach ($categories as $k => $lbl): ?>
                                        <option value="<?= $e($k) ?>"><?= $e($lbl) ?></option>
                                    <?php endforeach ?>
                                </select>
                            </div>
                            <div class="settings-field">
                                <label for="mod_license">License</label>
                                <input id="mod_license" type="text" name="license" value="MIT" placeholder="MIT">
                            </div>
                        </div>
                        <div class="settings-field">
                            <label for="mod_tags">Tags</label>
                            <input id="mod_tags" type="text" name="tags" placeholder="errors, 404, json, fetch">
                        </div>
                        <div class="settings-field">
                            <label for="mod_summary">Summary</label>
                            <textarea id="mod_summary" name="summary" rows="2" placeholder="404/500/503 pages and JSON error responses for fetch() calls."></textarea>
                        </div>
                        <?php if ($servers !== []): ?>
                            <div class="settings-field">
                                <label for="mod_server">Host server</label>
                                <select id="mod_server" name="server" style="padding:9px 10px;border:1px solid var(--border-strong);border-radius:8px;background:#fff">
                                    <?php foreach ($servers as $srv): ?>
                                        <option value="<?= $e($srv['id']) ?>"><?= $e($srv['id']) ?> (<?= $e($srv['host'] ?? '') ?>)</option>
                                    <?php endforeach ?>
                                </select>
                            </div>
                        <?php endif ?>
                        <button type="submit" class="btn-primary">Create bare repository</button>
                    </form>
                </div>
            </section>

            <section class="ov-card">
                <div class="ov-card-head">
                    <h2>EmbeddingGemma 2 search</h2>
                    <?php if (!empty($emb_health['ok'])): ?>
                        <span class="status-pill live-running">Online<?= isset($emb_health['latency_ms']) ? ' · ' . (int) $emb_health['latency_ms'] . ' ms' : '' ?></span>
                    <?php elseif (!empty($emb_health['configured'])): ?>
                        <span class="status-pill live-failed">Unreachable</span>
                    <?php else: ?>
                        <span class="status-pill live-not_live">Not configured</span>
                    <?php endif ?>
                </div>
                <div class="ov-pad">
                    <dl class="ov-settings" style="padding:0">
                        <div>
                            <dt>Indexed modules</dt>
                            <dd><?= (int) $emb_stats['indexed'] ?> / <?= (int) $emb_stats['total'] ?></dd>
                        </div>
                        <div>
                            <dt>Missing / outdated</dt>
                            <dd><?= (int) $emb_stats['outdated'] ?></dd>
                        </div>
                    </dl>
                    <?php if (empty($emb_health['configured'])): ?>
                        <p class="form-hint">Start the FreeBSD-native <strong>EmbeddingGemma 2</strong> service preset on <a href="/services">Services</a> (or set <code>EMBEDDINGS_URL</code>) to enable semantic search in the Harbour.</p>
                    <?php else: ?>
                        <div class="form-actions">
                            <form method="post" action="/harbour/reindex" style="margin:0" data-helm="Charting the seas…">
                                <?= $csrf() ?>
                                <button type="submit" class="btn-primary">Embed missing</button>
                            </form>
                            <form method="post" action="/harbour/reindex" style="margin:0" data-helm="Charting the seas…">
                                <?= $csrf() ?>
                                <input type="hidden" name="force" value="1">
                                <button type="submit" class="btn-secondary">Re-embed all</button>
                            </form>
                        </div>
                    <?php endif ?>
                </div>
            </section>
        </aside>
    </div>
</main>
