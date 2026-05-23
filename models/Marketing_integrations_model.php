<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Marketing_integrations_model extends App_Model
{
    const TABLE = 'marketing_channel_integrations';
    const ACTIVE_SOCIAL_CHANNELS = ['whatsapp_business', 'whatsapp'];

    protected $table;

    public function __construct()
    {
        parent::__construct();

        $this->table = db_prefix() . self::TABLE;
    }

    public function count_all($activeOnly = true)
    {
        if (!$this->db->table_exists($this->table)) {
            return 0;
        }

        if (!$activeOnly) {
            return (int) $this->db->count_all($this->table);
        }

        $this->db->from($this->table);
        $this->db->where_in('channel', self::ACTIVE_SOCIAL_CHANNELS);

        return (int) $this->db->count_all_results();
    }

    public function exists($id)
    {
        return total_rows($this->table, ['id' => $id]) > 0;
    }

    public function get_all($activeOnly = true)
    {
        if (!$this->db->table_exists($this->table)) {
            return [];
        }

        if ($activeOnly) {
            $this->db->where_in('channel', self::ACTIVE_SOCIAL_CHANNELS);
        }

        $this->db->order_by('channel', 'ASC');

        return $this->db->get($this->table)->result_array();
    }

    public function get_active_social_integrations()
    {
        return $this->get_all(true);
    }
}
