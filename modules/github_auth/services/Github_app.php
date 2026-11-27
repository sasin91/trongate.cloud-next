<?php
declare(strict_types=1);

/**
 * The trongate.cloud GitHub App client.
 *
 * - As an OAuth client (client id + secret): authorize_url(), exchange_code(),
 *   and refresh() manage user access tokens for sign-in and repository picking.
 * - As the GitHub App (client id + private key via short-lived RS256 JWT):
 *   repository_token() mints installation tokens scoped to one repository for
 *   builds and push deploys, independent of any user session.
 *
 * All HTTPS transport passes through engine/Http_client.php.
 */
final class Github_app {
    public const API_BASE = 'https://api.github.com';
    public const OAUTH_BASE = 'https://github.com/login/oauth';

    /** JWT lifetime; GitHub allows at most 10 minutes. */
    public const JWT_TTL_SECONDS = 540;

    private Http_client $http;

    public function __construct(
        private string $client_id,
        private string $client_secret = '',
        private string $private_key = '',
        private string $slug = '',
        private string $webhook_secret = '',
        ?Http_client $http = null,
    ) {
        $this->http = $http ?? new Http_client();
    }

    public static function from_env(?Http_client $http = null): self {
        $client_id = trim((string) (getenv('GITHUB_APP_CLIENT_ID') ?: ''));
        $client_secret = trim((string) (getenv('GITHUB_APP_CLIENT_SECRET') ?: ''));
        $slug = trim((string) (getenv('GITHUB_APP_SLUG') ?: ''));
        $webhook_secret = trim((string) (getenv('GITHUB_APP_WEBHOOK_SECRET') ?: ''));

        $raw_key = (string) (getenv('GITHUB_APP_PRIVATE_KEY') ?: '');
        if (trim($raw_key) === '') {
            $key_file = trim((string) (getenv('GITHUB_APP_PRIVATE_KEY_FILE') ?: ''));
            if ($key_file !== '' && is_file($key_file) && is_readable($key_file)) {
                $raw_key = (string) file_get_contents($key_file);
            }
        }
        $private_key = str_replace(['\\r\\n', '\\n', "\r\n", "\r"], "\n", trim($raw_key));

        return new self($client_id, $client_secret, $private_key, $slug, $webhook_secret, $http);
    }

    /** Whether OAuth sign-in / GitHub account connection is configured. */
    public function can_sign_in(): bool {
        return $this->client_id !== '' && $this->client_secret !== '';
    }

    /** Whether the App can mint repository-scoped installation tokens. */
    public function can_mint_installation_tokens(): bool {
        return $this->client_id !== '' && $this->private_key !== '';
    }

    public function slug(): string {
        return $this->slug;
    }

    public function webhook_secret(): string {
        return $this->webhook_secret;
    }

    public function authorize_url(string $state, string $redirect_uri): string {
        return self::OAUTH_BASE . '/authorize?' . http_build_query([
            'client_id' => $this->client_id,
            'redirect_uri' => $redirect_uri,
            'state' => $state,
            'allow_signup' => 'true',
        ]);
    }

    /** Where a user installs or configures repository access for the App. */
    public function install_url(): string {
        if ($this->slug === '') {
            return '';
        }
        return 'https://github.com/apps/' . rawurlencode($this->slug) . '/installations/new';
    }

    /**
     * Exchanges an OAuth callback code for a user access token.
     *
     * @return array{token: string, expires_at: ?int, refresh_token: ?string, refresh_expires_at: ?int}
     */
    public function exchange_code(string $code, string $redirect_uri): array {
        return $this->oauth_token([
            'code' => $code,
            'redirect_uri' => $redirect_uri,
        ]);
    }

    /**
     * Renews an expired user access token using its refresh token.
     *
     * @return array{token: string, expires_at: ?int, refresh_token: ?string, refresh_expires_at: ?int}
     */
    public function refresh(string $refresh_token): array {
        return $this->oauth_token([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refresh_token,
        ]);
    }

    /**
     * Mints a short-lived installation token scoped to one repository.
     * Throws Github_api_error with code 404 when the App is not installed on $repo.
     *
     * @param string $repo owner/name
     * @param array<string, string> $permissions
     */
    public function repository_token(string $repo, array $permissions = ['contents' => 'read']): string {
        $repo = self::normalize_repo($repo);
        $installation = $this->app_request('GET', '/repos/' . $repo . '/installation');
        $installation_id = (int) ($installation['id'] ?? 0);
        if ($installation_id <= 0) {
            throw new Github_api_error('No GitHub App installation for ' . $repo, 404);
        }

        [, $repo_name] = explode('/', $repo, 2);
        $response = $this->app_request(
            'POST',
            '/app/installations/' . $installation_id . '/access_tokens',
            [
                'repositories' => [$repo_name],
                'permissions' => $permissions,
            ]
        );

        $token = (string) ($response['token'] ?? '');
        if ($token === '') {
            throw new Github_api_error('GitHub returned no installation token for ' . $repo, 500);
        }

        return $token;
    }

