<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Marketing_mailer
{
    protected $ci;

    public function __construct()
    {
        $this->ci = &get_instance();
        $this->ci->load->model('emails_model');
        $this->ci->load->model('marketing/Marketing_suppressions_model', 'marketing_suppressions_model');
        $this->ci->load->model('marketing/Marketing_public_links_model', 'marketing_public_links_model');
        $this->ci->load->helper('email_templates');
        $this->ci->load->helper('func');
        $this->ci->load->library('email');
        $this->ci->load->library('marketing/Marketing_unsubscribe');
    }

    protected function get_marketing_smtp_config()
    {
        if ((int) get_option('marketing_smtp_enabled') !== 1) {
            return null;
        }

        $host = trim(get_option('marketing_smtp_host'));
        $port = trim(get_option('marketing_smtp_port'));

        if ($host === '' || $port === '' || !is_numeric($port) || (int) $port < 1 || (int) $port > 65535) {
            return false;
        }

        $username = trim(get_option('marketing_smtp_username'));
        $password = trim(get_option('marketing_smtp_password'));
        if ($password !== '') {
            $this->ci->load->library('encryption');
            $decrypted = $this->ci->encryption->decrypt($password);
            $password = $decrypted !== false ? $decrypted : $password;
        }

        $encryption = trim(get_option('marketing_smtp_encryption'));
        if (!in_array($encryption, ['tls', 'ssl', 'none'], true)) {
            $encryption = 'none';
        }

        $sesRegion = trim(get_option('marketing_ses_region'));

        return [
            'protocol' => 'smtp',
            'smtp_host' => $host,
            'smtp_port' => (int) $port,
            'smtp_user' => $username,
            'smtp_pass' => $password,
            'smtp_crypto' => $encryption !== 'none' ? $encryption : '',
            'smtp_timeout' => 30,
            'smtp_keepalive' => false,
            'wordwrap' => true,
            'mailtype' => 'html',
            'charset' => 'utf-8',
            'crlf' => "\r\n",
            'newline' => "\r\n",
        ];
    }

    protected function initialize_email_transport()
    {
        $this->ci->load->config('email');
        $smtpConfig = $this->get_marketing_smtp_config();

        if ($smtpConfig === false) {
            return false;
        }

        if (is_array($smtpConfig)) {
            $this->ci->email->initialize($smtpConfig);
        } else {
            $config = config_item('email');
            $this->ci->email->initialize($config ?: []);
        }

        return true;
    }

    protected function prepare_email_transport()
    {
        if (!$this->initialize_email_transport()) {
            return false;
        }

        return true;
    }

    protected function get_ses_configuration_set()
    {
        return trim((string) get_option('marketing_ses_configuration_set'));
    }

    protected function is_likely_ses_smtp_transport()
    {
        $host = strtolower(trim((string) get_option('marketing_smtp_host')));
        $region = trim((string) get_option('marketing_ses_region'));

        return strpos($host, 'amazonses.com') !== false
            || strpos($host, 'amazonaws.com') !== false
            || $region !== ''
            || $this->get_ses_configuration_set() !== '';
    }

    protected function build_safe_header_token($value, $fallback = 'na')
    {
        $token = strtolower(trim((string) $value));
        $token = preg_replace('/[^a-z0-9._-]+/i', '-', $token);
        $token = trim((string) $token, '-.');

        return $token !== '' ? $token : $fallback;
    }

    protected function build_correlation_domain($fromEmail)
    {
        $fromEmail = trim((string) $fromEmail);
        if (strpos($fromEmail, '@') !== false) {
            $domain = substr(strrchr($fromEmail, '@'), 1);
            if (is_string($domain) && $domain !== '') {
                return $this->build_safe_header_token($domain, 'localhost.localdomain');
            }
        }

        $siteHost = parse_url(site_url(), PHP_URL_HOST);
        if (is_string($siteHost) && trim($siteHost) !== '') {
            return $this->build_safe_header_token($siteHost, 'localhost.localdomain');
        }

        return 'localhost.localdomain';
    }

    protected function build_outbound_correlation_payload(array $campaign, array $recipient, $fromEmail)
    {
        $campaignId = max(0, (int) ($campaign['id'] ?? 0));
        $recipientId = max(0, (int) ($recipient['id'] ?? 0));
        $domain = $this->build_correlation_domain($fromEmail);
        try {
            $entropy = bin2hex(random_bytes(6));
        } catch (Throwable $exception) {
            $entropy = substr(hash('sha256', uniqid((string) mt_rand(), true)), 0, 12);
        }
        $seed = $campaignId . ':' . $recipientId . ':' . microtime(true) . ':' . $entropy;
        $token = substr(hash('sha256', $seed), 0, 24);
        $localPart = 'mkt.c' . $campaignId . '.r' . $recipientId . '.t' . gmdate('YmdHis') . '.' . $token;
        $rawMessageId = $localPart . '@' . $domain;

        return [
            'campaign_id' => $campaignId,
            'recipient_id' => $recipientId,
            'correlation_id' => $rawMessageId,
            'message_id' => $rawMessageId,
            'configuration_set' => $this->get_ses_configuration_set(),
            'ses_tags_header' => 'campaign_id=' . $campaignId . ', recipient_id=' . $recipientId . ', module=marketing',
        ];
    }

    protected function build_outbound_attempt_plan(array $outboundMeta)
    {
        $attempts = [[
            'include_configuration_set' => false,
            'include_ses_tags' => false,
            'label' => 'correlation_only',
        ]];

        if ($this->is_likely_ses_smtp_transport()) {
            $attempts = [[
                'include_configuration_set' => $outboundMeta['configuration_set'] !== '',
                'include_ses_tags' => true,
                'label' => 'ses_config_and_tags',
            ]];

            if ($outboundMeta['configuration_set'] !== '') {
                $attempts[] = [
                    'include_configuration_set' => true,
                    'include_ses_tags' => false,
                    'label' => 'ses_config_only',
                ];
            }

            $attempts[] = [
                'include_configuration_set' => false,
                'include_ses_tags' => false,
                'label' => 'correlation_only',
            ];
        }

        $uniqueAttempts = [];
        $seen = [];
        foreach ($attempts as $attempt) {
            $key = ($attempt['include_configuration_set'] ? '1' : '0') . ':' . ($attempt['include_ses_tags'] ? '1' : '0');
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $uniqueAttempts[] = $attempt;
        }

        return $uniqueAttempts;
    }

    protected function apply_campaign_outbound_headers(array $outboundMeta, $replyMarker = null, array $options = [])
    {
        if (!method_exists($this->ci->email, 'set_header')) {
            return [
                'configuration_set_applied' => false,
                'ses_tags_applied' => false,
                'correlation_header_applied' => false,
                'headers' => [],
            ];
        }

        $this->ci->email->set_header('X-Marketing-Correlation-ID', $outboundMeta['correlation_id']);
        $this->ci->email->set_header('X-Marketing-Campaign-ID', (string) $outboundMeta['campaign_id']);
        $this->ci->email->set_header('X-Marketing-Recipient-ID', (string) $outboundMeta['recipient_id']);

        $headers = [
            'X-Marketing-Correlation-ID' => $outboundMeta['correlation_id'],
            'X-Marketing-Campaign-ID' => (string) $outboundMeta['campaign_id'],
            'X-Marketing-Recipient-ID' => (string) $outboundMeta['recipient_id'],
        ];

        if (is_string($replyMarker) && trim($replyMarker) !== '') {
            $this->ci->email->set_header('X-Marketing-Reply-ID', $replyMarker);
            $headers['X-Marketing-Reply-ID'] = $replyMarker;
        }

        $configurationSetApplied = false;
        $sesTagsApplied = false;
        $includeConfigurationSet = !empty($options['include_configuration_set']);
        $includeSesTags = !empty($options['include_ses_tags']);

        if ($includeConfigurationSet && $outboundMeta['configuration_set'] !== '') {
            $this->ci->email->set_header('X-SES-CONFIGURATION-SET', $outboundMeta['configuration_set']);
            $configurationSetApplied = true;
            $headers['X-SES-CONFIGURATION-SET'] = $outboundMeta['configuration_set'];
        }

        if ($includeSesTags && $this->is_likely_ses_smtp_transport()) {
            $this->ci->email->set_header('X-SES-MESSAGE-TAGS', $outboundMeta['ses_tags_header']);
            $sesTagsApplied = true;
            $headers['X-SES-MESSAGE-TAGS'] = $outboundMeta['ses_tags_header'];
        }

        return [
            'configuration_set_applied' => $configurationSetApplied,
            'ses_tags_applied' => $sesTagsApplied,
            'correlation_header_applied' => true,
            'headers' => $headers,
        ];
    }

    protected function log_campaign_send_attempt(array $campaign, array $recipient, array $outboundMeta, array $attempt, array $headerStatus, $success, $failureReason = null)
    {
        $context = [
            'campaign_id' => (int) ($campaign['id'] ?? 0),
            'recipient_id' => (int) ($recipient['id'] ?? 0),
            'attempt' => (string) ($attempt['label'] ?? 'send'),
            'success' => $success ? '1' : '0',
            'correlation_id' => (string) $outboundMeta['correlation_id'],
            'configuration_set_applied' => !empty($headerStatus['configuration_set_applied']) ? '1' : '0',
            'ses_tags_applied' => !empty($headerStatus['ses_tags_applied']) ? '1' : '0',
            'applied_headers' => json_encode($headerStatus['headers'] ?? []),
        ];

        if (is_string($failureReason) && $failureReason !== '') {
            $context['failure_reason'] = $failureReason;
        }

        $parts = [];
        foreach ($context as $key => $value) {
            $parts[] = $key . '=' . $value;
        }

        log_message($success ? 'info' : 'warning', 'Marketing outbound SMTP attempt: ' . implode(' ', $parts));
    }

    protected function sanitize_debug_message($message)
    {
        $message = trim(strip_tags((string) $message));
        if ($message === '') {
            return 'send_failed';
        }

        $storedPassword = trim((string) get_option('marketing_smtp_password'));
        $storedUsername = trim((string) get_option('marketing_smtp_username'));

        foreach ([$storedPassword, $storedUsername] as $redaction) {
            if ($redaction !== '') {
                $message = str_replace($redaction, '[redacted]', $message);
            }
        }

        $safeLines = [];
        foreach (preg_split('/\r\n|\r|\n/', $message) as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            if (preg_match('/^(to|from|cc|bcc|reply-to|subject|message-id|x-marketing-reply-id)\s*:/i', $line)) {
                continue;
            }

            $safeLines[] = $line;
            if (count($safeLines) >= 3) {
                break;
            }
        }

        $message = implode(' | ', $safeLines);
        if ($message === '') {
            $message = 'send_failed';
        }

        $message = preg_replace('/smtp_pass\s*[:=]\s*[^\s]+/i', 'smtp_pass=[redacted]', $message);
        $message = preg_replace('/password\s*[:=]\s*[^\s]+/i', 'password=[redacted]', $message);
        $message = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[redacted-email]', $message);
        $message = preg_replace('#https?://[^\s]+#i', '[redacted-url]', $message);

        return mb_substr($message, 0, 300);
    }

    protected function classify_provider_failure($message)
    {
        $message = strtolower(trim((string) $message));
        if ($message === '' || $message === 'send_failed') {
            return [
                'transient' => false,
                'throttle' => false,
            ];
        }

        $throttlePatterns = [
            '/throttl(?:e|ed|ing)/i',
            '/rate\s+exceed(?:ed|s)?/i',
            '/maximum\s+sending\s+rate\s+exceeded/i',
            '/too\s+many\s+requests/i',
            '/slow\s+down/i',
        ];

        foreach ($throttlePatterns as $pattern) {
            if (preg_match($pattern, $message) === 1) {
                return [
                    'transient' => true,
                    'throttle' => true,
                ];
            }
        }

        $transientPatterns = [
            '/\b421\b/',
            '/\b450\b/',
            '/\b451\b/',
            '/\b452\b/',
            '/temporar(?:y|ily)\s+(?:unavailable|failure|error|problem)/i',
            '/connection\s+temporar(?:y|ily)\s+unavailable/i',
            '/try\s+again\s+later/i',
            '/timed?\s*out/i',
            '/timeout/i',
        ];

        foreach ($transientPatterns as $pattern) {
            if (preg_match($pattern, $message) === 1) {
                return [
                    'transient' => true,
                    'throttle' => false,
                ];
            }
        }

        return [
            'transient' => false,
            'throttle' => false,
        ];
    }

    protected function build_reply_marker(array $campaign, array $recipient)
    {
        $campaignId = isset($campaign['id']) ? (int) $campaign['id'] : 0;
        $recipientId = isset($recipient['id']) ? (int) $recipient['id'] : 0;

        return 'reply_id:' . $campaignId . ':' . $recipientId;
    }

    protected function append_reply_tracking_markup($html, $text, array $campaign, array $recipient)
    {
        $marker = $this->build_reply_marker($campaign, $recipient);
        $html = (string) $html;
        $text = (string) $text;

        $htmlMarker = '<!--' . $marker . '-->';
        if (strpos($html, $htmlMarker) === false) {
            if (stripos($html, '</body>') !== false) {
                $html = preg_replace('/<\/body>/i', $htmlMarker . '</body>', $html, 1);
            } else {
                $html .= $htmlMarker;
            }
        }

        return [
            'html' => $html,
            'text' => $text,
            'marker' => $marker,
        ];
    }

    protected function click_tracking_enabled(array $campaign)
    {
        return ((string) ($campaign['delivery_channel'] ?? 'email') === 'email')
            && ((int) ($campaign['track_clicks'] ?? 0) === 1);
    }

    protected function should_skip_click_tracking_url($url, $unsubscribeUrl = null)
    {
        $normalizedUrl = $this->ci->marketing_public_links_model->normalize_trackable_url($url);
        if ($normalizedUrl === null) {
            return true;
        }

        $normalizedUnsubscribe = $unsubscribeUrl !== null
            ? $this->ci->marketing_public_links_model->normalize_trackable_url($unsubscribeUrl)
            : null;
        if ($normalizedUnsubscribe !== null && $normalizedUrl === $normalizedUnsubscribe) {
            return true;
        }

        $parsed = parse_url($normalizedUrl);
        $path = trim((string) ($parsed['path'] ?? ''));
        if ($path !== '' && (
            strpos($path, '/marketing/track/c/') !== false
            || strpos($path, '/marketing/unsubscribe/') !== false
        )) {
            return true;
        }

        return false;
    }

    protected function get_click_tracking_url(array $campaign, array $recipient, $originalUrl, $unsubscribeUrl, array &$linkCache)
    {
        $normalizedUrl = $this->ci->marketing_public_links_model->normalize_trackable_url($originalUrl);
        if ($normalizedUrl === null || $this->should_skip_click_tracking_url($normalizedUrl, $unsubscribeUrl)) {
            return null;
        }

        if (isset($linkCache[$normalizedUrl])) {
            return $linkCache[$normalizedUrl];
        }

        $link = $this->ci->marketing_public_links_model->issue_click_link($campaign, $recipient, $normalizedUrl);
        if (!$link || empty($link['url'])) {
            return null;
        }

        $linkCache[$normalizedUrl] = (string) $link['url'];

        return $linkCache[$normalizedUrl];
    }

    protected function rewrite_click_tracking_html($html, array $campaign, array $recipient, $unsubscribeUrl, array &$linkCache)
    {
        $html = (string) $html;

        return preg_replace_callback(
            '/(<a\b[^>]*\bhref\s*=\s*)(["\'])([^"\']+)(\2)/i',
            function ($matches) use ($campaign, $recipient, $unsubscribeUrl, &$linkCache) {
                $trackedUrl = $this->get_click_tracking_url($campaign, $recipient, $matches[3], $unsubscribeUrl, $linkCache);
                if ($trackedUrl === null) {
                    return $matches[0];
                }

                return $matches[1] . $matches[2] . html_escape($trackedUrl) . $matches[4];
            },
            $html
        );
    }

    protected function rewrite_click_tracking_text($text, array $campaign, array $recipient, $unsubscribeUrl, array &$linkCache)
    {
        $text = (string) $text;

        return preg_replace_callback(
            '~https?://[^\s<>"\']+~i',
            function ($matches) use ($campaign, $recipient, $unsubscribeUrl, &$linkCache) {
                $candidate = (string) $matches[0];
                $suffix = '';

                while ($candidate !== '' && preg_match('/[)\].,!?:;]+$/', $candidate) === 1) {
                    $suffix = substr($candidate, -1) . $suffix;
                    $candidate = substr($candidate, 0, -1);
                }

                $trackedUrl = $this->get_click_tracking_url($campaign, $recipient, $candidate, $unsubscribeUrl, $linkCache);
                if ($trackedUrl === null) {
                    return $matches[0];
                }

                return $trackedUrl . $suffix;
            },
            $text
        );
    }

    public function send_campaign_message(array $campaign, array $recipient)
    {
        $fromEmail = $campaign['from_email'] ?: get_option('marketing_smtp_from_email');
        $fromName = $campaign['from_name'] ?: get_option('marketing_smtp_from_name') ?: get_option('marketing_default_from_name') ?: get_option('companyname');
        $replyTo = $campaign['reply_to_email'] ?: get_option('marketing_smtp_reply_to') ?: get_option('marketing_default_reply_to_email');
        $recipientEmail = $recipient['email'];

        if (trim((string) ($campaign['subject'] ?? '')) === '' || trim((string) ($campaign['html_content'] ?? '')) === '') {
            return [
                'success' => false,
                'error' => 'campaign_not_ready',
            ];
        }

        if (strpos((string) ($campaign['html_content'] ?? ''), '{{unsubscribe_url}}') === false) {
            return [
                'success' => false,
                'error' => 'missing_unsubscribe_url',
            ];
        }

        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL) || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'error' => 'invalid_email',
            ];
        }

        $activeSuppression = $this->ci->marketing_suppressions_model->get_active_suppression($recipientEmail);
        if ($activeSuppression !== null) {
            return [
                'success' => false,
                'suppressed' => true,
                'suppression_type' => 'email',
                'suppression_reason' => 'suppressed_' . ($activeSuppression['reason'] ?? 'email'),
                'error' => 'recipient_suppressed',
            ];
        }

        $unsubscribeUrl = $this->ci->marketing_unsubscribe->build_url($recipient);
        if ($unsubscribeUrl === null) {
            return [
                'success' => false,
                'error' => 'unsubscribe_url_generation_failed',
            ];
        }

        $htmlContent = str_replace('{{unsubscribe_url}}', $unsubscribeUrl, (string) $campaign['html_content']);
        $textContent = str_replace('{{unsubscribe_url}}', $unsubscribeUrl, (string) ($campaign['text_content'] ?? ''));
        $replyTracking = $this->append_reply_tracking_markup($htmlContent, $textContent, $campaign, $recipient);
        $htmlContent = $replyTracking['html'];
        $textContent = $replyTracking['text'];
        $outboundMeta = $this->build_outbound_correlation_payload($campaign, $recipient, $fromEmail);

        $template = new StdClass();
        $template->message = $htmlContent;
        $template->fromname = $fromName;
        $template->subject = $campaign['subject'];

        $template = parse_email_template($template);
        $message = check_for_links($template->message);

        if (!$this->prepare_email_transport()) {
            return [
                'success' => false,
                'error' => 'invalid_smtp_configuration',
            ];
        }

        $textMessage = trim($textContent);
        if ($textMessage === '') {
            $textMessage = strip_html_tags($message, '<br/>, <br>, <br />');
        }

        if ($this->click_tracking_enabled($campaign)) {
            $linkCache = [];
            $message = $this->rewrite_click_tracking_html($message, $campaign, $recipient, $unsubscribeUrl, $linkCache);
            $textMessage = $this->rewrite_click_tracking_text($textMessage, $campaign, $recipient, $unsubscribeUrl, $linkCache);
        }

        $attempts = $this->build_outbound_attempt_plan($outboundMeta);
        $success = false;
        $headerStatus = [
            'configuration_set_applied' => false,
            'ses_tags_applied' => false,
            'correlation_header_applied' => false,
            'headers' => [],
        ];
        $failureReason = null;

        foreach ($attempts as $attempt) {
            $this->ci->email->clear(true);
            $this->ci->email->set_newline(config_item('newline') ?: "\r\n");
            $this->ci->email->from($fromEmail, $fromName);
            $this->ci->email->to($recipientEmail);

            if (!empty($replyTo) && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
                $this->ci->email->reply_to($replyTo);
            }

            $headerStatus = $this->apply_campaign_outbound_headers($outboundMeta, $replyTracking['marker'], $attempt);
            $this->ci->email->subject($template->subject);
            $this->ci->email->message($message);
            $this->ci->email->set_alt_message($textMessage);

            $success = $this->ci->email->send(false);
            $debugOutput = $this->ci->email->print_debugger();
            $failureReason = $success ? null : $this->sanitize_debug_message($debugOutput);
            $this->log_campaign_send_attempt($campaign, $recipient, $outboundMeta, $attempt, $headerStatus, $success, $failureReason);

            $this->ci->email->clear(true);

            if ($success) {
                break;
            }
        }

        return [
            'success' => $success,
            'message_id' => $outboundMeta['message_id'],
            'provider_message_id' => $outboundMeta['message_id'],
            'correlation_id' => $outboundMeta['correlation_id'],
            'configuration_set' => $outboundMeta['configuration_set'],
            'configuration_set_applied' => $headerStatus['configuration_set_applied'],
            'ses_tags_applied' => $headerStatus['ses_tags_applied'],
            'transient_failure' => !$success ? $this->classify_provider_failure($failureReason)['transient'] : false,
            'provider_throttle' => !$success ? $this->classify_provider_failure($failureReason)['throttle'] : false,
            'error' => $failureReason,
        ];
    }

    public function send_test_message(string $recipientEmail)
    {
        $fromEmail = get_option('marketing_smtp_from_email') ?: get_option('marketing_default_from_email');
        $fromName = get_option('marketing_smtp_from_name') ?: get_option('marketing_default_from_name') ?: get_option('companyname');
        $replyTo = get_option('marketing_smtp_reply_to') ?: get_option('marketing_default_reply_to_email');

        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL) || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'error' => 'invalid_email',
            ];
        }

        if (!$this->prepare_email_transport()) {
            return [
                'success' => false,
                'error' => 'invalid_smtp_configuration',
            ];
        }

        $this->ci->email->clear(true);
        $this->ci->email->set_newline(config_item('newline') ?: "\r\n");
        $this->ci->email->from($fromEmail, $fromName);
        $this->ci->email->to($recipientEmail);

        if (!empty($replyTo) && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $this->ci->email->reply_to($replyTo);
        }

        if (method_exists($this->ci->email, 'set_header')) {
            $testMeta = $this->build_outbound_correlation_payload(['id' => 0], ['id' => 0], $fromEmail);
            $this->ci->email->set_header('X-Marketing-Correlation-ID', $testMeta['correlation_id']);
            if ($testMeta['configuration_set'] !== '') {
                $this->ci->email->set_header('X-SES-CONFIGURATION-SET', $testMeta['configuration_set']);
            }
        }

        $this->ci->email->subject('Marketing SMTP test email');
        $this->ci->email->message('<p>This is a test email sent from the marketing module using current marketing SMTP settings.</p>');
        $this->ci->email->set_alt_message('This is a test email sent from the marketing module using current marketing SMTP settings.');

        $success = $this->ci->email->send();
        $error = $success ? null : $this->sanitize_debug_message($this->ci->email->print_debugger(['headers']));
        $this->ci->email->clear(true);

        return [
            'success' => $success,
            'error' => $error,
        ];
    }
}
