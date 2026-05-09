<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Webhooks extends CI_Controller
{
    protected $webhookLogTable;

    public function __construct()
    {
        parent::__construct();

        $this->load->model('marketing/Marketing_events_model', 'marketing_events_model');
        $this->load->model('marketing/Marketing_suppressions_model', 'marketing_suppressions_model');
        $this->load->model('marketing/Marketing_campaigns_model', 'marketing_campaigns_model');
        $this->load->model('marketing/Marketing_audiences_model', 'marketing_audiences_model');
        $this->load->model('marketing/Marketing_conversations_model', 'marketing_conversations_model');
        $this->load->model('marketing/Marketing_reply_leads_model', 'marketing_reply_leads_model');
        $this->load->library('marketing/Marketing_ses_sns');
        $this->load->library('marketing/Marketing_whatsapp');

        $this->webhookLogTable = db_prefix() . 'marketing_webhook_logs';
    }

    public function ses_sns()
    {
        if ($this->input->method() !== 'post') {
            return $this->respondJson([
                'status' => 'error',
                'message' => 'Method not allowed. POST required.',
            ], 405);
        }

        $rawBody = (string) file_get_contents('php://input');
        if (trim($rawBody) === '') {
            return $this->respondJson([
                'status' => 'error',
                'message' => 'Empty request body.',
            ], 400);
        }

        $body = json_decode($rawBody, true);
        if (!is_array($body)) {
            return $this->respondJson([
                'status' => 'error',
                'message' => 'Invalid JSON payload.',
            ], 400);
        }

        $headers = $this->getRequestHeaders();
        $rawMessageType = $this->scalarString($body['Type'] ?? null, $this->scalarString($headers['x-amz-sns-message-type'] ?? null, null));
        $messageType = $this->marketing_ses_sns->normalize_event_name($rawMessageType);
        $snsMessageId = $this->scalarString($body['MessageId'] ?? null, 'n/a');
        $signatureValid = 0;
        $signatureVerificationEnabled = get_option('marketing_ses_verify_signature') == 1;

        log_message('info', 'Marketing SES/SNS webhook received: type=' . ($messageType !== '' ? $messageType : 'unknown') . ' sns_message_id=' . $snsMessageId);

        if ($signatureVerificationEnabled) {
            $verification = $this->marketing_ses_sns->verify($rawBody, $headers);
            $messageType = !empty($verification['type']) ? $verification['type'] : $messageType;

            if (empty($verification['valid'])) {
                $verificationError = $this->sanitizeWebhookError($verification['error'] ?? 'SNS signature verification failed.');

                log_message('warning', 'Marketing SES/SNS signature rejected: type=' . ($messageType !== '' ? $messageType : 'unknown') . ' sns_message_id=' . $snsMessageId . ' reason=' . $verificationError);

                $webhookLogId = $this->logWebhook([
                    'provider' => 'ses_sns',
                    'event_type' => null,
                    'message_type' => $rawMessageType,
                    'signature_valid' => 0,
                    'payload_json' => $rawBody,
                    'headers_json' => $this->safeJsonEncode($headers),
                    'processed' => 1,
                    'processed_at' => date('Y-m-d H:i:s'),
                    'error_message' => $verificationError,
                ]);

                return $this->respondJson([
                    'status' => 'error',
                    'message' => 'SNS signature verification failed.',
                    'log_id' => $webhookLogId,
                ], 403);
            }

            $signatureValid = 1;
        }

        if ($messageType === 'subscription_confirmation') {
            $webhookLogId = $this->logWebhook([
                'provider' => 'ses_sns',
                'event_type' => 'subscription_confirmation',
                'message_type' => $rawMessageType,
                'signature_valid' => $signatureValid,
                'payload_json' => $rawBody,
                'headers_json' => $this->safeJsonEncode($headers),
            ]);

            if ($signatureVerificationEnabled) {
                $confirmation = $this->marketing_ses_sns->confirm_subscription($body['SubscribeURL'] ?? '');
                if (!empty($confirmation['success'])) {
                    log_message('info', 'Marketing SES/SNS subscription confirmed: sns_message_id=' . $snsMessageId);

                    $this->updateWebhookLog($webhookLogId, [
                        'event_type' => 'subscription_confirmation',
                        'processed' => 1,
                        'processed_at' => date('Y-m-d H:i:s'),
                    ]);

                    return $this->respondJson([
                        'status' => 'ok',
                        'message' => 'Subscription confirmed.',
                        'type' => 'subscription_confirmation',
                    ], 200);
                }

                $confirmationError = $this->sanitizeWebhookError($confirmation['error'] ?? 'SNS subscription confirmation request failed.');

                log_message('error', 'Marketing SES/SNS subscription confirmation failed: sns_message_id=' . $snsMessageId . ' reason=' . $confirmationError);

                $this->updateWebhookLog($webhookLogId, [
                    'event_type' => 'subscription_confirmation',
                    'processed' => 1,
                    'processed_at' => date('Y-m-d H:i:s'),
                    'error_message' => $confirmationError,
                ]);

                return $this->respondJson([
                    'status' => 'error',
                    'message' => 'Subscription confirmation request failed.',
                ], 502);
            }

            log_message('info', 'Marketing SES/SNS subscription confirmation received without signature verification. Manual confirmation is required. sns_message_id=' . $snsMessageId);

            $this->updateWebhookLog($webhookLogId, [
                'event_type' => 'subscription_confirmation',
                'processed' => 1,
                'processed_at' => date('Y-m-d H:i:s'),
            ]);

            return $this->respondJson([
                'status' => 'ok',
                'message' => 'Subscription confirmation received. Manual confirmation is required or signature verification is disabled.',
                'type' => 'subscription_confirmation',
            ], 200);
        }

        if ($messageType === 'unsubscribe_confirmation') {
            $webhookLogId = $this->logWebhook([
                'provider' => 'ses_sns',
                'event_type' => 'unsubscribe_confirmation',
                'message_type' => $rawMessageType,
                'signature_valid' => $signatureValid,
                'payload_json' => $rawBody,
                'headers_json' => $this->safeJsonEncode($headers),
            ]);

            log_message('info', 'Marketing SES/SNS unsubscribe confirmation received: sns_message_id=' . $snsMessageId);

            $this->updateWebhookLog($webhookLogId, [
                'event_type' => 'unsubscribe_confirmation',
                'processed' => 1,
                'processed_at' => date('Y-m-d H:i:s'),
            ]);

            return $this->respondJson([
                'status' => 'ok',
                'message' => 'Unsubscribe confirmation received.',
                'type' => 'unsubscribe_confirmation',
            ], 200);
        }

        $webhookLogId = $this->logWebhook([
            'provider' => 'ses_sns',
            'event_type' => null,
            'message_type' => $rawMessageType,
            'signature_valid' => $signatureValid,
            'payload_json' => $rawBody,
            'headers_json' => $this->safeJsonEncode($headers),
        ]);

        if ($messageType !== 'notification') {
            $this->updateWebhookLog($webhookLogId, [
                'processed' => 1,
                'processed_at' => date('Y-m-d H:i:s'),
                'error_message' => 'Unsupported SNS message type.',
            ]);

            return $this->respondJson([
                'status' => 'error',
                'message' => 'Unsupported SNS message type.',
            ], 400);
        }

        $message = json_decode($this->scalarString($body['Message'] ?? '', ''), true);
        if (!is_array($message)) {
            $this->updateWebhookLog($webhookLogId, [
                'processed' => 1,
                'processed_at' => date('Y-m-d H:i:s'),
                'error_message' => 'Invalid Message payload.',
            ]);

            return $this->respondJson([
                'status' => 'error',
                'message' => 'Invalid Message payload.',
            ], 400);
        }

        $event = $this->parseSesNotification($message);
        if ($event === null) {
            $this->updateWebhookLog($webhookLogId, [
                'processed' => 1,
                'processed_at' => date('Y-m-d H:i:s'),
                'error_message' => 'Unrecognized SES notification.',
            ]);

            return $this->respondJson([
                'status' => 'error',
                'message' => 'Unrecognized SES notification.',
            ], 400);
        }

        log_message(
            'info',
            'Marketing SES/SNS event normalized: raw=' . ($message['notificationType'] ?? ($message['eventType'] ?? 'n/a'))
            . ' normalized=' . ($event['event_type'] ?? 'n/a')
        );

        $message['_sns_message_id'] = $body['MessageId'] ?? null;
        $event['sns_message_id'] = $body['MessageId'] ?? null;
        $event['payload_json'] = $this->safeJsonEncode($message);
        $event['event_at'] = $event['event_at'] ?? date('Y-m-d H:i:s');

        $dedupeCriteria = $this->marketing_events_model->build_event_dedupe_criteria([
            'provider' => 'ses_sns',
            'event_type' => $event['event_type'],
            'provider_message_id' => $event['provider_message_id'] ?? null,
            'provider_event_id' => $event['provider_event_id'] ?? null,
            'email' => $event['email'] ?? null,
            'url' => $event['url'] ?? null,
            'event_at' => $event['event_at'] ?? null,
            'sns_message_id' => $event['sns_message_id'] ?? null,
        ]);

        $eventLockKey = '';
        if ($this->hasStableDedupeCriteria($dedupeCriteria)) {
            $eventLock = $this->marketing_events_model->acquire_event_lock($dedupeCriteria);
            if (!empty($eventLock['acquired'])) {
                $eventLockKey = (string) ($eventLock['lock_key'] ?? '');
            } else {
                log_message(
                    'warning',
                    'Marketing SES/SNS event lock unavailable; continuing with best-effort dedupe. type=' . $event['event_type']
                    . ' sns_message_id=' . ($event['sns_message_id'] ?? 'n/a')
                );
            }
        }

        try {
            if ($this->hasStableDedupeCriteria($dedupeCriteria) && $this->marketing_events_model->event_exists($dedupeCriteria)) {
                log_message('info', 'Marketing SES/SNS duplicate event skipped: type=' . $event['event_type'] . ' sns_message_id=' . ($event['sns_message_id'] ?? 'n/a'));
                $this->updateWebhookLog($webhookLogId, [
                    'event_type' => $event['event_type'],
                    'processed' => 1,
                    'processed_at' => date('Y-m-d H:i:s'),
                    'error_message' => 'Duplicate SES/SNS event skipped.',
                ]);

                return $this->respondJson([
                    'status' => 'ok',
                    'message' => 'Duplicate event skipped.',
                    'event' => $event['event_type'],
                ], 200);
            }

            if (!$this->hasStableDedupeCriteria($dedupeCriteria)) {
                log_message('warning', 'Marketing SES/SNS event missing stable dedupe fields; inserting with best effort. type=' . $event['event_type']);
            }

            $recipient = $this->marketing_campaigns_model->find_recipient_for_event(
                $event['provider_message_id'] ?? null,
                $event['message_id'] ?? null,
                $event['email'] ?? null,
                [
                    'campaign_id' => $event['campaign_id'] ?? null,
                    'recipient_id' => $event['recipient_id'] ?? null,
                    'correlation_id' => $event['correlation_id'] ?? null,
                    'header_message_id' => $event['header_message_id'] ?? null,
                    'prefer_campaign_email' => ($event['event_type'] ?? '') === 'delivered',
                ]
            );

            $campaignId = !empty($event['campaign_id']) ? (int) $event['campaign_id'] : null;
            $recipientId = !empty($event['recipient_id']) ? (int) $event['recipient_id'] : null;
            $contactId = null;

            if ($recipient) {
                $recipientId = $recipient['id'];
                $campaignId = $recipient['campaign_id'];
                $contactId = $recipient['marketing_contact_id'];

                $providerMessageId = $this->normalizeMessageIdentifier($event['provider_message_id'] ?? null);
                if ($providerMessageId !== null && $providerMessageId !== $this->normalizeMessageIdentifier($recipient['provider_message_id'] ?? null)) {
                    $this->marketing_campaigns_model->update_recipient_record($recipientId, [
                        'provider_message_id' => $providerMessageId,
                    ]);
                }

                $this->marketing_campaigns_model->apply_recipient_event($recipientId, array_merge($event, [
                    'provider' => 'ses_sns',
                ]));
                log_message('info', 'Marketing SES/SNS recipient updated: recipient_id=' . $recipientId . ' campaign_id=' . $campaignId . ' event=' . $event['event_type']);
            } else {
                $contact = $this->findContactByEmail($event['email'] ?? null);
                if ($contact) {
                    $contactId = $contact['id'];
                }

                log_message(
                    'warning',
                    'Marketing SES/SNS event could not be correlated to a recipient: event=' . $event['event_type']
                    . ' campaign_id=' . (int) ($campaignId ?? 0)
                    . ' recipient_id=' . (int) ($recipientId ?? 0)
                    . ' provider_message_id=' . ($event['provider_message_id'] ?? 'n/a')
                    . ' correlation_id=' . ($event['correlation_id'] ?? 'n/a')
                    . ' config_set=' . ($event['configuration_set'] ?? 'n/a')
                );
            }

            if (in_array($event['event_type'], ['bounce', 'complaint'], true) && !empty($event['email'])) {
                $suppressionId = $this->marketing_suppressions_model->upsert_suppression(
                    $event['email'],
                    $event['event_type'],
                    $event['provider_message_id'] ?? null,
                    'ses_sns'
                );
                if ($suppressionId) {
                    log_message('info', 'Marketing SES/SNS suppression created or updated: suppression_id=' . $suppressionId . ' reason=' . $event['event_type']);
                }
            }

            $storeResult = $this->marketing_events_model->add_event_idempotent([
                'campaign_id' => $campaignId,
                'campaign_recipient_id' => $recipientId,
                'marketing_contact_id' => $contactId,
                'event_type' => $event['event_type'],
                'provider' => 'ses_sns',
                'provider_event_id' => $event['provider_event_id'] ?? null,
                'provider_message_id' => $event['provider_message_id'] ?? null,
                'sns_message_id' => $event['sns_message_id'] ?? null,
                'email' => $event['email'] ?? null,
                'url' => $event['url'] ?? null,
                'payload_json' => $event['payload_json'] ?? null,
                'event_at' => $event['event_at'],
            ]);

            if (!empty($storeResult['duplicate'])) {
                log_message('info', 'Marketing SES/SNS duplicate event skipped: type=' . $event['event_type'] . ' sns_message_id=' . ($event['sns_message_id'] ?? 'n/a'));
                $this->updateWebhookLog($webhookLogId, [
                    'event_type' => $event['event_type'],
                    'processed' => 1,
                    'processed_at' => date('Y-m-d H:i:s'),
                    'error_message' => 'Duplicate SES/SNS event skipped.',
                ]);

                return $this->respondJson([
                    'status' => 'ok',
                    'message' => 'Duplicate event skipped.',
                    'event' => $event['event_type'],
                ], 200);
            }

            if (empty($storeResult['success'])) {
                $storeError = $this->sanitizeWebhookError($storeResult['error'] ?? 'SES/SNS event storage failed.');
                log_message('error', 'Marketing SES/SNS event storage failed after processing: type=' . $event['event_type'] . ' sns_message_id=' . ($event['sns_message_id'] ?? 'n/a') . ' reason=' . $storeError);
                $this->updateWebhookLog($webhookLogId, [
                    'event_type' => $event['event_type'],
                    'processed' => 1,
                    'processed_at' => date('Y-m-d H:i:s'),
                    'error_message' => $storeError,
                ]);

                return $this->respondJson([
                    'status' => 'ok',
                    'message' => 'Notification processed.',
                    'event' => $event['event_type'],
                ], 200);
            }

            $this->updateWebhookLog($webhookLogId, [
                'event_type' => $event['event_type'],
                'processed' => 1,
                'processed_at' => date('Y-m-d H:i:s'),
            ]);

            return $this->respondJson([
                'status' => 'ok',
                'message' => 'Notification processed.',
                'event' => $event['event_type'],
            ], 200);
        } finally {
            if ($eventLockKey !== '') {
                $this->marketing_events_model->release_event_lock($eventLockKey);
            }
        }
    }

    public function whatsapp()
    {
        $method = strtolower((string) $this->input->method());

        if ($method === 'get') {
            $mode = $_GET['hub.mode'] ?? $this->input->get('hub.mode', true) ?? $this->input->get('hub_mode', true);
            $token = $_GET['hub.verify_token'] ?? $this->input->get('hub.verify_token', true) ?? $this->input->get('hub_verify_token', true);
            $challenge = $_GET['hub.challenge'] ?? $this->input->get('hub.challenge', true) ?? $this->input->get('hub_challenge', true);

            $verification = $this->marketing_whatsapp->verify_webhook($mode, $token, $challenge);
            if (!empty($verification['success'])) {
                $this->output
                    ->set_content_type('text/plain')
                    ->set_status_header(200)
                    ->set_output((string) ($verification['challenge'] ?? ''));

                return;
            }

            return $this->respondJson([
                'success' => false,
                'status' => 'error',
                'message' => $verification['message'] ?? 'Webhook verification failed.',
            ], (int) ($verification['status_code'] ?? 403));
        }

        if ($method !== 'post') {
            return $this->respondJson([
                'success' => false,
                'status' => 'error',
                'message' => 'Method not allowed. GET or POST required.',
            ], 405);
        }

        $rawBody = trim(file_get_contents('php://input'));
        $headers = $this->getRequestHeaders();
        $payload = $rawBody !== '' ? json_decode($rawBody, true) : null;
        $webhookLogId = $this->logWebhook([
            'provider' => 'whatsapp_business',
            'event_type' => 'whatsapp_webhook',
            'message_type' => 'notification',
            'signature_valid' => 0,
            'payload_json' => $rawBody !== '' ? $rawBody : json_encode([]),
            'headers_json' => json_encode($headers),
        ]);

        if (!is_array($payload)) {
            $this->updateWebhookLog($webhookLogId, [
                'processed' => 1,
                'error_message' => 'Invalid WhatsApp webhook payload.',
            ]);

            return $this->respondJson([
                'success' => false,
                'status' => 'error',
                'message' => 'Invalid WhatsApp webhook payload.',
            ], 200);
        }

        log_message('info', 'Marketing WhatsApp webhook received.');
        $parsed = $this->marketing_whatsapp->parse_inbound_webhook_payload($payload);
        if (empty($parsed['valid'])) {
            $this->updateWebhookLog($webhookLogId, [
                'processed' => 1,
                'error_message' => 'WhatsApp webhook payload was not recognized.',
            ]);

            return $this->respondJson([
                'success' => false,
                'status' => 'error',
                'message' => 'WhatsApp webhook payload was not recognized.',
            ], 200);
        }

        $processedMessages = 0;
        $processedStatuses = 0;

        foreach (($parsed['messages'] ?? []) as $message) {
            if ($this->storeWhatsappInboundMessage($message)) {
                $processedMessages++;
            }
        }

        foreach (($parsed['statuses'] ?? []) as $statusEvent) {
            if ($this->storeWhatsappStatusEvent($statusEvent)) {
                $processedStatuses++;
            }
        }

        $this->updateWebhookLog($webhookLogId, [
            'processed' => 1,
            'event_type' => 'whatsapp_webhook',
        ]);

        return $this->respondJson([
            'success' => true,
            'status' => 'ok',
            'message' => 'WhatsApp webhook processed.',
            'messages_processed' => $processedMessages,
            'statuses_processed' => $processedStatuses,
        ], 200);
    }

    public function inbound_email()
    {
        if ($this->input->method() !== 'post') {
            return $this->respondJson([
                'success' => false,
                'status' => 'error',
                'message' => 'Method not allowed. POST required.',
            ], 405);
        }

        $payload = $this->getInboundPayload();
        if ($payload === null) {
            return $this->respondJson([
                'success' => false,
                'status' => 'error',
                'message' => 'Invalid inbound payload.',
            ], 400);
        }

        $senderEmail = $this->normalizeEmail($payload['sender_email'] ?? $payload['from'] ?? '');
        $subject = $this->sanitizeInboundSubject($payload['subject'] ?? '');
        $bodyHtml = $this->sanitizeInboundHtml($payload['body_html'] ?? $payload['html'] ?? '');
        $bodyText = $this->sanitizeInboundText($payload['body_text'] ?? $payload['text'] ?? $bodyHtml);
        $headers = is_array($payload['headers'] ?? null) ? $payload['headers'] : [];
        $rawBody = (string) ($payload['_raw_body'] ?? '');
        $providerMessageId = trim((string) ($payload['message_id'] ?? $payload['provider_message_id'] ?? ''));
        $receivedAt = date('Y-m-d H:i:s');

        if ($senderEmail === '' || ($bodyText === '' && $bodyHtml === '')) {
            return $this->respondJson([
                'success' => false,
                'status' => 'error',
                'message' => 'Sender email and message body are required.',
            ], 400);
        }

        log_message('info', 'Marketing inbound email received: sender=' . $senderEmail . ' subject=' . ($subject !== '' ? mb_substr($subject, 0, 120) : 'n/a'));

        $replyMatch = $this->extractReplyTrackingIdentifier($subject, $bodyHtml, $bodyText, $headers, $rawBody);
        $recipient = null;
        $campaignId = null;
        $contactId = null;
        $matchMode = 'fallback_email';
        $warning = null;
        $campaign = null;

        if ($replyMatch !== null) {
            log_message('info', 'Marketing inbound email reply_id parsed: campaign_id=' . (int) $replyMatch['campaign_id'] . ' recipient_id=' . (int) $replyMatch['recipient_id']);
            $candidateRecipient = $this->marketing_campaigns_model->get_recipient_by_id((int) $replyMatch['recipient_id']);
            if ($candidateRecipient
                && (int) $candidateRecipient['campaign_id'] === (int) $replyMatch['campaign_id']) {
                $recipient = $candidateRecipient;
                $campaignId = (int) $replyMatch['campaign_id'];
                $contactId = (int) $candidateRecipient['marketing_contact_id'];
                $matchMode = 'reply_id';
                log_message('info', 'Marketing inbound email matched by reply_id: campaign_id=' . $campaignId . ' recipient_id=' . (int) $recipient['id']);
            } else {
                $warning = 'reply_id_not_matched';
                log_message('warning', 'Marketing inbound email reply_id did not resolve to a valid campaign recipient: campaign_id=' . (int) $replyMatch['campaign_id'] . ' recipient_id=' . (int) $replyMatch['recipient_id']);
            }
        }

        if ($recipient === null) {
            $recipient = $this->findLatestRecipientByEmail($senderEmail);
            if ($recipient) {
                $campaignId = (int) $recipient['campaign_id'];
                $contactId = (int) $recipient['marketing_contact_id'];
                log_message('info', 'Marketing inbound email matched by sender fallback: campaign_id=' . $campaignId . ' recipient_id=' . (int) $recipient['id']);
            }
        }

        $contact = $this->findContactByEmail($senderEmail);
        $senderWasExistingMarketingContact = !empty($contact);
        if ($contact) {
            $contactId = (int) $contact['id'];
        } elseif ($contactId <= 0) {
            $createdContactId = $this->marketing_audiences_model->upsert_contact([
                'email' => $senderEmail,
                'full_name' => trim((string) ($payload['sender_name'] ?? '')),
                'status' => 'active',
                'consent_status' => 'unknown',
                'source_type' => 'reply',
            ]);
            if ($createdContactId) {
                $contactId = (int) $createdContactId;
                log_message('info', 'Marketing inbound unknown sender created as contact: contact_id=' . $contactId . ' email=' . $senderEmail);
            }
        }

        $conversation = null;
        $messageId = 0;
        if ($this->marketing_conversations_model->tables_available()) {
            $conversation = $this->marketing_conversations_model->find_or_create_conversation([
                'campaign_id' => $campaignId,
                'marketing_contact_id' => $contactId,
                'email' => $senderEmail,
                'subject' => $subject,
                'last_message_at' => $receivedAt,
                'status' => 'open',
                'source_type' => 'email_reply',
            ]);

            if ($conversation) {
                $messageId = (int) $this->marketing_conversations_model->add_message([
                    'conversation_id' => (int) $conversation['id'],
                    'campaign_id' => $campaignId,
                    'marketing_contact_id' => $contactId,
                    'direction' => 'inbound',
                    'email' => $senderEmail,
                    'subject' => $subject,
                    'message' => $bodyText !== '' ? $bodyText : strip_tags($bodyHtml),
                    'body_text' => $bodyText,
                    'body_html' => $bodyHtml !== '' ? $bodyHtml : null,
                    'provider_message_id' => $providerMessageId !== '' ? $providerMessageId : null,
                    'datecreated' => $receivedAt,
                ]);
                log_message('info', 'Marketing conversation stored for inbound email: conversation_id=' . (int) $conversation['id'] . ' message_id=' . $messageId);
            }
        } else {
            log_message('warning', 'Marketing inbound email could not create conversation because conversation tables are unavailable.');
        }

        if ($campaignId > 0) {
            $campaign = $this->marketing_campaigns_model->get($campaignId);
        }

        $leadResult = [
            'lead_id' => null,
            'lead_status' => 'not_converted',
            'lead_action' => 'disabled',
            'event_type' => 'reply_lead_skipped',
            'lead_error' => null,
        ];

        try {
            $leadResult = $this->marketing_reply_leads_model->convert_reply_to_lead([
                'email' => $senderEmail,
                'sender_name' => trim((string) ($payload['sender_name'] ?? '')),
                'subject' => $subject,
                'preview' => $bodyText !== '' ? $bodyText : strip_tags($bodyHtml),
                'campaign_id' => $campaignId,
                'campaign_name' => $campaign['name'] ?? '',
                'conversation_id' => $conversation['id'] ?? null,
                'message_id' => $messageId ?: null,
                'existing_marketing_contact' => $senderWasExistingMarketingContact,
            ]);
        } catch (Throwable $e) {
            $safeLeadError = mb_substr(preg_replace('/\s+/', ' ', trim(strip_tags((string) $e->getMessage()))), 0, 200);
            log_message('error', 'Marketing reply-to-lead failed safely: email=' . $senderEmail . ' message=' . $safeLeadError);
            $leadResult = [
                'lead_id' => null,
                'lead_status' => 'failed',
                'lead_action' => 'failed',
                'event_type' => 'reply_lead_failed',
                'lead_error' => 'Native Perfex lead creation failed.',
            ];
        }

        if (!empty($leadResult['event_type'])) {
            $this->marketing_events_model->add_event([
                'campaign_id' => $campaignId,
                'campaign_recipient_id' => $recipient ? (int) $recipient['id'] : null,
                'marketing_contact_id' => $contactId ?: null,
                'event_type' => $leadResult['event_type'],
                'provider' => 'inbound_email',
                'email' => $senderEmail,
                'event_at' => $receivedAt,
                'payload_json' => json_encode([
                    'lead_id' => $leadResult['lead_id'] ?? null,
                    'lead_status' => $leadResult['lead_status'] ?? null,
                    'lead_action' => $leadResult['lead_action'] ?? null,
                    'lead_error' => $leadResult['lead_error'] ?? null,
                    'conversation_id' => $conversation['id'] ?? null,
                    'message_id' => $messageId ?: null,
                    'subject' => $subject,
                ]),
            ]);
        }

        $this->marketing_events_model->add_event([
            'campaign_id' => $campaignId,
            'campaign_recipient_id' => $recipient ? (int) $recipient['id'] : null,
            'marketing_contact_id' => $contactId ?: null,
            'event_type' => 'reply',
            'provider' => 'inbound_email',
            'provider_message_id' => $providerMessageId !== '' ? $providerMessageId : null,
            'email' => $senderEmail,
            'event_at' => $receivedAt,
            'payload_json' => json_encode([
                'match_mode' => $matchMode,
                'conversation_id' => $conversation['id'] ?? null,
                'message_id' => $messageId ?: null,
                'subject' => $subject,
            ]),
        ]);

        return $this->respondJson([
            'success' => true,
            'status' => 'ok',
            'message' => 'Inbound email processed.',
            'campaign_id' => $campaignId,
            'contact_id' => $contactId ?: null,
            'conversation_id' => $conversation['id'] ?? null,
            'match_mode' => $matchMode,
            'matched_by' => $matchMode,
            'warning' => $warning,
            'lead_id' => $leadResult['lead_id'] ?? null,
            'lead_status' => $leadResult['lead_status'] ?? null,
            'lead_action' => $leadResult['lead_action'] ?? null,
            'lead_error' => $leadResult['lead_error'] ?? null,
        ], 200);
    }

    protected function parseSesNotification(array $message)
    {
        $mail = $message['mail'] ?? [];
        $mailMetadata = $this->extractSesMailMetadata($mail);
        $rawType = $this->scalarString($message['notificationType'] ?? ($message['eventType'] ?? null), '');
        $normalizedType = $this->normalizeSesNotificationType($rawType);
        if ($normalizedType === '') {
            return null;
        }

        $event = [
            'event_type'          => 'failed',
            'message_id'          => $mailMetadata['correlation_message_id'] ?? ($mailMetadata['header_message_id'] ?? null),
            'provider_message_id' => $mailMetadata['provider_message_id'] ?? null,
            'header_message_id'   => $mailMetadata['header_message_id'] ?? null,
            'correlation_id'      => $mailMetadata['correlation_message_id'] ?? null,
            'campaign_id'         => $mailMetadata['campaign_id'] ?? null,
            'recipient_id'        => $mailMetadata['recipient_id'] ?? null,
            'configuration_set'   => $mailMetadata['configuration_set'] ?? null,
            'email'               => null,
            'provider_event_id'   => null,
            'url'                 => null,
            'event_at'            => $this->extractSesEventTimestamp($message, $mail, $normalizedType),
        ];

        if (!empty($mail['destination']) && is_array($mail['destination'])) {
            $event['email'] = $mail['destination'][0];
        }

        switch ($normalizedType) {
            case 'provider_send':
                $event['event_type'] = 'provider_send';
                $send = is_array($message['send'] ?? null) ? $message['send'] : [];
                if (!empty($send['recipients'][0])) {
                    $event['email'] = $send['recipients'][0];
                }
                break;
            case 'delivered':
                $event['event_type'] = 'delivered';
                $delivery = is_array($message['delivery'] ?? null) ? $message['delivery'] : [];
                if (!empty($delivery['recipients'][0])) {
                    $event['email'] = $delivery['recipients'][0];
                }
                break;
            case 'bounce':
                $event['event_type'] = 'bounce';
                $bounce = $message['bounce'] ?? [];
                if (!empty($bounce['bouncedRecipients'][0]['emailAddress'])) {
                    $event['email'] = $bounce['bouncedRecipients'][0]['emailAddress'];
                }
                $event['provider_event_id'] = $bounce['feedbackId'] ?? null;
                break;
            case 'complaint':
                $event['event_type'] = 'complaint';
                $complaint = $message['complaint'] ?? [];
                if (!empty($complaint['complainedRecipients'][0]['emailAddress'])) {
                    $event['email'] = $complaint['complainedRecipients'][0]['emailAddress'];
                }
                $event['provider_event_id'] = $complaint['feedbackId'] ?? null;
                break;
            case 'open':
                $event['event_type'] = 'open';
                $open = $message['open'] ?? [];
                $event['email'] = $open['recipient'] ?? $event['email'];
                break;
            case 'click':
                $event['event_type'] = 'click';
                $click = $message['click'] ?? [];
                $event['email'] = $click['recipient'] ?? $event['email'];
                $event['url'] = $click['link'] ?? $click['url'] ?? null;
                break;
            case 'rejected':
                $event['event_type'] = 'rejected';
                $reject = is_array($message['reject'] ?? null) ? $message['reject'] : [];
                if (!empty($reject['recipient'])) {
                    $event['email'] = $reject['recipient'];
                }
                break;
            case 'rendering_failed':
                $event['event_type'] = 'rendering_failed';
                $failure = is_array($message['failure'] ?? null) ? $message['failure'] : [];
                if (!empty($failure['recipient'])) {
                    $event['email'] = $failure['recipient'];
                }
                break;
            case 'delivery_delay':
                $event['event_type'] = 'delivery_delay';
                $deliveryDelay = is_array($message['deliveryDelay'] ?? ($message['delivery_delay'] ?? null))
                    ? ($message['deliveryDelay'] ?? $message['delivery_delay'])
                    : [];
                if (!empty($deliveryDelay['delayedRecipients'][0]['emailAddress'])) {
                    $event['email'] = $deliveryDelay['delayedRecipients'][0]['emailAddress'];
                } elseif (!empty($deliveryDelay['recipient'])) {
                    $event['email'] = $deliveryDelay['recipient'];
                } elseif (!empty($deliveryDelay['recipients'][0])) {
                    $event['email'] = $deliveryDelay['recipients'][0];
                }
                break;
            case 'subscription':
                $event['event_type'] = 'subscription';
                break;
            default:
                return null;
        }

        if (empty($event['email'])) {
            $event['email'] = $this->getEmailFromMail($mail);
        }

        $event['email'] = $this->normalizeEmail($event['email']);

        return $event;
    }

    protected function normalizeSesNotificationType($rawType)
    {
        $normalized = $this->marketing_ses_sns->normalize_event_name($rawType);
        $map = [
            'sent' => 'provider_send',
            'send' => 'provider_send',
            'delivered' => 'delivered',
            'bounce' => 'bounce',
            'complaint' => 'complaint',
            'rejected' => 'rejected',
            'rendering_failure' => 'rendering_failed',
            'rendering_failed' => 'rendering_failed',
            'delivery_delay' => 'delivery_delay',
            'open' => 'open',
            'click' => 'click',
            'subscription' => 'subscription',
        ];

        return $map[$normalized] ?? '';
    }

    protected function extractSesEventTimestamp(array $message, array $mail, $normalizedType)
    {
        $sectionMap = [
            'provider_send' => ['send'],
            'delivered' => ['delivery'],
            'bounce' => ['bounce'],
            'complaint' => ['complaint'],
            'rejected' => ['reject'],
            'rendering_failed' => ['failure', 'renderingFailure', 'rendering_failure'],
            'delivery_delay' => ['deliveryDelay', 'delivery_delay'],
            'open' => ['open'],
            'click' => ['click'],
            'subscription' => ['subscription'],
        ];

        foreach ($sectionMap[$normalizedType] ?? [] as $sectionKey) {
            $section = $message[$sectionKey] ?? null;
            if (is_array($section) && !empty($section['timestamp'])) {
                $timestamp = strtotime((string) $section['timestamp']);
                if ($timestamp !== false) {
                    return date('Y-m-d H:i:s', $timestamp);
                }
            }
        }

        if (!empty($mail['timestamp'])) {
            $timestamp = strtotime((string) $mail['timestamp']);
            if ($timestamp !== false) {
                return date('Y-m-d H:i:s', $timestamp);
            }
        }

        return date('Y-m-d H:i:s');
    }

    protected function extractSesMailMetadata(array $mail)
    {
        $headers = $this->extractSesHeaderMap($mail);
        $tags = is_array($mail['tags'] ?? null) ? $mail['tags'] : [];
        $providerMessageId = $this->normalizeMessageIdentifier(
            $mail['messageId'] ?? $this->extractSesHeaderValue($headers, ['Message-ID'])
        );
        $headerMessageId = $this->normalizeMessageIdentifier($this->extractSesHeaderValue($headers, ['Message-ID']));
        $correlationId = $this->normalizeMessageIdentifier(
            $this->extractSesHeaderValue($headers, ['X-Marketing-Correlation-ID', 'X-Marketing-Message-ID'])
            ?: $this->extractSesTagValue($tags, ['correlation_id'])
            ?: $headerMessageId
        );
        $campaignId = $this->extractPositiveInt(
            $this->extractSesTagValue($tags, ['campaign_id', 'campaign'])
            ?: $this->extractSesHeaderValue($headers, ['X-Marketing-Campaign-ID'])
        );
        $recipientId = $this->extractPositiveInt(
            $this->extractSesTagValue($tags, ['recipient_id', 'recipient'])
            ?: $this->extractSesHeaderValue($headers, ['X-Marketing-Recipient-ID'])
        );
        $configurationSet = trim((string) (
            $this->extractSesTagValue($tags, ['ses:configuration-set', 'configuration_set'])
            ?: $this->extractSesHeaderValue($headers, ['X-SES-CONFIGURATION-SET'])
        ));

        return [
            'provider_message_id' => $providerMessageId,
            'header_message_id' => $headerMessageId,
            'correlation_message_id' => $correlationId,
            'campaign_id' => $campaignId > 0 ? $campaignId : null,
            'recipient_id' => $recipientId > 0 ? $recipientId : null,
            'configuration_set' => $configurationSet !== '' ? $configurationSet : null,
        ];
    }

    protected function extractSesHeaderMap(array $mail)
    {
        $headerMap = [];
        $headerRows = is_array($mail['headers'] ?? null) ? $mail['headers'] : [];
        foreach ($headerRows as $headerRow) {
            if (!is_array($headerRow)) {
                continue;
            }

            $name = strtolower(trim((string) ($headerRow['name'] ?? '')));
            $value = trim((string) ($headerRow['value'] ?? ''));
            if ($name === '' || $value === '') {
                continue;
            }

            $headerMap[$name] = $value;
        }

        $commonHeaders = is_array($mail['commonHeaders'] ?? null) ? $mail['commonHeaders'] : [];
        foreach ($commonHeaders as $name => $value) {
            $normalizedName = strtolower(trim((string) $name));
            if ($normalizedName === '') {
                continue;
            }

            if (is_array($value)) {
                $value = reset($value);
            }

            if (!isset($headerMap[$normalizedName]) && is_scalar($value)) {
                $headerMap[$normalizedName] = trim((string) $value);
            }
        }

        return $headerMap;
    }

    protected function extractSesHeaderValue(array $headerMap, array $headerNames)
    {
        foreach ($headerNames as $headerName) {
            $normalizedName = strtolower(trim((string) $headerName));
            if ($normalizedName !== '' && isset($headerMap[$normalizedName]) && trim((string) $headerMap[$normalizedName]) !== '') {
                return trim((string) $headerMap[$normalizedName]);
            }

            $canonicalName = preg_replace('/[^a-z0-9]+/', '', $normalizedName);
            foreach ($headerMap as $mapName => $mapValue) {
                if ($canonicalName !== '' && preg_replace('/[^a-z0-9]+/', '', strtolower((string) $mapName)) === $canonicalName) {
                    if (trim((string) $mapValue) !== '') {
                        return trim((string) $mapValue);
                    }
                }
            }
        }

        return '';
    }

    protected function extractSesTagValue(array $tags, array $candidateKeys)
    {
        $normalizedTags = [];
        foreach ($tags as $key => $value) {
            $normalizedKey = strtolower(trim((string) $key));
            if ($normalizedKey === '') {
                continue;
            }

            if (is_array($value)) {
                $value = reset($value);
            }

            if (is_scalar($value)) {
                $normalizedTags[$normalizedKey] = trim((string) $value);
            }
        }

        foreach ($candidateKeys as $candidateKey) {
            $normalizedKey = strtolower(trim((string) $candidateKey));
            if ($normalizedKey !== '' && isset($normalizedTags[$normalizedKey]) && $normalizedTags[$normalizedKey] !== '') {
                return $normalizedTags[$normalizedKey];
            }
        }

        return '';
    }

    protected function extractPositiveInt($value)
    {
        $value = trim((string) $value);
        if ($value === '' || !ctype_digit($value)) {
            return 0;
        }

        return (int) $value;
    }

    protected function storeWhatsappInboundMessage(array $message)
    {
        $phone = $this->marketing_whatsapp->normalizePhone($message['from'] ?? '');
        $providerMessageId = trim((string) ($message['message_id'] ?? ''));
        $eventAt = $message['timestamp'] ?? date('Y-m-d H:i:s');
        $eventType = 'whatsapp_inbound_message';

        $dedupeCriteria = [
            'provider' => 'whatsapp_business',
            'event_type' => $eventType,
            'provider_message_id' => $providerMessageId,
            'email' => $phone,
            'event_at' => $eventAt,
        ];

        if ($this->hasStableDedupeCriteria($dedupeCriteria) && $this->marketing_events_model->event_exists($dedupeCriteria)) {
            log_message('info', 'Marketing WhatsApp duplicate inbound message skipped: phone=' . $phone . ' provider_message_id=' . ($providerMessageId !== '' ? $providerMessageId : 'n/a'));
            return false;
        }

        $conversation = null;
        $messageId = 0;
        if ($this->marketing_conversations_model->tables_available()) {
            $conversation = $this->marketing_conversations_model->find_or_create_conversation([
                'campaign_id' => null,
                'marketing_contact_id' => null,
                'email' => $phone,
                'subject' => 'WhatsApp',
                'last_message_at' => $eventAt,
                'status' => 'open',
                'source_type' => 'whatsapp',
            ]);

            if ($conversation) {
                $messageId = (int) $this->marketing_conversations_model->add_message([
                    'conversation_id' => (int) $conversation['id'],
                    'campaign_id' => null,
                    'marketing_contact_id' => null,
                    'direction' => 'inbound',
                    'email' => $phone,
                    'subject' => 'WhatsApp',
                    'message' => trim((string) ($message['text'] ?? '')),
                    'body_text' => trim((string) ($message['text'] ?? '')),
                    'provider_message_id' => $providerMessageId !== '' ? $providerMessageId : null,
                    'datecreated' => $eventAt,
                ]);
            }
        }

        $this->marketing_events_model->add_event([
            'campaign_id' => null,
            'campaign_recipient_id' => null,
            'marketing_contact_id' => null,
            'event_type' => $eventType,
            'provider' => 'whatsapp_business',
            'provider_message_id' => $providerMessageId !== '' ? $providerMessageId : null,
            'email' => $phone !== '' ? $phone : null,
            'event_at' => $eventAt,
            'payload_json' => json_encode([
                'message_id' => $providerMessageId,
                'sender_name' => $message['sender_name'] ?? '',
                'type' => $message['type'] ?? '',
                'conversation_id' => $conversation['id'] ?? null,
                'stored_message_id' => $messageId ?: null,
            ]),
        ]);

        log_message('info', 'Marketing WhatsApp inbound message stored: phone=' . $phone . ' conversation_id=' . (int) ($conversation['id'] ?? 0));

        return true;
    }

    protected function storeWhatsappStatusEvent(array $statusEvent)
    {
        $phone = $this->marketing_whatsapp->normalizePhone($statusEvent['recipient'] ?? '');
        $providerMessageId = trim((string) ($statusEvent['message_id'] ?? ''));
        $eventAt = $statusEvent['timestamp'] ?? date('Y-m-d H:i:s');
        $eventType = trim((string) ($statusEvent['event_type'] ?? 'whatsapp_status'));

        $dedupeCriteria = [
            'provider' => 'whatsapp_business',
            'event_type' => $eventType,
            'provider_message_id' => $providerMessageId,
            'email' => $phone,
            'event_at' => $eventAt,
        ];

        if ($this->hasStableDedupeCriteria($dedupeCriteria) && $this->marketing_events_model->event_exists($dedupeCriteria)) {
            log_message('info', 'Marketing WhatsApp duplicate status skipped: event=' . $eventType . ' phone=' . $phone . ' provider_message_id=' . ($providerMessageId !== '' ? $providerMessageId : 'n/a'));
            return false;
        }

        $this->marketing_events_model->add_event([
            'campaign_id' => null,
            'campaign_recipient_id' => null,
            'marketing_contact_id' => null,
            'event_type' => $eventType,
            'provider' => 'whatsapp_business',
            'provider_message_id' => $providerMessageId !== '' ? $providerMessageId : null,
            'email' => $phone !== '' ? $phone : null,
            'event_at' => $eventAt,
            'payload_json' => json_encode([
                'status' => $statusEvent['status'] ?? '',
                'conversation_id' => $statusEvent['conversation_id'] ?? '',
            ]),
        ]);

        log_message('info', 'Marketing WhatsApp status stored: event=' . $eventType . ' phone=' . $phone);

        return true;
    }

    protected function hasStableDedupeCriteria(array $criteria)
    {
        foreach (['provider_message_id', 'provider_event_id', 'sns_message_id'] as $field) {
            if (!empty($criteria[$field])) {
                return true;
            }
        }

        return !empty($criteria['email']) && !empty($criteria['event_type']) && !empty($criteria['event_at']);
    }

    protected function getEmailFromMail(array $mail)
    {
        if (!empty($mail['destination']) && is_array($mail['destination'])) {
            return $mail['destination'][0];
        }

        return null;
    }

    protected function findContactByEmail($email)
    {
        if (empty($email)) {
            return null;
        }

        $normalizedEmail = $this->normalizeEmail($email);

        return $this->db->where('normalized_email', $normalizedEmail)
            ->get(db_prefix() . 'marketing_contacts')
            ->row_array();
    }

    protected function findLatestRecipientByEmail($email)
    {
        if (empty($email)) {
            return null;
        }

        return $this->db->from(db_prefix() . 'marketing_campaign_recipients')
            ->where('email', $this->normalizeEmail($email))
            ->order_by('id', 'DESC')
            ->limit(1)
            ->get()
            ->row_array();
    }

    protected function normalizeEmail($email)
    {
        return trim(strtolower((string) $email));
    }

    protected function normalizeMessageIdentifier($messageId)
    {
        $messageId = trim((string) $messageId);
        $messageId = trim($messageId, '<>');

        return $messageId !== '' ? $messageId : null;
    }

    protected function getRequestHeaders()
    {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
        } else {
            $headers = [];
            foreach ($_SERVER as $key => $value) {
                if (strpos($key, 'HTTP_') === 0) {
                    $header = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                    $headers[$header] = $value;
                }
            }
        }

        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower($name)] = $value;
        }

        return $normalized;
    }

    protected function safeJsonEncode($value)
    {
        $json = json_encode($value);
        if ($json === false) {
            return json_encode([]);
        }

        return $json;
    }

    protected function sanitizeWebhookError($message)
    {
        $message = preg_replace('/\s+/', ' ', trim(strip_tags((string) $message)));

        return mb_substr($message, 0, 200);
    }

    protected function scalarString($value, $default = '')
    {
        if (is_array($value) || is_object($value) || $value === null) {
            return $default;
        }

        return (string) $value;
    }

    protected function respondJson(array $data, $statusCode = 200)
    {
        $this->output
            ->set_content_type('application/json')
            ->set_status_header((int) $statusCode)
            ->set_output(json_encode($data));
    }

    protected function getInboundPayload()
    {
        $rawBody = trim(file_get_contents('php://input'));
        if ($rawBody !== '') {
            $decoded = json_decode($rawBody, true);
            if (is_array($decoded)) {
                $decoded['_raw_body'] = $rawBody;
                return $decoded;
            }
        }

        $post = $this->input->post(null, false);

        return is_array($post) && !empty($post) ? $post : null;
    }

    protected function extractReplyTrackingIdentifier($subject, $bodyHtml, $bodyText, array $headers = [], $rawBody = '')
    {
        $sources = [
            ['type' => 'subject', 'value' => (string) $subject],
            ['type' => 'text', 'value' => (string) $bodyText],
            ['type' => 'html', 'value' => (string) $bodyHtml],
            ['type' => 'raw', 'value' => (string) $rawBody],
        ];

        foreach ($headers as $name => $value) {
            if (strtolower((string) $name) === 'x-marketing-reply-id') {
                $sources[] = ['type' => 'header', 'value' => (string) $value];
            }
        }

        foreach ($sources as $source) {
            $value = trim((string) ($source['value'] ?? ''));
            if ($value === '') {
                continue;
            }

            if (preg_match('/reply_id\s*:\s*(\d+)\s*:\s*(\d+)/i', $value, $matches)) {
                return [
                    'campaign_id' => (int) $matches[1],
                    'recipient_id' => (int) $matches[2],
                ];
            }

            if (($source['type'] ?? '') === 'header' && preg_match('/^(\d+)\s*:\s*(\d+)$/', $value, $matches)) {
                return [
                    'campaign_id' => (int) $matches[1],
                    'recipient_id' => (int) $matches[2],
                ];
            }
        }

        return null;
    }

    protected function sanitizeInboundSubject($subject)
    {
        return mb_substr(trim(strip_tags((string) $subject)), 0, 255);
    }

    protected function sanitizeInboundHtml($html)
    {
        $html = trim((string) $html);
        if ($html === '') {
            return '';
        }

        $html = preg_replace('#<script\b[^>]*>(.*?)</script>#is', '', $html);
        $html = preg_replace('#<iframe\b[^>]*>.*?</iframe>#is', '', $html);
        $html = preg_replace('#on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html);
        $html = preg_replace('#(href|src)\s*=\s*("|\')?\s*javascript:[^"\'>\s]+#i', '$1="#"', $html);

        return mb_substr($html, 0, 20000);
    }

    protected function sanitizeInboundText($text)
    {
        $text = html_entity_decode(strip_tags((string) $text), ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);

        return mb_substr(trim($text), 0, 20000);
    }

    protected function logWebhook(array $data)
    {
        $data['datecreated'] = date('Y-m-d H:i:s');
        $this->db->insert($this->webhookLogTable, $data);

        return $this->db->insert_id();
    }

    protected function updateWebhookLog($id, array $data)
    {
        $data['processed_at'] = $data['processed_at'] ?? date('Y-m-d H:i:s');

        $this->db->where('id', $id);
        $this->db->update($this->webhookLogTable, $data);
    }
}
