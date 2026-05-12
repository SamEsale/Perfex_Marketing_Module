<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<?php
$isWhatsappCampaign = ($campaign['delivery_channel'] ?? 'email') === 'whatsapp';
$showConfigurationWarnings = !empty($campaign_readiness['show_configuration_warnings']);
$readinessGuidance = !empty($campaign_readiness['show_send_readiness_messages'])
    ? array_values(array_unique(array_filter($campaign_readiness['guidance_messages'] ?? [])))
    : [];
$emailTelemetryMessages = !$isWhatsappCampaign
    ? array_values(array_unique(array_filter($smtp_status['telemetry_messages'] ?? [])))
    : [];
$hasEmailTelemetryEvents = ((int) ($event_summary['delivered'] ?? 0)
    + (int) ($event_summary['open'] ?? 0)
    + (int) ($event_summary['click'] ?? 0)
    + (int) ($event_summary['bounce'] ?? 0)
    + (int) ($event_summary['complaint'] ?? 0)
    + (int) ($event_summary['failed_rejected'] ?? 0)
    + (int) ($event_summary['unsubscribe'] ?? 0)
    + (int) ($event_summary['delivery_delay'] ?? 0)) > 0;
$awaitingSesTelemetry = !$isWhatsappCampaign
    && (int) ($recipient_summary['sent'] ?? 0) > 0
    && !$hasEmailTelemetryEvents;
$campaignStatusDisplay = ucfirst((string) ($campaign['status'] ?? 'draft'));
$audienceNameDisplay = trim((string) ($campaign['audience_name'] ?? '')) !== '' ? (string) $campaign['audience_name'] : '-';
$scheduledAtDisplay = !empty($campaign['scheduled_at']) ? (string) $campaign['scheduled_at'] : _l('marketing_campaign_send_now');
$preparedRecipientCount = (int) ($recipient_summary['queued'] ?? 0)
    + (int) ($recipient_summary['processing'] ?? 0)
    + (int) ($recipient_summary['sent'] ?? 0)
    + (int) ($recipient_summary['failed'] ?? 0)
    + (int) ($recipient_summary['suppressed'] ?? 0)
    + (int) ($recipient_summary['skipped'] ?? 0);
$nextStepMessage = $campaign_readiness['primary_message'];

if (!$send_enabled) {
    $nextStepMessage = _l('marketing_campaign_command_next_delivery_disabled');
} elseif ($campaign['status'] === 'scheduled') {
    $nextStepMessage = _l('marketing_campaign_command_next_schedule');
} elseif ($campaign['status'] === 'sending') {
    $nextStepMessage = _l('marketing_campaign_command_next_processing');
} elseif ($can_retry_failed) {
    $nextStepMessage = _l('marketing_campaign_command_next_review_failures');
} elseif ($can_send && (int) ($recipient_summary['queued'] ?? 0) > 0) {
    $nextStepMessage = _l('marketing_campaign_command_next_send');
} elseif ($can_prepare_recipients && (int) ($recipient_summary['queued'] ?? 0) === 0) {
    $nextStepMessage = _l('marketing_campaign_command_next_prepare');
} elseif ($can_edit_campaign) {
    $nextStepMessage = _l('marketing_campaign_command_next_edit');
}
$buildRecipientPageUrl = static function ($page, $statusFilter, $perPage) use ($campaign) {
    $query = [
        'recipient_page' => max(1, (int) $page),
        'recipient_per_page' => max(1, (int) $perPage),
    ];

    if ($statusFilter !== '') {
        $query['recipient_status'] = $statusFilter;
    }

    return admin_url('marketing/campaigns/show/' . $campaign['id'] . '?' . http_build_query($query));
};
?>
<style>
    .marketing-campaign-layout {
        display: grid;
        grid-template-columns: minmax(0, 1.05fr) minmax(0, .95fr);
        gap: 20px;
    }
    .marketing-campaign-stack > .panel_s + .panel_s {
        margin-top: 20px;
    }
    .marketing-campaign-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
    }
    .marketing-campaign-actions .inline-block {
        margin-left: 0 !important;
    }
    .marketing-compact-table th {
        width: 38%;
        color: #475569;
        font-weight: 600;
    }
    .marketing-recipient-filters {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }
    .marketing-code-block {
        white-space: pre-wrap;
        word-break: break-word;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 14px;
        max-height: 340px;
        overflow: auto;
    }
    @media (max-width: 991px) {
        .marketing-campaign-layout {
            grid-template-columns: 1fr;
        }
    }
