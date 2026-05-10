<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Marketing_campaigns_model extends App_Model
{
    const TABLE = 'marketing_campaigns';
    const LISTS_TABLE = 'marketing_lists';
    const CONTACTS_TABLE = 'marketing_contacts';
    const MEMBERS_TABLE = 'marketing_list_members';
    const SUPPRESSIONS_TABLE = 'marketing_suppressions';
    const RECIPIENTS_TABLE = 'marketing_campaign_recipients';
    const EVENTS_TABLE = 'marketing_events';

    private const STATUSES = [
        'draft',
        'scheduled',
        'sending',
        'sent',
        'paused',
        'cancelled',
        'failed',
    ];
    private const TARGETING_MARKER_PREFIX = '<!--marketing_targeting_rules:';
    private const TARGETING_MARKER_SUFFIX = '-->';
    private const SENDABLE_RECIPIENT_STATUSES = ['queued', 'pending'];
    private const FAILURE_METRIC_CODES = [
        'provider_transient_failure',
        'provider_throttle',
        'provider_permanent_failure',
        'provider_rendering_failure',
        'recipient_bounced',
        'recipient_complained',
        'recipient_suppressed',
        'recipient_unsubscribed',
        'recipient_skipped',
        'recipient_reconciliation_skipped',
    ];
    private const PROCESSING_STALE_TIMEOUT_SECONDS = 1800;
    private const QUEUE_WORKER_STALE_TIMEOUT_SECONDS = 1800;
    private const MAX_SEND_BATCH_LIMIT = 100;
    private const COMMERCIAL_PREPARE_THRESHOLD_FALLBACK = 100;

    protected $table;
    protected $listsTable;
    protected $contactsTable;
    protected $membersTable;
    protected $suppressionsTable;
    protected $recipientsTable;
    protected $eventsTable;
    protected $marketingSuppressionsModel;
    protected $columnExistsCache = [];
    private const DELIVERY_CHANNELS = ['email', 'whatsapp'];

    public function __construct()
    {
        parent::__construct();

        $this->table = db_prefix() . self::TABLE;
        $this->listsTable = db_prefix() . self::LISTS_TABLE;
        $this->contactsTable = db_prefix() . self::CONTACTS_TABLE;
        $this->membersTable = db_prefix() . self::MEMBERS_TABLE;
        $this->suppressionsTable = db_prefix() . self::SUPPRESSIONS_TABLE;
        $this->recipientsTable = db_prefix() . self::RECIPIENTS_TABLE;
        $this->eventsTable = db_prefix() . self::EVENTS_TABLE;
    }

    public function normalize_delivery_channel($channel)
    {
        $channel = strtolower(trim((string) $channel));

        return in_array($channel, self::DELIVERY_CHANNELS, true) ? $channel : 'email';
    }

    public function is_whatsapp_campaign($campaign)
    {
        if (is_array($campaign)) {
            return $this->normalize_delivery_channel($campaign['delivery_channel'] ?? 'email') === 'whatsapp';
        }

        return $this->normalize_delivery_channel($campaign) === 'whatsapp';
    }

    public function normalize_phone_number($phone)
    {
        $phone = trim((string) $phone);
        if ($phone === '') {
            return null;
        }

        $prefix = strpos($phone, '+') === 0 ? '+' : '';
        $digits = preg_replace('/[^0-9]/', '', $phone);
        if ($digits === '') {
            return null;
        }

        $normalized = $prefix . $digits;

        if (strlen($digits) < 8 || strlen($digits) > 15) {
            return null;
        }

        return $normalized;
    }

    public function get_default_targeting_rules()
    {
        return [
            'send_mode' => 'full',
            'contact_status' => '',
            'consent_status' => '',
            'company_contains' => '',
            'email_contains' => '',
            'email_domain' => '',
            'source_type' => '',
            'created_from' => '',
            'created_to' => '',
            'exclude_bounced' => 1,
            'exclude_complained' => 1,
            'exclude_unsubscribed' => 1,
            'exclude_suppressed' => 1,
        ];
    }

    public function normalize_targeting_rules(array $rules = [])
    {
        $defaults = $this->get_default_targeting_rules();
        $normalized = array_merge($defaults, $rules);

        $normalized['send_mode'] = $normalized['send_mode'] === 'filtered' ? 'filtered' : 'full';
        $normalized['contact_status'] = $this->sanitize_targeting_value($normalized['contact_status']);
        $normalized['consent_status'] = $this->sanitize_targeting_value($normalized['consent_status']);
        $normalized['company_contains'] = trim((string) $normalized['company_contains']);
        $normalized['email_contains'] = trim((string) $normalized['email_contains']);
        $normalized['email_domain'] = ltrim(mb_strtolower(trim((string) $normalized['email_domain'])), '@');
        $normalized['source_type'] = in_array($normalized['source_type'], ['manual', 'import'], true) ? $normalized['source_type'] : '';
        $normalized['created_from'] = $this->normalize_targeting_date($normalized['created_from']);
        $normalized['created_to'] = $this->normalize_targeting_date($normalized['created_to']);
        $normalized['exclude_bounced'] = 1;
        $normalized['exclude_complained'] = 1;
        $normalized['exclude_unsubscribed'] = 1;
        $normalized['exclude_suppressed'] = 1;

        if ($normalized['send_mode'] !== 'filtered') {
            $normalized['contact_status'] = '';
            $normalized['consent_status'] = '';
            $normalized['company_contains'] = '';
            $normalized['email_contains'] = '';
            $normalized['email_domain'] = '';
            $normalized['source_type'] = '';
            $normalized['created_from'] = '';
            $normalized['created_to'] = '';
        }

        return $normalized;
    }

    public function strip_targeting_rules_from_builder_state($builderState)
    {
        return preg_replace('/<!--marketing_targeting_rules:[A-Za-z0-9+\/=]+-->\s*/i', '', (string) $builderState);
    }

    public function extract_targeting_rules_from_builder_state($builderState)
    {
        $builderState = (string) $builderState;

        if (preg_match('/<!--marketing_targeting_rules:([A-Za-z0-9+\/=]+)-->/i', $builderState, $matches)) {
            $decoded = base64_decode($matches[1], true);
            if ($decoded !== false) {
                $payload = json_decode($decoded, true);
                if (is_array($payload)) {
                    return $this->normalize_targeting_rules($payload);
                }
            }
        }

        return $this->get_default_targeting_rules();
    }

    public function embed_targeting_rules_in_builder_state($builderState, array $rules = [])
    {
        $cleanBuilderState = $this->strip_targeting_rules_from_builder_state($builderState);
        $normalizedRules = $this->normalize_targeting_rules($rules);
        $encodedRules = base64_encode(json_encode($normalizedRules));

        return self::TARGETING_MARKER_PREFIX . $encodedRules . self::TARGETING_MARKER_SUFFIX . $cleanBuilderState;
    }

    public function get_campaign_targeting_storage_column()
    {
        static $resolved = false;
        static $storageColumn = null;

        if ($resolved) {
            return $storageColumn;
        }

        foreach (['segment_rules_json', 'targeting_rules_json'] as $candidate) {
            if ($this->column_exists($this->table, $candidate)) {
                $storageColumn = $candidate;
                $resolved = true;

                return $storageColumn;
            }
        }

        $resolved = true;

        return null;
    }

    public function get_campaign_targeting_rules($campaign)
    {
        if (!is_array($campaign)) {
            return $this->get_default_targeting_rules();
        }

        $storageColumn = $this->get_campaign_targeting_storage_column();
        if ($storageColumn !== null && !empty($campaign[$storageColumn])) {
            $decoded = json_decode((string) $campaign[$storageColumn], true);
            if (is_array($decoded)) {
                return $this->normalize_targeting_rules($decoded);
            }
        }

        $builderState = (string) ($campaign['builder_state'] ?? '');
        if ($builderState === '' && !empty($campaign['html_content'])) {
            $builderState = $this->extract_builder_state_metadata((string) $campaign['html_content']);
        }

        return $this->extract_targeting_rules_from_builder_state($builderState);
    }

    public function get_targeting_summary_lines(array $rules)
    {
        $rules = $this->normalize_targeting_rules($rules);
        $lines = [];

        if ($rules['send_mode'] !== 'filtered') {
            $lines[] = 'Full audience';
        } else {
            $lines[] = 'Filtered segment';

            if ($rules['contact_status'] !== '') {
                $lines[] = 'Contact status: ' . ucfirst($rules['contact_status']);
            }

            if ($rules['consent_status'] !== '') {
                $lines[] = 'Consent: ' . ucfirst($rules['consent_status']);
            }

            if ($rules['company_contains'] !== '') {
                $lines[] = 'Company contains: ' . $rules['company_contains'];
            }

            if ($rules['email_contains'] !== '') {
                $lines[] = 'Email contains: ' . $rules['email_contains'];
            }

            if ($rules['email_domain'] !== '') {
                $lines[] = 'Email domain: @' . $rules['email_domain'];
            }

            if ($rules['source_type'] === 'manual') {
                $lines[] = 'Source: Manual contacts';
            } elseif ($rules['source_type'] === 'import') {
                $lines[] = 'Source: Imported contacts';
            }

            if ($rules['created_from'] !== '' || $rules['created_to'] !== '') {
                $dateRange = 'Created';
                if ($rules['created_from'] !== '') {
                    $dateRange .= ' from ' . $rules['created_from'];
                }
                if ($rules['created_to'] !== '') {
                    $dateRange .= ' to ' . $rules['created_to'];
                }
                $lines[] = $dateRange;
            }
        }

        $lines[] = 'Safety exclusions: bounced, complained, unsubscribed, and suppressed contacts are always excluded';

        return $lines;
    }

    public function count_all()
    {
        return $this->db->count_all($this->table);
    }

    public function get_active_suppression($email)
    {
        return $this->get_suppressions_model()->get_active_suppression($email);
    }

    public function is_suppressed($email)
    {
        return $this->get_suppressions_model()->is_suppressed($email);
    }

    public function exists($id)
    {
        return total_rows($this->table, ['id' => $id]) > 0;
    }

    public function get($id)
    {
        $this->db->select('c.*, l.name as audience_name');
        $this->db->from($this->table . ' c');
        $this->db->join($this->listsTable . ' l', 'l.id = c.list_id', 'left');
        $this->db->where('c.id', (int) $id);

        return $this->db->get()->row_array();
    }

    public function get_all($limit = 0)
    {
        $this->db->select('c.*, l.name as audience_name');
        $this->db->from($this->table . ' c');
        $this->db->join($this->listsTable . ' l', 'l.id = c.list_id', 'left');
        $this->db->order_by('c.id', 'DESC');

        if ($limit > 0) {
            $this->db->limit((int) $limit);
        }

        return $this->db->get()->result_array();
    }

    public function get_recent($limit = 10)
    {
        return $this->get_all($limit);
    }

    public function get_by_owner($ownerId, $limit = 0)
    {
        $this->db->select('c.*, l.name as audience_name');
        $this->db->from($this->table . ' c');
        $this->db->join($this->listsTable . ' l', 'l.id = c.list_id', 'left');
        $this->db->where('c.created_by', (int) $ownerId);
        $this->db->order_by('c.id', 'DESC');

        if ($limit > 0) {
            $this->db->limit((int) $limit);
        }

        return $this->db->get()->result_array();
    }

    public function get_by_status($status)
    {
        if (!in_array($status, self::STATUSES, true)) {
            return [];
        }

        $this->db->select('c.*, l.name as audience_name');
        $this->db->from($this->table . ' c');
        $this->db->join($this->listsTable . ' l', 'l.id = c.list_id', 'left');
        $this->db->where('c.status', $status);
        $this->db->order_by('c.id', 'DESC');

        return $this->db->get()->result_array();
    }

    public function count_by_status($status)
    {
        if (!in_array($status, self::STATUSES, true)) {
            return 0;
        }

        $this->db->where('status', $status);

        return $this->db->count_all_results($this->table);
    }

    public function create($data)
    {
        $data['created_by'] = get_staff_user_id();
        $data = $this->filter_existing_columns($this->table, $data);

        $this->db->insert($this->table, $data);

        return $this->db->insert_id();
    }

    public function update($id, $data)
    {
        $data['updated_by'] = get_staff_user_id();
        $data = $this->filter_existing_columns($this->table, $data);

        $this->db->where('id', (int) $id);
        $this->db->update($this->table, $data);

        return $this->db->affected_rows() !== 0;
    }

    public function delete($id)
    {
        $campaignId = (int) $id;

        $this->db->trans_begin();

        // Delete recipients
        $this->db->where('campaign_id', $campaignId);
        if (!$this->db->delete($this->recipientsTable)) {
            $this->db->trans_rollback();

            return false;
        }

        // Delete events
        $this->db->where('campaign_id', $campaignId);
        if (!$this->db->delete($this->eventsTable)) {
            $this->db->trans_rollback();

            return false;
        }

        // Delete campaign
        $this->db->where('id', $campaignId);
        if (!$this->db->delete($this->table)) {
            $this->db->trans_rollback();

            return false;
        }

        // Ensure campaign row actually deleted
        if ($this->db->affected_rows() === 0) {
            $this->db->trans_rollback();
            return false;
        }

        $this->db->trans_commit();

        return true;
    }

    public function campaign_can_be_deleted($campaign)
    {
        if (is_numeric($campaign)) {
            $campaign = $this->get($campaign);
        }

        if (!$campaign) {
            return false;
        }

        return $this->count_recipients_by_status($campaign['id'], 'processing') === 0;
    }

    public function campaign_can_be_edited($campaignId)
    {
        $campaign = is_array($campaignId) ? $campaignId : $this->get($campaignId);

        if (!$campaign) {
            return false;
        }

        if (in_array($campaign['status'], ['draft', 'scheduled'], true)) {
            return true;
        }

        if ($campaign['status'] !== 'sending') {
            return false;
        }

        return $this->count_recipients_by_status($campaign['id'], 'queued') === 0
            && $this->count_recipients_by_status($campaign['id'], 'processing') === 0
            && $this->count_recipients_by_status($campaign['id'], 'sent') === 0
            && $this->count_recipients_by_status($campaign['id'], 'failed') === 0;
    }

    public function list_exists($listId)
    {
        return total_rows($this->listsTable, ['id' => (int) $listId]) > 0;
    }

    public function count_recipients_by_status($campaignId, $status)
    {
        return total_rows($this->recipientsTable, ['campaign_id' => (int) $campaignId, 'status' => $status]);
    }

    public function count_campaign_recipients($campaignId, $status = null)
    {
        $this->db->from($this->recipientsTable);
        $this->db->where('campaign_id', (int) $campaignId);

        if (is_string($status) && $status !== '') {
            $this->db->where('status', $status);
        }

        return (int) $this->db->count_all_results();
    }

    public function get_campaign_recipients_paginated($campaignId, $limit, $offset, $status = null)
    {
        $limit = max(1, (int) $limit);
        $offset = max(0, (int) $offset);

        $this->db->from($this->recipientsTable);
        $this->db->where('campaign_id', (int) $campaignId);

        if (is_string($status) && $status !== '') {
            $this->db->where('status', $status);
        }

        return $this->db->order_by('id', 'DESC')
            ->limit($limit, $offset)
            ->get()
            ->result_array();
    }

    public function retry_failed_recipients($campaignId)
    {
        $campaignId = (int) $campaignId;
        $campaign = $this->get($campaignId);

        if (!$campaign) {
            return false;
        }

        $this->db->trans_begin();

        $update = [
            'status' => 'queued',
            'processing_token' => null,
            'outbound_attempt_token' => null,
            'queued_at' => date('Y-m-d H:i:s'),
            'sent_at' => null,
            'failed_at' => null,
            'message_id' => null,
            'provider_message_id' => null,
            'failure_reason' => null,
            'last_event_at' => null,
            'dateupdated' => date('Y-m-d H:i:s'),
        ];
        $update = $this->filter_existing_columns($this->recipientsTable, $update);

        $this->db->where('campaign_id', $campaignId);
        $this->db->where('status', 'failed');
        $updated = $this->db->update($this->recipientsTable, $update);

        if (!$updated) {
            $this->db->trans_rollback();

            return false;
        }

        $requeued = (int) $this->db->affected_rows();

        if ($requeued > 0 && in_array($campaign['status'], ['sent', 'failed'], true)) {
            $campaignUpdate = [
                'status' => 'sending',
                'send_completed_at' => null,
            ];

            if ($this->column_exists($this->table, 'send_started_at') && empty($campaign['send_started_at'])) {
                $campaignUpdate['send_started_at'] = date('Y-m-d H:i:s');
            }

            $campaignUpdate = $this->filter_existing_columns($this->table, $campaignUpdate);

            $this->db->where('id', $campaignId);
            if (!$this->db->update($this->table, $campaignUpdate)) {
                $this->db->trans_rollback();

                return false;
            }
        }

        if (!$this->create_event([
            'campaign_id' => $campaignId,
            'event_type' => 'retry_triggered',
            'provider' => $campaign['source_type'] ?? 'smtp',
            'payload_json' => json_encode([
                'requeued' => $requeued,
                'campaign_id' => $campaignId,
            ]),
        ])) {
            $this->db->trans_rollback();

            return false;
        }

        $this->db->trans_commit();

        return [
            'requeued' => $requeued,
        ];
    }

    public function find_recipient_for_event($providerMessageId = null, $messageId = null, $email = null, array $context = [])
    {
        $campaignId = isset($context['campaign_id']) ? (int) $context['campaign_id'] : 0;
        $recipientId = isset($context['recipient_id']) ? (int) $context['recipient_id'] : 0;
        $preferCampaignEmail = !empty($context['prefer_campaign_email']);
        $normalizedEmail = $this->normalize_email($email);

        if ($recipientId > 0) {
            $recipient = $this->db->from($this->recipientsTable)
                ->where('id', $recipientId)
                ->limit(1)
                ->get()
                ->row_array();

            if (!empty($recipient) && ($campaignId <= 0 || (int) $recipient['campaign_id'] === $campaignId)) {
                return $recipient;
            }
        }

        if ($preferCampaignEmail && $normalizedEmail !== null && $campaignId > 0) {
            $recipients = $this->db->from($this->recipientsTable)
                ->where('campaign_id', $campaignId)
                ->where('email', $normalizedEmail)
                ->limit(2)
                ->get()
                ->result_array();

            if (count($recipients) === 1) {
                return $recipients[0];
            }
        }

        $outboundAttemptToken = trim((string) ($context['outbound_attempt_token'] ?? ''));
        if ($outboundAttemptToken !== '') {
            $recipient = $this->find_recipient_by_outbound_attempt_token($outboundAttemptToken, $campaignId);
            if (!empty($recipient)) {
                return $recipient;
            }
        }

        $identifiers = [];
        foreach ([
            $context['correlation_id'] ?? null,
            $providerMessageId,
            $messageId,
            $context['header_message_id'] ?? null,
        ] as $identifier) {
            $normalizedIdentifier = $this->normalize_message_identifier($identifier);
            if ($normalizedIdentifier !== null) {
                $identifiers[$normalizedIdentifier] = true;
            }
        }

        foreach (array_keys($identifiers) as $identifier) {
            $recipient = $this->find_recipient_by_message_identifier($identifier, $campaignId);
            if (!empty($recipient)) {
                return $recipient;
            }
        }

        if ($normalizedEmail !== null && $campaignId > 0) {
            $recipients = $this->db->from($this->recipientsTable)
                ->where('campaign_id', $campaignId)
                ->where('email', $normalizedEmail)
                ->limit(2)
                ->get()
                ->result_array();

            if (count($recipients) === 1) {
                return $recipients[0];
            }
        }

        if ($normalizedEmail !== null) {
            $recipients = $this->db->from($this->recipientsTable)
                ->where('email', $normalizedEmail)
                ->limit(2)
                ->get()
                ->result_array();

            if (count($recipients) === 1) {
                return $recipients[0];
            }
        }

        return null;
    }

    protected function find_recipient_by_outbound_attempt_token($outboundAttemptToken, $campaignId = 0)
    {
        $outboundAttemptToken = trim((string) $outboundAttemptToken);
        if ($outboundAttemptToken === '' || !$this->column_exists($this->recipientsTable, 'outbound_attempt_token')) {
            return null;
        }

        $this->db->from($this->recipientsTable);
        if ((int) $campaignId > 0) {
            $this->db->where('campaign_id', (int) $campaignId);
        }
        $this->db->where('outbound_attempt_token', $outboundAttemptToken);

        return $this->db->order_by('id', 'DESC')
            ->limit(1)
            ->get()
            ->row_array();
    }

    protected function find_recipient_by_message_identifier($identifier, $campaignId = 0)
    {
        $identifier = $this->normalize_message_identifier($identifier);
        if ($identifier === null) {
            return null;
        }

        $this->db->from($this->recipientsTable);
        if ((int) $campaignId > 0) {
            $this->db->where('campaign_id', (int) $campaignId);
        }
        $this->db->group_start()
            ->where('provider_message_id', $identifier)
            ->or_where('message_id', $identifier)
            ->group_end();

        return $this->db->order_by('id', 'DESC')
            ->limit(1)
            ->get()
            ->row_array();
    }

    public function update_recipient_record($recipientId, array $data = [])
    {
        if (empty($data)) {
            return false;
        }

        if ($this->column_exists($this->recipientsTable, 'dateupdated') && !array_key_exists('dateupdated', $data)) {
            $data['dateupdated'] = date('Y-m-d H:i:s');
        }

        $data = $this->filter_existing_columns($this->recipientsTable, $data);

        if (empty($data)) {
            return false;
        }

        $this->db->where('id', (int) $recipientId);
        $this->db->update($this->recipientsTable, $data);

        return $this->db->affected_rows() !== 0;
    }

    public function get_recipient_by_id($recipientId)
    {
        return $this->db->get_where($this->recipientsTable, ['id' => (int) $recipientId])->row_array();
    }

    public function increment_recipient_counts($recipientId, array $counters)
    {
        if (empty($counters) || $recipientId === null) {
            return false;
        }

        $hasCounterUpdate = false;
        foreach ($counters as $field => $amount) {
            if ($this->column_exists($this->recipientsTable, $field)) {
                $this->db->set($field, $field . ' + ' . (int) $amount, false);
                $hasCounterUpdate = true;
            }
        }

        if (!$hasCounterUpdate) {
            return false;
        }

        $this->db->where('id', (int) $recipientId);
        $this->db->update($this->recipientsTable);

        return $this->db->affected_rows() !== 0;
    }

    public function get_due_campaigns($limit = 10)
    {
        $now = date('Y-m-d H:i:s');

        $this->db->select('c.*, l.name as audience_name');
        $this->db->from($this->table . ' c');
        $this->db->join($this->listsTable . ' l', 'l.id = c.list_id', 'left');
        $this->db->where("(c.status = 'scheduled' AND (c.scheduled_at IS NULL OR c.scheduled_at <= " . $this->db->escape($now) . ")) OR c.status = 'sending'", null, false);
        $this->db->order_by('FIELD(c.status, "sending", "scheduled")', false);
        $this->db->order_by('c.id', 'ASC');

        if ((int) $limit > 0) {
            $this->db->limit((int) $limit);
        }

        return $this->db->get()->result_array();
    }

    public function get_send_window_settings()
    {
        $start = $this->normalize_send_window_time(get_option('marketing_send_start_hour'), '00:00');
        $end = $this->normalize_send_window_time(get_option('marketing_send_end_hour'), '23:59');

        return [
            'start' => $start,
            'end' => $end,
            'label' => $start . ' - ' . $end,
        ];
    }

    public function is_within_send_window($dateTime = null)
    {
        $window = $this->get_send_window_settings();
        $timestamp = $dateTime ? strtotime((string) $dateTime) : time();
        if ($timestamp === false) {
            $timestamp = time();
        }

        $currentTime = date('H:i', $timestamp);

        if ($window['start'] === $window['end']) {
            return true;
        }

        if (strcmp($window['start'], $window['end']) < 0) {
            return strcmp($window['start'], $currentTime) <= 0 && strcmp($currentTime, $window['end']) <= 0;
        }

        return strcmp($currentTime, $window['start']) >= 0 || strcmp($currentTime, $window['end']) <= 0;
    }

    public function get_rate_limit_snapshot()
    {
        $acceptedThisHour = $this->count_sent_events_since(date('Y-m-d H:00:00'));
        $acceptedToday = $this->count_sent_events_since(date('Y-m-d 00:00:00'));
        $hourlyCap = max(0, (int) get_option('marketing_send_hourly_cap'));
        $dailyCap = max(0, (int) get_option('marketing_send_daily_cap'));

        return [
            'per_cron_run' => $this->clamp_send_batch_limit((int) get_option('marketing_send_per_cron_run')),
            'hourly_cap' => $hourlyCap,
            'daily_cap' => $dailyCap,
            'accepted_this_hour' => $acceptedThisHour,
            'accepted_today' => $acceptedToday,
            'hourly_remaining' => $hourlyCap > 0 ? max(0, $hourlyCap - $acceptedThisHour) : null,
            'daily_remaining' => $dailyCap > 0 ? max(0, $dailyCap - $acceptedToday) : null,
        ];
    }

    public function process_due_recipients($limit = null, $campaignId = null)
    {
        $summary = [
            'processed' => 0,
            'accepted' => 0,
            'failed' => 0,
            'suppressed' => 0,
            'skipped' => 0,
            'recovered' => 0,
            'campaigns_considered' => 0,
            'campaigns_started' => 0,
            'campaigns_completed' => 0,
            'warnings' => [],
            'send_enabled' => $this->option_enabled('marketing_send_enabled'),
            'window_paused' => false,
        ];
        $manualOrCron = $campaignId !== null ? 'manual' : 'cron';

        if (!$this->option_enabled('marketing_send_enabled')) {
            $summary['warnings'][] = 'send_disabled';

            return $summary;
        }

        $queueSchemaCheck = $this->get_queue_schema_check();
        if (!$queueSchemaCheck['ready']) {
            $summary['warnings'][] = 'queue_schema_incomplete';
            $this->log_safe_queue_error(
                'Marketing campaign queue skipped due to incomplete schema: missing='
                . implode(',', $queueSchemaCheck['missing'])
            );

            return $summary;
        }

        $requestedLimit = $limit !== null ? (int) $limit : (int) get_option('marketing_send_per_cron_run');
        $limit = $this->clamp_send_batch_limit($requestedLimit);
        $batchLimitClamped = $requestedLimit > self::MAX_SEND_BATCH_LIMIT;
        if ($batchLimitClamped) {
            $summary['warnings'][] = 'batch_limit_clamped';
        }

        if (!$this->is_within_send_window()) {
            $summary['warnings'][] = 'outside_send_window';
            $summary['window_paused'] = true;
            log_message('info', 'Marketing send paused by send window: mode=' . $manualOrCron . ' window=' . $this->get_send_window_settings()['label']);

            return $summary;
        }

        $rateSnapshot = $this->get_rate_limit_snapshot();
        if ($rateSnapshot['hourly_remaining'] !== null) {
            if ($rateSnapshot['hourly_remaining'] <= 0) {
                $summary['warnings'][] = 'hourly_cap_reached';
                log_message('info', 'Marketing hourly cap reached: accepted_this_hour=' . $rateSnapshot['accepted_this_hour'] . ' cap=' . $rateSnapshot['hourly_cap']);

                return $summary;
            }

            $limit = min($limit, $rateSnapshot['hourly_remaining']);
        }

        if ($rateSnapshot['daily_remaining'] !== null) {
            if ($rateSnapshot['daily_remaining'] <= 0) {
                $summary['warnings'][] = 'daily_cap_reached';
                log_message('info', 'Marketing daily cap reached: accepted_today=' . $rateSnapshot['accepted_today'] . ' cap=' . $rateSnapshot['daily_cap']);

                return $summary;
            }

            $limit = min($limit, $rateSnapshot['daily_remaining']);
        }

        if ($campaignId !== null) {
            $campaign = $this->get($campaignId);
            if (!$campaign) {
                $summary['warnings'][] = 'campaign_not_found';

                return $summary;
            }

            if (!in_array($campaign['status'], ['draft', 'scheduled', 'sending'], true)) {
                $summary['warnings'][] = 'campaign_not_processable';

                return $summary;
            }

            $campaigns = [$campaign];
        } else {
            $campaigns = $this->get_due_campaigns($limit);
        }

        $this->load->library('marketing/Marketing_mailer');
        $this->load->library('marketing/Marketing_whatsapp');

        $sleepMs = max(0, min(5000, (int) get_option('marketing_send_sleep_ms')));

        foreach ($campaigns as $campaign) {
            $deliveryChannel = $this->normalize_delivery_channel($campaign['delivery_channel'] ?? 'email');
            $licenseFeature = $deliveryChannel === 'whatsapp' ? 'whatsapp_campaign_send' : 'campaign_send';
            $eventProvider = $deliveryChannel === 'whatsapp' ? 'whatsapp_business' : ($campaign['source_type'] ?? 'smtp');
            $summary['campaigns_considered']++;
            $campaignWarnings = [];
            if ($batchLimitClamped) {
                $this->append_unique_warning($campaignWarnings, 'batch_limit_clamped');
            }
            $campaignProcessed = 0;
            $campaignAccepted = 0;
            $campaignFailed = 0;
            $campaignRecovered = 0;
            $campaignFailureMetrics = [];
            $campaignLockKey = '';
            $workerLeaseToken = $this->generate_recipient_processing_token((int) $campaign['id']);
            $campaignWorkerToken = $this->generate_campaign_worker_token((int) $campaign['id']);

            $campaignLock = $this->acquire_campaign_send_lock((int) $campaign['id']);
            if (!empty($campaignLock['supported']) && empty($campaignLock['acquired'])) {
                $warning = 'campaign_' . (int) $campaign['id'] . '_locked';
                $this->append_unique_warning($summary['warnings'], $warning);
                $this->append_unique_warning($campaignWarnings, $warning);
                $this->create_event([
                    'campaign_id' => $campaign['id'],
                    'event_type' => 'batch_processed',
                    'provider' => $eventProvider,
                    'payload_json' => json_encode([
                        'processed' => 0,
                        'accepted' => 0,
                        'failed' => 0,
                        'recovered' => 0,
                        'failure_metrics' => $campaignFailureMetrics,
                        'warnings' => $campaignWarnings,
                        'manual_or_cron' => $manualOrCron,
                        'delivery_channel' => $deliveryChannel,
                    ]),
                ]);
                log_message('info', 'Marketing campaign send skipped because another worker holds the lock: campaign_id=' . (int) $campaign['id']);
                continue;
            }

            if (!empty($campaignLock['acquired'])) {
                $campaignLockKey = (string) ($campaignLock['lock_key'] ?? '');
            }

            try {
                $campaignLease = $this->acquire_campaign_worker_lease((int) $campaign['id'], $campaignWorkerToken);
                if (empty($campaignLease['acquired'])) {
                    $leaseWarning = (string) ($campaignLease['warning'] ?? ('campaign_' . (int) $campaign['id'] . '_worker_locked'));
                    $this->append_unique_warning($summary['warnings'], $leaseWarning);
                    $this->append_unique_warning($campaignWarnings, $leaseWarning);
                    $this->create_event([
                        'campaign_id' => $campaign['id'],
                        'event_type' => 'batch_processed',
                        'provider' => $eventProvider,
                        'payload_json' => json_encode([
                            'processed' => 0,
                            'accepted' => 0,
                            'failed' => 0,
                            'recovered' => 0,
                            'failure_metrics' => $campaignFailureMetrics,
                            'warnings' => $campaignWarnings,
                            'manual_or_cron' => $manualOrCron,
                            'delivery_channel' => $deliveryChannel,
                        ]),
                    ]);

                    log_message(
                        'info',
                        'Marketing campaign worker lease unavailable: campaign_id=' . (int) $campaign['id']
                        . ' warning=' . $leaseWarning
                        . ' reason=' . (string) ($campaignLease['reason'] ?? 'lease_unavailable')
                    );
                    continue;
                }
                if (!empty($campaignLease['recovered_stale'])) {
                    $this->append_unique_warning($summary['warnings'], 'campaign_worker_lease_recovered');
                    $this->append_unique_warning($campaignWarnings, 'campaign_worker_lease_recovered');
                }

                if (in_array($campaign['status'], ['paused', 'cancelled', 'failed', 'sent'], true)) {
                    continue;
                }

                if (!marketing_is_feature_allowed($licenseFeature)) {
                    $summary['warnings'][] = 'license_inactive';
                    $summary['readiness_messages'] = [marketing_license_block_message()];
                    log_message(
                        'warning',
                        'Marketing commercial send blocked by inactive license: campaign_id=' . (int) $campaign['id']
                        . ' feature=' . $licenseFeature
                    );

                    return $summary;
                }

                if ($this->is_campaign_scheduled_for_future($campaign)) {
                    $warning = 'campaign_not_due';
                    $summary['warnings'][] = $warning;
                    $campaignWarnings[] = $warning;
                    log_message('info', 'Marketing scheduled campaign not due yet: campaign_id=' . (int) $campaign['id'] . ' scheduled_at=' . ($campaign['scheduled_at'] ?? ''));
                    continue;
                }

                $readiness = $this->validate_send_ready($campaign['id']);
                if (!$readiness['ready']) {
                    $warning = 'campaign_' . $campaign['id'] . '_not_ready';
                    $summary['warnings'][] = $warning;
                    $campaignWarnings[] = $warning;
                    $summary['readiness_messages'] = $readiness['messages'];
                    $this->create_event([
                        'campaign_id' => $campaign['id'],
                        'event_type' => 'send_readiness_failed',
                        'provider' => $eventProvider,
                        'payload_json' => json_encode([
                            'readiness_messages' => array_values($readiness['messages']),
                            'missing_fields' => array_values($readiness['missing_fields']),
                            'manual_or_cron' => $manualOrCron,
                            'delivery_channel' => $deliveryChannel,
                        ]),
                    ]);
                    $this->create_event([
                        'campaign_id' => $campaign['id'],
                        'event_type' => 'batch_processed',
                        'provider' => $eventProvider,
                        'payload_json' => json_encode([
                            'processed' => 0,
                            'accepted' => 0,
                            'failed' => 0,
                            'recovered' => 0,
                            'failure_metrics' => $campaignFailureMetrics,
                            'warnings' => $campaignWarnings,
                            'manual_or_cron' => $manualOrCron,
                            'delivery_channel' => $deliveryChannel,
                        ]),
                    ]);
                    continue;
                }

                $campaignRecovered = $this->recover_stuck_processing($campaign['id']);
                $summary['recovered'] += $campaignRecovered;
                if ($campaignRecovered > 0) {
                    $this->append_unique_warning($summary['warnings'], 'stale_processing_recovered');
                    $this->append_unique_warning($campaignWarnings, 'stale_processing_recovered');
                }

                $queued = $this->count_sendable_recipients($campaign['id']);
                if ($queued === 0) {
                    $warning = 'campaign_' . $campaign['id'] . '_no_queued_recipients';
                    $summary['warnings'][] = $warning;
                    $campaignWarnings[] = $warning;
                    $this->create_event([
                        'campaign_id' => $campaign['id'],
                        'event_type' => 'batch_processed',
                        'provider' => $eventProvider,
                        'payload_json' => json_encode([
                            'processed' => 0,
                            'accepted' => 0,
                            'failed' => 0,
                            'recovered' => $campaignRecovered,
                            'failure_metrics' => $campaignFailureMetrics,
                            'warnings' => $campaignWarnings,
                            'manual_or_cron' => $manualOrCron,
                            'delivery_channel' => $deliveryChannel,
                        ]),
                    ]);
                    continue;
                }

            if (in_array($campaign['status'], ['draft', 'scheduled'], true)) {
                if ($this->mark_campaign_started($campaign)) {
                    $summary['campaigns_started']++;
                }
                $campaign['status'] = 'sending';
                $campaign['send_started_at'] = date('Y-m-d H:i:s');
            }

            $remaining = $limit - $summary['processed'];
            if ($remaining <= 0) {
                break;
            }

            $this->create_event([
                'campaign_id' => $campaign['id'],
                'event_type' => 'send_start',
                'provider' => $eventProvider,
                'payload_json' => json_encode([
                    'queued_before' => $queued,
                    'batch_limit' => $remaining,
                    'manual_or_cron' => $manualOrCron,
                    'delivery_channel' => $deliveryChannel,
                ]),
            ]);

            $reservation = $this->reserve_next_recipients($campaign['id'], $remaining, $workerLeaseToken);
            if (empty($reservation['success'])) {
                $reservationWarning = $reservation['warning'] ?? 'recipient_reservation_failed';
                $this->append_unique_warning($summary['warnings'], $reservationWarning);
                $this->append_unique_warning($campaignWarnings, $reservationWarning);
                $this->log_safe_queue_error(
                    'Marketing recipient reservation failed: campaign_id=' . (int) $campaign['id']
                    . ' warning=' . $reservationWarning
                    . ' reason=' . (string) ($reservation['error'] ?? 'unknown')
                );
                $this->create_event([
                    'campaign_id' => $campaign['id'],
                    'event_type' => 'batch_processed',
                    'provider' => $eventProvider,
                    'payload_json' => json_encode([
                        'processed' => 0,
                        'accepted' => 0,
                        'failed' => 0,
                        'recovered' => $campaignRecovered,
                        'failure_metrics' => $campaignFailureMetrics,
                        'warnings' => $campaignWarnings,
                        'manual_or_cron' => $manualOrCron,
                        'delivery_channel' => $deliveryChannel,
                    ]),
                ]);
                continue;
            }

            $recipients = $reservation['recipients'] ?? [];
            if (empty($recipients)) {
                if ($this->finalize_campaign_if_complete($campaign['id'])) {
                    $summary['campaigns_completed']++;
                }
                $this->create_event([
                    'campaign_id' => $campaign['id'],
                    'event_type' => 'batch_processed',
                    'provider' => $eventProvider,
                    'payload_json' => json_encode([
                        'processed' => 0,
                        'accepted' => 0,
                        'failed' => 0,
                        'recovered' => $campaignRecovered,
                        'failure_metrics' => $campaignFailureMetrics,
                        'warnings' => $campaignWarnings,
                        'manual_or_cron' => $manualOrCron,
                        'delivery_channel' => $deliveryChannel,
                    ]),
                ]);
                continue;
            }

            foreach ($recipients as $recipient) {
                $this->touch_campaign_worker_lease((int) $campaign['id'], $campaignWorkerToken);

                if (!$this->is_within_send_window()) {
                    $recoveredNow = $this->recover_stuck_processing($campaign['id']);
                    $summary['recovered'] += $recoveredNow;
                    $campaignRecovered += $recoveredNow;
                    $summary['warnings'][] = 'outside_send_window';
                    $campaignWarnings[] = 'outside_send_window';
                    log_message('info', 'Marketing campaign paused by send window during batch: campaign_id=' . (int) $campaign['id']);
                    break;
                }

                $liveRateSnapshot = $this->get_rate_limit_snapshot();
                if ($liveRateSnapshot['hourly_remaining'] !== null && $liveRateSnapshot['hourly_remaining'] <= 0) {
                    $recoveredNow = $this->recover_stuck_processing($campaign['id']);
                    $summary['recovered'] += $recoveredNow;
                    $campaignRecovered += $recoveredNow;
                    $summary['warnings'][] = 'hourly_cap_reached';
                    $campaignWarnings[] = 'hourly_cap_reached';
                    log_message('info', 'Marketing hourly cap reached during batch: campaign_id=' . (int) $campaign['id']);
                    break;
                }

                if ($liveRateSnapshot['daily_remaining'] !== null && $liveRateSnapshot['daily_remaining'] <= 0) {
                    $recoveredNow = $this->recover_stuck_processing($campaign['id']);
                    $summary['recovered'] += $recoveredNow;
                    $campaignRecovered += $recoveredNow;
                    $summary['warnings'][] = 'daily_cap_reached';
                    $campaignWarnings[] = 'daily_cap_reached';
                    log_message('info', 'Marketing daily cap reached during batch: campaign_id=' . (int) $campaign['id']);
                    break;
                }

                $preSendResult = null;
                if ($deliveryChannel === 'email') {
                    $attemptStart = $this->begin_reserved_outbound_attempt($recipient, $workerLeaseToken);
                    if (empty($attemptStart['success'])) {
                        if (!empty($attemptStart['blocked'])) {
                            $warning = (string) ($attemptStart['warning'] ?? 'outbound_attempt_replay_blocked');
                            $this->append_unique_warning($summary['warnings'], $warning);
                            $this->append_unique_warning($campaignWarnings, $warning);
                            log_message(
                                'warning',
                                'Marketing outbound attempt blocked before provider send: campaign_id=' . (int) $campaign['id']
                                . ' recipient_id=' . (int) $recipient['id']
                                . ' warning=' . $warning
                            );
                            if (!empty($attemptStart['recipient']) && is_array($attemptStart['recipient'])) {
                                $recipient = $attemptStart['recipient'];
                            }
                            $preSendResult = [
                                'success' => false,
                                'error' => (string) ($attemptStart['error'] ?? $warning),
                                'transient_failure' => false,
                                'provider_throttle' => false,
                            ];
                        } else {
                            $this->append_unique_warning($summary['warnings'], 'recipient_lease_conflict');
                            $this->append_unique_warning($campaignWarnings, 'recipient_lease_conflict');
                            log_message('warning', 'Marketing recipient lease conflict before outbound attempt assignment: campaign_id=' . (int) $campaign['id'] . ' recipient_id=' . (int) $recipient['id']);
                            continue;
                        }
                    } elseif (!empty($attemptStart['recipient']) && is_array($attemptStart['recipient'])) {
                        $recipient = $attemptStart['recipient'];
                    }
                }

                try {
                    if ($this->recipient_is_duplicate_for_send($recipient)) {
                        if (!$this->update_reserved_recipient_status($recipient['id'], $workerLeaseToken, 'skipped', [
                            'failure_reason' => 'duplicate_recipient_skipped',
                        ])) {
                            $this->append_unique_warning($summary['warnings'], 'recipient_lease_conflict');
                            $this->append_unique_warning($campaignWarnings, 'recipient_lease_conflict');
                            log_message('warning', 'Marketing recipient lease conflict on skipped update: campaign_id=' . (int) $campaign['id'] . ' recipient_id=' . (int) $recipient['id']);
                            continue;
                        }

                        $this->create_event([
                            'campaign_id' => $campaign['id'],
                            'campaign_recipient_id' => $recipient['id'],
                            'marketing_contact_id' => $recipient['marketing_contact_id'],
                            'event_type' => 'skipped',
                            'provider' => $eventProvider,
                            'payload_json' => json_encode([
                                'normalized_failure_code' => 'recipient_skipped',
                                'reason' => 'duplicate_recipient_skipped',
                                'delivery_channel' => $deliveryChannel,
                            ]),
                        ]);
                        $this->increment_metric_count($campaignFailureMetrics, 'recipient_skipped');

                        $summary['skipped']++;
                        $campaignProcessed++;
                        $summary['processed']++;
                        $remaining--;

                        if ($remaining <= 0) {
                            break;
                        }

                        continue;
                    }

                    $suppressionMatch = $this->get_active_suppression_for_recipient($recipient, $deliveryChannel);
                    if ($suppressionMatch['suppressed']) {
                        if (!$this->mark_recipient_suppressed($campaign, $recipient, $eventProvider, $deliveryChannel, $suppressionMatch, $workerLeaseToken)) {
                            $this->append_unique_warning($summary['warnings'], 'recipient_lease_conflict');
                            $this->append_unique_warning($campaignWarnings, 'recipient_lease_conflict');
                            log_message('warning', 'Marketing recipient lease conflict on suppression update: campaign_id=' . (int) $campaign['id'] . ' recipient_id=' . (int) $recipient['id']);
                            continue;
                        }

                        $summary['suppressed']++;
                        $normalizedSuppressionCode = $this->normalize_provider_failure_metric_code([
                            'suppressed' => true,
                            'suppression_reason' => $suppressionMatch['reason'] ?? null,
                        ]);
                        if ($normalizedSuppressionCode !== null) {
                            $this->increment_metric_count($campaignFailureMetrics, $normalizedSuppressionCode);
                        }
                        $campaignProcessed++;
                        $summary['processed']++;
                        $remaining--;

                        if ($remaining <= 0) {
                            break;
                        }

                        continue;
                    }

                    if ($preSendResult !== null) {
                        $result = $preSendResult;
                    } elseif ($deliveryChannel === 'whatsapp') {
                        $result = $this->marketing_whatsapp->send_template_message(
                            (string) ($recipient['destination'] ?? ''),
                            (string) ($campaign['whatsapp_template_name'] ?? ''),
                            (string) ($campaign['whatsapp_template_language'] ?? ''),
                            $this->build_whatsapp_template_components($campaign)
                        );
                    } else {
                        $result = $this->marketing_mailer->send_campaign_message($campaign, $recipient);
                    }
                } catch (Throwable $exception) {
                    $result = [
                        'success' => false,
                        'error' => $this->sanitize_failure_reason($exception->getMessage()),
                    ];
                }

                if (!empty($result['suppressed'])) {
                    $mailSuppressionMatch = [
                        'suppressed' => true,
                        'type' => (string) ($result['suppression_type'] ?? 'email'),
                        'reason' => (string) ($result['suppression_reason'] ?? 'suppressed_email'),
                    ];
                    if (!$this->mark_recipient_suppressed($campaign, $recipient, $eventProvider, $deliveryChannel, $mailSuppressionMatch, $workerLeaseToken)) {
                        $this->append_unique_warning($summary['warnings'], 'recipient_lease_conflict');
                        $this->append_unique_warning($campaignWarnings, 'recipient_lease_conflict');
                        log_message('warning', 'Marketing recipient lease conflict on mailer suppression update: campaign_id=' . (int) $campaign['id'] . ' recipient_id=' . (int) $recipient['id']);
                        continue;
                    }
                    $summary['suppressed']++;
                    $normalizedSuppressionCode = $this->normalize_provider_failure_metric_code([
                        'suppressed' => true,
                        'suppression_reason' => $mailSuppressionMatch['reason'] ?? null,
                    ]);
                    if ($normalizedSuppressionCode !== null) {
                        $this->increment_metric_count($campaignFailureMetrics, $normalizedSuppressionCode);
                    }
                } elseif (!empty($result['success'])) {
                    $recipientUpdate = [
                        'sent_at' => date('Y-m-d H:i:s'),
                    ];
                    $resultMessageId = $this->normalize_message_identifier($result['message_id'] ?? null);
                    $resultProviderMessageId = $this->normalize_message_identifier($result['provider_message_id'] ?? $resultMessageId);
                    if ($resultMessageId !== null) {
                        $recipientUpdate['message_id'] = $resultMessageId;
                    }
                    if ($resultProviderMessageId !== null) {
                        $recipientUpdate['provider_message_id'] = $resultProviderMessageId;
                    }

                    if (!$this->update_reserved_recipient_status($recipient['id'], $workerLeaseToken, 'sent', $recipientUpdate)) {
                        $this->append_unique_warning($summary['warnings'], 'recipient_lease_conflict');
                        $this->append_unique_warning($campaignWarnings, 'recipient_lease_conflict');
                        log_message('warning', 'Marketing recipient lease conflict on sent update: campaign_id=' . (int) $campaign['id'] . ' recipient_id=' . (int) $recipient['id']);
                        continue;
                    }

                    $this->create_event([
                        'campaign_id' => $campaign['id'],
                        'campaign_recipient_id' => $recipient['id'],
                        'marketing_contact_id' => $recipient['marketing_contact_id'],
                        'event_type' => $deliveryChannel === 'whatsapp' ? 'whatsapp_sent' : 'sent',
                        'provider' => $eventProvider,
                        'provider_message_id' => $resultProviderMessageId,
                        'email' => $recipient['email'],
                        'payload_json' => json_encode([
                            'outcome' => 'accepted_for_sending',
                            'delivery_channel' => $deliveryChannel,
                            'destination' => $recipient['destination'] ?? null,
                            'configuration_set' => $deliveryChannel === 'email' ? ($result['configuration_set'] ?? null) : null,
                            'configuration_set_applied' => !empty($result['configuration_set_applied']),
                            'ses_tags_applied' => !empty($result['ses_tags_applied']),
                            'correlation_id' => $resultMessageId,
                            'note' => $deliveryChannel === 'whatsapp'
                                ? 'WhatsApp template request accepted by provider.'
                                : 'SMTP success indicates acceptance for sending. Delivery outcomes are updated from webhook events.',
                        ]),
                    ]);

                    $summary['accepted']++;
                    $campaignAccepted++;
                } else {
                    $failureReason = $this->sanitize_failure_reason($result['error'] ?? 'send_failed');
                    $isTransientFailure = $deliveryChannel === 'email' && !empty($result['transient_failure']);
                    $isProviderThrottle = $deliveryChannel === 'email' && !empty($result['provider_throttle']);
                    $normalizedFailureCode = $this->normalize_provider_failure_metric_code([
                        'event_type' => 'failed',
                        'provider_failure_code' => $result['provider_failure_code'] ?? null,
                        'provider_throttle' => $isProviderThrottle,
                        'transient_failure' => $isTransientFailure,
                        'provider_rendering_failure' => !empty($result['provider_rendering_failure']),
                        'failure_reason' => $failureReason,
                    ]);
                    if ($isProviderThrottle) {
                        $failureReason = 'temporary_provider_throttle: ' . $failureReason;
                    } elseif ($isTransientFailure) {
                        $failureReason = 'temporary_provider_failure: ' . $failureReason;
                    }
                    $failedRecipientUpdate = [
                        'failed_at' => date('Y-m-d H:i:s'),
                        'failure_reason' => $failureReason,
                    ];
                    $resultMessageId = $this->normalize_message_identifier($result['message_id'] ?? null);
                    $resultProviderMessageId = $this->normalize_message_identifier($result['provider_message_id'] ?? $resultMessageId);
                    if ($resultMessageId !== null) {
                        $failedRecipientUpdate['message_id'] = $resultMessageId;
                    }
                    if ($resultProviderMessageId !== null) {
                        $failedRecipientUpdate['provider_message_id'] = $resultProviderMessageId;
                    }

                    if (!$this->update_reserved_recipient_status($recipient['id'], $workerLeaseToken, 'failed', $failedRecipientUpdate)) {
                        $this->append_unique_warning($summary['warnings'], 'recipient_lease_conflict');
                        $this->append_unique_warning($campaignWarnings, 'recipient_lease_conflict');
                        log_message('warning', 'Marketing recipient lease conflict on failed update: campaign_id=' . (int) $campaign['id'] . ' recipient_id=' . (int) $recipient['id']);
                        continue;
                    }

                    $this->create_event([
                        'campaign_id' => $campaign['id'],
                        'campaign_recipient_id' => $recipient['id'],
                        'marketing_contact_id' => $recipient['marketing_contact_id'],
                        'event_type' => $deliveryChannel === 'whatsapp' ? 'whatsapp_failed' : 'failed',
                        'provider' => $eventProvider,
                        'provider_message_id' => $resultProviderMessageId,
                        'email' => $recipient['email'],
                        'payload_json' => json_encode([
                            'normalized_failure_code' => $normalizedFailureCode,
                            'failure_reason' => $failureReason,
                            'delivery_channel' => $deliveryChannel,
                            'destination' => $recipient['destination'] ?? null,
                            'configuration_set' => $deliveryChannel === 'email' ? ($result['configuration_set'] ?? null) : null,
                            'configuration_set_applied' => !empty($result['configuration_set_applied']),
                            'ses_tags_applied' => !empty($result['ses_tags_applied']),
                            'correlation_id' => $resultMessageId,
                        ]),
                    ]);

                    log_message(
                        'error',
                        'Marketing recipient send failed: campaign_id=' . (int) $campaign['id']
                        . ' recipient_id=' . (int) $recipient['id']
                        . ' channel=' . $deliveryChannel
                        . ' provider=' . $eventProvider
                        . ' reason=' . $failureReason
                    );

                    $summary['failed']++;
                    $campaignFailed++;
                    if ($normalizedFailureCode !== null) {
                        $this->increment_metric_count($campaignFailureMetrics, $normalizedFailureCode);
                    }

                    if ($isProviderThrottle || $isTransientFailure) {
                        $warning = $isProviderThrottle ? 'provider_throttle_detected' : 'transient_delivery_failure';
                        $this->append_unique_warning($summary['warnings'], $warning);
                        $this->append_unique_warning($campaignWarnings, $warning);
                        log_message(
                            'warning',
                            'Marketing campaign batch paused after temporary provider failure: campaign_id=' . (int) $campaign['id']
                            . ' recipient_id=' . (int) $recipient['id']
                            . ' channel=' . $deliveryChannel
                            . ' warning=' . $warning
                        );
                    }
                }

                $summary['processed']++;
                $campaignProcessed++;
                $remaining--;

                if (($deliveryChannel === 'email' && !empty($result['provider_throttle'])) || ($deliveryChannel === 'email' && !empty($result['transient_failure']))) {
                    break;
                }

                if ($remaining <= 0) {
                    break;
                }

                if ($sleepMs > 0) {
                    usleep($sleepMs * 1000);
                }
            }

            if ($this->finalize_campaign_if_complete($campaign['id'])) {
                $summary['campaigns_completed']++;
            }

            $this->create_event([
                'campaign_id' => $campaign['id'],
                'event_type' => 'batch_processed',
                'provider' => $eventProvider,
                'payload_json' => json_encode([
                    'processed' => $campaignProcessed,
                    'accepted' => $campaignAccepted,
                    'failed' => $campaignFailed,
                    'recovered' => $campaignRecovered,
                    'failure_metrics' => $campaignFailureMetrics,
                    'warnings' => $campaignWarnings,
                    'manual_or_cron' => $manualOrCron,
                    'delivery_channel' => $deliveryChannel,
                ]),
            ]);

                if ($summary['processed'] >= $limit) {
                    break;
                }
            } finally {
                $this->release_campaign_worker_lease((int) $campaign['id'], $campaignWorkerToken);
                if ($campaignLockKey !== '') {
                    $this->release_campaign_send_lock($campaignLockKey);
                }
            }
        }

        return $summary;
    }

    public function get_queue_diagnostics($recentBatchLimit = 10, $recentWarningLimit = 5)
    {
        $recentBatchLimit = max(1, min(25, (int) $recentBatchLimit));
        $recentWarningLimit = max(1, min(10, (int) $recentWarningLimit));

        $queueSchemaCheck = $this->get_queue_schema_check();
        $diagnostics = [
            'schema_ready' => $queueSchemaCheck['ready'],
            'schema_missing' => array_values($queueSchemaCheck['missing']),
            'campaign_worker_leases_active' => 0,
            'campaign_worker_leases_stale' => 0,
            'recipients_processing' => 0,
            'recipients_processing_stale' => 0,
            'recipients_queued' => 0,
            'recipients_failed' => 0,
            'recent_recovered_total' => 0,
            'recent_warning_counts' => [],
            'recent_failure_metric_counts' => [],
        ];

        if ($this->db->table_exists($this->table) && $this->column_exists($this->table, 'queue_worker_token')) {
            $select = ['id', 'queue_worker_token'];
            $select[] = $this->column_exists($this->table, 'queue_worker_started_at')
                ? 'queue_worker_started_at'
                : 'NULL AS queue_worker_started_at';
            $select[] = $this->column_exists($this->table, 'queue_worker_heartbeat_at')
                ? 'queue_worker_heartbeat_at'
                : 'NULL AS queue_worker_heartbeat_at';

            $leaseRows = $this->db->select(implode(', ', $select), false)
                ->from($this->table)
                ->where('queue_worker_token IS NOT NULL', null, false)
                ->where('queue_worker_token !=', '')
                ->get()
                ->result_array();

            $diagnostics['campaign_worker_leases_active'] = count($leaseRows);

            foreach ($leaseRows as $leaseRow) {
                if ($this->campaign_worker_lease_is_stale($leaseRow)) {
                    $diagnostics['campaign_worker_leases_stale']++;
                }
            }
        }

        if ($this->db->table_exists($this->recipientsTable) && $this->column_exists($this->recipientsTable, 'status')) {
            $diagnostics['recipients_queued'] = total_rows($this->recipientsTable, ['status' => 'queued']);
            $diagnostics['recipients_failed'] = total_rows($this->recipientsTable, ['status' => 'failed']);
            $diagnostics['recipients_processing'] = total_rows($this->recipientsTable, ['status' => 'processing']);

            if ($this->column_exists($this->recipientsTable, 'dateupdated')) {
                $cutoffDateTime = date('Y-m-d H:i:s', time() - self::PROCESSING_STALE_TIMEOUT_SECONDS);
                $this->db->from($this->recipientsTable . ' r');
                $this->db->where('r.status', 'processing');

                if (
                    $this->db->table_exists($this->table)
                    && $this->column_exists($this->recipientsTable, 'campaign_id')
                    && $this->column_exists($this->table, 'send_started_at')
                ) {
                    $this->db->join($this->table . ' c', 'c.id = r.campaign_id', 'left');
                }

                $this->db->group_start();
                $this->db->where('r.dateupdated <=', $cutoffDateTime);
                if (
                    $this->db->table_exists($this->table)
                    && $this->column_exists($this->recipientsTable, 'campaign_id')
                    && $this->column_exists($this->table, 'send_started_at')
                ) {
                    $this->db->or_group_start();
                    $this->db->where('r.dateupdated IS NULL', null, false);
                    $this->db->where('c.send_started_at <=', $cutoffDateTime);
                    $this->db->group_end();
                }
                $this->db->group_end();

                $diagnostics['recipients_processing_stale'] = (int) $this->db->count_all_results();
            }
        }

        if (
            $this->db->table_exists($this->eventsTable)
            && $this->column_exists($this->eventsTable, 'event_type')
            && $this->column_exists($this->eventsTable, 'payload_json')
        ) {
            $this->db->select(
                ($this->column_exists($this->eventsTable, 'campaign_id') ? 'campaign_id' : 'NULL AS campaign_id')
                . ', '
                . ($this->column_exists($this->eventsTable, 'event_at') ? 'event_at' : 'NULL AS event_at')
                . ', payload_json',
                false
            );
            $this->db->from($this->eventsTable);
            $this->db->where($this->normalized_event_type_sql() . ' = ' . $this->db->escape('batch_processed'), null, false);

            if ($this->column_exists($this->eventsTable, 'event_at')) {
                $this->db->order_by('event_at', 'DESC');
            } else {
                $this->db->order_by('id', 'DESC');
            }

            $rows = $this->db->limit($recentBatchLimit)->get()->result_array();
            $warningCounts = [];

            foreach ($rows as $row) {
                $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
                if (!is_array($payload)) {
                    continue;
                }

                $diagnostics['recent_recovered_total'] += max(0, (int) ($payload['recovered'] ?? 0));

                $warnings = $payload['warnings'] ?? [];
                if (!is_array($warnings)) {
                    continue;
                }

                foreach ($warnings as $warning) {
                    $warning = trim((string) $warning);
                    if ($warning === '') {
                        continue;
                    }

                    if (!isset($warningCounts[$warning])) {
                        $warningCounts[$warning] = 0;
                    }
                    $warningCounts[$warning]++;
                }
            }

            if (!empty($warningCounts)) {
                arsort($warningCounts);
                $diagnostics['recent_warning_counts'] = array_slice($warningCounts, 0, $recentWarningLimit, true);
            }
        }

        if (
            $this->db->table_exists($this->eventsTable)
            && $this->column_exists($this->eventsTable, 'payload_json')
        ) {
            $eventMetricRows = $this->db->select('payload_json', false)
                ->from($this->eventsTable)
                ->order_by($this->column_exists($this->eventsTable, 'event_at') ? 'event_at' : 'id', 'DESC')
                ->limit(max(20, $recentBatchLimit * 5))
                ->get()
                ->result_array();

            $eventMetricCounts = $diagnostics['recent_failure_metric_counts'];
            foreach ($eventMetricRows as $row) {
                $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
                if (!is_array($payload)) {
                    continue;
                }

                $normalizedFailureCode = trim((string) ($payload['normalized_failure_code'] ?? ($payload['_failure_metrics']['normalized_failure_code'] ?? '')));
                if (!$this->is_valid_failure_metric_code($normalizedFailureCode)) {
                    continue;
                }

                if (!isset($eventMetricCounts[$normalizedFailureCode])) {
                    $eventMetricCounts[$normalizedFailureCode] = 0;
                }
                $eventMetricCounts[$normalizedFailureCode]++;
            }

            if (!empty($eventMetricCounts)) {
                arsort($eventMetricCounts);
                $diagnostics['recent_failure_metric_counts'] = array_slice($eventMetricCounts, 0, $recentWarningLimit, true);
            }
        }

        return $diagnostics;
    }

    protected function count_sent_events_since($fromDateTime)
    {
        if (!$this->column_exists($this->eventsTable, 'event_type')) {
            return 0;
        }

        $this->db->from($this->eventsTable);
        $this->db->where_in('event_type', ['sent', 'whatsapp_sent']);

        if ($this->column_exists($this->eventsTable, 'event_at')) {
            $this->db->where('event_at >=', $fromDateTime);
        } elseif ($this->column_exists($this->eventsTable, 'datecreated')) {
            $this->db->where('datecreated >=', $fromDateTime);
        } else {
            return 0;
        }

        return (int) $this->db->count_all_results();
    }

    public function get_whatsapp_event_summary($campaignId)
    {
        $summary = [
            'sent' => 0,
            'delivered' => 0,
            'read' => 0,
            'failed' => 0,
        ];

        if (!$this->column_exists($this->eventsTable, 'event_type')) {
            return $summary;
        }

        $rows = $this->db->select('event_type, COUNT(*) as total')
            ->from($this->eventsTable)
            ->where('campaign_id', (int) $campaignId)
            ->where_in('event_type', ['whatsapp_sent', 'whatsapp_delivered', 'whatsapp_read', 'whatsapp_failed'])
            ->group_by('event_type')
            ->get()
            ->result_array();

        foreach ($rows as $row) {
            if ($row['event_type'] === 'whatsapp_sent') {
                $summary['sent'] = (int) $row['total'];
            } elseif ($row['event_type'] === 'whatsapp_delivered') {
                $summary['delivered'] = (int) $row['total'];
            } elseif ($row['event_type'] === 'whatsapp_read') {
                $summary['read'] = (int) $row['total'];
            } elseif ($row['event_type'] === 'whatsapp_failed') {
                $summary['failed'] = (int) $row['total'];
            }
        }

        return $summary;
    }

    protected function build_whatsapp_template_components(array $campaign)
    {
        $parameters = [];

        foreach (['whatsapp_template_var1', 'whatsapp_template_var2', 'whatsapp_template_var3'] as $field) {
            $value = trim((string) ($campaign[$field] ?? ''));
            if ($value !== '') {
                $parameters[] = [
                    'type' => 'text',
                    'text' => $value,
                ];
            }
        }

        if (empty($parameters)) {
            return [];
        }

        return [[
            'type' => 'body',
            'parameters' => $parameters,
        ]];
    }

    private function reserve_next_recipients($campaignId, $limit, $workerLeaseToken = '')
    {
        $limit = (int) $limit;
        if ($limit <= 0) {
            return [
                'success' => true,
                'recipients' => [],
                'warning' => null,
                'error' => null,
            ];
        }

        $this->db->trans_begin();

        $recipientIdsQuery = $this->db->select('id')
            ->from($this->recipientsTable)
            ->where('campaign_id', (int) $campaignId)
            ->where_in('status', self::SENDABLE_RECIPIENT_STATUSES)
            ->order_by('id', 'ASC')
            ->limit($limit)
            ->get();

        if ($recipientIdsQuery === false) {
            $this->db->trans_rollback();

            return [
                'success' => false,
                'recipients' => [],
                'warning' => 'recipient_reservation_failed',
                'error' => 'recipient_select_failed',
            ];
        }

        $recipientIds = $recipientIdsQuery->result_array();

        if (empty($recipientIds)) {
            $this->db->trans_commit();

            return [
                'success' => true,
                'recipients' => [],
                'warning' => null,
                'error' => null,
            ];
        }

        $recipientIds = array_column($recipientIds, 'id');

        $update = [
            'status' => 'processing',
            'processing_token' => $workerLeaseToken !== '' ? $workerLeaseToken : null,
            'dateupdated' => date('Y-m-d H:i:s'),
        ];
        $update = $this->filter_existing_columns($this->recipientsTable, $update);
        if (empty($update)) {
            $this->db->trans_rollback();

            return [
                'success' => false,
                'recipients' => [],
                'warning' => 'recipient_reservation_failed',
                'error' => 'recipient_reservation_update_filtered',
            ];
        }

        $this->db->where_in('id', $recipientIds);
        $this->db->where_in('status', self::SENDABLE_RECIPIENT_STATUSES);
        $updated = $this->db->update($this->recipientsTable, $update);
        if (!$updated || $this->db->trans_status() === false) {
            $this->db->trans_rollback();

            return [
                'success' => false,
                'recipients' => [],
                'warning' => 'recipient_reservation_failed',
                'error' => 'recipient_reservation_update_failed',
            ];
        }

        $this->db->where_in('id', $recipientIds);
        $this->db->where('status', 'processing');
        if ($workerLeaseToken !== '' && $this->column_exists($this->recipientsTable, 'processing_token')) {
            $this->db->where('processing_token', $workerLeaseToken);
        }

        $reservedQuery = $this->db->get($this->recipientsTable);
        if ($reservedQuery === false) {
            $this->db->trans_rollback();

            return [
                'success' => false,
                'recipients' => [],
                'warning' => 'recipient_reservation_failed',
                'error' => 'recipient_reservation_fetch_failed',
            ];
        }

        $recipients = $reservedQuery->result_array();
        if (count($recipients) !== count($recipientIds)) {
            $this->db->trans_rollback();

            return [
                'success' => false,
                'recipients' => [],
                'warning' => 'recipient_reservation_conflict',
                'error' => 'recipient_reservation_count_mismatch',
            ];
        }

        if ($this->db->trans_status() === false) {
            $this->db->trans_rollback();

            return [
                'success' => false,
                'recipients' => [],
                'warning' => 'recipient_reservation_failed',
                'error' => 'recipient_reservation_transaction_failed',
            ];
        }

        $this->db->trans_commit();

        return [
            'success' => true,
            'recipients' => $recipients,
            'warning' => null,
            'error' => null,
        ];
    }

    private function acquire_campaign_send_lock($campaignId, $timeout = 0)
    {
        $campaignId = (int) $campaignId;
        if ($campaignId <= 0) {
            return [
                'supported' => false,
                'acquired' => false,
                'lock_key' => '',
            ];
        }

        $lockKey = 'mkt_send_campaign_' . $campaignId;
        $query = $this->db->query('SELECT GET_LOCK(?, ?) AS campaign_lock', [$lockKey, max(0, (int) $timeout)]);
        if ($query === false) {
            return [
                'supported' => false,
                'acquired' => false,
                'lock_key' => '',
            ];
        }

        $row = $query->row_array();

        return [
            'supported' => true,
            'acquired' => (string) ($row['campaign_lock'] ?? '0') === '1',
            'lock_key' => $lockKey,
        ];
    }

    private function release_campaign_send_lock($lockKey)
    {
        $lockKey = trim((string) $lockKey);
        if ($lockKey === '') {
            return false;
        }

        $query = $this->db->query('SELECT RELEASE_LOCK(?) AS campaign_lock', [$lockKey]);
        if ($query === false) {
            return false;
        }

        $row = $query->row_array();

        return (string) ($row['campaign_lock'] ?? '0') === '1';
    }

    private function recover_stuck_processing($campaignId = null)
    {
        $campaign = $campaignId !== null ? $this->get((int) $campaignId) : null;
        $cutoffTimestamp = time() - self::PROCESSING_STALE_TIMEOUT_SECONDS;
        $cutoffDateTime = date('Y-m-d H:i:s', $cutoffTimestamp);
        $recipientHasDateUpdated = $this->column_exists($this->recipientsTable, 'dateupdated');
        $campaignSendStartedAtIsStale = $campaign !== null && $this->campaign_send_started_before($campaign, $cutoffTimestamp);

        if (!$recipientHasDateUpdated && !$campaignSendStartedAtIsStale) {
            return 0;
        }

        $update = [
            'status' => 'queued',
            'processing_token' => null,
            'queued_at' => date('Y-m-d H:i:s'),
            'dateupdated' => date('Y-m-d H:i:s'),
        ];
        $update = $this->filter_existing_columns($this->recipientsTable, $update);

        $this->db->where('status', 'processing');
        if ($campaignId !== null) {
            $this->db->where('campaign_id', (int) $campaignId);
        }

        if ($recipientHasDateUpdated) {
            $this->db->group_start();
            $this->db->where('dateupdated <=', $cutoffDateTime);
            if ($campaignSendStartedAtIsStale) {
                $this->db->or_where('dateupdated IS NULL', null, false);
            }
            $this->db->group_end();
        }

        $this->db->update($this->recipientsTable, $update);

        return (int) $this->db->affected_rows();
    }

    private function acquire_campaign_worker_lease($campaignId, $workerToken)
    {
        $campaignId = (int) $campaignId;
        $workerToken = trim((string) $workerToken);
        if ($campaignId <= 0 || $workerToken === '') {
            return [
                'acquired' => false,
                'warning' => 'campaign_worker_lease_failed',
                'reason' => 'invalid_campaign_worker_lease_request',
            ];
        }

        if (
            !$this->column_exists($this->table, 'queue_worker_token')
            || !$this->column_exists($this->table, 'queue_worker_started_at')
            || !$this->column_exists($this->table, 'queue_worker_heartbeat_at')
        ) {
            return [
                'acquired' => false,
                'warning' => 'campaign_worker_lease_missing',
                'reason' => 'campaign_worker_lease_columns_missing',
            ];
        }

        $campaign = $this->get($campaignId);
        if (!$campaign) {
            return [
                'acquired' => false,
                'warning' => 'campaign_not_found',
                'reason' => 'campaign_not_found',
            ];
        }

        $now = date('Y-m-d H:i:s');
        $heartbeatAt = trim((string) ($campaign['queue_worker_heartbeat_at'] ?? ''));
        $currentToken = trim((string) ($campaign['queue_worker_token'] ?? ''));
        $staleLease = $this->campaign_worker_lease_is_stale($campaign);

        if ($currentToken !== '' && hash_equals($currentToken, $workerToken)) {
            $this->touch_campaign_worker_lease($campaignId, $workerToken);

            return [
                'acquired' => true,
                'warning' => null,
                'reason' => null,
                'recovered_stale' => false,
            ];
        }

        if ($currentToken !== '' && !$staleLease) {
            return [
                'acquired' => false,
                'warning' => 'campaign_' . $campaignId . '_worker_locked',
                'reason' => 'campaign_worker_lease_active',
                'owner_started_at' => $heartbeatAt !== '' ? $heartbeatAt : ($campaign['queue_worker_started_at'] ?? null),
            ];
        }

        $update = $this->filter_existing_columns($this->table, [
            'queue_worker_token' => $workerToken,
            'queue_worker_started_at' => $now,
            'queue_worker_heartbeat_at' => $now,
            'dateupdated' => $now,
        ]);
        if (empty($update)) {
            return [
                'acquired' => false,
                'warning' => 'campaign_worker_lease_failed',
                'reason' => 'campaign_worker_lease_update_filtered',
            ];
        }

        $this->db->trans_begin();
        $this->db->where('id', $campaignId);
        $this->db->group_start();
        $this->db->where('queue_worker_token IS NULL', null, false);
        $this->db->or_where('queue_worker_token', '');
        if ($staleLease) {
            $this->db->or_where('queue_worker_token', $currentToken);
        }
        $this->db->group_end();
        $updated = $this->db->update($this->table, $update);

        if (!$updated || $this->db->trans_status() === false || (int) $this->db->affected_rows() === 0) {
            $this->db->trans_rollback();

            return [
                'acquired' => false,
                'warning' => 'campaign_' . $campaignId . '_worker_locked',
                'reason' => $staleLease ? 'campaign_worker_lease_takeover_failed' : 'campaign_worker_lease_race_lost',
            ];
        }

        $this->db->trans_commit();

        if ($staleLease) {
            log_message(
                'warning',
                'Marketing recovered stale campaign worker lease: campaign_id=' . $campaignId
                . ' previous_heartbeat=' . ($heartbeatAt !== '' ? $heartbeatAt : 'n/a')
            );
        }

        return [
            'acquired' => true,
            'warning' => null,
            'reason' => null,
            'recovered_stale' => $staleLease,
        ];
    }

    private function touch_campaign_worker_lease($campaignId, $workerToken)
    {
        $campaignId = (int) $campaignId;
        $workerToken = trim((string) $workerToken);
        if ($campaignId <= 0 || $workerToken === '' || !$this->column_exists($this->table, 'queue_worker_heartbeat_at')) {
            return false;
        }

        $update = $this->filter_existing_columns($this->table, [
            'queue_worker_heartbeat_at' => date('Y-m-d H:i:s'),
            'dateupdated' => date('Y-m-d H:i:s'),
        ]);
        if (empty($update)) {
            return false;
        }

        $this->db->where('id', $campaignId);
        $this->db->where('queue_worker_token', $workerToken);
        $this->db->update($this->table, $update);

        return $this->db->affected_rows() >= 0;
    }

    private function release_campaign_worker_lease($campaignId, $workerToken)
    {
        $campaignId = (int) $campaignId;
        $workerToken = trim((string) $workerToken);
        if ($campaignId <= 0 || $workerToken === '' || !$this->column_exists($this->table, 'queue_worker_token')) {
            return false;
        }

        $update = $this->filter_existing_columns($this->table, [
            'queue_worker_token' => null,
            'queue_worker_started_at' => null,
            'queue_worker_heartbeat_at' => null,
            'dateupdated' => date('Y-m-d H:i:s'),
        ]);
        if (empty($update)) {
            return false;
        }

        $this->db->where('id', $campaignId);
        $this->db->where('queue_worker_token', $workerToken);
        $this->db->update($this->table, $update);

        return $this->db->affected_rows() !== 0;
    }

    private function update_recipient_status($recipientId, $status, array $data = [])
    {
        $data['status'] = $status;
        if ($this->column_exists($this->recipientsTable, 'processing_token') && !array_key_exists('processing_token', $data) && $status !== 'processing') {
            $data['processing_token'] = null;
        }
        if ($this->column_exists($this->recipientsTable, 'dateupdated') && !array_key_exists('dateupdated', $data)) {
            $data['dateupdated'] = date('Y-m-d H:i:s');
        }
        $data = $this->filter_existing_columns($this->recipientsTable, $data);

        if (empty($data)) {
            return false;
        }

        $this->db->where('id', (int) $recipientId);
        $this->db->update($this->recipientsTable, $data);

        return $this->db->affected_rows() !== 0;
    }

    private function update_reserved_recipient_status($recipientId, $workerLeaseToken, $status, array $data = [])
    {
        $workerLeaseToken = trim((string) $workerLeaseToken);
        $data['status'] = $status;

        if ($this->column_exists($this->recipientsTable, 'processing_token') && !array_key_exists('processing_token', $data) && $status !== 'processing') {
            $data['processing_token'] = null;
        }
        if ($this->column_exists($this->recipientsTable, 'dateupdated') && !array_key_exists('dateupdated', $data)) {
            $data['dateupdated'] = date('Y-m-d H:i:s');
        }

        $data = $this->filter_existing_columns($this->recipientsTable, $data);
        if (empty($data)) {
            return false;
        }

        $this->db->where('id', (int) $recipientId);
        $this->db->where('status', 'processing');
        if ($workerLeaseToken !== '' && $this->column_exists($this->recipientsTable, 'processing_token')) {
            $this->db->where('processing_token', $workerLeaseToken);
        }
        $this->db->update($this->recipientsTable, $data);

        return $this->db->affected_rows() !== 0;
    }

    private function begin_reserved_outbound_attempt(array $recipient, $workerLeaseToken)
    {
        $recipientId = (int) ($recipient['id'] ?? 0);
        if ($recipientId <= 0) {
            return [
                'success' => false,
                'blocked' => false,
                'warning' => 'recipient_lease_conflict',
                'error' => 'invalid_recipient',
            ];
        }

        $current = $this->db->from($this->recipientsTable)
            ->where('id', $recipientId)
            ->where('status', 'processing')
            ->limit(1)
            ->get()
            ->row_array();

        if (!$current) {
            return [
                'success' => false,
                'blocked' => false,
                'warning' => 'recipient_lease_conflict',
                'error' => 'recipient_not_processing',
            ];
        }

        if ($workerLeaseToken !== '' && $this->column_exists($this->recipientsTable, 'processing_token')) {
            $currentLeaseToken = trim((string) ($current['processing_token'] ?? ''));
            if ($currentLeaseToken === '' || !hash_equals($currentLeaseToken, trim((string) $workerLeaseToken))) {
                return [
                    'success' => false,
                    'blocked' => false,
                    'warning' => 'recipient_lease_conflict',
                    'error' => 'recipient_processing_token_mismatch',
                    'recipient' => $current,
                ];
            }
        }

        $currentStatus = strtolower(trim((string) ($current['status'] ?? '')));
        if (in_array($currentStatus, ['sent', 'suppressed', 'skipped'], true) || !empty($current['sent_at'])) {
            return [
                'success' => false,
                'blocked' => true,
                'warning' => 'completed_send_marker_present',
                'error' => 'completed_send_marker_present',
                'recipient' => $current,
            ];
        }

        $existingAttemptToken = trim((string) ($current['outbound_attempt_token'] ?? ''));
        if ($existingAttemptToken !== '') {
            return [
                'success' => false,
                'blocked' => true,
                'warning' => 'outbound_attempt_replay_blocked',
                'error' => 'outbound_attempt_replay_blocked',
                'recipient' => $current,
            ];
        }

        $attemptToken = $this->generate_outbound_attempt_token((int) ($current['campaign_id'] ?? 0), $recipientId);
        $update = $this->filter_existing_columns($this->recipientsTable, [
            'outbound_attempt_token' => $attemptToken,
            'dateupdated' => date('Y-m-d H:i:s'),
        ]);

        if (empty($update)) {
            return [
                'success' => false,
                'blocked' => false,
                'warning' => 'recipient_lease_conflict',
                'error' => 'outbound_attempt_update_filtered',
                'recipient' => $current,
            ];
        }

        $this->db->where('id', $recipientId);
        $this->db->where('status', 'processing');
        if ($workerLeaseToken !== '' && $this->column_exists($this->recipientsTable, 'processing_token')) {
            $this->db->where('processing_token', $workerLeaseToken);
        }
        $this->db->update($this->recipientsTable, $update);

        if ($this->db->affected_rows() === 0) {
            return [
                'success' => false,
                'blocked' => false,
                'warning' => 'recipient_lease_conflict',
                'error' => 'outbound_attempt_assignment_failed',
                'recipient' => $current,
            ];
        }

        $current['outbound_attempt_token'] = $attemptToken;
        $current['dateupdated'] = $update['dateupdated'] ?? ($current['dateupdated'] ?? null);

        return [
            'success' => true,
            'blocked' => false,
            'warning' => null,
            'error' => null,
            'recipient' => $current,
            'outbound_attempt_token' => $attemptToken,
        ];
    }

    private function generate_recipient_processing_token($campaignId)
    {
        try {
            $token = bin2hex(random_bytes(16));
        } catch (Throwable $exception) {
            $token = substr(hash('sha256', uniqid((string) $campaignId, true) . ':' . microtime(true) . ':' . mt_rand()), 0, 32);
        }

        return 'cmp' . (int) $campaignId . '_' . $token;
    }

    private function generate_campaign_worker_token($campaignId)
    {
        try {
            $token = bin2hex(random_bytes(16));
        } catch (Throwable $exception) {
            $token = substr(hash('sha256', uniqid((string) $campaignId, true) . ':' . microtime(true) . ':' . mt_rand()), 0, 32);
        }

        return 'wrk' . (int) $campaignId . '_' . $token;
    }

    private function generate_outbound_attempt_token($campaignId, $recipientId)
    {
        try {
            $token = bin2hex(random_bytes(16));
        } catch (Throwable $exception) {
            $token = substr(hash('sha256', uniqid((string) $campaignId . ':' . (string) $recipientId, true) . ':' . microtime(true) . ':' . mt_rand()), 0, 32);
        }

        return 'out' . (int) $campaignId . '_' . (int) $recipientId . '_' . $token;
    }

    private function append_unique_warning(array &$warnings, $warning)
    {
        $warning = trim((string) $warning);
        if ($warning === '' || in_array($warning, $warnings, true)) {
            return;
        }

        $warnings[] = $warning;
    }

    private function create_event(array $data)
    {
        if (!isset($data['event_type'])) {
            return false;
        }

        $CI = &get_instance();
        $CI->load->model('marketing/Marketing_events_model', 'marketing_events_model');

        return $CI->marketing_events_model->add_event($data);
    }

    private function get_events_model()
    {
        $CI = &get_instance();
        $CI->load->model('marketing/Marketing_events_model', 'marketing_events_model');

        return $CI->marketing_events_model;
    }

    private function should_count_recipient_click_metric($recipientId, array $event)
    {
        $provider = strtolower(trim((string) ($event['provider'] ?? '')));
        if ($provider !== 'ses_sns') {
            return true;
        }

        $url = trim((string) ($event['url'] ?? ''));
        if ($url !== '') {
            $path = parse_url($url, PHP_URL_PATH);
            $path = is_string($path) ? trim($path) : '';
            if ($path !== '' && strpos($path, '/marketing/track/c/') !== false) {
                return false;
            }
        }

        return !$this->get_events_model()->has_recent_module_click_metric(
            $recipientId,
            $url,
            $event['event_at'] ?? null
        );
    }

    public function apply_recipient_event($recipientId, array $event)
    {
        $recipient = $this->get_recipient_by_id($recipientId);
        if (!$recipient) {
            return [
                'matched' => false,
                'mutated' => false,
                'skipped' => true,
                'reason' => 'recipient_not_found',
                'normalized_failure_code' => 'recipient_reconciliation_skipped',
            ];
        }

        $eventType = strtolower(trim((string) ($event['event_type'] ?? '')));
        $eventAt = !empty($event['event_at']) ? (string) $event['event_at'] : date('Y-m-d H:i:s');
        $currentStatus = strtolower((string) ($recipient['status'] ?? ''));
        $reconciliation = $this->build_recipient_event_reconciliation($recipient, $event);
        if (empty($reconciliation['allow_mutation'])) {
            return [
                'matched' => true,
                'mutated' => false,
                'skipped' => true,
                'reason' => $reconciliation['reason'] ?? 'recipient_event_rejected',
                'normalized_failure_code' => 'recipient_reconciliation_skipped',
                'status_before' => $currentStatus,
                'status_after' => $currentStatus,
            ];
        }

        $shouldUpdateLastEventAt = !array_key_exists('update_last_event_at', $event) || !empty($event['update_last_event_at']);
        $update = [];
        $countersUpdated = false;

        if (!empty($reconciliation['adopt_message_id']) && empty($recipient['message_id'])) {
            $messageId = $this->normalize_message_identifier(
                $event['message_id'] ?? ($event['correlation_id'] ?? ($event['header_message_id'] ?? null))
            );
            if ($messageId !== null) {
                $update['message_id'] = $messageId;
            }
        }

        if (!empty($reconciliation['adopt_provider_message_id']) && empty($recipient['provider_message_id'])) {
            $providerMessageId = $this->normalize_message_identifier($event['provider_message_id'] ?? null);
            if ($providerMessageId !== null) {
                $update['provider_message_id'] = $providerMessageId;
            }
        }

        if ($shouldUpdateLastEventAt) {
            $update['last_event_at'] = $eventAt;
        }

        if ($eventType === 'delivered') {
            if (in_array($currentStatus, self::SENDABLE_RECIPIENT_STATUSES, true)) {
                $update['status'] = 'sent';
            }
            $countersUpdated = $this->increment_recipient_counts($recipientId, ['delivered_count' => 1]) || $countersUpdated;
        } elseif ($eventType === 'open') {
            if (in_array($currentStatus, self::SENDABLE_RECIPIENT_STATUSES, true)) {
                $update['status'] = 'sent';
            }
            $countersUpdated = $this->increment_recipient_counts($recipientId, ['open_count' => 1]) || $countersUpdated;
        } elseif ($eventType === 'click') {
            $this->ensure_recipient_click_metric_columns();

            if (in_array($currentStatus, self::SENDABLE_RECIPIENT_STATUSES, true)) {
                $update['status'] = 'sent';
            }
            $countMetric = array_key_exists('count_metric', $event)
                ? !empty($event['count_metric'])
                : $this->should_count_recipient_click_metric($recipientId, $event);
            $countUniqueMetric = $countMetric && !empty($event['count_unique_metric']);

            if ($countMetric) {
                $counters = ['click_count' => 1];
                if ($countUniqueMetric && $this->column_exists($this->recipientsTable, 'unique_click_count')) {
                    $counters['unique_click_count'] = 1;
                }
                $countersUpdated = $this->increment_recipient_counts($recipientId, $counters) || $countersUpdated;
            }

            if ($countMetric && !empty($event['url']) && $this->column_exists($this->recipientsTable, 'last_clicked_url')) {
                $update['last_clicked_url'] = (string) $event['url'];
            }
        } elseif (in_array($eventType, ['bounce', 'complaint'], true)) {
            $update['status'] = 'failed';
            $update['failed_at'] = $eventAt;
            $update['failure_reason'] = $this->build_event_failure_reason($event);
        } elseif (in_array($eventType, ['rejected', 'rendering_failure', 'rendering_failed', 'failed'], true)) {
            $update['status'] = 'failed';
            $update['failed_at'] = $eventAt;
            $update['failure_reason'] = $this->build_event_failure_reason($event);
        }

        $recordUpdated = !empty($update) ? $this->update_recipient_record($recipientId, $update) : false;
        $statusAfter = strtolower((string) ($update['status'] ?? $currentStatus));

        return [
            'matched' => true,
            'mutated' => $recordUpdated || $countersUpdated,
            'skipped' => false,
            'reason' => null,
            'normalized_failure_code' => $this->normalize_provider_failure_metric_code($event),
            'status_before' => $currentStatus,
            'status_after' => $statusAfter,
        ];
    }

    protected function build_recipient_event_reconciliation(array $recipient, array $event)
    {
        $eventType = strtolower(trim((string) ($event['event_type'] ?? '')));
        $currentStatus = strtolower(trim((string) ($recipient['status'] ?? '')));
        $identityCheck = $this->event_matches_active_outbound_identity($recipient, $event);

        if (empty($identityCheck['matched'])) {
            return [
                'allow_mutation' => false,
                'reason' => $identityCheck['reason'] ?? 'provider_identity_mismatch',
                'adopt_message_id' => false,
                'adopt_provider_message_id' => false,
            ];
        }

        if (in_array($eventType, ['delivered', 'open', 'click'], true)) {
            if ($this->recipient_status_blocks_engagement_events($currentStatus)) {
                return [
                    'allow_mutation' => false,
                    'reason' => 'terminal_status_blocks_event',
                    'adopt_message_id' => false,
                    'adopt_provider_message_id' => false,
                ];
            }

            if ($this->recipient_contact_state_blocks_engagement_events($recipient)) {
                return [
                    'allow_mutation' => false,
                    'reason' => 'contact_state_blocks_engagement',
                    'adopt_message_id' => false,
                    'adopt_provider_message_id' => false,
                ];
            }
        }

        if (in_array($eventType, ['bounce', 'complaint', 'rejected', 'rendering_failure', 'rendering_failed', 'failed'], true)
            && in_array($currentStatus, ['suppressed', 'skipped'], true)) {
            return [
                'allow_mutation' => false,
                'reason' => 'terminal_status_blocks_failure',
                'adopt_message_id' => false,
                'adopt_provider_message_id' => false,
            ];
        }

        $canAdoptIdentifiers = in_array((string) ($identityCheck['source'] ?? ''), ['outbound_attempt_token', 'message_identifier'], true);

        return [
            'allow_mutation' => true,
            'reason' => null,
            'adopt_message_id' => $canAdoptIdentifiers,
            'adopt_provider_message_id' => $canAdoptIdentifiers,
        ];
    }

    protected function event_matches_active_outbound_identity(array $recipient, array $event)
    {
        $recipientAttemptToken = trim((string) ($recipient['outbound_attempt_token'] ?? ''));
        $eventAttemptToken = trim((string) ($event['outbound_attempt_token'] ?? ''));

        if ($eventAttemptToken !== '') {
            if ($recipientAttemptToken === '') {
                return [
                    'matched' => false,
                    'reason' => 'outbound_attempt_token_mismatch',
                    'source' => 'outbound_attempt_token',
                ];
            }

            if (!hash_equals($recipientAttemptToken, $eventAttemptToken)) {
                return [
                    'matched' => false,
                    'reason' => 'outbound_attempt_token_mismatch',
                    'source' => 'outbound_attempt_token',
                ];
            }

            return [
                'matched' => true,
                'reason' => null,
                'source' => 'outbound_attempt_token',
            ];
        }

        $recipientIdentifiers = array_values(array_filter(array_unique([
            $this->normalize_message_identifier($recipient['provider_message_id'] ?? null),
            $this->normalize_message_identifier($recipient['message_id'] ?? null),
        ])));
        $eventIdentifiers = array_values(array_filter(array_unique([
            $this->normalize_message_identifier($event['provider_message_id'] ?? null),
            $this->normalize_message_identifier($event['message_id'] ?? null),
            $this->normalize_message_identifier($event['correlation_id'] ?? null),
            $this->normalize_message_identifier($event['header_message_id'] ?? null),
        ])));

        if (!empty($recipientIdentifiers) && !empty($eventIdentifiers)) {
            foreach ($eventIdentifiers as $eventIdentifier) {
                if (in_array($eventIdentifier, $recipientIdentifiers, true)) {
                    return [
                        'matched' => true,
                        'reason' => null,
                        'source' => 'message_identifier',
                    ];
                }
            }

            return [
                'matched' => false,
                'reason' => 'provider_message_identity_mismatch',
                'source' => 'message_identifier',
            ];
        }

        if ($recipientAttemptToken !== '' && !empty($eventIdentifiers)) {
            return [
                'matched' => false,
                'reason' => 'provider_message_identity_mismatch',
                'source' => 'message_identifier',
            ];
        }

        return [
            'matched' => true,
            'reason' => null,
            'source' => 'best_effort',
        ];
    }

    protected function recipient_status_blocks_engagement_events($status)
    {
        $status = strtolower(trim((string) $status));

        return in_array($status, ['failed', 'suppressed', 'skipped'], true);
    }

    protected function recipient_contact_state_blocks_engagement_events(array $recipient)
    {
        $contactId = (int) ($recipient['marketing_contact_id'] ?? 0);
        if ($contactId <= 0 || !$this->db->table_exists($this->contactsTable)) {
            return false;
        }

        $contact = $this->db->select('status, consent_status')
            ->from($this->contactsTable)
            ->where('id', $contactId)
            ->limit(1)
            ->get()
            ->row_array();

        if (empty($contact)) {
            return false;
        }

        $contactStatus = strtolower(trim((string) ($contact['status'] ?? '')));
        $consentStatus = strtolower(trim((string) ($contact['consent_status'] ?? '')));

        return in_array($contactStatus, ['unsubscribed', 'suppressed', 'bounced', 'complained'], true)
            || $consentStatus === 'unsubscribed';
    }

    public function build_event_failure_reason(array $event)
    {
        $eventType = strtolower(trim((string) ($event['event_type'] ?? 'failed')));
        $map = [
            'bounce' => 'SES bounce',
            'complaint' => 'SES complaint',
            'rejected' => 'SES rejected',
            'rendering_failure' => 'SES rendering failure',
            'rendering_failed' => 'SES rendering failure',
            'delivery_delay' => 'SES delivery delay',
            'failed' => 'SES failure',
        ];

        return $map[$eventType] ?? ('SES ' . str_replace('_', ' ', $eventType));
    }

    public function normalize_provider_failure_metric_code(array $context)
    {
        $explicitCode = trim((string) ($context['provider_failure_code'] ?? ''));
        if ($this->is_valid_failure_metric_code($explicitCode)) {
            return $explicitCode;
        }

        if (!empty($context['reconciliation_skipped']) || ($context['normalized_failure_code'] ?? '') === 'recipient_reconciliation_skipped') {
            return 'recipient_reconciliation_skipped';
        }

        if (!empty($context['suppressed'])) {
            $suppressionReason = strtolower(trim((string) ($context['suppression_reason'] ?? ($context['reason'] ?? ''))));
            if (strpos($suppressionReason, 'unsubscribe') !== false || strpos($suppressionReason, 'unsubscribed') !== false) {
                return 'recipient_unsubscribed';
            }
            if (strpos($suppressionReason, 'complain') !== false) {
                return 'recipient_complained';
            }
            if (strpos($suppressionReason, 'bounce') !== false) {
                return 'recipient_bounced';
            }

            return 'recipient_suppressed';
        }

        if (!empty($context['skipped']) || strtolower(trim((string) ($context['event_type'] ?? ''))) === 'skipped') {
            return 'recipient_skipped';
        }

        $eventType = strtolower(trim((string) ($context['event_type'] ?? '')));
        switch ($eventType) {
            case 'bounce':
                return 'recipient_bounced';
            case 'complaint':
                return 'recipient_complained';
            case 'unsubscribe':
            case 'unsubscribed':
                return 'recipient_unsubscribed';
            case 'rendering_failure':
            case 'rendering_failed':
                return 'provider_rendering_failure';
        }

        if (!empty($context['provider_throttle'])) {
            return 'provider_throttle';
        }
        if (!empty($context['transient_failure'])) {
            return 'provider_transient_failure';
        }
        if (!empty($context['provider_rendering_failure'])) {
            return 'provider_rendering_failure';
        }

        if (in_array($eventType, ['failed', 'rejected'], true) || !empty($context['failure_reason'])) {
            return 'provider_permanent_failure';
        }

        return null;
    }

    private function finalize_campaign_if_complete($campaignId)
    {
        $campaign = $this->get($campaignId);
        if (!$campaign || $campaign['status'] !== 'sending') {
            return false;
        }

        $queued = $this->count_sendable_recipients($campaignId);
        $processing = $this->count_recipients_by_status($campaignId, 'processing');

        if ($queued !== 0 || $processing !== 0) {
            return false;
        }

        $sent = $this->count_recipients_by_status($campaignId, 'sent');
        $failed = $this->count_recipients_by_status($campaignId, 'failed');
        $suppressed = $this->count_recipients_by_status($campaignId, 'suppressed');
        $skipped = $this->count_recipients_by_status($campaignId, 'skipped');
        $newStatus = ($sent > 0 || $suppressed > 0 || $skipped > 0) ? 'sent' : ($failed > 0 ? 'failed' : $campaign['status']);

        if ($newStatus === $campaign['status']) {
            return false;
        }

        $updated = $this->update($campaignId, [
            'status' => $newStatus,
            'send_completed_at' => date('Y-m-d H:i:s'),
        ]);

        if ($updated) {
            log_message(
                'info',
                'Marketing campaign completed: campaign_id=' . (int) $campaignId
                . ' status=' . $newStatus
                . ' sent=' . $sent
                . ' failed=' . $failed
                . ' suppressed=' . $suppressed
                . ' skipped=' . $skipped
            );
        }

        return $updated;
    }

    private function campaign_send_started_before(array $campaign, $cutoffTimestamp)
    {
        $sendStartedAt = trim((string) ($campaign['send_started_at'] ?? ''));
        if ($sendStartedAt === '') {
            return false;
        }

        $sendStartedTimestamp = strtotime($sendStartedAt);
        if ($sendStartedTimestamp === false) {
            return false;
        }

        return $sendStartedTimestamp <= (int) $cutoffTimestamp;
    }

    public function get_queued_recipients($campaignId, $limit = 50)
    {
        return $this->db->select('*')
            ->from($this->recipientsTable)
            ->where('campaign_id', (int) $campaignId)
            ->where('status', 'queued')
            ->order_by('id', 'ASC')
            ->limit((int) $limit)
            ->get()
            ->result_array();
    }

    public function get_recipient_summary($campaignId)
    {
        $summary = [
            'queued' => 0,
            'processing' => 0,
            'sent' => 0,
            'failed' => 0,
            'suppressed' => 0,
            'skipped' => 0,
            'total' => 0,
        ];

        $rows = $this->db->select('status, COUNT(*) as total')
            ->from($this->recipientsTable)
            ->where('campaign_id', (int) $campaignId)
            ->group_by('status')
            ->get()
            ->result_array();

        foreach ($rows as $row) {
            $status = $row['status'];
            $summary[$status] = (int) $row['total'];
            $summary['total'] += (int) $row['total'];
        }

        return $summary;
    }

    public function get_event_summary($campaignId)
    {
        $CI = &get_instance();
        $CI->load->model('marketing/Marketing_events_model', 'marketing_events_model');

        $summary = $CI->marketing_events_model->get_normalized_summary((int) $campaignId);
        $recipientSummary = $this->get_recipient_summary($campaignId);
        $acceptedSentEvents = (int) $summary['accepted_sent'];
        $reconciledAcceptedSent = (int) $recipientSummary['sent'] > 0
            ? (int) $recipientSummary['sent']
            : $acceptedSentEvents;

        $summary['accepted_sent_events'] = $acceptedSentEvents;
        $summary['accepted_sent'] = $reconciledAcceptedSent;
        $summary['accepted_sent_reconciled'] = $reconciledAcceptedSent;
        $summary['accepted_sent_delta'] = $reconciledAcceptedSent - $acceptedSentEvents;

        return $summary;
    }

    public function get_campaign_readiness_snapshot($campaignId, ?array $campaign = null, ?array $recipientSummary = null, ?array $sendReadiness = null, ?array $eventSummary = null)
    {
        $campaign = $campaign ?? $this->get($campaignId);

        if (!$campaign) {
            return [
                'phase' => 'draft',
                'status_label' => _l('marketing_campaign_missing_fields_status'),
                'alert_type' => 'warning',
                'primary_message' => _l('marketing_campaign_not_found'),
                'guidance_messages' => [],
                'show_configuration_warnings' => true,
                'show_send_readiness_messages' => false,
                'is_completed' => false,
            ];
        }

        $recipientSummary = $recipientSummary ?? $this->get_recipient_summary($campaignId);
        $sendReadiness = $sendReadiness ?? $this->validate_send_ready($campaignId);
        $eventSummary = $eventSummary ?? $this->get_event_summary($campaignId);

        $queued = (int) ($recipientSummary['queued'] ?? 0);
        $processing = (int) ($recipientSummary['processing'] ?? 0);
        $sent = (int) ($recipientSummary['sent'] ?? 0);
        $failed = (int) ($recipientSummary['failed'] ?? 0);
        $suppressed = (int) ($recipientSummary['suppressed'] ?? 0);
        $skipped = (int) ($recipientSummary['skipped'] ?? 0);
        $acceptedSent = (int) ($eventSummary['accepted_sent'] ?? 0);
        $status = strtolower(trim((string) ($campaign['status'] ?? 'draft')));

        $completed = $sent + $failed + $suppressed + $skipped;
        $successfulCompletion = $sent + $suppressed + $skipped;
        $hasCompletion = $completed > 0 || $acceptedSent > 0 || !empty($campaign['send_completed_at']);
        $showConfigurationWarnings = !in_array($status, ['sent', 'cancelled'], true) && !$hasCompletion;

        $snapshot = [
            'phase' => 'draft',
            'status_label' => _l('marketing_campaign_missing_fields_status'),
            'alert_type' => 'warning',
            'primary_message' => _l('marketing_campaign_operational_draft'),
            'guidance_messages' => [],
            'show_configuration_warnings' => $showConfigurationWarnings,
            'show_send_readiness_messages' => false,
            'is_completed' => false,
        ];

        if ($status === 'cancelled') {
            $snapshot['phase'] = 'cancelled';
            $snapshot['status_label'] = _l('marketing_campaign_cancelled_status');
            $snapshot['primary_message'] = _l('marketing_campaign_operational_cancelled');

            return $snapshot;
        }

        if (($status === 'sent' || $successfulCompletion > 0 || $acceptedSent > 0) && $queued === 0 && $processing === 0) {
            $snapshot['phase'] = 'completed';
            $snapshot['status_label'] = _l('marketing_campaign_completed_status');
            $snapshot['alert_type'] = $failed > 0 ? 'warning' : 'success';
            $snapshot['primary_message'] = $failed > 0
                ? _l('marketing_campaign_operational_completed_with_failures')
                : _l('marketing_campaign_operational_completed');
            $snapshot['is_completed'] = true;

            return $snapshot;
        }

        if ($status === 'failed' || ($status === 'sending' && $queued === 0 && $processing === 0 && $completed === 0)) {
            $snapshot['phase'] = 'failed_stalled';
            $snapshot['status_label'] = _l('marketing_campaign_failed_stalled_status');
            $snapshot['primary_message'] = _l('marketing_campaign_operational_failed_stalled');
            $snapshot['guidance_messages'] = !empty($sendReadiness['messages'])
                ? array_values(array_unique(array_filter($sendReadiness['messages'])))
                : [_l('marketing_campaign_prepare_before_send')];
            $snapshot['show_send_readiness_messages'] = !empty($snapshot['guidance_messages']);

            return $snapshot;
        }

        if ($processing > 0 || $status === 'sending') {
            $snapshot['phase'] = 'processing';
            $snapshot['status_label'] = _l('marketing_campaign_processing_status');
            $snapshot['alert_type'] = 'info';
            $snapshot['primary_message'] = $queued > 0
                ? _l('marketing_campaign_operational_processing')
                : _l('marketing_campaign_operational_processing_finalizing');

            return $snapshot;
        }

        if ($queued > 0) {
            $snapshot['phase'] = 'queued';
            $snapshot['status_label'] = _l('marketing_campaign_queued_status');
            $snapshot['alert_type'] = 'info';
            $snapshot['primary_message'] = _l('marketing_campaign_operational_queued');

            return $snapshot;
        }

        if ($sendReadiness['ready']) {
            $snapshot['phase'] = 'ready';
            $snapshot['status_label'] = _l('marketing_campaign_ready_prepare_status');
            $snapshot['alert_type'] = 'info';
            $snapshot['primary_message'] = _l('marketing_campaign_operational_ready_prepare');
            $snapshot['guidance_messages'] = [_l('marketing_campaign_prepare_before_send')];
            $snapshot['show_send_readiness_messages'] = true;

            return $snapshot;
        }

        $snapshot['guidance_messages'] = array_values(array_unique(array_filter($sendReadiness['messages'] ?? [])));
        $snapshot['show_send_readiness_messages'] = !empty($snapshot['guidance_messages']);

        return $snapshot;
    }

    public function can_prepare_recipients($campaign)
    {
        if (is_numeric($campaign)) {
            $campaign = $this->get($campaign);
        }

        if (!$campaign || empty($campaign['list_id'])) {
            return false;
        }

        return in_array($campaign['status'], ['draft', 'scheduled', 'sending'], true) && $this->list_exists($campaign['list_id']);
    }

    public function can_schedule($campaign)
    {
        if (is_numeric($campaign)) {
            $campaign = $this->get($campaign);
        }

        if (!$campaign) {
            return false;
        }

        return in_array($campaign['status'], ['draft', 'scheduled'], true);
    }

    public function prepare_recipients($campaignId)
    {
        $campaign = $this->get($campaignId);

        if (!$this->can_prepare_recipients($campaign)) {
            return false;
        }

        $deliveryChannel = $this->normalize_delivery_channel($campaign['delivery_channel'] ?? 'email');

        $summary = [
            'success' => false,
            'queued' => 0,
            'invalid' => 0,
            'suppressed' => 0,
            'inactive' => 0,
            'unsubscribed' => 0,
            'duplicate' => 0,
            'missing_phone' => 0,
            'total_members' => 0,
            'filtered_out' => 0,
        ];

        $snapshot = $this->get_campaign_queue_targeting_snapshot($campaign);
        if ($snapshot['total_members'] === 0) {
            $summary['success'] = true;

            return array_merge($summary, [
                'total_members' => 0,
                'filtered_out' => 0,
            ]);
        }

        $summary['total_members'] = $snapshot['total_members'];
        $summary['invalid'] = $snapshot['invalid'];
        $summary['suppressed'] = $snapshot['suppressed'];
        $summary['inactive'] = $snapshot['inactive'];
        $summary['unsubscribed'] = $snapshot['unsubscribed'];
        $summary['duplicate'] = $snapshot['duplicate'];
        $summary['missing_phone'] = (int) ($snapshot['missing_phone'] ?? 0);
        $summary['filtered_out'] = $snapshot['filtered_out'];

        if ($this->requires_prepare_license($snapshot) && !marketing_is_feature_allowed('recipient_prepare')) {
            $summary['license_blocked'] = true;
            $summary['license_message'] = marketing_license_block_message();

            return $summary;
        }

        $this->db->trans_start();

        foreach ($snapshot['eligible_contacts'] as $member) {
            if ($this->campaign_recipient_exists($campaignId, $member, $deliveryChannel)) {
                $summary['duplicate']++;
                continue;
            }

            $insertData = [
                'campaign_id' => (int) $campaignId,
                'marketing_contact_id' => (int) $member['marketing_contact_id'],
                'email' => $deliveryChannel === 'whatsapp'
                    ? (string) ($member['normalized_email'] ?? ($member['email'] ?? ''))
                    : (string) $member['normalized_email'],
                'channel' => $deliveryChannel,
                'destination' => $deliveryChannel === 'whatsapp'
                    ? (string) ($member['normalized_phone'] ?? '')
                    : (string) $member['normalized_email'],
                'status' => 'queued',
                'queued_at' => date('Y-m-d H:i:s'),
            ];

            $insertData = $this->filter_existing_columns($this->recipientsTable, $insertData);

            $this->db->insert($this->recipientsTable, $insertData);

            if ($this->db->affected_rows() !== 0) {
                $summary['queued']++;
            }
        }

        $this->db->trans_complete();

        if ($this->db->trans_status() === false) {
            return false;
        }

        if ($summary['queued'] > 0) {
            $this->create_event([
                'campaign_id' => (int) $campaignId,
                'event_type' => 'queued',
                'provider' => $deliveryChannel === 'whatsapp' ? 'whatsapp_business' : ($campaign['source_type'] ?? 'smtp'),
                'event_at' => date('Y-m-d H:i:s'),
                'payload_json' => json_encode([
                    'queued' => $summary['queued'],
                    'invalid' => $summary['invalid'],
                    'suppressed' => $summary['suppressed'],
                    'inactive' => $summary['inactive'],
                    'unsubscribed' => $summary['unsubscribed'],
                    'duplicate' => $summary['duplicate'],
                    'missing_phone' => $summary['missing_phone'],
                    'delivery_channel' => $deliveryChannel,
                ]),
            ]);
        }

        $summary['success'] = true;

        return $summary;
    }

    public function schedule_campaign($campaignId, $scheduledAt = null)
    {
        $data = [
            'status' => 'scheduled',
            'scheduled_at' => $scheduledAt ? date('Y-m-d H:i:s', strtotime($scheduledAt)) : null,
        ];

        return $this->update($campaignId, $data);
    }

    public function cancel_campaign($campaignId)
    {
        return $this->update($campaignId, [
            'status' => 'cancelled',
            'scheduled_at' => null,
        ]);
    }

    public function validate_send_ready($campaignId)
    {
        $campaign = $this->get($campaignId);

        if (!$campaign) {
            return [
                'ready' => false,
                'missing_fields' => ['campaign'],
                'messages' => [_l('marketing_campaign_not_found')],
                'smtp_ready' => false,
                'queued_recipients' => 0,
                'eligible_recipients' => 0,
            ];
        }

        $missingFields = [];
        $messages = [];
        $deliveryChannel = $this->normalize_delivery_channel($campaign['delivery_channel'] ?? 'email');

        if ($deliveryChannel === 'email' && trim((string) $campaign['subject']) === '') {
            $missingFields[] = 'subject';
            $messages[] = _l('marketing_subject') . ' is required.';
        }

        if ($deliveryChannel === 'whatsapp') {
            if (trim((string) ($campaign['whatsapp_template_name'] ?? '')) === '') {
                $missingFields[] = 'whatsapp_template_name';
                $messages[] = _l('marketing_campaign_whatsapp_template_required');
            }

            if (trim((string) ($campaign['whatsapp_template_language'] ?? '')) === '') {
                $missingFields[] = 'whatsapp_template_language';
                $messages[] = _l('marketing_campaign_whatsapp_template_language_required');
            }

            $whatsappStatus = $this->get_whatsapp_configuration_status();
            if (!$whatsappStatus['valid']) {
                $missingFields[] = 'whatsapp_configuration';
                $messages = array_merge($messages, $whatsappStatus['messages']);
            }
        } else {
            if (trim((string) $campaign['html_content']) === '') {
                $missingFields[] = 'html_content';
                $messages[] = _l('marketing_campaign_html_required');
            }

            if (strpos((string) $campaign['html_content'], '{{unsubscribe_url}}') === false) {
                $missingFields[] = 'unsubscribe_url';
                $messages[] = _l('marketing_campaign_unsubscribe_required');
            }

            if (!$this->campaign_images_are_absolute((string) $campaign['html_content'])) {
                $missingFields[] = 'absolute_images';
                $messages[] = _l('marketing_campaign_absolute_images_required');
            }

            $smtpStatus = $this->get_smtp_configuration_status();
            if (!$smtpStatus['valid']) {
                $missingFields[] = 'smtp_configuration';
                $messages = array_merge($messages, $smtpStatus['messages']);
            }
        }

        if (empty($campaign['list_id']) || !$this->list_exists($campaign['list_id'])) {
            $missingFields[] = 'list_id';
            $messages[] = _l('marketing_campaign_list_required');
        }

        $queuedRecipients = $this->count_recipients_by_status($campaignId, 'queued');
        $eligibleRecipients = $this->count_eligible_contacts($campaign);
        $snapshot = $this->get_campaign_targeting_snapshot($campaign);
        if ($deliveryChannel === 'whatsapp' && (int) ($snapshot['missing_phone'] ?? 0) > 0) {
            $messages[] = sprintf(_l('marketing_campaign_whatsapp_missing_phone_warning'), (int) $snapshot['missing_phone']);
        }

        return [
            'ready' => empty($missingFields) && ($eligibleRecipients > 0 || $queuedRecipients > 0),
            'missing_fields' => $missingFields,
            'messages' => ($eligibleRecipients > 0 || $queuedRecipients > 0) ? $messages : array_merge($messages, [_l('marketing_campaign_no_eligible_contacts')]),
            'smtp_ready' => $deliveryChannel === 'email' ? $this->get_smtp_configuration_status()['valid'] : true,
            'queued_recipients' => $queuedRecipients,
            'eligible_recipients' => $eligibleRecipients,
        ];
    }

    public function get_whatsapp_configuration_status()
    {
        $CI = &get_instance();
        $CI->load->library('marketing/Marketing_whatsapp');
        $settings = $CI->marketing_whatsapp->get_settings();

        $messages = [];
        if (empty($settings['enabled'])) {
            $messages[] = _l('marketing_campaign_whatsapp_disabled_warning');
        }
        if (trim((string) ($settings['phone_number_id'] ?? '')) === '') {
            $messages[] = _l('marketing_campaign_whatsapp_phone_number_missing');
        }
        if (trim((string) ($settings['access_token'] ?? '')) === '') {
            $messages[] = _l('marketing_campaign_whatsapp_token_missing');
        }

        return [
            'enabled' => !empty($settings['enabled']),
            'valid' => empty($messages),
            'messages' => $messages,
        ];
    }

    protected function requires_prepare_license(array $snapshot)
    {
        $eligibleCount = isset($snapshot['eligible_contacts']) && is_array($snapshot['eligible_contacts'])
            ? count($snapshot['eligible_contacts'])
            : 0;
        $threshold = defined('MARKETING_COMMERCIAL_PREPARE_THRESHOLD')
            ? (int) MARKETING_COMMERCIAL_PREPARE_THRESHOLD
            : self::COMMERCIAL_PREPARE_THRESHOLD_FALLBACK;

        return $eligibleCount >= max(1, $threshold);
    }

    public function get_smtp_configuration_status()
    {
        if (!$this->option_enabled('marketing_smtp_enabled')) {
            return [
                'enabled' => false,
                'valid' => true,
                'messages' => [],
                'configuration_set' => '',
                'telemetry_ready' => false,
                'telemetry_messages' => [],
            ];
        }

        $messages = [];
        $telemetryMessages = [];
        $host = trim((string) get_option('marketing_smtp_host'));
        $port = trim((string) get_option('marketing_smtp_port'));
        $username = trim((string) get_option('marketing_smtp_username'));
        $fromEmail = trim((string) get_option('marketing_smtp_from_email'));
        $password = trim((string) get_option('marketing_smtp_password'));
        $replyTo = trim((string) get_option('marketing_smtp_reply_to'));
        $encryption = trim((string) get_option('marketing_smtp_encryption'));
        $configurationSet = trim((string) get_option('marketing_ses_configuration_set'));

        if ($host === '') {
            $messages[] = _l('marketing_smtp_host') . ' is required.';
        }

        if ($port === '' || !is_numeric($port) || (int) $port < 1 || (int) $port > 65535) {
            $messages[] = _l('marketing_smtp_port') . ' ' . _l('marketing_must_be_valid');
        }

        if ($username === '') {
            $messages[] = _l('marketing_smtp_username') . ' is required.';
        }

        if ($password === '') {
            $messages[] = _l('marketing_smtp_password') . ' is required.';
        }

        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            $messages[] = _l('marketing_smtp_from_email') . ' ' . _l('marketing_must_be_valid');
        }

        if ($replyTo !== '' && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $messages[] = _l('marketing_smtp_reply_to') . ' ' . _l('marketing_must_be_valid');
        }

        if ($encryption !== '' && !in_array($encryption, ['none', 'ssl', 'tls'], true)) {
            $messages[] = _l('marketing_smtp_encryption') . ' ' . _l('marketing_must_be_valid');
        }

        if ($configurationSet === '') {
            $telemetryMessages[] = _l('marketing_ses_configuration_set_missing_diagnostic');
        }

        $telemetryMessages[] = _l('marketing_ses_tracking_expectation_diagnostic');

        return [
            'enabled' => true,
            'valid' => empty($messages),
            'messages' => $messages,
            'configuration_set' => $configurationSet,
            'telemetry_ready' => $configurationSet !== '',
            'telemetry_messages' => $telemetryMessages,
        ];
    }

    public function get_campaign_targeting_snapshot($campaign, ?array $overrideRules = null)
    {
        return $this->build_campaign_eligibility_snapshot($campaign, $overrideRules, false);
    }

    protected function count_eligible_contacts($campaign)
    {
        $snapshot = $this->get_campaign_targeting_snapshot($campaign);

        return (int) $snapshot['eligible'];
    }

    private function get_campaign_queue_targeting_snapshot($campaign, ?array $overrideRules = null)
    {
        return $this->build_campaign_eligibility_snapshot($campaign, $overrideRules, true);
    }

    private function build_campaign_eligibility_snapshot($campaign, ?array $overrideRules = null, $excludeExistingRecipients = false)
    {
        $rules = $overrideRules !== null ? $this->normalize_targeting_rules($overrideRules) : (is_array($campaign) ? $this->get_campaign_targeting_rules($campaign) : $this->get_default_targeting_rules());
        if (!is_array($campaign) || empty($campaign['list_id']) || !$this->list_exists($campaign['list_id'])) {
            return $this->empty_campaign_targeting_snapshot($rules);
        }

        $deliveryChannel = $this->normalize_delivery_channel($campaign['delivery_channel'] ?? 'email');
        $members = $this->build_targeting_members_query($campaign, $rules)->get()->result_array();
        if (empty($members)) {
            return $this->empty_campaign_targeting_snapshot($rules);
        }

        $existingRecipientMaps = $excludeExistingRecipients
            ? $this->get_existing_campaign_recipient_maps((int) ($campaign['id'] ?? 0))
            : ['contact_ids' => [], 'emails' => [], 'destinations' => []];

        $normalizedEmails = [];
        $normalizedPhones = [];
        foreach ($members as $member) {
            $normalizedEmail = $this->normalize_email(!empty($member['normalized_email']) ? $member['normalized_email'] : $member['email']);
            if ($normalizedEmail !== null) {
                $normalizedEmails[] = $normalizedEmail;
            }

            $normalizedPhone = $this->normalize_phone_number($member['phone'] ?? '');
            if ($normalizedPhone !== null) {
                $normalizedPhones[] = $normalizedPhone;
            }
        }

        $suppressedEmails = $this->get_active_suppressed_emails($normalizedEmails);
        $suppressedPhones = $this->get_active_suppressed_phones($normalizedPhones);
        $eligibleContacts = [];
        $seenDestinations = [];
        $summary = $this->empty_campaign_targeting_snapshot($rules);
        $summary['total_members'] = count($members);

        foreach ($members as $member) {
            $email = trim((string) ($member['email'] ?? ''));
            $normalizedEmail = $this->normalize_email(!empty($member['normalized_email']) ? $member['normalized_email'] : $email);
            $normalizedPhone = $this->normalize_phone_number($member['phone'] ?? '');
            $contactStatus = strtolower(trim((string) ($member['contact_status'] ?? '')));

            if ($deliveryChannel === 'email' && ($normalizedEmail === null || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
                $summary['invalid']++;
                continue;
            }

            if (!$this->is_sendable_consent($member['consent_status'] ?? null) || $contactStatus === 'unsubscribed') {
                $summary['unsubscribed']++;
                continue;
            }

            $isSuppressed = in_array($contactStatus, ['suppressed', 'bounced', 'complained'], true);
            if ($deliveryChannel === 'whatsapp') {
                $isSuppressed = $isSuppressed || ($normalizedPhone !== null && isset($suppressedPhones[$normalizedPhone]));
            } else {
                $isSuppressed = $isSuppressed || ($normalizedEmail !== null && isset($suppressedEmails[$normalizedEmail]));
            }

            if ($isSuppressed) {
                $summary['suppressed']++;
                continue;
            }

            if (!$this->is_active_status($member['member_status'] ?? null) || !$this->is_active_status($contactStatus)) {
                $summary['inactive']++;
                continue;
            }

            if ($deliveryChannel === 'whatsapp' && $normalizedPhone === null) {
                $summary['missing_phone']++;
                continue;
            }

            $duplicateKey = $deliveryChannel === 'whatsapp' ? $normalizedPhone : $normalizedEmail;
            if ($duplicateKey !== null && isset($seenDestinations[$duplicateKey])) {
                $summary['duplicate']++;
                continue;
            }

            if ($excludeExistingRecipients && $this->campaign_recipient_map_contains($existingRecipientMaps, $member, $deliveryChannel, $normalizedEmail, $normalizedPhone)) {
                $summary['duplicate']++;
                continue;
            }

            $member['normalized_email'] = $normalizedEmail;
            $member['normalized_phone'] = $normalizedPhone;
            $eligibleContacts[] = $member;
            if ($duplicateKey !== null) {
                $seenDestinations[$duplicateKey] = true;
            }
        }

        $summary['eligible_contacts'] = $eligibleContacts;
        $summary['eligible'] = count($eligibleContacts);

        return $summary;
    }

    private function empty_campaign_targeting_snapshot(array $rules)
    {
        return [
            'rules' => $rules,
            'eligible' => 0,
            'eligible_contacts' => [],
            'invalid' => 0,
            'suppressed' => 0,
            'inactive' => 0,
            'unsubscribed' => 0,
            'duplicate' => 0,
            'missing_phone' => 0,
            'filtered_out' => 0,
            'total_members' => 0,
        ];
    }

    private function build_targeting_members_query(array $campaign, array $rules)
    {
        $selectFields = [
            'c.id as marketing_contact_id',
            'c.email',
            'c.normalized_email',
            'c.phone',
            'c.company',
            'c.status as contact_status',
            'c.consent_status',
            'c.source_type',
            'm.member_status',
            'm.dateadded as member_dateadded',
        ];
        if ($this->contact_created_date_filter_supported()) {
            $selectFields[] = 'c.datecreated as contact_datecreated';
        }

        $this->db->select(implode(', ', $selectFields));
        $this->db->from($this->membersTable . ' m');
        $this->db->join($this->contactsTable . ' c', 'c.id = m.marketing_contact_id');
        $this->db->where('m.list_id', (int) $campaign['list_id']);

        if ($rules['send_mode'] === 'filtered') {
            if ($rules['contact_status'] !== '') {
                $this->db->where('c.status', $rules['contact_status']);
            }

            if ($rules['consent_status'] !== '') {
                $this->db->where('c.consent_status', $rules['consent_status']);
            }

            if ($rules['company_contains'] !== '') {
                $this->db->like('c.company', $rules['company_contains']);
            }

            if ($rules['email_contains'] !== '') {
                $this->db->group_start()
                    ->like('c.email', $rules['email_contains'])
                    ->or_like('c.normalized_email', mb_strtolower($rules['email_contains']))
                    ->group_end();
            }

            if ($rules['email_domain'] !== '') {
                $domainMatch = '@' . $rules['email_domain'];
                $this->db->group_start()
                    ->like('c.email', $domainMatch, 'before')
                    ->or_like('c.normalized_email', $domainMatch, 'before')
                    ->group_end();
            }

            if ($rules['source_type'] === 'manual') {
                $this->db->where('c.source_type', 'manual');
            } elseif ($rules['source_type'] === 'import') {
                $this->db->group_start()
                    ->where('c.source_type !=', 'manual')
                    ->where('c.source_type IS NOT NULL', null, false)
                    ->group_end();
            }

            if ($this->contact_created_date_filter_supported()) {
                if ($rules['created_from'] !== '') {
                    $this->db->where('DATE(c.datecreated) >=', $rules['created_from']);
                }

                if ($rules['created_to'] !== '') {
                    $this->db->where('DATE(c.datecreated) <=', $rules['created_to']);
                }
            }
        }

        $this->db->order_by('m.id', 'ASC');
        $this->db->order_by('c.id', 'ASC');

        return $this->db;
    }

    private function get_active_suppressed_emails(array $normalizedEmails)
    {
        $suppressedEmails = [];

        if (empty($normalizedEmails) || !$this->db->table_exists($this->suppressionsTable) || !$this->column_exists($this->suppressionsTable, 'normalized_email')) {
            return $suppressedEmails;
        }

        $suppressionMap = $this->get_suppressions_model()->get_active_suppression_map($normalizedEmails);
        foreach ($suppressionMap as $normalizedEmail => $suppressionRow) {
            $suppressedEmails[$normalizedEmail] = true;
        }

        return $suppressedEmails;
    }

    private function get_active_suppressed_phones(array $normalizedPhones)
    {
        $suppressedPhones = [];

        if (empty($normalizedPhones) || !$this->db->table_exists($this->suppressionsTable) || !$this->column_exists($this->suppressionsTable, 'phone')) {
            return $suppressedPhones;
        }

        $suppressionRows = $this->db->select('phone')
            ->from($this->suppressionsTable)
            ->where('active', 1)
            ->where_in('phone', array_values(array_unique($normalizedPhones)))
            ->get()
            ->result_array();

        foreach ($suppressionRows as $suppressionRow) {
            $normalizedPhone = $this->normalize_phone_number($suppressionRow['phone'] ?? '');
            if ($normalizedPhone !== null) {
                $suppressedPhones[$normalizedPhone] = true;
            }
        }

        return $suppressedPhones;
    }

    private function get_existing_campaign_recipient_maps($campaignId)
    {
        $campaignId = (int) $campaignId;
        if ($campaignId <= 0 || !$this->db->table_exists($this->recipientsTable)) {
            return [
                'contact_ids' => [],
                'emails' => [],
                'destinations' => [],
            ];
        }

        $existingSelect = ['marketing_contact_id', 'email'];
        if ($this->column_exists($this->recipientsTable, 'destination')) {
            $existingSelect[] = 'destination';
        }

        $existingRecipients = $this->db->select(implode(', ', $existingSelect))
            ->from($this->recipientsTable)
            ->where('campaign_id', $campaignId)
            ->get()
            ->result_array();

        $maps = [
            'contact_ids' => [],
            'emails' => [],
            'destinations' => [],
        ];

        foreach ($existingRecipients as $existingRecipient) {
            $contactId = (int) ($existingRecipient['marketing_contact_id'] ?? 0);
            if ($contactId > 0) {
                $maps['contact_ids'][$contactId] = true;
            }

            $normalizedExistingEmail = $this->normalize_email($existingRecipient['email'] ?? '');
            if ($normalizedExistingEmail !== null) {
                $maps['emails'][$normalizedExistingEmail] = true;
            }

            $normalizedExistingDestination = $this->normalize_phone_number($existingRecipient['destination'] ?? '');
            if ($normalizedExistingDestination !== null) {
                $maps['destinations'][$normalizedExistingDestination] = true;
            }
        }

        return $maps;
    }

    private function campaign_recipient_map_contains(array $maps, array $member, $deliveryChannel, $normalizedEmail, $normalizedPhone)
    {
        $contactId = (int) ($member['marketing_contact_id'] ?? 0);
        if ($contactId > 0 && isset($maps['contact_ids'][$contactId])) {
            return true;
        }

        if ($normalizedEmail !== null && isset($maps['emails'][$normalizedEmail])) {
            return true;
        }

        if ($deliveryChannel === 'whatsapp' && $normalizedPhone !== null && isset($maps['destinations'][$normalizedPhone])) {
            return true;
        }

        return false;
    }

    private function campaign_recipient_exists($campaignId, array $member, $deliveryChannel)
    {
        $campaignId = (int) $campaignId;
        if ($campaignId <= 0) {
            return false;
        }

        $this->db->from($this->recipientsTable);
        $this->db->where('campaign_id', $campaignId);

        if ($this->column_exists($this->recipientsTable, 'marketing_contact_id')) {
            $this->db->where('marketing_contact_id', (int) ($member['marketing_contact_id'] ?? 0));
        }

        if ($this->column_exists($this->recipientsTable, 'channel')) {
            $this->db->where('channel', $deliveryChannel);
        }

        $destination = $deliveryChannel === 'whatsapp'
            ? ($member['normalized_phone'] ?? null)
            : ($member['normalized_email'] ?? null);

        if ($destination !== null && $this->column_exists($this->recipientsTable, 'destination')) {
            $this->db->where('destination', (string) $destination);
        } elseif (!empty($member['normalized_email']) && $this->column_exists($this->recipientsTable, 'email')) {
            $this->db->where('email', (string) $member['normalized_email']);
        }

        return (int) $this->db->count_all_results() > 0;
    }

    private function recipient_is_duplicate_for_send(array $recipient)
    {
        if (empty($recipient['campaign_id']) || empty($recipient['id'])) {
            return false;
        }

        $deliveryChannel = $this->normalize_delivery_channel($recipient['channel'] ?? 'email');
        $this->db->from($this->recipientsTable);
        $this->db->where('campaign_id', (int) $recipient['campaign_id']);
        $this->db->where('id <', (int) $recipient['id']);

        if ($this->column_exists($this->recipientsTable, 'channel')) {
            $this->db->where('channel', $deliveryChannel);
        }

        if ($this->column_exists($this->recipientsTable, 'marketing_contact_id') && !empty($recipient['marketing_contact_id'])) {
            $this->db->where('marketing_contact_id', (int) $recipient['marketing_contact_id']);
        }

        $destination = $deliveryChannel === 'whatsapp'
            ? $this->normalize_phone_number($recipient['destination'] ?? '')
            : $this->normalize_email($recipient['destination'] ?? ($recipient['email'] ?? ''));

        if ($destination !== null && $this->column_exists($this->recipientsTable, 'destination')) {
            $this->db->where('destination', (string) $destination);
        } elseif (!empty($recipient['email']) && $this->column_exists($this->recipientsTable, 'email')) {
            $this->db->where('email', (string) $this->normalize_email($recipient['email']));
        }

        return (int) $this->db->count_all_results() > 0;
    }

    private function get_active_suppression_for_recipient(array $recipient, $deliveryChannel)
    {
        $deliveryChannel = $this->normalize_delivery_channel($deliveryChannel);

        if ($deliveryChannel === 'whatsapp') {
            if (!$this->db->table_exists($this->suppressionsTable) || !$this->column_exists($this->suppressionsTable, 'phone')) {
                return ['suppressed' => false];
            }

            $normalizedPhone = $this->normalize_phone_number($recipient['destination'] ?? '');
            if ($normalizedPhone === null) {
                return ['suppressed' => false];
            }

            $exists = $this->db->from($this->suppressionsTable)
                ->where('active', 1)
                ->where('phone', $normalizedPhone)
                ->count_all_results() > 0;

            return [
                'suppressed' => $exists,
                'type' => 'phone',
                'reason' => 'suppressed_phone',
            ];
        }

        $normalizedEmail = $this->normalize_email($recipient['email'] ?? ($recipient['destination'] ?? ''));
        if (
            $normalizedEmail === null
            || !$this->db->table_exists($this->suppressionsTable)
            || !$this->column_exists($this->suppressionsTable, 'normalized_email')
        ) {
            return ['suppressed' => false];
        }

        $suppression = $this->get_suppressions_model()->get_active_suppression($normalizedEmail);

        return [
            'suppressed' => $suppression !== null,
            'type' => 'email',
            'reason' => $suppression !== null ? 'suppressed_' . ($suppression['reason'] ?? 'email') : 'suppressed_email',
        ];
    }

    private function count_sendable_recipients($campaignId)
    {
        $this->db->from($this->recipientsTable);
        $this->db->where('campaign_id', (int) $campaignId);
        $this->db->where_in('status', self::SENDABLE_RECIPIENT_STATUSES);

        return (int) $this->db->count_all_results();
    }

    private function get_queue_schema_check()
    {
        $requirements = [
            $this->table => ['id', 'status', 'delivery_channel', 'queue_worker_token', 'queue_worker_started_at', 'queue_worker_heartbeat_at'],
            $this->listsTable => ['id'],
            $this->recipientsTable => ['id', 'campaign_id', 'status', 'processing_token', 'outbound_attempt_token'],
            $this->eventsTable => ['event_type'],
            $this->suppressionsTable => ['active', 'normalized_email'],
        ];

        $missing = [];

        foreach ($requirements as $table => $columns) {
            if (!$this->db->table_exists($table)) {
                $missing[] = $table;
                continue;
            }

            foreach ($columns as $column) {
                if (!$this->column_exists($table, $column)) {
                    $missing[] = $table . '.' . $column;
                }
            }
        }

        return [
            'ready' => empty($missing),
            'missing' => $missing,
        ];
    }

    private function log_safe_queue_error($message)
    {
        log_message('error', $this->sanitize_failure_reason($message));
    }

    private function mark_recipient_suppressed(array $campaign, array $recipient, $eventProvider, $deliveryChannel, array $suppressionMatch, $workerLeaseToken = '')
    {
        $reason = $this->sanitize_failure_reason($suppressionMatch['reason'] ?? 'suppressed_email');
        $type = trim((string) ($suppressionMatch['type'] ?? 'email'));
        $normalizedFailureCode = $this->normalize_provider_failure_metric_code([
            'suppressed' => true,
            'suppression_reason' => $reason,
        ]);

        if (!$this->update_reserved_recipient_status($recipient['id'], $workerLeaseToken, 'suppressed', [
            'failure_reason' => $reason,
        ])) {
            return false;
        }

        $this->create_event([
            'campaign_id' => $campaign['id'],
            'campaign_recipient_id' => $recipient['id'],
            'marketing_contact_id' => $recipient['marketing_contact_id'],
            'event_type' => 'suppressed',
            'provider' => $eventProvider,
            'payload_json' => json_encode([
                'normalized_failure_code' => $normalizedFailureCode,
                'reason' => $reason,
                'delivery_channel' => $deliveryChannel,
            ]),
        ]);

        log_message(
            'info',
            'Marketing recipient skipped due to suppression: campaign_id=' . (int) $campaign['id']
            . ' recipient_id=' . (int) $recipient['id']
            . ' channel=' . $deliveryChannel
            . ' suppression=' . $type
        );

        return true;
    }

    protected function sanitize_failure_reason($message)
    {
        $message = trim(strip_tags((string) $message));
        if ($message === '') {
            return 'send_failed';
        }

        $redactions = [
            trim((string) get_option('marketing_smtp_password')),
            trim((string) get_option('marketing_smtp_username')),
            trim((string) get_option('marketing_whatsapp_access_token')),
        ];

        foreach ($redactions as $redaction) {
            if ($redaction !== '') {
                $message = str_replace($redaction, '[redacted]', $message);
            }
        }

        $message = preg_replace('/smtp_pass\s*[:=]\s*[^\s]+/i', 'smtp_pass=[redacted]', $message);
        $message = preg_replace('/access[_\s-]*token\s*[:=]\s*[^\s]+/i', 'access_token=[redacted]', $message);

        return mb_substr($message, 0, 1000);
    }

    public function normalize_email($email)
    {
        $email = trim((string) $email);

        return $email === '' ? null : mb_strtolower($email);
    }

    public function normalize_message_identifier($identifier)
    {
        $identifier = trim((string) $identifier);
        $identifier = trim($identifier, '<>');

        return $identifier === '' ? null : $identifier;
    }

    protected function get_suppressions_model()
    {
        if ($this->marketingSuppressionsModel === null) {
            $this->load->model('marketing/Marketing_suppressions_model', 'marketing_suppressions_model');
            $this->marketingSuppressionsModel = $this->marketing_suppressions_model;
        }

        return $this->marketingSuppressionsModel;
    }

    protected function campaign_images_are_absolute($html)
    {
        if (trim($html) === '') {
            return false;
        }

        if (!preg_match_all('/<img\b[^>]*\bsrc\s*=\s*([\'"])(.*?)\1/i', $html, $matches)) {
            return true;
        }

        foreach ($matches[2] as $src) {
            $src = trim(html_entity_decode($src, ENT_QUOTES, 'UTF-8'));
            if ($src === '' || !preg_match('#^https?://#i', $src)) {
                return false;
            }
        }

        return true;
    }

    private function is_sendable_consent($consentStatus)
    {
        $consentStatus = strtolower(trim((string) $consentStatus));

        return $consentStatus === '' || $consentStatus === 'unknown' || $consentStatus === 'subscribed';
    }

    private function is_active_status($status)
    {
        $status = strtolower(trim((string) $status));

        return $status === '' || $status === 'active' || $status === '1';
    }

    private function sanitize_targeting_value($value)
    {
        return trim((string) $value);
    }

    private function normalized_event_type_sql()
    {
        return "LOWER(REPLACE(REPLACE(TRIM(event_type), ' ', '_'), '-', '_'))";
    }

    private function increment_metric_count(array &$counts, $code, $amount = 1)
    {
        $code = trim((string) $code);
        if (!$this->is_valid_failure_metric_code($code)) {
            return;
        }

        if (!isset($counts[$code])) {
            $counts[$code] = 0;
        }

        $counts[$code] += max(0, (int) $amount);
    }

    private function is_valid_failure_metric_code($code)
    {
        $code = trim((string) $code);

        return $code !== '' && in_array($code, self::FAILURE_METRIC_CODES, true);
    }

    private function normalize_targeting_date($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return '';
        }

        return date('Y-m-d', $timestamp);
    }

    private function normalize_send_window_time($value, $fallback)
    {
        $value = trim((string) $value);
        if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value)) {
            return $value;
        }

        return $fallback;
    }

    private function is_campaign_scheduled_for_future(array $campaign)
    {
        if (($campaign['status'] ?? '') !== 'scheduled') {
            return false;
        }

        if (empty($campaign['scheduled_at'])) {
            return false;
        }

        $scheduledTimestamp = strtotime((string) $campaign['scheduled_at']);
        if ($scheduledTimestamp === false) {
            return false;
        }

        return $scheduledTimestamp > time();
    }

    private function mark_campaign_started(array $campaign)
    {
        $campaignUpdate = [
            'status' => 'sending',
            'scheduled_at' => $campaign['scheduled_at'] ?? null,
        ];

        if ($this->column_exists($this->table, 'send_started_at') && empty($campaign['send_started_at'])) {
            $campaignUpdate['send_started_at'] = date('Y-m-d H:i:s');
        }

        $updated = $this->update((int) $campaign['id'], $campaignUpdate);

        if ($updated) {
            log_message('info', 'Marketing campaign started: campaign_id=' . (int) $campaign['id'] . ' previous_status=' . ($campaign['status'] ?? 'unknown'));
        }

        return $updated;
    }

    public function contact_created_date_filter_supported()
    {
        return $this->column_exists($this->contactsTable, 'datecreated');
    }

    private function extract_builder_state_metadata($html)
    {
        $html = (string) $html;

        if (preg_match('/<!--marketing_builder_state:([A-Za-z0-9+\/=]+)-->/i', $html, $matches)) {
            $decoded = base64_decode($matches[1], true);

            return $decoded !== false ? $decoded : '';
        }

        return '';
    }

    private function option_enabled($key)
    {
        $value = strtolower(trim((string) get_option($key)));

        return in_array($value, ['1', 'yes', 'true', 'on'], true);
    }

    private function clamp_send_batch_limit($limit)
    {
        $limit = (int) $limit;
        if ($limit <= 0) {
            return 1;
        }

        return min(self::MAX_SEND_BATCH_LIMIT, $limit);
    }

    private function campaign_worker_lease_is_stale(array $campaign)
    {
        $cutoffTimestamp = time() - self::QUEUE_WORKER_STALE_TIMEOUT_SECONDS;
        $heartbeatAt = trim((string) ($campaign['queue_worker_heartbeat_at'] ?? ''));
        if ($heartbeatAt !== '') {
            $heartbeatTimestamp = strtotime($heartbeatAt);
            if ($heartbeatTimestamp !== false) {
                return $heartbeatTimestamp <= $cutoffTimestamp;
            }
        }

        $startedAt = trim((string) ($campaign['queue_worker_started_at'] ?? ''));
        if ($startedAt !== '') {
            $startedTimestamp = strtotime($startedAt);
            if ($startedTimestamp !== false) {
                return $startedTimestamp <= $cutoffTimestamp;
            }
        }

        return false;
    }

    private function ensure_recipient_click_metric_columns()
    {
        if (!$this->db->table_exists($this->recipientsTable)) {
            return;
        }

        $requiredColumns = [
            'unique_click_count',
            'last_clicked_url',
        ];

        foreach ($requiredColumns as $column) {
            if ($this->column_exists($this->recipientsTable, $column)) {
                continue;
            }

            if (function_exists('marketing_bootstrap_safe_schema')) {
                marketing_bootstrap_safe_schema(true);
                $this->clear_column_exists_cache($this->recipientsTable, $requiredColumns);
            }

            break;
        }
    }

    private function column_exists($table, $column)
    {
        $cacheKey = $table . '.' . $column;

        if (!array_key_exists($cacheKey, $this->columnExistsCache)) {
            $this->columnExistsCache[$cacheKey] = $this->db->field_exists($column, $table);
        }

        return $this->columnExistsCache[$cacheKey];
    }

    private function clear_column_exists_cache($table, array $columns = [])
    {
        if (empty($columns)) {
            foreach (array_keys($this->columnExistsCache) as $cacheKey) {
                if (strpos($cacheKey, $table . '.') === 0) {
                    unset($this->columnExistsCache[$cacheKey]);
                }
            }

            return;
        }

        foreach ($columns as $column) {
            unset($this->columnExistsCache[$table . '.' . $column]);
        }
    }

    private function filter_existing_columns($table, array $data)
    {
        foreach (array_keys($data) as $column) {
            if (!$this->column_exists($table, $column)) {
                unset($data[$column]);
            }
        }

        return $data;
    }
}
