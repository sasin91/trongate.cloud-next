--TEST--
GitHub App JWT/installation tokens, OAuth state/token refresh, encrypted account links, and builder token hygiene
--FILE--
<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/tests/inc/model_fixture.php';
require_once dirname(__DIR__, 3) . '/infra/runtime/builder.php';

function assert_ok(bool $cond, string $msg): void {
    if (!$cond) {
        throw new RuntimeException($msg);
    }
}

class Stub_github_http extends Http_client {
    public array $calls = [];
    /** @var callable */
    private $handler;

    public function __construct(callable $handler) {
        $this->handler = $handler;
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $connect_timeout = 15, int $timeout = 45): array {
        $this->calls[] = compact('method', 'url', 'headers', 'body');
        return ($this->handler)($method, $url, $headers, $body);
    }
}

$dir = sys_get_temp_dir() . '/tc-gh-auth-' . bin2hex(random_bytes(6));
mkdir($dir);
putenv('TC_STATE=' . $dir);
putenv('TC_DSN=sqlite:' . $dir . '/state.db');
file_put_contents($dir . '/key', Encryptor::generate_key());

try {
    // 1. Real RS256 JWT generation & OpenSSL verification.
    $cnf_path = $dir . '/openssl.cnf';
    file_put_contents($cnf_path, "[req]\ndistinguished_name = dn\n[dn]\n");
    $key_res = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
        'config' => $cnf_path,
    ]);
    assert_ok($key_res !== false, 'Could not generate test RSA key');
    openssl_pkey_export($key_res, $private_pem, null, ['config' => $cnf_path]);
    $pub_pem = openssl_pkey_get_details($key_res)['key'];

    $http = new Stub_github_http(static function (string $method, string $url, array $headers, ?string $body) use ($pub_pem): array {
        if ($url === 'https://github.com/login/oauth/access_token') {
            $req = json_decode((string) $body, true);
            if (($req['code'] ?? '') === 'good-code') {
                return ['status' => 200, 'body' => json_encode([
                    'access_token' => 'ghu_initial_token_12345',
                    'expires_in' => 28800,
                    'refresh_token' => 'ghr_refresh_12345',
                    'refresh_token_expires_in' => 15897600,
                ])];
            }
            if (($req['grant_type'] ?? '') === 'refresh_token' && ($req['refresh_token'] ?? '') === 'ghr_refresh_12345') {
                return ['status' => 200, 'body' => json_encode([
                    'access_token' => 'ghu_refreshed_token_67890',
                    'expires_in' => 28800,
                    'refresh_token' => 'ghr_refresh_new_67890',
                ])];
            }
            return ['status' => 200, 'body' => json_encode(['error' => 'bad_verification_code', 'error_description' => 'The code passed is incorrect or expired.'])];
        }
        if ($method === 'GET' && $url === 'https://api.github.com/repos/acme/private-app/installation') {
            // Verify RS256 JWT signature on Authorization header.
            $auth = (string) ($headers['Authorization'] ?? '');
            assert_ok(str_starts_with($auth, 'Bearer '), 'Missing Bearer JWT header');
            $jwt = substr($auth, 7);
            $parts = explode('.', $jwt);
            assert_ok(count($parts) === 3, 'Malformed JWT');
            $sig = base64_decode(strtr($parts[2], '-_', '+/'));
            $verified = openssl_verify($parts[0] . '.' . $parts[1], $sig, $pub_pem, OPENSSL_ALGO_SHA256);
            assert_ok($verified === 1, 'RS256 JWT signature did not verify');
            $claims = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
            assert_ok(($claims['iss'] ?? '') === 'Iv23liTestClient', 'JWT issuer mismatch');
            return ['status' => 200, 'body' => json_encode(['id' => 4242])];
        }
        if ($method === 'GET' && $url === 'https://api.github.com/repos/acme/uninstalled/installation') {
            return ['status' => 404, 'body' => json_encode(['message' => 'Not Found'])];
        }
        if ($method === 'POST' && $url === 'https://api.github.com/app/installations/4242/access_tokens') {
            $req = json_decode((string) $body, true);
            assert_ok(($req['repositories'] ?? []) === ['private-app'], 'Installation token not scoped to single repo');
            assert_ok(($req['permissions'] ?? []) === ['contents' => 'read'], 'Installation token permissions mismatch');
            return ['status' => 201, 'body' => json_encode(['token' => 'ghs_install_secret_token_999'])];
        }
        if ($method === 'GET' && $url === 'https://api.github.com/user/installations?per_page=100') {
            return ['status' => 200, 'body' => json_encode(['installations' => [['id' => 4242]]])];
        }
        if ($method === 'GET' && $url === 'https://api.github.com/user/installations/4242/repositories?per_page=100&page=1') {
            return ['status' => 200, 'body' => json_encode(['repositories' => [
                ['id' => 2, 'name' => 'zeta', 'full_name' => 'acme/zeta', 'html_url' => 'https://github.com/acme/zeta', 'private' => true, 'default_branch' => 'main'],
                ['id' => 1, 'name' => 'alpha', 'full_name' => 'acme/alpha', 'html_url' => 'https://github.com/acme/alpha', 'private' => false, 'default_branch' => 'develop'],
            ]])];
        }
        if ($method === 'GET' && $url === 'https://api.github.com/user/emails?per_page=100') {
            return ['status' => 200, 'body' => json_encode([
                ['email' => 'unverified@example.test', 'primary' => true, 'verified' => false],
                ['email' => 'dev@example.test', 'primary' => true, 'verified' => true],
            ])];
        }
        return ['status' => 404, 'body' => '{}'];
    });

    $app = new Github_app('Iv23liTestClient', 'client-secret', $private_pem, 'trongate-cloud', 'whsec', $http);
    assert_ok($app->can_sign_in(), 'App should be sign-in ready');
    assert_ok($app->can_mint_installation_tokens(), 'App should mint installation tokens');
    assert_ok(str_contains($app->authorize_url('state123', 'https://panel.test/auth/github/callback'), 'client_id=Iv23liTestClient'), 'Authorize URL invalid');
    assert_ok($app->install_url() === 'https://github.com/apps/trongate-cloud/installations/new', 'Install URL invalid');

    $oauth = $app->exchange_code('good-code', 'https://panel.test/auth/github/callback');
    assert_ok($oauth['token'] === 'ghu_initial_token_12345' && $oauth['refresh_token'] === 'ghr_refresh_12345', 'OAuth exchange mismatch');

    try {
        $app->exchange_code('bad-code', 'https://panel.test/auth/github/callback');
        throw new LogicException('Bad OAuth code accepted');
    } catch (Github_api_error $e) {
        assert_ok($e->getCode() === 401, 'Bad OAuth code must return 401');
    }

    $install_token = $app->repository_token('acme/private-app');
    assert_ok($install_token === 'ghs_install_secret_token_999', 'Installation token mismatch');

    try {
        $app->repository_token('acme/uninstalled');
        throw new LogicException('Uninstalled repo accepted');
    } catch (Github_api_error $e) {
        assert_ok($e->getCode() === 404, 'Uninstalled repo must return 404');
    }

    // 2. Github_client repo listing & email extraction.
    $client = new Github_client($oauth['token'], $http);
    $repos = $client->list_repositories();
    assert_ok(count($repos) === 2 && $repos[0]['full_name'] === 'acme/alpha' && $repos[1]['private'] === true, 'Repositories not sorted/hydrated');
    assert_ok($client->can_pick('ACME/ZETA'), 'can_pick must be case-insensitive');
    assert_ok(!$client->can_pick('acme/other'), 'can_pick accepted unlisted repo');
    assert_ok($client->primary_email() === 'dev@example.test', 'Primary verified email mismatch');

    // 3. Account link uniqueness, encrypted OAuth token storage, and automatic refresh.
    $pdo = new PDO('sqlite:' . $dir . '/state.db');
    (new Setup_model('setup', $pdo))->migrate();
    $gh_model = new Github_auth_model('github_auth', $pdo);

    $gh_model->_link_user(1, 9001, 'alice');
    $gh_model->_save_token(1, [
        'token' => 'ghu_expired_token',
        'expires_at' => time() - 10,
        'refresh_token' => 'ghr_refresh_12345',
    ]);

    $raw_cipher = (string) $pdo->query("SELECT ciphertext FROM credentials WHERE id='github-user-1'")->fetchColumn();
    assert_ok($raw_cipher !== '' && !str_contains($raw_cipher, 'ghu_expired_token') && !str_contains($raw_cipher, 'ghr_refresh_12345'), 'GitHub OAuth token stored unencrypted');

    try {
        $gh_model->_link_user(2, 9001, 'alice');
        throw new LogicException('Duplicate GitHub account link accepted');
    } catch (RuntimeException $e) {
        assert_ok(str_contains($e->getMessage(), 'already linked'), 'Duplicate link error mismatch');
    }

    putenv('GITHUB_APP_CLIENT_ID=Iv23liTestClient');
    putenv('GITHUB_APP_CLIENT_SECRET=client-secret');
    putenv('GITHUB_APP_SLUG=trongate-cloud');

    $gh_ctrl = new Github_auth('github_auth');
    $gh_ctrl->_share_database(static fn() => $pdo);
    $gh_ctrl->_use_http_client($http);

    $refreshed_client = $gh_ctrl->_client_for_user(1);
    assert_ok($refreshed_client !== null, 'Expired user token should auto-refresh');
    $stored_after = $gh_model->_load_token(1);
    assert_ok(($stored_after['token'] ?? '') === 'ghu_refreshed_token_67890', 'Refreshed token not persisted');

    // 4. OAuth state HMAC & safe return path guards.
    $nonce = bin2hex(random_bytes(16));
    $state = $gh_ctrl->_sign_state($nonce);
    assert_ok($gh_ctrl->_verify_state($state, $nonce), 'Valid state rejected');
    assert_ok(!$gh_ctrl->_verify_state($state, 'wrong-nonce'), 'Wrong cookie nonce accepted');
    assert_ok(!$gh_ctrl->_verify_state($state . 'x', $nonce), 'Tampered state accepted');
    assert_ok($gh_ctrl->_safe_return('apps/create') === 'apps/create', 'Valid return path rejected');
    assert_ok($gh_ctrl->_safe_return('https://evil.test/steal') === Github_auth::DEFAULT_RETURN, 'External return URL accepted');
    assert_ok($gh_ctrl->_safe_return('//evil.test') === Github_auth::DEFAULT_RETURN, 'Protocol-relative return URL accepted');

    // 5. Self-signup via GitHub creates a passwordless scoped account with apps.create,
    //    refuses email takeover, and allows owning newly launched apps.
    $created = $gh_model->_create_github_account('newuser@example.test', 'New Dev', 9002, 'newdev');
    assert_ok($created['user_id'] > 0 && $created['member_id'] > 0 && $created['level'] === 2, 'GitHub self-signup account creation failed');
    $login_model = new Login_model('login', $pdo);
    assert_ok(!$login_model->validate_credentials('newuser@example.test', '', 2), 'Passwordless GitHub account must never log in with empty password');
    assert_ok(!$login_model->validate_credentials('newuser@example.test', 'anything', 2), 'Passwordless GitHub account must never log in with a password');
    $team_model = new Team_model('team', $pdo);
    $new_member = $team_model->_find_member($created['member_id']);
    assert_ok($new_member !== null && in_array('apps.create', $new_member['permissions']['workspace'], true), 'GitHub signup account missing apps.create permission');
    $new_actor = Access_policy::from_member($new_member);
    assert_ok(Access_policy::can_workspace($new_actor, 'apps.create'), 'New GitHub user cannot create apps');
    assert_ok(!Access_policy::can_workspace($new_actor, 'servers.manage'), 'New GitHub user must not manage servers');
    assert_ok(!Access_policy::can_app($new_actor, 'someone-elses-app', 'view'), 'New GitHub user must not see other apps');
    try {
        $gh_model->_create_github_account('newuser@example.test', 'Takeover', 9003, 'attacker');
        throw new LogicException('Duplicate email signup accepted');
    } catch (RuntimeException $e) {
        assert_ok(str_contains($e->getMessage(), 'already exists'), 'Duplicate email takeover error mismatch');
    }
    $team_model->_set_member_app_permissions($created['member_id'], 'my-first-app', Access_policy::APP_PRESETS['maintainer']['permissions']);
    $updated_actor = Access_policy::from_member($team_model->_find_member($created['member_id']));
    assert_ok(Access_policy::can_app($updated_actor, 'my-first-app', 'deploy') && Access_policy::can_app($updated_actor, 'my-first-app', 'settings'), 'Creator not granted maintainer access on launched app');

    // 6. Unlink clears both record and encrypted credentials.
    $gh_model->_unlink_user(1);
    assert_ok($gh_model->_find_link_by_user_id(1) === null && $gh_model->_load_token(1) === null, 'Unlink left stale state');

    // 7. Redaction & Builder token hygiene.
    assert_ok(!str_contains(Redact::text('error ghs_SecretInstallToken123456 end'), 'ghs_SecretInstallToken123456'), 'Redact leaked ghs_ token');
    assert_ok(!str_contains(Builder::sanitize_error('fatal: auth ghs_SecretInstallToken123456 failed'), 'ghs_SecretInstallToken123456'), 'Builder sanitize_error leaked ghs_ token');

    $builder = new class($dir . '/builds') extends Builder {
        public array $recorded = [];
        public string $seen_auth_content = '';
        public function free_space(): float {
            return 10 * 1024 * 1024 * 1024;
        }
        public function run(array $args, int $timeout = 1800): string {
            $this->recorded[] = $args;
            foreach ($args as $arg) {
                if (is_string($arg) && str_starts_with($arg, 'include.path=')) {
                    $path = substr($arg, strlen('include.path='));
                    $this->seen_auth_content = (string) file_get_contents($path);
                }
            }
            if (($args[0] ?? '') === 'git' && ($args[1] ?? '') === 'init') {
                mkdir($args[2] . '/public', 0755, true);
                mkdir($args[2] . '/config', 0755, true);
                file_put_contents($args[2] . '/public/index.php', '<?php echo "ok";');
                file_put_contents($args[2] . '/config/config.php', '<?php define("BASE_URL", "http://localhost/");');
            }
            if (($args[0] ?? '') === 'git' && in_array('rev-parse', $args, true)) {
                return str_repeat('a', 40);
            }
            return '';
        }
        public function build_in_jail(string $operation, string $app, string $source, string $php_version, array $extensions, string $commands): void {
        }
    };
    $res = $builder->build([
        'operation' => str_repeat('1', 32),
        'app' => 'priv-demo',
        'repository' => 'acme/private-app',
        'commit' => str_repeat('a', 40),
        'php_version' => '8.4',
        'github_token' => 'ghs_SuperSecretInstallToken999',
    ]);
    assert_ok(($res['status'] ?? '') === 'succeeded', 'Authenticated builder run failed');
    $expected_b64 = base64_encode('x-access-token:ghs_SuperSecretInstallToken999');
    assert_ok(str_contains($builder->seen_auth_content, 'https://github.com/') && str_contains($builder->seen_auth_content, $expected_b64), 'Builder did not write scoped git auth header');
    assert_ok(!str_contains(json_encode($builder->recorded), 'ghs_SuperSecretInstallToken999'), 'GitHub token leaked into process arguments');
    assert_ok(!str_contains((string) file_get_contents($dir . '/builds/' . str_repeat('1', 32) . '.json'), 'ghs_SuperSecretInstallToken999'), 'GitHub token leaked into build journal');

    echo "PASS: GitHub App JWT, OAuth refresh, encrypted links, and builder token hygiene\n";
} finally {
    unset($gh_ctrl, $gh_model, $pdo);
    $rm = static function (string $path) use (&$rm): void {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_link($path) || !is_dir($path)) {
            @unlink($path);
            return;
        }
        foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $item) {
            $rm($item->getPathname());
        }
        @rmdir($path);
    };
    $rm($dir);
}
?>
--EXPECT--
PASS: GitHub App JWT, OAuth refresh, encrypted links, and builder token hygiene