</style>
<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-12">
                <div class="panel_s">
                    <div class="panel-body">
                        <div class="clearfix">
                            <h4 class="pull-left no-margin"><?= _l('marketing_campaign_detail'); ?></h4>
                            <a href="<?= admin_url('marketing/campaigns'); ?>" class="btn btn-default pull-right"><?= _l('back'); ?></a>
                        </div>
                        <hr />
                        <div class="marketing-campaign-command-center" data-marketing-campaign-command-center="1">
                            <div class="marketing-campaign-command-header">
                                <div>
                                    <h4 class="marketing-campaign-command-title"><?= _l('marketing_campaign_command_center'); ?></h4>
                                    <p class="marketing-campaign-command-helper no-margin"><?= _l('marketing_campaign_command_helper'); ?></p>
                                </div>
                                <span class="marketing-campaign-command-status-pill is-<?= html_escape($campaign_readiness['alert_type']); ?>"><?= html_escape($readiness_status); ?></span>
                            </div>
                            <div class="marketing-campaign-command-grid">
                                <div class="marketing-campaign-command-card">
                                    <span class="marketing-campaign-command-label"><?= _l('marketing_campaign_command_status'); ?></span>
                                    <div class="marketing-campaign-command-value"><?= html_escape($campaignStatusDisplay); ?></div>
                                    <p class="marketing-campaign-command-copy no-margin"><?= html_escape($campaign_readiness['primary_message']); ?></p>
                                </div>
                                <div class="marketing-campaign-command-card">
                                    <span class="marketing-campaign-command-label"><?= _l('marketing_campaign_readiness_status'); ?></span>
                                    <div class="marketing-campaign-command-value"><?= html_escape($readiness_status); ?></div>
                                    <?php if (!$send_enabled) { ?>
                                        <p class="marketing-campaign-command-copy marketing-campaign-command-copy--warning"><?= _l('marketing_campaign_send_disabled_exact'); ?></p>
                                    <?php } ?>
                                    <?php if ($showConfigurationWarnings && !$isWhatsappCampaign && !$smtp_status['valid']) { ?>
                                        <p class="marketing-campaign-command-copy marketing-campaign-command-copy--warning">
                                            <strong><?= _l('marketing_campaign_smtp_incomplete_warning'); ?></strong>
                                            <?= html_escape(implode(' ', $smtp_status['messages'])); ?>
                                        </p>
                                    <?php } ?>
                                    <?php if ($showConfigurationWarnings && $isWhatsappCampaign && !$whatsapp_status['valid']) { ?>
                                        <p class="marketing-campaign-command-copy marketing-campaign-command-copy--warning"><?= html_escape(implode(' ', $whatsapp_status['messages'])); ?></p>
                                    <?php } ?>
                                    <p class="marketing-campaign-command-copy no-margin">
                                        <?= !empty($readinessGuidance) ? html_escape(implode(' ', $readinessGuidance)) : html_escape($campaign_readiness['primary_message']); ?>
                                    </p>
                                </div>
                                <div class="marketing-campaign-command-card">
                                    <span class="marketing-campaign-command-label"><?= _l('marketing_campaign_command_audience'); ?></span>
                                    <div class="marketing-campaign-command-value"><?= html_escape($audienceNameDisplay); ?></div>
                                    <p class="marketing-campaign-command-copy no-margin">
                                        <?= html_escape(sprintf(_l('marketing_campaign_command_recipients_summary'), $preparedRecipientCount, (int) ($send_readiness['eligible_recipients'] ?? 0))); ?>
                                    </p>
                                </div>
                                <div class="marketing-campaign-command-card">
                                    <span class="marketing-campaign-command-label"><?= _l('marketing_campaign_command_delivery'); ?></span>
                                    <div class="marketing-campaign-command-value"><?= html_escape($isWhatsappCampaign ? _l('marketing_campaign_delivery_channel_whatsapp') : _l('marketing_campaign_delivery_channel_email')); ?></div>
                                    <p class="marketing-campaign-command-copy no-margin">
                                        <?= html_escape(sprintf(_l('marketing_campaign_command_schedule_summary'), $scheduledAtDisplay, $send_window['label'])); ?>
                                    </p>
                                </div>
                                <div class="marketing-campaign-command-card">
                                    <span class="marketing-campaign-command-label"><?= _l('marketing_campaign_command_next_step'); ?></span>
                                    <div class="marketing-campaign-command-value"><?= html_escape($campaign_readiness['status_label']); ?></div>
                                    <p class="marketing-campaign-command-copy no-margin"><?= html_escape($nextStepMessage); ?></p>
                                </div>
                                <div class="marketing-campaign-command-card">
                                    <span class="marketing-campaign-command-label"><?= _l('marketing_campaign_command_actions'); ?></span>
                                    <div class="marketing-campaign-command-actions">
                                        <a href="<?= admin_url('marketing/campaigns/preview/' . $campaign['id']); ?>" class="btn btn-default btn-sm"><?= _l('marketing_preview_campaign_button'); ?></a>
                                        <?php if ($can_edit_campaign) { ?>
                                            <a href="<?= admin_url('marketing/campaigns/edit/' . $campaign['id']); ?>" class="btn btn-default btn-sm"><?= _l('edit'); ?></a>
                                        <?php } ?>
                                        <?php if ($can_prepare_recipients && (int) $recipient_summary['queued'] === 0) { ?>
                                            <?= form_open(admin_url('marketing/campaigns'), ['class' => 'inline-block']); ?>
                                                <input type="hidden" name="prepare_campaign_id" value="<?= (int) $campaign['id']; ?>">
                                                <button type="submit" class="btn btn-info btn-sm"><?= _l('marketing_campaign_prepare_recipients'); ?></button>
                                            <?= form_close(); ?>
                                        <?php } ?>
                                        <?php if ($can_send && in_array($campaign['status'], ['draft', 'sending'], true) && (int) $recipient_summary['queued'] > 0) { ?>
                                            <?= form_open(admin_url('marketing/campaigns'), ['class' => 'inline-block']); ?>
                                                <input type="hidden" name="send_campaign_id" value="<?= (int) $campaign['id']; ?>">
                                                <button type="submit" class="btn btn-success btn-sm"><?= _l('marketing_campaign_send_campaign'); ?></button>
                                            <?= form_close(); ?>
                                        <?php } ?>
                                        <?php if ($can_retry_failed) { ?>
                                            <?= form_open(admin_url('marketing/campaigns'), ['class' => 'inline-block']); ?>
                                                <input type="hidden" name="retry_campaign_id" value="<?= (int) $campaign['id']; ?>">
                                                <button type="submit" class="btn btn-warning btn-sm"><?= _l('marketing_campaign_retry_failed_button'); ?></button>
                                            <?= form_close(); ?>
                                        <?php } ?>
                                        <?php if ($can_reset_campaign) { ?>
                                            <?= form_open(admin_url('marketing/campaigns'), ['class' => 'inline-block']); ?>
                                                <input type="hidden" name="reset_campaign_id" value="<?= (int) $campaign['id']; ?>">
                                                <button type="submit" class="btn btn-default btn-sm"><?= _l('marketing_campaign_reset_to_draft_button'); ?></button>
                                            <?= form_close(); ?>
                                        <?php } ?>
                                        <?php if ($can_delete_campaign) { ?>
                                            <?= form_open(admin_url('marketing/campaigns'), ['class' => 'inline-block', 'onsubmit' => "return confirm('Delete this campaign?');"]); ?>
                                                <input type="hidden" name="delete_campaign_id" value="<?= (int) $campaign['id']; ?>">
                                                <button type="submit" class="btn btn-danger btn-sm"><?= _l('delete'); ?></button>
                                            <?= form_close(); ?>
                                        <?php } ?>
                                    </div>
                                    <?php if ($can_send && $campaign['status'] === 'draft') { ?>
                                        <div class="marketing-campaign-command-schedule mtop15">
                                            <?= form_open(admin_url('marketing/campaigns/schedule/' . $campaign['id'])); ?>
                                                <div class="form-group no-margin">
                                                    <label for="marketing-campaign-command-scheduled-at"><?= _l('marketing_campaign_scheduled_at'); ?></label>
                                                    <input type="datetime-local" id="marketing-campaign-command-scheduled-at" name="scheduled_at" class="form-control" value="<?= !empty($campaign['scheduled_at']) ? html_escape(date('Y-m-d\TH:i', strtotime($campaign['scheduled_at']))) : ''; ?>">
                                                    <p class="text-muted mtop5"><?= _l('marketing_campaign_schedule_future_note'); ?></p>
                                                </div>
                                                <button type="submit" class="btn btn-success btn-sm"><?= _l('marketing_campaign_schedule'); ?></button>
                                            <?= form_close(); ?>
                                        </div>
                                    <?php } ?>
                                    <?php if ($can_send && $campaign['status'] === 'scheduled') { ?>
                                        <div class="marketing-campaign-command-actions mtop15">
                                            <?= form_open(admin_url('marketing/campaigns/schedule/' . $campaign['id']), ['class' => 'inline-block']); ?>
                                                <input type="hidden" name="action" value="draft">
                                                <button type="submit" class="btn btn-default btn-sm"><?= _l('marketing_campaign_move_to_draft'); ?></button>
                                            <?= form_close(); ?>
                                            <?= form_open(admin_url('marketing/campaigns/schedule/' . $campaign['id']), ['class' => 'inline-block']); ?>
                                                <input type="hidden" name="action" value="cancelled">
                                                <button type="submit" class="btn btn-danger btn-sm"><?= _l('marketing_campaign_cancel_scheduled'); ?></button>
                                            <?= form_close(); ?>
                                        </div>
                                    <?php } ?>
                                </div>
                            </div>
                        </div>
                        <div class="marketing-campaign-layout">
                            <div class="marketing-campaign-stack">
                                <div class="panel_s">
                                    <div class="panel-body">
                                        <h5 class="bold no-margin"><?= _l('marketing_campaign_detail'); ?></h5>
                                        <table class="table table-striped table-borderless marketing-compact-table mtop15">
                                            <tbody>
                                                <tr>
                                                    <th><?= _l('marketing_name'); ?></th>
                                                    <td><?= html_escape($campaign['name']); ?></td>
                                                </tr>
                                                <tr>
                                                    <th><?= _l('marketing_subject'); ?></th>
                                                    <td><?= html_escape($campaign['subject']); ?></td>
                                                </tr>
                                                <tr>
                                                    <th><?= _l('marketing_campaign_list'); ?></th>
                                                    <td><?= html_escape($campaign['audience_name'] ?: '-'); ?></td>
                                                </tr>
                                                <tr>
                                                    <th><?= _l('marketing_campaign_delivery_channel'); ?></th>
                                                    <td><?= html_escape($isWhatsappCampaign ? _l('marketing_campaign_delivery_channel_whatsapp') : _l('marketing_campaign_delivery_channel_email')); ?></td>
                                                </tr>
                                                <tr>
                                                    <th><?= _l('marketing_status'); ?></th>
                                                    <td><?= html_escape($campaign['status']); ?></td>
                                                </tr>
                                                <tr>
                                                    <th><?= _l('marketing_campaign_preview_text'); ?></th>
                                                    <td><?= html_escape($campaign['preview_text']); ?></td>
                                                </tr>
                                                <?php if ($isWhatsappCampaign) { ?>
                                                <tr>
                                                    <th><?= _l('marketing_campaign_whatsapp_template_name'); ?></th>
                                                    <td><?= html_escape($campaign['whatsapp_template_name'] ?: '-'); ?></td>
                                                </tr>
                                                <tr>
                                                    <th><?= _l('marketing_campaign_whatsapp_template_language'); ?></th>
                                                    <td><?= html_escape($campaign['whatsapp_template_language'] ?: '-'); ?></td>
                                                </tr>
                                                <?php } ?>
                                                <?php if (!$isWhatsappCampaign) { ?>
                                                <tr>
                                                    <th><?= _l('marketing_campaign_from_name'); ?></th>
                                                    <td><?= html_escape($campaign['from_name']); ?></td>
                                                </tr>
                                                <tr>
                                                    <th><?= _l('marketing_campaign_from_email'); ?></th>
                                                    <td><?= html_escape($campaign['from_email']); ?></td>
                                                </tr>
                                                <tr>
                                                    <th><?= _l('marketing_campaign_reply_to_email'); ?></th>
                                                    <td><?= html_escape($campaign['reply_to_email']); ?></td>
                                                </tr>
                                                <?php } ?>
                                                <tr>
                                                    <th><?= _l('marketing_campaign_scheduled_at'); ?></th>
                                                    <td><?= !empty($campaign['scheduled_at']) ? html_escape($campaign['scheduled_at']) : _l('marketing_campaign_send_now'); ?></td>
                                                </tr>
                                                <tr>
                                                    <th><?= _l('marketing_send_window'); ?></th>
                                                    <td><?= html_escape($send_window['label']); ?></td>
                                                </tr>
                                                <tr>
                                                    <th><?= _l('marketing_campaign_rate_limit_status'); ?></th>
                                                    <td>
                                                        <?= html_escape(sprintf(
                                                            _l('marketing_campaign_rate_limit_status_value'),
                                                            (int) $rate_limit_snapshot['per_cron_run'],
                                                            $rate_limit_snapshot['hourly_cap'] > 0 ? (int) $rate_limit_snapshot['hourly_cap'] : 0,
                                                            $rate_limit_snapshot['daily_cap'] > 0 ? (int) $rate_limit_snapshot['daily_cap'] : 0
                                                        )); ?>
                                                    </td>
                                                </tr>
                                                <?php if (!$isWhatsappCampaign) { ?>
                                                <tr>
                                                    <th><?= _l('marketing_campaign_track_opens'); ?></th>
                                                    <td><?= $campaign['track_opens'] ? _l('yes') : _l('no'); ?></td>
                                                </tr>
                                                <tr>
                                                    <th><?= _l('marketing_campaign_track_clicks'); ?></th>
                                                    <td><?= $campaign['track_clicks'] ? _l('yes') : _l('no'); ?></td>
                                                </tr>
                                                <?php } ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <?php if (!$isWhatsappCampaign) { ?>
                                <div class="panel_s">
                                    <div class="panel-body">
                                        <h5 class="bold no-margin">Email Design Preview</h5>
                                        <?php if (!empty($preview_html)) { ?>
                                            <div class="mtop15" style="border:1px solid #e2e8f0;border-radius:16px;overflow:hidden;background:#f8fafc;">
                                                <iframe
                                                    title="Email Design Preview"
                                                    sandbox=""
                                                    srcdoc="<?= html_escape($preview_html); ?>"
                                                    style="display:block;width:100%;min-height:720px;border:0;background:#ffffff;"
                                                ></iframe>
                                            </div>
                                        <?php } else { ?>
                                            <p class="text-muted mtop15 no-margin"><?= _l('marketing_no_records_yet'); ?></p>
                                        <?php } ?>

                                        <?php if (!empty($campaign['text_content'])) { ?>
                                            <div class="mtop20">
                                                <h5 class="bold no-margin">Plain Text Preview</h5>
                                                <pre class="marketing-code-block mtop15"><?= html_escape($campaign['text_content']); ?></pre>
                                            </div>
                                        <?php } ?>
                                    </div>
                                </div>
                                <?php } ?>
                            </div>

                            <div class="marketing-campaign-stack">
                                <div class="panel_s">
                                    <div class="panel-body">
                                        <h5 class="bold no-margin"><?= _l('marketing_campaign_recipient_summary'); ?></h5>
                                        <table class="table table-striped table-borderless marketing-compact-table mtop15">
                                            <tbody>
                                                <tr>
                                                    <th><?= _l('marketing_campaign_total_queued'); ?></th>
                                                    <td><?= (int) $recipient_summary['queued']; ?></td>
                                                </tr>
                                                <tr>
                                                    <th><?= _l('marketing_campaign_processing_recipients'); ?></th>
                                                    <td><?= (int) $recipient_summary['processing']; ?></td>
                                                </tr>
                                                <tr>
                                                    <th><?= _l('marketing_campaign_sent_recipients'); ?></th>
                                                    <td><?= (int) $recipient_summary['sent']; ?></td>
                                                </tr>
                                                <tr>
                                                    <th><?= _l('marketing_campaign_failed_recipients'); ?></th>
                                                    <td><?= (int) $recipient_summary['failed']; ?></td>
                                                </tr>
                                                <tr>
                                                    <th><?= _l('marketing_campaign_suppressed_recipients'); ?></th>
                                                    <td><?= (int) $recipient_summary['suppressed']; ?></td>
                                                </tr>
                                                <tr>
                                                    <th><?= _l('marketing_campaign_skipped_recipients'); ?></th>
                                                    <td><?= (int) $recipient_summary['skipped']; ?></td>
                                                </tr>
                                                <tr>
                                                    <th><?= _l('marketing_campaign_eligible_recipients'); ?></th>
                                                    <td><?= (int) $send_readiness['eligible_recipients']; ?></td>
                                                </tr>
                                                <tr>
                                                    <th><?= _l('marketing_campaign_readiness_status'); ?></th>
                                                    <td><?= html_escape($readiness_status); ?></td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <div class="panel_s">
                                    <div class="panel-body">
                                        <h5 class="bold no-margin"><?= _l('marketing_campaign_targeting_rules_heading'); ?></h5>
                                        <table class="table table-striped table-borderless marketing-compact-table mtop15">
                                            <tbody>
                                                <tr>
                                                    <th><?= _l('marketing_campaign_targeting_mode'); ?></th>
                                                    <td><?= html_escape(($campaign['targeting_rules']['send_mode'] ?? 'full') === 'filtered' ? _l('marketing_campaign_targeting_filtered_segment') : _l('marketing_campaign_targeting_full_audience')); ?></td>
                                                </tr>
                                                <tr>
                                                    <th><?= _l('marketing_campaign_targeting_estimated_eligible'); ?></th>
                                                    <td><?= (int) $targeting_snapshot['eligible']; ?></td>
                                                </tr>
                                                <tr>
                                                    <th><?= _l('marketing_campaign_targeting_total_members'); ?></th>
                                                    <td><?= (int) $targeting_snapshot['total_members']; ?></td>
                                                </tr>
                                                <tr>
                                                    <th><?= _l('marketing_campaign_targeting_rules_applied'); ?></th>
                                                    <td>
                                                        <?php foreach ($targeting_summary_lines as $summaryLine) { ?>
                                                            <div><?= html_escape($summaryLine); ?></div>
                                                        <?php } ?>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <div class="panel_s">
                                    <div class="panel-body">
                                        <h5 class="bold no-margin"><?= _l('marketing_delivery_analytics'); ?></h5>
                                        <?php if (!$isWhatsappCampaign && !empty($emailTelemetryMessages)) { ?>
                                            <div class="mtop15">
                                                <?php foreach ($emailTelemetryMessages as $telemetryMessage) { ?>
                                                    <div class="alert alert-<?= !empty($smtp_status['telemetry_ready']) ? 'info' : 'warning'; ?> mbot10">
                                                        <?= html_escape($telemetryMessage); ?>
                                                    </div>
                                                <?php } ?>
                                            </div>
                                        <?php } ?>
                                        <?php if ($awaitingSesTelemetry) { ?>
                                            <div class="alert alert-warning mtop15 mbot15">
                                                <?= _l('marketing_ses_tracking_pending_diagnostic'); ?>
                                            </div>
                                        <?php } ?>
                                        <table class="table table-striped table-borderless marketing-compact-table mtop15">
                                            <tbody>
                                                <?php if ($isWhatsappCampaign) { ?>
                                                <tr>
                                                    <th><?= _l('marketing_whatsapp_sent'); ?></th>
                                                    <td><?= (int) $whatsapp_event_summary['sent']; ?></td>
                                                </tr>
                                                <tr>
                                                    <th><?= _l('marketing_whatsapp_delivered'); ?></th>
                                                    <td><?= (int) $whatsapp_event_summary['delivered']; ?></td>
                                                </tr>
                                                <tr>
                                                    <th><?= _l('marketing_whatsapp_read'); ?></th>
                                                    <td><?= (int) $whatsapp_event_summary['read']; ?></td>
                                                </tr>
                                                <tr>
                                                    <th><?= _l('marketing_whatsapp_failed'); ?></th>
                                                    <td><?= (int) $whatsapp_event_summary['failed']; ?></td>
                                                </tr>
                                                <?php } else { ?>
                                                <tr>
                                                    <th><?= _l('marketing_accepted_sent'); ?></th>
                                                    <td>
                                                        <?= (int) $event_summary['accepted_sent']; ?>
                                                        <?php if ((int) ($event_summary['accepted_sent_events'] ?? 0) !== (int) $event_summary['accepted_sent']) { ?>
                                                            <div class="text-muted small"><?= sprintf(_l('marketing_campaign_raw_sent_events_helper'), (int) $event_summary['accepted_sent_events']); ?></div>
                                                        <?php } ?>
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <th><?= _l('marketing_campaign_delivered_recipients'); ?></th>
                                                    <td><?= (int) $event_summary['delivered']; ?></td>
                                                </tr>
                                                <tr>
                                                    <th><?= _l('marketing_campaign_open_events'); ?></th>
                                                    <td><?= (int) $event_summary['open']; ?></td>
                                                </tr>
                                                <tr>
                                                    <th><?= _l('marketing_campaign_click_events'); ?></th>
                                                    <td><?= (int) $event_summary['click']; ?></td>
                                                </tr>
                                                <tr>
                                                    <th><?= _l('marketing_campaign_bounced_recipients'); ?></th>
                                                    <td><?= (int) $event_summary['bounce']; ?></td>
                                                </tr>
                                                <tr>
                                                    <th><?= _l('marketing_campaign_complained_recipients'); ?></th>
                                                    <td><?= (int) $event_summary['complaint']; ?></td>
                                                </tr>
                                                <tr>
                                                    <th><?= _l('marketing_failed_rejections'); ?></th>
                                                    <td><?= (int) $event_summary['failed_rejected']; ?></td>
                                                </tr>
                                                <tr>
                                                    <th><?= _l('marketing_unsubscribes'); ?></th>
                                                    <td><?= (int) $event_summary['unsubscribe']; ?></td>
                                                </tr>
                                                <?php } ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <div class="panel_s">
                                    <div class="panel-body">
                                        <h5 class="bold no-margin"><?= _l('marketing_campaign_replies_conversations'); ?></h5>
                                        <?php if (empty($campaign_replies)) { ?>
                                            <p class="text-muted mtop15 no-margin"><?= _l('marketing_campaign_no_linked_replies'); ?></p>
                                        <?php } else { ?>
                                            <div class="table-responsive mtop15">
                                                <table class="table table-striped table-hover">
                                                    <thead>
                                                        <tr>
                                                            <th><?= _l('marketing_email_address'); ?></th>
                                                            <th><?= _l('marketing_subject'); ?></th>
                                                            <th><?= _l('marketing_message_preview'); ?></th>
                                                            <?php if (!empty($show_reply_lead_metadata)) { ?>
                                                                <th><?= _l('marketing_reply_lead'); ?></th>
                                                            <?php } ?>
                                                            <th><?= _l('marketing_event_at'); ?></th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($campaign_replies as $reply) { ?>
                                                            <tr>
                                                                <td><?= e(!empty($reply['email']) ? $reply['email'] : (!empty($reply['from_email']) ? $reply['from_email'] : '-')); ?></td>
                                                                <td><?= e($reply['subject'] ?: '-'); ?></td>
                                                                <td><?= e($reply['preview'] ?: '-'); ?></td>
                                                                <?php if (!empty($show_reply_lead_metadata)) { ?>
                                                                    <td>
                                                                        <div><?= e($reply['lead_status_label'] ?? 'Not converted'); ?></div>
                                                                        <?php if (!empty($reply['lead_url']) && !empty($reply['lead_id'])) { ?>
                                                                            <small><a href="<?= e($reply['lead_url']); ?>">#<?= (int) $reply['lead_id']; ?></a></small>
                                                                        <?php } ?>
                                                                    </td>
                                                                <?php } ?>
                                                                <td><?= !empty($reply['datecreated']) ? e(_dt($reply['datecreated'])) : '-'; ?></td>
                                                            </tr>
                                                        <?php } ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php } ?>
                                        <?php if (!empty($unmatched_replies)) { ?>
                                            <div class="alert alert-info mtop15 mbot15">
                                                <?= _l('marketing_campaign_unmatched_replies_notice'); ?>
                                            </div>
                                            <div class="table-responsive">
                                                <table class="table table-striped table-hover">
                                                    <thead>
                                                        <tr>
                                                            <th><?= _l('marketing_email_address'); ?></th>
                                                            <th><?= _l('marketing_subject'); ?></th>
                                                            <th><?= _l('marketing_message_preview'); ?></th>
                                                            <?php if (!empty($show_reply_lead_metadata)) { ?>
                                                                <th><?= _l('marketing_reply_lead'); ?></th>
                                                            <?php } ?>
                                                            <th><?= _l('marketing_event_at'); ?></th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($unmatched_replies as $reply) { ?>
                                                            <tr>
                                                                <td><?= e(!empty($reply['email']) ? $reply['email'] : (!empty($reply['from_email']) ? $reply['from_email'] : '-')); ?></td>
                                                                <td><?= e($reply['subject'] ?: '-'); ?></td>
                                                                <td><?= e($reply['preview'] ?: '-'); ?></td>
                                                                <?php if (!empty($show_reply_lead_metadata)) { ?>
                                                                    <td>
                                                                        <div><?= e($reply['lead_status_label'] ?? 'Not converted'); ?></div>
                                                                        <?php if (!empty($reply['lead_url']) && !empty($reply['lead_id'])) { ?>
                                                                            <small><a href="<?= e($reply['lead_url']); ?>">#<?= (int) $reply['lead_id']; ?></a></small>
                                                                        <?php } ?>
                                                                    </td>
                                                                <?php } ?>
                                                                <td><?= !empty($reply['datecreated']) ? e(_dt($reply['datecreated'])) : '-'; ?></td>
                                                            </tr>
                                                        <?php } ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php } ?>
                                    </div>
                                </div>

                                <div class="panel_s">
                                    <div class="panel-body">
                                        <div class="clearfix">
                                            <h5 class="pull-left bold no-margin"><?= _l('marketing_campaign_recipients_section'); ?></h5>
                                            <div class="pull-right marketing-recipient-filters">
                                                <a href="<?= $buildRecipientPageUrl(1, '', $recipients_pagination['per_page']); ?>" class="btn btn-default btn-sm <?= $recipient_filter === '' ? 'active' : ''; ?>"><?= _l('marketing_all'); ?></a>
                                                <a href="<?= $buildRecipientPageUrl(1, 'failed', $recipients_pagination['per_page']); ?>" class="btn btn-default btn-sm <?= $recipient_filter === 'failed' ? 'active' : ''; ?>"><?= _l('marketing_failed'); ?></a>
                                                <a href="<?= $buildRecipientPageUrl(1, 'sent', $recipients_pagination['per_page']); ?>" class="btn btn-default btn-sm <?= $recipient_filter === 'sent' ? 'active' : ''; ?>"><?= _l('marketing_sent'); ?></a>
                                            </div>
                                        </div>
                                        <hr />
                                        <?php if (count($campaign_recipients) === 0) { ?>
                                            <p class="text-muted no-margin"><?= _l('marketing_no_records_yet'); ?></p>
                                        <?php } else { ?>
                                            <div class="table-responsive">
                                                <table class="table table-striped table-hover">
                                                    <thead>
                                                        <tr>
                                                            <th><?= _l($isWhatsappCampaign ? 'marketing_campaign_destination' : 'marketing_email_address'); ?></th>
                                                            <th><?= _l('marketing_status'); ?></th>
                                                            <th><?= _l('marketing_campaign_recipient_sent_at'); ?></th>
                                                            <th><?= _l('marketing_campaign_recipient_failed_at'); ?></th>
                                                            <th><?= _l('marketing_campaign_recipient_failure_reason'); ?></th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($campaign_recipients as $recipient) { ?>
                                                            <tr>
                                                                <td>
                                                                    <div><?= e($isWhatsappCampaign ? (!empty($recipient['destination']) ? $recipient['destination'] : '-') : $recipient['email']); ?></div>
                                                                    <?php if (!$isWhatsappCampaign) { ?>
                                                                        <small class="text-muted"><?= _l('marketing_campaign_reply_test_id'); ?>: <?= e('reply_id:' . (int) $campaign['id'] . ':' . (int) $recipient['id']); ?></small>
                                                                    <?php } ?>
                                                                </td>
                                                                <td><?= e(ucfirst($recipient['status'])); ?></td>
                                                                <td><?= !empty($recipient['sent_at']) ? e(_dt($recipient['sent_at'])) : '-'; ?></td>
                                                                <td><?= !empty($recipient['failed_at']) ? e(_dt($recipient['failed_at'])) : '-'; ?></td>
                                                                <td><?= !empty($recipient['failure_reason']) ? e($recipient['failure_reason']) : '-'; ?></td>
                                                            </tr>
                                                        <?php } ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                            <div class="clearfix mtop15">
                                                <div class="pull-left text-muted">
                                                    <?= sprintf('Showing %d to %d of %d recipients', (($recipients_pagination['page'] - 1) * $recipients_pagination['per_page']) + 1, min($recipients_pagination['page'] * $recipients_pagination['per_page'], $recipients_pagination['total']), $recipients_pagination['total']); ?>
                                                </div>
                                                <?php if ($recipients_pagination['total_pages'] > 1) { ?>
                                                    <ul class="pagination no-margin pull-right">
                                                        <li class="<?= $recipients_pagination['page'] <= 1 ? 'disabled' : ''; ?>">
                                                            <a href="<?= $recipients_pagination['page'] <= 1 ? '#' : $buildRecipientPageUrl($recipients_pagination['page'] - 1, $recipient_filter, $recipients_pagination['per_page']); ?>">Previous</a>
                                                        </li>
                                                        <?php for ($page = 1; $page <= $recipients_pagination['total_pages']; $page++) { ?>
                                                            <li class="<?= $page === (int) $recipients_pagination['page'] ? 'active' : ''; ?>">
                                                                <a href="<?= $buildRecipientPageUrl($page, $recipient_filter, $recipients_pagination['per_page']); ?>"><?= $page; ?></a>
                                                            </li>
                                                        <?php } ?>
                                                        <li class="<?= $recipients_pagination['page'] >= $recipients_pagination['total_pages'] ? 'disabled' : ''; ?>">
                                                            <a href="<?= $recipients_pagination['page'] >= $recipients_pagination['total_pages'] ? '#' : $buildRecipientPageUrl($recipients_pagination['page'] + 1, $recipient_filter, $recipients_pagination['per_page']); ?>">Next</a>
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
            </div>
        </div>
    </div>
</div>
<?php init_tail(); ?>
</body>
</html>
