<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Marketing_ses_sns
{
    public function normalize_event_name($name)
    {
        $normalized = strtolower(trim((string) $name));
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
            'notification' => 'notification',
            'unsubscribe' => 'unsubscribe',
            'unsubscribed' => 'unsubscribe',
            'failed' => 'failed',
        ];

        return $map[$normalized] ?? $normalized;
    }

    public function verify(array $body, array $headers)
    {
        // SES SNS signature verification is not implemented in this module.
        // The webhook handler uses this response to enforce the enabled/disabled option safely.
        return [
            'implemented' => false,
            'valid'       => false,
            'message'     => 'SES SNS signature verification is not implemented.',
        ];
    }
}
