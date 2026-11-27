<?php
declare(strict_types=1);

require_once APPPATH . 'modules/trongate_security/traits/Encrypted_credentials.php';

/**
 * Persistence for linked GitHub accounts (records kind='github-link')
 * and encrypted OAuth user tokens (credentials id='github-user-{trongate_user_id}').
 */
class Github_auth_model extends Model {
    use Encrypted_credentials;

    public const RECORD_KIND = 'github-link';
    public const CREDENTIAL_PREFIX = 'github-user-';
    public const LOGIN_REMEMBER_DAYS = 30;

    function __construct(?string $module_name = null, PDO|Closure|null $connection = null) {
        parent::__construct($module_name, $connection);
        $this->module('records');
    }

    function _find_link_by_user_id(int $user_id): ?array {
        if ($user_id <= 0) {
            return null;
        }
        $row = $this->records->_find(['kind' => self::RECORD_KIND, 'id' => (string) $user_id]);
        if ($row === null) {
            return null;
        }
        return [
            'user_id' => $user_id,
            'github_user_id' => (int) ($row['github_user_id'] ?? 0),
            'github_login' => (string) ($row['github_login'] ?? ''),
            'connected_at' => (int) ($row['connected_at'] ?? 0),
        ];
    }

    function _find_link_by_github_id(int $github_user_id): ?array {
        if ($github_user_id <= 0) {
            return null;
        }
        foreach ($this->records->_all(self::RECORD_KIND) as $row) {
            if ((int) ($row['github_user_id'] ?? 0) === $github_user_id) {
                return [
                    'user_id' => (int) ($row['id'] ?? 0),
                    'github_user_id' => $github_user_id,
                    'github_login' => (string) ($row['github_login'] ?? ''),
                    'connected_at' => (int) ($row['connected_at'] ?? 0),
                ];
            }
        }
        return null;
    }

    function _link_user(int $user_id, int $github_user_id, string $github_login): void {
        if ($user_id <= 0 || $github_user_id <= 0) {
            throw new RuntimeException('Invalid account link.');
        }
        $existing = $this->_find_link_by_github_id($github_user_id);
        if ($existing !== null && (int) $existing['user_id'] !== $user_id) {
            throw new RuntimeException('That GitHub account is already linked to another trongate.cloud account.');
        }
        $this->records->_put([
            'kind' => self::RECORD_KIND,
            'id' => (string) $user_id,
            'data' => [
                'github_user_id' => $github_user_id,
                'github_login' => substr(trim($github_login), 0, 100),
                'connected_at' => time(),
            ],
        ]);
    }

    function _unlink_user(int $user_id): void {
        if ($user_id <= 0) {
            return;
        }
        $this->records->_delete(['kind' => self::RECORD_KIND, 'id' => (string) $user_id]);
        $this->_delete_token($user_id);
    }

