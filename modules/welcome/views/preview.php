<?php
$e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$csrf = static fn(): string => '';
?>
<!doctype html>
<html lang="en">
<head>
    <base href="<?= BASE_URL ?>">
    <meta charset="utf-8">
    <meta name="viewport" content="width=1440">
    <meta name="robots" content="noindex">
    <title>App overview - trongate.cloud</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>css/trongate.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>templates_module/css/wizard.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>deployments_module/css/deployments.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>templates_module/css/panel.css">
</head>
<body class="wz-body">
    <header class="wz-top tc-header">
        <div class="wz-top-row tc-header-row">
            <div class="tc-header-left">
                <span class="wz-brand"><?php require APPPATH . 'modules/templates/views/_mark.php'; ?><span>trongate<b>.cloud</b></span></span>
                <span class="tc-header-sep" aria-hidden="true"></span>
                <nav class="tc-crumbs" aria-label="Breadcrumb">
                    <span>Apps</span>
                    <span class="tc-crumb-sep" aria-hidden="true">/</span>
                    <span>Storefront</span>
                </nav>
            </div>
            <nav class="tc-nav" aria-label="Preview navigation">
                <span>Apps</span>
                <span>Services</span>
                <span>Jobs</span>
                <span>Sign out</span>
            </nav>
        </div>
    </header>

    <?php require APPPATH . 'modules/apps/views/show.php'; ?>
</body>
</html>
