<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Marketing_public_links_model extends App_Model
{
    const TABLE = 'marketing_public_links';
    const CAMPAIGNS_TABLE = 'marketing_campaigns';
    const TYPE_CLICK = 'click';
    const TYPE_UNSUBSCRIBE = 'unsubscribe';
    const CLICK_REPORT_LIMIT = 100;
    const RETENTION_MIN_DAYS = 30;
    const RETENTION_RESET_LIMIT = 500;
    private const ACTIVE_CAMPAIGN_STATUSES = ['scheduled', 'sending'];

    protected $table;
    protected $campaignsTable;

    public function __construct()
    {
        parent::__construct();

        $this->table = db_prefix() . self::TABLE;
        $this->campaignsTable = db_prefix() . self::CAMPAIGNS_TABLE;
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

    public function get_click_aggregate_retention_diagnostics($retentionDays = null, $limit = self::RETENTION_RESET_LIMIT)
    {
        $retentionDays = $this->normalize_retention_days($retentionDays);
        $limit = max(1, min(self::RETENTION_RESET_LIMIT, (int) $limit));
        $diagnostics = [
            'cleanup_ready' => false,
            'candidate_preview' => 0,
            'candidate_more' => false,
            'warnings' => [],
        ];

        if (!$this->can_reset_click_aggregates()) {
            $diagnostics['warnings'][] = 'click_aggregate_cleanup_unavailable';

            return $diagnostics;
        }

        $cutoffDateTime = $this->build_retention_cutoff_datetime($retentionDays);
        $candidates = $this->get_click_aggregate_candidate_rows($cutoffDateTime, $limit + 1);

        $diagnostics['cleanup_ready'] = true;
        $diagnostics['candidate_preview'] = min($limit, count($candidates));
        $diagnostics['candidate_more'] = count($candidates) > $limit;

        return $diagnostics;
    }

    public function reset_stale_click_aggregates($retentionDays = null, $limit = self::RETENTION_RESET_LIMIT)
    {
        $retentionDays = $this->normalize_retention_days($retentionDays);
        $limit = max(1, min(self::RETENTION_RESET_LIMIT, (int) $limit));
        $summary = [
            'click_links_reset' => 0,
            'click_links_skipped' => 0,
            'candidate_preview' => 0,
            'candidate_more' => false,
            'warnings' => [],
        ];

        if (!$this->can_reset_click_aggregates()) {
            $summary['warnings'][] = 'click_aggregate_cleanup_unavailable';

            return $summary;
        }

        $cutoffDateTime = $this->build_retention_cutoff_datetime($retentionDays);
        $candidatePreviewRows = $this->get_click_aggregate_candidate_rows($cutoffDateTime, $limit + 1);
        $summary['candidate_preview'] = min($limit, count($candidatePreviewRows));
        $summary['candidate_more'] = count($candidatePreviewRows) > $limit;

        $candidateRows = array_slice($candidatePreviewRows, 0, $limit);
        if (empty($candidateRows)) {
            return $summary;
        }

        $update = $this->filter_existing_columns([
            'click_count' => 0,
            'human_click_count' => 0,
            'unique_click_count' => 0,
            'last_clicked_at' => null,
            'last_human_clicked_at' => null,
            'dateupdated' => date('Y-m-d H:i:s'),
        ]);

        if (empty($update)) {
            $summary['click_links_skipped'] = count($candidateRows);
            $summary['warnings'][] = 'click_aggregate_cleanup_unavailable';

            return $summary;
        }

        foreach ($candidateRows as $row) {
            if (!$this->row_has_click_aggregate_activity($row)) {
                $summary['click_links_skipped']++;
                continue;
            }

            $this->db->where('id', (int) ($row['id'] ?? 0));
            $this->db->where('token_type', self::TYPE_CLICK);
            $this->db->update($this->table, $update);

            if ($this->db->affected_rows() >= 0) {
                $summary['click_links_reset']++;
            } else {
                $summary['click_links_skipped']++;
            }
        }

        return $summary;
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

    protected function can_reset_click_aggregates()
    {
        return $this->table_ready()
            && $this->db->field_exists('id', $this->table)
            && $this->db->field_exists('token_type', $this->table)
            && (
                $this->db->field_exists('dateupdated', $this->table)
                || $this->db->field_exists('last_used_at', $this->table)
                || $this->db->field_exists('datecreated', $this->table)
            );
    }

    protected function normalize_retention_days($retentionDays)
    {
        $retentionDays = (int) $retentionDays;
        if ($retentionDays <= 0) {
            $retentionDays = self::RETENTION_MIN_DAYS;
        }

        return max(self::RETENTION_MIN_DAYS, $retentionDays);
    }

    protected function build_retention_cutoff_datetime($retentionDays)
    {
        $retentionDays = $this->normalize_retention_days($retentionDays);

        return date('Y-m-d H:i:s', time() - ($retentionDays * 86400));
    }

    protected function get_click_aggregate_candidate_rows($cutoffDateTime, $limit)
    {
        if (!$this->can_reset_click_aggregates()) {
            return [];
        }

        $select = ['pl.id'];
        foreach ([
            'click_count',
            'human_click_count',
            'unique_click_count',
            'last_clicked_at',
            'last_human_clicked_at',
            'last_used_at',
            'datecreated',
            'dateupdated',
            'campaign_id',
        ] as $column) {
            if ($this->db->field_exists($column, $this->table)) {
                $select[] = 'pl.' . $column;
            }
        }

        if (
            $this->db->table_exists($this->campaignsTable)
            && $this->db->field_exists('campaign_id', $this->table)
            && $this->db->field_exists('id', $this->campaignsTable)
            && $this->db->field_exists('status', $this->campaignsTable)
        ) {
            $select[] = 'c.status AS campaign_status';
            $this->db->join($this->campaignsTable . ' c', 'c.id = pl.campaign_id', 'left');
        } else {
            $select[] = 'NULL AS campaign_status';
        }

        $this->db->select(implode(', ', $select), false)
            ->from($this->table . ' pl')
            ->where('pl.token_type', self::TYPE_CLICK);

        if ($this->db->field_exists('is_active', $this->table)) {
            $this->db->where('pl.is_active', 1);
        }

        if (
            $this->db->table_exists($this->campaignsTable)
            && $this->db->field_exists('campaign_id', $this->table)
            && $this->db->field_exists('id', $this->campaignsTable)
            && $this->db->field_exists('status', $this->campaignsTable)
        ) {
            $this->db->group_start();
            $this->db->where('c.status IS NULL', null, false);
            $this->db->or_where_not_in('c.status', self::ACTIVE_CAMPAIGN_STATUSES);
            $this->db->group_end();
        }

        if ($this->db->field_exists('last_human_clicked_at', $this->table)) {
            $this->db->group_start();
            $this->db->where('pl.last_human_clicked_at <=', $cutoffDateTime);
            $this->db->or_where('pl.last_human_clicked_at IS NULL', null, false);
            $this->db->group_end();
        }

        if ($this->db->field_exists('last_clicked_at', $this->table)) {
            $this->db->group_start();
            $this->db->where('pl.last_clicked_at <=', $cutoffDateTime);
            $this->db->or_where('pl.last_clicked_at IS NULL', null, false);
            $this->db->group_end();
        }

        if ($this->db->field_exists('last_used_at', $this->table)) {
            $this->db->group_start();
            $this->db->where('pl.last_used_at <=', $cutoffDateTime);
            $this->db->or_where('pl.last_used_at IS NULL', null, false);
            $this->db->group_end();
        } elseif ($this->db->field_exists('dateupdated', $this->table)) {
            // Fall back to generic row age only when click/use timestamps are unavailable.
            $this->db->group_start();
            $this->db->where('pl.dateupdated <=', $cutoffDateTime);
            $this->db->or_where('pl.dateupdated IS NULL', null, false);
            $this->db->group_end();
        }

        if ($this->db->field_exists('datecreated', $this->table)) {
            $this->db->where('pl.datecreated <=', $cutoffDateTime);
        }

        $this->db->group_start();
        $hasActivityFilter = false;
        foreach (['click_count', 'human_click_count', 'unique_click_count'] as $counterColumn) {
            if ($this->db->field_exists($counterColumn, $this->table)) {
                if ($hasActivityFilter) {
                    $this->db->or_where('pl.' . $counterColumn . ' >', 0, false);
                } else {
                    $this->db->where('pl.' . $counterColumn . ' >', 0, false);
                    $hasActivityFilter = true;
                }
            }
        }
        foreach (['last_clicked_at', 'last_human_clicked_at'] as $dateColumn) {
            if ($this->db->field_exists($dateColumn, $this->table)) {
                if ($hasActivityFilter) {
                    $this->db->or_where('pl.' . $dateColumn . ' IS NOT NULL', null, false);
                } else {
                    $this->db->where('pl.' . $dateColumn . ' IS NOT NULL', null, false);
                    $hasActivityFilter = true;
                }
            }
        }
        if (!$hasActivityFilter) {
            $this->db->where('1 = 0', null, false);
        }
        $this->db->group_end();

        if ($this->db->field_exists('last_used_at', $this->table)) {
            $this->db->order_by('pl.last_used_at', 'ASC');
        } elseif ($this->db->field_exists('dateupdated', $this->table)) {
            $this->db->order_by('pl.dateupdated', 'ASC');
        } else {
            $this->db->order_by('pl.id', 'ASC');
        }

        return $this->db->limit(max(1, (int) $limit))->get()->result_array();
    }

    protected function row_has_click_aggregate_activity(array $row)
    {
        foreach (['click_count', 'human_click_count', 'unique_click_count'] as $counterColumn) {
            if ((int) ($row[$counterColumn] ?? 0) > 0) {
                return true;
            }
        }

        foreach (['last_clicked_at', 'last_human_clicked_at'] as $dateColumn) {
            if (!empty($row[$dateColumn])) {
                return true;
            }
        }

        return false;
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