    /**
     * Signs an RS256 App JWT (issuer = client id).
     */
    public function jwt(): string {
        if ($this->client_id === '' || $this->private_key === '') {
            throw new RuntimeException('GITHUB_APP_CLIENT_ID and GITHUB_APP_PRIVATE_KEY are required to authenticate as the GitHub App.');
        }

        $now = time();
        $header = self::base64url(self::encode_json(['alg' => 'RS256', 'typ' => 'JWT']));
        // Backdate iat by 60 seconds for clock drift.
        $payload = self::base64url(self::encode_json([
            'iat' => $now - 60,
            'exp' => $now + self::JWT_TTL_SECONDS,
            'iss' => $this->client_id,
        ]));
        $signing_input = $header . '.' . $payload;

        $key = openssl_pkey_get_private($this->private_key);
        $signature = '';
        if ($key === false || !openssl_sign($signing_input, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('GITHUB_APP_PRIVATE_KEY is not a valid PEM private key.');
        }

        return $signing_input . '.' . self::base64url($signature);
    }

    public static function base64url(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * POST login/oauth/access_token. GitHub answers 200 with an "error" field on failure.
     *
     * @return array{token: string, expires_at: ?int, refresh_token: ?string, refresh_expires_at: ?int}
     */
    private function oauth_token(array $fields): array {
        if (!$this->can_sign_in()) {
            throw new RuntimeException('GITHUB_APP_CLIENT_ID and GITHUB_APP_CLIENT_SECRET are required for GitHub OAuth.');
        }

        $payload = self::encode_json([
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret,
        ] + $fields);

        $response = $this->http->request(
            'POST',
            self::OAUTH_BASE . '/access_token',
            [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'User-Agent' => 'TrongateCloud/2.0',
            ],
            $payload,
            10,
            20
        );

        $decoded = json_decode($response['body'], true);
        if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($decoded)) {
            throw new Github_api_error('GitHub OAuth token request failed.', $response['status']);
        }

        if (empty($decoded['access_token']) || !is_string($decoded['access_token'])) {
            $detail = (string) ($decoded['error_description'] ?? $decoded['error'] ?? 'no access token');
            throw new Github_api_error('GitHub sign-in failed: ' . $detail, 401);
        }

        $now = time();
        return [
            'token' => $decoded['access_token'],
            'expires_at' => isset($decoded['expires_in']) ? $now + (int) $decoded['expires_in'] : null,
            'refresh_token' => isset($decoded['refresh_token']) && is_string($decoded['refresh_token'])
                ? $decoded['refresh_token']
                : null,
            'refresh_expires_at' => isset($decoded['refresh_token_expires_in'])
                ? $now + (int) $decoded['refresh_token_expires_in']
                : null,
        ];
    }

    private function app_request(string $method, string $endpoint, ?array $data = null): array {
        $headers = [
            'Accept' => 'application/vnd.github+json',
            'Authorization' => 'Bearer ' . $this->jwt(),
            'User-Agent' => 'TrongateCloud/2.0',
            'X-GitHub-Api-Version' => '2022-11-28',
        ];
        $body = null;
        if ($data !== null) {
            $headers['Content-Type'] = 'application/json';
            $body = self::encode_json($data);
        }

        $response = $this->http->request($method, self::API_BASE . $endpoint, $headers, $body, 10, 20);
        $decoded = json_decode($response['body'], true);

        if ($response['status'] < 200 || $response['status'] >= 300) {
            $message = is_array($decoded) && is_string($decoded['message'] ?? null)
                ? $decoded['message']
                : 'GitHub App API request failed.';
            throw new Github_api_error($message, $response['status']);
        }

        return is_array($decoded) ? $decoded : [];
    }

    private static function normalize_repo(string $repo): string {
        $clean = trim($repo, "/ \t\n\r\0\x0B");
        if (!preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~D', $clean)) {
            throw new InvalidArgumentException('Invalid GitHub repository.');
        }
        return $clean;
    }

    private static function encode_json(array $value): string {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
