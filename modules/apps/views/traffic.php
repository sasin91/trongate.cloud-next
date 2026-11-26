<?php
$active_tab = 'traffic';
$app = $overview['app'];
$app_id = (string) $app['id'];
$label = (string) ($app['label'] ?? $app_id);
?>
<main class="panel tc-panel">
    <div class="ov">
        <?php require __DIR__ . '/_header.php'; ?>

        <section class="ov-card traffic" id="traffic"
                 data-poll-url="/apps/traffic_poll/<?= $e(rawurlencode($app_id)) ?>"
                 data-file="<?= $e($app_id) ?>-traffic"
                 data-label="<?= $e($label) ?>"
                 aria-labelledby="traffic-h">
            <div class="ov-card-head traffic-head">
                <h2 id="traffic-h">Live traffic</h2>
                <span class="muted" id="traffic-status" aria-live="polite">Connecting&hellip;</span>
                <span class="traffic-actions">
                    <span class="traffic-recording muted" id="traffic-recording" hidden></span>
                    <button type="button" class="btn-secondary" id="traffic-record">Start recording</button>
                </span>
            </div>

            <div class="ov-rates">
                <dl class="ov-traffic-stats">
                    <div><dt>Requests</dt><dd data-stat="requests">0</dd></div>
                    <div><dt>Last minute</dt><dd data-stat="per_minute">0</dd></div>
                    <div><dt>Server errors</dt><dd data-stat="server_errors">0%</dd></div>
                    <div><dt>Median</dt><dd data-stat="median">n/a</dd></div>
                    <div><dt>p95</dt><dd data-stat="p95">n/a</dd></div>
                    <div><dt>Sent</dt><dd data-stat="bytes">0 B</dd></div>
                </dl>
                <p class="ov-rates-sum">
                    <span><i class="ov-swatch ov-swatch-ok" aria-hidden="true"></i><span data-stat="ok">0</span> answered</span>
                    <span><i class="ov-swatch ov-swatch-warn" aria-hidden="true"></i><span data-stat="client_errors">0</span> 4xx</span>
                    <span><i class="ov-swatch ov-swatch-error" aria-hidden="true"></i><span data-stat="server_error_count">0</span> 5xx</span>
                </p>
                <svg class="traffic-chart" id="traffic-chart" viewBox="0 0 600 160" preserveAspectRatio="none" role="img" aria-label="Requests over the last 5 minutes"></svg>
                <div class="ov-rates-axis muted" aria-hidden="true">
                    <span id="traffic-chart-from">5 min ago</span>
                    <span class="traffic-chart-legend"><i class="traffic-p95-key"></i><span>p95 time taken (right scale, <span id="traffic-chart-p95">0 ms</span> max)</span></span>
                    <span id="traffic-chart-to">now</span>
                </div>
            </div>

            <div class="ov-card-head traffic-head">
                <h2>Requests</h2>
                <label class="checkbox-label app-log-filter">
                    <input type="checkbox" id="traffic-errors-only">
                    Errors only
                </label>
                <span class="muted">Newest first &middot; times in UTC</span>
            </div>
            <div class="ov-table-wrap traffic-list">
                <table class="deployments-table traffic-table">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Request</th>
                            <th>Status</th>
                            <th>Time taken</th>
                            <th>Size</th>
                        </tr>
                    </thead>
                    <tbody id="traffic-requests"></tbody>
                </table>
                <p class="ov-empty muted" id="traffic-empty">No requests in the last 5 minutes yet. New ones show up here as they arrive.</p>
            </div>
            <p class="traffic-note muted">Shows the last 5 minutes and every request while this page is open. Record to download requests as CSV (up to 60 minutes or 200,000 requests).</p>
        </section>
    </div>
</main>
