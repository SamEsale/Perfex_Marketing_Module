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
        // Intentional no-op.
        // This module uses Marketing_schema safe bootstrap and repair instead of CI_Migration
        // so activation and upgrades stay non-destructive and isolated from Perfex core.
        log_message('info', 'Marketing module migration skipped (safe mode)');

        return true;
    }

    public function down()
    {
        // Intentional no-op. Uninstall must not drop buyer data automatically.
        return true;
    }
}
