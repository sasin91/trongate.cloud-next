<?php
require_once APPPATH . 'modules/services/services/Service_spec.php';

$active_tab = 'services';
$app = $overview['app'];
$app_id = (string) $app['id'];
$label = (string) ($app['label'] ?? $app_id);
$services = $services ?? $overview['services'] ?? [];
$shared_services = $shared_services ?? $overview['shared_services'] ?? [];
$presets = $presets ?? Service_spec::presets();
$can_add_service = $can_add_service ?? true;
$can_manage_services = !empty($can_manage_services);
$can_shared_services_view = !empty($can_shared_services_view);
$can_shared_services_manage = !empty($can_shared_services_manage);
$app_service_names = [];
foreach ($services as $svc) {
    if (!empty($svc['name'])) {
        $app_service_names[(string) $svc['name']] = true;
    }
}
?>
<main class="panel tc-panel">
    <div class="ov">
        <?php require __DIR__ . '/_header.php'; ?>

        <?php if (!empty($saved_notice)): ?>
            <p class="flash" role="status"><?= $e($saved_notice) ?></p>
        <?php endif ?>

        <div class="services-grid">
            <div class="ov-col">
                <?php if ($shared_services !== []): ?>
                    <section class="ov-card" aria-labelledby="shared-services-h">
                        <div class="ov-card-head">
                            <h2 id="shared-services-h">Shared services</h2>
                            <?php if ($can_shared_services_view): ?>
                                <a href="/services"><?= $can_shared_services_manage ? 'Manage shared services' : 'All shared services' ?></a>
                            <?php else: ?>
                                <span class="muted">shared across apps on this server</span>
                            <?php endif ?>
                        </div>
                        <div class="ov-pad">
                            <ul class="service-list">
                                <?php foreach ($shared_services as $svc):
                                    $svc_id = (string) ($svc['id'] ?? $svc['name']);
                                    $svc_name = (string) ($svc['name'] ?? '');
                                    $status = (string) ($svc['status'] ?? 'queued');
                                    $pill = match ($status) {
                                        'running' => 'succeeded',
                                        'failed' => 'failed',
                                        default => 'pending',
                                    };
                                    $var = Service_spec::url_variable($svc_name);
                                    $url_line = !empty($svc['url']) ? $var . '=' . $svc['url'] : $var . ' (assigned on start)';
                                    $overridden = isset($app_service_names[$svc_name]);
                                ?>
                                    <li class="service-row">
                                        <span class="service-who">
                                            <strong>
                                                <span><?= $e($svc_name) ?></span>
                                                <span class="status-pill status-<?= $e($pill) ?>"><?= $e($status) ?></span>
                                                <span class="ov-sha">shared</span>
                                            </strong>
                                            <small class="mono"><?= $e($svc['command'] ?? '') ?></small>
                                            <small><code><?= $e($url_line) ?></code> &middot; <?= (int) ($svc['memory_mb'] ?? Service_spec::DEFAULT_MEMORY_MB) ?> MB<?php if ($overridden): ?> &middot; overridden by app service<?php endif ?></small>
                                            <?php if (!empty($svc['error'])): ?>
                                                <small class="wz-errors"><?= $e($svc['error']) ?></small>
                                            <?php endif ?>
                                        </span>
                                        <?php if ($can_shared_services_view): ?>
                                            <span class="service-actions">
                                                <a class="btn-link" href="/services#service-<?= $e(rawurlencode($svc_id)) ?>"><?= $can_shared_services_manage ? 'Manage' : 'View' ?></a>
                                            </span>
                                        <?php endif ?>
                                    </li>
                                <?php endforeach ?>
                            </ul>
                        </div>
                    </section>
                <?php endif ?>

                <section class="ov-card" aria-labelledby="services-h">
                    <div class="ov-card-head">
                        <h2 id="services-h">Services</h2>
                        <span class="muted">private services <?= $e($label) ?> can call</span>
                    </div>
                    <div class="ov-pad">
                        <?php if ($services === []): ?>
                            <?php if ($shared_services !== []): ?>
                                <p class="muted" style="font-size:14px">No app-specific services yet for <?= $e($label) ?>. Shared services listed above are already available on deploy; add one here only if <?= $e($label) ?> needs its own dedicated instance.</p>
                            <?php else: ?>
                                <p class="muted" style="font-size:14px">No services yet for <?= $e($label) ?>. Add one and <?= $e($label) ?> automatically gets its <code>{NAME}_URL</code> on its next deploy.</p>
                            <?php endif ?>
                        <?php else: ?>
                            <ul class="service-list">
                                <?php foreach ($services as $svc):
                                    $svc_id = (string) ($svc['id'] ?? $svc['name']);
                                    $status = (string) ($svc['status'] ?? 'queued');
                                    $pill = match ($status) {
                                        'running' => 'succeeded',
                                        'failed' => 'failed',
                                        default => 'pending',
                                    };
                                    $var = Service_spec::url_variable((string) $svc['name']);
                                    $url_line = !empty($svc['url']) ? $var . '=' . $svc['url'] : $var . ' (assigned on start)';
                                    $remove_q = 'Remove ' . $svc['name'] . ' from ' . $label . '? Redeploy ' . $label . ' after removing it.';
                                ?>
                                    <li class="service-row">
                                        <span class="service-who">
                                            <strong>
                                                <span><?= $e($svc['name']) ?></span>
                                                <span class="status-pill status-<?= $e($pill) ?>"><?= $e($status) ?></span>
                                            </strong>
                                            <small class="mono"><?= $e($svc['command'] ?? '') ?></small>
                                            <small><code><?= $e($url_line) ?></code> &middot; <?= (int) ($svc['memory_mb'] ?? Service_spec::DEFAULT_MEMORY_MB) ?> MB</small>
                                            <?php if (!empty($svc['error'])): ?>
                                                <small class="wz-errors"><?= $e($svc['error']) ?></small>
                                            <?php endif ?>
                                        </span>
                                        <?php if ($can_manage_services): ?>
                                            <span class="service-actions">
                                                <form method="post" action="/services/redeploy">
                                                    <?= $csrf() ?>
                                                    <input type="hidden" name="app" value="<?= $e($app_id) ?>">
                                                    <input type="hidden" name="service" value="<?= $e($svc_id) ?>">
                                                    <button type="submit" class="btn-link">Redeploy</button>
                                                </form>
                                                <form method="post" action="/services/remove" data-confirm="<?= $e($remove_q) ?>">
                                                    <?= $csrf() ?>
                                                    <input type="hidden" name="app" value="<?= $e($app_id) ?>">
                                                    <input type="hidden" name="service" value="<?= $e($svc_id) ?>">
                                                    <button type="submit" class="btn-link">Remove</button>
                                                </form>
                                            </span>
                                        <?php endif ?>
                                    </li>
                                <?php endforeach ?>
                            </ul>
                        <?php endif ?>
                    </div>
                </section>
            </div>

            <?php if ($can_add_service): ?>
                <section class="ov-card" aria-labelledby="add-service-h">
                    <div class="ov-card-head">
                        <h2 id="add-service-h">Add a service</h2>
                        <span class="muted">for <?= $e($label) ?></span>
                    </div>
                    <div class="ov-pad">
                        <?php if ($presets !== []): ?>
                            <p class="form-hint">Quick presets:</p>
                            <div class="service-presets">
                                <?php foreach ($presets as $key => $preset): ?>
                                    <button type="button" class="service-preset" data-service-preset="<?= $e(json_encode($preset, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)) ?>"><?= $e($preset['label']) ?></button>
                                <?php endforeach ?>
                            </div>
                        <?php endif ?>

                        <form method="post" action="/services/add" class="settings-form" id="add-service-form">
                            <?= $csrf() ?>
                            <input type="hidden" name="app" value="<?= $e($app_id) ?>">

                            <div class="settings-field">
                                <label for="service_name">Name</label>
                                <input class="mono" type="text" id="service_name" name="service_name" value="<?= $value('service_name') ?>" placeholder="laya" maxlength="40" pattern="[a-z][a-z0-9_]*" required>
                                <p class="form-hint"><?= $e($label) ?> gets <code>{NAME}_URL</code> on its next deploy.</p>
                            </div>

                            <div class="settings-field">
                                <label for="service_packages">Packages</label>
                                <input class="mono" type="text" id="service_packages" name="service_packages" value="<?= $value('service_packages') ?>" placeholder="py312-pytorch py312-transformers py312-fastapi py312-uvicorn">
                                <p class="form-hint">Optional space-separated packages installed for this service.</p>
                            </div>

                            <div class="settings-field">
                                <label for="service_setup">Setup commands</label>
                                <textarea class="mono" id="service_setup" name="service_setup" rows="4" placeholder="Optional one-time setup commands run during deploy" spellcheck="false"><?= $value('service_setup') ?></textarea>
                                <p class="form-hint">Runs during deploy; <code>/var/db/service</code> persists across redeploys.</p>
                            </div>

                            <div class="settings-field">
                                <label for="service_command">Start command</label>
                                <input class="mono" type="text" id="service_command" name="service_command" value="<?= $value('service_command') ?>" placeholder="/var/db/service/venv/bin/python -m laya.serve" maxlength="500" required>
                                <p class="form-hint">Supervised always-on process.</p>
                            </div>

                            <div class="service-row-2">
                                <div class="settings-field">
                                    <label for="service_port">Port</label>
                                    <input class="mono" type="number" id="service_port" name="service_port" value="<?= $value('service_port', (string) Service_spec::DEFAULT_PORT) ?>" min="1024" max="65535" required>
                                    <p class="form-hint">Also passed as <code>PORT</code>.</p>
                                </div>
                                <div class="settings-field">
                                    <label for="service_memory_mb">Memory (MB)</label>
                                    <input class="mono" type="number" id="service_memory_mb" name="service_memory_mb" value="<?= $value('service_memory_mb', (string) Service_spec::DEFAULT_MEMORY_MB) ?>" min="<?= Service_spec::MIN_MEMORY_MB ?>" max="<?= Service_spec::MAX_MEMORY_MB ?>" required>
                                    <p class="form-hint">Maximum memory allocated to this service.</p>
                                </div>
                            </div>

                            <div class="settings-field">
                                <label for="service_env">Environment variables</label>
                                <textarea class="mono" id="service_env" name="service_env" rows="4" placeholder="KEY=value, one per line" spellcheck="false" autocomplete="off"><?= $value('service_env') ?></textarea>
                                <p class="form-hint">Write-only, encrypted at rest. Reachable only over private networking.</p>
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="btn-primary">Add service</button>
                            </div>
                        </form>
                    </div>
                </section>
            <?php endif ?>
        </div>
    </div>
</main>
