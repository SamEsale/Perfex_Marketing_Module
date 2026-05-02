<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Marketing_unsubscribe
{
    protected $ci;

    public function __construct()
    {
        $this->ci = &get_instance();
    }

    public function generate_token(array $recipient)
    {
        $payload = [
            'recipient_id' => (int) ($recipient['id'] ?? 0),
            'campaign_id' => (int) ($recipient['campaign_id'] ?? 0),
            'contact_id' => (int) ($recipient['marketing_contact_id'] ?? 0),
            'email' => mb_strtolower(trim((string) ($recipient['email'] ?? ''))),
        ];

        $payloadJson = json_encode($payload);
        if ($payloadJson === false) {
            return null;
        }

        $signed = [
            'p' => $payload,
            's' => hash_hmac('sha256', $payloadJson, $this->get_secret()),
        ];

        return rtrim(strtr(base64_encode(json_encode($signed)), '+/', '-_'), '=');
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
        $decoded = base64_decode(strtr((string) $token, '-_', '+/'), true);
        if ($decoded === false) {
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
        ];
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
}
