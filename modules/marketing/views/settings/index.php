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

$provider_readiness = isset($provider_readiness) && is_array($provider_readiness) ? $provider_readiness : [];
$normalizedProviderReadiness = [];
foreach ($provider_readiness as $providerStatus) {
    if (!is_array($providerStatus)) {
        continue;
    }

    $providerChecks = isset($providerStatus['checks']) && is_array($providerStatus['checks']) ? $providerStatus['checks'] : [];
    $normalizedChecks = [];
    foreach ($providerChecks as $providerCheck) {
        if (!is_array($providerCheck)) {
            continue;
        }

        $normalizedChecks[] = array_merge([
            'label' => '',
            'value' => '',
        ], $providerCheck);
    }

    $normalizedProviderReadiness[] = array_merge([
        'title' => '',
        'status_key' => 'unavailable',
        'status_label' => '',
        'note' => '',
        'checks' => [],
    ], $providerStatus, [
        'checks' => $normalizedChecks,
    ]);
}
$provider_readiness = $normalizedProviderReadiness;

$release_readiness = isset($release_readiness) && is_array($release_readiness) ? $release_readiness : [];
$normalizedReleaseReadiness = [];
foreach ($release_readiness as $readinessItem) {
    if (!is_array($readinessItem)) {
        continue;
    }

    $normalizedReleaseReadiness[] = array_merge([
        'title' => '',
        'status_key' => 'unavailable',
        'status_label' => '',
        'note' => '',
    ], $readinessItem);
}
$release_readiness = $normalizedReleaseReadiness;

$whatsappEnabled = !empty($whatsapp_settings['enabled']);
$licenseStatusClass = 'alert-info';

if (($license_state['status'] ?? '') === 'active') {
    $licenseStatusClass = 'alert-success';
} elseif (($license_state['status'] ?? '') === 'grace') {
    $licenseStatusClass = 'alert-warning';
} elseif (in_array(($license_state['status'] ?? ''), ['inactive', 'error'], true)) {
    $licenseStatusClass = 'alert-danger';
}

$findReadinessItem = static function (array $items, $title) {
    foreach ($items as $item) {
        if ((string) ($item['title'] ?? '') === (string) $title) {
            return $item;
        }
    }

    return [
        'status_key' => 'unavailable',
        'status_label' => _l('marketing_release_readiness_status_unavailable'),
    ];
};

$summaryStatusClass = static function ($statusKey) {
    $statusKey = (string) $statusKey;

    if (in_array($statusKey, ['ready', 'active'], true)) {
        return 'marketing-settings-status-pill marketing-settings-status-pill-ready';
    }

    if (in_array($statusKey, ['needs_review', 'grace'], true)) {
        return 'marketing-settings-status-pill marketing-settings-status-pill-needs-review';
    }

    if ($statusKey === 'optional') {
        return 'marketing-settings-status-pill marketing-settings-status-pill-optional';
    }

    return 'marketing-settings-status-pill marketing-settings-status-pill-blocked';
};

$emailReadinessSummary = $findReadinessItem($provider_readiness, _l('marketing_provider_readiness_email_delivery'));
$sesReadinessSummary = $findReadinessItem($provider_readiness, _l('marketing_provider_readiness_ses_webhooks'));
$inboundReadinessSummary = $findReadinessItem($provider_readiness, _l('marketing_provider_readiness_inbound_email'));
$whatsappReadinessSummary = $findReadinessItem($provider_readiness, _l('marketing_provider_readiness_whatsapp'));

$licenseSummaryStatusKey = 'blocked';
$licenseSummaryStatusLabel = !empty($license_state['status_label']) ? (string) $license_state['status_label'] : _l('marketing_release_readiness_status_unavailable');

if (empty($license_available)) {
    $licenseSummaryStatusKey = 'unavailable';
    $licenseSummaryStatusLabel = _l('marketing_release_readiness_status_unavailable');
} else {
    $licenseRawStatus = (string) ($license_state['status'] ?? 'inactive');

    if ($licenseRawStatus === 'active') {
        $licenseSummaryStatusKey = 'ready';
    } elseif ($licenseRawStatus === 'grace') {
        $licenseSummaryStatusKey = 'needs_review';
    } elseif ($licenseRawStatus === 'optional') {
        $licenseSummaryStatusKey = 'optional';
    }
}

$settingsQuickStatus = [
    [
        'label' => _l('marketing_settings_summary_email_delivery'),
        'status_key' => (string) ($emailReadinessSummary['status_key'] ?? 'unavailable'),
        'status_label' => (string) ($emailReadinessSummary['status_label'] ?? _l('marketing_release_readiness_status_unavailable')),
        'anchor' => 'marketing-settings-section-email',
    ],
    [
        'label' => _l('marketing_settings_summary_ses'),
        'status_key' => (string) ($sesReadinessSummary['status_key'] ?? 'unavailable'),
        'status_label' => (string) ($sesReadinessSummary['status_label'] ?? _l('marketing_release_readiness_status_unavailable')),
        'anchor' => 'marketing-settings-section-email',
    ],
    [
        'label' => _l('marketing_settings_summary_inbound_reply'),
        'status_key' => (string) ($inboundReadinessSummary['status_key'] ?? 'unavailable'),
        'status_label' => (string) ($inboundReadinessSummary['status_label'] ?? _l('marketing_release_readiness_status_unavailable')),
        'anchor' => 'marketing-settings-section-reply',
    ],
    [
        'label' => _l('marketing_settings_summary_whatsapp'),
        'status_key' => (string) ($whatsappReadinessSummary['status_key'] ?? 'unavailable'),
        'status_label' => (string) ($whatsappReadinessSummary['status_label'] ?? _l('marketing_release_readiness_status_unavailable')),
        'anchor' => 'marketing-settings-section-whatsapp',
    ],
    [
        'label' => _l('marketing_settings_summary_license'),
        'status_key' => $licenseSummaryStatusKey,
        'status_label' => $licenseSummaryStatusLabel,
        'anchor' => 'marketing-settings-section-license',
    ],
];

