<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Marketing_conversations_model extends App_Model
{
    const CONTACTS_TABLE = 'marketing_contacts';
    const CONVERSATIONS_TABLE = 'marketing_conversations';
    const MESSAGES_TABLE = 'marketing_messages';

    protected $contactsTable;
    protected $conversationsTable;
    protected $messagesTable;

    public function __construct()
    {
        parent::__construct();

        $this->contactsTable      = db_prefix() . self::CONTACTS_TABLE;
        $this->conversationsTable = db_prefix() . self::CONVERSATIONS_TABLE;
        $this->messagesTable      = db_prefix() . self::MESSAGES_TABLE;
    }

    public function count_conversations()
    {
        return $this->db->count_all($this->conversationsTable);
    }

    public function count_messages()
    {
        return $this->db->count_all($this->messagesTable);
    }

    public function conversation_exists($id)
    {
        return total_rows($this->conversationsTable, ['id' => $id]) > 0;
    }

    public function tables_available()
    {
        return $this->db->table_exists($this->conversationsTable)
            && $this->db->table_exists($this->messagesTable);
    }

    public function find_conversation($campaignId = null, $contactId = null, $email = null, array $options = [])
    {
        if (!$this->db->table_exists($this->conversationsTable)) {
            return null;
        }

        $providerThreadId = trim((string) ($options['provider_thread_id'] ?? ''));
        $normalizedPhone = $this->normalize_phone($options['normalized_phone'] ?? ($options['contact_phone'] ?? ''));
        $channel = trim((string) ($options['channel'] ?? ''));
        $provider = trim((string) ($options['provider'] ?? ''));

        $this->db->from($this->conversationsTable);

        if ($campaignId !== null && $this->db->field_exists('campaign_id', $this->conversationsTable)) {
            $this->db->where('campaign_id', (int) $campaignId);
        }

        if ($channel !== '' && $this->db->field_exists('channel', $this->conversationsTable)) {
            $this->db->where('channel', $channel);
        }

        if ($provider !== '' && $this->db->field_exists('provider', $this->conversationsTable)) {
            $this->db->where('provider', $provider);
        }

        if ($providerThreadId !== '' && $this->db->field_exists('provider_thread_id', $this->conversationsTable)) {
            $this->db->where('provider_thread_id', $providerThreadId);
        } elseif ($contactId !== null && $this->db->field_exists('marketing_contact_id', $this->conversationsTable)) {
            $this->db->where('marketing_contact_id', (int) $contactId);
        } elseif ($normalizedPhone !== '' && $this->db->field_exists('normalized_phone', $this->conversationsTable)) {
            $this->db->where('normalized_phone', $normalizedPhone);
        } elseif ($normalizedPhone !== '' && $this->db->field_exists('contact_phone', $this->conversationsTable)) {
            $this->db->where('contact_phone', $normalizedPhone);
        } elseif ($email !== null && $email !== '' && $this->db->field_exists('email', $this->conversationsTable)) {
            $this->db->where('email', mb_strtolower(trim((string) $email)));
        } elseif ($email !== null && $email !== '' && $this->db->field_exists('contact_email', $this->conversationsTable)) {
            $this->db->where('contact_email', mb_strtolower(trim((string) $email)));
        }

        return $this->db->order_by('id', 'DESC')->limit(1)->get()->row_array();
    }

    public function find_or_create_conversation(array $data)
    {
        if (!$this->db->table_exists($this->conversationsTable)) {
            return null;
        }

        $campaignId = isset($data['campaign_id']) ? (int) $data['campaign_id'] : null;
        $contactId = isset($data['marketing_contact_id']) ? (int) $data['marketing_contact_id'] : null;
        $email = isset($data['email']) ? trim((string) $data['email']) : '';
        $normalizedPhone = $this->normalize_phone($data['normalized_phone'] ?? ($data['contact_phone'] ?? ''));
        $providerThreadId = trim((string) ($data['provider_thread_id'] ?? ''));
        $sourceType = isset($data['source_type']) ? trim((string) $data['source_type']) : '';
        $provider = isset($data['provider']) ? trim((string) $data['provider']) : '';
        $channel = isset($data['channel']) ? trim((string) $data['channel']) : '';
        if ($channel === '' && ($sourceType === 'email_reply' || $provider === 'inbound_email')) {
            $channel = 'email';
        }
        if ($channel === '') {
            $channel = 'email';
        }
        $existing = $this->find_conversation($campaignId, $contactId ?: null, $email, [
            'channel' => $channel,
            'provider' => $provider,
            'normalized_phone' => $normalizedPhone,
            'provider_thread_id' => $providerThreadId,
        ]);
        if ($existing) {
            $this->touch_conversation((int) $existing['id'], $data);

            return $this->get_conversation_row((int) $existing['id']);
        }

        $insert = [
            'campaign_id' => $campaignId,
            'marketing_contact_id' => $contactId ?: null,
            'email' => $email !== '' ? mb_strtolower($email) : null,
            'contact_email' => $email !== '' ? mb_strtolower($email) : null,
            'channel' => $channel,
            'provider' => $provider !== '' ? $provider : null,
            'contact_phone' => $normalizedPhone !== '' ? $normalizedPhone : null,
            'normalized_phone' => $normalizedPhone !== '' ? $normalizedPhone : null,
            'provider_thread_id' => $providerThreadId !== '' ? $providerThreadId : null,
            'lead_id' => !empty($data['lead_id']) ? (int) $data['lead_id'] : null,
            'assigned_staff_id' => !empty($data['assigned_staff_id']) ? (int) $data['assigned_staff_id'] : null,
            'unread_count' => isset($data['unread_count']) ? max(0, (int) $data['unread_count']) : 0,
            'subject' => isset($data['subject']) ? trim((string) $data['subject']) : null,
            'last_message_at' => isset($data['last_message_at']) ? (string) $data['last_message_at'] : date('Y-m-d H:i:s'),
            'last_inbound_at' => isset($data['last_inbound_at']) ? (string) $data['last_inbound_at'] : null,
            'last_outbound_at' => isset($data['last_outbound_at']) ? (string) $data['last_outbound_at'] : null,
            'status' => isset($data['status']) ? trim((string) $data['status']) : 'open',
            'source_type' => isset($data['source_type']) ? trim((string) $data['source_type']) : 'email_reply',
            'internal_notes' => isset($data['internal_notes']) ? trim((string) $data['internal_notes']) : null,
            'datecreated' => date('Y-m-d H:i:s'),
        ];

        $insert = $this->filter_existing_columns($this->conversationsTable, $insert);
        $this->db->insert($this->conversationsTable, $insert);
        $conversationId = (int) $this->db->insert_id();

        if ($conversationId <= 0) {
            return null;
        }

        return $this->get_conversation_row($conversationId);
    }

    public function add_message(array $data)
    {
        if (!$this->db->table_exists($this->messagesTable)) {
            return 0;
        }

        $provider = isset($data['provider']) ? trim((string) $data['provider']) : '';
        $channel = isset($data['channel']) ? trim((string) $data['channel']) : '';
        if ($channel === '' && $provider === 'inbound_email') {
            $channel = 'email';
        }

        $insert = [
            'conversation_id' => isset($data['conversation_id']) ? (int) $data['conversation_id'] : 0,
            'campaign_id' => isset($data['campaign_id']) ? (int) $data['campaign_id'] : null,
            'marketing_contact_id' => isset($data['marketing_contact_id']) ? (int) $data['marketing_contact_id'] : null,
            'direction' => isset($data['direction']) ? trim((string) $data['direction']) : 'inbound',
            'channel' => $channel !== '' ? $channel : null,
            'email' => isset($data['email']) ? mb_strtolower(trim((string) $data['email'])) : null,
            'from_email' => isset($data['from_email'])
                ? mb_strtolower(trim((string) $data['from_email']))
                : (isset($data['email']) ? mb_strtolower(trim((string) $data['email'])) : null),
            'provider' => $provider !== '' ? $provider : null,
            'message_type' => isset($data['message_type']) ? trim((string) $data['message_type']) : null,
            'provider_thread_id' => isset($data['provider_thread_id']) ? trim((string) $data['provider_thread_id']) : null,
            'provider_status' => isset($data['provider_status']) ? trim((string) $data['provider_status']) : null,
            'subject' => isset($data['subject']) ? trim((string) $data['subject']) : null,
            'message' => isset($data['message']) ? (string) $data['message'] : null,
            'body' => isset($data['message']) ? (string) $data['message'] : null,
            'body_text' => isset($data['body_text']) ? (string) $data['body_text'] : (isset($data['message']) ? (string) $data['message'] : null),
            'body_html' => isset($data['body_html']) ? (string) $data['body_html'] : null,
            'media_meta_json' => $this->encode_media_meta_json($data['media_meta_json'] ?? ($data['media_meta'] ?? null)),
            'context_provider_message_id' => isset($data['context_provider_message_id']) ? trim((string) $data['context_provider_message_id']) : null,
            'provider_message_id' => isset($data['provider_message_id']) ? trim((string) $data['provider_message_id']) : null,
            'dedupe_key' => isset($data['dedupe_key']) ? trim((string) $data['dedupe_key']) : null,
            'datecreated' => isset($data['datecreated']) ? (string) $data['datecreated'] : date('Y-m-d H:i:s'),
        ];

        $insert = $this->filter_existing_columns($this->messagesTable, $insert);
        if (empty($insert['conversation_id'])) {
            return 0;
        }

        $this->db->insert($this->messagesTable, $insert);
        $messageId = (int) $this->db->insert_id();

        if ($messageId > 0) {
            $this->touch_conversation((int) $insert['conversation_id'], [
                'direction' => $insert['direction'] ?? 'inbound',
                'last_message_at' => $insert['datecreated'] ?? date('Y-m-d H:i:s'),
                'subject' => $insert['subject'] ?? null,
                'channel' => $insert['channel'] ?? null,
                'provider' => $insert['provider'] ?? null,
                'contact_phone' => $data['contact_phone'] ?? null,
                'normalized_phone' => $data['normalized_phone'] ?? null,
                'provider_thread_id' => $insert['provider_thread_id'] ?? null,
            ]);
        }

        return $messageId;
    }

    public function find_message_by_provider_message_id($providerMessageId, $provider = null)
    {
        if (!$this->db->table_exists($this->messagesTable) || !$this->db->field_exists('provider_message_id', $this->messagesTable)) {
            return null;
        }

        $providerMessageId = trim((string) $providerMessageId);
        if ($providerMessageId === '') {
            return null;
        }

        $this->db->from($this->messagesTable);
        $this->db->where('provider_message_id', $providerMessageId);
        if ($provider !== null && $provider !== '' && $this->db->field_exists('provider', $this->messagesTable)) {
            $this->db->where('provider', trim((string) $provider));
        }

        return $this->db->order_by('id', 'DESC')->limit(1)->get()->row_array();
    }

    public function find_message_by_dedupe_key($dedupeKey)
    {
        if (!$this->db->table_exists($this->messagesTable) || !$this->db->field_exists('dedupe_key', $this->messagesTable)) {
            return null;
        }

        $dedupeKey = trim((string) $dedupeKey);
        if ($dedupeKey === '') {
            return null;
        }

        return $this->db->from($this->messagesTable)
            ->where('dedupe_key', $dedupeKey)
            ->order_by('id', 'DESC')
            ->limit(1)
            ->get()
            ->row_array();
    }

    public function add_message_idempotent(array $data)
    {
        $providerMessageId = trim((string) ($data['provider_message_id'] ?? ''));
        $provider = trim((string) ($data['provider'] ?? ''));
        $dedupeKey = trim((string) ($data['dedupe_key'] ?? ''));

        if ($providerMessageId !== '') {
            $existingByProvider = $this->find_message_by_provider_message_id($providerMessageId, $provider !== '' ? $provider : null);
            if ($existingByProvider) {
                return [
                    'success' => true,
                    'inserted' => false,
                    'duplicate' => true,
                    'id' => (int) $existingByProvider['id'],
                    'conversation_id' => (int) ($existingByProvider['conversation_id'] ?? 0),
                    'reason' => 'provider_message_id',
                ];
            }
        }

        if ($dedupeKey !== '') {
            $existingByDedupeKey = $this->find_message_by_dedupe_key($dedupeKey);
            if ($existingByDedupeKey) {
                return [
                    'success' => true,
                    'inserted' => false,
                    'duplicate' => true,
                    'id' => (int) $existingByDedupeKey['id'],
                    'conversation_id' => (int) ($existingByDedupeKey['conversation_id'] ?? 0),
                    'reason' => 'dedupe_key',
                ];
            }
        }

        $messageId = $this->add_message($data);
        if ($messageId > 0) {
            return [
                'success' => true,
                'inserted' => true,
                'duplicate' => false,
                'id' => (int) $messageId,
                'conversation_id' => (int) ($data['conversation_id'] ?? 0),
            ];
        }

        if ($providerMessageId !== '') {
            $existingByProvider = $this->find_message_by_provider_message_id($providerMessageId, $provider !== '' ? $provider : null);
            if ($existingByProvider) {
                return [
                    'success' => true,
                    'inserted' => false,
                    'duplicate' => true,
                    'id' => (int) $existingByProvider['id'],
                    'conversation_id' => (int) ($existingByProvider['conversation_id'] ?? 0),
                    'reason' => 'provider_message_id',
                ];
            }
        }

        if ($dedupeKey !== '') {
            $existingByDedupeKey = $this->find_message_by_dedupe_key($dedupeKey);
            if ($existingByDedupeKey) {
                return [
                    'success' => true,
                    'inserted' => false,
                    'duplicate' => true,
                    'id' => (int) $existingByDedupeKey['id'],
                    'conversation_id' => (int) ($existingByDedupeKey['conversation_id'] ?? 0),
                    'reason' => 'dedupe_key',
                ];
            }
        }

        return [
            'success' => false,
            'inserted' => false,
            'duplicate' => false,
            'id' => 0,
        ];
    }

    public function get_campaign_replies($campaignId, $limit = 20)
    {
        if (!$this->tables_available()) {
            return [];
        }

        $limit = max(1, (int) $limit);
        $conversationCampaignField = $this->db->field_exists('campaign_id', $this->conversationsTable) ? 'c.campaign_id' : null;
        $messageCampaignField = $this->db->field_exists('campaign_id', $this->messagesTable) ? 'm.campaign_id' : null;
        $select = [
            'm.id',
            'm.direction',
            'c.id as conversation_id',
            ($this->db->field_exists('subject', $this->messagesTable) ? 'm.subject' : 'NULL as subject'),
            ($this->db->field_exists('email', $this->messagesTable) ? 'm.email' : 'NULL as email'),
            ($this->db->field_exists('from_email', $this->messagesTable) ? 'm.from_email' : 'NULL as from_email'),
            ($this->db->field_exists('body_text', $this->messagesTable) ? 'm.body_text' : 'NULL as body_text'),
            ($this->db->field_exists('body', $this->messagesTable) ? 'm.body' : 'NULL as body'),
            ($this->db->field_exists('datecreated', $this->messagesTable) ? 'm.datecreated' : 'NULL as datecreated'),
        ];

        $this->db->select(implode(', ', $select), false);
        $this->db->from($this->messagesTable . ' m');
        $this->db->join($this->conversationsTable . ' c', 'c.id = m.conversation_id', 'left');
        $this->db->where('m.direction', 'inbound');
        if ($messageCampaignField !== null && $conversationCampaignField !== null) {
            $this->db->group_start()
                ->where($messageCampaignField, (int) $campaignId)
                ->or_where($conversationCampaignField, (int) $campaignId)
                ->group_end();
        } elseif ($messageCampaignField !== null) {
            $this->db->where($messageCampaignField, (int) $campaignId);
        } elseif ($conversationCampaignField !== null) {
            $this->db->where($conversationCampaignField, (int) $campaignId);
        } else {
            return [];
        }

        $rows = $this->db->order_by('m.id', 'DESC')->limit($limit)->get()->result_array();

        return $this->append_previews($rows);
    }

    public function get_unmatched_inbound_replies($limit = 5)
    {
        if (!$this->tables_available()) {
            return [];
        }

        $limit = max(1, (int) $limit);
        $conversationCampaignField = $this->db->field_exists('campaign_id', $this->conversationsTable) ? 'c.campaign_id' : null;
        $messageCampaignField = $this->db->field_exists('campaign_id', $this->messagesTable) ? 'm.campaign_id' : null;
        $select = [
            'm.id',
            'm.direction',
            'c.id as conversation_id',
            ($this->db->field_exists('subject', $this->messagesTable) ? 'm.subject' : 'NULL as subject'),
            ($this->db->field_exists('email', $this->messagesTable) ? 'm.email' : 'NULL as email'),
            ($this->db->field_exists('from_email', $this->messagesTable) ? 'm.from_email' : 'NULL as from_email'),
            ($this->db->field_exists('body_text', $this->messagesTable) ? 'm.body_text' : 'NULL as body_text'),
            ($this->db->field_exists('body', $this->messagesTable) ? 'm.body' : 'NULL as body'),
            ($this->db->field_exists('datecreated', $this->messagesTable) ? 'm.datecreated' : 'NULL as datecreated'),
        ];

        $this->db->select(implode(', ', $select), false);
        $this->db->from($this->messagesTable . ' m');
        $this->db->join($this->conversationsTable . ' c', 'c.id = m.conversation_id', 'left');
        $this->db->where('m.direction', 'inbound');

        if ($messageCampaignField !== null) {
            $this->db->group_start()
                ->where($messageCampaignField, null)
                ->or_where($messageCampaignField, 0)
                ->group_end();
        }

        if ($conversationCampaignField !== null) {
            $this->db->group_start()
                ->where($conversationCampaignField, null)
                ->or_where($conversationCampaignField, 0)
                ->group_end();
        }

        $rows = $this->db->order_by('m.id', 'DESC')->limit($limit)->get()->result_array();

        return $this->append_previews($rows);
    }

    public function get_recent_whatsapp_conversations($limit = 5)
    {
        $summary = [
            'available' => false,
            'shown_count' => 0,
            'unread_conversation_count' => 0,
            'unread_message_count' => 0,
            'items' => [],
        ];

        if (!$this->tables_available()
            || !$this->db->field_exists('id', $this->conversationsTable)
            || !$this->db->field_exists('channel', $this->conversationsTable)
            || !$this->db->field_exists('provider', $this->conversationsTable)) {
            return $summary;
        }

        $limit = max(1, min(10, (int) $limit));
        $select = [
            'c.id',
            ($this->db->field_exists('contact_phone', $this->conversationsTable) ? 'c.contact_phone' : 'NULL as contact_phone'),
            ($this->db->field_exists('normalized_phone', $this->conversationsTable) ? 'c.normalized_phone' : 'NULL as normalized_phone'),
            ($this->db->field_exists('unread_count', $this->conversationsTable) ? 'c.unread_count' : '0 as unread_count'),
            ($this->db->field_exists('lead_id', $this->conversationsTable) ? 'c.lead_id' : 'NULL as lead_id'),
            ($this->db->field_exists('last_message_at', $this->conversationsTable) ? 'c.last_message_at' : 'NULL as last_message_at'),
            ($this->db->field_exists('dateupdated', $this->conversationsTable) ? 'c.dateupdated' : 'NULL as dateupdated'),
            'NULL as contact_full_name',
        ];

        $canJoinContacts = $this->db->table_exists($this->contactsTable)
            && $this->db->field_exists('marketing_contact_id', $this->conversationsTable)
            && $this->db->field_exists('id', $this->contactsTable);
        if ($canJoinContacts) {
            if ($this->db->field_exists('full_name', $this->contactsTable)) {
                $select[count($select) - 1] = 'mc.full_name as contact_full_name';
            } elseif ($this->db->field_exists('first_name', $this->contactsTable) && $this->db->field_exists('last_name', $this->contactsTable)) {
                $select[count($select) - 1] = "TRIM(CONCAT(COALESCE(mc.first_name, ''), ' ', COALESCE(mc.last_name, ''))) as contact_full_name";
            }
        }

        $this->db->select(implode(', ', $select), false);
        $this->db->from($this->conversationsTable . ' c');
        if ($canJoinContacts) {
            $this->db->join($this->contactsTable . ' mc', 'mc.id = c.marketing_contact_id', 'left');
        }
        $this->db->where('c.channel', 'whatsapp');
        $this->db->where('c.provider', 'whatsapp_cloud');
        if ($this->db->field_exists('unread_count', $this->conversationsTable)) {
            $this->db->order_by('c.unread_count', 'DESC');
        }
        if ($this->db->field_exists('last_message_at', $this->conversationsTable)) {
            $this->db->order_by('c.last_message_at', 'DESC');
        } elseif ($this->db->field_exists('dateupdated', $this->conversationsTable)) {
            $this->db->order_by('c.dateupdated', 'DESC');
        } else {
            $this->db->order_by('c.id', 'DESC');
        }

        $rows = $this->db->limit($limit)->get()->result_array();
        if (empty($rows)) {
            $summary['available'] = true;

            return $summary;
        }

        $items = [];
        $unreadConversationCount = 0;
        $unreadMessageCount = 0;
        foreach ($rows as $row) {
            $unreadCount = max(0, (int) ($row['unread_count'] ?? 0));
            if ($unreadCount > 0) {
                $unreadConversationCount++;
                $unreadMessageCount += $unreadCount;
            }

            $items[] = [
                'id' => (int) ($row['id'] ?? 0),
                'contact_name' => trim((string) ($row['contact_full_name'] ?? '')),
                'contact_phone' => trim((string) ($row['contact_phone'] ?? '')),
                'normalized_phone' => trim((string) ($row['normalized_phone'] ?? '')),
                'unread_count' => $unreadCount,
                'lead_id' => !empty($row['lead_id']) ? (int) $row['lead_id'] : null,
                'last_activity_at' => trim((string) ($row['last_message_at'] ?? ($row['dateupdated'] ?? ''))),
                'preview' => '',
            ];
        }

        $summary['available'] = true;
        $summary['shown_count'] = count($items);
        $summary['unread_conversation_count'] = $unreadConversationCount;
        $summary['unread_message_count'] = $unreadMessageCount;
        $summary['items'] = $this->append_whatsapp_conversation_previews($items);

        return $summary;
    }

    public function get_whatsapp_conversation_context($conversationId)
    {
        $conversationId = (int) $conversationId;
        if ($conversationId <= 0
            || !$this->tables_available()
            || !$this->db->field_exists('id', $this->conversationsTable)
            || !$this->db->field_exists('channel', $this->conversationsTable)
            || !$this->db->field_exists('provider', $this->conversationsTable)) {
            return null;
        }

        $select = [
            'c.id',
            ($this->db->field_exists('lead_id', $this->conversationsTable) ? 'c.lead_id' : 'NULL as lead_id'),
            ($this->db->field_exists('contact_phone', $this->conversationsTable) ? 'c.contact_phone' : 'NULL as contact_phone'),
            ($this->db->field_exists('normalized_phone', $this->conversationsTable) ? 'c.normalized_phone' : 'NULL as normalized_phone'),
            ($this->db->field_exists('email', $this->conversationsTable) ? 'c.email' : 'NULL as email'),
            ($this->db->field_exists('marketing_contact_id', $this->conversationsTable) ? 'c.marketing_contact_id' : 'NULL as marketing_contact_id'),
            ($this->db->field_exists('last_message_at', $this->conversationsTable) ? 'c.last_message_at' : 'NULL as last_message_at'),
            'NULL as contact_full_name',
        ];

        $canJoinContacts = $this->db->table_exists($this->contactsTable)
            && $this->db->field_exists('marketing_contact_id', $this->conversationsTable)
            && $this->db->field_exists('id', $this->contactsTable);
        if ($canJoinContacts) {
            if ($this->db->field_exists('full_name', $this->contactsTable)) {
                $select[count($select) - 1] = 'mc.full_name as contact_full_name';
            } elseif ($this->db->field_exists('first_name', $this->contactsTable) && $this->db->field_exists('last_name', $this->contactsTable)) {
                $select[count($select) - 1] = "TRIM(CONCAT(COALESCE(mc.first_name, ''), ' ', COALESCE(mc.last_name, ''))) as contact_full_name";
            }
        }

        $this->db->select(implode(', ', $select), false);
        $this->db->from($this->conversationsTable . ' c');
        if ($canJoinContacts) {
            $this->db->join($this->contactsTable . ' mc', 'mc.id = c.marketing_contact_id', 'left');
        }
        $this->db->where('c.id', $conversationId);
        $this->db->where('c.channel', 'whatsapp');
        $this->db->where('c.provider', 'whatsapp_cloud');
        $row = $this->db->limit(1)->get()->row_array();
        if (empty($row)) {
            return null;
        }

        return [
            'conversation_id' => (int) ($row['id'] ?? 0),
            'lead_id' => !empty($row['lead_id']) ? (int) $row['lead_id'] : null,
            'marketing_contact_id' => !empty($row['marketing_contact_id']) ? (int) $row['marketing_contact_id'] : null,
            'contact_name' => trim((string) ($row['contact_full_name'] ?? '')),
            'phone' => trim((string) ($row['contact_phone'] ?? '')),
            'normalized_phone' => trim((string) ($row['normalized_phone'] ?? '')),
            'email' => trim((string) ($row['email'] ?? '')),
            'preview' => $this->get_latest_inbound_message_preview($conversationId),
            'last_activity_at' => trim((string) ($row['last_message_at'] ?? '')),
            'channel' => 'whatsapp',
            'provider' => 'whatsapp_cloud',
        ];
    }

    public function set_conversation_lead_id($conversationId, $leadId)
    {
        $conversationId = (int) $conversationId;
        $leadId = (int) $leadId;
        if ($conversationId <= 0
            || $leadId <= 0
            || !$this->db->table_exists($this->conversationsTable)
            || !$this->db->field_exists('id', $this->conversationsTable)
            || !$this->db->field_exists('lead_id', $this->conversationsTable)) {
            return false;
        }

        $update = ['lead_id' => $leadId];
        if ($this->db->field_exists('dateupdated', $this->conversationsTable)) {
            $update['dateupdated'] = date('Y-m-d H:i:s');
        }

        $this->db->where('id', $conversationId);
        if ($this->db->field_exists('channel', $this->conversationsTable)) {
            $this->db->where('channel', 'whatsapp');
        }
        if ($this->db->field_exists('provider', $this->conversationsTable)) {
            $this->db->where('provider', 'whatsapp_cloud');
        }

        return $this->db->update($this->conversationsTable, $this->filter_existing_columns($this->conversationsTable, $update));
    }

    public function mark_whatsapp_conversation_read($conversationId)
    {
        $conversationId = (int) $conversationId;
        if ($conversationId <= 0
            || !$this->db->table_exists($this->conversationsTable)
            || !$this->db->field_exists('id', $this->conversationsTable)
            || !$this->db->field_exists('unread_count', $this->conversationsTable)) {
            return [
                'success' => false,
                'conversation_id' => $conversationId,
                'message' => 'WhatsApp conversation unread state is unavailable.',
            ];
        }

        $update = ['unread_count' => 0];
        if ($this->db->field_exists('dateupdated', $this->conversationsTable)) {
            $update['dateupdated'] = date('Y-m-d H:i:s');
        }

        $this->db->where('id', $conversationId);
        if ($this->db->field_exists('channel', $this->conversationsTable)) {
            $this->db->where('channel', 'whatsapp');
        }
        if ($this->db->field_exists('provider', $this->conversationsTable)) {
            $this->db->where('provider', 'whatsapp_cloud');
        }

        $updated = $this->db->update($this->conversationsTable, $this->filter_existing_columns($this->conversationsTable, $update));

        return [
            'success' => (bool) $updated,
            'conversation_id' => $conversationId,
            'message' => $updated ? 'WhatsApp conversation marked read.' : 'WhatsApp conversation could not be marked read.',
        ];
    }

    private function touch_conversation($conversationId, array $data = [])
    {
        if (!$this->db->table_exists($this->conversationsTable)) {
            return false;
        }

        $activityAt = isset($data['last_message_at']) ? (string) $data['last_message_at'] : date('Y-m-d H:i:s');
        $direction = trim((string) ($data['direction'] ?? ''));
        $update = [
            'last_message_at' => $activityAt,
            'dateupdated' => date('Y-m-d H:i:s'),
        ];
        if (!empty($data['subject'])) {
            $update['subject'] = trim((string) $data['subject']);
        }
        foreach ([
            'status',
            'source_type',
            'channel',
            'provider',
            'provider_thread_id',
            'internal_notes',
        ] as $column) {
            if (!empty($data[$column])) {
                $update[$column] = trim((string) $data[$column]);
            }
        }
        $normalizedPhone = $this->normalize_phone($data['normalized_phone'] ?? ($data['contact_phone'] ?? ''));
        if ($normalizedPhone !== '') {
            $update['contact_phone'] = $normalizedPhone;
            $update['normalized_phone'] = $normalizedPhone;
        }
        if (!empty($data['lead_id'])) {
            $update['lead_id'] = (int) $data['lead_id'];
        }
        if (!empty($data['assigned_staff_id'])) {
            $update['assigned_staff_id'] = (int) $data['assigned_staff_id'];
        }
        if ($direction === 'inbound') {
            $update['last_inbound_at'] = $activityAt;
        } elseif ($direction === 'outbound') {
            $update['last_outbound_at'] = $activityAt;
        } elseif (!empty($data['last_inbound_at'])) {
            $update['last_inbound_at'] = (string) $data['last_inbound_at'];
        } elseif (!empty($data['last_outbound_at'])) {
            $update['last_outbound_at'] = (string) $data['last_outbound_at'];
        }

        $update = $this->filter_existing_columns($this->conversationsTable, $update);
        $shouldIncrementUnread = $direction === 'inbound' && $this->db->field_exists('unread_count', $this->conversationsTable);
        if (empty($update) && !$shouldIncrementUnread) {
            return false;
        }

        $this->db->where('id', (int) $conversationId);
        if ($shouldIncrementUnread) {
            $this->db->set('unread_count', 'unread_count + 1', false);
        }
        $this->db->update($this->conversationsTable, $update);

        return true;
    }

    private function get_conversation_row($conversationId)
    {
        return $this->db->get_where($this->conversationsTable, ['id' => (int) $conversationId])->row_array();
    }

    private function append_whatsapp_conversation_previews(array $items)
    {
        foreach ($items as &$item) {
            $item['preview'] = $this->get_latest_inbound_message_preview((int) ($item['id'] ?? 0));
        }
        unset($item);

        return $items;
    }

    private function get_latest_inbound_message_preview($conversationId)
    {
        if ($conversationId <= 0
            || !$this->db->table_exists($this->messagesTable)
            || !$this->db->field_exists('conversation_id', $this->messagesTable)
            || !$this->db->field_exists('direction', $this->messagesTable)) {
            return '';
        }

        $select = $this->db->field_exists('body_text', $this->messagesTable) ? 'body_text' : ($this->db->field_exists('body', $this->messagesTable) ? 'body' : null);
        if ($select === null) {
            return '';
        }

        $this->db->select($select);
        $this->db->from($this->messagesTable);
        $this->db->where('conversation_id', (int) $conversationId);
        $this->db->where('direction', 'inbound');
        if ($this->db->field_exists('channel', $this->messagesTable)) {
            $this->db->where('channel', 'whatsapp');
        }
        if ($this->db->field_exists('provider', $this->messagesTable)) {
            $this->db->where('provider', 'whatsapp_cloud');
        }
        if ($this->db->field_exists('id', $this->messagesTable)) {
            $this->db->order_by('id', 'DESC');
        } elseif ($this->db->field_exists('datecreated', $this->messagesTable)) {
            $this->db->order_by('datecreated', 'DESC');
        }

        $row = $this->db->limit(1)->get()->row_array();
        $body = trim((string) ($row[$select] ?? ''));
        if ($body === '') {
            return '';
        }

        return mb_substr(preg_replace('/\s+/', ' ', $body), 0, 180);
    }

    private function filter_existing_columns($table, array $data)
    {
        foreach (array_keys($data) as $column) {
            if (!$this->db->field_exists($column, $table)) {
                unset($data[$column]);
            }
        }

        return $data;
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

    private function encode_media_meta_json($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            return trim($value) !== '' ? $value : null;
        }

        if (!is_array($value) && !is_object($value)) {
            return null;
        }

        $json = json_encode($value);

        return $json !== false ? $json : null;
    }

    private function append_previews(array $rows)
    {
        foreach ($rows as &$row) {
            $body = trim((string) ($row['body_text'] ?? $row['body'] ?? ''));
            $row['preview'] = mb_substr(preg_replace('/\s+/', ' ', $body), 0, 180);
        }
        unset($row);

        return $rows;
    }
}
