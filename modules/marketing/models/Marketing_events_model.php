<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Marketing_events_model extends App_Model
{
    const TABLE = 'marketing_events';
    const WEBHOOK_LOGS_TABLE = 'marketing_webhook_logs';
    const EVENT_LOCK_TIMEOUT = 5;
    const MIN_RETENTION_DAYS = 30;
    const MAX_DELETE_PER_RUN = 500;
    const DEFAULT_RETENTION_DAYS = 180;

    protected $table;
    protected $webhookLogTable;
    protected $tableColumns;
    protected $marketingPublicLinksModel;
    protected $eventAliases = [
        'sent' => ['sent'],
        'provider_send' => ['provider_send'],
        'delivered' => ['delivered'],
        'open' => ['open', 'opened'],
        'click' => ['click', 'clicked'],
        'bounce' => ['bounce', 'bounced'],
        'complaint' => ['complaint', 'complained'],
        'rejected' => ['rejected'],
        'rendering_failed' => ['rendering_failed', 'rendering_failure', 'renderingfailure'],
        'delivery_delay' => ['delivery_delay', 'deliverydelay'],
        'unsubscribe' => ['unsubscribe', 'unsubscribed'],
        'failed' => ['failed'],
        'send_readiness_failed' => ['send_readiness_failed'],
        'queued' => ['queued'],
        'send_start' => ['send_start'],
        'batch_processed' => ['batch_processed'],
        'subscription' => ['subscription'],
        'subscription_confirmation' => ['subscription_confirmation'],
        'notification' => ['notification'],
    ];

    public function __construct()
    {
        parent::__construct();

        $this->table = db_prefix() . self::TABLE;
        $this->webhookLogTable = db_prefix() . self::WEBHOOK_LOGS_TABLE;
    }

    public function count_all()
    {
        if (!$this->db->table_exists($this->table)) {
            return 0;
        }

        return $this->db->count_all($this->table);
    }

    public function exists($id)
    {
        return total_rows($this->table, ['id' => $id]) > 0;
    }

    public function get_recent($limit = 10)
    {
        if (!$this->db->table_exists($this->table)) {
            return [];
        }

        $this->db->order_by('id', 'DESC');

        return $this->db->get($this->table, $limit)->result_array();
    }

    public function get_events_paginated($limit, $offset)
    {
        $limit = max(1, (int) $limit);
        $offset = max(0, (int) $offset);

        if (!$this->db->table_exists($this->table)) {
            return [];
        }

        $select = [
            $this->column_exists('id') ? 'id' : '0 AS id',
            $this->column_exists('event_type') ? 'event_type' : "'' AS event_type",
            $this->column_exists('email') ? 'email' : "'' AS email",
            $this->column_exists('provider') ? 'provider' : "'' AS provider",
            $this->column_exists('url') ? 'url' : "'' AS url",
            $this->column_exists('event_at') ? 'event_at' : 'NULL AS event_at',
        ];

        $this->db->select(implode(', ', $select), false)
            ->from($this->table);

        if ($this->column_exists('event_at')) {
            $this->db->order_by('event_at', 'DESC');
        } elseif ($this->column_exists('id')) {
            $this->db->order_by('id', 'DESC');
        }

        $query = $this->db
            ->limit($limit, $offset)
            ->get();

        return $query ? $query->result_array() : [];
    }

    public function count_all_events()
    {
        if (!$this->db->table_exists($this->table)) {
            return 0;
        }

        return (int) $this->db->count_all($this->table);
    }

    public function summary_counts()
    {
        $summary = [];
        foreach (['queued', 'sent', 'delivered', 'open', 'click', 'bounce', 'complaint', 'unsubscribe', 'failed'] as $type) {
            $summary[$type] = $this->count_events_by_normalized_types([$type]);
        }

        return $summary;
    }

    public function normalize_event_type($eventType)
    {
        $normalized = strtolower(trim((string) $eventType));
        $normalized = str_replace([' ', '-'], '_', $normalized);

        foreach ($this->eventAliases as $canonical => $aliases) {
            if (in_array($normalized, $aliases, true) || $canonical === $normalized) {
                return $canonical;
            }
        }

        return $normalized;
    }

    public function get_event_type_aliases($eventType)
    {
        $normalized = $this->normalize_event_type($eventType);
        return $this->eventAliases[$normalized] ?? [$normalized];
    }

    public function event_exists(array $criteria)
    {
        if (!$this->db->table_exists($this->table)) {
            return false;
        }

        $criteria = $this->build_event_dedupe_criteria($criteria);
        if (!$this->has_stable_dedupe_criteria($criteria)) {
            return false;
        }

        $this->apply_event_match_filters($criteria);

        return (int) $this->db->count_all_results() > 0;
    }

    public function count_events_by_normalized_types(array $eventTypes, $campaignId = null)
    {
        if (!$this->db->table_exists($this->table) || !$this->column_exists('event_type')) {
            return 0;
        }

        $aliases = [];
        foreach ($eventTypes as $eventType) {
            $aliases = array_merge($aliases, $this->get_event_type_aliases($eventType));
        }

        $aliases = array_values(array_unique(array_filter($aliases)));
        if (empty($aliases)) {
            return 0;
        }

        $this->db->from($this->table);
        $this->apply_normalized_event_type_filter($aliases);
        if ($campaignId !== null && $this->column_exists('campaign_id')) {
            $this->db->where('campaign_id', (int) $campaignId);
        } elseif ($campaignId !== null) {
            return 0;
        }

        return (int) $this->db->count_all_results();
    }

    public function get_normalized_summary($campaignId = null)
    {
        if (!$this->db->table_exists($this->table)) {
            return [
                'total_records' => 0,
                'accepted_sent' => 0,
                'delivered' => 0,
                'open' => 0,
                'click' => 0,
                'bounce' => 0,
                'complaint' => 0,
                'failed_rejected' => 0,
                'send_readiness_failed' => 0,
                'unsubscribe' => 0,
                'delivery_delay' => 0,
                'send_start' => 0,
                'batch_processed' => 0,
            ];
        }

        return [
            'total_records' => $campaignId === null
                ? $this->count_all_events()
                : ($this->column_exists('campaign_id')
                    ? (int) $this->db->from($this->table)->where('campaign_id', (int) $campaignId)->count_all_results()
                    : 0),
            'accepted_sent' => $this->count_events_by_normalized_types(['sent'], $campaignId),
            'delivered' => $this->count_events_by_normalized_types(['delivered'], $campaignId),
            'open' => $this->count_events_by_normalized_types(['open'], $campaignId),
            'click' => $this->count_events_by_normalized_types(['click'], $campaignId),
            'bounce' => $this->count_events_by_normalized_types(['bounce'], $campaignId),
            'complaint' => $this->count_events_by_normalized_types(['complaint'], $campaignId),
            'failed_rejected' => $this->count_events_by_normalized_types(['failed', 'rejected', 'rendering_failed'], $campaignId),
            'send_readiness_failed' => $this->count_events_by_normalized_types(['send_readiness_failed'], $campaignId),
            'unsubscribe' => $this->count_events_by_normalized_types(['unsubscribe'], $campaignId),
            'delivery_delay' => $this->count_events_by_normalized_types(['delivery_delay'], $campaignId),
            'send_start' => $this->count_events_by_normalized_types(['send_start'], $campaignId),
            'batch_processed' => $this->count_events_by_normalized_types(['batch_processed'], $campaignId),
        ];
    }

    public function has_recent_module_click(array $criteria, $windowSeconds = 120)
    {
        $windowSeconds = max(1, (int) $windowSeconds);
        $recipientId = (int) ($criteria['campaign_recipient_id'] ?? 0);
        $trackingLinkId = (int) ($criteria['tracking_link_id'] ?? 0);
        $url = trim((string) ($criteria['url'] ?? ''));
        $eventAt = trim((string) ($criteria['event_at'] ?? ''));

        if ($recipientId <= 0 || ($trackingLinkId <= 0 && $url === '')) {
            return false;
        }

        if (
            !$this->db->table_exists($this->table)
            || !$this->column_exists('provider')
            || !$this->column_exists('campaign_recipient_id')
            || !$this->column_exists('event_at')
        ) {
            return false;
        }

        $threshold = strtotime($eventAt !== '' ? $eventAt : 'now');
        if ($threshold === false) {
            $threshold = time();
        }

        $payloadSelect = $this->column_exists('payload_json') ? 'payload_json' : 'NULL AS payload_json';
        $urlSelect = $this->column_exists('url') ? 'url' : 'NULL AS url';

        $rows = $this->db->select($urlSelect . ', ' . $payloadSelect, false)
            ->from($this->table)
            ->where('provider', 'module')
            ->where('campaign_recipient_id', $recipientId)
            ->where('event_at >=', date('Y-m-d H:i:s', $threshold - $windowSeconds))
            ->order_by('id', 'DESC')
            ->limit(25);

        $this->apply_normalized_event_type_filter($this->get_event_type_aliases('click'));

        $rows = $this->db->get()->result_array();

        foreach ($rows as $row) {
            $rowUrl = trim((string) ($row['url'] ?? ''));
            $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
            if (!is_array($payload)) {
                if ($url !== '' && $rowUrl === $url) {
                    return true;
                }

                continue;
            }

            if (!empty($payload['is_bot']) || empty($payload['counted_metric'])) {
                continue;
            }

            $payloadTrackingLinkId = (int) ($payload['tracking_link_id'] ?? 0);
            if ($trackingLinkId > 0 && $payloadTrackingLinkId > 0 && $payloadTrackingLinkId === $trackingLinkId) {
                return true;
            }

            $payloadOriginalUrl = trim((string) ($payload['original_url'] ?? ''));
            if ($url !== '' && ($rowUrl === $url || $payloadOriginalUrl === $url)) {
                return true;
            }
        }

        return false;
    }

    public function has_recent_module_click_metric($recipientId, $url, $eventAt = null, $windowSeconds = 900)
    {
        $recipientId = (int) $recipientId;
        $url = trim((string) $url);
        $windowSeconds = max(1, (int) $windowSeconds);

        if ($recipientId <= 0) {
            return false;
        }

        if (
            !$this->db->table_exists($this->table)
            || !$this->column_exists('provider')
            || !$this->column_exists('campaign_recipient_id')
            || !$this->column_exists('event_at')
        ) {
            return false;
        }

        $threshold = strtotime($eventAt ? (string) $eventAt : 'now');
        if ($threshold === false) {
            $threshold = time();
        }

        $payloadSelect = $this->column_exists('payload_json') ? 'payload_json' : 'NULL AS payload_json';

        $this->db->select($payloadSelect, false)
            ->from($this->table)
            ->where('provider', 'module')
            ->where('campaign_recipient_id', $recipientId)
            ->where('event_at >=', date('Y-m-d H:i:s', $threshold - $windowSeconds))
            ->order_by('id', 'DESC')
            ->limit(25);

        $this->apply_normalized_event_type_filter($this->get_event_type_aliases('click'));

        if ($url !== '' && $this->column_exists('url')) {
            $this->db->where('url', $url);
        }

        $rows = $this->db->get()->result_array();
        if ($this->rows_contain_counted_human_module_click($rows)) {
            return true;
        }

        if ($url !== '') {
            $fallbackRows = $this->db->select($payloadSelect, false)
                ->from($this->table)
                ->where('provider', 'module')
                ->where('campaign_recipient_id', $recipientId)
                ->where('event_at >=', date('Y-m-d H:i:s', $threshold - min($windowSeconds, 300)))
                ->order_by('id', 'DESC')
                ->limit(5);

            $this->apply_normalized_event_type_filter($this->get_event_type_aliases('click'));

            $fallbackRows = $this->db->get()->result_array();

            return $this->rows_contain_counted_human_module_click($fallbackRows);
        }

        return false;
    }

    public function has_marketing_unsubscribe_event(array $criteria)
    {
        if (
            !$this->db->table_exists($this->table)
            || !$this->column_exists('provider')
            || !$this->column_exists('event_type')
        ) {
            return false;
        }

        $recipientId = (int) ($criteria['campaign_recipient_id'] ?? 0);
        $contactId = (int) ($criteria['marketing_contact_id'] ?? 0);
        $email = trim((string) ($criteria['email'] ?? ''));
        $publicLinkId = (int) ($criteria['public_link_id'] ?? 0);

        $this->db->from($this->table)
            ->where('provider', 'marketing_unsubscribe');
        $this->apply_normalized_event_type_filter($this->get_event_type_aliases('unsubscribe'));

        if ($recipientId > 0 && $this->column_exists('campaign_recipient_id')) {
            $this->db->where('campaign_recipient_id', $recipientId);
        } elseif ($contactId > 0 && $this->column_exists('marketing_contact_id')) {
            $this->db->where('marketing_contact_id', $contactId);
        } elseif ($email !== '' && $this->column_exists('email')) {
            $this->db->where('email', $email);
        } else {
            return false;
        }

        $rows = $this->db->select(
            ($this->column_exists('payload_json') ? 'payload_json' : 'NULL AS payload_json')
            . ', '
            . ($this->column_exists('email') ? 'email' : 'NULL AS email'),
            false
        )
            ->order_by('id', 'DESC')
            ->limit(10)
            ->get()
            ->result_array();

        foreach ($rows as $row) {
            if ($publicLinkId > 0) {
                $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
                if (is_array($payload) && (int) ($payload['public_link_id'] ?? 0) === $publicLinkId) {
                    return true;
                }
            }

            if ($email !== '' && trim((string) ($row['email'] ?? '')) === $email) {
                return true;
            }
        }

        return !empty($rows);
    }

    protected function rows_contain_counted_human_module_click(array $rows)
    {
        foreach ($rows as $row) {
            $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
            if (!is_array($payload)) {
                return true;
            }

            if (!empty($payload['counted_metric']) && empty($payload['is_bot'])) {
                return true;
            }
        }

        return false;
    }

    public function add_event(array $data)
    {
        if (!$this->db->table_exists($this->table)) {
            return false;
        }

        $defaults = [
            'campaign_id' => null,
            'campaign_recipient_id' => null,
            'marketing_contact_id' => null,
            'event_type' => null,
            'provider' => 'smtp',
            'provider_event_id' => null,
            'provider_message_id' => null,
            'sns_message_id' => null,
            'email' => null,
            'url' => null,
            'ip_address' => null,
            'user_agent' => null,
            'payload_json' => null,
            'event_at' => date('Y-m-d H:i:s'),
            'datecreated' => date('Y-m-d H:i:s'),
        ];

        $event = array_merge($defaults, $data);

        if (empty($event['event_type'])) {
            return false;
        }

        $event['event_type'] = $this->normalize_event_type($event['event_type']);
        $event = $this->filter_insertable_event($event);
        if (empty($event) || !array_key_exists('event_type', $event) || trim((string) $event['event_type']) === '') {
            return false;
        }

        $this->db->insert($this->table, $event);

        if (!$this->db->affected_rows()) {
            return false;
        }

        return $this->db->insert_id();
    }

    public function add_event_idempotent(array $data)
    {
        $criteria = $this->build_event_dedupe_criteria($data);
        if ($this->has_stable_dedupe_criteria($criteria) && $this->event_exists($criteria)) {
            return [
                'success' => true,
                'inserted' => false,
                'duplicate' => true,
            ];
        }

        $eventId = $this->add_event($data);
        if ($eventId) {
            return [
                'success' => true,
                'inserted' => true,
                'duplicate' => false,
                'id' => (int) $eventId,
            ];
        }

        if ($this->has_stable_dedupe_criteria($criteria) && $this->event_exists($criteria)) {
            return [
                'success' => true,
                'inserted' => false,
                'duplicate' => true,
            ];
        }

        return [
            'success' => false,
            'inserted' => false,
            'duplicate' => false,
            'error' => $this->get_last_db_error_message(),
        ];
    }

    public function build_event_dedupe_criteria(array $data)
    {
        $provider = trim((string) ($data['provider'] ?? ''));
        $eventType = trim((string) ($data['event_type'] ?? ''));

        return [
            'provider' => $provider,
            'event_type' => $eventType !== '' ? $this->normalize_event_type($eventType) : '',
            'provider_message_id' => trim((string) ($data['provider_message_id'] ?? ($data['message_id'] ?? ''))),
            'provider_event_id' => trim((string) ($data['provider_event_id'] ?? '')),
            'email' => trim((string) ($data['email'] ?? '')),
            'url' => trim((string) ($data['url'] ?? '')),
            'event_at' => trim((string) ($data['event_at'] ?? '')),
            'sns_message_id' => trim((string) ($data['sns_message_id'] ?? ($data['_sns_message_id'] ?? ''))),
        ];
    }

    public function has_stable_dedupe_criteria(array $criteria)
    {
        $criteria = $this->build_event_dedupe_criteria($criteria);

        foreach (['provider_message_id', 'provider_event_id', 'sns_message_id'] as $field) {
            if (!empty($criteria[$field])) {
                return true;
            }
        }

        return !empty($criteria['email']) && !empty($criteria['event_type']) && !empty($criteria['event_at']);
    }

    public function acquire_event_lock(array $criteria, $timeout = self::EVENT_LOCK_TIMEOUT)
    {
        $lockKey = $this->build_event_lock_key($criteria);
        if ($lockKey === '') {
            return [
                'acquired' => false,
                'lock_key' => '',
                'error' => 'No stable event lock key available.',
            ];
        }

        $query = $this->db->query('SELECT GET_LOCK(?, ?) AS event_lock', [$lockKey, max(0, (int) $timeout)]);
        if ($query === false) {
            return [
                'acquired' => false,
                'lock_key' => $lockKey,
                'error' => 'Event lock query failed.',
            ];
        }

        $row = $query->row_array();
        if ((string) ($row['event_lock'] ?? '0') !== '1') {
            return [
                'acquired' => false,
                'lock_key' => $lockKey,
                'error' => 'Event lock timed out.',
            ];
        }

        return [
            'acquired' => true,
            'lock_key' => $lockKey,
        ];
    }

    public function release_event_lock($lockKey)
    {
        $lockKey = trim((string) $lockKey);
        if ($lockKey === '') {
            return false;
        }

        $query = $this->db->query('SELECT RELEASE_LOCK(?) AS event_lock', [$lockKey]);
        if ($query === false) {
            return false;
        }

        $row = $query->row_array();

        return (string) ($row['event_lock'] ?? '0') === '1';
    }

    public function clear_event_history()
    {
        return false;
    }

    public function get_retention_diagnostics()
    {
        $eventRetentionDays = $this->get_retention_days('marketing_event_retention_days');
        $webhookLogRetentionDays = $this->get_retention_days('marketing_webhook_log_retention_days');

        $diagnostics = [
            'cleanup_mode' => 'explicit_only',
            'event_retention_days' => $eventRetentionDays,
            'webhook_log_retention_days' => $webhookLogRetentionDays,
            'retention_floor_days' => self::MIN_RETENTION_DAYS,
            'delete_limit_per_run' => self::MAX_DELETE_PER_RUN,
            'events_cleanup_ready' => false,
            'webhook_logs_cleanup_ready' => false,
            'click_aggregate_cleanup_ready' => false,
            'events_cleanup_candidate_preview' => 0,
            'events_cleanup_candidate_more' => false,
            'webhook_logs_cleanup_candidate_preview' => 0,
            'webhook_logs_cleanup_candidate_more' => false,
            'click_aggregate_candidate_preview' => 0,
            'click_aggregate_candidate_more' => false,
            'warnings' => [],
        ];

        $eventCutoffDateTime = $this->build_retention_cutoff_datetime($eventRetentionDays);
        if ($eventCutoffDateTime !== null && $this->can_cleanup_events()) {
            $eventCandidatePreview = $this->count_event_cleanup_candidate_preview($eventCutoffDateTime);
            $diagnostics['events_cleanup_ready'] = true;
            $diagnostics['events_cleanup_candidate_preview'] = min(self::MAX_DELETE_PER_RUN, $eventCandidatePreview);
            $diagnostics['events_cleanup_candidate_more'] = $eventCandidatePreview > self::MAX_DELETE_PER_RUN;
        } else {
            $diagnostics['warnings'][] = 'event_cleanup_unavailable';
        }

        $webhookCutoffDateTime = $this->build_retention_cutoff_datetime($webhookLogRetentionDays);
        if ($webhookCutoffDateTime !== null && $this->can_cleanup_webhook_logs()) {
            $webhookCandidatePreview = $this->count_webhook_log_cleanup_candidate_preview($webhookCutoffDateTime);
            $diagnostics['webhook_logs_cleanup_ready'] = true;
            $diagnostics['webhook_logs_cleanup_candidate_preview'] = min(self::MAX_DELETE_PER_RUN, $webhookCandidatePreview);
            $diagnostics['webhook_logs_cleanup_candidate_more'] = $webhookCandidatePreview > self::MAX_DELETE_PER_RUN;
        } else {
            $diagnostics['warnings'][] = 'webhook_log_cleanup_unavailable';
        }

        $clickAggregateDiagnostics = $this->get_click_aggregate_retention_diagnostics_summary($eventRetentionDays);
        $diagnostics['click_aggregate_cleanup_ready'] = !empty($clickAggregateDiagnostics['cleanup_ready']);
        $diagnostics['click_aggregate_candidate_preview'] = (int) ($clickAggregateDiagnostics['candidate_preview'] ?? 0);
        $diagnostics['click_aggregate_candidate_more'] = !empty($clickAggregateDiagnostics['candidate_more']);
        if (!empty($clickAggregateDiagnostics['warnings']) && is_array($clickAggregateDiagnostics['warnings'])) {
            foreach ($clickAggregateDiagnostics['warnings'] as $warning) {
                $warning = trim((string) $warning);
                if ($warning !== '') {
                    $diagnostics['warnings'][] = $warning;
                }
            }
        }

        return $diagnostics;
    }

    public function run_retention_cleanup($eventRetentionDays = null, $webhookLogRetentionDays = null)
    {
        $eventRetentionDays = $this->normalize_retention_days($eventRetentionDays, 'marketing_event_retention_days');
        $webhookLogRetentionDays = $this->normalize_retention_days($webhookLogRetentionDays, 'marketing_webhook_log_retention_days');

        $summary = [
            'cleanup_mode' => 'explicit_only',
            'event_retention_days' => $eventRetentionDays,
            'webhook_log_retention_days' => $webhookLogRetentionDays,
            'retention_floor_days' => self::MIN_RETENTION_DAYS,
            'delete_limit_per_run' => self::MAX_DELETE_PER_RUN,
            'events_deleted' => 0,
            'webhook_logs_deleted' => 0,
            'click_links_reset' => 0,
            'click_links_skipped' => 0,
            'events_cleanup_candidate_preview' => 0,
            'events_cleanup_candidate_more' => false,
            'webhook_logs_cleanup_candidate_preview' => 0,
            'webhook_logs_cleanup_candidate_more' => false,
            'click_aggregate_candidate_preview' => 0,
            'click_aggregate_candidate_more' => false,
            'warnings' => [],
        ];

        $eventCutoffDateTime = $this->build_retention_cutoff_datetime($eventRetentionDays);
        if ($eventCutoffDateTime !== null && $this->can_cleanup_events()) {
            $eventCandidatePreview = $this->count_event_cleanup_candidate_preview($eventCutoffDateTime);
            $summary['events_cleanup_candidate_preview'] = min(self::MAX_DELETE_PER_RUN, $eventCandidatePreview);
            $summary['events_cleanup_candidate_more'] = $eventCandidatePreview > self::MAX_DELETE_PER_RUN;
            $summary['events_deleted'] = $this->delete_event_cleanup_candidates($eventCutoffDateTime);
        } else {
            $summary['warnings'][] = 'event_cleanup_unavailable';
        }

        $webhookCutoffDateTime = $this->build_retention_cutoff_datetime($webhookLogRetentionDays);
        if ($webhookCutoffDateTime !== null && $this->can_cleanup_webhook_logs()) {
            $webhookCandidatePreview = $this->count_webhook_log_cleanup_candidate_preview($webhookCutoffDateTime);
            $summary['webhook_logs_cleanup_candidate_preview'] = min(self::MAX_DELETE_PER_RUN, $webhookCandidatePreview);
            $summary['webhook_logs_cleanup_candidate_more'] = $webhookCandidatePreview > self::MAX_DELETE_PER_RUN;
            $summary['webhook_logs_deleted'] = $this->delete_webhook_log_cleanup_candidates($webhookCutoffDateTime);
        } else {
            $summary['warnings'][] = 'webhook_log_cleanup_unavailable';
        }

        $clickAggregateSummary = $this->run_click_aggregate_cleanup($eventRetentionDays);
        $summary['click_links_reset'] = (int) ($clickAggregateSummary['click_links_reset'] ?? 0);
        $summary['click_links_skipped'] = (int) ($clickAggregateSummary['click_links_skipped'] ?? 0);
        $summary['click_aggregate_candidate_preview'] = (int) ($clickAggregateSummary['candidate_preview'] ?? 0);
        $summary['click_aggregate_candidate_more'] = !empty($clickAggregateSummary['candidate_more']);
        if (!empty($clickAggregateSummary['warnings']) && is_array($clickAggregateSummary['warnings'])) {
            foreach ($clickAggregateSummary['warnings'] as $warning) {
                $warning = trim((string) $warning);
                if ($warning !== '') {
                    $summary['warnings'][] = $warning;
                }
            }
        }

        return $summary;
    }

    protected function apply_event_match_filters(array $criteria)
    {
        $provider = trim((string) ($criteria['provider'] ?? ''));
        $providerMessageId = trim((string) ($criteria['provider_message_id'] ?? ''));
        $providerEventId = trim((string) ($criteria['provider_event_id'] ?? ''));
        $email = trim((string) ($criteria['email'] ?? ''));
        $url = trim((string) ($criteria['url'] ?? ''));
        $eventAt = trim((string) ($criteria['event_at'] ?? ''));
        $snsMessageId = trim((string) ($criteria['sns_message_id'] ?? ''));
        $eventType = trim((string) ($criteria['event_type'] ?? ''));

        $this->db->from($this->table);

        if ($provider !== '' && $this->column_exists('provider')) {
            $this->db->where('provider', $provider);
        }
        if ($snsMessageId !== '') {
            $escapedSnsMessageId = $this->db->escape_str($snsMessageId);
            $hasSnsMatchCondition = false;
            $this->db->group_start();
            if ($this->column_exists('sns_message_id')) {
                $this->db->where('sns_message_id', $snsMessageId);
                $hasSnsMatchCondition = true;
            }
            if ($this->column_exists('payload_json')) {
                if ($hasSnsMatchCondition) {
                    $this->db->or_like('payload_json', '"sns_message_id":"' . $escapedSnsMessageId . '"', 'both', false);
                } else {
                    $this->db->like('payload_json', '"sns_message_id":"' . $escapedSnsMessageId . '"', 'both', false);
                    $hasSnsMatchCondition = true;
                }
                $this->db->or_like('payload_json', '"_sns_message_id":"' . $escapedSnsMessageId . '"', 'both', false);
                $hasSnsMatchCondition = true;
            }
            if (!$hasSnsMatchCondition) {
                $this->db->where('1 = 0', null, false);
            }
            $this->db->group_end();

            return;
        }

        if ($eventType !== '') {
            $this->apply_normalized_event_type_filter($this->get_event_type_aliases($eventType));
        }
        if ($providerEventId !== '') {
            if ($this->column_exists('provider_event_id')) {
                $this->db->where('provider_event_id', $providerEventId);
            }

            return;
        }

        if ($providerMessageId !== '' && $this->column_exists('provider_message_id')) {
            $this->db->where('provider_message_id', $providerMessageId);
        }
        if ($email !== '' && $this->column_exists('email')) {
            $this->db->where('email', $email);
        }
        if ($url !== '' && $this->column_exists('url')) {
            $this->db->where('url', $url);
        }
        if ($eventAt !== '' && $this->column_exists('event_at')) {
            $this->db->where('event_at', $eventAt);
        }
    }

    protected function apply_normalized_event_type_filter(array $eventTypes)
    {
        $aliases = [];
        foreach ($eventTypes as $eventType) {
            $normalized = $this->normalize_event_type_value_for_query($eventType);
            if ($normalized !== '') {
                $aliases[] = $normalized;
            }
        }

        $aliases = array_values(array_unique($aliases));
        if (empty($aliases)) {
            return;
        }

        if (!$this->column_exists('event_type')) {
            $this->db->where('1 = 0', null, false);

            return;
        }

        $escapedAliases = [];
        foreach ($aliases as $alias) {
            $escapedAliases[] = $this->db->escape($alias);
        }

        if (empty($escapedAliases)) {
            $this->db->where('1 = 0', null, false);

            return;
        }

        $this->db->where($this->normalized_event_type_sql() . ' IN (' . implode(', ', $escapedAliases) . ')', null, false);
    }

    protected function normalized_event_type_sql()
    {
        return "LOWER(REPLACE(REPLACE(TRIM(event_type), ' ', '_'), '-', '_'))";
    }

    protected function normalize_event_type_value_for_query($eventType)
    {
        $eventType = strtolower(trim((string) $eventType));
        $eventType = str_replace([' ', '-'], '_', $eventType);

        return $eventType;
    }

    protected function build_event_lock_key(array $criteria)
    {
        $criteria = $this->build_event_dedupe_criteria($criteria);
        if (!$this->has_stable_dedupe_criteria($criteria)) {
            return '';
        }

        if (!empty($criteria['sns_message_id'])) {
            $lockSource = [
                'provider' => $criteria['provider'],
                'sns_message_id' => $criteria['sns_message_id'],
            ];
        } elseif (!empty($criteria['provider_message_id']) || !empty($criteria['provider_event_id'])) {
            $lockSource = [
                'provider' => $criteria['provider'],
                'event_type' => $criteria['event_type'],
                'provider_message_id' => $criteria['provider_message_id'],
                'provider_event_id' => $criteria['provider_event_id'],
                'email' => $criteria['email'],
            ];
        } else {
            $lockSource = [
                'provider' => $criteria['provider'],
                'event_type' => $criteria['event_type'],
                'email' => $criteria['email'],
                'url' => $criteria['url'],
                'event_at' => $criteria['event_at'],
            ];
        }

        return 'mkt_evt_' . substr(hash('sha256', json_encode($lockSource)), 0, 56);
    }

    protected function filter_insertable_event(array $event)
    {
        $columns = $this->get_table_columns();

        return array_intersect_key($event, array_flip($columns));
    }

    protected function get_table_columns()
    {
        if ($this->tableColumns === null) {
            $this->tableColumns = $this->db->list_fields($this->table);
        }

        return is_array($this->tableColumns) ? $this->tableColumns : [];
    }

    protected function column_exists($column)
    {
        return in_array($column, $this->get_table_columns(), true);
    }

    protected function get_last_db_error_message()
    {
        $error = $this->db->error();

        return trim((string) ($error['message'] ?? ''));
    }

    protected function log_events_runtime_failure($prefix, Throwable $exception)
    {
        log_message('error', $prefix . $this->sanitize_log_message($exception->getMessage()));
    }

    protected function sanitize_log_message($message)
    {
        if (function_exists('marketing_sanitize_log_message')) {
            return marketing_sanitize_log_message($message);
        }

        $message = trim(strip_tags((string) $message));
        if ($message === '') {
            return 'unknown_error';
        }

        return function_exists('mb_substr')
            ? mb_substr($message, 0, 1000)
            : substr($message, 0, 1000);
    }

    protected function get_public_links_model()
    {
        if ($this->marketingPublicLinksModel === null) {
            $CI = &get_instance();
            $CI->load->model('marketing/Marketing_public_links_model', 'marketing_public_links_model');
            $this->marketingPublicLinksModel = $CI->marketing_public_links_model;
        }

        return $this->marketingPublicLinksModel;
    }

    protected function get_click_aggregate_retention_diagnostics_summary($eventRetentionDays)
    {
        $summary = [
            'cleanup_ready' => false,
            'candidate_preview' => 0,
            'candidate_more' => false,
            'warnings' => ['click_aggregate_cleanup_unavailable'],
        ];

        $publicLinksModel = $this->get_public_links_model();
        if (!is_object($publicLinksModel) || !method_exists($publicLinksModel, 'get_click_aggregate_retention_diagnostics')) {
            return $summary;
        }

        try {
            $diagnostics = $publicLinksModel->get_click_aggregate_retention_diagnostics($eventRetentionDays, self::MAX_DELETE_PER_RUN);
            if (is_array($diagnostics)) {
                return array_merge($summary, $diagnostics);
            }
        } catch (Throwable $exception) {
            $this->log_events_runtime_failure('Marketing click aggregate retention diagnostics unavailable: ', $exception);
        }

        return $summary;
    }

    protected function run_click_aggregate_cleanup($eventRetentionDays)
    {
        $summary = [
            'click_links_reset' => 0,
            'click_links_skipped' => 0,
            'candidate_preview' => 0,
            'candidate_more' => false,
            'warnings' => ['click_aggregate_cleanup_unavailable'],
        ];

        $publicLinksModel = $this->get_public_links_model();
        if (!is_object($publicLinksModel) || !method_exists($publicLinksModel, 'reset_stale_click_aggregates')) {
            return $summary;
        }

        try {
            $cleanup = $publicLinksModel->reset_stale_click_aggregates($eventRetentionDays, self::MAX_DELETE_PER_RUN);
            if (is_array($cleanup)) {
                return array_merge($summary, $cleanup);
            }
        } catch (Throwable $exception) {
            $this->log_events_runtime_failure('Marketing click aggregate cleanup failed closed: ', $exception);
        }

        return $summary;
    }

    protected function get_retention_days($optionName)
    {
        return $this->normalize_retention_days(get_option($optionName), $optionName);
    }

    protected function normalize_retention_days($value, $optionName = null)
    {
        $days = (int) $value;
        if ($days <= 0 && $optionName !== null) {
            $days = (int) get_option($optionName);
        }
        if ($days <= 0) {
            $days = self::DEFAULT_RETENTION_DAYS;
        }

        return max(self::MIN_RETENTION_DAYS, $days);
    }

    protected function build_retention_cutoff_datetime($retentionDays)
    {
        $retentionDays = max(self::MIN_RETENTION_DAYS, (int) $retentionDays);
        if ($retentionDays <= 0) {
            return null;
        }

        return date('Y-m-d H:i:s', time() - ($retentionDays * 86400));
    }

    protected function can_cleanup_events()
    {
        return $this->db->table_exists($this->table)
            && $this->column_exists('id')
            && $this->column_exists('event_type')
            && ($this->column_exists('event_at') || $this->column_exists('datecreated'));
    }

    protected function can_cleanup_webhook_logs()
    {
        return $this->db->table_exists($this->webhookLogTable)
            && $this->db->field_exists('id', $this->webhookLogTable)
            && $this->db->field_exists('datecreated', $this->webhookLogTable);
    }

    protected function count_event_cleanup_candidate_preview($cutoffDateTime)
    {
        $this->db->select('id', false)
            ->from($this->table)
            ->limit(self::MAX_DELETE_PER_RUN + 1);
        $this->apply_event_cleanup_filters($cutoffDateTime);

        return count($this->db->get()->result_array());
    }

    protected function count_webhook_log_cleanup_candidate_preview($cutoffDateTime)
    {
        $this->db->select('id', false)
            ->from($this->webhookLogTable)
            ->where('datecreated <=', $cutoffDateTime)
            ->order_by('id', 'ASC')
            ->limit(self::MAX_DELETE_PER_RUN + 1);

        return count($this->db->get()->result_array());
    }

    protected function delete_event_cleanup_candidates($cutoffDateTime)
    {
        $candidateIds = $this->get_event_cleanup_candidate_ids($cutoffDateTime);
        if (empty($candidateIds)) {
            return 0;
        }

        $this->db->where_in('id', $candidateIds);
        $this->db->delete($this->table);

        return (int) $this->db->affected_rows();
    }

    protected function delete_webhook_log_cleanup_candidates($cutoffDateTime)
    {
        $candidateIds = $this->get_webhook_log_cleanup_candidate_ids($cutoffDateTime);
        if (empty($candidateIds)) {
            return 0;
        }

        $this->db->where_in('id', $candidateIds);
        $this->db->delete($this->webhookLogTable);

        return (int) $this->db->affected_rows();
    }

    protected function get_event_cleanup_candidate_ids($cutoffDateTime)
    {
        $this->db->select('id', false)
            ->from($this->table)
            ->limit(self::MAX_DELETE_PER_RUN)
            ->order_by('id', 'ASC');
        $this->apply_event_cleanup_filters($cutoffDateTime);

        return array_map('intval', array_column($this->db->get()->result_array(), 'id'));
    }

    protected function get_webhook_log_cleanup_candidate_ids($cutoffDateTime)
    {
        $rows = $this->db->select('id', false)
            ->from($this->webhookLogTable)
            ->where('datecreated <=', $cutoffDateTime)
            ->order_by('id', 'ASC')
            ->limit(self::MAX_DELETE_PER_RUN)
            ->get()
            ->result_array();

        return array_map('intval', array_column($rows, 'id'));
    }

    protected function apply_event_cleanup_filters($cutoffDateTime)
    {
        $escapedEventTypes = [];
        foreach ($this->get_audit_critical_event_types() as $eventType) {
            $escapedEventTypes[] = $this->db->escape($eventType);
        }

        if (!empty($escapedEventTypes)) {
            $this->db->where(
                $this->normalized_event_type_sql() . ' NOT IN (' . implode(', ', $escapedEventTypes) . ')',
                null,
                false
            );
        }

        if ($this->column_exists('event_at')) {
            $this->db->where('event_at <=', $cutoffDateTime);
        } else {
            $this->db->where('datecreated <=', $cutoffDateTime);
        }
    }

    protected function get_audit_critical_event_types()
    {
        return [
            'bounce',
            'complaint',
            'unsubscribe',
            'suppressed',
            'operator_action',
            'subscription',
            'subscription_confirmation',
            'unsubscribe_confirmation',
            'reply',
            'reply_lead_created',
            'reply_lead_existing',
            'reply_lead_failed',
            'reply_lead_skipped',
            'retry_triggered',
        ];
    }
}
