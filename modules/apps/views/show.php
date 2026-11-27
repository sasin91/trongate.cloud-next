<?php
$active_tab = 'overview';
$app = $overview['app'];
$app_id = (string) $app['id'];
$label = (string) ($app['label'] ?? $app_id);
$domain = (string) ($app['domain'] ?? '');
$repository = (string) ($app['repository'] ?? '');
$branch = (string) ($overview['settings']['branch'] ?? $app['branch'] ?? 'main');
$releases = $overview['releases'];
$latest = $overview['latest_release'];
$deployments = $overview['deployments'];
$current = $overview['current'];
$live = $overview['live_state'];
$canary = $overview['canary'];
$services = $overview['services'];
$shared_services = $overview['shared_services'] ?? [];
$can_rollback = (bool) $overview['can_rollback'];
$database_name = (string) $overview['database_name'];
$php_version = (string) ($latest['spec']['php_version'] ?? $app['php_version'] ?? '8.4');
$health_path = (string) ($overview['settings']['health'] ?? $latest['spec']['health'] ?? '/');
$active_slot = (string) ($current['slot'] ?? '');

$actor_ctx = is_array($actor ?? null) ? $actor : null;
$can_deploy = $actor_ctx === null || Access_policy::can_app($actor_ctx, $app_id, 'deploy');
$can_deployments = $actor_ctx === null || Access_policy::can_app($actor_ctx, $app_id, 'deployments');
$can_database = $actor_ctx === null || Access_policy::can_app($actor_ctx, $app_id, 'database_read');
$can_logs = $actor_ctx === null || Access_policy::can_app($actor_ctx, $app_id, 'logs');
$can_services_view = $actor_ctx === null || Access_policy::can_app($actor_ctx, $app_id, 'services_view');
$can_services_manage = $actor_ctx === null || Access_policy::can_app($actor_ctx, $app_id, 'services_manage');
$can_shared_services_view = $actor_ctx === null || Access_policy::can_workspace($actor_ctx, 'services.view');
$can_shared_services_manage = $actor_ctx === null || Access_policy::can_workspace($actor_ctx, 'services.manage');
$can_settings = $actor_ctx === null || Access_policy::can_app($actor_ctx, $app_id, 'settings');
$can_delete = $actor_ctx === null || Access_policy::can_app($actor_ctx, $app_id, 'delete');
?>
<main class="panel tc-panel">
    <div class="ov">
        <?php require __DIR__ . '/_header.php'; ?>

        <div class="ov-main">
            <div class="ov-col">
                <section class="ov-card" aria-labelledby="env-h">
                    <div class="ov-card-head">
                        <h2 id="env-h">Environment</h2>
                        <span class="muted">production</span>
                    </div>
                    <div class="ov-canvas">
                        <div class="ov-env">
                            <div class="ov-node">
                                <span class="ov-node-kind">Source</span>
                                <strong><?= $e($repository !== '' ? $repository : $label) ?></strong>
                                <span class="muted">Branch <?= $e($branch) ?></span>
                            </div>
                            <div class="ov-link" aria-hidden="true"></div>
                            <div class="ov-node ov-node-app">
                                <span class="ov-node-kind">App</span>
                                <strong><?= $e($label) ?></strong>
                                <div class="ov-chips">
                                    <span>PHP <?= $e($php_version) ?></span>
                                    <span>HTTPS</span>
                                    <?php if ($canary !== null): ?>
                                        <span class="ov-chip-canary">Canary <?= (int) ($canary['weight'] ?? 0) ?>%</span>
                                    <?php endif ?>
                                </div>
                            </div>
                            <div class="ov-link" aria-hidden="true"></div>
                            <div class="ov-node">
                                <span class="ov-node-kind">Domains</span>
                                <strong><?= $domain !== '' ? '1 domain' : '0 domains' ?></strong>
                                <span class="muted">All on HTTPS</span>
                            </div>
                        </div>

                        <div class="ov-db-wrap">
                            <div class="ov-link-v" aria-hidden="true"></div>
                            <div class="ov-attached">
                                <div class="ov-node ov-node-db">
                                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v12c0 1.7 3.6 3 8 3s8-1.3 8-3V6"/><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/></svg>
                                    <div>
                                        <span class="ov-node-kind">Database</span>
                                        <strong class="ov-mono"><?= $e($database_name) ?></strong>
                                        <span class="muted">MariaDB<?php if ($can_database): ?> &middot; <a href="/apps/database/<?= $e(rawurlencode($app_id)) ?>">Open</a><?php endif ?></span>
                                    </div>
                                </div>

                                <div class="ov-node ov-node-db">
                                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                                    <div>
                                        <span class="ov-node-kind">Sessions</span>
                                        <strong class="ov-mono"><?= $e($database_name) ?></strong>
                                        <span class="muted">Managed cache</span>
                                    </div>
                                </div>

                                <?php if ($can_services_view):
                                    $app_service_names = [];
                                    foreach ($services as $svc) {
                                        if (!empty($svc['name'])) {
                                            $app_service_names[(string) $svc['name']] = true;
                                        }
                                    }
                                ?>
                                    <?php foreach ($shared_services as $svc):
                                        $svc_id = (string) ($svc['id'] ?? $svc['name'] ?? '');
                                        $svc_name = (string) ($svc['name'] ?? '');
                                        if ($svc_name === '' || isset($app_service_names[$svc_name])) {
                                            continue;
                                        }
                                        $svc_status = (string) ($svc['status'] ?? 'pending');
                                    ?>
                                        <div class="ov-node ov-node-db">
                                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 8l-9-5-9 5v8l9 5 9-5z"/><path d="M3 8l9 5 9-5"/><path d="M12 13v8"/></svg>
                                            <div>
                                                <span class="ov-node-kind">Shared service</span>
                                                <strong class="ov-mono"><?= $e($svc_name) ?></strong>
                                                <span class="muted"><?= $e(Service_spec::url_variable($svc_name)) ?> &middot; <span class="<?= $svc_status === 'failed' ? 'ov-warn-text' : '' ?>"><?= $e($svc_status) ?></span><?php if ($can_shared_services_view): ?> &middot; <a href="/services#service-<?= $e(rawurlencode($svc_id)) ?>"><?= $can_shared_services_manage ? 'Manage' : 'View' ?></a><?php endif ?></span>
                                            </div>
                                        </div>
                                    <?php endforeach ?>
                                    <?php foreach ($services as $svc):
                                        $svc_name = (string) ($svc['name'] ?? '');
                                        $svc_status = (string) ($svc['status'] ?? 'pending');
                                    ?>
                                        <div class="ov-node ov-node-db">
                                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 8l-9-5-9 5v8l9 5 9-5z"/><path d="M3 8l9 5 9-5"/><path d="M12 13v8"/></svg>
                                            <div>
                                                <span class="ov-node-kind">Service</span>
                                                <strong class="ov-mono"><?= $e($svc_name) ?></strong>
                                                <span class="muted"><?= $e(Service_spec::url_variable($svc_name)) ?> &middot; <span class="<?= $svc_status === 'failed' ? 'ov-warn-text' : '' ?>"><?= $e($svc_status) ?></span> &middot; <a href="/apps/services/<?= $e(rawurlencode($app_id)) ?>"><?= $can_services_manage ? 'Manage' : 'View' ?></a></span>
                                            </div>
                                        </div>
                                    <?php endforeach ?>
                                <?php endif ?>
                            </div>
                            <?php if ($can_services_manage && $services === []): ?>
                                <a class="ov-add-service" href="/apps/services/<?= $e(rawurlencode($app_id)) ?>">Add a service</a>
                            <?php endif ?>
                        </div>
                    </div>
                </section>

                <?php if ($canary !== null):
                    $cw = (int) ($canary['weight'] ?? 0);
                    $ccommit = (string) ($canary['commit'] ?? '');
                ?>
                    <section class="ov-card canary-card" aria-label="Active canary deployment">
                        <div class="ov-card-head">
                            <h2>Canary rollout active</h2>
                            <span class="ov-badge ok"><?= $cw === 0 ? 'Preview only (0%)' : $cw . '% traffic' ?></span>
                        </div>
                        <div class="ov-pad">
                            <p class="muted">
                                <?php if ($ccommit !== ''): ?>
                                    Commit <code class="ov-sha"><?= $e(substr($ccommit, 0, 7)) ?></code> &middot;
                                <?php endif ?>
                                Serving alongside the stable release with sticky visitor routing.
                            </p>
                            <?php if ($domain !== ''): ?>
                                <p class="ov-links">
                                    <a href="https://<?= $e($domain) ?>/?tc_canary=1" target="_blank" rel="noopener">Preview canary ↗</a>
                                    <a href="https://<?= $e($domain) ?>/?tc_canary=0" target="_blank" rel="noopener">View stable ↗</a>
                                    <span class="muted">Header <code>X-Canary: 1</code></span>
                                </p>
                            <?php endif ?>
                            <?php if ($can_deploy): ?>
                                <div class="canary-weights">
                                    <?php foreach ([0 => '0% preview', 10 => '10%', 25 => '25%', 50 => '50%'] as $pct => $wlabel): ?>
                                        <form method="post" action="/deployments/change">
                                            <?= $csrf() ?>
                                            <input type="hidden" name="app" value="<?= $e($app_id) ?>">
                                            <input type="hidden" name="canary_weight" value="<?= $pct ?>">
                                            <button class="service-preset<?= $cw === $pct ? ' is-active' : '' ?>" name="command" value="canary-weight"<?= $cw === $pct ? ' disabled' : '' ?>><?= $e($wlabel) ?></button>
                                        </form>
                                    <?php endforeach ?>
                                </div>
                                <div class="canary-actions">
                                    <form method="post" action="/deployments/change" data-confirm="Promote this canary to 100% of traffic?">
                                        <?= $csrf() ?>
                                        <input type="hidden" name="app" value="<?= $e($app_id) ?>">
                                        <button class="btn-primary" name="command" value="canary-promote">Promote to 100%</button>
                                    </form>
                                    <form method="post" action="/deployments/change" data-confirm="Abort this canary and route 100% of traffic back to the stable release?">
                                        <?= $csrf() ?>
                                        <input type="hidden" name="app" value="<?= $e($app_id) ?>">
                                        <button class="btn-secondary" name="command" value="canary-abort">Abort canary</button>
                                    </form>
                                </div>
                            <?php endif ?>
                        </div>
                    </section>
                <?php endif ?>

                <?php if ($can_deployments): ?>
                <section class="ov-card" id="deployments" aria-labelledby="dep-h">
                    <div class="ov-card-head">
                        <h2 id="dep-h">Deployments</h2>
                        <a href="/apps/deployments/<?= $e(rawurlencode($app_id)) ?>">All deployments</a>
                    </div>
                    <?php if ($deployments === []): ?>
                        <p class="ov-empty muted">No deployments yet.</p>
                    <?php else: ?>
                        <div class="ov-table-wrap">
                            <table class="deployments-table">
                                <thead>
                                    <tr>
                                        <th>Status</th>
                                        <th>Commit</th>
                                        <th>Trigger</th>
                                        <th>Started</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($deployments as $d): ?>
                                        <tr>
                                            <td>
                                                <a href="/deployments?operation=<?= $e(rawurlencode($d['id'])) ?>" class="status-pill status-<?= $e($d['status']) ?>"><?= $e($d['status']) ?></a>
                                                <?php if ($d['is_live']): ?><span class="current-tag">live</span><?php endif ?>
                                            </td>
                                            <td>
                                                <?php if ($d['commit'] !== ''): ?>
                                                    <code class="ov-sha"><?= $e(substr($d['commit'], 0, 7)) ?></code>
                                                <?php else: ?>
                                                    <span class="muted">&mdash;</span>
                                                <?php endif ?>
                                            </td>
                                            <td class="muted"><?= $e($d['trigger']) ?></td>
                                            <td class="muted"><?= $d['started_at'] > 0 ? $e(gmdate('Y-m-d H:i:s', $d['started_at'])) : '—' ?></td>
                                            <td class="row-actions">
                                                <?php if ($can_deploy && $canary === null && !$d['is_live'] && $d['release_id'] !== '' && $d['status'] === 'succeeded'): ?>
                                                    <form method="post" action="/deployments/change" data-confirm="Deploy this release to <?= $e($label) ?>?">
                                                        <?= $csrf() ?>
                                                        <input type="hidden" name="app" value="<?= $e($app_id) ?>">
                                                        <input type="hidden" name="release" value="<?= $e($d['release_id']) ?>">
                                                        <button type="submit" class="btn-link" name="command" value="redeploy-app">Roll back to this</button>
                                                    </form>
                                                <?php endif ?>
                                            </td>
                                        </tr>
                                    <?php endforeach ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif ?>
                </section>
                <?php endif ?>
            </div>

            <div class="ov-col">
                <section class="ov-card ov-pad" aria-labelledby="live-h">
                    <h2 id="live-h">Live deployment</h2>
                    <?php if ($current === null): ?>
                        <p class="muted">Not live yet. Your first deployment shows up on the left.</p>
                    <?php else: ?>
                        <p class="ov-current">
                            <?php if ($current['commit'] !== ''): ?>
                                <code class="ov-sha"><?= $e(substr($current['commit'], 0, 7)) ?></code>
                            <?php endif ?>
                            <span class="muted"><?= $e($current['trigger']) ?><?= $current['completed_at'] > 0 ? ' · ' . $e(gmdate('Y-m-d H:i', $current['completed_at'])) . ' UTC' : '' ?></span>
                        </p>
                        <p class="ov-links">
                            <?php if ($can_deployments): ?>
                                <a href="/deployments?operation=<?= $e(rawurlencode($current['id'])) ?>">Build log</a>
                            <?php endif ?>
                            <?php if ($current['commit'] !== '' && $repository !== '' && preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~D', $repository)): ?>
                                <a href="https://github.com/<?= $e($repository) ?>/commit/<?= $e($current['commit']) ?>" target="_blank" rel="noopener">View on GitHub ↗</a>
                            <?php endif ?>
                        </p>
                    <?php endif ?>
                </section>

                <?php if ($can_logs && is_array($log_rates) && $log_rates !== []):
                    $err_count = array_sum(array_column($log_rates, 'errors'));
                    $warn_count = array_sum(array_column($log_rates, 'warnings'));
                    $sampled = array_sum(array_column($log_rates, 'total'));
                    $peak = max(1, ...array_map(static fn(array $h) => $h['errors'] + $h['warnings'], $log_rates));
                    $plural = static fn(int $n, string $w) => number_format($n) . ' ' . $w . ($n === 1 ? '' : 's');
                ?>
                    <section class="ov-card" aria-labelledby="err-h">
                        <div class="ov-card-head">
                            <h2 id="err-h">Errors</h2>
                            <a href="/apps/logs/<?= $e(rawurlencode($app_id)) ?>">View logs</a>
                        </div>
                        <div class="ov-rates">
                            <?php if ($sampled === 0): ?>
                                <p class="muted">Nothing logged in the last 24 hours. Counts start once your app writes output.</p>
                            <?php else: ?>
                                <p class="ov-rates-sum">
                                    <span><i class="ov-swatch ov-swatch-error" aria-hidden="true"></i><strong><?= $e($plural($err_count, 'error')) ?></strong></span>
                                    <span><i class="ov-swatch ov-swatch-warn" aria-hidden="true"></i><strong><?= $e($plural($warn_count, 'warning')) ?></strong></span>
                                    <span class="muted">last 24 h</span>
                                </p>
                                <div class="ov-rates-bars" role="img" aria-label="<?= $e($plural($err_count, 'error') . ' and ' . $plural($warn_count, 'warning') . ' in the last 24 hours') ?>">
                                    <?php foreach ($log_rates as $hour): ?>
                                        <span class="ov-rate-bar" title="<?= $e(substr($hour['hour'], 11, 5) . ' UTC: ' . $plural($hour['errors'], 'error') . ', ' . $plural($hour['warnings'], 'warning') . ' of ' . $plural($hour['total'], 'line')) ?>">
                                            <?php if ($hour['warnings'] > 0): ?><i class="ov-rate-warn" style="height:<?= round($hour['warnings'] / $peak * 100, 1) ?>%"></i><?php endif ?>
                                            <?php if ($hour['errors'] > 0): ?><i class="ov-rate-error" style="height:<?= round($hour['errors'] / $peak * 100, 1) ?>%"></i><?php endif ?>
                                        </span>
                                    <?php endforeach ?>
                                </div>
                                <div class="ov-rates-axis muted" aria-hidden="true"><span>24 h ago</span><span>now</span></div>
                            <?php endif ?>
                        </div>
                    </section>
                <?php endif ?>

                <?php if ($can_deploy && $canary === null && $releases !== []): ?>
                    <section class="ov-card ov-pad" aria-labelledby="canary-launch-h">
                        <h2 id="canary-launch-h">Canary rollout</h2>
                        <p class="muted" style="font-size:13px">Test a release with sticky traffic splitting before promoting to 100%.</p>
                        <form method="post" action="/deployments/change" class="ov-canary-form">
                            <?= $csrf() ?>
                            <input type="hidden" name="app" value="<?= $e($app_id) ?>">
                            <label class="ov-field-sm">
                                <span>Release</span>
                                <select name="release" required>
                                    <?php foreach ($releases as $rel):
                                        $rsha = (string) ($rel['metadata']['commit'] ?? $rel['spec']['commit'] ?? '');
                                        $rtitle = strtok(trim((string) ($rel['metadata']['message'] ?? '')), "\n") ?: ($rsha !== '' ? 'Commit ' . substr($rsha, 0, 7) : substr((string) $rel['id'], 0, 7));
                                    ?>
                                        <option value="<?= $e($rel['id']) ?>"><?= $e(substr($rsha !== '' ? $rsha : (string) $rel['id'], 0, 7)) ?> — <?= $e(mb_strimwidth($rtitle, 0, 34, '…')) ?></option>
                                    <?php endforeach ?>
                                </select>
                            </label>
                            <div class="canary-launch">
                                <label class="canary-split-label">
                                    <span>Split</span>
                                    <select name="canary_weight" aria-label="Canary traffic percentage">
                                        <option value="10" selected>10%</option>
                                        <option value="25">25%</option>
                                        <option value="50">50%</option>
                                        <option value="0">0% (preview)</option>
                                    </select>
                                </label>
                                <button type="submit" class="btn-secondary" name="command" value="canary-app">Deploy as canary</button>
                            </div>
                        </form>
                    </section>
                <?php endif ?>

                <section class="ov-card" aria-labelledby="dom-h">
                    <div class="ov-card-head">
                        <h2 id="dom-h">Domains</h2>
                        <span class="muted">HTTPS</span>
                    </div>
                    <?php if ($domain !== ''): ?>
                        <div class="ov-domain">
                            <span class="ov-mono"><?= $e($domain) ?></span>
                            <span class="ov-badge ok">HTTPS</span>
                        </div>
                        <p class="ov-domain-hint muted">Served automatically over HTTPS.</p>
                    <?php endif ?>
                </section>

                <section class="ov-card" id="settings" aria-labelledby="set-h">
                    <div class="ov-card-head">
                        <h2 id="set-h">Quick settings</h2>
                        <?php if ($can_settings): ?>
                            <a href="/apps/settings/<?= $e(rawurlencode($app_id)) ?>">Edit</a>
                        <?php endif ?>
                    </div>
                    <dl class="ov-settings">
                        <div><dt>PHP</dt><dd><?= $e($php_version) ?></dd></div>
                        <div><dt>Branch</dt><dd><code class="ov-sha"><?= $e($branch) ?></code></dd></div>
                        <div><dt>Deploy on push</dt><dd><?= !empty($overview['settings']['auto_deploy']) ? 'On' : 'Off' ?></dd></div>
                        <div><dt>Health check</dt><dd><code class="ov-sha"><?= $e($health_path) ?></code></dd></div>
                        <div><dt>Database</dt><dd><code class="ov-sha"><?= $e($database_name) ?></code></dd></div>
                        <div><dt>Canary</dt><dd><?= $canary !== null ? ((int) ($canary['weight'] ?? 0) . '%') : 'Off' ?></dd></div>
                    </dl>
                    <?php if ($can_delete): ?>
                        <div class="ov-danger-foot">
                            <details>
                                <summary class="link-button">Delete app</summary>
                                <form method="post" action="/apps/destroy" data-confirm="Delete <?= $e($label) ?>? Running instances and routes will be removed." style="margin-top:12px">
                                    <?= $csrf() ?>
                                    <input type="hidden" name="app" value="<?= $e($app_id) ?>">
                                    <p class="wz-hint">Stops your app, removes its domain routing, and clears active sessions.</p>
                                    <label class="wz-check">
                                        <input type="checkbox" name="purge_data" value="1">
                                        <span>Also permanently purge database &amp; persistent data</span>
                                    </label>
                                    <p style="margin-top:10px;margin-bottom:0"><button class="btn-danger" type="submit">Confirm delete <?= $e($label) ?></button></p>
                                </form>
                            </details>
                        </div>
                    <?php endif ?>
                </section>
            </div>
        </div>
    </div>
</main>
