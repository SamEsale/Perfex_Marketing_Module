<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Marketing_integrity_model extends App_Model
{
    const MAX_SAMPLE_LIMIT = 10;
    const PROCESSING_STALE_TIMEOUT_SECONDS = 1800;

    protected $campaignsTable;
    protected $recipientsTable;
    protected $eventsTable;
    protected $conversationsTable;
    protected $messagesTable;

    public function __construct()
    {
        parent::__construct();

        $this->campaignsTable = db_prefix() . 'marketing_campaigns';
        $this->recipientsTable = db_prefix() . 'marketing_campaign_recipients';
        $this->eventsTable = db_prefix() . 'marketing_events';
        $this->conversationsTable = db_prefix() . 'marketing_conversations';
        $this->messagesTable = db_prefix() . 'marketing_messages';
    }

    public function get_integrity_diagnostics($sampleLimit = 5)
    {
        $sampleLimit = max(1, min(self::MAX_SAMPLE_LIMIT, (int) $sampleLimit));
        $categories = [
            $this->check_orphaned_event_recipient_refs($sampleLimit),
            $this->check_duplicate_provider_event_identities($sampleLimit),
            $this->check_reconciliation_skipped_events($sampleLimit),
            $this->check_recovered_failed_recipient_events($sampleLimit),
            $this->check_invalid_recipient_statuses($sampleLimit),
            $this->check_processing_rows_missing_token($sampleLimit),
            $this->check_stale_processing_rows($sampleLimit),
            $this->check_queued_rows_with_attempt_token($sampleLimit),
            $this->check_suppressed_or_skipped_with_engagement($sampleLimit),
            $this->check_failed_rows_with_positive_provider_evidence($sampleLimit),
            $this->check_sending_campaigns_with_no_active_recipients($sampleLimit),
            $this->check_terminal_campaigns_with_active_recipients($sampleLimit),
            $this->check_stale_worker_leases($sampleLimit),
            $this->check_overdue_scheduled_campaigns($sampleLimit),
            $this->check_inbound_messages_missing_channel($sampleLimit),
            $this->check_inbound_messages_missing_provider($sampleLimit),
            $this->check_inbound_messages_missing_dedupe_key($sampleLimit),
            $this->check_duplicate_inbound_provider_message_ids($sampleLimit),
            $this->check_email_reply_conversations_missing_channel($sampleLimit),
            $this->check_email_reply_conversations_missing_provider($sampleLimit),
            $this->check_schema_drift_snapshot($sampleLimit),
        ];

        $criticalCount = 0;
        $warningCount = 0;
        $topCategories = [];

        foreach ($categories as $category) {
            $count = (int) ($category['count'] ?? 0);
            if ($count <= 0) {
                continue;
            }

            if (($category['severity'] ?? 'warning') === 'critical') {
                $criticalCount += $count;
            } else {
                $warningCount += $count;
            }

            $topCategories[] = [
                'key' => (string) ($category['key'] ?? ''),
                'title' => (string) ($category['title'] ?? ''),
                'severity' => (string) ($category['severity'] ?? 'warning'),
                'count' => $count,
            ];
        }

        usort($topCategories, static function ($left, $right) {
            return (int) ($right['count'] ?? 0) <=> (int) ($left['count'] ?? 0);
        });

        return [
            'generated_at' => gmdate('c'),
            'available' => true,
            'critical_count' => $criticalCount,
            'warning_count' => $warningCount,
            'categories' => $categories,
            'top_categories' => array_slice($topCategories, 0, 5),
        ];
    }

    protected function category($key, $title, $severity, $count, array $samples = [], array $summary = [])
    {
        return [
            'key' => $key,
            'title' => $title,
            'severity' => $severity,
            'count' => max(0, (int) $count),
            'samples' => array_values($samples),
            'summary' => $summary,
        ];
    }

    protected function fetch_count($sql)
    {
        $query = $this->db->query($sql);
        if ($query === false) {
            return 0;
        }

        $row = $query->row_array();

        return (int) ($row['total'] ?? 0);
    }

    protected function fetch_rows($sql, $limit = 0)
    {
        if ($limit > 0) {
            $sql .= ' LIMIT ' . (int) $limit;
        }

        $query = $this->db->query($sql);
        if ($query === false) {
            return [];
        }

        return $query->result_array();
    }

    protected function table_exists($table)
    {
        return $this->db->table_exists($table);
    }

    protected function field_exists($table, $field)
    {
        return $this->db->field_exists($field, $table);
    }

    protected function check_orphaned_event_recipient_refs($sampleLimit)
    {
        if (!$this->table_exists($this->eventsTable) || !$this->table_exists($this->recipientsTable) || !$this->field_exists($this->eventsTable, 'campaign_recipient_id')) {
            return $this->category('orphaned_event_recipient_refs', 'Orphaned Event Recipient Refs', 'critical', 0);
        }

        $where = " FROM {$this->eventsTable} e LEFT JOIN {$this->recipientsTable} r ON r.id = e.campaign_recipient_id WHERE e.campaign_recipient_id IS NOT NULL AND r.id IS NULL";
        $count = $this->fetch_count('SELECT COUNT(*) AS total' . $where);
        $rows = $this->fetch_rows('SELECT e.id, e.event_type, e.campaign_id, e.campaign_recipient_id, e.provider, e.event_at' . $where . ' ORDER BY e.id ASC', $sampleLimit);

        return $this->category('orphaned_event_recipient_refs', 'Orphaned Event Recipient Refs', 'critical', $count, $rows);
    }

    protected function check_duplicate_provider_event_identities($sampleLimit)
    {
        if (!$this->table_exists($this->eventsTable) || !$this->field_exists($this->eventsTable, 'provider_event_id')) {
            return $this->category('duplicate_provider_event_identities', 'Duplicate Provider Event Identities', 'warning', 0);
        }

        $groupSql = " FROM {$this->eventsTable} WHERE provider_event_id IS NOT NULL AND provider_event_id != '' GROUP BY provider, provider_event_id HAVING COUNT(*) > 1";
        $count = $this->fetch_count('SELECT COUNT(*) AS total FROM (' . 'SELECT provider, provider_event_id' . $groupSql . ') dup');
        $rows = $this->fetch_rows('SELECT provider, provider_event_id, COUNT(*) AS total' . $groupSql . ' ORDER BY total DESC, provider_event_id ASC', $sampleLimit);

        return $this->category('duplicate_provider_event_identities', 'Duplicate Provider Event Identities', 'warning', $count, $rows);
    }

    protected function check_reconciliation_skipped_events($sampleLimit)
    {
        if (!$this->table_exists($this->eventsTable) || !$this->field_exists($this->eventsTable, 'payload_json')) {
            return $this->category('reconciliation_skipped_events', 'Reconciliation Skipped Events', 'warning', 0);
        }

        $where = " FROM {$this->eventsTable} WHERE payload_json LIKE '%reconciliation_skipped%'";
        $count = $this->fetch_count('SELECT COUNT(*) AS total' . $where);
        $rows = $this->fetch_rows('SELECT id, event_type, provider, provider_message_id, event_at, payload_json' . $where . ' ORDER BY id DESC', $sampleLimit);
        $samples = [];
        $reasonCounts = [];

        foreach ($rows as $row) {
            $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
            $reason = '';
            if (is_array($payload)) {
                $reason = trim((string) ($payload['_recipient_reconciliation']['reason'] ?? ($payload['reason'] ?? '')));
            }
            if ($reason !== '') {
                $reasonCounts[$reason] = ($reasonCounts[$reason] ?? 0) + 1;
            }

            $samples[] = [
                'id' => (int) ($row['id'] ?? 0),
                'event_type' => (string) ($row['event_type'] ?? ''),
                'provider' => (string) ($row['provider'] ?? ''),
                'reason' => $reason !== '' ? $reason : 'unknown',
                'event_at' => (string) ($row['event_at'] ?? ''),
            ];
        }

        arsort($reasonCounts);

        return $this->category('reconciliation_skipped_events', 'Reconciliation Skipped Events', 'warning', $count, $samples, [
            'reasons' => array_slice($reasonCounts, 0, $sampleLimit, true),
        ]);
    }

    protected function check_recovered_failed_recipient_events($sampleLimit)
    {
        if (!$this->table_exists($this->eventsTable) || !$this->field_exists($this->eventsTable, 'payload_json')) {
            return $this->category('recovered_failed_recipient_events', 'Recovered Failed Recipient Events', 'warning', 0);
        }

        $where = " FROM {$this->eventsTable} WHERE payload_json LIKE '%provider_event_recovered_failed_recipient%'";
        $count = $this->fetch_count('SELECT COUNT(*) AS total' . $where);
        $rows = $this->fetch_rows('SELECT id, event_type, provider, campaign_recipient_id, provider_message_id, event_at' . $where . ' ORDER BY id DESC', $sampleLimit);

        return $this->category('recovered_failed_recipient_events', 'Recovered Failed Recipient Events', 'warning', $count, $rows);
    }

    protected function check_invalid_recipient_statuses($sampleLimit)
    {
        if (!$this->table_exists($this->recipientsTable) || !$this->field_exists($this->recipientsTable, 'status')) {
            return $this->category('invalid_recipient_statuses', 'Invalid Recipient Status Values', 'critical', 0);
        }

        $knownStatuses = "'queued','processing','sent','failed','suppressed','skipped'";
        $where = " FROM {$this->recipientsTable} WHERE status NOT IN ({$knownStatuses})";
        $count = $this->fetch_count('SELECT COUNT(*) AS total' . $where);
        $rows = $this->fetch_rows('SELECT id, campaign_id, status' . $where . ' ORDER BY id ASC', $sampleLimit);

        return $this->category('invalid_recipient_statuses', 'Invalid Recipient Status Values', 'critical', $count, $rows);
    }

    protected function check_processing_rows_missing_token($sampleLimit)
    {
        if (!$this->table_exists($this->recipientsTable) || !$this->field_exists($this->recipientsTable, 'status') || !$this->field_exists($this->recipientsTable, 'processing_token')) {
            return $this->category('processing_rows_missing_token', 'Processing Rows Missing Token', 'critical', 0);
        }

        $where = " FROM {$this->recipientsTable} WHERE status = 'processing' AND (processing_token IS NULL OR processing_token = '')";
        $count = $this->fetch_count('SELECT COUNT(*) AS total' . $where);
        $rows = $this->fetch_rows('SELECT id, campaign_id, status, dateupdated' . $where . ' ORDER BY id ASC', $sampleLimit);

        return $this->category('processing_rows_missing_token', 'Processing Rows Missing Token', 'critical', $count, $rows);
    }

    protected function check_stale_processing_rows($sampleLimit)
    {
        if (!$this->table_exists($this->recipientsTable) || !$this->field_exists($this->recipientsTable, 'status') || !$this->field_exists($this->recipientsTable, 'dateupdated')) {
            return $this->category('stale_processing_rows', 'Stale Processing Rows', 'warning', 0);
        }

        $cutoff = $this->db->escape(date('Y-m-d H:i:s', time() - self::PROCESSING_STALE_TIMEOUT_SECONDS));
        $where = " FROM {$this->recipientsTable} WHERE status = 'processing' AND dateupdated IS NOT NULL AND dateupdated < {$cutoff}";
        $count = $this->fetch_count('SELECT COUNT(*) AS total' . $where);
        $rows = $this->fetch_rows('SELECT id, campaign_id, processing_token, dateupdated' . $where . ' ORDER BY dateupdated ASC', $sampleLimit);

        return $this->category('stale_processing_rows', 'Stale Processing Rows', 'warning', $count, $rows);
    }

    protected function check_queued_rows_with_attempt_token($sampleLimit)
    {
        if (!$this->table_exists($this->recipientsTable) || !$this->field_exists($this->recipientsTable, 'status') || !$this->field_exists($this->recipientsTable, 'outbound_attempt_token')) {
            return $this->category('queued_rows_with_attempt_token', 'Queued Rows With Attempt Token', 'warning', 0);
        }

        $where = " FROM {$this->recipientsTable} WHERE status = 'queued' AND outbound_attempt_token IS NOT NULL AND outbound_attempt_token != ''";
        $count = $this->fetch_count('SELECT COUNT(*) AS total' . $where);
        $rows = $this->fetch_rows('SELECT id, campaign_id, outbound_attempt_token, dateupdated' . $where . ' ORDER BY id ASC', $sampleLimit);

        return $this->category('queued_rows_with_attempt_token', 'Queued Rows With Attempt Token', 'warning', $count, $rows);
    }

    protected function check_suppressed_or_skipped_with_engagement($sampleLimit)
    {
        if (!$this->table_exists($this->recipientsTable) || !$this->table_exists($this->eventsTable)) {
            return $this->category('suppressed_or_skipped_with_engagement', 'Suppressed Or Skipped With Engagement Evidence', 'warning', 0);
        }

        $where = " FROM {$this->recipientsTable} r JOIN {$this->eventsTable} e ON e.campaign_recipient_id = r.id WHERE r.status IN ('suppressed','skipped') AND e.event_type IN ('delivered','open','click')";
        $count = $this->fetch_count('SELECT COUNT(DISTINCT r.id) AS total' . $where);
        $rows = $this->fetch_rows('SELECT r.id, r.campaign_id, r.status, MAX(e.event_type) AS event_type, MAX(e.event_at) AS event_at' . $where . ' GROUP BY r.id, r.campaign_id, r.status ORDER BY MAX(e.event_at) DESC', $sampleLimit);

        return $this->category('suppressed_or_skipped_with_engagement', 'Suppressed Or Skipped With Engagement Evidence', 'warning', $count, $rows);
    }

    protected function check_failed_rows_with_positive_provider_evidence($sampleLimit)
    {
        if (!$this->table_exists($this->recipientsTable) || !$this->table_exists($this->eventsTable)) {
            return $this->category('failed_rows_with_positive_provider_evidence', 'Failed Rows With Positive Provider Evidence', 'warning', 0);
        }

        $where = " FROM {$this->recipientsTable} r JOIN {$this->eventsTable} e ON e.campaign_recipient_id = r.id WHERE r.status = 'failed' AND e.event_type IN ('delivered','open','click')";
        $count = $this->fetch_count('SELECT COUNT(DISTINCT r.id) AS total' . $where);
        $rows = $this->fetch_rows('SELECT r.id, r.campaign_id, r.status, MAX(e.event_type) AS event_type, MAX(e.event_at) AS event_at' . $where . ' GROUP BY r.id, r.campaign_id, r.status ORDER BY MAX(e.event_at) DESC', $sampleLimit);

        return $this->category('failed_rows_with_positive_provider_evidence', 'Failed Rows With Positive Provider Evidence', 'warning', $count, $rows);
    }

    protected function check_sending_campaigns_with_no_active_recipients($sampleLimit)
    {
        if (!$this->table_exists($this->campaignsTable) || !$this->table_exists($this->recipientsTable)) {
            return $this->category('sending_campaigns_with_no_active_recipients', 'Sending Campaigns With No Active Recipients', 'warning', 0);
        }

        $sql = "SELECT c.id, c.name, c.status, c.send_started_at,
            SUM(CASE WHEN r.status = 'queued' THEN 1 ELSE 0 END) AS queued_count,
            SUM(CASE WHEN r.status = 'processing' THEN 1 ELSE 0 END) AS processing_count
            FROM {$this->campaignsTable} c
            LEFT JOIN {$this->recipientsTable} r ON r.campaign_id = c.id
            WHERE c.status = 'sending'
            GROUP BY c.id, c.name, c.status, c.send_started_at
            HAVING queued_count = 0 AND processing_count = 0";
        $count = $this->fetch_count('SELECT COUNT(*) AS total FROM (' . $sql . ') stuck');
        $rows = $this->fetch_rows($sql . ' ORDER BY c.id ASC', $sampleLimit);

        return $this->category('sending_campaigns_with_no_active_recipients', 'Sending Campaigns With No Active Recipients', 'warning', $count, $rows);
    }

    protected function check_terminal_campaigns_with_active_recipients($sampleLimit)
    {
        if (!$this->table_exists($this->campaignsTable) || !$this->table_exists($this->recipientsTable)) {
            return $this->category('terminal_campaigns_with_active_recipients', 'Terminal Campaigns With Active Recipients', 'warning', 0);
        }

        $sql = "SELECT c.id, c.name, c.status, COUNT(*) AS active_recipient_count
            FROM {$this->campaignsTable} c
            JOIN {$this->recipientsTable} r ON r.campaign_id = c.id
            WHERE c.status IN ('draft','sent','completed','cancelled','failed') AND r.status IN ('queued','processing')
            GROUP BY c.id, c.name, c.status
            HAVING active_recipient_count > 0";
        $count = $this->fetch_count('SELECT COUNT(*) AS total FROM (' . $sql . ') terminal_rows');
        $rows = $this->fetch_rows($sql . ' ORDER BY active_recipient_count DESC, c.id ASC', $sampleLimit);

        return $this->category('terminal_campaigns_with_active_recipients', 'Terminal Campaigns With Active Recipients', 'warning', $count, $rows);
    }

    protected function check_stale_worker_leases($sampleLimit)
    {
        if (
            !$this->table_exists($this->campaignsTable)
            || !$this->field_exists($this->campaignsTable, 'queue_worker_token')
            || !$this->field_exists($this->campaignsTable, 'queue_worker_heartbeat_at')
        ) {
            return $this->category('stale_worker_leases', 'Stale Worker Leases', 'warning', 0);
        }

        $cutoff = $this->db->escape(date('Y-m-d H:i:s', time() - self::PROCESSING_STALE_TIMEOUT_SECONDS));
        $where = " FROM {$this->campaignsTable} WHERE queue_worker_token IS NOT NULL AND queue_worker_token != '' AND queue_worker_heartbeat_at IS NOT NULL AND queue_worker_heartbeat_at < {$cutoff}";
        $count = $this->fetch_count('SELECT COUNT(*) AS total' . $where);
        $rows = $this->fetch_rows('SELECT id, name, status, queue_worker_started_at, queue_worker_heartbeat_at' . $where . ' ORDER BY queue_worker_heartbeat_at ASC', $sampleLimit);

        return $this->category('stale_worker_leases', 'Stale Worker Leases', 'warning', $count, $rows);
    }

    protected function check_overdue_scheduled_campaigns($sampleLimit)
    {
        if (!$this->table_exists($this->campaignsTable) || !$this->field_exists($this->campaignsTable, 'scheduled_at') || !$this->field_exists($this->campaignsTable, 'status')) {
            return $this->category('overdue_scheduled_campaigns', 'Overdue Scheduled Campaigns', 'warning', 0);
        }

        $cutoff = $this->db->escape(date('Y-m-d H:i:s', time() - 600));
        $where = " FROM {$this->campaignsTable} WHERE status = 'scheduled' AND scheduled_at IS NOT NULL AND scheduled_at < {$cutoff}";
        $count = $this->fetch_count('SELECT COUNT(*) AS total' . $where);
        $rows = $this->fetch_rows('SELECT id, name, status, scheduled_at' . $where . ' ORDER BY scheduled_at ASC', $sampleLimit);

        return $this->category('overdue_scheduled_campaigns', 'Overdue Scheduled Campaigns', 'warning', $count, $rows);
    }

    protected function check_inbound_messages_missing_channel($sampleLimit)
    {
        return $this->check_inbound_message_field_gap('channel', 'inbound_messages_missing_channel', 'Inbound Email Messages Missing Channel', 'critical', $sampleLimit);
    }

    protected function check_inbound_messages_missing_provider($sampleLimit)
    {
        return $this->check_inbound_message_field_gap('provider', 'inbound_messages_missing_provider', 'Inbound Email Messages Missing Provider', 'critical', $sampleLimit);
    }

    protected function check_inbound_messages_missing_dedupe_key($sampleLimit)
    {
        return $this->check_inbound_message_field_gap('dedupe_key', 'inbound_messages_missing_dedupe_key', 'Inbound Email Messages Missing Dedupe Key', 'critical', $sampleLimit);
    }

    protected function check_inbound_message_field_gap($field, $key, $title, $severity, $sampleLimit)
    {
        if (!$this->table_exists($this->messagesTable) || !$this->field_exists($this->messagesTable, $field) || !$this->field_exists($this->messagesTable, 'provider')) {
            return $this->category($key, $title, $severity, 0);
        }

        $where = " FROM {$this->messagesTable} WHERE provider = 'inbound_email' AND ({$field} IS NULL OR {$field} = '')";
        $count = $this->fetch_count('SELECT COUNT(*) AS total' . $where);
        $rows = $this->fetch_rows('SELECT id, conversation_id, provider_message_id, email, datecreated' . $where . ' ORDER BY id ASC', $sampleLimit);

        return $this->category($key, $title, $severity, $count, $rows);
    }

    protected function check_duplicate_inbound_provider_message_ids($sampleLimit)
    {
        if (!$this->table_exists($this->messagesTable) || !$this->field_exists($this->messagesTable, 'provider_message_id')) {
            return $this->category('duplicate_inbound_provider_message_ids', 'Duplicate Inbound Provider Message IDs', 'critical', 0);
        }

        $groupSql = " FROM {$this->messagesTable} WHERE provider = 'inbound_email' AND provider_message_id IS NOT NULL AND provider_message_id != '' GROUP BY provider_message_id HAVING COUNT(*) > 1";
        $count = $this->fetch_count('SELECT COUNT(*) AS total FROM (' . 'SELECT provider_message_id' . $groupSql . ') dup');
        $rows = $this->fetch_rows('SELECT provider_message_id, COUNT(*) AS total' . $groupSql . ' ORDER BY total DESC, provider_message_id ASC', $sampleLimit);

        return $this->category('duplicate_inbound_provider_message_ids', 'Duplicate Inbound Provider Message IDs', 'critical', $count, $rows);
    }

    protected function check_email_reply_conversations_missing_channel($sampleLimit)
    {
        return $this->check_email_reply_conversation_field_gap('channel', 'email_reply_conversations_missing_channel', 'Email Reply Conversations Missing Channel', 'critical', $sampleLimit);
    }

    protected function check_email_reply_conversations_missing_provider($sampleLimit)
    {
        return $this->check_email_reply_conversation_field_gap('provider', 'email_reply_conversations_missing_provider', 'Email Reply Conversations Missing Provider', 'critical', $sampleLimit);
    }

    protected function check_email_reply_conversation_field_gap($field, $key, $title, $severity, $sampleLimit)
    {
        if (!$this->table_exists($this->conversationsTable) || !$this->field_exists($this->conversationsTable, 'source_type') || !$this->field_exists($this->conversationsTable, $field)) {
            return $this->category($key, $title, $severity, 0);
        }

        $where = " FROM {$this->conversationsTable} WHERE source_type = 'email_reply' AND ({$field} IS NULL OR {$field} = '')";
        $count = $this->fetch_count('SELECT COUNT(*) AS total' . $where);
        $rows = $this->fetch_rows('SELECT id, campaign_id, marketing_contact_id, email, unread_count, datecreated' . $where . ' ORDER BY id ASC', $sampleLimit);

        return $this->category($key, $title, $severity, $count, $rows);
    }

    protected function check_schema_drift_snapshot($sampleLimit)
    {
        if (!defined('APP_DB_NAME')) {
            return $this->category('schema_drift_snapshot', 'Schema Drift Snapshot', 'warning', 0);
        }

        $databaseName = $this->db->escape(APP_DB_NAME);
        $expected = [
            $this->conversationsTable => [
                'channel' => ['nullable' => true],
                'provider' => ['nullable' => true],
            ],
            $this->messagesTable => [
                'channel' => ['nullable' => true],
                'provider' => ['nullable' => true],
                'dedupe_key' => ['nullable' => true],
            ],
            $this->recipientsTable => [
                'processing_token' => ['nullable' => true],
                'outbound_attempt_token' => ['nullable' => true],
            ],
            $this->campaignsTable => [
                'queue_worker_token' => ['nullable' => true],
                'queue_worker_heartbeat_at' => ['nullable' => true],
            ],
        ];

        $rows = $this->fetch_rows(
            "SELECT TABLE_NAME, COLUMN_NAME, IS_NULLABLE
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = {$databaseName}
               AND TABLE_NAME IN ('{$this->conversationsTable}','{$this->messagesTable}','{$this->recipientsTable}','{$this->campaignsTable}')
               AND COLUMN_NAME IN ('channel','provider','dedupe_key','processing_token','outbound_attempt_token','queue_worker_token','queue_worker_heartbeat_at')"
        );

        $actual = [];
        foreach ($rows as $row) {
            $actual[(string) $row['TABLE_NAME']][(string) $row['COLUMN_NAME']] = (string) $row['IS_NULLABLE'];
        }

        $samples = [];
        foreach ($expected as $table => $columns) {
            foreach ($columns as $column => $rules) {
                $actualNullability = $actual[$table][$column] ?? 'missing';
                $expectedNullability = !empty($rules['nullable']) ? 'YES' : 'NO';
                if ($actualNullability !== $expectedNullability) {
                    $samples[] = [
                        'table' => $table,
                        'column' => $column,
                        'expected' => $expectedNullability,
                        'actual' => $actualNullability,
                    ];
                }
            }
        }

        $indexRows = $this->fetch_rows(
            "SELECT TABLE_NAME, INDEX_NAME
             FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = {$databaseName}
               AND TABLE_NAME = '{$this->messagesTable}'
               AND INDEX_NAME = 'dedupe_key'"
        );
        if (empty($indexRows)) {
            $samples[] = [
                'table' => $this->messagesTable,
                'column' => 'dedupe_key',
                'expected' => 'INDEX',
                'actual' => 'missing',
            ];
        }

        return $this->category('schema_drift_snapshot', 'Schema Drift Snapshot', 'warning', count($samples), array_slice($samples, 0, $sampleLimit));
    }
}
