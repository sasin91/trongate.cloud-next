<?php
declare(strict_types=1);

class Team_model extends Model {
    function _normalize_email(string $raw): string {
        $email = strtolower(trim($raw));
        if ($email === '' || strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Enter a valid email address.');
        }
        return $email;
    }

    function _administrators(): array {
        return $this->db->query_bind(
            'SELECT id, username, trongate_user_id FROM trongate_administrators ORDER BY id ASC',
            [],
            'statement'
        )->fetchAll();
    }

    function _administrator_by_user_id(int $user_id): ?array {
        $row = $this->db->query_bind(
            'SELECT id, username, trongate_user_id FROM trongate_administrators WHERE trongate_user_id = ? LIMIT 1',
            [$user_id],
            'statement'
        )->fetch();
        return is_array($row) ? $row : null;
    }

    function _members(): array {
        $rows = $this->db->query_bind(
            'SELECT id, email, name, trongate_user_id, role, scope, permissions, active, created_at, last_login FROM team_members ORDER BY created_at DESC, id DESC',
            [],
            'statement'
        )->fetchAll();
        foreach ($rows as &$row) {
            $row = $this->_hydrate_member($row);
        }
        unset($row);
        return $rows;
    }

    function _find_member(int $id): ?array {
        $row = $this->db->query_bind(
            'SELECT id, email, name, trongate_user_id, role, scope, permissions, active, created_at, last_login FROM team_members WHERE id = ? LIMIT 1',
            [$id],
            'statement'
        )->fetch();
        return is_array($row) ? $this->_hydrate_member($row) : null;
    }

    function _find_member_by_user_id(int $trongate_user_id): ?array {
        $row = $this->db->query_bind(
            'SELECT id, email, name, trongate_user_id, role, scope, permissions, active, created_at, last_login FROM team_members WHERE trongate_user_id = ? LIMIT 1',
            [$trongate_user_id],
            'statement'
        )->fetch();
        return is_array($row) ? $this->_hydrate_member($row) : null;
    }

    function _find_member_by_email(string $email): ?array {
        $row = $this->db->query_bind(
            'SELECT id, email, name, password, trongate_user_id, role, scope, permissions, active, created_at, last_login FROM team_members WHERE email = ? LIMIT 1',
            [strtolower(trim($email))],
            'statement'
        )->fetch();
        if (!is_array($row)) {
            return null;
        }
        $password = (string) ($row['password'] ?? '');
        $hydrated = $this->_hydrate_member($row);
        $hydrated['password'] = $password;
        return $hydrated;
    }

    function _touch_last_login(int $trongate_user_id): void {
        $this->db->query_bind(
            'UPDATE team_members SET last_login = ? WHERE trongate_user_id = ?',
            [time(), $trongate_user_id],
            'statement'
        );
    }

