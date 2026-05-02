<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Marketing_mailer
{
    protected $ci;

    public function __construct()
    {
        $this->ci = &get_instance();
        $this->ci->load->model('emails_model');
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

        $configurationSet = trim(get_option('marketing_ses_configuration_set'));
        if ($configurationSet !== '' && method_exists($this->ci->email, 'set_header')) {
            $this->ci->email->set_header('X-SES-CONFIGURATION-SET', $configurationSet);
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

        $message = preg_replace('/smtp_pass\s*[:=]\s*[^\s]+/i', 'smtp_pass=[redacted]', $message);
        $message = preg_replace('/password\s*[:=]\s*[^\s]+/i', 'password=[redacted]', $message);

        return mb_substr($message, 0, 1000);
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

        $this->ci->email->clear(true);
        $this->ci->email->set_newline(config_item('newline') ?: "\r\n");
        $this->ci->email->from($fromEmail, $fromName);
        $this->ci->email->to($recipientEmail);

        if (!empty($replyTo) && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $this->ci->email->reply_to($replyTo);
        }
        if (method_exists($this->ci->email, 'set_header')) {
            $this->ci->email->set_header('X-Marketing-Reply-ID', $replyTracking['marker']);
        }

        $this->ci->email->subject($template->subject);
        $this->ci->email->message($message);

        $textMessage = trim($textContent);
        if ($textMessage === '') {
            $textMessage = strip_html_tags($message, '<br/>, <br>, <br />');
        }

        $this->ci->email->set_alt_message($textMessage);

        $success = $this->ci->email->send();
        $this->ci->email->clear(true);

        return [
            'success' => $success,
            'message_id' => null,
            'error' => $success ? null : $this->sanitize_debug_message($this->ci->email->print_debugger(['headers', 'subject', 'message'])),
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

        $this->ci->email->subject('Marketing SMTP test email');
        $this->ci->email->message('<p>This is a test email sent from the marketing module using current marketing SMTP settings.</p>');
        $this->ci->email->set_alt_message('This is a test email sent from the marketing module using current marketing SMTP settings.');

        $success = $this->ci->email->send();
        $error = $success ? null : $this->sanitize_debug_message($this->ci->email->print_debugger(['headers', 'subject', 'message']));
        $this->ci->email->clear(true);

        return [
            'success' => $success,
            'error' => $error,
        ];
    }
}
