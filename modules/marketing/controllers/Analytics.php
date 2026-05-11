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
                $clickLinksReset = (int) ($summary['click_links_reset'] ?? 0);
                $clickLinksSkipped = (int) ($summary['click_links_skipped'] ?? 0);
                $warnings = isset($summary['warnings']) && is_array($summary['warnings'])
                    ? array_values(array_filter(array_map('trim', $summary['warnings'])))
                    : [];

                if ($eventsDeleted > 0 || $webhookLogsDeleted > 0 || $clickLinksReset > 0) {
                    $message = 'Retention cleanup completed. Event rows cleaned: ' . $eventsDeleted
                        . '. Webhook logs cleaned: ' . $webhookLogsDeleted
                        . '. Click aggregate rows reset: ' . $clickLinksReset . '.';
                    if ($clickLinksSkipped > 0) {
                        $message .= ' Protected click aggregate rows skipped: ' . $clickLinksSkipped . '.';
                    }
                    set_alert(
                        'success',
                        $message
                    );
                } elseif (!empty($warnings)) {
                    set_alert(
                        'warning',
                        'Retention cleanup completed without deleting rows. Warnings: ' . implode(', ', $warnings) . '.'
                    );
                } else {
                    $message = 'Retention cleanup completed. No eligible telemetry rows or stale click aggregates required changes.';
                    if ($clickLinksSkipped > 0) {
                        $message .= ' Protected click aggregate rows skipped: ' . $clickLinksSkipped . '.';
                    }
                    set_alert('info', $message);
                }
            } catch (Throwable $exception) {
                log_message('error', 'Marketing retention cleanup failed from analytics: ' . marketing_sanitize_log_message($exception->getMessage()));
                set_alert('danger', 'Retention cleanup failed. No telemetry rows or click aggregates were changed.');
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
        $data['retention_diagnostics'] = $this->marketing_events_model->get_retention_diagnostics();
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
