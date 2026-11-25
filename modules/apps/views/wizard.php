<?php
$php_version = (string) ($_POST['php_version'] ?? '8.4');
$database_checked = !array_key_exists('database', $_POST) || in_array((string) $_POST['database'], ['1', 'true', 'on'], true);
$disk_quota = $_POST['disk_quota_mb'] ?? '1024';
$optional_extensions = implode(', ', Build_commands::OPTIONAL_EXTENSIONS);
?>
<div class="wz-wrap">
    <main class="wz-main">
        <form method="post" class="wz-stage" novalidate data-steps data-step="<?= $value('wizard_step', 0) ?>" data-error="<?= $message !== null ? '1' : '' ?>">
            <?= $csrf() ?>
            <input type="hidden" name="command" value="launch-app">
            <input type="hidden" name="request" value="<?= $e($_SESSION['launch_request']) ?>">
            <button type="button" class="wz-back" data-back hidden>← Back</button>

            <fieldset class="wz-step" data-label="Repository">
                <div class="wz-q">
                    <span class="wz-num">1 →</span>
                    <legend>Which app are we putting online?</legend>
                </div>
                <?php $field('repository', 'Public GitHub repository'); ?>
                <p class="wz-hint">Paste <code>https://github.com/owner/repository</code> or <code>owner/repository</code>. Your app is built inside an isolated FreeBSD build jail on your host. Private repositories and automatic push deployments require a GitHub App integration, which is not connected here.</p>
            </fieldset>

            <fieldset class="wz-step" data-label="Branch">
                <div class="wz-q">
                    <span class="wz-num">2 →</span>
                    <legend>Which branch goes live?</legend>
                </div>
                <?php $field('branch', 'Branch or full commit', 'main'); ?>
                <p class="wz-hint">Launch resolves this to an exact 40-character commit SHA. Builds run only when you launch or redeploy.</p>
            </fieldset>

            <fieldset class="wz-step" data-label="Environment">
                <div class="wz-q">
                    <span class="wz-num">3 →</span>
                    <legend>Fill in its environment variables.</legend>
                </div>
                <label class="wz-field">
                    <span class="wz-label">Environment variables</span>
                    <textarea class="wz-in mono" name="environment" rows="6" placeholder="APP_ENV=production" spellcheck="false" autocomplete="off"><?= $value('environment') ?></textarea>
                    <span class="wz-hint">One <code>NAME=value</code> per line. Values are encrypted at rest and never printed in deployment logs. Your draft is saved to your account when leaving this page.</span>
                </label>
            </fieldset>

            <fieldset class="wz-step" data-label="Name">
                <div class="wz-q">
                    <span class="wz-num">4 →</span>
                    <legend>Name it. Give it an address.</legend>
                </div>
                <?php $field('label', 'App name (lowercase letters, numbers and hyphens)'); ?>
                <?php $field('domain', 'Your app’s domain'); ?>
                <p class="wz-hint">We generate <code>config/config.php</code> with this domain’s <code>BASE_URL</code> and guide you through DNS and HTTPS in the Go live step.</p>
            </fieldset>

            <fieldset class="wz-step" data-label="Server">
                <div class="wz-q">
                    <span class="wz-num">5 →</span>
                    <legend>Where should it run?</legend>
                </div>
                <label class="wz-field">
                    <span class="wz-label">FreeBSD host</span>
                    <select class="wz-select" name="server" required>
                        <option value="">Choose a ready server</option>
                        <?php foreach ($ready as $server): ?>
                            <option value="<?= $e($server['id']) ?>" <?= ($_POST['server'] ?? '') === $server['id'] ? 'selected' : '' ?>><?= $e($server['id'] . ' · ' . $server['host']) ?></option>
                        <?php endforeach ?>
                    </select>
                </label>
                <a class="link-button" href="/servers">Add a server</a>
                <?php if (!$ready): ?>
                    <div class="wz-help">You need a prepared FreeBSD host first. <a class="link-button" href="/servers">Add a server</a>.</div>
                <?php endif ?>
                <label class="wz-field">
                    <span class="wz-label">PHP runtime</span>
                    <select class="wz-select" name="php_version" required>
                        <option value="8.4" <?= $php_version === '8.4' ? 'selected' : '' ?>>PHP 8.4 (shared read-only FreeBSD runtime)</option>
                    </select>
                    <span class="wz-hint">Runs in an isolated FreeBSD <code>jail(8)</code> with ZFS blue/green slots, <code>php-fpm</code> on-demand workers, and host Caddy TLS.</span>
                </label>
            </fieldset>

            <fieldset class="wz-step" data-label="Database">
                <div class="wz-q">
                    <span class="wz-num">6 →</span>
                    <legend>Does your app need a database?</legend>
                </div>
                <input type="hidden" name="database" value="0">
                <label class="wz-toggle-row">
                    <span>
                        <strong>Auto-provision a shared MariaDB database (recommended)</strong>
                        <small>Creates an isolated per-app database and user on your host’s <code>tc_mariadb</code> jail and writes <code>config/database.php</code> over the Unix socket automatically.</small>
                    </span>
                    <input class="wz-switch" type="checkbox" name="database" value="1" <?= $database_checked ? 'checked' : '' ?>>
                </label>
                <div class="wz-help">
                    <strong>Shared Valkey sessions included</strong>
                    <p>Per-app Valkey sessions (<code>tc_valkey</code> with isolated ACLs) are enabled automatically so user sessions stay intact across zero-downtime blue/green deployments and rollbacks.</p>
                </div>
            </fieldset>

            <fieldset class="wz-step" data-label="Build">
                <div class="wz-q">
                    <span class="wz-num">7 →</span>
                    <legend>How should we build it?</legend>
                </div>
                <label class="wz-field">
                    <span class="wz-label">Build commands</span>
                    <textarea name="build_commands" class="wz-in mono" rows="3" placeholder="npm ci&#10;npm run build"><?= $value('build_commands') ?></textarea>
                    <span class="wz-hint">One command per line, run inside an ephemeral FreeBSD build jail on your host. Composer runs automatically when <code>composer.json</code> is present.</span>
                </label>
                <?php $field('php_extensions', 'Extra PHP extensions (comma separated)', '', 'text', false); ?>
                <p class="wz-hint">Core Trongate extensions are always enabled. Curated optional extensions: <code><?= $e($optional_extensions) ?></code>.</p>
            </fieldset>

            <fieldset class="wz-step" data-label="Launch">
                <div class="wz-q">
                    <span class="wz-num">8 →</span>
                    <legend>Ready to launch?</legend>
                </div>
                <?php $field('health', 'Health check path', '/'); ?>
                <label class="wz-field">
                    <span class="wz-label">ZFS disk quota (MiB)</span>
                    <input class="wz-in mono" type="number" name="disk_quota_mb" value="<?= $e($disk_quota) ?>" min="128" max="1048576" step="128" required>
                    <span class="wz-hint">Caps total ZFS storage for this app’s releases and persistent data. Memory and CPU limits are managed automatically via <code>rctl(8)</code> and can be tuned from your server’s resource grid.</span>
                </label>
                <label class="wz-field">
                    <span class="wz-label">Persistent paths, relative to /app</span>
                    <textarea class="wz-in mono" name="persistent_paths" rows="2" placeholder="public/uploads"><?= $value('persistent_paths') ?></textarea>
                    <span class="wz-hint">One directory per line. Stored on a per-app ZFS dataset (<code>exec=off</code>, <code>setuid=off</code>) that survives deployments.</span>
                </label>
                <p class="wz-hint">A successful build starts a standby jail, runs your health check, and cuts over atomically with zero downtime.</p>
            </fieldset>

            <div class="wz-nav">
                <button class="wz-ok" data-next>Launch</button>
                <span class="wz-enter">press <strong>Enter ↵</strong></span>
                <a class="wz-back" href="/">Cancel</a>
            </div>
        </form>
    </main>
    <aside class="wz-side" aria-label="Your app so far">
        <div class="wz-sheet">
            <div class="wz-sheet-head"><span>Your app so far</span><span>draft</span></div>
            <dl>
                <?php foreach (['repository' => 'repository', 'branch' => 'branch', 'label' => 'label', 'domain' => 'domain', 'server' => 'server', 'php_version' => 'php', 'database' => 'database'] as $name => $heading): ?>
                    <div><dt><?= $e($heading) ?></dt><dd data-show="<?= $e($name) ?>"></dd></div>
                <?php endforeach ?>
            </dl>
        </div>
    </aside>
</div>
