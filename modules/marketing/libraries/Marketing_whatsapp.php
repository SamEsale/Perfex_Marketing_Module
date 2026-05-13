<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Marketing_whatsapp
{
    protected $ci;
    protected $optionTable;

    public function __construct()
    {
        $this->ci = &get_instance();
        $this->optionTable = db_prefix() . 'options';

        if (!isset($this->ci->db) || !$this->ci->db) {
            $this->ci->load->database();
        }
    }

    public function get_settings()
    {
        $rawEnabled = $this->getOptionValue('marketing_whatsapp_enabled');

        return [
            'enabled' => $this->optionToBool($rawEnabled),
            'raw_enabled' => $rawEnabled,
            'graph_version' => trim((string) $this->getOptionValue('marketing_whatsapp_graph_version')),
            'phone_number_id' => trim((string) $this->getOptionValue('marketing_whatsapp_phone_number_id')),
            'business_account_id' => trim((string) $this->getOptionValue('marketing_whatsapp_business_account_id')),
            'access_token' => trim((string) $this->getOptionValue('marketing_whatsapp_access_token')),
            'app_secret' => trim((string) $this->getOptionValue('marketing_whatsapp_app_secret')),
            'webhook_verify_token' => trim((string) $this->getOptionValue('marketing_whatsapp_webhook_verify_token')),
            'default_template_name' => trim((string) $this->getOptionValue('marketing_whatsapp_default_template_name')),
            'default_template_language' => trim((string) $this->getOptionValue('marketing_whatsapp_default_template_language')),
            'test_recipient' => trim((string) $this->getOptionValue('marketing_whatsapp_test_recipient')),
        ];
    }

    public function is_enabled()
    {
        $settings = $this->get_settings();

        return !empty($settings['enabled']);
    }

    public function verify_webhook($mode, $token, $challenge)
    {
        $settings = $this->get_settings();
        $expectedToken = (string) ($settings['webhook_verify_token'] ?? '');

        if ($mode !== 'subscribe' || $expectedToken === '' || !hash_equals($expectedToken, (string) $token)) {
            $this->logSafeError('Marketing WhatsApp webhook verification failed.');

            return [
                'success' => false,
                'status_code' => 403,
                'message' => 'Webhook verification failed.',
            ];
        }

        log_message('info', 'Marketing WhatsApp webhook verified successfully.');

        return [
            'success' => true,
            'status_code' => 200,
            'challenge' => (string) $challenge,
            'message' => 'Webhook verified.',
        ];
    }

    public function verify_post_signature($rawBody, array $headers = [])
    {
        $settings = $this->get_settings();
        $appSecret = trim((string) ($settings['app_secret'] ?? ''));

        if ($appSecret === '') {
            return [
                'accepted' => true,
                'authenticated' => false,
                'mode' => 'compatibility',
                'warning' => 'whatsapp_post_signature_not_configured',
            ];
        }

        $signatureHeader = trim((string) ($headers['x-hub-signature-256'] ?? ''));
        if ($signatureHeader === '' || strpos($signatureHeader, 'sha256=') !== 0) {
            $this->logSafeError('Marketing WhatsApp POST signature missing or malformed.');

            return [
                'accepted' => false,
                'authenticated' => false,
                'mode' => 'app_secret',
                'warning' => 'whatsapp_post_signature_invalid',
                'message' => 'WhatsApp webhook signature verification failed.',
            ];
        }

        $presentedSignature = substr($signatureHeader, 7);
        if ($presentedSignature === '' || preg_match('/^[a-f0-9]{64}$/i', $presentedSignature) !== 1) {
            $this->logSafeError('Marketing WhatsApp POST signature malformed.');

            return [
                'accepted' => false,
                'authenticated' => false,
                'mode' => 'app_secret',
                'warning' => 'whatsapp_post_signature_invalid',
                'message' => 'WhatsApp webhook signature verification failed.',
            ];
        }

        $expectedSignature = hash_hmac('sha256', (string) $rawBody, $appSecret);
        if (!hash_equals($expectedSignature, strtolower($presentedSignature))) {
            $this->logSafeError('Marketing WhatsApp POST signature verification failed.');

            return [
                'accepted' => false,
                'authenticated' => false,
                'mode' => 'app_secret',
                'warning' => 'whatsapp_post_signature_invalid',
                'message' => 'WhatsApp webhook signature verification failed.',
            ];
        }

        return [
            'accepted' => true,
            'authenticated' => true,
            'mode' => 'app_secret',
            'warning' => null,
        ];
    }

    public function send_template_message($to, $templateName, $languageCode, array $components = [])
    {
        $settings = $this->get_settings();
        if (empty($settings['enabled'])) {
            log_message(
                'error',
                'Marketing WhatsApp disabled for send attempt: raw_option='
                . json_encode($settings['raw_enabled'])
                . ' interpreted_enabled=' . ($settings['enabled'] ? 'yes' : 'no')
            );

            return [
                'success' => false,
                'error' => 'WhatsApp Business integration is disabled.',
            ];
        }

        $graphVersion = $settings['graph_version'] !== '' ? $settings['graph_version'] : 'v23.0';
        $phoneNumberId = $settings['phone_number_id'];
        $accessToken = $settings['access_token'];
        $recipient = $this->normalizePhone($to);
        $template = trim((string) $templateName);
        $language = trim((string) $languageCode);

        if ($phoneNumberId === '') {
            return [
                'success' => false,
                'error' => 'WhatsApp phone number ID is missing.',
            ];
        }

        if ($accessToken === '') {
            return [
                'success' => false,
                'error' => 'WhatsApp access token is missing.',
            ];
        }

        if ($template === '') {
            return [
                'success' => false,
                'error' => 'WhatsApp template name is missing.',
            ];
        }

        if ($language === '') {
            return [
                'success' => false,
                'error' => 'WhatsApp template language is missing.',
            ];
        }

        if ($recipient === '') {
            return [
                'success' => false,
                'error' => 'WhatsApp test recipient is missing or invalid.',
            ];
        }

        if (!function_exists('curl_init')) {
            return [
                'success' => false,
                'error' => 'cURL is required to send WhatsApp template messages.',
            ];
        }

        $endpoint = 'https://graph.facebook.com/' . rawurlencode($graphVersion) . '/' . rawurlencode($phoneNumberId) . '/messages';
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $recipient,
            'type' => 'template',
            'template' => [
                'name' => $template,
                'language' => [
                    'code' => $language,
                ],
            ],
        ];

        if (!empty($components)) {
            $payload['template']['components'] = array_values($components);
        }

        $curl = curl_init($endpoint);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 30,
        ]);

        $rawResponse = curl_exec($curl);
        $curlError = curl_error($curl);
        $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($rawResponse === false || $curlError !== '') {
            $this->logSafeError('Marketing WhatsApp test send failed at transport level: ' . $this->sanitizeMessage($curlError));

            return [
                'success' => false,
                'error' => 'WhatsApp API request failed.',
                'status_code' => $statusCode,
                'meta_error_message' => '',
                'meta_error_type' => '',
                'meta_error_code' => '',
                'meta_error_subcode' => '',
                'meta_fbtrace_id' => '',
            ];
        }

        $decoded = json_decode($rawResponse, true);
        if ($statusCode >= 200 && $statusCode < 300 && !empty($decoded['messages'][0]['id'])) {
            log_message('info', 'Marketing WhatsApp template message accepted: phone_number_id=' . $phoneNumberId . ' status=' . $statusCode);

            return [
                'success' => true,
                'status_code' => $statusCode,
                'message_id' => (string) $decoded['messages'][0]['id'],
            ];
        }

        $metaError = $this->extractMetaErrorDetails($decoded, $rawResponse);
        $apiError = $metaError['meta_error_message'] !== '' ? $metaError['meta_error_message'] : 'WhatsApp API request was not accepted.';
        $this->logSafeError(
            'Marketing WhatsApp API send failed: status=' . $statusCode
            . ' type=' . $metaError['meta_error_type']
            . ' code=' . $metaError['meta_error_code']
            . ' subcode=' . $metaError['meta_error_subcode']
            . ' fbtrace_id=' . $metaError['meta_fbtrace_id']
            . ' message=' . $apiError
        );

        return [
            'success' => false,
            'error' => $apiError,
            'status_code' => $statusCode,
            'meta_error_message' => $metaError['meta_error_message'],
            'meta_error_type' => $metaError['meta_error_type'],
            'meta_error_code' => $metaError['meta_error_code'],
            'meta_error_subcode' => $metaError['meta_error_subcode'],
            'meta_fbtrace_id' => $metaError['meta_fbtrace_id'],
        ];
    }

    public function parse_inbound_webhook_payload($payload)
    {
        if (!is_array($payload)) {
            return [
                'valid' => false,
                'messages' => [],
                'statuses' => [],
            ];
        }

        $parsed = [
            'valid' => true,
            'messages' => [],
            'statuses' => [],
            'metadata' => [],
        ];

        $entries = isset($payload['entry']) && is_array($payload['entry']) ? $payload['entry'] : [];
        foreach ($entries as $entry) {
            $changes = isset($entry['changes']) && is_array($entry['changes']) ? $entry['changes'] : [];
            foreach ($changes as $change) {
                $value = isset($change['value']) && is_array($change['value']) ? $change['value'] : [];
                $metadata = isset($value['metadata']) && is_array($value['metadata']) ? $value['metadata'] : [];
                if (!empty($metadata)) {
                    $parsed['metadata'][] = [
                        'phone_number_id' => trim((string) ($metadata['phone_number_id'] ?? '')),
                        'display_phone_number' => trim((string) ($metadata['display_phone_number'] ?? '')),
                    ];
                }

                $contacts = [];
                foreach (($value['contacts'] ?? []) as $contact) {
                    $waId = $this->normalizePhone($contact['wa_id'] ?? '');
                    if ($waId === '') {
                        continue;
                    }
                    $contacts[$waId] = [
                        'name' => trim((string) ($contact['profile']['name'] ?? '')),
                    ];
                }

                foreach (($value['messages'] ?? []) as $message) {
                    $from = $this->normalizePhone($message['from'] ?? '');
                    if ($from === '') {
                        continue;
                    }

                    $messageType = trim((string) ($message['type'] ?? 'text'));
                    $bodyText = $this->extractMessageText($message);

                    $parsed['messages'][] = [
                        'message_id' => trim((string) ($message['id'] ?? '')),
                        'from' => $from,
                        'sender_name' => trim((string) ($contacts[$from]['name'] ?? '')),
                        'type' => $messageType,
                        'text' => $bodyText,
                        'timestamp' => $this->normalizeTimestamp($message['timestamp'] ?? null),
                        'context_message_id' => trim((string) ($message['context']['id'] ?? '')),
                        'raw' => [
                            'type' => $messageType,
                        ],
                    ];
                }

                foreach (($value['statuses'] ?? []) as $statusItem) {
                    $recipient = $this->normalizePhone($statusItem['recipient_id'] ?? '');
                    $status = strtolower(trim((string) ($statusItem['status'] ?? '')));
                    if ($recipient === '' || $status === '') {
                        continue;
                    }

                    $parsed['statuses'][] = [
                        'message_id' => trim((string) ($statusItem['id'] ?? '')),
                        'recipient' => $recipient,
                        'status' => $status,
                        'event_type' => $this->map_status_to_event_type($status),
                        'timestamp' => $this->normalizeTimestamp($statusItem['timestamp'] ?? null),
                        'conversation_id' => trim((string) ($statusItem['conversation']['id'] ?? '')),
                        'raw' => [
                            'status' => $status,
                        ],
                    ];
                }
            }
        }

        return $parsed;
    }

    public function normalizePhone($phone)
    {
        $phone = trim((string) $phone);
        if ($phone === '') {
            return '';
        }

        $prefix = strpos($phone, '+') === 0 ? '+' : '';
        $digits = preg_replace('/[^0-9]/', '', $phone);

        return $digits === '' ? '' : $prefix . $digits;
    }

    protected function extractMessageText(array $message)
    {
        $type = trim((string) ($message['type'] ?? 'text'));

        if ($type === 'text') {
            return $this->sanitizeMessage($message['text']['body'] ?? '');
        }

        if ($type === 'button') {
            return $this->sanitizeMessage($message['button']['text'] ?? '');
        }

        if ($type === 'interactive') {
            $interactive = $message['interactive'] ?? [];
            if (!empty($interactive['button_reply']['title'])) {
                return $this->sanitizeMessage($interactive['button_reply']['title']);
            }
            if (!empty($interactive['list_reply']['title'])) {
                return $this->sanitizeMessage($interactive['list_reply']['title']);
            }
        }

        return $this->sanitizeMessage(json_encode(['type' => $type]));
    }

    protected function map_status_to_event_type($status)
    {
        switch ($status) {
            case 'sent':
                return 'whatsapp_sent';
            case 'delivered':
                return 'whatsapp_delivered';
            case 'read':
                return 'whatsapp_read';
            case 'failed':
                return 'whatsapp_failed';
            default:
                return 'whatsapp_' . preg_replace('/[^a-z0-9_]+/i', '_', strtolower($status));
        }
    }

    protected function normalizeTimestamp($timestamp)
    {
        if ($timestamp === null || $timestamp === '') {
            return date('Y-m-d H:i:s');
        }

        if (is_numeric($timestamp)) {
            return date('Y-m-d H:i:s', (int) $timestamp);
        }

        $parsed = strtotime((string) $timestamp);

        return $parsed ? date('Y-m-d H:i:s', $parsed) : date('Y-m-d H:i:s');
    }

    protected function sanitizeMessage($message)
    {
        $message = trim(strip_tags((string) $message));
        $message = preg_replace('/\s+/', ' ', $message);

        return mb_substr($message, 0, 180);
    }

    protected function extractMetaErrorDetails($decoded, $rawResponse = '')
    {
        $error = is_array($decoded) && isset($decoded['error']) && is_array($decoded['error'])
            ? $decoded['error']
            : [];

        return [
            'meta_error_message' => $this->sanitizeMessage($error['message'] ?? $rawResponse),
            'meta_error_type' => $this->sanitizeMetaScalar($error['type'] ?? ''),
            'meta_error_code' => $this->sanitizeMetaScalar($error['code'] ?? ''),
            'meta_error_subcode' => $this->sanitizeMetaScalar($error['error_subcode'] ?? ''),
            'meta_fbtrace_id' => $this->sanitizeMetaScalar($error['fbtrace_id'] ?? ''),
        ];
    }

    protected function sanitizeMetaScalar($value)
    {
        if (is_array($value) || is_object($value) || $value === null) {
            return '';
        }

        return preg_replace('/[^A-Za-z0-9._:-]/', '', mb_substr(trim((string) $value), 0, 120));
    }

    protected function logSafeError($message)
    {
        log_message('error', $this->sanitizeMessage($message));
    }

    protected function optionToBool($value)
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        if (in_array($normalized, ['1', 'true', 'yes'], true)) {
            return true;
        }

        if (in_array($normalized, ['', '0', 'false', 'no'], true)) {
            return false;
        }

        return false;
    }

    protected function getOptionValue($name)
    {
        $value = get_option($name);
        $dbRecord = $this->getOptionValueFromDb($name);
        $dbExists = $dbRecord['exists'];
        $dbValue = $dbRecord['value'];

        if ($dbExists) {
            return $dbValue;
        }

        if ($value !== false && $value !== null) {
            return (string) $value;
        }

        return '';
    }

    protected function getOptionValueFromDb($name)
    {
        if (!isset($this->ci->db) || !$this->ci->db || !$this->ci->db->table_exists($this->optionTable)) {
            return [
                'exists' => false,
                'value' => '',
            ];
        }

        $row = $this->ci->db
            ->select('value')
            ->from($this->optionTable)
            ->where('name', $name)
            ->get()
            ->row_array();

        return [
            'exists' => is_array($row),
            'value' => isset($row['value']) ? (string) $row['value'] : '',
        ];
    }
}
