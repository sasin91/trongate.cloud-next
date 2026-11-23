<?php
require_once APPPATH . 'modules/trongate_security/traits/Encrypted_credentials.php';
require_once APPPATH . 'modules/jobs/traits/Job_queue.php';
class Apps_model extends Model {
    use Encrypted_credentials, Job_queue;
    public function __construct(?string $module_name = null, PDO|Closure|null $connection = null) {
        parent::__construct($module_name, $connection);
        $this->module('records');
    }

    function _load_draft(int $user): ?array {
        return $this->records->_find(['kind' => 'app-draft', 'id' => (string) $user]);
    }

    function _save_draft(int $user, string $encrypted, string $request): void {
        $this->records->_put(['kind' => 'app-draft', 'id' => (string) $user, 'data' => ['encrypted' => $encrypted, 'request' => $request]]);
    }

    function _delete_draft(int $user): void {
        $this->records->_delete(['kind' => 'app-draft', 'id' => (string) $user]);
    }

    function _build_target(string $target, string $app, string $request, string $mode = 'local'): array {
        $application = $this->records->_get(['kind' => 'server', 'id' => $target]);
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
        $applicationServers = array_column($this->records->_all('app'), 'server');
        $candidates = [];
        foreach ($this->records->_all('server') as $server) {
            if ($server['id'] === $target || ($server['host'] ?? '') === ($application['host'] ?? '') ||
                empty($server['builder_ready']) || $architecture === '' || ($server['facts']['arch'] ?? '') !== $architecture ||
                in_array($server['id'], $applicationServers, true)) {
                continue;
            }
            $server['build_queue'] = (int) $this->db->query_bind(
                "SELECT COUNT(*) FROM jobs WHERE resource=? AND status IN ('pending','running','uncertain')",
                ['builder:' . $server['id']]
            , 'statement')->fetchColumn();
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

    function _plan_launch(array $p, string $request, ?array $source_data = null): string {
        try { return $this->records->_get(['kind' => 'launch', 'id' => $request])['operation']; } catch (RuntimeException) {}
        $id=Validate::id(trim($p['label'] ?? ''));
        $server=Validate::id($p['server'] ?? '');
        if (!$this->records->_get(['kind' => 'server', 'id' => $server])['application_ready']) { throw new RuntimeException('Choose a server that is ready for applications.'); }
        $source=$p['source'] ?? 'image';
        if (!in_array($source,['image','git'],true)) { throw new RuntimeException('Choose an image or public GitHub repository.'); }
        $spec=['image'=>$source==='image'?trim($p['image'] ?? ''):'registry.invalid/pending@sha256:'.str_repeat('0',64),
            'domain'=>trim($p['domain'] ?? ''),'health'=>$p['health'] ?? '/',
            'container_port'=>$p['container_port'] ?? 8080];
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
            $repository=Apps::_repository($p['repository'] ?? '');
            $branch=trim($p['branch'] ?? 'main');
            if ($branch==='' || strlen($branch)>200) { throw new RuntimeException('Choose a branch or commit.'); }
            if ($source_data === null || $source_data['repository'] !== $repository || $source_data['branch'] !== $branch) {
                throw new RuntimeException('Resolve the source revision before planning a build.');
            }
            $commit = $source_data['commit'];
            $metadata = $source_data;
            $build=['builder'=>$builder,'mode'=>$target['mode'],'plan'=>Build::plan(['repository'=>$repository,'commit'=>$commit,'base'=>'','container_port'=>$spec['container_port'],'tag'=>$target['tag'],
                'commands'=>$p['build_commands'] ?? '', 'extensions'=>preg_split('/[\s,]+/',trim($p['php_extensions'] ?? ''),-1,PREG_SPLIT_NO_EMPTY)])];
            $registrySecret = trim(getenv('TC_BUILD_REGISTRY_SECRET') ?: '');
            if ($target['mode'] === 'dedicated' && $registrySecret !== '') {
                $spec['registry_secret'] = Validate::id($registrySecret);
                $this->secret($registrySecret);
            }
            $spec['commit']=$commit;
        }
        $s=$this; $s->db->connection()->beginTransaction();
        try {
            // Unique records reject concurrent submissions instead of replacing another app.
            $this->records->_insert(['kind' => 'app', 'id' => $id, 'data' => ['server' => $server, 'label' => $id, 'domain' => $spec['domain'], 'source' => $source]]);
            foreach ($environment as $name=>$value) {
                $ref='env-'.substr(hash('sha256',$id.':'.$name),0,32);
                $s->secret($ref,$value); $spec['secrets'][$name]=$ref;
            }
            if ($build!==null) {
                $operation=$s->enqueue('build','builder:'.$build['builder'],$build+['launch'=>['app'=>$id,'server'=>$server,'release'=>$spec,'metadata'=>$metadata]]);
            } else {
                $release=$this->_release(['app'=>$id,'release'=>$spec]);
                $this->module('deployments');
                $operation=$this->deployments->model->_queue('deploy',['app'=>$id,'release'=>$release]);
            }
            $this->records->_insert(['kind' => 'launch', 'id' => $request, 'data' => ['operation' => $operation]]);
            $s->db->connection()->commit(); return $operation;
        } catch (Throwable $e) {
            $s->db->connection()->rollBack();
            try { return $s->records->_get(['kind' => 'launch', 'id' => $request])['operation']; } catch (RuntimeException) {}
            if ($e instanceof PDOException) { throw new RuntimeException('This app name is already in use. Choose another name.'); }
            throw $e;
        }
    }

    function _deploy_built(string $build, array $launch, array $result): string {
        $s=$this;
        try { return $s->records->_get(['kind' => 'build-deployment', 'id' => $build])['operation']; } catch (RuntimeException) {}
        $spec=Validate::release(array_replace($launch['release'],['image'=>$result['image']]));
        $s->db->connection()->beginTransaction();
        try {
            $this->records->_insert(['kind' => 'build-deployment', 'id' => $build, 'data' => []]);
            $release=$this->_release(['app'=>$launch['app'],'release'=>$spec,'metadata'=>$launch['metadata'] ?? []]);
            $this->module('deployments');
            $operation=$this->deployments->model->_queue('deploy',['app'=>$launch['app'],'release'=>$release]);
            $s->records->_put(['kind' => 'build-deployment', 'id' => $build, 'data' => ['operation'=>$operation]]);
            $s->db->connection()->commit(); return $operation;
        } catch (Throwable $e) {
            $s->db->connection()->rollBack();
            try { return $s->records->_get(['kind' => 'build-deployment', 'id' => $build])['operation']; } catch (RuntimeException) { throw $e; }
        }
    }
    function _create(array $p): string {
        $s = $this;
        $id = Validate::id($p['id']);
        $server = Validate::id($p['server']);
        $s->records->_get(['kind' => 'server', 'id' => $server]);
        foreach ($s->records->_all('app') as $existing) {
            if ($existing['id'] === $id) {
                throw new RuntimeException('Application exists');
            }
        }
        $s->records->_put(['kind' => 'app', 'id' => $id, 'data' => ['server' => $server]]);
        return $id;
    }
    function _release(array $p): string {
        $s = $this;
        $app = Validate::id($p['app']);
        $s->records->_get(['kind' => 'app', 'id' => $app]);
        $release = Validate::release($p['release']);
        $id = hash('sha256', self::_encode_json($release));
        try {
            $existing = $s->records->_get(['kind' => 'release', 'id' => $id]);
        } catch (RuntimeException $e) {
            $existing = [];
        }
        $s->records->_put(['kind' => 'release', 'id' => $id, 'data' => [
            'app' => $app, 'spec' => $release,
            'created' => $existing['created'] ?? time(),
            'metadata' => $existing['metadata'] ?? ($p['metadata'] ?? [])
        ]]);
        return $id;
    }

    private static function _encode_json(array $value): string {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
