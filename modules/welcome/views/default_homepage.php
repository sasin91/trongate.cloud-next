<?php
$icon = static fn(string $paths, int $size = 24, string $stroke = 'currentColor'): string => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="' . $stroke . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths . '</svg>';

$values = [
    ['Simple', 'Connect your repository and pick a branch. No servers to configure, no YAML to maintain.', '<circle cx="12" cy="12" r="3.5"/><path d="M3 12h5.5"/><path d="M15.5 12H21"/>'],
    ['Fast', 'Every release builds into an immutable ZFS snapshot and goes live on HTTPS with MariaDB ready.', '<path d="M13 2 4 14h7l-1 8 9-12h-7z"/>'],
    ['Convenient', 'Isolated FreeBSD jails on bare metal, canary rollouts, and sub-second rollback in one click.', '<path d="M12 3 4.5 6v6c0 4.5 3.2 7.6 7.5 9 4.3-1.4 7.5-4.5 7.5-9V6z"/><path d="M8.5 12l2.5 2.5 4.5-5"/>'],
];

$steps = [
    ['Connect a repository', 'Paste your GitHub repository URL and pick the branch or commit that should go live.'],
    ['Configure secrets', 'Add environment variables encrypted at rest. Your database and session cache are wired in automatically.'],
    ['Name it', 'The name becomes your address at name.' . $apps_domain . '. Bring your own domain whenever you like.'],
    ['Deploy', 'Each release builds in an isolated jail, passes a health check, and cuts over with zero downtime. Roll back in a click.'],
];

$stability = [
    ['Nothing goes live untested', 'A new build starts in a standby slot and only takes traffic once your app answers its health check. Until then, the last good slot keeps serving.'],
    ['Failed deploys undo themselves', 'If a new release fails its local or public check, traffic stays on or returns to the last good slot automatically. Nobody has to wake up for it.'],
    ['Every good release is kept', 'The previous slot stays on ZFS for sub-second rollback without rebuilding, and earlier releases can be redeployed in one click.'],
    ['No surprise upgrades', 'We build exactly what you pushed, with only the PHP extensions you chose. Your app changes when you change it.'],
];

$features = [
    ['Zero-downtime blue/green deploys', 'New builds spin up alongside your running app, pass health checks, and switch over atomically.', '<path d="M12 16V8"/><path d="M8.5 11.5 12 8l3.5 3.5"/><path d="M7 19a4.5 4.5 0 0 1-1-8.9A5.5 5.5 0 0 1 16.6 8.5 3.5 3.5 0 0 1 18 19z"/>'],
    ['Custom domains, HTTPS included', 'Point DNS at your server. Caddy issues and renews TLS certificates automatically with no extra configuration.', '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>'],
    ['Safe rollouts and canaries', 'Route 10%, 25%, or 50% of visitors to a new release before promoting to 100%, or roll back immediately.', '<path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/>'],
    ['MariaDB and Valkey included', 'Every app gets its own MariaDB database and isolated Valkey session namespace wired straight into config.', '<ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v12c0 1.7 3.6 3 8 3s8-1.3 8-3V6"/><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/>'],
    ['On-demand bare-metal efficiency', 'PHP-FPM scales workers on demand inside lightweight FreeBSD jails with hard memory and CPU caps.', '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>'],
    ['Logs, traffic, and database tools', 'Inspect live requests, 24-hour error rates, deployment timelines, and your MariaDB tables from one calm panel.', '<path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/>'],
];

