<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Campaigns extends AdminController
{
    private $saveCampaignErrorMessage = null;
    private const BUILDER_STATE_MARKER_PREFIX = '<!--marketing_builder_state:';
    private const BUILDER_STATE_MARKER_SUFFIX = '-->';
    private const UNSAFE_DEFAULT_PLAIN_TEXT_SIGNATURES = [
        'Your headline goes here Introduce the offer, explain the value, and set up the email. Call to Action',
        'Your headline goes here Introduce the offer, explain the value, and set up the email. Call to Action Add your marketing copy here. Shop now Your Company 123 Market Street City, State Unsubscribe',
    ];

    private const STATUSES = [
        'draft',
        'scheduled',
        'sending',
        'sent',
        'paused',
        'cancelled',
        'failed',
    ];

    public function __construct()
    {
        parent::__construct();

        if (staff_cant('view', 'marketing') && staff_cant('view_own', 'marketing')) {
            access_denied('marketing');
        }

        $this->load->model('marketing/Marketing_campaigns_model', 'marketing_campaigns_model');
        $this->load->model('marketing/Marketing_audiences_model', 'marketing_audiences_model');
        $this->load->model('marketing/Marketing_conversations_model', 'marketing_conversations_model');
        $this->load->model('marketing/Marketing_reply_leads_model', 'marketing_reply_leads_model');
        $this->load->library('form_validation');
    }

    public function index()
    {
        if ($this->input->post('prepare_campaign_id')) {
            if (staff_cant('send', 'marketing')) {
                access_denied('marketing');
            }

            $campaignId = (int) $this->input->post('prepare_campaign_id');
            $campaign = $this->marketing_campaigns_model->get($campaignId);

            if (!$campaign) {
                set_alert('danger', _l('marketing_campaign_not_found'));
                redirect(admin_url('marketing/campaigns'));
            }

            if (staff_cant('view', 'marketing') && staff_can('view_own', 'marketing') && $campaign['created_by'] !== get_staff_user_id()) {
                access_denied('marketing');
            }

            $snapshot = $this->marketing_campaigns_model->get_campaign_targeting_snapshot($campaign);
            if ($this->should_require_prepare_license($snapshot) && !marketing_is_feature_allowed('recipient_prepare')) {
                set_alert('warning', marketing_license_block_message());
                redirect(admin_url('marketing/campaigns/show/' . $campaignId));
            }

            $this->marketing_campaigns_model->record_operator_action('manual_prepare_recipients_requested', [], $campaignId, get_staff_user_id());
            $summary = $this->marketing_campaigns_model->prepare_recipients($campaignId);

            if (is_array($summary) && !empty($summary['license_blocked'])) {
                set_alert('warning', $summary['license_message'] ?? marketing_license_block_message());
            } elseif ($summary === false) {
                if ($this->can_reset_stuck_sending_campaign($campaignId, $campaign)) {
                    $resetStatus = !empty($campaign['scheduled_at']) ? 'scheduled' : 'draft';
                    $this->marketing_campaigns_model->update($campaignId, [
                        'status' => $resetStatus,
                        'send_started_at' => null,
                        'send_completed_at' => null,
                    ]);
                }
                set_alert('warning', _l('marketing_campaign_prepare_failed'));
            } elseif (!empty($summary['queued'])) {
                set_alert('success', sprintf(_l('marketing_campaign_recipients_prepared_summary'), (int) $summary['queued']));
            } else {
                set_alert('warning', _l('marketing_campaign_no_eligible_contacts'));
            }

            redirect(admin_url('marketing/campaigns/show/' . $campaignId));
        }

        if ($this->input->post('reprepare_campaign_id')) {
            if (staff_cant('send', 'marketing')) {
                access_denied('marketing');
            }

            $campaignId = (int) $this->input->post('reprepare_campaign_id');
            $campaign = $this->marketing_campaigns_model->get($campaignId);

            if (!$campaign) {
                set_alert('danger', _l('marketing_campaign_not_found'));
                redirect(admin_url('marketing/campaigns'));
            }

            if (staff_cant('view', 'marketing') && staff_can('view_own', 'marketing') && $campaign['created_by'] !== get_staff_user_id()) {
                access_denied('marketing');
            }

            if (!$this->marketing_campaigns_model->can_reprepare_recipients($campaign)) {
                set_alert('warning', _l('marketing_campaign_reprepare_recipients_restricted'));
                redirect(admin_url('marketing/campaigns/show/' . $campaignId));
            }

            $queuedRecipients = $this->marketing_campaigns_model->count_recipients_by_status($campaignId, 'queued');
            if ($queuedRecipients === 0) {
                set_alert('warning', _l('marketing_campaign_reprepare_recipients_restricted'));
                redirect(admin_url('marketing/campaigns/show/' . $campaignId));
            }

            $snapshot = $this->marketing_campaigns_model->get_campaign_targeting_snapshot($campaign);
            if ($this->should_require_prepare_license($snapshot) && !marketing_is_feature_allowed('recipient_prepare')) {
                set_alert('warning', marketing_license_block_message());
                redirect(admin_url('marketing/campaigns/show/' . $campaignId));
            }

            $this->marketing_campaigns_model->record_operator_action('manual_reprepare_recipients_requested', [], $campaignId, get_staff_user_id());
            $summary = $this->marketing_campaigns_model->sync_newly_eligible_recipients($campaignId);

            if (is_array($summary) && !empty($summary['license_blocked'])) {
                set_alert('warning', $summary['license_message'] ?? marketing_license_block_message());
            } elseif ($summary === false) {
                set_alert('danger', _l('marketing_campaign_prepare_recipients_failure'));
            } elseif ((int) ($summary['added_count'] ?? 0) > 0) {
                set_alert('success', sprintf(_l('marketing_campaign_reprepare_recipients_success'), (int) $summary['added_count']));
            } else {
                set_alert('warning', _l('marketing_campaign_reprepare_recipients_none'));
            }

            redirect(admin_url('marketing/campaigns/show/' . $campaignId));
        }


        if ($this->input->post('send_campaign_id')) {
            if (staff_cant('send', 'marketing')) {
                access_denied('marketing');
            }

            $campaignId = (int) $this->input->post('send_campaign_id');
            $campaign = $this->marketing_campaigns_model->get($campaignId);

            if (!$campaign) {
                set_alert('danger', _l('marketing_campaign_not_found'));
                redirect(admin_url('marketing/campaigns'));
            }

            if (staff_cant('view', 'marketing') && staff_can('view_own', 'marketing') && $campaign['created_by'] !== get_staff_user_id()) {
                access_denied('marketing');
            }

            if (!$this->marketing_option_enabled('marketing_send_enabled')) {
                set_alert('warning', _l('marketing_campaign_delivery_disabled'));
                redirect(admin_url('marketing/campaigns/show/' . $campaignId));
            }

            if (!marketing_is_feature_allowed($this->get_campaign_send_license_feature($campaign))) {
                set_alert('warning', marketing_license_block_message());
                redirect(admin_url('marketing/campaigns/show/' . $campaignId));
            }

            if (!in_array($campaign['status'], ['draft', 'scheduled', 'sending'], true)) {
                set_alert('info', 'Campaign completed');
                redirect(admin_url('marketing/campaigns/show/' . $campaignId));
            }

            $queued = $this->marketing_campaigns_model->count_recipients_by_status($campaignId, 'queued');
            if ($queued === 0) {
                set_alert('warning', 'No queued recipients. Please prepare recipients first.');
                redirect(admin_url('marketing/campaigns/show/' . $campaignId));
            }

            if ($this->marketing_campaigns_model->is_queue_paused()) {
                set_alert('warning', 'Campaign queue is paused. Resume the queue before sending.');
                redirect(admin_url('marketing/campaigns/show/' . $campaignId));
            }

            $this->marketing_campaigns_model->record_operator_action('manual_process_queue_requested', [], $campaignId, get_staff_user_id());
            $summary = $this->marketing_campaigns_model->process_due_recipients(null, $campaignId);

            if (is_array($summary) && (int) ($summary['processed'] ?? 0) === 0) {
                $messages = [];
                if (!empty($summary['readiness_messages']) && is_array($summary['readiness_messages'])) {
                    $messages = array_values(array_unique(array_filter($summary['readiness_messages'])));
                } else {
                    $messages = $this->format_send_warnings($summary['warnings'] ?? []);
                }

                set_alert('warning', implode(' ', $messages));
            } elseif (is_array($summary)) {
                $accepted = (int) ($summary['accepted'] ?? $summary['processed'] ?? 0);
                $failed   = (int) ($summary['failed'] ?? 0);
                set_alert('success', sprintf(_l('marketing_campaign_send_batch_summary'), $accepted, $failed));
            } else {
                set_alert('success', _l('marketing_campaign_queue_processed'));
            }

            redirect(admin_url('marketing/campaigns/show/' . $campaignId));
        }

        if ($this->input->post('reset_campaign_id')) {
            if (staff_cant('send', 'marketing')) {
                access_denied('marketing');
            }

            $campaignId = (int) $this->input->post('reset_campaign_id');
            $campaign = $this->marketing_campaigns_model->get($campaignId);

            if (!$campaign) {
                set_alert('danger', _l('marketing_campaign_not_found'));
                redirect(admin_url('marketing/campaigns'));
            }

            if (staff_cant('view', 'marketing') && staff_can('view_own', 'marketing') && $campaign['created_by'] !== get_staff_user_id()) {
                access_denied('marketing');
            }

            $this->marketing_campaigns_model->record_operator_action('manual_reset_campaign_requested', [], $campaignId, get_staff_user_id());
            if ($campaign['status'] === 'sending'
                && $this->marketing_campaigns_model->count_recipients_by_status($campaignId, 'processing') === 0) {
                $this->marketing_campaigns_model->update($campaignId, [
                    'status' => 'draft',
                    'send_started_at' => null,
                    'send_completed_at' => null,
                ]);
                set_alert('success', _l('marketing_campaign_reset_to_draft'));
            } else {
                set_alert('warning', 'Campaign cannot be reset right now.');
            }

            redirect(admin_url('marketing/campaigns/show/' . $campaignId));
        }

        if ($this->input->post('retry_campaign_id')) {
            if (staff_cant('send', 'marketing')) {
                access_denied('marketing');
            }

            $campaignId = (int) $this->input->post('retry_campaign_id');
            $campaign = $this->marketing_campaigns_model->get($campaignId);

            if (!$campaign) {
                set_alert('danger', _l('marketing_campaign_not_found'));
                redirect(admin_url('marketing/campaigns'));
            }

            if (staff_cant('view', 'marketing') && staff_can('view_own', 'marketing') && $campaign['created_by'] !== get_staff_user_id()) {
                access_denied('marketing');
            }

            $this->marketing_campaigns_model->record_operator_action('manual_retry_failed_requested', [], $campaignId, get_staff_user_id());
            $result = $this->marketing_campaigns_model->retry_failed_recipients($campaignId);

            if ($result === false) {
                set_alert('warning', _l('marketing_campaign_retry_failed_none_found'));
            } elseif ((int) ($result['requeued'] ?? 0) > 0) {
                set_alert('success', _l('marketing_campaign_retry_failed_success'));
            } else {
                set_alert('warning', _l('marketing_campaign_retry_failed_none_found'));
            }

            redirect(admin_url('marketing/campaigns/show/' . $campaignId));
        }


        if ($this->input->post('delete_campaign_id')) {
            if (staff_cant('delete', 'marketing')) {
                access_denied('marketing');
            }

            $campaignId = (int) $this->input->post('delete_campaign_id');
            $campaign = $this->marketing_campaigns_model->get($campaignId);

            if (!$campaign) {
                set_alert('danger', _l('marketing_campaign_not_found'));
                redirect(admin_url('marketing/campaigns'));
            }

            if (staff_cant('view', 'marketing') && staff_can('view_own', 'marketing') && $campaign['created_by'] !== get_staff_user_id()) {
                access_denied('marketing');
            }

            if (!$this->marketing_campaigns_model->campaign_can_be_deleted($campaign)) {
                set_alert('danger', _l('marketing_campaign_delete_processing'));
            } elseif ($this->marketing_campaigns_model->campaign_can_be_deleted($campaign) && $this->marketing_campaigns_model->delete($campaignId)) {
                set_alert('success', _l('marketing_campaign_deleted'));
            } else {
                set_alert('warning', _l('marketing_campaign_delete_failure'));
            }

            redirect(admin_url('marketing/campaigns'));
        }

        if (staff_can('view', 'marketing')) {
            $campaigns = $this->marketing_campaigns_model->get_all();
        } else {
            $campaigns = $this->marketing_campaigns_model->get_by_owner(get_staff_user_id());
        }

        foreach ($campaigns as &$campaign) {
            $campaign['queued_recipients'] = $this->marketing_campaigns_model->count_recipients_by_status($campaign['id'], 'queued');
            $campaign['processing_recipients'] = $this->marketing_campaigns_model->count_recipients_by_status($campaign['id'], 'processing');
            $campaign['sent_recipients'] = $this->marketing_campaigns_model->count_recipients_by_status($campaign['id'], 'sent');
            $campaign['failed_recipients'] = $this->marketing_campaigns_model->count_recipients_by_status($campaign['id'], 'failed');
            $campaign['can_edit_campaign'] = $this->marketing_campaigns_model->campaign_can_be_edited($campaign);
            $campaign['can_delete_campaign'] = $this->marketing_campaigns_model->campaign_can_be_deleted($campaign);
            $campaign['can_reset_campaign'] = $campaign['status'] === 'sending' && (int) $campaign['processing_recipients'] === 0;
        }
        unset($campaign);

        $data['title']      = _l('marketing_campaigns');
        $data['campaigns']  = $campaigns;
        $data['can_create'] = staff_can('create', 'marketing');
        $data['can_edit']   = staff_can('edit', 'marketing');
        $data['can_delete'] = staff_can('delete', 'marketing');
        $data['can_send']   = staff_can('send', 'marketing');

        $this->load->view('marketing/campaigns/index', $data);
    }

    public function create()
    {
        if (staff_cant('create', 'marketing')) {
            access_denied('marketing');
        }

        if ($this->input->post()) {
            $savedCampaignId = $this->save_campaign();
            if ($savedCampaignId) {
                redirect(admin_url('marketing/campaigns/edit/' . $savedCampaignId));
            }

            $campaign = $this->get_posted_campaign();
        } else {
            $campaign = $this->get_default_campaign();
        }

        $data['title']     = _l('marketing_create_campaign');
        $data['campaign']  = $campaign;
        $data['action']    = admin_url('marketing/campaigns/create');
        $data['audiences'] = $this->marketing_audiences_model->get_all_lists();
        $data['statuses']  = self::STATUSES;
        $data['contact_status_options'] = $this->marketing_audiences_model->get_contact_status_options();
        $data['consent_status_options'] = $this->marketing_audiences_model->get_consent_status_options();
        $data['supports_created_date_filter'] = $this->marketing_campaigns_model->contact_created_date_filter_supported();
        $data['form_error_message'] = $this->saveCampaignErrorMessage;

        $this->load->view('marketing/campaigns/form', $data);
    }

    public function edit($id)
    {
        if (staff_cant('edit', 'marketing')) {
            access_denied('marketing');
        }

        $campaign = $this->hydrate_builder_campaign($this->marketing_campaigns_model->get($id));

        if (!$campaign) {
            show_404();
        }

        if (staff_cant('view', 'marketing') && staff_can('view_own', 'marketing')) {
            if ($campaign['created_by'] !== get_staff_user_id()) {
                access_denied('marketing');
            }
        }

        if (!$this->marketing_campaigns_model->campaign_can_be_edited($campaign)) {
            set_alert('warning', _l('marketing_campaign_edit_restricted'));
            redirect(admin_url('marketing/campaigns'));
        }

        if ($this->input->post()) {
            $savedCampaignId = $this->save_campaign($id);
            if ($savedCampaignId) {
                redirect(admin_url('marketing/campaigns/edit/' . $savedCampaignId));
            }

            $campaign = $this->get_posted_campaign();
            $campaign['id'] = $id;
        }

        $data['title']     = _l('marketing_edit_campaign');
        $data['campaign']  = $campaign;
        $data['action']    = admin_url('marketing/campaigns/edit/' . $id);
        $data['audiences'] = $this->marketing_audiences_model->get_all_lists();
        $data['statuses']  = self::STATUSES;
        $data['contact_status_options'] = $this->marketing_audiences_model->get_contact_status_options();
        $data['consent_status_options'] = $this->marketing_audiences_model->get_consent_status_options();
        $data['supports_created_date_filter'] = $this->marketing_campaigns_model->contact_created_date_filter_supported();
        $data['form_error_message'] = $this->saveCampaignErrorMessage;

        $this->load->view('marketing/campaigns/form', $data);
    }

    public function delete($id)
    {
        if (staff_cant('delete', 'marketing')) {
            access_denied('marketing');
        }

        if (!$this->input->post()) {
            show_404();
        }

        $campaign = $this->hydrate_builder_campaign($this->marketing_campaigns_model->get($id));

        if (!$campaign) {
            show_404();
        }

        redirect(admin_url('marketing/campaigns'));
    }

    public function show($id)
    {
        if (staff_cant('view', 'marketing') && staff_cant('view_own', 'marketing')) {
            access_denied('marketing');
        }

        $campaign = $this->hydrate_builder_campaign($this->marketing_campaigns_model->get($id));

        if (!$campaign) {
            show_404();
        }

        if (staff_cant('view', 'marketing') && staff_can('view_own', 'marketing')) {
            if ($campaign['created_by'] !== get_staff_user_id()) {
                access_denied('marketing');
            }
        }

        $recipientSummary = $this->marketing_campaigns_model->get_recipient_summary($id);
        $eventSummary = $this->marketing_campaigns_model->get_event_summary($id);
        $whatsappEventSummary = $this->marketing_campaigns_model->get_whatsapp_event_summary($id);
        $sendReadiness = $this->marketing_campaigns_model->validate_send_ready($id);
        $campaignReadiness = $this->marketing_campaigns_model->get_campaign_readiness_snapshot(
            $id,
            $campaign,
            $recipientSummary,
            $sendReadiness,
            $eventSummary
        );
        $targetingSnapshot = $this->marketing_campaigns_model->get_campaign_targeting_snapshot($campaign);
        $sendWindow = $this->marketing_campaigns_model->get_send_window_settings();
        $rateLimitSnapshot = $this->marketing_campaigns_model->get_rate_limit_snapshot();
        $whatsappStatus = $this->marketing_campaigns_model->get_whatsapp_configuration_status();
        $campaignReplies = $this->marketing_conversations_model->get_campaign_replies($id, 20);
        $unmatchedReplies = $this->marketing_conversations_model->get_unmatched_inbound_replies(5);
        $campaignReplies = $this->marketing_reply_leads_model->decorate_replies_with_lead_metadata($campaignReplies, $id);
        $unmatchedReplies = $this->marketing_reply_leads_model->decorate_replies_with_lead_metadata($unmatchedReplies, null);
        $readinessStatus = $campaignReadiness['status_label'];
        $recipientPage = max(1, (int) $this->input->get('recipient_page'));
        $recipientPerPage = (int) $this->input->get('recipient_per_page');
        if ($recipientPerPage <= 0) {
            $recipientPerPage = 20;
        }
        $recipientPerPage = min($recipientPerPage, 100);
        $recipientStatusFilter = trim((string) $this->input->get('recipient_status', true));
        $allowedRecipientFilters = ['', 'failed', 'sent'];
        if (!in_array($recipientStatusFilter, $allowedRecipientFilters, true)) {
            $recipientStatusFilter = '';
        }
        $recipientTotal = $this->marketing_campaigns_model->count_campaign_recipients($id, $recipientStatusFilter !== '' ? $recipientStatusFilter : null);
        $recipientTotalPages = max(1, (int) ceil($recipientTotal / $recipientPerPage));
        if ($recipientPage > $recipientTotalPages && $recipientTotal > 0) {
            $recipientPage = $recipientTotalPages;
        }
        $recipientOffset = ($recipientPage - 1) * $recipientPerPage;
        $campaignRecipients = $this->marketing_campaigns_model->get_campaign_recipients_paginated(
            $id,
            $recipientPerPage,
            $recipientOffset,
            $recipientStatusFilter !== '' ? $recipientStatusFilter : null
        );

        $data['title'] = _l('marketing_campaign_detail');
        $data['campaign'] = $campaign;
        $data['preview_html'] = $this->sanitize_preview_html($campaign['html_content']);
        $data['recipient_summary'] = $recipientSummary;
        $data['event_summary'] = $eventSummary;
        $data['whatsapp_event_summary'] = $whatsappEventSummary;
        $data['whatsapp_status'] = $whatsappStatus;
        $data['send_readiness'] = $sendReadiness;
        $data['campaign_readiness'] = $campaignReadiness;
        $data['targeting_snapshot'] = $targetingSnapshot;
        $data['targeting_summary_lines'] = $this->marketing_campaigns_model->get_targeting_summary_lines($campaign['targeting_rules']);
        $data['send_window'] = $sendWindow;
        $data['rate_limit_snapshot'] = $rateLimitSnapshot;
        $data['campaign_replies'] = $campaignReplies;
        $data['unmatched_replies'] = $unmatchedReplies;
        $data['readiness_status'] = $readinessStatus;
        $data['campaign_recipients'] = $campaignRecipients;
        $data['recipient_filter'] = $recipientStatusFilter;
        $data['recipients_pagination'] = [
            'page' => $recipientPage,
            'per_page' => $recipientPerPage,
            'total' => $recipientTotal,
            'total_pages' => $recipientTotalPages,
        ];
        $data['can_send'] = staff_can('send', 'marketing');
        $data['can_prepare_recipients'] = $data['can_send'] && $this->marketing_campaigns_model->can_prepare_recipients($campaign);
        $data['can_reprepare_recipients'] = $data['can_send'] && $this->marketing_campaigns_model->can_reprepare_recipients($campaign);
        $data['can_schedule'] = $data['can_send'] && $this->marketing_campaigns_model->can_schedule($campaign);
        $data['can_edit_campaign'] = staff_can('edit', 'marketing') && $this->marketing_campaigns_model->campaign_can_be_edited($campaign);
        $data['can_reset_campaign'] = $data['can_send']
            && $campaign['status'] === 'sending'
            && (int) $recipientSummary['processing'] === 0;
        $data['can_delete'] = staff_can('delete', 'marketing');
        $data['can_delete_campaign'] = $data['can_delete'] && $this->marketing_campaigns_model->campaign_can_be_deleted($campaign);
        $data['show_reply_lead_metadata'] = staff_can('view', 'marketing');
        $data['can_retry_failed'] = $data['can_send']
            && (int) $recipientSummary['failed'] > 0
            && in_array($campaign['status'], ['sending', 'sent', 'failed'], true);
        $data['send_enabled'] = get_option('marketing_send_enabled') == 1;
        $data['smtp_status'] = $this->marketing_campaigns_model->get_smtp_configuration_status();

        $this->load->view('marketing/campaigns/show', $data);
    }

    public function estimate_recipients()
    {
        if (staff_cant('create', 'marketing') && staff_cant('edit', 'marketing') && staff_cant('send', 'marketing')) {
            access_denied('marketing');
        }

        if ($this->input->method() !== 'post') {
            $this->respond_json([
                'success' => false,
                'message' => 'Method not allowed. POST required.',
            ], 405);

            return;
        }

        $campaignId = (int) $this->input->post('campaign_id');
        $existingCampaign = null;
        if ($campaignId > 0) {
            $existingCampaign = $this->marketing_campaigns_model->get($campaignId);
            if (!$existingCampaign) {
                $this->respond_json([
                    'success' => false,
                    'message' => _l('marketing_campaign_not_found'),
                ], 404);

                return;
            }

            if (staff_cant('view', 'marketing') && staff_can('view_own', 'marketing') && (int) $existingCampaign['created_by'] !== get_staff_user_id()) {
                access_denied('marketing');
            }
        }

        $listId = (int) $this->input->post('list_id');
        if ($listId <= 0 || !$this->marketing_audiences_model->list_exists($listId)) {
            $this->respond_json([
                'success' => false,
                'message' => _l('marketing_campaign_list_required'),
            ], 400);

            return;
        }

        $campaign = $existingCampaign ?: [];
        $campaign['id'] = $campaignId;
        $campaign['list_id'] = $listId;
        $campaign['builder_state'] = isset($campaign['builder_state']) ? $campaign['builder_state'] : '';
        $campaign['html_content'] = isset($campaign['html_content']) ? $campaign['html_content'] : '';
        $campaign['targeting_rules'] = $this->get_posted_targeting_rules();

        $snapshot = $this->marketing_campaigns_model->get_campaign_targeting_snapshot($campaign, $campaign['targeting_rules']);

        $this->respond_json([
            'success' => true,
            'eligible' => (int) $snapshot['eligible'],
            'total_members' => (int) $snapshot['total_members'],
            'duplicate' => (int) $snapshot['duplicate'],
            'invalid' => (int) $snapshot['invalid'],
            'suppressed' => (int) $snapshot['suppressed'],
            'inactive' => (int) $snapshot['inactive'],
            'unsubscribed' => (int) $snapshot['unsubscribed'],
            'message' => sprintf(
                _l('marketing_campaign_segment_estimate_result'),
                (int) $snapshot['eligible'],
                (int) $snapshot['total_members']
            ),
            'csrf' => [
                'name' => $this->security->get_csrf_token_name(),
                'hash' => $this->security->get_csrf_hash(),
            ],
        ]);
    }

    public function preview($id)
    {
        if (staff_cant('view', 'marketing') && staff_cant('view_own', 'marketing')) {
            access_denied('marketing');
        }

        $campaign = $this->hydrate_builder_campaign($this->marketing_campaigns_model->get($id));

        if (!$campaign) {
            show_404();
        }

        if (staff_cant('view', 'marketing') && staff_can('view_own', 'marketing')) {
            if ($campaign['created_by'] !== get_staff_user_id()) {
                access_denied('marketing');
            }
        }

        $data['title']       = _l('marketing_campaign_preview');
        $data['campaign']    = $campaign;
        $data['preview_html'] = $this->sanitize_preview_html($campaign['html_content']);

        $this->load->view('marketing/campaigns/preview', $data);
    }

    private function can_reset_stuck_sending_campaign($campaignId, array $campaign)
    {
        if ($campaign['status'] !== 'sending') {
            return false;
        }

        return $this->marketing_campaigns_model->count_recipients_by_status($campaignId, 'queued') === 0
            && $this->marketing_campaigns_model->count_recipients_by_status($campaignId, 'processing') === 0
            && $this->marketing_campaigns_model->count_recipients_by_status($campaignId, 'sent') === 0
            && $this->marketing_campaigns_model->count_recipients_by_status($campaignId, 'failed') === 0;
    }

    public function prepare_recipients($id)
    {
        if (staff_cant('send', 'marketing')) {
            access_denied('marketing');
        }

        if (!$this->input->post()) {
            show_404();
        }

        $campaign = $this->marketing_campaigns_model->get($id);

        if (!$campaign) {
            show_404();
        }

        if (staff_cant('view', 'marketing') && staff_can('view_own', 'marketing')) {
            if ($campaign['created_by'] !== get_staff_user_id()) {
                access_denied('marketing');
            }
        }

        if (!in_array($campaign['status'], ['draft', 'scheduled'], true)) {
            set_alert('warning', _l('marketing_campaign_prepare_recipients_restricted'));
            redirect(admin_url('marketing/campaigns/show/' . $id));
        }

        if (empty($campaign['list_id']) || !$this->marketing_audiences_model->list_exists($campaign['list_id'])) {
            set_alert('danger', _l('marketing_campaign_list_required'));
            redirect(admin_url('marketing/campaigns/show/' . $id));
        }

        $summary = $this->marketing_campaigns_model->prepare_recipients($id);

        if ($summary === false) {
            set_alert('danger', _l('marketing_campaign_prepare_recipients_failure'));
        } elseif ((int) $summary['queued'] === 0) {
            set_alert('warning', $this->build_prepare_summary_message($summary));
        } else {
            set_alert('success', $this->build_prepare_summary_message($summary));
        }

        redirect(admin_url('marketing/campaigns/show/' . $id));
    }

    public function reprepare_recipients($id)
    {
        if (staff_cant('send', 'marketing')) {
            access_denied('marketing');
        }

        if (!$this->input->post()) {
            show_404();
        }

        $campaign = $this->marketing_campaigns_model->get($id);

        if (!$campaign) {
            show_404();
        }

        if (staff_cant('view', 'marketing') && staff_can('view_own', 'marketing')) {
            if ($campaign['created_by'] !== get_staff_user_id()) {
                access_denied('marketing');
            }
        }

        if (!$this->marketing_campaigns_model->can_reprepare_recipients($campaign)) {
            set_alert('warning', _l('marketing_campaign_reprepare_recipients_restricted'));
            redirect(admin_url('marketing/campaigns/show/' . $id));
        }

        $queuedRecipients = $this->marketing_campaigns_model->count_recipients_by_status($id, 'queued');
        if ($queuedRecipients === 0) {
            set_alert('warning', _l('marketing_campaign_reprepare_recipients_restricted'));
            redirect(admin_url('marketing/campaigns/show/' . $id));
        }

        $snapshot = $this->marketing_campaigns_model->get_campaign_targeting_snapshot($campaign);
        if ($this->should_require_prepare_license($snapshot) && !marketing_is_feature_allowed('recipient_prepare')) {
            set_alert('warning', marketing_license_block_message());
            redirect(admin_url('marketing/campaigns/show/' . $id));
        }

        $this->marketing_campaigns_model->record_operator_action('manual_reprepare_recipients_requested', [], $id, get_staff_user_id());
        $summary = $this->marketing_campaigns_model->sync_newly_eligible_recipients($id);

        if (is_array($summary) && !empty($summary['license_blocked'])) {
            set_alert('warning', $summary['license_message'] ?? marketing_license_block_message());
        } elseif ($summary === false) {
            set_alert('danger', _l('marketing_campaign_prepare_recipients_failure'));
        } elseif ((int) ($summary['added_count'] ?? 0) > 0) {
            set_alert('success', sprintf(_l('marketing_campaign_reprepare_recipients_success'), (int) $summary['added_count']));
        } else {
            set_alert('warning', _l('marketing_campaign_reprepare_recipients_none'));
        }

        redirect(admin_url('marketing/campaigns/show/' . $id));
    }

    public function schedule($id)
    {
        if (staff_cant('send', 'marketing')) {
            access_denied('marketing');
        }

        if (!$this->input->post()) {
            show_404();
        }

        $campaign = $this->marketing_campaigns_model->get($id);

        if (!$campaign) {
            show_404();
        }

        if (staff_cant('view', 'marketing') && staff_can('view_own', 'marketing')) {
            if ($campaign['created_by'] !== get_staff_user_id()) {
                access_denied('marketing');
            }
        }

        if (!in_array($campaign['status'], ['draft', 'scheduled'], true)) {
            set_alert('warning', _l('marketing_campaign_schedule_restricted'));
            redirect(admin_url('marketing/campaigns/show/' . $id));
        }

        $action = $this->input->post('action', true);

        if ($campaign['status'] === 'scheduled' && $action === 'draft') {
            $this->marketing_campaigns_model->update($id, ['status' => 'draft', 'scheduled_at' => null]);
            set_alert('success', _l('marketing_campaign_unschedule_success'));
            redirect(admin_url('marketing/campaigns/show/' . $id));
        }

        if ($campaign['status'] === 'scheduled' && $action === 'cancelled') {
            $this->marketing_campaigns_model->cancel_campaign($id);
            set_alert('success', _l('marketing_campaign_cancel_success'));
            redirect(admin_url('marketing/campaigns/show/' . $id));
        }

        $ready = $this->marketing_campaigns_model->validate_send_ready($id);

        if (!$ready['ready']) {
            $message = _l('marketing_campaign_schedule_missing_fields');
            if (!empty($ready['messages'])) {
                $message .= ' ' . implode(' ', $ready['messages']);
            }
            set_alert('danger', $message);
            redirect(admin_url('marketing/campaigns/show/' . $id));
        }

        $queuedRecipients = $this->marketing_campaigns_model->count_recipients_by_status($id, 'queued');

        if ($queuedRecipients === 0) {
            set_alert('danger', _l('marketing_campaign_schedule_no_recipients'));
            redirect(admin_url('marketing/campaigns/show/' . $id));
        }

        $scheduledAt = $this->normalize_scheduled_at($this->input->post('scheduled_at', true));

        if ($scheduledAt === false) {
            set_alert('danger', _l('marketing_campaign_scheduled_at_invalid'));
            redirect(admin_url('marketing/campaigns/show/' . $id));
        }

        if (!$this->is_future_datetime($scheduledAt)) {
            set_alert('danger', _l('marketing_campaign_scheduled_at_future_required'));
            redirect(admin_url('marketing/campaigns/show/' . $id));
        }

        if ($this->marketing_campaigns_model->schedule_campaign($id, $scheduledAt)) {
            set_alert('success', _l('marketing_campaign_schedule_success'));
        } else {
            set_alert('danger', _l('marketing_campaign_schedule_failure'));
        }

        redirect(admin_url('marketing/campaigns/show/' . $id));
    }

    public function process_queue($id = null)
    {
        if (staff_cant('send', 'marketing')) {
            access_denied('marketing');
        }

        if (!$this->input->post()) {
            show_404();
        }

        $campaign = null;
        if ($id !== null) {
            $campaign = $this->marketing_campaigns_model->get($id);
            if (!$campaign) {
                show_404();
            }

            if (staff_cant('view', 'marketing') && staff_can('view_own', 'marketing')) {
                if ($campaign['created_by'] !== get_staff_user_id()) {
                    access_denied('marketing');
                }
            }
        }

        if (get_option('marketing_send_enabled') != 1) {
            set_alert('warning', _l('marketing_campaign_send_disabled_exact'));
            if ($campaign !== null) {
                redirect(admin_url('marketing/campaigns/show/' . $campaign['id']));
            }

            redirect(admin_url('marketing/campaigns'));
        }

        if ($this->marketing_campaigns_model->is_queue_paused()) {
            set_alert('warning', 'Campaign queue is paused. Resume the queue before sending.');
            if ($campaign !== null) {
                redirect(admin_url('marketing/campaigns/show/' . $campaign['id']));
            }

            redirect(admin_url('marketing/campaigns'));
        }

        if ($campaign !== null) {
            $queuedRecipients = $this->marketing_campaigns_model->count_recipients_by_status($campaign['id'], 'queued');
            if ($queuedRecipients <= 0) {
                set_alert('warning', _l('marketing_campaign_prepare_before_send'));
                redirect(admin_url('marketing/campaigns/show/' . $campaign['id']));
            }
        }

        $limit = (int) get_option('marketing_send_per_cron_run');
        if ($limit <= 0) {
            $limit = 1;
        }
        if ($campaign !== null) {
            $ready = $this->marketing_campaigns_model->validate_send_ready($campaign['id']);
            if (!$ready['ready']) {
                $message = _l('marketing_campaign_process_queue_not_ready');
                if (!empty($ready['messages'])) {
                    $message .= ' ' . implode(' ', $ready['messages']);
                }
                set_alert('warning', $message);
                redirect(admin_url('marketing/campaigns/show/' . $campaign['id']));
            }
        }

        $this->marketing_campaigns_model->record_operator_action('manual_process_queue_requested', [], $id !== null ? (int) $id : null, get_staff_user_id());
        $summary = $this->marketing_campaigns_model->process_due_recipients($limit, $id);

        if ((int) $summary['processed'] > 0) {
            set_alert('success', $this->build_process_summary_message($summary));
        } else {
            $message = _l('marketing_campaign_process_queue_no_recipients');
            if (!empty($summary['warnings'])) {
                $message .= ' ' . implode(' ', $this->translate_process_warnings($summary['warnings']));
            }
            set_alert('info', $message);
        }

        if ($campaign !== null) {
            redirect(admin_url('marketing/campaigns/show/' . $campaign['id']));
        }

        redirect(admin_url('marketing/campaigns'));
    }

    public function upload_media()
    {
        if (staff_cant('create', 'marketing') && staff_cant('edit', 'marketing')) {
            access_denied('marketing');
        }

        if ($this->input->method() !== 'post') {
            $this->respond_json([
                'success' => false,
                'message' => _l('marketing_builder_upload_post_only'),
            ], 405);

            return;
        }

        if (empty($_FILES['builder_image']) || !is_uploaded_file($_FILES['builder_image']['tmp_name'])) {
            $this->respond_json([
                'success' => false,
                'message' => _l('marketing_builder_upload_missing_file'),
            ], 400);

            return;
        }

        $file = $_FILES['builder_image'];
        $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $maxBytes = 2 * 1024 * 1024;

        if (!in_array($extension, $allowedExtensions, true)) {
            $this->respond_json([
                'success' => false,
                'message' => _l('marketing_builder_upload_invalid_type'),
            ], 400);

            return;
        }

        if ((int) $file['size'] <= 0 || (int) $file['size'] > $maxBytes) {
            $this->respond_json([
                'success' => false,
                'message' => _l('marketing_builder_upload_too_large'),
            ], 400);

            return;
        }

        $imageInfo = @getimagesize($file['tmp_name']);
        if ($imageInfo === false || empty($imageInfo['mime']) || !in_array($imageInfo['mime'], $allowedMimeTypes, true)) {
            $this->respond_json([
                'success' => false,
                'message' => _l('marketing_builder_upload_invalid_type'),
            ], 400);

            return;
        }

        $uploadDir = FCPATH . 'uploads/marketing_media/';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
            $this->respond_json([
                'success' => false,
                'message' => _l('marketing_builder_upload_failed'),
            ], 500);

            return;
        }

        $storedName = uniqid('marketing_media_', true) . '.' . $extension;
        $storedPath = $uploadDir . $storedName;

        if (!move_uploaded_file($file['tmp_name'], $storedPath)) {
            $this->respond_json([
                'success' => false,
                'message' => _l('marketing_builder_upload_failed'),
            ], 500);

            return;
        }

        $this->respond_json([
            'success' => true,
            'url' => site_url('uploads/marketing_media/' . rawurlencode($storedName)),
            'csrf' => [
                'name' => $this->security->get_csrf_token_name(),
                'hash' => $this->security->get_csrf_hash(),
            ],
        ]);
    }

    protected function save_campaign($id = null)
    {
        $deliveryChannel = $this->marketing_campaigns_model->normalize_delivery_channel($this->input->post('delivery_channel', true));

        $this->form_validation->set_rules('name', _l('marketing_name'), 'required|trim');
        if ($deliveryChannel === 'email') {
            $this->form_validation->set_rules('subject', _l('marketing_subject'), 'required|trim');
            $this->form_validation->set_rules('html_content', _l('marketing_campaign_html_content'), 'required');
        }
        $this->form_validation->set_rules('list_id', _l('marketing_campaign_list'), 'required|integer');
        $this->form_validation->set_rules('from_email', _l('marketing_campaign_from_email'), 'trim|valid_email');
        $this->form_validation->set_rules('reply_to_email', _l('marketing_campaign_reply_to_email'), 'trim|valid_email');

        $htmlContent = $this->sanitize_campaign_html((string) $this->input->post('html_content', false));
        $builderState = trim((string) $this->input->post('builder_state', false));
        $validationMessages = [];

        if ($this->form_validation->run() === false) {
            if ($deliveryChannel === 'email' && trim((string) $this->input->post('html_content', false)) === '') {
                $validationMessages[] = _l('marketing_campaign_email_content_required');
            }

            $rawValidationMessages = preg_split('/\r?\n+/', trim(strip_tags((string) validation_errors("\n", "\n"))));
            foreach ($rawValidationMessages as $rawValidationMessage) {
                $rawValidationMessage = trim((string) $rawValidationMessage);
                if ($rawValidationMessage === '' || stripos($rawValidationMessage, 'HTML Content field is required') !== false) {
                    continue;
                }

                if (!in_array($rawValidationMessage, $validationMessages, true)) {
                    $validationMessages[] = $rawValidationMessage;
                }
            }
        }

        if ($deliveryChannel === 'email' && !$this->campaign_has_unsubscribe_token($htmlContent)) {
            $validationMessages[] = _l('marketing_campaign_unsubscribe_save_required');
        }

        if ($deliveryChannel === 'whatsapp') {
            if (trim((string) $this->input->post('whatsapp_template_name', true)) === '') {
                $validationMessages[] = _l('marketing_campaign_whatsapp_template_required');
            }

            if (trim((string) $this->input->post('whatsapp_template_language', true)) === '') {
                $validationMessages[] = _l('marketing_campaign_whatsapp_template_language_required');
            }
        }

        $validationMessages = array_values(array_unique(array_filter(array_map('trim', $validationMessages))));
        if (!empty($validationMessages)) {
            $this->saveCampaignErrorMessage = $validationMessages;
            set_alert('danger', implode(' ', $validationMessages));
            return false;
        }

        $textContent = $this->normalize_campaign_text_content($this->input->post('text_content', true));
        if ($textContent === '') {
            $textContent = $this->normalize_campaign_text_content($this->generate_plain_text_from_html($htmlContent));
        }

        $targetingRules = $this->get_posted_targeting_rules();
        $builderState = $this->marketing_campaigns_model->embed_targeting_rules_in_builder_state($builderState, $targetingRules);
        $scheduleMode = $this->input->post('schedule_mode', true) === 'later' ? 'later' : 'now';
        $scheduledAt = $this->normalize_scheduled_at($this->input->post('scheduled_at', true));

        if ($scheduleMode === 'later') {
            if ($scheduledAt === false) {
                $this->saveCampaignErrorMessage = [_l('marketing_campaign_scheduled_at_invalid')];
                set_alert('danger', implode(' ', $this->saveCampaignErrorMessage));
                return false;
            }

            if (!$this->is_future_datetime($scheduledAt)) {
                $this->saveCampaignErrorMessage = [_l('marketing_campaign_scheduled_at_future_required')];
                set_alert('danger', implode(' ', $this->saveCampaignErrorMessage));
                return false;
            }
        } else {
            $scheduledAt = null;
        }

        $data = [
            'name'           => $this->input->post('name', true),
            'subject'        => $deliveryChannel === 'whatsapp'
                ? trim((string) $this->input->post('subject', true))
                : $this->input->post('subject', true),
            'preview_text'   => $this->input->post('preview_text', true),
            'from_name'      => $this->input->post('from_name', true),
            'from_email'     => $this->normalize_email($this->input->post('from_email', true)),
            'reply_to_email' => $this->normalize_email($this->input->post('reply_to_email', true)),
            'list_id'        => (int) $this->input->post('list_id'),
            'status'         => $scheduleMode === 'later' ? 'scheduled' : 'draft',
            'delivery_channel' => $deliveryChannel,
            'whatsapp_template_name' => trim((string) $this->input->post('whatsapp_template_name', true)),
            'whatsapp_template_language' => trim((string) $this->input->post('whatsapp_template_language', true)),
            'whatsapp_template_var1' => trim((string) $this->input->post('whatsapp_template_var1', true)),
            'whatsapp_template_var2' => trim((string) $this->input->post('whatsapp_template_var2', true)),
            'whatsapp_template_var3' => trim((string) $this->input->post('whatsapp_template_var3', true)),
            'editor_type'    => 'builder',
            'builder_state'  => $builderState,
            'html_content'   => $this->embed_builder_state_metadata($htmlContent, $builderState),
            'text_content'   => $deliveryChannel === 'whatsapp' ? trim((string) $textContent) : $textContent,
            'track_opens'    => $deliveryChannel === 'email' && $this->input->post('track_opens') ? 1 : 0,
            'track_clicks'   => $deliveryChannel === 'email' && $this->input->post('track_clicks') ? 1 : 0,
            'scheduled_at'   => $scheduledAt,
        ];

        $targetingStorageColumn = $this->marketing_campaigns_model->get_campaign_targeting_storage_column();
        if ($targetingStorageColumn !== null) {
            $data[$targetingStorageColumn] = json_encode($targetingRules);
        }

        if (!$this->marketing_audiences_model->list_exists($data['list_id'])) {
            $this->saveCampaignErrorMessage = [_l('marketing_campaign_list_required')];
            set_alert('danger', implode(' ', $this->saveCampaignErrorMessage));
            return false;
        }

        if ($id) {
            if ($this->marketing_campaigns_model->update($id, $data)) {
                set_alert('success', _l('marketing_campaign_saved'));
                return (int) $id;
            }
        } else {
            $createdId = (int) $this->marketing_campaigns_model->create($data);
            if ($createdId > 0) {
                set_alert('success', _l('marketing_campaign_saved'));
                return $createdId;
            }
        }

        $this->saveCampaignErrorMessage = [_l('marketing_campaign_save_error')];
        set_alert('danger', implode(' ', $this->saveCampaignErrorMessage));
        return false;
    }

    private function campaign_has_unsubscribe_token($htmlContent)
    {
        $htmlContent = (string) $htmlContent;

        return strpos($htmlContent, '{{unsubscribe_url}}') !== false
            || stripos($htmlContent, '%7B%7Bunsubscribe_url%7D%7D') !== false
            || strpos($htmlContent, '&#123;&#123;unsubscribe_url&#125;&#125;') !== false
            || strpos($htmlContent, '&lcub;&lcub;unsubscribe_url&rcub;&rcub;') !== false;
    }

    protected function get_default_campaign()
    {
        return [
            'id'            => '',
            'name'          => '',
            'subject'       => '',
            'preview_text'  => '',
            'from_name'     => '',
            'from_email'    => '',
            'reply_to_email'=> '',
            'list_id'       => '',
            'status'        => 'draft',
            'delivery_channel' => 'email',
            'whatsapp_template_name' => '',
            'whatsapp_template_language' => 'en_US',
            'whatsapp_template_var1' => '',
            'whatsapp_template_var2' => '',
            'whatsapp_template_var3' => '',
            'html_content'  => '',
            'builder_state' => '',
            'text_content'  => '',
            'track_opens'   => $this->get_default_tracking_setting('marketing_track_opens', 1),
            'track_clicks'  => $this->get_default_tracking_setting('marketing_track_clicks', 1),
            'scheduled_at'  => '',
            'schedule_mode' => 'now',
            'targeting_rules' => $this->marketing_campaigns_model->get_default_targeting_rules(),
        ];
    }

    protected function get_default_tracking_setting($optionName, $fallback = 1)
    {
        $value = get_option($optionName);
        if ($value === '' || $value === null) {
            return (int) $fallback;
        }

        return (string) $value === '1' ? 1 : 0;
    }

    protected function get_posted_campaign()
    {
        $htmlContent = (string) $this->input->post('html_content', false);
        $builderState = (string) $this->input->post('builder_state', false);

        if (trim($builderState) === '' && strpos($htmlContent, self::BUILDER_STATE_MARKER_PREFIX) !== false) {
            $builderState = $this->extract_builder_state_metadata($htmlContent);
            $htmlContent = $this->strip_builder_state_metadata($htmlContent);
        }

        $targetingRules = $this->get_posted_targeting_rules();
        $builderState = $this->marketing_campaigns_model->strip_targeting_rules_from_builder_state($builderState);

        return [
            'name'          => $this->input->post('name', true),
            'subject'       => $this->input->post('subject', true),
            'preview_text'  => $this->input->post('preview_text', true),
            'from_name'     => $this->input->post('from_name', true),
            'from_email'    => $this->input->post('from_email', true),
            'reply_to_email'=> $this->input->post('reply_to_email', true),
            'list_id'       => (int) $this->input->post('list_id'),
            'status'        => $this->input->post('status', true),
            'delivery_channel' => $this->marketing_campaigns_model->normalize_delivery_channel($this->input->post('delivery_channel', true)),
            'whatsapp_template_name' => trim((string) $this->input->post('whatsapp_template_name', true)),
            'whatsapp_template_language' => trim((string) $this->input->post('whatsapp_template_language', true)),
            'whatsapp_template_var1' => trim((string) $this->input->post('whatsapp_template_var1', true)),
            'whatsapp_template_var2' => trim((string) $this->input->post('whatsapp_template_var2', true)),
            'whatsapp_template_var3' => trim((string) $this->input->post('whatsapp_template_var3', true)),
            'html_content'  => $htmlContent,
            'builder_state' => $builderState,
            'text_content'  => $this->normalize_campaign_text_content($this->input->post('text_content', true)),
            'track_opens'   => $this->input->post('track_opens') ? 1 : 0,
            'track_clicks'  => $this->input->post('track_clicks') ? 1 : 0,
            'scheduled_at'  => $this->input->post('scheduled_at', true),
            'schedule_mode' => $this->input->post('schedule_mode', true) === 'later' ? 'later' : 'now',
            'targeting_rules' => $targetingRules,
        ];
    }

    protected function normalize_email($email)
    {
        $email = trim($email);

        if ($email === '') {
            return null;
        }

        return mb_strtolower($email);
    }

    protected function normalize_scheduled_at($scheduledAt)
    {
        if ($scheduledAt === null || trim($scheduledAt) === '') {
            return null;
        }

        $timestamp = strtotime($scheduledAt);

        if ($timestamp === false) {
            return false;
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    private function is_future_datetime($scheduledAt)
    {
        if ($scheduledAt === null || $scheduledAt === '') {
            return false;
        }

        $timestamp = strtotime((string) $scheduledAt);
        if ($timestamp === false) {
            return false;
        }

        return $timestamp > time();
    }

    protected function sanitize_preview_html($html)
    {
        $html = $this->strip_builder_state_metadata($html);

        if (trim($html) === '') {
            return '';
        }

        $sanitized = preg_replace('#<script\b[^>]*>(.*?)</script>#is', '', $html);
        $sanitized = preg_replace('#<\s*/?\s*iframe\b[^>]*>#i', '', $sanitized);
        $sanitized = preg_replace('#on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $sanitized);
        $sanitized = preg_replace('#(href|src)\s*=\s*("|\')?\s*javascript:[^"\'>\s]+#i', '$1="#"', $sanitized);
        $sanitized = preg_replace('#(href|src)\s*=\s*("|\')?\s*vbscript:[^"\'>\s]+#i', '$1="#"', $sanitized);

        return $sanitized;
    }

    private function hydrate_builder_campaign($campaign)
    {
        if (!$campaign) {
            return $campaign;
        }

        $storedHtml = (string) ($campaign['html_content'] ?? '');
        $campaign['builder_state'] = !empty($campaign['builder_state'])
            ? (string) $campaign['builder_state']
            : $this->extract_builder_state_metadata($storedHtml);
        $campaign['targeting_rules'] = $this->marketing_campaigns_model->get_campaign_targeting_rules($campaign);
        $campaign['builder_state'] = $this->marketing_campaigns_model->strip_targeting_rules_from_builder_state($campaign['builder_state']);
        $campaign['html_content'] = $this->strip_builder_state_metadata($storedHtml);
        $campaign['text_content'] = $this->normalize_campaign_text_content($campaign['text_content'] ?? '');
        $campaign['schedule_mode'] = !empty($campaign['scheduled_at']) && $campaign['status'] === 'scheduled' ? 'later' : 'now';
        $campaign['delivery_channel'] = $this->marketing_campaigns_model->normalize_delivery_channel($campaign['delivery_channel'] ?? 'email');
        $campaign['whatsapp_template_name'] = (string) ($campaign['whatsapp_template_name'] ?? '');
        $campaign['whatsapp_template_language'] = (string) ($campaign['whatsapp_template_language'] ?? 'en_US');
        $campaign['whatsapp_template_var1'] = (string) ($campaign['whatsapp_template_var1'] ?? '');
        $campaign['whatsapp_template_var2'] = (string) ($campaign['whatsapp_template_var2'] ?? '');
        $campaign['whatsapp_template_var3'] = (string) ($campaign['whatsapp_template_var3'] ?? '');

        return $campaign;
    }

    private function get_posted_targeting_rules()
    {
        return $this->marketing_campaigns_model->normalize_targeting_rules([
            'send_mode' => $this->input->post('targeting_send_mode', true),
            'contact_status' => $this->input->post('targeting_contact_status', true),
            'consent_status' => $this->input->post('targeting_consent_status', true),
            'company_contains' => $this->input->post('targeting_company_contains', true),
            'email_contains' => $this->input->post('targeting_email_contains', true),
            'email_domain' => $this->input->post('targeting_email_domain', true),
            'source_type' => $this->input->post('targeting_source_type', true),
            'created_from' => $this->input->post('targeting_created_from', true),
            'created_to' => $this->input->post('targeting_created_to', true),
        ]);
    }

    private function embed_builder_state_metadata($html, $builderState)
    {
        $cleanHtml = $this->strip_builder_state_metadata((string) $html);
        $builderState = trim((string) $builderState);

        if ($builderState === '') {
            return $cleanHtml;
        }

        return self::BUILDER_STATE_MARKER_PREFIX . base64_encode($builderState) . self::BUILDER_STATE_MARKER_SUFFIX . $cleanHtml;
    }

    private function extract_builder_state_metadata($html)
    {
        $html = (string) $html;

        if (preg_match('/<!--marketing_builder_state:([A-Za-z0-9+\/=]+)-->/i', $html, $matches)) {
            $decoded = base64_decode($matches[1], true);

            return $decoded !== false ? $decoded : '';
        }

        return '';
    }

    private function strip_builder_state_metadata($html)
    {
        return preg_replace('/<!--marketing_builder_state:[A-Za-z0-9+\/=]+-->\s*/i', '', (string) $html);
    }

    protected function sanitize_campaign_html($html)
    {
        $html = trim((string) $html);
        if ($html === '') {
            return '';
        }

        $html = preg_replace('#<script\b[^>]*>(.*?)</script>#is', '', $html);
        $html = preg_replace('#<iframe\b[^>]*>.*?</iframe>#is', '', $html);
        $html = preg_replace('#<\s*/?\s*iframe\b[^>]*>#i', '', $html);
        $html = preg_replace('#on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html);
        $html = preg_replace('#(href|src)\s*=\s*("|\')?\s*javascript:[^"\'>\s]+#i', '$1="#"', $html);
        $html = preg_replace('#(href|src)\s*=\s*("|\')?\s*vbscript:[^"\'>\s]+#i', '$1="#"', $html);

        return $html;
    }

    protected function generate_plain_text_from_html($html)
    {
        $html = preg_replace('/<\s*br\s*\/?>/i', "\n", (string) $html);
        $html = preg_replace('/<\/p>/i', "\n\n", $html);
        $html = preg_replace('/<\/div>/i', "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8');
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }

    private function normalize_campaign_text_content($text)
    {
        $text = trim((string) $text);

        return $this->is_unsafe_default_plain_text($text) ? '' : $text;
    }

    private function is_unsafe_default_plain_text($text)
    {
        $normalized = preg_replace('/\s+/', ' ', html_entity_decode(trim((string) $text), ENT_QUOTES, 'UTF-8'));

        foreach (self::UNSAFE_DEFAULT_PLAIN_TEXT_SIGNATURES as $signature) {
            if ($normalized === $signature) {
                return true;
            }
        }

        return false;
    }

    protected function respond_json(array $data, $statusCode = 200)
    {
        $this->output
            ->set_content_type('application/json')
            ->set_status_header((int) $statusCode)
            ->set_output(json_encode($data));
    }

    protected function build_prepare_summary_message(array $summary)
    {
        $message = sprintf(
            _l('marketing_campaign_prepare_recipients_success'),
            (int) $summary['queued'],
            (int) $summary['duplicate'],
            (int) $summary['invalid'],
            (int) $summary['suppressed'],
            (int) $summary['inactive'],
            (int) $summary['unsubscribed']
        );

        if ((int) ($summary['missing_phone'] ?? 0) > 0) {
            $message .= ' ' . sprintf(_l('marketing_campaign_whatsapp_missing_phone_warning'), (int) $summary['missing_phone']);
        }

        return $message;
    }

    protected function build_process_summary_message(array $summary)
    {
        return sprintf(
            _l('marketing_campaign_process_queue_success'),
            (int) $summary['accepted'],
            (int) $summary['failed'],
            (int) $summary['recovered']
        );
    }

    protected function translate_process_warnings(array $warnings)
    {
        $messages = [];

        foreach ($warnings as $warning) {
            switch ($warning) {
                case 'send_disabled':
                    $messages[] = _l('marketing_campaign_send_disabled_exact');
                    break;
                case 'queue_paused':
                    $messages[] = 'Campaign queue is paused.';
                    break;
                case 'hourly_cap_reached':
                    $messages[] = _l('marketing_campaign_hourly_cap_reached');
                    break;
                case 'daily_cap_reached':
                    $messages[] = _l('marketing_campaign_daily_cap_reached');
                    break;
                case 'rate_limit_exhausted':
                    $messages[] = 'Campaign sending is paused because the configured rate limit is exhausted.';
                    break;
                case 'license_inactive':
                    $messages[] = _l('marketing_license_inactive_alert');
                    break;
                case 'outside_send_window':
                    $messages[] = _l('marketing_campaign_outside_send_window');
                    break;
                case 'campaign_not_due':
                    $messages[] = _l('marketing_campaign_not_due');
                    break;
                case 'campaign_not_processable':
                    $messages[] = _l('marketing_campaign_not_processable');
                    break;
                case 'campaign_not_found':
                    $messages[] = _l('marketing_campaign_not_found');
                    break;
                case 'cron_overlap_detected':
                case 'campaign_worker_locked':
                    $messages[] = 'Another queue worker is already processing this campaign.';
                    break;
                case 'stale_worker_recovered':
                    $messages[] = 'A stale campaign worker lease was recovered safely.';
                    break;
                case 'stale_recipient_processing_recovered':
                    $messages[] = 'Stale recipient processing rows were recovered safely.';
                    break;
                case 'stale_processing_recovered_with_outbound_attempt':
                    $messages[] = 'Recovered stale recipients were re-queued after preserving late-event reconciliation safety.';
                    break;
                default:
                    if (strpos($warning, 'campaign_') === 0 && substr($warning, -10) === '_not_ready') {
                        $messages[] = _l('marketing_campaign_process_queue_not_ready');
                    } elseif (strpos($warning, 'campaign_') === 0 && substr($warning, -20) === '_no_queued_recipients') {
                        $messages[] = _l('marketing_campaign_schedule_no_recipients');
                    }
                    break;
            }
        }

        return array_values(array_unique(array_filter($messages)));
    }

    private function format_send_warnings(array $warnings)
    {
        $messages = [];

        foreach ($warnings as $warning) {
            if ($warning === 'send_disabled') {
                $messages[] = 'Campaign delivery is disabled in Marketing Settings.';
            } elseif ($warning === 'queue_paused') {
                $messages[] = 'Campaign queue is paused. Resume the queue before sending.';
            } elseif ($warning === 'campaign_not_found') {
                $messages[] = 'Campaign not found.';
            } elseif ($warning === 'campaign_not_processable') {
                $messages[] = 'Campaign status is not processable.';
            } elseif ($warning === 'license_inactive') {
                $messages[] = marketing_license_block_message();
            } elseif ($warning === 'outside_send_window') {
                $messages[] = 'Campaign sending is paused because the current time is outside the configured send window.';
            } elseif ($warning === 'hourly_cap_reached') {
                $messages[] = 'Hourly sending cap reached.';
            } elseif ($warning === 'daily_cap_reached') {
                $messages[] = 'Daily sending cap reached.';
            } elseif ($warning === 'rate_limit_exhausted') {
                $messages[] = 'Campaign sending is paused because the configured rate limit is exhausted.';
            } elseif ($warning === 'cron_overlap_detected' || $warning === 'campaign_worker_locked') {
                $messages[] = 'Another queue worker is already processing this campaign.';
            } elseif ($warning === 'stale_worker_recovered') {
                $messages[] = 'A stale campaign worker lease was recovered safely.';
            } elseif ($warning === 'stale_recipient_processing_recovered') {
                $messages[] = 'Stale recipient processing rows were recovered safely.';
            } elseif ($warning === 'stale_processing_recovered_with_outbound_attempt') {
                $messages[] = 'Recovered stale recipients were re-queued after preserving late-event reconciliation safety.';
            } elseif (strpos($warning, 'campaign_') === 0 && substr($warning, -10) === '_not_ready') {
                $messages[] = 'Campaign is not ready to send. Check subject, HTML content, unsubscribe link, image URLs, SMTP settings, and audience.';
            } elseif (strpos($warning, 'campaign_') === 0 && substr($warning, -20) === '_no_queued_recipients') {
                $messages[] = 'No recipients queued. Please prepare recipients first.';
            }
        }

        if (empty($messages)) {
            $messages[] = 'Campaign could not be sent. Please review campaign readiness.';
        }

        return array_values(array_unique(array_filter($messages)));
    }

    private function marketing_option_enabled($key)
    {
        $value = strtolower(trim((string) get_option($key)));

        return in_array($value, ['1', 'yes', 'true', 'on'], true);
    }

    private function get_campaign_send_license_feature(array $campaign)
    {
        return $this->marketing_campaigns_model->is_whatsapp_campaign($campaign)
            ? 'whatsapp_campaign_send'
            : 'campaign_send';
    }

    private function should_require_prepare_license(array $snapshot)
    {
        $eligibleCount = isset($snapshot['eligible_contacts']) && is_array($snapshot['eligible_contacts'])
            ? count($snapshot['eligible_contacts'])
            : 0;
        $threshold = defined('MARKETING_COMMERCIAL_PREPARE_THRESHOLD')
            ? (int) MARKETING_COMMERCIAL_PREPARE_THRESHOLD
            : 100;

        return $eligibleCount >= max(1, $threshold);
    }


}
