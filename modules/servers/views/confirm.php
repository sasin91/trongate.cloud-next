<div class="wz-wrap"><main class="wz-main"><div class="wz-stage"><div class="wz-q"><h1>Connect your FreeBSD server.</h1><p>We’ll connect over pinned Ed25519 SSH and verify FreeBSD, ZFS, jails, rctl, pf, Caddy, MariaDB, and Valkey via <code>tc-runtime</code>.</p></div>
<?php if ($page === 'confirm-server' && isset($_SESSION['enrollment'])): $pending = $_SESSION['enrollment']; ?>
<p>Server: <strong><?= $e($pending['host']) ?></strong></p>
<?php if (!empty($pending['fingerprint'])): ?><p class="wz-hint">Ed25519 fingerprint: <code><?= $e($pending['fingerprint']) ?></code></p><?php endif ?>
<p>Finish connecting to check whether this FreeBSD host is ready for your apps.</p>
<form method="post"><?= $csrf() ?><input type="hidden" name="command" value="confirm-server"><button class="wz-ok">Finish connecting</button></form>
<p><a href="/servers/connect">Edit SSH connection details</a></p>
<?php else: ?>
<p class="wz-hint">Prepare your bare-metal FreeBSD host with the <code>infra/scripts/</code> bootstrap scripts before connecting. SSH uses the non-root <code>tc-agent</code> account with a forced-command <code>doas /usr/local/libexec/tc-runtime</code> entry.</p>
<form method="post" action="/servers/connect">
    <?= $csrf() ?>
    <input type="hidden" name="command" value="connect-server">
    <?php
        $field('id', 'Server name (lowercase, hyphens)', 'freebsd-1');
        $field('host', 'IP address or hostname');
        $field('port', 'SSH port', 22, 'number');
        $field('user', 'SSH user', 'tc-agent');
    ?>
    <label class="wz-field"><span class="wz-label">SSH private key</span><textarea class="wz-in mono" name="private_key" rows="5" required autocomplete="off" spellcheck="false"></textarea><span class="wz-hint">Stored encrypted. The server’s Ed25519 SSH host key is pinned automatically on first connection.</span></label>
    <button class="wz-ok">Connect server</button>
</form>
<?php endif ?></div></main></div>
