<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Marketing_dashboard_model extends App_Model
{
    const CAMPAIGNS_TABLE       = 'marketing_campaigns';
    const LISTS_TABLE           = 'marketing_lists';
    const CONTACTS_TABLE        = 'marketing_contacts';
    const MEMBERS_TABLE         = 'marketing_list_members';
    const RECIPIENTS_TABLE      = 'marketing_campaign_recipients';
    const EVENTS_TABLE          = 'marketing_events';
    const SUPPRESSIONS_TABLE    = 'marketing_suppressions';

    protected $campaignsTable;
    protected $listsTable;
    protected $contactsTable;
    protected $membersTable;
    protected $recipientsTable;
    protected $eventsTable;
    protected $suppressionsTable;

    public function __construct()
    {
        parent::__construct();

        $this->campaignsTable    = db_prefix() . self::CAMPAIGNS_TABLE;
        $this->listsTable        = db_prefix() . self::LISTS_TABLE;
        $this->contactsTable     = db_prefix() . self::CONTACTS_TABLE;
        $this->membersTable      = db_prefix() . self::MEMBERS_TABLE;
        $this->recipientsTable   = db_prefix() . self::RECIPIENTS_TABLE;
        $this->eventsTable       = db_prefix() . self::EVENTS_TABLE;
        $this->suppressionsTable = db_prefix() . self::SUPPRESSIONS_TABLE;
    }

    public function get_dashboard_data($campaignLimit = 5, $activityLimit = 10)
    {
        $eventModel = $this->get_events_model();
        $eventSummary = $eventModel->get_normalized_summary();
        $totalRecipients = $this->db->count_all($this->recipientsTable);
        $sentRecipients = total_rows($this->recipientsTable, ['status' => 'sent']);
        $delivered = (int) $eventSummary['delivered'];
        $opened = (int) $eventSummary['open'];
        $bounced = (int) $eventSummary['bounce'];
        $spamComplaints = (int) $eventSummary['complaint'];

        $deliveryRate = $sentRecipients > 0 ? ($delivered / $sentRecipients) * 100 : 0;
        $openRate = $delivered > 0 ? ($opened / $delivered) * 100 : 0;
        $bounceRate = $sentRecipients > 0 ? ($bounced / $sentRecipients) * 100 : 0;

        return [
            'total_campaigns'        => $this->db->count_all($this->campaignsTable),
            'total_lists'            => $this->db->count_all($this->listsTable),
            'total_contacts'         => $this->db->count_all($this->contactsTable),
            'total_memberships'      => $this->db->count_all($this->membersTable),
            'active_suppressions'    => total_rows($this->suppressionsTable, ['active' => 1]),
            'total_recipients'       => $totalRecipients,
            'sent_recipients'        => $sentRecipients,
            'delivered_events'       => $delivered,
            'opened_events'          => $opened,
            'bounced_events'         => $bounced,
            'spam_complaint_events'  => $spamComplaints,
            'delivery_rate'          => $deliveryRate,
            'open_rate'              => $openRate,
            'bounce_rate'            => $bounceRate,
            'click_analytics'        => $this->get_click_analytics(),
            'recent_campaigns'       => $this->get_recent_campaigns($campaignLimit),
            'recent_activity'        => $this->get_recent_activity($activityLimit),
        ];
    }

    public function get_click_analytics($topLimit = 5)
    {
        $rows = $this->db->select('id, campaign_id, campaign_recipient_id, url, payload_json')
            ->from($this->eventsTable)
            ->where_in('event_type', ['click', 'clicked'])
            ->order_by('event_at', 'DESC')
            ->get()
            ->result_array();

        $analytics = [
            'total_clicks' => 0,
            'unique_clicked_recipients' => 0,
            'top_clicked_links' => [],
            'clicks_by_campaign' => [],
            'chart_mode' => 'empty',
            'chart_labels' => [],
            'chart_values' => [],
            'has_data' => false,
        ];

        if (empty($rows)) {
            return $analytics;
        }

        $analytics['total_clicks'] = count($rows);
        $analytics['has_data'] = true;

        $uniqueRecipients = [];
        $linkCounts = [];
        $campaignCounts = [];

        foreach ($rows as $row) {
            if (!empty($row['campaign_recipient_id'])) {
                $uniqueRecipients[(int) $row['campaign_recipient_id']] = true;
            }

            $resolvedUrl = $this->extract_click_url($row);
            if ($resolvedUrl !== null) {
                if (!isset($linkCounts[$resolvedUrl])) {
                    $linkCounts[$resolvedUrl] = 0;
                }
                $linkCounts[$resolvedUrl]++;
            }

            $campaignId = (int) ($row['campaign_id'] ?? 0);
            if ($campaignId > 0) {
                if (!isset($campaignCounts[$campaignId])) {
                    $campaignCounts[$campaignId] = 0;
                }
                $campaignCounts[$campaignId]++;
            }
        }

        $analytics['unique_clicked_recipients'] = count($uniqueRecipients);

        if (!empty($linkCounts)) {
            arsort($linkCounts);
            $topLinks = array_slice($linkCounts, 0, max(1, (int) $topLimit), true);

            foreach ($topLinks as $url => $count) {
                $analytics['top_clicked_links'][] = [
                    'url' => $url,
                    'count' => (int) $count,
                ];
                $analytics['chart_labels'][] = $url;
                $analytics['chart_values'][] = (int) $count;
            }

            $analytics['chart_mode'] = 'links';
        } elseif (!empty($campaignCounts)) {
            arsort($campaignCounts);
            $campaignNames = $this->get_campaign_names(array_keys($campaignCounts));
            $topCampaigns = array_slice($campaignCounts, 0, max(1, (int) $topLimit), true);

            foreach ($topCampaigns as $campaignId => $count) {
                $label = isset($campaignNames[$campaignId]) ? $campaignNames[$campaignId] : ('#' . $campaignId);
                $analytics['clicks_by_campaign'][] = [
                    'campaign_id' => (int) $campaignId,
                    'campaign_name' => $label,
                    'count' => (int) $count,
                ];
                $analytics['chart_labels'][] = $label;
                $analytics['chart_values'][] = (int) $count;
            }

            $analytics['chart_mode'] = 'campaigns';
        }

        if (empty($analytics['clicks_by_campaign']) && !empty($campaignCounts)) {
            arsort($campaignCounts);
            $campaignNames = $this->get_campaign_names(array_keys($campaignCounts));
            foreach ($campaignCounts as $campaignId => $count) {
                $analytics['clicks_by_campaign'][] = [
                    'campaign_id' => (int) $campaignId,
                    'campaign_name' => isset($campaignNames[$campaignId]) ? $campaignNames[$campaignId] : ('#' . $campaignId),
                    'count' => (int) $count,
                ];
            }
        }

        return $analytics;
    }

    public function get_analytics_summary()
    {
        $eventSummary = $this->get_events_model()->get_normalized_summary();
        return [
            'campaigns' => [
                'total' => $this->db->count_all($this->campaignsTable),
                'draft' => total_rows($this->campaignsTable, ['status' => 'draft']),
                'sending' => total_rows($this->campaignsTable, ['status' => 'sending']),
                'sent' => total_rows($this->campaignsTable, ['status' => 'sent']),
                'failed' => total_rows($this->campaignsTable, ['status' => 'failed']),
            ],
            'recipients' => [
                'total' => $this->db->count_all($this->recipientsTable),
                'queued' => total_rows($this->recipientsTable, ['status' => 'queued']),
                'processing' => total_rows($this->recipientsTable, ['status' => 'processing']),
                'sent' => total_rows($this->recipientsTable, ['status' => 'sent']),
                'failed' => total_rows($this->recipientsTable, ['status' => 'failed']),
            ],
            'events' => [
                'total_records' => (int) $eventSummary['total_records'],
                'accepted_sent' => (int) $eventSummary['accepted_sent'],
                'delivered' => (int) $eventSummary['delivered'],
                'open' => (int) $eventSummary['open'],
                'click' => (int) $eventSummary['click'],
                'bounce' => (int) $eventSummary['bounce'],
                'complaint' => (int) $eventSummary['complaint'],
                'failed_rejected' => (int) $eventSummary['failed_rejected'],
                'unsubscribe' => (int) $eventSummary['unsubscribe'],
                'delivery_delay' => (int) $eventSummary['delivery_delay'],
            ],
        ];
    }

    public function get_recent_campaigns($limit = 5)
    {
        $this->db->select('id, name, subject, status, scheduled_at, send_completed_at');
        $this->db->order_by('id', 'DESC');

        return $this->db->get($this->campaignsTable, $limit)->result_array();
    }

    public function get_recent_activity($limit = 10)
    {
        $this->db->select('id, event_type, provider, email, event_at, url');
        $this->db->order_by('event_at', 'DESC');

        return $this->db->get($this->eventsTable, $limit)->result_array();
    }

    protected function extract_click_url(array $row)
    {
        $directUrl = trim((string) ($row['url'] ?? ''));
        if ($directUrl !== '') {
            return $directUrl;
        }

        $payloadJson = $row['payload_json'] ?? null;
        if (!is_string($payloadJson) || trim($payloadJson) === '') {
            return null;
        }

        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            return null;
        }

        $candidates = [
            $payload['click']['link'] ?? null,
            $payload['click']['url'] ?? null,
            $payload['link'] ?? null,
            $payload['url'] ?? null,
            $payload['clicked_url'] ?? null,
            $payload['destination'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    protected function get_campaign_names(array $campaignIds)
    {
        $campaignIds = array_values(array_unique(array_map('intval', $campaignIds)));
        if (empty($campaignIds)) {
            return [];
        }

        $rows = $this->db->select('id, name')
            ->from($this->campaignsTable)
            ->where_in('id', $campaignIds)
            ->get()
            ->result_array();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['id']] = (string) $row['name'];
        }

        return $map;
    }

    protected function count_events_by_types(array $eventTypes)
    {
        return (int) $this->db->from($this->eventsTable)
            ->where_in('event_type', $eventTypes)
            ->count_all_results();
    }

    protected function get_events_model()
    {
        $CI = &get_instance();
        $CI->load->model('marketing/Marketing_events_model', 'marketing_events_model');

        return $CI->marketing_events_model;
    }
}
