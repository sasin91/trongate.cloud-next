<?php
declare(strict_types=1);
class Providers extends Trongate {
    function index(): void {
        $this->trongate_security->make_sure_allowed();
        $this->templates->_render('servers','connect','connect-server');
    }
    function action(): void {
        $this->trongate_security->make_sure_allowed(); $this->trongate_security->_csrf();
        $store=Store::open();
        try { $this->_handle($store,new Control($store),(string)($_POST['command'] ?? ''),true); }
        catch (Throwable $e) { $this->trongate_security->_json(['error'=>$e instanceof RuntimeException?$e->getMessage():'Invalid input'],422); }
    }
    // Internal HMVC entry points: Core blocks underscore methods from URL dispatch.
    function _handle(Store $store, Control $control, string $command, bool $inline): void {
        $result=match($command) {
            'connect-provider'=>$this->_connect_project($store,$_POST['provider_name'] ?? '',$_POST['token'] ?? ''),
            'provider-server-options'=>$this->_options($store,$_POST['provider'] ?? ''),
            'quote-provider-server'=>$this->_quote($store,$_POST),
            'create-provider-server'=>$this->_create($store,$_POST['quote'] ?? '',($_POST['confirm_cost'] ?? '')==='1'),
            'provider-server-status'=>$this->_status($store,$_POST['operation'] ?? '')
        };
        $this->trongate_security->_json($result); return;
    }
    function _display(string $view, array $data): void {
        $data['viewData']=$data;
        $this->view($view, $data);
    }
    function _client(Store $store, string $id, Http $http=new Http): Hetzner {
        $record=$store->get('provider',Validate::id($id));
        if ($record['type']!=='hetzner') { throw new RuntimeException('This provider is not supported yet.'); }
        return new Hetzner($store->secret($record['credential']),$http);
    }
    function _connect_project(Store $store, string $id,string $token, Http $http=new Http): array {
        Validate::id($id);
        if (trim($token)==='') { throw new RuntimeException('Enter a Hetzner Cloud project API token.'); }
        foreach ($store->all('provider') as $existing) { if ($existing['id']===$id) { throw new RuntimeException('Project name already connected. Choose it from the list.'); } }
        (new Hetzner($token,$http))->list('locations');
        $ref='provider-'.substr(hash('sha256',$id),0,30);
        $store->secret($ref,$token); $store->put('provider',$id,['type'=>'hetzner','credential'=>$ref]);
        return ['id'=>$id];
    }
    function _options(Store $store, string $id, Http $http=new Http): array {
        $provider=$this->_client($store,$id,$http);
        $options=$provider->options();
        $options['images']=array_merge($options['images'],$provider->list('images','&type=system'));
        $options['ssh_keys']=array_map(fn($key)=>['id'=>$key['id'],'name'=>$key['name'],'fingerprint'=>$key['fingerprint']],$provider->list('ssh_keys'));
        $options['pricing']=$provider->call('GET','/pricing')['pricing'];
        return $options;
    }
    function _quote(Store $store, array $p, Http $http=new Http): array {
        $id=Validate::id($p['provider'] ?? ''); $name=Validate::id($p['name'] ?? '');
        $options=$this->_options($store,$id,$http);
        $find=function(array $items,string $field,mixed $value): array {
            foreach ($items as $item) { if ((string)$item[$field]===(string)$value) { return $item; } }
            throw new RuntimeException('The selected provider option is no longer available. Reload the options.');
        };
        $type=$find($options['server_types'],'name',$p['server_type'] ?? '');
        $location=$find($options['locations'],'name',$p['location'] ?? '');
        $image=$find($options['images'],'id',$p['image'] ?? '');
        $key=$find($options['ssh_keys'],'id',$p['ssh_key'] ?? '');
        $availability=$find($type['locations'] ?? [],'name',$location['name']);
        if (empty($availability['available']) || !empty($type['deprecated'])) { throw new RuntimeException('That server type is currently unavailable in this location.'); }
        if ($image['architecture']!==$type['architecture'] || ($image['status'] ?? '')!=='available' || !empty($image['deprecated'])) { throw new RuntimeException('Select an available image matching the server architecture.'); }
        if ($image['type']!=='system' && ($image['type']!=='snapshot' || ($image['labels']['tc-nixos'] ?? '')!=='true')) { throw new RuntimeException('Choose a system image or a prepared Trongate snapshot.'); }
        if ((int)($image['disk_size'] ?? 0)>(int)$type['disk']) { throw new RuntimeException('This image needs a larger disk.'); }
        $price=$find($type['prices'],'location',$location['name']);
        $ip=$find($options['pricing']['primary_ips'],'type','ipv4');
        $ipPrice=$find($ip['prices'],'location',$location['name']);
        $plan=['provider'=>$id,'name'=>$name,'server_type'=>$type['name'],'location'=>$location['name'],'image'=>$image['id'],'image_kind'=>$image['type'],'ssh_keys'=>[$key['id']],'confirm'=>true];
        $summary=['name'=>$name,'provider'=>$id,'type'=>$type['name'],'location'=>$location['description'] ?? $location['name'],'image'=>$image['description'],'ssh_key'=>$key['name'],
            'currency'=>$options['pricing']['currency'],'monthly_net'=>number_format((float)$price['price_monthly']['net']+(float)$ipPrice['price_monthly']['net'],2,'.',''),
            'hourly_net'=>number_format((float)$price['price_hourly']['net']+(float)$ipPrice['price_hourly']['net'],4,'.',''),
            'preparation_required'=>$image['type']==='system'];
        $quote=bin2hex(random_bytes(16));
        $store->put('server-quote',$quote,['plan'=>$plan,'summary'=>$summary,'expires'=>time()+600]);
        return ['quote'=>$quote]+$summary;
    }
    function _create(Store $store, string $quote,bool $confirmed): array {
        if (!$confirmed) { throw new RuntimeException('Confirm the server configuration and cost first.'); }
        $s=$store;
        try { return $s->get('server-request',$quote); } catch (RuntimeException) {}
        $record=$s->get('server-quote',$quote);
        if ($record['expires']<time()) { throw new RuntimeException('The review has expired. Review the current options again.'); }
        $s->db->beginTransaction();
        try {
            $s->run('INSERT INTO records(kind,id,data) VALUES(?,?,?)',['server-request',$quote,'{}']);
            $operation=(new Control($s))->execute('provision',$record['plan']);
            $result=['operation'=>$operation]; $s->put('server-request',$quote,$result);
            $s->db->commit(); return $result;
        } catch (Throwable $e) {
            $s->db->rollBack();
            try { return $s->get('server-request',$quote); } catch (RuntimeException) { throw $e; }
        }
    }
    function _status(Store $store, string $operation): array {
        $job=$store->job($operation);
        if ($job['kind']!=='provision') { throw new RuntimeException('Not a server creation operation.'); }
        $payload=json_decode($job['payload'],true); $result=json_decode($job['result'],true);
        $out=['operation'=>$operation,'status'=>$job['status'],'name'=>$payload['name'],'provider'=>$payload['provider']];
        if ($job['status']==='succeeded') {
            $server=$store->get('provider-server',(string)$result['provider_id'])['server'];
            $out+=['provider_id'=>$server['id'],'host'=>$server['public_net']['ipv4']['ip'],'preparation_required'=>($payload['image_kind'] ?? '')==='system'];
        }
        if (in_array($job['status'],['failed','uncertain'])) { $out['message']='Inspect this operation and the provider console before creating another server; a resource may already exist.'; }
        return $out;
    }
}
