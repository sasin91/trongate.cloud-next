<?php
trait Job_queue {
    private function job_queue(): Model {
        $this->module('jobs');
        return $this->jobs->model;
    }
    function enqueue(string $kind,string $resource,array $payload): string { return $this->job_queue()->enqueue($kind,$resource,$payload); }
    function job(string $id): array { return $this->job_queue()->job($id); }
    function step(string $id,string $step,array $data): void { $this->job_queue()->step($id,$step,$data); }
    function resolve(string $id,array $result): void { $this->job_queue()->resolve($id,$result); }
}
