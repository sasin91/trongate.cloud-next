<?php
require __DIR__ . '/_helpers.php';
$actor = is_array($actor ?? null) ? $actor : null;
$viewData = compact(
    'e',
    'value',
    'csrf',
    'field',
    'ready',
    'page',
    'operation',
    'signedIn',
    'actor',
    'message',
    'inventory',
    'jobs',
    'pending_servers',
    'deleting_server',
    'timeline',
    'resource_details',
    'resources_saved'
);
$viewData += [
    'capacities' => $capacities ?? [],
    'services' => $services ?? [],
    'presets' => $presets ?? [],
    'can_add_service' => $can_add_service ?? true,
    'crumbs' => $crumbs ?? [],
    'app_summaries' => $app_summaries ?? [],
    'overview' => $overview ?? null,
    'saved_notice' => $saved_notice ?? null,
    'github' => $github ?? null,
    'app_logs' => $app_logs ?? null,
    'log_rates' => $log_rates ?? null,
    'db_info' => $db_info ?? null,
    'db_view' => $db_view ?? 'sql',
    'db_table' => $db_table ?? null,
    'db_page' => $db_page ?? 1,
    'db_sql' => $db_sql ?? '',
    'db_allow_changes' => $db_allow_changes ?? false,
    'query_result' => $query_result ?? null,
    'query_error' => $query_error ?? null,
    'import_notice' => $import_notice ?? null,
    'import_error' => $import_error ?? null,
    'import_sql' => $import_sql ?? '',
    'starter_sets' => $starter_sets ?? [],
    'administrators' => $administrators ?? [],
    'members' => $members ?? [],
    'invitations' => $invitations ?? [],
    'all_apps' => $all_apps ?? [],
    'member' => $member ?? null,
    'app_members' => $app_members ?? [],
    'all_members' => $all_members ?? [],
    'app_invitations' => $app_invitations ?? [],
    'invite_link' => $invite_link ?? null,
];
$can_services_nav = $actor !== null && Access_policy::can_workspace($actor, 'services.view');
$can_jobs_nav = $actor !== null && Access_policy::can_access_jobs_page($actor);
$can_team_nav = $actor !== null && Access_policy::can_workspace($actor, 'team.manage');
$can_create_apps = $actor !== null && Access_policy::can_workspace($actor, 'apps.create');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>trongate.cloud · <?= $e(ucwords(str_replace('-', ' ', $page))) ?></title>
    <link rel="stylesheet" href="/css/trongate.css">
    <link rel="stylesheet" href="/templates_module/css/wizard.css">
    <script src="/js/trongate-mx.js" defer></script>
    <script src="/templates_module/js/mx.js" defer></script>
    <script src="/servers_module/js/resources.js" defer></script>
    <link rel="stylesheet" href="/deployments_module/css/deployments.css">
    <link rel="stylesheet" href="/templates_module/css/panel.css">
    <script src="/apps_module/js/flows.js" defer></script>
    <script src="/apps_module/js/app_logs.js" defer></script>
    <script src="/apps_module/js/app_traffic.js" defer></script>
    <script src="/services_module/js/services.js" defer></script>
    <script src="/team_module/js/team.js" defer></script>
</head>
<body class="wz-body">
    <aside id="mx-feedback" role="status" aria-live="polite"></aside>
    <button hidden id="mx-error-trigger" mx-get="/templates/request_error" mx-target="#mx-feedback" mx-trigger="none" mx-after-swap="resourceRelease"></button>
    <header class="wz-top tc-header">
        <div class="wz-top-row tc-header-row">
            <div class="tc-header-left">
                <a class="wz-brand" href="/apps"><?php require __DIR__ . '/_mark.php'; ?><span>trongate<b>.cloud</b></span></a>
                <?php if (!empty($crumbs)): ?>
                    <span class="tc-header-sep" aria-hidden="true"></span>
                    <nav class="tc-crumbs" aria-label="Breadcrumb">
                        <?php foreach ($crumbs as $ci => [$chref, $clabel]): ?>
                            <?php if ($ci > 0): ?><span class="tc-crumb-sep" aria-hidden="true">/</span><?php endif ?>
                            <a href="<?= $e($chref) ?>"<?= $ci === count($crumbs) - 1 ? ' aria-current="page"' : '' ?>><?= $e($clabel) ?></a>
                        <?php endforeach ?>
                    </nav>
                <?php endif ?>
            </div>
            <?php if ($signedIn): ?>
                <nav class="tc-nav">
                    <a href="/apps"<?= $page === 'dashboard' ? ' aria-current="page"' : '' ?>>Apps</a>
                    <?php if ($can_services_nav): ?>
                        <a href="/services"<?= $page === 'services' ? ' aria-current="page"' : '' ?>>Services</a>
                    <?php endif ?>
                    <?php if ($can_jobs_nav): ?>
                        <a href="/jobs"<?= $page === 'jobs' ? ' aria-current="page"' : '' ?>>Jobs</a>
                    <?php endif ?>
                    <?php if ($can_team_nav): ?>
                        <a href="/team"<?= in_array($page, ['team', 'team-member'], true) ? ' aria-current="page"' : '' ?>>Team</a>
                    <?php endif ?>
                    <?php if ($actor !== null && empty($actor['is_owner']) && $actor['email'] !== ''): ?>
                        <span class="tc-actor-pill" title="Signed in as <?= $e($actor['email']) ?>"><?= $e($actor['email']) ?></span>
                    <?php endif ?>
                    <form method="post" action="/login/logout"><?= $csrf() ?><button class="link-button">Sign out</button></form>
                </nav>
            <?php endif ?>
            <span id="wz-counter" class="wz-counter"></span>
        </div>
        <div id="wz-progress" class="wz-progress" aria-hidden="true"></div>
    </header>
    <?php if ($can_create_apps && $page !== 'new-app' && isset($_SESSION['app_draft'])): ?>
        <p class="notice"><a href="/apps/create">Continue app setup</a> — your draft is saved.</p>
    <?php endif ?>
    <?php if ($message !== null && !in_array($page, ['server-details', 'deployment', 'forbidden'], true)): ?>
        <div class="notice wz-errors" role="alert"><?= $e($message) ?></div>
    <?php endif ?>
    <?php $this->{$content_module}->_display($view_file, $viewData); ?>
</body>
</html>
