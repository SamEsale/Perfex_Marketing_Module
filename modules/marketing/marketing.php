<?php

defined('BASEPATH') or exit('No direct script access allowed');

/*
Module Name: Marketing
Description: Email marketing and lead capture module scaffold for Perfex CRM.
Version: 1.1.0
Requires at least: 3.2.*
*/

define('MARKETING_MODULE_NAME', 'marketing');
define('MARKETING_MODULE_VERSION', '1.1.0');
define('MARKETING_COMMERCIAL_PREPARE_THRESHOLD', 100);
define('MARKETING_SCHEMA_BOOTSTRAP_VERSION', '2026-05-10-safe-bootstrap-v8');

hooks()->add_action('admin_init', 'marketing_module_init_menu_items');
hooks()->add_action('admin_init', 'marketing_register_permissions');
hooks()->add_action('admin_init', 'marketing_ensure_required_options');
hooks()->add_action('admin_init', 'marketing_bootstrap_safe_schema');
hooks()->add_action('admin_init', 'marketing_sync_module_installed_version');
hooks()->add_action('admin_init', 'marketing_seed_reply_lead_defaults');
hooks()->add_action('admin_init', 'marketing_normalize_reply_created_leads');
hooks()->add_action('app_init', 'marketing_register_csrf_exclusions');
hooks()->add_action('after_cron_run', 'marketing_cron_process_queue');

hooks()->add_filter('module_marketing_action_links', 'module_marketing_action_links');

register_activation_hook(MARKETING_MODULE_NAME, 'marketing_module_activation_hook');
register_deactivation_hook(MARKETING_MODULE_NAME, 'marketing_module_deactivation_hook');
register_uninstall_hook(MARKETING_MODULE_NAME, 'marketing_module_uninstall_hook');
register_language_files(MARKETING_MODULE_NAME, [MARKETING_MODULE_NAME]);

function marketing_module_activation_hook()
{
    require_once __DIR__ . '/install.php';
    marketing_ensure_required_options();
    marketing_bootstrap_safe_schema(true);
    marketing_seed_reply_lead_defaults();
    marketing_normalize_reply_created_leads();
}

function marketing_module_deactivation_hook()
{
    // Keep module data intact on deactivation.
}

function marketing_module_uninstall_hook()
{
    require_once __DIR__ . '/uninstall.php';
}

function marketing_register_permissions()
{
    $capabilities = [
        'capabilities' => [
            'view'      => _l('permission_view') . '(' . _l('permission_global') . ')',
            'view_own'  => _l('permission_view_own'),
            'create'    => _l('permission_create'),
            'edit'      => _l('permission_edit'),
            'delete'    => _l('permission_delete'),
            'send'      => _l('marketing_permission_send'),
            'import'    => _l('marketing_permission_import'),
            'settings'  => _l('settings'),
        ],
    ];

    register_staff_capabilities('marketing', $capabilities, _l('marketing'));
}

