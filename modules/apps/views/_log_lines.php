<?php foreach ($lines as $line):
    $ltime = (string) ($line['time'] ?? '');
    $llevel = (string) ($line['level'] ?? '');
    $ltext = (string) ($line['text'] ?? '');
    $lsource = (string) ($line['source'] ?? 'app');
?>
<div class="app-log-line" data-time="<?= $e($ltime) ?>" data-source="<?= $e($lsource) ?>"<?= $llevel !== '' ? ' data-level="' . $e($llevel) . '"' : '' ?>><span class="app-log-time" title="<?= $e($ltime) ?>"><?= $e(strlen($ltime) >= 19 ? substr($ltime, 11, 8) : $ltime) ?></span><span class="app-log-level"><?= $e($llevel) ?></span><span class="app-log-text"><?= $e($ltext) ?></span></div>
<?php endforeach ?>
