<?php
declare(strict_types=1);

class Team extends Trongate {
    function index(): void {
        $this->trongate_security->make_sure_allowed();
        $this->trongate_security->_require_workspace('team.manage');

        $flash = $_SESSION['team_flash'] ?? null;
        $error = $_SESSION['team_error'] ?? null;
        $invite_link = $_SESSION['team_invite_link'] ?? null;
        unset($_SESSION['team_flash'], $_SESSION['team_error'], $_SESSION['team_invite_link']);

        $this->templates->_render('team', 'index', 'team', [
            'administrators' => $this->model->_administrators(),
            'members' => $this->model->_members(),
            'invitations' => $this->model->_pending_invitations(),
            'all_apps' => $this->records->_all('app'),
            'saved_notice' => is_string($flash) ? $flash : null,
            'message' => is_string($error) ? $error : null,
            'invite_link' => is_array($invite_link) ? $invite_link : null,
            'crumbs' => [['/team', 'Team']],
        ]);
    }

    function invite(): void {
        $this->trongate_security->make_sure_allowed();
        $this->trongate_security->_csrf();
        $this->trongate_security->_require_workspace('team.manage');

        $return_app = trim((string) ($_POST['return_app'] ?? ''));
        $redirect_to = $return_app !== ''
            ? '/apps/access/' . rawurlencode($return_app)
            : '/team';

        try {
            $email = (string) ($_POST['email'] ?? '');
            $note = (string) ($_POST['note'] ?? '');
            $ttl_days = (int) ($_POST['expires_days'] ?? 7);
            $valid_app_ids = array_map(static fn(array $a): string => (string) ($a['id'] ?? ''), $this->records->_all('app'));
            $grant = Access_policy::parse_form_grant($_POST, $valid_app_ids);

            $actor = $this->trongate_security->_actor();
            $invited_by = $actor['email'] !== '' ? $actor['email'] : $actor['label'];
            $created = $this->model->_create_invitation($email, $grant, $note, $invited_by, $ttl_days);

            $url = rtrim(BASE_URL, '/') . '/team/accept/' . $created['token'];
            $summary = Access_policy::summary_lines($created['role'], $created['scope'], $created['permissions']);
            $mail = Mailer::send_invite(
                $created['email'],
                $url,
                $invited_by,
                $summary,
                $created['note'],
                $created['expires_at']
            );

            $_SESSION['team_invite_link'] = [
                'email' => $created['email'],
                'url' => $url,
                'expires_at' => $created['expires_at'],
                'sent' => !empty($mail['sent']),
                'mail_reason' => (string) ($mail['reason'] ?? ''),
                'mail_error' => (string) ($mail['error'] ?? ''),
            ];
            $_SESSION['team_flash'] = !empty($mail['sent'])
                ? 'Invitation sent to ' . $created['email'] . '.'
                : 'Invitation created for ' . $created['email'] . '. Share the invite link below.';
        } catch (Throwable $e) {
            $_SESSION['team_error'] = $e instanceof RuntimeException ? $e->getMessage() : 'Could not create invitation.';
        }

        header('Location: ' . $redirect_to, true, 303);
        exit;
    }

    function resend_invite(): void {
        $this->trongate_security->make_sure_allowed();
        $this->trongate_security->_csrf();
        $this->trongate_security->_require_workspace('team.manage');

        $return_app = trim((string) ($_POST['return_app'] ?? ''));
        $redirect_to = $return_app !== ''
            ? '/apps/access/' . rawurlencode($return_app)
            : '/team';

        try {
            $id = trim((string) ($_POST['invitation'] ?? ''));
            $rotated = $this->model->_rotate_invitation($id);
            $url = rtrim(BASE_URL, '/') . '/team/accept/' . $rotated['token'];
            $summary = Access_policy::summary_lines($rotated['role'], $rotated['scope'], $rotated['permissions']);
            $mail = Mailer::send_invite(
                $rotated['email'],
                $url,
                $rotated['invited_by'],
                $summary,
                $rotated['note'],
                $rotated['expires_at']
            );

            $_SESSION['team_invite_link'] = [
                'email' => $rotated['email'],
                'url' => $url,
                'expires_at' => $rotated['expires_at'],
                'sent' => !empty($mail['sent']),
                'mail_reason' => (string) ($mail['reason'] ?? ''),
                'mail_error' => (string) ($mail['error'] ?? ''),
            ];
            $_SESSION['team_flash'] = !empty($mail['sent'])
                ? 'Fresh invitation link sent to ' . $rotated['email'] . '.'
                : 'Generated a fresh invite link for ' . $rotated['email'] . '. The previous link has been invalidated.';
        } catch (Throwable $e) {
            $_SESSION['team_error'] = $e instanceof RuntimeException ? $e->getMessage() : 'Could not regenerate invitation.';
        }

        header('Location: ' . $redirect_to, true, 303);
        exit;
    }

