<?php
$active_tab = 'database';
$app = $overview['app'];
$app_id = (string) $app['id'];
$database_name = (string) ($db_info['database'] ?? $overview['database_name']);
$base_url = '/apps/database/' . rawurlencode($app_id);

$readable = is_array($db_info) && !empty($db_info['ok']);
$db_error = is_array($db_info) && isset($db_info['error']) ? (string) $db_info['error'] : null;
$all_items = $readable && is_array($db_info['tables'] ?? null) ? $db_info['tables'] : [];
$sidebar_tables = array_values(array_filter($all_items, static fn(array $t): bool => empty($t['view'])));
$sidebar_views = array_values(array_filter($all_items, static fn(array $t): bool => !empty($t['view'])));
$sql_files = $readable && is_array($db_info['sql_files'] ?? null) ? $db_info['sql_files'] : [];

$current_table = $readable && is_string($db_info['table'] ?? null) ? $db_info['table'] : null;
$view = (string) ($db_view ?? 'sql');
if ($current_table === null && in_array($view, ['data', 'structure'], true)) {
    $view = 'sql';
}
$columns = $readable && is_array($db_info['columns'] ?? null) ? $db_info['columns'] : [];
$indexes = $readable && is_array($db_info['indexes'] ?? null) ? $db_info['indexes'] : [];
$rows = $readable && is_array($db_info['rows'] ?? null) ? $db_info['rows'] : [];
$page = max(1, (int) ($db_info['page'] ?? ($db_page ?? 1)));
$has_next = !empty($db_info['has_next']);

