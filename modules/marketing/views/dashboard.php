<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<style>
    .marketing-ops-shell {
        border: 1px solid #e2e8f0;
        background: linear-gradient(180deg, #f8fafc 0%, #ffffff 100%);
        border-radius: 16px;
        overflow: hidden;
    }
    .marketing-ops-section {
        border-top: 1px solid #e2e8f0;
        padding-top: 18px;
        margin-top: 18px;
    }
    .marketing-ops-section:first-of-type {
        border-top: 0;
        padding-top: 0;
        margin-top: 0;
    }
    .marketing-ops-section-title {
        margin: 0;
        font-size: 15px;
        font-weight: 700;
        color: #0f172a;
    }
    .marketing-ops-section-copy {
        margin: 6px 0 0;
        color: #64748b;
        font-size: 13px;
    }
    .marketing-ops-badge {
        display: inline-block;
        margin: 0 8px 8px 0;
        padding: 7px 10px;
        border-radius: 999px;
        background: #eff6ff;
        color: #1d4ed8;
        font-size: 12px;
        font-weight: 600;
    }
    .marketing-ops-status-pill {
        display: inline-flex;
        align-items: center;
        margin: 0 8px 8px 0;
        padding: 7px 11px;
        border-radius: 999px;
        border: 1px solid #dbeafe;
        background: #ffffff;
        color: #0f172a;
        font-size: 12px;
        font-weight: 700;
    }
    .marketing-ops-control-shell,
    .marketing-ops-table-shell {
        margin-top: 14px;
        padding: 16px;
        border-radius: 14px;
        border: 1px solid #e2e8f0;
        background: #ffffff;
    }
    .marketing-ops-control-grid {
        display: grid;
        grid-template-columns: minmax(0, 1.35fr) minmax(280px, .65fr);
        gap: 16px;
    }
    .marketing-ops-button-row .btn {
        margin: 0 10px 10px 0;
    }
    .marketing-ops-list {
        display: grid;
        gap: 10px;
    }
    .marketing-ops-list-row {
        display: flex;
        justify-content: space-between;
        gap: 16px;
        padding-bottom: 10px;
        border-bottom: 1px solid #f1f5f9;
        color: #334155;
        font-size: 13px;
    }
    .marketing-ops-list-row:last-child {
        border-bottom: 0;
        padding-bottom: 0;
    }
    .marketing-ops-list-row strong {
        color: #0f172a;
        font-weight: 700;
    }
    .marketing-ops-empty {
        margin-top: 12px;
        padding: 14px 16px;
        border: 1px dashed #cbd5e1;
        border-radius: 12px;
        color: #64748b;
        background: #ffffff;
    }
    .marketing-ops-metric {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        padding: 16px;
        min-height: 112px;
    }
    .marketing-ops-metric-label {
        display: block;
        color: #64748b;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: .08em;
    }
    .marketing-ops-metric-value {
        display: block;
        margin-top: 10px;
        color: #0f172a;
        font-size: 28px;
        font-weight: 700;
        line-height: 1.1;
    }
    .marketing-ops-metric-helper {
        display: block;
        margin-top: 8px;
        color: #94a3b8;
        font-size: 12px;
    }
    .marketing-ops-retention-grid,
    .marketing-ops-health-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 14px;
    }
    .marketing-ops-compact-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 14px;
        align-items: start;
    }
    .marketing-ops-compact-card {
        min-width: 0;
    }
    .marketing-ops-compact-card .marketing-ops-section-title {
        margin-bottom: 0;
    }
    .marketing-ops-compact-card .marketing-ops-section-copy {
        min-height: 36px;
    }
    .marketing-ops-compact-card .marketing-ops-badge-grid {
        margin-top: 12px;
    }
    .marketing-ops-compact-card .marketing-ops-table-shell {
        max-height: 248px;
        overflow: auto;
    }
    .marketing-ops-badge-grid {
        display: flex;
        flex-wrap: wrap;
        margin-top: 14px;
    }
    @media (max-width: 991px) {
        .marketing-ops-control-grid,
        .marketing-ops-retention-grid,
        .marketing-ops-health-grid,
        .marketing-ops-compact-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }
    @media (max-width: 767px) {
        .marketing-ops-control-grid,
        .marketing-ops-retention-grid,
        .marketing-ops-health-grid,
        .marketing-ops-compact-grid {
            grid-template-columns: repeat(1, minmax(0, 1fr));
        }
        .marketing-ops-compact-card .marketing-ops-section-copy {
            min-height: 0;
        }
    }
