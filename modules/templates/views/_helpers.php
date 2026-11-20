<?php
$e=fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$value=fn($name,$default='')=>$e($_POST[$name] ?? $default);
$csrf=fn()=>'<input type="hidden" name="csrf_token" value="'.$e($_SESSION['csrf_token']).'">';
$field=function($name,$label,$default='',$type='text',$required=true) use($value,$e) { ?>
<label class="wz-field"><span class="wz-label"><?= $e($label) ?></span><input class="wz-in" type="<?= $e($type) ?>" name="<?= $e($name) ?>" value="<?= $type==='password'?'':$value($name,$default) ?>" <?= $required?'required':'' ?>></label>
<?php };
$ready=array_filter($inventory['servers'] ?? [],fn($s)=>$s['application_ready']);
