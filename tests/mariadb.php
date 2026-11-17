<?php
require dirname(__DIR__).'/lib/bootstrap.php';
$s=Store::open(); $s->migrate();
$id=$s->enqueue('deploy','server:test',[]); $job=$s->claim('test');
if ($job['id']!==$id || $s->claim('other')!==null) { throw new RuntimeException('Claim isolation failed'); }
$s->heartbeat($id,'test'); $s->finish($id,'test','succeeded',['verified'=>true]);
if ($s->job($id)['status']!=='succeeded') { throw new RuntimeException('Result persistence failed'); }
echo "MariaDB queue integration passed\n";
