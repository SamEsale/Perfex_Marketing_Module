<?php defined('BASEPATH') OR exit('No direct script access allowed');

class Migration_Initial_schema
{
    protected $ci;

    public function __construct()
    {
        $this->ci = &get_instance();
        $this->ci->load->database();
    }

    public function up()
    {
        // SAFE MODE: do nothing to avoid crashes on shared hosting
        log_message('info', 'Marketing module migration skipped (safe mode)');
    }

    public function down()
    {
        // no-op
    }
}
