<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-12">
                <div class="panel_s">
                    <div class="panel-body">
                        <h4 class="no-margin"><?= _l('marketing_dashboard'); ?></h4>
                        <p class="text-muted mtop10"><?= _l('marketing_phase_1_notice'); ?></p>
                        <hr />
                        <div class="row">
                            <div class="col-md-3">
                                <div class="tw-bg-white tw-p-15 tw-rounded tw-border">
                                    <span class="text-muted text-uppercase small"><?= _l('marketing_audiences'); ?></span>
                                    <h3 class="tw-mt-10"><?= html_escape($total_lists); ?></h3>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="tw-bg-white tw-p-15 tw-rounded tw-border">
                                    <span class="text-muted text-uppercase small"><?= _l('marketing_contacts'); ?></span>
                                    <h3 class="tw-mt-10"><?= html_escape($total_contacts); ?></h3>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="tw-bg-white tw-p-15 tw-rounded tw-border">
                                    <span class="text-muted text-uppercase small"><?= _l('marketing_total_memberships'); ?></span>
                                    <h3 class="tw-mt-10"><?= html_escape($total_memberships); ?></h3>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="tw-bg-white tw-p-15 tw-rounded tw-border">
                                    <span class="text-muted text-uppercase small"><?= _l('marketing_campaigns'); ?></span>
                                    <h3 class="tw-mt-10"><?= html_escape($total_campaigns); ?></h3>
                                </div>
                            </div>
                        </div>
                        <div class="row tw-mt-15">
                            <div class="col-md-3">
                                <div class="tw-bg-white tw-p-15 tw-rounded tw-border">
                                    <span class="text-muted text-uppercase small"><?= _l('marketing_delivery_rate'); ?></span>
                                    <h3 class="tw-mt-10"><?= html_escape(number_format($delivery_rate, 2)); ?>%</h3>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="tw-bg-white tw-p-15 tw-rounded tw-border">
                                    <span class="text-muted text-uppercase small"><?= _l('marketing_open_rate'); ?></span>
                                    <h3 class="tw-mt-10"><?= html_escape(number_format($open_rate, 2)); ?>%</h3>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="tw-bg-white tw-p-15 tw-rounded tw-border">
                                    <span class="text-muted text-uppercase small"><?= _l('marketing_bounce_rate'); ?></span>
                                    <h3 class="tw-mt-10"><?= html_escape(number_format($bounce_rate, 2)); ?>%</h3>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="tw-bg-white tw-p-15 tw-rounded tw-border">
                                    <span class="text-muted text-uppercase small"><?= _l('marketing_active_suppressions'); ?></span>
                                    <h3 class="tw-mt-10"><?= html_escape($active_suppressions); ?></h3>
                                </div>
                            </div>
                        </div>
                        <div class="row tw-mt-15">
                            <div class="col-md-12">
                                <div class="panel_s" style="border:1px solid #e2e8f0;background:linear-gradient(135deg,#fff8f1 0%,#eff6ff 100%);">
                                    <div class="panel-body">
                                        <div class="row">
                                            <div class="col-md-8">
                                                <h4 class="no-margin"><?= _l('marketing_link_click_analytics'); ?></h4>
                                                <p class="text-muted mtop10"><?= _l('marketing_click_analytics_note'); ?></p>
                                            </div>
                                            <div class="col-md-2">
                                                <div class="tw-bg-white tw-p-15 tw-rounded tw-border">
                                                    <span class="text-muted text-uppercase small"><?= _l('marketing_human_clicks'); ?></span>
                                                    <h3 class="tw-mt-10" style="color:#ea580c;"><?= html_escape($click_analytics['human_clicks']); ?></h3>
                                                </div>
                                            </div>
                                            <div class="col-md-2">
                                                <div class="tw-bg-white tw-p-15 tw-rounded tw-border">
                                                    <span class="text-muted text-uppercase small"><?= _l('marketing_unique_human_clicks'); ?></span>
                                                    <h3 class="tw-mt-10" style="color:#2563eb;"><?= html_escape($click_analytics['unique_human_clicks']); ?></h3>
                                                </div>
                                            </div>
                                        </div>
                                        <p class="text-muted mtop15 no-margin"><?= sprintf(_l('marketing_raw_click_events_helper'), (int) $click_analytics['raw_click_events']); ?></p>

                                        <?php if (!$click_analytics['has_data']) { ?>
                                            <div class="alert alert-info mtop15 mbot0">
                                                <?= _l('marketing_click_analytics_empty'); ?>
                                            </div>
                                        <?php } else { ?>
                                            <div class="row mtop20">
                                                <div class="col-md-6">
                                                    <div style="position:relative;height:360px;">
                                                        <canvas id="marketing-click-analytics-chart" height="360"></canvas>
                                                    </div>
                                                    <div id="marketing-click-chart-fallback" class="hide">
                                                        <div class="alert alert-info">
                                                            <?= _l('marketing_click_chart_fallback'); ?>
                                                        </div>
                                                        <div class="table-responsive">
                                                            <table class="table table-striped">
                                                                <thead>
                                                                    <tr>
                                                                        <th><?= _l('marketing_click_target'); ?></th>
                                                                        <th><?= _l('marketing_human_clicks'); ?></th>
                                                                        <th><?= _l('marketing_unique_human_clicks'); ?></th>
                                                                    </tr>
                                                                </thead>
                                                                <tbody>
                                                                    <?php
                                                                    $fallbackRows = $click_analytics['chart_mode'] === 'links' ? $click_analytics['top_clicked_links'] : $click_analytics['clicks_by_campaign'];
                                                                    foreach ($fallbackRows as $row) {
                                                                        $label = $click_analytics['chart_mode'] === 'links' ? $row['url'] : $row['campaign_name'];
                                                                        ?>
                                                                        <tr>
                                                                            <td title="<?= html_escape($label); ?>"><?= html_escape(mb_strimwidth($label, 0, 62, '...')); ?></td>
                                                                            <td><?= (int) $row['count']; ?></td>
                                                                            <td><?= (int) ($row['unique_count'] ?? 0); ?></td>
                                                                        </tr>
                                                                    <?php } ?>
                                                                </tbody>
                                                            </table>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <h5 class="bold"><?= _l($click_analytics['chart_mode'] === 'links' ? 'marketing_top_clicked_links' : 'marketing_clicks_by_campaign'); ?></h5>
                                                    <div class="table-responsive">
                                                        <table class="table table-striped">
                                                            <thead>
                                                                <tr>
                                                                    <th><?= _l('marketing_click_target'); ?></th>
                                                                    <th><?= _l('marketing_human_clicks'); ?></th>
                                                                    <th><?= _l('marketing_unique_human_clicks'); ?></th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                <?php
                                                                $detailRows = $click_analytics['chart_mode'] === 'links' ? $click_analytics['top_clicked_links'] : $click_analytics['clicks_by_campaign'];
                                                                foreach ($detailRows as $row) {
                                                                    $label = $click_analytics['chart_mode'] === 'links' ? $row['url'] : $row['campaign_name'];
                                                                    ?>
                                                                    <tr>
                                                                        <td title="<?= html_escape($label); ?>"><?= html_escape(mb_strimwidth($label, 0, 62, '...')); ?></td>
                                                                        <td><span class="label label-primary"><?= (int) $row['count']; ?></span></td>
                                                                        <td><span class="label label-default"><?= (int) ($row['unique_count'] ?? 0); ?></span></td>
                                                                    </tr>
                                                                <?php } ?>
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php } ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php
                        $queueDiagnostics = isset($queue_diagnostics) && is_array($queue_diagnostics) ? $queue_diagnostics : [];
                        $queueRateLimit = isset($queueDiagnostics['rate_limit_snapshot']) && is_array($queueDiagnostics['rate_limit_snapshot'])
                            ? $queueDiagnostics['rate_limit_snapshot']
                            : [];
                        $recentQueueWarnings = isset($queueDiagnostics['recent_warning_counts']) && is_array($queueDiagnostics['recent_warning_counts'])
                            ? $queueDiagnostics['recent_warning_counts']
                            : [];
                        $recentFailureMetrics = isset($queueDiagnostics['recent_failure_metric_counts']) && is_array($queueDiagnostics['recent_failure_metric_counts'])
                            ? $queueDiagnostics['recent_failure_metric_counts']
                            : [];
                        $recentOperatorActions = isset($queueDiagnostics['recent_operator_actions']) && is_array($queueDiagnostics['recent_operator_actions'])
                            ? $queueDiagnostics['recent_operator_actions']
                            : [];
                        $retentionDiagnostics = isset($retention_diagnostics) && is_array($retention_diagnostics)
                            ? $retention_diagnostics
                            : [];
                        $canManageQueue = !empty($can_manage_queue);
                        ?>
                        <div class="row tw-mt-15">
                            <div class="col-md-12">
                                <div class="panel_s" style="border:1px solid #e2e8f0;background:#f8fafc;">
                                    <div class="panel-body">
                                        <div class="row">
                                            <div class="col-md-8">
                                                <h4 class="no-margin">Queue Diagnostics</h4>
                                                <p class="text-muted mtop10">Live worker, processing, and recovery telemetry from existing queue and event records.</p>
                                            </div>
                                            <div class="col-md-4 text-right">
                                                <?php if (!empty($queueDiagnostics['queue_paused'])) { ?>
                                                    <span class="label label-warning">Queue Paused</span>
                                                <?php } else { ?>
                                                    <span class="label label-success">Queue Active</span>
                                                <?php } ?>
                                                <?php if (!empty($queueDiagnostics['schema_ready'])) { ?>
                                                    <span class="label label-success">Schema Ready</span>
                                                <?php } else { ?>
                                                    <span class="label label-warning">Schema Partial</span>
                                                <?php } ?>
                                            </div>
                                        </div>
                                        <div class="row mtop15">
                                            <div class="col-md-2">
                                                <div class="tw-bg-white tw-p-15 tw-rounded tw-border">
                                                    <span class="text-muted text-uppercase small">Worker Leases</span>
                                                    <h3 class="tw-mt-10"><?= (int) ($queueDiagnostics['campaign_worker_leases_active'] ?? 0); ?></h3>
                                                </div>
                                            </div>
                                            <div class="col-md-2">
                                                <div class="tw-bg-white tw-p-15 tw-rounded tw-border">
                                                    <span class="text-muted text-uppercase small">Stale Worker Leases</span>
                                                    <h3 class="tw-mt-10"><?= (int) ($queueDiagnostics['campaign_worker_leases_stale'] ?? 0); ?></h3>
                                                </div>
                                            </div>
                                            <div class="col-md-2">
                                                <div class="tw-bg-white tw-p-15 tw-rounded tw-border">
                                                    <span class="text-muted text-uppercase small">Processing</span>
                                                    <h3 class="tw-mt-10"><?= (int) ($queueDiagnostics['recipients_processing'] ?? 0); ?></h3>
                                                </div>
                                            </div>
                                            <div class="col-md-2">
                                                <div class="tw-bg-white tw-p-15 tw-rounded tw-border">
                                                    <span class="text-muted text-uppercase small">Stale Processing</span>
                                                    <h3 class="tw-mt-10"><?= (int) ($queueDiagnostics['recipients_processing_stale'] ?? 0); ?></h3>
                                                </div>
                                            </div>
                                            <div class="col-md-2">
                                                <div class="tw-bg-white tw-p-15 tw-rounded tw-border">
                                                    <span class="text-muted text-uppercase small">Queued</span>
                                                    <h3 class="tw-mt-10"><?= (int) ($queueDiagnostics['recipients_queued'] ?? 0); ?></h3>
                                                </div>
                                            </div>
                                            <div class="col-md-2">
                                                <div class="tw-bg-white tw-p-15 tw-rounded tw-border">
                                                    <span class="text-muted text-uppercase small">Hourly Remaining</span>
                                                    <h3 class="tw-mt-10"><?= html_escape($queueRateLimit['hourly_remaining'] === null ? '∞' : (string) (int) $queueRateLimit['hourly_remaining']); ?></h3>
                                                </div>
                                            </div>
                                            <div class="col-md-2">
                                                <div class="tw-bg-white tw-p-15 tw-rounded tw-border">
                                                    <span class="text-muted text-uppercase small">Daily Remaining</span>
                                                    <h3 class="tw-mt-10"><?= html_escape($queueRateLimit['daily_remaining'] === null ? '∞' : (string) (int) $queueRateLimit['daily_remaining']); ?></h3>
                                                </div>
                                            </div>
                                            <div class="col-md-2">
                                                <div class="tw-bg-white tw-p-15 tw-rounded tw-border">
                                                    <span class="text-muted text-uppercase small">Failed</span>
                                                    <h3 class="tw-mt-10"><?= (int) ($queueDiagnostics['recipients_failed'] ?? 0); ?></h3>
                                                </div>
                                            </div>
                                        </div>
                                        <?php if ($canManageQueue) { ?>
                                            <div class="mtop15">
                                                <h5 class="bold no-margin">Operator Controls</h5>
                                                <div class="marketing-campaign-actions mtop10">
                                                    <?= form_open(admin_url('marketing/queue_control'), ['class' => 'inline-block']); ?>
                                                        <input type="hidden" name="queue_action" value="<?= !empty($queueDiagnostics['queue_paused']) ? 'resume' : 'pause'; ?>">
                                                        <button type="submit" class="btn btn-<?= !empty($queueDiagnostics['queue_paused']) ? 'success' : 'warning'; ?>">
                                                            <?= !empty($queueDiagnostics['queue_paused']) ? 'Resume Queue' : 'Pause Queue'; ?>
                                                        </button>
                                                    <?= form_close(); ?>
                                                    <?= form_open(admin_url('marketing/queue_control'), ['class' => 'inline-block']); ?>
                                                        <input type="hidden" name="queue_action" value="recover_stale_worker_leases">
                                                        <button type="submit" class="btn btn-default">Recover Stale Worker Leases</button>
                                                    <?= form_close(); ?>
                                                    <?= form_open(admin_url('marketing/queue_control'), ['class' => 'inline-block']); ?>
                                                        <input type="hidden" name="queue_action" value="recover_stale_processing">
                                                        <button type="submit" class="btn btn-default">Recover Stale Processing</button>
                                                    <?= form_close(); ?>
                                                </div>
                                            </div>
                                        <?php } ?>
                                        <div class="mtop15">
                                            <p class="text-muted no-margin">
                                                Recent recovered rows: <strong><?= (int) ($queueDiagnostics['recent_recovered_total'] ?? 0); ?></strong>
                                            </p>
                                            <?php if (!empty($queueDiagnostics['schema_missing']) && is_array($queueDiagnostics['schema_missing'])) { ?>
                                                <p class="text-muted mtop10 no-margin">
                                                    Missing queue schema elements:
                                                    <?= html_escape(implode(', ', array_slice($queueDiagnostics['schema_missing'], 0, 6))); ?>
                                                </p>
                                            <?php } ?>
                                        </div>
                                        <div class="mtop15">
                                            <h5 class="bold no-margin">Recent Queue Warnings</h5>
                                            <?php if (empty($recentQueueWarnings)) { ?>
                                                <p class="text-muted mtop10 no-margin">No recent queue warning payloads were found.</p>
                                            <?php } else { ?>
                                                <div class="mtop10">
                                                    <?php foreach ($recentQueueWarnings as $warningCode => $warningCount) { ?>
                                                        <span class="label label-default" style="display:inline-block;margin:0 8px 8px 0;">
                                                            <?= html_escape(ucwords(str_replace('_', ' ', (string) $warningCode))); ?>: <?= (int) $warningCount; ?>
                                                        </span>
                                                    <?php } ?>
                                                </div>
                                            <?php } ?>
                                        </div>
                                        <div class="mtop15">
                                            <h5 class="bold no-margin">Recent Failure Metrics</h5>
                                            <?php if (empty($recentFailureMetrics)) { ?>
                                                <p class="text-muted mtop10 no-margin">No normalized provider or recipient failure metrics were found recently.</p>
                                            <?php } else { ?>
                                                <div class="mtop10">
                                                    <?php foreach ($recentFailureMetrics as $metricCode => $metricCount) { ?>
                                                        <span class="label label-primary" style="display:inline-block;margin:0 8px 8px 0;">
                                                            <?= html_escape(ucwords(str_replace('_', ' ', (string) $metricCode))); ?>: <?= (int) $metricCount; ?>
                                                        </span>
                                                    <?php } ?>
                                                </div>
                                            <?php } ?>
                                        </div>
                                        <div class="mtop15">
                                            <h5 class="bold no-margin">Recent Operator Actions</h5>
                                            <?php if (empty($recentOperatorActions)) { ?>
                                                <p class="text-muted mtop10 no-margin">No recent manual queue operator actions were found.</p>
                                            <?php } else { ?>
                                                <div class="table-responsive mtop10">
                                                    <table class="table table-striped">
                                                        <thead>
                                                            <tr>
                                                                <th>Action</th>
                                                                <th>Staff ID</th>
                                                                <th>When</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach ($recentOperatorActions as $operatorAction) { ?>
                                                                <tr>
                                                                    <td><?= html_escape(ucwords(str_replace('_', ' ', (string) ($operatorAction['action'] ?? '')))); ?></td>
                                                                    <td><?= (int) ($operatorAction['performed_by'] ?? 0); ?></td>
                                                                    <td><?= !empty($operatorAction['event_at']) ? html_escape((string) $operatorAction['event_at']) : '-'; ?></td>
                                                                </tr>
                                                            <?php } ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            <?php } ?>
                                        </div>
                                        <div class="mtop15">
                                            <h5 class="bold no-margin">Telemetry Retention</h5>
                                            <p class="text-muted mtop10 no-margin">
                                                Cleanup mode:
                                                <strong><?= html_escape((string) ($retentionDiagnostics['cleanup_mode'] ?? 'explicit_only')); ?></strong>.
                                                Event retention:
                                                <strong><?= (int) ($retentionDiagnostics['event_retention_days'] ?? 0); ?>d</strong>.
                                                Webhook log retention:
                                                <strong><?= (int) ($retentionDiagnostics['webhook_log_retention_days'] ?? 0); ?>d</strong>.
                                            </p>
                                            <p class="text-muted mtop10 no-margin">
                                                Hard floor:
                                                <strong><?= (int) ($retentionDiagnostics['retention_floor_days'] ?? 0); ?>d</strong>.
                                                Per-run delete cap:
                                                <strong><?= (int) ($retentionDiagnostics['delete_limit_per_run'] ?? 0); ?></strong>.
                                            </p>
                                            <div class="mtop10">
                                                <span class="label label-default" style="display:inline-block;margin:0 8px 8px 0;">
                                                    Event Cleanup Candidates:
                                                    <?= (int) ($retentionDiagnostics['events_cleanup_candidate_preview'] ?? 0); ?><?= !empty($retentionDiagnostics['events_cleanup_candidate_more']) ? '+' : ''; ?>
                                                </span>
                                                <span class="label label-default" style="display:inline-block;margin:0 8px 8px 0;">
                                                    Webhook Log Candidates:
                                                    <?= (int) ($retentionDiagnostics['webhook_logs_cleanup_candidate_preview'] ?? 0); ?><?= !empty($retentionDiagnostics['webhook_logs_cleanup_candidate_more']) ? '+' : ''; ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-md-6">
                <div class="panel_s">
                    <div class="panel-body">
                        <h4 class="no-margin"><?= _l('marketing_recent_campaigns'); ?></h4>
                        <hr />
                        <?php if (empty($recent_campaigns)) { ?>
                            <p class="text-muted"><?= _l('marketing_no_campaigns_yet'); ?></p>
                        <?php } else { ?>
                            <div class="table-responsive">
                                <table class="table dt-table">
                                    <thead>
                                        <tr>
                                            <th><?= _l('id'); ?></th>
                                            <th><?= _l('marketing_name'); ?></th>
                                            <th><?= _l('marketing_subject'); ?></th>
                                            <th><?= _l('marketing_status'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recent_campaigns as $campaign) { ?>
                                            <tr>
                                                <td><?= html_escape($campaign['id']); ?></td>
                                                <td><?= html_escape($campaign['name']); ?></td>
                                                <td><?= html_escape($campaign['subject']); ?></td>
                                                <td><?= html_escape($campaign['status']); ?></td>
                                            </tr>
                                        <?php } ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php } ?>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="panel_s">
                    <div class="panel-body">
                        <h4 class="no-margin"><?= _l('marketing_recent_activity'); ?></h4>
                        <hr />
                        <?php if (empty($recent_activity)) { ?>
                            <p class="text-muted"><?= _l('marketing_no_activity_yet'); ?></p>
                        <?php } else { ?>
                            <div class="table-responsive">
                                <table class="table dt-table">
                                    <thead>
                                        <tr>
                                            <th><?= _l('marketing_event_type'); ?></th>
                                            <th><?= _l('marketing_provider'); ?></th>
                                            <th><?= _l('marketing_email'); ?></th>
                                            <th><?= _l('marketing_event_at'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recent_activity as $event) { ?>
                                            <tr>
                                                <td><?= html_escape($event['event_type']); ?></td>
                                                <td><?= html_escape($event['provider']); ?></td>
                                                <td><?= html_escape($event['email']); ?></td>
                                                <td><?= html_escape($event['event_at']); ?></td>
                                            </tr>
                                        <?php } ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php } ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php init_tail(); ?>
