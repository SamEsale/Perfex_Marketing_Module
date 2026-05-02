<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Marketing_conversations_model extends App_Model
{
    const CONVERSATIONS_TABLE = 'marketing_conversations';
    const MESSAGES_TABLE = 'marketing_messages';

    protected $conversationsTable;
    protected $messagesTable;

    public function __construct()
    {
        parent::__construct();

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

    public function find_conversation($campaignId = null, $contactId = null, $email = null)
    {
        if (!$this->db->table_exists($this->conversationsTable)) {
            return null;
        }

        $this->db->from($this->conversationsTable);

        if ($campaignId !== null && $this->db->field_exists('campaign_id', $this->conversationsTable)) {
            $this->db->where('campaign_id', (int) $campaignId);
        }

        if ($contactId !== null && $this->db->field_exists('marketing_contact_id', $this->conversationsTable)) {
            $this->db->where('marketing_contact_id', (int) $contactId);
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
        $existing = $this->find_conversation($campaignId, $contactId ?: null, $email);
        if ($existing) {
            $this->touch_conversation((int) $existing['id'], $data);

            return $existing;
        }

        $insert = [
            'campaign_id' => $campaignId,
            'marketing_contact_id' => $contactId ?: null,
            'email' => $email !== '' ? mb_strtolower($email) : null,
            'contact_email' => $email !== '' ? mb_strtolower($email) : null,
            'subject' => isset($data['subject']) ? trim((string) $data['subject']) : null,
            'last_message_at' => isset($data['last_message_at']) ? (string) $data['last_message_at'] : date('Y-m-d H:i:s'),
            'status' => isset($data['status']) ? trim((string) $data['status']) : 'open',
            'source_type' => isset($data['source_type']) ? trim((string) $data['source_type']) : 'email_reply',
            'datecreated' => date('Y-m-d H:i:s'),
        ];

        $insert = $this->filter_existing_columns($this->conversationsTable, $insert);
        $this->db->insert($this->conversationsTable, $insert);
        $conversationId = (int) $this->db->insert_id();

        if ($conversationId <= 0) {
            return null;
        }

        return $this->db->get_where($this->conversationsTable, ['id' => $conversationId])->row_array();
    }

    public function add_message(array $data)
    {
        if (!$this->db->table_exists($this->messagesTable)) {
            return 0;
        }

        $insert = [
            'conversation_id' => isset($data['conversation_id']) ? (int) $data['conversation_id'] : 0,
            'campaign_id' => isset($data['campaign_id']) ? (int) $data['campaign_id'] : null,
            'marketing_contact_id' => isset($data['marketing_contact_id']) ? (int) $data['marketing_contact_id'] : null,
            'direction' => isset($data['direction']) ? trim((string) $data['direction']) : 'inbound',
            'email' => isset($data['email']) ? mb_strtolower(trim((string) $data['email'])) : null,
            'from_email' => isset($data['email']) ? mb_strtolower(trim((string) $data['email'])) : null,
            'subject' => isset($data['subject']) ? trim((string) $data['subject']) : null,
            'message' => isset($data['message']) ? (string) $data['message'] : null,
            'body' => isset($data['message']) ? (string) $data['message'] : null,
            'body_text' => isset($data['body_text']) ? (string) $data['body_text'] : (isset($data['message']) ? (string) $data['message'] : null),
            'body_html' => isset($data['body_html']) ? (string) $data['body_html'] : null,
            'provider_message_id' => isset($data['provider_message_id']) ? trim((string) $data['provider_message_id']) : null,
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
                'last_message_at' => $insert['datecreated'] ?? date('Y-m-d H:i:s'),
                'subject' => $insert['subject'] ?? null,
            ]);
        }

        return $messageId;
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

    private function touch_conversation($conversationId, array $data = [])
    {
        if (!$this->db->table_exists($this->conversationsTable)) {
            return false;
        }

        $update = [
            'last_message_at' => isset($data['last_message_at']) ? (string) $data['last_message_at'] : date('Y-m-d H:i:s'),
        ];
        if (!empty($data['subject'])) {
            $update['subject'] = trim((string) $data['subject']);
        }

        $update = $this->filter_existing_columns($this->conversationsTable, $update);
        if (empty($update)) {
            return false;
        }

        $this->db->where('id', (int) $conversationId);
        $this->db->update($this->conversationsTable, $update);

        return true;
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