$sql_value = (string) ($db_sql ?? '');
$allow_changes = !empty($db_allow_changes);
$query_result = is_array($query_result ?? null) ? $query_result : null;
$query_error = isset($query_error) && is_string($query_error) ? $query_error : null;
$import_notice = isset($import_notice) && is_string($import_notice) ? $import_notice : null;
$import_error = isset($import_error) && is_string($import_error) ? $import_error : null;
$import_sql = (string) ($import_sql ?? '');
$starter_sets = is_array($starter_sets ?? null) ? $starter_sets : [];
$actor_ctx = is_array($actor ?? null) ? $actor : null;
$can_db_write = $actor_ctx === null || Access_policy::can_app($actor_ctx, $app_id, 'database_write');
?>
<main class="panel tc-panel">
    <div class="ov">
        <?php require __DIR__ . '/_header.php'; ?>

        <?php if ($import_notice !== null): ?>
            <p class="flash" role="status"><?= $e($import_notice) ?></p>
        <?php endif ?>

        <?php if (!$readable): ?>
            <section class="ov-card">
                <p class="ov-empty muted"><?= $e($db_error ?? 'Database is unavailable right now. Deploy the app or try again shortly.') ?></p>
            </section>
        <?php else: ?>
            <div class="db-ws">
                <div class="db-bar">
                    <span class="db-conn" title="Your app's database"><span class="db-conn-dot"></span><?= $e($database_name) ?> &middot; MariaDB</span>
                    <nav class="db-tabs" aria-label="Database views">
                        <span class="db-tab"<?= $view === 'sql' ? ' aria-current="page"' : '' ?>>
                            <a href="<?= $e($base_url . '?view=sql') ?>">SQL</a>
                        </span>
                        <?php if ($can_db_write): ?>
                            <span class="db-tab"<?= $view === 'import' ? ' aria-current="page"' : '' ?>>
                                <a href="<?= $e($base_url . '?view=import') ?>">Import &amp; setup</a>
                            </span>
                        <?php endif ?>
                        <?php if ($current_table !== null): ?>
                            <span class="db-tab"<?= in_array($view, ['data', 'structure'], true) ? ' aria-current="page"' : '' ?>>
                                <a href="<?= $e($base_url . '?table=' . rawurlencode($current_table)) ?>"><?= $e($current_table) ?></a>
                            </span>
                        <?php endif ?>
                    </nav>
                    <?php if ($current_table !== null && in_array($view, ['data', 'structure'], true)): ?>
                        <div class="db-toggle" role="group" aria-label="Table view">
                            <a href="<?= $e($base_url . '?table=' . rawurlencode($current_table) . '&view=data') ?>"<?= $view === 'data' ? ' aria-current="page"' : '' ?>>Data</a>
                            <a href="<?= $e($base_url . '?table=' . rawurlencode($current_table) . '&view=structure') ?>"<?= $view === 'structure' ? ' aria-current="page"' : '' ?>>Structure</a>
                        </div>
                    <?php endif ?>
                </div>

                <aside class="db-side" aria-label="Tables">
                    <input type="search" class="db-search" id="db-table-filter" placeholder="Filter tables…" aria-label="Filter tables">
                    <div class="db-side-list">
                        <p class="db-group">Tables &middot; <?= count($sidebar_tables) ?></p>
                        <?php if ($sidebar_tables === []): ?>
                            <p class="db-side-empty">No tables yet. Run a <code>CREATE TABLE</code> in <a href="<?= $e($base_url . '?view=sql') ?>">SQL</a> or use <a href="<?= $e($base_url . '?view=import') ?>">Import &amp; setup</a>.</p>
                        <?php else: ?>
                            <ul id="db-table-list">
                                <?php foreach ($sidebar_tables as $item):
                                    $tname = (string) $item['name'];
                                    $trows = $item['rows'];
                                    $is_cur = $current_table === $tname && in_array($view, ['data', 'structure'], true);
                                ?>
                                    <li data-table-name="<?= $e(strtolower($tname)) ?>">
                                        <a href="<?= $e($base_url . '?table=' . rawurlencode($tname)) ?>"<?= $is_cur ? ' aria-current="page"' : '' ?>>
                                            <span class="db-side-name"><?= $e($tname) ?></span>
                                            <?php if ($trows !== null): ?>
                                                <span class="db-side-note"><?= number_format((int) $trows) ?></span>
                                            <?php endif ?>
                                        </a>
                                    </li>
                                <?php endforeach ?>
                            </ul>
                        <?php endif ?>

                        <?php if ($sidebar_views !== []): ?>
                            <p class="db-group">Views &middot; <?= count($sidebar_views) ?></p>
                            <ul>
                                <?php foreach ($sidebar_views as $item):
                                    $vname = (string) $item['name'];
                                    $is_cur = $current_table === $vname && in_array($view, ['data', 'structure'], true);
                                ?>
                                    <li data-table-name="<?= $e(strtolower($vname)) ?>">
                                        <a href="<?= $e($base_url . '?table=' . rawurlencode($vname)) ?>"<?= $is_cur ? ' aria-current="page"' : '' ?>>
                                            <span class="db-side-name"><?= $e($vname) ?></span>
                                        </a>
                                    </li>
                                <?php endforeach ?>
                            </ul>
                        <?php endif ?>
                    </div>
                    <div class="db-side-foot">
                        <a href="<?= $e($base_url . '?view=sql') ?>"<?= $view === 'sql' ? ' aria-current="page"' : '' ?>>SQL</a>
                        <?php if ($can_db_write): ?>
                            <a href="<?= $e($base_url . '?view=import') ?>"<?= $view === 'import' ? ' aria-current="page"' : '' ?>>Import &amp; setup</a>
                        <?php endif ?>
                    </div>
                </aside>

                <section class="db-body">
                    <?php if ($view === 'sql'): ?>
                        <form method="post" action="/apps/database_query" class="db-sql" onsubmit="return !this.allow_changes || !this.allow_changes.checked || confirm('Run this SQL with changes allowed on <?= $e($database_name) ?>?')">
                            <?= $csrf() ?>
                            <input type="hidden" name="app" value="<?= $e($app_id) ?>">
                            <textarea name="sql" id="db-sql" class="db-editor" rows="6" spellcheck="false" placeholder="<?= $can_db_write ? "SHOW TABLES;\n-- Or tick Allow changes to run CREATE TABLE, ALTER TABLE, INSERT, UPDATE..." : "SHOW TABLES;\nSELECT * FROM ... LIMIT 50;" ?>"><?= $e($sql_value) ?></textarea>
                            <div class="db-sql-bar">
                                <button type="submit" class="btn-primary db-run">&#9654; Run</button>
                                <?php if ($can_db_write): ?>
                                    <label class="checkbox-label">
                                        <input type="checkbox" name="allow_changes" value="1"<?= $allow_changes ? ' checked' : '' ?>>
                                        Allow changes
                                    </label>
                                    <span class="db-sql-hint">One transaction &middot; read-only unless you allow changes &middot; stops after 15 s</span>
                                <?php else: ?>
                                    <span class="db-sql-hint">Read-only access &middot; stops after 15 s</span>
                                <?php endif ?>
                            </div>
                        </form>

                        <div class="db-panel">
                            <?php if ($query_error !== null): ?>
                                <p class="db-error" role="alert"><?= $e($query_error) ?></p>
                            <?php elseif ($query_result === null): ?>
                                <p class="db-empty-rows">Run a statement above to inspect or update <code><?= $e($database_name) ?></code>.</p>
                            <?php else: ?>
                                <?php foreach ($query_result as $idx => $panel):
                                    $pcols = is_array($panel['columns'] ?? null) ? $panel['columns'] : [];
                                    $prows = is_array($panel['rows'] ?? null) ? $panel['rows'] : [];
                                    $paffected = $panel['affected'] ?? null;
                                    $pms = (float) ($panel['ms'] ?? 0);
                                ?>
                                    <p class="db-done" role="status">
                                        <?php if (count($query_result) > 1): ?>
                                            <span class="db-stmt-num"><?= $idx + 1 ?>.</span>
                                        <?php endif ?>
                                        <code><?= $e($panel['statement'] ?? '') ?></code>
                                        <span class="muted">
                                            <?php if ($pcols !== []): ?>
                                                &middot; <?= count($prows) ?> <?= count($prows) === 1 ? 'row' : 'rows' ?><?= !empty($panel['truncated']) ? ' (truncated)' : '' ?>
                                            <?php else: ?>
                                                &middot; <?= (int) $paffected ?> <?= (int) $paffected === 1 ? 'row affected' : 'rows affected' ?>
                                            <?php endif ?>
                                            &middot; <?= $e(number_format($pms, 1)) ?> ms
                                        </span>
                                    </p>
                                    <?php if ($pcols !== []): ?>
                                        <div class="db-grid-wrap">
                                            <table class="db-grid">
                                                <thead>
                                                    <tr>
                                                        <th class="db-gut"></th>
                                                        <?php foreach ($pcols as $col): ?>
                                                            <th><?= $e($col) ?></th>
                                                        <?php endforeach ?>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php if ($prows === []): ?>
                                                        <tr><td class="db-gut"></td><td colspan="<?= count($pcols) ?>" class="muted">No rows returned.</td></tr>
                                                    <?php else: ?>
                                                        <?php foreach ($prows as $n => $cells): ?>
                                                            <tr>
                                                                <td class="db-gut"><?= $n + 1 ?></td>
                                                                <?php foreach ($cells as $cell): ?>
                                                                    <td><?php if ($cell === null): ?><span class="db-null">NULL</span><?php else: ?><?= $e($cell) ?><?php endif ?></td>
                                                                <?php endforeach ?>
                                                            </tr>
                                                        <?php endforeach ?>
                                                    <?php endif ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif ?>
                                <?php endforeach ?>
                            <?php endif ?>
                        </div>

                    <?php elseif ($view === 'import'): ?>
                        <div class="db-import-wrap">
                            <?php if ($import_error !== null): ?>
                                <p class="db-error" role="alert"><?= $e($import_error) ?></p>
                            <?php endif ?>

                            <?php if ($sql_files !== []): ?>
                                <section class="db-import-card">
                                    <h3>Repository SQL files</h3>
                                    <p class="muted">Detected in the active release. Click to run against <code><?= $e($database_name) ?></code>.</p>
                                    <ul class="db-sql-files">
                                        <?php foreach ($sql_files as $sf): ?>
                                            <li>
                                                <code class="ov-sha"><?= $e($sf['path']) ?></code>
                                                <span class="muted"><?= number_format(max(1, (int) round(((int) $sf['bytes']) / 1024))) ?> KB</span>
                                                <form method="post" action="/apps/database_import" onsubmit="return confirm('Run <?= $e($sf['path']) ?> against <?= $e($database_name) ?>?')">
                                                    <?= $csrf() ?>
                                                    <input type="hidden" name="app" value="<?= $e($app_id) ?>">
                                                    <input type="hidden" name="release_sql_file" value="<?= $e($sf['path']) ?>">
                                                    <button type="submit" class="btn-secondary db-btn-sm">Run file</button>
                                                </form>
                                            </li>
                                        <?php endforeach ?>
                                    </ul>
                                </section>
                            <?php endif ?>

                            <section class="db-import-card">
                                <h3>Upload a .sql file or paste setup SQL</h3>
                                <p class="muted">Runs CREATE TABLE, ALTER TABLE, INSERT, and schema setup statements (up to 512 KB) directly against <code><?= $e($database_name) ?></code>.</p>
                                <form method="post" action="/apps/database_import" enctype="multipart/form-data" class="db-import-form">
                                    <?= $csrf() ?>
                                    <input type="hidden" name="app" value="<?= $e($app_id) ?>">
                                    <div class="settings-field">
                                        <label for="sql_file">SQL file (.sql)</label>
                                        <input type="file" id="sql_file" name="sql_file" accept=".sql,text/plain,application/sql">
                                    </div>
                                    <div class="settings-field">
                                        <label for="import_sql">Or paste SQL</label>
                                        <textarea id="import_sql" name="import_sql" class="mono" rows="6" spellcheck="false" placeholder="CREATE TABLE IF NOT EXISTS demo_table (&#10;  id INT AUTO_INCREMENT PRIMARY KEY,&#10;  name VARCHAR(255) NOT NULL&#10;);"><?= $e($import_sql) ?></textarea>
                                    </div>
                                    <div class="form-actions">
                                        <button type="submit" class="btn-primary">Run SQL import</button>
                                    </div>
                                </form>
                            </section>

                            <section class="db-import-card">
                                <h3>Trongate starter tables</h3>
                                <p class="muted">Create standard Trongate authentication and login tables (safe to run — existing tables are preserved).</p>
                                <ul class="db-sql-files">
                                    <?php foreach ($starter_sets as $key => $meta): ?>
                                        <li>
                                            <div>
                                                <strong><?= $e($meta['label']) ?></strong>
                                                <div class="muted" style="font-size:12px"><?= $e($meta['tables']) ?></div>
                                            </div>
                                            <form method="post" action="/apps/database_import" onsubmit="return confirm('Create <?= $e($meta['label']) ?> tables in <?= $e($database_name) ?>?')">
                                                <?= $csrf() ?>
                                                <input type="hidden" name="app" value="<?= $e($app_id) ?>">
                                                <input type="hidden" name="starter_set" value="<?= $e($key) ?>">
                                                <button type="submit" class="btn-secondary db-btn-sm">Install tables</button>
                                            </form>
                                        </li>
                                    <?php endforeach ?>
                                </ul>
                            </section>
                        </div>

                    <?php elseif ($view === 'structure' && $current_table !== null): ?>
                        <div class="db-panel">
                            <h3 class="db-subhead">Columns &middot; <?= $e($current_table) ?></h3>
                            <div class="db-grid-wrap">
                                <table class="db-grid">
                                    <thead>
                                        <tr>
                                            <th>Column</th>
                                            <th>Type</th>
                                            <th>Nullable</th>
                                            <th>Key</th>
                                            <th>Default</th>
                                            <th>Extra</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($columns as $c): ?>
                                            <tr>
                                                <td class="ov-mono"><strong><?= $e($c['name']) ?></strong></td>
                                                <td class="ov-mono"><?= $e($c['column_type']) ?></td>
                                                <td><?= !empty($c['nullable']) ? 'YES' : 'NO' ?></td>
                                                <td><?= $e($c['key'] !== '' ? $c['key'] : '—') ?></td>
                                                <td><?php if ($c['default'] === null): ?><span class="db-null">NULL</span><?php else: ?><?= $e($c['default']) ?><?php endif ?></td>
                                                <td class="muted"><?= $e($c['extra'] !== '' ? $c['extra'] : '—') ?></td>
                                            </tr>
                                        <?php endforeach ?>
                                    </tbody>
                                </table>
                            </div>

                            <?php if ($indexes !== []): ?>
                                <h3 class="db-subhead" style="margin-top:20px">Indexes</h3>
                                <div class="db-grid-wrap">
                                    <table class="db-grid">
                                        <thead>
                                            <tr>
                                                <th>Name</th>
                                                <th>Column</th>
                                                <th>Unique</th>
                                                <th>Type</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($indexes as $idx): ?>
                                                <tr>
                                                    <td class="ov-mono"><?= $e($idx['name']) ?></td>
                                                    <td class="ov-mono"><?= $e($idx['column']) ?></td>
                                                    <td><?= !empty($idx['unique']) ? 'Yes' : 'No' ?></td>
                                                    <td class="muted"><?= $e($idx['type']) ?></td>
                                                </tr>
                                            <?php endforeach ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif ?>
                        </div>

                    <?php elseif ($current_table !== null): ?>
                        <div class="db-panel db-data-panel">
                            <div class="db-grid-wrap">
                                <table class="db-grid">
                                    <thead>
                                        <tr>
                                            <th class="db-gut"></th>
                                            <?php foreach ($columns as $c): ?>
                                                <th title="<?= $e($c['column_type']) ?>"><?= $e($c['name']) ?></th>
                                            <?php endforeach ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($rows === []): ?>
                                            <tr><td class="db-gut"></td><td colspan="<?= max(1, count($columns)) ?>" class="muted">This table has no rows yet.</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($rows as $n => $cells): ?>
                                                <tr>
                                                    <td class="db-gut"><?= (($page - 1) * 50) + $n + 1 ?></td>
                                                    <?php foreach ($cells as $cell): ?>
                                                        <td><?php if ($cell === null): ?><span class="db-null">NULL</span><?php else: ?><?= $e($cell) ?><?php endif ?></td>
                                                    <?php endforeach ?>
                                                </tr>
                                            <?php endforeach ?>
                                        <?php endif ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="db-pager">
                                <span class="muted">Page <?= $page ?> &middot; <?= count($rows) ?> <?= count($rows) === 1 ? 'row' : 'rows' ?> shown</span>
                                <div class="db-pager-links">
                                    <?php if ($page > 1): ?>
                                        <a href="<?= $e($base_url . '?table=' . rawurlencode($current_table) . '&page=' . ($page - 1)) ?>" class="btn-secondary db-btn-sm">&larr; Previous</a>
                                    <?php endif ?>
                                    <?php if ($has_next): ?>
                                        <a href="<?= $e($base_url . '?table=' . rawurlencode($current_table) . '&page=' . ($page + 1)) ?>" class="btn-secondary db-btn-sm">Next &rarr;</a>
                                    <?php endif ?>
                                </div>
                            </div>
                        </div>
                    <?php endif ?>
                </section>
            </div>
            <script>
                (function () {
                    const input = document.getElementById('db-table-filter');
                    const list = document.getElementById('db-table-list');
                    if (!input || !list) return;
                    input.addEventListener('input', function () {
                        const q = input.value.trim().toLowerCase();
                        list.querySelectorAll('li[data-table-name]').forEach(function (li) {
                            li.hidden = q !== '' && !li.dataset.tableName.includes(q);
                        });
                    });
                })();
            </script>
        <?php endif ?>
    </div>
</main>