function marketing_module_init_menu_items()
{
    $CI = &get_instance();

    if (marketing_staff_can_access_module()) {
        $CI->app_menu->add_sidebar_menu_item('marketing', [
            'collapse' => true,
            'name'     => _l('marketing'),
            'href'     => admin_url('marketing'),
            'position' => 47,
            'icon'     => 'fa-solid fa-bullhorn',
            'badge'    => [],
        ]);

        $CI->app_menu->add_sidebar_children_item('marketing', [
            'slug'     => 'marketing-dashboard',
            'name'     => _l('marketing_dashboard'),
            'href'     => admin_url('marketing'),
            'position' => 5,
            'badge'    => [],
        ]);

        $CI->app_menu->add_sidebar_children_item('marketing', [
            'slug'     => 'marketing-campaigns',
            'name'     => _l('marketing_campaigns'),
            'href'     => admin_url('marketing/campaigns'),
            'position' => 10,
            'badge'    => [],
        ]);

        $CI->app_menu->add_sidebar_children_item('marketing', [
            'slug'     => 'marketing-audiences',
            'name'     => _l('marketing_audiences'),
            'href'     => admin_url('marketing/audiences'),
            'position' => 15,
            'badge'    => [],
        ]);

        $CI->app_menu->add_sidebar_children_item('marketing', [
            'slug'     => 'marketing-imports',
            'name'     => _l('marketing_imports'),
            'href'     => admin_url('marketing/imports'),
            'position' => 20,
            'badge'    => [],
        ]);

        $CI->app_menu->add_sidebar_children_item('marketing', [
            'slug'     => 'marketing-analytics',
            'name'     => _l('marketing_analytics'),
            'href'     => admin_url('marketing/analytics'),
            'position' => 25,
            'badge'    => [],
        ]);

        if (staff_can('settings', 'marketing')) {
            $CI->app_menu->add_sidebar_children_item('marketing', [
                'slug'     => 'marketing-settings',
                'name'     => _l('marketing_settings'),
                'href'     => admin_url('marketing/settings'),
                'position' => 30,
                'badge'    => [],
            ]);
        }
    }

    if (is_admin()) {
        $CI->app_menu->add_setup_menu_item('marketing', [
            'href'     => admin_url('marketing/settings'),
            'name'     => _l('marketing'),
            'position' => 66,
        ]);
    }
}

function module_marketing_action_links($actions)
{
    if (get_instance()->app_modules->is_active(MARKETING_MODULE_NAME)) {
        $actions[] = '<a href="' . admin_url('marketing') . '">' . _l('marketing_dashboard') . '</a>';
        $actions[] = '<a href="' . admin_url('marketing/settings') . '">' . _l('settings') . '</a>';
    }

    return $actions;
}

function marketing_cron_process_queue($manually = false)
{
    try {
        marketing_bootstrap_safe_schema();

        if (get_option('marketing_send_enabled') != 1) {
            return true;
        }

        $CI = &get_instance();
        $CI->load->model('marketing/Marketing_campaigns_model', 'marketing_campaigns_model');

        $limit = (int) get_option('marketing_send_per_cron_run');
        if ($limit <= 0) {
            $limit = 1;
        }

        log_message('info', 'Marketing campaign queue started: mode=cron limit=' . $limit);
        $summary = $CI->marketing_campaigns_model->process_due_recipients($limit);
        log_message('info', 'Marketing campaign queue completed: ' . json_encode([
            'processed' => (int) ($summary['processed'] ?? 0),
            'accepted' => (int) ($summary['accepted'] ?? 0),
            'failed' => (int) ($summary['failed'] ?? 0),
            'suppressed' => (int) ($summary['suppressed'] ?? 0),
            'skipped' => (int) ($summary['skipped'] ?? 0),
            'recovered' => (int) ($summary['recovered'] ?? 0),
            'campaigns_considered' => (int) ($summary['campaigns_considered'] ?? 0),
            'campaigns_started' => (int) ($summary['campaigns_started'] ?? 0),
            'campaigns_completed' => (int) ($summary['campaigns_completed'] ?? 0),
            'warnings' => array_values($summary['warnings'] ?? []),
        ]));
    } catch (Throwable $exception) {
        log_message('error', 'Marketing campaign queue failed: ' . marketing_sanitize_log_message($exception->getMessage()));
    }

    return true;
}

function marketing_register_csrf_exclusions()
{
    $CI = &get_instance();
    $file = __DIR__ . '/config/csrf_exclude_uris.php';

    if (!file_exists($file)) {
        return;
    }

    $moduleExcludes = require $file;
    if (!is_array($moduleExcludes)) {
        return;
    }

    $existing = (array) $CI->config->item('csrf_exclude_uris');
    $merged = array_values(array_unique(array_merge($existing, $moduleExcludes)));
    $CI->config->set_item('csrf_exclude_uris', $merged);
}

