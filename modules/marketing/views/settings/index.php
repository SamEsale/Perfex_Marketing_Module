<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php
$settingFieldName = function ($key) {
    return 'settings[' . $key . ']';
};

$settingValue = function ($key, $default = '') use ($settings) {
    return array_key_exists($key, $settings) ? $settings[$key] : $default;
};

$settingBool = function ($key, $default = '0') use ($settingValue) {
    return (string) $settingValue($key, $default) === '1';
};

$whatsappSettingValue = function ($key, $default = '') use ($whatsapp_settings) {
    return array_key_exists($key, $whatsapp_settings) ? $whatsapp_settings[$key] : $default;
};

$licenseFieldValue = function ($key, $default = '') use ($license_form) {
    return array_key_exists($key, $license_form) ? $license_form[$key] : $default;
};

$whatsappEnabled = !empty($whatsapp_settings['enabled']);
$licenseStatusClass = 'alert-info';

if (($license_state['status'] ?? '') === 'active') {
    $licenseStatusClass = 'alert-success';
} elseif (($license_state['status'] ?? '') === 'grace') {
    $licenseStatusClass = 'alert-warning';
} elseif (in_array(($license_state['status'] ?? ''), ['inactive', 'error'], true)) {
    $licenseStatusClass = 'alert-danger';
}
?>
<?php init_head(); ?>
<style>
    .marketing-settings-shell {
        border: 1px solid #e2e8f0;
        background: linear-gradient(180deg, #f8fafc 0%, #ffffff 100%);
        border-radius: 18px;
        padding: 20px;
    }
    .marketing-settings-grid {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
        gap: 20px;
        align-items: start;
    }
    .marketing-settings-column {
        min-width: 0;
    }
    .marketing-settings-column > .panel_s + .panel_s {
        margin-top: 20px;
    }
    .marketing-settings-panel {
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 14px 28px rgba(15, 23, 42, .04);
    }
    .marketing-settings-panel .panel-body {
        padding: 22px;
    }
    .marketing-settings-column .panel_s,
    .marketing-settings-column .panel-body,
    .marketing-settings-column .form-control,
    .marketing-settings-column select.form-control,
    .marketing-settings-column textarea.form-control,
    .marketing-settings-column .table-responsive,
    .marketing-settings-column table {
        max-width: 100%;
        min-width: 0;
    }
    .marketing-settings-column .table-responsive {
        overflow-x: auto;
    }
    .marketing-settings-column .form-group:last-child,
    .marketing-settings-column .alert:last-child {
        margin-bottom: 0;
    }
    .marketing-settings-column input[readonly] {
        cursor: text;
    }
    .marketing-settings-panel-title {
        margin: 0;
        font-size: 17px;
        font-weight: 700;
        color: #0f172a;
    }
    .marketing-settings-panel-copy {
        margin: 10px 0 0;
        color: #64748b;
        font-size: 13px;
        line-height: 1.55;
    }
    .marketing-settings-form-actions {
        margin-top: 20px;
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        flex-wrap: wrap;
    }
    .marketing-license-actions {
        display: flex;
        gap: 10px;
        justify-content: flex-end;
        flex-wrap: wrap;
    }
    @media (max-width: 767px) {
        .marketing-settings-shell {
            padding: 14px;
        }
        .marketing-settings-grid {
            grid-template-columns: 1fr;
        }
        .marketing-settings-form-actions,
        .marketing-license-actions {
            justify-content: stretch;
        }
        .marketing-settings-form-actions .btn,
        .marketing-license-actions .btn {
            width: 100%;
        }
    }
</style>
<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-12">
                <div class="panel_s">
                    <div class="panel-body">
                        <h4 class="no-margin"><?= _l('marketing_settings'); ?></h4>
                        <p class="text-muted mtop10"><?= _l('marketing_settings_placeholder'); ?></p>
                        <hr />
                        <div class="marketing-settings-shell">
                            <div class="marketing-settings-grid">
                            <div class="marketing-settings-column">
                                <?= form_open(admin_url('marketing/settings/save'), ['id' => 'marketing-general-settings-form']); ?>
                                <input type="hidden" name="settings_form" value="general">
                                <div class="panel_s marketing-settings-panel">
                                    <div class="panel-body">
                                        <h4 class="marketing-settings-panel-title"><?= _l('settings'); ?></h4>
                                        <p class="marketing-settings-panel-copy"><?= _l('marketing_settings_placeholder'); ?></p>

                                        <div class="form-group">
                                            <label class="control-label"><?= _l('marketing_send_enabled'); ?></label>
                                            <div class="radio radio-primary radio-inline">
                                                <input type="radio" id="marketing_send_enabled_yes" name="<?= html_escape($settingFieldName('marketing_send_enabled')); ?>" value="1" <?= $settingBool('marketing_send_enabled') ? 'checked' : ''; ?>>
                                                <label for="marketing_send_enabled_yes"><?= _l('settings_yes'); ?></label>
                                            </div>
                                            <div class="radio radio-primary radio-inline">
                                                <input type="radio" id="marketing_send_enabled_no" name="<?= html_escape($settingFieldName('marketing_send_enabled')); ?>" value="0" <?= !$settingBool('marketing_send_enabled') ? 'checked' : ''; ?>>
                                                <label for="marketing_send_enabled_no"><?= _l('settings_no'); ?></label>
                                            </div>
                                        </div>

                                        <div class="alert alert-warning">
                                            <?= _l('marketing_settings_send_enabled_warning'); ?>
                                        </div>
                                        <div class="alert alert-info">
                                            <?= _l('marketing_delivery_source_of_truth_note'); ?>
                                        </div>

                                        <div class="form-group">
                                            <label class="control-label"><?= _l('marketing_ses_webhook_url'); ?></label>
                                            <input type="text" class="form-control" readonly value="<?= e(site_url('marketing/webhooks/ses_sns')); ?>">
                                            <p class="text-muted mtop5"><?= _l('marketing_webhook_url_runtime_note'); ?></p>
                                        </div>

                                        <div class="form-group">
                                            <label class="control-label"><?= _l('marketing_ses_verify_signature'); ?></label>
                                            <div class="radio radio-primary radio-inline">
                                                <input type="radio" id="marketing_ses_verify_signature_yes" name="<?= html_escape($settingFieldName('marketing_ses_verify_signature')); ?>" value="1" <?= $settingBool('marketing_ses_verify_signature') ? 'checked' : ''; ?>>
                                                <label for="marketing_ses_verify_signature_yes"><?= _l('settings_yes'); ?></label>
                                            </div>
                                            <div class="radio radio-primary radio-inline">
                                                <input type="radio" id="marketing_ses_verify_signature_no" name="<?= html_escape($settingFieldName('marketing_ses_verify_signature')); ?>" value="0" <?= !$settingBool('marketing_ses_verify_signature') ? 'checked' : ''; ?>>
                                                <label for="marketing_ses_verify_signature_no"><?= _l('settings_no'); ?></label>
                                            </div>
                                        </div>

                                        <?php if (!$settingBool('marketing_ses_verify_signature')) { ?>
                                            <div class="alert alert-warning">
                                                <?= _l('marketing_ses_signature_disabled_warning'); ?>
                                            </div>
                                        <?php } ?>

                                        <div class="form-group">
                                            <label for="marketing_send_per_cron_run" class="control-label"><?= _l('marketing_send_per_cron_run'); ?></label>
                                            <input type="number" min="1" id="marketing_send_per_cron_run" name="<?= html_escape($settingFieldName('marketing_send_per_cron_run')); ?>" class="form-control" value="<?= html_escape($settingValue('marketing_send_per_cron_run')); ?>">
                                        </div>
                                        <div class="form-group">
                                            <label for="marketing_send_sleep_ms" class="control-label"><?= _l('marketing_send_sleep_ms'); ?></label>
                                            <input type="number" min="0" id="marketing_send_sleep_ms" name="<?= html_escape($settingFieldName('marketing_send_sleep_ms')); ?>" class="form-control" value="<?= html_escape($settingValue('marketing_send_sleep_ms')); ?>">
                                        </div>
                                        <div class="form-group">
                                            <label for="marketing_send_hourly_cap" class="control-label"><?= _l('marketing_send_hourly_cap'); ?></label>
                                            <input type="number" min="0" id="marketing_send_hourly_cap" name="<?= html_escape($settingFieldName('marketing_send_hourly_cap')); ?>" class="form-control" value="<?= html_escape($settingValue('marketing_send_hourly_cap')); ?>">
                                        </div>
                                        <div class="form-group">
                                            <label for="marketing_send_daily_cap" class="control-label"><?= _l('marketing_send_daily_cap'); ?></label>
                                            <input type="number" min="0" id="marketing_send_daily_cap" name="<?= html_escape($settingFieldName('marketing_send_daily_cap')); ?>" class="form-control" value="<?= html_escape($settingValue('marketing_send_daily_cap')); ?>">
                                        </div>
                                        <div class="row">
                                            <div class="col-sm-6">
                                                <div class="form-group">
                                                    <label for="marketing_send_start_hour" class="control-label"><?= _l('marketing_send_start_hour'); ?></label>
                                                    <input type="time" id="marketing_send_start_hour" name="<?= html_escape($settingFieldName('marketing_send_start_hour')); ?>" class="form-control" value="<?= html_escape($settingValue('marketing_send_start_hour', '00:00')); ?>">
                                                </div>
                                            </div>
                                            <div class="col-sm-6">
                                                <div class="form-group">
                                                    <label for="marketing_send_end_hour" class="control-label"><?= _l('marketing_send_end_hour'); ?></label>
                                                    <input type="time" id="marketing_send_end_hour" name="<?= html_escape($settingFieldName('marketing_send_end_hour')); ?>" class="form-control" value="<?= html_escape($settingValue('marketing_send_end_hour', '23:59')); ?>">
                                                </div>
                                            </div>
                                        </div>
                                        <p class="text-muted mtop5"><?= _l('marketing_send_window_help'); ?></p>
                                        <div class="form-group">
                                            <label for="marketing_default_from_name" class="control-label"><?= _l('marketing_default_from_name'); ?></label>
                                            <input type="text" id="marketing_default_from_name" name="<?= html_escape($settingFieldName('marketing_default_from_name')); ?>" class="form-control" value="<?= html_escape($settingValue('marketing_default_from_name')); ?>">
                                        </div>
                                        <div class="form-group">
                                            <label for="marketing_default_from_email" class="control-label"><?= _l('marketing_default_from_email'); ?></label>
                                            <input type="email" id="marketing_default_from_email" name="<?= html_escape($settingFieldName('marketing_default_from_email')); ?>" class="form-control" value="<?= html_escape($settingValue('marketing_default_from_email')); ?>">
                                        </div>
                                        <div class="form-group">
                                            <label for="marketing_default_reply_to_email" class="control-label"><?= _l('marketing_default_reply_to_email'); ?></label>
                                            <input type="email" id="marketing_default_reply_to_email" name="<?= html_escape($settingFieldName('marketing_default_reply_to_email')); ?>" class="form-control" value="<?= html_escape($settingValue('marketing_default_reply_to_email')); ?>">
                                        </div>
                                        <div class="form-group">
                                            <label class="control-label"><?= _l('marketing_track_opens'); ?></label>
                                            <div class="radio radio-primary radio-inline">
                                                <input type="radio" id="marketing_track_opens_yes" name="<?= html_escape($settingFieldName('marketing_track_opens')); ?>" value="1" <?= $settingBool('marketing_track_opens', '1') ? 'checked' : ''; ?>>
                                                <label for="marketing_track_opens_yes"><?= _l('settings_yes'); ?></label>
                                            </div>
                                            <div class="radio radio-primary radio-inline">
                                                <input type="radio" id="marketing_track_opens_no" name="<?= html_escape($settingFieldName('marketing_track_opens')); ?>" value="0" <?= !$settingBool('marketing_track_opens', '1') ? 'checked' : ''; ?>>
                                                <label for="marketing_track_opens_no"><?= _l('settings_no'); ?></label>
                                            </div>
                                            <p class="text-muted mtop5"><?= _l('marketing_tracking_defaults_help'); ?></p>
                                        </div>
                                        <div class="form-group">
                                            <label class="control-label"><?= _l('marketing_track_clicks'); ?></label>
                                            <div class="radio radio-primary radio-inline">
                                                <input type="radio" id="marketing_track_clicks_yes" name="<?= html_escape($settingFieldName('marketing_track_clicks')); ?>" value="1" <?= $settingBool('marketing_track_clicks', '1') ? 'checked' : ''; ?>>
                                                <label for="marketing_track_clicks_yes"><?= _l('settings_yes'); ?></label>
                                            </div>
                                            <div class="radio radio-primary radio-inline">
                                                <input type="radio" id="marketing_track_clicks_no" name="<?= html_escape($settingFieldName('marketing_track_clicks')); ?>" value="0" <?= !$settingBool('marketing_track_clicks', '1') ? 'checked' : ''; ?>>
                                                <label for="marketing_track_clicks_no"><?= _l('settings_no'); ?></label>
                                            </div>
                                            <p class="text-muted mtop5"><?= _l('marketing_tracking_defaults_help'); ?></p>
                                        </div>
                                        <div class="marketing-settings-form-actions">
                                            <button type="submit" class="btn btn-primary"><?= _l('marketing_save_general_settings'); ?></button>
                                        </div>
                                    </div>
                                </div>
                                <?= form_close(); ?>

                                <!-- marketing-reply-lead-settings-panel -->
                                <?= form_open(admin_url('marketing/settings/save'), ['id' => 'marketing-reply-lead-settings-form']); ?>
                                <input type="hidden" name="settings_form" value="reply_leads">
                                <div class="panel_s marketing-settings-panel">
                                    <div class="panel-body">
                                        <h4 class="marketing-settings-panel-title"><?= _l('marketing_reply_to_lead_settings'); ?></h4>
                                        <p class="marketing-settings-panel-copy"><?= _l('marketing_reply_to_lead_settings_note'); ?></p>

                                        <?php if (!$reply_lead_available) { ?>
                                            <div class="alert alert-warning">
                                                <?= _l('marketing_reply_to_lead_unavailable_note'); ?>
                                            </div>
                                        <?php } ?>
                                        <?php if (!$reply_lead_dependencies_available) { ?>
                                            <div class="alert alert-warning">
                                                <?= _l('marketing_reply_to_lead_dependencies_warning'); ?>
                                            </div>
                                        <?php } ?>

                                        <div class="form-group">
                                            <label class="control-label"><?= _l('marketing_reply_to_lead_enabled'); ?></label>
                                            <div class="radio radio-primary radio-inline">
                                                <input type="radio" id="marketing_reply_to_lead_enabled_yes" name="<?= html_escape($settingFieldName('marketing_reply_to_lead_enabled')); ?>" value="1" <?= $settingBool('marketing_reply_to_lead_enabled') ? 'checked' : ''; ?>>
                                                <label for="marketing_reply_to_lead_enabled_yes"><?= _l('settings_yes'); ?></label>
                                            </div>
                                            <div class="radio radio-primary radio-inline">
                                                <input type="radio" id="marketing_reply_to_lead_enabled_no" name="<?= html_escape($settingFieldName('marketing_reply_to_lead_enabled')); ?>" value="0" <?= !$settingBool('marketing_reply_to_lead_enabled') ? 'checked' : ''; ?>>
                                                <label for="marketing_reply_to_lead_enabled_no"><?= _l('settings_no'); ?></label>
                                            </div>
                                        </div>

                                        <div class="form-group">
                                            <label class="control-label"><?= _l('marketing_reply_to_lead_unknown_only'); ?></label>
                                            <div class="radio radio-primary radio-inline">
                                                <input type="radio" id="marketing_reply_to_lead_unknown_only_yes" name="<?= html_escape($settingFieldName('marketing_reply_to_lead_unknown_only')); ?>" value="1" <?= $settingBool('marketing_reply_to_lead_unknown_only', '1') ? 'checked' : ''; ?>>
                                                <label for="marketing_reply_to_lead_unknown_only_yes"><?= _l('settings_yes'); ?></label>
                                            </div>
                                            <div class="radio radio-primary radio-inline">
                                                <input type="radio" id="marketing_reply_to_lead_unknown_only_no" name="<?= html_escape($settingFieldName('marketing_reply_to_lead_unknown_only')); ?>" value="0" <?= !$settingBool('marketing_reply_to_lead_unknown_only', '1') ? 'checked' : ''; ?>>
                                                <label for="marketing_reply_to_lead_unknown_only_no"><?= _l('settings_no'); ?></label>
                                            </div>
                                        </div>

                                        <div class="form-group">
                                            <label for="marketing_reply_to_lead_status" class="control-label"><?= _l('marketing_reply_to_lead_status'); ?></label>
                                            <select id="marketing_reply_to_lead_status" name="<?= html_escape($settingFieldName('marketing_reply_to_lead_status')); ?>" class="form-control" <?= empty($lead_status_options) ? 'disabled' : ''; ?>>
                                                <option value="0"><?= _l('marketing_reply_to_lead_select_none'); ?></option>
                                                <?php foreach ($lead_status_options as $statusOption) { ?>
                                                    <option value="<?= (int) $statusOption['id']; ?>" <?= (int) $settingValue('marketing_reply_to_lead_status', '0') === (int) $statusOption['id'] ? 'selected' : ''; ?>><?= e($statusOption['name']); ?></option>
                                                <?php } ?>
                                            </select>
                                            <?php if (empty($lead_status_options)) { ?>
                                                <input type="hidden" name="<?= html_escape($settingFieldName('marketing_reply_to_lead_status')); ?>" value="<?= html_escape($settingValue('marketing_reply_to_lead_status', '0')); ?>">
                                            <?php } ?>
                                        </div>

                                        <div class="form-group">
                                            <label for="marketing_reply_to_lead_source" class="control-label"><?= _l('marketing_reply_to_lead_source'); ?></label>
                                            <select id="marketing_reply_to_lead_source" name="<?= html_escape($settingFieldName('marketing_reply_to_lead_source')); ?>" class="form-control" <?= empty($lead_source_options) ? 'disabled' : ''; ?>>
                                                <option value="0"><?= _l('marketing_reply_to_lead_select_none'); ?></option>
                                                <?php foreach ($lead_source_options as $sourceOption) { ?>
                                                    <option value="<?= (int) $sourceOption['id']; ?>" <?= (int) $settingValue('marketing_reply_to_lead_source', '0') === (int) $sourceOption['id'] ? 'selected' : ''; ?>><?= e($sourceOption['name']); ?></option>
                                                <?php } ?>
                                            </select>
                                            <?php if (empty($lead_source_options)) { ?>
                                                <input type="hidden" name="<?= html_escape($settingFieldName('marketing_reply_to_lead_source')); ?>" value="<?= html_escape($settingValue('marketing_reply_to_lead_source', '0')); ?>">
                                            <?php } ?>
                                        </div>

                                        <div class="form-group">
                                            <label for="marketing_reply_to_lead_assigned_staff" class="control-label"><?= _l('marketing_reply_to_lead_assigned_staff'); ?></label>
                                            <select id="marketing_reply_to_lead_assigned_staff" name="<?= html_escape($settingFieldName('marketing_reply_to_lead_assigned_staff')); ?>" class="form-control" <?= empty($lead_staff_options) ? 'disabled' : ''; ?>>
                                                <option value="0"><?= _l('marketing_reply_to_lead_select_none'); ?></option>
                                                <?php foreach ($lead_staff_options as $staffOption) { ?>
                                                    <option value="<?= (int) $staffOption['staffid']; ?>" <?= (int) $settingValue('marketing_reply_to_lead_assigned_staff', '0') === (int) $staffOption['staffid'] ? 'selected' : ''; ?>><?= e(trim($staffOption['firstname'] . ' ' . $staffOption['lastname'])); ?></option>
                                                <?php } ?>
                                            </select>
                                            <?php if (empty($lead_staff_options)) { ?>
                                                <input type="hidden" name="<?= html_escape($settingFieldName('marketing_reply_to_lead_assigned_staff')); ?>" value="<?= html_escape($settingValue('marketing_reply_to_lead_assigned_staff', '0')); ?>">
                                            <?php } ?>
                                        </div>

                                        <div class="form-group">
                                            <label for="marketing_reply_to_lead_name_prefix" class="control-label"><?= _l('marketing_reply_to_lead_name_prefix'); ?></label>
                                            <input type="text" id="marketing_reply_to_lead_name_prefix" name="<?= html_escape($settingFieldName('marketing_reply_to_lead_name_prefix')); ?>" class="form-control" value="<?= html_escape($settingValue('marketing_reply_to_lead_name_prefix', 'Marketing Reply')); ?>">
                                        </div>

                                        <div class="marketing-settings-form-actions">
                                            <button type="submit" class="btn btn-primary"><?= _l('save'); ?></button>
                                        </div>
                                    </div>
                                </div>
                                <?= form_close(); ?>

                                <div class="panel_s marketing-settings-panel">
                                    <div class="panel-body">
                                        <h4 class="marketing-settings-panel-title"><?= _l('marketing_inbound_reply_webhook_url'); ?></h4>
                                        <p class="marketing-settings-panel-copy"><?= _l('marketing_reply_to_lead_settings_note'); ?></p>
                                        <div class="form-group no-margin">
                                            <label class="control-label"><?= _l('marketing_inbound_reply_webhook_url'); ?></label>
                                            <input type="text" class="form-control" readonly value="<?= e(site_url('marketing/webhooks/inbound_email')); ?>">
                                            <p class="text-muted mtop5"><?= _l('marketing_webhook_url_runtime_note'); ?></p>
                                        </div>
                                    </div>
                                </div>

                                <div class="panel_s marketing-settings-panel">
                                    <div class="panel-body">
                                        <h4 class="marketing-settings-panel-title"><?= _l('marketing_license_updates_heading'); ?></h4>
                                        <p class="marketing-settings-panel-copy"><?= _l('marketing_license_updates_note'); ?></p>

                                        <?php if (empty($license_available)) { ?>
                                            <div class="alert alert-warning">
                                                <?= _l('marketing_license_unavailable_warning'); ?>
                                            </div>
                                        <?php } else { ?>
                                            <div class="alert <?= html_escape($licenseStatusClass); ?>">
                                                <?= html_escape($license_state['status_label'] ?? 'Inactive'); ?>
                                                <?php if (!empty($license_state['grace_active']) && !empty($license_state['grace_until'])) { ?>
                                                    <br />
                                                    <?= sprintf(_l('marketing_license_grace_warning'), html_escape($license_state['grace_until'])); ?>
                                                <?php } ?>
                                                <?php if (!empty($license_state['last_error'])) { ?>
                                                    <br />
                                                    <?= html_escape($license_state['last_error']); ?>
                                                <?php } ?>
                                            </div>

                                            <?= form_open(admin_url('marketing/settings/save'), ['id' => 'marketing-license-settings-form']); ?>
                                            <input type="hidden" name="settings_form" value="license">

                                            <div class="form-group">
                                                <label for="marketing_license_key" class="control-label"><?= _l('marketing_license_key'); ?></label>
                                                <input type="password" id="marketing_license_key" name="marketing_license_key" class="form-control" value="" autocomplete="off" placeholder="<?= html_escape(_l('marketing_license_key_placeholder')); ?>">
                                                <p class="text-muted mtop5">
                                                    <?php if (!empty($license_state['masked_license_key'])) { ?>
                                                        <?= sprintf(_l('marketing_license_key_masked'), html_escape($license_state['masked_license_key'])); ?>
                                                    <?php } else { ?>
                                                        <?= _l('marketing_license_key_empty_note'); ?>
                                                    <?php } ?>
                                                </p>
                                            </div>

                                            <div class="form-group">
                                                <label for="marketing_license_email" class="control-label"><?= _l('marketing_license_email'); ?></label>
                                                <input type="email" id="marketing_license_email" name="marketing_license_email" class="form-control" value="<?= html_escape($licenseFieldValue('marketing_license_email')); ?>">
                                            </div>

                                            <div class="form-group">
                                                <label for="marketing_license_status" class="control-label"><?= _l('marketing_license_status'); ?></label>
                                                <input type="text" id="marketing_license_status" class="form-control" readonly value="<?= html_escape($license_state['status_label'] ?? 'Inactive'); ?>">
                                            </div>

                                            <div class="form-group">
                                                <label for="marketing_license_domain" class="control-label"><?= _l('marketing_license_domain'); ?></label>
                                                <input type="text" id="marketing_license_domain" class="form-control" readonly value="<?= html_escape($license_state['licensed_domain'] !== '' ? $license_state['licensed_domain'] : ''); ?>">
                                                <p class="text-muted mtop5"><?= sprintf(_l('marketing_license_current_domain_note'), html_escape($license_state['current_domain'] ?? 'unknown-domain')); ?></p>
                                            </div>

                                            <div class="form-group">
                                                <label for="marketing_license_last_checked" class="control-label"><?= _l('marketing_license_last_checked'); ?></label>
                                                <input type="text" id="marketing_license_last_checked" class="form-control" readonly value="<?= html_escape($license_state['last_checked_display'] ?? 'Never'); ?>">
                                            </div>

                                            <div class="form-group">
                                                <label for="marketing_license_module_version" class="control-label"><?= _l('marketing_license_module_version'); ?></label>
                                                <input type="text" id="marketing_license_module_version" class="form-control" readonly value="<?= html_escape($license_state['module_version'] ?? '1.1.0'); ?>">
                                            </div>

                                            <div class="alert alert-info">
                                                <?= sprintf(
                                                    _l('marketing_license_api_config_note'),
                                                    html_escape($license_state['api_base_url_hint'] ?? 'https://license.spitol.com/api')
                                                ); ?>
                                            </div>

                                            <div class="marketing-license-actions">
                                                <button type="submit" name="license_action" value="activate" class="btn btn-primary"><?= _l('marketing_license_activate_button'); ?></button>
                                                <button type="submit" name="license_action" value="deactivate" class="btn btn-danger" onclick="return confirm('<?= html_escape(_l('marketing_license_deactivate_confirm')); ?>');"><?= _l('marketing_license_deactivate_button'); ?></button>
                                            </div>
                                            <?= form_close(); ?>
                                        <?php } ?>
                                    </div>
                                </div>

                            </div>

                            <div class="marketing-settings-column">
                                <?= form_open(admin_url('marketing/settings/save'), ['id' => 'marketing-smtp-settings-form']); ?>
                                <input type="hidden" name="settings_form" value="smtp">
                                <div class="panel_s marketing-settings-panel">
                                    <div class="panel-body">
                                        <h4 class="marketing-settings-panel-title"><?= _l('marketing_smtp_settings_section'); ?></h4>
                                        <p class="marketing-settings-panel-copy"><?= _l('marketing_smtp_settings_note'); ?></p>

                                        <div class="form-group">
                                            <label class="control-label"><?= _l('marketing_smtp_enabled'); ?></label>
                                            <div class="radio radio-primary radio-inline">
                                                <input type="radio" id="marketing_smtp_enabled_yes" name="<?= html_escape($settingFieldName('marketing_smtp_enabled')); ?>" value="1" <?= $settingBool('marketing_smtp_enabled') ? 'checked' : ''; ?>>
                                                <label for="marketing_smtp_enabled_yes"><?= _l('settings_yes'); ?></label>
                                            </div>
                                            <div class="radio radio-primary radio-inline">
                                                <input type="radio" id="marketing_smtp_enabled_no" name="<?= html_escape($settingFieldName('marketing_smtp_enabled')); ?>" value="0" <?= !$settingBool('marketing_smtp_enabled') ? 'checked' : ''; ?>>
                                                <label for="marketing_smtp_enabled_no"><?= _l('settings_no'); ?></label>
                                            </div>
                                        </div>

                                        <div class="alert alert-info">
                                            <?= _l('marketing_smtp_settings_warning'); ?>
                                        </div>

                                        <div class="form-group">
                                            <label for="marketing_smtp_host" class="control-label"><?= _l('marketing_smtp_host'); ?></label>
                                            <input type="text" id="marketing_smtp_host" name="<?= html_escape($settingFieldName('marketing_smtp_host')); ?>" class="form-control" value="<?= html_escape($settingValue('marketing_smtp_host')); ?>">
                                        </div>
                                        <div class="form-group">
                                            <label for="marketing_smtp_port" class="control-label"><?= _l('marketing_smtp_port'); ?></label>
                                            <input type="number" min="1" max="65535" id="marketing_smtp_port" name="<?= html_escape($settingFieldName('marketing_smtp_port')); ?>" class="form-control" value="<?= html_escape($settingValue('marketing_smtp_port')); ?>">
                                        </div>
                                        <div class="form-group">
                                            <label for="marketing_smtp_username" class="control-label"><?= _l('marketing_smtp_username'); ?></label>
                                            <input type="text" id="marketing_smtp_username" name="<?= html_escape($settingFieldName('marketing_smtp_username')); ?>" class="form-control" value="<?= html_escape($settingValue('marketing_smtp_username')); ?>">
                                        </div>
                                        <div class="form-group">
                                            <label for="marketing_smtp_password" class="control-label"><?= _l('marketing_smtp_password'); ?></label>
                                            <input type="password" id="marketing_smtp_password" name="marketing_smtp_password" class="form-control" value="" placeholder="<?= html_escape(_l('marketing_smtp_password_help')); ?>">
                                        </div>
                                        <div class="form-group">
                                            <label for="marketing_smtp_from_email" class="control-label"><?= _l('marketing_smtp_from_email'); ?></label>
                                            <input type="email" id="marketing_smtp_from_email" name="<?= html_escape($settingFieldName('marketing_smtp_from_email')); ?>" class="form-control" value="<?= html_escape($settingValue('marketing_smtp_from_email')); ?>">
                                        </div>
                                        <div class="form-group">
                                            <label for="marketing_smtp_from_name" class="control-label"><?= _l('marketing_smtp_from_name'); ?></label>
                                            <input type="text" id="marketing_smtp_from_name" name="<?= html_escape($settingFieldName('marketing_smtp_from_name')); ?>" class="form-control" value="<?= html_escape($settingValue('marketing_smtp_from_name')); ?>">
                                        </div>
                                        <div class="form-group">
                                            <label for="marketing_smtp_reply_to" class="control-label"><?= _l('marketing_smtp_reply_to'); ?></label>
                                            <input type="email" id="marketing_smtp_reply_to" name="<?= html_escape($settingFieldName('marketing_smtp_reply_to')); ?>" class="form-control" value="<?= html_escape($settingValue('marketing_smtp_reply_to')); ?>">
                                        </div>
                                        <div class="form-group">
                                            <label for="marketing_smtp_encryption" class="control-label"><?= _l('marketing_smtp_encryption'); ?></label>
                                            <select id="marketing_smtp_encryption" name="<?= html_escape($settingFieldName('marketing_smtp_encryption')); ?>" class="form-control">
                                                <option value="none" <?= (string) $settingValue('marketing_smtp_encryption', 'none') === 'none' ? 'selected' : ''; ?>><?= _l('marketing_smtp_encryption_none'); ?></option>
                                                <option value="tls" <?= (string) $settingValue('marketing_smtp_encryption', 'none') === 'tls' ? 'selected' : ''; ?>><?= _l('marketing_smtp_encryption_tls'); ?></option>
                                                <option value="ssl" <?= (string) $settingValue('marketing_smtp_encryption', 'none') === 'ssl' ? 'selected' : ''; ?>><?= _l('marketing_smtp_encryption_ssl'); ?></option>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label for="marketing_ses_region" class="control-label"><?= _l('marketing_ses_region'); ?></label>
                                            <input type="text" id="marketing_ses_region" name="<?= html_escape($settingFieldName('marketing_ses_region')); ?>" class="form-control" value="<?= html_escape($settingValue('marketing_ses_region')); ?>">
                                        </div>
                                        <div class="form-group">
                                            <label for="marketing_ses_configuration_set" class="control-label"><?= _l('marketing_ses_configuration_set'); ?></label>
                                            <input type="text" id="marketing_ses_configuration_set" name="<?= html_escape($settingFieldName('marketing_ses_configuration_set')); ?>" class="form-control" value="<?= html_escape($settingValue('marketing_ses_configuration_set')); ?>">
                                            <p class="text-muted mtop5"><?= _l('marketing_ses_configuration_set_help'); ?></p>
                                        </div>
                                        <div class="marketing-settings-form-actions">
                                            <button type="submit" class="btn btn-primary"><?= _l('marketing_save_smtp_settings'); ?></button>
                                        </div>
                                    </div>
                                </div>
                                <?= form_close(); ?>

                                <div class="panel_s marketing-settings-panel">
                                    <div class="panel-body">
                                        <h4 class="marketing-settings-panel-title"><?= _l('marketing_smtp_test_heading'); ?></h4>
                                        <p class="marketing-settings-panel-copy"><?= _l('marketing_smtp_test_note'); ?></p>
                                        <?= form_open(admin_url('marketing/settings/test_smtp'), ['id' => 'marketing-smtp-test-form']); ?>
                                        <?= render_input('test_email', 'marketing_smtp_test_email_address', '', 'email'); ?>
                                        <div class="marketing-settings-form-actions">
                                            <button type="submit" class="btn btn-default"><?= _l('marketing_smtp_test_button'); ?></button>
                                        </div>
                                        <?= form_close(); ?>
                                    </div>
                                </div>

                                <!-- marketing-whatsapp-settings-form-start -->
                                <!-- marketing-whatsapp-form-action: save -->
                                <?= form_open(admin_url('marketing/settings/save'), ['id' => 'marketing-whatsapp-settings-form']); ?>
                                <input type="hidden" name="settings_form" value="whatsapp">
                                <input type="hidden" name="whatsapp_form_probe" value="1">
                                <div class="panel_s marketing-settings-panel">
                                    <div class="panel-body">
                                        <h4 class="marketing-settings-panel-title"><?= _l('marketing_whatsapp_settings'); ?></h4>
                                        <p class="marketing-settings-panel-copy"><?= _l('marketing_whatsapp_settings_note'); ?></p>

                                        <div class="form-group">
                                            <label class="control-label"><?= _l('marketing_whatsapp_enabled'); ?></label>
                                            <input type="hidden" name="marketing_whatsapp_enabled" value="0">
                                            <div class="radio radio-primary radio-inline">
                                                <input type="radio" id="marketing_whatsapp_enabled_yes" name="marketing_whatsapp_enabled" value="1" <?= $whatsappEnabled ? 'checked' : ''; ?>>
                                                <label for="marketing_whatsapp_enabled_yes"><?= _l('settings_yes'); ?></label>
                                            </div>
                                            <div class="radio radio-primary radio-inline">
                                                <input type="radio" id="marketing_whatsapp_enabled_no" name="marketing_whatsapp_enabled" value="0" <?= !$whatsappEnabled ? 'checked' : ''; ?>>
                                                <label for="marketing_whatsapp_enabled_no"><?= _l('settings_no'); ?></label>
                                            </div>
                                        </div>

                                        <div class="alert alert-info">
                                            <?= _l('marketing_whatsapp_settings_warning'); ?>
                                        </div>

                                        <div class="form-group">
                                            <label class="control-label"><?= _l('marketing_whatsapp_webhook_url'); ?></label>
                                            <input type="text" class="form-control" readonly value="<?= e(site_url('marketing/webhooks/whatsapp')); ?>">
                                            <p class="text-muted mtop5"><?= _l('marketing_webhook_url_runtime_note'); ?></p>
                                        </div>

                                        <div class="form-group">
                                            <label for="marketing_whatsapp_graph_version" class="control-label"><?= _l('marketing_whatsapp_graph_version'); ?></label>
                                            <input type="text" id="marketing_whatsapp_graph_version" name="marketing_whatsapp_graph_version" class="form-control" value="<?= html_escape($whatsappSettingValue('graph_version', 'v23.0')); ?>" placeholder="v23.0">
                                            <p class="text-muted mtop5"><?= _l('marketing_whatsapp_graph_version_note'); ?></p>
                                        </div>

                                        <div class="form-group">
                                            <label for="marketing_whatsapp_phone_number_id" class="control-label"><?= _l('marketing_whatsapp_phone_number_id'); ?></label>
                                            <input type="text" id="marketing_whatsapp_phone_number_id" name="marketing_whatsapp_phone_number_id" class="form-control" value="<?= html_escape($whatsappSettingValue('phone_number_id')); ?>">
                                        </div>

                                        <div class="form-group">
                                            <label for="marketing_whatsapp_business_account_id" class="control-label"><?= _l('marketing_whatsapp_business_account_id'); ?></label>
                                            <input type="text" id="marketing_whatsapp_business_account_id" name="marketing_whatsapp_business_account_id" class="form-control" value="<?= html_escape($whatsappSettingValue('business_account_id')); ?>">
                                        </div>

                                        <div class="form-group">
                                            <label for="marketing_whatsapp_access_token" class="control-label"><?= _l('marketing_whatsapp_access_token'); ?></label>
                                            <input type="password" id="marketing_whatsapp_access_token" name="marketing_whatsapp_access_token" class="form-control" value="" placeholder="<?= html_escape(_l('marketing_whatsapp_access_token_help')); ?>">
                                            <p class="text-muted mtop5">
                                                <?= $whatsapp_access_token_configured ? _l('marketing_whatsapp_access_token_configured') : _l('marketing_whatsapp_access_token_missing'); ?>
                                            </p>
                                        </div>

                                        <div class="form-group">
                                            <label for="marketing_whatsapp_webhook_verify_token" class="control-label"><?= _l('marketing_whatsapp_webhook_verify_token'); ?></label>
                                            <input type="text" id="marketing_whatsapp_webhook_verify_token" name="marketing_whatsapp_webhook_verify_token" class="form-control" value="<?= html_escape($whatsappSettingValue('webhook_verify_token')); ?>">
                                        </div>

                                        <div class="form-group">
                                            <label for="marketing_whatsapp_default_template_name" class="control-label"><?= _l('marketing_whatsapp_default_template_name'); ?></label>
                                            <input type="text" id="marketing_whatsapp_default_template_name" name="marketing_whatsapp_default_template_name" class="form-control" value="<?= html_escape($whatsappSettingValue('default_template_name')); ?>">
                                        </div>

                                        <div class="form-group">
                                            <label for="marketing_whatsapp_default_template_language" class="control-label"><?= _l('marketing_whatsapp_default_template_language'); ?></label>
                                            <input type="text" id="marketing_whatsapp_default_template_language" name="marketing_whatsapp_default_template_language" class="form-control" value="<?= html_escape($whatsappSettingValue('default_template_language', 'en_US')); ?>" placeholder="en_US">
                                            <p class="text-muted mtop5"><?= _l('marketing_whatsapp_default_template_language_note'); ?></p>
                                        </div>

                                        <div class="form-group">
                                            <label for="marketing_whatsapp_test_recipient" class="control-label"><?= _l('marketing_whatsapp_test_recipient'); ?></label>
                                            <input type="text" id="marketing_whatsapp_test_recipient" name="marketing_whatsapp_test_recipient" class="form-control" value="<?= html_escape($whatsappSettingValue('test_recipient')); ?>" placeholder="+46700000000">
                                            <p class="text-muted mtop5"><?= _l('marketing_whatsapp_test_recipient_note'); ?></p>
                                        </div>

                                        <div class="marketing-settings-form-actions">
                                            <button type="submit" class="btn btn-primary"><?= _l('save'); ?></button>
                                        </div>
                                    </div>
                                </div>
                                <?= form_close(); ?>
                                <!-- marketing-whatsapp-settings-form-end -->

                                <!-- marketing-whatsapp-test-form-start -->
                                <div class="panel_s marketing-settings-panel">
                                    <div class="panel-body">
                                        <h4 class="marketing-settings-panel-title"><?= _l('marketing_whatsapp_test_heading'); ?></h4>
                                        <p class="marketing-settings-panel-copy"><?= _l('marketing_whatsapp_test_note'); ?></p>
                                        <div class="alert alert-info">
                                            Send Test uses the currently saved WhatsApp settings. Save changes above before testing a new token, phone number ID, template, or recipient.
                                        </div>
                                        <?= form_open(admin_url('marketing/settings/save'), ['id' => 'marketing-whatsapp-test-form']); ?>
                                        <input type="hidden" name="settings_form" value="whatsapp_test">
                                        <div class="marketing-settings-form-actions">
                                            <button type="submit" class="btn btn-default">Send Test</button>
                                        </div>
                                        <?= form_close(); ?>
                                    </div>
                                </div>
                                <!-- marketing-whatsapp-test-form-end -->

                                <div class="panel_s marketing-settings-panel">
                                    <div class="panel-body">
                                        <h4 class="marketing-settings-panel-title"><?= _l('marketing_schema_health'); ?></h4>
                                        <p class="marketing-settings-panel-copy"><?= _l('marketing_schema_health_note'); ?></p>

                                        <?php if (!empty($schema_report['ok'])) { ?>
                                            <div class="alert alert-success">
                                                <?= _l('marketing_schema_health_ok'); ?>
                                            </div>
                                        <?php } else { ?>
                                            <div class="alert alert-warning">
                                                <?= _l('marketing_schema_health_warning'); ?>
                                            </div>

                                            <?php if (!empty($schema_report['missing_tables'])) { ?>
                                                <h5 class="bold"><?= _l('marketing_schema_missing_tables'); ?></h5>
                                                <ul class="mbot15">
                                                    <?php foreach ($schema_report['missing_tables'] as $item) { ?>
                                                        <li><code><?= html_escape($item['table']); ?></code> <span class="text-muted">(<?= html_escape($item['label']); ?>)</span></li>
                                                    <?php } ?>
                                                </ul>
                                            <?php } ?>

                                            <?php if (!empty($schema_report['missing_columns'])) { ?>
                                                <h5 class="bold"><?= _l('marketing_schema_missing_columns'); ?></h5>
                                                <ul class="mbot15">
                                                    <?php foreach ($schema_report['missing_columns'] as $item) { ?>
                                                        <li><code><?= html_escape($item['table'] . '.' . $item['column']); ?></code></li>
                                                    <?php } ?>
                                                </ul>
                                            <?php } ?>

                                            <?php if (!empty($schema_report['unsafe_missing_columns'])) { ?>
                                                <h5 class="bold"><?= _l('marketing_schema_manual_review'); ?></h5>
                                                <ul class="mbot15">
                                                    <?php foreach ($schema_report['unsafe_missing_columns'] as $item) { ?>
                                                        <li><code><?= html_escape($item['table'] . '.' . $item['column']); ?></code></li>
                                                    <?php } ?>
                                                </ul>
                                            <?php } ?>

                                            <?php if (!empty($schema_report['orphan_tables'])) { ?>
                                                <h5 class="bold"><?= _l('marketing_schema_orphan_tables'); ?></h5>
                                                <p class="text-muted"><?= _l('marketing_schema_orphan_tables_note'); ?></p>
                                                <ul class="mbot15">
                                                    <?php foreach ($schema_report['orphan_tables'] as $item) { ?>
                                                        <li>
                                                            <code><?= html_escape($item['table']); ?></code>
                                                            <?php if (!empty($item['canonical_table'])) { ?>
                                                                <span class="text-muted">(<?= html_escape($item['label']); ?>; <?= html_escape($item['canonical_table']); ?>)</span>
                                                            <?php } else { ?>
                                                                <span class="text-muted">(<?= html_escape($item['label']); ?>)</span>
                                                            <?php } ?>
                                                        </li>
                                                    <?php } ?>
                                                </ul>
                                            <?php } ?>

                                            <?= form_open(admin_url('marketing/settings/save')); ?>
                                            <input type="hidden" name="settings_form" value="schema_repair">
                                            <div class="marketing-settings-form-actions">
                                                <button type="submit" class="btn btn-warning"><?= _l('marketing_schema_repair_button'); ?></button>
                                            </div>
                                            <?= form_close(); ?>
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
</div>
<?php init_tail(); ?>
</body>
</html>
