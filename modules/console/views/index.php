<?php $escape=fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); ?>
<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Trongate Cloud Next</title>
<style>body{font:16px system-ui;background:#f4f6f8;color:#172435;max-width:1100px;margin:3rem auto;padding:0 1rem}h1{font-size:1.8rem}section,details{background:white;border:1px solid #dce2e8;border-radius:8px;padding:1rem;margin:1rem 0}label{display:block;margin:.7rem 0}input,select,textarea,button{font:inherit;padding:.6rem;border:1px solid #aebac7;border-radius:4px}textarea{width:95%;font:14px monospace}button{background:#153f68;color:white;cursor:pointer}pre{white-space:pre-wrap;overflow-wrap:anywhere}table{width:100%;border-collapse:collapse}td,th{text-align:left;padding:.6rem;border-bottom:1px solid #ddd}small{color:#526477}a{color:#153f68}</style>
<h1>Trongate Cloud Next</h1><p>Servers and application deployments · installation administrator</p>
<?php if ($message!==null): ?><section><pre><?= $escape($message) ?></pre></section><?php endif ?>
<?php if (!$signedIn): ?>
<section><h2>Sign in</h2><form method="post"><input type="hidden" name="csrf" value="<?= $escape($_SESSION['csrf']) ?>"><input type="hidden" name="command" value="login"><label>Administrator <input name="name" required autocomplete="username"></label><label>Password <input name="password" type="password" required autocomplete="current-password"></label><button>Sign in</button></form><p>Bootstrap an administrator using <code>tc init</code> on this installation.</p></section>
<?php else: ?>
<section><h2>Servers</h2><table><tr><th>Server</th><th>Application readiness</th><th>Managed NixOS</th></tr>
<?php foreach ($inventory['servers'] as $server): ?><tr><td><?= $escape($server['id'].' · '.$server['host']) ?></td><td><?= $escape($server['application_ready']?'Ready':implode(', ',$server['missing'])) ?></td><td><?= $server['managed_eligible']?'Eligible':'Application only' ?></td></tr><?php endforeach ?></table></section>
<section><h2>Perform an operation</h2><p>Choose an action and supply its JSON fields. The same fields work with <code>tc COMMAND payload.json</code>. Provider mutations require <code>"confirm": true</code>.</p>
<form method="post"><input type="hidden" name="csrf" value="<?= $escape($_SESSION['csrf']) ?>"><label>Action <select name="command">
<?php foreach (['credential','provider-connect','provider-options','server-scan','server-confirm','server-inspect','app-create','release','deploy','rollback','host-update','host-rollback','provision','provider-inspect','provider-reboot','provider-delete','snapshot-create','snapshot-delete','reconcile','operation','inventory'] as $action): ?><option><?= $escape($action) ?></option><?php endforeach ?></select></label><label>Fields <textarea name="payload" rows="9" required spellcheck="false">{}</textarea></label><button>Run selected action</button></form>
<details><summary>Example fields</summary><pre>credential: {"id":"ssh-main","value":"PRIVATE KEY CONTENT"}
provider-connect: {"id":"hetzner","credential":"hetzner-token"}
server-scan: {"host":"server.example.com","port":22,"user":"root","credential":"ssh-main"}
server-confirm: {"id":"web","enrollment":"SCAN ID","fingerprint":"SHA256:INDEPENDENTLY VERIFIED FINGERPRINT"}
app-create: {"id":"demo","server":"web"}
release: {"app":"demo","release":{"image":"registry.example.com/demo@sha256:DIGEST","domain":"demo.example.com","health":"/","mounts":{"uploads":"/app/public/uploads"}}}
deploy: {"app":"demo","release":"RELEASE ID"}
rollback: {"app":"demo"}
host-update: {"server":"web","revision":"FROZEN REVISION ID"}
host-rollback: {"server":"web","generation":"RETAINED STORE PATH"}
provision: {"provider":"hetzner","name":"web","server_type":"FROM OPTIONS","location":"FROM OPTIONS","image":123,"ssh_keys":[456],"confirm":true}
operation / reconcile: {"operation":"OPERATION ID"}
provider-inspect / provider-reboot / provider-delete / snapshot-create / snapshot-delete: {"provider":"hetzner","provider_id":123,"confirm":true}</pre></details></section>
<section><h2>Operations</h2><p>Refresh to see worker progress. Uncertain operations retain their resource lock until reconciliation verifies an outcome.</p><table><tr><th>Operation</th><th>Action / resource</th><th>Status</th></tr><?php foreach ($operations as $operation): ?><tr><td><code><?= $escape($operation['id']) ?></code><details><summary>Result</summary><pre><?= $escape($operation['result']) ?></pre></details></td><td><?= $escape($operation['kind'].' / '.$operation['resource']) ?></td><td><?= $escape($operation['status']) ?></td></tr><?php endforeach ?></table></section>
<details><summary>Application and provider inventory</summary><pre><?= $escape(json_encode($inventory,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)) ?></pre></details>
<form method="post"><input type="hidden" name="csrf" value="<?= $escape($_SESSION['csrf']) ?>"><button name="command" value="logout">Sign out</button></form>
<?php endif ?></html>
