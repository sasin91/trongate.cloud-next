<?php
$administrators = $administrators ?? [];
$members = $members ?? [];
$invitations = $invitations ?? [];
$all_apps = $all_apps ?? [];
$invite_link = is_array($invite_link ?? null) ? $invite_link : null;
$presets = Access_policy::APP_PRESETS;
$app_perms_meta = Access_policy::APP_PERMISSIONS;
$ws_perms_meta = Access_policy::WORKSPACE_PERMISSIONS;
?>
<main class="panel tc-panel">
    <div class="section-header">
        <div>
            <p class="eyebrow">ACCESS CONTROL</p>
            <h1>Team &amp; invitations.</h1>
            <p class="muted" style="margin:6px 0 0">Invite collaborators with exact per-app or workspace permissions.</p>
        </div>
        <a class="btn-secondary" href="/apps">&larr; Back to apps</a>
    </div>

    <?php if (!empty($saved_notice)): ?>
        <p class="flash" role="status" style="margin-bottom:20px"><?= $e($saved_notice) ?></p>
    <?php endif ?>

    <?php if ($invite_link !== null): ?>
        <section class="ov-card invite-banner" aria-label="Invitation link" style="margin-bottom:24px">
            <div class="ov-card-head">
                <h2>Invite link for <?= $e($invite_link['email']) ?></h2>
                <span class="ov-badge ok">Shown once</span>
            </div>
            <div class="ov-pad">
                <p class="form-hint" style="margin:0">
                    <?php if (!empty($invite_link['sent'])): ?>
                        An invitation email was sent to <strong><?= $e($invite_link['email']) ?></strong>. You can also copy this link directly:
                    <?php elseif (($invite_link['mail_error'] ?? '') !== ''): ?>
                        Email delivery failed (<?= $e($invite_link['mail_error']) ?>). Share this link directly with <strong><?= $e($invite_link['email']) ?></strong>:
                    <?php else: ?>
                        Share this private link with <strong><?= $e($invite_link['email']) ?></strong> (expires <?= $e(gmdate('Y-m-d H:i', (int) $invite_link['expires_at'])) ?> UTC):
                    <?php endif ?>
                </p>
                <div class="invite-copy-row">
                    <input type="text" id="fresh-invite-url" class="mono" value="<?= $e($invite_link['url']) ?>" readonly onclick="this.select()">
                    <button type="button" class="btn-primary" data-copy-target="fresh-invite-url">Copy link</button>
                </div>
            </div>
        </section>
    <?php endif ?>

    <div class="team-layout">
        <div class="ov-col">
            <section class="ov-card" aria-labelledby="members-h">
                <div class="ov-card-head">
                    <h2 id="members-h">Members</h2>
                    <span class="muted"><?= count($administrators) + count($members) ?> total</span>
                </div>
                <div class="ov-table-wrap">
                    <table class="deployments-table">
                        <thead>
                            <tr>
                                <th>Member</th>
                                <th>Access</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($administrators as $admin): ?>
                                <tr>
                                    <td>
                                        <strong><?= $e($admin['username']) ?></strong>
                                        <div class="muted" style="font-size:12px">Primary administrator</div>
                                    </td>
                                    <td><span class="ov-badge ok">Workspace owner</span></td>
                                    <td><span class="status-pill live-running">Active</span></td>
                                    <td></td>
                                </tr>
                            <?php endforeach ?>
                            <?php foreach ($members as $m): ?>
                                <tr>
                                    <td>
                                        <strong><?= $e($m['name'] !== '' ? $m['name'] : $m['email']) ?></strong>
                                        <div class="muted" style="font-size:12px"><?= $e($m['email']) ?></div>
                                    </td>
                                    <td style="white-space:normal">
                                        <div class="access-chips">
                                            <?php foreach ($m['summary'] as $line): ?>
                                                <span class="access-chip"><?= $e($line) ?></span>
                                            <?php endforeach ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($m['active']): ?>
                                            <span class="status-pill live-running">Active</span>
                                        <?php else: ?>
                                            <span class="status-pill live-failed">Suspended</span>
                                        <?php endif ?>
                                    </td>
                                    <td style="text-align:right">
                                        <a href="/team/member/<?= (int) $m['id'] ?>" class="btn-link">Manage</a>
                                    </td>
                                </tr>
                            <?php endforeach ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="ov-card" aria-labelledby="invites-h">
                <div class="ov-card-head">
                    <h2 id="invites-h">Pending invitations</h2>
                    <span class="muted"><?= count($invitations) ?> pending</span>
                </div>
                <?php if ($invitations === []): ?>
                    <p class="ov-empty muted">No pending invitations.</p>
                <?php else: ?>
                    <div class="ov-table-wrap">
                        <table class="deployments-table">
                            <thead>
                                <tr>
                                    <th>Email</th>
                                    <th>Granted access</th>
                                    <th>Expires</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($invitations as $inv): ?>
                                    <tr>
                                        <td>
                                            <strong><?= $e($inv['email']) ?></strong>
                                            <?php if ($inv['invited_by'] !== ''): ?>
                                                <div class="muted" style="font-size:12px">by <?= $e($inv['invited_by']) ?></div>
                                            <?php endif ?>
                                        </td>
                                        <td style="white-space:normal">
                                            <div class="access-chips">
                                                <?php foreach ($inv['summary'] as $line): ?>
                                                    <span class="access-chip"><?= $e($line) ?></span>
                                                <?php endforeach ?>
                                            </div>
                                        </td>
                                        <td class="muted"><?= $e(gmdate('Y-m-d', (int) $inv['expires_at'])) ?> UTC</td>
                                        <td>
                                            <div class="team-row-actions">
                                                <form method="post" action="/team/resend_invite">
                                                    <?= $csrf() ?>
                                                    <input type="hidden" name="invitation" value="<?= $e($inv['id']) ?>">
                                                    <button type="submit" class="btn-link">New link</button>
                                                </form>
                                                <form method="post" action="/team/revoke_invite" data-confirm="Revoke invitation for <?= $e($inv['email']) ?>?">
                                                    <?= $csrf() ?>
                                                    <input type="hidden" name="invitation" value="<?= $e($inv['id']) ?>">
                                                    <button type="submit" class="btn-link">Revoke</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif ?>
            </section>
        </div>

        <section class="ov-card" aria-labelledby="invite-form-h">
            <div class="ov-card-head">
                <h2 id="invite-form-h">Invite team member</h2>
                <span class="muted">Least-privilege by default</span>
            </div>
            <div class="ov-pad">
                <form method="post" action="/team/invite" class="settings-form" data-access-form>
                    <?= $csrf() ?>

                    <div class="settings-field">
                        <label for="invite-email">Email address</label>
                        <input type="email" id="invite-email" name="email" placeholder="teammate@example.com" required autocomplete="off">
                    </div>

                    <div class="service-row-2">
                        <div class="settings-field">
                            <label for="invite-expires">Expires in</label>
                            <select id="invite-expires" name="expires_days" class="tc-select">
                                <option value="1">24 hours</option>
                                <option value="7" selected>7 days</option>
                                <option value="30">30 days</option>
                            </select>
                        </div>
                        <div class="settings-field">
                            <label for="invite-note">Personal note (optional)</label>
                            <input type="text" id="invite-note" name="note" maxlength="500" placeholder="e.g. Take a look at slop for ideas">
                        </div>
                    </div>

                    <fieldset class="access-mode-fieldset">
                        <legend>Access level</legend>
                        <label class="access-mode-opt">
                            <input type="radio" name="access_mode" value="scoped" checked>
                            <span><strong>Specific apps</strong><small>Grant tailored permissions per app (recommended)</small></span>
                        </label>
                        <label class="access-mode-opt">
                            <input type="radio" name="access_mode" value="all">
                            <span><strong>All apps</strong><small>Apply a permission set across all current and future apps</small></span>
                        </label>
                        <label class="access-mode-opt">
                            <input type="radio" name="access_mode" value="admin">
                            <span><strong>Workspace administrator</strong><small>Full access to all apps, servers, services, and team</small></span>
                        </label>
                    </fieldset>

                    <div data-access-section="scoped">
                        <?php if ($all_apps === []): ?>
                            <p class="form-hint">No apps yet. Grant workspace permissions below or choose All apps / Workspace administrator.</p>
                        <?php else: ?>
                            <div class="perm-stack">
                                <?php foreach ($all_apps as $app):
                                    $aid = (string) ($app['id'] ?? '');
                                    $alabel = (string) ($app['label'] ?? $aid);
                                    $adomain = (string) ($app['domain'] ?? '');
                                ?>
                                    <div class="perm-app-card" data-perm-group>
                                        <div class="perm-app-head">
                                            <div>
                                                <strong><?= $e($alabel) ?></strong>
                                                <?php if ($adomain !== ''): ?><span class="muted ov-mono" style="font-size:12px;margin-left:6px"><?= $e($adomain) ?></span><?php endif ?>
                                            </div>
                                            <div class="perm-presets">
                                                <?php foreach ($presets as $pkey => $preset): ?>
                                                    <button type="button" class="service-preset" data-perm-preset="<?= $e(implode(',', $preset['permissions'])) ?>"><?= $e($preset['label']) ?></button>
                                                <?php endforeach ?>
                                                <button type="button" class="service-preset" data-perm-preset="">None</button>
                                            </div>
                                        </div>
                                        <div class="perm-grid">
                                            <?php foreach ($app_perms_meta as $perm_key => $meta): ?>
                                                <label class="perm-check" title="<?= $e($meta['description']) ?>">
                                                    <input type="checkbox" name="app_perms[<?= $e($aid) ?>][]" value="<?= $e($perm_key) ?>" data-perm-key="<?= $e($perm_key) ?>">
                                                    <span><?= $e($meta['label']) ?></span>
                                                </label>
                                            <?php endforeach ?>
                                        </div>
                                    </div>
                                <?php endforeach ?>
                            </div>
                        <?php endif ?>
                    </div>

                    <div data-access-section="all" hidden>
                        <div class="perm-app-card" data-perm-group>
                            <div class="perm-app-head">
                                <strong>Permissions on every app</strong>
                                <div class="perm-presets">
                                    <?php foreach ($presets as $pkey => $preset): ?>
                                        <button type="button" class="service-preset" data-perm-preset="<?= $e(implode(',', $preset['permissions'])) ?>"><?= $e($preset['label']) ?></button>
                                    <?php endforeach ?>
                                    <button type="button" class="service-preset" data-perm-preset="">None</button>
                                </div>
                            </div>
                            <div class="perm-grid">
                                <?php foreach ($app_perms_meta as $perm_key => $meta): ?>
                                    <label class="perm-check" title="<?= $e($meta['description']) ?>">
                                        <input type="checkbox" name="all_app_perms[]" value="<?= $e($perm_key) ?>" data-perm-key="<?= $e($perm_key) ?>"<?= in_array($perm_key, $presets['viewer']['permissions'], true) ? ' checked' : '' ?>>
                                        <span><?= $e($meta['label']) ?></span>
                                    </label>
                                <?php endforeach ?>
                            </div>
                        </div>
                    </div>

                    <div data-access-section="scoped,all">
                        <div class="perm-app-card">
                            <div class="perm-app-head">
                                <strong>Workspace capabilities (optional)</strong>
                            </div>
                            <div class="perm-grid">
                                <?php foreach ($ws_perms_meta as $wkey => $wmeta): ?>
                                    <label class="perm-check" title="<?= $e($wmeta['description']) ?>">
                                        <input type="checkbox" name="workspace_perms[]" value="<?= $e($wkey) ?>">
                                        <span><?= $e($wmeta['label']) ?></span>
                                    </label>
                                <?php endforeach ?>
                            </div>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn-primary">Create invitation</button>
                    </div>
                </form>
            </div>
        </section>
    </div>
</main>