</style>
<div id="wrapper">
    <div class="content">
        <?php
        $click_analytics = isset($click_analytics) && is_array($click_analytics)
            ? array_replace_recursive([
                'human_clicks' => 0,
                'unique_human_clicks' => 0,
                'raw_click_events' => 0,
                'tracked_link_human_clicks' => 0,
                'tracked_link_unique_human_clicks' => 0,
                'top_clicked_links' => [],
                'clicks_by_campaign' => [],
                'chart_mode' => 'empty',
                'chart_labels' => [],
                'chart_values' => [],
                'has_data' => false,
            ], $click_analytics)
            : [
                'human_clicks' => 0,
                'unique_human_clicks' => 0,
                'raw_click_events' => 0,
                'tracked_link_human_clicks' => 0,
                'tracked_link_unique_human_clicks' => 0,
                'top_clicked_links' => [],
                'clicks_by_campaign' => [],
                'chart_mode' => 'empty',
                'chart_labels' => [],
                'chart_values' => [],
                'has_data' => false,
            ];
        $recent_campaigns = isset($recent_campaigns) && is_array($recent_campaigns) ? $recent_campaigns : [];
        $recent_activity = isset($recent_activity) && is_array($recent_activity) ? $recent_activity : [];
        $truncate_dashboard_label = static function ($label, $width = 62) {
            $label = (string) $label;
            if (function_exists('mb_strimwidth')) {
                return mb_strimwidth($label, 0, (int) $width, '...');
            }

            return strlen($label) > (int) $width ? substr($label, 0, max(0, (int) $width - 3)) . '...' : $label;
        };
        ?>
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
                                                                            <td title="<?= html_escape($label); ?>"><?= html_escape($truncate_dashboard_label($label)); ?></td>
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
                                                                        <td title="<?= html_escape($label); ?>"><?= html_escape($truncate_dashboard_label($label)); ?></td>
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
                        $queueDiagnostics = array_merge([
                            'queue_paused' => false,
                            'schema_ready' => false,
                            'schema_missing' => [],
                            'rate_limit_snapshot' => [],
                            'recent_warning_counts' => [],
                            'recent_failure_metric_counts' => [],
                            'recent_operator_actions' => [],
                            'campaign_worker_leases_active' => 0,
                            'campaign_worker_leases_stale' => 0,
                            'recipients_processing' => 0,
                            'recipients_processing_stale' => 0,
                            'recipients_queued' => 0,
                            'recipients_failed' => 0,
                            'recent_recovered_total' => 0,
                        ], $queueDiagnostics);
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
                            ? array_merge([
                                'cleanup_mode' => 'explicit_only',
                                'event_retention_days' => 0,
                                'webhook_log_retention_days' => 0,
                                'retention_floor_days' => 30,
                                'delete_limit_per_run' => 500,
                                'events_cleanup_candidate_preview' => 0,
                                'events_cleanup_candidate_more' => false,
                                'webhook_logs_cleanup_candidate_preview' => 0,
                                'webhook_logs_cleanup_candidate_more' => false,
                                'click_aggregate_candidate_preview' => 0,
                                'click_aggregate_candidate_more' => false,
                            ], $retention_diagnostics)
                            : [
                                'cleanup_mode' => 'explicit_only',
                                'event_retention_days' => 0,
                                'webhook_log_retention_days' => 0,
                                'retention_floor_days' => 30,
                                'delete_limit_per_run' => 500,
                                'events_cleanup_candidate_preview' => 0,
                                'events_cleanup_candidate_more' => false,
                                'webhook_logs_cleanup_candidate_preview' => 0,
                                'webhook_logs_cleanup_candidate_more' => false,
                                'click_aggregate_candidate_preview' => 0,
                                'click_aggregate_candidate_more' => false,
                            ];
                        $retentionModeLabel = str_replace('_', ' ', (string) ($retentionDiagnostics['cleanup_mode'] ?? 'explicit_only'));
                        $canManageQueue = !empty($can_manage_queue);
                        ?>
                        <div class="row tw-mt-15">
                            <div class="col-md-12">
                                <div class="panel_s marketing-ops-shell">
                                    <div class="panel-body">
                                        <div class="row">
                                            <div class="col-md-8">
                                                <h4 class="no-margin">Queue Diagnostics</h4>
                                                <p class="text-muted mtop10">Operational controls and recent execution telemetry for queue health, provider pressure, operator actions, and bounded telemetry retention.</p>
                                            </div>
                                            <div class="col-md-4 text-right">
                                                <span class="marketing-ops-status-pill"><?= !empty($queueDiagnostics['queue_paused']) ? 'Queue Paused' : 'Queue Active'; ?></span>
                                                <span class="marketing-ops-status-pill"><?= !empty($queueDiagnostics['schema_ready']) ? 'Schema Ready' : 'Schema Partial'; ?></span>
                                                <span class="marketing-ops-status-pill">Mode: <?= html_escape(ucwords($retentionModeLabel)); ?></span>
                                            </div>
                                        </div>
                                        <div class="marketing-ops-section">
                                            <h5 class="marketing-ops-section-title">Queue Controls</h5>
                                            <p class="marketing-ops-section-copy">Pause, resume, or recover stale queue work without modifying active recipient ownership or deleting telemetry.</p>
                                            <div class="marketing-ops-control-shell">
                                                <div class="marketing-ops-control-grid">
                                                    <div>
                                                        <?php if ($canManageQueue) { ?>
                                                            <div class="marketing-ops-button-row">
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
                                                        <?php } else { ?>
                                                            <div class="marketing-ops-empty">Queue controls are available to marketing settings administrators only.</div>
                                                        <?php } ?>
                                                    </div>
                                                    <div class="marketing-ops-list">
                                                        <div class="marketing-ops-list-row">
                                                            <span>Queue state</span>
                                                            <strong><?= !empty($queueDiagnostics['queue_paused']) ? 'Paused' : 'Active'; ?></strong>
                                                        </div>
                                                        <div class="marketing-ops-list-row">
                                                            <span>Recent recovered rows</span>
                                                            <strong><?= (int) ($queueDiagnostics['recent_recovered_total'] ?? 0); ?></strong>
                                                        </div>
                                                        <div class="marketing-ops-list-row">
                                                            <span>Hourly send capacity</span>
                                                            <strong><?= html_escape($queueRateLimit['hourly_remaining'] === null ? 'Unlimited' : (string) (int) $queueRateLimit['hourly_remaining']); ?></strong>
                                                        </div>
                                                        <div class="marketing-ops-list-row">
                                                            <span>Daily send capacity</span>
                                                            <strong><?= html_escape($queueRateLimit['daily_remaining'] === null ? 'Unlimited' : (string) (int) $queueRateLimit['daily_remaining']); ?></strong>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="marketing-ops-section">
                                            <h5 class="marketing-ops-section-title">Queue Health</h5>
                                            <p class="marketing-ops-section-copy">Live lease, backlog, recovery, and rate-cap telemetry from existing queue state.</p>
                                            <div class="marketing-ops-health-grid mtop15">
                                                <div class="marketing-ops-metric">
                                                    <span class="marketing-ops-metric-label">Worker Leases</span>
                                                    <span class="marketing-ops-metric-value"><?= (int) ($queueDiagnostics['campaign_worker_leases_active'] ?? 0); ?></span>
                                                    <span class="marketing-ops-metric-helper">Active campaign worker ownership markers.</span>
                                                </div>
                                                <div class="marketing-ops-metric">
                                                    <span class="marketing-ops-metric-label">Stale Worker Leases</span>
                                                    <span class="marketing-ops-metric-value"><?= (int) ($queueDiagnostics['campaign_worker_leases_stale'] ?? 0); ?></span>
                                                    <span class="marketing-ops-metric-helper">Eligible for guarded stale recovery only.</span>
                                                </div>
                                                <div class="marketing-ops-metric">
                                                    <span class="marketing-ops-metric-label">Processing Recipients</span>
                                                    <span class="marketing-ops-metric-value"><?= (int) ($queueDiagnostics['recipients_processing'] ?? 0); ?></span>
                                                    <span class="marketing-ops-metric-helper">Rows currently owned by active workers.</span>
                                                </div>
                                                <div class="marketing-ops-metric">
                                                    <span class="marketing-ops-metric-label">Stale Processing</span>
                                                    <span class="marketing-ops-metric-value"><?= (int) ($queueDiagnostics['recipients_processing_stale'] ?? 0); ?></span>
                                                    <span class="marketing-ops-metric-helper">Processing rows past the stale timeout.</span>
                                                </div>
                                                <div class="marketing-ops-metric">
                                                    <span class="marketing-ops-metric-label">Queued Recipients</span>
                                                    <span class="marketing-ops-metric-value"><?= (int) ($queueDiagnostics['recipients_queued'] ?? 0); ?></span>
                                                    <span class="marketing-ops-metric-helper">Sendable recipients waiting for queue execution.</span>
                                                </div>
                                                <div class="marketing-ops-metric">
                                                    <span class="marketing-ops-metric-label">Failed Recipients</span>
                                                    <span class="marketing-ops-metric-value"><?= (int) ($queueDiagnostics['recipients_failed'] ?? 0); ?></span>
                                                    <span class="marketing-ops-metric-helper">Recipient rows currently marked failed.</span>
                                                </div>
                                                <div class="marketing-ops-metric">
                                                    <span class="marketing-ops-metric-label">Hourly Remaining</span>
                                                    <span class="marketing-ops-metric-value"><?= html_escape($queueRateLimit['hourly_remaining'] === null ? '∞' : (string) (int) $queueRateLimit['hourly_remaining']); ?></span>
                                                    <span class="marketing-ops-metric-helper">Current hourly send capacity.</span>
                                                </div>
                                                <div class="marketing-ops-metric">
                                                    <span class="marketing-ops-metric-label">Daily Remaining</span>
                                                    <span class="marketing-ops-metric-value"><?= html_escape($queueRateLimit['daily_remaining'] === null ? '∞' : (string) (int) $queueRateLimit['daily_remaining']); ?></span>
                                                    <span class="marketing-ops-metric-helper">Current daily send capacity.</span>
                                                </div>
                                                <div class="marketing-ops-metric">
                                                    <span class="marketing-ops-metric-label">Recovered Rows</span>
                                                    <span class="marketing-ops-metric-value"><?= (int) ($queueDiagnostics['recent_recovered_total'] ?? 0); ?></span>
                                                    <span class="marketing-ops-metric-helper">Rows recovered in the recent batch telemetry window.</span>
                                                </div>
                                            </div>
                                            <?php if (!empty($queueDiagnostics['schema_missing']) && is_array($queueDiagnostics['schema_missing'])) { ?>
                                                <p class="text-muted mtop15 no-margin">
                                                    Missing queue schema elements:
                                                    <?= html_escape(implode(', ', array_slice($queueDiagnostics['schema_missing'], 0, 6))); ?>
                                                </p>
                                            <?php } ?>
                                        </div>
                                        <div class="marketing-ops-section marketing-ops-compact-grid">
                                            <div class="marketing-ops-compact-card">
                                                <h5 class="marketing-ops-section-title">Recent Warnings</h5>
                                                <p class="marketing-ops-section-copy">Recent queue-level warning payloads from bounded batch telemetry.</p>
                                                <?php if (empty($recentQueueWarnings)) { ?>
                                                    <div class="marketing-ops-empty">No warnings in the recent window.</div>
                                                <?php } else { ?>
                                                    <div class="marketing-ops-badge-grid">
                                                        <?php foreach ($recentQueueWarnings as $warningCode => $warningCount) { ?>
                                                            <span class="marketing-ops-badge">
                                                                <?= html_escape(ucwords(str_replace('_', ' ', (string) $warningCode))); ?>: <?= (int) $warningCount; ?>
                                                            </span>
                                                        <?php } ?>
                                                    </div>
                                                <?php } ?>
                                            </div>
                                            <div class="marketing-ops-compact-card">
                                                <h5 class="marketing-ops-section-title">Failure Metrics</h5>
                                                <p class="marketing-ops-section-copy">Recent normalized provider and recipient failure signals retained for operator review.</p>
                                                <?php if (empty($recentFailureMetrics)) { ?>
                                                    <div class="marketing-ops-empty">No failure signals in the recent diagnostics window.</div>
                                                <?php } else { ?>
                                                    <div class="marketing-ops-badge-grid">
                                                        <?php foreach ($recentFailureMetrics as $metricCode => $metricCount) { ?>
                                                            <span class="marketing-ops-badge">
                                                                <?= html_escape(ucwords(str_replace('_', ' ', (string) $metricCode))); ?>: <?= (int) $metricCount; ?>
                                                            </span>
                                                        <?php } ?>
                                                    </div>
                                                <?php } ?>
                                            </div>
                                            <div class="marketing-ops-compact-card">
                                                <h5 class="marketing-ops-section-title">Operator Audit</h5>
                                                <p class="marketing-ops-section-copy">Recent manual queue and recovery actions recorded for administrator visibility.</p>
                                                <?php if (empty($recentOperatorActions)) { ?>
                                                    <div class="marketing-ops-empty">No recent operator actions.</div>
                                                <?php } else { ?>
                                                    <div class="marketing-ops-table-shell">
                                                        <div class="table-responsive">
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
                                                    </div>
                                                <?php } ?>
                                            </div>
                                        </div>
                                        <div class="marketing-ops-section">
                                            <h5 class="marketing-ops-section-title">Telemetry Retention</h5>
                                            <p class="marketing-ops-section-copy">Bounded explicit cleanup policy for non-critical telemetry and stale click aggregates.</p>
                                            <div class="marketing-ops-retention-grid mtop15">
                                                <div class="marketing-ops-metric">
                                                    <span class="marketing-ops-metric-label">Mode</span>
                                                    <span class="marketing-ops-metric-value"><?= html_escape(ucwords($retentionModeLabel)); ?></span>
                                                    <span class="marketing-ops-metric-helper">Automatic cron deletion remains disabled.</span>
                                                </div>
                                                <div class="marketing-ops-metric">
                                                    <span class="marketing-ops-metric-label">Events</span>
                                                    <span class="marketing-ops-metric-value"><?= (int) ($retentionDiagnostics['event_retention_days'] ?? 0); ?>d</span>
                                                    <span class="marketing-ops-metric-helper">Non-critical event retention window.</span>
                                                </div>
                                                <div class="marketing-ops-metric">
                                                    <span class="marketing-ops-metric-label">Webhooks</span>
                                                    <span class="marketing-ops-metric-value"><?= (int) ($retentionDiagnostics['webhook_log_retention_days'] ?? 0); ?>d</span>
                                                    <span class="marketing-ops-metric-helper">Webhook log retention window.</span>
                                                </div>
                                                <div class="marketing-ops-metric">
                                                    <span class="marketing-ops-metric-label">Floor</span>
                                                    <span class="marketing-ops-metric-value"><?= (int) ($retentionDiagnostics['retention_floor_days'] ?? 0); ?>d</span>
                                                    <span class="marketing-ops-metric-helper">Cleanup never runs below this threshold.</span>
                                                </div>
                                                <div class="marketing-ops-metric">
                                                    <span class="marketing-ops-metric-label">Per-Run Cap</span>
                                                    <span class="marketing-ops-metric-value"><?= (int) ($retentionDiagnostics['delete_limit_per_run'] ?? 0); ?></span>
                                                    <span class="marketing-ops-metric-helper">Maximum rows processed in one cleanup run.</span>
                                                </div>
                                                <div class="marketing-ops-metric">
                                                    <span class="marketing-ops-metric-label">Event Candidates</span>
                                                    <span class="marketing-ops-metric-value"><?= (int) ($retentionDiagnostics['events_cleanup_candidate_preview'] ?? 0); ?><?= !empty($retentionDiagnostics['events_cleanup_candidate_more']) ? '+' : ''; ?></span>
                                                    <span class="marketing-ops-metric-helper">Current bounded preview of event cleanup candidates.</span>
                                                </div>
                                                <div class="marketing-ops-metric">
                                                    <span class="marketing-ops-metric-label">Webhook Candidates</span>
                                                    <span class="marketing-ops-metric-value"><?= (int) ($retentionDiagnostics['webhook_logs_cleanup_candidate_preview'] ?? 0); ?><?= !empty($retentionDiagnostics['webhook_logs_cleanup_candidate_more']) ? '+' : ''; ?></span>
                                                    <span class="marketing-ops-metric-helper">Current bounded preview of webhook log cleanup candidates.</span>
                                                </div>
                                                <div class="marketing-ops-metric">
                                                    <span class="marketing-ops-metric-label">Click Aggregate Candidates</span>
                                                    <span class="marketing-ops-metric-value"><?= (int) ($retentionDiagnostics['click_aggregate_candidate_preview'] ?? 0); ?><?= !empty($retentionDiagnostics['click_aggregate_candidate_more']) ? '+' : ''; ?></span>
                                                    <span class="marketing-ops-metric-helper">Old click tracking counters eligible for safe reset.</span>
                                                </div>
                                            </div>
                                            <div class="marketing-ops-badge-grid">
                                                <span class="marketing-ops-badge">Mode: <?= html_escape(ucwords($retentionModeLabel)); ?></span>
                                                <span class="marketing-ops-badge">Events: <?= (int) ($retentionDiagnostics['event_retention_days'] ?? 0); ?> days</span>
                                                <span class="marketing-ops-badge">Webhooks: <?= (int) ($retentionDiagnostics['webhook_log_retention_days'] ?? 0); ?> days</span>
                                                <span class="marketing-ops-badge">Floor: <?= (int) ($retentionDiagnostics['retention_floor_days'] ?? 0); ?> days</span>
                                                <span class="marketing-ops-badge">Per-run cap: <?= (int) ($retentionDiagnostics['delete_limit_per_run'] ?? 0); ?> rows</span>
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
