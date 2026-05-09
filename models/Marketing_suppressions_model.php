<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Marketing_suppressions_model extends App_Model
{
    const TABLE = 'marketing_suppressions';

    protected $table;

    public function __construct()
    {
        parent::__construct();

        $this->table = db_prefix() . self::TABLE;
    }

    public function count_all()
    {
        return $this->db->count_all($this->table);
    }

    public function exists($id)
    {
        return total_rows($this->table, ['id' => $id]) > 0;
    }

    public function count_active()
    {
        return total_rows($this->table, ['active' => 1]);
    }

    public function upsert_suppression($email, $reason, $providerMessageId = null, $source = null)
    {
        $normalizedEmail = $this->normalize_email($email);

        if ($normalizedEmail === '' || !in_array($reason, ['bounce', 'complaint', 'unsubscribe'], true)) {
            return false;
        }

        $existing = $this->db->where('normalized_email', $normalizedEmail)
            ->where('reason', $reason)
            ->where('active', 1)
            ->get($this->table)
            ->row_array();

        $data = [
            'email'               => $email,
            'normalized_email'    => $normalizedEmail,
            'reason'              => $reason,
            'source'              => $source ?: ($reason === 'unsubscribe' ? 'marketing_unsubscribe' : 'ses_sns'),
            'provider_message_id' => $providerMessageId,
            'active'              => 1,
            'datecreated'         => date('Y-m-d H:i:s'),
        ];

        if ($existing) {
            $this->db->where('id', $existing['id']);
            $this->db->update($this->table, $data);

            return $existing['id'];
        }

        $data['datecreated'] = date('Y-m-d H:i:s');
        $this->db->insert($this->table, $data);

        return $this->db->insert_id();
    }

    protected function normalize_email($email)
    {
        return trim(strtolower((string) $email));
    }
}
