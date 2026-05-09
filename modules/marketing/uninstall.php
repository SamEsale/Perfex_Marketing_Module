<?php

defined('BASEPATH') or exit('No direct script access allowed');

$moduleOptions = [
    'marketing_send_enabled',
    'marketing_send_per_cron_run',
    'marketing_send_sleep_ms',
    'marketing_send_hourly_cap',
    'marketing_send_daily_cap',
    'marketing_default_from_name',
    'marketing_default_from_email',
    'marketing_default_reply_to_email',
    'marketing_track_opens',
    'marketing_track_clicks',
    'marketing_ses_sns_topic_arn',
    'marketing_ses_verify_signature',
    'marketing_ses_sns_signing_mode',
    'marketing_reply_lead_status',
    'marketing_reply_lead_source',
    'marketing_bounce_auto_suppress',
    'marketing_complaint_auto_suppress',
    'marketing_smtp_enabled',
    'marketing_smtp_host',
    'marketing_smtp_port',
    'marketing_smtp_encryption',
    'marketing_smtp_username',
    'marketing_smtp_password',
    'marketing_smtp_from_email',
    'marketing_smtp_from_name',
    'marketing_smtp_reply_to',
    'marketing_ses_region',
    'marketing_ses_configuration_set',
    'marketing_reply_to_lead_enabled',
    'marketing_reply_to_lead_unknown_only',
    'marketing_reply_to_lead_status',
    'marketing_reply_to_lead_source',
    'marketing_reply_to_lead_assigned_staff',
    'marketing_reply_to_lead_name_prefix',
    'marketing_whatsapp_enabled',
    'marketing_whatsapp_graph_version',
    'marketing_whatsapp_phone_number_id',
    'marketing_whatsapp_business_account_id',
    'marketing_whatsapp_access_token',
    'marketing_whatsapp_webhook_verify_token',
    'marketing_whatsapp_default_template_name',
    'marketing_whatsapp_default_template_language',
    'marketing_whatsapp_test_recipient',
    'marketing_license_key',
    'marketing_license_key_protected',
    'marketing_license_email',
    'marketing_license_status',
    'marketing_license_domain',
    'marketing_license_last_checked',
    'marketing_license_last_error',
    'marketing_license_grace_until',
    'marketing_license_instance_id',
    'marketing_license_api_base_url',
    'marketing_license_response_hmac_secret',
    'marketing_schema_bootstrap_version',
    'marketing_reply_lead_defaults_seeded',
    'marketing_reply_lead_source_normalized',
];

foreach ($moduleOptions as $option) {
    delete_option($option);
}

$legacyOptions = [
    'marketing_smtp_' . 'crypto',
    'marketing_smtp_' . 'aws_configuration_set',
];

foreach ($legacyOptions as $option) {
    delete_option($option);
}

require_once __DIR__ . '/migrations/001_initial_schema.php';

$migration = new Migration_Initial_schema();
$migration->down();
