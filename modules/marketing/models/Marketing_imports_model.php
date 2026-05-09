<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Marketing_imports_model extends App_Model
{
    const TABLE = 'marketing_imports';

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

    public function get_recent($limit = 10)
    {
        $this->db->order_by('id', 'DESC');

        return $this->db->get($this->table, $limit)->result_array();
    }

    public function create_import($data)
    {
        $this->db->insert($this->table, $data);

        return $this->db->insert_id();
    }

    public function update_import($id, $data)
    {
        $this->db->where('id', $id);
        $this->db->update($this->table, $data);

        return $this->db->affected_rows() !== 0;
    }

    public function get_import($id)
    {
        return $this->db->get_where($this->table, ['id' => $id])->row_array();
    }

    public function delete_import($id)
    {
        $this->db->where('id', $id);
        $this->db->delete($this->table);

        return $this->db->affected_rows() > 0;
    }
}