    function revoke_invite(): void {
        $this->trongate_security->make_sure_allowed();
        $this->trongate_security->_csrf();
        $this->trongate_security->_require_workspace('team.manage');

        $return_app = trim((string) ($_POST['return_app'] ?? ''));
        $redirect_to = $return_app !== ''
            ? '/apps/access/' . rawurlencode($return_app)
            : '/team';

        try {
            $id = trim((string) ($_POST['invitation'] ?? ''));
            $this->model->_revoke_invitation($id);
            $_SESSION['team_flash'] = 'Invitation revoked.';
        } catch (Throwable $e) {
            $_SESSION['team_error'] = $e instanceof RuntimeException ? $e->getMessage() : 'Could not revoke invitation.';
        }

        header('Location: ' . $redirect_to, true, 303);
        exit;
    }

    function member(): void {
        $this->trongate_security->make_sure_allowed();
        $this->trongate_security->_require_workspace('team.manage');

        $id = segment(3, 'int');
        $member = $id > 0 ? $this->model->_find_member($id) : null;
        if ($member === null) {
            http_response_code(404);
            $this->templates->_render('team', 'index', 'team', [
                'administrators' => $this->model->_administrators(),
                'members' => $this->model->_members(),
                'invitations' => $this->model->_pending_invitations(),
                'all_apps' => $this->records->_all('app'),
                'message' => 'Team member not found.',
                'crumbs' => [['/team', 'Team']],
            ]);
            return;
        }

        $flash = $_SESSION['team_flash'] ?? null;
        $error = $_SESSION['team_error'] ?? null;
        unset($_SESSION['team_flash'], $_SESSION['team_error']);

        $this->templates->_render('team', 'member', 'team-member', [
            'member' => $member,
            'all_apps' => $this->records->_all('app'),
            'saved_notice' => is_string($flash) ? $flash : null,
            'message' => is_string($error) ? $error : null,
            'crumbs' => [
                ['/team', 'Team'],
                ['/team/member/' . $member['id'], $member['name'] !== '' ? $member['name'] : $member['email']],
            ],
        ]);
    }

    function update_member(): void {
        $this->trongate_security->make_sure_allowed();
        $this->trongate_security->_csrf();
        $this->trongate_security->_require_workspace('team.manage');

        $member_id = (int) ($_POST['member_id'] ?? 0);
        try {
            $member = $this->model->_find_member($member_id);
            if ($member === null) {
                throw new RuntimeException('Team member not found.');
            }
            $valid_app_ids = array_map(static fn(array $a): string => (string) ($a['id'] ?? ''), $this->records->_all('app'));
            $grant = Access_policy::parse_form_grant($_POST, $valid_app_ids);

            $actor = $this->trongate_security->_actor();
            if ((int) ($actor['member_id'] ?? 0) === $member_id) {
                $simulated = Access_policy::from_member([
                    'id' => $member_id,
                    'email' => $member['email'],
                    'name' => $member['name'],
                    'trongate_user_id' => $member['trongate_user_id'],
                    'role' => $grant['role'],
                    'scope' => $grant['scope'],
                    'permissions' => $grant['permissions'],
                ]);
                if (!Access_policy::can_workspace($simulated, 'team.manage')) {
                    throw new RuntimeException('You cannot remove your own team management permission.');
                }
            }

            $name = isset($_POST['name']) ? (string) $_POST['name'] : null;
            $this->model->_update_member_access($member_id, $grant, $name);
            $_SESSION['team_flash'] = 'Access updated for ' . $member['email'] . '.';
        } catch (Throwable $e) {
            $_SESSION['team_error'] = $e instanceof RuntimeException ? $e->getMessage() : 'Could not update team member.';
        }

        header('Location: /team/member/' . $member_id, true, 303);
        exit;
    }

