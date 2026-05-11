<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Analytics extends AdminController
{
    public function __construct()
    {
        parent::__construct();

        if (staff_cant('view', 'marketing') && staff_cant('view_own', 'marketing')) {
            access_denied('marketing');
        }

        $this->load->model('marketing/Marketing_events_model', 'marketing_events_model');
        $this->load->model('marketing/Marketing_dashboard_model', 'marketing_dashboard_model');
    }

    public function index()
    {
        if ($this->input->post('run_retention_cleanup')) {
            if (staff_cant('settings', 'marketing')) {
                access_denied('marketing');
            }

            try {
                $summary = $this->marketing_events_model->run_retention_cleanup();
                $eventsDeleted = (int) ($summary['events_deleted'] ?? 0);
                $webhookLogsDeleted = (int) ($summary['webhook_logs_deleted'] ?? 0);
                $warnings = isset($summary['warnings']) && is_array($summary['warnings'])
                    ? array_values(array_filter(array_map('trim', $summary['warnings'])))
                    : [];

                if ($eventsDeleted > 0 || $webhookLogsDeleted > 0) {
                    set_alert(
                        'success',
                        'Retention cleanup completed. Deleted ' . $eventsDeleted
                        . ' telemetry event row(s) and ' . $webhookLogsDeleted . ' webhook log row(s).'
                    );
                } elseif (!empty($warnings)) {
                    set_alert(
                        'warning',
                        'Retention cleanup did not delete rows. Warnings: ' . implode(', ', $warnings) . '.'
                    );
                } else {
                    set_alert('info', 'Retention cleanup completed. No eligible telemetry rows were deleted.');
                }
            } catch (Throwable $exception) {
                log_message('error', 'Marketing retention cleanup failed from analytics: ' . marketing_sanitize_log_message($exception->getMessage()));
                set_alert('danger', 'Retention cleanup failed. No telemetry rows were deleted.');
            }

            redirect(admin_url('marketing/analytics'));
        }

        $page = max(1, (int) $this->input->get('page'));
        $perPage = (int) $this->input->get('per_page');
        if ($perPage <= 0) {
            $perPage = 20;
        }
        $perPage = min($perPage, 100);
        $offset = ($page - 1) * $perPage;
        $totalEvents = $this->marketing_events_model->count_all_events();
        $totalPages = max(1, (int) ceil($totalEvents / $perPage));

        if ($page > $totalPages && $totalEvents > 0) {
            $page = $totalPages;
            $offset = ($page - 1) * $perPage;
        }

        $data['title']      = _l('marketing_analytics');
        $data['stats']      = $this->marketing_dashboard_model->get_analytics_summary();
        $data['recent']     = $this->marketing_events_model->get_events_paginated($perPage, $offset);
        $data['can_run_retention_cleanup'] = staff_can('settings', 'marketing');
        $data['events_pagination'] = [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $totalEvents,
            'total_pages' => $totalPages,
        ];

        $this->load->view('marketing/analytics/index', $data);
    }
}
