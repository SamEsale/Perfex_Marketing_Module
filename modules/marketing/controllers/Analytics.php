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
        if ($this->input->post('clear_marketing_events')) {
            if (staff_cant('delete', 'marketing')) {
                access_denied('marketing');
            }

            if ($this->marketing_events_model->clear_event_history()) {
                set_alert('success', _l('marketing_event_history_cleared'));
            } else {
                set_alert('warning', _l('marketing_event_history_clear_failed'));
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
        $data['can_clear_event_history'] = staff_can('delete', 'marketing');
        $data['events_pagination'] = [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $totalEvents,
            'total_pages' => $totalPages,
        ];

        $this->load->view('marketing/analytics/index', $data);
    }
}
