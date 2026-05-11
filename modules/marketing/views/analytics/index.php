<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<style>
    .marketing-analytics-section-title {
        margin: 0 0 14px;
        font-weight: 700;
        letter-spacing: .02em;
        color: #0f172a;
    }
    .marketing-analytics-section {
        margin-bottom: 24px;
    }
    .marketing-analytics-grid {
        display: grid;
        grid-template-columns: repeat(5, minmax(0, 1fr));
        gap: 16px;
    }
    .marketing-metric-card {
        border-radius: 14px;
        padding: 18px 16px;
        min-height: 116px;
        border: 1px solid rgba(148, 163, 184, .22);
        box-shadow: 0 10px 24px rgba(15, 23, 42, .05);
    }
    .marketing-metric-card .metric-label {
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: .08em;
        color: inherit;
        opacity: .78;
    }
    .marketing-metric-card .metric-value {
        font-size: 34px;
        font-weight: 700;
        line-height: 1.1;
        margin-top: 10px;
        color: inherit;
    }
    .marketing-metric-card .metric-helper {
        font-size: 12px;
        margin-top: 8px;
        color: inherit;
        opacity: .72;
    }
    .marketing-metric-blue { background: #eef6ff; color: #2563eb; }
    .marketing-metric-green { background: #ecfdf3; color: #15803d; }
    .marketing-metric-amber { background: #fffbeb; color: #b45309; }
    .marketing-metric-red { background: #fef2f2; color: #b91c1c; }
    .marketing-metric-slate { background: #f8fafc; color: #475569; }
    .marketing-metric-purple { background: #f5f3ff; color: #6d28d9; }
    .marketing-analytics-note {
        margin-top: 14px;
        padding: 14px 16px;
        border-radius: 14px;
        border: 1px solid rgba(59, 130, 246, .16);
        background: linear-gradient(135deg, #eff6ff 0%, #f8fafc 100%);
        color: #1e3a8a;
        font-size: 13px;
    }
    .marketing-analytics-note strong {
        color: #0f172a;
    }
    .marketing-analytics-badge-row {
        margin-top: 12px;
    }
    .marketing-analytics-badge {
        display: inline-block;
        margin: 0 8px 8px 0;
        padding: 7px 10px;
        border-radius: 999px;
        background: #ffffff;
        border: 1px solid rgba(148, 163, 184, .28);
        color: #334155;
        font-size: 12px;
        font-weight: 600;
    }
    @media (max-width: 1199px) {
        .marketing-analytics-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }
    @media (max-width: 767px) {
        .marketing-analytics-grid {
            grid-template-columns: repeat(1, minmax(0, 1fr));
        }
    }
</style>
<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-12">
                <div class="panel_s">
                    <div class="panel-body">
                        <?php
                        $stats = isset($stats) && is_array($stats) ? $stats : [];
                        $stats = array_replace_recursive([
                            'campaigns' => ['total' => 0, 'draft' => 0, 'sending' => 0, 'sent' => 0, 'failed' => 0],
                            'recipients' => ['total' => 0, 'queued' => 0, 'processing' => 0, 'sent' => 0, 'failed' => 0],
                            'events' => [
                                'total_records' => 0,
                                'accepted_sent' => 0,
                                'accepted_sent_events' => 0,
                                'delivered' => 0,
                                'open' => 0,
                                'click' => 0,
                                'human_clicks' => 0,
                                'unique_human_clicks' => 0,
                                'tracked_link_human_clicks' => 0,
                                'tracked_link_unique_human_clicks' => 0,
                                'raw_click_events' => 0,
                                'bounce' => 0,
                                'complaint' => 0,
                                'failed_rejected' => 0,
                                'send_readiness_failed' => 0,
                                'unsubscribe' => 0,
                                'delivery_delay' => 0,
                            ],
                        ], $stats);
                        $recent = isset($recent) && is_array($recent) ? $recent : [];
                        $normalizedRecent = [];
                        foreach ($recent as $event) {
                            if (!is_array($event)) {
                                continue;
                            }

                            $normalizedRecent[] = array_merge([
                                'id' => 0,
                                'event_type' => '',
                                'email' => '',
                                'provider' => '',
                                'url' => '',
                                'event_at' => null,
                            ], $event);
                        }
                        $recent = $normalizedRecent;
                        $events_pagination = isset($events_pagination) && is_array($events_pagination)
                            ? array_merge(['page' => 1, 'per_page' => 20, 'total' => 0, 'total_pages' => 1], $events_pagination)
                            : ['page' => 1, 'per_page' => 20, 'total' => 0, 'total_pages' => 1];
                        $top_clicked_links = isset($top_clicked_links) && is_array($top_clicked_links) ? $top_clicked_links : [];
                        $eventTypeLabels = [
                            'queued' => 'Recipients Queued',
                            'send_start' => 'Send Started',
                            'batch_processed' => 'Batch Processed',
                            'sent' => 'Accepted by SMTP / SES',
                            'delivered' => 'Delivered',
                            'open' => 'Open',
                            'opened' => 'Open',
                            'click' => 'Click',
                            'clicked' => 'Click',
                            'bounce' => 'Bounce',
                            'bounced' => 'Bounce',
                            'complaint' => 'Complaint',
                            'complained' => 'Complaint',
                            'unsubscribe' => 'Unsubscribed',
                            'unsubscribed' => 'Unsubscribed',
                            'failed' => 'Send Failed',
                            'rejected' => _l('marketing_event_rejected'),
                            'rendering_failure' => _l('marketing_event_rendering_failure'),
                            'delivery_delay' => _l('marketing_event_delivery_delay'),
                            'subscription_confirmation' => 'Subscription Confirmation',
                            'notification' => 'Notification',
                            'send_readiness_failed' => 'Send Readiness Failed',
                            'retry_triggered' => 'Retry Triggered',
                        ];
                        $eventTypeBadgeClasses = [
                            'queued' => 'warning',
                            'send_start' => 'warning',
                            'batch_processed' => 'warning',
                            'sent' => 'success',
                            'delivered' => 'success',
                            'open' => 'success',
                            'opened' => 'success',
                            'click' => 'success',
                            'clicked' => 'success',
                            'bounce' => 'danger',
                            'bounced' => 'danger',
                            'complaint' => 'danger',
                            'complained' => 'danger',
                            'unsubscribe' => 'default',
                            'unsubscribed' => 'default',
                            'failed' => 'danger',
                            'rejected' => 'danger',
                            'rendering_failure' => 'danger',
                            'delivery_delay' => 'warning',
                            'subscription_confirmation' => 'info',
                            'notification' => 'info',
                            'send_readiness_failed' => 'danger',
                            'retry_triggered' => 'warning',
                        ];
                        $renderMetricCard = static function ($label, $value, $class, $helper = '') {
                            ?>
                            <div class="marketing-metric-card <?= $class; ?>">
                                <div class="metric-label"><?= html_escape($label); ?></div>
                                <div class="metric-value"><?= (int) $value; ?></div>
                                <?php if ($helper !== '') { ?>
                                    <div class="metric-helper"><?= html_escape($helper); ?></div>
                                <?php } ?>
                            </div>
                            <?php
                        };
                        $buildAnalyticsPageUrl = static function ($page) use ($events_pagination) {
                            $query = [
                                'page' => max(1, (int) $page),
                                'per_page' => (int) $events_pagination['per_page'],
                            ];

                            return admin_url('marketing/analytics?' . http_build_query($query));
                        };
                        $acceptedSentHelper = '';
                        if ((int) ($stats['events']['accepted_sent_events'] ?? 0) !== (int) $stats['events']['accepted_sent']) {
                            $acceptedSentHelper = sprintf(_l('marketing_campaign_raw_sent_events_helper'), (int) $stats['events']['accepted_sent_events']);
                        }
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
                                'warnings' => [],
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
                                'warnings' => [],
                            ];
                        ?>
                        <div class="tw-flex tw-items-start tw-justify-between tw-gap-4">
                            <div>
                                <h4 class="no-margin"><?= _l('marketing_analytics'); ?></h4>
                                <p class="text-muted mtop10"><?= _l('marketing_analytics_placeholder'); ?></p>
                            </div>
                            <?php if (!empty($can_run_retention_cleanup)) { ?>
                                <div class="tw-flex-shrink-0">
                                    <?= form_open(admin_url('marketing/analytics'), ['class' => 'inline-block']); ?>
                                        <input type="hidden" name="run_retention_cleanup" value="1">
                                        <button type="submit" class="btn btn-default">Run Retention Cleanup</button>
                                    <?= form_close(); ?>
                                </div>
                            <?php } ?>
                        </div>
                        <div class="marketing-analytics-note">
                            <strong>Retention policy:</strong> Audit-critical events such as unsubscribes, suppressions, bounces, complaints, and operator actions are retained by design.
                            Bounded cleanup removes only non-critical telemetry and safely resets stale click aggregates for old tracking links.
                            <div class="marketing-analytics-badge-row">
                                <span class="marketing-analytics-badge">Event candidates: <?= (int) ($retentionDiagnostics['events_cleanup_candidate_preview'] ?? 0); ?><?= !empty($retentionDiagnostics['events_cleanup_candidate_more']) ? '+' : ''; ?></span>
                                <span class="marketing-analytics-badge">Webhook candidates: <?= (int) ($retentionDiagnostics['webhook_logs_cleanup_candidate_preview'] ?? 0); ?><?= !empty($retentionDiagnostics['webhook_logs_cleanup_candidate_more']) ? '+' : ''; ?></span>
                                <span class="marketing-analytics-badge">Click aggregate candidates: <?= (int) ($retentionDiagnostics['click_aggregate_candidate_preview'] ?? 0); ?><?= !empty($retentionDiagnostics['click_aggregate_candidate_more']) ? '+' : ''; ?></span>
                            </div>
                        </div>
                        <hr />
                        <div class="marketing-analytics-section">
                            <div>
                                <h5 class="marketing-analytics-section-title"><?= _l('marketing_campaigns'); ?></h5>
                            </div>
                            <div class="marketing-analytics-grid">
                                <?php $renderMetricCard('Total Campaigns', $stats['campaigns']['total'], 'marketing-metric-blue'); ?>
                                <?php $renderMetricCard('Draft Campaigns', $stats['campaigns']['draft'], 'marketing-metric-slate'); ?>
                                <?php $renderMetricCard('Sending Campaigns', $stats['campaigns']['sending'], 'marketing-metric-amber'); ?>
                                <?php $renderMetricCard('Sent Campaigns', $stats['campaigns']['sent'], 'marketing-metric-green'); ?>
                                <?php $renderMetricCard('Failed Campaigns', $stats['campaigns']['failed'], 'marketing-metric-red'); ?>
                            </div>
                        </div>
                        <div class="marketing-analytics-section">
                            <div>
                                <h5 class="marketing-analytics-section-title">Recipients</h5>
                            </div>
                            <div class="marketing-analytics-grid">
                                <?php $renderMetricCard('Queued Recipients', $stats['recipients']['queued'], 'marketing-metric-blue'); ?>
                                <?php $renderMetricCard('Processing Recipients', $stats['recipients']['processing'], 'marketing-metric-amber'); ?>
                                <?php $renderMetricCard('Sent Recipients', $stats['recipients']['sent'], 'marketing-metric-green'); ?>
                                <?php $renderMetricCard('Failed Recipients', $stats['recipients']['failed'], 'marketing-metric-red'); ?>
                                <?php $renderMetricCard('Total Recipients', $stats['recipients']['total'], 'marketing-metric-slate'); ?>
                            </div>
                        </div>
                        <div class="marketing-analytics-section">
                            <div>
                                <h5 class="marketing-analytics-section-title"><?= _l('marketing_link_click_analytics'); ?></h5>
                            </div>
                            <div class="marketing-analytics-grid">
                                <?php $renderMetricCard(_l('marketing_human_clicks'), $stats['events']['human_clicks'], 'marketing-metric-blue', sprintf(_l('marketing_tracked_link_human_clicks_helper'), (int) ($stats['events']['tracked_link_human_clicks'] ?? 0))); ?>
                                <?php $renderMetricCard(_l('marketing_unique_human_clicks'), $stats['events']['unique_human_clicks'], 'marketing-metric-blue', sprintf(_l('marketing_tracked_link_unique_human_clicks_helper'), (int) ($stats['events']['tracked_link_unique_human_clicks'] ?? 0))); ?>
                                <?php $renderMetricCard(_l('marketing_raw_click_events'), $stats['events']['raw_click_events'], 'marketing-metric-slate', _l('marketing_raw_click_events_note')); ?>
                            </div>
                        </div>
                        <div class="marketing-analytics-section">
                            <div>
                                <h5 class="marketing-analytics-section-title">Event Activity</h5>
                            </div>
                            <div class="marketing-analytics-grid">
                                <?php $renderMetricCard('Total Event Records', $stats['events']['total_records'], 'marketing-metric-slate', 'All rows from marketing_events'); ?>
                                <?php $renderMetricCard(_l('marketing_accepted_sent'), $stats['events']['accepted_sent'], 'marketing-metric-green', $acceptedSentHelper); ?>
                                <?php $renderMetricCard('Delivered', $stats['events']['delivered'], 'marketing-metric-green'); ?>
                                <?php $renderMetricCard('Opens', $stats['events']['open'], 'marketing-metric-blue'); ?>
                                <?php $renderMetricCard('Bounces', $stats['events']['bounce'], 'marketing-metric-red'); ?>
                                <?php $renderMetricCard('Complaints', $stats['events']['complaint'], 'marketing-metric-red'); ?>
                                <?php $renderMetricCard(_l('marketing_failed_rejections'), $stats['events']['failed_rejected'], 'marketing-metric-red'); ?>
                                <?php $renderMetricCard(_l('marketing_send_readiness_failed'), $stats['events']['send_readiness_failed'], 'marketing-metric-amber'); ?>
                                <?php $renderMetricCard(_l('marketing_unsubscribes'), $stats['events']['unsubscribe'], 'marketing-metric-slate'); ?>
                                <?php $renderMetricCard('Delivery Delays', $stats['events']['delivery_delay'], 'marketing-metric-amber'); ?>
                            </div>
                        </div>
                        <hr />
                        <?php if (empty($recent)) { ?>
                            <p class="text-muted no-margin"><?= _l('marketing_no_records_yet'); ?></p>
                        <?php } else { ?>
                            <div class="table-responsive">
                                <table class="table dt-table">
                                    <thead>
                                        <tr>
                                            <th><?= _l('id'); ?></th>
                                            <th><?= _l('marketing_event_type'); ?></th>
                                            <th><?= _l('marketing_email'); ?></th>
                                            <th><?= _l('marketing_provider'); ?></th>
                                            <th><?= _l('marketing_click_target'); ?></th>
                                            <th><?= _l('marketing_event_at'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recent as $event) { ?>
                                            <?php
                                            $eventType = (string) $event['event_type'];
                                            $eventLabel = $eventTypeLabels[$eventType] ?? ucwords(str_replace('_', ' ', $eventType));
                                            $badgeClass = $eventTypeBadgeClasses[$eventType] ?? 'default';
                                            $eventAt = !empty($event['event_at']) ? _dt($event['event_at']) : '-';
                                            ?>
                                            <tr>
                                                <td><?= (int) $event['id']; ?></td>
                                                <td><span class="label label-<?= html_escape($badgeClass); ?>"><?= html_escape($eventLabel); ?></span></td>
                                                <td><?= e($event['email'] ?: '-'); ?></td>
                                                <td><?= e($event['provider']); ?></td>
                                                <td><?= e($event['url'] ?: '-'); ?></td>
                                                <td><?= e($eventAt); ?></td>
                                            </tr>
                                        <?php } ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="clearfix mtop15">
                                <?php if ($events_pagination['total_pages'] > 1) { ?>
                                    <ul class="pagination no-margin pull-right">
                                        <li class="<?= $events_pagination['page'] <= 1 ? 'disabled' : ''; ?>">
                                            <a href="<?= $events_pagination['page'] <= 1 ? '#' : $buildAnalyticsPageUrl($events_pagination['page'] - 1); ?>">Previous</a>
                                        </li>
                                        <?php for ($page = 1; $page <= $events_pagination['total_pages']; $page++) { ?>
                                            <li class="<?= $page === (int) $events_pagination['page'] ? 'active' : ''; ?>">
                                                <a href="<?= $buildAnalyticsPageUrl($page); ?>"><?= $page; ?></a>
                                            </li>
                                        <?php } ?>
                                        <li class="<?= $events_pagination['page'] >= $events_pagination['total_pages'] ? 'disabled' : ''; ?>">
                                            <a href="<?= $events_pagination['page'] >= $events_pagination['total_pages'] ? '#' : $buildAnalyticsPageUrl($events_pagination['page'] + 1); ?>">Next</a>
                                        </li>
                                    </ul>
                                <?php } ?>
                            </div>
                        <?php } ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php init_tail(); ?>
</body>
</html>
