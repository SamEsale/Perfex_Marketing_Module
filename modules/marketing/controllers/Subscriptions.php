<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Subscriptions extends CI_Controller
{
    private const UNSUBSCRIBE_CONFIRM_VALUE = 'confirm';

    public function __construct()
    {
        parent::__construct();

        if (function_exists('marketing_bootstrap_safe_schema')) {
            marketing_bootstrap_safe_schema();
        }

        $this->load->model('marketing/Marketing_campaigns_model', 'marketing_campaigns_model');
        $this->load->model('marketing/Marketing_audiences_model', 'marketing_audiences_model');
        $this->load->model('marketing/Marketing_suppressions_model', 'marketing_suppressions_model');
        $this->load->model('marketing/Marketing_events_model', 'marketing_events_model');
        $this->load->model('marketing/Marketing_public_links_model', 'marketing_public_links_model');
        $this->load->library('marketing/Marketing_unsubscribe');
    }

    public function unsubscribe($token = null)
    {
        $payload = $this->marketing_unsubscribe->verify_token($token);
        $context = $payload !== null ? $this->build_unsubscribe_context($payload) : null;
        $requestMethod = strtoupper((string) $this->input->method(true));

        $data = [
            'title' => _l('marketing_unsubscribe_title'),
            'mode' => 'result',
            'success' => false,
            'message' => _l('marketing_unsubscribe_invalid'),
            'status_label' => _l('marketing_unsubscribe_status_error'),
            'unsubscribe_url' => site_url('marketing/unsubscribe/' . rawurlencode((string) $token)),
            'token' => (string) $token,
            'email' => $context['normalized_email'] ?? '',
            'campaign_name' => $context['campaign']['name'] ?? '',
            'csrf_name' => $this->security->get_csrf_token_name(),
            'csrf_hash' => $this->security->get_csrf_hash(),
        ];

        if ($payload === null || $context === null) {
            $this->load->view('marketing/subscriptions/unsubscribe_result', $data);
            return;
        }

        if ($requestMethod !== 'POST') {
            $data['mode'] = 'confirm';
            $data['message'] = _l('marketing_unsubscribe_confirm_message');
            $data['status_label'] = _l('marketing_unsubscribe_status_pending');
            $data['title'] = _l('marketing_unsubscribe_confirm_title');
            $this->load->view('marketing/subscriptions/unsubscribe_result', $data);
            return;
        }

        $confirmation = trim((string) $this->input->post('unsubscribe_confirmation', false));
        if ($confirmation !== self::UNSUBSCRIBE_CONFIRM_VALUE) {
            $data['mode'] = 'confirm';
            $data['title'] = _l('marketing_unsubscribe_confirm_title');
            $data['message'] = _l('marketing_unsubscribe_confirm_required');
            $data['status_label'] = _l('marketing_unsubscribe_status_pending');
            $this->load->view('marketing/subscriptions/unsubscribe_result', $data);
            return;
        }

        $result = $this->apply_confirmed_unsubscribe($payload, $context);
        $data['message'] = !empty($result['success']) ? _l('marketing_unsubscribe_success') : _l('marketing_unsubscribe_invalid');
        $data['status_label'] = !empty($result['success']) ? _l('marketing_unsubscribe_status_success') : _l('marketing_unsubscribe_status_error');
        $data['success'] = !empty($result['success']);

        $this->load->view('marketing/subscriptions/unsubscribe_result', $data);
    }

    protected function build_unsubscribe_context(array $payload)
    {
        $recipientId = (int) ($payload['recipient_id'] ?? 0);
        $campaignId = (int) ($payload['campaign_id'] ?? 0);
        $contactId = (int) ($payload['contact_id'] ?? 0);
        $normalizedEmail = mb_strtolower(trim((string) ($payload['email'] ?? '')));
        $recipient = $recipientId > 0 ? $this->marketing_campaigns_model->get_recipient_by_id($recipientId) : null;

        if ($recipient) {
            if ($campaignId <= 0) {
                $campaignId = (int) ($recipient['campaign_id'] ?? 0);
            }
            if ($contactId <= 0) {
                $contactId = (int) ($recipient['marketing_contact_id'] ?? 0);
            }
            if ($normalizedEmail === '') {
                $normalizedEmail = mb_strtolower(trim((string) ($recipient['email'] ?? '')));
            }
        }

        $campaign = $campaignId > 0 ? $this->marketing_campaigns_model->get($campaignId) : null;
        $contact = $contactId > 0
            ? $this->marketing_audiences_model->get_contact($contactId)
            : ($normalizedEmail !== '' ? $this->marketing_audiences_model->get_contact_by_normalized_email($normalizedEmail) : null);

        if ($contact) {
            $contactId = (int) $contact['id'];
            if ($normalizedEmail === '') {
                $normalizedEmail = mb_strtolower(trim((string) ($contact['normalized_email'] ?? ($contact['email'] ?? ''))));
            }
        }

        return [
            'recipient_id' => $recipientId,
            'campaign_id' => $campaignId,
            'contact_id' => $contactId,
            'normalized_email' => $normalizedEmail,
            'recipient' => $recipient,
            'campaign' => $campaign,
            'contact' => $contact,
        ];
    }

    protected function apply_confirmed_unsubscribe(array $payload, array $context)
    {
        $recipientId = (int) ($context['recipient_id'] ?? 0);
        $campaignId = (int) ($context['campaign_id'] ?? 0);
        $contactId = (int) ($context['contact_id'] ?? 0);
        $normalizedEmail = (string) ($context['normalized_email'] ?? '');
        $campaign = $context['campaign'] ?? null;
        $listMembershipChanged = false;

        if ($contactId > 0) {
            $this->marketing_audiences_model->mark_contact_unsubscribed($contactId);
        }

        if ($campaign && !empty($campaign['list_id']) && $contactId > 0) {
            $listMembershipChanged = $this->marketing_audiences_model->mark_list_membership_unsubscribed((int) $campaign['list_id'], $contactId);
        }

        $suppressionId = false;
        if ($normalizedEmail !== '') {
            $suppressionId = $this->marketing_suppressions_model->upsert_suppression(
                $normalizedEmail,
                'unsubscribe',
                null,
                'marketing_unsubscribe'
            );
        }

        if (!empty($payload['link_id'])) {
            $this->marketing_public_links_model->touch_token_use((int) $payload['link_id']);
        }

        $hasActiveSuppression = $normalizedEmail !== ''
            && ($suppressionId || $this->marketing_suppressions_model->get_active_suppression($normalizedEmail) !== null);

        $unsubscribeApplied = $hasActiveSuppression || $contactId > 0 || $listMembershipChanged;

        if (!$unsubscribeApplied) {
            return ['success' => false];
        }

        $eventAt = date('Y-m-d H:i:s');
        if (!$this->marketing_events_model->has_marketing_unsubscribe_event([
            'campaign_recipient_id' => $recipientId > 0 ? $recipientId : null,
            'marketing_contact_id' => $contactId > 0 ? $contactId : null,
            'email' => $normalizedEmail,
            'public_link_id' => (int) ($payload['link_id'] ?? 0),
        ])) {
            $this->marketing_events_model->add_event([
                'campaign_id' => $campaignId > 0 ? $campaignId : null,
                'campaign_recipient_id' => $recipientId > 0 ? $recipientId : null,
                'marketing_contact_id' => $contactId > 0 ? $contactId : null,
                'event_type' => 'unsubscribe',
                'provider' => 'marketing_unsubscribe',
                'email' => $normalizedEmail,
                'event_at' => $eventAt,
                'payload_json' => json_encode([
                    'token_type' => (string) ($payload['token_type'] ?? 'unknown'),
                    'public_link_id' => (int) ($payload['link_id'] ?? 0),
                    'list_id' => $campaign && !empty($campaign['list_id']) ? (int) $campaign['list_id'] : 0,
                    'list_membership_unsubscribed' => $listMembershipChanged,
                ]),
            ]);
        }

        if ($recipientId > 0) {
            $this->marketing_campaigns_model->update_recipient_record($recipientId, [
                'last_event_at' => $eventAt,
            ]);
        }

        return ['success' => true];
    }
}
