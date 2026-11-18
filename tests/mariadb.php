<?php
require dirname(__DIR__).'/lib/bootstrap.php';
if (($argv[1] ?? '')==='claim-child') {
    $job=(new Operations_model('operations'))->claim(bin2hex(random_bytes(16))); echo $job ? $job['id'] : 'none'; exit;
}
$s=new Operations_model('operations'); (new Setup_model('setup',$s->connection()))->migrate();
$id=$s->enqueue('deploy','server:test',[]); $job=$s->claim('test');
if ($job['id']!==$id || $s->claim('other')!==null) { throw new RuntimeException('Claim isolation failed'); }
$s->heartbeat($id,'test'); $s->finish($id,'test','succeeded',['verified'=>true]);
if ($s->job($id)['status']!=='succeeded') { throw new RuntimeException('Result persistence failed'); }
echo "MariaDB queue integration passed\n";
for ($i=0;$i<8;$i++) { $s->enqueue('deploy','server:concurrent',[]); }
$children=[];
for ($i=0;$i<4;$i++) {
    $process=proc_open([PHP_BINARY,__FILE__,'claim-child'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fclose($pipes[0]); $children[]=[$process,$pipes];
}
$claims=[];
foreach ($children as [$process,$pipes]) {
    $out=stream_get_contents($pipes[1]); $error=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process)!==0) { throw new RuntimeException('Concurrent claim child failed: '.$error); }
    if ($out!=='none') { $claims[]=$out; }
}
if (count($claims)!==1) { throw new RuntimeException('Conflicting operations were claimed concurrently'); }
echo "Four-process MariaDB resource serialization passed\n";
