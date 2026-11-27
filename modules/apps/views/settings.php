<?php
$active_tab = 'settings';
$app = $overview['app'];
$app_id = (string) $app['id'];
$label = (string) ($app['label'] ?? $app_id);
$domain = (string) ($app['domain'] ?? '');
$settings = $overview['settings'];
$env_names = $settings['env_names'];
$optional_extensions = implode(', ', Build_commands::OPTIONAL_EXTENSIONS);
$can_delete = !empty($can_delete);
?>
<main class="panel tc-panel">
    <div class="ov">
        <?php require __DIR__ . '/_header.php'; ?>

        <?php if (!empty($saved_notice)): ?>
            <p class="flash" role="status"><?= $e($saved_notice) ?></p>
        <?php endif ?>

        <div class="settings-stack">
            <section class="ov-card">
                <div class="ov-card-head">
                    <h2>Application settings</h2>
                    <span class="muted">Applied on next deploy</span>
                </div>
                <div class="ov-pad">
                    <form method="post" action="/apps/save_settings" class="settings-form">
                        <?= $csrf() ?>
                        <input type="hidden" name="app" value="<?= $e($app_id) ?>">

                        <div class="settings-field">
                            <label>Address</label>
                            <p><code class="ov-sha">https://<?= $e($domain) ?></code></p>
                            <p class="form-hint">Served automatically over HTTPS.</p>
                        </div>

                        <div class="settings-field">
                            <label for="branch">Branch</label>
                            <input type="text" id="branch" name="branch" class="mono" value="<?= $e($settings['branch']) ?>" required autocomplete="off">
                            <p class="form-hint">Branch or commit resolved on push or when you press <strong>Deploy now</strong>.</p>
                        </div>

                        <div class="settings-field">
                            <input type="hidden" name="auto_deploy" value="0">
                            <label class="settings-checkbox">
                                <input type="checkbox" name="auto_deploy" value="1"<?= !empty($settings['auto_deploy']) ? ' checked' : '' ?>>
                                <span>Deploy on push</span>
                            </label>
                            <p class="form-hint">
                                Pushes to the branch deploy automatically through the trongate.cloud GitHub App. Turn this off to deploy only when you press <strong>Deploy now</strong>.
                                <?php $gh = is_array($github ?? null) ? $github : []; ?>
                                <?php if (!empty($gh['connected']) && !empty($gh['install_url'])): ?>
                                    <a href="<?= $e($gh['install_url']) ?>">Manage GitHub repository access ↗</a>
                                <?php elseif (empty($gh['connected']) && !empty($gh['connect_url'])): ?>
                                    <a href="<?= $e($gh['connect_url']) ?>">Connect GitHub</a>
                                <?php endif ?>
                            </p>
                        </div>

                        <div class="settings-field">
                            <label for="health">Health check path</label>
                            <input type="text" id="health" name="health" class="mono" value="<?= $e($settings['health']) ?>" placeholder="/" required>
                            <p class="form-hint">Deploys wait for this path to answer 2xx or 3xx before switching traffic.</p>
                        </div>

                        <div class="settings-field">
                            <label for="php_extensions">Extra PHP extensions</label>
                            <input type="text" id="php_extensions" name="php_extensions" class="mono" value="<?= $e(implode(', ', $settings['extensions'])) ?>" placeholder="e.g. gd, imagick, intl">
                            <p class="form-hint">Comma-separated extensions: <code><?= $e($optional_extensions) ?></code>.</p>
                        </div>

                        <div class="settings-field">
                            <label for="build_commands">Build commands</label>
                            <textarea id="build_commands" name="build_commands" class="mono" rows="3" spellcheck="false" placeholder="npm ci&#10;npm run build"><?= $e($settings['build_commands']) ?></textarea>
                            <p class="form-hint">One command per line, run during each build after <code>composer install</code>.</p>
                        </div>

                        <div class="settings-field">
                            <label for="migration_command">Migration command</label>
                            <input type="text" id="migration_command" name="migration_command" class="mono" value="<?= $e($settings['migration_command'] ?? '') ?>" placeholder="e.g. php /app/migrate.php" autocomplete="off">
                            <p class="form-hint">Optional single command run before traffic switches on each deploy. Leave blank to manage schema changes from the <a href="/apps/database/<?= $e(rawurlencode($app_id)) ?>">Database</a> tab.</p>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn-primary">Save settings</button>
                            <a href="/apps/show/<?= $e(rawurlencode($app_id)) ?>" class="btn-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </section>

            <section class="ov-card" id="environment">
                <div class="ov-card-head">
                    <h2>Environment variables</h2>
                    <span class="muted">Encrypted at rest</span>
                </div>
                <div class="ov-pad">
                    <p class="form-hint" style="margin-top:0">Your app reads them with <code>getenv('NAME')</code>. Secret values are encrypted at rest and never displayed again after saving. Changes take effect on the next deploy.</p>

                    <?php if ($env_names !== []): ?>
                        <ul class="env-list">
                            <?php foreach ($env_names as $name): ?>
                                <li>
                                    <code class="ov-sha"><?= $e($name) ?></code>
                                    <form method="post" action="/apps/delete_env" data-confirm="Remove environment variable <?= $e($name) ?>?">
                                        <?= $csrf() ?>
                                        <input type="hidden" name="app" value="<?= $e($app_id) ?>">
                                        <input type="hidden" name="name" value="<?= $e($name) ?>">
                                        <button type="submit" class="btn-link">Remove</button>
                                    </form>
                                </li>
                            <?php endforeach ?>
                        </ul>
                    <?php else: ?>
                        <p class="muted" style="font-size:14px">No custom environment variables configured yet.</p>
                    <?php endif ?>

                    <form method="post" action="/apps/save_env" class="settings-form">
                        <?= $csrf() ?>
                        <input type="hidden" name="app" value="<?= $e($app_id) ?>">
                        <div class="settings-field">
                            <label for="environment_input">Add or update variables</label>
                            <textarea id="environment_input" name="environment" class="mono" rows="4" spellcheck="false" autocomplete="off" placeholder="API_KEY=secret&#10;APP_ENV=production"></textarea>
                            <p class="form-hint">One <code>NAME=value</code> per line. Existing keys with the same name are replaced.</p>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn-primary">Save variables</button>
                        </div>
                    </form>
                </div>
            </section>

            <?php if ($can_delete): ?>
                <section class="ov-card danger-card">
                    <div class="ov-card-head">
                        <h2>Delete app</h2>
                    </div>
                    <div class="ov-pad">
                        <p class="muted" style="font-size:14px">Stops your app, removes its domain routing, and clears active sessions.</p>
                        <form method="post" action="/apps/destroy" data-confirm="Delete <?= $e($label) ?>? Running instances and routes will be removed.">
                            <?= $csrf() ?>
                            <input type="hidden" name="app" value="<?= $e($app_id) ?>">
                            <label class="wz-check">
                                <input type="checkbox" name="purge_data" value="1">
                                <span>Also permanently purge the database and persistent data</span>
                            </label>
                            <p style="margin-top:14px;margin-bottom:0"><button class="btn-danger" type="submit">Delete <?= $e($label) ?></button></p>
                        </form>
                    </div>
                </section>
            <?php endif ?>
        </div>
    </div>
</main>
