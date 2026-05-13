<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must run from cli.\n");
    exit(2);
}

$marketingRuntimeExitHandled = false;

register_shutdown_function(function () use (&$marketingRuntimeExitHandled) {
    if ($marketingRuntimeExitHandled) {
        return;
    }

    $error = error_get_last();
    if (!is_array($error)) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array((int) ($error['type'] ?? 0), $fatalTypes, true)) {
        return;
    }

    $message = trim((string) ($error['message'] ?? 'Runtime bootstrap failure.'));
    if ($message === '') {
        $message = 'Runtime bootstrap failure.';
    }

    fwrite(STDERR, "Runtime bootstrap failure: " . $message . "\n");
    exit(2);
});

function marketing_print_banner()
{
    echo "Marketing Integrity Diagnostics\n";
}

function marketing_exit_with_status($status, $message = '', $stderr = true)
{
    global $marketingRuntimeExitHandled;
    $marketingRuntimeExitHandled = true;

    if ($message !== '') {
        fwrite($stderr ? STDERR : STDOUT, rtrim($message) . "\n");
    }

    exit((int) $status);
}

function marketing_find_crm_root($startDir)
{
    $dir = $startDir;
    for ($i = 0; $i < 6; $i++) {
        if (is_file($dir . '/application/config/app-config.php') && is_file($dir . '/application/config/database.php')) {
            return $dir;
        }
        $parent = dirname($dir);
        if ($parent === $dir) {
            break;
        }
        $dir = $parent;
    }

    return null;
}

function marketing_define_bootstrap_guards($crmRoot)
{
    if (!defined('BASEPATH')) {
        define('BASEPATH', $crmRoot . '/system/');
    }

    if (!defined('APPPATH')) {
        define('APPPATH', $crmRoot . '/application/');
    }

    if (!defined('FCPATH')) {
        define('FCPATH', rtrim($crmRoot, '/\\') . DIRECTORY_SEPARATOR);
    }

    if (!defined('ENVIRONMENT')) {
        define('ENVIRONMENT', 'production');
    }
}

function marketing_define_runtime_db_prefix_fallback()
{
    if (!function_exists('db_prefix')) {
        /**
         * Minimal fallback for loading Perfex database config in this CLI validator.
         * This does not replace the full Perfex runtime bootstrap.
         */
        function db_prefix()
        {
            return 'tbl';
        }
    }
}

function marketing_connect_db($crmRoot)
{
    marketing_define_bootstrap_guards($crmRoot);
    marketing_define_runtime_db_prefix_fallback();

    try {
        require $crmRoot . '/application/config/app-config.php';
        require $crmRoot . '/application/config/database.php';
    } catch (Throwable $exception) {
        marketing_exit_with_status(2, "Runtime bootstrap failure: " . $exception->getMessage());
    }

    if (!isset($db['default']) || !is_array($db['default'])) {
        marketing_exit_with_status(2, "Database configuration is unavailable.");
    }

    $config = $db['default'];
    $mysqli = @new mysqli($config['hostname'], $config['username'], $config['password'], $config['database']);
    if ($mysqli->connect_errno) {
        marketing_exit_with_status(2, "Database connection failed.");
    }

    $charset = defined('APP_DB_CHARSET') ? APP_DB_CHARSET : 'utf8mb4';
    $mysqli->set_charset($charset);

    return [$mysqli, $config['database']];
}

function marketing_fetch_count($mysqli, $sql)
{
    $result = $mysqli->query($sql);
    if (!$result) {
        return 0;
    }

    $row = $result->fetch_assoc();

    return (int) ($row['total'] ?? 0);
}

function marketing_fetch_rows($mysqli, $sql, $limit = 5)
{
    $sql .= ' LIMIT ' . max(1, min(10, (int) $limit));
    $result = $mysqli->query($sql);
    if (!$result) {
        return [];
    }

    return $result->fetch_all(MYSQLI_ASSOC);
}

