<?php
$active_tab = 'modules';
$app = $overview['app'];
$app_id = (string) $app['id'];
$label = (string) ($app['label'] ?? $app_id);
$attached = is_array($app_modules ?? null) ? $app_modules : [];
$catalog_results = is_array($registry_results ?? null) ? $registry_results : [];
$catalog_versions = is_array($registry_versions ?? null) ? $registry_versions : [];
$catalog_schemas = is_array($registry_schemas ?? null) ? $registry_schemas : [];
$categories = is_array($registry_categories ?? null) ? $registry_categories : Module_spec::CATEGORIES;
$q = (string) ($registry_query ?? '');
$cat = (string) ($registry_category ?? '');
$can_edit = !empty($can_settings);
$has_pending_deploy = !empty($modules_pending_deploy);
$attached_by_name = [];
foreach ($attached as $item) {
    $attached_by_name[(string) ($item['name'] ?? '')] = $item;
}
?>
<link rel="stylesheet" href="/registry_module/css/registry.css">
<main class="panel tc-panel">
    <div class="ov">
        <?php require __DIR__ . '/_header.php'; ?>

        <?php if (!empty($saved_notice)): ?>
            <p class="flash" role="status"><?= $e($saved_notice) ?></p>
        <?php endif ?>

        <?php if ($has_pending_deploy && !empty($can_deploy)): ?>
            <div class="reg-notice" role="status" style="display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:0">
                <span>Module changes are saved. Deploy <strong><?= $e($label) ?></strong> to mount updated ZFS snapshots and apply module config overlays.</span>
                <form method="post" action="/apps/deploy" style="margin:0;width:auto">
                    <?= $csrf() ?>
                    <input type="hidden" name="app" value="<?= $e($app_id) ?>">
                    <button type="submit" class="btn-primary">Deploy now</button>
                </form>
            </div>
        <?php endif ?>

        <section class="ov-card">
            <div class="ov-card-head">
                <h2>Installed modules</h2>
                <span class="muted"><?= count($attached) ?> mounted via ZFS RO nullfs</span>
            </div>
            <div class="ov-pad">
                <?php if ($attached === []): ?>
                    <p class="muted" style="font-size:14px">No registry modules attached to this app yet. Insert a module below or commit a <code>trongate-modules.json</code> manifest in your repository.</p>
                <?php else: ?>
                    <div class="mod-list">
                        <?php foreach ($attached as $mod): ?>
                            <?php
                            $ns = (string) ($mod['namespace'] ?? '');
                            $name = (string) ($mod['name'] ?? '');
                            $slug = $ns . '/' . $name;
                            $ver = (string) ($mod['version'] ?? '');
                            $autowire = !empty($mod['autowire']);
                            $source = (string) ($mod['source'] ?? 'panel');
                            $versions = $catalog_versions[$slug] ?? [];
                            $latest = $versions !== [] ? (string) ($versions[0]['version'] ?? '') : '';
                            $has_update = $latest !== '' && version_compare($latest, $ver) > 0;
                            $schema = $catalog_schemas[$slug] ?? [];
                            $schema_keys = [];
                            foreach ($schema as $sf) {
                                $schema_keys[(string) ($sf['key'] ?? '')] = true;
                            }
                            $cfg = is_array($mod['config'] ?? null) ? $mod['config'] : [];
                            $secret_names = array_keys(is_array($mod['secret_refs'] ?? null) ? $mod['secret_refs'] : []);
                            $extra_lines = [];
                            foreach ($cfg as $ck => $cv) {
                                if (!isset($schema_keys[$ck])) {
                                    $extra_lines[] = $ck . '=' . (is_bool($cv) ? ($cv ? 'true' : 'false') : (string) $cv);
                                }
                            }
                            $can_autowire = Module_spec::can_autowire($name);
                            ?>
                            <article class="mod-item">
                                <div class="mod-item-head">
                                    <div>
                                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                                            <a href="/registry/show/<?= $e(rawurlencode($ns)) ?>/<?= $e(rawurlencode($name)) ?>" style="font-weight:600;text-decoration:none;color:var(--text-main)"><?= $e($slug) ?></a>
                                            <span class="reg-pill reg-pill-ver">v<?= $e($ver) ?></span>
                                            <?php if ($autowire): ?>
                                                <span class="reg-pill reg-pill-autowire">Auto-wired</span>
                                            <?php endif ?>
                                            <span class="reg-pill reg-pill-keyword"><?= $e($source === 'manifest' ? 'trongate-modules.json' : 'Panel') ?></span>
                                        </div>
                                        <div class="muted" style="font-size:12px;margin-top:3px">
                                            Mounted read-only at <code>/app/modules/<?= $e($name) ?></code>
                                            <?php if ($cfg !== [] || $secret_names !== []): ?>
                                                &middot; Config overlay: <code>/app/config/<?= $e($name) ?>.php</code>
                                            <?php endif ?>
                                        </div>
                                    </div>
                                    <?php if ($can_edit): ?>
                                        <div class="mod-inline-actions">
                                            <?php if ($has_update): ?>
                                                <form method="post" action="/apps/update_module">
                                                    <?= $csrf() ?>
                                                    <input type="hidden" name="app" value="<?= $e($app_id) ?>">
                                                    <input type="hidden" name="namespace" value="<?= $e($ns) ?>">
                                                    <input type="hidden" name="name" value="<?= $e($name) ?>">
                                                    <input type="hidden" name="version" value="<?= $e($latest) ?>">
                                                    <input type="hidden" name="autowire" value="<?= $autowire ? '1' : '0' ?>">
                                                    <input type="hidden" name="preserve_config" value="1">
                                                    <button type="submit" class="btn-primary" style="padding:6px 12px;font-size:13px">Update to v<?= $e($latest) ?></button>
                                                </form>
                                            <?php endif ?>
                                            <?php if ($source !== 'manifest'): ?>
                                                <form method="post" action="/apps/remove_module" data-confirm="Remove module <?= $e($slug) ?> from <?= $e($label) ?>?">
                                                    <?= $csrf() ?>
                                                    <input type="hidden" name="app" value="<?= $e($app_id) ?>">
                                                    <input type="hidden" name="name" value="<?= $e($name) ?>">
                                                    <button type="submit" class="btn-link">Remove</button>
                                                </form>
                                            <?php endif ?>
                                        </div>
                                    <?php endif ?>
                                </div>

                                <?php if ($can_edit): ?>
                                    <div class="mod-item-body">
                                        <details<?= ($cfg !== [] || $schema !== []) ? '' : '' ?>>
                                            <summary style="cursor:pointer;font-size:14px;font-weight:500;color:var(--primary)">Version, wiring &amp; configuration</summary>
                                            <form method="post" action="/apps/update_module" class="settings-form" style="margin-top:14px">
                                                <?= $csrf() ?>
                                                <input type="hidden" name="app" value="<?= $e($app_id) ?>">
                                                <input type="hidden" name="namespace" value="<?= $e($ns) ?>">
                                                <input type="hidden" name="name" value="<?= $e($name) ?>">

                                                <div class="mod-config-grid">
                                                    <div class="settings-field">
                                                        <label for="ver_<?= $e($name) ?>">Pinned version</label>
                                                        <?php if ($versions !== []): ?>
                                                            <select id="ver_<?= $e($name) ?>" name="version" style="padding:9px 10px;border:1px solid var(--border-strong);border-radius:8px;background:#fff">
                                                                <?php
                                                                $has_cur = false;
                                                                foreach ($versions as $vrow):
                                                                    $vv = (string) ($vrow['version'] ?? '');
                                                                    if ($vv === $ver) {
                                                                        $has_cur = true;
                                                                    }
                                                                ?>
                                                                    <option value="<?= $e($vv) ?>"<?= $vv === $ver ? ' selected' : '' ?>>v<?= $e($vv) ?></option>
                                                                <?php endforeach ?>
                                                                <?php if (!$has_cur && $ver !== ''): ?>
                                                                    <option value="<?= $e($ver) ?>" selected>v<?= $e($ver) ?> (pinned)</option>
                                                                <?php endif ?>
                                                            </select>
                                                        <?php else: ?>
                                                            <input id="ver_<?= $e($name) ?>" type="text" name="version" value="<?= $e($ver) ?>" required>
                                                        <?php endif ?>
                                                    </div>

                                                    <?php if ($can_autowire): ?>
                                                        <div class="settings-field" style="justify-content:center">
                                                            <label>Runtime wiring</label>
                                                            <input type="hidden" name="autowire" value="0">
                                                            <label style="display:flex;gap:8px;align-items:center;text-transform:none;letter-spacing:0;font-weight:400;color:var(--text-main);font-size:14px">
                                                                <input type="checkbox" name="autowire" value="1"<?= $autowire ? ' checked' : '' ?> style="width:auto">
                                                                <span>Auto-wire <code><?= $e($name) ?></code> into PHP-FPM &amp; config</span>
                                                            </label>
                                                        </div>
                                                    <?php endif ?>
                                                </div>

                                                <?php if ($schema !== []): ?>
                                                    <div class="mod-config-grid">
                                                        <?php foreach ($schema as $field): ?>
                                                            <?php
                                                            $fkey = (string) ($field['key'] ?? '');
                                                            $flabel = (string) ($field['label'] ?? $fkey);
                                                            $ftype = (string) ($field['type'] ?? 'string');
                                                            $fsecret = !empty($field['secret']);
                                                            $fdefault = $field['default'] ?? '';
                                                            $fhint = (string) ($field['hint'] ?? '');
                                                            $fval = $fsecret ? '' : ($cfg[$fkey] ?? $fdefault);
                                                            $is_set_secret = $fsecret && in_array($fkey, $secret_names, true);
                                                            ?>
                                                            <div class="settings-field">
                                                                <label for="cfg_<?= $e($name . '_' . $fkey) ?>"><?= $e($flabel) ?><?= $fsecret ? ' (secret)' : '' ?></label>
                                                                <?php if ($ftype === 'bool'): ?>
                                                                    <input type="hidden" name="schema_config[<?= $e($fkey) ?>]" value="0">
                                                                    <label style="display:flex;gap:8px;align-items:center;text-transform:none;letter-spacing:0;font-weight:400;color:var(--text-main);font-size:14px">
                                                                        <input id="cfg_<?= $e($name . '_' . $fkey) ?>" type="checkbox" name="schema_config[<?= $e($fkey) ?>]" value="1"<?= !empty($fval) ? ' checked' : '' ?> style="width:auto">
                                                                        <span>Enabled</span>
                                                                    </label>
                                                                <?php elseif ($ftype === 'select' && !empty($field['options'])): ?>
                                                                    <select id="cfg_<?= $e($name . '_' . $fkey) ?>" name="schema_config[<?= $e($fkey) ?>]" style="padding:9px 10px;border:1px solid var(--border-strong);border-radius:8px;background:#fff">
                                                                        <?php foreach ($field['options'] as $opt): ?>
                                                                            <option value="<?= $e($opt) ?>"<?= (string) $fval === (string) $opt ? ' selected' : '' ?>><?= $e($opt) ?></option>
                                                                        <?php endforeach ?>
                                                                    </select>
                                                                <?php elseif ($fsecret): ?>
                                                                    <input id="cfg_<?= $e($name . '_' . $fkey) ?>" type="password" name="schema_secrets[<?= $e($fkey) ?>]" value="" placeholder="<?= $is_set_secret ? 'Saved (leave blank to keep)' : 'Enter secret value' ?>" autocomplete="new-password">
                                                                <?php else: ?>
                                                                    <input id="cfg_<?= $e($name . '_' . $fkey) ?>" type="<?= $ftype === 'int' ? 'number' : 'text' ?>" name="schema_config[<?= $e($fkey) ?>]" value="<?= $e((string) $fval) ?>">
                                                                <?php endif ?>
                                                                <?php if ($fhint !== ''): ?>
                                                                    <p class="form-hint"><?= $e($fhint) ?></p>
                                                                <?php endif ?>
                                                            </div>
                                                        <?php endforeach ?>
                                                    </div>
                                                <?php endif ?>

                                                <div class="settings-field">
                                                    <label for="extra_cfg_<?= $e($name) ?>">Additional configuration (<code>key=value</code> per line)</label>
                                                    <textarea id="extra_cfg_<?= $e($name) ?>" name="extra_config" class="mono" rows="2" spellcheck="false" placeholder="option_name=value"><?= $e(implode("\n", $extra_lines)) ?></textarea>
                                                    <p class="form-hint">Rendered read-only as <code>/app/config/<?= $e($name) ?>.php</code> (returns an associative array).</p>
                                                </div>

                                                <div class="form-actions">
                                                    <button type="submit" class="btn-primary">Save module configuration</button>
                                                </div>
                                            </form>
                                        </details>
                                    </div>
                                <?php endif ?>
                            </article>
                        <?php endforeach ?>
                    </div>
                <?php endif ?>
            </div>
        </section>

        <?php if ($can_edit): ?>
            <section class="ov-card">
                <div class="ov-card-head">
                    <h2>Insert module from ZFS Registry</h2>
                    <a href="/registry" class="btn-link" style="font-size:13px">Open full Marketplace &rarr;</a>
                </div>
                <div class="ov-pad">
                    <form method="get" action="/apps/modules/<?= $e(rawurlencode($app_id)) ?>" class="reg-search-form">
                        <input
                            type="search"
                            name="q"
                            value="<?= $e($q) ?>"
                            class="reg-search-input"
                            placeholder="Search modules semantically or by name (e.g. error pages, logging, auth)…"
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
                            No published modules matched. <a href="/registry/manage">Register and publish modules</a> in the ZFS registry first.
                        </p>
                    <?php else: ?>
                        <div class="mod-browser-grid">
                            <?php foreach ($catalog_results as $mod): ?>
                                <?php
                                $mns = (string) ($mod['namespace'] ?? '');
                                $mname = (string) ($mod['name'] ?? '');
                                $mslug = $mns . '/' . $mname;
                                $mvers = $catalog_versions[$mslug] ?? [];
                                $already = isset($attached_by_name[$mname]);
                                $match = $mod['match'] ?? null;
                                $m_autowire = Module_spec::can_autowire($mname);
                                ?>
                                <div class="mod-browser-card">
                                    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px">
                                        <div>
                                            <div class="reg-slug"><?= $e($mslug) ?></div>
                                            <h3><a href="/registry/show/<?= $e(rawurlencode($mns)) ?>/<?= $e(rawurlencode($mname)) ?>" style="text-decoration:none;color:inherit"><?= $e($mod['title'] ?? $mname) ?></a></h3>
                                        </div>
                                        <?php if ($match === 'both'): ?>
                                            <span class="reg-pill reg-pill-both">Both</span>
                                        <?php elseif ($match === 'semantic'): ?>
                                            <span class="reg-pill reg-pill-semantic">Semantic</span>
                                        <?php elseif ($match === 'keyword'): ?>
                                            <span class="reg-pill reg-pill-keyword">Keyword</span>
                                        <?php endif ?>
                                    </div>
                                    <p><?= $e(($mod['summary'] ?? '') !== '' ? $mod['summary'] : 'Self-contained Trongate module.') ?></p>
                                    <div class="mod-browser-foot">
                                        <?php if ($mvers === []): ?>
                                            <span class="muted" style="font-size:12px">No versions published yet</span>
                                        <?php elseif ($already): ?>
                                            <span class="status-pill live-running" style="align-self:flex-start">Installed</span>
                                        <?php else: ?>
                                            <form method="post" action="/apps/insert_module">
                                                <?= $csrf() ?>
                                                <input type="hidden" name="app" value="<?= $e($app_id) ?>">
                                                <input type="hidden" name="namespace" value="<?= $e($mns) ?>">
                                                <input type="hidden" name="name" value="<?= $e($mname) ?>">
                                                <div class="mod-browser-row">
                                                    <select name="version" aria-label="Version">
                                                        <?php foreach ($mvers as $vr): ?>
                                                            <option value="<?= $e($vr['version']) ?>">v<?= $e($vr['version']) ?></option>
                                                        <?php endforeach ?>
                                                    </select>
                                                    <?php if ($m_autowire): ?>
                                                        <label style="display:inline-flex;gap:5px;align-items:center;font-size:12px;margin:0">
                                                            <input type="checkbox" name="autowire" value="1" checked style="width:auto">
                                                            <span>Auto-wire</span>
                                                        </label>
                                                    <?php endif ?>
                                                    <button type="submit" class="btn-primary" style="padding:6px 12px;font-size:13px;margin-left:auto">Insert</button>
                                                </div>
                                            </form>
                                        <?php endif ?>
                                    </div>
                                </div>
                            <?php endforeach ?>
                        </div>
                    <?php endif ?>
                </div>
            </section>
        <?php endif ?>
    </div>
</main>
