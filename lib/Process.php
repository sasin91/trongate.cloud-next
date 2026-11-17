<?php
declare(strict_types=1);
class Process {
    function run(array $argv, string $input = '', int $timeout = 60, ?callable $heartbeat = null, array $extra_env = []): array {
        $env = [];
        foreach (['PATH','HOME','USER','TMPDIR','SystemRoot','TEMP'] as $k) { if (getenv($k)!==false) { $env[$k]=getenv($k); } }
        $p = proc_open($argv,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$extra_env+$env,['bypass_shell'=>true]);
        if (!is_resource($p)) { throw new RuntimeException('Could not start subprocess'); }
        fwrite($pipes[0],$input); fclose($pipes[0]);
        stream_set_blocking($pipes[1],false); stream_set_blocking($pipes[2],false);
        $start=microtime(true); $last=0; $out=''; $err='';
        try {
            do {
                $out=substr($out.stream_get_contents($pipes[1]),-1048576); $err=substr($err.stream_get_contents($pipes[2]),-1048576);
                $status=proc_get_status($p);
                if (!$status['running']) { break; }
                if (microtime(true)-$start>$timeout) { throw new RuntimeException('Subprocess timed out; remote outcome may be uncertain'); }
                if ($heartbeat && time()-$last>=10) { $heartbeat(); $last=time(); }
                usleep(50000);
            } while (true);
            $out.=stream_get_contents($pipes[1]); $err.=stream_get_contents($pipes[2]);
            return ['exit'=>$status['exitcode'],'out'=>$out,'err'=>$err];
        } finally { if (proc_get_status($p)['running']) { proc_terminate($p,9); } fclose($pipes[1]); fclose($pipes[2]); proc_close($p); }
    }
}
