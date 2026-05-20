<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Marketing_mailer
{
    protected $ci;
    private const BUILDER_STATE_METADATA_PATTERN = '/<!--marketing_builder_state:[A-Za-z0-9+\/=]+-->\s*/i';

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

    protected function normalize_campaign_html_for_send(array &$campaign)
    {
        $originalHtml = (string) ($campaign['html_content'] ?? '');
        $normalizedHtml = $this->normalize_unsubscribe_token_markup($originalHtml);
        $normalizedHtml = $this->normalize_social_icon_email_html($normalizedHtml);

        if ($normalizedHtml !== $originalHtml) {
            $campaign['html_content'] = $normalizedHtml;
            $campaignId = (int) ($campaign['id'] ?? 0);
            if ($campaignId > 0) {
                $this->ci->db->where('id', $campaignId);
                $updated = $this->ci->db->update(db_prefix() . 'marketing_campaigns', [
                    'html_content' => $normalizedHtml,
                ]);
                if (!$updated) {
                    log_message('warning', 'Marketing campaign html_content normalization could not be persisted: campaign_id=' . $campaignId);
                }
            }
        }

        return $normalizedHtml;
    }

    protected function normalize_social_icon_email_html($html)
    {
        $originalHtml = (string) $html;
        $html = (string) $html;
        if (trim($html) === '' || !class_exists('DOMDocument')) {
            return $html;
        }

        try {
            $builderStateMetadata = '';
            if (preg_match(self::BUILDER_STATE_METADATA_PATTERN, $html, $matches)) {
                $builderStateMetadata = (string) $matches[0];
                $html = preg_replace(self::BUILDER_STATE_METADATA_PATTERN, '', $html, 1);
            }

            $dom = new DOMDocument('1.0', 'UTF-8');
            $wrapperId = 'marketing-social-normalizer-root';
            $encoded = function_exists('mb_convert_encoding')
                ? mb_convert_encoding('<div id="' . $wrapperId . '">' . $html . '</div>', 'HTML-ENTITIES', 'UTF-8')
                : '<div id="' . $wrapperId . '">' . $html . '</div>';
            $flags = 0;
            if (defined('LIBXML_HTML_NOIMPLIED')) {
                $flags |= LIBXML_HTML_NOIMPLIED;
            }
            if (defined('LIBXML_HTML_NODEFDTD')) {
                $flags |= LIBXML_HTML_NODEFDTD;
            }

            $previousErrors = libxml_use_internal_errors(true);
            $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $encoded, $flags);
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);

            if (!$loaded) {
                return $builderStateMetadata . $html;
            }

            $root = $dom->getElementById($wrapperId);
            if (!$root) {
                return $builderStateMetadata . $html;
            }

            $changed = false;
            $links = [];
            $containers = [];
            foreach ($dom->getElementsByTagName('a') as $link) {
                $links[] = $link;
            }

            foreach ($links as $link) {
                $channel = $this->detect_social_icon_channel($link);
                if ($channel === null) {
                    continue;
                }

                $href = trim((string) $link->getAttribute('href'));
                if ($href === '' || $href === '#') {
                    $container = $this->find_social_icon_container($link);
                    if ($container instanceof DOMElement) {
                        $containers[spl_object_id($container)] = $container;
                    }
                    if ($link->parentNode) {
                        $link->parentNode->removeChild($link);
                        $changed = true;
                    }
                    continue;
                }

                $container = $this->find_social_icon_container($link);
                if ($container instanceof DOMElement) {
                    $containers[spl_object_id($container)] = $container;
                }
                $this->rewrite_social_icon_anchor($dom, $link, $channel, $href);
                $changed = true;
            }

            foreach ($containers as $container) {
                if (!$container instanceof DOMElement || !$container->parentNode) {
                    continue;
                }

                if ($this->container_has_social_icon_links($container)) {
                    continue;
                }

                if (trim(preg_replace('/\x{00a0}/u', ' ', (string) $container->textContent)) !== '') {
                    continue;
                }

                $container->parentNode->removeChild($container);
                $changed = true;
            }

            return $builderStateMetadata . ($changed ? $this->get_dom_inner_html($root) : $html);
        } catch (Throwable $exception) {
            log_message('warning', 'Marketing social icon normalization fallback used in mailer: ' . $this->sanitize_failure_reason($exception->getMessage()));

            return $originalHtml;
        }
    }

    protected function detect_social_icon_channel(DOMElement $link)
    {
        if (!$this->looks_like_social_icon_link($link)) {
            return null;
        }

        $className = ' ' . preg_replace('/\s+/', ' ', strtolower((string) $link->getAttribute('class'))) . ' ';
        foreach ($this->get_social_icon_email_definitions() as $channel => $definition) {
            foreach ($definition['classes'] as $class) {
                if (strpos($className, ' ' . strtolower($class) . ' ') !== false) {
                    return $channel;
                }
            }
        }

        foreach ([
            (string) $link->getAttribute('aria-label'),
            (string) $link->getAttribute('title'),
            (string) $link->textContent,
        ] as $value) {
            $channel = $this->map_social_icon_channel_token($value);
            if ($channel !== null) {
                return $channel;
            }
        }

        return $this->infer_social_icon_channel_from_href((string) $link->getAttribute('href'));
    }

    protected function looks_like_social_icon_link(DOMElement $link)
    {
        $className = strtolower((string) $link->getAttribute('class'));
        $style = strtolower((string) $link->getAttribute('style'));

        return strpos($className, 'mb-social-') !== false
            || (strpos($style, 'border-radius:50%') !== false
                && strpos($style, 'width:40px') !== false
                && strpos($style, 'height:40px') !== false);
    }

    protected function map_social_icon_channel_token($value)
    {
        $normalized = strtolower(preg_replace('/[^a-z0-9]+/', '', trim((string) $value)));
        $map = [
            'facebook' => 'facebook',
            'f' => 'facebook',
            'instagram' => 'instagram',
            'ig' => 'instagram',
            'x' => 'twitter',
            'twitter' => 'twitter',
            'twitterx' => 'twitter',
            'youtube' => 'youtube',
            'yt' => 'youtube',
            'linkedin' => 'linkedin',
            'in' => 'linkedin',
            'website' => 'website',
            'www' => 'website',
        ];

        return $map[$normalized] ?? null;
    }

    protected function infer_social_icon_channel_from_href($href)
    {
        $normalizedHref = strtolower(trim((string) $href));
        if ($normalizedHref === '' || $normalizedHref === '#') {
            return null;
        }

        if (strpos($normalizedHref, 'facebook.com') !== false) {
            return 'facebook';
        }

        if (strpos($normalizedHref, 'instagram.com') !== false) {
            return 'instagram';
        }

        if (strpos($normalizedHref, 'x.com') !== false || strpos($normalizedHref, 'twitter.com') !== false) {
            return 'twitter';
        }

        if (strpos($normalizedHref, 'youtube.com') !== false || strpos($normalizedHref, 'youtu.be') !== false) {
            return 'youtube';
        }

        if (strpos($normalizedHref, 'linkedin.com') !== false) {
            return 'linkedin';
        }

        if (preg_match('#^https?://#i', $normalizedHref)) {
            return 'website';
        }

        return null;
    }

    protected function find_social_icon_container(DOMElement $node)
    {
        $current = $node;

        while ($current instanceof DOMElement) {
            if (strtolower($current->tagName) === 'table') {
                return $current;
            }

            $current = $current->parentNode instanceof DOMElement ? $current->parentNode : null;
        }

        return $node->parentNode instanceof DOMElement ? $node->parentNode : null;
    }

    protected function container_has_social_icon_links(DOMElement $container)
    {
        foreach ($container->getElementsByTagName('a') as $link) {
            if ($link instanceof DOMElement && $this->detect_social_icon_channel($link) !== null) {
                return true;
            }
        }

        return false;
    }

    protected function campaign_contains_broken_social_block_markup($html)
    {
        $html = (string) $html;
        if (trim($html) === '' || !class_exists('DOMDocument')) {
            return false;
        }

        try {
            $dom = new DOMDocument('1.0', 'UTF-8');
            $encoded = function_exists('mb_convert_encoding')
                ? mb_convert_encoding('<div id="marketing-social-guard-root">' . $html . '</div>', 'HTML-ENTITIES', 'UTF-8')
                : '<div id="marketing-social-guard-root">' . $html . '</div>';
            $flags = 0;
            if (defined('LIBXML_HTML_NOIMPLIED')) {
                $flags |= LIBXML_HTML_NOIMPLIED;
            }
            if (defined('LIBXML_HTML_NODEFDTD')) {
                $flags |= LIBXML_HTML_NODEFDTD;
            }

            $previousErrors = libxml_use_internal_errors(true);
            $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $encoded, $flags);
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);

            if (!$loaded) {
                return false;
            }

            $hasSocialLinks = false;
            $hasSocialImages = false;
            foreach ($dom->getElementsByTagName('a') as $link) {
                if (!$link instanceof DOMElement) {
                    continue;
                }

                if ($this->detect_social_icon_channel($link) === null) {
                    continue;
                }

                $href = trim((string) $link->getAttribute('href'));
                if ($href === '' || $href === '#') {
                    continue;
                }

                $hasSocialLinks = true;
                foreach ($link->getElementsByTagName('img') as $image) {
                    if ($image instanceof DOMElement && strpos((string) $image->getAttribute('src'), 'assets/img/social/') !== false) {
                        $hasSocialImages = true;
                        break 2;
                    }
                }
            }

            return $hasSocialLinks && !$hasSocialImages;
        } catch (Throwable $exception) {
            log_message('warning', 'Marketing social icon send guard fallback used in mailer: ' . $this->sanitize_failure_reason($exception->getMessage()));

            return false;
        }
    }

    protected function rewrite_social_icon_anchor(DOMDocument $dom, DOMElement $link, $channel, $href)
    {
        $definitions = $this->get_social_icon_email_definitions();
        if (!isset($definitions[$channel])) {
            return;
        }

        $definition = $definitions[$channel];
        while ($link->firstChild) {
            $link->removeChild($link->firstChild);
        }

        $img = $dom->createElement('img');
        $img->setAttribute('src', $definition['url']);
        $img->setAttribute('width', '40');
        $img->setAttribute('height', '40');
        $img->setAttribute('alt', $definition['label']);
        $img->setAttribute('style', 'display:block;border:0;outline:none;text-decoration:none;width:40px;height:40px;border-radius:50%;');
        $link->appendChild($img);

        $link->setAttribute('href', $href);
        $link->setAttribute('target', '_blank');
        $link->setAttribute('rel', 'noopener noreferrer');
        $link->setAttribute('title', $definition['label']);
        $link->setAttribute('aria-label', $definition['label']);
        $link->setAttribute('style', 'display:inline-block;vertical-align:middle;width:40px;height:40px;line-height:40px;margin:0 6px;text-align:center;text-decoration:none;border-radius:50%;');
    }

    protected function get_social_icon_email_definitions()
    {
        return [
            'facebook' => ['label' => 'Facebook', 'url' => module_dir_url('marketing', 'assets/img/social/facebook.png'), 'classes' => ['mb-social-facebook']],
            'instagram' => ['label' => 'Instagram', 'url' => module_dir_url('marketing', 'assets/img/social/instagram.png'), 'classes' => ['mb-social-instagram']],
            'twitter' => ['label' => 'X', 'url' => module_dir_url('marketing', 'assets/img/social/x.png'), 'classes' => ['mb-social-twitter', 'mb-social-x']],
            'youtube' => ['label' => 'YouTube', 'url' => module_dir_url('marketing', 'assets/img/social/youtube.png'), 'classes' => ['mb-social-youtube']],
            'linkedin' => ['label' => 'LinkedIn', 'url' => module_dir_url('marketing', 'assets/img/social/linkedin.png'), 'classes' => ['mb-social-linkedin']],
            'website' => ['label' => 'Website', 'url' => module_dir_url('marketing', 'assets/img/social/website.png'), 'classes' => ['mb-social-website']],
        ];
    }

    protected function get_dom_inner_html(DOMNode $node)
    {
        $html = '';
        foreach ($node->childNodes as $childNode) {
            $html .= $node->ownerDocument->saveHTML($childNode);
        }

        return $html;
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

    protected function get_default_campaign_sender_values()
    {
        $fromEmail = trim((string) get_option('marketing_smtp_from_email'));
        $fromName = trim((string) get_option('marketing_smtp_from_name'));
        $replyTo = trim((string) get_option('marketing_smtp_reply_to'));

        if ($fromName === '') {
            $fromName = trim((string) get_option('marketing_default_from_name'));
        }
        if ($fromName === '') {
            $fromName = trim((string) get_option('companyname'));
        }
        if ($replyTo === '') {
            $replyTo = trim((string) get_option('marketing_default_reply_to_email'));
        }

        return [
            'from_name' => $fromName,
            'from_email' => $fromEmail,
            'reply_to_email' => $replyTo,
        ];
    }

    protected function resolve_campaign_sender(array $campaign)
    {
        $defaults = $this->get_default_campaign_sender_values();
        $campaignFromEmail = trim((string) ($campaign['from_email'] ?? ''));
        $campaignFromName = trim((string) ($campaign['from_name'] ?? ''));
        $campaignReplyTo = trim((string) ($campaign['reply_to_email'] ?? ''));
        $configuredFromEmail = trim((string) ($defaults['from_email'] ?? ''));

        if ($campaignFromEmail !== '' && $configuredFromEmail !== '' && strcasecmp($campaignFromEmail, $configuredFromEmail) !== 0) {
            return [
                'valid' => false,
                'error' => 'campaign_from_email_mismatch',
                'message' => 'Campaign From Email must match the verified Marketing SMTP From Email (' . $configuredFromEmail . ') or be left blank.',
            ];
        }

        $fromEmail = $campaignFromEmail !== '' ? $campaignFromEmail : $configuredFromEmail;
        $fromName = $campaignFromName !== '' ? $campaignFromName : trim((string) ($defaults['from_name'] ?? ''));
        $replyTo = $campaignReplyTo !== '' ? $campaignReplyTo : trim((string) ($defaults['reply_to_email'] ?? ''));

        return [
            'valid' => true,
            'from_email' => $fromEmail,
            'from_name' => $fromName,
            'reply_to_email' => $replyTo,
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
        $attemptToken = $this->build_safe_header_token((string) ($recipient['outbound_attempt_token'] ?? ''), '');
        if ($attemptToken === '') {
            try {
                $entropy = bin2hex(random_bytes(6));
            } catch (Throwable $exception) {
                $entropy = substr(hash('sha256', uniqid((string) mt_rand(), true)), 0, 12);
            }
            $seed = $campaignId . ':' . $recipientId . ':' . microtime(true) . ':' . $entropy;
            $attemptToken = substr(hash('sha256', $seed), 0, 24);
        }
        $messageToken = substr($attemptToken, 0, 24);
        $localPart = 'mkt.c' . $campaignId . '.r' . $recipientId . '.a' . $messageToken;
        $rawMessageId = $localPart . '@' . $domain;

        return [
            'campaign_id' => $campaignId,
            'recipient_id' => $recipientId,
            'outbound_attempt_token' => $attemptToken,
            'correlation_id' => $rawMessageId,
            'message_id' => $rawMessageId,
            'configuration_set' => $this->get_ses_configuration_set(),
            'ses_tags_header' => 'campaign_id=' . $campaignId
                . ', recipient_id=' . $recipientId
                . ', outbound_attempt_token=' . $attemptToken
                . ', module=marketing',
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
        if (!empty($outboundMeta['outbound_attempt_token'])) {
            $this->ci->email->set_header('X-Marketing-Outbound-Attempt-ID', (string) $outboundMeta['outbound_attempt_token']);
        }

        $headers = [
            'X-Marketing-Correlation-ID' => $outboundMeta['correlation_id'],
            'X-Marketing-Campaign-ID' => (string) $outboundMeta['campaign_id'],
            'X-Marketing-Recipient-ID' => (string) $outboundMeta['recipient_id'],
        ];
        if (!empty($outboundMeta['outbound_attempt_token'])) {
            $headers['X-Marketing-Outbound-Attempt-ID'] = (string) $outboundMeta['outbound_attempt_token'];
        }

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
            'outbound_attempt_token' => (string) ($outboundMeta['outbound_attempt_token'] ?? ''),
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
                'rendering' => false,
                'normalized_failure_code' => 'provider_permanent_failure',
            ];
        }

        $renderingPatterns = [
            '/rendering[_\s-]*failure/i',
            '/rendering[_\s-]*failed/i',
            '/template\s+render(?:ing)?\s+failed/i',
            '/unable\s+to\s+render/i',
        ];

        foreach ($renderingPatterns as $pattern) {
            if (preg_match($pattern, $message) === 1) {
                return [
                    'transient' => false,
                    'throttle' => false,
                    'rendering' => true,
                    'normalized_failure_code' => 'provider_rendering_failure',
                ];
            }
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
                    'rendering' => false,
                    'normalized_failure_code' => 'provider_throttle',
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
                    'rendering' => false,
                    'normalized_failure_code' => 'provider_transient_failure',
                ];
            }
        }

        return [
            'transient' => false,
            'throttle' => false,
            'rendering' => false,
            'normalized_failure_code' => 'provider_permanent_failure',
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

    protected function build_personalization_replacements(array $recipient, $unsubscribeUrl)
    {
        $contact = $this->get_recipient_contact_profile($recipient);
        $email = trim((string) ($contact['email'] ?? ($recipient['email'] ?? '')));
        $firstName = trim((string) ($contact['first_name'] ?? ''));
        $lastName = trim((string) ($contact['last_name'] ?? ''));
        $fullName = trim((string) ($contact['full_name'] ?? ''));
        $company = trim((string) ($contact['company'] ?? ''));

        if ($fullName === '') {
            $fullName = trim($firstName . ' ' . $lastName);
        }

        if ($firstName === '' && $fullName !== '') {
            $parts = preg_split('/\s+/', $fullName);
            $firstName = is_array($parts) && !empty($parts[0]) ? trim((string) $parts[0]) : '';
        }

        if ($lastName === '' && $fullName !== '' && $firstName !== '' && stripos($fullName, $firstName) === 0) {
            $lastName = trim((string) substr($fullName, strlen($firstName)));
        }

        return [
            '{{first_name}}' => $firstName,
            '{{last_name}}' => $lastName,
            '{{full_name}}' => $fullName,
            '{{email}}' => $email,
            '{{company}}' => $company,
            '{{unsubscribe_url}}' => (string) $unsubscribeUrl,
        ];
    }

    protected function apply_personalization_replacements($content, array $replacements)
    {
        if (!is_string($content) || $content === '') {
            return (string) $content;
        }

        $content = $this->normalize_unsubscribe_token_markup($content);

        return strtr($content, $replacements);
    }

    protected function campaign_has_unsubscribe_token($content)
    {
        $content = (string) $content;
        $candidates = [$content];
        $decoded = rawurldecode($content);

        if ($decoded !== $content) {
            $candidates[] = $decoded;
        }

        $entityDecoded = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($entityDecoded !== $content) {
            $candidates[] = $entityDecoded;
        }

        foreach ($candidates as $candidate) {
            $haystack = strtolower((string) $candidate);

            if (strpos($haystack, '{{unsubscribe_url}}') !== false
                || strpos($haystack, '%7b%7bunsubscribe_url%7d%7d') !== false
                || strpos($haystack, '&#123;&#123;unsubscribe_url&#125;&#125;') !== false
                || strpos($haystack, '&lcub;&lcub;unsubscribe_url&rcub;&rcub;') !== false
                || strpos($haystack, '\\u007b\\u007bunsubscribe_url\\u007d\\u007d') !== false
                || strpos($haystack, 'href="{{unsubscribe_url}}"') !== false
                || strpos($haystack, "href='{{unsubscribe_url}}'") !== false
                || strpos($haystack, 'href=&quot;{{unsubscribe_url}}&quot;') !== false
                || strpos($haystack, 'href=&#39;{{unsubscribe_url}}&#39;') !== false
                || strpos($haystack, '/marketing/unsubscribe/') !== false
            ) {
                return true;
            }
        }

        return false;
    }

    protected function normalize_unsubscribe_token_markup($content)
    {
        if (!is_string($content) || $content === '') {
            return (string) $content;
        }

        return str_ireplace(
            [
                '%7B%7Bunsubscribe_url%7D%7D',
                '&#123;&#123;unsubscribe_url&#125;&#125;',
                '&lcub;&lcub;unsubscribe_url&rcub;&rcub;',
                '\\u007b\\u007bunsubscribe_url\\u007d\\u007d',
            ],
            '{{unsubscribe_url}}',
            $content
        );
    }

    protected function get_recipient_contact_profile(array $recipient)
    {
        $contactId = (int) ($recipient['marketing_contact_id'] ?? 0);
        $contactsTable = db_prefix() . 'marketing_contacts';
        $profile = [
            'email' => trim((string) ($recipient['email'] ?? '')),
            'first_name' => '',
            'last_name' => '',
            'full_name' => '',
            'company' => '',
        ];

        if ($contactId <= 0 || !$this->ci->db->table_exists($contactsTable)) {
            return $profile;
        }

        $select = ['email'];
        foreach (['first_name', 'last_name', 'full_name', 'company'] as $column) {
            if ($this->ci->db->field_exists($column, $contactsTable)) {
                $select[] = $column;
            }
        }

        $row = $this->ci->db
            ->select(implode(', ', $select))
            ->from($contactsTable)
            ->where('id', $contactId)
            ->get()
            ->row_array();

        if (!is_array($row)) {
            return $profile;
        }

        return array_merge($profile, [
            'email' => trim((string) ($row['email'] ?? $profile['email'])),
            'first_name' => trim((string) ($row['first_name'] ?? '')),
            'last_name' => trim((string) ($row['last_name'] ?? '')),
            'full_name' => trim((string) ($row['full_name'] ?? '')),
            'company' => trim((string) ($row['company'] ?? '')),
        ]);
    }

    public function send_campaign_message(array $campaign, array $recipient)
    {
        $sender = $this->resolve_campaign_sender($campaign);
        if (empty($sender['valid'])) {
            return [
                'success' => false,
                'error' => (string) ($sender['error'] ?? 'campaign_sender_invalid'),
                'message' => (string) ($sender['message'] ?? 'Campaign sender configuration is invalid.'),
                'campaign_configuration_error' => true,
            ];
        }

        $fromEmail = (string) ($sender['from_email'] ?? '');
        $fromName = (string) ($sender['from_name'] ?? '');
        $replyTo = (string) ($sender['reply_to_email'] ?? '');
        $recipientEmail = $recipient['email'];
        $campaignHtmlContent = trim($this->normalize_campaign_html_for_send($campaign));

        if (trim((string) ($campaign['subject'] ?? '')) === '' || $campaignHtmlContent === '') {
            return [
                'success' => false,
                'error' => 'campaign_not_ready',
            ];
        }

        if ($this->campaign_contains_broken_social_block_markup($campaignHtmlContent)) {
            return [
                'success' => false,
                'error' => 'broken_social_icon_markup',
                'message' => 'Campaign social icon markup could not be normalized to PNG images.',
            ];
        }

        if (!$this->campaign_has_unsubscribe_token($campaignHtmlContent)) {
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

        $personalization = $this->build_personalization_replacements($recipient, $unsubscribeUrl);
        $subject = $this->apply_personalization_replacements((string) ($campaign['subject'] ?? ''), $personalization);
        $htmlContent = $this->apply_personalization_replacements($campaignHtmlContent, $personalization);
        $textContent = $this->apply_personalization_replacements((string) ($campaign['text_content'] ?? ''), $personalization);
        $replyTracking = $this->append_reply_tracking_markup($htmlContent, $textContent, $campaign, $recipient);
        $htmlContent = $replyTracking['html'];
        $textContent = $replyTracking['text'];
        $outboundMeta = $this->build_outbound_correlation_payload($campaign, $recipient, $fromEmail);

        $template = new StdClass();
        $template->message = $htmlContent;
        $template->fromname = $fromName;
        $template->subject = $subject;

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

        $failureClassification = !$success
            ? $this->classify_provider_failure($failureReason)
            : [
                'transient' => false,
                'throttle' => false,
                'rendering' => false,
                'normalized_failure_code' => null,
            ];

        return [
            'success' => $success,
            'message_id' => $outboundMeta['message_id'],
            'provider_message_id' => $outboundMeta['message_id'],
            'correlation_id' => $outboundMeta['correlation_id'],
            'configuration_set' => $outboundMeta['configuration_set'],
            'configuration_set_applied' => $headerStatus['configuration_set_applied'],
            'ses_tags_applied' => $headerStatus['ses_tags_applied'],
            'transient_failure' => !$success ? $failureClassification['transient'] : false,
            'provider_throttle' => !$success ? $failureClassification['throttle'] : false,
            'provider_rendering_failure' => !$success ? $failureClassification['rendering'] : false,
            'provider_failure_code' => !$success ? ($failureClassification['normalized_failure_code'] ?? null) : null,
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