$settingsSectionNav = [
    ['label' => _l('marketing_settings_nav_general'), 'anchor' => 'marketing-settings-section-general'],
    ['label' => _l('marketing_settings_nav_email'), 'anchor' => 'marketing-settings-section-email'],
    ['label' => _l('marketing_settings_nav_reply'), 'anchor' => 'marketing-settings-section-reply'],
    ['label' => _l('marketing_settings_nav_whatsapp'), 'anchor' => 'marketing-settings-section-whatsapp'],
    ['label' => _l('marketing_settings_nav_license'), 'anchor' => 'marketing-settings-section-license'],
    ['label' => _l('marketing_settings_nav_readiness'), 'anchor' => 'marketing-settings-section-readiness'],
    ['label' => _l('marketing_settings_nav_diagnostics'), 'anchor' => 'marketing-settings-section-diagnostics'],
];
?>
<?php init_head(); ?>
<style>
    .marketing-settings-shell {
        border: 1px solid #e2e8f0;
        background: linear-gradient(180deg, #f8fafc 0%, #ffffff 100%);
        border-radius: 18px;
        padding: 18px;
    }
    .marketing-settings-summary {
        border: 1px solid #dbe4f0;
        border-radius: 16px;
        background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
        padding: 16px;
        margin-bottom: 14px;
    }
    .marketing-settings-summary-head {
        display: flex;
        justify-content: space-between;
        gap: 14px;
        flex-wrap: wrap;
        align-items: flex-end;
        margin-bottom: 14px;
    }
    .marketing-settings-summary-title {
        margin: 0;
        font-size: 18px;
        font-weight: 700;
        color: #0f172a;
    }
    .marketing-settings-summary-copy {
        margin: 6px 0 0;
        color: #64748b;
        font-size: 13px;
        line-height: 1.5;
        max-width: 760px;
    }
    .marketing-settings-summary-grid {
        display: grid;
        grid-template-columns: repeat(5, minmax(0, 1fr));
        gap: 10px;
    }
    .marketing-settings-summary-nav {
        margin-top: 12px;
    }
    .marketing-settings-summary-card {
        display: block;
        text-decoration: none;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        padding: 12px 13px;
        background: #fff;
        transition: border-color .15s ease, box-shadow .15s ease, transform .15s ease;
    }
    .marketing-settings-summary-card:hover,
    .marketing-settings-summary-card:focus {
        text-decoration: none;
        border-color: #cbd5e1;
        box-shadow: 0 10px 24px rgba(15, 23, 42, .06);
        transform: translateY(-1px);
    }
    .marketing-settings-summary-label {
        display: block;
        font-size: 12px;
        font-weight: 700;
        letter-spacing: .02em;
        text-transform: uppercase;
        color: #64748b;
        margin-bottom: 8px;
    }
    .marketing-settings-status-pill {
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        padding: 5px 10px;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: .02em;
        text-transform: uppercase;
    }
    .marketing-settings-status-pill-ready {
        background: #dcfce7;
        color: #166534;
    }
    .marketing-settings-status-pill-needs-review {
        background: #fef3c7;
        color: #92400e;
    }
    .marketing-settings-status-pill-optional {
        background: #e2e8f0;
        color: #334155;
    }
    .marketing-settings-status-pill-blocked {
        background: #fee2e2;
        color: #991b1b;
    }
    .marketing-settings-section-nav {
        position: sticky;
        top: 10px;
        z-index: 5;
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-bottom: 14px;
        padding: 10px;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        background: rgba(255, 255, 255, .92);
        backdrop-filter: blur(12px);
    }
    .marketing-settings-section-link {
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        padding: 8px 12px;
        background: #f8fafc;
        color: #334155;
        font-size: 12px;
        font-weight: 700;
        text-decoration: none;
        border: 1px solid #e2e8f0;
        transition: background .15s ease, border-color .15s ease, color .15s ease;
    }
    .marketing-settings-section-link:hover,
    .marketing-settings-section-link:focus {
        text-decoration: none;
        background: #eff6ff;
        border-color: #bfdbfe;
        color: #1d4ed8;
    }
    .marketing-settings-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 16px;
        align-items: start;
    }
    .marketing-settings-wide-card {
        margin: 0 0 16px;
    }
    .marketing-settings-wide-card .panel-body {
        padding: 18px;
    }
    .marketing-settings-wide-grid {
        display: grid;
        gap: 14px;
    }
    .marketing-settings-wide-grid-4 {
        grid-template-columns: repeat(4, minmax(0, 1fr));
    }
    .marketing-settings-wide-section {
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        background: #f8fafc;
        padding: 14px;
    }
    .marketing-settings-wide-section-title {
        margin: 0 0 10px;
        font-size: 12px;
        font-weight: 700;
        letter-spacing: .02em;
        text-transform: uppercase;
        color: #64748b;
    }
    .marketing-settings-column {
        min-width: 0;
    }
    .marketing-settings-column > .panel_s + .panel_s {
        margin-top: 16px;
    }
    .marketing-settings-column > form + form,
    .marketing-settings-column > form + .panel_s,
    .marketing-settings-column > .panel_s + form {
        margin-top: 16px;
    }
    .marketing-settings-panel {
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 14px 28px rgba(15, 23, 42, .04);
    }
    .marketing-settings-panel .panel-body {
        padding: 18px;
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
    .marketing-settings-column .form-group {
        margin-bottom: 12px;
    }
    .marketing-settings-column .text-muted {
        font-size: 12px;
        line-height: 1.45;
    }
    .marketing-settings-column hr {
        margin: 16px 0;
    }
    .marketing-settings-column input[readonly] {
        cursor: text;
    }
    .marketing-settings-section-anchor {
        scroll-margin-top: 82px;
    }
    .marketing-settings-panel-title {
        margin: 0;
        font-size: 16px;
        font-weight: 700;
        color: #0f172a;
    }
    .marketing-settings-panel-copy {
        margin: 8px 0 0;
        color: #64748b;
        font-size: 13px;
        line-height: 1.55;
    }
    .marketing-settings-form-grid {
        display: grid;
        gap: 12px;
        align-items: start;
    }
    .marketing-settings-form-grid-2 {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .marketing-settings-form-grid-3 {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
    .marketing-settings-form-span-2 {
        grid-column: span 2;
    }
    .marketing-settings-details {
        margin-top: 12px;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        background: #f8fafc;
        overflow: hidden;
    }
    .marketing-settings-details summary {
        list-style: none;
        cursor: pointer;
        padding: 12px 14px;
        font-size: 13px;
        font-weight: 700;
        color: #0f172a;
    }
    .marketing-settings-details summary::-webkit-details-marker {
        display: none;
    }
    .marketing-settings-details-body {
        padding: 0 14px 14px;
    }
    .marketing-settings-mini-card {
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        background: #fff;
        padding: 14px;
        margin-top: 12px;
    }
    .marketing-settings-card-meta {
        margin-bottom: 10px;
    }
    .marketing-settings-form-actions {
        margin-top: 16px;
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
    .marketing-provider-readiness-list {
        display: grid;
        gap: 10px;
    }
    .marketing-provider-readiness-item {
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        padding: 12px 14px;
        background: #fff;
    }
    .marketing-provider-readiness-header {
        display: flex;
        justify-content: space-between;
        gap: 12px;
        align-items: flex-start;
        margin-bottom: 8px;
        flex-wrap: wrap;
    }
    .marketing-provider-readiness-title {
        margin: 0;
        font-size: 14px;
        font-weight: 700;
        color: #0f172a;
    }
    .marketing-provider-readiness-status {
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        padding: 5px 10px;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: .02em;
        text-transform: uppercase;
    }
    .marketing-provider-readiness-status-ready {
        background: #dcfce7;
        color: #166534;
    }
    .marketing-provider-readiness-status-needs_configuration,
    .marketing-provider-readiness-status-blocked {
        background: #fee2e2;
        color: #991b1b;
    }
    .marketing-provider-readiness-status-needs_review {
        background: #fef3c7;
        color: #92400e;
    }
    .marketing-provider-readiness-status-optional {
        background: #e2e8f0;
        color: #334155;
    }
    .marketing-provider-readiness-status-unavailable {
        background: #e5e7eb;
        color: #4b5563;
    }
    .marketing-provider-readiness-note {
        margin: 0 0 8px;
        color: #64748b;
        font-size: 13px;
        line-height: 1.55;
    }
    .marketing-provider-readiness-checks {
        list-style: none;
        margin: 0;
        padding: 0;
        display: grid;
        gap: 8px;
    }
    .marketing-provider-readiness-checks li {
        display: flex;
        justify-content: space-between;
        gap: 12px;
        align-items: center;
        font-size: 13px;
        color: #334155;
        flex-wrap: wrap;
    }
    .marketing-provider-readiness-check-value {
        color: #0f172a;
        font-weight: 600;
    }
    @media (max-width: 1199px) {
        .marketing-settings-summary-grid,
        .marketing-settings-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .marketing-settings-wide-grid-4 {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .marketing-settings-form-grid-3 {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }
    @media (max-width: 767px) {
        .marketing-settings-shell {
            padding: 14px;
        }
        .marketing-settings-summary-grid,
        .marketing-settings-grid,
        .marketing-settings-form-grid,
        .marketing-settings-form-grid-2,
        .marketing-settings-form-grid-3 {
            grid-template-columns: 1fr;
        }
        .marketing-settings-form-span-2 {
            grid-column: auto;
        }
        .marketing-settings-section-nav {
            position: static;
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
                        <div class="marketing-settings-shell" data-marketing-settings-shell="1">
                            <div class="marketing-settings-summary" data-marketing-settings-summary-grid="1">
                                <div class="marketing-settings-summary-head">
                                    <div>
                                        <h5 class="marketing-settings-summary-title"><?= _l('marketing_settings_summary_heading'); ?></h5>
                                        <p class="marketing-settings-summary-copy"><?= _l('marketing_settings_summary_note'); ?></p>
                                    </div>
                                </div>
                                <div class="marketing-settings-summary-grid">
                                    <?php foreach ($settingsQuickStatus as $summaryItem) { ?>
                                        <a href="#<?= html_escape($summaryItem['anchor']); ?>" class="marketing-settings-summary-card">
                                            <span class="marketing-settings-summary-label"><?= html_escape($summaryItem['label']); ?></span>
                                            <span class="<?= html_escape($summaryStatusClass($summaryItem['status_key'])); ?>">
                                                <?= html_escape($summaryItem['status_label']); ?>
                                            </span>
                                        </a>
                                    <?php } ?>
                                </div>
                                <div class="marketing-settings-section-nav marketing-settings-summary-nav" data-marketing-settings-nav="1">
                                    <?php foreach ($settingsSectionNav as $sectionNavItem) { ?>
                                        <a href="#<?= html_escape($sectionNavItem['anchor']); ?>" class="marketing-settings-section-link"><?= html_escape($sectionNavItem['label']); ?></a>
                                    <?php } ?>
                                </div>
                            </div>

                            <?= form_open(admin_url('marketing/settings/save'), ['id' => 'marketing-smtp-settings-form']); ?>
                            <input type="hidden" name="settings_form" value="smtp">
                            <div class="panel_s marketing-settings-panel marketing-settings-wide-card marketing-settings-section-anchor" id="marketing-settings-section-email" data-marketing-settings-email-wide="1">
                                <div class="panel-body">
                                    <h4 class="marketing-settings-panel-title"><?= _l('marketing_smtp_settings_section'); ?></h4>
                                    <p class="marketing-settings-panel-copy"><?= _l('marketing_smtp_settings_note'); ?></p>

                                    <div class="alert alert-info">
                                        <?= _l('marketing_smtp_settings_warning'); ?>
                                    </div>
                                    <div class="alert alert-info">
                                        <?= _l('marketing_email_delivery_ses_sns_note'); ?>
                                    </div>

                                    <div class="marketing-settings-wide-grid marketing-settings-wide-grid-4">
                                        <div class="marketing-settings-wide-section">
                                            <h5 class="marketing-settings-wide-section-title"><?= _l('marketing_settings_email_group_transport'); ?></h5>
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
                                            <div class="form-group">
                                                <label for="marketing_smtp_host" class="control-label"><?= _l('marketing_smtp_host'); ?></label>
                                                <input type="text" id="marketing_smtp_host" name="<?= html_escape($settingFieldName('marketing_smtp_host')); ?>" class="form-control" value="<?= html_escape($settingValue('marketing_smtp_host')); ?>">
                                            </div>
                                            <div class="marketing-settings-form-grid marketing-settings-form-grid-2">
                                                <div class="form-group">
                                                    <label for="marketing_smtp_port" class="control-label"><?= _l('marketing_smtp_port'); ?></label>
                                                    <input type="number" min="1" max="65535" id="marketing_smtp_port" name="<?= html_escape($settingFieldName('marketing_smtp_port')); ?>" class="form-control" value="<?= html_escape($settingValue('marketing_smtp_port')); ?>">
                                                </div>
                                                <div class="form-group">
                                                    <label for="marketing_smtp_encryption" class="control-label"><?= _l('marketing_smtp_encryption'); ?></label>
                                                    <select id="marketing_smtp_encryption" name="<?= html_escape($settingFieldName('marketing_smtp_encryption')); ?>" class="form-control">
                                                        <option value="none" <?= (string) $settingValue('marketing_smtp_encryption', 'none') === 'none' ? 'selected' : ''; ?>><?= _l('marketing_smtp_encryption_none'); ?></option>
                                                        <option value="tls" <?= (string) $settingValue('marketing_smtp_encryption', 'none') === 'tls' ? 'selected' : ''; ?>><?= _l('marketing_smtp_encryption_tls'); ?></option>
                                                        <option value="ssl" <?= (string) $settingValue('marketing_smtp_encryption', 'none') === 'ssl' ? 'selected' : ''; ?>><?= _l('marketing_smtp_encryption_ssl'); ?></option>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="marketing-settings-wide-section">
                                            <h5 class="marketing-settings-wide-section-title"><?= _l('marketing_settings_email_group_sender'); ?></h5>
                                            <div class="form-group">
                                                <label for="marketing_smtp_username" class="control-label"><?= _l('marketing_smtp_username'); ?></label>
                                                <input type="text" id="marketing_smtp_username" name="<?= html_escape($settingFieldName('marketing_smtp_username')); ?>" class="form-control" value="<?= html_escape($settingValue('marketing_smtp_username')); ?>">
                                                <p class="text-muted mtop5"><?= _l('marketing_smtp_username_help'); ?></p>
                                            </div>
                                            <div class="form-group">
                                                <label for="marketing_smtp_password" class="control-label"><?= _l('marketing_smtp_password'); ?></label>
                                                <input type="password" id="marketing_smtp_password" name="marketing_smtp_password" class="form-control" value="" placeholder="<?= html_escape(_l('marketing_smtp_password_help')); ?>">
                                                <p class="text-muted mtop5"><?= _l('marketing_smtp_password_visibility_note'); ?></p>
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
                                        </div>

                                        <div class="marketing-settings-wide-section">
                                            <h5 class="marketing-settings-wide-section-title"><?= _l('marketing_settings_email_group_ses'); ?></h5>
                                            <div class="form-group">
                                                <label for="marketing_ses_region" class="control-label"><?= _l('marketing_ses_region'); ?></label>
                                                <input type="text" id="marketing_ses_region" name="<?= html_escape($settingFieldName('marketing_ses_region')); ?>" class="form-control" value="<?= html_escape($settingValue('marketing_ses_region')); ?>">
                                                <p class="text-muted mtop5"><?= _l('marketing_ses_region_help'); ?></p>
                                            </div>
                                            <div class="form-group">
                                                <label for="marketing_ses_configuration_set" class="control-label"><?= _l('marketing_ses_configuration_set'); ?></label>
                                                <input type="text" id="marketing_ses_configuration_set" name="<?= html_escape($settingFieldName('marketing_ses_configuration_set')); ?>" class="form-control" value="<?= html_escape($settingValue('marketing_ses_configuration_set')); ?>">
                                                <p class="text-muted mtop5"><?= _l('marketing_ses_configuration_set_help'); ?></p>
                                            </div>
                                            <div class="form-group">
                                                <label for="marketing_ses_sns_topic_arn" class="control-label"><?= _l('marketing_ses_sns_topic_arn'); ?></label>
                                                <input type="text" id="marketing_ses_sns_topic_arn" name="<?= html_escape($settingFieldName('marketing_ses_sns_topic_arn')); ?>" class="form-control" value="<?= html_escape($settingValue('marketing_ses_sns_topic_arn')); ?>">
                                                <p class="text-muted mtop5"><?= _l('marketing_ses_sns_topic_arn_help'); ?></p>
                                            </div>
                                            <div class="form-group">
                                                <label class="control-label"><?= _l('marketing_ses_verify_signature'); ?></label>
                                                <div class="radio radio-primary radio-inline">
                                                    <input type="radio" id="marketing_smtp_ses_verify_signature_yes" name="<?= html_escape($settingFieldName('marketing_ses_verify_signature')); ?>" value="1" <?= $settingBool('marketing_ses_verify_signature') ? 'checked' : ''; ?>>
                                                    <label for="marketing_smtp_ses_verify_signature_yes"><?= _l('settings_yes'); ?></label>
                                                </div>
                                                <div class="radio radio-primary radio-inline">
                                                    <input type="radio" id="marketing_smtp_ses_verify_signature_no" name="<?= html_escape($settingFieldName('marketing_ses_verify_signature')); ?>" value="0" <?= !$settingBool('marketing_ses_verify_signature') ? 'checked' : ''; ?>>
                                                    <label for="marketing_smtp_ses_verify_signature_no"><?= _l('settings_no'); ?></label>
                                                </div>
                                                <p class="text-muted mtop5"><?= _l('marketing_ses_verify_signature_help'); ?></p>
                                            </div>
                                            <?php if (!$settingBool('marketing_ses_verify_signature')) { ?>
                                                <div class="alert alert-warning">
                                                    <?= _l('marketing_ses_signature_disabled_warning'); ?>
                                                </div>
                                            <?php } ?>
                                            <div class="form-group">
                                                <label for="marketing_ses_sns_signing_mode" class="control-label"><?= _l('marketing_ses_sns_signing_mode'); ?></label>
                                                <select id="marketing_ses_sns_signing_mode" name="<?= html_escape($settingFieldName('marketing_ses_sns_signing_mode')); ?>" class="form-control">
                                                    <option value="strict" <?= (string) $settingValue('marketing_ses_sns_signing_mode', 'strict') === 'strict' ? 'selected' : ''; ?>><?= _l('marketing_ses_sns_signing_mode_strict'); ?></option>
                                                    <option value="compatibility" <?= (string) $settingValue('marketing_ses_sns_signing_mode', 'strict') === 'compatibility' ? 'selected' : ''; ?>><?= _l('marketing_ses_sns_signing_mode_compatibility'); ?></option>
                                                </select>
                                                <p class="text-muted mtop5"><?= _l('marketing_ses_sns_signing_mode_help'); ?></p>
                                            </div>
                                        </div>

                                        <div class="marketing-settings-wide-section">
                                            <h5 class="marketing-settings-wide-section-title"><?= _l('marketing_settings_email_group_security'); ?></h5>
                                            <details class="marketing-settings-details" open>
                                                <summary><?= _l('marketing_settings_advanced_security_controls'); ?></summary>
                                                <div class="marketing-settings-details-body">
                                                    <div class="marketing-settings-form-grid marketing-settings-form-grid-2">
                                                        <div class="form-group">
                                                            <label for="marketing_ses_sns_replay_window_seconds" class="control-label"><?= _l('marketing_ses_sns_replay_window_seconds'); ?></label>
                                                            <input type="number" min="1" max="86400" id="marketing_ses_sns_replay_window_seconds" name="<?= html_escape($settingFieldName('marketing_ses_sns_replay_window_seconds')); ?>" class="form-control" value="<?= html_escape($settingValue('marketing_ses_sns_replay_window_seconds', '900')); ?>">
                                                            <p class="text-muted mtop5"><?= _l('marketing_ses_sns_replay_window_seconds_help'); ?></p>
                                                        </div>
                                                        <div class="form-group">
                                                            <label for="marketing_ses_sns_future_skew_seconds" class="control-label"><?= _l('marketing_ses_sns_future_skew_seconds'); ?></label>
                                                            <input type="number" min="0" max="3600" id="marketing_ses_sns_future_skew_seconds" name="<?= html_escape($settingFieldName('marketing_ses_sns_future_skew_seconds')); ?>" class="form-control" value="<?= html_escape($settingValue('marketing_ses_sns_future_skew_seconds', '300')); ?>">
                                                            <p class="text-muted mtop5"><?= _l('marketing_ses_sns_future_skew_seconds_help'); ?></p>
                                                        </div>
                                                    </div>
                                                </div>
                                            </details>
                                            <div class="form-group mtop10">
                                                <label class="control-label"><?= _l('marketing_ses_webhook_url'); ?></label>
                                                <input type="text" class="form-control" readonly value="<?= e(site_url('marketing/webhooks/ses_sns')); ?>">
                                                <p class="text-muted mtop5"><?= _l('marketing_webhook_url_runtime_note'); ?></p>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="marketing-settings-form-actions">
                                        <button type="submit" class="btn btn-primary"><?= _l('marketing_save_smtp_settings'); ?></button>
                                    </div>
                                </div>
                            </div>
                            <?= form_close(); ?>

                            <div class="marketing-settings-grid marketing-settings-layout-grid" data-marketing-settings-layout="3-col">
                            <div class="marketing-settings-column marketing-settings-column--core">
                                <?= form_open(admin_url('marketing/settings/save'), ['id' => 'marketing-general-settings-form']); ?>
                                <input type="hidden" name="settings_form" value="general">
                                <div class="panel_s marketing-settings-panel marketing-settings-section-anchor" id="marketing-settings-section-general">
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

                                        <details class="marketing-settings-details" open>
                                            <summary><?= _l('marketing_settings_advanced_delivery_controls'); ?></summary>
                                            <div class="marketing-settings-details-body">
                                                <div class="marketing-settings-form-grid marketing-settings-form-grid-2">
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
                                                    <div class="form-group">
                                                        <label for="marketing_send_start_hour" class="control-label"><?= _l('marketing_send_start_hour'); ?></label>
                                                        <input type="time" id="marketing_send_start_hour" name="<?= html_escape($settingFieldName('marketing_send_start_hour')); ?>" class="form-control" value="<?= html_escape($settingValue('marketing_send_start_hour', '00:00')); ?>">
                                                    </div>
                                                    <div class="form-group">
                                                        <label for="marketing_send_end_hour" class="control-label"><?= _l('marketing_send_end_hour'); ?></label>
                                                        <input type="time" id="marketing_send_end_hour" name="<?= html_escape($settingFieldName('marketing_send_end_hour')); ?>" class="form-control" value="<?= html_escape($settingValue('marketing_send_end_hour', '23:59')); ?>">
                                                    </div>
                                                </div>
                                            </div>
                                        </details>
                                        <p class="text-muted mtop5"><?= _l('marketing_send_window_help'); ?></p>
                                        <div class="marketing-settings-form-grid marketing-settings-form-grid-2">
                                            <div class="form-group">
                                                <label for="marketing_default_from_name" class="control-label"><?= _l('marketing_default_from_name'); ?></label>
                                                <input type="text" id="marketing_default_from_name" name="<?= html_escape($settingFieldName('marketing_default_from_name')); ?>" class="form-control" value="<?= html_escape($settingValue('marketing_default_from_name')); ?>">
                                            </div>
                                            <div class="form-group">
                                                <label for="marketing_default_from_email" class="control-label"><?= _l('marketing_default_from_email'); ?></label>
                                                <input type="email" id="marketing_default_from_email" name="<?= html_escape($settingFieldName('marketing_default_from_email')); ?>" class="form-control" value="<?= html_escape($settingValue('marketing_default_from_email')); ?>">
                                            </div>
                                            <div class="form-group marketing-settings-form-span-2">
                                                <label for="marketing_default_reply_to_email" class="control-label"><?= _l('marketing_default_reply_to_email'); ?></label>
                                                <input type="email" id="marketing_default_reply_to_email" name="<?= html_escape($settingFieldName('marketing_default_reply_to_email')); ?>" class="form-control" value="<?= html_escape($settingValue('marketing_default_reply_to_email')); ?>">
                                            </div>
                                        </div>
                                        <hr />
                                        <h5 class="bold no-margin"><?= _l('marketing_company_address_settings_heading'); ?></h5>
                                        <p class="text-muted mtop10"><?= _l('marketing_company_address_settings_note'); ?></p>
                                        <div class="marketing-settings-mini-card">
                                                <p class="marketing-settings-panel-copy"><?= _l('marketing_company_address_managed_in_perfex'); ?></p>
                                                <p class="mtop10"><a href="<?= admin_url('settings?group=company'); ?>" class="btn btn-default"><?= _l('marketing_company_address_manage_in_perfex'); ?></a></p>
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
                                <div class="panel_s marketing-settings-panel marketing-settings-section-anchor" id="marketing-settings-section-reply">
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

                                        <div class="marketing-settings-form-grid marketing-settings-form-grid-2">
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
                                        </div>

                                        <div class="form-group">
                                            <label for="marketing_inbound_email_secret" class="control-label"><?= _l('marketing_inbound_email_secret'); ?></label>
                                            <input type="password" id="marketing_inbound_email_secret" name="<?= html_escape($settingFieldName('marketing_inbound_email_secret')); ?>" class="form-control" value="" autocomplete="new-password">
                                            <p class="text-muted mtop5"><?= _l('marketing_inbound_email_secret_help'); ?></p>
                                            <p class="text-muted mtop5"><?= _l('marketing_inbound_email_secret_visibility_note'); ?></p>
                                            <div class="alert <?= $inbound_email_secret_configured ? 'alert-success' : 'alert-warning'; ?> mtop10 mbot0">
                                                <?= $inbound_email_secret_configured ? _l('marketing_inbound_email_secret_status_configured') : _l('marketing_inbound_email_secret_status_missing'); ?>
                                            </div>
                                        </div>

                                        <div class="marketing-settings-form-actions">
                                            <button type="submit" class="btn btn-primary"><?= _l('save'); ?></button>
                                        </div>
                                    </div>
                                </div>
                                <?= form_close(); ?>

                                <div class="panel_s marketing-settings-panel marketing-settings-section-anchor" id="marketing-settings-section-general-webhook">
                                    <div class="panel-body">
                                        <h4 class="marketing-settings-panel-title"><?= _l('marketing_inbound_reply_webhook_url'); ?></h4>
                                        <p class="marketing-settings-panel-copy"><?= _l('marketing_reply_to_lead_settings_note'); ?></p>
                                        <div class="form-group no-margin">
                                            <label class="control-label"><?= _l('marketing_inbound_reply_webhook_url'); ?></label>
                                            <input type="text" class="form-control" readonly value="<?= e(site_url('marketing/webhooks/inbound_email')); ?>">
                                            <p class="text-muted mtop5"><?= _l('marketing_webhook_url_runtime_note'); ?></p>
                                            <div class="alert <?= $inbound_email_secret_configured ? 'alert-success' : 'alert-warning'; ?> mtop10 mbot0">
                                                <?= $inbound_email_secret_configured ? _l('marketing_inbound_email_secret_runtime_configured') : _l('marketing_inbound_email_secret_runtime_missing'); ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                            </div>

                            <div class="marketing-settings-column marketing-settings-column--readiness">
                                <div class="panel_s marketing-settings-panel">
                                    <div class="panel-body">
                                        <h4 class="marketing-settings-panel-title"><?= _l('marketing_smtp_test_heading'); ?></h4>
                                        <p class="marketing-settings-panel-copy"><?= _l('marketing_smtp_test_note'); ?></p>
                                        <?= form_open(admin_url('marketing/settings/test_smtp'), ['id' => 'marketing-smtp-test-form']); ?>
                                        <div class="marketing-settings-form-grid">
                                            <?= render_input('test_email', 'marketing_smtp_test_email_address', '', 'email'); ?>
                                        </div>
                                        <div class="marketing-settings-form-actions">
                                            <button type="submit" class="btn btn-default"><?= _l('marketing_smtp_test_button'); ?></button>
                                        </div>
                                        <?= form_close(); ?>
                                    </div>
                                </div>

                                <div class="panel_s marketing-settings-panel marketing-settings-section-anchor" id="marketing-settings-section-readiness">
                                    <div class="panel-body">
                                        <h4 class="marketing-settings-panel-title"><?= _l('marketing_provider_readiness_heading'); ?></h4>
                                        <p class="marketing-settings-panel-copy"><?= _l('marketing_provider_readiness_note'); ?></p>

                                        <div class="marketing-provider-readiness-list">
                                            <?php foreach ($provider_readiness as $providerStatus) { ?>
                                                <div class="marketing-provider-readiness-item">
                                                    <div class="marketing-provider-readiness-header">
                                                        <h5 class="marketing-provider-readiness-title"><?= html_escape($providerStatus['title']); ?></h5>
                                                        <span class="marketing-provider-readiness-status marketing-provider-readiness-status-<?= html_escape((string) $providerStatus['status_key']); ?>">
                                                            <?= html_escape($providerStatus['status_label']); ?>
                                                        </span>
                                                    </div>
                                                    <p class="marketing-provider-readiness-note"><?= html_escape($providerStatus['note']); ?></p>
                                                    <?php if (!empty($providerStatus['checks']) && is_array($providerStatus['checks'])) { ?>
                                                        <ul class="marketing-provider-readiness-checks">
                                                            <?php foreach ($providerStatus['checks'] as $providerCheck) { ?>
                                                                <li>
                                                                    <span><?= html_escape($providerCheck['label']); ?></span>
                                                                    <span class="marketing-provider-readiness-check-value"><?= html_escape($providerCheck['value']); ?></span>
                                                                </li>
                                                            <?php } ?>
                                                        </ul>
                                                    <?php } ?>
                                                </div>
                                            <?php } ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="panel_s marketing-settings-panel">
                                    <div class="panel-body">
                                        <h4 class="marketing-settings-panel-title"><?= _l('marketing_release_readiness_heading'); ?></h4>
                                        <p class="marketing-settings-panel-copy"><?= _l('marketing_release_readiness_note'); ?></p>

                                        <div class="marketing-provider-readiness-list">
                                            <?php foreach ($release_readiness as $readinessItem) { ?>
                                                <div class="marketing-provider-readiness-item">
                                                    <div class="marketing-provider-readiness-header">
                                                        <h5 class="marketing-provider-readiness-title"><?= html_escape($readinessItem['title']); ?></h5>
                                                        <span class="marketing-provider-readiness-status marketing-provider-readiness-status-<?= html_escape((string) $readinessItem['status_key']); ?>">
                                                            <?= html_escape($readinessItem['status_label']); ?>
                                                        </span>
                                                    </div>
                                                    <p class="marketing-provider-readiness-note"><?= html_escape($readinessItem['note']); ?></p>
                                                </div>
                                            <?php } ?>
                                        </div>
                                    </div>
                                </div>

                            </div>

                            <div class="marketing-settings-column marketing-settings-column--advanced">
                                <div class="panel_s marketing-settings-panel marketing-settings-section-anchor" id="marketing-settings-section-license">
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

                                            <div class="marketing-settings-form-grid marketing-settings-form-grid-2">
                                                <div class="form-group">
                                                    <label for="marketing_license_status" class="control-label"><?= _l('marketing_license_status'); ?></label>
                                                    <input type="text" id="marketing_license_status" class="form-control" readonly value="<?= html_escape($license_state['status_label'] ?? 'Inactive'); ?>">
                                                </div>

                                                <div class="form-group">
                                                    <label for="marketing_license_last_checked" class="control-label"><?= _l('marketing_license_last_checked'); ?></label>
                                                    <input type="text" id="marketing_license_last_checked" class="form-control" readonly value="<?= html_escape($license_state['last_checked_display'] ?? 'Never'); ?>">
                                                </div>

                                                <div class="form-group">
                                                    <label for="marketing_license_domain" class="control-label"><?= _l('marketing_license_domain'); ?></label>
                                                    <input type="text" id="marketing_license_domain" class="form-control" readonly value="<?= html_escape($license_state['licensed_domain'] !== '' ? $license_state['licensed_domain'] : ''); ?>">
                                                    <p class="text-muted mtop5"><?= sprintf(_l('marketing_license_current_domain_note'), html_escape($license_state['current_domain'] ?? 'unknown-domain')); ?></p>
                                                </div>

                                                <div class="form-group">
                                                    <label for="marketing_license_module_version" class="control-label"><?= _l('marketing_license_module_version'); ?></label>
                                                    <input type="text" id="marketing_license_module_version" class="form-control" readonly value="<?= html_escape($license_state['module_version'] ?? '1.1.0'); ?>">
                                                </div>
                                            </div>

                                            <div class="alert alert-info">
                                                <?= sprintf(
                                                    _l('marketing_license_api_config_note'),
                                                    html_escape($license_state['api_base_url_hint'] ?? 'https://license.example.com/api')
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

                                <!-- marketing-whatsapp-settings-form-start -->
                                <!-- marketing-whatsapp-form-action: save -->
                                <?= form_open(admin_url('marketing/settings/save'), ['id' => 'marketing-whatsapp-settings-form']); ?>
                                <input type="hidden" name="settings_form" value="whatsapp">
                                <input type="hidden" name="whatsapp_form_probe" value="1">
                                <div class="panel_s marketing-settings-panel marketing-settings-section-anchor" id="marketing-settings-section-whatsapp">
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

                                        <div class="marketing-settings-form-grid marketing-settings-form-grid-2">
                                            <div class="form-group">
                                                <label for="marketing_whatsapp_graph_version" class="control-label"><?= _l('marketing_whatsapp_graph_version'); ?></label>
                                                <input type="text" id="marketing_whatsapp_graph_version" name="marketing_whatsapp_graph_version" class="form-control" value="<?= html_escape($whatsappSettingValue('graph_version', 'v23.0')); ?>" placeholder="v23.0">
                                                <p class="text-muted mtop5"><?= _l('marketing_whatsapp_graph_version_note'); ?></p>
                                            </div>

                                            <div class="form-group">
                                                <label for="marketing_whatsapp_phone_number_id" class="control-label"><?= _l('marketing_whatsapp_phone_number_id'); ?></label>
                                                <input type="text" id="marketing_whatsapp_phone_number_id" name="marketing_whatsapp_phone_number_id" class="form-control" value="<?= html_escape($whatsappSettingValue('phone_number_id')); ?>">
                                            </div>

                                            <div class="form-group marketing-settings-form-span-2">
                                                <label for="marketing_whatsapp_business_account_id" class="control-label"><?= _l('marketing_whatsapp_business_account_id'); ?></label>
                                                <input type="text" id="marketing_whatsapp_business_account_id" name="marketing_whatsapp_business_account_id" class="form-control" value="<?= html_escape($whatsappSettingValue('business_account_id')); ?>">
                                            </div>
                                        </div>

                                        <details class="marketing-settings-details">
                                            <summary><?= _l('marketing_settings_advanced_whatsapp_credentials'); ?></summary>
                                            <div class="marketing-settings-details-body">
                                                <div class="marketing-settings-form-grid marketing-settings-form-grid-2">
                                                    <div class="form-group">
                                                        <label for="marketing_whatsapp_access_token" class="control-label"><?= _l('marketing_whatsapp_access_token'); ?></label>
                                                        <input type="password" id="marketing_whatsapp_access_token" name="marketing_whatsapp_access_token" class="form-control" value="" placeholder="<?= html_escape(_l('marketing_whatsapp_access_token_help')); ?>">
                                                        <p class="text-muted mtop5">
                                                            <?= $whatsapp_access_token_configured ? _l('marketing_whatsapp_access_token_configured') : _l('marketing_whatsapp_access_token_missing'); ?>
                                                        </p>
                                                    </div>

                                                    <div class="form-group">
                                                        <label for="marketing_whatsapp_app_secret" class="control-label"><?= _l('marketing_whatsapp_app_secret'); ?></label>
                                                        <input type="password" id="marketing_whatsapp_app_secret" name="marketing_whatsapp_app_secret" class="form-control" value="" autocomplete="new-password" placeholder="<?= html_escape(_l('marketing_whatsapp_app_secret_help')); ?>">
                                                        <p class="text-muted mtop5"><?= _l('marketing_whatsapp_app_secret_visibility_note'); ?></p>
                                                        <div class="alert <?= $whatsapp_app_secret_configured ? 'alert-success' : 'alert-warning'; ?> mtop10 mbot0">
                                                            <?= $whatsapp_app_secret_configured ? _l('marketing_whatsapp_app_secret_status_configured') : _l('marketing_whatsapp_app_secret_status_missing'); ?>
                                                        </div>
                                                    </div>

                                                    <div class="form-group marketing-settings-form-span-2">
                                                        <label for="marketing_whatsapp_webhook_verify_token" class="control-label"><?= _l('marketing_whatsapp_webhook_verify_token'); ?></label>
                                                        <input type="text" id="marketing_whatsapp_webhook_verify_token" name="marketing_whatsapp_webhook_verify_token" class="form-control" value="<?= html_escape($whatsappSettingValue('webhook_verify_token')); ?>">
                                                        <p class="text-muted mtop5">
                                                            <?= $whatsapp_app_secret_configured ? _l('marketing_whatsapp_app_secret_runtime_configured') : _l('marketing_whatsapp_app_secret_runtime_missing'); ?>
                                                        </p>
                                                    </div>
                                                </div>
                                            </div>
                                        </details>

                                        <div class="marketing-settings-form-grid marketing-settings-form-grid-2">
                                            <div class="form-group">
                                                <label for="marketing_whatsapp_default_template_name" class="control-label"><?= _l('marketing_whatsapp_default_template_name'); ?></label>
                                                <input type="text" id="marketing_whatsapp_default_template_name" name="marketing_whatsapp_default_template_name" class="form-control" value="<?= html_escape($whatsappSettingValue('default_template_name')); ?>">
                                            </div>

                                            <div class="form-group">
                                                <label for="marketing_whatsapp_default_template_language" class="control-label"><?= _l('marketing_whatsapp_default_template_language'); ?></label>
                                                <input type="text" id="marketing_whatsapp_default_template_language" name="marketing_whatsapp_default_template_language" class="form-control" value="<?= html_escape($whatsappSettingValue('default_template_language', 'en_US')); ?>" placeholder="en_US">
                                                <p class="text-muted mtop5"><?= _l('marketing_whatsapp_default_template_language_note'); ?></p>
                                            </div>

                                            <div class="form-group marketing-settings-form-span-2">
                                                <label for="marketing_whatsapp_test_recipient" class="control-label"><?= _l('marketing_whatsapp_test_recipient'); ?></label>
                                                <input type="text" id="marketing_whatsapp_test_recipient" name="marketing_whatsapp_test_recipient" class="form-control" value="<?= html_escape($whatsappSettingValue('test_recipient')); ?>" placeholder="+46700000000">
                                                <p class="text-muted mtop5"><?= _l('marketing_whatsapp_test_recipient_note'); ?></p>
                                            </div>
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

                                <div class="panel_s marketing-settings-panel marketing-settings-section-anchor" id="marketing-settings-section-diagnostics">
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
