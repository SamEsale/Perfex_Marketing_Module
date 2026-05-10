<?php defined('BASEPATH') OR exit('No direct script access allowed');

$CI = &get_instance();

$options = [
    'marketing_send_enabled' => '0',
    'marketing_send_per_cron_run' => '20',
    'marketing_send_sleep_ms' => '0',
    'marketing_send_hourly_cap' => '0',
    'marketing_send_daily_cap' => '0',
    'marketing_send_start_hour' => '00:00',
    'marketing_send_end_hour' => '23:59',
    'marketing_default_from_name' => '',
    'marketing_default_from_email' => '',
    'marketing_default_reply_to_email' => '',
    'marketing_track_opens' => '1',
    'marketing_track_clicks' => '1',
    'marketing_ses_verify_signature' => '1',
    'marketing_ses_sns_topic_arn' => '',
    'marketing_ses_sns_signing_mode' => 'strict',
    'marketing_ses_sns_replay_window_seconds' => '900',
    'marketing_ses_sns_future_skew_seconds' => '300',
    'marketing_smtp_enabled' => '0',
    'marketing_smtp_host' => '',
    'marketing_smtp_port' => '',
    'marketing_smtp_encryption' => '',
    'marketing_smtp_username' => '',
    'marketing_smtp_password' => '',
    'marketing_smtp_from_email' => '',
    'marketing_smtp_from_name' => '',
    'marketing_smtp_reply_to' => '',
    'marketing_ses_region' => '',
    'marketing_ses_configuration_set' => '',
    'marketing_reply_to_lead_enabled' => '0',
    'marketing_reply_to_lead_unknown_only' => '1',
    'marketing_reply_to_lead_status' => '0',
    'marketing_reply_to_lead_source' => '0',
    'marketing_reply_to_lead_assigned_staff' => '0',
    'marketing_reply_to_lead_name_prefix' => 'Marketing Reply',
    'marketing_whatsapp_enabled' => '0',
    'marketing_whatsapp_graph_version' => 'v23.0',
    'marketing_whatsapp_phone_number_id' => '',
    'marketing_whatsapp_business_account_id' => '',
    'marketing_whatsapp_access_token' => '',
    'marketing_whatsapp_webhook_verify_token' => '',
    'marketing_whatsapp_default_template_name' => '',
    'marketing_whatsapp_default_template_language' => 'en_US',
    'marketing_whatsapp_test_recipient' => '',
    'marketing_license_key' => '',
    'marketing_license_key_protected' => '',
    'marketing_license_email' => '',
    'marketing_license_status' => 'inactive',
    'marketing_license_domain' => '',
    'marketing_license_last_checked' => '',
    'marketing_license_last_error' => '',
    'marketing_license_grace_until' => '',
    'marketing_license_instance_id' => '',
    'marketing_license_api_base_url' => 'https://license.spitol.com/api',
    'marketing_license_response_hmac_secret' => '',
    'marketing_schema_bootstrap_version' => '',
    'marketing_reply_lead_defaults_seeded' => '0',
    'marketing_reply_lead_source_normalized' => '0',
];

foreach ($options as $name => $value) {
    if (get_option($name) === false) {
        add_option($name, $value);
    }
}

if (function_exists('marketing_bootstrap_safe_schema')) {
    marketing_bootstrap_safe_schema(true);
}

if (function_exists('marketing_seed_reply_lead_defaults')) {
    marketing_seed_reply_lead_defaults();
}

if (function_exists('marketing_normalize_reply_created_leads')) {
    marketing_normalize_reply_created_leads();
}

log_message('info', 'Marketing module installed with safe schema bootstrap. Missing module-owned schema is created non-destructively.');

return true;
