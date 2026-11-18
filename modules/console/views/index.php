<?php
$e=fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$value=fn($name,$default='')=>$e($_POST[$name] ?? $default);
$csrf=fn()=>'<input type="hidden" name="csrf" value="'.$e($_SESSION['csrf']).'">';
$field=function($name,$label,$default='',$type='text',$required=true) use($value,$e) { ?>
<label class="wz-field"><span class="wz-label"><?= $e($label) ?></span><input class="wz-in" type="<?= $e($type) ?>" name="<?= $e($name) ?>" value="<?= $type==='password'?'':$value($name,$default) ?>" <?= $required?'required':'' ?>></label>
<?php };
$ready=array_filter($inventory['servers'] ?? [],fn($s)=>$s['application_ready']);
$viewData=compact('e','value','csrf','field','ready','page','operation','signedIn','message','inventory','operations');
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Trongate Cloud · <?= $e(ucwords(str_replace('-',' ',$page))) ?></title><link rel="stylesheet" href="/console_module/css/wizard.css"><link rel="stylesheet" href="/deployments_module/css/deployments.css"><link rel="stylesheet" href="/console_module/css/panel.css"><script src="/apps_module/js/flows.js" defer></script><script src="/deployments_module/js/progress.js" defer></script><script src="/servers_module/js/server-dialog.js" defer></script><script src="/providers_module/js/provider-server.js" defer></script></head>
<body class="wz-body"><header class="wz-top"><div class="wz-top-row"><a class="wz-brand" href="/"><?php require __DIR__.'/_mark.php'; ?><span>trongate<b>.cloud</b></span></a>
<?php if ($signedIn): ?><nav><a href="/">Apps</a><a href="/servers">Servers</a><a href="/?page=advanced">Advanced</a><form method="post"><?= $csrf() ?><button class="link-button" name="command" value="logout">Sign out</button></form></nav><?php endif ?><span id="wz-counter" class="wz-counter"></span></div><div id="wz-progress" class="wz-progress" aria-hidden="true"></div></header>
<?php if ($message!==null): ?><div class="notice wz-errors" role="alert"><?= $e($message) ?></div><?php endif ?>
<?php if (!$signedIn): ?>
<?php $this->authentication->_display('login',$viewData); ?>
<?php elseif ($page==='connect-server'): ?>
<?php $this->servers->_display('connect',$viewData); ?>
<?php elseif ($page==='confirm-server'): ?>
<?php $this->servers->_display('confirm',$viewData); ?>
<?php elseif ($page==='new-app'): $this->apps->_display('wizard',$viewData); ?>
<?php elseif ($page==='deployment' && $operation): $this->deployments->_display('progress',$viewData); ?>
<?php else: ?>
<?php $this->apps->_display('dashboard',$viewData); ?>
<?php endif ?></body></html>
