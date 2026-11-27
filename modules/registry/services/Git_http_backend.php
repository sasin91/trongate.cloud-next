<?php
declare(strict_types=1);

/**
 * Minimal, unprivileged Smart HTTP Git backend wrapper for self-hosted author repos.
 *
 * Serves `git-upload-pack` (public read/clone/subtree) and `git-receive-pack`
 * (authenticated per-namespace push via scoped registry tokens) without ever
 * invoking zfs(8) or privileged commands. Pushed semver tags (`refs/tags/vX.Y.Z`)
 * are extracted from the pkt-line header so the control plane can enqueue a
 * root-verified `registry-publish` job via `tc-runtime`.
 */
final class Git_http_backend {
    public const MAX_PUSH_BYTES = 32 * 1024 * 1024;
    public const DEFAULT_BACKEND_PATHS = [
        '/usr/local/libexec/git-core/git-http-backend',
        '/usr/libexec/git-core/git-http-backend',
        '/usr/lib/git-core/git-http-backend',
    ];

    /**
     * Parses a Smart HTTP Git path: `/git/<namespace>/<module>.git/<subpath>`.
     *
     * @return ?array{namespace: string, name: string, subpath: string}
     */
    public static function parse_path(string $path): ?array {
        if (preg_match(
            '~^/git/([a-z][a-z0-9-]{1,38})/([a-z][a-z0-9_]{0,39})\.git/(info/refs|git-upload-pack|git-receive-pack)$~D',
            $path,
            $m
        ) !== 1) {
            return null;
        }
        return [
            'namespace' => $m[1],
            'name' => $m[2],
            'subpath' => $m[3],
        ];
    }

    /**
     * Returns true when the Git HTTP request requires write authentication.
     */
    public static function requires_auth(string $subpath, string $query_string): bool {
        if ($subpath === 'git-receive-pack') {
            return true;
        }
        if ($subpath === 'info/refs') {
            parse_str($query_string, $qs);
            return ($qs['service'] ?? '') === 'git-receive-pack';
        }
        return false;
    }

    /**
     * Extracts a plaintext registry token from `Authorization: Bearer <token>` or
     * `Authorization: Basic base64(user:token)`.
     */
    public static function extract_token(array $server): ?string {
        $header = trim((string) ($server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
        if ($header === '') {
            if (!empty($server['PHP_AUTH_PW'])) {
                return trim((string) $server['PHP_AUTH_PW']);
            }
            if (!empty($server['PHP_AUTH_USER'])) {
                return trim((string) $server['PHP_AUTH_USER']);
            }
            return null;
        }
        if (stripos($header, 'Bearer ') === 0) {
            $token = trim(substr($header, 7));
            return $token !== '' ? $token : null;
        }
        if (stripos($header, 'Basic ') === 0) {
            $decoded = base64_decode(trim(substr($header, 6)), true);
            if (!is_string($decoded) || $decoded === '') {
                return null;
            }
            if (str_contains($decoded, ':')) {
                [$user, $pass] = explode(':', $decoded, 2);
                $candidate = trim($pass) !== '' ? trim($pass) : trim($user);
                return $candidate !== '' ? $candidate : null;
            }
            return trim($decoded);
        }
        return null;
    }

    /**
     * Extracts pushed semver tag versions (`refs/tags/v1.2.3` or `refs/tags/1.2.3`)
     * from a `git-receive-pack` pkt-line command block (before the PACK stream).
     *
     * @return list<array{ref: string, version: string, commit: string}>
     */
    public static function extract_pushed_tags(string $body): array {
        $tags = [];
        $offset = 0;
        $len = strlen($body);
        $zero = str_repeat('0', 40);

        while ($offset + 4 <= $len) {
            $hex = substr($body, $offset, 4);
            if (!ctype_xdigit($hex)) {
                break;
            }
            $pkt_len = (int) hexdec($hex);
            if ($pkt_len === 0) {
                break;
            }
            if ($pkt_len < 4 || $offset + $pkt_len > $len) {
                break;
            }
            $line = substr($body, $offset + 4, $pkt_len - 4);
            $offset += $pkt_len;

            $null_pos = strpos($line, "\0");
            if ($null_pos !== false) {
                $line = substr($line, 0, $null_pos);
            }
            $line = trim($line);
            if (preg_match('~^([a-f0-9]{40})\s+([a-f0-9]{40})\s+refs/tags/(v?([0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?))$~D', $line, $m) === 1) {
                if ($m[2] !== $zero) {
                    $tags[] = [
                        'ref' => $m[3],
                        'version' => $m[4],
                        'commit' => $m[2],
                    ];
                }
            }
        }
        return $tags;
    }

    public static function find_binary(): ?string {
        $env = trim((string) (getenv('TC_GIT_HTTP_BACKEND') ?: ''));
        if ($env !== '' && is_executable($env)) {
            return $env;
        }
        foreach (self::DEFAULT_BACKEND_PATHS as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }
        return null;
    }

    /**
     * Executes git-http-backend as a CGI subprocess against $repos_root.
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public static function execute(
        string $repos_root,
        string $namespace,
        string $name,
        string $subpath,
        string $method,
        string $query_string,
        string $content_type,
        string $body,
        ?string $remote_user = null,
    ): array {
        $binary = self::find_binary();
        if ($binary === null) {
            throw new RuntimeException('git-http-backend binary is not installed on this host.');
        }

        if (strlen($body) > self::MAX_PUSH_BYTES) {
            return [
                'status' => 413,
                'headers' => ['Content-Type' => 'text/plain; charset=utf-8'],
                'body' => 'Git push exceeds maximum size limit (32 MiB).',
            ];
        }

        $env = [
            'PATH' => '/usr/local/bin:/usr/bin:/bin',
            'GIT_PROJECT_ROOT' => $repos_root,
            'GIT_HTTP_EXPORT_ALL' => '1',
            'PATH_INFO' => '/' . $namespace . '/' . $name . '.git/' . $subpath,
            'REQUEST_METHOD' => strtoupper($method),
            'QUERY_STRING' => $query_string,
            'CONTENT_TYPE' => $content_type,
            'CONTENT_LENGTH' => (string) strlen($body),
        ];
        if ($remote_user !== null && $remote_user !== '') {
            $env['REMOTE_USER'] = $remote_user;
        }

        $result = (new Process())->run([$binary], $body, 120, null, $env);
        if ($result['exit'] !== 0 && $result['out'] === '') {
            throw new RuntimeException('Git backend failed.');
        }

        return self::parse_cgi_response($result['out']);
    }

    /**
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public static function parse_cgi_response(string $raw): array {
        $sep = strpos($raw, "\r\n\r\n");
        $sep_len = 4;
        if ($sep === false) {
            $sep = strpos($raw, "\n\n");
            $sep_len = 2;
        }
        if ($sep === false) {
            return ['status' => 200, 'headers' => [], 'body' => $raw];
        }

        $head = substr($raw, 0, $sep);
        $body = substr($raw, $sep + $sep_len);
        $status = 200;
        $headers = [];
        foreach (preg_split('/\r?\n/', $head) ?: [] as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$k, $v] = explode(':', $line, 2);
            $k = trim($k);
            $v = trim($v);
            if (strcasecmp($k, 'Status') === 0) {
                $status = (int) $v;
                continue;
            }
            $headers[$k] = $v;
        }
        return ['status' => $status, 'headers' => $headers, 'body' => $body];
    }
}
