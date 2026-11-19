<main class="panel server-setup"><h1>Create a server</h1><p class="wz-hint">Choose a region and size. Review the full cost before anything is created.</p><p><a href="/providers">Manage providers</a> / <a href="/servers">Back to servers</a></p>
<?php $config = $_SESSION['server_config'] ?? []; $options = $_SESSION['server_options'] ?? null; $review = $_SESSION['server_review'] ?? null; ?>
<?php if ($review): ?><h2>Review configuration and cost</h2><dl><?php foreach (['name','provider','type','location','image','ssh_key','monthly_net','hourly_net','currency'] as $key): ?><dt><?= $e(str_replace('_', ' ', $key)) ?></dt><dd><?= $e($review[$key]) ?></dd><?php endforeach ?></dl>
<p>Prices include IPv4 and exclude tax. Hetzner bills your project directly. Standard Linux images require runtime preparation before apps can deploy.</p>
<form method="post"><?= $csrf() ?><input type="hidden" name="command" value="create"><input type="hidden" name="quote" value="<?= $e($review['quote']) ?>"><label><input type="checkbox" name="confirm_cost" value="1" required> Create this billable server at the displayed price</label><button class="wz-ok">Create server</button></form>
<form method="post"><?= $csrf() ?><button name="command" value="edit">Edit configuration</button></form>
<?php else: ?>
<form method="post"><?= $csrf() ?><label class="wz-field">Connected project<select class="wz-select" name="provider" required><?php foreach ($inventory['providers'] as $provider): ?><option value="<?= $e($provider['id']) ?>" <?= ($config['provider'] ?? '') === $provider['id'] ? 'selected' : '' ?>><?= $e($provider['label'] ?? $provider['id']) ?></option><?php endforeach ?></select></label><button class="wz-ok" name="command" value="options">Load server options</button></form>
<?php if (!$inventory['providers']): ?><p><a href="/providers">Connect a provider first</a></p><?php endif ?>
<?php if ($options): ?><form method="post" class="card" data-server-config data-options="<?= $e(json_encode($options, JSON_THROW_ON_ERROR)) ?>" data-saved="<?= $e(json_encode($config, JSON_THROW_ON_ERROR)) ?>"><?= $csrf() ?><input type="hidden" name="command" value="review"><input type="hidden" name="provider" value="<?= $e($config['provider']) ?>"><?php $field('name', 'Server name', $config['name'] ?? 'web'); ?>
<div class="server-fields">
<?php foreach (['location' => 'Region', 'server_type' => 'Server size', 'image' => 'Operating system / prepared snapshot', 'ssh_key' => 'SSH public key'] as $name => $label): ?>
<label class="wz-field"><span class="wz-label"><?= $e($label) ?></span><select class="wz-select" name="<?= $e($name) ?>" required></select></label>
<?php endforeach ?>
</div><aside class="server-estimate" aria-live="polite"><strong data-size-summary></strong><p data-size-price></p><p class="wz-hint" data-size-note></p></aside>
<p class="wz-hint" data-options-empty role="status"></p>
<noscript><p>Enable JavaScript to select compatible server options.</p></noscript>
<p>Only available sizes and compatible images are shown. Availability and pricing are checked again before confirmation.</p><button class="wz-ok" data-review disabled>Review configuration and cost</button></form><?php endif; endif ?></main>
