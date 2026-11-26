<?php
$php_version = (string) ($_POST['php_version'] ?? '8.4');
$apps_domain = Apps_model::apps_domain();
$optional_extensions = implode(', ', Build_commands::OPTIONAL_EXTENSIONS);
$initial_label = trim((string) ($_POST['label'] ?? ''));
?>
<div class="wz-wrap">
    <main class="wz-main">
        <form method="post" class="wz-stage highlight-errors" novalidate data-steps data-step="<?= $value('wizard_step', 0) ?>" data-apps-domain="<?= $e($apps_domain) ?>" data-error="<?= $message !== null ? '1' : '' ?>" mx-after-validation="wizardAfterValidation">
            <?= $csrf() ?>
            <input type="hidden" name="command" value="launch-app">
            <input type="hidden" name="request" value="<?= $e($_SESSION['launch_request']) ?>">
            <input type="hidden" name="php_version" value="<?= $e($php_version) ?>">
            <input type="hidden" name="wizard_step" value="<?= $value('wizard_step', 0) ?>">
            <div id="wz-step-state" hidden></div>
            <button type="button" class="wz-back" data-back hidden>← Back</button>

            <fieldset class="wz-step" data-label="Repository">
                <div class="wz-q">
                    <span class="wz-num">1 →</span>
                    <legend>Which app are we putting online?</legend>
                </div>
                <?php $field('repository', 'Public GitHub repository'); ?>
                <p class="wz-hint">Paste <code>https://github.com/owner/repository</code> or <code>owner/repository</code>. We check that the repository is public and fetch its default branch.</p>
            </fieldset>

            <fieldset class="wz-step" data-label="Branch">
                <div class="wz-q">
                    <span class="wz-num">2 →</span>
                    <legend>Which branch goes live?</legend>
                </div>
                <?php $field('branch', 'Branch or full commit', 'main'); ?>
                <p class="wz-hint">We verify this branch on GitHub and inspect <code>package.json</code> / <code>composer.json</code> to suggest build commands. Builds run only when you launch or redeploy.</p>
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
                    <legend>Name your app.</legend>
                </div>
                <?php $field('label', 'App name (lowercase letters, numbers and hyphens)'); ?>
                <div class="wz-address"><span>https://</span><b data-address-slug><?= $e($initial_label !== '' ? $initial_label : 'your-app') ?></b><span>.<?= $e($apps_domain) ?></span></div>
                <p class="wz-hint">We configure <code>config/config.php</code> with this HTTPS address automatically. Custom domains can be attached later.</p>
            </fieldset>

            <fieldset class="wz-step" data-label="Build">
                <div class="wz-q">
                    <span class="wz-num">5 →</span>
                    <legend>How should we build it?</legend>
                </div>
                <label class="wz-field">
                    <span class="wz-label">Build commands</span>
                    <textarea name="build_commands" class="wz-in mono" rows="3" placeholder="npm ci&#10;npm run build"><?= $value('build_commands') ?></textarea>
                    <span class="wz-hint" data-build-detected hidden></span>
                    <span class="wz-hint">One command per line, run during the build. Composer runs automatically when <code>composer.json</code> is present.</span>
                </label>
                <?php $field('php_extensions', 'Extra PHP extensions (comma separated)', '', 'text', false); ?>
                <p class="wz-hint">Core Trongate extensions are always enabled. Curated optional extensions: <code><?= $e($optional_extensions) ?></code>.</p>
            </fieldset>

            <fieldset class="wz-step" data-label="Launch">
                <div class="wz-q">
                    <span class="wz-num">6 →</span>
                    <legend>Ready to launch?</legend>
                </div>
                <?php $field('health', 'Health check path', '/'); ?>
                <p class="wz-hint">Storage, database, sessions, and upload directories are configured automatically by inspecting your repository. A successful build verifies your health check and cuts over with zero downtime.</p>
            </fieldset>

            <div class="wz-nav">
                <button type="submit" class="wz-ok" data-next
                    mx-post="/apps/validate_step"
                    mx-target="#wz-step-state"
                    mx-swap="outerHTML"
                    mx-on-trigger="wizardBeforeValidate"
                    mx-after-swap="wizardStepValidated"
                    mx-on-timeout="wizardValidateTimeout">Launch</button>
                <span class="wz-enter">press <strong>Enter ↵</strong></span>
                <a class="wz-back" href="/apps">Cancel</a>
            </div>
        </form>
    </main>
    <aside class="wz-side" aria-label="Your app so far">
        <div class="wz-sheet">
            <div class="wz-sheet-head"><span>Your app so far</span><span>draft</span></div>
            <dl>
                <?php foreach (['repository' => 'repository', 'branch' => 'branch', 'label' => 'label', 'address' => 'address', 'php_version' => 'php'] as $name => $heading): ?>
                    <div><dt><?= $e($heading) ?></dt><dd data-show="<?= $e($name) ?>"></dd></div>
                <?php endforeach ?>
            </dl>
        </div>
    </aside>
</div>