function marketing_ensure_required_options()
{
    $requiredOptions = [
        'marketing_ses_verify_signature' => '1',
        'marketing_ses_sns_topic_arn' => '',
        'marketing_ses_sns_signing_mode' => 'strict',
        'marketing_ses_sns_replay_window_seconds' => '900',
        'marketing_ses_sns_future_skew_seconds' => '300',
        'marketing_ses_region' => '',
        'marketing_license_key_protected' => '',
        'marketing_license_response_hmac_secret' => '',
        'marketing_schema_bootstrap_version' => '',
    ];

    foreach ($requiredOptions as $name => $value) {
        if (get_option($name) === false) {
            add_option($name, $value);
        }
    }
}

function marketing_bootstrap_safe_schema($force = false)
{
    $schemaVersion = MARKETING_SCHEMA_BOOTSTRAP_VERSION;

    marketing_ensure_required_options();

    if (!$force && get_option('marketing_schema_bootstrap_version') === $schemaVersion) {
        return;
    }

    $CI = &get_instance();
    $CI->load->library('marketing/Marketing_schema');
    $result = $CI->marketing_schema->ensure_minimum_schema();

    if (get_option('marketing_schema_bootstrap_version') === false) {
        add_option('marketing_schema_bootstrap_version', $schemaVersion);
    } else {
        update_option('marketing_schema_bootstrap_version', $schemaVersion);
    }

    log_message('info', 'Marketing safe schema bootstrap summary: ' . json_encode([
        'ok' => !empty($result['ok']),
        'created_tables' => array_values($result['created_tables'] ?? []),
        'added_columns' => array_values($result['added_columns'] ?? []),
        'skipped_columns' => array_values($result['skipped_columns'] ?? []),
        'added_indexes' => array_values($result['added_indexes'] ?? []),
        'skipped_indexes' => array_values($result['skipped_indexes'] ?? []),
        'orphan_tables' => array_values(array_map(function ($item) {
            return (string) ($item['table'] ?? '');
        }, $result['report']['orphan_tables'] ?? [])),
        'errors' => array_values($result['errors'] ?? []),
    ]));
}

function marketing_sync_module_installed_version()
{
    $CI = &get_instance();

    if (!isset($CI->app_modules) || !method_exists($CI->app_modules, 'get')) {
        return;
    }

    $module = $CI->app_modules->get(MARKETING_MODULE_NAME);
    if (!$module || $module['installed_version'] === false) {
        return;
    }

    if ((string) $module['installed_version'] === MARKETING_MODULE_VERSION) {
        return;
    }

    $CI->load->library('marketing/Marketing_schema');
    $report = $CI->marketing_schema->get_readiness_report();

    if (!empty($report['missing_tables']) || !empty($report['missing_columns']) || !empty($report['unsafe_missing_columns'])) {
        log_message('warning', 'Marketing module version sync deferred because schema is not fully ready.');

        return;
    }

    $CI->db->where('module_name', MARKETING_MODULE_NAME);
    $CI->db->update(db_prefix() . 'modules', [
        'installed_version' => MARKETING_MODULE_VERSION,
    ]);

    log_message(
        'info',
        'Marketing module installed_version synchronized to package version after safe bootstrap: from='
        . (string) $module['installed_version'] . ' to=' . MARKETING_MODULE_VERSION
    );
}

function marketing_sanitize_log_message($message)
{
    $message = trim(strip_tags((string) $message));
    if ($message === '') {
        return 'unknown_error';
    }

    $redactions = [
        trim((string) get_option('marketing_smtp_password')),
        trim((string) get_option('marketing_smtp_username')),
        trim((string) get_option('marketing_whatsapp_access_token')),
        trim((string) get_option('marketing_license_key')),
        trim((string) get_option('marketing_license_key_protected')),
        trim((string) get_option('marketing_license_response_hmac_secret')),
    ];

    foreach ($redactions as $redaction) {
        if ($redaction !== '') {
            $message = str_replace($redaction, '[redacted]', $message);
        }
    }

    $message = preg_replace('/access[_\s-]*token\s*[:=]\s*[^\s]+/i', 'access_token=[redacted]', $message);
    $message = preg_replace('/smtp_pass\s*[:=]\s*[^\s]+/i', 'smtp_pass=[redacted]', $message);
    $message = preg_replace('/password\s*[:=]\s*[^\s]+/i', 'password=[redacted]', $message);

    return mb_substr($message, 0, 1000);
}