function marketing_print_section($label, $severity, $count, array $rows = [])
{
    printf("[%s] %s: %d\n", strtoupper($severity), $label, (int) $count);
    if (empty($rows)) {
        return;
    }

    $sampleIds = [];
    foreach ($rows as $row) {
        if (isset($row['id'])) {
            $sampleIds[] = (string) $row['id'];
            continue;
        }
        if (isset($row['provider_message_id'])) {
            $sampleIds[] = (string) $row['provider_message_id'];
            continue;
        }
        if (isset($row['provider_event_id'])) {
            $sampleIds[] = (string) $row['provider_event_id'];
        }
    }

    if (!empty($sampleIds)) {
        printf("  sample ids: %s\n", implode(', ', array_slice($sampleIds, 0, 5)));
    }
}

$exitStatus = 0;
marketing_print_banner();

$crmRoot = marketing_find_crm_root(dirname(__DIR__, 3));
if ($crmRoot === null) {
    marketing_exit_with_status(2, "Perfex CRM root not found. Run this from the deployed CRM root.");
}

[$mysqli, $databaseName] = marketing_connect_db($crmRoot);

$categories = [];

$categories[] = [
    'label' => 'Audit-Preserved Orphaned Event Recipient Refs',
    'severity' => 'warning',
    'count' => marketing_fetch_count($mysqli, "select count(*) as total from tblmarketing_events e left join tblmarketing_campaign_recipients r on r.id = e.campaign_recipient_id where e.campaign_recipient_id is not null and r.id is null"),
    'rows' => marketing_fetch_rows($mysqli, "select e.id, e.campaign_recipient_id from tblmarketing_events e left join tblmarketing_campaign_recipients r on r.id = e.campaign_recipient_id where e.campaign_recipient_id is not null and r.id is null order by e.id asc"),
];
$categories[] = [
    'label' => 'Invalid Recipient Status Values',
    'severity' => 'critical',
    'count' => marketing_fetch_count($mysqli, "select count(*) as total from tblmarketing_campaign_recipients where status not in ('queued','processing','sent','failed','suppressed','skipped')"),
    'rows' => marketing_fetch_rows($mysqli, "select id, status from tblmarketing_campaign_recipients where status not in ('queued','processing','sent','failed','suppressed','skipped') order by id asc"),
];
$categories[] = [
    'label' => 'Processing Rows Missing Token',
    'severity' => 'critical',
    'count' => marketing_fetch_count($mysqli, "select count(*) as total from tblmarketing_campaign_recipients where status='processing' and (processing_token is null or processing_token='')"),
    'rows' => marketing_fetch_rows($mysqli, "select id, campaign_id from tblmarketing_campaign_recipients where status='processing' and (processing_token is null or processing_token='') order by id asc"),
];
$categories[] = [
    'label' => 'Queued Rows With Attempt Token',
    'severity' => 'warning',
    'count' => marketing_fetch_count($mysqli, "select count(*) as total from tblmarketing_campaign_recipients where status='queued' and outbound_attempt_token is not null and outbound_attempt_token!=''"),
    'rows' => marketing_fetch_rows($mysqli, "select id, campaign_id from tblmarketing_campaign_recipients where status='queued' and outbound_attempt_token is not null and outbound_attempt_token!='' order by id asc"),
];
$categories[] = [
    'label' => 'Stale Processing Rows',
    'severity' => 'warning',
    'count' => marketing_fetch_count($mysqli, "select count(*) as total from tblmarketing_campaign_recipients where status='processing' and dateupdated is not null and dateupdated < (utc_timestamp() - interval 30 minute)"),
    'rows' => marketing_fetch_rows($mysqli, "select id, campaign_id from tblmarketing_campaign_recipients where status='processing' and dateupdated is not null and dateupdated < (utc_timestamp() - interval 30 minute) order by dateupdated asc"),
];
$categories[] = [
    'label' => 'Failed Rows With Positive Provider Evidence',
    'severity' => 'warning',
    'count' => marketing_fetch_count($mysqli, "select count(distinct r.id) as total from tblmarketing_campaign_recipients r join tblmarketing_events e on e.campaign_recipient_id=r.id where r.status='failed' and e.event_type in ('delivered','open','click')"),
    'rows' => marketing_fetch_rows($mysqli, "select r.id, r.campaign_id from tblmarketing_campaign_recipients r join tblmarketing_events e on e.campaign_recipient_id=r.id where r.status='failed' and e.event_type in ('delivered','open','click') group by r.id, r.campaign_id order by r.id asc"),
];
$categories[] = [
    'label' => 'Suppressed Or Skipped With Engagement Evidence',
    'severity' => 'warning',
    'count' => marketing_fetch_count($mysqli, "select count(distinct r.id) as total from tblmarketing_campaign_recipients r join tblmarketing_events e on e.campaign_recipient_id=r.id where r.status in ('suppressed','skipped') and e.event_type in ('delivered','open','click')"),
    'rows' => marketing_fetch_rows($mysqli, "select r.id, r.campaign_id from tblmarketing_campaign_recipients r join tblmarketing_events e on e.campaign_recipient_id=r.id where r.status in ('suppressed','skipped') and e.event_type in ('delivered','open','click') group by r.id, r.campaign_id order by r.id asc"),
];
$categories[] = [
    'label' => 'Sending Campaigns With No Active Recipients',
    'severity' => 'warning',
    'count' => marketing_fetch_count($mysqli, "select count(*) as total from (select c.id from tblmarketing_campaigns c left join tblmarketing_campaign_recipients r on r.campaign_id=c.id where c.status='sending' group by c.id having sum(case when r.status='queued' then 1 else 0 end)=0 and sum(case when r.status='processing' then 1 else 0 end)=0) x"),
    'rows' => marketing_fetch_rows($mysqli, "select c.id, c.name from tblmarketing_campaigns c left join tblmarketing_campaign_recipients r on r.campaign_id=c.id where c.status='sending' group by c.id, c.name having sum(case when r.status='queued' then 1 else 0 end)=0 and sum(case when r.status='processing' then 1 else 0 end)=0 order by c.id asc"),
];
$categories[] = [
    'label' => 'Terminal Campaigns With Active Recipients',
    'severity' => 'warning',
    'count' => marketing_fetch_count($mysqli, "select count(*) as total from (select c.id from tblmarketing_campaigns c join tblmarketing_campaign_recipients r on r.campaign_id=c.id where c.status in ('draft','sent','completed','cancelled','failed') and r.status in ('queued','processing') group by c.id) x"),
    'rows' => marketing_fetch_rows($mysqli, "select c.id, c.name from tblmarketing_campaigns c join tblmarketing_campaign_recipients r on r.campaign_id=c.id where c.status in ('draft','sent','completed','cancelled','failed') and r.status in ('queued','processing') group by c.id, c.name order by c.id asc"),
];
$categories[] = [
    'label' => 'Stale Worker Leases',
    'severity' => 'warning',
    'count' => marketing_fetch_count($mysqli, "select count(*) as total from tblmarketing_campaigns where queue_worker_token is not null and queue_worker_token!='' and queue_worker_heartbeat_at is not null and queue_worker_heartbeat_at < (utc_timestamp() - interval 30 minute)"),
    'rows' => marketing_fetch_rows($mysqli, "select id, name from tblmarketing_campaigns where queue_worker_token is not null and queue_worker_token!='' and queue_worker_heartbeat_at is not null and queue_worker_heartbeat_at < (utc_timestamp() - interval 30 minute) order by id asc"),
];
$categories[] = [
    'label' => 'Overdue Scheduled Campaigns',
    'severity' => 'warning',
    'count' => marketing_fetch_count($mysqli, "select count(*) as total from tblmarketing_campaigns where status='scheduled' and scheduled_at is not null and scheduled_at < (utc_timestamp() - interval 10 minute)"),
    'rows' => marketing_fetch_rows($mysqli, "select id, name from tblmarketing_campaigns where status='scheduled' and scheduled_at is not null and scheduled_at < (utc_timestamp() - interval 10 minute) order by id asc"),
];
$categories[] = [
    'label' => 'Inbound Email Messages Missing Channel',
    'severity' => 'critical',
    'count' => marketing_fetch_count($mysqli, "select count(*) as total from tblmarketing_messages where provider='inbound_email' and (channel is null or channel='')"),
    'rows' => marketing_fetch_rows($mysqli, "select id, provider_message_id from tblmarketing_messages where provider='inbound_email' and (channel is null or channel='') order by id asc"),
];
$categories[] = [
    'label' => 'Inbound Email Messages Missing Provider',
    'severity' => 'critical',
    'count' => marketing_fetch_count($mysqli, "select count(*) as total from tblmarketing_messages where provider='inbound_email' and (provider is null or provider='')"),
    'rows' => marketing_fetch_rows($mysqli, "select id, provider_message_id from tblmarketing_messages where provider='inbound_email' and (provider is null or provider='') order by id asc"),
];
$categories[] = [
    'label' => 'Inbound Email Messages Missing Dedupe Key',
    'severity' => 'critical',
    'count' => marketing_fetch_count($mysqli, "select count(*) as total from tblmarketing_messages where provider='inbound_email' and (dedupe_key is null or dedupe_key='')"),
    'rows' => marketing_fetch_rows($mysqli, "select id, provider_message_id from tblmarketing_messages where provider='inbound_email' and (dedupe_key is null or dedupe_key='') order by id asc"),
];
$categories[] = [
    'label' => 'Duplicate Inbound Provider Message IDs',
    'severity' => 'critical',
    'count' => marketing_fetch_count($mysqli, "select count(*) as total from (select provider_message_id from tblmarketing_messages where provider='inbound_email' and provider_message_id is not null and provider_message_id!='' group by provider_message_id having count(*) > 1) x"),
    'rows' => marketing_fetch_rows($mysqli, "select provider_message_id, count(*) as total from tblmarketing_messages where provider='inbound_email' and provider_message_id is not null and provider_message_id!='' group by provider_message_id having count(*) > 1 order by total desc"),
];
$categories[] = [
    'label' => 'Email Reply Conversations Missing Channel',
    'severity' => 'critical',
    'count' => marketing_fetch_count($mysqli, "select count(*) as total from tblmarketing_conversations where source_type='email_reply' and (channel is null or channel='')"),
    'rows' => marketing_fetch_rows($mysqli, "select id, email from tblmarketing_conversations where source_type='email_reply' and (channel is null or channel='') order by id asc"),
];
$categories[] = [
    'label' => 'Email Reply Conversations Missing Provider',
    'severity' => 'critical',
    'count' => marketing_fetch_count($mysqli, "select count(*) as total from tblmarketing_conversations where source_type='email_reply' and (provider is null or provider='')"),
    'rows' => marketing_fetch_rows($mysqli, "select id, email from tblmarketing_conversations where source_type='email_reply' and (provider is null or provider='') order by id asc"),
];
$categories[] = [
    'label' => 'Schema Drift Snapshot',
    'severity' => 'warning',
    'count' => marketing_fetch_count($mysqli, "select count(*) as total from information_schema.columns where table_schema='" . $mysqli->real_escape_string($databaseName) . "' and ((table_name='tblmarketing_conversations' and column_name='channel' and is_nullable!='YES') or (table_name='tblmarketing_messages' and column_name='channel' and is_nullable!='YES'))"),
    'rows' => marketing_fetch_rows($mysqli, "select table_name as id, column_name, is_nullable from information_schema.columns where table_schema='" . $mysqli->real_escape_string($databaseName) . "' and ((table_name='tblmarketing_conversations' and column_name='channel' and is_nullable!='YES') or (table_name='tblmarketing_messages' and column_name='channel' and is_nullable!='YES'))"),
];

$criticalCount = 0;
$warningCount = 0;

echo "Generated at: " . gmdate('c') . "\n";
echo "CRM root: " . $crmRoot . "\n";
echo "------------------------------\n";

foreach ($categories as $category) {
    $count = (int) ($category['count'] ?? 0);
    if (($category['severity'] ?? 'warning') === 'critical') {
        $criticalCount += $count;
    } else {
        $warningCount += $count;
    }
    marketing_print_section($category['label'], $category['severity'], $count, $category['rows'] ?? []);
}

echo "------------------------------\n";
echo "critical_count=" . $criticalCount . "\n";
echo "warning_count=" . $warningCount . "\n";

$exitStatus = $criticalCount > 0 ? 1 : 0;

marketing_exit_with_status($exitStatus, '', false);
