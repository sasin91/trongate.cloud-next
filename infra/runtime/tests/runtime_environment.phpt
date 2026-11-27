--TEST--
Runtime environment distinguishes production and isolated execution
--FILE--
<?php
require __DIR__ . '/../runtime.php';

$production = Runtime_environment::production();
echo $production->root, '|', $production->production ? 'production' : 'isolated', "\n";

$isolated = Runtime_environment::isolated(sys_get_temp_dir() . '/tc-runtime-environment');
echo $isolated->production ? 'production' : 'isolated', "\n";

$inferred = Runtime_environment::for_root('/tmp/example');
echo $inferred->production ? 'production' : 'isolated', "\n";

$runtime = new Runtime(
    sys_get_temp_dir() . '/tc-runtime-environment',
    environment: Runtime_environment::isolated(sys_get_temp_dir() . '/tc-runtime-environment')
);
echo $runtime->environment->production ? 'production' : 'isolated', "\n";
Runtime::remove_tree($runtime->root);
?>
--EXPECT--
/var/db/tc/runtime|production
isolated
isolated
isolated
