<?php
$services = $services ?? [];
$presets = $presets ?? Service_spec::presets();
$can_add_service = $can_add_service ?? true;
$actor_ctx = is_array($actor ?? null) ? $actor : null;
?>
<main class="panel">
    <div class="panel-heading">
        <div>
            <p class="eyebrow">PROJECT SERVICES</p>
            <h1>Services.</h1>
        </div>
        <a class="wz-back" href="/apps">← Back to apps</a>
    </div>

    <div class="services-grid">
        <section class="card" aria-labelledby="services-h">
            <div class="panel-heading">
                <h2 id="services-h">Running services</h2>
                <span class="wz-hint">private services your apps can call</span>
            </div>
            <?php if ($services === []): ?>
                <p class="wz-hint">No services yet. Add one and your apps automatically get its <code>{NAME}_URL</code> on their next deploy.</p>
            <?php else: ?>
                <ul class="service-list">
                    <?php foreach ($services as $svc):
                        $svc_id = (string) ($svc['id'] ?? $svc['name']);
                        $svc_app = (string) ($svc['app'] ?? '');
                        $status = (string) ($svc['status'] ?? 'queued');
                        $pill = match ($status) {
                            'running' => 'succeeded',
                            'failed' => 'failed',
                            default => 'pending',
                        };
                        $var = Service_spec::url_variable((string) $svc['name']);
                        $url_line = !empty($svc['url']) ? $var . '=' . $svc['url'] : $var . ' (assigned on start)';
                        $remove_q = $svc_app !== ''
                            ? 'Remove ' . $svc['name'] . ' from ' . $svc_app . '? Redeploy ' . $svc_app . ' after removing it.'
                            : 'Remove ' . $svc['name'] . '? Apps calling it will get errors until you redeploy them without it.';
                    ?>
                        <li class="service-row">
                            <span class="service-who">
                                <strong>
                                    <span><?= $e($svc['name']) ?></span>
                                    <span class="status" data-status="<?= $e($pill) ?>"><?= $e($status) ?></span>
                                    <?php if ($svc_app !== ''): ?>
                                        <a class="ov-sha" href="/apps/services/<?= $e(rawurlencode($svc_app)) ?>"><?= $e($svc_app) ?></a>
                                    <?php else: ?>
                                        <span class="ov-sha">shared</span>
                                    <?php endif ?>
                                </strong>
                                <small class="mono"><?= $e($svc['command'] ?? '') ?></small>
                                <small><code><?= $e($url_line) ?></code> · <?= (int) ($svc['memory_mb'] ?? Service_spec::DEFAULT_MEMORY_MB) ?> MB</small>
                                <?php if (!empty($svc['error'])): ?>
                                    <small class="wz-errors"><?= $e($svc['error']) ?></small>
                                <?php endif ?>
                            </span>
                            <?php
                                $can_manage_row = $actor_ctx === null || ($svc_app !== ''
                                    ? Access_policy::can_app($actor_ctx, $svc_app, 'services_manage')
                                    : Access_policy::can_workspace($actor_ctx, 'services.manage'));
                            ?>
                            <?php if ($can_manage_row): ?>
                                <span class="service-actions">
                                    <form method="post" action="/services/redeploy">
                                        <?= $csrf() ?>
                                        <input type="hidden" name="service" value="<?= $e($svc_id) ?>">
                                        <button type="submit" class="link-button">Redeploy</button>
                                    </form>
                                    <form method="post" action="/services/remove" data-confirm="<?= $e($remove_q) ?>">
                                        <?= $csrf() ?>
                                        <input type="hidden" name="service" value="<?= $e($svc_id) ?>">
                                        <button type="submit" class="link-button">Remove</button>
                                    </form>
                                </span>
                            <?php endif ?>
                        </li>
                    <?php endforeach ?>
                </ul>
            <?php endif ?>
        </section>

        <?php if ($can_add_service): ?>
            <section class="card" aria-labelledby="add-service-h">
                <div class="panel-heading">
                    <h2 id="add-service-h">Add a service</h2>
                </div>

                <?php if ($presets !== []): ?>
                    <p class="wz-hint">Quick presets:</p>
                    <div class="service-presets">
                        <?php foreach ($presets as $key => $preset): ?>
                            <button type="button" class="service-preset" data-service-preset="<?= $e(json_encode($preset, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)) ?>"><?= $e($preset['label']) ?></button>
                        <?php endforeach ?>
                    </div>
                <?php endif ?>

                <form method="post" action="/services/add" class="service-form" id="add-service-form">
                    <?= $csrf() ?>
                    <label class="wz-field">
                        <span class="wz-label">Name</span>
                        <input class="wz-in mono" type="text" name="service_name" value="<?= $value('service_name') ?>" placeholder="laya" maxlength="40" pattern="[a-z][a-z0-9_]*" required>
                        <span class="wz-hint">Your apps get <code>{NAME}_URL</code> on their next deploy.</span>
                    </label>

                    <label class="wz-field">
                        <span class="wz-label">Packages</span>
                        <input class="wz-in mono" type="text" name="service_packages" value="<?= $value('service_packages') ?>" placeholder="py312-pytorch py312-transformers py312-fastapi py312-uvicorn">
                        <span class="wz-hint">Optional space-separated packages installed for this service.</span>
                    </label>

                    <label class="wz-field">
                        <span class="wz-label">Setup commands</span>
                        <textarea class="wz-in mono" name="service_setup" rows="4" placeholder="Optional one-time setup commands run during deploy" spellcheck="false"><?= $value('service_setup') ?></textarea>
                        <span class="wz-hint">Runs during deploy; <code>/var/db/service</code> persists across redeploys.</span>
                    </label>

                    <label class="wz-field">
                        <span class="wz-label">Start command</span>
                        <input class="wz-in mono" type="text" name="service_command" value="<?= $value('service_command') ?>" placeholder="/var/db/service/venv/bin/python -m laya.serve" maxlength="500" required>
                        <span class="wz-hint">Supervised always-on process.</span>
                    </label>

                    <div class="service-row-2">
                        <label class="wz-field">
                            <span class="wz-label">Port</span>
                            <input class="wz-in mono" type="number" name="service_port" value="<?= $value('service_port', (string) Service_spec::DEFAULT_PORT) ?>" min="1024" max="65535" required>
                            <span class="wz-hint">Also passed as <code>PORT</code>.</span>
                        </label>
                        <label class="wz-field">
                            <span class="wz-label">Memory (MB)</span>
                            <input class="wz-in mono" type="number" name="service_memory_mb" value="<?= $value('service_memory_mb', (string) Service_spec::DEFAULT_MEMORY_MB) ?>" min="<?= Service_spec::MIN_MEMORY_MB ?>" max="<?= Service_spec::MAX_MEMORY_MB ?>" required>
                            <span class="wz-hint">Maximum memory allocated to this service.</span>
                        </label>
                    </div>

                    <label class="wz-field">
                        <span class="wz-label">Environment variables</span>
                        <textarea class="wz-in mono" name="service_env" rows="4" placeholder="KEY=value, one per line" spellcheck="false" autocomplete="off"><?= $value('service_env') ?></textarea>
                        <span class="wz-hint">Write-only, encrypted at rest. Reachable only from your apps over private networking.</span>
                    </label>

                    <div>
                        <button type="submit" class="wz-ok">Add service</button>
                    </div>
                </form>
            </section>
        <?php endif ?>
    </div>
</main>
