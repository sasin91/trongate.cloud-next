<?php
require_once dirname(__DIR__).'/db/traits/Database_access.php';
require_once dirname(__DIR__).'/operations/traits/Inventory_records.php';
require_once dirname(__DIR__).'/operations/traits/Encrypted_credentials.php';
require_once dirname(__DIR__).'/operations/traits/Operation_queue.php';
class Apps_model extends Model {
    use Database_access, Inventory_records, Encrypted_credentials, Operation_queue;
    function _load_draft(int $user): ?array {
        $row = $this->run('SELECT data FROM records WHERE kind=? AND id=?', ['app-draft', (string) $user])->fetchColumn();
        return $row === false ? null : json_decode($row, true, 512, JSON_THROW_ON_ERROR);
    }

    function _save_draft(int $user, string $encrypted, string $request): void {
        $this->put('app-draft', (string) $user, ['encrypted' => $encrypted, 'request' => $request]);
    }

    function _delete_draft(int $user): void {
        $this->run('DELETE FROM records WHERE kind=? AND id=?', ['app-draft', (string) $user]);
    }

    static function _repository(string $value): string {
        $value=preg_replace('~^https://github\.com/~i','',trim($value));
        return Validate::match(preg_replace('~\.git$~','',rtrim($value,'/')),'~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~D','public GitHub repository');
    }

