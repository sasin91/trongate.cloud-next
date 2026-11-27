<?php
$gh_error = $_SESSION['github_error'] ?? null;
$gh_flash = $_SESSION['github_flash'] ?? null;
unset($_SESSION['github_error'], $_SESSION['github_flash']);
$e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Trongate Cloud · Sign in</title>
    <link rel="stylesheet" href="/templates_module/css/wizard.css">
    <link rel="stylesheet" href="/templates_module/css/panel.css">
</head>
<body class="wz-body">
    <main class="wz-wrap">
        <div class="wz-main">
            <div class="wz-stage">
                <h1>Welcome back.</h1>
                <p><?= $user_level_id === 1 ? 'Sign in as an administrator.' : 'Sign in to your team account.' ?></p>
                <?php if (is_string($gh_flash) && $gh_flash !== ''): ?>
                    <p class="flash" role="status"><?= $e($gh_flash) ?></p>
                <?php endif ?>
                <?php if (is_string($gh_error) && $gh_error !== ''): ?>
                    <div class="wz-errors" role="alert"><p><?= $e($gh_error) ?></p></div>
                <?php endif ?>
                <?php if ($user_level_id === 2): ?>
                    <a href="/auth/github" class="github-btn">
                        <svg width="18" height="18" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0016 8c0-4.42-3.58-8-8-8z"/></svg>
                        Continue with GitHub
                    </a>
                    <div class="or-divider">or with password</div>
                <?php endif ?>
                <?= validation_errors() ?>
                <?= form_open($form_location) ?>
                    <label class="wz-field">
                        <span class="wz-label"><?= $e($identifier_label) ?></span>
                        <input class="wz-in" name="identifier" autocomplete="username" required>
                    </label>
                    <label class="wz-field">
                        <span class="wz-label">Password</span>
                        <input class="wz-in" type="password" name="password" autocomplete="current-password" required>
                    </label>
                    <button class="wz-ok">Sign in</button>
                <?= form_close() ?>
            </div>
        </div>
    </main>
</body>
</html>
