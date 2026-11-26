<?php
$active_tab = 'logs';
$app = $overview['app'];
$app_id = (string) $app['id'];
$lines = is_array($app_logs['lines'] ?? null) ? $app_logs['lines'] : [];
$cursor = (string) ($app_logs['cursor'] ?? '');
$readable = is_array($app_logs) && !empty($app_logs['ok']);
?>
<main class="panel tc-panel">
    <div class="ov">
        <?php require __DIR__ . '/_header.php'; ?>

        <section class="ov-card app-logs" aria-labelledby="logs-h">
            <div class="ov-card-head">
                <h2 id="logs-h">Output</h2>
                <label class="checkbox-label app-log-filter">
                    <input type="checkbox" id="log-problems-only">
                    Warnings and errors only
                </label>
                <button type="button" class="btn-secondary app-log-copy" id="copy-last-error" hidden disabled>Copy last error</button>
                <span class="muted">Last 250 lines &middot; times in UTC</span>
            </div>
            <?php if (!$readable): ?>
                <p class="log-note">Logs are unavailable right now. Deploy the app or try again shortly.</p>
            <?php endif ?>
            <div class="app-log-box" id="app-log-box" tabindex="0" aria-label="App output" aria-live="polite">
                <div id="app-log-lines"><?php require __DIR__ . '/_log_lines.php'; ?></div>
            </div>
        </section>

        <?php if ($readable): ?>
            <input type="hidden" id="app-log-poller" value="<?= $e($cursor) ?>"
                   mx-get="/apps/logs_poll/<?= $e(rawurlencode($app_id)) ?>?since=${this.value}"
                   mx-trigger="every 3s"
                   mx-target="none"
                   mx-select-oob='[{"select":"#new-log-lines","target":"#app-log-lines","swap":"beforeend"},{"select":"#log-cursor","target":"#app-log-poller","swap":"value"}]'>
        <?php endif ?>
    </div>
</main>
