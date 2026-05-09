<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Track extends CI_Controller
{
    private const UNIQUE_CLICK_WINDOW_SECONDS = 120;

    public function __construct()
    {
        parent::__construct();

        if (function_exists('marketing_bootstrap_safe_schema')) {
            marketing_bootstrap_safe_schema();
        }

        $this->load->model('marketing/Marketing_public_links_model', 'marketing_public_links_model');
        $this->load->model('marketing/Marketing_campaigns_model', 'marketing_campaigns_model');
        $this->load->model('marketing/Marketing_events_model', 'marketing_events_model');
    }

    public function c($token = null)
    {
        $link = $this->marketing_public_links_model->resolve_click_token($token);
        if (!$link || empty($link['original_url'])) {
            show_404();

            return;
        }

        $eventAt = date('Y-m-d H:i:s');
        $campaignId = !empty($link['campaign_id']) ? (int) $link['campaign_id'] : null;
        $recipientId = !empty($link['campaign_recipient_id']) ? (int) $link['campaign_recipient_id'] : null;
        $contactId = !empty($link['marketing_contact_id']) ? (int) $link['marketing_contact_id'] : null;
        $ipAddress = $this->sanitizeIpAddress($this->input->ip_address());
        $userAgent = $this->sanitizeUserAgent($this->input->user_agent());
        $requestMethod = strtoupper((string) $this->input->method(true));
        $isBot = $this->is_obvious_automated_click($userAgent, $requestMethod);
        $countHumanEngagement = !$isBot && !$this->marketing_events_model->has_recent_module_click([
            'campaign_recipient_id' => $recipientId,
            'tracking_link_id' => (int) $link['id'],
            'url' => (string) $link['original_url'],
            'event_at' => $eventAt,
        ], self::UNIQUE_CLICK_WINDOW_SECONDS);

        $this->marketing_public_links_model->record_click_activity((int) $link['id'], [
            'increment_total' => true,
            'is_human' => !$isBot,
            'is_unique_human_click' => $countHumanEngagement,
        ]);
        $this->marketing_events_model->add_event([
            'campaign_id' => $campaignId,
            'campaign_recipient_id' => $recipientId,
            'marketing_contact_id' => $contactId,
            'event_type' => 'click',
            'provider' => 'module',
            'email' => !empty($link['normalized_email']) ? (string) $link['normalized_email'] : null,
            'url' => (string) $link['original_url'],
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'event_at' => $eventAt,
            'payload_json' => json_encode([
                'tracking_type' => 'module_click',
                'tracking_link_id' => (int) $link['id'],
                'original_url' => (string) $link['original_url'],
                'is_bot' => $isBot,
                'is_unique_click' => $countHumanEngagement,
                'counted_metric' => $countHumanEngagement,
                'request_method' => $requestMethod,
            ]),
        ]);

        if ($recipientId !== null && !$isBot) {
            $this->marketing_campaigns_model->apply_recipient_event($recipientId, [
                'event_type' => 'click',
                'url' => (string) $link['original_url'],
                'event_at' => $eventAt,
                'count_metric' => $countHumanEngagement,
                'count_unique_metric' => $countHumanEngagement,
                'provider' => 'module',
            ]);
        }

        redirect((string) $link['original_url'], 'location', 302);
    }

    protected function sanitizeIpAddress($ipAddress)
    {
        $ipAddress = trim((string) $ipAddress);

        return filter_var($ipAddress, FILTER_VALIDATE_IP) ? $ipAddress : null;
    }

    protected function sanitizeUserAgent($userAgent)
    {
        $userAgent = trim(strip_tags((string) $userAgent));

        return $userAgent !== '' ? mb_substr($userAgent, 0, 255) : null;
    }

    protected function is_obvious_automated_click($userAgent, $requestMethod)
    {
        $requestMethod = strtoupper(trim((string) $requestMethod));
        if ($requestMethod === 'HEAD') {
            return true;
        }

        $userAgent = strtolower(trim((string) $userAgent));
        if ($userAgent === '') {
            return false;
        }

        $botMarkers = [
            'proofpoint',
            'url-defense',
            'barracuda',
            'mimecast',
            'safelinks',
            'microsoft office',
            'microsoft preview',
            'symantec',
            'trend micro',
            'fortinet',
            'cisco',
            'cloudmark',
            'urlscan',
            'avanan',
            'curl/',
            'wget/',
            'python-requests',
            'okhttp',
            'headlesschrome',
            'bot',
            'crawler',
            'spider',
        ];

        foreach ($botMarkers as $marker) {
            if (strpos($userAgent, $marker) !== false) {
                return true;
            }
        }

        return false;
    }
}
