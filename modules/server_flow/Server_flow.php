<?php
declare(strict_types=1);

/** Provider selection, reviewed provisioning and enrollment handoff for the app wizard. */
final class Server_flow {
    function __construct(private Store $store,private Http $http=new Http) {}
    function provider(string $id): Hetzner {
        $record=$this->store->get('provider',Validate::id($id));
        if ($record['type']!=='hetzner') { throw new RuntimeException('This provider is not supported yet.'); }
        return new Hetzner($this->store->secret($record['credential']),$this->http);
    }
    function connect(string $id,string $token): array {
        Validate::id($id);
        if (trim($token)==='') { throw new RuntimeException('Enter a Hetzner Cloud project API token.'); }
        foreach ($this->store->all('provider') as $existing) { if ($existing['id']===$id) { throw new RuntimeException('Project name already connected. Choose it from the list.'); } }
        (new Hetzner($token,$this->http))->list('locations');
        $ref='provider-'.substr(hash('sha256',$id),0,30);
        $this->store->secret($ref,$token); $this->store->put('provider',$id,['type'=>'hetzner','credential'=>$ref]);
        return ['id'=>$id];
    }
    function options(string $id): array {
        $provider=$this->provider($id);
        $options=$provider->options();
        $options['images']=array_merge($options['images'],$provider->list('images','&type=system'));
        $options['ssh_keys']=array_map(fn($key)=>['id'=>$key['id'],'name'=>$key['name'],'fingerprint'=>$key['fingerprint']],$provider->list('ssh_keys'));
        $options['pricing']=$provider->call('GET','/pricing')['pricing'];
        return $options;
    }
    function quote(array $p): array {
        $id=Validate::id($p['provider'] ?? ''); $name=Validate::id($p['name'] ?? '');
        $options=$this->options($id);
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
        $this->store->put('server-quote',$quote,['plan'=>$plan,'summary'=>$summary,'expires'=>time()+600]);
        return ['quote'=>$quote]+$summary;
    }
    function create(string $quote,bool $confirmed): array {
        if (!$confirmed) { throw new RuntimeException('Confirm the server configuration and cost first.'); }
        $s=$this->store;
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
    function status(string $operation): array {
        $job=$this->store->job($operation);
        if ($job['kind']!=='provision') { throw new RuntimeException('Not a server creation operation.'); }
        $payload=json_decode($job['payload'],true); $result=json_decode($job['result'],true);
        $out=['operation'=>$operation,'status'=>$job['status'],'name'=>$payload['name'],'provider'=>$payload['provider']];
        if ($job['status']==='succeeded') {
            $server=$this->store->get('provider-server',(string)$result['provider_id'])['server'];
            $out+=['provider_id'=>$server['id'],'host'=>$server['public_net']['ipv4']['ip'],'preparation_required'=>($payload['image_kind'] ?? '')==='system'];
        }
        if (in_array($job['status'],['failed','uncertain'])) { $out['message']='Inspect this operation and the provider console before creating another server; a resource may already exist.'; }
        return $out;
    }
}