function marketing_get_module_version()
{
    return defined('MARKETING_MODULE_VERSION') ? MARKETING_MODULE_VERSION : '1.0.0';
}

function marketing_get_perfex_version()
{
    if (function_exists('get_app_version')) {
        return (string) get_app_version();
    }

    $CI = &get_instance();
    $migrationVersion = (string) $CI->config->item('migration_version');

    return $migrationVersion !== '' ? wordwrap($migrationVersion, 1, '.', true) : '';
}

function marketing_get_license_library()
{
    $libraryPath = __DIR__ . '/libraries/Marketing_license.php';
    if (!is_file($libraryPath)) {
        return null;
    }

    $CI = &get_instance();
    try {
        $CI->load->library('marketing/Marketing_license');
    } catch (Throwable $exception) {
        log_message('error', 'Marketing license library load failed: ' . marketing_sanitize_log_message($exception->getMessage()));

        return null;
    }

    return isset($CI->marketing_license) && is_object($CI->marketing_license)
        ? $CI->marketing_license
        : null;
}

function marketing_license_block_message()
{
    return function_exists('_l')
        ? _l('marketing_license_inactive_alert')
        : 'Marketing license is not active. Please activate your license to use this feature.';
}

function marketing_is_feature_allowed($feature)
{
    try {
        $library = marketing_get_license_library();
        if (!$library || !method_exists($library, 'is_feature_allowed')) {
            return true;
        }

        return $library->is_feature_allowed($feature);
    } catch (Throwable $exception) {
        log_message('error', 'Marketing license feature check failed: ' . marketing_sanitize_log_message($exception->getMessage()));

        return true;
    }
}

function marketing_seed_reply_lead_defaults()
{
    if (get_option('marketing_reply_lead_defaults_seeded') === '1') {
        return;
    }

    $CI = &get_instance();
    if (!isset($CI->db) || !is_object($CI->db)) {
        $CI->load->database();
    }

    if (!isset($CI->db) || !is_object($CI->db)) {
        return;
    }

    $statusTable = db_prefix() . 'leads_status';
    $sourceTable = db_prefix() . 'leads_sources';

    if (!$CI->db->table_exists($statusTable) || !$CI->db->table_exists($sourceTable)) {
        return;
    }

    $seededStatuses = marketing_seed_reply_lead_named_records($CI->db, $statusTable, [
        ['name' => 'New Lead', 'color' => '#1abc9c'],
        ['name' => 'Prospect', 'color' => '#3498db'],
    ], true);
    $seededSources = marketing_seed_reply_lead_named_records($CI->db, $sourceTable, [
        ['name' => 'Email Reply'],
        ['name' => 'Marketing Reply'],
    ], false);

    if ($seededStatuses && $seededSources) {
        if (get_option('marketing_reply_lead_defaults_seeded') === false) {
            add_option('marketing_reply_lead_defaults_seeded', '1');
        } else {
            update_option('marketing_reply_lead_defaults_seeded', '1');
        }
    }
}

