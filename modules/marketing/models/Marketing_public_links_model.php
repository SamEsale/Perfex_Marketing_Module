<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Marketing_public_links_model extends App_Model
{
    const TABLE = 'marketing_public_links';
    const TYPE_CLICK = 'click';
    const TYPE_UNSUBSCRIBE = 'unsubscribe';
    const CLICK_REPORT_LIMIT = 100;

    protected $table;

    public function __construct()
    {
        parent::__construct();

        $this->table = db_prefix() . self::TABLE;
    }

    public function issue_click_link(array $campaign, array $recipient, $originalUrl)
    {
        $normalizedUrl = $this->normalize_trackable_url($originalUrl);
        if ($normalizedUrl === null || !$this->table_ready()) {
            return null;
        }

        return $this->create_public_link(self::TYPE_CLICK, [
            'campaign_id' => (int) ($campaign['id'] ?? 0),
            'campaign_recipient_id' => (int) ($recipient['id'] ?? 0),
            'marketing_contact_id' => (int) ($recipient['marketing_contact_id'] ?? 0),
            'normalized_email' => $this->normalize_email($recipient['email'] ?? ''),
            'original_url' => $normalizedUrl,
            'payload_json' => json_encode([
                'delivery_channel' => (string) ($campaign['delivery_channel'] ?? 'email'),
                'source_type' => (string) ($campaign['source_type'] ?? 'smtp'),
            ]),
        ]);
    }

    public function issue_unsubscribe_link(array $recipient)
    {
        if (!$this->table_ready()) {
            return null;
        }

        return $this->create_public_link(self::TYPE_UNSUBSCRIBE, [
            'campaign_id' => (int) ($recipient['campaign_id'] ?? 0),
            'campaign_recipient_id' => (int) ($recipient['id'] ?? 0),
            'marketing_contact_id' => (int) ($recipient['marketing_contact_id'] ?? 0),
            'normalized_email' => $this->normalize_email($recipient['email'] ?? ''),
            'payload_json' => json_encode([
                'channel' => (string) ($recipient['channel'] ?? 'email'),
            ]),
        ]);
    }

    public function resolve_click_token($token)
    {
        $row = $this->resolve_token_row($token, self::TYPE_CLICK);
        if (!$row) {
            return null;
        }

        $normalizedUrl = $this->normalize_trackable_url($row['original_url'] ?? null);
        if ($normalizedUrl === null) {
            return null;
        }

        $row['original_url'] = $normalizedUrl;

        return $row;
    }

    public function resolve_unsubscribe_token($token)
    {
        return $this->resolve_token_row($token, self::TYPE_UNSUBSCRIBE);
    }

    public function record_click_activity($linkId, array $activity = [])
    {
        if (!$this->table_ready()) {
            return false;
        }

        $this->ensure_click_metric_columns();

        $now = date('Y-m-d H:i:s');
        $incrementTotal = !array_key_exists('increment_total', $activity) || !empty($activity['increment_total']);
        $isHuman = !empty($activity['is_human']);
        $isUniqueHumanClick = !empty($activity['is_unique_human_click']);
        $update = [
            'last_clicked_at' => $now,
            'last_used_at' => $now,
            'dateupdated' => $now,
        ];

        if ($isHuman && $this->db->field_exists('last_human_clicked_at', $this->table)) {
            $update['last_human_clicked_at'] = $now;
        }

        $update = $this->filter_existing_columns($update);
        $hasCounterUpdate = false;

        if ($incrementTotal && $this->db->field_exists('click_count', $this->table)) {
            $this->db->set('click_count', 'click_count + 1', false);
            $hasCounterUpdate = true;
        }
        if ($isHuman && $this->db->field_exists('human_click_count', $this->table)) {
            $this->db->set('human_click_count', 'human_click_count + 1', false);
            $hasCounterUpdate = true;
        }
        if ($isUniqueHumanClick && $this->db->field_exists('unique_click_count', $this->table)) {
            $this->db->set('unique_click_count', 'unique_click_count + 1', false);
            $hasCounterUpdate = true;
        }

        if (empty($update) && !$hasCounterUpdate) {
            return false;
        }

        $this->db->where('id', (int) $linkId);
        $this->db->where('token_type', self::TYPE_CLICK);
        if (!empty($update)) {
            $this->db->update($this->table, $update);
        } else {
            $this->db->update($this->table);
        }

        return $this->db->affected_rows() >= 0;
    }

    public function register_click($linkId)
    {
        return $this->record_click_activity($linkId, [
            'increment_total' => true,
            'is_human' => true,
            'is_unique_human_click' => true,
        ]);
    }

    public function touch_token_use($linkId)
    {
        if (!$this->table_ready()) {
            return false;
        }

        $update = $this->filter_existing_columns([
            'last_used_at' => date('Y-m-d H:i:s'),
            'dateupdated' => date('Y-m-d H:i:s'),
        ]);

        if (empty($update)) {
            return false;
        }

        $this->db->where('id', (int) $linkId);
        $this->db->update($this->table, $update);

        return $this->db->affected_rows() >= 0;
    }

    public function normalize_trackable_url($url)
    {
        $url = html_entity_decode(trim((string) $url), ENT_QUOTES, 'UTF-8');
        if ($url === '') {
            return null;
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = trim((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return null;
        }

        return $url;
    }

    public function get_click_links_for_campaign($campaignId, $limit = self::CLICK_REPORT_LIMIT, $recipientId = null)
    {
        if (!$this->table_ready()) {
            return [];
        }

        $limit = max(1, (int) $limit);
        $campaignId = (int) $campaignId;
        $recipientId = $recipientId !== null ? (int) $recipientId : null;
        if ($campaignId <= 0) {
            return [];
        }

        $this->db->from($this->table)
            ->where('token_type', self::TYPE_CLICK)
            ->where('campaign_id', $campaignId);

        if ($recipientId !== null && $recipientId > 0) {
            $this->db->where('campaign_recipient_id', $recipientId);
        }

        return $this->db->order_by('click_count', 'DESC')
            ->order_by('last_clicked_at', 'DESC')
            ->limit($limit)
            ->get()
            ->result_array();
    }

    public function get_click_link_summary_for_campaign($campaignId)
    {
        if (!$this->table_ready()) {
            return [
                'links' => 0,
                'total_clicks' => 0,
                'human_clicks' => 0,
                'unique_clicks' => 0,
            ];
        }

        $campaignId = (int) $campaignId;
        if ($campaignId <= 0) {
            return [
                'links' => 0,
                'total_clicks' => 0,
                'human_clicks' => 0,
                'unique_clicks' => 0,
            ];
        }

        $select = [
            'COUNT(*) AS links',
            'COALESCE(SUM(click_count), 0) AS total_clicks',
        ];
        if ($this->db->field_exists('human_click_count', $this->table)) {
            $select[] = 'COALESCE(SUM(human_click_count), 0) AS human_clicks';
        } else {
            $select[] = '0 AS human_clicks';
        }
        if ($this->db->field_exists('unique_click_count', $this->table)) {
            $select[] = 'COALESCE(SUM(unique_click_count), 0) AS unique_clicks';
        } else {
            $select[] = '0 AS unique_clicks';
        }

        $row = $this->db->select(implode(', ', $select), false)
            ->from($this->table)
            ->where('token_type', self::TYPE_CLICK)
            ->where('campaign_id', $campaignId)
            ->get()
            ->row_array();

        return [
            'links' => (int) ($row['links'] ?? 0),
            'total_clicks' => (int) ($row['total_clicks'] ?? 0),
            'human_clicks' => (int) ($row['human_clicks'] ?? 0),
            'unique_clicks' => (int) ($row['unique_clicks'] ?? 0),
        ];
    }

    protected function create_public_link($tokenType, array $data)
    {
        $token = $this->generate_public_token();
        if ($token === null) {
            return null;
        }

        $now = date('Y-m-d H:i:s');
        $insert = [
            'token_hash' => $this->hash_token($token),
            'token_type' => $tokenType,
            'campaign_id' => !empty($data['campaign_id']) ? (int) $data['campaign_id'] : null,
            'campaign_recipient_id' => !empty($data['campaign_recipient_id']) ? (int) $data['campaign_recipient_id'] : null,
            'marketing_contact_id' => !empty($data['marketing_contact_id']) ? (int) $data['marketing_contact_id'] : null,
            'normalized_email' => $data['normalized_email'] !== '' ? (string) $data['normalized_email'] : null,
            'original_url' => $data['original_url'] ?? null,
            'click_count' => 0,
            'last_clicked_at' => null,
            'last_used_at' => null,
            'is_active' => 1,
            'payload_json' => $data['payload_json'] ?? null,
            'datecreated' => $now,
            'dateupdated' => $now,
        ];

        $insert = $this->filter_existing_columns($insert);
        $this->db->insert($this->table, $insert);

        if (!$this->db->affected_rows()) {
            return null;
        }

        $id = (int) $this->db->insert_id();
        $path = $tokenType === self::TYPE_CLICK
            ? 'marketing/track/c/'
            : 'marketing/unsubscribe/';

        return [
            'id' => $id,
            'token' => $token,
            'token_hash' => $insert['token_hash'],
            'url' => site_url($path . rawurlencode($token)),
        ];
    }

    protected function resolve_token_row($token, $tokenType)
    {
        if (!$this->table_ready()) {
            return null;
        }

        $token = $this->normalize_public_token($token);
        if ($token === null) {
            return null;
        }

        $row = $this->db->where('token_hash', $this->hash_token($token))
            ->where('token_type', (string) $tokenType)
            ->where('is_active', 1)
            ->limit(1)
            ->get($this->table)
            ->row_array();

        return is_array($row) ? $row : null;
    }

    protected function normalize_public_token($token)
    {
        $token = trim((string) $token);
        if ($token === '' || !preg_match('/^[A-Za-z0-9\-_]{20,255}$/', $token)) {
            return null;
        }

        return $token;
    }

    protected function generate_public_token()
    {
        try {
            return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        } catch (Throwable $exception) {
            return null;
        }
    }

    protected function hash_token($token)
    {
        return hash('sha256', (string) $token);
    }

    protected function normalize_email($email)
    {
        return mb_strtolower(trim((string) $email));
    }

    protected function ensure_click_metric_columns()
    {
        if (!$this->table_ready()) {
            return;
        }

        $requiredColumns = [
            'human_click_count',
            'unique_click_count',
            'last_human_clicked_at',
        ];

        foreach ($requiredColumns as $column) {
            if ($this->db->field_exists($column, $this->table)) {
                continue;
            }

            if (function_exists('marketing_bootstrap_safe_schema')) {
                marketing_bootstrap_safe_schema(true);
            }

            break;
        }
    }

    protected function table_ready()
    {
        return $this->db->table_exists($this->table);
    }

    protected function filter_existing_columns(array $data)
    {
        $columns = $this->db->list_fields($this->table);
        if (!is_array($columns) || empty($columns)) {
            return [];
        }

        return array_intersect_key($data, array_flip($columns));
    }
}
