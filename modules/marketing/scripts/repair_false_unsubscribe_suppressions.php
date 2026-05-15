<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must run from cli.\n");
    exit(2);
}

$handledExit = false;

register_shutdown_function(function () use (&$handledExit) {
    if ($handledExit) {
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
    fwrite(STDERR, ($message !== '' ? $message : 'Runtime bootstrap failure.') . "\n");
    exit(2);
});

function unsubscribe_repair_exit($status, $message = '', $stderr = false)
{
    global $handledExit;
    $handledExit = true;

    if ($message !== '') {
        fwrite($stderr ? STDERR : STDOUT, rtrim($message) . "\n");
    }

    exit((int) $status);
}

function unsubscribe_repair_usage()
{
    echo "Usage:\n";
    echo "  php modules/marketing/scripts/repair_false_unsubscribe_suppressions.php --email=user@example.com [--email=other@example.com]\n";
    echo "  php modules/marketing/scripts/repair_false_unsubscribe_suppressions.php --emails=user@example.com,other@example.com\n";
    echo "  php modules/marketing/scripts/repair_false_unsubscribe_suppressions.php --email=user@example.com --apply\n";
    echo "  php modules/marketing/scripts/repair_false_unsubscribe_suppressions.php --help\n";
}

function unsubscribe_repair_find_crm_root($startDir)
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

function unsubscribe_repair_define_bootstrap_guards($crmRoot)
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

function unsubscribe_repair_define_runtime_db_prefix_fallback()
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

function unsubscribe_repair_connect_db($crmRoot)
{
    unsubscribe_repair_define_bootstrap_guards($crmRoot);
    unsubscribe_repair_define_runtime_db_prefix_fallback();

    try {
        require $crmRoot . '/application/config/app-config.php';
        require $crmRoot . '/application/config/database.php';
    } catch (Throwable $exception) {
        unsubscribe_repair_exit(2, "Runtime bootstrap failure: " . $exception->getMessage(), true);
    }

    if (!isset($db['default']) || !is_array($db['default'])) {
        unsubscribe_repair_exit(2, "Database configuration is unavailable.", true);
    }

    $config = $db['default'];
    $mysqli = @new mysqli($config['hostname'], $config['username'], $config['password'], $config['database']);
    if ($mysqli->connect_errno) {
        unsubscribe_repair_exit(2, "Database connection failed.", true);
    }

    $charset = defined('APP_DB_CHARSET') ? APP_DB_CHARSET : 'utf8mb4';
    $mysqli->set_charset($charset);

    return [$mysqli, $config['database']];
}

function unsubscribe_repair_table_exists($mysqli, $databaseName, $tableName)
{
    $sql = "select count(*) as total from information_schema.tables where table_schema='" . $mysqli->real_escape_string($databaseName) . "' and table_name='" . $mysqli->real_escape_string($tableName) . "'";
    $result = $mysqli->query($sql);
    if (!$result) {
        return false;
    }

    $row = $result->fetch_assoc();

    return (int) ($row['total'] ?? 0) > 0;
}

function unsubscribe_repair_normalize_email($email)
{
    return mb_strtolower(trim((string) $email));
}

function unsubscribe_repair_collect_emails(array $arguments)
{
    $emails = [];

    foreach ($arguments as $argument) {
        if (strpos($argument, '--email=') === 0) {
            $emails[] = substr($argument, 8);
            continue;
        }

        if (strpos($argument, '--emails=') === 0) {
            $emails = array_merge($emails, explode(',', substr($argument, 9)));
            continue;
        }
    }

    $normalized = [];
    foreach ($emails as $email) {
        $candidate = unsubscribe_repair_normalize_email($email);
        if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
            $normalized[$candidate] = $candidate;
        }
    }

    return array_values($normalized);
}

$applyMode = false;
$showHelp = false;
$emails = unsubscribe_repair_collect_emails(array_slice($argv, 1));

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--apply') {
        $applyMode = true;
        continue;
    }

    if ($argument === '--help') {
        $showHelp = true;
        continue;
    }

    if (strpos($argument, '--email=') === 0 || strpos($argument, '--emails=') === 0) {
        continue;
    }

    unsubscribe_repair_usage();
    unsubscribe_repair_exit(2, "Unknown argument: " . $argument, true);
}

if ($showHelp) {
    unsubscribe_repair_usage();
    unsubscribe_repair_exit(0);
}

if (empty($emails)) {
    unsubscribe_repair_usage();
    unsubscribe_repair_exit(2, "At least one valid email is required.", true);
}

$crmRoot = unsubscribe_repair_find_crm_root(dirname(__DIR__, 3));
if ($crmRoot === null) {
    unsubscribe_repair_exit(2, "Perfex CRM root not found. Run this from the deployed CRM root.", true);
}

[$mysqli, $databaseName] = unsubscribe_repair_connect_db($crmRoot);

$tableName = db_prefix() . 'marketing_suppressions';
if (!unsubscribe_repair_table_exists($mysqli, $databaseName, $tableName)) {
    unsubscribe_repair_exit(2, "Suppression table is unavailable.", true);
}

$escapedEmails = [];
foreach ($emails as $email) {
    $escapedEmails[] = "'" . $mysqli->real_escape_string($email) . "'";
}
$emailListSql = implode(', ', $escapedEmails);

$selectSql = "select id, normalized_email, reason, source, active from {$tableName} where normalized_email in ({$emailListSql}) and reason='unsubscribe' and source='marketing_unsubscribe' and active=1 order by normalized_email asc, id asc";
$result = $mysqli->query($selectSql);
if (!$result) {
    unsubscribe_repair_exit(2, "Unable to query unsubscribe suppressions.", true);
}

$rows = $result->fetch_all(MYSQLI_ASSOC);
echo "False Unsubscribe Suppression Repair\n";
echo "Mode: " . ($applyMode ? 'apply' : 'dry-run') . "\n";
echo "Emails requested: " . implode(', ', $emails) . "\n";
echo "Matching active unsubscribe suppressions: " . count($rows) . "\n";

foreach ($rows as $row) {
    printf("  - id=%d email=%s source=%s reason=%s active=%s\n",
        (int) ($row['id'] ?? 0),
        (string) ($row['normalized_email'] ?? ''),
        (string) ($row['source'] ?? ''),
        (string) ($row['reason'] ?? ''),
        (string) ($row['active'] ?? '')
    );
}

if (!$applyMode) {
    echo "Dry run only. Re-run with --apply to deactivate the matching suppressions.\n";
    unsubscribe_repair_exit(0);
}

if (empty($rows)) {
    echo "No matching active unsubscribe suppressions were found.\n";
    unsubscribe_repair_exit(0);
}

$ids = array_values(array_filter(array_map('intval', array_column($rows, 'id'))));
if (empty($ids)) {
    unsubscribe_repair_exit(2, "No valid suppression IDs were found to update.", true);
}

$updateSql = "update {$tableName} set active=0";
if ($mysqli->query("show columns from {$tableName} like 'dateupdated'") instanceof mysqli_result) {
    $updateSql .= ", dateupdated='" . $mysqli->real_escape_string(date('Y-m-d H:i:s')) . "'";
}
$updateSql .= " where id in (" . implode(', ', $ids) . ") and reason='unsubscribe' and source='marketing_unsubscribe' and active=1";

if ($mysqli->query($updateSql) !== true) {
    unsubscribe_repair_exit(2, "Failed to deactivate unsubscribe suppressions.", true);
}

echo "Deactivated unsubscribe suppressions: " . (int) $mysqli->affected_rows . "\n";
unsubscribe_repair_exit(0);
