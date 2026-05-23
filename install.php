<?php defined('BASEPATH') OR exit('No direct script access allowed');

$CI = &get_instance();

$options = function_exists('marketing_get_default_options')
    ? marketing_get_default_options()
    : [];

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
