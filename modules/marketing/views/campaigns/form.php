<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<?php
$builderCssPath = module_dir_path('marketing', 'assets/css/marketing-builder.css');
$builderJsPath  = module_dir_path('marketing', 'assets/js/marketing-builder.js');
$quillCssPath   = module_dir_path('marketing', 'assets/vendor/quill/quill.snow.css');
$quillJsPath    = module_dir_path('marketing', 'assets/vendor/quill/quill.js');
$builderCssUrl  = module_dir_url('marketing', 'assets/css/marketing-builder.css');
$builderJsUrl   = module_dir_url('marketing', 'assets/js/marketing-builder.js');
$quillCssUrl    = module_dir_url('marketing', 'assets/vendor/quill/quill.snow.css');
$quillJsUrl     = module_dir_url('marketing', 'assets/vendor/quill/quill.js');
$builderCssVer  = is_file($builderCssPath) ? (string) filemtime($builderCssPath) : '1';
$builderJsVer   = is_file($builderJsPath) ? (string) filemtime($builderJsPath) : '1';
$quillCssVer    = is_file($quillCssPath) ? (string) filemtime($quillCssPath) : '1';
$quillJsVer     = is_file($quillJsPath) ? (string) filemtime($quillJsPath) : '1';
?>
<link rel="stylesheet" href="<?= html_escape($quillCssUrl . '?v=' . $quillCssVer); ?>">
<link rel="stylesheet" href="<?= html_escape($builderCssUrl . '?v=' . $builderCssVer); ?>">
<?php
$errorMessages = !empty($form_error_message) ? (is_array($form_error_message) ? $form_error_message : [$form_error_message]) : [];
$hasUnsubscribeSaveError = false;
$audiences = isset($audiences) && is_array($audiences) ? $audiences : [];
$targetingRules = isset($campaign['targeting_rules']) && is_array($campaign['targeting_rules']) ? $campaign['targeting_rules'] : [];
$deliveryChannel = isset($campaign['delivery_channel']) && $campaign['delivery_channel'] === 'whatsapp' ? 'whatsapp' : 'email';
$isWhatsappCampaign = $deliveryChannel === 'whatsapp';
$campaignNameValue = trim((string) ($campaign['name'] ?? ''));
$subjectValue = trim((string) ($campaign['subject'] ?? ''));
$audienceId = (int) ($campaign['list_id'] ?? 0);
$audienceName = '';
$targetingMode = ($targetingRules['send_mode'] ?? 'full') === 'filtered' ? 'filtered' : 'full';
$fromNameValue = trim((string) ($campaign['from_name'] ?? ''));
$fromEmailValue = trim((string) ($campaign['from_email'] ?? ''));
$whatsAppTemplateNameValue = trim((string) ($campaign['whatsapp_template_name'] ?? ''));
$whatsAppTemplateLanguageValue = trim((string) ($campaign['whatsapp_template_language'] ?? ''));
$builderStateValue = trim((string) ($campaign['builder_state'] ?? ''));
$htmlContentValue = trim((string) ($campaign['html_content'] ?? ''));
$textContentValue = trim((string) ($campaign['text_content'] ?? ''));
$builderCompanyAddress = isset($builder_company_address) && is_array($builder_company_address)
    ? array_merge(['html' => 'Your Company<br>123 Market Street<br>City, State', 'text' => "Your Company\n123 Market Street\nCity, State", 'configured' => false], $builder_company_address)
    : ['html' => 'Your Company<br>123 Market Street<br>City, State', 'text' => "Your Company\n123 Market Street\nCity, State", 'configured' => false];
$builderMergeTags = isset($builder_merge_tags) && is_array($builder_merge_tags) ? $builder_merge_tags : [];
$scheduleMode = ($campaign['schedule_mode'] ?? 'now') === 'later' ? 'later' : 'now';
$scheduledAtValue = trim((string) ($campaign['scheduled_at'] ?? ''));
$scheduledAtDisplayValue = '';
$trackOpensEnabled = !empty($campaign['track_opens']);
$trackClicksEnabled = !empty($campaign['track_clicks']);
$audienceModeLabel = $targetingMode === 'filtered'
    ? _l('marketing_campaign_targeting_filtered_segment')
    : _l('marketing_campaign_targeting_full_audience');

foreach ($audiences as $audience) {
    if ((int) ($audience['id'] ?? 0) === $audienceId) {
        $audienceName = trim((string) ($audience['name'] ?? ''));
        break;
    }
}

if ($scheduledAtValue !== '') {
    $scheduledTimestamp = strtotime($scheduledAtValue);
    $scheduledAtDisplayValue = $scheduledTimestamp ? date('Y-m-d H:i', $scheduledTimestamp) : $scheduledAtValue;
}

$basicsReady = $campaignNameValue !== '' && ($isWhatsappCampaign || $subjectValue !== '');
$audienceReady = $audienceId > 0;
$audienceSummaryLabel = $audienceReady ? $audienceName : _l('marketing_campaign_audience_summary_none');
$senderReady = $isWhatsappCampaign || ($fromNameValue !== '' && $fromEmailValue !== '');
$hasBuilderContent = $builderStateValue !== '' || $htmlContentValue !== '';
$contentReady = $isWhatsappCampaign
    ? ($whatsAppTemplateNameValue !== '' && $whatsAppTemplateLanguageValue !== '')
    : $hasBuilderContent;
$scheduleReady = $scheduleMode !== 'later' || $scheduledAtValue !== '';

$trackingSummary = $isWhatsappCampaign
    ? _l('marketing_campaign_setup_tracking_not_applicable')
    : sprintf(
        _l('marketing_campaign_setup_tracking_summary'),
        $trackOpensEnabled ? _l('marketing_campaign_setup_tracking_on') : _l('marketing_campaign_setup_tracking_off'),
        $trackClicksEnabled ? _l('marketing_campaign_setup_tracking_on') : _l('marketing_campaign_setup_tracking_off')
    );

$setupChecklist = [
    [
        'label' => _l('marketing_campaign_setup_basics'),
        'complete' => $basicsReady,
        'detail' => $basicsReady
            ? ($isWhatsappCampaign ? _l('marketing_campaign_setup_basics_ready_whatsapp') : _l('marketing_campaign_setup_basics_ready_email'))
            : ($isWhatsappCampaign ? _l('marketing_campaign_setup_basics_pending_whatsapp') : _l('marketing_campaign_setup_basics_pending_email')),
    ],
    [
        'label' => _l('marketing_campaign_setup_audience'),
        'complete' => $audienceReady,
        'detail' => $audienceReady
            ? trim($audienceName . ' - ' . ($targetingMode === 'filtered' ? _l('marketing_campaign_setup_audience_filtered') : _l('marketing_campaign_setup_audience_full')))
            : _l('marketing_campaign_setup_audience_pending'),
    ],
    [
        'label' => _l('marketing_campaign_setup_channel'),
        'complete' => true,
        'detail' => $isWhatsappCampaign ? _l('marketing_campaign_delivery_channel_whatsapp') : _l('marketing_campaign_delivery_channel_email'),
    ],
    [
        'label' => _l('marketing_campaign_setup_sender'),
        'complete' => $senderReady,
        'detail' => $isWhatsappCampaign
            ? _l('marketing_campaign_setup_sender_optional')
            : ($senderReady ? _l('marketing_campaign_setup_sender_ready') : _l('marketing_campaign_setup_sender_pending')),
    ],
    [
        'label' => _l('marketing_campaign_setup_content'),
        'complete' => $contentReady,
        'detail' => $isWhatsappCampaign
            ? ($contentReady ? _l('marketing_campaign_setup_content_ready_whatsapp') : _l('marketing_campaign_setup_content_pending_whatsapp'))
            : ($contentReady ? _l('marketing_campaign_setup_content_ready_email') : _l('marketing_campaign_setup_content_pending_email')),
    ],
    [
        'label' => _l('marketing_campaign_setup_tracking'),
        'complete' => true,
        'detail' => $trackingSummary,
    ],
    [
        'label' => _l('marketing_campaign_setup_schedule'),
        'complete' => $scheduleReady,
        'detail' => $scheduleMode === 'later'
            ? ($scheduleReady ? sprintf(_l('marketing_campaign_setup_schedule_later'), $scheduledAtDisplayValue) : _l('marketing_campaign_setup_schedule_missing'))
            : _l('marketing_campaign_setup_schedule_now'),
    ],
];