    function _save_token(int $user_id, array $token): void {
        $this->secret(
            self::CREDENTIAL_PREFIX . $user_id,
            json_encode($token, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
        );
    }

    function _load_token(int $user_id): ?array {
        if ($user_id <= 0) {
            return null;
        }
        try {
            $raw = $this->secret(self::CREDENTIAL_PREFIX . $user_id);
        } catch (RuntimeException) {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    function _delete_token(int $user_id): void {
        if ($user_id <= 0) {
            return;
        }
        $this->db->query_bind(
            'DELETE FROM credentials WHERE id = ?',
            [self::CREDENTIAL_PREFIX . $user_id],
            'statement'
        );
    }

    function _email_exists(string $email): bool {
        $normalized = strtolower(trim($email));
        if ($normalized === '') {
            return false;
        }
        $member = $this->db->query_bind(
            'SELECT 1 FROM team_members WHERE LOWER(email) = ? LIMIT 1',
            [$normalized],
            'statement'
        )->fetchColumn();
        if ($member !== false) {
            return true;
        }
        $admin = $this->db->query_bind(
            'SELECT 1 FROM trongate_administrators WHERE LOWER(username) = ? LIMIT 1',
            [$normalized],
            'statement'
        )->fetchColumn();
        return $admin !== false;
    }

    /**
     * Creates a passwordless customer account (team_members level 2 with apps.create
     * workspace permission) and links its GitHub account atomically.
     * Password is stored as '' so password login (Login_model::validate_credentials)
     * never signs this account in — only GitHub OAuth does.
     *
     * @return array{user_id: int, member_id: int, level: int}
     */
    function _create_github_account(string $email, string $name, int $github_user_id, string $github_login): array {
        $clean_email = strtolower(trim($email));
        if ($clean_email === '' || strlen($clean_email) > 190 || !filter_var($clean_email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Your GitHub account needs a valid verified primary email address to sign up.');
        }
        $display_name = trim(substr($name !== '' ? $name : $github_login, 0, 120));
        if ($display_name === '') {
            $display_name = strstr($clean_email, '@', true) ?: $clean_email;
        }
        $permissions = Access_policy::normalize_permissions([
            'workspace' => ['apps.create'],
            'all_apps' => [],
            'apps' => [],
        ], 'member', 'scoped');

        $now = time();
        $this->db->connection()->beginTransaction();
        try {
            if ($this->_email_exists($clean_email)) {
                throw new RuntimeException('An account with ' . $clean_email . ' already exists. Sign in with your password, then connect GitHub.');
            }
            $existing = $this->_find_link_by_github_id($github_user_id);
            if ($existing !== null) {
                throw new RuntimeException('That GitHub account is already linked to another trongate.cloud account.');
            }

            $this->db->query_bind(
                'INSERT INTO trongate_users(code, user_level_id) VALUES(?, 2)',
                [bin2hex(random_bytes(16))],
                'statement'
            );
            $trongate_user_id = (int) $this->db->connection()->lastInsertId();

            $this->db->query_bind(
                'INSERT INTO team_members(email, name, password, trongate_user_id, role, scope, permissions, active, created_at, last_login) VALUES(?, ?, ?, ?, ?, ?, ?, 1, ?, ?)',
                [
                    $clean_email,
                    $display_name,
                    '',
                    $trongate_user_id,
                    'member',
                    'scoped',
                    json_encode($permissions, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    $now,
                    $now,
                ],
                'statement'
            );
            $member_id = (int) $this->db->connection()->lastInsertId();

            $this->records->_put([
                'kind' => self::RECORD_KIND,
                'id' => (string) $trongate_user_id,
                'data' => [
                    'github_user_id' => $github_user_id,
                    'github_login' => substr(trim($github_login), 0, 100),
                    'connected_at' => $now,
                ],
            ]);

            $this->db->connection()->commit();
            return ['user_id' => $trongate_user_id, 'member_id' => $member_id, 'level' => 2];
        } catch (Throwable $e) {
            if ($this->db->connection()->inTransaction()) {
                $this->db->connection()->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Resolves an active local control-plane account (administrator or team member) by trongate_user_id.
     *
     * @return array{user_id: int, level: int, active: bool}|null
     */
    function _resolve_local_user(int $user_id): ?array {
        if ($user_id <= 0) {
            return null;
        }
        $user = $this->db->query_bind(
            'SELECT id, user_level_id FROM trongate_users WHERE id = ? LIMIT 1',
            [$user_id],
            'statement'
        )->fetch();
        if (!is_array($user)) {
            return null;
        }
        $level = (int) ($user['user_level_id'] ?? 0);
        if ($level === 1) {
            $admin = $this->db->query_bind(
                'SELECT id FROM trongate_administrators WHERE trongate_user_id = ? LIMIT 1',
                [$user_id],
                'statement'
            )->fetch();
            if (!is_array($admin)) {
                return null;
            }
            return ['user_id' => $user_id, 'level' => 1, 'active' => true];
        }
        if ($level === 2) {
            $member = $this->db->query_bind(
                'SELECT id, active FROM team_members WHERE trongate_user_id = ? LIMIT 1',
                [$user_id],
                'statement'
            )->fetch();
            if (!is_array($member)) {
                return null;
            }
            return ['user_id' => $user_id, 'level' => 2, 'active' => (int) ($member['active'] ?? 0) === 1];
        }
        return null;
    }

    function _log_in_user(int $user_id, int $level): string {
        $this->module('trongate_tokens');
        $this->trongate_tokens->destroy();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $token = $this->trongate_tokens->generate_token([
            'user_id' => $user_id,
            'expiry_date' => time() + (86400 * self::LOGIN_REMEMBER_DAYS),
        ]);
        $_SESSION['trongatetoken'] = $token;
        if ($level === 2) {
            $this->db->query_bind(
                'UPDATE team_members SET last_login = ? WHERE trongate_user_id = ?',
                [time(), $user_id],
                'statement'
            );
        }
        return $token;
    }
}
