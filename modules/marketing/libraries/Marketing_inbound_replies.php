<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Marketing_inbound_replies
{
    protected $ci;

    public function __construct()
    {
        $this->ci = &get_instance();
        $this->ci->load->model('marketing/Marketing_campaigns_model', 'marketing_campaigns_model');
        $this->ci->load->model('marketing/Marketing_conversations_model', 'marketing_conversations_model');
        $this->ci->load->model('marketing/Marketing_events_model', 'marketing_events_model');
        $this->ci->load->model('marketing/Marketing_reply_leads_model', 'marketing_reply_leads_model');
        $this->ci->load->model('marketing/Marketing_audiences_model', 'marketing_audiences_model');
    }

    public function process(array $payload, array $options = [])
    {
        $headers = $this->normalize_headers($options['headers'] ?? ($payload['headers'] ?? []));
        $rawBody = (string) ($options['raw_body'] ?? ($payload['_raw_body'] ?? ''));
        $sourceProvider = trim((string) ($options['source_provider'] ?? 'inbound_email'));
        $sourceProvider = $sourceProvider !== '' ? $sourceProvider : 'inbound_email';
        $eventProvider = trim((string) ($options['event_provider'] ?? 'inbound_email'));
        $eventProvider = $eventProvider !== '' ? $eventProvider : 'inbound_email';

        $senderEmail = $this->normalize_email($payload['sender_email'] ?? ($payload['from'] ?? ($payload['from_email'] ?? '')));
        $senderName = $this->sanitize_sender_name($payload['sender_name'] ?? ($payload['from_name'] ?? ''));
        $subject = $this->sanitize_subject($payload['subject'] ?? '');
        $bodyHtml = $this->sanitize_html($payload['body_html'] ?? ($payload['html'] ?? ''));
        $bodyText = $this->sanitize_text($payload['body_text'] ?? ($payload['text'] ?? ($bodyHtml !== '' ? $bodyHtml : '')));
        $messagePreview = $bodyText !== '' ? $bodyText : strip_tags($bodyHtml);
        $messageIdentifier = $this->extract_message_identifier($payload, $headers);
        $providerMessageId = $this->extract_provider_message_id($payload, $headers, $messageIdentifier);
        $inReplyTo = $this->normalize_message_identifier($payload['in_reply_to'] ?? ($headers['in-reply-to'] ?? ''));
        $referencesHeader = $this->sanitize_references_header($payload['references'] ?? ($headers['references'] ?? ''));
        $referenceIdentifiers = $this->extract_message_identifiers($referencesHeader);
        $receivedAt = $this->resolve_received_at($payload, $headers);
        $mailboxUid = trim((string) ($options['mailbox_uid'] ?? ($payload['mailbox_uid'] ?? '')));
        $mailboxFolder = trim((string) ($options['mailbox_folder'] ?? ($payload['mailbox_folder'] ?? '')));
        $dedupeKey = $this->build_dedupe_key($senderEmail, $subject, $messagePreview, $messageIdentifier, $mailboxUid, $receivedAt);

        if ($senderEmail === '' || ($bodyText === '' && $bodyHtml === '')) {
            return [
                'success' => false,
                'status' => 'invalid',
                'message' => 'Sender email and message body are required.',
            ];
        }

        $replyEventDedupeId = $providerMessageId !== ''
            ? $providerMessageId
            : ($messageIdentifier !== '' ? $messageIdentifier : 'inbound_reply:' . sha1($dedupeKey));
        $eventLock = $this->ci->marketing_events_model->acquire_event_lock([
            'provider' => $eventProvider,
            'event_type' => 'reply',
            'provider_message_id' => $providerMessageId !== '' ? $providerMessageId : ($messageIdentifier !== '' ? $messageIdentifier : null),
            'provider_event_id' => $replyEventDedupeId,
            'email' => $senderEmail,
            'event_at' => $receivedAt,
        ]);
        $eventLockKey = trim((string) ($eventLock['lock_key'] ?? ''));
        if (empty($eventLock['acquired'])) {
            return [
                'success' => true,
                'status' => 'processing',
                'message' => 'Inbound email is already being processed.',
                'duplicate_message' => true,
            ];
        }

        try {
            $replyMatch = $this->extract_reply_tracking_identifier($subject, $bodyHtml, $bodyText, $headers, $rawBody);
            $recipient = null;
            $campaignId = 0;
            $contactId = 0;
            $matchMode = 'unmatched';
            $matchConfidence = 'none';
            $warning = trim((string) ($options['authentication_warning'] ?? ''));

            if ($replyMatch !== null) {
                $candidateRecipient = $this->ci->marketing_campaigns_model->get_recipient_by_id((int) $replyMatch['recipient_id']);
                if ($candidateRecipient && (int) $candidateRecipient['campaign_id'] === (int) $replyMatch['campaign_id']) {
                    if ($this->sender_matches_recipient($senderEmail, $candidateRecipient)) {
                        $recipient = $candidateRecipient;
                        $campaignId = (int) $replyMatch['campaign_id'];
                        $contactId = (int) ($candidateRecipient['marketing_contact_id'] ?? 0);
                        $matchMode = 'reply_id';
                        $matchConfidence = 'strong';
                    } else {
                        $warning = $warning !== '' ? $warning : 'reply_id_sender_mismatch';
                        $matchMode = 'reply_id_sender_mismatch';
                        $matchConfidence = 'suspicious';
                    }
                } else {
                    $warning = $warning !== '' ? $warning : 'reply_id_not_matched';
                }
            }

            if ($recipient === null) {
                $recipient = $this->match_recipient_by_identifiers($senderEmail, $campaignId, array_merge(
                    $inReplyTo !== '' ? [$inReplyTo] : [],
                    $referenceIdentifiers
                ));

                if ($recipient) {
                    $campaignId = (int) ($recipient['campaign_id'] ?? 0);
                    $contactId = (int) ($recipient['marketing_contact_id'] ?? 0);
                    $matchMode = 'message_identifier';
                    $matchConfidence = 'strong';
                }
            }

            if ($recipient === null) {
                $fallbackRecipient = $this->ci->marketing_campaigns_model->find_recipient_for_event(null, null, $senderEmail, []);
                if (!empty($fallbackRecipient) && $this->sender_matches_recipient($senderEmail, $fallbackRecipient)) {
                    $recipient = $fallbackRecipient;
                    $campaignId = (int) ($recipient['campaign_id'] ?? 0);
                    $contactId = (int) ($recipient['marketing_contact_id'] ?? 0);
                    $matchMode = 'sender_email_unique';
                    $matchConfidence = 'safe_fallback';
                    if ($warning === '') {
                        $warning = 'sender_email_unique_fallback';
                    }
                }
            }

            $contact = $this->find_contact_by_email($senderEmail);
            $senderWasExistingMarketingContact = !empty($contact);
            if (!empty($contact)) {
                $contactId = (int) ($contact['id'] ?? 0);
            } elseif ($contactId <= 0) {
                $createdContactId = $this->ci->marketing_audiences_model->upsert_contact([
                    'email' => $senderEmail,
                    'full_name' => $senderName,
                    'status' => 'active',
                    'consent_status' => 'unknown',
                    'source_type' => 'reply',
                ]);
                if ($createdContactId) {
                    $contactId = (int) $createdContactId;
                }
            }

            $conversation = null;
            $messageId = 0;
            $messageStoreResult = [
                'success' => false,
                'inserted' => false,
                'duplicate' => false,
                'id' => 0,
            ];

            if ($this->ci->marketing_conversations_model->tables_available()) {
                $conversation = $this->ci->marketing_conversations_model->find_or_create_conversation([
                    'campaign_id' => $campaignId > 0 ? $campaignId : null,
                    'marketing_contact_id' => $contactId > 0 ? $contactId : null,
                    'email' => $senderEmail,
                    'channel' => 'email',
                    'provider' => 'inbound_email',
                    'subject' => $subject,
                    'last_message_at' => $receivedAt,
                    'status' => 'open',
                    'source_type' => 'email_reply',
                ]);

                if ($conversation) {
                    $messageStoreResult = $this->ci->marketing_conversations_model->add_message_idempotent([
                        'conversation_id' => (int) $conversation['id'],
                        'campaign_id' => $campaignId > 0 ? $campaignId : null,
                        'marketing_contact_id' => $contactId > 0 ? $contactId : null,
                        'direction' => 'inbound',
                        'channel' => 'email',
                        'email' => $senderEmail,
                        'from_email' => $senderEmail,
                        'subject' => $subject,
                        'message' => $messagePreview,
                        'body_text' => $bodyText,
                        'body_html' => $bodyHtml !== '' ? $bodyHtml : null,
                        'provider' => 'inbound_email',
                        'provider_message_id' => $providerMessageId !== '' ? $providerMessageId : null,
                        'context_provider_message_id' => $inReplyTo !== '' ? $inReplyTo : null,
                        'message_identifier' => $messageIdentifier !== '' ? $messageIdentifier : null,
                        'in_reply_to' => $inReplyTo !== '' ? $inReplyTo : null,
                        'references_header' => $referencesHeader !== '' ? $referencesHeader : null,
                        'mailbox_uid' => $mailboxUid !== '' ? $mailboxUid : null,
                        'mailbox_folder' => $mailboxFolder !== '' ? $mailboxFolder : null,
                        'received_at' => $receivedAt,
                        'headers_json' => $this->safe_json_encode($headers),
                        'source_provider' => $sourceProvider,
                        'dedupe_key' => $dedupeKey,
                        'datecreated' => $receivedAt,
                    ]);
                    $messageId = (int) ($messageStoreResult['id'] ?? 0);
                }
            }

            $campaignRecipientId = $recipient ? (int) ($recipient['id'] ?? 0) : 0;
            $campaign = $campaignId > 0 ? $this->ci->marketing_campaigns_model->get($campaignId) : null;
            $leadResult = [
                'lead_id' => null,
                'lead_status' => 'not_converted',
                'lead_action' => 'disabled',
                'event_type' => 'reply_lead_skipped',
                'lead_error' => null,
            ];
            $campaignRecipientMatched = $campaignId > 0 && $campaignRecipientId > 0;

            if (!empty($messageStoreResult['duplicate'])) {
                $leadResult = [
                    'lead_id' => null,
                    'lead_status' => 'duplicate',
                    'lead_action' => 'skipped_duplicate',
                    'event_type' => 'reply_lead_skipped',
                    'lead_error' => null,
                ];
            } elseif ($messageId <= 0) {
                $leadResult = [
                    'lead_id' => null,
                    'lead_status' => 'not_converted',
                    'lead_action' => 'skipped',
                    'event_type' => 'reply_lead_skipped',
                    'lead_error' => 'Inbound email message could not be stored safely for automatic lead conversion.',
                ];
            } elseif (!$campaignRecipientMatched) {
                $leadResult = [
                    'lead_id' => null,
                    'lead_status' => 'not_converted',
                    'lead_action' => 'skipped',
                    'event_type' => 'reply_lead_skipped',
                    'lead_error' => 'Skipped because reply is not linked to a campaign recipient',
                ];
            } else {
                try {
                    $leadResult = $this->ci->marketing_reply_leads_model->convert_reply_to_lead([
                        'email' => $senderEmail,
                        'sender_name' => $senderName,
                        'subject' => $subject,
                        'preview' => $messagePreview,
                        'campaign_id' => $campaignId > 0 ? $campaignId : null,
                        'campaign_name' => $campaign['name'] ?? '',
                        'conversation_id' => $conversation['id'] ?? null,
                        'message_id' => $messageId ?: null,
                        'existing_marketing_contact' => $senderWasExistingMarketingContact,
                        'received_at' => $receivedAt,
                    ]);
                } catch (Throwable $e) {
                    $safeLeadError = $this->sanitize_error_message($e->getMessage());
                    log_message('error', 'Marketing reply-to-lead failed safely: email=' . $senderEmail . ' message=' . $safeLeadError);
                    $leadResult = [
                        'lead_id' => null,
                        'lead_status' => 'failed',
                        'lead_action' => 'failed',
                        'event_type' => 'reply_lead_failed',
                        'lead_error' => 'Native Perfex lead creation failed.',
                    ];
                }
            }

            if (!empty($leadResult['event_type'])) {
                $this->ci->marketing_events_model->add_event_idempotent([
                    'campaign_id' => $campaignId > 0 ? $campaignId : null,
                    'campaign_recipient_id' => $campaignRecipientId > 0 ? $campaignRecipientId : null,
                    'marketing_contact_id' => $contactId > 0 ? $contactId : null,
                    'event_type' => $leadResult['event_type'],
                    'provider' => $eventProvider,
                    'provider_event_id' => 'reply_lead:' . $replyEventDedupeId,
                    'provider_message_id' => $providerMessageId !== '' ? $providerMessageId : ($messageIdentifier !== '' ? $messageIdentifier : null),
                    'email' => $senderEmail,
                    'event_at' => $receivedAt,
                    'payload_json' => $this->safe_json_encode([
                        'lead_id' => $leadResult['lead_id'] ?? null,
                        'lead_status' => $leadResult['lead_status'] ?? null,
                        'lead_action' => $leadResult['lead_action'] ?? null,
                        'lead_error' => $leadResult['lead_error'] ?? null,
                        'conversation_id' => $conversation['id'] ?? null,
                        'message_id' => $messageId ?: null,
                        'subject' => $subject,
                        'match_mode' => $matchMode,
                        'match_confidence' => $matchConfidence,
                        'authenticated' => !empty($options['authenticated']),
                        'source_provider' => $sourceProvider,
                    ]),
                ]);
            }

            $replyEventResult = $this->ci->marketing_events_model->add_event_idempotent([
                'campaign_id' => $campaignId > 0 ? $campaignId : null,
                'campaign_recipient_id' => $campaignRecipientId > 0 ? $campaignRecipientId : null,
                'marketing_contact_id' => $contactId > 0 ? $contactId : null,
                'event_type' => 'reply',
                'provider' => $eventProvider,
                'provider_event_id' => $replyEventDedupeId,
                'provider_message_id' => $providerMessageId !== '' ? $providerMessageId : ($messageIdentifier !== '' ? $messageIdentifier : null),
                'email' => $senderEmail,
                'event_at' => $receivedAt,
                'payload_json' => $this->safe_json_encode([
                    'match_mode' => $matchMode,
                    'match_confidence' => $matchConfidence,
                    'conversation_id' => $conversation['id'] ?? null,
                    'message_id' => $messageId ?: null,
                    'subject' => $subject,
                    'authenticated' => !empty($options['authenticated']),
                    'warning' => $warning !== '' ? $warning : null,
                    'duplicate_message' => !empty($messageStoreResult['duplicate']),
                    'message_identifier' => $messageIdentifier !== '' ? $messageIdentifier : null,
                    'in_reply_to' => $inReplyTo !== '' ? $inReplyTo : null,
                    'source_provider' => $sourceProvider,
                ]),
            ]);

            return [
                'success' => true,
                'status' => !empty($messageStoreResult['duplicate']) ? 'duplicate' : 'ok',
                'message' => !empty($messageStoreResult['duplicate']) ? 'Inbound email duplicate ignored.' : 'Inbound email processed.',
                'campaign_id' => $campaignId > 0 ? $campaignId : null,
                'contact_id' => $contactId > 0 ? $contactId : null,
                'conversation_id' => $conversation['id'] ?? null,
                'match_mode' => $matchMode,
                'matched_by' => $matchMode,
                'warning' => $warning !== '' ? $warning : null,
                'authenticated' => !empty($options['authenticated']),
                'duplicate_message' => !empty($messageStoreResult['duplicate']),
                'event_duplicate' => !empty($replyEventResult['duplicate']),
                'lead_id' => $leadResult['lead_id'] ?? null,
                'lead_status' => $leadResult['lead_status'] ?? null,
                'lead_action' => $leadResult['lead_action'] ?? null,
                'lead_error' => $leadResult['lead_error'] ?? null,
                'message_id' => $messageId ?: null,
            ];
        } finally {
            if ($eventLockKey !== '') {
                $this->ci->marketing_events_model->release_event_lock($eventLockKey);
            }
        }
    }

    protected function match_recipient_by_identifiers($senderEmail, $campaignId, array $identifiers)
    {
        foreach ($identifiers as $identifier) {
            $identifier = $this->normalize_message_identifier($identifier);
            if ($identifier === '') {
                continue;
            }

            $recipient = $this->ci->marketing_campaigns_model->find_recipient_for_event(
                $identifier,
                $identifier,
                null,
                ['campaign_id' => (int) $campaignId]
            );

            if (!empty($recipient) && $this->sender_matches_recipient($senderEmail, $recipient)) {
                return $recipient;
            }
        }

        return null;
    }

    protected function should_allow_fallback_lead_creation($senderEmail, $subject, $inReplyTo, $referencesHeader)
    {
        if ($this->is_own_mailbox_sender($senderEmail)) {
            return false;
        }

        if (preg_match('/^\s*re\s*:/i', (string) $subject) === 1) {
            return true;
        }

        return trim((string) $inReplyTo) !== '' || trim((string) $referencesHeader) !== '';
    }

    protected function is_own_mailbox_sender($senderEmail)
    {
        $senderEmail = $this->normalize_email($senderEmail);
        if ($senderEmail === '') {
            return false;
        }

        $configuredEmails = array_filter([
            $this->normalize_email(get_option('marketing_smtp_from_email')),
            $this->normalize_email(get_option('marketing_default_from_email')),
            $this->normalize_email(get_option('marketing_default_reply_to_email')),
            $this->normalize_email(get_option('marketing_smtp_reply_to')),
            $this->normalize_email(get_option('marketing_reply_mailbox_username')),
        ]);

        if (in_array($senderEmail, $configuredEmails, true)) {
            return true;
        }

        $senderDomain = $this->extract_email_domain($senderEmail);
        if ($senderDomain === '') {
            return false;
        }

        foreach ($configuredEmails as $configuredEmail) {
            if ($this->extract_email_domain($configuredEmail) === $senderDomain) {
                return true;
            }
        }

        return false;
    }

    protected function extract_email_domain($email)
    {
        $email = $this->normalize_email($email);
        if ($email === '' || strpos($email, '@') === false) {
            return '';
        }

        return substr(strrchr($email, '@'), 1);
    }

    protected function find_contact_by_email($email)
    {
        $normalizedEmail = $this->normalize_email($email);
        if ($normalizedEmail === '') {
            return null;
        }

        $table = db_prefix() . 'marketing_contacts';
        if (!$this->ci->db->table_exists($table) || !$this->ci->db->field_exists('email', $table)) {
            return null;
        }

        return $this->ci->db->from($table)
            ->where('email', $normalizedEmail)
            ->order_by('id', 'DESC')
            ->limit(1)
            ->get()
            ->row_array();
    }

    protected function sender_matches_recipient($senderEmail, array $recipient)
    {
        $senderEmail = $this->normalize_email($senderEmail);
        $recipientEmail = $this->normalize_email($recipient['email'] ?? ($recipient['destination'] ?? ''));
        if ($senderEmail === '' || $recipientEmail === '') {
            return true;
        }

        return $senderEmail === $recipientEmail;
    }

    protected function extract_reply_tracking_identifier($subject, $bodyHtml, $bodyText, array $headers = [], $rawBody = '')
    {
        $sources = [
            (string) $subject,
            (string) $bodyText,
            (string) $bodyHtml,
            (string) $rawBody,
            (string) ($headers['x-marketing-reply-id'] ?? ''),
        ];

        foreach ($sources as $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }

            if (preg_match('/reply_id\s*:\s*(\d+)\s*:\s*(\d+)/i', $value, $matches)) {
                return [
                    'campaign_id' => (int) $matches[1],
                    'recipient_id' => (int) $matches[2],
                ];
            }

            if (preg_match('/^(\d+)\s*:\s*(\d+)$/', $value, $matches)) {
                return [
                    'campaign_id' => (int) $matches[1],
                    'recipient_id' => (int) $matches[2],
                ];
            }
        }

        return null;
    }

    protected function extract_message_identifier(array $payload, array $headers)
    {
        $candidates = [
            $payload['message_id'] ?? null,
            $payload['header_message_id'] ?? null,
            $headers['message-id'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $normalized = $this->normalize_message_identifier($candidate);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return '';
    }

    protected function extract_provider_message_id(array $payload, array $headers, $messageIdentifier)
    {
        foreach ([
            $payload['provider_message_id'] ?? null,
            $payload['message_id'] ?? null,
            $headers['x-message-id'] ?? null,
            $messageIdentifier,
        ] as $candidate) {
            $normalized = $this->normalize_message_identifier($candidate);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return '';
    }

    protected function resolve_received_at(array $payload, array $headers)
    {
        foreach ([
            $payload['received_at'] ?? null,
            $payload['timestamp'] ?? null,
            $payload['date'] ?? null,
            $headers['date'] ?? null,
        ] as $candidate) {
            if ($candidate === null || $candidate === '') {
                continue;
            }

            $timestamp = is_numeric($candidate) ? (int) $candidate : strtotime((string) $candidate);
            if ($timestamp !== false && $timestamp > 0) {
                return date('Y-m-d H:i:s', $timestamp);
            }
        }

        return date('Y-m-d H:i:s');
    }

    protected function build_dedupe_key($senderEmail, $subject, $messagePreview, $messageIdentifier, $mailboxUid, $receivedAt)
    {
        $messageIdentifier = $this->normalize_message_identifier($messageIdentifier);
        if ($messageIdentifier !== '') {
            return 'inbound_email:message_identifier:' . $messageIdentifier;
        }

        $mailboxUid = trim((string) $mailboxUid);
        if ($mailboxUid !== '') {
            return 'inbound_email:mailbox_uid:' . $mailboxUid;
        }

        $senderEmail = $this->normalize_email($senderEmail);
        $subject = mb_strtolower(preg_replace('/\s+/', ' ', trim((string) $subject)));
        $messagePreview = mb_strtolower(preg_replace('/\s+/', ' ', trim((string) $messagePreview)));
        $messagePreview = mb_substr($messagePreview, 0, 500);
        $bucket = date('Y-m-d H:i', strtotime((string) $receivedAt));

        return 'inbound_email:fallback:' . sha1(implode('|', [$senderEmail, $subject, sha1($messagePreview), $bucket]));
    }

    protected function extract_message_identifiers($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return [];
        }

        preg_match_all('/<([^<>]+)>/', $value, $matches);
        $identifiers = [];
        foreach ($matches[1] ?? [] as $identifier) {
            $normalized = $this->normalize_message_identifier($identifier);
            if ($normalized !== '') {
                $identifiers[$normalized] = $normalized;
            }
        }

        return array_values($identifiers);
    }

    protected function sanitize_references_header($value)
    {
        $value = trim(preg_replace('/\s+/', ' ', (string) $value));

        return $value === '' ? '' : mb_substr($value, 0, 4000);
    }

    protected function normalize_headers($headers)
    {
        $normalized = [];
        if (!is_array($headers)) {
            return $normalized;
        }

        foreach ($headers as $key => $value) {
            $headerKey = strtolower(trim((string) $key));
            if ($headerKey === '') {
                continue;
            }

            if (is_array($value)) {
                $value = implode(', ', array_map('strval', $value));
            }

            $normalized[$headerKey] = trim((string) $value);
        }

        return $normalized;
    }

    protected function sanitize_sender_name($value)
    {
        $value = trim(strip_tags((string) $value));

        return $value === '' ? '' : mb_substr($value, 0, 191);
    }

    protected function sanitize_subject($subject)
    {
        return mb_substr(trim(strip_tags((string) $subject)), 0, 255);
    }

    protected function sanitize_html($html)
    {
        $html = trim((string) $html);
        if ($html === '') {
            return '';
        }

        $html = preg_replace('#<script\b[^>]*>(.*?)</script>#is', '', $html);
        $html = preg_replace('#<iframe\b[^>]*>.*?</iframe>#is', '', $html);
        $html = preg_replace('#on[a-z]+\s*=\s*(\"[^\"]*\"|\'[^\']*\'|[^\s>]+)#i', '', $html);
        $html = preg_replace('#(href|src)\s*=\s*(\"|\')?\s*javascript:[^\"\'>\s]+#i', '$1="#"', $html);

        return mb_substr($html, 0, 20000);
    }

    protected function sanitize_text($text)
    {
        $text = html_entity_decode(strip_tags((string) $text), ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);

        return mb_substr(trim($text), 0, 20000);
    }

    protected function normalize_email($email)
    {
        $email = trim((string) $email);

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)
            ? mb_strtolower($email)
            : '';
    }

    protected function normalize_message_identifier($identifier)
    {
        $identifier = trim((string) $identifier);
        $identifier = trim($identifier, '<>');

        return $identifier === '' ? '' : $identifier;
    }

    protected function safe_json_encode($value)
    {
        $encoded = json_encode($value);

        return $encoded === false ? '{}' : $encoded;
    }

    protected function sanitize_error_message($message)
    {
        if (function_exists('marketing_sanitize_log_message')) {
            return marketing_sanitize_log_message($message);
        }

        $message = trim(strip_tags((string) $message));

        return $message === '' ? 'unknown_error' : mb_substr($message, 0, 500);
    }
}
