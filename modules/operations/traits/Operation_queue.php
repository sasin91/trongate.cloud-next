<?php
trait Operation_queue {
    private function operation_queue(): Operations_model { return new Operations_model('operations',$this->connection()); }
    function enqueue(string $kind,string $resource,array $payload): string { return $this->operation_queue()->enqueue($kind,$resource,$payload); }
    function job(string $id): array { return $this->operation_queue()->job($id); }
    function step(string $id,string $step,array $data): void { $this->operation_queue()->step($id,$step,$data); }
    function resolve(string $id,array $result): void { $this->operation_queue()->resolve($id,$result); }
}
