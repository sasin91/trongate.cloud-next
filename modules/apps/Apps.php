<?php
declare(strict_types=1);
class Apps extends Trongate {
    function index(): void { $this->console->_page('dashboard'); }
    function create(): void { $this->console->_page('new-app'); }
    // Internal HMVC entry points: Core blocks underscore methods from URL dispatch.
    function _launch(Store $store, Control $control, string $command, bool $inline): void {
        if (!hash_equals($_SESSION['launch_request'],(string)($_POST['request'] ?? ''))) { throw new RuntimeException('This launch form has expired. Reload and try again.'); }
        $operation=$this->_plan_launch($store,$_POST,$_SESSION['launch_request']);
        header('Location: /deployments?operation='.rawurlencode($operation),true,303); exit;
    }
    function _display(string $view, array $data): void {
        $data['viewData']=$data;
        $this->view($view, $data);
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

    function _plan_launch(Store $store, array $p, string $request): string {
        try { return $store->get('launch',$request)['operation']; } catch (RuntimeException) {}
        $id=Validate::id(trim($p['label'] ?? ''));
        $server=Validate::id($p['server'] ?? '');
        if (!$store->get('server',$server)['application_ready']) { throw new RuntimeException('Choose a server that is ready for applications.'); }
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
            $builder=Validate::id($p['builder'] ?? ''); $store->get('server',$builder);
            if ($builder===$server) { throw new RuntimeException('Choose a separate builder to keep application builds isolated.'); }
            $repository=self::_repository($p['repository'] ?? '');
            $branch=trim($p['branch'] ?? 'main');
            if ($branch==='' || strlen($branch)>200) { throw new RuntimeException('Choose a branch or commit.'); }
            $commit=self::_github($repository.'/commits/'.rawurlencode($branch))['sha'];
            $build=['builder'=>$builder,'plan'=>Build::plan(['repository'=>$repository,'commit'=>$commit,'base'=>trim($p['base'] ?? ''),'tag'=>trim($p['tag'] ?? ''),
                'commands'=>$p['build_commands'] ?? '', 'extensions'=>preg_split('/[\s,]+/',trim($p['php_extensions'] ?? ''),-1,PREG_SPLIT_NO_EMPTY)])];
            $spec['commit']=$commit;
        }
        $s=$store; $s->db->beginTransaction();
        try {
            // Unique records reject concurrent submissions instead of replacing another app.
            $s->run('INSERT INTO records(kind,id,data) VALUES(?,?,?)',['app',$id,json(['server'=>$server,'label'=>$id,'domain'=>$spec['domain'],'source'=>$source])]);
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
            $s->run('INSERT INTO records(kind,id,data) VALUES(?,?,?)',['launch',$request,json(['operation'=>$operation])]);
            $s->db->commit(); return $operation;
        } catch (Throwable $e) {
            $s->db->rollBack();
            try { return $s->get('launch',$request)['operation']; } catch (RuntimeException) {}
            if ($e instanceof PDOException) { throw new RuntimeException('This app name is already in use. Choose another name.'); }
            throw $e;
        }
    }

    function _deploy_built(Store $store, string $build, array $launch, array $result): string {
        $s=$store;
        try { return $s->get('build-deployment',$build)['operation']; } catch (RuntimeException) {}
        $spec=Validate::release(array_replace($launch['release'],['image'=>$result['image']]));
        $s->db->beginTransaction();
        try {
            $s->run('INSERT INTO records(kind,id,data) VALUES(?,?,?)',['build-deployment',$build,'{}']);
            $release=(new Control($s))->execute('release',['app'=>$launch['app'],'release'=>$spec]);
            $operation=(new Control($s))->execute('deploy',['app'=>$launch['app'],'release'=>$release]);
            $s->put('build-deployment',$build,['operation'=>$operation]);
            $s->db->commit(); return $operation;
        } catch (Throwable $e) {
            $s->db->rollBack();
            try { return $s->get('build-deployment',$build)['operation']; } catch (RuntimeException) { throw $e; }
        }
    }
}
