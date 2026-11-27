<?php
$active_tab = 'access';
$app = $overview['app'];
$app_id = (string) $app['id'];
$label = (string) ($app['label'] ?? $app_id);
$app_members = $app_members ?? [];
$all_members = $all_members ?? [];
$app_invitations = $app_invitations ?? [];
$invite_link = is_array($invite_link ?? null) ? $invite_link : null;
$presets = Team::APP_PRESETS;
$app_perms_meta = Team::APP_PERMISSIONS;

$existing_ids = array_flip(array_map(static fn(array $m): int => (int) $m['id'], $app_members));
$grantable_members = array_values(array_filter(
    $all_members,
    static fn(array $m): bool => $m['role'] !== 'admin' && !isset($existing_ids[(int) $m['id']])
));
?>
<main class="panel tc-panel">
    <div class="ov">
        <?php require __DIR__ . '/_header.php'; ?>

        <?php if (!empty($saved_notice)): ?>
            <p class="flash" role="status"><?= $e($saved_notice) ?></p>
        <?php endif ?>

        <?php if ($invite_link !== null): ?>
            <section class="ov-card invite-banner" aria-label="Invitation link">
                <div class="ov-card-head">
                    <h2>Invite link for <?= $e($invite_link['email']) ?></h2>
                    <span class="ov-badge ok">Shown once</span>
                </div>
                <div class="ov-pad">
                    <p class="form-hint" style="margin:0">
                        <?php if (!empty($invite_link['sent'])): ?>
                            An invitation email was sent to <strong><?= $e($invite_link['email']) ?></strong>. You can also copy this link directly:
                        <?php else: ?>
                            Share this private link with <strong><?= $e($invite_link['email']) ?></strong> (expires <?= $e(gmdate('Y-m-d H:i', (int) $invite_link['expires_at'])) ?> UTC):
                        <?php endif ?>
                    </p>
                    <div class="invite-copy-row">
                        <input type="text" id="app-invite-url" class="mono" value="<?= $e($invite_link['url']) ?>" readonly onclick="this.select()">
                        <button type="button" class="btn-primary" data-copy-target="app-invite-url">Copy link</button>
                    </div>
                </div>
            </section>
        <?php endif ?>

        <div class="team-layout">
            <div class="ov-col">
                <section class="ov-card" aria-labelledby="app-access-members-h">
                    <div class="ov-card-head">
                        <h2 id="app-access-members-h">Who has access to <?= $e($label) ?></h2>
                        <a href="/team">All team members</a>
                    </div>
                    <div class="ov-table-wrap">
                        <table class="deployments-table">
                            <thead>
                                <tr>
                                    <th>Member</th>
                                    <th>Access on <?= $e($label) ?></th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($app_members as $m): ?>
                                    <tr>
                                        <td>
                                            <strong><?= $e($m['name'] !== '' ? $m['name'] : $m['email']) ?></strong>
                                            <div class="muted" style="font-size:12px"><?= $e($m['email']) ?> &middot; <?= $e($m['app_source']) ?></div>
                                        </td>
                                        <td style="white-space:normal">
                                            <span class="ov-badge" style="background:#ecebfa;color:var(--primary)"><?= $e($m['app_preset_label']) ?></span>
                                            <div class="muted" style="font-size:12px;margin-top:4px"><?= $e(implode(', ', $m['app_permission_labels'])) ?></div>
                                        </td>
                                        <td style="text-align:right">
                                            <div class="team-row-actions">
                                                <a href="/team/member/<?= (int) $m['id'] ?>" class="btn-link">Edit</a>
                                                <?php if ($m['role'] !== 'admin' && !empty($m['direct_permissions'])): ?>
                                                    <form method="post" action="/team/update_app_member" data-confirm="Remove <?= $e($m['email']) ?>'s direct access to <?= $e($label) ?>?">
                                                        <?= $csrf() ?>
                                                        <input type="hidden" name="app" value="<?= $e($app_id) ?>">
                                                        <input type="hidden" name="member_id" value="<?= (int) $m['id'] ?>">
                                                        <input type="hidden" name="mode" value="revoke">
                                                        <button type="submit" class="btn-link">Remove</button>
                                                    </form>
                                                <?php endif ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <?php if ($grantable_members !== []): ?>
                    <section class="ov-card" aria-labelledby="grant-existing-h">
                        <div class="ov-card-head">
                            <h2 id="grant-existing-h">Grant an existing member access</h2>
                        </div>
                        <div class="ov-pad">
                            <form method="post" action="/team/update_app_member" class="settings-form">
                                <?= $csrf() ?>
                                <input type="hidden" name="app" value="<?= $e($app_id) ?>">
                                <div class="service-row-2">
                                    <div class="settings-field">
                                        <label for="grant-member">Team member</label>
                                        <select id="grant-member" name="member_id" class="tc-select" required>
                                            <?php foreach ($grantable_members as $gm): ?>
                                                <option value="<?= (int) $gm['id'] ?>"><?= $e($gm['name'] !== '' ? $gm['name'] . ' (' . $gm['email'] . ')' : $gm['email']) ?></option>
                                            <?php endforeach ?>
                                        </select>
                                    </div>
                                    <div class="settings-field">
                                        <label for="grant-preset">Role on <?= $e($label) ?></label>
                                        <select id="grant-preset" name="preset" class="tc-select">
                                            <?php foreach ($presets as $pkey => $preset): ?>
                                                <option value="<?= $e($pkey) ?>"><?= $e($preset['label']) ?></option>
                                            <?php endforeach ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="form-actions">
                                    <button type="submit" class="btn-secondary">Grant access</button>
                                </div>
                            </form>
                        </div>
                    </section>
                <?php endif ?>

                <section class="ov-card" aria-labelledby="app-invites-h">
                    <div class="ov-card-head">
                        <h2 id="app-invites-h">Pending invitations for <?= $e($label) ?></h2>
                        <span class="muted"><?= count($app_invitations) ?> pending</span>
                    </div>
                    <?php if ($app_invitations === []): ?>
                        <p class="ov-empty muted">No pending invitations for <?= $e($label) ?>.</p>
                    <?php else: ?>
                        <div class="ov-table-wrap">
                            <table class="deployments-table">
                                <thead>
                                    <tr>
                                        <th>Email</th>
                                        <th>Access</th>
                                        <th>Expires</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($app_invitations as $inv): ?>
                                        <tr>
                                            <td><strong><?= $e($inv['email']) ?></strong></td>
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
                                                        <input type="hidden" name="return_app" value="<?= $e($app_id) ?>">
                                                        <button type="submit" class="btn-link">New link</button>
                                                    </form>
                                                    <form method="post" action="/team/revoke_invite" data-confirm="Revoke invitation for <?= $e($inv['email']) ?>?">
                                                        <?= $csrf() ?>
                                                        <input type="hidden" name="invitation" value="<?= $e($inv['id']) ?>">
                                                        <input type="hidden" name="return_app" value="<?= $e($app_id) ?>">
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

            <section class="ov-card" aria-labelledby="app-invite-h">
                <div class="ov-card-head">
                    <h2 id="app-invite-h">Invite to <?= $e($label) ?></h2>
                    <span class="muted">Scoped to this app</span>
                </div>
                <div class="ov-pad">
                    <form method="post" action="/team/invite" class="settings-form">
                        <?= $csrf() ?>
                        <input type="hidden" name="access_mode" value="scoped">
                        <input type="hidden" name="return_app" value="<?= $e($app_id) ?>">

                        <div class="settings-field">
                            <label for="app-invite-email">Email address</label>
                            <input type="email" id="app-invite-email" name="email" placeholder="teammate@example.com" required autocomplete="off">
                        </div>

                        <div class="settings-field">
                            <label for="app-invite-note">Personal note (optional)</label>
                            <input type="text" id="app-invite-note" name="note" maxlength="500" placeholder="e.g. Sharing <?= $e($label) ?> read-only for reference">
                        </div>

                        <div class="perm-app-card" data-perm-group>
                            <div class="perm-app-head">
                                <strong>Permissions on <?= $e($label) ?></strong>
                                <div class="perm-presets">
                                    <?php foreach ($presets as $preset): ?>
                                        <button type="button" class="service-preset" data-perm-preset="<?= $e(implode(',', $preset['permissions'])) ?>"><?= $e($preset['label']) ?></button>
                                    <?php endforeach ?>
                                </div>
                            </div>
                            <div class="perm-grid">
                                <?php foreach ($app_perms_meta as $perm_key => $meta): ?>
                                    <label class="perm-check" title="<?= $e($meta['description']) ?>">
                                        <input type="checkbox" name="app_perms[<?= $e($app_id) ?>][]" value="<?= $e($perm_key) ?>" data-perm-key="<?= $e($perm_key) ?>"<?= in_array($perm_key, $presets['viewer']['permissions'], true) ? ' checked' : '' ?>>
                                        <span><?= $e($meta['label']) ?></span>
                                    </label>
                                <?php endforeach ?>
                            </div>
                            <p class="form-hint" style="margin-top:8px">Defaults to <strong>Viewer</strong> (read-only overview, deployments, logs, traffic, and attached services).</p>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn-primary">Create invitation</button>
                        </div>
                    </form>
                </div>
            </section>
        </div>
    </div>
</main>
