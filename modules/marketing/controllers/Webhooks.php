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
        $signatureVerificationRequired = $this->shouldRequireSesSnsSignatureVerification();
        $verificationOptions = $this->getSesSnsVerificationOptions();

        log_message('info', 'Marketing SES/SNS webhook received: type=' . ($messageType !== '' ? $messageType : 'unknown') . ' sns_message_id=' . $snsMessageId);

        if ($signatureVerificationRequired) {
            $verification = $this->marketing_ses_sns->verify($rawBody, $headers, $verificationOptions);
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

            if ($signatureVerificationRequired) {
                $confirmation = $this->marketing_ses_sns->confirm_subscription($body['SubscribeURL'] ?? '', $verificationOptions);
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
                    'outbound_attempt_token' => $event['outbound_attempt_token'] ?? null,
                    'prefer_campaign_email' => ($event['event_type'] ?? '') === 'delivered',
                ]
            );

            $campaignId = !empty($event['campaign_id']) ? (int) $event['campaign_id'] : null;
            $recipientId = !empty($event['recipient_id']) ? (int) $event['recipient_id'] : null;
            $contactId = null;
            $reconciliation = [
                'matched' => false,
                'mutated' => false,
                'skipped' => false,
                'reason' => 'recipient_not_found',
            ];

            if ($recipient) {
                $recipientId = $recipient['id'];
                $campaignId = $recipient['campaign_id'];
                $contactId = $recipient['marketing_contact_id'];

                $reconciliation = $this->marketing_campaigns_model->apply_recipient_event($recipientId, array_merge($event, [
                    'provider' => 'ses_sns',
                ]));
                log_message(
                    !empty($reconciliation['mutated']) ? 'info' : 'warning',
                    'Marketing SES/SNS recipient reconciliation: recipient_id=' . $recipientId
                    . ' campaign_id=' . $campaignId
                    . ' event=' . $event['event_type']
                    . ' mutated=' . (!empty($reconciliation['mutated']) ? '1' : '0')
                    . ' reason=' . ($reconciliation['reason'] ?? 'none')
                );
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

            $message['_recipient_reconciliation'] = [
                'matched' => !empty($reconciliation['matched']),
                'mutated' => !empty($reconciliation['mutated']),
                'skipped' => !empty($reconciliation['skipped']),
                'reason' => $reconciliation['reason'] ?? null,
                'recipient_id' => $recipientId,
                'campaign_id' => $campaignId,
                'outbound_attempt_token' => $event['outbound_attempt_token'] ?? null,
            ];
            $message['_failure_metrics'] = [
                'normalized_failure_code' => $this->marketing_campaigns_model->normalize_provider_failure_metric_code([
                    'event_type' => $event['event_type'] ?? null,
                    'reconciliation_skipped' => !empty($reconciliation['skipped']),
                    'normalized_failure_code' => $reconciliation['normalized_failure_code'] ?? null,
                ]),
                'reconciliation_skipped' => !empty($reconciliation['skipped']),
                'reconciliation_reason' => $reconciliation['reason'] ?? null,
            ];
            $event['payload_json'] = $this->safeJsonEncode($message);

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

        $rawBody = (string) file_get_contents('php://input');
        $headers = $this->getRequestHeaders();
        $trimmedRawBody = trim($rawBody);
        $payload = $trimmedRawBody !== '' ? json_decode($rawBody, true) : null;
        $webhookLogId = $this->logWebhook([
            'provider' => 'whatsapp_business',
            'event_type' => 'whatsapp_webhook',
            'message_type' => 'notification',
            'signature_valid' => 0,
            'payload_json' => $trimmedRawBody !== '' ? $rawBody : json_encode([]),
            'headers_json' => json_encode($headers),
        ]);

        $signatureVerification = $this->marketing_whatsapp->verify_post_signature($rawBody, $headers);
        if (empty($signatureVerification['accepted'])) {
            $this->updateWebhookLog($webhookLogId, [
                'processed' => 1,
                'signature_valid' => 0,
                'error_message' => 'WhatsApp POST signature verification failed.',
            ]);

            return $this->respondJson([
                'success' => false,
                'status' => 'error',
                'message' => $signatureVerification['message'] ?? 'WhatsApp webhook signature verification failed.',
            ], 401);
        }

        if (!is_array($payload)) {
            $this->updateWebhookLog($webhookLogId, [
                'processed' => 1,
                'signature_valid' => !empty($signatureVerification['authenticated']) ? 1 : 0,
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
                'signature_valid' => !empty($signatureVerification['authenticated']) ? 1 : 0,
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
            'signature_valid' => !empty($signatureVerification['authenticated']) ? 1 : 0,
        ]);

        return $this->respondJson([
            'success' => true,
            'status' => 'ok',
            'message' => 'WhatsApp webhook processed.',
            'messages_processed' => $processedMessages,
            'statuses_processed' => $processedStatuses,
            'authenticated' => !empty($signatureVerification['authenticated']),
            'warning' => $signatureVerification['warning'] ?? null,
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

        $headers = $this->getRequestHeaders();
        $authentication = $this->validateInboundEmailAuthentication($payload, $headers);
        if (empty($authentication['accepted'])) {
            log_message('warning', 'Marketing inbound email rejected due to authentication failure.');

            return $this->respondJson([
                'success' => false,
                'status' => 'error',
                'message' => 'Inbound email authentication failed.',
            ], 401);
        }

        $senderEmail = $this->normalizeEmail($payload['sender_email'] ?? $payload['from'] ?? '');
        $subject = $this->sanitizeInboundSubject($payload['subject'] ?? '');
        $bodyHtml = $this->sanitizeInboundHtml($payload['body_html'] ?? $payload['html'] ?? '');
        $bodyText = $this->sanitizeInboundText($payload['body_text'] ?? $payload['text'] ?? $bodyHtml);
        $payloadHeaders = is_array($payload['headers'] ?? null) ? $payload['headers'] : [];
        $rawBody = (string) ($payload['_raw_body'] ?? '');
        $providerMessageId = $this->extractInboundProviderMessageId($payload, $headers, $payloadHeaders);
        $receivedAt = $this->resolveInboundReceivedAt($payload, $headers, $payloadHeaders);
        $messagePreview = $bodyText !== '' ? $bodyText : strip_tags($bodyHtml);
        $dedupeKey = $this->buildInboundMessageDedupeKey($senderEmail, $subject, $messagePreview, $payload, $headers, $payloadHeaders, $receivedAt);

        if ($senderEmail === '' || ($bodyText === '' && $bodyHtml === '')) {
            return $this->respondJson([
                'success' => false,
                'status' => 'error',
                'message' => 'Sender email and message body are required.',
            ], 400);
        }

        log_message('info', 'Marketing inbound email received: sender=' . $senderEmail . ' subject=' . ($subject !== '' ? mb_substr($subject, 0, 120) : 'n/a'));
        if (!empty($authentication['warning'])) {
            log_message('warning', 'Marketing inbound email accepted in compatibility mode: warning=' . $authentication['warning'] . ' sender=' . $senderEmail);
        }

        $replyEventDedupeId = $providerMessageId !== '' ? $providerMessageId : 'inbound_reply:' . $dedupeKey;
        $dedupeCriteria = [
            'provider' => 'inbound_email',
            'event_type' => 'reply',
            'provider_message_id' => $providerMessageId !== '' ? $providerMessageId : null,
            'provider_event_id' => $replyEventDedupeId,
            'email' => $senderEmail,
            'event_at' => $receivedAt,
        ];
        $eventLock = $this->marketing_events_model->acquire_event_lock($dedupeCriteria);
        $eventLockKey = trim((string) ($eventLock['lock_key'] ?? ''));
        if (empty($eventLock['acquired'])) {
            log_message('warning', 'Marketing inbound email skipped because a stable dedupe lock is already active: sender=' . $senderEmail);

            return $this->respondJson([
                'success' => true,
                'status' => 'processing',
                'message' => 'Inbound email is already being processed.',
            ], 202);
        }

        try {
        $replyMatch = $this->extractReplyTrackingIdentifier($subject, $bodyHtml, $bodyText, array_merge($payloadHeaders, $headers), $rawBody);
        $recipient = null;
        $campaignId = null;
        $contactId = null;
        $matchMode = 'unmatched';
        $matchConfidence = 'none';
        $warning = null;
        $campaign = null;
        $leadConversionAllowed = false;
        $messageStoreResult = [
            'success' => false,
            'inserted' => false,
            'duplicate' => false,
            'id' => 0,
        ];

        if ($replyMatch !== null) {
            log_message('info', 'Marketing inbound email reply_id parsed: campaign_id=' . (int) $replyMatch['campaign_id'] . ' recipient_id=' . (int) $replyMatch['recipient_id']);
            $candidateRecipient = $this->marketing_campaigns_model->get_recipient_by_id((int) $replyMatch['recipient_id']);
            if ($candidateRecipient
                && (int) $candidateRecipient['campaign_id'] === (int) $replyMatch['campaign_id']) {
                if ($this->senderMatchesRecipient($senderEmail, $candidateRecipient)) {
                    $recipient = $candidateRecipient;
                    $campaignId = (int) $replyMatch['campaign_id'];
                    $contactId = (int) $candidateRecipient['marketing_contact_id'];
                    $matchMode = 'reply_id';
                    $matchConfidence = 'strong';
                    $leadConversionAllowed = true;
                    log_message('info', 'Marketing inbound email matched by reply_id: campaign_id=' . $campaignId . ' recipient_id=' . (int) $recipient['id']);
                } else {
                    $warning = 'reply_id_sender_mismatch';
                    $matchMode = 'reply_id_sender_mismatch';
                    $matchConfidence = 'suspicious';
                    log_message('warning', 'Marketing inbound email reply_id sender mismatch: campaign_id=' . (int) $replyMatch['campaign_id'] . ' recipient_id=' . (int) $replyMatch['recipient_id'] . ' sender=' . $senderEmail);
                }
            } else {
                $warning = 'reply_id_not_matched';
                log_message('warning', 'Marketing inbound email reply_id did not resolve to a valid campaign recipient: campaign_id=' . (int) $replyMatch['campaign_id'] . ' recipient_id=' . (int) $replyMatch['recipient_id']);
            }
        }

        if ($recipient === null && $replyMatch === null) {
            $fallbackMatch = $this->findUnambiguousRecipientByEmail($senderEmail);
            if (!empty($fallbackMatch['ambiguous'])) {
                $warning = $warning ?: 'sender_email_ambiguous';
                $matchMode = 'sender_email_ambiguous';
                $matchConfidence = 'none';
                log_message('warning', 'Marketing inbound email sender fallback is ambiguous and will stay unmatched: sender=' . $senderEmail);
            } elseif (!empty($fallbackMatch['recipient']) && is_array($fallbackMatch['recipient'])) {
                $recipient = $fallbackMatch['recipient'];
                $campaignId = (int) $recipient['campaign_id'];
                $contactId = (int) $recipient['marketing_contact_id'];
                $matchMode = 'sender_email_unique';
                $matchConfidence = 'safe_fallback';
                $warning = $warning ?: 'sender_email_unique_fallback';
                log_message('info', 'Marketing inbound email matched by unique sender fallback: campaign_id=' . $campaignId . ' recipient_id=' . (int) $recipient['id']);
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
                'channel' => 'email',
                'provider' => 'inbound_email',
                'subject' => $subject,
                'last_message_at' => $receivedAt,
                'status' => 'open',
                'source_type' => 'email_reply',
            ]);

            if ($conversation) {
                $messageStoreResult = $this->marketing_conversations_model->add_message_idempotent([
                    'conversation_id' => (int) $conversation['id'],
                    'campaign_id' => $campaignId,
                    'marketing_contact_id' => $contactId,
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
                    'dedupe_key' => $dedupeKey,
                    'datecreated' => $receivedAt,
                ]);
                $messageId = (int) ($messageStoreResult['id'] ?? 0);
                if (!empty($messageStoreResult['duplicate'])) {
                    log_message('info', 'Marketing inbound email duplicate replay skipped: conversation_id=' . (int) $conversation['id'] . ' message_id=' . $messageId . ' reason=' . ($messageStoreResult['reason'] ?? 'duplicate'));
                } else {
                    log_message('info', 'Marketing conversation stored for inbound email: conversation_id=' . (int) $conversation['id'] . ' message_id=' . $messageId);
                }
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

        if (!empty($messageStoreResult['duplicate'])) {
            $leadResult = [
                'lead_id' => null,
                'lead_status' => 'duplicate',
                'lead_action' => 'skipped_duplicate',
                'event_type' => 'reply_lead_skipped',
                'lead_error' => null,
            ];
        } elseif (!$leadConversionAllowed || empty($authentication['lead_conversion_allowed']) || $messageId <= 0) {
            $leadResult = [
                'lead_id' => null,
                'lead_status' => 'not_converted',
                'lead_action' => 'skipped',
                'event_type' => 'reply_lead_skipped',
                'lead_error' => $messageId <= 0
                    ? 'Inbound email message could not be stored safely for automatic lead conversion.'
                    : ($leadConversionAllowed ? 'Inbound email authentication is not configured for lead conversion.' : 'Inbound email reply could not be matched strongly enough for automatic lead conversion.'),
            ];
        } else {
            try {
                $leadResult = $this->marketing_reply_leads_model->convert_reply_to_lead([
                    'email' => $senderEmail,
                    'sender_name' => trim((string) ($payload['sender_name'] ?? '')),
                    'subject' => $subject,
                    'preview' => $messagePreview,
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
        }

        if (!empty($leadResult['event_type'])) {
            $this->marketing_events_model->add_event_idempotent([
                'campaign_id' => $campaignId,
                'campaign_recipient_id' => $recipient ? (int) $recipient['id'] : null,
                'marketing_contact_id' => $contactId ?: null,
                'event_type' => $leadResult['event_type'],
                'provider' => 'inbound_email',
                'provider_event_id' => 'reply_lead:' . $replyEventDedupeId,
                'provider_message_id' => $providerMessageId !== '' ? $providerMessageId : null,
                'email' => $senderEmail,
                'event_at' => $receivedAt,
                'payload_json' => $this->safeJsonEncode([
                    'lead_id' => $leadResult['lead_id'] ?? null,
                    'lead_status' => $leadResult['lead_status'] ?? null,
                    'lead_action' => $leadResult['lead_action'] ?? null,
                    'lead_error' => $leadResult['lead_error'] ?? null,
                    'conversation_id' => $conversation['id'] ?? null,
                    'message_id' => $messageId ?: null,
                    'subject' => $subject,
                    'match_mode' => $matchMode,
                    'match_confidence' => $matchConfidence,
                    'authenticated' => !empty($authentication['authenticated']),
                ]),
            ]);
        }

        $replyEventResult = $this->marketing_events_model->add_event_idempotent([
            'campaign_id' => $campaignId,
            'campaign_recipient_id' => $recipient ? (int) $recipient['id'] : null,
            'marketing_contact_id' => $contactId ?: null,
            'event_type' => 'reply',
            'provider' => 'inbound_email',
            'provider_event_id' => $replyEventDedupeId,
            'provider_message_id' => $providerMessageId !== '' ? $providerMessageId : null,
            'email' => $senderEmail,
            'event_at' => $receivedAt,
            'payload_json' => $this->safeJsonEncode([
                'match_mode' => $matchMode,
                'match_confidence' => $matchConfidence,
                'conversation_id' => $conversation['id'] ?? null,
                'message_id' => $messageId ?: null,
                'subject' => $subject,
                'authenticated' => !empty($authentication['authenticated']),
                'auth_mode' => $authentication['mode'] ?? 'compatibility',
                'warning' => $warning ?: ($authentication['warning'] ?? null),
                'duplicate_message' => !empty($messageStoreResult['duplicate']),
            ]),
        ]);

        return $this->respondJson([
            'success' => true,
            'status' => !empty($messageStoreResult['duplicate']) ? 'duplicate' : 'ok',
            'message' => !empty($messageStoreResult['duplicate']) ? 'Inbound email duplicate ignored.' : 'Inbound email processed.',
            'campaign_id' => $campaignId,
            'contact_id' => $contactId ?: null,
            'conversation_id' => $conversation['id'] ?? null,
            'match_mode' => $matchMode,
            'matched_by' => $matchMode,
            'warning' => $warning ?: ($authentication['warning'] ?? null),
            'authenticated' => !empty($authentication['authenticated']),
            'duplicate_message' => !empty($messageStoreResult['duplicate']),
            'event_duplicate' => !empty($replyEventResult['duplicate']),
            'lead_id' => $leadResult['lead_id'] ?? null,
            'lead_status' => $leadResult['lead_status'] ?? null,
            'lead_action' => $leadResult['lead_action'] ?? null,
            'lead_error' => $leadResult['lead_error'] ?? null,
        ], 200);
        } finally {
            if ($eventLockKey !== '') {
                $this->marketing_events_model->release_event_lock($eventLockKey);
            }
        }
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
            'outbound_attempt_token' => $mailMetadata['outbound_attempt_token'] ?? null,
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
        $outboundAttemptToken = trim((string) (
            $this->extractSesTagValue($tags, ['outbound_attempt_token'])
            ?: $this->extractSesHeaderValue($headers, ['X-Marketing-Outbound-Attempt-ID'])
        ));
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
            'outbound_attempt_token' => $outboundAttemptToken !== '' ? $outboundAttemptToken : null,
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
        $messageType = trim((string) ($message['type'] ?? 'text'));
        $contextProviderMessageId = trim((string) ($message['context_message_id'] ?? ''));
        $providerThreadId = trim((string) ($message['provider_thread_id'] ?? ''));
        $mediaMeta = $this->buildWhatsappMessageMediaMeta($message);
        $dedupeKey = $this->buildWhatsappInboundMessageDedupeKey($message, $phone, $providerMessageId, $eventAt, $messageType, $contextProviderMessageId);
        $providerEventId = $this->buildWhatsappInboundProviderEventId($providerMessageId, $dedupeKey);

        $dedupeCriteria = [
            'provider' => 'whatsapp_business',
            'event_type' => $eventType,
            'provider_message_id' => $providerMessageId,
            'provider_event_id' => $providerEventId,
            'email' => $phone,
            'event_at' => $eventAt,
        ];

        $eventLockKey = '';
        if ($this->hasStableDedupeCriteria($dedupeCriteria)) {
            $eventLock = $this->marketing_events_model->acquire_event_lock($dedupeCriteria);
            if (!empty($eventLock['acquired'])) {
                $eventLockKey = (string) ($eventLock['lock_key'] ?? '');
            }
        }

        try {
            $conversation = null;
            $messageId = 0;
            $messageStored = false;
            $duplicateMessage = false;
            if ($this->marketing_conversations_model->tables_available()) {
                $conversation = $this->marketing_conversations_model->find_or_create_conversation([
                    'campaign_id' => null,
                    'marketing_contact_id' => null,
                    'email' => $phone,
                    'channel' => 'whatsapp',
                    'provider' => 'whatsapp_cloud',
                    'contact_phone' => $phone,
                    'normalized_phone' => $phone,
                    'provider_thread_id' => $providerThreadId !== '' ? $providerThreadId : null,
                    'subject' => 'WhatsApp',
                    'last_message_at' => $eventAt,
                    'last_inbound_at' => $eventAt,
                    'status' => 'open',
                    'source_type' => 'whatsapp',
                ]);

                if ($conversation) {
                    $messageStoreResult = $this->marketing_conversations_model->add_message_idempotent([
                        'conversation_id' => (int) $conversation['id'],
                        'campaign_id' => null,
                        'marketing_contact_id' => null,
                        'direction' => 'inbound',
                        'email' => $phone,
                        'from_email' => $phone,
                        'channel' => 'whatsapp',
                        'provider' => 'whatsapp_cloud',
                        'contact_phone' => $phone,
                        'normalized_phone' => $phone,
                        'provider_thread_id' => $providerThreadId !== '' ? $providerThreadId : null,
                        'message_type' => $messageType !== '' ? $messageType : 'text',
                        'subject' => 'WhatsApp',
                        'message' => trim((string) ($message['text'] ?? '')),
                        'body_text' => trim((string) ($message['text'] ?? '')),
                        'provider_message_id' => $providerMessageId !== '' ? $providerMessageId : null,
                        'dedupe_key' => $dedupeKey,
                        'context_provider_message_id' => $contextProviderMessageId !== '' ? $contextProviderMessageId : null,
                        'media_meta_json' => $mediaMeta,
                        'datecreated' => $eventAt,
                    ]);
                    $messageId = (int) ($messageStoreResult['id'] ?? 0);
                    $messageStored = !empty($messageStoreResult['success']);
                    $duplicateMessage = !empty($messageStoreResult['duplicate']);
                }
            }

            $eventStoreResult = $this->marketing_events_model->add_event_idempotent([
                'campaign_id' => null,
                'campaign_recipient_id' => null,
                'marketing_contact_id' => null,
                'event_type' => $eventType,
                'provider' => 'whatsapp_business',
                'provider_event_id' => $providerEventId,
                'provider_message_id' => $providerMessageId !== '' ? $providerMessageId : null,
                'email' => $phone !== '' ? $phone : null,
                'event_at' => $eventAt,
                'payload_json' => json_encode([
                    'message_id' => $providerMessageId,
                    'sender_name' => $message['sender_name'] ?? '',
                    'type' => $messageType,
                    'channel' => 'whatsapp',
                    'provider' => 'whatsapp_cloud',
                    'contact_phone' => $phone,
                    'normalized_phone' => $phone,
                    'provider_thread_id' => $providerThreadId !== '' ? $providerThreadId : null,
                    'context_provider_message_id' => $contextProviderMessageId !== '' ? $contextProviderMessageId : null,
                    'media_meta' => $mediaMeta,
                    'conversation_id' => $conversation['id'] ?? null,
                    'stored_message_id' => $messageId ?: null,
                    'duplicate_message' => $duplicateMessage,
                ]),
            ]);

            if ($duplicateMessage && !empty($eventStoreResult['duplicate'])) {
                log_message('info', 'Marketing WhatsApp duplicate inbound message skipped: phone=' . $phone . ' provider_message_id=' . ($providerMessageId !== '' ? $providerMessageId : 'n/a'));
                return false;
            }

            log_message('info', 'Marketing WhatsApp inbound message stored: phone=' . $phone . ' conversation_id=' . (int) ($conversation['id'] ?? 0));

            return $messageStored || !empty($eventStoreResult['inserted']);
        } finally {
            if ($eventLockKey !== '') {
                $this->marketing_events_model->release_event_lock($eventLockKey);
            }
        }
    }

    protected function storeWhatsappStatusEvent(array $statusEvent)
    {
        $phone = $this->marketing_whatsapp->normalizePhone($statusEvent['recipient'] ?? '');
        $providerMessageId = trim((string) ($statusEvent['message_id'] ?? ''));
        $eventAt = $statusEvent['timestamp'] ?? date('Y-m-d H:i:s');
        $eventType = trim((string) ($statusEvent['event_type'] ?? 'whatsapp_status'));
        $providerEventId = $this->buildWhatsappStatusProviderEventId($statusEvent, $phone, $providerMessageId, $eventAt, $eventType);

        $dedupeCriteria = [
            'provider' => 'whatsapp_business',
            'event_type' => $eventType,
            'provider_message_id' => $providerMessageId,
            'provider_event_id' => $providerEventId,
            'email' => $phone,
            'event_at' => $eventAt,
        ];

        $eventLockKey = '';
        if ($this->hasStableDedupeCriteria($dedupeCriteria)) {
            $eventLock = $this->marketing_events_model->acquire_event_lock($dedupeCriteria);
            if (!empty($eventLock['acquired'])) {
                $eventLockKey = (string) ($eventLock['lock_key'] ?? '');
            }
        }

        try {
            $storeResult = $this->marketing_events_model->add_event_idempotent([
                'campaign_id' => null,
                'campaign_recipient_id' => null,
                'marketing_contact_id' => null,
                'event_type' => $eventType,
                'provider' => 'whatsapp_business',
                'provider_event_id' => $providerEventId,
                'provider_message_id' => $providerMessageId !== '' ? $providerMessageId : null,
                'email' => $phone !== '' ? $phone : null,
                'event_at' => $eventAt,
                'payload_json' => json_encode([
                    'status' => $statusEvent['status'] ?? '',
                    'conversation_id' => $statusEvent['conversation_id'] ?? '',
                ]),
            ]);

            if (!empty($storeResult['duplicate'])) {
                log_message('info', 'Marketing WhatsApp duplicate status skipped: event=' . $eventType . ' phone=' . $phone . ' provider_message_id=' . ($providerMessageId !== '' ? $providerMessageId : 'n/a'));
                return false;
            }

            log_message('info', 'Marketing WhatsApp status stored: event=' . $eventType . ' phone=' . $phone);

            return !empty($storeResult['success']);
        } finally {
            if ($eventLockKey !== '') {
                $this->marketing_events_model->release_event_lock($eventLockKey);
            }
        }
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
        if ($normalizedEmail === '') {
            return null;
        }

        return $this->db->where('normalized_email', $normalizedEmail)
            ->get(db_prefix() . 'marketing_contacts')
            ->row_array();
    }

    protected function findUnambiguousRecipientByEmail($email)
    {
        $normalizedEmail = $this->normalizeEmail($email);
        if ($normalizedEmail === '') {
            return [
                'recipient' => null,
                'ambiguous' => false,
                'count' => 0,
            ];
        }

        $rows = $this->db->from(db_prefix() . 'marketing_campaign_recipients')
            ->where('email', $normalizedEmail)
            ->order_by('id', 'DESC')
            ->limit(2)
            ->get()
            ->result_array();

        if (count($rows) === 1) {
            return [
                'recipient' => $rows[0],
                'ambiguous' => false,
                'count' => 1,
            ];
        }

        return [
            'recipient' => null,
            'ambiguous' => count($rows) > 1,
            'count' => count($rows),
        ];
    }

    protected function normalizeEmail($email)
    {
        $email = trim((string) $email);
        if ($email === '') {
            return '';
        }

        if (preg_match('/<([^<>@\s]+@[^<>@\s]+)>/', $email, $matches) === 1) {
            $email = $matches[1];
        }

        $email = trim($email, "\"' \t\n\r\0\x0B");
        $email = trim($email, '<>');
        $email = mb_strtolower($email);

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
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

    protected function shouldRequireSesSnsSignatureVerification()
    {
        if (defined('ENVIRONMENT') && ENVIRONMENT === 'production') {
            return true;
        }

        return get_option('marketing_ses_verify_signature') == 1;
    }

    protected function getSesSnsVerificationOptions()
    {
        return [
            'topic_arn' => trim((string) get_option('marketing_ses_sns_topic_arn')),
            'region' => strtolower(trim((string) get_option('marketing_ses_region'))),
            'replay_window_seconds' => max(1, (int) get_option('marketing_ses_sns_replay_window_seconds')),
            'future_skew_seconds' => max(0, (int) get_option('marketing_ses_sns_future_skew_seconds')),
        ];
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

    protected function buildWhatsappMessageMediaMeta(array $message)
    {
        $messageType = trim((string) ($message['type'] ?? ''));
        $meta = [];

        if ($messageType !== '') {
            $meta['type'] = $messageType;
        }

        $contextProviderMessageId = trim((string) ($message['context_message_id'] ?? ''));
        if ($contextProviderMessageId !== '') {
            $meta['context_provider_message_id'] = $contextProviderMessageId;
        }

        if (!empty($message['raw']) && is_array($message['raw'])) {
            foreach ($message['raw'] as $key => $value) {
                if (is_scalar($value) || $value === null) {
                    $meta[$key] = $value;
                }
            }
        }

        if (empty($meta)) {
            return null;
        }

        return $meta;
    }

    protected function buildWhatsappInboundMessageDedupeKey(array $message, $phone, $providerMessageId, $eventAt, $messageType, $contextProviderMessageId)
    {
        $providerMessageId = trim((string) $providerMessageId);
        if ($providerMessageId !== '') {
            return 'whatsapp_inbound:provider_message_id:' . $providerMessageId;
        }

        $bodyText = trim((string) ($message['text'] ?? ''));
        $hashSource = implode('|', [
            'whatsapp_cloud',
            trim((string) $phone),
            trim((string) $eventAt),
            trim((string) $messageType),
            trim((string) $contextProviderMessageId),
            $bodyText,
            json_encode($message['raw'] ?? []),
        ]);

        return 'whatsapp_inbound:fallback:' . sha1($hashSource);
    }

    protected function buildWhatsappInboundProviderEventId($providerMessageId, $dedupeKey)
    {
        $providerMessageId = trim((string) $providerMessageId);
        if ($providerMessageId !== '') {
            return 'whatsapp_inbound:' . $providerMessageId;
        }

        return 'whatsapp_inbound:' . sha1((string) $dedupeKey);
    }

    protected function buildWhatsappStatusProviderEventId(array $statusEvent, $phone, $providerMessageId, $eventAt, $eventType)
    {
        $providerMessageId = trim((string) $providerMessageId);
        if ($providerMessageId !== '') {
            return 'whatsapp_status:' . $eventType . ':' . $providerMessageId;
        }

        $hashSource = implode('|', [
            'whatsapp_status',
            trim((string) $eventType),
            trim((string) $phone),
            trim((string) $eventAt),
            trim((string) ($statusEvent['status'] ?? '')),
            trim((string) ($statusEvent['conversation_id'] ?? '')),
        ]);

        return 'whatsapp_status:' . sha1($hashSource);
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

    protected function validateInboundEmailAuthentication(array $payload, array $headers)
    {
        $configuredSecret = trim((string) get_option('marketing_inbound_email_secret'));
        if ($configuredSecret === '') {
            return [
                'accepted' => true,
                'authenticated' => false,
                'lead_conversion_allowed' => true,
                'mode' => 'compatibility',
                'warning' => 'inbound_email_auth_not_configured',
            ];
        }

        $presentedSecret = trim((string) ($headers['x-marketing-inbound-secret'] ?? ($payload['inbound_secret'] ?? ($payload['secret'] ?? ($payload['token'] ?? '')))));
        if ($presentedSecret !== '' && hash_equals($configuredSecret, $presentedSecret)) {
            return [
                'accepted' => true,
                'authenticated' => true,
                'lead_conversion_allowed' => true,
                'mode' => 'shared_secret',
                'warning' => null,
            ];
        }

        return [
            'accepted' => false,
            'authenticated' => false,
            'lead_conversion_allowed' => false,
            'mode' => 'shared_secret',
            'warning' => 'inbound_email_auth_failed',
        ];
    }

    protected function extractInboundProviderMessageId(array $payload, array $requestHeaders = [], array $payloadHeaders = [])
    {
        $candidates = [
            $payload['message_id'] ?? null,
            $payload['provider_message_id'] ?? null,
            $requestHeaders['message-id'] ?? null,
            $requestHeaders['x-message-id'] ?? null,
            $payloadHeaders['Message-Id'] ?? ($payloadHeaders['Message-ID'] ?? ($payloadHeaders['message-id'] ?? null)),
        ];

        foreach ($candidates as $candidate) {
            $normalized = $this->normalizeMessageIdentifier($candidate);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return '';
    }

    protected function resolveInboundReceivedAt(array $payload, array $requestHeaders = [], array $payloadHeaders = [])
    {
        $candidates = [
            $payload['received_at'] ?? null,
            $payload['timestamp'] ?? null,
            $payload['date'] ?? null,
            $requestHeaders['date'] ?? null,
            $payloadHeaders['Date'] ?? ($payloadHeaders['date'] ?? null),
        ];

        foreach ($candidates as $candidate) {
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

    protected function buildInboundMessageDedupeKey($senderEmail, $subject, $messagePreview, array $payload = [], array $requestHeaders = [], array $payloadHeaders = [], $receivedAt = null)
    {
        $senderEmail = $this->normalizeEmail($senderEmail);
        $subject = mb_strtolower(preg_replace('/\s+/', ' ', trim((string) $subject)));
        $messagePreview = mb_strtolower(preg_replace('/\s+/', ' ', trim((string) $messagePreview)));
        $messagePreview = mb_substr($messagePreview, 0, 500);
        $bodyHash = sha1($messagePreview);
        $receivedAt = $receivedAt ?: $this->resolveInboundReceivedAt($payload, $requestHeaders, $payloadHeaders);
        $bucket = date('Y-m-d H:i', strtotime((string) $receivedAt));

        return 'inbound_email:' . sha1(implode('|', [$senderEmail, $subject, $bodyHash, $bucket]));
    }

    protected function senderMatchesRecipient($senderEmail, array $recipient)
    {
        $senderEmail = $this->normalizeEmail($senderEmail);
        $recipientEmail = $this->normalizeEmail($recipient['email'] ?? ($recipient['destination'] ?? ''));
        if ($senderEmail === '' || $recipientEmail === '') {
            return true;
        }

        return $senderEmail === $recipientEmail;
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
