<?php
require_once APPPATH . 'modules/apps/services/Build_commands.php';
$php_version = (string) ($_POST['php_version'] ?? '8.4');
require_once APPPATH . 'modules/apps/Apps_model.php';
$apps_domain = Apps_model::apps_domain();
$optional_extensions = implode(', ', Build_commands::OPTIONAL_EXTENSIONS);
$initial_label = trim((string) ($_POST['label'] ?? ''));
$initial_repo = trim((string) ($_POST['repository'] ?? ''));
$gh = is_array($github ?? null) ? $github : [
    'connected' => false,
    'github_login' => '',
    'install_url' => '',
    'connect_url' => '/auth/github?then=apps%2Fcreate',
    'repositories' => [],
];
$gh_repos = is_array($gh['repositories'] ?? null) ? $gh['repositories'] : [];
$auto_deploy_checked = array_key_exists('auto_deploy', $_POST)
    ? (!empty($_POST['auto_deploy']) && (string) $_POST['auto_deploy'] !== '0')
    : !empty($gh['connected']);
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

            <?php if (!empty($saved_notice)): ?>
                <p class="flash" role="status"><?= $e($saved_notice) ?></p>
            <?php endif ?>

            <fieldset class="wz-step" data-label="Repository">
                <div class="wz-q">
                    <span class="wz-num">1 →</span>
                    <legend>Which app are we putting online?</legend>
                </div>

                    <div class="gh-connect-bar">
                        <?php if (empty($gh['connected'])): ?>
                            <div class="gh-connect-meta">
                                <strong>Connect GitHub</strong>
                                <span class="wz-hint">Pick from your repositories (private ones included) and deploy automatically on every push.</span>
                            </div>
                            <a href="<?= $e($gh['connect_url']) ?>" class="github-btn github-btn-sm">
                                <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0016 8c0-4.42-3.58-8-8-8z"/></svg>
                                Connect GitHub
                            </a>
                        <?php else: ?>
                            <div class="gh-connect-meta">
                                <strong>GitHub connected<?= $gh['github_login'] !== '' ? ' as @' . $e($gh['github_login']) : '' ?></strong>
                                <span class="wz-hint">Repositories granted to the trongate.cloud GitHub App deploy automatically on push.</span>
                            </div>
                            <?php if (!empty($gh['install_url'])): ?>
                                <a href="<?= $e($gh['install_url']) ?>" class="btn-secondary">Manage repositories ↗</a>
                            <?php endif ?>
                        <?php endif ?>
                    </div>

                    <?php if (!empty($gh['connected'])): ?>
                        <?php if ($gh_repos === []): ?>
                            <p class="wz-hint">The trongate.cloud GitHub App cannot read any of your repositories yet.<?php if (!empty($gh['install_url'])): ?> <a href="<?= $e($gh['install_url']) ?>">Install it on GitHub</a> and pick the repositories to deploy.<?php endif ?></p>
                        <?php else: ?>
                            <?php if (count($gh_repos) > 6): ?>
                                <label class="wz-field">
                                    <span class="wz-sr">Filter repositories</span>
                                    <input type="search" class="wz-in" placeholder="Filter repositories" data-repo-filter>
                                </label>
                            <?php endif ?>
                            <div class="wz-choices" data-repositories>
                                <?php foreach (array_values($gh_repos) as $repo):
                                    $full_name = (string) ($repo['full_name'] ?? '');
                                    $default_branch = (string) ($repo['default_branch'] ?? 'main');
                                ?>
                                    <label class="wz-choice" data-name="<?= $e(strtolower($full_name)) ?>">
                                        <input type="radio" name="github_repo_pick" value="<?= $e($full_name) ?>" data-repo-choice data-default-branch="<?= $e($default_branch) ?>"<?= strcasecmp($initial_repo, $full_name) === 0 ? ' checked' : '' ?>>
                                        <span class="wz-choice-text">
                                            <strong><?= $e($full_name) ?></strong>
                                            <small><?= !empty($repo['private']) ? 'Private' : 'Public' ?> · <?= $e($default_branch) ?></small>
                                        </span>
                                    </label>
                                <?php endforeach ?>
                            </div>
                        <?php endif ?>
                    <?php endif ?>

                <?php $field('repository', !empty($gh['connected']) ? 'Or enter a GitHub repository' : 'Public GitHub repository'); ?>
                <p class="wz-hint">Paste <code>https://github.com/owner/repository</code> or <code>owner/repository</code>. <?= !empty($gh['connected']) ? 'Private repositories work automatically when granted to the GitHub App.' : 'We check that the repository is accessible and fetch its default branch.' ?></p>
            </fieldset>

            <fieldset class="wz-step" data-label="Branch">
                <div class="wz-q">
                    <span class="wz-num">2 →</span>
                    <legend>Which branch goes live?</legend>
                </div>
                <?php $field('branch', 'Branch or full commit', 'main'); ?>
                <datalist id="wz-branch-list"></datalist>
                <p class="wz-hint">We verify this branch on GitHub and inspect <code>package.json</code> / <code>composer.json</code> to suggest build commands.</p>
                <label class="wz-toggle-row" data-push-row>
                    <span>
                        <strong>Deploy on push</strong>
                        <small>Pushes to this branch deploy automatically through the trongate.cloud GitHub App. Turn off to deploy only when you press Deploy now.</small>
                    </span>
                    <input type="hidden" name="auto_deploy" value="0">
                    <input type="checkbox" name="auto_deploy" value="1" class="wz-switch"<?= $auto_deploy_checked ? ' checked' : '' ?>>
                </label>
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
                <?php foreach (['repository' => 'repository', 'branch' => 'branch', 'auto_deploy' => 'on push', 'label' => 'label', 'address' => 'address', 'php_version' => 'php'] as $name => $heading): ?>
                    <div><dt><?= $e($heading) ?></dt><dd data-show="<?= $e($name) ?>"></dd></div>
                <?php endforeach ?>
            </dl>
        </div>
    </aside>
</div>
