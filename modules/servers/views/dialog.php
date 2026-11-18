<dialog id="server-dialog" aria-labelledby="server-dialog-title">
<div class="panel-heading"><h2 id="server-dialog-title">Add an application server</h2><button type="button" class="link-button" data-close-server><?= $page==='new-app'?'Back to app setup':'Back to servers' ?></button></div>
<?php if ($page==='new-app'): ?><p>Your app setup stays here while you connect and verify the server.</p><?php endif ?>
<p class="wz-hint">Use a separate, prepared application host. <a href="https://github.com/sasin91/trongate.cloud-next/blob/main/docs/self-hosting.md#prepared-linux-target" target="_blank" rel="noreferrer">Open preparation instructions ↗</a></p>
<p data-server-error role="alert" hidden></p>
<div data-server-choice class="wz-choices"><button type="button" class="wz-choice" data-choose-provider><span class="wz-key">A</span><span class="wz-choice-text"><strong>Create at a provider</strong><small>Choose a Hetzner project, region and server size</small></span></button><button type="button" class="wz-choice" data-choose-existing><span class="wz-key">B</span><span class="wz-choice-text"><strong>Connect an existing server</strong><small>Use SSH to connect a server you already have</small></span></button></div>
<?php $this->providers->_display('server',$viewData); ?>
<form data-server-connect method="post" hidden><?= $csrf() ?><input type="hidden" name="inline" value="server"><input type="hidden" name="command" value="connect-server"><input type="hidden" name="provision_operation">
<p data-created-server-note hidden></p>
<label class="wz-field"><span class="wz-label">Server name</span><input class="wz-in" name="id" required pattern="[a-z][a-z0-9-]{0,39}" placeholder="web"></label>
<label class="wz-field"><span class="wz-label">IP address or hostname</span><input class="wz-in" name="host" required></label>
<label class="wz-field"><span class="wz-label">SSH port</span><input class="wz-in" name="port" type="number" min="1" max="65535" value="22" required></label>
<label class="wz-field"><span class="wz-label">SSH user</span><input class="wz-in" name="user" value="root" required></label>
<label class="wz-field"><span class="wz-label">SSH private key</span><textarea class="wz-in mono" name="private_key" rows="4" required autocomplete="off" spellcheck="false"></textarea></label>
<button class="wz-ok">Connect server</button></form>
<form data-server-confirm method="post" hidden><?= $csrf() ?><input type="hidden" name="inline" value="server"><input type="hidden" name="command" value="confirm-server"><input type="hidden" name="enrollment">
<p>Observed fingerprint: <code data-server-fingerprint></code></p>
<p>Use the server’s independent console to run <code>ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub</code>, then enter its SHA256 fingerprint below.</p>
<label class="wz-field"><span class="wz-label">Verified fingerprint</span><input class="wz-in" name="fingerprint" required autocomplete="off"></label><button class="wz-ok">Confirm and use server</button> <button type="button" class="link-button" data-retry-server>Connect again</button></form>
<form data-server-unready method="post" hidden><?= $csrf() ?><input type="hidden" name="inline" value="server"><input type="hidden" name="command" value="inspect-connected-server"><input type="hidden" name="server"><p>The server was connected, but is not ready for applications:</p><p data-server-missing></p><p>Finish preparing its runtime, then check it again here.</p><button class="wz-ok">Check readiness again</button></form>
</dialog>
