<?php
$member = $member ?? [];
$all_apps = $all_apps ?? [];
$presets = Team::APP_PRESETS;
$app_perms_meta = Team::APP_PERMISSIONS;
$ws_perms_meta = Team::WORKSPACE_PERMISSIONS;
$mode = $member['role'] === 'admin' ? 'admin' : ($member['scope'] === 'all' ? 'all' : 'scoped');
$ws_checked = $member['permissions']['workspace'] ?? [];
$all_checked = $member['permissions']['all_apps'] ?? [];
$apps_checked = $member['permissions']['apps'] ?? [];
$is_self = (int) (($actor['member_id'] ?? 0)) === (int) ($member['id'] ?? -1);
?>
<main class="panel tc-panel">
    <div class="section-header">
        <div>
            <p class="eyebrow">TEAM MEMBER</p>
            <h1><?= $e($member['name'] !== '' ? $member['name'] : $member['email']) ?></h1>
            <p class="muted" style="margin:6px 0 0">
                <?= $e($member['email']) ?>
                &middot; <?= $member['active'] ? 'Active' : 'Suspended' ?>
                <?php if (!empty($member['last_login'])): ?>
                    &middot; Last sign-in <?= $e(gmdate('Y-m-d H:i', (int) $member['last_login'])) ?> UTC
                <?php endif ?>
            </p>
        </div>
        <a class="btn-secondary" href="/team">&larr; Back to team</a>
    </div>

    <?php if (!empty($saved_notice)): ?>
        <p class="flash" role="status" style="margin-bottom:20px"><?= $e($saved_notice) ?></p>
    <?php endif ?>

    <div class="settings-stack">
        <section class="ov-card">
            <div class="ov-card-head">
                <h2>Granular access</h2>
                <span class="muted">Changes apply immediately</span>
            </div>
            <div class="ov-pad">
                <form method="post" action="/team/update_member" class="settings-form" data-access-form>
                    <?= $csrf() ?>
                    <input type="hidden" name="member_id" value="<?= (int) $member['id'] ?>">

                    <div class="settings-field">
                        <label for="member-name">Display name</label>
                        <input type="text" id="member-name" name="name" value="<?= $e($member['name']) ?>" maxlength="120" required>
                    </div>

                    <fieldset class="access-mode-fieldset">
                        <legend>Access level</legend>
                        <label class="access-mode-opt">
                            <input type="radio" name="access_mode" value="scoped"<?= $mode === 'scoped' ? ' checked' : '' ?>>
                            <span><strong>Specific apps</strong><small>Access only to apps granted below</small></span>
                        </label>
                        <label class="access-mode-opt">
                            <input type="radio" name="access_mode" value="all"<?= $mode === 'all' ? ' checked' : '' ?>>
                            <span><strong>All apps</strong><small>Default permissions on every app, plus optional per-app extras</small></span>
                        </label>
                        <label class="access-mode-opt">
                            <input type="radio" name="access_mode" value="admin"<?= $mode === 'admin' ? ' checked' : '' ?>>
                            <span><strong>Workspace administrator</strong><small>Full access to all apps, servers, services, and team</small></span>
                        </label>
                    </fieldset>

                    <div data-access-section="all"<?= $mode !== 'all' ? ' hidden' : '' ?>>
                        <div class="perm-app-card" data-perm-group>
                            <div class="perm-app-head">
                                <strong>Default permissions on all apps</strong>
                                <div class="perm-presets">
                                    <?php foreach ($presets as $preset): ?>
                                        <button type="button" class="service-preset" data-perm-preset="<?= $e(implode(',', $preset['permissions'])) ?>"><?= $e($preset['label']) ?></button>
                                    <?php endforeach ?>
                                    <button type="button" class="service-preset" data-perm-preset="">None</button>
                                </div>
                            </div>
                            <div class="perm-grid">
                                <?php foreach ($app_perms_meta as $perm_key => $meta): ?>
                                    <label class="perm-check" title="<?= $e($meta['description']) ?>">
                                        <input type="checkbox" name="all_app_perms[]" value="<?= $e($perm_key) ?>" data-perm-key="<?= $e($perm_key) ?>"<?= in_array($perm_key, $all_checked, true) ? ' checked' : '' ?>>
                                        <span><?= $e($meta['label']) ?></span>
                                    </label>
                                <?php endforeach ?>
                            </div>
                        </div>
                    </div>

                    <div data-access-section="scoped,all"<?= $mode === 'admin' ? ' hidden' : '' ?>>
                        <?php if ($all_apps !== []): ?>
                            <div class="perm-stack">
                                <?php foreach ($all_apps as $app):
                                    $aid = (string) ($app['id'] ?? '');
                                    $alabel = (string) ($app['label'] ?? $aid);
                                    $adomain = (string) ($app['domain'] ?? '');
                                    $cur = is_array($apps_checked[$aid] ?? null) ? $apps_checked[$aid] : [];
                                ?>
                                    <div class="perm-app-card" data-perm-group>
                                        <div class="perm-app-head">
                                            <div>
                                                <strong><?= $e($alabel) ?></strong>
                                                <?php if ($adomain !== ''): ?><span class="muted ov-mono" style="font-size:12px;margin-left:6px"><?= $e($adomain) ?></span><?php endif ?>
                                            </div>
                                            <div class="perm-presets">
                                                <?php foreach ($presets as $preset): ?>
                                                    <button type="button" class="service-preset" data-perm-preset="<?= $e(implode(',', $preset['permissions'])) ?>"><?= $e($preset['label']) ?></button>
                                                <?php endforeach ?>
                                                <button type="button" class="service-preset" data-perm-preset="">None</button>
                                            </div>
                                        </div>
                                        <div class="perm-grid">
                                            <?php foreach ($app_perms_meta as $perm_key => $meta): ?>
                                                <label class="perm-check" title="<?= $e($meta['description']) ?>">
                                                    <input type="checkbox" name="app_perms[<?= $e($aid) ?>][]" value="<?= $e($perm_key) ?>" data-perm-key="<?= $e($perm_key) ?>"<?= in_array($perm_key, $cur, true) ? ' checked' : '' ?>>
                                                    <span><?= $e($meta['label']) ?></span>
                                                </label>
                                            <?php endforeach ?>
                                        </div>
                                    </div>
                                <?php endforeach ?>
                            </div>
                        <?php endif ?>

                        <div class="perm-app-card" style="margin-top:16px">
                            <div class="perm-app-head">
                                <strong>Workspace capabilities</strong>
                            </div>
                            <div class="perm-grid">
                                <?php foreach ($ws_perms_meta as $wkey => $wmeta): ?>
                                    <label class="perm-check" title="<?= $e($wmeta['description']) ?>">
                                        <input type="checkbox" name="workspace_perms[]" value="<?= $e($wkey) ?>"<?= in_array($wkey, $ws_checked, true) ? ' checked' : '' ?>>
                                        <span><?= $e($wmeta['label']) ?></span>
                                    </label>
                                <?php endforeach ?>
                            </div>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn-primary">Save access</button>
                        <a href="/team" class="btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </section>

        <?php if (!$is_self): ?>
            <section class="ov-card danger-card">
                <div class="ov-card-head">
                    <h2>Account status</h2>
                </div>
                <div class="ov-pad">
                    <div class="form-actions">
                        <form method="post" action="/team/toggle_member" style="margin:0">
                            <?= $csrf() ?>
                            <input type="hidden" name="member_id" value="<?= (int) $member['id'] ?>">
                            <input type="hidden" name="active" value="<?= $member['active'] ? '0' : '1' ?>">
                            <button type="submit" class="btn-secondary"><?= $member['active'] ? 'Suspend member' : 'Reactivate member' ?></button>
                        </form>
                        <form method="post" action="/team/remove_member" style="margin:0" data-confirm="Permanently remove <?= $e($member['email']) ?> from this workspace?">
                            <?= $csrf() ?>
                            <input type="hidden" name="member_id" value="<?= (int) $member['id'] ?>">
                            <button type="submit" class="btn-danger">Remove member</button>
                        </form>
                    </div>
                    <p class="form-hint">Suspending or removing a member immediately revokes all of their active sessions.</p>
                </div>
            </section>
        <?php endif ?>
    </div>
</main>