    function update_app_member(): void {
        $this->trongate_security->make_sure_allowed();
        $this->trongate_security->_csrf();
        $this->trongate_security->_require_workspace('team.manage');

        $app_id = '';
        try {
            $app_id = Validate::id((string) ($_POST['app'] ?? ''));
            $this->records->_get(['kind' => 'app', 'id' => $app_id]);
            $member_id = (int) ($_POST['member_id'] ?? 0);
            $mode = trim((string) ($_POST['mode'] ?? 'update'));
            if ($mode === 'revoke') {
                $perms = [];
            } else {
                $perms = is_array($_POST['perms'] ?? null) ? $_POST['perms'] : [];
                $preset = trim((string) ($_POST['preset'] ?? ''));
                if ($perms === [] && isset(Access_policy::APP_PRESETS[$preset])) {
                    $perms = Access_policy::APP_PRESETS[$preset]['permissions'];
                }
            }
            $updated = $this->model->_set_member_app_permissions($member_id, $app_id, $perms);
            $_SESSION['team_flash'] = $perms === []
                ? 'Removed direct access to ' . $app_id . ' for ' . $updated['email'] . '.'
                : 'Updated ' . $app_id . ' access for ' . $updated['email'] . '.';
        } catch (Throwable $e) {
            $_SESSION['team_error'] = $e instanceof RuntimeException ? $e->getMessage() : 'Could not update app access.';
        }

        header('Location: ' . ($app_id !== '' ? '/apps/access/' . rawurlencode($app_id) : '/team'), true, 303);
        exit;
    }

    function toggle_member(): void {
        $this->trongate_security->make_sure_allowed();
        $this->trongate_security->_csrf();
        $this->trongate_security->_require_workspace('team.manage');

        $member_id = (int) ($_POST['member_id'] ?? 0);
        $active = ($_POST['active'] ?? '0') === '1';
        $actor = $this->trongate_security->_actor();
        try {
            if ((int) ($actor['member_id'] ?? 0) === $member_id && !$active) {
                throw new RuntimeException('You cannot suspend your own account.');
            }
            $updated = $this->model->_set_member_active($member_id, $active);
            $_SESSION['team_flash'] = $active
                ? 'Reactivated ' . $updated['email'] . '.'
                : 'Suspended ' . $updated['email'] . ' and ended their active sessions.';
        } catch (Throwable $e) {
            $_SESSION['team_error'] = $e instanceof RuntimeException ? $e->getMessage() : 'Could not update member status.';
        }

        header('Location: /team/member/' . $member_id, true, 303);
        exit;
    }

    function remove_member(): void {
        $this->trongate_security->make_sure_allowed();
        $this->trongate_security->_csrf();
        $this->trongate_security->_require_workspace('team.manage');

        $member_id = (int) ($_POST['member_id'] ?? 0);
        $actor = $this->trongate_security->_actor();
        try {
            if ((int) ($actor['member_id'] ?? 0) === $member_id) {
                throw new RuntimeException('You cannot remove your own account.');
            }
            $this->model->_remove_member($member_id);
            $_SESSION['team_flash'] = 'Team member removed and active sessions ended.';
        } catch (Throwable $e) {
            $_SESSION['team_error'] = $e instanceof RuntimeException ? $e->getMessage() : 'Could not remove team member.';
        }

        header('Location: /team', true, 303);
        exit;
    }

    /**
     * Public invitation acceptance screen.
     */
    function accept(): void {
        header('Referrer-Policy: no-referrer');
        $_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
        $token = trim((string) segment(3));
        $invite = $this->model->_find_valid_invitation_by_token($token);
        $existing = $invite !== null ? $this->model->_find_member_by_email($invite['email']) : null;
        $current_actor = $this->_optional_actor();
        $signed_in_as_invitee = $existing !== null
            && $current_actor !== null
            && (int) ($current_actor['member_id'] ?? 0) === (int) $existing['id'];

        $error = $_SESSION['invite_accept_error'] ?? null;
        unset($_SESSION['invite_accept_error']);

        $this->_render_accept([
            'token' => $token,
            'invitation' => $invite,
            'existing_member' => $existing !== null,
            'signed_in_as_invitee' => $signed_in_as_invitee,
            'error' => is_string($error) ? $error : null,
        ]);
    }

