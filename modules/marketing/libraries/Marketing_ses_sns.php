<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Marketing_ses_sns
{
    const HTTP_TIMEOUT = 10;

    protected $certCache = [];

    public function normalize_event_name($name)
    {
        $normalized = strtolower(trim($this->scalar_string($name, '')));
        $normalized = str_replace([' ', '-'], '_', $normalized);

        $map = [
            'send' => 'sent',
            'sent' => 'sent',
            'delivery' => 'delivered',
            'delivered' => 'delivered',
            'open' => 'open',
            'opened' => 'open',
            'click' => 'click',
            'clicked' => 'click',
            'bounce' => 'bounce',
            'bounced' => 'bounce',
            'complaint' => 'complaint',
            'complained' => 'complaint',
            'reject' => 'rejected',
            'rejected' => 'rejected',
            'renderingfailure' => 'rendering_failure',
            'rendering_failure' => 'rendering_failure',
            'deliverydelay' => 'delivery_delay',
            'delivery_delay' => 'delivery_delay',
            'subscriptionconfirmation' => 'subscription_confirmation',
            'subscription_confirmation' => 'subscription_confirmation',
            'unsubscribeconfirmation' => 'unsubscribe_confirmation',
            'unsubscribe_confirmation' => 'unsubscribe_confirmation',
            'notification' => 'notification',
            'unsubscribe' => 'unsubscribe',
            'unsubscribed' => 'unsubscribe',
            'failed' => 'failed',
        ];

        return $map[$normalized] ?? $normalized;
    }

    public function verify($payload, array $headers = [], array $options = [])
    {
        $result = [
            'implemented' => true,
            'valid' => false,
            'error' => null,
        ];
        $expectedTopicArn = trim($this->scalar_string($options['topic_arn'] ?? '', ''));
        $expectedRegion = strtolower(trim($this->scalar_string($options['region'] ?? '', '')));
        $replayWindowSeconds = max(1, (int) ($options['replay_window_seconds'] ?? 900));
        $futureSkewSeconds = max(0, (int) ($options['future_skew_seconds'] ?? 300));

        $parsed = $this->parse_payload($payload);
        if (!$parsed['valid']) {
            $result['error'] = $parsed['error'];

            return $result;
        }

        $message = $parsed['body'];
        $messageType = $this->normalize_event_name($this->scalar_string($message['Type'] ?? ($headers['x-amz-sns-message-type'] ?? ''), ''));
        if ($messageType !== '') {
            $result['type'] = $messageType;
        }

        $rawType = trim($this->scalar_string($message['Type'] ?? '', ''));
        if (!in_array($rawType, ['Notification', 'SubscriptionConfirmation', 'UnsubscribeConfirmation'], true)) {
            $result['error'] = 'Unsupported SNS message type.';

            return $result;
        }

        foreach (['Type', 'MessageId', 'TopicArn', 'Timestamp', 'SignatureVersion', 'Signature', 'SigningCertURL'] as $field) {
            if (!$this->has_non_empty_scalar($message, $field)) {
                $result['error'] = 'Missing required SNS field: ' . $field . '.';

                return $result;
            }
        }

        if ((string) $message['SignatureVersion'] !== '1') {
            $result['error'] = 'Unsupported SNS signature version.';

            return $result;
        }

        $signingCertUrl = trim((string) $message['SigningCertURL']);
        if (!$this->is_valid_signing_cert_url($signingCertUrl)) {
            $result['error'] = 'Invalid SNS signing certificate URL.';

            return $result;
        }

        if ($expectedRegion !== '' && !$this->signing_cert_url_matches_region($signingCertUrl, $expectedRegion)) {
            $result['error'] = 'SNS signing certificate URL region does not match the configured SES region.';

            return $result;
        }

        if ($expectedTopicArn !== '' && !$this->topic_arn_matches($message['TopicArn'] ?? '', $expectedTopicArn)) {
            $result['error'] = 'SNS TopicArn does not match the configured SES SNS topic.';

            return $result;
        }

        $timestampValidation = $this->validate_timestamp_freshness($message['Timestamp'] ?? null, $replayWindowSeconds, $futureSkewSeconds);
        if (!$timestampValidation['valid']) {
            $result['error'] = $timestampValidation['error'];

            return $result;
        }

        $canonicalString = $this->build_canonical_string($message);
        if ($canonicalString === null) {
            $result['error'] = 'SNS message is missing fields required for signature verification.';

            return $result;
        }

        if (!function_exists('openssl_verify') || !function_exists('openssl_pkey_get_public')) {
            $result['error'] = 'OpenSSL is required for SNS signature verification.';

            return $result;
        }

        $certificate = $this->get_signing_certificate($signingCertUrl);
        if (!$certificate['success']) {
            $result['error'] = $certificate['error'];

            return $result;
        }

        $signature = base64_decode((string) $message['Signature'], true);
        if ($signature === false) {
            $result['error'] = 'SNS signature is not valid base64 data.';

            return $result;
        }

        $publicKey = openssl_pkey_get_public($certificate['body']);
        if ($publicKey === false) {
            $result['error'] = 'SNS signing certificate could not be parsed.';

            return $result;
        }

        $verified = openssl_verify($canonicalString, $signature, $publicKey, OPENSSL_ALGO_SHA1);

        if (is_resource($publicKey) && function_exists('openssl_free_key')) {
            openssl_free_key($publicKey);
        }

        if ($verified !== 1) {
            $result['error'] = $verified === 0
                ? 'SNS signature verification failed.'
                : $this->sanitize_error('OpenSSL verification error: ' . $this->get_openssl_error());

            return $result;
        }

        $result['valid'] = true;

        return $result;
    }

    public function confirm_subscription($subscribeUrl, array $options = [])
    {
        if (is_array($subscribeUrl) || is_object($subscribeUrl)) {
            return [
                'success' => false,
                'error' => 'SNS SubscribeURL is invalid.',
            ];
        }

        $subscribeUrl = trim((string) $subscribeUrl);
        if ($subscribeUrl === '') {
            return [
                'success' => false,
                'error' => 'SNS SubscribeURL is missing.',
            ];
        }

        $expectedRegion = strtolower(trim($this->scalar_string($options['region'] ?? '', '')));
        if (!$this->is_valid_sns_action_url($subscribeUrl, $expectedRegion)) {
            return [
                'success' => false,
                'error' => 'SNS SubscribeURL is invalid.',
            ];
        }

        return $this->http_get($subscribeUrl, self::HTTP_TIMEOUT);
    }

    protected function parse_payload($payload)
    {
        if (is_array($payload)) {
            return [
                'valid' => true,
                'body' => $payload,
            ];
        }

        $rawPayload = (string) $payload;
        if (trim($rawPayload) === '') {
            return [
                'valid' => false,
                'error' => 'Empty SNS payload.',
            ];
        }

        $decoded = json_decode($rawPayload, true);
        if (!is_array($decoded)) {
            return [
                'valid' => false,
                'error' => 'Invalid SNS JSON payload.',
            ];
        }

        return [
            'valid' => true,
            'body' => $decoded,
        ];
    }

    protected function has_non_empty_scalar(array $message, $field)
    {
        if (!array_key_exists($field, $message) || is_array($message[$field]) || is_object($message[$field])) {
            return false;
        }

        return trim((string) $message[$field]) !== '';
    }

    protected function is_valid_signing_cert_url($url)
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');

        if ($scheme !== 'https' || !$this->is_safe_sns_host($host)) {
            return false;
        }

        if (!empty($parts['query']) || !empty($parts['fragment']) || !empty($parts['user']) || !empty($parts['pass']) || !empty($parts['port'])) {
            return false;
        }

        return substr(strtolower($path), -4) === '.pem';
    }

    protected function signing_cert_url_matches_region($url, $expectedRegion)
    {
        $expectedRegion = strtolower(trim((string) $expectedRegion));
        if ($expectedRegion === '') {
            return true;
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if (!preg_match('/^sns\.([a-z0-9-]+)\.amazonaws\.com$/', $host, $matches)) {
            return false;
        }

        return strtolower((string) ($matches[1] ?? '')) === $expectedRegion;
    }

    protected function topic_arn_matches($actualTopicArn, $expectedTopicArn)
    {
        $actualTopicArn = trim((string) $actualTopicArn);
        $expectedTopicArn = trim((string) $expectedTopicArn);

        if ($actualTopicArn === '' || $expectedTopicArn === '') {
            return false;
        }

        return hash_equals($expectedTopicArn, $actualTopicArn);
    }

    protected function validate_timestamp_freshness($timestamp, $replayWindowSeconds, $futureSkewSeconds)
    {
        $parsedTimestamp = $this->parse_sns_timestamp($timestamp);
        if ($parsedTimestamp === false) {
            return [
                'valid' => false,
                'error' => 'SNS Timestamp is missing or malformed.',
            ];
        }

        $now = time();
        if ($parsedTimestamp < ($now - max(1, (int) $replayWindowSeconds))) {
            return [
                'valid' => false,
                'error' => 'SNS Timestamp is outside the allowed replay window.',
            ];
        }

        if ($parsedTimestamp > ($now + max(0, (int) $futureSkewSeconds))) {
            return [
                'valid' => false,
                'error' => 'SNS Timestamp is too far in the future.',
            ];
        }

        return [
            'valid' => true,
            'error' => null,
        ];
    }

    protected function parse_sns_timestamp($timestamp)
    {
        if (is_array($timestamp) || is_object($timestamp)) {
            return false;
        }

        $timestamp = trim((string) $timestamp);
        if ($timestamp === '') {
            return false;
        }

        $parsedTimestamp = strtotime($timestamp);
        if ($parsedTimestamp === false) {
            return false;
        }

        return (int) $parsedTimestamp;
    }

    protected function is_valid_sns_action_url($url, $expectedRegion = '')
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($scheme !== 'https' || !$this->is_safe_sns_host($host)) {
            return false;
        }

        if (!empty($parts['query']) || !empty($parts['fragment']) || !empty($parts['user']) || !empty($parts['pass']) || !empty($parts['port'])) {
            return false;
        }

        if ($expectedRegion !== '' && !$this->signing_cert_url_matches_region($url, $expectedRegion)) {
            return false;
        }

        return true;
    }

    protected function is_safe_sns_host($host)
    {
        $host = strtolower(trim((string) $host));
        if ($host === '') {
            return false;
        }

        return preg_match('/^sns\.[a-z0-9-]+\.amazonaws\.com$/', $host) === 1;
    }

    protected function build_canonical_string(array $message)
    {
        $type = trim((string) ($message['Type'] ?? ''));
        if ($type === 'Notification') {
            $fields = ['Message', 'MessageId'];
            if (array_key_exists('Subject', $message) && !is_array($message['Subject']) && !is_object($message['Subject'])) {
                $fields[] = 'Subject';
            }
            $fields = array_merge($fields, ['Timestamp', 'TopicArn', 'Type']);
        } elseif (in_array($type, ['SubscriptionConfirmation', 'UnsubscribeConfirmation'], true)) {
            $fields = ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'];
        } else {
            return null;
        }

        $lines = [];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $message) || is_array($message[$field]) || is_object($message[$field])) {
                return null;
            }

            $lines[] = $field;
            $lines[] = (string) $message[$field];
        }

        return implode("\n", $lines) . "\n";
    }

    protected function get_signing_certificate($url)
    {
        if (isset($this->certCache[$url])) {
            return [
                'success' => true,
                'body' => $this->certCache[$url],
            ];
        }

        $response = $this->http_get($url, self::HTTP_TIMEOUT);
        if (!$response['success']) {
            return $response;
        }

        $this->certCache[$url] = $response['body'];

        return $response;
    }

    protected function http_get($url, $timeout)
    {
        $timeout = max(1, (int) $timeout);

        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => min($timeout, 5),
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => 'Perfex-Marketing-SNS/1.0',
            ]);

            $body = curl_exec($curl);
            $error = curl_error($curl);
            $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);

            if ($body === false || $error !== '') {
                return [
                    'success' => false,
                    'error' => 'SNS remote request failed: ' . $this->sanitize_error($error !== '' ? $error : 'Transport error.'),
                ];
            }

            if ($statusCode < 200 || $statusCode >= 300) {
                return [
                    'success' => false,
                    'error' => 'SNS remote request returned HTTP ' . $statusCode . '.',
                    'status_code' => $statusCode,
                ];
            }

            return [
                'success' => true,
                'body' => (string) $body,
                'status_code' => $statusCode,
            ];
        }

        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            return [
                'success' => false,
                'error' => 'cURL or allow_url_fopen is required for SNS verification requests.',
            ];
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeout,
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
                'header' => "User-Agent: Perfex-Marketing-SNS/1.0\r\n",
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $stream = @fopen($url, 'rb', false, $context);
        if ($stream === false) {
            return [
                'success' => false,
                'error' => 'SNS remote request failed.',
            ];
        }

        $body = stream_get_contents($stream);
        $metadata = stream_get_meta_data($stream);
        fclose($stream);

        $responseHeaders = $metadata['wrapper_data'] ?? [];
        $statusCode = $this->extract_status_code(is_array($responseHeaders) ? $responseHeaders : []);

        if ($body === false) {
            return [
                'success' => false,
                'error' => 'SNS remote request failed.',
            ];
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            return [
                'success' => false,
                'error' => 'SNS remote request returned HTTP ' . $statusCode . '.',
                'status_code' => $statusCode,
            ];
        }

        return [
            'success' => true,
            'body' => (string) $body,
            'status_code' => $statusCode,
        ];
    }

    protected function extract_status_code(array $headers)
    {
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $header, $matches)) {
                return (int) $matches[1];
            }
        }

        return 0;
    }

    protected function sanitize_error($message)
    {
        $message = preg_replace('/\s+/', ' ', trim(strip_tags((string) $message)));

        return mb_substr($message, 0, 200);
    }

    protected function get_openssl_error()
    {
        $error = '';
        while ($message = openssl_error_string()) {
            $error = $message;
        }

        return $error !== '' ? $error : 'Unknown OpenSSL error.';
    }

    protected function scalar_string($value, $default = '')
    {
        if (is_array($value) || is_object($value) || $value === null) {
            return $default;
        }

        return (string) $value;
    }
}
