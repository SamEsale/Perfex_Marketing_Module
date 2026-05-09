<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Marketing extends AdminController
{
    public function __construct()
    {
        parent::__construct();

        if (!marketing_staff_can_access_module()) {
            access_denied('marketing');
        }

        $this->load->model('marketing/Marketing_dashboard_model', 'marketing_dashboard_model');
    }

    public function index()
    {
        $data = $this->marketing_dashboard_model->get_dashboard_data();
        $data['title'] = _l('marketing_dashboard');

        $this->load->view('marketing/dashboard', $data);
    }
}
