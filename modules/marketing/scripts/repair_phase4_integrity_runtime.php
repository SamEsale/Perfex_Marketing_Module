<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must run from cli.\n");
    exit(2);
}

$marketingRepairExitHandled = false;

register_shutdown_function(function () use (&$marketingRepairExitHandled) {
    if ($marketingRepairExitHandled) {
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

function marketing_repair_print_banner()
{
    echo "Marketing Integrity Repair\n";
}

function marketing_repair_exit($status, $message = '', $stderr = true)
{
    global $marketingRepairExitHandled;
    $marketingRepairExitHandled = true;

    if ($message !== '') {
        fwrite($stderr ? STDERR : STDOUT, rtrim($message) . "\n");
    }

    exit((int) $status);
}

function marketing_repair_usage()
{
    echo "Usage:\n";
    echo "  php modules/marketing/scripts/repair_phase4_integrity_runtime.php\n";
    echo "  php modules/marketing/scripts/repair_phase4_integrity_runtime.php --apply\n";
    echo "  php modules/marketing/scripts/repair_phase4_integrity_runtime.php --help\n";
}

function marketing_repair_find_crm_root($startDir)
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

function marketing_repair_define_bootstrap_guards($crmRoot)
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

function marketing_repair_define_runtime_db_prefix_fallback()
{
    if (!function_exists('db_prefix')) {
        /**
         * Minimal fallback for loading Perfex database config in this CLI repair tool.
         * This does not replace the full Perfex runtime bootstrap.
         */
        function db_prefix()
        {
            return 'tbl';
        }
    }
}

function marketing_repair_connect_db($crmRoot)
{
    marketing_repair_define_bootstrap_guards($crmRoot);
    marketing_repair_define_runtime_db_prefix_fallback();

    try {
        require $crmRoot . '/application/config/app-config.php';
        require $crmRoot . '/application/config/database.php';
    } catch (Throwable $exception) {
        marketing_repair_exit(2, "Runtime bootstrap failure: " . $exception->getMessage());
    }

    if (!isset($db['default']) || !is_array($db['default'])) {
        marketing_repair_exit(2, "Database configuration is unavailable.");
    }

    $config = $db['default'];
    $mysqli = @new mysqli($config['hostname'], $config['username'], $config['password'], $config['database']);
    if ($mysqli->connect_errno) {
        marketing_repair_exit(2, "Database connection failed.");
    }

    $charset = defined('APP_DB_CHARSET') ? APP_DB_CHARSET : 'utf8mb4';
    $mysqli->set_charset($charset);

    return $mysqli;
}

function marketing_repair_fetch_count($mysqli, $sql)
{
    $result = $mysqli->query($sql);
    if (!$result) {
        marketing_repair_exit(2, "Runtime setup failure: unable to read integrity metadata.");
    }

    $row = $result->fetch_assoc();

    return (int) ($row['total'] ?? 0);
}

function marketing_repair_fetch_rows($mysqli, $sql, $limit = 5)
{
    $sql .= ' LIMIT ' . max(1, min(10, (int) $limit));
    $result = $mysqli->query($sql);
    if (!$result) {
        marketing_repair_exit(2, "Runtime setup failure: unable to read integrity samples.");
    }

    return $result->fetch_all(MYSQLI_ASSOC);
}

function marketing_repair_print_samples(array $rows)
{
    if (empty($rows)) {
        return;
    }

    $sampleIds = [];
    foreach ($rows as $row) {
        if (isset($row['id'])) {
            $sampleIds[] = (string) $row['id'];
        }
    }

    if (!empty($sampleIds)) {
        echo "  sample ids: " . implode(', ', array_slice($sampleIds, 0, 5)) . "\n";
    }
}

function marketing_repair_run_update($mysqli, $sql)
{
    $result = $mysqli->query($sql);
    if ($result !== true) {
        return false;
    }

    return (int) $mysqli->affected_rows;
}

$applyMode = false;
$showHelp = false;

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--apply') {
        $applyMode = true;
        continue;
    }

    if ($argument === '--help') {
        $showHelp = true;
        continue;
    }

    marketing_repair_print_banner();
    marketing_repair_usage();
    marketing_repair_exit(2, "Unknown argument: " . $argument);
}

marketing_repair_print_banner();

if ($showHelp) {
    marketing_repair_usage();
    marketing_repair_exit(0, '', false);
}

$crmRoot = marketing_repair_find_crm_root(dirname(__DIR__, 3));
if ($crmRoot === null) {
    marketing_repair_exit(2, "Perfex CRM root not found. Run this from the deployed CRM root.");
}

$mysqli = marketing_repair_connect_db($crmRoot);

$repairTargets = [
    [
        'key' => 'inbound_messages_missing_channel',
        'title' => 'Inbound Email Messages Missing Channel',
        'count_sql' => "select count(*) as total from tblmarketing_messages where provider='inbound_email' and (channel is null or channel='')",
        'sample_sql' => "select id from tblmarketing_messages where provider='inbound_email' and (channel is null or channel='') order by id asc",
        'update_sql' => "update tblmarketing_messages set channel='email' where provider='inbound_email' and (channel is null or channel='')",
    ],
    [
        'key' => 'email_reply_conversations_missing_channel',
        'title' => 'Email Reply Conversations Missing Channel',
        'count_sql' => "select count(*) as total from tblmarketing_conversations where source_type='email_reply' and (channel is null or channel='')",
        'sample_sql' => "select id from tblmarketing_conversations where source_type='email_reply' and (channel is null or channel='') order by id asc",
        'update_sql' => "update tblmarketing_conversations set channel='email' where source_type='email_reply' and (channel is null or channel='')",
    ],
    [
        'key' => 'email_reply_conversations_missing_provider',
        'title' => 'Email Reply Conversations Missing Provider',
        'count_sql' => "select count(*) as total from tblmarketing_conversations where source_type='email_reply' and (provider is null or provider='')",
        'sample_sql' => "select id from tblmarketing_conversations where source_type='email_reply' and (provider is null or provider='') order by id asc",
        'update_sql' => "update tblmarketing_conversations set provider='inbound_email' where source_type='email_reply' and (provider is null or provider='')",
    ],
];

$nonRepairable = [
    'title' => 'Orphaned Event Recipient Refs',
    'count' => marketing_repair_fetch_count($mysqli, "select count(*) as total from tblmarketing_events e left join tblmarketing_campaign_recipients r on r.id = e.campaign_recipient_id where e.campaign_recipient_id is not null and r.id is null"),
    'rows' => marketing_repair_fetch_rows($mysqli, "select e.id from tblmarketing_events e left join tblmarketing_campaign_recipients r on r.id = e.campaign_recipient_id where e.campaign_recipient_id is not null and r.id is null order by e.id asc"),
];

echo "Mode: " . ($applyMode ? 'apply' : 'dry-run') . "\n";
echo "CRM root: " . $crmRoot . "\n";
echo "------------------------------\n";

$repairableCount = 0;
foreach ($repairTargets as &$target) {
    $target['count'] = marketing_repair_fetch_count($mysqli, $target['count_sql']);
    $target['rows'] = marketing_repair_fetch_rows($mysqli, $target['sample_sql']);
    $repairableCount += (int) $target['count'];

    echo $target['title'] . ': ' . (int) $target['count'] . "\n";
    marketing_repair_print_samples($target['rows']);
}
unset($target);

echo $nonRepairable['title'] . ': ' . (int) $nonRepairable['count'] . " (diagnostic-only; no automatic repair)\n";
marketing_repair_print_samples($nonRepairable['rows']);
echo "Schema drift remains diagnostic-only in this patch.\n";
echo "------------------------------\n";

if (!$applyMode) {
    if ($repairableCount > 0) {
        echo "Dry-run found repairable metadata drift. Re-run with --apply to persist these repairs.\n";
        marketing_repair_exit(1, '', false);
    }

    echo "No repairable metadata drift found.\n";
    marketing_repair_exit(0, '', false);
}

if ($repairableCount <= 0) {
    echo "No repairable metadata drift found.\n";
    marketing_repair_exit(0, '', false);
}

$mysqli->autocommit(false);

foreach ($repairTargets as $target) {
    if ((int) $target['count'] <= 0) {
        continue;
    }

    $applied = marketing_repair_run_update($mysqli, $target['update_sql']);
    if ($applied === false) {
        $mysqli->rollback();
        marketing_repair_exit(3, "Apply failed while repairing: " . $target['title']);
    }

    echo "Applied " . $target['title'] . ': ' . (int) $applied . "\n";
}

if (!$mysqli->commit()) {
    $mysqli->rollback();
    marketing_repair_exit(3, "Apply failed while committing integrity repairs.");
}

$mysqli->autocommit(true);
echo "Integrity metadata repairs completed successfully.\n";
marketing_repair_exit(0, '', false);
