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
            $this->output
                ->set_content_type('application/json')
                ->set_status_header(405)
                ->set_output(json_encode([
                    'status'  => 'error',
                    'message' => 'Method not allowed. POST required.',
                ]));

            return;
        }

        $rawBody = trim(file_get_contents('php://input'));
        if ($rawBody === '') {
            $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode([
                    'status'  => 'error',
                    'message' => 'Empty request body.',
                ]));

            return;
        }

        $body = json_decode($rawBody, true);
        if ($body === null) {
            $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode([
                    'status'  => 'error',
                    'message' => 'Invalid JSON payload.',
                ]));

            return;
        }

        $headers = $this->getRequestHeaders();
        $rawMessageType = $body['Type'] ?? $headers['x-amz-sns-message-type'] ?? null;
        $messageType = $this->marketing_ses_sns->normalize_event_name($rawMessageType);
        $signatureValid = 0;

        log_message('info', 'Marketing SES/SNS webhook received: type=' . $messageType . ' sns_message_id=' . ($body['MessageId'] ?? 'n/a'));

        if ($messageType === 'subscription_confirmation') {
            $webhookLogId = $this->logWebhook([
                'provider'        => 'ses_sns',
                'event_type'      => 'subscription_confirmation',
                'message_type'    => $rawMessageType,
                'signature_valid' => $signatureValid,
                'payload_json'    => $rawBody,
                'headers_json'    => json_encode($headers),
            ]);

            log_message('info', 'Marketing SES/SNS subscription confirmation received. Auto-confirmation is not implemented; confirm manually in AWS/SNS if needed. sns_message_id=' . ($body['MessageId'] ?? 'n/a'));

            $this->updateWebhookLog($webhookLogId, [
                'event_type'   => 'subscription_confirmation',
                'processed'    => 1,
                'processed_at' => date('Y-m-d H:i:s'),
            ]);

            $this->output
                ->set_content_type('application/json')
                ->set_status_header(200)
                ->set_output(json_encode([
                    'status'  => 'ok',
                    'message' => 'Subscription confirmation received. Manual confirmation is required or auto-confirmation is disabled.',
                ]));

            return;
        }

        if (get_option('marketing_ses_verify_signature') == 1) {
            $verification = $this->marketing_ses_sns->verify($body, $headers);

            if (!$verification['implemented']) {
                $this->logWebhook([
                    'provider'        => 'ses_sns',
                    'event_type'      => null,
                    'message_type'    => $messageType,
                    'signature_valid' => 0,
                    'payload_json'    => $rawBody,
                    'headers_json'    => json_encode($headers),
                    'processed'       => 0,
                    'error_message'   => $verification['message'],
                ]);

                $this->output
                    ->set_content_type('application/json')
                    ->set_status_header(501)
                    ->set_output(json_encode([
                        'status'  => 'error',
                    'message' => 'SES SNS signature verification is not implemented.',
                ]));

                return;
            }

            if (!$verification['valid']) {
                $webhookLogId = $this->logWebhook([
                    'provider'        => 'ses_sns',
                    'event_type'      => null,
                    'message_type'    => $messageType,
                    'signature_valid' => 0,
                    'payload_json'    => $rawBody,
                    'headers_json'    => json_encode($headers),
                    'processed'       => 1,
                    'processed_at'    => date('Y-m-d H:i:s'),
                    'error_message'   => $verification['message'],
                ]);

                $this->output
                    ->set_content_type('application/json')
                    ->set_status_header(403)
                    ->set_output(json_encode([
                        'status'  => 'error',
                        'message' => 'SNS signature verification failed.',
                        'log_id'  => $webhookLogId,
                    ]));

                return;
            }

            $signatureValid = 1;
        }

        $webhookLogId = $this->logWebhook([
            'provider'        => 'ses_sns',
            'event_type'      => null,
            'message_type'    => $rawMessageType,
            'signature_valid' => $signatureValid,
            'payload_json'    => $rawBody,
            'headers_json'    => json_encode($headers),
        ]);

        if ($messageType !== 'notification') {
            $this->updateWebhookLog($webhookLogId, [
                'processed'     => 1,
                'processed_at'  => date('Y-m-d H:i:s'),
                'error_message' => 'Unsupported SNS message type.',
            ]);

            $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode([
                    'status'  => 'error',
                    'message' => 'Unsupported SNS message type.',
                ]));

            return;
        }

        $message = json_decode($body['Message'] ?? '', true);
        if ($message === null) {
            $this->updateWebhookLog($webhookLogId, [
                'processed'     => 1,
                'processed_at'  => date('Y-m-d H:i:s'),
                'error_message' => 'Invalid Message payload.',
            ]);

            $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode([
                    'status'  => 'error',
                    'message' => 'Invalid Message payload.',
                ]));

            return;
        }

        $event = $this->parseSesNotification($message);
        if ($event === null) {
            $this->updateWebhookLog($webhookLogId, [
                'processed'     => 1,
                'processed_at'  => date('Y-m-d H:i:s'),
                'error_message' => 'Unrecognized SES notification.',
            ]);

            $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode([
                    'status'  => 'error',
                    'message' => 'Unrecognized SES notification.',
                ]));

            return;
        }

        log_message('info', 'Marketing SES/SNS event normalized: raw=' . ($message['notificationType'] ?? 'n/a') . ' normalized=' . ($event['event_type'] ?? 'n/a') . ' email=' . ($event['email'] ?? 'n/a'));

        $message['_sns_message_id'] = $body['MessageId'] ?? null;
        $event['sns_message_id'] = $body['MessageId'] ?? null;
        $event['payload_json'] = $this->safeJsonEncode($message);
        $event['event_at'] = $event['event_at'] ?? date('Y-m-d H:i:s');

        $dedupeCriteria = [
            'provider' => 'ses_sns',
            'event_type' => $event['event_type'],
            'provider_message_id' => $event['provider_message_id'] ?? null,
            'provider_event_id' => $event['provider_event_id'] ?? null,
            'email' => $event['email'] ?? null,
            'url' => $event['url'] ?? null,
            'event_at' => $event['event_at'] ?? null,
            'sns_message_id' => $event['sns_message_id'] ?? null,
        ];

        if ($this->hasStableDedupeCriteria($dedupeCriteria) && $this->marketing_events_model->event_exists($dedupeCriteria)) {
            log_message('info', 'Marketing SES/SNS duplicate event skipped: type=' . $event['event_type'] . ' email=' . ($event['email'] ?? 'n/a') . ' sns_message_id=' . ($event['sns_message_id'] ?? 'n/a'));
            $this->updateWebhookLog($webhookLogId, [
                'event_type'   => $event['event_type'],
                'processed'    => 1,
                'processed_at' => date('Y-m-d H:i:s'),
                'error_message' => 'Duplicate SES/SNS event skipped.',
            ]);

            $this->output
                ->set_content_type('application/json')
                ->set_status_header(200)
                ->set_output(json_encode([
                    'status'  => 'ok',
                    'message' => 'Duplicate event skipped.',
                    'event'   => $event['event_type'],
                ]));

            return;
        }

        if (!$this->hasStableDedupeCriteria($dedupeCriteria)) {
            log_message('warning', 'Marketing SES/SNS event missing stable dedupe fields; inserting with best effort. type=' . $event['event_type'] . ' email=' . ($event['email'] ?? 'n/a'));
        }

        $recipient = $this->marketing_campaigns_model->find_recipient_for_event(
            $event['provider_message_id'] ?? null,
            $event['message_id'] ?? null,
            $event['email'] ?? null
        );

        $campaignId = null;
        $recipientId = null;
        $contactId = null;

        if ($recipient) {
            $recipientId = $recipient['id'];
            $campaignId = $recipient['campaign_id'];
            $contactId = $recipient['marketing_contact_id'];
            $this->marketing_campaigns_model->apply_recipient_event($recipientId, $event);
            log_message('info', 'Marketing SES/SNS recipient updated: recipient_id=' . $recipientId . ' campaign_id=' . $campaignId . ' event=' . $event['event_type']);
        } else {
            $contact = $this->findContactByEmail($event['email'] ?? null);
            if ($contact) {
                $contactId = $contact['id'];
            }
        }

        if (in_array($event['event_type'], ['bounce', 'complaint'], true) && !empty($event['email'])) {
            $suppressionId = $this->marketing_suppressions_model->upsert_suppression(
                $event['email'],
                $event['event_type'],
                $event['provider_message_id'] ?? null,
                'ses_sns'
            );
            if ($suppressionId) {
                log_message('info', 'Marketing SES/SNS suppression created or updated: suppression_id=' . $suppressionId . ' reason=' . $event['event_type'] . ' email=' . $event['email']);
            }
        }

        $this->marketing_events_model->add_event([
            'campaign_id'           => $campaignId,
            'campaign_recipient_id' => $recipientId,
            'marketing_contact_id'  => $contactId,
            'event_type'            => $event['event_type'],
            'provider'              => 'ses_sns',
            'provider_event_id'     => $event['provider_event_id'] ?? null,
            'provider_message_id'   => $event['provider_message_id'] ?? null,
            'email'                 => $event['email'] ?? null,
            'url'                   => $event['url'] ?? null,
            'payload_json'          => $event['payload_json'] ?? null,
            'event_at'              => $event['event_at'],
        ]);

        $this->updateWebhookLog($webhookLogId, [
            'event_type'   => $event['event_type'],
            'processed'    => 1,
            'processed_at' => date('Y-m-d H:i:s'),
        ]);

        $this->output
            ->set_content_type('application/json')
            ->set_status_header(200)
            ->set_output(json_encode([
                'status'  => 'ok',
                'message' => 'Notification processed.',
                'event'   => $event['event_type'],
            ]));
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
        $event = [
            'event_type'          => 'failed',
            'message_id'          => $mail['messageId'] ?? null,
            'provider_message_id' => $mail['messageId'] ?? null,
            'email'               => null,
            'provider_event_id'   => null,
            'url'                 => null,
            'event_at'            => isset($mail['timestamp']) ? date('Y-m-d H:i:s', strtotime($mail['timestamp'])) : null,
        ];

        if (!empty($mail['destination']) && is_array($mail['destination'])) {
            $event['email'] = $mail['destination'][0];
        }

        $type = $message['notificationType'] ?? null;
        $normalizedType = $this->marketing_ses_sns->normalize_event_name($type);
        switch ($normalizedType) {
            case 'delivered':
                $event['event_type'] = 'delivered';
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
            case 'rendering_failure':
            case 'delivery_delay':
                $event['event_type'] = $normalizedType;
                $event['provider_event_id'] = isset($message[$type]) ? $message[$type] : ($message[str_replace('_', '', $type)] ?? null);
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
