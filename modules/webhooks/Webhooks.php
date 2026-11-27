<?php
declare(strict_types=1);

/**
 * The GitHub App's webhook receiver: POST /webhooks/github.
 *
 * GitHub signs the raw request body with GITHUB_APP_WEBHOOK_SECRET in
 * X-Hub-Signature-256 for every repository the App is installed on. A signed
 * push event queues a build & deploy for every matching app on that repository
 * and branch that has Deploy on push enabled (app.auto_deploy = true).
 */
require_once APPPATH . 'modules/login/github/services/Github_app.php';

class Webhooks extends Trongate {
    /** GitHub caps webhook payloads at 25 MiB. */
    public const MAX_BODY_BYTES = 26214400;

    function github(): void {
        $secret = Github_app::from_config()->webhook_secret();
        $method = (string) ($_SERVER['REQUEST_METHOD'] ?? '');
        $signature = (string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
        $event = (string) ($_SERVER['HTTP_X_GITHUB_EVENT'] ?? '');
        $content_type = (string) ($_SERVER['CONTENT_TYPE'] ?? '');

        if ($method !== 'POST') {
            $this->_respond(405, ['error' => 'method_not_allowed']);
            return;
        }
        if ($secret === '') {
            $this->_respond(503, ['error' => 'not_configured']);
            return;
        }

        $body = (string) file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1);
        $response = $this->_process_github_request($method, $body, $signature, $event, $content_type, $secret);
        $this->_respond($response['status'], $response['body']);
    }

    /**
     * Pure request processor for POST /webhooks/github (used by both HTTP and tests).
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    function _process_github_request(
        string $method,
        string $body,
        string $signature,
        string $event,
        string $content_type,
        string $secret,
    ): array {
        if ($method !== 'POST') {
            return ['status' => 405, 'body' => ['error' => 'method_not_allowed']];
        }
        if ($secret === '') {
            return ['status' => 503, 'body' => ['error' => 'not_configured']];
        }
        if (strlen($body) > self::MAX_BODY_BYTES) {
            return ['status' => 413, 'body' => ['error' => 'payload_too_large']];
        }
        if (!$this->_valid_signature($body, $signature, $secret)) {
            return ['status' => 401, 'body' => ['error' => 'invalid_signature']];
        }

        $payload = $this->_decode_payload($body, $content_type);
        if ($payload === null) {
            return ['status' => 400, 'body' => ['error' => 'invalid_payload']];
        }

        if ($event === 'ping') {
            return ['status' => 200, 'body' => ['ok' => true, 'message' => 'pong']];
        }
        if ($event === 'push') {
            return $this->_handle_push($payload);
        }

        return ['status' => 202, 'body' => ['ok' => true, 'ignored' => 'event']];
    }

    /**
     * Queues a deploy for each app on the pushed repository and branch with
     * auto_deploy enabled. Ignores tags, branch deletions, and unmatched repos.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    function _handle_push(array $payload): array {
        $ref = (string) ($payload['ref'] ?? '');
        $sha = strtolower((string) ($payload['after'] ?? ''));
        $repository = $this->_repository_path($payload);

        if (!str_starts_with($ref, 'refs/heads/') || $repository === '') {
            return ['status' => 202, 'body' => ['ok' => true, 'ignored' => 'ref', 'ref' => $ref]];
        }
        if (!empty($payload['deleted']) || !preg_match('/^[0-9a-f]{40}$/D', $sha) || trim($sha, '0') === '') {
            return ['status' => 202, 'body' => ['ok' => true, 'ignored' => 'deleted_or_empty']];
        }

        $branch = substr($ref, strlen('refs/heads/'));
        $head_commit = is_array($payload['head_commit'] ?? null) ? $payload['head_commit'] : [];
        $pusher = is_array($payload['pusher'] ?? null) ? $payload['pusher'] : [];
        $canonical_repo = is_string($payload['repository']['full_name'] ?? null)
            && preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~D', $payload['repository']['full_name']) === 1
            ? $payload['repository']['full_name']
            : $repository;

        $deployments = [];
        $skipped = [];

        $this->module('apps');
        foreach ($this->model->_apps_on_branch($repository, $branch) as $app) {
            $app_id = (string) ($app['id'] ?? '');
            if ($app_id === '') {
                continue;
            }
            if (empty($app['auto_deploy'])) {
                $skipped[$app_id] = 'auto_deploy_off';
                continue;
            }
            $source = [
                'repository' => (string) ($app['repository'] ?? $canonical_repo),
                'branch' => $branch,
                'commit' => $sha,
                'message' => substr((string) ($head_commit['message'] ?? ''), 0, 8000),
                'author' => substr((string) ($head_commit['author']['name'] ?? $pusher['name'] ?? ''), 0, 200),
                'committed_at' => (string) ($head_commit['timestamp'] ?? gmdate('c')),
            ];
            try {
                $deployments[$app_id] = $this->apps->model->_queue_redeploy_latest($app_id, $source, false, 0, 'webhook');
            } catch (RuntimeException) {
                $skipped[$app_id] = 'not_deployable';
            }
        }

        if ($deployments === [] && $skipped === []) {
            return ['status' => 202, 'body' => ['ok' => true, 'ignored' => 'no_app']];
        }

        return [
            'status' => 202,
            'body' => [
                'ok' => true,
                'deployments' => $deployments,
                'skipped' => $skipped,
                'commit_sha' => $sha,
            ],
        ];
    }

    /** Constant-time check of "sha256=<hex hmac of raw body>". */
    function _valid_signature(string $body, string $header, string $secret): bool {
        if (!str_starts_with($header, 'sha256=')) {
            return false;
        }
        return hash_equals('sha256=' . hash_hmac('sha256', $body, $secret), $header);
    }

    /** JSON body, or form-encoded "payload" field when using application/x-www-form-urlencoded. */
    function _decode_payload(string $body, string $content_type): ?array {
        if (str_starts_with(strtolower($content_type), 'application/x-www-form-urlencoded')) {
            parse_str($body, $fields);
            $body = is_string($fields['payload'] ?? null) ? $fields['payload'] : '';
        }
        $payload = json_decode($body, true);
        return is_array($payload) ? $payload : null;
    }

    function _repository_path(array $payload): string {
        $repo = is_array($payload['repository'] ?? null) ? $payload['repository'] : [];
        $full_name = trim((string) ($repo['full_name'] ?? ''));
        if (preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~D', $full_name) === 1) {
            return strtolower($full_name);
        }
        $url = trim((string) ($repo['html_url'] ?? ''));
        if ($url !== '') {
            $path = (string) parse_url(rtrim($url, '/'), PHP_URL_PATH);
            $clean = strtolower((string) preg_replace('/\.git$/i', '', trim($path, '/')));
            if (preg_match('~^[a-z0-9_.-]+/[a-z0-9_.-]+$~D', $clean) === 1) {
                return $clean;
            }
        }
        return '';
    }

    function _respond(int $status, array $body): void {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
