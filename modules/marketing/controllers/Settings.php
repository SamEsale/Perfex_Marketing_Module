<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Settings extends AdminController
{
    protected $marketingLicenseAvailable = false;

    public function __construct()
    {
        parent::__construct();
        $this->load->library('form_validation');

        if (staff_cant('settings', 'marketing')) {
            access_denied('marketing');
        }

        $this->load->model('marketing/Marketing_reply_leads_model', 'marketing_reply_leads_model');
        $this->load->model('marketing/Marketing_dashboard_model', 'marketing_dashboard_model');
        $this->load->library('marketing/Marketing_schema');
        $this->load->library('marketing/Marketing_reply_mailbox');
        $this->load->library('marketing/Marketing_whatsapp');
        $this->marketingLicenseAvailable = $this->load_marketing_license_library();
    }

    public function index()
    {
        $submittedGeneralSettings = $this->session->tempdata('marketing_settings_general_form_data');
        $submittedSmtpSettings = $this->session->tempdata('marketing_settings_smtp_form_data');
        $submittedReplyLeadSettings = $this->session->tempdata('marketing_settings_reply_lead_form_data');
        $submittedWhatsappSettings = $this->session->tempdata('marketing_settings_whatsapp_form_data');
        $submittedLicenseSettings = $this->session->tempdata('marketing_settings_license_form_data');

        $data['title']         = _l('marketing_settings');
        $data['settings']      = [
            'marketing_send_enabled'            => get_option('marketing_send_enabled'),
            'marketing_send_per_cron_run'       => get_option('marketing_send_per_cron_run'),
            'marketing_send_sleep_ms'           => get_option('marketing_send_sleep_ms'),
            'marketing_send_hourly_cap'         => get_option('marketing_send_hourly_cap'),
            'marketing_send_daily_cap'          => get_option('marketing_send_daily_cap'),
            'marketing_send_start_hour'         => get_option('marketing_send_start_hour'),
            'marketing_send_end_hour'           => get_option('marketing_send_end_hour'),
            'marketing_default_from_name'       => get_option('marketing_default_from_name'),
            'marketing_default_from_email'      => get_option('marketing_default_from_email'),
            'marketing_default_reply_to_email'  => get_option('marketing_default_reply_to_email'),
            'marketing_track_opens'             => get_option('marketing_track_opens'),
            'marketing_track_clicks'            => get_option('marketing_track_clicks'),
            'marketing_ses_verify_signature'    => get_option('marketing_ses_verify_signature'),
            'marketing_ses_sns_topic_arn'       => get_option('marketing_ses_sns_topic_arn'),
            'marketing_ses_sns_signing_mode'    => get_option('marketing_ses_sns_signing_mode'),
            'marketing_ses_sns_replay_window_seconds' => get_option('marketing_ses_sns_replay_window_seconds'),
            'marketing_ses_sns_future_skew_seconds' => get_option('marketing_ses_sns_future_skew_seconds'),
            'marketing_bounce_auto_suppress'    => get_option('marketing_bounce_auto_suppress'),
            'marketing_complaint_auto_suppress' => get_option('marketing_complaint_auto_suppress'),
            'marketing_smtp_enabled'            => get_option('marketing_smtp_enabled'),
            'marketing_smtp_host'               => get_option('marketing_smtp_host'),
            'marketing_smtp_port'               => get_option('marketing_smtp_port'),
            'marketing_smtp_encryption'         => get_option('marketing_smtp_encryption'),
            'marketing_smtp_username'           => get_option('marketing_smtp_username'),
            'marketing_smtp_from_email'         => get_option('marketing_smtp_from_email'),
            'marketing_smtp_from_name'          => get_option('marketing_smtp_from_name'),
            'marketing_smtp_reply_to'           => get_option('marketing_smtp_reply_to'),
            'marketing_ses_region'              => get_option('marketing_ses_region'),
            'marketing_ses_configuration_set'   => get_option('marketing_ses_configuration_set'),
            'marketing_reply_to_lead_enabled'   => get_option('marketing_reply_to_lead_enabled'),
            'marketing_reply_to_lead_unknown_only' => get_option('marketing_reply_to_lead_unknown_only'),
            'marketing_reply_to_lead_status'    => get_option('marketing_reply_to_lead_status'),
            'marketing_reply_to_lead_source'    => get_option('marketing_reply_to_lead_source'),
            'marketing_reply_to_lead_assigned_staff' => get_option('marketing_reply_to_lead_assigned_staff'),
            'marketing_reply_to_lead_name_prefix' => get_option('marketing_reply_to_lead_name_prefix'),
            'marketing_inbound_email_secret'   => get_option('marketing_inbound_email_secret'),
            'marketing_reply_mailbox_host'     => get_option('marketing_reply_mailbox_host'),
            'marketing_reply_mailbox_port'     => get_option('marketing_reply_mailbox_port'),
            'marketing_reply_mailbox_encryption' => get_option('marketing_reply_mailbox_encryption'),
            'marketing_reply_mailbox_username' => get_option('marketing_reply_mailbox_username'),
            'marketing_reply_mailbox_folder'   => get_option('marketing_reply_mailbox_folder'),
            'marketing_reply_mailbox_processed_folder' => get_option('marketing_reply_mailbox_processed_folder'),
            'marketing_whatsapp_enabled'        => get_option('marketing_whatsapp_enabled'),
            'marketing_whatsapp_graph_version'  => get_option('marketing_whatsapp_graph_version'),
            'marketing_whatsapp_phone_number_id' => get_option('marketing_whatsapp_phone_number_id'),
            'marketing_whatsapp_business_account_id' => get_option('marketing_whatsapp_business_account_id'),
            'marketing_whatsapp_app_secret'    => get_option('marketing_whatsapp_app_secret'),
            'marketing_whatsapp_webhook_verify_token' => get_option('marketing_whatsapp_webhook_verify_token'),
            'marketing_whatsapp_default_template_name' => get_option('marketing_whatsapp_default_template_name'),
            'marketing_whatsapp_default_template_language' => get_option('marketing_whatsapp_default_template_language'),
            'marketing_whatsapp_test_recipient' => get_option('marketing_whatsapp_test_recipient'),
        ];

        foreach ([$submittedGeneralSettings, $submittedSmtpSettings, $submittedReplyLeadSettings, $submittedWhatsappSettings] as $submittedSettings) {
            if (!is_array($submittedSettings) || empty($submittedSettings)) {
                continue;
            }

            foreach ($submittedSettings as $key => $value) {
                if (array_key_exists($key, $data['settings'])
                    && $key !== 'marketing_smtp_password_raw'
                    && $key !== 'marketing_reply_mailbox_password_raw'
                    && $key !== 'marketing_whatsapp_access_token_raw') {
                    $data['settings'][$key] = $value;
                }
            }
        }

        $data['lead_status_options'] = $this->marketing_reply_leads_model->get_status_options();
        $data['lead_source_options'] = $this->marketing_reply_leads_model->get_source_options();
        $data['lead_staff_options'] = $this->marketing_reply_leads_model->get_staff_options();
        $data['reply_lead_available'] = $this->marketing_reply_leads_model->leads_available();
        $data['reply_lead_dependencies_available'] = !empty($data['lead_status_options'])
            && !empty($data['lead_source_options'])
            && !empty($data['lead_staff_options']);
        $data['smtp_password_configured'] = trim((string) get_option('marketing_smtp_password')) !== '';
        $data['inbound_email_secret_configured'] = trim((string) get_option('marketing_inbound_email_secret')) !== '';
        $data['reply_mailbox_password_configured'] = trim((string) get_option('marketing_reply_mailbox_password')) !== '';
        $data['reply_mailbox_readiness'] = $this->marketing_reply_mailbox->get_readiness_summary();
        $data['whatsapp_settings'] = $this->marketing_whatsapp->get_settings();
        if (is_array($submittedWhatsappSettings) && !empty($submittedWhatsappSettings)) {
            $whatsappFieldMap = [
                'marketing_whatsapp_enabled' => 'raw_enabled',
                'marketing_whatsapp_graph_version' => 'graph_version',
                'marketing_whatsapp_phone_number_id' => 'phone_number_id',
                'marketing_whatsapp_business_account_id' => 'business_account_id',
                'marketing_whatsapp_webhook_verify_token' => 'webhook_verify_token',
                'marketing_whatsapp_default_template_name' => 'default_template_name',
                'marketing_whatsapp_default_template_language' => 'default_template_language',
                'marketing_whatsapp_test_recipient' => 'test_recipient',
            ];

            foreach ($whatsappFieldMap as $formKey => $settingsKey) {
                if (array_key_exists($formKey, $submittedWhatsappSettings)) {
                    $data['whatsapp_settings'][$settingsKey] = (string) $submittedWhatsappSettings[$formKey];
                }
            }

            $data['whatsapp_settings']['enabled'] = (string) ($data['whatsapp_settings']['raw_enabled'] ?? '0') === '1';
        }
        $data['whatsapp_access_token_configured'] = $data['whatsapp_settings']['access_token'] !== '';
        $data['whatsapp_app_secret_configured'] = trim((string) ($data['whatsapp_settings']['app_secret'] ?? '')) !== '';
        $data['provider_readiness'] = $this->build_provider_readiness_summary(
            $data['whatsapp_settings'],
            $data['inbound_email_secret_configured'],
            $data['whatsapp_access_token_configured'],
            $data['whatsapp_app_secret_configured']
        );
        $data['schema_report'] = $this->marketing_schema->get_readiness_report();
        $data['cron_readiness'] = $this->marketing_dashboard_model->get_cron_readiness_summary();
        $data['license_available'] = $this->marketingLicenseAvailable;
        $data['license_state'] = $this->marketingLicenseAvailable
            ? $this->marketing_license->get_license_state()
            : $this->get_fallback_license_state();
        $data['release_readiness'] = $this->build_release_readiness_summary(
            $data['license_state'],
            $data['provider_readiness'],
            $data['schema_report']
        );
        $data['license_form'] = [
            'marketing_license_key' => '',
            'marketing_license_email' => $data['license_state']['email'],
        ];

        if (is_array($submittedLicenseSettings) && !empty($submittedLicenseSettings)) {
            $data['license_form'] = array_merge($data['license_form'], [
                'marketing_license_key' => (string) ($submittedLicenseSettings['marketing_license_key'] ?? ''),
                'marketing_license_email' => (string) ($submittedLicenseSettings['marketing_license_email'] ?? $data['license_state']['email']),
            ]);
        }

        $this->load->view('marketing/settings/index', $data);
    }

    protected function build_provider_readiness_summary(
        array $whatsappSettings,
        $inboundEmailSecretConfigured,
        $whatsappAccessTokenConfigured,
        $whatsappAppSecretConfigured
    ) {
        $sendEnabled = (string) get_option('marketing_send_enabled') === '1';
        $smtpEnabled = (string) get_option('marketing_smtp_enabled') === '1';
        $smtpPasswordConfigured = trim((string) get_option('marketing_smtp_password')) !== '';
        $smtpReady = $smtpEnabled
            && trim((string) get_option('marketing_smtp_host')) !== ''
            && (int) get_option('marketing_smtp_port') > 0
            && trim((string) get_option('marketing_smtp_username')) !== ''
            && $smtpPasswordConfigured
            && trim((string) get_option('marketing_smtp_from_email')) !== '';

        $sesSignatureEnabled = (string) get_option('marketing_ses_verify_signature') === '1';
        $sesRegionConfigured = trim((string) get_option('marketing_ses_region')) !== '';
        $sesTopicConfigured = trim((string) get_option('marketing_ses_sns_topic_arn')) !== '';
        $sesConfigurationSetConfigured = trim((string) get_option('marketing_ses_configuration_set')) !== '';
        $sesSigningMode = trim((string) get_option('marketing_ses_sns_signing_mode'));
        $sesSigningModeConfigured = $sesSigningMode !== '';
        $sesSigningModeLabel = $sesSigningModeConfigured && in_array($sesSigningMode, ['strict', 'compatibility'], true)
            ? _l('marketing_ses_sns_signing_mode_' . $sesSigningMode)
            : _l('marketing_provider_readiness_check_missing');
        $whatsappEnabled = !empty($whatsappSettings['enabled']);
        $whatsappWebhookTokenConfigured = trim((string) ($whatsappSettings['webhook_verify_token'] ?? '')) !== '';
        $whatsappPhoneIdConfigured = trim((string) ($whatsappSettings['phone_number_id'] ?? '')) !== '';
        $whatsappBusinessIdConfigured = trim((string) ($whatsappSettings['business_account_id'] ?? '')) !== '';
        $whatsappGraphVersionConfigured = trim((string) ($whatsappSettings['graph_version'] ?? '')) !== '';

        return [
            [
                'title' => _l('marketing_provider_readiness_email_delivery'),
                'status_key' => $smtpReady ? 'ready' : ($sendEnabled ? 'needs_configuration' : 'optional'),
                'status_label' => $smtpReady
                    ? _l('marketing_provider_readiness_status_ready')
                    : ($sendEnabled ? _l('marketing_provider_readiness_status_needs_configuration') : _l('marketing_provider_readiness_status_optional')),
                'note' => $smtpReady
                    ? _l('marketing_provider_readiness_email_delivery_ready_note')
                    : ($sendEnabled ? _l('marketing_provider_readiness_email_delivery_config_note') : _l('marketing_provider_readiness_email_delivery_optional_note')),
                'checks' => [
                    [
                        'label' => _l('marketing_smtp_enabled'),
                        'value' => $smtpEnabled ? _l('marketing_provider_readiness_check_configured') : _l('marketing_provider_readiness_check_missing'),
                    ],
                    [
                        'label' => _l('marketing_smtp_password'),
                        'value' => $smtpPasswordConfigured ? _l('marketing_provider_readiness_check_configured') : _l('marketing_provider_readiness_check_missing'),
                    ],
                    [
                        'label' => _l('marketing_default_from_email'),
                        'value' => trim((string) get_option('marketing_default_from_email')) !== '' || trim((string) get_option('marketing_smtp_from_email')) !== ''
                            ? _l('marketing_provider_readiness_check_configured')
                            : _l('marketing_provider_readiness_check_missing'),
                    ],
                ],
            ],
            [
                'title' => _l('marketing_provider_readiness_ses_webhooks'),
                'status_key' => ($sesSignatureEnabled && $sesRegionConfigured && $sesTopicConfigured && $sesConfigurationSetConfigured && $sesSigningModeConfigured)
                    ? 'ready'
                    : ($sesSignatureEnabled ? 'needs_configuration' : 'optional'),
                'status_label' => ($sesSignatureEnabled && $sesRegionConfigured && $sesTopicConfigured && $sesConfigurationSetConfigured && $sesSigningModeConfigured)
                    ? _l('marketing_provider_readiness_status_ready')
                    : ($sesSignatureEnabled ? _l('marketing_provider_readiness_status_needs_configuration') : _l('marketing_provider_readiness_status_optional')),
                'note' => ($sesSignatureEnabled && $sesRegionConfigured && $sesTopicConfigured && $sesConfigurationSetConfigured && $sesSigningModeConfigured)
                    ? _l('marketing_provider_readiness_ses_ready_note')
                    : ($sesSignatureEnabled ? _l('marketing_provider_readiness_ses_config_note') : _l('marketing_provider_readiness_ses_optional_note')),
                'checks' => [
                    [
                        'label' => _l('marketing_ses_verify_signature'),
                        'value' => $sesSignatureEnabled
                            ? _l('marketing_provider_readiness_signature_enabled')
                            : _l('marketing_provider_readiness_signature_missing'),
                    ],
                    [
                        'label' => _l('marketing_ses_region'),
                        'value' => $sesRegionConfigured ? _l('marketing_provider_readiness_check_configured') : _l('marketing_provider_readiness_check_missing'),
                    ],
                    [
                        'label' => _l('marketing_ses_sns_topic_arn'),
                        'value' => $sesTopicConfigured ? _l('marketing_provider_readiness_check_configured') : _l('marketing_provider_readiness_check_missing'),
                    ],
                    [
                        'label' => _l('marketing_ses_configuration_set'),
                        'value' => $sesConfigurationSetConfigured ? _l('marketing_provider_readiness_check_configured') : _l('marketing_provider_readiness_check_missing'),
                    ],
                    [
                        'label' => _l('marketing_ses_sns_signing_mode'),
                        'value' => $sesSigningModeLabel,
                    ],
                ],
            ],
            [
                'title' => _l('marketing_provider_readiness_inbound_email'),
                'status_key' => $inboundEmailSecretConfigured ? 'ready' : 'needs_configuration',
                'status_label' => $inboundEmailSecretConfigured
                    ? _l('marketing_provider_readiness_status_ready')
                    : _l('marketing_provider_readiness_status_needs_configuration'),
                'note' => $inboundEmailSecretConfigured
                    ? _l('marketing_provider_readiness_inbound_ready_note')
                    : _l('marketing_provider_readiness_inbound_config_note'),
                'checks' => [
                    [
                        'label' => _l('marketing_inbound_email_secret'),
                        'value' => $inboundEmailSecretConfigured ? _l('marketing_provider_readiness_check_configured') : _l('marketing_provider_readiness_check_missing'),
                    ],
                ],
            ],
            [
                'title' => _l('marketing_provider_readiness_whatsapp'),
                'status_key' => ($whatsappEnabled && $whatsappAccessTokenConfigured && $whatsappPhoneIdConfigured && $whatsappBusinessIdConfigured && $whatsappGraphVersionConfigured && $whatsappWebhookTokenConfigured)
                    ? ($whatsappAppSecretConfigured ? 'ready' : 'blocked')
                    : ($whatsappEnabled ? 'needs_configuration' : 'optional'),
                'status_label' => ($whatsappEnabled && $whatsappAccessTokenConfigured && $whatsappPhoneIdConfigured && $whatsappBusinessIdConfigured && $whatsappGraphVersionConfigured && $whatsappWebhookTokenConfigured)
                    ? ($whatsappAppSecretConfigured ? _l('marketing_provider_readiness_status_ready') : _l('marketing_provider_readiness_status_blocked'))
                    : ($whatsappEnabled ? _l('marketing_provider_readiness_status_needs_configuration') : _l('marketing_provider_readiness_status_optional')),
                'note' => ($whatsappEnabled && $whatsappAccessTokenConfigured && $whatsappPhoneIdConfigured && $whatsappBusinessIdConfigured && $whatsappGraphVersionConfigured && $whatsappWebhookTokenConfigured)
                    ? ($whatsappAppSecretConfigured ? _l('marketing_provider_readiness_whatsapp_ready_note') : _l('marketing_provider_readiness_whatsapp_blocked_note'))
                    : ($whatsappEnabled ? _l('marketing_provider_readiness_whatsapp_config_note') : _l('marketing_provider_readiness_whatsapp_optional_note')),
                'checks' => [
                    [
                        'label' => _l('marketing_whatsapp_access_token'),
                        'value' => $whatsappAccessTokenConfigured ? _l('marketing_provider_readiness_check_configured') : _l('marketing_provider_readiness_check_missing'),
                    ],
                    [
                        'label' => _l('marketing_whatsapp_app_secret'),
                        'value' => $whatsappAppSecretConfigured
                            ? _l('marketing_provider_readiness_signature_enabled')
                            : _l('marketing_provider_readiness_signature_missing'),
                    ],
                    [
                        'label' => _l('marketing_whatsapp_webhook_verify_token'),
                        'value' => $whatsappWebhookTokenConfigured ? _l('marketing_provider_readiness_check_configured') : _l('marketing_provider_readiness_check_missing'),
                    ],
                ],
            ],
        ];
    }

    protected function build_release_readiness_summary(array $licenseState, array $providerReadiness, array $schemaReport)
    {
        $moduleRoot = dirname(__DIR__);
        $readmePath = $moduleRoot . '/README.md';
        $installationPath = $moduleRoot . '/INSTALLATION.md';
        $operationsPath = $moduleRoot . '/docs/OPERATIONS.md';
        $productionChecklistPath = $moduleRoot . '/docs/PRODUCTION_CHECKLIST.md';
        $releaseManifestDocPath = $moduleRoot . '/docs/RELEASE_MANIFEST.md';
        $releaseManifestJsonPath = $moduleRoot . '/release/manifest.json';
        $releaseBuilderPath = $moduleRoot . '/scripts/build_release_package.sh';
        $releaseValidatorPath = $moduleRoot . '/scripts/verify_release_package.sh';
        $integrityRuntimePath = $moduleRoot . '/scripts/verify_phase4_integrity_runtime.php';
        $moduleVersion = trim((string) ($licenseState['module_version'] ?? ''));
        $licenseStatus = trim((string) ($licenseState['status'] ?? 'inactive'));
        $licenseConfigured = !empty($licenseState['api_configured']);
        $docsAvailable = is_file($readmePath)
            && is_file($installationPath)
            && is_file($operationsPath)
            && is_file($productionChecklistPath)
            && is_file($releaseManifestDocPath);
        $providerReadinessAvailable = !empty($providerReadiness);
        $schemaHealthy = !empty($schemaReport['ok']);
        $releaseValidationReady = is_file($releaseValidatorPath)
            && is_file($releaseBuilderPath)
            && is_file($releaseManifestJsonPath);
        $operationsReady = is_file($operationsPath)
            && is_file($productionChecklistPath)
            && is_file($releaseManifestDocPath);

        return [
            [
                'title' => _l('marketing_release_readiness_module_metadata'),
                'status_key' => $moduleVersion !== '' ? 'ready' : 'unavailable',
                'status_label' => $moduleVersion !== ''
                    ? _l('marketing_release_readiness_status_ready')
                    : _l('marketing_release_readiness_status_unavailable'),
                'note' => $moduleVersion !== ''
                    ? sprintf(_l('marketing_release_readiness_module_metadata_note'), $moduleVersion)
                    : _l('marketing_release_readiness_module_metadata_missing_note'),
            ],
            [
                'title' => _l('marketing_release_readiness_license_support'),
                'status_key' => !$this->marketingLicenseAvailable
                    ? 'unavailable'
                    : (in_array($licenseStatus, ['active', 'grace'], true) && $licenseConfigured ? 'ready' : 'needs_review'),
                'status_label' => !$this->marketingLicenseAvailable
                    ? _l('marketing_release_readiness_status_unavailable')
                    : (in_array($licenseStatus, ['active', 'grace'], true) && $licenseConfigured ? _l('marketing_release_readiness_status_ready') : _l('marketing_release_readiness_status_needs_review')),
                'note' => !$this->marketingLicenseAvailable
                    ? _l('marketing_release_readiness_license_unavailable_note')
                    : sprintf(_l('marketing_release_readiness_license_note'), trim((string) ($licenseState['status_label'] ?? 'Inactive'))),
            ],
            [
                'title' => _l('marketing_release_readiness_docs'),
                'status_key' => $docsAvailable ? 'ready' : 'needs_review',
                'status_label' => $docsAvailable
                    ? _l('marketing_release_readiness_status_ready')
                    : _l('marketing_release_readiness_status_needs_review'),
                'note' => $docsAvailable
                    ? _l('marketing_release_readiness_docs_note')
                    : _l('marketing_release_readiness_docs_missing_note'),
            ],
            [
                'title' => _l('marketing_release_readiness_release_validation'),
                'status_key' => $releaseValidationReady ? 'ready' : 'unavailable',
                'status_label' => $releaseValidationReady
                    ? _l('marketing_release_readiness_status_ready')
                    : _l('marketing_release_readiness_status_unavailable'),
                'note' => $releaseValidationReady
                    ? _l('marketing_release_readiness_release_validation_note')
                    : _l('marketing_release_readiness_release_validation_missing_note'),
            ],
            [
                'title' => _l('marketing_release_readiness_provider_summary'),
                'status_key' => $providerReadinessAvailable ? 'ready' : 'unavailable',
                'status_label' => $providerReadinessAvailable
                    ? _l('marketing_release_readiness_status_ready')
                    : _l('marketing_release_readiness_status_unavailable'),
                'note' => $providerReadinessAvailable
                    ? _l('marketing_release_readiness_provider_summary_note')
                    : _l('marketing_release_readiness_provider_summary_missing_note'),
            ],
            [
                'title' => _l('marketing_release_readiness_integrity'),
                'status_key' => is_file($integrityRuntimePath) && $schemaHealthy ? 'ready' : (is_file($integrityRuntimePath) ? 'needs_review' : 'unavailable'),
                'status_label' => is_file($integrityRuntimePath) && $schemaHealthy
                    ? _l('marketing_release_readiness_status_ready')
                    : (is_file($integrityRuntimePath) ? _l('marketing_release_readiness_status_needs_review') : _l('marketing_release_readiness_status_unavailable')),
                'note' => is_file($integrityRuntimePath)
                    ? ($schemaHealthy ? _l('marketing_release_readiness_integrity_note') : _l('marketing_release_readiness_integrity_review_note'))
                    : _l('marketing_release_readiness_integrity_missing_note'),
            ],
            [
                'title' => _l('marketing_release_readiness_operations'),
                'status_key' => $operationsReady ? 'ready' : 'needs_review',
                'status_label' => $operationsReady
                    ? _l('marketing_release_readiness_status_ready')
                    : _l('marketing_release_readiness_status_needs_review'),
                'note' => $operationsReady
                    ? _l('marketing_release_readiness_operations_note')
                    : _l('marketing_release_readiness_operations_missing_note'),
            ],
        ];
    }

    public function save()
    {
        if (staff_cant('settings', 'marketing')) {
            access_denied('marketing');
        }

        if (!$this->input->post()) {
            show_404();
        }

        $settingsForm = (string) $this->input->post('settings_form', true);
        if ($settingsForm === 'whatsapp') {
            return $this->save_whatsapp_settings();
        }
        if ($settingsForm === '' && $this->is_whatsapp_settings_submission()) {
            set_alert('danger', _l('marketing_settings_save_failure'));
            redirect(admin_url('marketing/settings'));
        }

        if ($settingsForm === 'general') {
            return $this->save_general();
        } elseif ($settingsForm === 'smtp') {
            return $this->save_smtp();
        } elseif ($settingsForm === 'reply_leads' || $settingsForm === 'reply_lead') {
            return $this->save_reply_leads();
        } elseif ($settingsForm === 'license') {
            return $this->handle_license_settings();
        } elseif ($settingsForm === 'whatsapp_test') {
            return $this->handle_whatsapp_test();
        } elseif ($settingsForm === 'schema_repair') {
            return $this->repair_schema();
        }

        set_alert('danger', _l('marketing_settings_save_failure'));
        redirect(admin_url('marketing/settings'));
    }

    protected function is_whatsapp_settings_submission()
    {
        $whatsappKeys = [
            'marketing_whatsapp_enabled',
            'marketing_whatsapp_graph_version',
            'marketing_whatsapp_phone_number_id',
            'marketing_whatsapp_business_account_id',
            'marketing_whatsapp_access_token',
            'marketing_whatsapp_app_secret',
            'marketing_whatsapp_webhook_verify_token',
            'marketing_whatsapp_default_template_name',
            'marketing_whatsapp_default_template_language',
            'marketing_whatsapp_test_recipient',
        ];

        foreach ($whatsappKeys as $key) {
            $value = $this->input->post($key, false);
            if ($value !== null) {
                return true;
            }
        }

        $nested = $this->input->post('settings');

        return is_array($nested) && array_key_exists('marketing_whatsapp_enabled', $nested);
    }

    protected function get_settings_post_data()
    {
        $nested = $this->input->post('settings');
        if (!is_array($nested)) {
            $nested = [];
        }

        $getValue = function ($key, $xssClean = true) use ($nested) {
            if (array_key_exists($key, $nested)) {
                return is_string($nested[$key]) ? trim($nested[$key]) : $nested[$key];
            }

            $value = $this->input->post($key, $xssClean);

            return is_string($value) ? trim($value) : $value;
        };

        return [
            'marketing_send_enabled' => (string) ($getValue('marketing_send_enabled') === '1' ? '1' : '0'),
            'marketing_send_per_cron_run' => (string) $getValue('marketing_send_per_cron_run'),
            'marketing_send_sleep_ms' => (string) $getValue('marketing_send_sleep_ms'),
            'marketing_send_hourly_cap' => (string) $getValue('marketing_send_hourly_cap'),
            'marketing_send_daily_cap' => (string) $getValue('marketing_send_daily_cap'),
            'marketing_send_start_hour' => (string) $getValue('marketing_send_start_hour'),
            'marketing_send_end_hour' => (string) $getValue('marketing_send_end_hour'),
            'marketing_default_from_name' => (string) $getValue('marketing_default_from_name'),
            'marketing_default_from_email' => (string) $getValue('marketing_default_from_email'),
            'marketing_default_reply_to_email' => (string) $getValue('marketing_default_reply_to_email'),
            'marketing_track_opens' => (string) ($getValue('marketing_track_opens') === '1' ? '1' : '0'),
            'marketing_track_clicks' => (string) ($getValue('marketing_track_clicks') === '1' ? '1' : '0'),
            'marketing_ses_verify_signature' => (string) ($getValue('marketing_ses_verify_signature') === '1' ? '1' : '0'),
            'marketing_ses_sns_topic_arn' => (string) $getValue('marketing_ses_sns_topic_arn'),
            'marketing_ses_sns_signing_mode' => (string) $getValue('marketing_ses_sns_signing_mode'),
            'marketing_ses_sns_replay_window_seconds' => (string) $getValue('marketing_ses_sns_replay_window_seconds'),
            'marketing_ses_sns_future_skew_seconds' => (string) $getValue('marketing_ses_sns_future_skew_seconds'),
            'marketing_smtp_enabled' => (string) ($getValue('marketing_smtp_enabled') === '1' ? '1' : '0'),
            'marketing_smtp_host' => (string) $getValue('marketing_smtp_host'),
            'marketing_smtp_port' => (string) $getValue('marketing_smtp_port'),
            'marketing_smtp_encryption' => (string) $getValue('marketing_smtp_encryption'),
            'marketing_smtp_username' => (string) $getValue('marketing_smtp_username'),
            'marketing_smtp_from_email' => (string) $getValue('marketing_smtp_from_email'),
            'marketing_smtp_from_name' => (string) $getValue('marketing_smtp_from_name'),
            'marketing_smtp_reply_to' => (string) $getValue('marketing_smtp_reply_to'),
            'marketing_ses_region' => (string) $getValue('marketing_ses_region'),
            'marketing_ses_configuration_set' => (string) $getValue('marketing_ses_configuration_set'),
            'marketing_reply_to_lead_enabled' => (string) ($getValue('marketing_reply_to_lead_enabled') === '1' ? '1' : '0'),
            'marketing_reply_to_lead_unknown_only' => (string) ($getValue('marketing_reply_to_lead_unknown_only') === '1' ? '1' : '0'),
            'marketing_reply_to_lead_status' => (string) $getValue('marketing_reply_to_lead_status'),
            'marketing_reply_to_lead_source' => (string) $getValue('marketing_reply_to_lead_source'),
            'marketing_reply_to_lead_assigned_staff' => (string) $getValue('marketing_reply_to_lead_assigned_staff'),
            'marketing_reply_to_lead_name_prefix' => (string) $getValue('marketing_reply_to_lead_name_prefix'),
            'marketing_inbound_email_secret' => (string) $getValue('marketing_inbound_email_secret', false),
            'marketing_reply_mailbox_host' => (string) $getValue('marketing_reply_mailbox_host'),
            'marketing_reply_mailbox_port' => (string) $getValue('marketing_reply_mailbox_port'),
            'marketing_reply_mailbox_encryption' => (string) $getValue('marketing_reply_mailbox_encryption'),
            'marketing_reply_mailbox_username' => (string) $getValue('marketing_reply_mailbox_username'),
            'marketing_reply_mailbox_folder' => (string) $getValue('marketing_reply_mailbox_folder'),
            'marketing_reply_mailbox_processed_folder' => (string) $getValue('marketing_reply_mailbox_processed_folder'),
            'marketing_reply_mailbox_password_raw' => trim((string) $this->input->post('marketing_reply_mailbox_password', false)),
            'marketing_smtp_password_raw' => trim((string) $this->input->post('marketing_smtp_password', false)),
        ];
    }

    protected function get_general_settings_post_data()
    {
        $posted = $this->get_settings_post_data();

        return [
            'marketing_send_enabled' => $posted['marketing_send_enabled'],
            'marketing_send_per_cron_run' => $posted['marketing_send_per_cron_run'],
            'marketing_send_sleep_ms' => $posted['marketing_send_sleep_ms'],
            'marketing_send_hourly_cap' => $posted['marketing_send_hourly_cap'],
            'marketing_send_daily_cap' => $posted['marketing_send_daily_cap'],
            'marketing_send_start_hour' => $posted['marketing_send_start_hour'],
            'marketing_send_end_hour' => $posted['marketing_send_end_hour'],
            'marketing_default_from_name' => $posted['marketing_default_from_name'],
            'marketing_default_from_email' => $posted['marketing_default_from_email'],
            'marketing_default_reply_to_email' => $posted['marketing_default_reply_to_email'],
            'marketing_track_opens' => $posted['marketing_track_opens'],
            'marketing_track_clicks' => $posted['marketing_track_clicks'],
        ];
    }

    protected function get_smtp_settings_post_data()
    {
        $posted = $this->get_settings_post_data();

        return [
            'marketing_smtp_enabled' => $posted['marketing_smtp_enabled'],
            'marketing_smtp_host' => $posted['marketing_smtp_host'],
            'marketing_smtp_port' => $posted['marketing_smtp_port'],
            'marketing_smtp_encryption' => $posted['marketing_smtp_encryption'],
            'marketing_smtp_username' => $posted['marketing_smtp_username'],
            'marketing_smtp_password_raw' => $posted['marketing_smtp_password_raw'],
            'marketing_smtp_from_email' => $posted['marketing_smtp_from_email'],
            'marketing_smtp_from_name' => $posted['marketing_smtp_from_name'],
            'marketing_smtp_reply_to' => $posted['marketing_smtp_reply_to'],
            'marketing_ses_region' => $posted['marketing_ses_region'],
            'marketing_ses_configuration_set' => $posted['marketing_ses_configuration_set'],
            'marketing_ses_verify_signature' => $posted['marketing_ses_verify_signature'],
            'marketing_ses_sns_topic_arn' => $posted['marketing_ses_sns_topic_arn'],
            'marketing_ses_sns_signing_mode' => $posted['marketing_ses_sns_signing_mode'],
            'marketing_ses_sns_replay_window_seconds' => $posted['marketing_ses_sns_replay_window_seconds'],
            'marketing_ses_sns_future_skew_seconds' => $posted['marketing_ses_sns_future_skew_seconds'],
        ];
    }

    protected function save_general()
    {
        $posted = $this->get_general_settings_post_data();
        $this->form_validation->set_data($posted);

        $this->form_validation->set_rules('marketing_send_per_cron_run', _l('marketing_send_per_cron_run'), 'required|integer|greater_than_equal_to[1]');
        $this->form_validation->set_rules('marketing_send_sleep_ms', _l('marketing_send_sleep_ms'), 'required|integer|greater_than_equal_to[0]');
        $this->form_validation->set_rules('marketing_send_hourly_cap', _l('marketing_send_hourly_cap'), 'required|integer|greater_than_equal_to[0]');
        $this->form_validation->set_rules('marketing_send_daily_cap', _l('marketing_send_daily_cap'), 'required|integer|greater_than_equal_to[0]');
        $this->form_validation->set_rules('marketing_send_start_hour', _l('marketing_send_start_hour'), 'required|regex_match[/^(?:[01]\d|2[0-3]):[0-5]\d$/]');
        $this->form_validation->set_rules('marketing_send_end_hour', _l('marketing_send_end_hour'), 'required|regex_match[/^(?:[01]\d|2[0-3]):[0-5]\d$/]');
        $this->form_validation->set_rules('marketing_default_from_name', _l('marketing_default_from_name'), 'trim');
        $this->form_validation->set_rules('marketing_default_from_email', _l('marketing_default_from_email'), 'trim|valid_email');
        $this->form_validation->set_rules('marketing_default_reply_to_email', _l('marketing_default_reply_to_email'), 'trim|valid_email');

        if ($this->form_validation->run() === false) {
            $this->session->set_tempdata('marketing_settings_general_form_data', $posted, 60);
            set_alert('danger', validation_errors('', ''));
            redirect(admin_url('marketing/settings'));
        }

        if ($posted['marketing_send_start_hour'] >= $posted['marketing_send_end_hour']) {
            $this->session->set_tempdata('marketing_settings_general_form_data', $posted, 60);
            set_alert('danger', _l('marketing_send_window_invalid'));
            redirect(admin_url('marketing/settings'));
        }

        foreach ([$posted['marketing_send_enabled'], $posted['marketing_track_opens'], $posted['marketing_track_clicks']] as $boolValue) {
            if (!in_array($boolValue, ['0', '1'], true)) {
                set_alert('danger', _l('marketing_settings_save_failure'));
                redirect(admin_url('marketing/settings'));
            }
        }

        $settings = [
            'marketing_send_enabled'           => $posted['marketing_send_enabled'],
            'marketing_send_per_cron_run'      => (int) $posted['marketing_send_per_cron_run'],
            'marketing_send_sleep_ms'          => (int) $posted['marketing_send_sleep_ms'],
            'marketing_send_hourly_cap'        => (int) $posted['marketing_send_hourly_cap'],
            'marketing_send_daily_cap'         => (int) $posted['marketing_send_daily_cap'],
            'marketing_send_start_hour'        => $posted['marketing_send_start_hour'],
            'marketing_send_end_hour'          => $posted['marketing_send_end_hour'],
            'marketing_default_from_name'      => $posted['marketing_default_from_name'],
            'marketing_default_from_email'     => $posted['marketing_default_from_email'],
            'marketing_default_reply_to_email' => $posted['marketing_default_reply_to_email'],
            'marketing_track_opens'            => $posted['marketing_track_opens'],
            'marketing_track_clicks'           => $posted['marketing_track_clicks'],
        ];

        $this->persist_settings($settings);
        log_message('info', 'Marketing general settings updated: ' . implode(', ', array_keys($settings)));
        set_alert('success', _l('marketing_general_settings_saved'));
        redirect(admin_url('marketing/settings'));
    }

    protected function save_smtp()
    {
        $posted = $this->get_smtp_settings_post_data();
        $marketingSmtpEnabled = $posted['marketing_smtp_enabled'] === '1' ? '1' : '0';
        $posted['marketing_ses_sns_signing_mode'] = $this->normalize_ses_sns_signing_mode($posted['marketing_ses_sns_signing_mode']);
        $posted['marketing_ses_sns_replay_window_seconds'] = (string) $this->normalize_integer_setting($posted['marketing_ses_sns_replay_window_seconds'], 900, 1, 86400);
        $posted['marketing_ses_sns_future_skew_seconds'] = (string) $this->normalize_integer_setting($posted['marketing_ses_sns_future_skew_seconds'], 300, 0, 3600);
        $this->form_validation->set_data($posted);
        $this->form_validation->set_rules('marketing_smtp_enabled', _l('marketing_smtp_enabled'), 'in_list[0,1]');
        $this->form_validation->set_rules('marketing_ses_verify_signature', _l('marketing_ses_verify_signature'), 'in_list[0,1]');
        $this->form_validation->set_rules('marketing_ses_region', _l('marketing_ses_region'), 'trim|regex_match[/^[A-Za-z0-9-]*$/]');
        $this->form_validation->set_rules('marketing_ses_configuration_set', _l('marketing_ses_configuration_set'), 'trim|max_length[64]|regex_match[/^[A-Za-z0-9._-]*$/]');
        $this->form_validation->set_rules('marketing_ses_sns_topic_arn', _l('marketing_ses_sns_topic_arn'), 'trim|max_length[512]|regex_match[/^(|arn:aws[a-z-]*:sns:[a-z0-9-]+:[0-9]{12}:[A-Za-z0-9_-]+(?:\.fifo)?)$/]');
        $this->form_validation->set_rules('marketing_ses_sns_signing_mode', _l('marketing_ses_sns_signing_mode'), 'required|in_list[strict,compatibility]');
        $this->form_validation->set_rules('marketing_ses_sns_replay_window_seconds', _l('marketing_ses_sns_replay_window_seconds'), 'required|integer|greater_than_equal_to[1]|less_than_equal_to[86400]');
        $this->form_validation->set_rules('marketing_ses_sns_future_skew_seconds', _l('marketing_ses_sns_future_skew_seconds'), 'required|integer|greater_than_equal_to[0]|less_than_equal_to[3600]');

        $existingStoredSmtpPassword = trim((string) get_option('marketing_smtp_password'));
        $smtpPasswordRule = $marketingSmtpEnabled === '1' && $existingStoredSmtpPassword === '' ? 'trim|required' : 'trim';

        if ($marketingSmtpEnabled === '1') {
            $this->form_validation->set_rules('marketing_smtp_host', _l('marketing_smtp_host'), 'trim|required');
            $this->form_validation->set_rules('marketing_smtp_port', _l('marketing_smtp_port'), 'trim|required|integer|greater_than_equal_to[1]|less_than_equal_to[65535]');
            $this->form_validation->set_rules('marketing_smtp_username', _l('marketing_smtp_username'), 'trim|required');
            $this->form_validation->set_rules('marketing_smtp_password_raw', _l('marketing_smtp_password'), $smtpPasswordRule);
            $this->form_validation->set_rules('marketing_smtp_from_email', _l('marketing_smtp_from_email'), 'trim|required|valid_email');
            $this->form_validation->set_rules('marketing_smtp_from_name', _l('marketing_smtp_from_name'), 'trim');
            $this->form_validation->set_rules('marketing_smtp_reply_to', _l('marketing_smtp_reply_to'), $posted['marketing_smtp_reply_to'] !== '' ? 'trim|valid_email' : 'trim');
            $this->form_validation->set_rules('marketing_smtp_encryption', _l('marketing_smtp_encryption'), 'in_list[none,ssl,tls]');
        }

        if ($this->form_validation->run() === false) {
            $this->session->set_tempdata('marketing_settings_smtp_form_data', $posted, 60);
            set_alert('danger', _l('marketing_smtp_settings_validation_failed') . ' ' . validation_errors('', ''));
            redirect(admin_url('marketing/settings'));
        }

        if (!in_array($marketingSmtpEnabled, ['0', '1'], true)) {
            set_alert('danger', _l('marketing_smtp_settings_validation_failed'));
            redirect(admin_url('marketing/settings'));
        }

        $settings = [
            'marketing_smtp_enabled'          => $marketingSmtpEnabled,
            'marketing_smtp_host'             => $posted['marketing_smtp_host'],
            'marketing_smtp_port'             => $posted['marketing_smtp_port'],
            'marketing_smtp_encryption'       => $posted['marketing_smtp_encryption'],
            'marketing_smtp_username'         => $posted['marketing_smtp_username'],
            'marketing_smtp_from_email'       => $posted['marketing_smtp_from_email'],
            'marketing_smtp_from_name'        => $posted['marketing_smtp_from_name'],
            'marketing_smtp_reply_to'         => $posted['marketing_smtp_reply_to'],
            'marketing_ses_region'            => $posted['marketing_ses_region'],
            'marketing_ses_configuration_set' => $posted['marketing_ses_configuration_set'],
            'marketing_ses_verify_signature'  => $posted['marketing_ses_verify_signature'],
            'marketing_ses_sns_topic_arn'     => $posted['marketing_ses_sns_topic_arn'],
            'marketing_ses_sns_signing_mode'  => $posted['marketing_ses_sns_signing_mode'],
            'marketing_ses_sns_replay_window_seconds' => (int) $posted['marketing_ses_sns_replay_window_seconds'],
            'marketing_ses_sns_future_skew_seconds' => (int) $posted['marketing_ses_sns_future_skew_seconds'],
        ];

        $smtpPassword = $posted['marketing_smtp_password_raw'];
        if ($smtpPassword !== '') {
            $this->load->library('encryption');
            $settings['marketing_smtp_password'] = $this->encryption->encrypt($smtpPassword);
        } elseif (total_rows(db_prefix() . 'options', ['name' => 'marketing_smtp_password']) === 0) {
            $settings['marketing_smtp_password'] = '';
        }

        $this->persist_settings($settings);
        log_message('info', 'Marketing SMTP settings updated: ' . implode(', ', array_keys($settings)));
        set_alert('success', _l('marketing_smtp_settings_saved'));
        redirect(admin_url('marketing/settings'));
    }

    protected function normalize_ses_sns_signing_mode($value)
    {
        $value = strtolower(trim((string) $value));

        return in_array($value, ['strict', 'compatibility'], true) ? $value : 'strict';
    }

    protected function normalize_integer_setting($value, $default, $minimum, $maximum)
    {
        $normalized = trim((string) $value);
        if ($normalized === '' || preg_match('/^-?\d+$/', $normalized) !== 1) {
            return $default;
        }

        $normalized = (int) $normalized;
        if ($normalized < $minimum) {
            return $minimum;
        }

        if ($normalized > $maximum) {
            return $maximum;
        }

        return $normalized;
    }

    protected function save_reply_leads()
    {
        $posted = $this->get_settings_post_data();
        $this->form_validation->set_data($posted);

        $this->form_validation->set_rules('marketing_reply_to_lead_enabled', _l('marketing_reply_to_lead_enabled'), 'in_list[0,1]');
        $this->form_validation->set_rules('marketing_reply_to_lead_unknown_only', _l('marketing_reply_to_lead_unknown_only'), 'in_list[0,1]');
        $this->form_validation->set_rules('marketing_reply_to_lead_status', _l('marketing_reply_to_lead_status'), 'trim|integer|greater_than_equal_to[0]');
        $this->form_validation->set_rules('marketing_reply_to_lead_source', _l('marketing_reply_to_lead_source'), 'trim|integer|greater_than_equal_to[0]');
        $this->form_validation->set_rules('marketing_reply_to_lead_assigned_staff', _l('marketing_reply_to_lead_assigned_staff'), 'trim|integer|greater_than_equal_to[0]');
        $this->form_validation->set_rules('marketing_reply_to_lead_name_prefix', _l('marketing_reply_to_lead_name_prefix'), 'trim|max_length[100]');
        $this->form_validation->set_rules('marketing_inbound_email_secret', _l('marketing_inbound_email_secret'), 'trim|max_length[255]');
        $this->form_validation->set_rules('marketing_reply_mailbox_host', _l('marketing_reply_mailbox_host'), 'trim|max_length[255]');
        $this->form_validation->set_rules('marketing_reply_mailbox_port', _l('marketing_reply_mailbox_port'), 'trim|integer|greater_than_equal_to[1]|less_than_equal_to[65535]');
        $this->form_validation->set_rules('marketing_reply_mailbox_encryption', _l('marketing_reply_mailbox_encryption'), 'trim|in_list[ssl,tls,none]');
        $this->form_validation->set_rules('marketing_reply_mailbox_username', _l('marketing_reply_mailbox_username'), 'trim|max_length[255]');
        $this->form_validation->set_rules('marketing_reply_mailbox_folder', _l('marketing_reply_mailbox_folder'), 'trim|max_length[191]');
        $this->form_validation->set_rules('marketing_reply_mailbox_processed_folder', _l('marketing_reply_mailbox_processed_folder'), 'trim|max_length[191]');

        if ($this->form_validation->run() === false) {
            $this->session->set_tempdata('marketing_settings_reply_lead_form_data', $posted, 60);
            set_alert('danger', validation_errors('', ''));
            redirect(admin_url('marketing/settings'));
        }

        $postedSecret = trim((string) ($posted['marketing_inbound_email_secret'] ?? ''));
        $existingSecret = trim((string) get_option('marketing_inbound_email_secret'));
        $settings = [
            'marketing_reply_to_lead_enabled' => $posted['marketing_reply_to_lead_enabled'],
            'marketing_reply_to_lead_unknown_only' => $posted['marketing_reply_to_lead_unknown_only'],
            'marketing_reply_to_lead_status' => (int) $posted['marketing_reply_to_lead_status'],
            'marketing_reply_to_lead_source' => (int) $posted['marketing_reply_to_lead_source'],
            'marketing_reply_to_lead_assigned_staff' => (int) $posted['marketing_reply_to_lead_assigned_staff'],
            'marketing_reply_to_lead_name_prefix' => $posted['marketing_reply_to_lead_name_prefix'] !== '' ? $posted['marketing_reply_to_lead_name_prefix'] : 'Marketing Reply',
            'marketing_inbound_email_secret' => $postedSecret !== '' ? $postedSecret : $existingSecret,
            'marketing_reply_mailbox_host' => trim((string) $posted['marketing_reply_mailbox_host']),
            'marketing_reply_mailbox_port' => trim((string) $posted['marketing_reply_mailbox_port']),
            'marketing_reply_mailbox_encryption' => trim((string) $posted['marketing_reply_mailbox_encryption']) !== '' ? trim((string) $posted['marketing_reply_mailbox_encryption']) : 'ssl',
            'marketing_reply_mailbox_username' => trim((string) $posted['marketing_reply_mailbox_username']),
            'marketing_reply_mailbox_folder' => trim((string) $posted['marketing_reply_mailbox_folder']) !== '' ? trim((string) $posted['marketing_reply_mailbox_folder']) : 'INBOX',
            'marketing_reply_mailbox_processed_folder' => trim((string) $posted['marketing_reply_mailbox_processed_folder']),
        ];

        $mailboxPassword = trim((string) ($posted['marketing_reply_mailbox_password_raw'] ?? ''));
        if ($mailboxPassword !== '') {
            $this->load->library('encryption');
            $settings['marketing_reply_mailbox_password'] = $this->encryption->encrypt($mailboxPassword);
        } elseif (total_rows(db_prefix() . 'options', ['name' => 'marketing_reply_mailbox_password']) === 0) {
            $settings['marketing_reply_mailbox_password'] = '';
        }

        $this->persist_settings($settings);
        log_message('info', 'Marketing reply-to-lead settings updated: ' . implode(', ', array_keys($settings)));
        set_alert('success', _l('marketing_reply_to_lead_settings_saved'));
        redirect(admin_url('marketing/settings'));
    }

    protected function get_whatsapp_settings_post_data()
    {
        $graphVersion = trim((string) $this->input->post('marketing_whatsapp_graph_version', false));
        $graphVersion = trim($graphVersion) !== '' ? trim($graphVersion) : 'v23.0';

        $templateLanguage = trim((string) $this->input->post('marketing_whatsapp_default_template_language', false));
        $templateLanguage = trim($templateLanguage) !== '' ? trim($templateLanguage) : 'en_US';

        $testRecipient = trim((string) $this->input->post('marketing_whatsapp_test_recipient', false));
        $testRecipient = $this->normalize_whatsapp_test_recipient($testRecipient);

        return [
            'marketing_whatsapp_enabled' => (string) ($this->input->post('marketing_whatsapp_enabled', false) === '1' ? '1' : '0'),
            'marketing_whatsapp_graph_version' => $graphVersion,
            'marketing_whatsapp_phone_number_id' => trim((string) $this->input->post('marketing_whatsapp_phone_number_id', false)),
            'marketing_whatsapp_business_account_id' => trim((string) $this->input->post('marketing_whatsapp_business_account_id', false)),
            'marketing_whatsapp_app_secret_raw' => trim((string) $this->input->post('marketing_whatsapp_app_secret', false)),
            'marketing_whatsapp_webhook_verify_token' => trim((string) $this->input->post('marketing_whatsapp_webhook_verify_token', false)),
            'marketing_whatsapp_default_template_name' => trim((string) $this->input->post('marketing_whatsapp_default_template_name', false)),
            'marketing_whatsapp_default_template_language' => $templateLanguage,
            'marketing_whatsapp_test_recipient' => $testRecipient,
            'marketing_whatsapp_access_token_raw' => trim((string) $this->input->post('marketing_whatsapp_access_token', false)),
        ];
    }

    protected function get_license_settings_post_data()
    {
        return [
            'marketing_license_key' => trim((string) $this->input->post('marketing_license_key', false)),
            'marketing_license_email' => trim((string) $this->input->post('marketing_license_email', true)),
            'license_action' => trim((string) $this->input->post('license_action', true)),
        ];
    }

    public function save_whatsapp_settings()
    {
        if (staff_cant('settings', 'marketing')) {
            access_denied('marketing');
        }

        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_404();
        }

        $enabledPost = $this->input->post('marketing_whatsapp_enabled', false);
        if (is_array($enabledPost)) {
            $enabledValues = array_map('strval', $enabledPost);
            $enabled = in_array('1', $enabledValues, true) ? '1' : '0';
        } else {
            $enabled = ((string) $enabledPost === '1') ? '1' : '0';
        }

        $graphVersion = trim((string) $this->input->post('marketing_whatsapp_graph_version', false));
        $graphVersion = $graphVersion !== '' ? $graphVersion : 'v23.0';
        $phoneNumberId = trim((string) $this->input->post('marketing_whatsapp_phone_number_id', false));
        $businessAccountId = trim((string) $this->input->post('marketing_whatsapp_business_account_id', false));
        $appSecret = trim((string) $this->input->post('marketing_whatsapp_app_secret', false));
        $verifyToken = trim((string) $this->input->post('marketing_whatsapp_webhook_verify_token', false));
        $templateName = trim((string) $this->input->post('marketing_whatsapp_default_template_name', false));
        $language = trim((string) $this->input->post('marketing_whatsapp_default_template_language', false));
        $language = $language !== '' ? $language : 'en_US';
        $rawTestRecipient = (string) $this->input->post('marketing_whatsapp_test_recipient', false);
        $testRecipient = $this->normalize_whatsapp_test_recipient($rawTestRecipient);
        $accessToken = trim((string) $this->input->post('marketing_whatsapp_access_token', false));

        $posted = [
            'marketing_whatsapp_enabled' => $enabled,
            'marketing_whatsapp_graph_version' => $graphVersion,
            'marketing_whatsapp_phone_number_id' => $phoneNumberId,
            'marketing_whatsapp_business_account_id' => $businessAccountId,
            'marketing_whatsapp_app_secret' => '',
            'marketing_whatsapp_webhook_verify_token' => $verifyToken,
            'marketing_whatsapp_default_template_name' => $templateName,
            'marketing_whatsapp_default_template_language' => $language,
            'marketing_whatsapp_test_recipient' => $rawTestRecipient,
        ];

        if (!$this->is_valid_whatsapp_graph_version($graphVersion)) {
            $this->session->set_tempdata('marketing_settings_whatsapp_form_data', $posted, 60);
            set_alert('danger', 'Invalid WhatsApp field: marketing_whatsapp_graph_version');
            redirect(admin_url('marketing/settings'));
        }

        if ($phoneNumberId !== '' && preg_match('/^[A-Za-z0-9._-]+$/', $phoneNumberId) !== 1) {
            $this->session->set_tempdata('marketing_settings_whatsapp_form_data', $posted, 60);
            set_alert('danger', 'Invalid WhatsApp field: marketing_whatsapp_phone_number_id');
            redirect(admin_url('marketing/settings'));
        }

        if ($businessAccountId !== '' && preg_match('/^[A-Za-z0-9._-]+$/', $businessAccountId) !== 1) {
            $this->session->set_tempdata('marketing_settings_whatsapp_form_data', $posted, 60);
            set_alert('danger', 'Invalid WhatsApp field: marketing_whatsapp_business_account_id');
            redirect(admin_url('marketing/settings'));
        }

        if ($appSecret !== '' && mb_strlen($appSecret) > 255) {
            $this->session->set_tempdata('marketing_settings_whatsapp_form_data', $posted, 60);
            set_alert('danger', 'Invalid WhatsApp field: marketing_whatsapp_app_secret');
            redirect(admin_url('marketing/settings'));
        }

        if (!$this->is_valid_whatsapp_template_language($language)) {
            $this->session->set_tempdata('marketing_settings_whatsapp_form_data', $posted, 60);
            set_alert('danger', 'Invalid WhatsApp field: marketing_whatsapp_default_template_language');
            redirect(admin_url('marketing/settings'));
        }

        if ($templateName !== '' && preg_match('/^[A-Za-z0-9_.-]+$/', $templateName) !== 1) {
            $this->session->set_tempdata('marketing_settings_whatsapp_form_data', $posted, 60);
            set_alert('danger', 'Invalid WhatsApp field: marketing_whatsapp_default_template_name');
            redirect(admin_url('marketing/settings'));
        }

        if ($testRecipient !== '' && !$this->is_valid_whatsapp_test_recipient($testRecipient)) {
            $this->session->set_tempdata('marketing_settings_whatsapp_form_data', $posted, 60);
            set_alert('danger', 'Invalid WhatsApp test recipient phone number.');
            redirect(admin_url('marketing/settings'));
        }

        $settings = [
            'marketing_whatsapp_enabled' => $enabled,
            'marketing_whatsapp_graph_version' => $graphVersion,
            'marketing_whatsapp_phone_number_id' => $phoneNumberId,
            'marketing_whatsapp_business_account_id' => $businessAccountId,
            'marketing_whatsapp_webhook_verify_token' => $verifyToken,
            'marketing_whatsapp_default_template_name' => $templateName,
            'marketing_whatsapp_default_template_language' => $language,
            'marketing_whatsapp_test_recipient' => $testRecipient,
        ];

        if ($accessToken !== '') {
            $settings['marketing_whatsapp_access_token'] = $accessToken;
        }
        if ($appSecret !== '') {
            $settings['marketing_whatsapp_app_secret'] = $appSecret;
        } elseif (total_rows(db_prefix() . 'options', ['name' => 'marketing_whatsapp_app_secret']) === 0) {
            $settings['marketing_whatsapp_app_secret'] = '';
        }

        foreach ($settings as $option => $value) {
            $saved = $this->force_save_marketing_option($option, $value);
            if (!$saved) {
                log_message('error', 'WhatsApp option failed to save: ' . $option);
                set_alert('danger', 'WhatsApp option failed to save: ' . $option);
                redirect(admin_url('marketing/settings'));
            }
        }

        $storedTestRecipient = $this->get_option_value_from_db('marketing_whatsapp_test_recipient');
        if ((string) $storedTestRecipient !== $testRecipient) {
            log_message('error', 'WhatsApp option failed to save: marketing_whatsapp_test_recipient');
            set_alert('danger', 'WhatsApp option failed to save: marketing_whatsapp_test_recipient');
            redirect(admin_url('marketing/settings'));
        }

        log_message('info', 'Marketing WhatsApp settings updated: ' . implode(', ', array_keys($settings)));
        set_alert('success', 'WhatsApp settings saved.');
        redirect(admin_url('marketing/settings'));
    }

    protected function normalize_whatsapp_test_recipient($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        $hadPlusPrefix = strpos($value, '+') === 0;
        $value = preg_replace('/[\s\-\(\)]+/', '', $value);
        $value = rtrim($value, ".,;:!?");
        $digits = preg_replace('/[^0-9]/', '', $value);
        if ($digits === '') {
            return '';
        }

        if (!$hadPlusPrefix && strpos($digits, '00') === 0) {
            $digits = substr($digits, 2);
        }

        return '+' . $digits;
    }

    protected function is_valid_whatsapp_graph_version($value)
    {
        return preg_match('/^v[0-9]+\.[0-9]+$/', (string) $value) === 1;
    }

    protected function is_valid_whatsapp_template_language($value)
    {
        return preg_match('/^[a-z]{2}(?:_[A-Z]{2})?$/', (string) $value) === 1;
    }

    protected function is_valid_whatsapp_test_recipient($value)
    {
        return preg_match('/^\+[1-9][0-9]{7,14}$/', (string) $value) === 1;
    }

    protected function persist_settings(array $settings)
    {
        foreach ($settings as $option => $value) {
            if (total_rows(db_prefix() . 'options', ['name' => $option]) === 0) {
                add_option($option, $value);
            } else {
                update_option($option, $value);
            }
        }
    }

    protected function marketing_update_or_add_option($name, $value)
    {
        if (total_rows(db_prefix() . 'options', ['name' => $name]) === 0) {
            add_option($name, $value);
        } else {
            update_option($name, $value);
        }

        $stored = get_option($name);
        if ($stored === false || ($stored === '' && (string) $value !== '')) {
            if (total_rows(db_prefix() . 'options', ['name' => $name]) === 0) {
                add_option($name, $value);
            } else {
                update_option($name, $value);
            }
        }
    }

    private function force_save_marketing_option($name, $value)
    {
        $table = db_prefix() . 'options';
        $value = (string) $value;

        $exists = $this->db
            ->where('name', $name)
            ->count_all_results($table) > 0;

        if ($exists) {
            $this->db->where('name', $name)->update($table, ['value' => $value]);
        } else {
            $insert = [
                'name' => $name,
                'value' => $value,
            ];
            if (in_array('autoload', $this->db->list_fields($table), true)) {
                $insert['autoload'] = 1;
            }
            $this->db->insert($table, $insert);
        }

        $row = $this->db
            ->select('value')
            ->where('name', $name)
            ->get($table)
            ->row_array();
        $storedValue = $row && array_key_exists('value', $row) ? (string) $row['value'] : '';
        $saved = $row && $storedValue === $value;

        return $saved;
    }

    protected function get_option_value_from_db($name)
    {
        $row = $this->db
            ->select('value')
            ->from(db_prefix() . 'options')
            ->where('name', $name)
            ->get()
            ->row_array();

        return isset($row['value']) ? (string) $row['value'] : null;
    }

    protected function repair_schema()
    {
        $result = $this->marketing_schema->ensure_minimum_schema();
        $report = $result['report'] ?? ['ok' => false];

        if (!empty($result['errors'])) {
            set_alert('warning', _l('marketing_schema_repair_partial') . ' ' . implode(' ', $result['errors']));
        } elseif (!empty($report['ok'])) {
            set_alert('success', _l('marketing_schema_repair_success'));
        } else {
            set_alert('warning', _l('marketing_schema_repair_warning'));
        }

        redirect(admin_url('marketing/settings'));
    }

    protected function handle_license_settings()
    {
        if (!$this->marketingLicenseAvailable) {
            set_alert('warning', _l('marketing_license_unavailable_warning'));
            redirect(admin_url('marketing/settings'));
        }

        $posted = $this->get_license_settings_post_data();
        $action = $posted['license_action'] !== '' ? $posted['license_action'] : 'activate';

        if ($action === 'deactivate') {
            $state = $this->marketing_license->deactivate_license();
            log_message('info', 'Marketing license deactivated for domain: ' . $state['current_domain']);
            set_alert('success', _l('marketing_license_deactivated'));
            redirect(admin_url('marketing/settings'));
        }

        $currentState = $this->marketing_license->get_license_state();
        $effectiveLicenseKey = trim((string) $posted['marketing_license_key']);
        if ($effectiveLicenseKey === '') {
            $effectiveLicenseKey = trim((string) ($currentState['license_key'] ?? ''));
        }

        $effectiveEmail = trim((string) $posted['marketing_license_email']);
        $validationData = [
            'marketing_license_key' => $effectiveLicenseKey,
            'marketing_license_email' => $effectiveEmail,
        ];

        $this->form_validation->set_data($validationData);
        $this->form_validation->set_rules('marketing_license_key', _l('marketing_license_key'), 'trim|required');
        $this->form_validation->set_rules('marketing_license_email', _l('marketing_license_email'), 'trim|required|valid_email');

        if ($this->form_validation->run() === false) {
            $this->session->set_tempdata('marketing_settings_license_form_data', [
                'marketing_license_key' => '',
                'marketing_license_email' => $posted['marketing_license_email'],
            ], 60);
            set_alert('danger', _l('marketing_license_missing_credentials'));
            redirect(admin_url('marketing/settings'));
        }

        $checkedState = $this->marketing_license->check_license($effectiveLicenseKey, $effectiveEmail);

        if ($checkedState['status'] === 'active') {
            log_message('info', 'Marketing license activated for domain: ' . $checkedState['current_domain']);
            set_alert('success', _l('marketing_license_activated'));
        } elseif ($checkedState['status'] === 'grace') {
            set_alert('warning', sprintf(_l('marketing_license_grace_warning'), $checkedState['grace_until']));
        } elseif ($checkedState['status'] === 'unconfigured') {
            set_alert('warning', _l('marketing_license_unconfigured_warning'));
        } else {
            $error = trim((string) $checkedState['last_error']);
            set_alert('danger', $error !== '' ? _l('marketing_license_check_failed') . ' ' . $error : _l('marketing_license_check_failed'));
        }

        redirect(admin_url('marketing/settings'));
    }

    protected function load_marketing_license_library()
    {
        $libraryPath = dirname(__DIR__) . '/libraries/Marketing_license.php';
        if (!is_file($libraryPath)) {
            return false;
        }

        try {
            $this->load->library('marketing/Marketing_license');
        } catch (Throwable $exception) {
            $message = $exception->getMessage();
            if (function_exists('marketing_sanitize_log_message')) {
                $message = marketing_sanitize_log_message($message);
            }
            log_message('error', 'Marketing license library load failed: ' . $message);

            return false;
        }

        return isset($this->marketing_license) && is_object($this->marketing_license);
    }

    protected function get_fallback_license_state()
    {
        return [
            'license_key' => '',
            'masked_license_key' => '',
            'email' => '',
            'status' => 'inactive',
            'status_label' => 'Inactive',
            'licensed_domain' => '',
            'last_checked' => '',
            'last_checked_display' => 'Never',
            'last_error' => '',
            'grace_until' => '',
            'grace_active' => false,
            'instance_id' => '',
            'current_domain' => function_exists('site_url') ? (string) parse_url((string) site_url(), PHP_URL_HOST) : 'unknown-domain',
            'module_version' => function_exists('marketing_get_module_version') ? marketing_get_module_version() : '1.1.0',
            'perfex_version' => function_exists('marketing_get_perfex_version') ? marketing_get_perfex_version() : '',
            'php_version' => PHP_VERSION,
            'api_base_url' => '',
            'api_base_url_hint' => 'https://license.example.com/api',
            'api_configured' => false,
        ];
    }

    public function test_smtp()
    {
        if (staff_cant('settings', 'marketing')) {
            access_denied('marketing');
        }

        if (!$this->input->post()) {
            show_404();
        }

        $testEmail = trim($this->input->post('test_email', true));
        if (!filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
            set_alert('danger', _l('marketing_smtp_test_invalid_address'));
            redirect(admin_url('marketing/settings'));
        }

        if (get_option('marketing_smtp_enabled') !== '1') {
            set_alert('danger', _l('marketing_smtp_test_not_enabled'));
            redirect(admin_url('marketing/settings'));
        }

        $this->load->library('marketing/Marketing_mailer');
        $result = $this->marketing_mailer->send_test_message($testEmail);

        if ($result['success']) {
            set_alert('success', _l('marketing_smtp_test_success'));
        } else {
            $error = trim($result['error'] ?? '');
            set_alert('danger', _l('marketing_smtp_test_failure') . ($error ? ' ' . $error : ''));
        }

        redirect(admin_url('marketing/settings'));
    }

    public function test_reply_mailbox()
    {
        if (staff_cant('settings', 'marketing')) {
            access_denied('marketing');
        }

        if (strtoupper((string) $this->input->method(true)) !== 'POST') {
            show_404();
        }

        $result = $this->marketing_reply_mailbox->test_connection();
        if (!empty($result['success'])) {
            set_alert('success', _l('marketing_reply_mailbox_test_success'));
        } else {
            $error = trim((string) ($result['error'] ?? ''));
            set_alert('danger', _l('marketing_reply_mailbox_test_failure') . ($error !== '' ? ' ' . $error : ''));
        }

        redirect(admin_url('marketing/settings'));
    }

    public function test_whatsapp()
    {
        if (staff_cant('settings', 'marketing')) {
            access_denied('marketing');
        }

        if (!$this->input->post()) {
            show_404();
        }

        return $this->handle_whatsapp_test();
    }

    protected function handle_whatsapp_test()
    {
        $settings = $this->marketing_whatsapp->get_settings();

        $result = $this->marketing_whatsapp->send_template_message(
            $settings['test_recipient'] ?? '',
            $settings['default_template_name'] ?? '',
            $settings['default_template_language'] ?? '',
            []
        );

        if (!empty($result['success'])) {
            set_alert('success', _l('marketing_whatsapp_test_success'));
        } else {
            set_alert('danger', $this->format_whatsapp_test_error_message($result));
        }

        redirect(admin_url('marketing/settings'));
    }

    protected function format_whatsapp_test_error_message(array $result)
    {
        $message = _l('marketing_whatsapp_test_failure');
        $error = trim((string) ($result['error'] ?? ''));
        if ($error !== '') {
            $message .= ' ' . $error;
        }

        $details = [];
        $statusCode = (int) ($result['status_code'] ?? 0);
        if ($statusCode > 0) {
            $details[] = 'HTTP ' . $statusCode;
        }

        $metaType = trim((string) ($result['meta_error_type'] ?? ''));
        if ($metaType !== '') {
            $details[] = 'type: ' . $metaType;
        }

        $metaCode = trim((string) ($result['meta_error_code'] ?? ''));
        if ($metaCode !== '') {
            $details[] = 'code: ' . $metaCode;
        }

        $metaSubcode = trim((string) ($result['meta_error_subcode'] ?? ''));
        if ($metaSubcode !== '') {
            $details[] = 'subcode: ' . $metaSubcode;
        }

        $fbtraceId = trim((string) ($result['meta_fbtrace_id'] ?? ''));
        if ($fbtraceId !== '') {
            $details[] = 'fbtrace_id: ' . $fbtraceId;
        }

        if (!empty($details)) {
            $message .= ' (' . implode(', ', $details) . ')';
        }

        return $message;
    }
}
