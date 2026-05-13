<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Marketing_reply_leads_model extends App_Model
{
    protected $ci;
    protected $leadsTable;
    protected $leadsStatusTable;
    protected $leadsSourcesTable;
    protected $staffTable;
    protected $eventsTable;

    public function __construct()
    {
        parent::__construct();

        $this->ci = &get_instance();
        $this->ensureDatabaseAvailable();

        $this->leadsTable        = db_prefix() . 'leads';
        $this->leadsStatusTable  = db_prefix() . 'leads_status';
        $this->leadsSourcesTable = db_prefix() . 'leads_sources';
        $this->staffTable        = db_prefix() . 'staff';
        $this->eventsTable       = db_prefix() . 'marketing_events';
    }

    public function leads_available()
    {
        $db = $this->getDb();

        return $db !== null && $db->table_exists($this->leadsTable);
    }

    public function get_status_options()
    {
        $db = $this->getDb();
        if ($db === null || !$db->table_exists($this->leadsStatusTable)) {
            return [];
        }

        $query = $db->select('id, name')
            ->from($this->leadsStatusTable)
            ->order_by('id', 'ASC')
            ->get();

        return $query ? $query->result_array() : [];
    }

    public function get_source_options()
    {
        $db = $this->getDb();
        if ($db === null || !$db->table_exists($this->leadsSourcesTable)) {
            return [];
        }

        $query = $db->select('id, name')
            ->from($this->leadsSourcesTable)
            ->order_by('id', 'ASC')
            ->get();

        return $query ? $query->result_array() : [];
    }

    public function get_staff_options()
    {
        $db = $this->getDb();
        if ($db === null || !$db->table_exists($this->staffTable)) {
            return [];
        }

        if ($db->field_exists('active', $this->staffTable)) {
            $db->where('active', 1);
        }

        $query = $db->select('staffid, firstname, lastname')
            ->from($this->staffTable)
            ->order_by('firstname', 'ASC')
            ->order_by('lastname', 'ASC')
            ->get();

        return $query ? $query->result_array() : [];
    }

    public function convert_reply_to_lead(array $context)
    {
        $result = [
            'lead_id' => null,
            'lead_status' => 'not_converted',
            'lead_action' => 'disabled',
            'event_type' => 'reply_lead_skipped',
            'lead_error' => null,
        ];

        $db = $this->getDb();
        $email = $this->normalize_email($context['email'] ?? '');
        $enabled = get_option('marketing_reply_to_lead_enabled') === '1';
        $unknownOnly = get_option('marketing_reply_to_lead_unknown_only') === '1';

        if (!$enabled) {
            return $result;
        }

        if ($db === null || !$this->leads_available()) {
            $result['lead_action'] = 'failed';
            $result['lead_status'] = 'failed';
            $result['event_type'] = 'reply_lead_failed';
            $result['lead_error'] = 'Database or leads table unavailable';
            log_message('error', 'Marketing reply-to-lead failed: database or leads table unavailable.');

            return $result;
        }

        if ($email === '') {
            $result['lead_action'] = 'skipped';
            $result['lead_error'] = 'Sender email missing or invalid';
            log_message('warning', 'Marketing reply-to-lead skipped: sender email missing or invalid.');

            return $result;
        }

        $existingLead = $this->find_lead_by_email($email);
        if ($existingLead) {
            return [
                'lead_id' => (int) $existingLead['id'],
                'lead_status' => 'existing_lead',
                'lead_action' => 'existing',
                'event_type' => 'reply_lead_existing',
                'lead_error' => null,
            ];
        }

        if ($unknownOnly && !empty($context['existing_marketing_contact'])) {
            $result['lead_action'] = 'skipped';
            $result['lead_error'] = 'Skipped because sender is already a known marketing contact';

            return $result;
        }

        $leadData = $this->build_lead_data($context, $email);
        if (empty($leadData)) {
            $result['lead_action'] = 'failed';
            $result['lead_status'] = 'failed';
            $result['event_type'] = 'reply_lead_failed';
            $result['lead_error'] = 'No valid lead status/source configured.';
            log_message('error', 'Marketing reply-to-lead preparation failed: no valid lead status/source configured.');

            return $result;
        }

        $leadId = $this->create_lead_direct($leadData);
        if (!$leadId) {
            $dbError = $db->error();
            $safeDbError = $this->sanitize_model_error($dbError['message'] ?? '');
            $result['lead_action'] = 'failed';
            $result['lead_status'] = 'failed';
            $result['event_type'] = 'reply_lead_failed';
            $result['lead_error'] = $safeDbError !== '' ? $safeDbError : 'Lead insert failed.';
            log_message('error', 'Marketing reply-to-lead direct insert failed: ' . ($safeDbError !== '' ? $safeDbError : 'Lead insert failed.'));

            return $result;
        }

        $leadId = (int) $leadId;

        return [
            'lead_id' => $leadId,
            'lead_status' => 'created',
            'lead_action' => 'created',
            'event_type' => 'reply_lead_created',
            'lead_error' => null,
        ];
    }

    public function get_whatsapp_lead_conversion_readiness(array $context)
    {
        $result = [
            'status' => 'manual_required',
            'ready' => false,
            'lead_id' => null,
            'matched_by' => null,
            'reason' => 'whatsapp_manual_conversion_deferred',
        ];

        $db = $this->getDb();
        if ($db === null || !$this->leads_available()) {
            $result['status'] = 'not_ready';
            $result['reason'] = 'leads_unavailable';

            return $result;
        }

        $conversationLeadId = (int) ($context['conversation_lead_id'] ?? ($context['lead_id'] ?? 0));
        if ($conversationLeadId > 0) {
            $result['status'] = 'already_linked';
            $result['lead_id'] = $conversationLeadId;
            $result['matched_by'] = 'conversation_lead_id';
            $result['reason'] = 'conversation_already_linked';

            return $result;
        }

        $email = $this->normalize_email($context['email'] ?? '');
        if ($email !== '') {
            $existingLead = $this->find_lead_by_email($email);
            if ($existingLead) {
                $result['status'] = 'existing_lead';
                $result['lead_id'] = (int) $existingLead['id'];
                $result['matched_by'] = 'email';
                $result['reason'] = 'existing_lead_by_email';

                return $result;
            }
        }

        $phoneMatch = $this->find_lead_by_phone($context['normalized_phone'] ?? ($context['phone'] ?? ''));
        if (!empty($phoneMatch['lead'])) {
            $result['status'] = 'existing_lead';
            $result['lead_id'] = (int) ($phoneMatch['lead']['id'] ?? 0);
            $result['matched_by'] = 'phone';
            $result['reason'] = 'existing_lead_by_phone';

            return $result;
        }

        if (!empty($phoneMatch['reason'])) {
            $result['reason'] = (string) $phoneMatch['reason'];
        }

        return $result;
    }

    public function decorate_replies_with_lead_metadata(array $replies, $campaignId = null)
    {
        if (empty($replies)) {
            return $replies;
        }

        $emails = [];
        foreach ($replies as $reply) {
            $email = $this->normalize_email($reply['email'] ?? $reply['from_email'] ?? '');
            if ($email !== '') {
                $emails[] = $email;
            }
        }

        $emails = array_values(array_unique($emails));
        $leadMap = $this->get_leads_by_emails($emails);
        $eventMap = $this->get_reply_lead_event_map($emails, $campaignId);

        foreach ($replies as &$reply) {
            $email = $this->normalize_email($reply['email'] ?? $reply['from_email'] ?? '');
            $reply['lead_id'] = null;
            $reply['lead_action'] = 'not_converted';
            $reply['lead_status_label'] = 'Not converted';
            $reply['lead_url'] = null;
            $reply['lead_error'] = null;

            $eventMeta = $this->resolve_reply_lead_event_for_reply($reply, $eventMap, $email);
            if ($eventMeta !== null) {
                $reply['lead_action'] = $eventMeta['lead_action'];
                $reply['lead_status_label'] = $this->lead_action_label($eventMeta['lead_action'], !empty($eventMeta['lead_id']));
                $reply['lead_id'] = $eventMeta['lead_id'] ?: null;
                $reply['lead_error'] = $eventMeta['lead_error'] ?? null;
            } elseif (isset($leadMap[$email])) {
                $reply['lead_action'] = 'existing';
                $reply['lead_status_label'] = $this->lead_action_label('existing', true);
                $reply['lead_id'] = (int) $leadMap[$email]['id'];
            }

            if (!empty($reply['lead_id'])) {
                $reply['lead_url'] = admin_url('leads/index/' . (int) $reply['lead_id']);
            }
        }
        unset($reply);

        return $replies;
    }

    private function build_lead_data(array $context, $email)
    {
        $statusId = $this->resolve_status_id(get_option('marketing_reply_to_lead_status'));
        $sourceId = $this->resolve_source_id(get_option('marketing_reply_to_lead_source'));
        $assignedStaffId = $this->resolve_staff_id(get_option('marketing_reply_to_lead_assigned_staff'));

        if ($statusId <= 0 || $sourceId <= 0) {
            return [];
        }

        if (!$this->status_exists($statusId) || !$this->source_exists($sourceId)) {
            return [];
        }

        $leadData = [
            'name'        => $this->build_lead_name($context, $email),
            'email'       => $email,
            'status'      => (int) $statusId,
            'source'      => (int) $sourceId,
            'assigned'    => $assignedStaffId > 0 ? (int) $assignedStaffId : 0,
            'description' => $this->build_lead_description($context),
            'address'     => '',
        ];

        return $leadData;
    }

    private function build_lead_name(array $context, $email)
    {
        $senderName = trim((string) ($context['sender_name'] ?? ''));
        if ($senderName !== '') {
            return mb_substr($senderName, 0, 191);
        }

        $prefix = trim((string) get_option('marketing_reply_to_lead_name_prefix'));
        if ($prefix === '') {
            $prefix = 'Marketing Reply';
        }

        return mb_substr($prefix . ' ' . $email, 0, 191);
    }

    private function build_lead_description(array $context)
    {
        $lines = [];
        if (!empty($context['campaign_name']) || !empty($context['campaign_id'])) {
            $campaignLine = 'Campaign: ';
            if (!empty($context['campaign_name'])) {
                $campaignLine .= trim((string) $context['campaign_name']);
                if (!empty($context['campaign_id'])) {
                    $campaignLine .= ' (#' . (int) $context['campaign_id'] . ')';
                }
            } else {
                $campaignLine .= '#' . (int) $context['campaign_id'];
            }
            $lines[] = $campaignLine;
        }

        if (!empty($context['subject'])) {
            $lines[] = 'Reply Subject: ' . trim((string) $context['subject']);
        }

        if (!empty($context['preview'])) {
            $lines[] = 'Reply Preview: ' . trim((string) $context['preview']);
        }

        if (!empty($context['conversation_id'])) {
            $lines[] = 'Conversation ID: ' . (int) $context['conversation_id'];
        }

        $description = strip_tags(implode("\n", $lines));
        $description = preg_replace('/\s+/', ' ', (string) $description);

        return mb_substr(trim($description), 0, 1200);
    }

    private function resolve_status_id($optionValue)
    {
        $optionId = (int) $optionValue;
        $options = $this->get_status_options();
        if (empty($options)) {
            return 0;
        }

        foreach ($options as $option) {
            if ((int) $option['id'] === $optionId) {
                return $optionId;
            }
        }

        return (int) $options[0]['id'];
    }

    private function resolve_source_id($optionValue)
    {
        $optionId = (int) $optionValue;
        $options = $this->get_source_options();
        if (empty($options)) {
            return 0;
        }

        foreach ($options as $option) {
            if ((int) $option['id'] === $optionId) {
                return $optionId;
            }
        }

        return (int) $options[0]['id'];
    }

    private function resolve_staff_id($optionValue)
    {
        $optionId = (int) $optionValue;
        $options = $this->get_staff_options();
        if ($optionId <= 0 || empty($options)) {
            return 0;
        }

        foreach ($options as $option) {
            if ((int) $option['staffid'] === $optionId) {
                return $optionId;
            }
        }

        return 0;
    }

    private function status_exists($statusId)
    {
        $db = $this->getDb();
        $statusId = (int) $statusId;
        if ($db === null || $statusId <= 0 || !$db->table_exists($this->leadsStatusTable)) {
            return false;
        }

        $query = $db->select('id')
            ->from($this->leadsStatusTable)
            ->where('id', $statusId)
            ->limit(1)
            ->get();

        return $query && !empty($query->row_array());
    }

    private function source_exists($sourceId)
    {
        $db = $this->getDb();
        $sourceId = (int) $sourceId;
        if ($db === null || $sourceId <= 0 || !$db->table_exists($this->leadsSourcesTable)) {
            return false;
        }

        $query = $db->select('id')
            ->from($this->leadsSourcesTable)
            ->where('id', $sourceId)
            ->limit(1)
            ->get();

        return $query && !empty($query->row_array());
    }

    private function find_lead_by_email($email)
    {
        $db = $this->getDb();
        if ($db === null || !$this->leads_available()) {
            return null;
        }

        $query = $db->from($this->leadsTable)
            ->where('email', $email)
            ->order_by('id', 'DESC')
            ->limit(1)
            ->get();

        return $query ? $query->row_array() : null;
    }

    private function get_leads_by_emails(array $emails)
    {
        $db = $this->getDb();
        if ($db === null || !$this->leads_available() || empty($emails)) {
            return [];
        }

        $query = $db->select('id, email')
            ->from($this->leadsTable)
            ->where_in('email', $emails)
            ->get();
        $rows = $query ? $query->result_array() : [];

        $map = [];
        foreach ($rows as $row) {
            $email = $this->normalize_email($row['email'] ?? '');
            if ($email !== '' && !isset($map[$email])) {
                $map[$email] = $row;
            }
        }

        return $map;
    }

    private function find_lead_by_phone($phone)
    {
        $db = $this->getDb();
        $normalizedPhone = $this->normalize_phone($phone);
        if ($db === null || !$this->leads_available() || $normalizedPhone === '') {
            return [
                'lead' => null,
                'reason' => $normalizedPhone === '' ? 'phone_missing' : 'leads_unavailable',
            ];
        }

        $phoneField = $this->resolve_leads_phone_field();
        if ($phoneField === null) {
            return [
                'lead' => null,
                'reason' => 'lead_phone_lookup_not_available',
            ];
        }

        $query = $db->select('id, ' . $phoneField)
            ->from($this->leadsTable)
            ->where($phoneField . ' IS NOT NULL', null, false)
            ->where($phoneField . ' !=', '')
            ->order_by('id', 'DESC')
            ->get();

        $rows = $query ? $query->result_array() : [];
        foreach ($rows as $row) {
            if ($this->normalize_phone($row[$phoneField] ?? '') === $normalizedPhone) {
                return [
                    'lead' => $row,
                    'reason' => null,
                ];
            }
        }

        return [
            'lead' => null,
            'reason' => 'no_existing_lead_match',
        ];
    }

    private function get_reply_lead_event_map(array $emails, $campaignId = null)
    {
        $db = $this->getDb();
        if ($db === null || empty($emails) || !$db->table_exists($this->eventsTable)) {
            return [];
        }

        $db->select('id, campaign_id, event_type, email, payload_json, event_at');
        $db->from($this->eventsTable);
        $db->where_in('event_type', ['reply_lead_created', 'reply_lead_existing', 'reply_lead_skipped', 'reply_lead_failed']);
        $db->where_in('email', $emails);
        if ($campaignId !== null) {
            $db->where('campaign_id', (int) $campaignId);
        }

        $query = $db->order_by('id', 'DESC')->get();
        $rows = $query ? $query->result_array() : [];
        $map = [
            'by_message' => [],
            'by_conversation' => [],
            'by_email' => [],
        ];

        foreach ($rows as $row) {
            $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
            if (!is_array($payload)) {
                $payload = [];
            }

            $meta = [
                'lead_id' => isset($payload['lead_id']) ? (int) $payload['lead_id'] : null,
                'lead_action' => isset($payload['lead_action']) ? (string) $payload['lead_action'] : $this->map_event_type_to_lead_action($row['event_type'] ?? ''),
                'lead_error' => isset($payload['lead_error']) ? (string) $payload['lead_error'] : null,
            ];

            if (!empty($payload['message_id']) && !isset($map['by_message'][(int) $payload['message_id']])) {
                $map['by_message'][(int) $payload['message_id']] = $meta;
            }

            if (!empty($payload['conversation_id']) && !isset($map['by_conversation'][(int) $payload['conversation_id']])) {
                $map['by_conversation'][(int) $payload['conversation_id']] = $meta;
            }

            $normalizedEmail = $this->normalize_email($row['email'] ?? '');
            if ($normalizedEmail !== '' && !isset($map['by_email'][$normalizedEmail])) {
                $map['by_email'][$normalizedEmail] = $meta;
            }
        }

        return $map;
    }

    private function resolve_reply_lead_event_for_reply(array $reply, array $eventMap, $email)
    {
        $messageId = isset($reply['id']) ? (int) $reply['id'] : 0;
        $conversationId = isset($reply['conversation_id']) ? (int) $reply['conversation_id'] : 0;

        if ($messageId > 0 && isset($eventMap['by_message'][$messageId])) {
            return $eventMap['by_message'][$messageId];
        }

        if ($conversationId > 0 && isset($eventMap['by_conversation'][$conversationId])) {
            return $eventMap['by_conversation'][$conversationId];
        }

        if ($email !== '' && isset($eventMap['by_email'][$email])) {
            return $eventMap['by_email'][$email];
        }

        return null;
    }

    private function map_event_type_to_lead_action($eventType)
    {
        switch ((string) $eventType) {
            case 'reply_lead_created':
                return 'created';
            case 'reply_lead_existing':
                return 'existing';
            case 'reply_lead_failed':
                return 'failed';
            case 'reply_lead_skipped':
            default:
                return 'skipped';
        }
    }

    private function lead_action_label($leadAction, $hasLeadId = false)
    {
        switch ((string) $leadAction) {
            case 'created':
                return $hasLeadId ? 'Lead created' : 'Failed';
            case 'existing':
                return $hasLeadId ? 'Existing lead' : 'Failed';
            case 'failed':
                return 'Failed';
            default:
                return 'Not converted';
        }
    }

    private function normalize_email($email)
    {
        $email = mb_strtolower(trim((string) $email));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    private function normalize_phone($phone)
    {
        $phone = trim((string) $phone);
        if ($phone === '') {
            return '';
        }

        $prefix = strpos($phone, '+') === 0 ? '+' : '';
        $digits = preg_replace('/[^0-9]/', '', $phone);

        return $digits === '' ? '' : $prefix . $digits;
    }

    private function resolve_leads_phone_field()
    {
        $db = $this->getDb();
        if ($db === null || !$this->leads_available()) {
            return null;
        }

        foreach (['phonenumber', 'phone'] as $candidate) {
            if ($db->field_exists($candidate, $this->leadsTable)) {
                return $candidate;
            }
        }

        return null;
    }

    private function sanitize_model_error($message)
    {
        $message = trim(strip_tags((string) $message));
        $message = preg_replace('/\s+/', ' ', $message);

        return mb_substr($message, 0, 200);
    }

    private function create_lead_direct(array $leadData)
    {
        $db = $this->getDb();
        if ($db === null || !$this->leads_available()) {
            return 0;
        }

        $statusId = isset($leadData['status']) ? (int) $leadData['status'] : 0;
        $sourceId = isset($leadData['source']) ? (int) $leadData['source'] : 0;
        if ($statusId <= 0 || $sourceId <= 0) {
            return 0;
        }

        $insert = [
            'name' => isset($leadData['name']) ? trim((string) $leadData['name']) : '',
            'email' => isset($leadData['email']) ? trim((string) $leadData['email']) : '',
            'status' => $statusId,
            'source' => $sourceId,
            'assigned' => isset($leadData['assigned']) ? (int) $leadData['assigned'] : 0,
            'description' => nl2br((string) ($leadData['description'] ?? '')),
            'dateadded' => date('Y-m-d H:i:s'),
            'addedfrom' => function_exists('get_staff_user_id') ? (int) get_staff_user_id() : 0,
            'address' => '',
            'is_public' => 0,
            'country' => 0,
        ];

        if ($insert['name'] === '' || $insert['email'] === '') {
            return 0;
        }

        $existingFields = $db->list_fields($this->leadsTable);
        if (!is_array($existingFields) || empty($existingFields)) {
            return 0;
        }

        foreach (array_keys($insert) as $column) {
            if (!in_array($column, $existingFields, true)) {
                unset($insert[$column]);
            }
        }

        $db->insert($this->leadsTable, $insert);

        return (int) $db->insert_id();
    }

    private function ensureDatabaseAvailable()
    {
        if (isset($this->db) && is_object($this->db)) {
            return;
        }

        if (isset($this->ci->db) && is_object($this->ci->db)) {
            $this->db = $this->ci->db;

            return;
        }

        if (isset($this->ci->load)) {
            $this->ci->load->database();
            if (isset($this->ci->db) && is_object($this->ci->db)) {
                $this->db = $this->ci->db;
            }
        }
    }

    private function getDb()
    {
        $this->ensureDatabaseAvailable();

        if (isset($this->db) && is_object($this->db)) {
            return $this->db;
        }

        return null;
    }
}
