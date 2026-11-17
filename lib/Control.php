<?php
declare(strict_types=1);
final class Control {
    function __construct(public Store $store) {}
    function execute(string $command,array $p): mixed {
        $s=$this->store;
        switch ($command) {
            case 'build':
                $builder=Validate::id($p['builder']); $s->get('server',$builder);
                return $s->enqueue('build','builder:'.$builder,['builder'=>$builder,'plan'=>Build::plan($p)]);
            case 'credential':
                return $s->secret(Validate::id($p['id']),$p['value']);
            case 'provider-connect':
                $id=Validate::id($p['id']); $credential=Validate::id($p['credential']);
                (new Hetzner($s->secret($credential)))->list('locations');
                $s->put('provider',$id,['type'=>'hetzner','credential'=>$credential]); return $id;
            case 'provider-options':
                return $this->provider($p['provider'])->options();
            case 'bootstrap-options':
                return $this->provider($p['provider'])->list('images','&type=system');
            case 'server-scan':
                $server=Validate::server($p); $identity=(new Ssh($s))->scan($server);
                $id=bin2hex(random_bytes(16)); $s->put('enrollment',$id,['server'=>$server,'identity'=>$identity,'expires'=>time()+900]);
                return ['enrollment'=>$id]+$identity+['instruction'=>'Verify fingerprint using an independent server console, then server-confirm.'];
            case 'server-confirm':
                $pending=$s->get('enrollment',$p['enrollment']);
                if ($pending['expires']<time() || !hash_equals($pending['identity']['fingerprint'],$p['fingerprint'])) { throw new RuntimeException('Expired enrollment or fingerprint mismatch'); }
                $id=Validate::id($p['id']);
                foreach ($s->all('server') as $existing) { if ($existing['id']===$id) { throw new RuntimeException('Server already enrolled; identity rotation requires a new inventory record'); } }
                $server=$pending['server']+['host_key'=>$pending['identity']['key']];
                if (isset($p['provider_id'],$p['provider'])) {
                    $observed=$this->provider($p['provider'])->call('GET','/servers/'.Validate::number($p['provider_id'],1,PHP_INT_MAX))['server'];
                    if (($observed['public_net']['ipv4']['ip'] ?? '')!==$server['host']) { throw new RuntimeException('Provider address does not match enrollment; use its primary IPv4 for linking'); }
                    $server+=['provider'=>$p['provider'],'provider_id'=>(int)$p['provider_id']];
                }
                $inspection=(new Ssh($s))->inspect($server);
                $s->put('server',$id,$server+$inspection); return ['id'=>$id]+$inspection;
            case 'server-inspect':
                $server=$s->get('server',$p['server']); $inspection=(new Ssh($s))->inspect($server);
                $s->put('server',$p['server'],array_replace($server,$inspection)); return $inspection;
            case 'app-create':
                $id=Validate::id($p['id']); $server=Validate::id($p['server']); $s->get('server',$server);
                foreach ($s->all('app') as $existing) { if ($existing['id']===$id) { throw new RuntimeException('Application exists'); } }
                $s->put('app',$id,['server'=>$server]); return $id;
            case 'release':
                $app=Validate::id($p['app']); $s->get('app',$app);
                $release=Validate::release($p['release']); $id=hash('sha256',json($release));
                $s->put('release',$id,['app'=>$app,'spec'=>$release]); return $id;
            case 'deploy':
            case 'rollback':
                $app=$s->get('app',$p['app']);
                if ($command==='deploy' && $s->get('release',$p['release'])['app']!==$p['app']) { throw new RuntimeException('Release belongs to another application'); }
                return $s->enqueue($command,'server:'.$app['server'],$p+['server'=>$app['server']]);
            case 'host-update':
            case 'host-rollback':
                $server=$s->get('server',$p['server']);
                if (!$server['managed_eligible']) { throw new RuntimeException('Host is not eligible for NixOS management'); }
                if ($command==='host-update') { Validate::match($p['revision'],'/^[a-f0-9]{64}$/D','revision'); }
                return $s->enqueue($command,'server:'.$p['server'],$p);
            case 'provision':
            case 'bootstrap-create':
            case 'provider-rescue':
            case 'provider-poweroff':
            case 'provider-reboot':
            case 'provider-delete':
            case 'snapshot-create':
            case 'snapshot-delete':
                $s->get('provider',$p['provider']);
                if (($p['confirm'] ?? false)!==true) { throw new RuntimeException('Explicit operator confirmation required for provider mutation'); }
                if (in_array($command,['provision','bootstrap-create'])) {
                    $p['bootstrap']=$command==='bootstrap-create';
                    Validate::id($p['name']); Validate::id($p['server_type']); Validate::id($p['location']);
                    Validate::number($p['image'],1,PHP_INT_MAX);
                    if (empty($p['ssh_keys'])) { throw new RuntimeException('Select at least one SSH public key'); }
                } else { Validate::number($p['provider_id'],1,PHP_INT_MAX); }
                if ($command==='snapshot-create') {
                    $eligible=false;
                    foreach ($s->all('server') as $server) {
                        if (($server['provider'] ?? null)===$p['provider'] && ($server['provider_id'] ?? null)===(int)$p['provider_id'] && $server['managed_eligible'] && $server['application_ready']) { $eligible=true; }
                    }
                    if (!$eligible) { throw new RuntimeException('Snapshot source must be linked to an inspected, ready NixOS server'); }
                }
                $resource='provider:'.$p['provider'];
                if (isset($p['provider_id']) && $command!=='snapshot-delete') {
                    foreach ($s->all('server') as $server) {
                        if (($server['provider'] ?? null)===$p['provider'] && ($server['provider_id'] ?? null)===(int)$p['provider_id']) { $resource='server:'.$server['id']; break; }
                    }
                }
                return $s->enqueue($command,$resource,$p);
            case 'provider-inspect':
                return $this->provider($p['provider'])->call('GET','/servers/'.Validate::number($p['provider_id'],1,PHP_INT_MAX));
            case 'reconcile':
                $job=$s->job($p['operation']);
                if ($job['status']!=='uncertain') { throw new RuntimeException('Only uncertain operations can be reconciled'); }
                $s->run("UPDATE jobs SET status='pending',attempts=0 WHERE id=? AND status='uncertain'",[$job['id']]); return $job['id'];
            case 'operations':
                return $s->run('SELECT id,resource,kind,status,attempts,created,result FROM jobs ORDER BY created DESC LIMIT 100')->fetchAll();
            case 'operation':
                return ['job'=>$s->job($p['operation']),'steps'=>$s->run('SELECT at,step,data FROM steps WHERE operation=? ORDER BY at,id',[$p['operation']])->fetchAll()];
            case 'inventory':
                return ['servers'=>$s->all('server'),'providers'=>$s->all('provider'),'apps'=>$s->all('app'),'releases'=>$s->all('release')];
            default: throw new RuntimeException('Unsupported command');
        }
    }
    function provider(string $id): Hetzner { return new Hetzner($this->store->secret($this->store->get('provider',$id)['credential'])); }
}
