<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Marketing_suppressions_model extends App_Model
{
    const TABLE = 'marketing_suppressions';
    private const ALLOWED_REASONS = ['bounce', 'complaint', 'unsubscribe'];

    protected $table;

    public function __construct()
    {
        parent::__construct();

        $this->table = db_prefix() . self::TABLE;
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

    public function count_active()
    {
        if (!$this->db->table_exists($this->table) || !$this->db->field_exists('active', $this->table)) {
            return 0;
        }

        return total_rows($this->table, ['active' => 1]);
    }

    public function upsert_suppression($email, $reason, $providerMessageId = null, $source = null)
    {
        if (!$this->db->table_exists($this->table) || !$this->db->field_exists('normalized_email', $this->table) || !$this->db->field_exists('reason', $this->table) || !$this->db->field_exists('active', $this->table)) {
            return false;
        }

        $normalizedEmail = $this->normalize_email($email);
        $reason = $this->normalize_reason($reason);
        $storedEmail = $this->sanitize_email_for_storage($email);

        if ($normalizedEmail === '' || $reason === null) {
            return false;
        }

        $existingRows = $this->db->select($this->db->field_exists('id', $this->table) ? 'id' : 'normalized_email')
            ->where('normalized_email', $normalizedEmail)
            ->where('reason', $reason)
            ->where('active', 1)
            ->get($this->table)
            ->result_array();

        $data = [
            'email' => $storedEmail,
            'normalized_email' => $normalizedEmail,
            'reason' => $reason,
            'source' => $source ?: ($reason === 'unsubscribe' ? 'marketing_unsubscribe' : 'ses_sns'),
            'provider_message_id' => $providerMessageId,
            'active' => 1,
        ];
        if ($this->db->field_exists('dateupdated', $this->table)) {
            $data['dateupdated'] = date('Y-m-d H:i:s');
        }
        $data = $this->filter_existing_columns($data);
        if (
            empty($data)
            || !array_key_exists('normalized_email', $data)
            || !array_key_exists('reason', $data)
            || !array_key_exists('active', $data)
        ) {
            return false;
        }

        if (!empty($existingRows)) {
            $existingIds = array_values(array_filter(array_map('intval', array_column($existingRows, 'id'))));
            if (!empty($existingIds) && $this->db->field_exists('id', $this->table)) {
                $this->db->where_in('id', $existingIds);
            } else {
                $this->db->where('normalized_email', $normalizedEmail);
                $this->db->where('reason', $reason);
                $this->db->where('active', 1);
            }
            $this->db->update($this->table, $data);

            return !empty($existingIds) ? $existingIds[0] : true;
        }

        if ($this->db->field_exists('datecreated', $this->table)) {
            $data['datecreated'] = date('Y-m-d H:i:s');
        }
        $data = $this->filter_existing_columns($data);
        $this->db->insert($this->table, $data);

        return $this->db->insert_id();
    }

    public function get_active_suppression($email)
    {
        if (!$this->db->table_exists($this->table) || !$this->db->field_exists('normalized_email', $this->table) || !$this->db->field_exists('active', $this->table)) {
            return null;
        }

        $normalizedEmail = $this->normalize_email($email);
        if ($normalizedEmail === '') {
            return null;
        }

        if ($this->db->field_exists('id', $this->table)) {
            $this->db->order_by('id', 'DESC');
        }

        $row = $this->db->where('normalized_email', $normalizedEmail)
            ->where('active', 1)
            ->get($this->table)
            ->row_array();

        return is_array($row) ? $row : null;
    }

    public function is_suppressed($email, $reason = null)
    {
        if (!$this->db->table_exists($this->table) || !$this->db->field_exists('normalized_email', $this->table) || !$this->db->field_exists('active', $this->table)) {
            return false;
        }

        $normalizedEmail = $this->normalize_email($email);
        if ($normalizedEmail === '') {
            return false;
        }

        $this->db->from($this->table);
        $this->db->where('normalized_email', $normalizedEmail);
        $this->db->where('active', 1);

        if ($reason !== null) {
            $normalizedReason = $this->normalize_reason($reason);
            if ($normalizedReason === null) {
                return false;
            }

            $this->db->where('reason', $normalizedReason);
        }

        return (int) $this->db->count_all_results() > 0;
    }

    public function get_active_suppression_map(array $emails)
    {
        if (!$this->db->table_exists($this->table) || !$this->db->field_exists('normalized_email', $this->table) || !$this->db->field_exists('active', $this->table)) {
            return [];
        }

        $normalizedEmails = [];
        foreach ($emails as $email) {
            $normalizedEmail = $this->normalize_email($email);
            if ($normalizedEmail !== '') {
                $normalizedEmails[$normalizedEmail] = $normalizedEmail;
            }
        }

        if (empty($normalizedEmails)) {
            return [];
        }

        $select = ['normalized_email'];
        foreach (['reason', 'source', 'active'] as $column) {
            $select[] = $this->db->field_exists($column, $this->table) ? $column : ('NULL AS ' . $column);
        }

        if ($this->db->field_exists('id', $this->table)) {
            $this->db->order_by('id', 'DESC');
        }

        $rows = $this->db->select(implode(', ', $select), false)
            ->from($this->table)
            ->where('active', 1)
            ->where_in('normalized_email', array_values($normalizedEmails))
            ->get()
            ->result_array();

        $map = [];
        foreach ($rows as $row) {
            $normalizedEmail = (string) ($row['normalized_email'] ?? '');
            if ($normalizedEmail === '') {
                continue;
            }

            if (!isset($map[$normalizedEmail])) {
                $map[$normalizedEmail] = [
                    'normalized_email' => $normalizedEmail,
                    'reasons' => [],
                ];
            }

            $reason = (string) ($row['reason'] ?? '');
            if ($reason !== '' && !in_array($reason, $map[$normalizedEmail]['reasons'], true)) {
                $map[$normalizedEmail]['reasons'][] = $reason;
            }
        }

        return $map;
    }

    public function normalize_email($email)
    {
        return mb_strtolower(trim((string) $email));
    }

    protected function normalize_reason($reason)
    {
        $reason = mb_strtolower(trim((string) $reason));

        return in_array($reason, self::ALLOWED_REASONS, true) ? $reason : null;
    }

    protected function sanitize_email_for_storage($email)
    {
        return trim((string) $email);
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
