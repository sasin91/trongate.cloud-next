--TEST--
Access_policy normalization, granular grants, and Team_model invitation lifecycle
--FILE--
<?php
require dirname(__DIR__, 3) . '/tests/inc/model_fixture.php';

$dir = sys_get_temp_dir() . '/tc-team-unit-' . bin2hex(random_bytes(6));
mkdir($dir);
putenv('TC_DSN=sqlite:' . $dir . '/state.db');
putenv('TC_STATE=' . $dir);

try {
    // 1. Access_policy normalization and implication rules
    $deployOnly = Access_policy::normalize_app_permissions(['deploy']);
    if ($deployOnly !== ['view', 'deployments', 'deploy']) {
        throw new RuntimeException('deploy must imply view and deployments: ' . implode(',', $deployOnly));
    }
    $dbWriteOnly = Access_policy::normalize_app_permissions(['database_write']);
    if ($dbWriteOnly !== ['view', 'database_read', 'database_write']) {
        throw new RuntimeException('database_write must imply view and database_read');
    }
    $svcManageOnly = Access_policy::normalize_app_permissions(['services_manage']);
    if ($svcManageOnly !== ['view', 'services_view', 'services_manage']) {
        throw new RuntimeException('services_manage must imply view and services_view');
    }
    $wsManage = Access_policy::normalize_workspace_permissions(['services.manage']);
    if ($wsManage !== ['services.view', 'services.manage']) {
        throw new RuntimeException('services.manage must imply services.view');
    }

    if (Access_policy::preset_label(Access_policy::APP_PRESETS['viewer']['permissions']) !== 'Viewer') {
        throw new RuntimeException('Viewer preset label mismatch');
    }
    if (Access_policy::preset_label(['view', 'logs']) !== 'Custom (2)') {
        throw new RuntimeException('Custom preset label mismatch');
    }

    // 2. Scoped viewer grant parsed from form
    $grant = Access_policy::parse_form_grant([
        'access_mode' => 'scoped',
        'app_perms' => [
            'slop' => ['view', 'deployments', 'logs', 'traffic', 'services_view'],
            'nonexistent' => ['delete'],
        ],
    ], ['slop', 'other']);
    if (isset($grant['permissions']['apps']['nonexistent'])) {
        throw new RuntimeException('Unknown app ID was not filtered out');
    }
    if (Access_policy::single_app_target($grant['role'], $grant['scope'], $grant['permissions']) !== 'slop') {
        throw new RuntimeException('single_app_target failed for single-app scoped grant');
    }

    // 3. Team_model lifecycle
    $model = new Test_model('jobs');
    $conn = $model->db->connection();
    (new Setup_model('setup', $conn))->migrate();
    $team = new Team_model('team', $conn);

    $inv = $team->_create_invitation('TBJ@SyncroNet.DK', $grant, 'Check out slop for ops ideas', 'jonas', 7);
    if ($inv['email'] !== 'tbj@syncronet.dk' || strlen($inv['token']) !== 64) {
        throw new RuntimeException('Invitation normalization or token generation failed');
    }
    // Raw token must never be stored in DB
    $rawStored = $conn->query('SELECT token_hash FROM team_invitations')->fetchColumn();
    if ($rawStored === $inv['token'] || $rawStored !== hash('sha256', $inv['token'])) {
        throw new RuntimeException('Invitation token was not stored as SHA-256 hash');
    }

    // Rotate invalidates previous link
    $oldToken = $inv['token'];
    $rotated = $team->_rotate_invitation($inv['id']);
    if ($team->_find_valid_invitation_by_token($oldToken) !== null) {
        throw new RuntimeException('Old token remained valid after rotation');
    }
    if ($team->_find_valid_invitation_by_token($rotated['token']) === null) {
        throw new RuntimeException('Rotated token was not accepted');
    }

    // Accept creates level-2 user + team_members row
    $accepted = $team->_accept_invitation($rotated['token'], 'Tobias', password_hash('very-secure-password-123', PASSWORD_DEFAULT));
    $member = $accepted['member'];
    if ($member['email'] !== 'tbj@syncronet.dk' || $member['name'] !== 'Tobias' || !$member['active']) {
        throw new RuntimeException('Accepted member record invalid');
    }
    $actor = Access_policy::from_member($member);
    if (!Access_policy::can_app($actor, 'slop', 'view')
        || !Access_policy::can_app($actor, 'slop', 'logs')
        || !Access_policy::can_app($actor, 'slop', 'services_view')
        || Access_policy::can_app($actor, 'slop', 'deploy')
        || Access_policy::can_app($actor, 'slop', 'database_read')
        || Access_policy::can_app($actor, 'other', 'view')
        || Access_policy::can_workspace($actor, 'servers.manage')
    ) {
        throw new RuntimeException('Viewer actor permissions evaluated incorrectly');
    }

    // Reusing an accepted token must fail
    $reused = false;
    try {
        $team->_accept_invitation($rotated['token'], 'Tobias', password_hash('another-password-123', PASSWORD_DEFAULT));
    } catch (RuntimeException) {
        $reused = true;
    }
    if (!$reused) {
        throw new RuntimeException('Accepted invitation token was accepted twice');
    }

    // Second invite for existing member merges new app without dropping slop
    $inv2 = $team->_create_invitation('tbj@syncronet.dk', [
        'role' => 'member',
        'scope' => 'scoped',
        'permissions' => ['workspace' => [], 'all_apps' => [], 'apps' => ['ops' => ['view', 'database_read']]],
    ], '', 'jonas', 7);
    $accepted2 = $team->_accept_invitation($inv2['token'], '', null, (int) $member['id']);
    $actor2 = Access_policy::from_member($accepted2['member']);
    if (!Access_policy::can_app($actor2, 'slop', 'logs') || !Access_policy::can_app($actor2, 'ops', 'database_read')) {
        throw new RuntimeException('Re-invitation did not merge existing and new app permissions');
    }

    // Job visibility checks
    if (!Access_policy::can_view_job($actor2, ['kind' => 'deploy', 'payload' => json_encode(['app' => 'slop'])])) {
        throw new RuntimeException('Member should see slop deploy job');
    }
    if (Access_policy::can_view_job($actor2, ['kind' => 'deploy', 'payload' => json_encode(['app' => 'other'])])) {
        throw new RuntimeException('Member saw job for unauthorized app');
    }
    if (Access_policy::can_view_job($actor2, ['kind' => 'deploy-service', 'payload' => json_encode(['service' => 'shared-ai'])])) {
        throw new RuntimeException('Member saw shared service job without services.view');
    }

    // Suspending immediately revokes active tokens
    $conn->prepare('INSERT INTO trongate_tokens(token, user_id, expiry_date) VALUES(?, ?, ?)')->execute([
        'tok-' . bin2hex(random_bytes(8)),
        $member['trongate_user_id'],
        time() + 3600,
    ]);
    $team->_set_member_active((int) $member['id'], false);
    $remainingTokens = (int) $conn->query('SELECT COUNT(*) FROM trongate_tokens WHERE user_id = ' . (int) $member['trongate_user_id'])->fetchColumn();
    if ($remainingTokens !== 0) {
        throw new RuntimeException('Suspending member did not revoke active tokens');
    }

    // Revoking pending invitation invalidates it
    $inv3 = $team->_create_invitation('revoked@example.com', $grant, '', 'jonas', 7);
    $team->_revoke_invitation($inv3['id']);
    if ($team->_find_valid_invitation_by_token($inv3['token']) !== null) {
        throw new RuntimeException('Revoked invitation remained valid');
    }

    // Pruning deleted app removes it from member permissions
    $team->_prune_app('ops');
    $afterPrune = Access_policy::from_member($team->_find_member((int) $member['id']));
    if (Access_policy::can_app($afterPrune, 'ops', 'view') || !Access_policy::can_app($afterPrune, 'slop', 'view')) {
        throw new RuntimeException('Pruning deleted app failed');
    }

    // Mailer gracefully reports not_configured when TC_SMTP_HOST is unset
    putenv('TC_SMTP_HOST=');
    $mailRes = Mailer::send_invite('tbj@syncronet.dk', 'http://localhost:8080/team/accept/abc', 'jonas', ['slop · Viewer']);
    if ($mailRes['sent'] !== false || ($mailRes['reason'] ?? '') !== 'not_configured') {
        throw new RuntimeException('Mailer should report not_configured without TC_SMTP_HOST');
    }

    echo "Team access policy and invitation lifecycle passed\n";
} finally {
    unset($team, $conn, $model);
    foreach (glob($dir . '/*') as $f) {
        unlink($f);
    }
    rmdir($dir);
}
?>
--EXPECT--
Team access policy and invitation lifecycle passed
