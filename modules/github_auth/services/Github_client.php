<?php
declare(strict_types=1);

/**
 * GitHub REST API client using one token (user OAuth token, installation token,
 * or empty string for anonymous public repository reads).
 *
 * All HTTPS transport passes through engine/Http_client.php.
 */
final class Github_client {
    public const API_BASE = 'https://api.github.com';
    public const MAX_REPOSITORY_PAGES = 5;

    private Http_client $http;

    public function __construct(private string $token = '', ?Http_client $http = null) {
        $this->http = $http ?? new Http_client();
    }

    public static function anonymous(?Http_client $http = null): self {
        return new self('', $http);
    }

    public function has_token(): bool {
        return $this->token !== '';
    }

    /** The authenticated GitHub user: id, login, name, etc. */
    public function user(): array {
        return $this->request('GET', '/user');
    }

    /** The user's verified primary email address, or null. */
    public function primary_email(): ?string {
        $emails = $this->request('GET', '/user/emails?per_page=100');
        foreach ($emails as $email) {
            if (is_array($email) && !empty($email['primary']) && !empty($email['verified']) && is_string($email['email'] ?? null)) {
                return $email['email'];
            }
        }
        return null;
    }

    /** Whether the user has at least one installation of the GitHub App. */
    public function has_installation(): bool {
        $response = $this->request('GET', '/user/installations?per_page=1');
        return (int) ($response['total_count'] ?? 0) > 0;
    }

    /**
     * Repositories accessible across the user's GitHub App installations.
     *
     * @return list<array{id: string, name: string, full_name: string, url: string, private: bool, default_branch: string}>
     */
    public function list_repositories(): array {
        $installations = $this->request('GET', '/user/installations?per_page=100')['installations'] ?? [];
        if (!is_array($installations)) {
            return [];
        }

        $repos = [];
        foreach ($installations as $installation) {
            $id = (int) ($installation['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            for ($page = 1; $page <= self::MAX_REPOSITORY_PAGES; $page++) {
                $response = $this->request('GET', '/user/installations/' . $id . '/repositories?per_page=100&page=' . $page);
                $items = is_array($response['repositories'] ?? null) ? $response['repositories'] : [];
                foreach ($items as $repo) {
                    if (!is_array($repo) || empty($repo['full_name'])) {
                        continue;
                    }
                    $full_name = (string) $repo['full_name'];
                    $repos[strtolower($full_name)] = [
                        'id' => (string) ($repo['id'] ?? ''),
                        'name' => (string) ($repo['name'] ?? ''),
                        'full_name' => $full_name,
                        'url' => (string) ($repo['html_url'] ?? ('https://github.com/' . $full_name)),
                        'private' => !empty($repo['private']),
                        'default_branch' => (string) ($repo['default_branch'] ?? 'main'),
                    ];
                }
                if (count($items) < 100) {
                    break;
                }
            }
        }

        uasort($repos, static fn(array $a, array $b): int => strcasecmp($a['full_name'], $b['full_name']));
        return array_values($repos);
    }

    public function can_pick(string $repo): bool {
        foreach ($this->list_repositories() as $candidate) {
            if (strcasecmp($candidate['full_name'], $repo) === 0) {
                return true;
            }
        }
        return false;
    }

    public function repository(string $repo): array {
        return $this->request('GET', '/repos/' . $repo);
    }

    /**
     * @return list<array{name: string, commit: array{sha: string}}>
     */
    public function list_branches(string $repo, int $per_page = 30): array {
        $per_page = max(1, min(100, $per_page));
        $rows = $this->request('GET', '/repos/' . $repo . '/branches?per_page=' . $per_page);
        return array_is_list($rows) ? $rows : [];
    }

    public function commit(string $repo, string $ref): array {
        return $this->request('GET', '/repos/' . $repo . '/commits/' . rawurlencode($ref));
    }

    public function commit_pulls(string $repo, string $sha): array {
        $rows = $this->request('GET', '/repos/' . $repo . '/commits/' . rawurlencode($sha) . '/pulls');
        return array_is_list($rows) ? $rows : [];
    }

    public function contents(string $repo, string $path = '', string $ref = ''): array {
        $clean_path = trim($path, '/');
        $endpoint = '/repos/' . $repo . '/contents'
            . ($clean_path !== '' ? '/' . str_replace('%2F', '/', rawurlencode($clean_path)) : '')
            . ($ref !== '' ? '?ref=' . rawurlencode($ref) : '');
        return $this->request('GET', $endpoint);
    }

    public function repo_path(string $repo_relative_path): array {
        return $this->request('GET', '/repos/' . ltrim($repo_relative_path, '/'));
    }

    public function request(string $method, string $endpoint, ?array $data = null): array {
        $headers = [
            'Accept' => 'application/vnd.github+json',
            'User-Agent' => 'TrongateCloud/2.0',
            'X-GitHub-Api-Version' => '2022-11-28',
        ];
        if ($this->token !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->token;
        }
        $body = null;
        if ($data !== null) {
            $headers['Content-Type'] = 'application/json';
            $body = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }

        $response = $this->http->request($method, self::API_BASE . $endpoint, $headers, $body, 5, 15);
        $decoded = json_decode($response['body'], true);

        if ($response['status'] < 200 || $response['status'] >= 300) {
            $message = is_array($decoded) && is_string($decoded['message'] ?? null)
                ? $decoded['message']
                : 'GitHub API request failed.';
            throw new Github_api_error($message, $response['status']);
        }

        return is_array($decoded) ? $decoded : [];
    }
}
