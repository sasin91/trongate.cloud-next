<main class="panel">
    <div class="panel-heading">
        <div>
            <p class="eyebrow">YOUR WORKSPACE</p>
            <h1>Your apps.</h1>
        </div>
        <a class="wz-ok" href="/apps/create">New app ↗</a>
    </div>

    <?php if (isset($_SESSION['app_draft'])): ?>
        <section class="welcome card">
            <div class="wz-q">
                <p class="eyebrow">PICK UP WHERE YOU LEFT OFF</p>
                <h2>Finish setting up your app.</h2>
                <p>Your saved repository, settings, and wizard progress are ready to continue.</p>
            </div>
            <a class="wz-ok" href="/apps/create">Continue app setup →</a>
        </section>
    <?php endif ?>

    <?php if (!$inventory['apps']): ?>
        <section class="welcome card">
            <div class="wz-q">
                <h2>Welcome aboard. Let’s put your first app online.</h2>
                <p>Connect a FreeBSD server, choose your GitHub repository, and launch. Follow each deployment as it happens.</p>
            </div>
            <div class="onboarding">
                <a class="onboarding-step" href="/servers">
                    <span>01</span>
                    <strong>Connect a server</strong>
                    <small><?= $ready ? 'Ready to deploy' : 'Verify its identity and runtime' ?></small>
                </a>
                <a class="onboarding-step" href="/apps/create">
                    <span>02</span>
                    <strong>Create your first app</strong>
                    <small>Public GitHub repository on FreeBSD</small>
                </a>
                <div class="onboarding-step">
                    <span>03</span>
                    <strong>Go live</strong>
                    <small>Build, deploy, health check</small>
                </div>
            </div>
        </section>
    <?php endif ?>

    <div class="app-grid">
        <?php foreach ($inventory['apps'] as $app):
            $releases = array_values(array_filter($inventory['releases'], fn($r) => $r['app'] === $app['id']));
            usort($releases, fn($a, $b) => ($b['created'] ?? 0) <=> ($a['created'] ?? 0));
            $latest = $releases[0] ?? null;
            $active_commit = (string) ($latest['metadata']['commit'] ?? $latest['spec']['commit'] ?? '');
            $php_version = (string) ($latest['spec']['php_version'] ?? $app['php_version'] ?? '8.4');
            $has_database = array_key_exists('database', $latest['spec'] ?? [])
                ? (bool) $latest['spec']['database']
                : (bool) ($app['database'] ?? true);
        ?>
            <article class="card">
                <p class="eyebrow">
                    <?= $e($app['server']) ?> · PHP <?= $e($php_version) ?> · <?= $has_database ? 'MariaDB' : 'No database' ?>
                </p>
                <h2><?= $e($app['label'] ?? $app['id']) ?></h2>
                <p><?= $e($app['domain'] ?? '') ?></p>
                <p class="wz-hint">
                    <?php if ($active_commit !== ''): ?>
                        Latest commit <code><?= $e(substr($active_commit, 0, 7)) ?></code>
                        <?php if (!empty($latest['metadata']['branch'])): ?>
                            on <code><?= $e($latest['metadata']['branch']) ?></code>
                        <?php endif ?>
                    <?php elseif (!empty($app['repository'])): ?>
                        <code><?= $e($app['repository']) ?></code><?php if (!empty($app['branch'])): ?> · <code><?= $e($app['branch']) ?></code><?php endif ?>
                    <?php else: ?>
                        No releases built yet
                    <?php endif ?>
                </p>

                <form method="post" action="/deployments/change">
                    <?= $csrf() ?>
                    <input type="hidden" name="app" value="<?= $e($app['id']) ?>">
                    <?php require __DIR__ . '/releases.php'; ?>
                    <button class="wz-ok" name="command" value="redeploy-app">Deploy selected release</button>
                </form>

                <form class="rollback" method="post" action="/deployments/change" data-confirm="Roll back this app to its previous release?">
                    <?= $csrf() ?>
                    <input type="hidden" name="app" value="<?= $e($app['id']) ?>">
                    <button class="link-button" name="command" value="rollback-app">Roll back to previous release</button>
                </form>

                <details class="rollback">
                    <summary class="link-button">Delete app</summary>
                    <form method="post" action="/apps/destroy" data-confirm="Delete this app from the host? Running jails and routes will be removed.">
                        <?= $csrf() ?>
                        <input type="hidden" name="app" value="<?= $e($app['id']) ?>">
                        <p class="wz-hint">Stops blue/green jails, removes the Caddy route and TLS allowlist entry, and revokes Valkey sessions.</p>
                        <label class="wz-check">
                            <input type="checkbox" name="purge_data" value="1">
                            <span>Also permanently purge MariaDB database and persistent ZFS data</span>
                        </label>
                        <p><button class="link-button" type="submit">Confirm delete <?= $e($app['label'] ?? $app['id']) ?></button></p>
                    </form>
                </details>
            </article>
        <?php endforeach ?>
    </div>

    <?php $this->servers->_display('list', $viewData); ?>
    <?php $this->deployments->_display('list', $viewData); ?>
</main>
