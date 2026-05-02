<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Subscriptions extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();

        $this->load->model('marketing/Marketing_campaigns_model', 'marketing_campaigns_model');
        $this->load->model('marketing/Marketing_audiences_model', 'marketing_audiences_model');
        $this->load->model('marketing/Marketing_suppressions_model', 'marketing_suppressions_model');
        $this->load->model('marketing/Marketing_events_model', 'marketing_events_model');
        $this->load->library('marketing/Marketing_unsubscribe');
    }

    public function unsubscribe($token = null)
    {
        $message = _l('marketing_unsubscribe_invalid');
        $success = false;

        $payload = $this->marketing_unsubscribe->verify_token($token);
        if ($payload !== null) {
            $recipient = $this->marketing_campaigns_model->get_recipient_by_id($payload['recipient_id']);

            if ($recipient && (int) $recipient['campaign_id'] === $payload['campaign_id']
                && (int) $recipient['marketing_contact_id'] === $payload['contact_id']
                && mb_strtolower(trim((string) $recipient['email'])) === $payload['email']) {
                $campaign = $this->marketing_campaigns_model->get($recipient['campaign_id']);
                $contact = $this->marketing_audiences_model->get_contact($recipient['marketing_contact_id']);

                if ($contact) {
                    $this->marketing_audiences_model->update_contact($contact['id'], [
                        'status' => 'unsubscribed',
                        'consent_status' => 'unsubscribed',
                    ]);
                }

                if ($campaign && !empty($campaign['list_id'])) {
                    $this->marketing_audiences_model->remove_member((int) $campaign['list_id'], (int) $recipient['marketing_contact_id']);
                }

                $this->marketing_suppressions_model->upsert_suppression($recipient['email'], 'unsubscribe');

                $this->marketing_events_model->add_event([
                    'campaign_id' => (int) $recipient['campaign_id'],
                    'campaign_recipient_id' => (int) $recipient['id'],
                    'marketing_contact_id' => (int) $recipient['marketing_contact_id'],
                    'event_type' => 'unsubscribe',
                    'provider' => 'marketing_unsubscribe',
                    'email' => $recipient['email'],
                    'event_at' => date('Y-m-d H:i:s'),
                    'payload_json' => json_encode(['token' => 'verified']),
                ]);

                $message = _l('marketing_unsubscribe_success');
                $success = true;
            }
        }

        $data['title'] = _l('marketing_unsubscribe_title');
        $data['message'] = $message;
        $data['success'] = $success;

        $this->load->view('marketing/subscriptions/unsubscribe_result', $data);
    }
}
