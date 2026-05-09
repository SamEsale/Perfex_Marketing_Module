<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Marketing_events_model extends App_Model
{
    const TABLE = 'marketing_events';

    protected $table;
    protected $eventAliases = [
        'sent' => ['sent'],
        'delivered' => ['delivered'],
        'open' => ['open', 'opened'],
        'click' => ['click', 'clicked'],
        'bounce' => ['bounce', 'bounced'],
        'complaint' => ['complaint', 'complained'],
        'rejected' => ['rejected'],
        'rendering_failure' => ['rendering_failure'],
        'delivery_delay' => ['delivery_delay'],
        'unsubscribe' => ['unsubscribe', 'unsubscribed'],
        'failed' => ['failed', 'send_readiness_failed'],
        'queued' => ['queued'],
        'send_start' => ['send_start'],
        'batch_processed' => ['batch_processed'],
        'subscription_confirmation' => ['subscription_confirmation'],
        'notification' => ['notification'],
    ];

    public function __construct()
    {
        parent::__construct();

        $this->table = db_prefix() . self::TABLE;
    }

    public function count_all()
    {
        return $this->db->count_all($this->table);
    }

    public function exists($id)
    {
        return total_rows($this->table, ['id' => $id]) > 0;
    }

    public function get_recent($limit = 10)
    {
        $this->db->order_by('id', 'DESC');

        return $this->db->get($this->table, $limit)->result_array();
    }

    public function get_events_paginated($limit, $offset)
    {
        $limit = max(1, (int) $limit);
        $offset = max(0, (int) $offset);

        return $this->db->from($this->table)
            ->order_by('id', 'DESC')
            ->limit($limit, $offset)
            ->get()
            ->result_array();
    }

    public function count_all_events()
    {
        return (int) $this->db->count_all($this->table);
    }

    public function summary_counts()
    {
        $summary = [];
        foreach (['queued', 'sent', 'delivered', 'open', 'click', 'bounce', 'complaint', 'unsubscribe', 'failed'] as $type) {
            $summary[$type] = $this->count_events_by_normalized_types([$type]);
        }

        return $summary;
    }

    public function normalize_event_type($eventType)
    {
        $normalized = strtolower(trim((string) $eventType));
        $normalized = str_replace([' ', '-'], '_', $normalized);

        foreach ($this->eventAliases as $canonical => $aliases) {
            if (in_array($normalized, $aliases, true) || $canonical === $normalized) {
                return $canonical;
            }
        }

        return $normalized;
    }

    public function get_event_type_aliases($eventType)
    {
        $normalized = $this->normalize_event_type($eventType);
        return $this->eventAliases[$normalized] ?? [$normalized];
    }

    public function event_exists(array $criteria)
    {
        $provider = trim((string) ($criteria['provider'] ?? ''));
        $providerMessageId = trim((string) ($criteria['provider_message_id'] ?? ''));
        $providerEventId = trim((string) ($criteria['provider_event_id'] ?? ''));
        $email = trim((string) ($criteria['email'] ?? ''));
        $url = trim((string) ($criteria['url'] ?? ''));
        $eventAt = trim((string) ($criteria['event_at'] ?? ''));
        $snsMessageId = trim((string) ($criteria['sns_message_id'] ?? ''));
        $eventType = trim((string) ($criteria['event_type'] ?? ''));

        if ($provider === '' && $providerMessageId === '' && $providerEventId === '' && $email === '' && $url === '' && $eventAt === '' && $snsMessageId === '' && $eventType === '') {
            return false;
        }

        $this->db->from($this->table);

        if ($provider !== '') {
            $this->db->where('provider', $provider);
        }
        if ($eventType !== '') {
            $this->db->where_in('event_type', $this->get_event_type_aliases($eventType));
        }
        if ($providerMessageId !== '') {
            $this->db->where('provider_message_id', $providerMessageId);
        }
        if ($providerEventId !== '') {
            $this->db->where('provider_event_id', $providerEventId);
        }
        if ($email !== '') {
            $this->db->where('email', $email);
        }
        if ($url !== '') {
            $this->db->where('url', $url);
        }
        if ($eventAt !== '') {
            $this->db->where('event_at', $eventAt);
        }
        if ($snsMessageId !== '') {
            $this->db->like('payload_json', '"sns_message_id":"' . $this->db->escape_str($snsMessageId) . '"', 'both', false);
        }

        return (int) $this->db->count_all_results() > 0;
    }

    public function count_events_by_normalized_types(array $eventTypes, $campaignId = null)
    {
        $aliases = [];
        foreach ($eventTypes as $eventType) {
            $aliases = array_merge($aliases, $this->get_event_type_aliases($eventType));
        }

        $aliases = array_values(array_unique(array_filter($aliases)));
        if (empty($aliases)) {
            return 0;
        }

        $this->db->from($this->table)->where_in('event_type', $aliases);
        if ($campaignId !== null) {
            $this->db->where('campaign_id', (int) $campaignId);
        }

        return (int) $this->db->count_all_results();
    }

    public function get_normalized_summary($campaignId = null)
    {
        return [
            'total_records' => $campaignId === null
                ? $this->count_all_events()
                : (int) $this->db->from($this->table)->where('campaign_id', (int) $campaignId)->count_all_results(),
            'accepted_sent' => $this->count_events_by_normalized_types(['sent'], $campaignId),
            'delivered' => $this->count_events_by_normalized_types(['delivered'], $campaignId),
            'open' => $this->count_events_by_normalized_types(['open'], $campaignId),
            'click' => $this->count_events_by_normalized_types(['click'], $campaignId),
            'bounce' => $this->count_events_by_normalized_types(['bounce'], $campaignId),
            'complaint' => $this->count_events_by_normalized_types(['complaint'], $campaignId),
            'failed_rejected' => $this->count_events_by_normalized_types(['failed', 'rejected', 'rendering_failure'], $campaignId),
            'unsubscribe' => $this->count_events_by_normalized_types(['unsubscribe'], $campaignId),
            'delivery_delay' => $this->count_events_by_normalized_types(['delivery_delay'], $campaignId),
            'send_start' => $this->count_events_by_normalized_types(['send_start'], $campaignId),
            'batch_processed' => $this->count_events_by_normalized_types(['batch_processed'], $campaignId),
        ];
    }

    public function add_event(array $data)
    {
        $defaults = [
            'campaign_id' => null,
            'campaign_recipient_id' => null,
            'marketing_contact_id' => null,
            'event_type' => null,
            'provider' => 'smtp',
            'provider_event_id' => null,
            'provider_message_id' => null,
            'email' => null,
            'url' => null,
            'ip_address' => null,
            'user_agent' => null,
            'payload_json' => null,
            'event_at' => date('Y-m-d H:i:s'),
            'datecreated' => date('Y-m-d H:i:s'),
        ];

        $event = array_merge($defaults, $data);

        if (empty($event['event_type'])) {
            return false;
        }

        $event['event_type'] = $this->normalize_event_type($event['event_type']);

        $this->db->insert($this->table, $event);

        return $this->db->insert_id();
    }

    public function clear_event_history()
    {
        return (bool) $this->db->empty_table($this->table);
    }
}