function marketing_normalize_reply_created_leads()
{
    if (get_option('marketing_reply_lead_source_normalized') === '1') {
        return;
    }

    $CI = &get_instance();
    if (!isset($CI->db) || !is_object($CI->db)) {
        $CI->load->database();
    }

    if (!isset($CI->db) || !is_object($CI->db)) {
        return;
    }

    $leadsTable = db_prefix() . 'leads';
    $sourcesTable = db_prefix() . 'leads_sources';

    if (!$CI->db->table_exists($leadsTable) || !$CI->db->table_exists($sourcesTable)) {
        return;
    }

    $existingFields = $CI->db->list_fields($leadsTable);
    if (!is_array($existingFields) || !in_array('name', $existingFields, true) || !in_array('source', $existingFields, true)) {
        return;
    }

    $emailReplySourceId = marketing_find_lead_source_id_by_name($CI->db, $sourcesTable, 'Email Reply');
    $marketingReplySourceId = marketing_find_lead_source_id_by_name($CI->db, $sourcesTable, 'Marketing Reply');

    if ($emailReplySourceId <= 0 || $marketingReplySourceId <= 0) {
        return;
    }

    $CI->db->where('source', $emailReplySourceId);
    $CI->db->like('name', 'Marketing Reply', 'after');
    $CI->db->update($leadsTable, ['source' => $marketingReplySourceId]);

    log_message('info', 'Marketing normalized reply-created leads source ids: from=' . $emailReplySourceId . ' to=' . $marketingReplySourceId . ' affected=' . (int) $CI->db->affected_rows());

    if (get_option('marketing_reply_lead_source_normalized') === false) {
        add_option('marketing_reply_lead_source_normalized', '1');
    } else {
        update_option('marketing_reply_lead_source_normalized', '1');
    }
}

function marketing_seed_reply_lead_named_records($db, $table, array $records, $isStatusTable = false)
{
    if (!is_object($db) || !$db->table_exists($table)) {
        return false;
    }

    $fields = $db->list_fields($table);
    if (empty($fields) || !in_array('name', $fields, true)) {
        return false;
    }

    $existingNames = [];
    $query = $db->select('name')->get($table);
    if ($query) {
        foreach ($query->result_array() as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name !== '') {
                $existingNames[strtolower($name)] = true;
            }
        }
    }

    $nextStatusOrder = 1;
    if ($isStatusTable && in_array('statusorder', $fields, true)) {
        $query = $db->select_max('statusorder')->get($table);
        $maxOrder = 0;
        if ($query) {
            $row = $query->row_array();
            $maxOrder = (int) ($row['statusorder'] ?? 0);
        }
        $nextStatusOrder = $maxOrder + 1;
    }

    foreach ($records as $record) {
        $name = trim((string) ($record['name'] ?? ''));
        if ($name === '') {
            continue;
        }

        $normalizedName = strtolower($name);
        if (isset($existingNames[$normalizedName])) {
            continue;
        }

        $insert = ['name' => $name];

        if ($isStatusTable) {
            if (in_array('color', $fields, true)) {
                $insert['color'] = (string) ($record['color'] ?? '#757575');
            }
            if (in_array('statusorder', $fields, true)) {
                $insert['statusorder'] = $nextStatusOrder++;
            }
        }

        $db->insert($table, $insert);
        if ($db->affected_rows() > 0) {
            $existingNames[$normalizedName] = true;
        }
    }

    return true;
}

function marketing_find_lead_source_id_by_name($db, $table, $name)
{
    if (!is_object($db) || !$db->table_exists($table)) {
        return 0;
    }

    $query = $db->select('id')
        ->from($table)
        ->where('LOWER(name) =', strtolower((string) $name))
        ->limit(1)
        ->get();

    if (!$query) {
        return 0;
    }

    $row = $query->row_array();

    return isset($row['id']) ? (int) $row['id'] : 0;
}

function marketing_staff_can_access_module()
{
    return staff_can('view', 'marketing')
        || staff_can('view_own', 'marketing')
        || staff_can('create', 'marketing')
        || staff_can('edit', 'marketing')
        || staff_can('delete', 'marketing')
        || staff_can('send', 'marketing')
        || staff_can('import', 'marketing')
        || staff_can('settings', 'marketing');
}
