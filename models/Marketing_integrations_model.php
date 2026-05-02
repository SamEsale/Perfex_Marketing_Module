<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Marketing_integrations_model extends App_Model
{
    const TABLE = 'marketing_channel_integrations';

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

    public function get_all()
    {
        $this->db->order_by('channel', 'ASC');

        return $this->db->get($this->table)->result_array();
    }
}