    /**
     * Public POST endpoint to complete an invitation and sign the user in.
     */
    function complete_invite(): void {
        header('Referrer-Policy: no-referrer');
        $this->trongate_security->_csrf();

        $token = trim((string) ($_POST['token'] ?? ''));
        $invite = $this->model->_find_valid_invitation_by_token($token);
        if ($invite === null) {
            $_SESSION['invite_accept_error'] = 'This invitation link is invalid or has expired.';
            header('Location: /team/accept/' . rawurlencode($token), true, 303);
            exit;
        }

        try {
            $existing = $this->model->_find_member_by_email($invite['email']);
            $current_actor = $this->_optional_actor();
            $signed_in_as_invitee = $existing !== null
                && $current_actor !== null
                && (int) ($current_actor['member_id'] ?? 0) === (int) $existing['id'];

            $password_hash = null;
            $name = trim((string) ($_POST['name'] ?? ''));
            $authenticated_member_id = null;

            if ($existing !== null) {
                if ($signed_in_as_invitee) {
                    $authenticated_member_id = (int) $existing['id'];
                } else {
                    $password = (string) ($_POST['password'] ?? '');
                    if (!password_verify($password, (string) ($existing['password'] ?? ''))) {
                        throw new RuntimeException('Enter the password for your existing ' . $invite['email'] . ' account to accept this invitation.');
                    }
                    $authenticated_member_id = (int) $existing['id'];
                }
            } else {
                $password = (string) ($_POST['password'] ?? '');
                $confirm = (string) ($_POST['password_confirm'] ?? '');
                if (strlen($password) < 12) {
                    throw new RuntimeException('Choose a password with at least 12 characters.');
                }
                if ($password !== $confirm) {
                    throw new RuntimeException('Password confirmation does not match.');
                }
                $this->module('login');
                $password_hash = $this->login->model->hash_password($password);
            }

            $accepted = $this->model->_accept_invitation($token, $name, $password_hash, $authenticated_member_id);
            $member = $accepted['member'];

            // Replace any previous session token with a fresh session for the invited member.
            $this->trongate_tokens->destroy();
            session_regenerate_id(true);
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $this->trongate_tokens->generate_token([
                'user_id' => (int) $member['trongate_user_id'],
                'expiry_date' => time() + 86400,
            ]);

            $single_app = Access_policy::single_app_target(
                $accepted['invitation']['role'],
                $accepted['invitation']['scope'],
                $accepted['invitation']['permissions']
            );
            $target = $single_app !== null ? '/apps/show/' . rawurlencode($single_app) : '/apps';
            header('Location: ' . $target, true, 303);
            exit;
        } catch (Throwable $e) {
            $_SESSION['invite_accept_error'] = $e instanceof RuntimeException ? $e->getMessage() : 'Could not accept invitation.';
            header('Location: /team/accept/' . rawurlencode($token), true, 303);
            exit;
        }
    }

    function _display(string $view, array $data): void {
        $data['viewData'] = $data;
        $this->view($view, $data);
    }

    private function _optional_actor(): ?array {
        $token = $this->trongate_tokens->attempt_get_valid_token([1, 2]);
        if ($token === false) {
            return null;
        }
        $user = $this->trongate_tokens->get_user_obj($token);
        if ($user === false || (int) $user->user_level_id !== 2) {
            return null;
        }
        $member = $this->model->_find_member_by_user_id((int) $user->trongate_user_id);
        if ($member === null || !$member['active']) {
            return null;
        }
        return Access_policy::from_member($member);
    }

    private function _render_accept(array $data): void {
        $e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $csrf = static fn(): string => '<input type="hidden" name="csrf_token" value="' . $e($_SESSION['csrf_token'] ?? '') . '">';
        $data['e'] = $e;
        $data['csrf'] = $csrf;
        $this->view('accept', $data);
    }
}
