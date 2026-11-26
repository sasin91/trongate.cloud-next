<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>trongate.cloud · Accept invitation</title>
    <link rel="stylesheet" href="/templates_module/css/wizard.css">
    <link rel="stylesheet" href="/templates_module/css/panel.css">
</head>
<body class="wz-body">
    <main class="wz-wrap">
        <div class="wz-main">
            <div class="wz-stage">
                <?php if ($invitation === null): ?>
                    <h1>Invitation unavailable.</h1>
                    <p>This invitation link is invalid, has already been accepted, or has expired. Ask a workspace administrator to send a fresh link.</p>
                    <p><a class="btn-secondary" href="/login/login/1">Go to sign in</a></p>
                <?php else:
                    $default_name = strstr((string) $invitation['email'], '@', true) ?: (string) $invitation['email'];
                ?>
                    <p class="eyebrow">WORKSPACE INVITATION</p>
                    <h1>You’re invited to trongate.cloud.</h1>
                    <p>
                        <?php if ($invitation['invited_by'] !== ''): ?>
                            <strong><?= $e($invitation['invited_by']) ?></strong> invited
                        <?php else: ?>
                            Invitation for
                        <?php endif ?>
                        <strong><?= $e($invitation['email']) ?></strong>.
                    </p>

                    <?php if ($invitation['note'] !== ''): ?>
                        <blockquote class="invite-note"><?= $e($invitation['note']) ?></blockquote>
                    <?php endif ?>

                    <section class="ov-card" style="margin:20px 0 24px">
                        <div class="ov-card-head">
                            <h2>Access included</h2>
                            <span class="muted">Expires <?= $e(gmdate('Y-m-d', (int) $invitation['expires_at'])) ?> UTC</span>
                        </div>
                        <div class="ov-pad">
                            <ul class="invite-scope-list">
                                <?php foreach ($invitation['summary'] as $line): ?>
                                    <li><?= $e($line) ?></li>
                                <?php endforeach ?>
                            </ul>
                        </div>
                    </section>

                    <?php if (!empty($error)): ?>
                        <p class="notice wz-errors" role="alert" style="margin:0 0 18px;width:100%"><?= $e($error) ?></p>
                    <?php endif ?>

                    <form method="post" action="/team/complete_invite" class="settings-form">
                        <?= $csrf() ?>
                        <input type="hidden" name="token" value="<?= $e($token) ?>">

                        <?php if ($existing_member): ?>
                            <?php if ($signed_in_as_invitee): ?>
                                <p class="form-hint">You’re signed in as <strong><?= $e($invitation['email']) ?></strong>. Accepting will add the access above to your account.</p>
                            <?php else: ?>
                                <p class="form-hint">An account already exists for <strong><?= $e($invitation['email']) ?></strong>. Confirm your password to add this access.</p>
                                <label class="wz-field">
                                    <span class="wz-label">Password</span>
                                    <input class="wz-in" type="password" name="password" autocomplete="current-password" required>
                                </label>
                            <?php endif ?>
                            <button type="submit" class="wz-ok">Accept invitation</button>
                        <?php else: ?>
                            <label class="wz-field">
                                <span class="wz-label">Your name</span>
                                <input class="wz-in" type="text" name="name" value="<?= $e($default_name) ?>" maxlength="120" autocomplete="name" required>
                            </label>
                            <label class="wz-field">
                                <span class="wz-label">Create a password</span>
                                <input class="wz-in" type="password" name="password" minlength="12" autocomplete="new-password" required>
                                <span class="wz-hint">At least 12 characters.</span>
                            </label>
                            <label class="wz-field">
                                <span class="wz-label">Confirm password</span>
                                <input class="wz-in" type="password" name="password_confirm" minlength="12" autocomplete="new-password" required>
                            </label>
                            <button type="submit" class="wz-ok">Accept &amp; sign in</button>
                        <?php endif ?>
                    </form>
                <?php endif ?>
            </div>
        </div>
    </main>
</body>
</html>