    function _pending_invitations(?string $app_id = null): array {
        $rows = $this->db->query_bind(
            'SELECT id, email, role, scope, permissions, note, invited_by, created_at, expires_at FROM team_invitations WHERE accepted_at = 0 AND revoked_at = 0 AND expires_at > ? ORDER BY created_at DESC',
            [time()],
            'statement'
        )->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            $hydrated = $this->_hydrate_invitation($row);
            if ($app_id !== null) {
                $applies = $hydrated['role'] === 'admin'
                    || ($hydrated['scope'] === 'all' && !empty($hydrated['permissions']['all_apps']))
                    || isset($hydrated['permissions']['apps'][$app_id]);
                if (!$applies) {
                    continue;
                }
            }
            $out[] = $hydrated;
        }
        return $out;
    }

    function _find_invitation(string $id): ?array {
        $row = $this->db->query_bind(
            'SELECT id, email, role, scope, permissions, note, invited_by, created_at, expires_at, accepted_at, revoked_at, accepted_member_id FROM team_invitations WHERE id = ? LIMIT 1',
            [$id],
            'statement'
        )->fetch();
        return is_array($row) ? $this->_hydrate_invitation($row) : null;
    }

    function _find_valid_invitation_by_token(string $token): ?array {
        $token = trim($token);
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
            return null;
        }
        $hash = hash('sha256', $token);
        $row = $this->db->query_bind(
            'SELECT id, email, role, scope, permissions, note, invited_by, created_at, expires_at, accepted_at, revoked_at, accepted_member_id FROM team_invitations WHERE token_hash = ? LIMIT 1',
            [$hash],
            'statement'
        )->fetch();
        if (!is_array($row)) {
            return null;
        }
        if ((int) $row['accepted_at'] > 0 || (int) $row['revoked_at'] > 0 || (int) $row['expires_at'] <= time()) {
            return null;
        }
        return $this->_hydrate_invitation($row);
    }

    /**
     * @return array{id: string, email: string, token: string, expires_at: int, role: string, scope: string, permissions: array, note: string, invited_by: string}
     */
    function _create_invitation(
        string $email,
        array $grant,
        string $note,
        string $invited_by,
        int $ttl_days = 7
    ): array {
        $clean_email = $this->_normalize_email($email);
        $days = max(1, min(30, $ttl_days));
        $note = trim(substr($note, 0, 500));
        $invited_by = trim(substr($invited_by, 0, 190));

        $id = bin2hex(random_bytes(16));
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $now = time();
        $expires_at = $now + ($days * 86400);

        $role = ($grant['role'] ?? 'member') === 'admin' ? 'admin' : 'member';
        $scope = ($grant['scope'] ?? 'scoped') === 'all' ? 'all' : 'scoped';
        $permissions = Access_policy::normalize_permissions(
            is_array($grant['permissions'] ?? null) ? $grant['permissions'] : [],
            $role,
            $scope
        );

        $this->db->query_bind(
            'INSERT INTO team_invitations(id, email, token_hash, role, scope, permissions, note, invited_by, created_at, expires_at, accepted_at, revoked_at, accepted_member_id) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0)',
            [$id, $clean_email, $hash, $role, $scope, self::_encode_json($permissions), $note, $invited_by, $now, $expires_at],
            'statement'
        );

        return [
            'id' => $id,
            'email' => $clean_email,
            'token' => $token,
            'expires_at' => $expires_at,
            'role' => $role,
            'scope' => $scope,
            'permissions' => $permissions,
            'note' => $note,
            'invited_by' => $invited_by,
        ];
    }

    /**
     * Rotates the invite token and extends expiry for a pending invitation.
     *
     * @return array{id: string, email: string, token: string, expires_at: int, role: string, scope: string, permissions: array, note: string, invited_by: string}
     */
    function _rotate_invitation(string $id, int $ttl_days = 7): array {
        $invite = $this->_find_invitation($id);
        if ($invite === null || (int) $invite['accepted_at'] > 0 || (int) $invite['revoked_at'] > 0) {
            throw new RuntimeException('Pending invitation not found.');
        }
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $expires_at = time() + (max(1, min(30, $ttl_days)) * 86400);

        $updated = $this->db->query_bind(
            'UPDATE team_invitations SET token_hash = ?, expires_at = ? WHERE id = ? AND accepted_at = 0 AND revoked_at = 0',
            [$hash, $expires_at, $id],
            'statement'
        )->rowCount();
        if ($updated !== 1) {
            throw new RuntimeException('Invitation is no longer pending.');
        }

        return [
            'id' => $id,
            'email' => (string) $invite['email'],
            'token' => $token,
            'expires_at' => $expires_at,
            'role' => (string) $invite['role'],
            'scope' => (string) $invite['scope'],
            'permissions' => $invite['permissions'],
            'note' => (string) $invite['note'],
            'invited_by' => (string) $invite['invited_by'],
        ];
    }

    function _revoke_invitation(string $id): void {
        $updated = $this->db->query_bind(
            'UPDATE team_invitations SET revoked_at = ? WHERE id = ? AND accepted_at = 0 AND revoked_at = 0',
            [time(), $id],
            'statement'
        )->rowCount();
        if ($updated !== 1) {
            throw new RuntimeException('Pending invitation not found.');
        }
    }

    /**
     * Atomically accepts an invitation, creating a new member or merging permissions into an existing one.
     *
     * @return array{member: array, invitation: array}
     */
    function _accept_invitation(
        string $token,
        string $name,
        ?string $password_hash,
        ?int $authenticated_member_id = null
    ): array {
        $token = trim($token);
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
            throw new RuntimeException('This invitation link is invalid or has expired.');
        }
        $hash = hash('sha256', $token);
        $now = time();

        $this->db->connection()->beginTransaction();
        try {
            $row = $this->db->query_bind(
                'SELECT id, email, role, scope, permissions, note, invited_by, created_at, expires_at, accepted_at, revoked_at, accepted_member_id FROM team_invitations WHERE token_hash = ? LIMIT 1',
                [$hash],
                'statement'
            )->fetch();
            if (!is_array($row) || (int) $row['accepted_at'] > 0 || (int) $row['revoked_at'] > 0 || (int) $row['expires_at'] <= $now) {
                throw new RuntimeException('This invitation link is invalid or has expired.');
            }
            $invite = $this->_hydrate_invitation($row);
            $email = (string) $invite['email'];

            $existing = $this->_find_member_by_email($email);
            if ($existing !== null) {
                if ($authenticated_member_id !== null && $authenticated_member_id !== (int) $existing['id']) {
                    throw new RuntimeException('This invitation is for ' . $email . '.');
                }
                $merged = Access_policy::merge_grant($existing, $invite);
                $this->db->query_bind(
                    'UPDATE team_members SET role = ?, scope = ?, permissions = ?, active = 1, last_login = ? WHERE id = ?',
                    [$merged['role'], $merged['scope'], self::_encode_json($merged['permissions']), $now, (int) $existing['id']],
                    'statement'
                );
                $member_id = (int) $existing['id'];
            } else {
                if ($password_hash === null || $password_hash === '') {
                    throw new RuntimeException('Choose a password to create your account.');
                }
                $display_name = trim(substr($name, 0, 120));
                if ($display_name === '') {
                    $display_name = strstr($email, '@', true) ?: $email;
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
                        $email,
                        $display_name,
                        $password_hash,
                        $trongate_user_id,
                        $invite['role'],
                        $invite['scope'],
                        self::_encode_json($invite['permissions']),
                        $now,
                        $now,
                    ],
                    'statement'
                );
                $member_id = (int) $this->db->connection()->lastInsertId();
            }

            $marked = $this->db->query_bind(
                'UPDATE team_invitations SET accepted_at = ?, accepted_member_id = ? WHERE id = ? AND accepted_at = 0 AND revoked_at = 0 AND expires_at > ?',
                [$now, $member_id, $invite['id'], $now],
                'statement'
            )->rowCount();
            if ($marked !== 1) {
                throw new RuntimeException('This invitation has already been used or revoked.');
            }

            $member = $this->_find_member($member_id);
            $this->db->connection()->commit();
            return ['member' => $member, 'invitation' => $invite];
        } catch (Throwable $e) {
            if ($this->db->connection()->inTransaction()) {
                $this->db->connection()->rollBack();
            }
            throw $e;
        }
    }

    function _update_member_access(int $member_id, array $grant, ?string $name = null): array {
        $member = $this->_find_member($member_id);
        if ($member === null) {
            throw new RuntimeException('Team member not found.');
        }
        $role = ($grant['role'] ?? 'member') === 'admin' ? 'admin' : 'member';
        $scope = ($grant['scope'] ?? 'scoped') === 'all' ? 'all' : 'scoped';
        $permissions = Access_policy::normalize_permissions(
            is_array($grant['permissions'] ?? null) ? $grant['permissions'] : [],
            $role,
            $scope
        );
        $display_name = $name !== null && trim($name) !== ''
            ? trim(substr($name, 0, 120))
            : (string) $member['name'];

        $this->db->query_bind(
            'UPDATE team_members SET name = ?, role = ?, scope = ?, permissions = ? WHERE id = ?',
            [$display_name, $role, $scope, self::_encode_json($permissions), $member_id],
            'statement'
        );

        return $this->_find_member($member_id);
    }

    /**
     * Updates or revokes a single app's granular permissions for an existing member.
     */
    function _set_member_app_permissions(int $member_id, string $app_id, array $app_perms): array {
        $member = $this->_find_member($member_id);
        if ($member === null) {
            throw new RuntimeException('Team member not found.');
        }
        if ($member['role'] === 'admin') {
            throw new RuntimeException('Workspace administrators already have full access to all apps.');
        }
        $perms = $member['permissions'];
        $clean = Access_policy::normalize_app_permissions($app_perms);
        if ($clean === []) {
            unset($perms['apps'][$app_id]);
        } else {
            $perms['apps'][$app_id] = $clean;
        }
        $normalized = Access_policy::normalize_permissions($perms, 'member', (string) $member['scope']);
        $this->db->query_bind(
            'UPDATE team_members SET permissions = ? WHERE id = ?',
            [self::_encode_json($normalized), $member_id],
            'statement'
        );
        return $this->_find_member($member_id);
    }

    function _set_member_active(int $member_id, bool $active): array {
        $member = $this->_find_member($member_id);
        if ($member === null) {
            throw new RuntimeException('Team member not found.');
        }
        $this->db->connection()->beginTransaction();
        try {
            $this->db->query_bind(
                'UPDATE team_members SET active = ? WHERE id = ?',
                [$active ? 1 : 0, $member_id],
                'statement'
            );
            if (!$active) {
                $this->db->query_bind(
                    'DELETE FROM trongate_tokens WHERE user_id = ?',
                    [(int) $member['trongate_user_id']],
                    'statement'
                );
            }
            $this->db->connection()->commit();
        } catch (Throwable $e) {
            if ($this->db->connection()->inTransaction()) {
                $this->db->connection()->rollBack();
            }
            throw $e;
        }
        return $this->_find_member($member_id);
    }

    function _remove_member(int $member_id): void {
        $member = $this->_find_member($member_id);
        if ($member === null) {
            throw new RuntimeException('Team member not found.');
        }
        $user_id = (int) $member['trongate_user_id'];
        $this->db->connection()->beginTransaction();
        try {
            $this->db->query_bind('DELETE FROM trongate_tokens WHERE user_id = ?', [$user_id], 'statement');
            $this->db->query_bind('DELETE FROM team_members WHERE id = ?', [$member_id], 'statement');
            $this->db->query_bind('DELETE FROM trongate_users WHERE id = ? AND user_level_id = 2', [$user_id], 'statement');
            $this->db->connection()->commit();
        } catch (Throwable $e) {
            if ($this->db->connection()->inTransaction()) {
                $this->db->connection()->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Removes a deleted app from scoped member and pending invitation permission sets.
     */
    function _prune_app(string $app_id): void {
        foreach ($this->_members() as $member) {
            if (isset($member['permissions']['apps'][$app_id])) {
                $perms = $member['permissions'];
                unset($perms['apps'][$app_id]);
                $this->db->query_bind(
                    'UPDATE team_members SET permissions = ? WHERE id = ?',
                    [self::_encode_json($perms), (int) $member['id']],
                    'statement'
                );
            }
        }
        foreach ($this->_pending_invitations($app_id) as $invite) {
            if (isset($invite['permissions']['apps'][$app_id])) {
                $perms = $invite['permissions'];
                unset($perms['apps'][$app_id]);
                $this->db->query_bind(
                    'UPDATE team_invitations SET permissions = ? WHERE id = ?',
                    [self::_encode_json($perms), (string) $invite['id']],
                    'statement'
                );
            }
        }
    }

    /**
     * Lists members who currently have access to a specific app (explicit, all-apps, or admin).
     */
    function _app_members(string $app_id): array {
        $result = [];
        foreach ($this->_members() as $member) {
            $actor = Access_policy::from_member($member);
            $effective = Access_policy::app_permissions($actor, $app_id);
            if ($effective === []) {
                continue;
            }
            $source = $member['role'] === 'admin'
                ? 'Workspace admin'
                : ($member['scope'] === 'all' && !empty($member['permissions']['all_apps'])
                    ? (isset($member['permissions']['apps'][$app_id]) ? 'All apps + custom' : 'All apps')
                    : 'Direct access');
            $member['app_permissions'] = $effective;
            $member['app_preset_label'] = Access_policy::preset_label($effective);
            $member['app_source'] = $source;
            $member['direct_permissions'] = $member['permissions']['apps'][$app_id] ?? [];
            $result[] = $member;
        }
        return $result;
    }

    private function _hydrate_member(array $row): array {
        $role = ($row['role'] ?? 'member') === 'admin' ? 'admin' : 'member';
        $scope = ($row['scope'] ?? 'scoped') === 'all' ? 'all' : 'scoped';
        $raw = is_string($row['permissions'] ?? null) ? (json_decode($row['permissions'], true) ?: []) : [];
        $perms = Access_policy::normalize_permissions($raw, $role, $scope);
        return [
            'id' => (int) ($row['id'] ?? 0),
            'email' => (string) ($row['email'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'trongate_user_id' => (int) ($row['trongate_user_id'] ?? 0),
            'role' => $role,
            'scope' => $role === 'admin' ? 'all' : $scope,
            'permissions' => $perms,
            'active' => (int) ($row['active'] ?? 1) === 1,
            'created_at' => (int) ($row['created_at'] ?? 0),
            'last_login' => (int) ($row['last_login'] ?? 0),
            'summary' => Access_policy::summary_lines($role, $scope, $perms),
        ];
    }

    private function _hydrate_invitation(array $row): array {
        $role = ($row['role'] ?? 'member') === 'admin' ? 'admin' : 'member';
        $scope = ($row['scope'] ?? 'scoped') === 'all' ? 'all' : 'scoped';
        $raw = is_string($row['permissions'] ?? null) ? (json_decode($row['permissions'], true) ?: []) : [];
        $perms = Access_policy::normalize_permissions($raw, $role, $scope);
        return [
            'id' => (string) ($row['id'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'role' => $role,
            'scope' => $role === 'admin' ? 'all' : $scope,
            'permissions' => $perms,
            'note' => (string) ($row['note'] ?? ''),
            'invited_by' => (string) ($row['invited_by'] ?? ''),
            'created_at' => (int) ($row['created_at'] ?? 0),
            'expires_at' => (int) ($row['expires_at'] ?? 0),
            'accepted_at' => (int) ($row['accepted_at'] ?? 0),
            'revoked_at' => (int) ($row['revoked_at'] ?? 0),
            'accepted_member_id' => (int) ($row['accepted_member_id'] ?? 0),
            'summary' => Access_policy::summary_lines($role, $scope, $perms),
        ];
    }

    private static function _encode_json(array $value): string {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
