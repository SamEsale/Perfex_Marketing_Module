<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Marketing_license
{
    private const DEFAULT_API_BASE_URL = 'https://license.imbisoft.com/api';
    private const DEFAULT_API_BASE_URL_HINT = 'https://license.example.com/api';
    private const GRACE_DAYS = 7;
    private const OPTION_TABLE = 'options';
    private const LEGACY_LICENSE_KEY_OPTION = 'marketing_license_key';
    private const PROTECTED_LICENSE_KEY_OPTION = 'marketing_license_key_protected';
    private const RESPONSE_HMAC_SECRET_OPTION = 'marketing_license_response_hmac_secret';
    private const RESPONSE_HMAC_SECRET_ENV = 'MARKETING_LICENSE_RESPONSE_HMAC_SECRET';

    protected $ci;
    protected $optionTable;

    public function __construct()
    {
        $this->ci = &get_instance();
        $this->optionTable = db_prefix() . self::OPTION_TABLE;

        if (!isset($this->ci->db) || !$this->ci->db) {
            $this->ci->load->database();
        }
    }

    public function get_license_state()
    {
        $licenseKey = $this->get_saved_license_key();
        $status = $this->normalize_status($this->get_option_value('marketing_license_status'));
        $licensedDomain = trim((string) $this->get_option_value('marketing_license_domain'));
        $lastChecked = trim((string) $this->get_option_value('marketing_license_last_checked'));
        $lastError = trim((string) $this->get_option_value('marketing_license_last_error'));
        $graceUntil = trim((string) $this->get_option_value('marketing_license_grace_until'));
        $apiBaseUrl = $this->resolve_api_base_url();
        $graceActive = $this->is_grace_active($graceUntil);

        if ($status === 'grace' && !$graceActive) {
            $status = 'error';
        }

        return [
            'license_key' => $licenseKey,
            'masked_license_key' => $this->mask_license_key($licenseKey),
            'email' => trim((string) $this->get_option_value('marketing_license_email')),
            'status' => $status,
            'status_label' => $this->humanize_status($status),
            'licensed_domain' => $licensedDomain,
            'last_checked' => $lastChecked,
            'last_checked_display' => $lastChecked !== '' ? $lastChecked : 'Never',
            'last_error' => $lastError,
            'grace_until' => $graceUntil,
            'grace_active' => $graceActive,
            'instance_id' => $this->get_instance_id(),
            'current_domain' => $this->get_current_domain(),
            'module_version' => function_exists('marketing_get_module_version') ? marketing_get_module_version() : '1.1.0',
            'perfex_version' => function_exists('marketing_get_perfex_version') ? marketing_get_perfex_version() : '',
            'php_version' => PHP_VERSION,
            'api_base_url' => $apiBaseUrl,
            'api_base_url_hint' => self::DEFAULT_API_BASE_URL_HINT,
            'api_configured' => $apiBaseUrl !== '',
        ];
    }

    public function save_license_key($licenseKey = '', $email = '')
    {
        $licenseKey = $this->sanitize_license_key($licenseKey);
        $email = $this->sanitize_email($email);

        $settings = array_merge($this->build_license_key_storage_settings($licenseKey), [
            'marketing_license_email' => $email,
            'marketing_license_status' => $licenseKey !== '' ? 'pending' : 'inactive',
            'marketing_license_domain' => '',
            'marketing_license_last_checked' => '',
            'marketing_license_last_error' => '',
            'marketing_license_grace_until' => '',
        ]);

        $this->persist_options($settings);
        $this->get_instance_id();

        return $this->get_license_state();
    }

    public function check_license($licenseKey = null, $email = null)
    {
        $state = $this->get_license_state();

        if ($licenseKey !== null || $email !== null) {
            $state = $this->save_license_key(
                $licenseKey !== null ? (string) $licenseKey : $state['license_key'],
                $email !== null ? (string) $email : $state['email']
            );
        }

        $licenseKey = trim((string) $state['license_key']);
        $email = trim((string) $state['email']);
        $checkedAt = date('Y-m-d H:i:s');
        $apiBaseUrl = trim((string) $state['api_base_url']);

        if ($licenseKey === '' || $email === '') {
            $this->persist_options([
                'marketing_license_status' => 'inactive',
                'marketing_license_last_checked' => $checkedAt,
                'marketing_license_last_error' => 'Enter both license key and customer email before checking the license.',
            ]);

            return $this->get_license_state();
        }

        if ($apiBaseUrl === '') {
            $this->persist_options([
                'marketing_license_status' => 'unconfigured',
                'marketing_license_last_checked' => $checkedAt,
                'marketing_license_last_error' => '',
            ]);

            return $this->get_license_state();
        }

        $payload = [
            'license_key' => $licenseKey,
            'email' => $email,
            'domain' => $this->get_current_domain(),
            'domain_hash' => hash('sha256', $this->get_current_domain()),
            'instance_id' => $this->get_instance_id(),
            'module_version' => $state['module_version'],
            'perfex_version' => $state['perfex_version'],
            'php_version' => PHP_VERSION,
        ];

        $response = $this->post_json($apiBaseUrl, '/licenses/check', $payload);
        if (!$response['success']) {
            return $this->handle_unreachable_server($state, $checkedAt, $response['error']);
        }

        $decoded = $response['body'];
        $signatureVerification = $this->verify_response_signature($decoded);
        if (empty($signatureVerification['valid'])) {
            $safeError = $this->sanitize_error($signatureVerification['error'] ?? 'License response signature verification failed.');

            $this->log_safe_error('Marketing license response signature rejected: ' . $safeError);
            $this->persist_options([
                'marketing_license_status' => 'error',
                'marketing_license_last_checked' => $checkedAt,
                'marketing_license_last_error' => $safeError,
                'marketing_license_grace_until' => '',
            ]);

            return $this->get_license_state();
        }

        if (empty($signatureVerification['configured'])) {
            $this->log_safe_warning('Marketing license response signature verification is not configured.');
        }

        $responseStatus = $this->normalize_status($decoded['status'] ?? 'inactive');
        $licenseMessage = trim(strip_tags((string) ($decoded['message'] ?? '')));
        if ($licenseMessage !== '') {
            $licenseMessage = $this->sanitize_error($licenseMessage);
        }

        $settings = [
            'marketing_license_status' => !empty($decoded['success']) ? $responseStatus : 'inactive',
            'marketing_license_domain' => trim((string) ($decoded['licensed_domain'] ?? $payload['domain'])),
            'marketing_license_last_checked' => $checkedAt,
            'marketing_license_last_error' => !empty($decoded['success']) ? '' : $licenseMessage,
            'marketing_license_grace_until' => !empty($decoded['success']) ? '' : trim((string) $this->get_option_value('marketing_license_grace_until')),
        ];

        if (empty($decoded['success']) && $licenseMessage === '') {
            $settings['marketing_license_last_error'] = 'License validation failed.';
        }

        $this->persist_options($settings);

        if (!empty($decoded['success'])) {
            $this->maybe_migrate_license_key($licenseKey);
        }

        return $this->get_license_state();
    }

    public function deactivate_license()
    {
        $state = $this->get_license_state();
        $apiBaseUrl = trim((string) $state['api_base_url']);
        $checkedAt = date('Y-m-d H:i:s');

        if ($apiBaseUrl !== '' && $state['license_key'] !== '' && $state['email'] !== '') {
            $payload = [
                'license_key' => $state['license_key'],
                'email' => $state['email'],
                'domain' => $this->get_current_domain(),
                'domain_hash' => hash('sha256', $this->get_current_domain()),
                'instance_id' => $this->get_instance_id(),
                'module_version' => $state['module_version'],
                'perfex_version' => $state['perfex_version'],
                'php_version' => PHP_VERSION,
            ];

            $response = $this->post_json($apiBaseUrl, '/licenses/deactivate', $payload);
            if (!$response['success']) {
                $this->log_safe_error('Marketing license deactivate request failed: ' . $response['error']);
            }
        }

        $this->persist_options([
            self::LEGACY_LICENSE_KEY_OPTION => '',
            self::PROTECTED_LICENSE_KEY_OPTION => '',
            'marketing_license_status' => 'inactive',
            'marketing_license_domain' => '',
            'marketing_license_last_checked' => $checkedAt,
            'marketing_license_last_error' => '',
            'marketing_license_grace_until' => '',
        ]);

        return $this->get_license_state();
    }

    public function is_feature_allowed($feature)
    {
        $state = $this->get_license_state();

        if (in_array($state['status'], ['active'], true)) {
            return true;
        }

        if ($state['status'] === 'grace' && $state['grace_active']) {
            return true;
        }

        return false;
    }

    public function get_instance_id()
    {
        $instanceId = trim((string) $this->get_option_value('marketing_license_instance_id'));
        if ($instanceId !== '') {
            return $instanceId;
        }

        try {
            $instanceId = bin2hex(random_bytes(16));
        } catch (Throwable $exception) {
            $instanceId = sha1(uniqid('marketing_license_', true));
        }

        $this->persist_options([
            'marketing_license_instance_id' => $instanceId,
        ]);

        return $instanceId;
    }

    public function get_current_domain()
    {
        $candidates = [];

        if (function_exists('site_url')) {
            $candidates[] = (string) site_url();
        }

        if (function_exists('base_url')) {
            $candidates[] = (string) base_url();
        }

        foreach ($candidates as $candidate) {
            $host = (string) parse_url($candidate, PHP_URL_HOST);
            $host = strtolower(trim($host));
            if ($host !== '') {
                return preg_replace('/:\d+$/', '', $host);
            }
        }

        return 'unknown-domain';
    }

    public function mask_license_key($licenseKey)
    {
        $licenseKey = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) $licenseKey));
        if ($licenseKey === '') {
            return '';
        }

        $tail = substr($licenseKey, -4);

        return 'XXXX-XXXX-' . $tail;
    }

    protected function handle_unreachable_server(array $state, $checkedAt, $error)
    {
        $error = $this->sanitize_error($error);
        $previouslyActive = in_array($state['status'], ['active', 'grace'], true);
        $graceUntil = trim((string) $state['grace_until']);

        if ($previouslyActive) {
            if ($graceUntil === '' || !$this->is_grace_active($graceUntil)) {
                $graceUntil = date('Y-m-d H:i:s', strtotime('+' . self::GRACE_DAYS . ' days'));
            }

            if ($this->is_grace_active($graceUntil)) {
                $this->persist_options([
                    'marketing_license_status' => 'grace',
                    'marketing_license_last_checked' => $checkedAt,
                    'marketing_license_last_error' => $error,
                    'marketing_license_grace_until' => $graceUntil,
                ]);

                return $this->get_license_state();
            }
        }

        $this->persist_options([
            'marketing_license_status' => 'error',
            'marketing_license_last_checked' => $checkedAt,
            'marketing_license_last_error' => $error,
        ]);

        return $this->get_license_state();
    }

    protected function post_json($baseUrl, $path, array $payload)
    {
        if (!function_exists('curl_init')) {
            return [
                'success' => false,
                'error' => 'cURL is required for license checks.',
            ];
        }

        $endpoint = rtrim((string) $baseUrl, '/') . $path;
        $curl = curl_init($endpoint);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $rawResponse = curl_exec($curl);
        $curlError = curl_error($curl);
        $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($rawResponse === false || $curlError !== '') {
            return [
                'success' => false,
                'error' => $this->sanitize_error($curlError !== '' ? $curlError : 'License server request failed.'),
            ];
        }

        $decoded = json_decode($rawResponse, true);
        if ($statusCode < 200 || $statusCode >= 300 || !is_array($decoded)) {
            return [
                'success' => false,
                'error' => is_array($decoded) && !empty($decoded['message'])
                    ? $this->sanitize_error($decoded['message'])
                    : 'License server returned an unexpected response.',
            ];
        }

        return [
            'success' => true,
            'body' => $decoded,
        ];
    }

    protected function resolve_api_base_url()
    {
        if (defined('MARKETING_LICENSE_API_BASE_URL')) {
            return trim((string) MARKETING_LICENSE_API_BASE_URL);
        }

        $optionValue = trim((string) $this->get_option_value('marketing_license_api_base_url'));
        if ($optionValue !== '') {
            return $optionValue;
        }

        return self::DEFAULT_API_BASE_URL;
    }

    protected function normalize_status($status)
    {
        $status = strtolower(trim((string) $status));
        $allowed = ['active', 'inactive', 'pending', 'unconfigured', 'grace', 'error'];

        return in_array($status, $allowed, true) ? $status : 'inactive';
    }

    protected function humanize_status($status)
    {
        $map = [
            'active' => 'Active',
            'inactive' => 'Inactive',
            'pending' => 'Pending',
            'unconfigured' => 'Unconfigured',
            'grace' => 'Grace Period',
            'error' => 'Check Failed',
        ];

        return $map[$status] ?? 'Inactive';
    }

    protected function is_grace_active($graceUntil)
    {
        $graceUntil = trim((string) $graceUntil);
        if ($graceUntil === '') {
            return false;
        }

        $timestamp = strtotime($graceUntil);

        return $timestamp !== false && $timestamp >= time();
    }

    protected function sanitize_license_key($licenseKey)
    {
        $licenseKey = strtoupper(trim((string) $licenseKey));
        $licenseKey = preg_replace('/[^A-Z0-9-]/', '', $licenseKey);

        return trim((string) $licenseKey);
    }

    protected function sanitize_email($email)
    {
        return strtolower(trim((string) $email));
    }

    protected function sanitize_error($message)
    {
        $message = trim(strip_tags((string) $message));
        if ($message === '') {
            return 'License server is unreachable.';
        }

        return mb_substr($message, 0, 500);
    }

    protected function log_safe_error($message)
    {
        $this->log_safe_message('error', $message);
    }

    protected function log_safe_warning($message)
    {
        $this->log_safe_message('warning', $message);
    }

    protected function log_safe_message($level, $message)
    {
        $message = (string) $message;
        if (function_exists('marketing_sanitize_log_message')) {
            $message = marketing_sanitize_log_message($message);
        }

        log_message($level, $message);
    }

    protected function get_option_value($name)
    {
        $row = $this->ci->db
            ->select('value')
            ->from($this->optionTable)
            ->where('name', $name)
            ->get()
            ->row_array();

        return isset($row['value']) ? $row['value'] : '';
    }

    protected function persist_options(array $settings)
    {
        foreach ($settings as $name => $value) {
            $value = (string) $value;
            $exists = $this->ci->db
                ->where('name', $name)
                ->count_all_results($this->optionTable) > 0;

            if ($exists) {
                $this->ci->db->where('name', $name)->update($this->optionTable, ['value' => $value]);
            } else {
                $insert = [
                    'name' => $name,
                    'value' => $value,
                ];

                if (in_array('autoload', $this->ci->db->list_fields($this->optionTable), true)) {
                    $insert['autoload'] = 1;
                }

                $this->ci->db->insert($this->optionTable, $insert);
            }
        }
    }

    protected function get_saved_license_key()
    {
        $protectedKey = trim((string) $this->get_option_value(self::PROTECTED_LICENSE_KEY_OPTION));
        if ($protectedKey !== '') {
            $decrypted = $this->decrypt_license_key($protectedKey);
            if ($decrypted !== false) {
                return $this->sanitize_license_key($decrypted);
            }

            $this->log_safe_error('Marketing protected license key decryption failed; falling back to legacy storage if available.');
        }

        return $this->sanitize_license_key($this->get_option_value(self::LEGACY_LICENSE_KEY_OPTION));
    }

    protected function build_license_key_storage_settings($licenseKey)
    {
        if ($licenseKey === '') {
            return [
                self::LEGACY_LICENSE_KEY_OPTION => '',
                self::PROTECTED_LICENSE_KEY_OPTION => '',
            ];
        }

        $encrypted = $this->encrypt_license_key($licenseKey);
        if ($encrypted['success']) {
            return [
                self::LEGACY_LICENSE_KEY_OPTION => '',
                self::PROTECTED_LICENSE_KEY_OPTION => $encrypted['value'],
            ];
        }

        $this->log_safe_warning('Marketing license key encryption is unavailable; falling back to legacy option storage.');

        return [
            self::LEGACY_LICENSE_KEY_OPTION => $licenseKey,
            self::PROTECTED_LICENSE_KEY_OPTION => '',
        ];
    }

    protected function maybe_migrate_license_key($licenseKey)
    {
        $licenseKey = $this->sanitize_license_key($licenseKey);
        if ($licenseKey === '') {
            return;
        }

        $legacyValue = trim((string) $this->get_option_value(self::LEGACY_LICENSE_KEY_OPTION));
        $protectedValue = trim((string) $this->get_option_value(self::PROTECTED_LICENSE_KEY_OPTION));

        if ($legacyValue === '' && $protectedValue !== '') {
            return;
        }

        $settings = $this->build_license_key_storage_settings($licenseKey);
        if ($settings[self::LEGACY_LICENSE_KEY_OPTION] === $legacyValue
            && $settings[self::PROTECTED_LICENSE_KEY_OPTION] === $protectedValue) {
            return;
        }

        $this->persist_options($settings);
    }

    protected function encrypt_license_key($licenseKey)
    {
        if (!$this->ensure_encryption_library()) {
            return [
                'success' => false,
            ];
        }

        try {
            $encrypted = $this->ci->encryption->encrypt($licenseKey);
        } catch (Throwable $exception) {
            $this->log_safe_warning('Marketing license key encryption failed: ' . $exception->getMessage());

            return [
                'success' => false,
            ];
        }

        if (!is_string($encrypted) || trim($encrypted) === '') {
            return [
                'success' => false,
            ];
        }

        return [
            'success' => true,
            'value' => $encrypted,
        ];
    }

    protected function decrypt_license_key($protectedKey)
    {
        if (!$this->ensure_encryption_library()) {
            return false;
        }

        try {
            $decrypted = $this->ci->encryption->decrypt($protectedKey);
        } catch (Throwable $exception) {
            $this->log_safe_warning('Marketing license key decryption failed: ' . $exception->getMessage());

            return false;
        }

        return is_string($decrypted) ? $decrypted : false;
    }

    protected function ensure_encryption_library()
    {
        if (isset($this->ci->encryption) && is_object($this->ci->encryption)
            && method_exists($this->ci->encryption, 'encrypt')
            && method_exists($this->ci->encryption, 'decrypt')) {
            return true;
        }

        try {
            $this->ci->load->library('encryption');
        } catch (Throwable $exception) {
            return false;
        }

        return isset($this->ci->encryption) && is_object($this->ci->encryption)
            && method_exists($this->ci->encryption, 'encrypt')
            && method_exists($this->ci->encryption, 'decrypt');
    }

    protected function verify_response_signature(array $payload)
    {
        $secret = $this->resolve_response_hmac_secret();
        if ($secret === '') {
            return [
                'valid' => true,
                'configured' => false,
            ];
        }

        $signature = $this->normalize_signature($payload['signature'] ?? '');
        if ($signature === '') {
            return [
                'valid' => false,
                'configured' => true,
                'error' => 'License response signature is missing.',
            ];
        }

        unset($payload['signature']);
        $canonicalPayload = $this->canonicalize_response_payload($payload);
        if ($canonicalPayload === false) {
            return [
                'valid' => false,
                'configured' => true,
                'error' => 'License response signature could not be verified.',
            ];
        }

        $expectedSignature = hash_hmac('sha256', $canonicalPayload, $secret);
        if (!hash_equals($expectedSignature, strtolower($signature))) {
            return [
                'valid' => false,
                'configured' => true,
                'error' => 'License response signature verification failed.',
            ];
        }

        return [
            'valid' => true,
            'configured' => true,
        ];
    }

    protected function resolve_response_hmac_secret()
    {
        if (defined('MARKETING_LICENSE_RESPONSE_HMAC_SECRET')) {
            return trim((string) MARKETING_LICENSE_RESPONSE_HMAC_SECRET);
        }

        $optionValue = trim((string) $this->get_option_value(self::RESPONSE_HMAC_SECRET_OPTION));
        if ($optionValue !== '') {
            return $optionValue;
        }

        if (function_exists('getenv')) {
            $envValue = getenv(self::RESPONSE_HMAC_SECRET_ENV);
            if (is_string($envValue) && trim($envValue) !== '') {
                return trim($envValue);
            }
        }

        return '';
    }

    protected function canonicalize_response_payload(array $payload)
    {
        $normalized = $this->sort_payload_keys($payload);
        $json = json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($json) ? $json : false;
    }

    protected function sort_payload_keys($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->sort_payload_keys($item);
        }

        if ($this->is_associative_array($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }

    protected function is_associative_array(array $value)
    {
        if ($value === []) {
            return false;
        }

        return array_keys($value) !== range(0, count($value) - 1);
    }

    protected function normalize_signature($signature)
    {
        if (is_array($signature) || is_object($signature) || $signature === null) {
            return '';
        }

        return strtolower(trim((string) $signature));
    }
}