$setupChecklistCompleteCount = 0;
foreach ($setupChecklist as $setupChecklistItem) {
    if (!empty($setupChecklistItem['complete'])) {
        $setupChecklistCompleteCount++;
    }
}

foreach ($errorMessages as $errorMessage) {
    $msg = strip_tags(trim((string) $errorMessage));
    if (strpos($msg, _l('marketing_campaign_unsubscribe_save_required')) !== false) {
        $hasUnsubscribeSaveError = true;
        break;
    }
}

// If the builder/html content already contains an unsubscribe token in any common encoding,
// don't show the unsubscribe save warning even if the server returned that error.
if ($hasUnsubscribeSaveError) {
    $combined = trim((string) ($htmlContentValue . ' ' . $builderStateValue));
    $candidates = [strtolower($combined)];
    $decoded = rawurldecode($combined);
    $entityDecoded = html_entity_decode($combined, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    if ($decoded !== $combined) {
        $candidates[] = strtolower($decoded);
    }

    if ($entityDecoded !== $combined) {
        $candidates[] = strtolower($entityDecoded);
    }

    foreach ($candidates as $candidate) {
        if (strpos($candidate, '{{unsubscribe_url}}') !== false
            || strpos($candidate, '%7b%7bunsubscribe_url%7d%7d') !== false
            || strpos($candidate, '&#123;&#123;unsubscribe_url&#125;&#125;') !== false
            || strpos($candidate, '&lcub;&lcub;unsubscribe_url&rcub;&rcub;') !== false
            || strpos($candidate, '\\u007b\\u007bunsubscribe_url\\u007d\\u007d') !== false
            || strpos($candidate, 'href="{{unsubscribe_url}}"') !== false
            || strpos($candidate, "href='{{unsubscribe_url}}'") !== false
            || strpos($candidate, 'href=&quot;{{unsubscribe_url}}&quot;') !== false
            || strpos($candidate, 'href=&#39;{{unsubscribe_url}}&#39;') !== false
            || strpos($candidate, '/marketing/unsubscribe/') !== false
        ) {
            $hasUnsubscribeSaveError = false;
            break;
        }
    }
}
?>
<div id="wrapper">
    <div class="content">
        <style>
            .marketing-targeting-panel {
                border: 1px solid #e2e8f0;
                border-radius: 14px;
                padding: 20px;
                background: #f8fafc;
            }
            .marketing-targeting-mode {
                display: flex;
                flex-wrap: wrap;
                gap: 16px;
                margin-bottom: 16px;
            }
            .marketing-targeting-note {
                color: #64748b;
                font-size: 12px;
            }
            .marketing-targeting-disabled {
                opacity: .75;
            }
            .marketing-channel-panel {
                border: 1px solid #e2e8f0;
                border-radius: 14px;
                padding: 20px;
                background: #f8fafc;
                margin-bottom: 20px;
            }
            .marketing-channel-note {
                color: #64748b;
                font-size: 12px;
            }
        </style>
        <div class="row">
            <div class="col-md-12">
                <div class="panel_s">
                    <div class="panel-body">
                        <h4 class="no-margin"><?= html_escape($title); ?></h4>
                        <p class="text-muted mtop10"><?= _l('marketing_builder_intro'); ?></p>
                        <hr />
                        <?php if (!empty($form_error_message)) { ?>
                            <div class="alert alert-danger">
                                <?php if (count($errorMessages) > 1) { ?>
                                    <ul class="no-margin pleft20">
                                        <?php foreach ($errorMessages as $message) { ?>
                                            <li><?= html_escape($message); ?></li>
                                        <?php } ?>
                                    </ul>
                                <?php } else { ?>
                                    <?= html_escape($errorMessages[0]); ?>
                                <?php } ?>
                                <?php if ($hasUnsubscribeSaveError) { ?>
                                    <div class="mtop15">
                                        <p class="no-margin"><?= _l('marketing_builder_unsubscribe_missing_warning'); ?></p>
                                        <button type="button" class="btn btn-info btn-sm mtop10" id="marketing-builder-insert-unsubscribe-now"><?= _l('marketing_builder_insert_unsubscribe_now'); ?></button>
                                    </div>
                                <?php } ?>
                            </div>
                        <?php } ?>
                        <?= form_open($action, ['id' => 'marketing_campaign_form']); ?>

                        <div class="row marketing-email-fields">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="name"><?= _l('marketing_name'); ?></label>
                                    <input type="text" id="name" name="name" class="form-control" value="<?= html_escape($campaign['name']); ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="subject"><?= _l('marketing_subject'); ?></label>
                                    <input type="text" id="subject" name="subject" class="form-control" value="<?= html_escape($campaign['subject']); ?>" <?= ($campaign['delivery_channel'] ?? 'email') === 'email' ? 'required' : ''; ?>>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="preview_text"><?= _l('marketing_campaign_preview_text'); ?></label>
                                    <input type="text" id="preview_text" name="preview_text" class="form-control" value="<?= html_escape($campaign['preview_text']); ?>">
                                </div>
                            </div>
                        </div>

                        <div class="marketing-campaign-audience-shell" data-marketing-campaign-audience-shell="1">
                            <div class="marketing-campaign-audience-header">
                                <div>
                                    <h4 class="marketing-campaign-audience-title"><?= _l('marketing_campaign_audience_heading'); ?></h4>
                                    <p class="marketing-campaign-audience-helper no-margin"><?= _l('marketing_campaign_audience_helper'); ?></p>
                                </div>
                            </div>
                            <div class="marketing-campaign-audience-grid">
                                <div class="marketing-campaign-audience-card">
                                    <div class="form-group no-margin">
                                        <label for="list_id"><?= _l('marketing_campaign_list'); ?></label>
                                        <select id="list_id" name="list_id" class="form-control" required>
                                            <option value=""><?= _l('marketing_choose_list'); ?></option>
                                            <?php foreach ($audiences as $audience) { ?>
                                                <option value="<?= (int) $audience['id']; ?>" <?= (string) $campaign['list_id'] === (string) $audience['id'] ? 'selected' : ''; ?>>
                                                    <?= html_escape($audience['name']); ?>
                                                </option>
                                            <?php } ?>
                                        </select>
                                        <?php if (empty($audiences)) { ?>
                                            <p class="text-muted mtop10"><?= _l('marketing_import_no_lists'); ?></p>
                                        <?php } ?>
                                    </div>
                                    <div class="marketing-campaign-audience-meta">
                                        <div class="marketing-campaign-audience-meta-row">
                                            <span class="marketing-campaign-audience-meta-label"><?= _l('marketing_campaign_audience_selected_label'); ?></span>
                                            <span class="marketing-campaign-audience-chip" id="marketing-campaign-audience-selected-label" data-empty-label="<?= html_escape(_l('marketing_campaign_audience_summary_none')); ?>"><?= html_escape($audienceSummaryLabel); ?></span>
                                        </div>
                                        <div class="marketing-campaign-audience-meta-row">
                                            <span class="marketing-campaign-audience-meta-label"><?= _l('marketing_campaign_audience_segment_label'); ?></span>
                                            <span class="marketing-campaign-audience-chip"><?= html_escape($audienceModeLabel); ?></span>
                                        </div>
                                    </div>
                                </div>
                                <div class="marketing-campaign-estimate-card">
                                    <h5 class="marketing-campaign-estimate-title"><?= _l('marketing_campaign_estimate_recipients'); ?></h5>
                                    <p class="marketing-campaign-audience-helper no-margin"><?= _l('marketing_campaign_audience_estimate_helper'); ?></p>
                                    <div class="mtop15">
                                        <button type="button" class="btn btn-default" id="marketing-estimate-recipients"><?= _l('marketing_campaign_estimate_recipients'); ?></button>
                                    </div>
                                    <div class="marketing-campaign-estimate-result-shell">
                                        <span id="marketing-estimate-recipients-result" class="text-muted"></span>
                                    </div>
                                    <p class="marketing-campaign-audience-next-step no-margin"><?= _l('marketing_campaign_audience_next_step_helper'); ?></p>
                                </div>
                            </div>

                        <div class="marketing-campaign-delivery-shell" data-marketing-campaign-delivery-shell="1">
                            <div class="marketing-campaign-delivery-header">
                                <div>
                                    <h4 class="marketing-campaign-delivery-title"><?= _l('marketing_campaign_delivery_settings_heading'); ?></h4>
                                    <p class="marketing-campaign-delivery-helper no-margin"><?= _l('marketing_campaign_delivery_settings_helper'); ?></p>
                                </div>
                            </div>
                            <div class="marketing-campaign-delivery-grid">
                                <div class="marketing-campaign-delivery-card">
                                    <h5 class="marketing-campaign-delivery-card-title"><?= _l('marketing_campaign_delivery_settings_channel_title'); ?></h5>
                                    <p class="marketing-campaign-delivery-card-copy"><?= _l('marketing_campaign_delivery_settings_channel_copy'); ?></p>
                                    <div class="marketing-channel-panel no-margin">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label for="delivery_channel"><?= _l('marketing_campaign_delivery_channel'); ?></label>
                                                    <select id="delivery_channel" name="delivery_channel" class="form-control">
                                                        <option value="email" <?= ($campaign['delivery_channel'] ?? 'email') === 'email' ? 'selected' : ''; ?>><?= _l('marketing_campaign_delivery_channel_email'); ?></option>
                                                        <option value="whatsapp" <?= ($campaign['delivery_channel'] ?? '') === 'whatsapp' ? 'selected' : ''; ?>><?= _l('marketing_campaign_delivery_channel_whatsapp'); ?></option>
                                                    </select>
                                                    <p class="marketing-channel-note mtop10 no-margin"><?= _l('marketing_campaign_delivery_channel_help'); ?></p>
                                                </div>
                                            </div>
                                        </div>
                                        <div id="marketing-whatsapp-fields" class="<?= ($campaign['delivery_channel'] ?? 'email') === 'whatsapp' ? '' : 'hide'; ?>">
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label for="whatsapp_template_name"><?= _l('marketing_campaign_whatsapp_template_name'); ?></label>
                                                        <input type="text" id="whatsapp_template_name" name="whatsapp_template_name" class="form-control" value="<?= html_escape($campaign['whatsapp_template_name'] ?? ''); ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label for="whatsapp_template_language"><?= _l('marketing_campaign_whatsapp_template_language'); ?></label>
                                                        <input type="text" id="whatsapp_template_language" name="whatsapp_template_language" class="form-control" value="<?= html_escape($campaign['whatsapp_template_language'] ?? 'en_US'); ?>">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-4">
                                                    <div class="form-group">
                                                        <label for="whatsapp_template_var1"><?= _l('marketing_campaign_whatsapp_template_var1'); ?></label>
                                                        <input type="text" id="whatsapp_template_var1" name="whatsapp_template_var1" class="form-control" value="<?= html_escape($campaign['whatsapp_template_var1'] ?? ''); ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="form-group">
                                                        <label for="whatsapp_template_var2"><?= _l('marketing_campaign_whatsapp_template_var2'); ?></label>
                                                        <input type="text" id="whatsapp_template_var2" name="whatsapp_template_var2" class="form-control" value="<?= html_escape($campaign['whatsapp_template_var2'] ?? ''); ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="form-group">
                                                        <label for="whatsapp_template_var3"><?= _l('marketing_campaign_whatsapp_template_var3'); ?></label>
                                                        <input type="text" id="whatsapp_template_var3" name="whatsapp_template_var3" class="form-control" value="<?= html_escape($campaign['whatsapp_template_var3'] ?? ''); ?>">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="alert alert-info no-margin">
                                                <?= _l('marketing_campaign_whatsapp_template_help'); ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="marketing-campaign-delivery-card">
                                    <h5 class="marketing-campaign-delivery-card-title"><?= _l('marketing_campaign_delivery_settings_sender_title'); ?></h5>
                                    <p class="marketing-campaign-delivery-card-copy"><?= _l('marketing_campaign_delivery_settings_sender_copy'); ?></p>
                                    <div class="row">
                                        <div class="col-md-4">
                                            <div class="form-group">
                                                <label for="from_name"><?= _l('marketing_campaign_from_name'); ?></label>
                                                <input type="text" id="from_name" name="from_name" class="form-control" value="<?= html_escape($campaign['from_name']); ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="form-group">
                                                <label for="from_email"><?= _l('marketing_campaign_from_email'); ?></label>
                                                <input type="email" id="from_email" name="from_email" class="form-control" value="<?= html_escape($campaign['from_email']); ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="form-group no-margin">
                                                <label for="reply_to_email"><?= _l('marketing_campaign_reply_to_email'); ?></label>
                                                <input type="email" id="reply_to_email" name="reply_to_email" class="form-control" value="<?= html_escape($campaign['reply_to_email']); ?>">
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="marketing-campaign-delivery-card marketing-email-fields">
                                    <h5 class="marketing-campaign-delivery-card-title"><?= _l('marketing_campaign_delivery_settings_tracking_title'); ?></h5>
                                    <p class="marketing-campaign-delivery-card-copy"><?= _l('marketing_campaign_delivery_settings_tracking_copy'); ?></p>
                                    <div class="marketing-campaign-delivery-tracking-row">
                                        <div class="checkbox checkbox-inline">
                                            <input type="hidden" name="track_opens" value="0">
                                            <input type="checkbox" id="track_opens" name="track_opens" value="1" <?= $campaign['track_opens'] ? 'checked' : ''; ?>>
                                            <label for="track_opens"><?= _l('marketing_campaign_track_opens'); ?></label>
                                        </div>
                                        <div class="checkbox checkbox-inline">
                                            <input type="hidden" name="track_clicks" value="0">
                                            <input type="checkbox" id="track_clicks" name="track_clicks" value="1" <?= $campaign['track_clicks'] ? 'checked' : ''; ?>>
                                            <label for="track_clicks"><?= _l('marketing_campaign_track_clicks'); ?></label>
                                        </div>
                                    </div>
                                    <p class="text-muted mtop10 no-margin"><?= _l('marketing_campaign_tracking_runtime_help'); ?></p>
                                </div>

                                <div class="marketing-campaign-delivery-card">
                                    <h5 class="marketing-campaign-delivery-card-title"><?= _l('marketing_campaign_delivery_settings_schedule_title'); ?></h5>
                                    <p class="marketing-campaign-delivery-card-copy"><?= _l('marketing_campaign_delivery_settings_schedule_copy'); ?></p>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label class="control-label"><?= _l('marketing_campaign_delivery_timing'); ?></label>
                                                <div class="radio radio-primary">
                                                    <input type="radio" id="schedule_mode_now" name="schedule_mode" value="now" <?= ($campaign['schedule_mode'] ?? 'now') !== 'later' ? 'checked' : ''; ?>>
                                                    <label for="schedule_mode_now"><?= _l('marketing_campaign_send_now'); ?></label>
                                                </div>
                                                <div class="radio radio-primary">
                                                    <input type="radio" id="schedule_mode_later" name="schedule_mode" value="later" <?= ($campaign['schedule_mode'] ?? '') === 'later' ? 'checked' : ''; ?>>
                                                    <label for="schedule_mode_later"><?= _l('marketing_campaign_schedule_for_later'); ?></label>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group no-margin">
                                                <label for="scheduled_at"><?= _l('marketing_campaign_scheduled_at'); ?></label>
                                                <input type="datetime-local" id="scheduled_at" name="scheduled_at" class="form-control" value="<?= !empty($campaign['scheduled_at']) ? html_escape(date('Y-m-d\TH:i', strtotime($campaign['scheduled_at']))) : ''; ?>">
                                                <p class="text-muted mtop5 no-margin"><?= _l('marketing_campaign_schedule_future_note'); ?></p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                            <div class="marketing-campaign-targeting-card mtop20">
                                <h4 class="bold no-margin"><?= _l('marketing_campaign_targeting_rules_heading'); ?></h4>
                                <p class="marketing-campaign-audience-helper mtop10"><?= _l('marketing_campaign_targeting_rules_help'); ?></p>
                                <div class="marketing-targeting-panel mtop15">
                                    <div class="marketing-targeting-mode">
                                        <div class="radio radio-primary">
                                            <input type="radio" id="targeting_send_mode_full" name="targeting_send_mode" value="full" <?= ($targetingRules['send_mode'] ?? 'full') !== 'filtered' ? 'checked' : ''; ?>>
                                            <label for="targeting_send_mode_full"><?= _l('marketing_campaign_targeting_full_audience'); ?></label>
                                        </div>
                                        <div class="radio radio-primary">
                                            <input type="radio" id="targeting_send_mode_filtered" name="targeting_send_mode" value="filtered" <?= ($targetingRules['send_mode'] ?? '') === 'filtered' ? 'checked' : ''; ?>>
                                            <label for="targeting_send_mode_filtered"><?= _l('marketing_campaign_targeting_filtered_segment'); ?></label>
                                        </div>
                                    </div>

                                    <div id="marketing-targeting-fields">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label for="targeting_contact_status"><?= _l('marketing_campaign_targeting_contact_status'); ?></label>
                                                    <select id="targeting_contact_status" name="targeting_contact_status" class="form-control">
                                                        <option value=""><?= _l('marketing_all'); ?></option>
                                                        <?php foreach ($contact_status_options as $option) { ?>
                                                            <option value="<?= html_escape($option); ?>" <?= ($targetingRules['contact_status'] ?? '') === $option ? 'selected' : ''; ?>><?= html_escape(ucfirst($option)); ?></option>
                                                        <?php } ?>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label for="targeting_consent_status"><?= _l('marketing_campaign_targeting_consent_status'); ?></label>
                                                    <select id="targeting_consent_status" name="targeting_consent_status" class="form-control">
                                                        <option value=""><?= _l('marketing_all'); ?></option>
                                                        <?php foreach ($consent_status_options as $option) { ?>
                                                            <option value="<?= html_escape($option); ?>" <?= ($targetingRules['consent_status'] ?? '') === $option ? 'selected' : ''; ?>><?= html_escape(ucfirst($option)); ?></option>
                                                        <?php } ?>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label for="targeting_company_contains"><?= _l('marketing_campaign_targeting_company_contains'); ?></label>
                                                    <input type="text" id="targeting_company_contains" name="targeting_company_contains" class="form-control" value="<?= html_escape($targetingRules['company_contains'] ?? ''); ?>">
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="form-group">
                                                    <label for="targeting_email_contains"><?= _l('marketing_campaign_targeting_email_contains'); ?></label>
                                                    <input type="text" id="targeting_email_contains" name="targeting_email_contains" class="form-control" value="<?= html_escape($targetingRules['email_contains'] ?? ''); ?>">
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="form-group">
                                                    <label for="targeting_email_domain"><?= _l('marketing_campaign_targeting_email_domain'); ?></label>
                                                    <input type="text" id="targeting_email_domain" name="targeting_email_domain" class="form-control" placeholder="example.com" value="<?= html_escape($targetingRules['email_domain'] ?? ''); ?>">
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-4">
                                                <div class="form-group">
                                                    <label for="targeting_source_type"><?= _l('marketing_campaign_targeting_source_type'); ?></label>
                                                    <select id="targeting_source_type" name="targeting_source_type" class="form-control">
                                                        <option value=""><?= _l('marketing_all'); ?></option>
                                                        <option value="manual" <?= ($targetingRules['source_type'] ?? '') === 'manual' ? 'selected' : ''; ?>><?= _l('marketing_campaign_targeting_source_manual'); ?></option>
                                                        <option value="import" <?= ($targetingRules['source_type'] ?? '') === 'import' ? 'selected' : ''; ?>><?= _l('marketing_campaign_targeting_source_import'); ?></option>
                                                    </select>
                                                </div>
                                            </div>
                                            <?php if ($supports_created_date_filter) { ?>
                                                <div class="col-md-4">
                                                    <div class="form-group">
                                                        <label for="targeting_created_from"><?= _l('marketing_campaign_targeting_created_from'); ?></label>
                                                        <input type="date" id="targeting_created_from" name="targeting_created_from" class="form-control" value="<?= html_escape($targetingRules['created_from'] ?? ''); ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="form-group">
                                                        <label for="targeting_created_to"><?= _l('marketing_campaign_targeting_created_to'); ?></label>
                                                        <input type="date" id="targeting_created_to" name="targeting_created_to" class="form-control" value="<?= html_escape($targetingRules['created_to'] ?? ''); ?>">
                                                    </div>
                                                </div>
                                            <?php } ?>
                                        </div>

                                        <div class="row marketing-targeting-disabled">
                                            <div class="col-md-12">
                                                <div class="checkbox checkbox-inline">
                                                    <input type="checkbox" checked disabled>
                                                    <label><?= _l('marketing_campaign_targeting_exclude_bounced'); ?></label>
                                                </div>
                                                <div class="checkbox checkbox-inline">
                                                    <input type="checkbox" checked disabled>
                                                    <label><?= _l('marketing_campaign_targeting_exclude_complained'); ?></label>
                                                </div>
                                                <div class="checkbox checkbox-inline">
                                                    <input type="checkbox" checked disabled>
                                                    <label><?= _l('marketing_campaign_targeting_exclude_unsubscribed'); ?></label>
                                                </div>
                                                <div class="checkbox checkbox-inline">
                                                    <input type="checkbox" checked disabled>
                                                    <label><?= _l('marketing_campaign_targeting_exclude_suppressed'); ?></label>
                                                </div>
                                                <p class="marketing-targeting-note mtop10 mbot0"><?= _l('marketing_campaign_targeting_safety_note'); ?></p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <input type="hidden" id="marketing-campaign-id" value="<?= (int) ($campaign['id'] ?? 0); ?>">
                        </div>

	                        <div class="alert alert-info marketing-email-fields">
	                            <p class="no-margin"><strong><?= _l('marketing_builder_unsubscribe_help'); ?></strong></p>
	                            <p class="mtop10 no-margin"><?= _l('marketing_builder_ses_tracking_help'); ?></p>
	                        </div>

	                        <div class="marketing-campaign-setup-panel" data-marketing-campaign-setup="1">
	                            <div class="marketing-campaign-setup-header">
	                                <div>
	                                    <h4 class="marketing-campaign-setup-heading"><?= _l('marketing_campaign_setup_heading'); ?></h4>
	                                    <p class="marketing-campaign-setup-helper no-margin"><?= _l('marketing_campaign_setup_helper'); ?></p>
	                                </div>
	                                <span class="marketing-campaign-setup-score"><?= (int) $setupChecklistCompleteCount; ?>/<?= count($setupChecklist); ?></span>
	                            </div>
	                            <div class="marketing-campaign-setup-checklist">
	                                <?php foreach ($setupChecklist as $setupChecklistItem) { ?>
	                                    <div class="marketing-campaign-setup-item <?= !empty($setupChecklistItem['complete']) ? 'is-complete' : 'is-attention'; ?>">
	                                        <div class="marketing-campaign-setup-item-top">
	                                            <span class="marketing-campaign-setup-item-label"><?= html_escape($setupChecklistItem['label']); ?></span>
	                                            <span class="marketing-campaign-setup-item-status"><?= !empty($setupChecklistItem['complete']) ? _l('marketing_campaign_setup_complete') : _l('marketing_campaign_setup_needs_attention'); ?></span>
	                                        </div>
	                                        <p class="marketing-campaign-setup-item-copy no-margin"><?= html_escape($setupChecklistItem['detail']); ?></p>
	                                    </div>
	                                <?php } ?>
	                            </div>
	                            <div class="marketing-campaign-setup-next-step">
	                                <span class="marketing-campaign-setup-next-step-label"><?= _l('marketing_campaign_setup_next_step'); ?></span>
	                                <span class="marketing-campaign-setup-next-step-copy"><?= _l('marketing_campaign_setup_next_step_helper'); ?></span>
	                            </div>
	                        </div>

	                        <div class="marketing-campaign-builder-shell marketing-email-fields" data-marketing-campaign-builder-shell="1">
	                            <div class="marketing-campaign-builder-header">
	                                <div>
	                                    <h4 class="marketing-campaign-builder-title"><?= _l('marketing_builder_heading'); ?></h4>
	                                    <p class="marketing-campaign-builder-helper no-margin"><?= _l('marketing_campaign_builder_helper'); ?></p>
	                                </div>
	                                <div class="marketing-campaign-builder-steps">
	                                    <span class="marketing-campaign-builder-step-chip"><?= _l('marketing_campaign_builder_step_blocks'); ?></span>
	                                    <span class="marketing-campaign-builder-step-chip"><?= _l('marketing_campaign_builder_step_preview'); ?></span>
	                                    <span class="marketing-campaign-builder-step-chip"><?= _l('marketing_campaign_builder_step_review'); ?></span>
	                                </div>
	                            </div>
	                            <div class="marketing-campaign-builder-next-step">
	                                <span class="marketing-campaign-builder-next-step-label"><?= _l('marketing_campaign_setup_next_step'); ?></span>
	                                <span class="marketing-campaign-builder-next-step-copy"><?= _l('marketing_campaign_builder_next_step_helper'); ?></span>
                                    <div class="marketing-campaign-builder-next-step-actions">
                                        <button type="button" class="btn btn-default" id="marketing-builder-preview-desktop"><?= _l('marketing_builder_preview_desktop'); ?></button>
                                        <button type="button" class="btn btn-default" id="marketing-builder-preview-mobile"><?= _l('marketing_builder_preview_mobile'); ?></button>
                                    </div>
	                            </div>
	                            <div class="marketing-campaign-builder-workspace">
	                                <div class="marketing-builder-shell">
                            <div class="marketing-builder-layout">
                                <aside class="marketing-builder-palette">
                                    <div class="marketing-builder-panel-heading" id="marketing-builder-library-header">
                                        <h5 class="bold no-margin"><?= _l('marketing_builder_palette'); ?></h5>
                                        <p class="marketing-builder-help no-margin"><?= _l('marketing_builder_drop_here'); ?></p>
                                        <div class="form-group mtop15 no-margin">
                                            <input type="text" id="marketing-builder-search" class="form-control" placeholder="<?= html_escape(_l('marketing_builder_search_elements')); ?>">
                                        </div>
                                    </div>
                                    <div id="marketing-builder-palette" class="marketing-builder-sidebar-view"></div>
                                    <div id="marketing-builder-properties" class="marketing-builder-sidebar-view hide"></div>
                                </aside>
                                <section class="marketing-builder-stage">
                                    <div class="marketing-builder-stage-header">
                                        <span id="marketing-builder-canvas-mode-label" class="marketing-builder-canvas-mode-label"><?= _l('marketing_builder_desktop_canvas'); ?></span>
                                    </div>
                                    <div id="marketing-builder-canvas-frame" class="marketing-builder-canvas-frame marketing-builder-canvas-frame--desktop">
                                        <div id="marketing-builder-canvas" class="marketing-builder-canvas"></div>
                                    </div>
                                </section>
                            </div>
                        </div>

                        <input type="hidden" id="html_content" name="html_content" value="<?= html_escape($campaign['html_content']); ?>">
                        <input type="hidden" id="builder_state" name="builder_state" value="<?= html_escape(isset($campaign['builder_state']) ? $campaign['builder_state'] : ''); ?>">
                        <input type="file" id="marketing-builder-image-input" accept=".jpg,.jpeg,.png,.gif,.webp,image/jpeg,image/png,image/gif,image/webp" class="hide">

                        <div class="panel_s marketing-builder-advanced mtop20">
                            <div class="panel-body">
                                <h4 class="bold no-margin"><?= _l('marketing_campaign_text_content'); ?></h4>
                                <div id="marketing-builder-advanced-panel" class="mtop15">
                                    <div class="form-group">
                                        <label for="text_content"><?= _l('marketing_campaign_text_content'); ?></label>
                                        <textarea id="text_content" name="text_content" class="form-control" rows="8" placeholder="<?= html_escape(_l('marketing_campaign_text_content_placeholder')); ?>"><?= html_escape($textContentValue); ?></textarea>
                                        <p class="marketing-builder-help mtop10"><?= _l('marketing_builder_plain_text_help'); ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>
	                            </div>
	                        </div>

                        <div class="marketing-campaign-form-footer" data-marketing-campaign-form-footer="1">
                            <div class="marketing-campaign-form-footer-main">
                                <div>
                                    <h4 class="marketing-campaign-form-footer-title"><?= _l('marketing_campaign_form_footer_heading'); ?></h4>
                                    <p class="marketing-campaign-form-footer-copy no-margin"><?= _l('marketing_campaign_form_footer_helper'); ?></p>
                                </div>
                                <div class="marketing-campaign-form-footer-actions">
                                    <button type="submit" class="btn btn-primary"><?= _l('submit'); ?></button>
                                    <a href="<?= admin_url('marketing/campaigns'); ?>" class="btn btn-default"><?= _l('back'); ?></a>
                                </div>
                            </div>
                            <p class="marketing-campaign-form-footer-note no-margin"><?= _l('marketing_campaign_form_footer_note'); ?></p>
                        </div>
                        <?= form_close(); ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="marketingBuilderPreviewModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title"><?= _l('marketing_builder_html_preview_title'); ?></h4>
            </div>
            <div class="modal-body">
                <div id="marketing-builder-preview-shell" class="marketing-builder-preview-shell marketing-builder-preview-shell--desktop">
                    <iframe id="marketing-builder-preview-frame" class="marketing-builder-preview-frame" title="<?= html_escape(_l('marketing_builder_html_preview_title')); ?>"></iframe>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="marketingBuilderImageModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title"><?= _l('marketing_builder_customize_image'); ?></h4>
            </div>
            <div class="modal-body">
                <div id="marketing-builder-image-upload-status" class="alert alert-info hide"></div>
                <div class="form-group">
                    <label for="marketing-builder-image-url"><?= _l('marketing_builder_image_url'); ?></label>
                    <input type="text" id="marketing-builder-image-url" class="form-control">
                </div>
                <div class="form-group">
                    <button type="button" class="btn btn-default" id="marketing-builder-image-upload-button"><?= _l('marketing_builder_upload_image'); ?></button>
                    <button type="button" class="btn btn-danger" id="marketing-builder-image-remove-button"><?= _l('marketing_builder_remove_image'); ?></button>
                </div>
                <div class="form-group">
                    <label for="marketing-builder-image-alt"><?= _l('marketing_builder_alt_text'); ?></label>
                    <input type="text" id="marketing-builder-image-alt" class="form-control">
                </div>
                <div class="row">
                    <div class="col-sm-6">
                        <div class="form-group">
                            <label for="marketing-builder-image-width"><?= _l('marketing_builder_width'); ?></label>
                            <input type="number" id="marketing-builder-image-width" class="form-control" min="40" max="600">
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="form-group">
                            <label for="marketing-builder-image-height"><?= _l('marketing_builder_height'); ?></label>
                            <input type="number" id="marketing-builder-image-height" class="form-control" min="0" max="600">
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label for="marketing-builder-image-align"><?= _l('marketing_builder_align'); ?></label>
                    <select id="marketing-builder-image-align" class="form-control">
                        <option value="left"><?= _l('marketing_builder_align_left'); ?></option>
                        <option value="center"><?= _l('marketing_builder_align_center'); ?></option>
                        <option value="right"><?= _l('marketing_builder_align_right'); ?></option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="marketing-builder-image-link"><?= _l('marketing_builder_link_url'); ?></label>
                    <input type="text" id="marketing-builder-image-link" class="form-control">
                </div>
                <div class="row">
                    <div class="col-sm-6">
                        <div class="form-group">
                            <label for="marketing-builder-image-padding-top"><?= _l('marketing_builder_padding_top'); ?></label>
                            <input type="number" id="marketing-builder-image-padding-top" class="form-control" min="0" max="120">
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="form-group">
                            <label for="marketing-builder-image-padding-right"><?= _l('marketing_builder_padding_right'); ?></label>
                            <input type="number" id="marketing-builder-image-padding-right" class="form-control" min="0" max="120">
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-sm-6">
                        <div class="form-group">
                            <label for="marketing-builder-image-padding-bottom"><?= _l('marketing_builder_padding_bottom'); ?></label>
                            <input type="number" id="marketing-builder-image-padding-bottom" class="form-control" min="0" max="120">
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="form-group">
                            <label for="marketing-builder-image-padding-left"><?= _l('marketing_builder_padding_left'); ?></label>
                            <input type="number" id="marketing-builder-image-padding-left" class="form-control" min="0" max="120">
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-sm-6">
                        <div class="form-group">
                            <label for="marketing-builder-image-margin-top"><?= _l('marketing_builder_margin_top'); ?></label>
                            <input type="number" id="marketing-builder-image-margin-top" class="form-control" min="0" max="120">
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="form-group">
                            <label for="marketing-builder-image-margin-bottom"><?= _l('marketing_builder_margin_bottom'); ?></label>
                            <input type="number" id="marketing-builder-image-margin-bottom" class="form-control" min="0" max="120">
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label for="marketing-builder-image-radius"><?= _l('marketing_builder_border_radius'); ?></label>
                    <input type="number" id="marketing-builder-image-radius" class="form-control" min="0" max="60">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal"><?= _l('marketing_builder_cancel'); ?></button>
                <button type="button" class="btn btn-primary" id="marketing-builder-image-apply"><?= _l('marketing_builder_apply'); ?></button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="marketingBuilderQrModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title"><?= _l('marketing_builder_customize_qr'); ?></h4>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label for="marketing-builder-qr-target"><?= _l('marketing_builder_qr_target'); ?></label>
                    <input type="text" id="marketing-builder-qr-target" class="form-control">
                </div>
                <div class="row">
                    <div class="col-sm-6">
                        <div class="form-group">
                            <label for="marketing-builder-qr-size"><?= _l('marketing_builder_size'); ?></label>
                            <input type="number" id="marketing-builder-qr-size" class="form-control" min="100" max="280">
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="form-group">
                            <label for="marketing-builder-qr-align"><?= _l('marketing_builder_align'); ?></label>
                            <select id="marketing-builder-qr-align" class="form-control">
                                <option value="left"><?= _l('marketing_builder_align_left'); ?></option>
                                <option value="center"><?= _l('marketing_builder_align_center'); ?></option>
                                <option value="right"><?= _l('marketing_builder_align_right'); ?></option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-sm-6">
                        <div class="form-group">
                            <label for="marketing-builder-qr-foreground"><?= _l('marketing_builder_foreground_color'); ?></label>
                            <input type="color" id="marketing-builder-qr-foreground" class="form-control">
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="form-group">
                            <label for="marketing-builder-qr-background"><?= _l('marketing_builder_background_color'); ?></label>
                            <input type="color" id="marketing-builder-qr-background" class="form-control">
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label for="marketing-builder-qr-caption"><?= _l('marketing_builder_caption'); ?></label>
                    <input type="text" id="marketing-builder-qr-caption" class="form-control">
                </div>
                <div class="row">
                    <div class="col-sm-6">
                        <div class="form-group">
                            <label for="marketing-builder-qr-padding"><?= _l('marketing_builder_padding_top'); ?></label>
                            <input type="number" id="marketing-builder-qr-padding" class="form-control" min="0" max="120">
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="form-group">
                            <label for="marketing-builder-qr-margin-bottom"><?= _l('marketing_builder_margin_bottom'); ?></label>
                            <input type="number" id="marketing-builder-qr-margin-bottom" class="form-control" min="0" max="120">
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal"><?= _l('marketing_builder_cancel'); ?></button>
                <button type="button" class="btn btn-primary" id="marketing-builder-qr-apply"><?= _l('marketing_builder_regenerate'); ?></button>
            </div>
        </div>
    </div>
</div>

<?php init_tail(); ?>
<script>
    window.MarketingCampaignBuilderConfig = {
        uploadUrl: <?= json_encode(admin_url('marketing/campaigns/upload_media')); ?>,
        estimateUrl: <?= json_encode(admin_url('marketing/campaigns/estimate_recipients')); ?>,
        isNewCampaign: <?= empty($campaign['id']) ? 'true' : 'false'; ?>,
        initialHtml: <?= json_encode((string) $campaign['html_content']); ?>,
        initialBuilderState: <?= json_encode(isset($campaign['builder_state']) ? (string) $campaign['builder_state'] : ''); ?>,
        csrf: {
            name: <?= json_encode($this->security->get_csrf_token_name()); ?>,
            hash: <?= json_encode($this->security->get_csrf_hash()); ?>
        },
        companyAddress: {
            html: <?= json_encode((string) $builderCompanyAddress['html']); ?>,
            text: <?= json_encode((string) $builderCompanyAddress['text']); ?>,
            configured: <?= !empty($builderCompanyAddress['configured']) ? 'true' : 'false'; ?>,
            source: <?= json_encode(!empty($builderCompanyAddress['source']) ? $builderCompanyAddress['source'] : 'marketing_module_fallback'); ?>
        },
        mergeTags: <?= json_encode(array_values($builderMergeTags)); ?>,
        strings: {
            selectBlock: <?= json_encode(_l('marketing_builder_select_block')); ?>,
            uploadImage: <?= json_encode(_l('marketing_builder_upload_image')); ?>,
            removeBlock: <?= json_encode(_l('marketing_builder_remove_block')); ?>,
            moveUp: <?= json_encode(_l('marketing_builder_move_up')); ?>,
            moveDown: <?= json_encode(_l('marketing_builder_move_down')); ?>,
            uploadFailed: <?= json_encode(_l('marketing_builder_upload_failed')); ?>,
            invalidLink: <?= json_encode(_l('marketing_builder_invalid_link')); ?>,
            replaceConfirm: <?= json_encode(_l('marketing_builder_replace_confirm')); ?>,
            mergeTags: <?= json_encode(_l('marketing_builder_merge_tags')); ?>,
            insertMergeTag: <?= json_encode(_l('marketing_builder_insert_merge_tag')); ?>,
            applyPreset: <?= json_encode(_l('marketing_builder_apply_preset')); ?>,
            previewDesktop: <?= json_encode(_l('marketing_builder_preview_desktop')); ?>,
            previewMobile: <?= json_encode(_l('marketing_builder_preview_mobile')); ?>,
            insertUnsubscribe: <?= json_encode(_l('marketing_builder_insert_unsubscribe')); ?>,
            mergeTagApplied: <?= json_encode(_l('marketing_builder_merge_tag_applied')); ?>,
            mergeTagSelectPrompt: <?= json_encode(_l('marketing_builder_merge_tag_select_prompt')); ?>,
            personalizeHelp: <?= json_encode(_l('marketing_builder_personalization_help')); ?>,
            richTextHelp: <?= json_encode(_l('marketing_builder_rich_text_help')); ?>,
            richTextBold: <?= json_encode(_l('marketing_builder_rich_text_bold')); ?>,
            richTextItalic: <?= json_encode(_l('marketing_builder_rich_text_italic')); ?>,
            richTextUnderline: <?= json_encode(_l('marketing_builder_rich_text_underline')); ?>,
            richTextParagraph: <?= json_encode(_l('marketing_builder_rich_text_paragraph')); ?>,
            richTextHeading2: <?= json_encode(_l('marketing_builder_rich_text_heading_2')); ?>,
            richTextHeading3: <?= json_encode(_l('marketing_builder_rich_text_heading_3')); ?>,
            richTextBullets: <?= json_encode(_l('marketing_builder_rich_text_bullets')); ?>,
            richTextNumbers: <?= json_encode(_l('marketing_builder_rich_text_numbers')); ?>,
            richTextLink: <?= json_encode(_l('marketing_builder_rich_text_link')); ?>,
            richTextClear: <?= json_encode(_l('marketing_builder_rich_text_clear')); ?>,
            richTextEditorUnavailable: <?= json_encode(_l('marketing_builder_rich_text_editor_unavailable')); ?>,
            richTextEditorFallback: <?= json_encode(_l('marketing_builder_rich_text_editor_fallback')); ?>,
            unsubscribePresent: <?= json_encode(_l('marketing_builder_unsubscribe_present')); ?>,
            unsubscribeMissing: <?= json_encode(_l('marketing_builder_unsubscribe_missing')); ?>,
            removeUnsubscribeConfirm: <?= json_encode(_l('marketing_builder_remove_unsubscribe_confirm')); ?>,
            htmlPreviewTitle: <?= json_encode(_l('marketing_builder_html_preview_title')); ?>,
            desktopCanvas: <?= json_encode(_l('marketing_builder_desktop_canvas')); ?>,
            mobileCanvas: <?= json_encode(_l('marketing_builder_mobile_canvas')); ?>,
            qrFallbackNote: <?= json_encode(_l('marketing_builder_qr_fallback_note')); ?>,
            dropHere: <?= json_encode(_l('marketing_builder_drop_here')); ?>,
            legacyBlock: <?= json_encode(_l('marketing_builder_legacy_block')); ?>,
            hero: <?= json_encode(_l('marketing_builder_block_header_hero')); ?>,
            logo: <?= json_encode(_l('marketing_builder_block_logo')); ?>,
            text: <?= json_encode(_l('marketing_builder_add_text')); ?>,
            image: <?= json_encode(_l('marketing_builder_add_image')); ?>,
            button: <?= json_encode(_l('marketing_builder_add_button')); ?>,
            divider: <?= json_encode(_l('marketing_builder_block_divider')); ?>,
            spacer: <?= json_encode(_l('marketing_builder_add_spacer')); ?>,
            columns2: <?= json_encode(_l('marketing_builder_add_two_columns')); ?>,
            columns3: <?= json_encode(_l('marketing_builder_block_three_columns')); ?>,
            qr: <?= json_encode(_l('marketing_builder_block_qr_code')); ?>,
            unsubscribe: <?= json_encode(_l('marketing_builder_insert_unsubscribe')); ?>,
            social: <?= json_encode(_l('marketing_builder_block_social_links')); ?>,
            footer: <?= json_encode(_l('marketing_builder_block_footer')); ?>,
            emailBackground: <?= json_encode(_l('marketing_builder_email_background')); ?>,
            contentBackground: <?= json_encode(_l('marketing_builder_content_background')); ?>,
            textColor: <?= json_encode(_l('marketing_builder_text_color')); ?>,
            buttonColor: <?= json_encode(_l('marketing_builder_button_color')); ?>,
            linkColor: <?= json_encode(_l('marketing_builder_link_color')); ?>,
            paddingTop: <?= json_encode(_l('marketing_builder_padding_top')); ?>,
            paddingRight: <?= json_encode(_l('marketing_builder_padding_right')); ?>,
            paddingBottom: <?= json_encode(_l('marketing_builder_padding_bottom')); ?>,
            paddingLeft: <?= json_encode(_l('marketing_builder_padding_left')); ?>,
            marginTop: <?= json_encode(_l('marketing_builder_margin_top')); ?>,
            marginBottom: <?= json_encode(_l('marketing_builder_margin_bottom')); ?>,
            borderWidth: <?= json_encode(_l('marketing_builder_border_width')); ?>,
            borderColor: <?= json_encode(_l('marketing_builder_border_color')); ?>,
            borderRadius: <?= json_encode(_l('marketing_builder_border_radius')); ?>,
            align: <?= json_encode(_l('marketing_builder_align')); ?>,
            alignLeft: <?= json_encode(_l('marketing_builder_align_left')); ?>,
            alignCenter: <?= json_encode(_l('marketing_builder_align_center')); ?>,
            alignRight: <?= json_encode(_l('marketing_builder_align_right')); ?>,
            width: <?= json_encode(_l('marketing_builder_width')); ?>,
            altText: <?= json_encode(_l('marketing_builder_alt_text')); ?>,
            linkUrl: <?= json_encode(_l('marketing_builder_link_url')); ?>,
            headline: <?= json_encode(_l('marketing_builder_headline')); ?>,
            subheadline: <?= json_encode(_l('marketing_builder_subheadline')); ?>,
            buttonLabel: <?= json_encode(_l('marketing_builder_button_label')); ?>,
            target: <?= json_encode(_l('marketing_builder_target')); ?>,
            targetSame: <?= json_encode(_l('marketing_builder_target_same')); ?>,
            targetNew: <?= json_encode(_l('marketing_builder_target_new')); ?>,
            imageUrl: <?= json_encode(_l('marketing_builder_image_url')); ?>,
            caption: <?= json_encode(_l('marketing_builder_caption')); ?>,
            size: <?= json_encode(_l('marketing_builder_size')); ?>,
            columnPreset: <?= json_encode(_l('marketing_builder_column_preset')); ?>,
            qrTarget: <?= json_encode(_l('marketing_builder_qr_target')); ?>,
            optionalImage: <?= json_encode(_l('marketing_builder_optional_image')); ?>,
            socialNote: <?= json_encode(_l('marketing_builder_social_note')); ?>,
            defaultTemplateName: <?= json_encode(_l('marketing_builder_default_template_name')); ?>,
            presetNewsletter: <?= json_encode(_l('marketing_builder_preset_newsletter')); ?>,
            presetPromotion: <?= json_encode(_l('marketing_builder_preset_promotion')); ?>,
            presetRealEstate: <?= json_encode(_l('marketing_builder_preset_real_estate')); ?>,
            presetProduct: <?= json_encode(_l('marketing_builder_preset_product')); ?>,
            presetSimpleText: <?= json_encode(_l('marketing_builder_preset_simple_text')); ?>,
            presetConferenceEvent: <?= json_encode(_l('marketing_builder_preset_conference_event')); ?>,
            globalSettings: <?= json_encode(_l('marketing_builder_global_settings')); ?>,
            blockSettings: <?= json_encode(_l('marketing_builder_block_settings')); ?>,
            background: <?= json_encode(_l('marketing_builder_background')); ?>,
            structure: <?= json_encode(_l('marketing_builder_structure')); ?>,
            basic: <?= json_encode(_l('marketing_builder_basic')); ?>,
            media: <?= json_encode(_l('marketing_builder_media')); ?>,
            marketing: <?= json_encode(_l('marketing_builder_marketing')); ?>,
            content: <?= json_encode(_l('marketing_builder_content')); ?>,
            compliance: <?= json_encode(_l('marketing_builder_compliance')); ?>,
            socialCategory: <?= json_encode(_l('marketing_builder_social')); ?>,
            settingsContent: <?= json_encode(_l('marketing_builder_settings_content')); ?>,
            settingsStyle: <?= json_encode(_l('marketing_builder_settings_style')); ?>,
            settingsAdvanced: <?= json_encode(_l('marketing_builder_settings_advanced')); ?>,
            editBlock: <?= json_encode(_l('marketing_builder_edit_block')); ?>,
            headingBlock: <?= json_encode(_l('marketing_builder_heading_block')); ?>,
            textEditorBlock: <?= json_encode(_l('marketing_builder_text_editor_block')); ?>,
            iconBlock: <?= json_encode(_l('marketing_builder_icon_block')); ?>,
            innerSectionBlock: <?= json_encode(_l('marketing_builder_inner_section_block')); ?>,
            imageBoxBlock: <?= json_encode(_l('marketing_builder_image_box_block')); ?>,
            galleryBlock: <?= json_encode(_l('marketing_builder_gallery_block')); ?>,
            ctaBlock: <?= json_encode(_l('marketing_builder_cta_block')); ?>,
            featureListBlock: <?= json_encode(_l('marketing_builder_feature_list_block')); ?>,
            testimonialBlock: <?= json_encode(_l('marketing_builder_testimonial_block')); ?>,
            couponBlock: <?= json_encode(_l('marketing_builder_coupon_block')); ?>,
            socialIconsBlock: <?= json_encode(_l('marketing_builder_social_icons_block')); ?>,
            companyAddressBlock: <?= json_encode(_l('marketing_builder_company_address_block')); ?>,
            emailFooterBlock: <?= json_encode(_l('marketing_builder_email_footer_block')); ?>,
            fontSize: <?= json_encode(_l('marketing_builder_font_size')); ?>,
            fontFamily: <?= json_encode(_l('marketing_builder_font_family')); ?>,
            fontWeight: <?= json_encode(_l('marketing_builder_font_weight')); ?>,
            lineHeight: <?= json_encode(_l('marketing_builder_line_height')); ?>,
            objectFit: <?= json_encode(_l('marketing_builder_object_fit')); ?>,
            customLabel: <?= json_encode(_l('marketing_builder_custom_label')); ?>,
            backToElements: <?= json_encode(_l('marketing_builder_back_to_elements')); ?>,
            customizeImage: <?= json_encode(_l('marketing_builder_customize_image')); ?>,
            customizeQr: <?= json_encode(_l('marketing_builder_customize_qr')); ?>,
            duplicateBlock: <?= json_encode(_l('marketing_builder_duplicate_block')); ?>,
            uploading: <?= json_encode(_l('marketing_builder_uploading')); ?>,
            uploadSuccess: <?= json_encode(_l('marketing_builder_upload_success')); ?>,
            removeImage: <?= json_encode(_l('marketing_builder_remove_image')); ?>,
            heroImage: <?= json_encode(_l('marketing_builder_hero_image')); ?>,
            heroHeadline: <?= json_encode(_l('marketing_builder_hero_headline')); ?>,
            heroSubheadline: <?= json_encode(_l('marketing_builder_hero_subheadline')); ?>,
            heroButton: <?= json_encode(_l('marketing_builder_hero_button')); ?>,
            hidden: <?= json_encode(_l('marketing_builder_hidden')); ?>,
            hide: <?= json_encode(_l('marketing_builder_hide')); ?>,
            restore: <?= json_encode(_l('marketing_builder_restore')); ?>,
            addRestore: <?= json_encode(_l('marketing_builder_add_restore')); ?>,
            buttonText: <?= json_encode(_l('marketing_builder_button_text')); ?>,
            height: <?= json_encode(_l('marketing_builder_height')); ?>,
            apply: <?= json_encode(_l('marketing_builder_apply')); ?>,
            cancel: <?= json_encode(_l('marketing_builder_cancel')); ?>,
            companyAddressUseSaved: <?= json_encode(_l('marketing_builder_company_address_use_saved')); ?>,
            companyAddressSavedConfigured: <?= json_encode(_l('marketing_builder_company_address_saved_configured')); ?>,
            companyAddressSavedMissing: <?= json_encode(_l('marketing_builder_company_address_saved_missing')); ?>,
            foregroundColor: <?= json_encode(_l('marketing_builder_foreground_color')); ?>,
            backgroundColor: <?= json_encode(_l('marketing_builder_background_color')); ?>,
            regenerate: <?= json_encode(_l('marketing_builder_regenerate')); ?>
        }
    };
</script>
<script src="<?= html_escape($quillJsUrl . '?v=' . $quillJsVer); ?>"></script>
<script src="<?= html_escape($builderJsUrl . '?v=' . $builderJsVer); ?>"></script>
<script>
    (function (window, document) {
        'use strict';

        function ready(fn) {
            if (document.readyState !== 'loading') {
                fn();
                return;
            }
            document.addEventListener('DOMContentLoaded', fn);
        }

        ready(function () {
            var estimateButton = document.getElementById('marketing-estimate-recipients');
            var estimateResult = document.getElementById('marketing-estimate-recipients-result');
            var form = document.getElementById('marketing_campaign_form');
            var channelField = document.getElementById('delivery_channel');
            var subjectField = document.getElementById('subject');
            var whatsappFields = document.getElementById('marketing-whatsapp-fields');
            var emailFields = document.querySelectorAll('.marketing-email-fields');
            var listField = document.getElementById('list_id');
            var selectedAudienceLabel = document.getElementById('marketing-campaign-audience-selected-label');
            var campaignIdField = document.getElementById('marketing-campaign-id');
            var modeFields = document.querySelectorAll('input[name="targeting_send_mode"]');
            var targetingFields = document.getElementById('marketing-targeting-fields');
            var config = window.MarketingCampaignBuilderConfig || {};

            function updateSelectedAudienceLabel() {
                var selectedOption;
                var label;

                if (!selectedAudienceLabel || !listField) {
                    return;
                }

                selectedOption = listField.options && listField.selectedIndex >= 0 ? listField.options[listField.selectedIndex] : null;
                label = selectedOption && selectedOption.value ? String(selectedOption.text || '').trim() : '';

                selectedAudienceLabel.textContent = label || selectedAudienceLabel.getAttribute('data-empty-label') || <?= json_encode(_l('marketing_campaign_audience_summary_none')); ?>;
            }

            function updateTargetingState() {
                var filtered = document.getElementById('targeting_send_mode_filtered');
                var enabled = filtered && filtered.checked;
                if (targetingFields) {
                    targetingFields.style.display = enabled ? '' : 'none';
                }
            }

            function updateScheduleState() {
                var scheduleLater = document.getElementById('schedule_mode_later');
                var scheduledAtField = document.getElementById('scheduled_at');
                if (!scheduledAtField) {
                    return;
                }

                var enabled = scheduleLater && scheduleLater.checked;
                scheduledAtField.disabled = !enabled;
                if (!enabled) {
                    scheduledAtField.value = '';
                }
            }

            function updateChannelState() {
                var isWhatsapp = channelField && channelField.value === 'whatsapp';

                if (whatsappFields) {
                    whatsappFields.classList.toggle('hide', !isWhatsapp);
                }

                Array.prototype.forEach.call(emailFields, function (field) {
                    field.classList.toggle('hide', isWhatsapp);
                });

                if (subjectField) {
                    subjectField.required = !isWhatsapp;
                }
            }

            Array.prototype.forEach.call(modeFields, function (field) {
                field.addEventListener('change', updateTargetingState);
            });
            updateTargetingState();

            Array.prototype.forEach.call(document.querySelectorAll('input[name="schedule_mode"]'), function (field) {
                field.addEventListener('change', updateScheduleState);
            });
            updateScheduleState();

            if (channelField) {
                channelField.addEventListener('change', updateChannelState);
            }
            updateChannelState();

            if (listField) {
                listField.addEventListener('change', updateSelectedAudienceLabel);
            }
            updateSelectedAudienceLabel();

            if (!estimateButton || !estimateResult || !form || !listField || !window.jQuery) {
                return;
            }

            estimateButton.addEventListener('click', function () {
                if (!listField.value) {
                    estimateResult.textContent = <?= json_encode(_l('marketing_campaign_list_required')); ?>;
                    estimateResult.className = 'text-danger mleft10';
                    return;
                }

                estimateButton.disabled = true;
                estimateResult.textContent = <?= json_encode(_l('marketing_campaign_estimate_loading')); ?>;
                estimateResult.className = 'text-muted mleft10';

                var payload = window.jQuery(form).serializeArray();
                payload.push({ name: 'campaign_id', value: campaignIdField ? campaignIdField.value : '' });

                window.jQuery.ajax({
                    url: config.estimateUrl,
                    method: 'POST',
                    dataType: 'json',
                    data: payload
                }).done(function (response) {
                    if (response && response.csrf && response.csrf.name) {
                        window.jQuery('input[name="' + response.csrf.name + '"]').val(response.csrf.hash);
                    }

                    if (response && response.success) {
                        estimateResult.textContent = response.message || '';
                        estimateResult.className = 'text-success mleft10';
                    } else {
                        estimateResult.textContent = response && response.message ? response.message : <?= json_encode(_l('marketing_campaign_segment_estimate_failed')); ?>;
                        estimateResult.className = 'text-danger mleft10';
                    }
                }).fail(function () {
                    estimateResult.textContent = <?= json_encode(_l('marketing_campaign_segment_estimate_failed')); ?>;
                    estimateResult.className = 'text-danger mleft10';
                }).always(function () {
                    estimateButton.disabled = false;
                });
            });
        });
    }(window, document));
</script>
</body>
</html>
