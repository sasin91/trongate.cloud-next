<?php
declare(strict_types=1);

/**
 * GitHub App OAuth connection, sign-in, installation Setup URL, and scoped API clients.
 *
 *   GET  /auth/github            start: sign in with GitHub or connect GitHub to the signed-in account
 *   GET  /auth/github/callback   GitHub OAuth callback (?code&state)
 *   GET  /auth/github/installed  GitHub App Setup URL after installing or updating repository access
 *   POST /auth/github/disconnect Disconnect GitHub from the signed-in account (auth + CSRF)
 *
 * User access tokens are stored encrypted in credentials (github-user-{id}) solely to
 * list repositories the user may pick. Builds and push webhooks mint repository-scoped
 * installation tokens via Github_app::repository_token() instead.
 */
require_once APPPATH . 'modules/login/github/services/Github_app.php';
require_once APPPATH . 'modules/login/github/services/Github_client.php';

class Github extends Trongate {
    function make_sure_allowed(): void {
        block_url('login-github/make_sure_allowed');
        $this->workspace->_actor();
    }

    public const STATE_COOKIE = 'tc_github_state';
    public const RETURN_COOKIE = 'tc_github_return';
    public const STATE_TTL_SECONDS = 600;
    public const DEFAULT_RETURN = 'apps/create';

    function start(): void {
        $app = $this->_app();
        $return_path = $this->_safe_return((string) ($_GET['then'] ?? ''));

        $nonce = bin2hex(random_bytes(16));
        $this->_set_cookie(self::STATE_COOKIE, $nonce);
        $this->_set_cookie(self::RETURN_COOKIE, $return_path);

        $this->_redirect_to($app->authorize_url($this->_sign_state($nonce), self::callback_url()));
    }

    function callback(): void {
        $state_ok = $this->_verify_state(
            (string) ($_GET['state'] ?? ''),
            (string) ($_COOKIE[self::STATE_COOKIE] ?? '')
        );
        $this->_set_cookie(self::STATE_COOKIE, '', -1);

        if (!$state_ok) {
            $this->_fail('That GitHub sign-in link has expired. Please try again.');
        }

        $code = (string) ($_GET['code'] ?? '');
        if (isset($_GET['error']) || $code === '') {
            $this->_fail('GitHub connection was cancelled.');
        }

        $app = $this->_app();
        try {
            $token = $app->exchange_code($code, self::callback_url());
            $github = new Github_client($token['token'], $this->http);
            $user = $github->user();
        } catch (Throwable) {
            $this->_fail('GitHub did not let us sign you in. Please try again.');
        }

        $github_user_id = (int) ($user['id'] ?? 0);
        $github_login = (string) ($user['login'] ?? '');
        $github_name = (string) ($user['name'] ?? '');
        if ($github_user_id <= 0) {
            $this->_fail('GitHub did not tell us who you are. Please try again.');
        }

        $current_user_id = $this->_current_user_id();
        $linked = $this->model->_find_link_by_github_id($github_user_id);

        if ($current_user_id > 0) {
            // Connecting GitHub to the already signed-in control-plane account.
            if ($linked !== null && (int) $linked['user_id'] !== $current_user_id) {
                $this->_fail('That GitHub account is already linked to another trongate.cloud account.');
            }
            $target_user_id = $current_user_id;
            $target_level = 0;
        } elseif ($linked !== null) {
            $local = $this->model->_resolve_local_user((int) $linked['user_id']);
            if ($local === null || !$local['active']) {
                $this->_fail('This account is disabled.');
            }
            $target_user_id = $local['user_id'];
            $target_level = $local['level'];
        } else {
            // Sign up a new scoped account with the GitHub account's verified primary email.
            $created = $this->_sign_up($github, $github_user_id, $github_login, $github_name);
            $target_user_id = $created['user_id'];
            $target_level = $created['level'];
        }

        try {
            $this->model->_link_user($target_user_id, $github_user_id, $github_login);
            $this->model->_save_token($target_user_id, $token);
        } catch (RuntimeException $e) {
            $this->_fail($e->getMessage());
        }

        if ($current_user_id === 0) {
            $this->model->_log_in_user($target_user_id, $target_level);
        }

        // If the GitHub App is not installed on any repositories yet, send the user to install it.
        if ($app->slug() !== '' && !$this->_has_installation($github)) {
            $this->_redirect_to($app->install_url());
        }

        $this->_set_flash('Connected GitHub as @' . $github_login . '.');
        $this->_return();
    }

