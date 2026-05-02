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
                        ?>
                        <div class="tw-flex tw-items-start tw-justify-between tw-gap-4">
                            <div>
                                <h4 class="no-margin"><?= _l('marketing_analytics'); ?></h4>
                                <p class="text-muted mtop10"><?= _l('marketing_analytics_placeholder'); ?></p>
                            </div>
                            <?php if (!empty($can_clear_event_history)) { ?>
                                <div class="tw-flex-shrink-0">
                                    <?= form_open(admin_url('marketing/analytics'), ['class' => 'inline-block', 'onsubmit' => "return confirm('" . html_escape(_l('marketing_clear_event_history_confirm')) . "');"]); ?>
                                        <input type="hidden" name="clear_marketing_events" value="1">
                                        <button type="submit" class="btn btn-danger"><?= _l('marketing_clear_event_history'); ?></button>
                                    <?= form_close(); ?>
                                </div>
                            <?php } ?>
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
                                <h5 class="marketing-analytics-section-title">Event Activity</h5>
                            </div>
                            <div class="marketing-analytics-grid">
                                <?php $renderMetricCard('Total Event Records', $stats['events']['total_records'], 'marketing-metric-slate', 'All rows from marketing_events'); ?>
                                <?php $renderMetricCard(_l('marketing_accepted_sent'), $stats['events']['accepted_sent'], 'marketing-metric-green'); ?>
                                <?php $renderMetricCard('Delivered', $stats['events']['delivered'], 'marketing-metric-green'); ?>
                                <?php $renderMetricCard('Opens', $stats['events']['open'], 'marketing-metric-blue'); ?>
                                <?php $renderMetricCard('Clicks', $stats['events']['click'], 'marketing-metric-blue'); ?>
                                <?php $renderMetricCard('Bounces', $stats['events']['bounce'], 'marketing-metric-red'); ?>
                                <?php $renderMetricCard('Complaints', $stats['events']['complaint'], 'marketing-metric-red'); ?>
                                <?php $renderMetricCard(_l('marketing_failed_rejections'), $stats['events']['failed_rejected'], 'marketing-metric-red'); ?>
                                <?php $renderMetricCard(_l('marketing_unsubscribes'), $stats['events']['unsubscribe'], 'marketing-metric-slate'); ?>
                                <?php $renderMetricCard('Delivery Delays', $stats['events']['delivery_delay'], 'marketing-metric-amber'); ?>
                            </div>
                        </div>
                        <hr />
                        <?php if (count($recent) === 0) { ?>
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
                                            ?>
                                            <tr>
                                                <td><?= (int) $event['id']; ?></td>
                                                <td><span class="label label-<?= html_escape($badgeClass); ?>"><?= html_escape($eventLabel); ?></span></td>
                                                <td><?= e($event['email'] ?: '-'); ?></td>
                                                <td><?= e($event['provider']); ?></td>
                                                <td><?= e($event['url'] ?: '-'); ?></td>
                                                <td><?= e(_dt($event['event_at'])); ?></td>
                                            </tr>
                                        <?php } ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="clearfix mtop15">
                                <div class="pull-left text-muted">
                                    <?= sprintf('Showing %d to %d of %d event records', (($events_pagination['page'] - 1) * $events_pagination['per_page']) + 1, min($events_pagination['page'] * $events_pagination['per_page'], $events_pagination['total']), $events_pagination['total']); ?>
                                </div>
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
