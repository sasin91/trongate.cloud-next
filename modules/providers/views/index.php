<main class="panel"><h1>Providers</h1><p>Manage connected Hetzner projects and their API tokens.</p>
<?php foreach ($inventory['providers'] as $provider): ?>
<section class="card"><h2><?= $e($provider['label'] ?? $provider['id']) ?></h2><p>Hetzner Cloud</p>
<a href="/servers/create?provider=<?= $e(urlencode($provider['id'])) ?>">Create a server</a>
<form method="post"><?= $csrf() ?><input type="hidden" name="command" value="update-token"><input type="hidden" name="provider" value="<?= $e($provider['id']) ?>">
<label class="wz-field"><span>Replacement API token</span><input class="wz-in" type="password" name="token" required autocomplete="off"></label><button class="wz-ok">Validate and update token</button></form></section>
<?php endforeach ?>
<section class="card"><h2>Connect a Hetzner project</h2><form method="post"><?= $csrf() ?><input type="hidden" name="command" value="connect-provider">
<?php $field('provider_name', 'Project name'); $field('token', 'Project API token', '', 'password'); ?>
<p>The token is validated before saving and stored encrypted.</p><button class="wz-ok">Connect project</button></form></section></main>
