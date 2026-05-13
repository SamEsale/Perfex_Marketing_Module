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
        $this->load->model('marketing/Marketing_integrity_model', 'marketing_integrity_model');
    }

    public function index()
    {
        $data = $this->get_default_dashboard_data();
        try {
            $dashboardData = $this->marketing_dashboard_model->get_dashboard_data();
            if (is_array($dashboardData)) {
                $data = array_replace_recursive($data, $dashboardData);
            }
        } catch (Throwable $exception) {
            log_message('error', 'Marketing dashboard data unavailable: ' . marketing_sanitize_log_message($exception->getMessage()));
        }

        try {
            $integrityDiagnostics = $this->marketing_integrity_model->get_integrity_diagnostics(3);
            if (is_array($integrityDiagnostics)) {
                $data['integrity_diagnostics'] = array_replace_recursive($data['integrity_diagnostics'] ?? [], $integrityDiagnostics);
            }
        } catch (Throwable $exception) {
            log_message('error', 'Marketing integrity diagnostics unavailable: ' . marketing_sanitize_log_message($exception->getMessage()));
        }

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

    protected function get_default_dashboard_data()
    {
        return [
            'total_campaigns' => 0,
            'total_lists' => 0,
            'total_contacts' => 0,
            'total_memberships' => 0,
            'active_suppressions' => 0,
            'total_recipients' => 0,
            'sent_recipients' => 0,
            'delivered_events' => 0,
            'opened_events' => 0,
            'bounced_events' => 0,
            'spam_complaint_events' => 0,
            'delivery_rate' => 0,
            'open_rate' => 0,
            'bounce_rate' => 0,
            'click_analytics' => [
                'human_clicks' => 0,
                'unique_human_clicks' => 0,
                'raw_click_events' => 0,
                'tracked_link_human_clicks' => 0,
                'tracked_link_unique_human_clicks' => 0,
                'total_clicks' => 0,
                'unique_clicked_recipients' => 0,
                'top_clicked_links' => [],
                'clicks_by_campaign' => [],
                'chart_mode' => 'empty',
                'chart_labels' => [],
                'chart_values' => [],
                'has_data' => false,
            ],
            'queue_diagnostics' => [
                'schema_ready' => false,
                'queue_paused' => false,
                'schema_missing' => [],
                'rate_limit_snapshot' => [],
                'recent_warning_counts' => [],
                'recent_failure_metric_counts' => [],
                'recent_operator_actions' => [],
                'campaign_worker_leases_active' => 0,
                'campaign_worker_leases_stale' => 0,
                'recipients_processing' => 0,
                'recipients_processing_stale' => 0,
                'recipients_queued' => 0,
                'recipients_failed' => 0,
                'recent_recovered_total' => 0,
            ],
            'retention_diagnostics' => [
                'cleanup_mode' => 'explicit_only',
                'event_retention_days' => 0,
                'webhook_log_retention_days' => 0,
                'retention_floor_days' => 30,
                'delete_limit_per_run' => 500,
                'events_cleanup_candidate_preview' => 0,
                'events_cleanup_candidate_more' => false,
                'webhook_logs_cleanup_candidate_preview' => 0,
                'webhook_logs_cleanup_candidate_more' => false,
                'click_aggregate_candidate_preview' => 0,
                'click_aggregate_candidate_more' => false,
            ],
            'integrity_diagnostics' => [
                'generated_at' => null,
                'available' => false,
                'critical_count' => 0,
                'warning_count' => 0,
                'categories' => [],
                'top_categories' => [],
            ],
            'recent_campaigns' => [],
            'recent_activity' => [],
        ];
    }
}