$faq = [
    ['Which Trongate versions work?', 'Trongate v1 and v2. We patch only BASE_URL and ENV in your config while leaving your own settings untouched.'],
    ['Do I need a Dockerfile or container config?', 'No. Push your Trongate repository and we build an immutable release and run it directly in an isolated FreeBSD jail with PHP 8.4 and Caddy already configured.'],
    ['How do rollbacks work?', 'Each app alternates between blue and green slots on ZFS. The previous slot stays ready on disk so rolling back switches Caddy back in under a second without rebuilding.'],
    ['Can I run private companion services?', 'Yes. Add always-on private services next to your app and every deploy receives its {NAME}_URL environment variable automatically.'],
    ['How do I use my own domain?', 'Attach your domain to the app and point DNS at your server. Once DNS resolves, we verify it and serve your app over automatic HTTPS.'],
    ['How is my app isolated?', 'Each app runs in its own non-VNET FreeBSD jail with read-only base and PHP runtime mounts, rctl memory and CPU limits, pf firewall rules, and a dedicated ZFS dataset quota.'],
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php
    $page_title = "trongate.cloud - push your Trongate app, it's live";
    $page_description = 'Hosting and deployments for apps built with the Trongate PHP framework. You push, we deploy: simple, fast, convenient.';
    require APPPATH . 'modules/templates/views/_site_head.php';
    ?>
    <script src="<?= BASE_URL ?>welcome_module/js/landing.js" defer></script>
</head>

<body class="lp">
    <?php require APPPATH . 'modules/templates/views/_site_header.php'; ?>

    <main>
        <section class="lp-hero" id="top">
            <div class="lp-hero-copy">
                <a href="#faq" class="lp-pill"><span>Bare-metal FreeBSD</span> Built for Trongate PHP</a>
                <h1 class="lp-h1">Push your Trongate app.<br><em>It's live.</em></h1>
                <p class="lp-lead">Connect your repository, answer a few quick questions, and your app runs on its own HTTPS address with MariaDB and instant rollback.</p>
                <div class="lp-hero-actions">
                    <a href="apps/create" class="lp-btn lp-btn-lg">Deploy your first app</a>
                    <a href="#how" class="lp-more">See how it works <span aria-hidden="true">&rarr;</span></a>
                </div>
            </div>

            <div class="lp-shot">
                <div class="lp-shot-tags" aria-hidden="true">
                    <span class="lp-tag lp-mono"><b>$</b> git push origin main</span>
                    <span class="lp-tag"><i></i> 4f2b8d1 is live</span>
                </div>
                <div class="lp-frame">
                    <div class="lp-frame-inner" aria-hidden="true">
                        <iframe src="<?= BASE_URL ?>welcome/preview" title="App overview" tabindex="-1" loading="lazy" scrolling="no"></iframe>
                    </div>
                </div>
            </div>
        </section>

        <section class="lp-band">
            <div class="lp-wrap lp-values">
                <div class="lp-center">
                    <h2 class="lp-h2 lp-h2-xl">You push. <em>We deploy.</em></h2>
                    <p class="lp-sub">That's the whole job.</p>
                </div>
                <div class="lp-3">
                    <?php foreach ($values as [$title, $text, $paths]): ?>
                        <div class="lp-value">
                            <span class="lp-value-icon"><?= $icon($paths) ?></span>
                            <div>
                                <h3><?= out($title) ?></h3>
                                <p><?= out($text) ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="lp-wrap lp-section" id="how">
            <div class="lp-head">
                <span class="lp-kicker">How it works</span>
                <h2 class="lp-h2">Four answers. Then it's live.</h2>
            </div>
            <ol class="lp-4">
                <?php foreach ($steps as $i => [$title, $text]): ?>
                    <li class="lp-step">
                        <span><?= sprintf('%02d', $i + 1) ?></span>
                        <h3><?= out($title) ?></h3>
                        <p><?= out($text) ?></p>
                    </li>
                <?php endforeach; ?>
            </ol>
        </section>

        <section class="lp-wrap lp-stable-wrap" id="stability">
            <div class="lp-stable">
                <div class="lp-stable-copy">
                    <span class="lp-kicker">Stability</span>
                    <h2 class="lp-h2">Peace of mind from deploy to production.</h2>
                    <p class="lp-sub">Stability throughout, the Trongate way. The framework puts stability first, and so does the place you run it.</p>
                    <a href="https://trongate.io/essays/trongates-new-versioning-system" target="_blank" rel="noopener" class="lp-link">Read Trongate's commitment to stability &rarr;</a>
                </div>
                <ol class="lp-stable-list">
                    <?php foreach ($stability as $i => [$title, $text]): ?>
                        <li>
                            <span><?= sprintf('%02d', $i + 1) ?></span>
                            <div>
                                <strong><?= out($title) ?></strong>
                                <p><?= out($text) ?></p>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </div>
        </section>

        <section class="lp-band" id="features">
            <div class="lp-wrap lp-section">
                <div class="lp-head">
                    <span class="lp-kicker">Features</span>
                    <h2 class="lp-h2">Everything between git push and a happy visitor.</h2>
                </div>
                <dl class="lp-2">
                    <?php foreach ($features as [$title, $text, $paths]): ?>
                        <div class="lp-feature">
                            <span class="lp-feature-icon"><?= $icon($paths, 20, '#FFFFFF') ?></span>
                            <div>
                                <dt><?= out($title) ?></dt>
                                <dd><?= out($text) ?></dd>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </dl>
            </div>
        </section>

        <section class="lp-wrap lp-section" id="faq">
            <h2 class="lp-h2">Questions</h2>
            <dl class="lp-faqs">
                <?php foreach ($faq as [$question, $answer]): ?>
                    <div class="lp-faq">
                        <dt><?= out($question) ?></dt>
                        <dd><?= out($answer) ?></dd>
                    </div>
                <?php endforeach; ?>
            </dl>
        </section>
    </main>

    <?php require APPPATH . 'modules/templates/views/_site_footer.php'; ?>
</body>

</html>
