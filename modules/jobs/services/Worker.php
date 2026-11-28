<?php
declare(strict_types=1);
require_once APPPATH . 'modules/jobs/services/Job_context.php';

class Worker {
    function __construct(private Model $model) {
    }
    function once(): bool {
        $owner = bin2hex(random_bytes(16));
        $job = $this->model->claim($owner);
        if (!$job) {
            return false;
        }
        $id = $job['id'];
        $heartbeat = fn() => $this->model->heartbeat($id, $owner);
        try {
            if ($job['attempts'] > 30) {
                throw new RuntimeException('Retry budget exhausted; reconcile required');
            }
            $this->model->step($id, 'claimed', ['attempt' => (int)$job['attempts']]);
            $context = new Job_context($id, $job['kind'], json_decode($job['payload'], true, 512, JSON_THROW_ON_ERROR), $heartbeat);
            $result = $this->perform($context);
            $status = $result['status'] ?? 'uncertain';
            if (!in_array($status, ['pending', 'succeeded', 'failed', 'uncertain', 'waiting'])) {
                $status = 'uncertain';
            }
            $this->model->step($id, 'observed', $result);
            $this->model->finish($id, $owner, $status, $result);
        } catch (Throwable $e) {
            // Exceptions may originate in libraries with credential-bearing messages.
            // Keep a safe class and step; raw host/build/customer output is not logged.
            $this->model->finish($id, $owner, 'uncertain', [
                'error' => get_class($e),
                'message' => 'Operation not verified; inspect remote host state and reconcile.',
            ]);
        }
        return true;
    }
    function perform(Job_context $context): array {
        $action = match ($context->kind) {
            'build' => 'apps/_build',
            'deploy', 'rollback' => 'deployments/_run',
            'canary-weight', 'canary-promote', 'canary-abort' => 'deployments/_canary',
            'destroy-app' => 'deployments/_destroy',
            'deploy-service' => 'services/_deploy',
            'destroy-service' => 'services/_destroy',
            'registry-init-repo' => 'harbour/_init_repo',
            'registry-publish' => 'harbour/_publish',
            'registry-yank' => 'harbour/_yank',
            default => throw new RuntimeException('Unsupported job kind'),
        };
        return Modules::run($action, $context);
    }
}