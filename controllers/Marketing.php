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
        $this->load->model('marketing/Marketing_conversations_model', 'marketing_conversations_model');
        $this->load->model('marketing/Marketing_integrity_model', 'marketing_integrity_model');
        $this->load->model('marketing/Marketing_reply_leads_model', 'marketing_reply_leads_model');
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

        try {
            $whatsappConversations = $this->marketing_conversations_model->get_recent_whatsapp_conversations(5);
            if (is_array($whatsappConversations)) {
                $whatsappConversations = $this->decorate_whatsapp_conversations_with_conversion_state($whatsappConversations);
                $data['whatsapp_conversations'] = array_replace_recursive($data['whatsapp_conversations'] ?? [], $whatsappConversations);
            }
        } catch (Throwable $exception) {
            log_message('error', 'Marketing WhatsApp dashboard conversations unavailable: ' . marketing_sanitize_log_message($exception->getMessage()));
        }

        $data['title'] = _l('marketing_dashboard');
        $data['can_manage_queue'] = staff_can('settings', 'marketing');

        $this->load->view('marketing/dashboard', $data);
    }

    public function reports()
    {
        $data = [
            'recent_campaigns' => [],
            'recent_activity' => [],
            'reports_cleanup' => [
                'activity_confirmation_phrase' => $this->get_reports_cleanup_confirmation_phrase('activity'),
                'campaigns_confirmation_phrase' => $this->get_reports_cleanup_confirmation_phrase('campaigns'),
            ],
        ];

        try {
            $data['recent_campaigns'] = $this->marketing_dashboard_model->get_recent_campaigns(5);
        } catch (Throwable $exception) {
            log_message('error', 'Marketing reports recent campaigns unavailable: ' . marketing_sanitize_log_message($exception->getMessage()));
        }

        try {
            $data['recent_activity'] = $this->marketing_dashboard_model->get_recent_activity(10);
        } catch (Throwable $exception) {
            log_message('error', 'Marketing reports recent activity unavailable: ' . marketing_sanitize_log_message($exception->getMessage()));
        }

        $data['title'] = _l('marketing_reports_heading');

        $this->load->view('marketing/reports', $data);
    }

    public function reports_cleanup()
    {
        if (staff_cant('settings', 'marketing')) {
            access_denied('marketing');
        }

        if (!$this->input->post()) {
            show_404();
        }

        $target = trim((string) $this->input->post('cleanup_target', true));
        $confirmation = trim((string) $this->input->post('cleanup_confirmation', false));
        $expectedConfirmation = $this->get_reports_cleanup_confirmation_phrase($target);

        if ($expectedConfirmation === null) {
            set_alert('danger', _l('marketing_reports_cleanup_invalid_target'));
            redirect(admin_url('marketing/reports'));
        }

        if ($confirmation !== $expectedConfirmation) {
            set_alert('danger', sprintf(_l('marketing_reports_cleanup_confirmation_invalid'), $expectedConfirmation));
            redirect(admin_url('marketing/reports'));
        }

        if ($target === 'activity') {
            $result = $this->marketing_dashboard_model->purge_report_activity();
        } else {
            $result = $this->marketing_dashboard_model->purge_report_campaigns();
        }

        $message = trim((string) ($result['message'] ?? ''));
        $deletedCounts = isset($result['deleted_counts']) && is_array($result['deleted_counts']) ? $result['deleted_counts'] : [];
        $deletedTotal = array_sum(array_map('intval', $deletedCounts));

        if (!empty($result['success'])) {
            if ($deletedTotal > 0) {
                if ($target === 'activity') {
                    set_alert('success', sprintf(_l('marketing_reports_cleanup_activity_success'), $deletedTotal));
                } else {
                    set_alert('success', sprintf(_l('marketing_reports_cleanup_campaigns_success'), $deletedTotal));
                }
            } else {
                set_alert('warning', $message !== '' ? $message : _l('marketing_reports_cleanup_no_rows'));
            }
        } else {
            set_alert('danger', $message !== '' ? $message : _l('marketing_reports_cleanup_failed'));
        }

        redirect(admin_url('marketing/reports'));
    }

    public function whatsapp_conversation($conversationId = null)
    {
        $conversationId = (int) $conversationId;
        if ($conversationId <= 0) {
            show_404();
        }

        $conversation = $this->marketing_conversations_model->get_whatsapp_conversation($conversationId);
        if (empty($conversation)) {
            show_404();
        }

        $messages = [];
        try {
            $messages = $this->marketing_conversations_model->get_whatsapp_conversation_messages($conversationId, 100);
        } catch (Throwable $exception) {
            log_message('error', 'Marketing WhatsApp conversation messages unavailable: ' . marketing_sanitize_log_message($exception->getMessage()));
        }

        $readiness = $this->marketing_reply_leads_model->get_whatsapp_lead_conversion_readiness([
            'conversation_lead_id' => $conversation['lead_id'] ?? null,
            'lead_id' => $conversation['lead_id'] ?? null,
            'email' => $conversation['email'] ?? '',
            'normalized_phone' => $conversation['normalized_phone'] ?? '',
            'phone' => $conversation['contact_phone'] ?? '',
        ]);
        $conversation['lead_conversion'] = $this->normalize_whatsapp_conversion_state($readiness, $conversation);
        $conversation['lead_url'] = !empty($conversation['lead_id'])
            ? admin_url('leads/index/' . (int) $conversation['lead_id'])
            : null;

        $data = [
            'title' => _l('marketing_whatsapp_conversation_title'),
            'conversation' => $conversation,
            'messages' => is_array($messages) ? $messages : [],
            'return_to' => 'marketing/whatsapp_conversation/' . $conversationId,
        ];

        $this->load->view('marketing/whatsapp_conversation', $data);
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

    public function convert_whatsapp_conversation()
    {
        if (staff_cant('settings', 'marketing')) {
            access_denied('marketing');
        }

        if (!$this->input->post()) {
            show_404();
        }

        $conversationId = (int) $this->input->post('conversation_id', true);
        if ($conversationId <= 0) {
            set_alert('warning', _l('marketing_dashboard_whatsapp_convert_invalid'));
            redirect($this->get_safe_marketing_redirect());
        }

        $result = $this->marketing_reply_leads_model->convert_whatsapp_conversation_to_lead($conversationId, get_staff_user_id());
        $leadId = (int) ($result['lead_id'] ?? 0);
        $message = trim((string) ($result['message'] ?? ''));
        if ($message === '') {
            $message = _l('marketing_dashboard_whatsapp_convert_unavailable');
        }

        if (!empty($result['success'])) {
            if (($result['action'] ?? '') === 'converted_created') {
                set_alert('success', sprintf(_l('marketing_dashboard_whatsapp_convert_created'), $leadId, $conversationId));
            } elseif (($result['action'] ?? '') === 'converted_existing' || ($result['action'] ?? '') === 'already_converted') {
                set_alert('success', sprintf(_l('marketing_dashboard_whatsapp_convert_linked_existing'), $leadId, $conversationId));
            } else {
                set_alert('success', $message);
            }
        } else {
            set_alert('warning', $message);
        }

        redirect($this->get_safe_marketing_redirect());
    }

    public function mark_whatsapp_conversation_read()
    {
        if (staff_cant('settings', 'marketing')) {
            access_denied('marketing');
        }

        if (!$this->input->post()) {
            show_404();
        }

        $conversationId = (int) $this->input->post('conversation_id', true);
        if ($conversationId <= 0) {
            set_alert('warning', _l('marketing_dashboard_whatsapp_mark_read_invalid'));
            redirect($this->get_safe_marketing_redirect());
        }

        $result = $this->marketing_conversations_model->mark_whatsapp_conversation_read($conversationId);
        if (!empty($result['success'])) {
            set_alert('success', sprintf(_l('marketing_dashboard_whatsapp_mark_read_success'), $conversationId));
        } else {
            $message = trim((string) ($result['message'] ?? ''));
            set_alert('warning', $message !== '' ? $message : _l('marketing_dashboard_whatsapp_mark_read_failure'));
        }

        redirect($this->get_safe_marketing_redirect());
    }

    public function send_whatsapp_conversation_reply()
    {
        if (staff_cant('settings', 'marketing')) {
            access_denied('marketing');
        }

        if (!$this->input->post()) {
            show_404();
        }

        $conversationId = (int) $this->input->post('conversation_id', true);
        $replyBody = trim((string) $this->input->post('reply_body', false));
        $maxLength = $this->get_whatsapp_reply_max_length();

        if ($conversationId <= 0) {
            set_alert('danger', _l('marketing_whatsapp_reply_invalid_conversation'));
            redirect($this->get_safe_marketing_redirect());
        }

        if ($replyBody === '') {
            set_alert('danger', _l('marketing_whatsapp_reply_body_required'));
            redirect($this->get_safe_marketing_redirect('marketing/whatsapp_conversation/' . $conversationId));
        }

        if (mb_strlen($replyBody) > $maxLength) {
            set_alert('danger', sprintf(_l('marketing_whatsapp_reply_body_too_long'), $maxLength));
            redirect($this->get_safe_marketing_redirect('marketing/whatsapp_conversation/' . $conversationId));
        }

        $conversation = $this->marketing_conversations_model->get_whatsapp_conversation($conversationId);
        if (empty($conversation)) {
            set_alert('danger', _l('marketing_whatsapp_reply_invalid_conversation'));
            redirect($this->get_safe_marketing_redirect());
        }

        $recipientPhone = trim((string) ($conversation['normalized_phone'] ?? ($conversation['contact_phone'] ?? '')));
        if ($recipientPhone === '') {
            set_alert('danger', _l('marketing_whatsapp_reply_contact_unavailable'));
            redirect($this->get_safe_marketing_redirect('marketing/whatsapp_conversation/' . $conversationId));
        }

        $this->load->library('marketing/Marketing_whatsapp');
        $sendResult = $this->marketing_whatsapp->send_text_message($recipientPhone, $replyBody);
        if (empty($sendResult['success'])) {
            set_alert('danger', $this->format_whatsapp_reply_error_message($sendResult));
            redirect($this->get_safe_marketing_redirect('marketing/whatsapp_conversation/' . $conversationId));
        }

        $persistResult = $this->marketing_conversations_model->record_whatsapp_outbound_reply($conversationId, [
            'body_text' => $replyBody,
            'provider_message_id' => trim((string) ($sendResult['provider_message_id'] ?? '')),
            'provider_thread_id' => trim((string) ($conversation['provider_thread_id'] ?? '')),
            'provider_status' => 'accepted',
            'datecreated' => date('Y-m-d H:i:s'),
            'contact_phone' => trim((string) ($conversation['contact_phone'] ?? '')),
            'normalized_phone' => trim((string) ($conversation['normalized_phone'] ?? '')),
        ]);

        if (!empty($persistResult['success'])) {
            set_alert('success', sprintf(_l('marketing_whatsapp_reply_send_success'), $conversationId));
        } else {
            $message = trim((string) ($persistResult['message'] ?? ''));
            if ($message === '') {
                $message = _l('marketing_whatsapp_reply_persistence_failure');
            }
            set_alert('warning', $message);
        }

        redirect($this->get_safe_marketing_redirect('marketing/whatsapp_conversation/' . $conversationId));
    }

    protected function get_reports_cleanup_confirmation_phrase($target)
    {
        if ($target === 'activity') {
            return 'DELETE ACTIVITY REPORTS';
        }

        if ($target === 'campaigns') {
            return 'DELETE CAMPAIGN REPORTS';
        }

        return null;
    }

    protected function get_safe_marketing_redirect($fallback = 'marketing')
    {
        $returnTo = trim((string) $this->input->post('return_to', true));
        if ($returnTo !== '' && preg_match('#^marketing(?:/[A-Za-z0-9_/-]+)?$#', $returnTo)) {
            return admin_url(trim($returnTo, '/'));
        }

        return admin_url($fallback);
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
            'whatsapp_conversations' => [
                'available' => false,
                'shown_count' => 0,
                'unread_conversation_count' => 0,
                'unread_message_count' => 0,
                'items' => [],
            ],
            'recent_campaigns' => [],
            'recent_activity' => [],
        ];
    }

    protected function decorate_whatsapp_conversations_with_conversion_state(array $summary)
    {
        $items = isset($summary['items']) && is_array($summary['items']) ? $summary['items'] : [];
        if (empty($items)) {
            $summary['items'] = [];

            return $summary;
        }

        foreach ($items as &$item) {
            $readiness = $this->marketing_reply_leads_model->get_whatsapp_lead_conversion_readiness([
                'conversation_lead_id' => $item['lead_id'] ?? null,
                'lead_id' => $item['lead_id'] ?? null,
                'email' => $item['email'] ?? '',
                'normalized_phone' => $item['normalized_phone'] ?? '',
                'phone' => $item['contact_phone'] ?? '',
            ]);
            $item['lead_conversion'] = $this->normalize_whatsapp_conversion_state($readiness, $item);
        }
        unset($item);

        $summary['items'] = $items;

        return $summary;
    }

    protected function normalize_whatsapp_conversion_state(array $readiness, array $item)
    {
        if (!empty($item['lead_id'])) {
            return [
                'actionable' => false,
                'label' => _l('marketing_dashboard_whatsapp_lead_linked'),
                'status' => 'linked',
            ];
        }

        if (!empty($readiness['ready']) && ($readiness['status'] ?? '') === 'existing_lead' && !empty($readiness['lead_id'])) {
            return [
                'actionable' => true,
                'label' => sprintf(_l('marketing_dashboard_whatsapp_convert_link_existing_action'), (int) $readiness['lead_id']),
                'status' => 'existing_lead',
            ];
        }

        if (!empty($readiness['ready']) && ($readiness['status'] ?? '') === 'ready_create') {
            return [
                'actionable' => true,
                'label' => _l('marketing_dashboard_whatsapp_convert_action'),
                'status' => 'ready_create',
            ];
        }

        if (($readiness['status'] ?? '') === 'ambiguous_match') {
            return [
                'actionable' => false,
                'label' => _l('marketing_dashboard_whatsapp_convert_ambiguous'),
                'status' => 'ambiguous_match',
            ];
        }

        if (($readiness['reason'] ?? '') === 'phone_missing') {
            return [
                'actionable' => false,
                'label' => _l('marketing_dashboard_whatsapp_convert_not_enough_identity'),
                'status' => 'not_ready',
            ];
        }

        return [
            'actionable' => false,
            'label' => _l('marketing_dashboard_whatsapp_convert_unavailable'),
            'status' => 'unavailable',
        ];
    }

    protected function get_whatsapp_reply_max_length()
    {
        return 4096;
    }

    protected function format_whatsapp_reply_error_message(array $result)
    {
        $message = _l('marketing_whatsapp_reply_send_failure');
        $error = trim((string) ($result['error'] ?? ''));
        if ($error !== '') {
            $message .= ' ' . $error;
        }

        $details = [];
        $statusCode = (int) ($result['status_code'] ?? 0);
        if ($statusCode > 0) {
            $details[] = 'HTTP ' . $statusCode;
        }

        $metaType = trim((string) ($result['meta_error_type'] ?? ''));
        if ($metaType !== '') {
            $details[] = 'type: ' . $metaType;
        }

        $metaCode = trim((string) ($result['meta_error_code'] ?? ''));
        if ($metaCode !== '') {
            $details[] = 'code: ' . $metaCode;
        }

        $metaSubcode = trim((string) ($result['meta_error_subcode'] ?? ''));
        if ($metaSubcode !== '') {
            $details[] = 'subcode: ' . $metaSubcode;
        }

        $fbtraceId = trim((string) ($result['meta_fbtrace_id'] ?? ''));
        if ($fbtraceId !== '') {
            $details[] = 'fbtrace_id: ' . $fbtraceId;
        }

        if (!empty($details)) {
            $message .= ' (' . implode(', ', $details) . ')';
        }

        return $message;
    }
}