<?php if ($click_analytics['has_data']) { ?>
<script>
    (function () {
        var chartCanvas = document.getElementById('marketing-click-analytics-chart');
        if (!chartCanvas) {
            return;
        }

        var labels = <?= json_encode(array_values($click_analytics['chart_labels'])); ?>;
        var values = <?= json_encode(array_values($click_analytics['chart_values'])); ?>;
        var fallback = document.getElementById('marketing-click-chart-fallback');
        var palette = ['#f97316', '#2563eb', '#14b8a6', '#e11d48', '#7c3aed', '#eab308', '#0f766e'];

        if (typeof window.Chart === 'undefined') {
            chartCanvas.classList.add('hide');
            if (fallback) {
                fallback.classList.remove('hide');
            }
            return;
        }

        new Chart(chartCanvas, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: values,
                    backgroundColor: palette.slice(0, values.length),
                    borderColor: '#ffffff',
                    borderWidth: 3,
                    hoverOffset: 8
                }]
            },
            options: {
                maintainAspectRatio: false,
                cutout: '58%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            boxWidth: 14,
                            padding: 18,
                            generateLabels: function (chart) {
                                var original = Chart.overrides.doughnut.plugins.legend.labels.generateLabels(chart);
                                return original.map(function (item) {
                                    var text = item.text || '';
                                    item.text = text.length > 40 ? text.substring(0, 37) + '...' : text;
                                    return item;
                                });
                            }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            title: function (tooltipItems) {
                                var item = tooltipItems[0];
                                return labels[item.dataIndex] || '';
                            }
                        }
                    }
                }
            }
        });
    }());
</script>
<?php } ?>
</body>
</html>
