<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Marketing_reply_mailbox
{
    protected $ci;

    public function __construct()
    {
        $this->ci = &get_instance();
        $this->ci->load->library('marketing/Marketing_inbound_replies');
        $this->ci->load->model('marketing/Marketing_conversations_model', 'marketing_conversations_model');
    }

    public function get_readiness_summary()
    {
        $settings = $this->get_settings();

        return [
            'enabled' => get_option('marketing_reply_to_lead_enabled') === '1',
            'imap_available' => $this->imap_available(),
            'mailbox_configured' => $this->is_mailbox_configured($settings),
            'last_poll_at' => trim((string) get_option('marketing_reply_mailbox_last_poll_at')),
            'last_processed_count' => (int) get_option('marketing_reply_mailbox_last_processed_count'),
            'last_error' => trim((string) get_option('marketing_reply_mailbox_last_error')),
            'folder' => $settings['folder'],
            'processed_folder' => $settings['processed_folder'],
        ];
    }

    public function test_connection()
    {
        $settings = $this->get_settings();
        if (!$this->imap_available()) {
            return [
                'success' => false,
                'error' => 'PHP IMAP extension is unavailable.',
            ];
        }

        if (!$this->is_mailbox_configured($settings)) {
            return [
                'success' => false,
                'error' => 'Reply mailbox settings are incomplete.',
            ];
        }

        $mailboxPath = $this->build_mailbox_path($settings, $settings['folder']);
        $stream = @imap_open($mailboxPath, $settings['username'], $settings['password']);
        if ($stream === false) {
            return [
                'success' => false,
                'error' => $this->sanitize_imap_error($this->collect_imap_errors()),
            ];
        }

        imap_close($stream);

        return [
            'success' => true,
        ];
    }

    public function poll_mailbox($limit = 25)
    {
        $summary = [
            'status' => 'skipped_disabled',
            'processed_count' => 0,
            'scanned_count' => 0,
            'skipped_count' => 0,
            'warnings' => [],
        ];

        if (get_option('marketing_reply_to_lead_enabled') !== '1') {
            return $summary;
        }

        $settings = $this->get_settings();
        if (!$this->imap_available()) {
            $error = 'PHP IMAP extension is unavailable.';
            $this->update_diagnostics(0, $error);

            return [
                'status' => 'failed',
                'processed_count' => 0,
                'scanned_count' => 0,
                'skipped_count' => 0,
                'warnings' => [$error],
            ];
        }

        if (!$this->is_mailbox_configured($settings)) {
            $error = 'Reply mailbox settings are incomplete.';
            $this->update_diagnostics(0, $error);

            return [
                'status' => 'failed',
                'processed_count' => 0,
                'scanned_count' => 0,
                'skipped_count' => 0,
                'warnings' => [$error],
            ];
        }

        $mailboxPath = $this->build_mailbox_path($settings, $settings['folder']);
        $stream = @imap_open($mailboxPath, $settings['username'], $settings['password']);
        if ($stream === false) {
            $error = $this->sanitize_imap_error($this->collect_imap_errors());
            $this->update_diagnostics(0, $error);

            return [
                'status' => 'failed',
                'processed_count' => 0,
                'scanned_count' => 0,
                'skipped_count' => 0,
                'warnings' => [$error],
            ];
        }

        $processed = 0;
        $scanned = 0;
        $skipped = 0;
        $warnings = [];
        $movedAny = false;
        $messageNumbers = $this->find_candidate_message_numbers($stream, max(1, min(50, (int) $limit)));
        if (!is_array($messageNumbers) || empty($messageNumbers)) {
            imap_close($stream);
            $this->update_diagnostics(0, '');

            return [
                'status' => 'idle',
                'processed_count' => 0,
                'scanned_count' => 0,
                'skipped_count' => 0,
                'warnings' => [],
            ];
        }

        foreach ($messageNumbers as $messageNumber) {
            try {
                $scanned++;
                $mailboxUid = (string) @imap_uid($stream, (int) $messageNumber);
                if ($this->already_processed_mailbox_uid($mailboxUid, $settings['folder'])) {
                    $skipped++;
                    continue;
                }

                $message = $this->extract_message($stream, (int) $messageNumber, $settings['folder']);
                $result = $this->ci->marketing_inbound_replies->process($message, [
                    'headers' => $message['headers'] ?? [],
                    'raw_body' => $message['_raw_body'] ?? '',
                    'source_provider' => 'reply_mailbox',
                    'event_provider' => 'inbound_email',
                    'mailbox_uid' => $message['mailbox_uid'] ?? '',
                    'mailbox_folder' => $message['mailbox_folder'] ?? $settings['folder'],
                    'authenticated' => true,
                    'lead_conversion_allowed' => true,
                ]);

                if (!empty($result['success'])) {
                    $processed++;
                    if ($settings['processed_folder'] !== '') {
                        if (@imap_mail_move($stream, (string) $messageNumber, $this->encode_folder_name($settings['processed_folder']))) {
                            $movedAny = true;
                        } else {
                            @imap_setflag_full($stream, (string) $messageNumber, '\\Seen');
                        }
                    } else {
                        @imap_setflag_full($stream, (string) $messageNumber, '\\Seen');
                    }
                } else {
                    $warnings[] = $this->sanitize_imap_error($result['message'] ?? 'Reply mailbox message processing failed.');
                }
            } catch (Throwable $exception) {
                $warnings[] = $this->sanitize_imap_error($exception->getMessage());
                log_message('error', 'Marketing reply mailbox message failed: ' . $this->sanitize_imap_error($exception->getMessage()));
            }
        }

        if ($movedAny) {
            @imap_expunge($stream);
        }
        imap_close($stream);

        $lastError = !empty($warnings) ? implode(' | ', $warnings) : '';
        $this->update_diagnostics($processed, $lastError);

        return [
            'status' => !empty($warnings) ? 'completed_with_warnings' : 'processed',
            'processed_count' => $processed,
            'scanned_count' => $scanned,
            'skipped_count' => $skipped,
            'warnings' => $warnings,
        ];
    }

    protected function find_candidate_message_numbers($stream, $limit)
    {
        $limit = max(1, min(50, (int) $limit));
        $sinceDate = date('d-M-Y', strtotime('-7 days'));
        $searches = [
            'SINCE "' . $sinceDate . '"',
            'ALL',
        ];

        foreach ($searches as $criteria) {
            $messageNumbers = @imap_search($stream, $criteria);
            if (!is_array($messageNumbers) || empty($messageNumbers)) {
                continue;
            }

            rsort($messageNumbers, SORT_NUMERIC);

            return array_slice(array_values(array_unique($messageNumbers)), 0, $limit);
        }

        return [];
    }

    protected function extract_message($stream, $messageNumber, $mailboxFolder)
    {
        $overviewRows = @imap_fetch_overview($stream, (string) $messageNumber, 0);
        $overview = is_array($overviewRows) && !empty($overviewRows) ? $overviewRows[0] : null;
        $headerText = (string) @imap_fetchheader($stream, (int) $messageNumber);
        $headerInfo = @imap_rfc822_parse_headers($headerText);
        $headerInfo = is_object($headerInfo) ? $headerInfo : null;
        $bodyParts = $this->extract_body_parts($stream, (int) $messageNumber, @imap_fetchstructure($stream, (int) $messageNumber));
        $senderEmail = $this->extract_address_email($headerInfo !== null ? ($headerInfo->from ?? []) : []);
        $senderName = $this->extract_address_name($headerInfo !== null ? ($headerInfo->from ?? []) : []);
        $toEmails = $this->extract_address_list($headerInfo !== null ? ($headerInfo->to ?? []) : []);
        $headers = $this->normalize_header_text($headerText);

        return [
            'sender_email' => $senderEmail,
            'sender_name' => $senderName,
            'to' => implode(', ', $toEmails),
            'subject' => $this->decode_imap_header_value(($overview->subject ?? '') !== '' ? $overview->subject : ($headerInfo !== null ? ($headerInfo->subject ?? '') : '')),
            'body_text' => $bodyParts['text'],
            'body_html' => $bodyParts['html'],
            'message_id' => $this->normalize_message_identifier(($overview->message_id ?? '') !== '' ? $overview->message_id : ($headerInfo !== null ? ($headerInfo->message_id ?? ($headers['message-id'] ?? '')) : ($headers['message-id'] ?? ''))),
            'in_reply_to' => $this->normalize_message_identifier($headerInfo !== null ? ($headerInfo->in_reply_to ?? ($headers['in-reply-to'] ?? '')) : ($headers['in-reply-to'] ?? '')),
            'references' => trim((string) ($headerInfo !== null ? ($headerInfo->references ?? ($headers['references'] ?? '')) : ($headers['references'] ?? ''))),
            'received_at' => $this->resolve_imap_date(($overview->date ?? '') !== '' ? $overview->date : ($headerInfo !== null ? ($headerInfo->date ?? '') : '')),
            'mailbox_uid' => (string) @imap_uid($stream, (int) $messageNumber),
            'mailbox_folder' => $mailboxFolder,
            'headers' => $headers,
            '_raw_body' => $headerText,
        ];
    }

    protected function extract_body_parts($stream, $messageNumber, $structure, $partNumber = null)
    {
        $result = [
            'text' => '',
            'html' => '',
        ];

        if (!is_object($structure)) {
            $result['text'] = trim((string) @imap_body($stream, $messageNumber));

            return $result;
        }

        if (!empty($structure->parts) && is_array($structure->parts)) {
            foreach ($structure->parts as $index => $part) {
                $childPartNumber = $partNumber === null ? (string) ($index + 1) : $partNumber . '.' . ($index + 1);
                $childParts = $this->extract_body_parts($stream, $messageNumber, $part, $childPartNumber);
                if ($result['text'] === '' && $childParts['text'] !== '') {
                    $result['text'] = $childParts['text'];
                }
                if ($result['html'] === '' && $childParts['html'] !== '') {
                    $result['html'] = $childParts['html'];
                }
            }

            return $result;
        }

        $body = $partNumber === null
            ? (string) @imap_body($stream, $messageNumber)
            : (string) @imap_fetchbody($stream, $messageNumber, $partNumber);
        $body = $this->decode_body_content($body, (int) ($structure->encoding ?? 0));
        $charset = $this->extract_part_charset($structure);
        if ($charset !== '') {
            $body = $this->convert_to_utf8($body, $charset);
        }

        $subtype = strtolower((string) ($structure->subtype ?? 'plain'));
        if ((int) ($structure->type ?? 0) === 0 && $subtype === 'plain') {
            $result['text'] = trim($body);
        } elseif ((int) ($structure->type ?? 0) === 0 && $subtype === 'html') {
            $result['html'] = trim($body);
        }

        return $result;
    }

    protected function get_settings()
    {
        return [
            'host' => trim((string) get_option('marketing_reply_mailbox_host')),
            'port' => trim((string) get_option('marketing_reply_mailbox_port')),
            'encryption' => trim((string) get_option('marketing_reply_mailbox_encryption')),
            'username' => trim((string) get_option('marketing_reply_mailbox_username')),
            'password' => $this->decode_secret((string) get_option('marketing_reply_mailbox_password')),
            'folder' => trim((string) get_option('marketing_reply_mailbox_folder')),
            'processed_folder' => trim((string) get_option('marketing_reply_mailbox_processed_folder')),
        ];
    }

    protected function is_mailbox_configured(array $settings)
    {
        return $settings['host'] !== ''
            && $settings['port'] !== ''
            && $settings['encryption'] !== ''
            && $settings['username'] !== ''
            && $settings['password'] !== ''
            && $settings['folder'] !== '';
    }

    protected function imap_available()
    {
        return function_exists('imap_open');
    }

    protected function build_mailbox_path(array $settings, $folder)
    {
        $flags = '/imap';
        $encryption = strtolower(trim((string) ($settings['encryption'] ?? 'ssl')));
        if ($encryption === 'ssl') {
            $flags .= '/ssl/novalidate-cert';
        } elseif ($encryption === 'tls') {
            $flags .= '/tls/validate-cert';
        } else {
            $flags .= '/notls';
        }

        return '{' . $settings['host'] . ':' . (int) $settings['port'] . $flags . '}' . $this->encode_folder_name($folder);
    }

    protected function encode_folder_name($folder)
    {
        $folder = trim((string) $folder);
        if ($folder === '') {
            return 'INBOX';
        }

        return function_exists('imap_utf7_encode') ? imap_utf7_encode($folder) : $folder;
    }

    protected function resolve_imap_date($value)
    {
        $timestamp = strtotime((string) $value);

        return $timestamp !== false && $timestamp > 0
            ? date('Y-m-d H:i:s', $timestamp)
            : date('Y-m-d H:i:s');
    }

    protected function normalize_header_text($rawHeaders)
    {
        $headers = [];
        $current = '';
        foreach (preg_split("/\r\n|\n|\r/", (string) $rawHeaders) as $line) {
            if ($line === '') {
                continue;
            }
            if (preg_match('/^\s+/', $line) === 1 && $current !== '') {
                $headers[$current] .= ' ' . trim($line);
                continue;
            }
            $parts = explode(':', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $current = strtolower(trim($parts[0]));
            $headers[$current] = trim($parts[1]);
        }

        return $headers;
    }

    protected function extract_address_list($addresses)
    {
        $items = [];
        foreach ((array) $addresses as $address) {
            $email = $this->extract_address_email([$address]);
            if ($email !== '') {
                $items[] = $email;
            }
        }

        return $items;
    }

    protected function extract_address_email($addresses)
    {
        foreach ((array) $addresses as $address) {
            $mailbox = trim((string) ($address->mailbox ?? ''));
            $host = trim((string) ($address->host ?? ''));
            if ($mailbox !== '' && $host !== '') {
                $email = $mailbox . '@' . $host;
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    return mb_strtolower($email);
                }
            }
        }

        return '';
    }

    protected function extract_address_name($addresses)
    {
        foreach ((array) $addresses as $address) {
            $personal = trim((string) ($address->personal ?? ''));
            if ($personal !== '') {
                return $this->decode_imap_header_value($personal);
            }
        }

        return '';
    }

    protected function decode_imap_header_value($value)
    {
        $value = (string) $value;
        if ($value === '') {
            return '';
        }

        $decoded = function_exists('imap_mime_header_decode') ? @imap_mime_header_decode($value) : false;
        if (!is_array($decoded)) {
            return trim($value);
        }

        $output = '';
        foreach ($decoded as $part) {
            $charset = strtoupper((string) ($part->charset ?? 'UTF-8'));
            $text = (string) ($part->text ?? '');
            if ($charset !== 'DEFAULT' && $charset !== 'UTF-8' && function_exists('iconv')) {
                $converted = @iconv($charset, 'UTF-8//IGNORE', $text);
                if ($converted !== false) {
                    $text = $converted;
                }
            }
            $output .= $text;
        }

        return trim($output);
    }

    protected function decode_body_content($body, $encoding)
    {
        if ((int) $encoding === 3) {
            $decoded = base64_decode((string) $body, true);

            return $decoded !== false ? $decoded : (string) $body;
        }

        if ((int) $encoding === 4) {
            return quoted_printable_decode((string) $body);
        }

        return (string) $body;
    }

    protected function extract_part_charset($part)
    {
        $parameters = array_merge(
            is_array($part->parameters ?? null) ? $part->parameters : [],
            is_array($part->dparameters ?? null) ? $part->dparameters : []
        );

        foreach ($parameters as $parameter) {
            if (strtolower((string) ($parameter->attribute ?? '')) === 'charset') {
                return trim((string) ($parameter->value ?? ''));
            }
        }

        return '';
    }

    protected function convert_to_utf8($value, $charset)
    {
        if ($charset === '' || strtoupper($charset) === 'UTF-8' || !function_exists('iconv')) {
            return (string) $value;
        }

        $converted = @iconv($charset, 'UTF-8//IGNORE', (string) $value);

        return $converted !== false ? $converted : (string) $value;
    }

    protected function decode_secret($stored)
    {
        $stored = (string) $stored;
        if ($stored === '') {
            return '';
        }

        $this->ci->load->library('encryption');
        $decrypted = $this->ci->encryption->decrypt($stored);
        if (is_string($decrypted) && $decrypted !== '') {
            return $decrypted;
        }

        return $stored;
    }

    protected function update_diagnostics($processedCount, $lastError)
    {
        $this->update_option('marketing_reply_mailbox_last_poll_at', date('Y-m-d H:i:s'));
        $this->update_option('marketing_reply_mailbox_last_processed_count', (string) max(0, (int) $processedCount));
        $this->update_option('marketing_reply_mailbox_last_error', trim((string) $lastError));
    }

    protected function update_option($name, $value)
    {
        if (function_exists('marketing_update_runtime_option')) {
            marketing_update_runtime_option($name, $value);

            return;
        }

        if (get_option($name) === false) {
            add_option($name, $value);
        } else {
            update_option($name, $value);
        }
    }

    protected function collect_imap_errors()
    {
        $errors = [];
        if (function_exists('imap_errors')) {
            $errors = array_merge($errors, (array) imap_errors());
        }
        if (function_exists('imap_alerts')) {
            $errors = array_merge($errors, (array) imap_alerts());
        }

        return implode(' | ', array_filter(array_map('trim', $errors)));
    }

    protected function sanitize_imap_error($message)
    {
        if (function_exists('marketing_sanitize_log_message')) {
            $message = marketing_sanitize_log_message($message);
        } else {
            $message = trim(strip_tags((string) $message));
        }

        return $message !== '' ? $message : 'IMAP connection failed.';
    }

    protected function normalize_message_identifier($identifier)
    {
        $identifier = trim((string) $identifier);
        $identifier = trim($identifier, '<>');

        return $identifier;
    }

    protected function already_processed_mailbox_uid($mailboxUid, $mailboxFolder)
    {
        $mailboxUid = trim((string) $mailboxUid);
        $mailboxFolder = trim((string) $mailboxFolder);
        if ($mailboxUid === '') {
            return false;
        }

        if (!isset($this->ci->marketing_conversations_model)
            || !is_object($this->ci->marketing_conversations_model)
            || !method_exists($this->ci->marketing_conversations_model, 'find_message_by_mailbox_uid')) {
            return false;
        }

        if (method_exists($this->ci->marketing_conversations_model, 'tables_available')
            && !$this->ci->marketing_conversations_model->tables_available()) {
            return false;
        }

        $existing = $this->ci->marketing_conversations_model->find_message_by_mailbox_uid($mailboxUid, $mailboxFolder !== '' ? $mailboxFolder : null);

        return !empty($existing);
    }
}
