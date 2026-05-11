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
    const PUBLIC_LINKS_TABLE    = 'marketing_public_links';
    const SUPPRESSIONS_TABLE    = 'marketing_suppressions';

    protected $campaignsTable;
    protected $listsTable;
    protected $contactsTable;
    protected $membersTable;
    protected $recipientsTable;
    protected $eventsTable;
    protected $publicLinksTable;
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
        $this->publicLinksTable  = db_prefix() . self::PUBLIC_LINKS_TABLE;
        $this->suppressionsTable = db_prefix() . self::SUPPRESSIONS_TABLE;
    }

    public function get_dashboard_data($campaignLimit = 5, $activityLimit = 10)
    {
        $defaults = $this->get_default_dashboard_data();
        $eventSummary = $this->get_safe_event_summary();
        $queueDiagnostics = $this->get_safe_queue_diagnostics();
        $retentionDiagnostics = $this->get_safe_retention_diagnostics();
        $totalRecipients = $this->db->count_all($this->recipientsTable);
        $sentRecipients = total_rows($this->recipientsTable, ['status' => 'sent']);
        $delivered = (int) $eventSummary['delivered'];
        $opened = (int) $eventSummary['open'];
        $bounced = (int) $eventSummary['bounce'];
        $spamComplaints = (int) $eventSummary['complaint'];

        $deliveryRate = $sentRecipients > 0 ? ($delivered / $sentRecipients) * 100 : 0;
        $openRate = $delivered > 0 ? ($opened / $delivered) * 100 : 0;
        $bounceRate = $sentRecipients > 0 ? ($bounced / $sentRecipients) * 100 : 0;

        return array_merge($defaults, [
            'total_campaigns'        => $this->db->count_all($this->campaignsTable),
            'total_lists'            => $this->db->count_all($this->listsTable),
            'total_contacts'         => $this->count_active_audience_contacts(),
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
            'queue_diagnostics'      => $queueDiagnostics,
            'retention_diagnostics'  => $retentionDiagnostics,
            'recent_campaigns'       => $this->get_recent_campaigns($campaignLimit),
            'recent_activity'        => $this->get_recent_activity($activityLimit),
        ]);
    }

    public function get_click_analytics($topLimit = 5)
    {
        $analytics = $this->get_default_click_analytics();
        $recipientClickSummary = $this->get_recipient_click_summary();
        $publicLinkSummary = $this->get_public_link_click_summary();
        $topLinkRows = $this->get_top_clicked_links($topLimit);
        $campaignRows = $this->get_clicks_by_campaign($topLimit);
        $rawClickEvents = $this->count_events_by_types(['click', 'clicked']);

        $analytics['human_clicks'] = (int) ($recipientClickSummary['human_clicks'] ?? 0);
        $analytics['unique_human_clicks'] = (int) ($recipientClickSummary['unique_human_clicks'] ?? 0);
        $analytics['raw_click_events'] = $rawClickEvents;
        $analytics['tracked_link_human_clicks'] = (int) ($publicLinkSummary['human_clicks'] ?? 0);
        $analytics['tracked_link_unique_human_clicks'] = (int) ($publicLinkSummary['unique_clicks'] ?? 0);
        $analytics['total_clicks'] = (int) ($recipientClickSummary['human_clicks'] ?? 0);
        $analytics['unique_clicked_recipients'] = (int) ($recipientClickSummary['unique_human_clicks'] ?? 0);

        if ($analytics['human_clicks'] <= 0 && $analytics['unique_human_clicks'] <= 0 && empty($topLinkRows) && empty($campaignRows)) {
            return $analytics;
        }

        $analytics['has_data'] = true;
        $analytics['top_clicked_links'] = $topLinkRows;
        $analytics['clicks_by_campaign'] = $campaignRows;

        if (!empty($topLinkRows)) {
            foreach ($topLinkRows as $row) {
                $analytics['chart_labels'][] = (string) $row['url'];
                $analytics['chart_values'][] = (int) $row['count'];
            }
            $analytics['chart_mode'] = 'links';
        } elseif (!empty($campaignRows)) {
            foreach ($campaignRows as $row) {
                $analytics['chart_labels'][] = (string) $row['campaign_name'];
                $analytics['chart_values'][] = (int) $row['count'];
            }
            $analytics['chart_mode'] = 'campaigns';
        }

        return $analytics;
    }

    public function get_top_clicked_links($topLimit = 5)
    {
        try {
            $rows = $this->get_top_clicked_links_from_public_links($topLimit);

            return is_array($rows) ? $rows : [];
        } catch (Throwable $exception) {
            $this->log_dashboard_runtime_failure('Marketing dashboard top clicked links unavailable: ', $exception);
        }

        return [];
    }

    public function get_clicks_by_campaign($topLimit = 5)
    {
        try {
            $rows = $this->get_clicks_by_campaign_metrics($topLimit);

            return is_array($rows) ? $rows : [];
        } catch (Throwable $exception) {
            $this->log_dashboard_runtime_failure('Marketing dashboard clicks-by-campaign metrics unavailable: ', $exception);
        }

        return [];
    }

    public function get_analytics_summary()
    {
        $eventSummary = $this->get_safe_event_summary();
        $sentRecipients = $this->safe_total_rows($this->recipientsTable, ['status' => 'sent']);
        $acceptedSentEvents = (int) $eventSummary['accepted_sent'];
        $recipientClickSummary = $this->get_recipient_click_summary();
        $publicLinkSummary = $this->get_public_link_click_summary();

        return [
            'campaigns' => [
                'total' => $this->safe_count_all($this->campaignsTable),
                'draft' => $this->safe_total_rows($this->campaignsTable, ['status' => 'draft']),
                'sending' => $this->safe_total_rows($this->campaignsTable, ['status' => 'sending']),
                'sent' => $this->safe_total_rows($this->campaignsTable, ['status' => 'sent']),
                'failed' => $this->safe_total_rows($this->campaignsTable, ['status' => 'failed']),
            ],
            'recipients' => [
                'total' => $this->safe_count_all($this->recipientsTable),
                'queued' => $this->safe_total_rows($this->recipientsTable, ['status' => 'queued']),
                'processing' => $this->safe_total_rows($this->recipientsTable, ['status' => 'processing']),
                'sent' => $sentRecipients,
                'failed' => $this->safe_total_rows($this->recipientsTable, ['status' => 'failed']),
            ],
            'events' => [
                'total_records' => (int) $eventSummary['total_records'],
                'accepted_sent' => $sentRecipients > 0 ? $sentRecipients : $acceptedSentEvents,
                'accepted_sent_events' => $acceptedSentEvents,
                'delivered' => (int) $eventSummary['delivered'],
                'open' => (int) $eventSummary['open'],
                'click' => (int) ($recipientClickSummary['human_clicks'] ?? 0),
                'human_clicks' => (int) ($recipientClickSummary['human_clicks'] ?? 0),
                'unique_human_clicks' => (int) ($recipientClickSummary['unique_human_clicks'] ?? 0),
                'tracked_link_human_clicks' => (int) ($publicLinkSummary['human_clicks'] ?? 0),
                'tracked_link_unique_human_clicks' => (int) ($publicLinkSummary['unique_clicks'] ?? 0),
                'raw_click_events' => (int) $eventSummary['click'],
                'bounce' => (int) $eventSummary['bounce'],
                'complaint' => (int) $eventSummary['complaint'],
                'failed_rejected' => (int) $eventSummary['failed_rejected'],
                'send_readiness_failed' => (int) $eventSummary['send_readiness_failed'],
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
        if (!$this->db->table_exists($this->eventsTable)) {
            return [];
        }

        $select = [
            $this->db->field_exists('id', $this->eventsTable) ? 'id' : 'NULL AS id',
            $this->db->field_exists('event_type', $this->eventsTable) ? 'event_type' : 'NULL AS event_type',
            $this->db->field_exists('provider', $this->eventsTable) ? 'provider' : 'NULL AS provider',
            $this->db->field_exists('email', $this->eventsTable) ? 'email' : 'NULL AS email',
            $this->db->field_exists('event_at', $this->eventsTable) ? 'event_at' : 'NULL AS event_at',
            $this->db->field_exists('url', $this->eventsTable) ? 'url' : 'NULL AS url',
        ];

        $this->db->select(implode(', ', $select), false);
        if ($this->db->field_exists('event_at', $this->eventsTable)) {
            $this->db->order_by('event_at', 'DESC');
        } elseif ($this->db->field_exists('id', $this->eventsTable)) {
            $this->db->order_by('id', 'DESC');
        }

        return $this->db->get($this->eventsTable, $limit)->result_array();
    }

    protected function extract_click_url(array $row)
    {
        $directUrl = trim((string) ($row['url'] ?? ''));
        if ($directUrl !== '' && !$this->is_internal_tracking_url($directUrl)) {
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
            if (is_string($candidate) && trim($candidate) !== '' && !$this->is_internal_tracking_url($candidate)) {
                return trim($candidate);
            }
        }

        return null;
    }

    protected function get_recipient_click_summary()
    {
        $summary = [
            'human_clicks' => 0,
            'unique_human_clicks' => 0,
        ];

        if (!$this->db->table_exists($this->recipientsTable)) {
            return $summary;
        }

        $select = [];
        if ($this->db->field_exists('click_count', $this->recipientsTable)) {
            $select[] = 'COALESCE(SUM(click_count), 0) AS human_clicks';
        } else {
            $select[] = '0 AS human_clicks';
        }
        if ($this->db->field_exists('unique_click_count', $this->recipientsTable)) {
            $select[] = 'COALESCE(SUM(unique_click_count), 0) AS unique_human_clicks';
        } elseif ($this->db->field_exists('click_count', $this->recipientsTable)) {
            $select[] = 'COALESCE(SUM(CASE WHEN click_count > 0 THEN 1 ELSE 0 END), 0) AS unique_human_clicks';
        } else {
            $select[] = '0 AS unique_human_clicks';
        }

        $row = $this->db->select(implode(', ', $select), false)
            ->from($this->recipientsTable)
            ->get()
            ->row_array();

        $summary['human_clicks'] = (int) ($row['human_clicks'] ?? 0);
        $summary['unique_human_clicks'] = (int) ($row['unique_human_clicks'] ?? 0);

        return $summary;
    }

    protected function count_active_audience_contacts()
    {
        if (
            !$this->db->table_exists($this->membersTable)
            || !$this->db->table_exists($this->contactsTable)
            || !$this->db->table_exists($this->listsTable)
            || !$this->db->field_exists('marketing_contact_id', $this->membersTable)
            || !$this->db->field_exists('list_id', $this->membersTable)
            || !$this->db->field_exists('id', $this->contactsTable)
            || !$this->db->field_exists('id', $this->listsTable)
        ) {
            return 0;
        }

        $this->db->from($this->membersTable . ' m');
        $this->db->join($this->contactsTable . ' c', 'c.id = m.marketing_contact_id', 'inner');
        $this->db->join($this->listsTable . ' l', 'l.id = m.list_id', 'inner');

        if ($this->db->field_exists('member_status', $this->membersTable)) {
            $this->db->group_start();
            $this->db->where('m.member_status IS NULL', null, false);
            $this->db->or_where('m.member_status', '');
            $this->db->or_where('m.member_status', 'active');
            $this->db->group_end();
        }

        if ($this->db->field_exists('is_active', $this->listsTable)) {
            $this->db->where('l.is_active', 1);
        } elseif ($this->db->field_exists('status', $this->listsTable)) {
            $this->db->group_start();
            $this->db->where('l.status IS NULL', null, false);
            $this->db->or_where('l.status', '');
            $this->db->or_where('l.status', 'active');
            $this->db->group_end();
        }

        if ($this->db->field_exists('status', $this->contactsTable)) {
            $this->db->group_start();
            $this->db->where('c.status IS NULL', null, false);
            $this->db->or_where('c.status', '');
            $this->db->or_where_not_in('c.status', ['suppressed', 'bounced', 'complained', 'unsubscribed']);
            $this->db->group_end();
        }

        if ($this->db->field_exists('consent_status', $this->contactsTable)) {
            $this->db->group_start();
            $this->db->where('c.consent_status IS NULL', null, false);
            $this->db->or_where('c.consent_status', '');
            $this->db->or_where('c.consent_status !=', 'unsubscribed');
            $this->db->group_end();
        }

        if (
            $this->db->table_exists($this->suppressionsTable)
            && $this->db->field_exists('normalized_email', $this->contactsTable)
            && $this->db->field_exists('normalized_email', $this->suppressionsTable)
            && $this->db->field_exists('active', $this->suppressionsTable)
        ) {
            $this->db->join(
                $this->suppressionsTable . ' s',
                's.normalized_email = c.normalized_email AND s.active = 1',
                'left'
            );
            $this->db->where('s.normalized_email IS NULL', null, false);
        }

        $row = $this->db->select('COUNT(DISTINCT c.id) AS total_contacts', false)
            ->get()
            ->row_array();

        return (int) ($row['total_contacts'] ?? 0);
    }

    protected function get_public_link_click_summary()
    {
        $summary = [
            'human_clicks' => 0,
            'unique_clicks' => 0,
        ];

        $this->reset_dashboard_query_builder();

        try {
            if (
                !$this->db->table_exists($this->publicLinksTable)
                || !$this->db->field_exists('token_type', $this->publicLinksTable)
            ) {
                return $summary;
            }

            $select = [];
            if ($this->db->field_exists('human_click_count', $this->publicLinksTable)) {
                $select[] = 'COALESCE(SUM(human_click_count), 0) AS human_clicks';
            } else {
                $select[] = '0 AS human_clicks';
            }
            if ($this->db->field_exists('unique_click_count', $this->publicLinksTable)) {
                $select[] = 'COALESCE(SUM(unique_click_count), 0) AS unique_clicks';
            } else {
                $select[] = '0 AS unique_clicks';
            }

            $row = $this->db->select(implode(', ', $select), false)
                ->from($this->publicLinksTable)
                ->where('token_type', 'click')
                ->get()
                ->row_array();

            $summary['human_clicks'] = (int) ($row['human_clicks'] ?? 0);
            $summary['unique_clicks'] = (int) ($row['unique_clicks'] ?? 0);

            return $summary;
        } finally {
            $this->reset_dashboard_query_builder();
        }
    }

    protected function get_top_clicked_links_from_public_links($topLimit)
    {
        $this->reset_dashboard_query_builder();

        try {
            if (
                !$this->db->table_exists($this->publicLinksTable)
                || !$this->db->field_exists('token_type', $this->publicLinksTable)
                || !$this->db->field_exists('original_url', $this->publicLinksTable)
            ) {
                return $this->get_top_clicked_links_from_event_fallback($topLimit);
            }

            $humanCountExpr = $this->db->field_exists('human_click_count', $this->publicLinksTable)
                ? 'COALESCE(SUM(pl.human_click_count), 0)'
                : '0';
            $uniqueCountExpr = $this->db->field_exists('unique_click_count', $this->publicLinksTable)
                ? 'COALESCE(SUM(pl.unique_click_count), 0)'
                : '0';

            $rows = $this->db->select('pl.original_url AS url, ' . $humanCountExpr . ' AS count, ' . $uniqueCountExpr . ' AS unique_count', false)
                ->from($this->publicLinksTable . ' pl')
                ->where('pl.token_type', 'click');

            if ($this->db->field_exists('is_active', $this->publicLinksTable)) {
                $this->db->where('pl.is_active', 1);
            }

            $rows = $this->db
                ->where('pl.original_url IS NOT NULL', null, false)
                ->where('pl.original_url !=', '')
                ->group_by('pl.original_url')
                ->order_by('count', 'DESC')
                ->order_by('unique_count', 'DESC')
                ->limit(max(1, (int) $topLimit))
                ->get()
                ->result_array();

            $links = [];
            foreach ($rows as $row) {
                $url = trim((string) ($row['url'] ?? ''));
                if ($url === '' || $this->is_internal_tracking_url($url) || (int) ($row['count'] ?? 0) <= 0) {
                    continue;
                }

                $links[] = [
                    'url' => $url,
                    'count' => (int) ($row['count'] ?? 0),
                    'unique_count' => (int) ($row['unique_count'] ?? 0),
                ];
            }

            if (!empty($links)) {
                return $links;
            }

            return $this->get_top_clicked_links_from_event_fallback($topLimit);
        } finally {
            $this->reset_dashboard_query_builder();
        }
    }

    protected function get_top_clicked_links_from_event_fallback($topLimit)
    {
        $this->reset_dashboard_query_builder();

        try {
            if (
                !$this->db->table_exists($this->eventsTable)
                || !$this->db->field_exists('provider', $this->eventsTable)
                || !$this->db->field_exists('event_type', $this->eventsTable)
            ) {
                return [];
            }

            $rows = $this->db->select(
                ($this->db->field_exists('url', $this->eventsTable) ? 'url' : 'NULL AS url')
                . ', '
                . ($this->db->field_exists('payload_json', $this->eventsTable) ? 'payload_json' : 'NULL AS payload_json'),
                false
            )
                ->from($this->eventsTable);

            $this->db->where('provider', 'module');

            $this->apply_normalized_event_type_filter($this->db, ['click', 'clicked']);

            if ($this->db->field_exists('event_at', $this->eventsTable)) {
                $this->db->order_by('event_at', 'DESC');
            } elseif ($this->db->field_exists('id', $this->eventsTable)) {
                $this->db->order_by('id', 'DESC');
            }

            $rows = $this->db->get()->result_array();

            $counts = [];
            foreach ($rows as $row) {
                $url = $this->extract_click_url($row);
                if ($url === null) {
                    continue;
                }

                if (!isset($counts[$url])) {
                    $counts[$url] = 0;
                }
                $counts[$url]++;
            }

            arsort($counts);
            $links = [];
            foreach (array_slice($counts, 0, max(1, (int) $topLimit), true) as $url => $count) {
                $links[] = [
                    'url' => $url,
                    'count' => (int) $count,
                    'unique_count' => 0,
                ];
            }

            return $links;
        } finally {
            $this->reset_dashboard_query_builder();
        }
    }

    protected function get_clicks_by_campaign_metrics($topLimit)
    {
        if (
            !$this->db->table_exists($this->recipientsTable)
            || !$this->db->field_exists('click_count', $this->recipientsTable)
            || !$this->db->field_exists('campaign_id', $this->recipientsTable)
        ) {
            return [];
        }

        $uniqueExpr = $this->db->field_exists('unique_click_count', $this->recipientsTable)
            ? 'COALESCE(SUM(r.unique_click_count), 0)'
            : 'COALESCE(SUM(CASE WHEN r.click_count > 0 THEN 1 ELSE 0 END), 0)';

        $campaignNameSelect = 'NULL AS campaign_name';
        $this->db->from($this->recipientsTable . ' r');
        if (
            $this->db->table_exists($this->campaignsTable)
            && $this->db->field_exists('id', $this->campaignsTable)
            && $this->db->field_exists('name', $this->campaignsTable)
        ) {
            $campaignNameSelect = 'c.name AS campaign_name';
            $this->db->join($this->campaignsTable . ' c', 'c.id = r.campaign_id', 'left');
        }

        $rows = $this->db->select('r.campaign_id, ' . $campaignNameSelect . ', COALESCE(SUM(r.click_count), 0) AS count, ' . $uniqueExpr . ' AS unique_count', false)
            ->group_by('r.campaign_id')
            ->having('count >', 0)
            ->order_by('count', 'DESC')
            ->order_by('unique_count', 'DESC')
            ->limit(max(1, (int) $topLimit))
            ->get()
            ->result_array();

        $campaigns = [];
        foreach ($rows as $row) {
            $campaignId = (int) ($row['campaign_id'] ?? 0);
            if ($campaignId <= 0) {
                continue;
            }

            $campaigns[] = [
                'campaign_id' => $campaignId,
                'campaign_name' => trim((string) ($row['campaign_name'] ?? '')) !== '' ? (string) $row['campaign_name'] : ('#' . $campaignId),
                'count' => (int) ($row['count'] ?? 0),
                'unique_count' => (int) ($row['unique_count'] ?? 0),
            ];
        }

        return $campaigns;
    }

    protected function is_internal_tracking_url($url)
    {
        $url = trim((string) $url);
        if ($url === '') {
            return false;
        }

        $path = parse_url($url, PHP_URL_PATH);
        $path = is_string($path) ? trim($path) : '';
        if ($path === '') {
            return false;
        }

        return strpos($path, '/marketing/track/c/') !== false;
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
        try {
            $eventModel = $this->get_events_model();
            if (is_object($eventModel) && method_exists($eventModel, 'count_events_by_normalized_types')) {
                return (int) $eventModel->count_events_by_normalized_types($eventTypes);
            }
        } catch (Throwable $exception) {
            $this->log_dashboard_runtime_failure('Marketing dashboard event type counts unavailable: ', $exception);
        }

        return 0;
    }

    protected function apply_normalized_event_type_filter($db, array $eventTypes)
    {
        if (!$this->db->field_exists('event_type', $this->eventsTable)) {
            $db->where('1 = 0', null, false);

            return;
        }

        $aliases = [];
        foreach ($eventTypes as $eventType) {
            $normalized = strtolower(trim((string) $eventType));
            $normalized = str_replace([' ', '-'], '_', $normalized);
            if ($normalized !== '') {
                $aliases[] = $normalized;
            }
        }

        $aliases = array_values(array_unique($aliases));
        if (empty($aliases)) {
            $db->where('1 = 0', null, false);

            return;
        }

        $escapedAliases = [];
        foreach ($aliases as $alias) {
            $escapedAliases[] = $this->db->escape($alias);
        }

        $db->where(
            "LOWER(REPLACE(REPLACE(TRIM(event_type), ' ', '_'), '-', '_')) IN (" . implode(', ', $escapedAliases) . ')',
            null,
            false
        );
    }

    protected function get_events_model()
    {
        $CI = &get_instance();
        $CI->load->model('marketing/Marketing_events_model', 'marketing_events_model');

        return $CI->marketing_events_model;
    }

    protected function get_campaigns_model()
    {
        $CI = &get_instance();
        $CI->load->model('marketing/Marketing_campaigns_model', 'marketing_campaigns_model');

        return $CI->marketing_campaigns_model;
    }

    protected function safe_count_all($table)
    {
        if (!is_string($table) || $table === '' || !$this->db->table_exists($table)) {
            return 0;
        }

        try {
            return (int) $this->db->count_all($table);
        } catch (Throwable $exception) {
            $this->log_dashboard_runtime_failure('Marketing dashboard count unavailable: ', $exception);
        }

        return 0;
    }

    protected function safe_total_rows($table, array $where)
    {
        if (!is_string($table) || $table === '' || !$this->db->table_exists($table)) {
            return 0;
        }

        foreach (array_keys($where) as $column) {
            if (!$this->db->field_exists($column, $table)) {
                return 0;
            }
        }

        try {
            return (int) total_rows($table, $where);
        } catch (Throwable $exception) {
            $this->log_dashboard_runtime_failure('Marketing dashboard filtered count unavailable: ', $exception);
        }

        return 0;
    }

    protected function log_dashboard_runtime_failure($prefix, Throwable $exception)
    {
        log_message('error', $prefix . $this->sanitize_log_message($exception->getMessage()));
    }

    protected function sanitize_log_message($message)
    {
        if (function_exists('marketing_sanitize_log_message')) {
            return marketing_sanitize_log_message($message);
        }

        $message = trim(strip_tags((string) $message));
        if ($message === '') {
            return 'unknown_error';
        }

        return function_exists('mb_substr')
            ? mb_substr($message, 0, 1000)
            : substr($message, 0, 1000);
    }

    protected function reset_dashboard_query_builder()
    {
        if (is_object($this->db) && method_exists($this->db, 'reset_query')) {
            $this->db->reset_query();
        }
    }

    protected function get_safe_event_summary()
    {
        $defaults = [
            'total_records' => 0,
            'accepted_sent' => 0,
            'delivered' => 0,
            'open' => 0,
            'click' => 0,
            'bounce' => 0,
            'complaint' => 0,
            'failed_rejected' => 0,
            'send_readiness_failed' => 0,
            'unsubscribe' => 0,
            'delivery_delay' => 0,
        ];

        try {
            $eventModel = $this->get_events_model();
            if (is_object($eventModel) && method_exists($eventModel, 'get_normalized_summary')) {
                $summary = $eventModel->get_normalized_summary();
                if (is_array($summary)) {
                    return array_merge($defaults, $summary);
                }
            }
        } catch (Throwable $exception) {
            $this->log_dashboard_runtime_failure('Marketing dashboard event summary unavailable: ', $exception);
        }

        return $defaults;
    }

    protected function get_safe_retention_diagnostics()
    {
        try {
            $eventModel = $this->get_events_model();
            if (is_object($eventModel) && method_exists($eventModel, 'get_retention_diagnostics')) {
                $diagnostics = $eventModel->get_retention_diagnostics();
                if (is_array($diagnostics)) {
                    return array_merge($this->get_default_retention_diagnostics(), $diagnostics);
                }
            }
        } catch (Throwable $exception) {
            $this->log_dashboard_runtime_failure('Marketing dashboard retention diagnostics unavailable: ', $exception);
        }

        return $this->get_default_retention_diagnostics();
    }

    protected function get_safe_queue_diagnostics()
    {
        try {
            $campaignsModel = $this->get_campaigns_model();
            if (is_object($campaignsModel) && method_exists($campaignsModel, 'get_queue_diagnostics')) {
                $diagnostics = $campaignsModel->get_queue_diagnostics();
                if (is_array($diagnostics)) {
                    return $diagnostics;
                }
            }
        } catch (Throwable $exception) {
            $this->log_dashboard_runtime_failure('Marketing dashboard queue diagnostics unavailable: ', $exception);
        }

        return [
            'schema_ready' => false,
            'queue_paused' => false,
            'schema_missing' => [],
            'rate_limit_snapshot' => [],
            'recent_warning_counts' => [],
            'recent_failure_metric_counts' => [],
            'recent_operator_actions' => [],
            'campaign_worker_leases_active' => 0,
            'campaign_worker_leases_stale' => 0,
            'recipients_processing' => 0,
            'recipients_processing_stale' => 0,
            'recipients_queued' => 0,
            'recipients_failed' => 0,
            'recent_recovered_total' => 0,
        ];
    }

    protected function get_default_dashboard_data()
    {
        return [
            'total_campaigns' => 0,
            'total_lists' => 0,
            'total_contacts' => 0,
            'total_memberships' => 0,
            'active_suppressions' => 0,
            'total_recipients' => 0,
            'sent_recipients' => 0,
            'delivered_events' => 0,
            'opened_events' => 0,
            'bounced_events' => 0,
            'spam_complaint_events' => 0,
            'delivery_rate' => 0,
            'open_rate' => 0,
            'bounce_rate' => 0,
            'click_analytics' => $this->get_default_click_analytics(),
            'queue_diagnostics' => $this->get_safe_queue_diagnostics(),
            'retention_diagnostics' => $this->get_default_retention_diagnostics(),
            'recent_campaigns' => [],
            'recent_activity' => [],
        ];
    }

    protected function get_default_click_analytics()
    {
        return [
            'human_clicks' => 0,
            'unique_human_clicks' => 0,
            'raw_click_events' => 0,
            'tracked_link_human_clicks' => 0,
            'tracked_link_unique_human_clicks' => 0,
            'total_clicks' => 0,
            'unique_clicked_recipients' => 0,
            'top_clicked_links' => [],
            'clicks_by_campaign' => [],
            'chart_mode' => 'empty',
            'chart_labels' => [],
            'chart_values' => [],
            'has_data' => false,
        ];
    }

    protected function get_default_retention_diagnostics()
    {
        return [
            'cleanup_mode' => 'explicit_only',
            'event_retention_days' => 0,
            'webhook_log_retention_days' => 0,
            'retention_floor_days' => 30,
            'delete_limit_per_run' => 500,
            'events_cleanup_candidate_preview' => 0,
            'events_cleanup_candidate_more' => false,
            'webhook_logs_cleanup_candidate_preview' => 0,
            'webhook_logs_cleanup_candidate_more' => false,
            'click_aggregate_candidate_preview' => 0,
            'click_aggregate_candidate_more' => false,
            'warnings' => [],
        ];
    }
}