    /**
     * Creates a new passwordless account for an unlinked GitHub user with a verified primary email.
     * Refuses to take over an existing password account that already uses that email.
     *
     * @return array{user_id: int, member_id: int, level: int}
     */
    function _sign_up(Github_client $github, int $github_user_id, string $github_login, string $github_name = ''): array {
        try {
            $email = $github->primary_email();
        } catch (Throwable) {
            $email = null;
        }

        if ($email === null || strlen($email) > 190) {
            $this->_fail('Your GitHub account needs a verified primary email address to sign up.');
        }

        if ($this->model->_email_exists($email)) {
            $this->_fail('An account with ' . $email . ' already exists. Sign in with your password, then connect GitHub.');
        }

        try {
            return $this->model->_create_github_account($email, $github_name, $github_user_id, $github_login);
        } catch (RuntimeException $e) {
            $this->_fail($e->getMessage());
        }
    }

    /**
     * GitHub App Setup URL: GitHub redirects here after installing the App or
     * updating which repositories the App can access.
     */
    function installed(): void {
        if ($this->_current_user_id() <= 0) {
            $this->_redirect_to('/login/login/tg-team');
        }
        $this->_set_flash('GitHub repository access updated.');
        $this->_return();
    }

    function disconnect(): void {
        $this->workspace->_actor();
        $this->trongate_security->_csrf();
        $user_id = $this->_current_user_id();
        if ($user_id > 0) {
            $this->model->_unlink_user($user_id);
        }
        $this->_set_flash('Disconnected GitHub from your account.');
        $return_path = $this->_safe_return((string) ($_POST['then'] ?? ''));
        $this->_redirect_to('/' . $return_path);
    }

    public static function callback_url(): string {
        return rtrim(BASE_URL, '/') . '/auth/github/callback';
    }

    // --- Internal HMVC helpers (blocked from URL dispatch by Core) -----------

    function _app(): Github_app {
        return Github_app::from_config();
    }

    /**
     * Returns a GitHub API client authenticated as the signed-in user,
     * transparently refreshing expired user access tokens.
     */
    function _client_for_user(int $user_id): ?Github_client {
        if ($user_id <= 0) {
            return null;
        }
        $token = $this->model->_load_token($user_id);
        if ($token === null || empty($token['token']) || !is_string($token['token'])) {
            return null;
        }

        if (!empty($token['expires_at']) && (int) $token['expires_at'] < time() + 60) {
            $token = $this->_refresh_user_token($user_id, $token);
            if ($token === null || empty($token['token'])) {
                return null;
            }
        }

        return new Github_client((string) $token['token'], $this->http);
    }

