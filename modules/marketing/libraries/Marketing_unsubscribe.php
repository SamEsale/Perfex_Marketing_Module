<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Marketing_unsubscribe
{
    protected $ci;

    public function __construct()
    {
        $this->ci = &get_instance();
        $this->ci->load->model('marketing/Marketing_public_links_model', 'marketing_public_links_model');
    }

    public function generate_token(array $recipient)
    {
        $link = $this->ci->marketing_public_links_model->issue_unsubscribe_link($recipient);

        return $link['token'] ?? null;
    }

    public function build_url(array $recipient)
    {
        $token = $this->generate_token($recipient);
        if ($token === null) {
            return null;
        }

        return site_url('marketing/unsubscribe/' . rawurlencode($token));
    }

    public function verify_token($token)
    {
        $tokenRow = $this->ci->marketing_public_links_model->resolve_unsubscribe_token($token);
        if ($tokenRow) {
            return [
                'recipient_id' => (int) ($tokenRow['campaign_recipient_id'] ?? 0),
                'campaign_id' => (int) ($tokenRow['campaign_id'] ?? 0),
                'contact_id' => (int) ($tokenRow['marketing_contact_id'] ?? 0),
                'email' => mb_strtolower(trim((string) ($tokenRow['normalized_email'] ?? ''))),
                'link_id' => (int) ($tokenRow['id'] ?? 0),
                'token_type' => 'opaque',
            ];
        }

        return $this->verify_legacy_token($token);
    }

    protected function get_secret()
    {
        $secret = (string) config_item('encryption_key');

        if ($secret === '') {
            $secret = (string) config_item('sess_cookie_name');
        }

        if ($secret === '') {
            $secret = (string) site_url();
        }

        return $secret;
    }

    protected function verify_legacy_token($token)
    {
        $decoded = $this->base64url_decode((string) $token);
        if ($decoded === null) {
            return null;
        }

        $data = json_decode($decoded, true);
        if (!is_array($data) || !isset($data['p'], $data['s']) || !is_array($data['p'])) {
            return null;
        }

        $payload = $data['p'];
        $signature = (string) $data['s'];
        $payloadJson = json_encode($payload);

        if ($payloadJson === false) {
            return null;
        }

        $expected = hash_hmac('sha256', $payloadJson, $this->get_secret());
        if (!hash_equals($expected, $signature)) {
            return null;
        }

        return [
            'recipient_id' => (int) ($payload['recipient_id'] ?? 0),
            'campaign_id' => (int) ($payload['campaign_id'] ?? 0),
            'contact_id' => (int) ($payload['contact_id'] ?? 0),
            'email' => mb_strtolower(trim((string) ($payload['email'] ?? ''))),
            'link_id' => 0,
            'token_type' => 'legacy',
        ];
    }

    protected function base64url_decode($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