    static function _github(string $path): array {
        $c=curl_init('https://api.github.com/repos/'.$path);
        curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_HTTPHEADER=>['Accept: application/vnd.github+json','User-Agent: Trongate-Cloud-Next']]);
        $body=curl_exec($c); $code=curl_getinfo($c,CURLINFO_RESPONSE_CODE); curl_close($c);
        if ($code!==200 || $body===false) { throw new RuntimeException('Could not read the public GitHub repository. Check the repository and branch, or try again after the API rate limit resets.'); }
        return json_decode($body,true,512,JSON_THROW_ON_ERROR);
    }

    function _build_target(string $target, string $app, string $request, string $mode = 'local'): array {
        $application = $this->get('server', $target);
        if ($mode === 'local') {
            if (empty($application['local_builder_ready'])) {
                throw new RuntimeException('Enable local builds on this server from Servers before continuing. Your setup is saved.');
            }
            return ['builder' => $target, 'mode' => 'local', 'tag' => 'localhost/tc/' . Validate::id($app) . ':build-' . substr(hash('sha256', $request), 0, 24)];
        }
        if ($mode !== 'dedicated') {
            throw new RuntimeException('Choose application server or dedicated builder.');
        }
        $architecture = $application['facts']['arch'] ?? '';
        $applicationServers = array_column($this->all('app'), 'server');
        $candidates = [];
        foreach ($this->all('server') as $server) {
            if ($server['id'] === $target || ($server['host'] ?? '') === ($application['host'] ?? '') ||
                empty($server['builder_ready']) || $architecture === '' || ($server['facts']['arch'] ?? '') !== $architecture ||
                in_array($server['id'], $applicationServers, true)) {
                continue;
            }
            $server['build_queue'] = (int) $this->run(
                "SELECT COUNT(*) FROM jobs WHERE resource=? AND status IN ('pending','running','uncertain')",
                ['builder:' . $server['id']]
            )->fetchColumn();
            $candidates[] = $server;
        }
        usort($candidates, static fn($a, $b) => [$a['build_queue'], $a['id']] <=> [$b['build_queue'], $b['id']]);
        if (!$candidates) {
            throw new RuntimeException('Git builds are not configured yet: no compatible dedicated builder is available. Your app setup is saved.');
        }
        $registry = rtrim(trim(getenv('TC_BUILD_REGISTRY') ?: ''), '/');
        if ($registry === '') {
            throw new RuntimeException('Git builds are not configured yet: the output registry needs to be set up. Your app setup is saved.');
        }
        Validate::match($registry, '~^[a-z0-9][a-z0-9.-]*(?::[0-9]+)?(?:/[a-z0-9][a-z0-9._-]*)*$~D', 'configured build registry');
        $tag = $registry . '/' . Validate::id($app) . ':build-' . substr(hash('sha256', $request), 0, 24);
        return ['builder' => $candidates[0]['id'], 'mode' => 'dedicated', 'tag' => $tag];
    }

    function _plan_launch(array $p, string $request): string {
        try { return $this->get('launch',$request)['operation']; } catch (RuntimeException) {}
        $id=Validate::id(trim($p['label'] ?? ''));
        $server=Validate::id($p['server'] ?? '');
        if (!$this->get('server',$server)['application_ready']) { throw new RuntimeException('Choose a server that is ready for applications.'); }
        $source=$p['source'] ?? 'image';
        if (!in_array($source,['image','git'],true)) { throw new RuntimeException('Choose an image or public GitHub repository.'); }
        $spec=['image'=>$source==='image'?trim($p['image'] ?? ''):'registry.invalid/pending@sha256:'.str_repeat('0',64),
            'domain'=>trim($p['domain'] ?? ''),'health'=>$p['health'] ?? '/',
            'container_port'=>$p['container_port'] ?? 8080,'memory_mb'=>$p['memory_mb'] ?? 256,'cpus'=>$p['cpus'] ?? 1];
        foreach (preg_split('/\r?\n/',trim($p['persistent_paths'] ?? '')) as $i=>$path) {
            if (trim($path)!=='') { $spec['mounts']['data-'.$i]='/app/'.ltrim(trim($path),'/'); }
        }
        $environment=[];
        foreach (preg_split('/\r?\n/',trim($p['environment'] ?? '')) as $line) {
            if ($line==='') { continue; }
            if (!str_contains($line,'=')) { throw new RuntimeException('Environment variables must use NAME=value, one per line.'); }
            [$name,$value]=explode('=',$line,2);
            Validate::match($name,'/^[A-Z_][A-Z0-9_]*$/D','environment name');
            $environment[$name]=$value;
        }
        $spec=Validate::release($spec);
        $build=null;
        if ($source==='git') {
            $target = $this->_build_target($server, $id, $request, $p['build_mode'] ?? 'local');
            $builder = $target['builder'];
            $repository=self::_repository($p['repository'] ?? '');
            $branch=trim($p['branch'] ?? 'main');
            if ($branch==='' || strlen($branch)>200) { throw new RuntimeException('Choose a branch or commit.'); }
            $commit=self::_github($repository.'/commits/'.rawurlencode($branch))['sha'];
            $build=['builder'=>$builder,'mode'=>$target['mode'],'plan'=>Build::plan(['repository'=>$repository,'commit'=>$commit,'base'=>'','container_port'=>$spec['container_port'],'tag'=>$target['tag'],
                'commands'=>$p['build_commands'] ?? '', 'extensions'=>preg_split('/[\s,]+/',trim($p['php_extensions'] ?? ''),-1,PREG_SPLIT_NO_EMPTY)])];
            $registrySecret = trim(getenv('TC_BUILD_REGISTRY_SECRET') ?: '');
            if ($target['mode'] === 'dedicated' && $registrySecret !== '') {
                $spec['registry_secret'] = Validate::id($registrySecret);
                $this->secret($registrySecret);
            }
            $spec['commit']=$commit;
        }
        $s=$this; $s->connection()->beginTransaction();
        try {
            // Unique records reject concurrent submissions instead of replacing another app.
            $s->run('INSERT INTO records(kind,id,data) VALUES(?,?,?)',['app',$id,tc_json(['server'=>$server,'label'=>$id,'domain'=>$spec['domain'],'source'=>$source])]);
            foreach ($environment as $name=>$value) {
                $ref='env-'.substr(hash('sha256',$id.':'.$name),0,32);
                $s->secret($ref,$value); $spec['secrets'][$name]=$ref;
            }
            if ($build!==null) {
                $operation=$s->enqueue('build','builder:'.$build['builder'],$build+['launch'=>['app'=>$id,'server'=>$server,'release'=>$spec]]);
            } else {
                $release=(new Control($s))->execute('release',['app'=>$id,'release'=>$spec]);
                $operation=(new Control($s))->execute('deploy',['app'=>$id,'release'=>$release]);
            }
            $s->run('INSERT INTO records(kind,id,data) VALUES(?,?,?)',['launch',$request,tc_json(['operation'=>$operation])]);
            $s->connection()->commit(); return $operation;
        } catch (Throwable $e) {
            $s->connection()->rollBack();
            try { return $s->get('launch',$request)['operation']; } catch (RuntimeException) {}
            if ($e instanceof PDOException) { throw new RuntimeException('This app name is already in use. Choose another name.'); }
            throw $e;
        }
    }

    function _deploy_built(string $build, array $launch, array $result): string {
        $s=$this;
        try { return $s->get('build-deployment',$build)['operation']; } catch (RuntimeException) {}
        $spec=Validate::release(array_replace($launch['release'],['image'=>$result['image']]));
        $s->connection()->beginTransaction();
        try {
            $s->run('INSERT INTO records(kind,id,data) VALUES(?,?,?)',['build-deployment',$build,'{}']);
            $release=(new Control($s))->execute('release',['app'=>$launch['app'],'release'=>$spec]);
            $operation=(new Control($s))->execute('deploy',['app'=>$launch['app'],'release'=>$release]);
            $s->put('build-deployment',$build,['operation'=>$operation]);
            $s->connection()->commit(); return $operation;
        } catch (Throwable $e) {
            $s->connection()->rollBack();
            try { return $s->get('build-deployment',$build)['operation']; } catch (RuntimeException) { throw $e; }
        }
    }
}