    /**
     * Mints a short-lived repository-scoped installation token when the GitHub App
     * is installed on $repository; returns null when it is not installed (404).
     */
    function _installation_token(string $repository, array $permissions = ['contents' => 'read']): ?string {
        $app = $this->_app();
        try {
            return $app->repository_token($repository, $permissions);
        } catch (Github_api_error $e) {
            if ($e->getCode() === 404) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * Resolves the best GitHub API client for reading $repository:
     * 1. GitHub App installation token (works for private & public repos, independent of user session)
     * 2. Signed-in user's OAuth token (if connected)
     * 3. Anonymous client (public repositories only)
     *
     * @return array{client: Github_client, source: string, token: string}
     */
    function _client_for_repository(string $repository, ?int $user_id = null, array $permissions = ['contents' => 'read']): array {
        try {
            $installation_token = $this->_installation_token($repository, $permissions);
            if ($installation_token !== null && $installation_token !== '') {
                return [
                    'client' => new Github_client($installation_token, $this->http),
                    'source' => 'installation',
                    'token' => $installation_token,
                ];
            }
        } catch (Throwable) {
            // Fall through to user or anonymous client when installation lookup fails transiently.
        }

        if ($user_id !== null && $user_id > 0) {
            $user_client = $this->_client_for_user($user_id);
            if ($user_client !== null) {
                return [
                    'client' => $user_client,
                    'source' => 'user',
                    'token' => '',
                ];
            }
        }

        return [
            'client' => Github_client::anonymous($this->http),
            'source' => 'anonymous',
            'token' => '',
        ];
    }

    /**
     * Connection summary and accessible repositories for UI views (e.g. wizard, settings).
     *
     * @return array{connected: bool, github_login: string, install_url: string, connect_url: string, repositories: list<array>}
     */
    function _ui_context(int $user_id, string $return_path = self::DEFAULT_RETURN, bool $load_repositories = false): array {
        $app = $this->_app();
        $link = $user_id > 0 ? $this->model->_find_link_by_user_id($user_id) : null;
        $client = $user_id > 0 ? $this->_client_for_user($user_id) : null;
        $connected = $link !== null && $client !== null;
        $repositories = [];

        if ($connected && $load_repositories) {
            try {
                $repositories = $client->list_repositories();
            } catch (Github_api_error $e) {
                if ($e->getCode() === 401) {
                    $this->model->_delete_token($user_id);
                    $connected = false;
                }
            } catch (Throwable) {
                // Leave repositories empty if GitHub is momentarily unreachable.
            }
        }

        $safe_return = $this->_safe_return($return_path);
        return [
            'connected' => $connected,
            'github_login' => $link !== null ? (string) $link['github_login'] : '',
            'install_url' => $app->install_url(),
            'connect_url' => '/auth/github?then=' . rawurlencode($safe_return),
            'repositories' => $repositories,
        ];
    }

    function _refresh_user_token(int $user_id, array $token): ?array {
        $refresh_token = (string) ($token['refresh_token'] ?? '');
        if ($refresh_token === '') {
            $this->model->_delete_token($user_id);
            return null;
        }
        try {
            $fresh = $this->_app()->refresh($refresh_token);
            $this->model->_save_token($user_id, $fresh);
            return $fresh;
        } catch (Throwable) {
            $this->model->_delete_token($user_id);
            return null;
        }
    }

    function _has_installation(Github_client $github): bool {
        try {
            return $github->has_installation();
        } catch (Throwable) {
            return true;
        }
    }

    function _current_user_id(): int {
        $actor = $this->workspace->_optional_actor();
        return is_array($actor) ? (int) ($actor['user_id'] ?? 0) : 0;
    }

    function _sign_state(string $nonce): string {
        $payload = $nonce . '.' . (time() + self::STATE_TTL_SECONDS);
        return $payload . '.' . hash_hmac('sha256', 'github-state:' . $payload, $this->_state_key());
    }

    function _verify_state(string $state, string $cookie_nonce): bool {
        $parts = explode('.', $state);
        if (count($parts) !== 3 || $cookie_nonce === '') {
            return false;
        }
        [$nonce, $expires, $mac] = $parts;
        if (!ctype_digit($expires)) {
            return false;
        }
        $expected = hash_hmac('sha256', 'github-state:' . $nonce . '.' . $expires, $this->_state_key());
        return hash_equals($expected, $mac)
            && hash_equals($nonce, $cookie_nonce)
            && (int) $expires >= time();
    }

    function _state_key(): string {
        return installation_key();
    }

    function _safe_return(string $path): string {
        $trimmed = ltrim(trim($path), '/');
        return preg_match('#^[a-z0-9][a-z0-9/_-]{0,199}$#i', $trimmed) === 1
            ? $trimmed
            : self::DEFAULT_RETURN;
    }

    function _return_path(): string {
        return $this->_safe_return((string) ($_COOKIE[self::RETURN_COOKIE] ?? ''));
    }

    function _return(): never {
        $path = $this->_return_path();
        $this->_set_cookie(self::RETURN_COOKIE, '', -1);
        $this->_redirect_to('/' . $path);
    }

    function _fail(string $message): never {
        $this->_set_flash($message, true);
        if ($this->_current_user_id() > 0) {
            $path = $this->_return_path();
            $this->_set_cookie(self::RETURN_COOKIE, '', -1);
            $this->_redirect_to('/' . $path);
        }
        $this->_redirect_to('/login/login/tg-team');
    }

    function _set_flash(string $message, bool $is_error = false): void {
        $_SESSION[$is_error ? 'github_error' : 'github_flash'] = $message;
    }

    function _set_cookie(string $name, string $value, int $ttl = self::STATE_TTL_SECONDS): void {
        setcookie($name, $value, [
            'expires' => time() + $ttl,
            'path' => '/',
            'secure' => str_starts_with(BASE_URL, 'https://'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    function _redirect_to(string $location): never {
        header('Location: ' . $location, true, 302);
        exit;
    }
}
