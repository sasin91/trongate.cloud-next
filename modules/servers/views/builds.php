<main class="panel">
    <p><a href="/servers">← Servers</a></p>
    <h1>Build servers</h1>
    <section class="card">
        <h2>Build on your app server</h2>
        <p>This is the default. Builds run as an unprivileged user, limited to 1 CPU and 1 GB of memory. Your live app keeps running. Images stay on the server.</p>
        <p>Managed NixOS servers enable local builds through <code>services.trongate-runtime.localBuilds = true;</code>. Update older runtime installations, then recheck readiness on the Servers page.</p>
    </section>
    <section class="card">
        <h2>Set up a dedicated builder</h2>
        <p>Choose this when builds compete with your apps for resources. Use a separate NixOS server with the same CPU architecture as your application server.</p>
        <ol>
            <li><a href="/servers/create">Create a NixOS server on Proxmox</a>, or use a spare server. Review available capacity before creating it.</li>
            <li>Apply the repository’s <code>nixosModules.builder</code> module. Enable <code>services.trongate-builder</code> and supply your SSH public key in <code>authorizedKeys</code>.</li>
            <li>Configure a private registry and its push credentials for the build user. Set the registry and encrypted pull credential in this installation’s build settings.</li>
            <li><a href="/servers/connect">Connect the builder</a> using SSH user <code>tc-builder</code>. Its readiness check verifies the build tools.</li>
            <li>Choose “Dedicated build server” in app setup. We select a compatible builder with the shortest queue.</li>
        </ol>
        <p>Dedicated setup currently requires NixOS and registry configuration. <a href="https://github.com/sasin91/trongate.cloud-next/blob/main/docs/builds-and-monitoring.md">Setup instructions</a></p>
    </section>
    <section class="card"><h2>Connected builders</h2>
    <?php $builders = array_filter($inventory['servers'], static fn($server) => !empty($server['builder_ready'])); ?>
    <?php if (!$builders): ?><p>No dedicated builder connected yet.</p><?php endif ?>
    <?php foreach ($builders as $server): ?><p><?= $e($server['id']) ?> · <?= $e($server['facts']['arch'] ?? '') ?></p><?php endforeach ?>
    </section>
</main>
