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
        $this->load->model('marketing/Marketing_campaigns_model', 'marketing_campaigns_model');
    }

    public function index()
    {
        $data = $this->marketing_dashboard_model->get_dashboard_data();
        $data['title'] = _l('marketing_dashboard');
        $data['can_manage_queue'] = staff_can('settings', 'marketing');

        $this->load->view('marketing/dashboard', $data);
    }

    public function queue_control()
    {
        if (staff_cant('settings', 'marketing')) {
            access_denied('marketing');
        }

        if (!$this->input->post()) {
            show_404();
        }

        $action = trim((string) $this->input->post('queue_action', true));
        $staffId = get_staff_user_id();

        if ($action === 'pause') {
            $saved = $this->marketing_campaigns_model->set_queue_paused(true, $staffId);
            set_alert($saved ? 'success' : 'warning', $saved ? 'Campaign queue paused.' : 'Campaign queue pause could not be saved.');
        } elseif ($action === 'resume') {
            $saved = $this->marketing_campaigns_model->set_queue_paused(false, $staffId);
            set_alert($saved ? 'success' : 'warning', $saved ? 'Campaign queue resumed.' : 'Campaign queue resume could not be saved.');
        } elseif ($action === 'recover_stale_worker_leases') {
            $result = $this->marketing_campaigns_model->recover_stale_campaign_worker_leases(null, $staffId);
            $recovered = (int) ($result['recovered'] ?? 0);
            set_alert($recovered > 0 ? 'success' : 'info', $recovered > 0 ? ('Recovered ' . $recovered . ' stale campaign worker lease(s).') : 'No stale campaign worker leases were eligible for recovery.');
        } elseif ($action === 'recover_stale_processing') {
            $result = $this->marketing_campaigns_model->recover_stale_recipient_processing(null, $staffId);
            $recovered = (int) ($result['recovered'] ?? 0);
            set_alert($recovered > 0 ? 'success' : 'info', $recovered > 0 ? ('Recovered ' . $recovered . ' stale processing recipient row(s).') : 'No stale recipient processing rows were eligible for recovery.');
        } else {
            set_alert('warning', 'Unknown queue control action.');
        }

        redirect(admin_url('marketing'));
    }
}
