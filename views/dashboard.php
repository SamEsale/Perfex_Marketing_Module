<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<style>
    .marketing-dashboard-shell {
        padding: 0;
        background: transparent;
        border-radius: 0;
    }
    .marketing-dashboard-layout {
        display: grid;
        gap: 24px;
    }
    .marketing-dashboard-hero {
        margin-bottom: 0;
        padding: 18px 20px;
        border: 1px solid rgba(148, 163, 184, .22);
        border-radius: 16px;
        background: #ffffff;
        box-shadow: 0 10px 24px rgba(15, 23, 42, .04);
    }
    .marketing-dashboard-hero-copy {
        margin: 10px 0 0;
        max-width: none;
        color: #64748b;
        font-size: 13px;
        line-height: 1.6;
    }
    .marketing-dashboard-summary-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 16px;
        margin-bottom: 0;
    }
    .marketing-dashboard-summary-card {
        padding: 18px 16px;
        min-height: 116px;
        border-radius: 14px;
        border: 1px solid rgba(148, 163, 184, .22);
        box-shadow: 0 10px 24px rgba(15, 23, 42, .05);
        color: #475569;
    }
    .marketing-dashboard-summary-card:nth-child(8n+1) {
        background: #eef6ff;
        color: #2563eb;
    }
    .marketing-dashboard-summary-card:nth-child(8n+2) {
        background: #ecfdf3;
        color: #15803d;
    }
    .marketing-dashboard-summary-card:nth-child(8n+3) {
        background: #f8fafc;
        color: #475569;
    }
    .marketing-dashboard-summary-card:nth-child(8n+4) {
        background: #f5f3ff;
        color: #6d28d9;
    }
    .marketing-dashboard-summary-card:nth-child(8n+5) {
        background: #ecfdf3;
        color: #15803d;
    }
    .marketing-dashboard-summary-card:nth-child(8n+6) {
        background: #eef6ff;
        color: #2563eb;
    }
    .marketing-dashboard-summary-card:nth-child(8n+7) {
        background: #fef2f2;
        color: #b91c1c;
    }
    .marketing-dashboard-summary-card:nth-child(8n+8) {
        background: #fffbeb;
        color: #b45309;
    }
    .marketing-dashboard-summary-label {
        display: block;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: .08em;
        font-weight: 700;
        color: inherit;
        opacity: .78;
    }
    .marketing-dashboard-summary-value {
        display: block;
        margin-top: 10px;
        color: inherit;
        font-size: 34px;
        line-height: 1.1;
        font-weight: 700;
    }
    .marketing-dashboard-summary-helper {
        display: block;
        margin-top: 8px;
        font-size: 12px;
        color: inherit;
        opacity: .72;
    }
    .marketing-dashboard-section-card {
        border: 1px solid rgba(148, 163, 184, .22);
        border-radius: 16px;
        background: #ffffff;
        box-shadow: 0 10px 24px rgba(15, 23, 42, .04);
        overflow: hidden;
    }
    .marketing-dashboard-section-card.is-analytics {
        background: #ffffff;
    }
    .marketing-dashboard-section-body {
        padding: 18px 20px;
    }
    .marketing-dashboard-section-head {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 18px;
        margin-bottom: 14px;
    }
    .marketing-dashboard-section-title {
        margin: 0;
        color: #0f172a;
        font-size: 17px;
        font-weight: 700;
    }
    .marketing-dashboard-section-copy {
        margin: 6px 0 0;
        color: #64748b;
        font-size: 13px;
        line-height: 1.6;
    }
    .marketing-dashboard-mini-kpis {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
        min-width: 0;
        max-width: 360px;
    }
    .marketing-dashboard-mini-kpi {
        padding: 14px 16px;
        border: 1px solid rgba(148, 163, 184, .18);
        border-radius: 14px;
        background: #f8fafc;
        text-align: left;
    }
    .marketing-dashboard-mini-kpi .marketing-dashboard-summary-value {
        font-size: 24px;
    }
    .marketing-dashboard-inline-note {
        margin: 0;
        color: #64748b;
        font-size: 13px;
    }
    .marketing-dashboard-click-strip {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 12px;
        margin-top: 12px;
    }
    .marketing-dashboard-click-chip {
        padding: 14px 16px;
        border: 1px solid rgba(148, 163, 184, .18);
        border-radius: 14px;
        background: #f8fafc;
        min-width: 0;
    }
    .marketing-dashboard-click-chip.is-highlight {
        background: #eff6ff;
        border-color: rgba(59, 130, 246, .16);
    }
    .marketing-dashboard-click-chip-label {
        display: block;
        color: #64748b;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: .08em;
        text-transform: uppercase;
    }
    .marketing-dashboard-click-chip-value {
        display: block;
        margin-top: 8px;
        color: #0f172a;
        font-size: 24px;
        font-weight: 700;
        line-height: 1.2;
    }
    .marketing-dashboard-click-chip-copy {
        display: block;
        margin-top: 6px;
        color: #64748b;
        font-size: 12px;
        line-height: 1.5;
    }
    .marketing-ops-shell {
        border: 1px solid rgba(148, 163, 184, .22);
        background: #ffffff;
        border-radius: 16px;
        box-shadow: 0 10px 24px rgba(15, 23, 42, .04);
        overflow: hidden;
    }
    .marketing-ops-shell .panel-body {
        padding: 18px 20px;
    }
    .marketing-dashboard-ops-head {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 16px;
    }
    .marketing-dashboard-ops-head-copy {
        color: #64748b;
        font-size: 13px;
        line-height: 1.6;
        margin: 6px 0 0;
    }
    .marketing-dashboard-ops-status-row {
        display: flex;
        flex-wrap: wrap;
        justify-content: flex-end;
        gap: 8px;
    }
    .marketing-dashboard-ops-grid {
        display: grid;
        grid-template-columns: repeat(12, minmax(0, 1fr));
        gap: 18px;
        margin-top: 18px;
    }
    .marketing-ops-section {
        grid-column: span 12;
        margin-top: 0;
        padding: 18px 20px;
        border: 1px solid rgba(148, 163, 184, .22);
        border-radius: 16px;
        background: #ffffff;
        box-shadow: 0 10px 24px rgba(15, 23, 42, .04);
    }
    .marketing-ops-section-title {
        margin: 0;
        font-size: 15px;
        font-weight: 700;
        color: #0f172a;
    }
    .marketing-ops-section-copy {
        margin: 6px 0 0;
        color: #64748b;
        font-size: 13px;
    }
    .marketing-ops-badge {
        display: inline-block;
        margin: 0 8px 8px 0;
        padding: 7px 10px;
        border-radius: 999px;
        background: #ffffff;
        border: 1px solid rgba(148, 163, 184, .28);
        color: #334155;
        font-size: 12px;
        font-weight: 600;
    }
    .marketing-ops-status-pill {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 7px 11px;
        border-radius: 999px;
        border: 1px solid rgba(148, 163, 184, .28);
        background: #ffffff;
        color: #334155;
        font-size: 12px;
        font-weight: 700;
    }
    .marketing-ops-control-shell,
    .marketing-ops-table-shell {
        margin-top: 16px;
        padding: 16px;
        border-radius: 14px;
        border: 1px solid rgba(148, 163, 184, .18);
        background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
    }
    .marketing-ops-control-grid {
        display: grid;
        grid-template-columns: minmax(0, 1.15fr) minmax(280px, .85fr);
        gap: 18px;
        align-items: start;
    }
    .marketing-ops-button-row .btn {
        margin: 0;
    }
    .marketing-ops-button-row {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        align-items: flex-start;
    }
    .marketing-ops-list {
        display: grid;
        gap: 10px;
    }
    .marketing-ops-list-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 14px;
        padding-bottom: 10px;
        border-bottom: 1px solid #f1f5f9;
        color: #334155;
        font-size: 13px;
    }
    .marketing-ops-list-row:last-child {
        border-bottom: 0;
        padding-bottom: 0;
    }
    .marketing-ops-list-row strong {
        color: #0f172a;
        font-weight: 700;
    }
    .marketing-ops-empty {
        margin-top: 14px;
        padding: 14px 16px;
        border: 1px solid rgba(148, 163, 184, .18);
        border-radius: 14px;
        color: #64748b;
        background: #f8fafc;
        font-size: 13px;
        line-height: 1.6;
    }
    .marketing-ops-metric {
        background: #f8fafc;
        border: 1px solid rgba(148, 163, 184, .18);
        border-radius: 14px;
        padding: 14px 15px;
        min-height: 104px;
    }
    .marketing-ops-metric-label {
        display: block;
        color: #64748b;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: .08em;
    }
    .marketing-ops-metric-value {
        display: block;
        margin-top: 8px;
        color: #0f172a;
        font-size: 24px;
        font-weight: 700;
        line-height: 1.1;
    }
    .marketing-ops-metric-helper {
        display: block;
        margin-top: 6px;
        color: #94a3b8;
        font-size: 12px;
    }
    .marketing-ops-retention-grid,
    .marketing-ops-health-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 14px;
    }
    .marketing-ops-compact-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 18px;
        align-items: start;
        padding: 0;
        border: 0;
        background: transparent;
        box-shadow: none;
    }
    .marketing-ops-compact-card {
        min-width: 0;
        padding: 18px 20px;
        border: 1px solid rgba(148, 163, 184, .22);
        border-radius: 16px;
        background: #ffffff;
        box-shadow: 0 10px 24px rgba(15, 23, 42, .04);
    }
    .marketing-ops-compact-card .marketing-ops-section-title {
        margin-bottom: 0;
    }
    .marketing-ops-compact-card .marketing-ops-section-copy {
        min-height: 0;
    }
    .marketing-ops-compact-card .marketing-ops-badge-grid {
        margin-top: 14px;
    }
    .marketing-ops-compact-card .marketing-ops-table-shell {
        max-height: 260px;
        overflow: auto;
    }
    .marketing-ops-badge-grid {
        display: flex;
        flex-wrap: wrap;
        margin-top: 14px;
    }
    .marketing-ops-integrity-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 14px;
        margin-top: 16px;
    }
    .marketing-ops-integrity-badge.critical {
        background: #fef2f2;
        color: #b91c1c;
    }
    .marketing-ops-integrity-badge.warning {
        background: #fff7ed;
        color: #c2410c;
    }
    .marketing-ops-advanced {
        margin-top: 16px;
        padding: 14px 16px;
        border: 1px solid rgba(148, 163, 184, .18);
        border-radius: 14px;
        background: #f8fafc;
    }
    .marketing-ops-advanced summary {
        cursor: pointer;
        color: #0f172a;
        font-weight: 700;
        list-style: none;
    }
    .marketing-ops-advanced summary::-webkit-details-marker {
        display: none;
    }
    .marketing-ops-advanced-copy {
        margin: 10px 0 0;
        color: #64748b;
        font-size: 13px;
        line-height: 1.6;
    }
    .marketing-ops-command-list {
        margin: 12px 0 0;
        padding-left: 18px;
        color: #475569;
    }
    .marketing-ops-command-list li {
        margin-bottom: 8px;
    }
    .marketing-ops-command-block {
        margin-top: 12px;
        padding: 12px 14px;
        border: 1px solid #1e293b;
        border-radius: 14px;
        background: #0f172a;
        color: #e2e8f0;
        font-size: 12px;
        line-height: 1.6;
        white-space: pre-wrap;
        word-break: break-word;
    }
    .marketing-ops-command-block--compact {
        margin-top: 10px;
        max-height: 160px;
        overflow: auto;
    }
    .marketing-ops-cron-strip {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin-top: 14px;
    }
    .marketing-ops-cron-chip {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 9px 12px;
        border: 1px solid rgba(148, 163, 184, .18);
        border-radius: 999px;
        background: #ffffff;
        color: #0f172a;
        min-width: 0;
    }
    .marketing-ops-cron-chip-label {
        color: #64748b;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: .04em;
        text-transform: uppercase;
        white-space: nowrap;
    }
    .marketing-ops-cron-chip-value {
        color: #0f172a;
        font-size: 13px;
        font-weight: 700;
        white-space: nowrap;
    }
    .marketing-ops-cron-chip-value--status {
        border-radius: 999px;
        padding: 4px 9px;
        font-size: 11px;
        letter-spacing: .02em;
        text-transform: uppercase;
    }
    .marketing-ops-cron-chip-value--healthy {
        background: #dcfce7;
        color: #166534;
    }
    .marketing-ops-cron-chip-value--warning {
        background: #fef3c7;
        color: #92400e;
    }
    .marketing-ops-cron-chip-value--critical {
        background: #fee2e2;
        color: #991b1b;
    }
    .marketing-ops-cron-chip-value--not_detected {
        background: #e2e8f0;
        color: #334155;
    }
    .marketing-ops-cron-details {
        margin-top: 16px;
        border: 1px solid rgba(148, 163, 184, .18);
        border-radius: 14px;
        background: #f8fafc;
        overflow: hidden;
    }
    .marketing-ops-cron-details summary {
        list-style: none;
        cursor: pointer;
        padding: 12px 14px;
        color: #0f172a;
        font-size: 13px;
        font-weight: 700;
    }
    .marketing-ops-cron-details summary::-webkit-details-marker {
        display: none;
    }
    .marketing-ops-cron-details-body {
        padding: 0 14px 14px;
    }
    .marketing-ops-cron-details-copy {
        margin: 0;
        color: #64748b;
        font-size: 13px;
        line-height: 1.55;
    }
    .marketing-ops-cron-details-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px 16px;
        margin-top: 12px;
    }
    .marketing-ops-cron-details-item {
        min-width: 0;
    }
    .marketing-ops-cron-details-item span {
        display: block;
        color: #64748b;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: .04em;
        text-transform: uppercase;
    }
    .marketing-ops-cron-details-item strong {
        display: block;
        margin-top: 4px;
        color: #0f172a;
        font-size: 13px;
        line-height: 1.4;
        word-break: break-word;
    }
    .marketing-dashboard-whatsapp-summary {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 14px;
        margin-top: 16px;
    }
    .marketing-dashboard-whatsapp-summary .marketing-ops-metric {
        min-height: 0;
    }
    .marketing-ops-table-shell .table {
        margin-bottom: 0;
    }
    .marketing-ops-table-shell .table > thead > tr > th {
        padding: 12px 14px;
        border-bottom: 1px solid #e2e8f0;
        background: #f8fafc;
        color: #334155;
        font-size: 12px;
        font-weight: 700;
        letter-spacing: .04em;
        text-transform: uppercase;
        white-space: nowrap;
    }
    .marketing-ops-table-shell .table > tbody > tr > td {
        padding: 10px 14px;
        border-top: 1px solid #f1f5f9;
        color: #0f172a;
        font-size: 13px;
        vertical-align: middle;
    }
    .marketing-ops-table-shell .table > tbody > tr:first-child > td {
        border-top: 0;
    }
    .marketing-ops-table-shell .table > tbody > tr:nth-child(even) {
        background: rgba(248, 250, 252, .72);
    }
    [data-marketing-cron-health-panel="1"] {
        grid-column: span 12;
    }
    [data-marketing-dashboard-integrity-panel="1"],
    [data-marketing-dashboard-whatsapp-panel="1"],
    [data-marketing-dashboard-queue-controls="1"],
    [data-marketing-dashboard-queue-health="1"] {
        grid-column: span 6;
    }
    [data-marketing-dashboard-queue-diagnostics="1"],
    [data-marketing-dashboard-retention-panel="1"] {
        grid-column: span 12;
    }
    [data-marketing-dashboard-whatsapp-panel="1"] .marketing-ops-table-shell,
    [data-marketing-dashboard-queue-diagnostics="1"] .marketing-ops-table-shell {
        overflow-x: auto;
    }
    @media (max-width: 1399px) {
        .marketing-ops-control-grid {
            grid-template-columns: 1fr;
        }
        .marketing-ops-health-grid,
        .marketing-ops-retention-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }
    @media (min-width: 1200px) {
        .marketing-dashboard-hero-copy {
            white-space: nowrap;
        }
    }
    @media (max-width: 1199px) {
        .marketing-dashboard-click-strip,
        .marketing-ops-retention-grid,
        .marketing-ops-health-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        [data-marketing-dashboard-integrity-panel="1"],
        [data-marketing-dashboard-whatsapp-panel="1"],
        [data-marketing-dashboard-queue-controls="1"],
        [data-marketing-dashboard-queue-health="1"] {
            grid-column: span 12;
        }
    }
    @media (max-width: 991px) {
        .marketing-dashboard-summary-grid,
        .marketing-dashboard-whatsapp-summary {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .marketing-ops-cron-details-grid {
            grid-template-columns: 1fr;
        }
        .marketing-dashboard-section-head {
            flex-direction: column;
        }
        .marketing-dashboard-mini-kpis {
            width: 100%;
            max-width: none;
        }
        .marketing-dashboard-ops-head {
            flex-direction: column;
        }
        .marketing-dashboard-ops-status-row {
            justify-content: flex-start;
        }
        .marketing-ops-control-grid,
        .marketing-ops-retention-grid,
        .marketing-ops-health-grid,
        .marketing-ops-compact-grid,
        .marketing-ops-integrity-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }
    @media (max-width: 767px) {
        .marketing-dashboard-summary-grid,
        .marketing-dashboard-mini-kpis,
        .marketing-dashboard-click-strip,
        .marketing-dashboard-whatsapp-summary {
            grid-template-columns: repeat(1, minmax(0, 1fr));
        }
        .marketing-dashboard-layout {
            gap: 18px;
        }
        .marketing-ops-control-grid,
        .marketing-ops-retention-grid,
        .marketing-ops-health-grid,
        .marketing-ops-compact-grid,
        .marketing-ops-integrity-grid {
            grid-template-columns: repeat(1, minmax(0, 1fr));
        }
        .marketing-ops-cron-strip {
            gap: 8px;
        }
        .marketing-ops-cron-chip {
            width: 100%;
            justify-content: space-between;
        }
        .marketing-ops-compact-card .marketing-ops-section-copy {
            min-height: 0;
        }
    }
</style>
<div id="wrapper">
    <div class="content">
        <?php
        $click_analytics = isset($click_analytics) && is_array($click_analytics)
            ? array_replace_recursive([
                'human_clicks' => 0,
                'unique_human_clicks' => 0,
                'raw_click_events' => 0,
                'tracked_link_human_clicks' => 0,
                'tracked_link_unique_human_clicks' => 0,
                'top_clicked_links' => [],
                'clicks_by_campaign' => [],
                'chart_mode' => 'empty',
                'chart_labels' => [],
                'chart_values' => [],
                'has_data' => false,
            ], $click_analytics)
            : [
                'human_clicks' => 0,
                'unique_human_clicks' => 0,
                'raw_click_events' => 0,
                'tracked_link_human_clicks' => 0,
                'tracked_link_unique_human_clicks' => 0,
                'top_clicked_links' => [],
                'clicks_by_campaign' => [],
                'chart_mode' => 'empty',
                'chart_labels' => [],
                'chart_values' => [],
                'has_data' => false,
            ];
        $recent_campaigns = isset($recent_campaigns) && is_array($recent_campaigns) ? $recent_campaigns : [];
        $recent_activity = isset($recent_activity) && is_array($recent_activity) ? $recent_activity : [];
        $truncate_dashboard_label = static function ($label, $width = 62) {
            $label = (string) $label;
            if (function_exists('mb_strimwidth')) {
                return mb_strimwidth($label, 0, (int) $width, '...');
            }

            return strlen($label) > (int) $width ? substr($label, 0, max(0, (int) $width - 3)) . '...' : $label;
        };
        ?>
        <div class="row">
            <div class="col-md-12">
                <div class="panel_s">
                    <div class="panel-body marketing-dashboard-shell" data-marketing-dashboard-analytics-layout="1">
                        <div class="marketing-dashboard-layout">
                        <div class="marketing-dashboard-hero" data-marketing-dashboard-hero-wide="1">
                            <h4 class="no-margin"><?= _l('marketing_dashboard'); ?></h4>
                            <p class="marketing-dashboard-hero-copy"><?= _l('marketing_dashboard_executive_overview_note'); ?></p>
                        </div>
                        <div class="marketing-dashboard-summary-grid">
                            <div class="marketing-dashboard-summary-card">
                                <span class="marketing-dashboard-summary-label"><?= _l('marketing_audiences'); ?></span>
                                <span class="marketing-dashboard-summary-value"><?= html_escape($total_lists); ?></span>
                                <span class="marketing-dashboard-summary-helper"><?= _l('marketing_dashboard_summary_audiences_helper'); ?></span>
                            </div>
                            <div class="marketing-dashboard-summary-card">
                                <span class="marketing-dashboard-summary-label"><?= _l('marketing_contacts'); ?></span>
                                <span class="marketing-dashboard-summary-value"><?= html_escape($total_contacts); ?></span>
                                <span class="marketing-dashboard-summary-helper"><?= _l('marketing_dashboard_summary_contacts_helper'); ?></span>
                            </div>
                            <div class="marketing-dashboard-summary-card">
                                <span class="marketing-dashboard-summary-label"><?= _l('marketing_total_memberships'); ?></span>
                                <span class="marketing-dashboard-summary-value"><?= html_escape($total_memberships); ?></span>
                                <span class="marketing-dashboard-summary-helper"><?= _l('marketing_dashboard_summary_memberships_helper'); ?></span>
                            </div>
                            <div class="marketing-dashboard-summary-card">
                                <span class="marketing-dashboard-summary-label"><?= _l('marketing_campaigns'); ?></span>
                                <span class="marketing-dashboard-summary-value"><?= html_escape($total_campaigns); ?></span>
                                <span class="marketing-dashboard-summary-helper"><?= _l('marketing_dashboard_summary_campaigns_helper'); ?></span>
                            </div>
                            <div class="marketing-dashboard-summary-card">
                                <span class="marketing-dashboard-summary-label"><?= _l('marketing_delivery_rate'); ?></span>
                                <span class="marketing-dashboard-summary-value"><?= html_escape(number_format($delivery_rate, 2)); ?>%</span>
                                <span class="marketing-dashboard-summary-helper"><?= _l('marketing_dashboard_summary_delivery_helper'); ?></span>
                            </div>
                            <div class="marketing-dashboard-summary-card">
                                <span class="marketing-dashboard-summary-label"><?= _l('marketing_open_rate'); ?></span>
                                <span class="marketing-dashboard-summary-value"><?= html_escape(number_format($open_rate, 2)); ?>%</span>
                                <span class="marketing-dashboard-summary-helper"><?= _l('marketing_dashboard_summary_open_helper'); ?></span>
                            </div>
                            <div class="marketing-dashboard-summary-card">
                                <span class="marketing-dashboard-summary-label"><?= _l('marketing_bounce_rate'); ?></span>
                                <span class="marketing-dashboard-summary-value"><?= html_escape(number_format($bounce_rate, 2)); ?>%</span>
                                <span class="marketing-dashboard-summary-helper"><?= _l('marketing_dashboard_summary_bounce_helper'); ?></span>
                            </div>
                            <div class="marketing-dashboard-summary-card">
                                <span class="marketing-dashboard-summary-label"><?= _l('marketing_active_suppressions'); ?></span>
                                <span class="marketing-dashboard-summary-value"><?= html_escape($active_suppressions); ?></span>
                                <span class="marketing-dashboard-summary-helper"><?= _l('marketing_dashboard_summary_suppressions_helper'); ?></span>
                            </div>
                        </div>
                        <div class="marketing-dashboard-section-card is-analytics">
                            <div class="marketing-dashboard-section-body">
                                <?php
                                $topClickRow = !empty($click_analytics['top_clicked_links'])
                                    ? $click_analytics['top_clicked_links'][0]
                                    : (!empty($click_analytics['clicks_by_campaign']) ? $click_analytics['clicks_by_campaign'][0] : null);
                                $topClickLabel = '-';
                                $topClickHelper = _l('marketing_click_analytics_empty');
                                if (is_array($topClickRow)) {
                                    $topClickLabel = !empty($click_analytics['top_clicked_links'])
                                        ? (string) ($topClickRow['url'] ?? '-')
                                        : (string) ($topClickRow['campaign_name'] ?? '-');
                                    $topClickHelper = !empty($click_analytics['top_clicked_links'])
                                        ? ((int) ($topClickRow['count'] ?? 0) . ' ' . strtolower(_l('marketing_human_clicks')))
                                        : ((int) ($topClickRow['count'] ?? 0) . ' ' . strtolower(_l('marketing_campaigns')));
                                }
                                ?>
                                <div class="marketing-dashboard-section-head">
                                    <div>
                                        <h4 class="marketing-dashboard-section-title"><?= _l('marketing_link_click_analytics'); ?></h4>
                                        <p class="marketing-dashboard-section-copy"><?= _l('marketing_dashboard_click_analytics_copy'); ?></p>
                                    </div>
                                    <a href="<?= admin_url('marketing/analytics'); ?>" class="btn btn-default btn-sm"><?= _l('marketing_view_full_analytics'); ?></a>
                                </div>
                                <div class="marketing-dashboard-click-strip">
                                    <div class="marketing-dashboard-click-chip">
                                        <span class="marketing-dashboard-click-chip-label"><?= _l('marketing_human_clicks'); ?></span>
                                        <span class="marketing-dashboard-click-chip-value" style="color:#ea580c;"><?= html_escape($click_analytics['human_clicks']); ?></span>
                                        <span class="marketing-dashboard-click-chip-copy"><?= _l('marketing_dashboard_click_human_helper'); ?></span>
                                    </div>
                                    <div class="marketing-dashboard-click-chip">
                                        <span class="marketing-dashboard-click-chip-label"><?= _l('marketing_unique_human_clicks'); ?></span>
                                        <span class="marketing-dashboard-click-chip-value" style="color:#2563eb;"><?= html_escape($click_analytics['unique_human_clicks']); ?></span>
                                        <span class="marketing-dashboard-click-chip-copy"><?= _l('marketing_dashboard_click_unique_helper'); ?></span>
                                    </div>
                                    <div class="marketing-dashboard-click-chip">
                                        <span class="marketing-dashboard-click-chip-label"><?= _l('marketing_dashboard_click_raw_label'); ?></span>
                                        <span class="marketing-dashboard-click-chip-value"><?= html_escape($click_analytics['raw_click_events']); ?></span>
                                        <span class="marketing-dashboard-click-chip-copy"><?= _l('marketing_dashboard_click_raw_helper_compact'); ?></span>
                                    </div>
                                    <div class="marketing-dashboard-click-chip is-highlight">
                                        <span class="marketing-dashboard-click-chip-label"><?= !empty($click_analytics['top_clicked_links']) ? _l('marketing_dashboard_click_top_link_label') : _l('marketing_dashboard_click_top_campaign_label'); ?></span>
                                        <span class="marketing-dashboard-click-chip-value" title="<?= html_escape($topClickLabel); ?>"><?= html_escape($truncate_dashboard_label($topClickLabel, 34)); ?></span>
                                        <span class="marketing-dashboard-click-chip-copy"><?= html_escape($topClickHelper); ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        </div>
                        <?php
                        $queueDiagnostics = isset($queue_diagnostics) && is_array($queue_diagnostics) ? $queue_diagnostics : [];
                        $queueDiagnostics = array_merge([
                            'queue_paused' => false,
                            'schema_ready' => false,
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
                        ], $queueDiagnostics);
                        $queueRateLimit = isset($queueDiagnostics['rate_limit_snapshot']) && is_array($queueDiagnostics['rate_limit_snapshot'])
                            ? $queueDiagnostics['rate_limit_snapshot']
                            : [];
                        $recentQueueWarnings = isset($queueDiagnostics['recent_warning_counts']) && is_array($queueDiagnostics['recent_warning_counts'])
                            ? $queueDiagnostics['recent_warning_counts']
                            : [];
                        $cronReadiness = isset($cron_readiness) && is_array($cron_readiness)
                            ? array_merge([
                                'cron_status' => 'not_detected',
                                'last_run_at' => null,
                                'last_import_run_at' => null,
                                'last_campaign_run_at' => null,
                                'minutes_since_last_run' => null,
                                'warning_threshold_minutes' => 5,
                                'critical_threshold_minutes' => 15,
                                'recommended_command' => '* * * * * cd . && php index.php cron/index >/dev/null 2>&1',
                                'import_queue' => [],
                            ], $cron_readiness)
                            : [
                                'cron_status' => 'not_detected',
                                'last_run_at' => null,
                                'last_import_run_at' => null,
                                'last_campaign_run_at' => null,
                                'minutes_since_last_run' => null,
                                'warning_threshold_minutes' => 5,
                                'critical_threshold_minutes' => 15,
                                'recommended_command' => '* * * * * cd . && php index.php cron/index >/dev/null 2>&1',
                                'import_queue' => [],
                            ];
                        $cronImportQueue = isset($cronReadiness['import_queue']) && is_array($cronReadiness['import_queue'])
                            ? array_merge([
                                'queued_count' => 0,
                                'processing_count' => 0,
                                'failed_count' => 0,
                                'completed_count' => 0,
                                'oldest_queued_at' => null,
                                'oldest_queued_age_minutes' => null,
                                'stale_processing_count' => 0,
                            ], $cronReadiness['import_queue'])
                            : [
                                'queued_count' => 0,
                                'processing_count' => 0,
                                'failed_count' => 0,
                                'completed_count' => 0,
                                'oldest_queued_at' => null,
                                'oldest_queued_age_minutes' => null,
                                'stale_processing_count' => 0,
                            ];
                        $cronStatusLabel = static function ($statusKey) {
                            if ($statusKey === 'healthy') {
                                return _l('marketing_cron_status_healthy');
                            }
                            if ($statusKey === 'warning') {
                                return _l('marketing_cron_status_warning');
                            }
                            if ($statusKey === 'critical') {
                                return _l('marketing_cron_status_critical');
                            }

                            return _l('marketing_cron_status_not_detected');
                        };
                        $cronStatusChipClass = static function ($statusKey) {
                            $statusKey = trim((string) $statusKey);
                            if (!in_array($statusKey, ['healthy', 'warning', 'critical', 'not_detected'], true)) {
                                $statusKey = 'not_detected';
                            }

                            return 'marketing-ops-cron-chip-value marketing-ops-cron-chip-value--status marketing-ops-cron-chip-value--' . $statusKey;
                        };
                        $formatCronMinutes = static function ($minutes) {
                            if ($minutes === null || $minutes === '') {
                                return _l('marketing_cron_not_detected_yet');
                            }

                            $minutes = max(0, (int) $minutes);
                            if ($minutes < 60) {
                                return sprintf(_l('marketing_cron_age_minutes'), $minutes);
                            }

                            $hours = (int) floor($minutes / 60);
                            $remainingMinutes = $minutes % 60;

                            if ($remainingMinutes === 0) {
                                return sprintf(_l('marketing_cron_age_hours'), $hours);
                            }

                            return sprintf(_l('marketing_cron_age_hours_minutes'), $hours, $remainingMinutes);
                        };
                        $recentFailureMetrics = isset($queueDiagnostics['recent_failure_metric_counts']) && is_array($queueDiagnostics['recent_failure_metric_counts'])
                            ? $queueDiagnostics['recent_failure_metric_counts']
                            : [];
                        $recentOperatorActions = isset($queueDiagnostics['recent_operator_actions']) && is_array($queueDiagnostics['recent_operator_actions'])
                            ? $queueDiagnostics['recent_operator_actions']
                            : [];
                        $retentionDiagnostics = isset($retention_diagnostics) && is_array($retention_diagnostics)
                            ? array_merge([
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
                            ], $retention_diagnostics)
                            : [
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
                            ];
                        $retentionModeLabel = str_replace('_', ' ', (string) ($retentionDiagnostics['cleanup_mode'] ?? 'explicit_only'));
                        $integrityDiagnostics = isset($integrity_diagnostics) && is_array($integrity_diagnostics)
                            ? array_merge([
                                'generated_at' => null,
                                'available' => false,
                                'critical_count' => 0,
                                'warning_count' => 0,
                                'categories' => [],
                                'top_categories' => [],
                            ], $integrity_diagnostics)
                            : [
                                'generated_at' => null,
                                'available' => false,
                                'critical_count' => 0,
                                'warning_count' => 0,
                                'categories' => [],
                                'top_categories' => [],
                            ];
                        $integrityTopCategories = isset($integrityDiagnostics['top_categories']) && is_array($integrityDiagnostics['top_categories'])
                            ? $integrityDiagnostics['top_categories']
                            : [];
                        $whatsappConversations = isset($whatsapp_conversations) && is_array($whatsapp_conversations)
                            ? array_merge([
                                'available' => false,
                                'shown_count' => 0,
                                'unread_conversation_count' => 0,
                                'unread_message_count' => 0,
                                'items' => [],
                            ], $whatsapp_conversations)
                            : [
                                'available' => false,
                                'shown_count' => 0,
                                'unread_conversation_count' => 0,
                                'unread_message_count' => 0,
                                'items' => [],
                            ];
                        $whatsappConversationItems = isset($whatsappConversations['items']) && is_array($whatsappConversations['items'])
                            ? $whatsappConversations['items']
                            : [];
                        $canManageQueue = !empty($can_manage_queue);
                        ?>
                        <div class="panel_s marketing-ops-shell">
                            <div class="panel-body">
                                        <div class="marketing-dashboard-ops-head">
                                            <div>
                                                <h4 class="no-margin"><?= _l('marketing_dashboard_operations_center'); ?></h4>
                                                <p class="marketing-dashboard-ops-head-copy"><?= _l('marketing_dashboard_operations_center_note'); ?></p>
                                            </div>
                                            <div class="marketing-dashboard-ops-status-row">
                                                <span class="marketing-ops-status-pill"><?= !empty($queueDiagnostics['queue_paused']) ? 'Queue Paused' : 'Queue Active'; ?></span>
                                                <span class="marketing-ops-status-pill"><?= !empty($queueDiagnostics['schema_ready']) ? 'Schema Ready' : 'Schema Partial'; ?></span>
                                                <span class="marketing-ops-status-pill">Mode: <?= html_escape(ucwords($retentionModeLabel)); ?></span>
                                                <span class="marketing-ops-status-pill"><?= sprintf(_l('marketing_cron_status_pill_format'), html_escape($cronStatusLabel((string) $cronReadiness['cron_status']))); ?></span>
                                            </div>
                                        </div>
                                        <div class="marketing-dashboard-ops-grid">
                                        <div class="marketing-ops-section" data-marketing-cron-health-panel="1" data-marketing-cron-health-compact="1">
                                            <h5 class="marketing-ops-section-title"><?= _l('marketing_cron_readiness_heading'); ?></h5>
                                            <p class="marketing-ops-section-copy"><?= _l('marketing_cron_dashboard_compact_note'); ?></p>
                                            <div class="marketing-ops-cron-strip">
                                                <div class="marketing-ops-cron-chip">
                                                    <span class="marketing-ops-cron-chip-label">Cron</span>
                                                    <span class="<?= html_escape($cronStatusChipClass((string) $cronReadiness['cron_status'])); ?>"><?= html_escape($cronStatusLabel((string) $cronReadiness['cron_status'])); ?></span>
                                                </div>
                                                <div class="marketing-ops-cron-chip">
                                                    <span class="marketing-ops-cron-chip-label"><?= _l('marketing_cron_compact_last_run'); ?></span>
                                                    <span class="marketing-ops-cron-chip-value"><?= html_escape($formatCronMinutes($cronReadiness['minutes_since_last_run'])); ?></span>
                                                </div>
                                                <div class="marketing-ops-cron-chip">
                                                    <span class="marketing-ops-cron-chip-label"><?= _l('marketing_import_status_queued'); ?></span>
                                                    <span class="marketing-ops-cron-chip-value"><?= (int) ($cronImportQueue['queued_count'] ?? 0); ?></span>
                                                </div>
                                                <div class="marketing-ops-cron-chip">
                                                    <span class="marketing-ops-cron-chip-label"><?= _l('marketing_import_status_processing'); ?></span>
                                                    <span class="marketing-ops-cron-chip-value"><?= (int) ($cronImportQueue['processing_count'] ?? 0); ?></span>
                                                </div>
                                                <div class="marketing-ops-cron-chip">
                                                    <span class="marketing-ops-cron-chip-label"><?= _l('marketing_import_status_failed'); ?></span>
                                                    <span class="marketing-ops-cron-chip-value"><?= (int) ($cronImportQueue['failed_count'] ?? 0); ?></span>
                                                </div>
                                                <div class="marketing-ops-cron-chip">
                                                    <span class="marketing-ops-cron-chip-label"><?= _l('marketing_import_status_completed'); ?></span>
                                                    <span class="marketing-ops-cron-chip-value"><?= (int) ($cronImportQueue['completed_count'] ?? 0); ?></span>
                                                </div>
                                                <div class="marketing-ops-cron-chip">
                                                    <span class="marketing-ops-cron-chip-label"><?= _l('marketing_cron_compact_stale'); ?></span>
                                                    <span class="marketing-ops-cron-chip-value"><?= (int) ($cronImportQueue['stale_processing_count'] ?? 0); ?></span>
                                                </div>
                                            </div>
                                            <details class="marketing-ops-cron-details" data-marketing-cron-details="1">
                                                <summary><?= _l('marketing_cron_diagnostics_summary'); ?></summary>
                                                <div class="marketing-ops-cron-details-body">
                                                    <p class="marketing-ops-cron-details-copy"><?= _l('marketing_cron_readiness_note'); ?></p>
                                                    <div class="marketing-ops-cron-details-grid">
                                                        <div class="marketing-ops-cron-details-item">
                                                            <span><?= _l('marketing_cron_last_run_at'); ?></span>
                                                            <strong><?= !empty($cronReadiness['last_run_at']) ? html_escape((string) $cronReadiness['last_run_at']) : _l('marketing_cron_not_detected_yet'); ?></strong>
                                                        </div>
                                                        <div class="marketing-ops-cron-details-item">
                                                            <span><?= _l('marketing_cron_oldest_queued_job_age'); ?></span>
                                                            <strong><?= html_escape($formatCronMinutes($cronImportQueue['oldest_queued_age_minutes'])); ?></strong>
                                                        </div>
                                                        <div class="marketing-ops-cron-details-item">
                                                            <span><?= _l('marketing_cron_last_import_run_at'); ?></span>
                                                            <strong><?= !empty($cronReadiness['last_import_run_at']) ? html_escape((string) $cronReadiness['last_import_run_at']) : _l('marketing_cron_not_detected_yet'); ?></strong>
                                                        </div>
                                                        <div class="marketing-ops-cron-details-item">
                                                            <span><?= _l('marketing_cron_last_campaign_run_at'); ?></span>
                                                            <strong><?= !empty($cronReadiness['last_campaign_run_at']) ? html_escape((string) $cronReadiness['last_campaign_run_at']) : _l('marketing_cron_not_detected_yet'); ?></strong>
                                                        </div>
                                                        <div class="marketing-ops-cron-details-item">
                                                            <span><?= _l('marketing_cron_warning_threshold'); ?></span>
                                                            <strong><?= sprintf(_l('marketing_cron_age_minutes'), (int) ($cronReadiness['warning_threshold_minutes'] ?? 5)); ?></strong>
                                                        </div>
                                                        <div class="marketing-ops-cron-details-item">
                                                            <span><?= _l('marketing_cron_critical_threshold'); ?></span>
                                                            <strong><?= sprintf(_l('marketing_cron_age_minutes'), (int) ($cronReadiness['critical_threshold_minutes'] ?? 15)); ?></strong>
                                                        </div>
                                                    </div>
                                                    <pre class="marketing-ops-command-block marketing-ops-command-block--compact" data-marketing-cron-command="1"><code><?= html_escape((string) ($cronReadiness['recommended_command'] ?? '')); ?></code></pre>
                                                </div>
                                            </details>
                                        </div>
                                        <div class="marketing-ops-section" data-marketing-dashboard-integrity-panel="1">
                                            <h5 class="marketing-ops-section-title">Integrity Health</h5>
                                            <p class="marketing-ops-section-copy"><?= _l('marketing_dashboard_integrity_health_copy'); ?></p>
                                            <?php if (empty($integrityDiagnostics['available'])) { ?>
                                                <div class="marketing-ops-empty"><?= _l('marketing_dashboard_integrity_health_unavailable'); ?></div>
                                            <?php } else { ?>
                                                <div class="marketing-ops-integrity-grid">
                                                    <div class="marketing-ops-metric">
                                                        <span class="marketing-ops-metric-label">Critical Anomalies</span>
                                                        <span class="marketing-ops-metric-value"><?= (int) ($integrityDiagnostics['critical_count'] ?? 0); ?></span>
                                                        <span class="marketing-ops-metric-helper">Immediate integrity issues needing operator review.</span>
                                                    </div>
                                                    <div class="marketing-ops-metric">
                                                        <span class="marketing-ops-metric-label">Warnings</span>
                                                        <span class="marketing-ops-metric-value"><?= (int) ($integrityDiagnostics['warning_count'] ?? 0); ?></span>
                                                        <span class="marketing-ops-metric-helper">Non-blocking drift or stale-state signals.</span>
                                                    </div>
                                                    <div class="marketing-ops-metric">
                                                        <span class="marketing-ops-metric-label">Generated At</span>
                                                        <span class="marketing-ops-metric-value" style="font-size:18px;"><?= !empty($integrityDiagnostics['generated_at']) ? html_escape((string) $integrityDiagnostics['generated_at']) : 'n/a'; ?></span>
                                                        <span class="marketing-ops-metric-helper">Last dashboard integrity snapshot time.</span>
                                                    </div>
                                                </div>
                                                <details class="marketing-ops-advanced">
                                                    <summary><?= _l('marketing_dashboard_integrity_advanced_title'); ?></summary>
                                                    <p class="marketing-ops-advanced-copy"><?= _l('marketing_dashboard_integrity_advanced_copy'); ?></p>
                                                    <ul class="marketing-ops-command-list">
                                                        <li><code><?= html_escape('php modules/marketing/scripts/verify_phase4_integrity_runtime.php'); ?></code></li>
                                                        <li><code><?= html_escape('php modules/marketing/scripts/repair_phase4_integrity_runtime.php'); ?></code></li>
                                                        <li><code><?= html_escape('php modules/marketing/scripts/repair_phase4_integrity_runtime.php --apply'); ?></code></li>
                                                    </ul>
                                                </details>
                                                <?php if (empty($integrityTopCategories)) { ?>
                                                    <div class="marketing-ops-empty"><?= _l('marketing_dashboard_integrity_health_empty'); ?></div>
                                                <?php } else { ?>
                                                    <div class="marketing-ops-badge-grid">
                                                        <?php foreach (array_slice($integrityTopCategories, 0, 5) as $integrityCategory) { ?>
                                                            <span class="marketing-ops-badge marketing-ops-integrity-badge <?= html_escape((string) ($integrityCategory['severity'] ?? 'warning')); ?>">
                                                                <?= html_escape((string) ($integrityCategory['title'] ?? 'Integrity')); ?>: <?= (int) ($integrityCategory['count'] ?? 0); ?>
                                                            </span>
                                                        <?php } ?>
                                                    </div>
                                                <?php } ?>
                                            <?php } ?>
                                        </div>
                                        <div class="marketing-ops-section" data-marketing-dashboard-whatsapp-panel="1">
                                            <h5 class="marketing-ops-section-title"><?= _l('marketing_dashboard_whatsapp_conversations'); ?></h5>
                                            <p class="marketing-ops-section-copy"><?= _l('marketing_dashboard_whatsapp_conversations_note'); ?></p>
                                            <?php if (empty($whatsappConversations['available'])) { ?>
                                                <div class="marketing-ops-empty"><?= _l('marketing_dashboard_whatsapp_conversations_unavailable'); ?></div>
                                            <?php } else { ?>
                                                <div class="marketing-dashboard-whatsapp-summary">
                                                    <div class="marketing-ops-metric">
                                                        <span class="marketing-ops-metric-label"><?= _l('marketing_dashboard_whatsapp_conversations_shown'); ?></span>
                                                        <span class="marketing-ops-metric-value"><?= (int) ($whatsappConversations['shown_count'] ?? 0); ?></span>
                                                        <span class="marketing-ops-metric-helper"><?= _l('marketing_dashboard_whatsapp_shown_helper'); ?></span>
                                                    </div>
                                                    <div class="marketing-ops-metric">
                                                        <span class="marketing-ops-metric-label"><?= _l('marketing_dashboard_whatsapp_conversations_unread'); ?></span>
                                                        <span class="marketing-ops-metric-value"><?= (int) ($whatsappConversations['unread_conversation_count'] ?? 0); ?></span>
                                                        <span class="marketing-ops-metric-helper"><?= _l('marketing_dashboard_whatsapp_unread_conversations_helper'); ?></span>
                                                    </div>
                                                    <div class="marketing-ops-metric">
                                                        <span class="marketing-ops-metric-label"><?= _l('marketing_dashboard_whatsapp_messages_unread'); ?></span>
                                                        <span class="marketing-ops-metric-value"><?= (int) ($whatsappConversations['unread_message_count'] ?? 0); ?></span>
                                                        <span class="marketing-ops-metric-helper"><?= _l('marketing_dashboard_whatsapp_unread_messages_helper'); ?></span>
                                                    </div>
                                                </div>
                                                <?php if (empty($whatsappConversationItems)) { ?>
                                                    <div class="marketing-ops-empty"><?= _l('marketing_dashboard_whatsapp_conversations_empty'); ?></div>
                                                <?php } else { ?>
                                                    <div class="marketing-ops-table-shell mtop15">
                                                        <div class="table-responsive">
                                                            <table class="table table-striped">
                                                                <thead>
                                                                    <tr>
                                                                        <th><?= _l('marketing_dashboard_whatsapp_conversation_id'); ?></th>
                                                                        <th><?= _l('marketing_dashboard_whatsapp_contact'); ?></th>
                                                                        <th><?= _l('marketing_dashboard_whatsapp_unread'); ?></th>
                                                                        <th><?= _l('marketing_dashboard_whatsapp_lead'); ?></th>
                                                                        <th><?= _l('marketing_dashboard_whatsapp_preview'); ?></th>
                                                                        <th><?= _l('marketing_dashboard_whatsapp_last_activity'); ?></th>
                                                                    </tr>
                                                                </thead>
                                                                <tbody>
                                                                    <?php foreach (array_slice($whatsappConversationItems, 0, 5) as $conversation) { ?>
                                                                        <?php
                                                                        $contactName = trim((string) ($conversation['contact_name'] ?? ''));
                                                                        $contactPhone = trim((string) ($conversation['contact_phone'] ?? ($conversation['normalized_phone'] ?? '')));
                                                                        $contactLabel = $contactName !== '' ? $contactName : ($contactPhone !== '' ? $contactPhone : _l('marketing_dashboard_whatsapp_contact_unknown'));
                                                                        $preview = trim((string) ($conversation['preview'] ?? ''));
                                                                        $preview = $preview !== '' ? mb_substr($preview, 0, 120) : _l('marketing_dashboard_whatsapp_preview_empty');
                                                                        $leadConversion = isset($conversation['lead_conversion']) && is_array($conversation['lead_conversion']) ? $conversation['lead_conversion'] : [];
                                                                        $conversionLabel = trim((string) ($leadConversion['label'] ?? _l('marketing_dashboard_whatsapp_convert_unavailable')));
                                                                        $conversationUrl = admin_url('marketing/whatsapp_conversation/' . (int) ($conversation['id'] ?? 0));
                                                                        ?>
                                                                        <tr>
                                                                            <td><a href="<?= html_escape($conversationUrl); ?>">#<?= (int) ($conversation['id'] ?? 0); ?></a></td>
                                                                            <td>
                                                                                <strong><a href="<?= html_escape($conversationUrl); ?>"><?= html_escape($contactLabel); ?></a></strong>
                                                                                <?php if ($contactName !== '' && $contactPhone !== '') { ?>
                                                                                    <br><small><?= html_escape($contactPhone); ?></small>
                                                                                <?php } ?>
                                                                            </td>
                                                                            <td>
                                                                                <?php if ((int) ($conversation['unread_count'] ?? 0) > 0) { ?>
                                                                                    <span class="marketing-ops-badge"><?= (int) ($conversation['unread_count'] ?? 0); ?></span>
                                                                                    <div class="mtop5">
                                                                                        <?= form_open(admin_url('marketing/mark_whatsapp_conversation_read'), ['class' => 'inline-block']); ?>
                                                                                            <input type="hidden" name="conversation_id" value="<?= (int) ($conversation['id'] ?? 0); ?>">
                                                                                            <button type="submit" class="btn btn-default btn-sm"><?= _l('marketing_dashboard_whatsapp_mark_read_action'); ?></button>
                                                                                        <?= form_close(); ?>
                                                                                    </div>
                                                                                <?php } else { ?>
                                                                                    <span class="text-muted"><?= _l('marketing_dashboard_whatsapp_mark_read_none'); ?></span>
                                                                                <?php } ?>
                                                                            </td>
                                                                            <td>
                                                                                <?php if (!empty($conversation['lead_id'])) { ?>
                                                                                    <span class="marketing-ops-badge"><?= _l('marketing_dashboard_whatsapp_lead_linked'); ?> #<?= (int) $conversation['lead_id']; ?></span>
                                                                                <?php } elseif (!empty($leadConversion['actionable'])) { ?>
                                                                                    <?= form_open(admin_url('marketing/convert_whatsapp_conversation'), ['class' => 'inline-block']); ?>
                                                                                        <input type="hidden" name="conversation_id" value="<?= (int) ($conversation['id'] ?? 0); ?>">
                                                                                        <button type="submit" class="btn btn-default btn-sm"><?= html_escape($conversionLabel); ?></button>
                                                                                    <?= form_close(); ?>
                                                                                <?php } else { ?>
                                                                                    <span class="text-muted"><?= html_escape($conversionLabel); ?></span>
                                                                                <?php } ?>
                                                                            </td>
                                                                            <td><a href="<?= html_escape($conversationUrl); ?>"><?= html_escape($preview); ?></a></td>
                                                                            <td><a href="<?= html_escape($conversationUrl); ?>"><?= !empty($conversation['last_activity_at']) ? html_escape((string) $conversation['last_activity_at']) : '-'; ?></a></td>
                                                                        </tr>
                                                                    <?php } ?>
                                                                </tbody>
                                                            </table>
                                                        </div>
                                                    </div>
                                                <?php } ?>
                                            <?php } ?>
                                        </div>
                                        <div class="marketing-ops-section" data-marketing-dashboard-queue-controls="1">
                                            <h5 class="marketing-ops-section-title">Queue Controls</h5>
                                            <p class="marketing-ops-section-copy">Pause, resume, or recover stale queue work without modifying active recipient ownership or deleting telemetry.</p>
                                            <div class="marketing-ops-control-shell">
                                                <div class="marketing-ops-control-grid">
                                                    <div>
                                                        <?php if ($canManageQueue) { ?>
                                                            <div class="marketing-ops-button-row">
                                                                <?= form_open(admin_url('marketing/queue_control'), ['class' => 'inline-block']); ?>
                                                                    <input type="hidden" name="queue_action" value="<?= !empty($queueDiagnostics['queue_paused']) ? 'resume' : 'pause'; ?>">
                                                                    <button type="submit" class="btn btn-<?= !empty($queueDiagnostics['queue_paused']) ? 'success' : 'warning'; ?>">
                                                                        <?= !empty($queueDiagnostics['queue_paused']) ? 'Resume Queue' : 'Pause Queue'; ?>
                                                                    </button>
                                                                <?= form_close(); ?>
                                                                <?= form_open(admin_url('marketing/queue_control'), ['class' => 'inline-block']); ?>
                                                                    <input type="hidden" name="queue_action" value="recover_stale_worker_leases">
                                                                    <button type="submit" class="btn btn-default">Recover Stale Worker Leases</button>
                                                                <?= form_close(); ?>
                                                                <?= form_open(admin_url('marketing/queue_control'), ['class' => 'inline-block']); ?>
                                                                    <input type="hidden" name="queue_action" value="recover_stale_processing">
                                                                    <button type="submit" class="btn btn-default">Recover Stale Processing</button>
                                                                <?= form_close(); ?>
                                                            </div>
                                                        <?php } else { ?>
                                                            <div class="marketing-ops-empty">Queue controls are available to marketing settings administrators only.</div>
                                                        <?php } ?>
                                                    </div>
                                                    <div class="marketing-ops-list">
                                                        <div class="marketing-ops-list-row">
                                                            <span>Queue state</span>
                                                            <strong><?= !empty($queueDiagnostics['queue_paused']) ? 'Paused' : 'Active'; ?></strong>
                                                        </div>
                                                        <div class="marketing-ops-list-row">
                                                            <span>Recent recovered rows</span>
                                                            <strong><?= (int) ($queueDiagnostics['recent_recovered_total'] ?? 0); ?></strong>
                                                        </div>
                                                        <div class="marketing-ops-list-row">
                                                            <span>Hourly send capacity</span>
                                                            <strong><?= html_escape($queueRateLimit['hourly_remaining'] === null ? 'Unlimited' : (string) (int) $queueRateLimit['hourly_remaining']); ?></strong>
                                                        </div>
                                                        <div class="marketing-ops-list-row">
                                                            <span>Daily send capacity</span>
                                                            <strong><?= html_escape($queueRateLimit['daily_remaining'] === null ? 'Unlimited' : (string) (int) $queueRateLimit['daily_remaining']); ?></strong>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="marketing-ops-section" data-marketing-dashboard-queue-health="1">
                                            <h5 class="marketing-ops-section-title">Queue Health</h5>
                                            <p class="marketing-ops-section-copy">Live lease, backlog, recovery, and rate-cap telemetry from existing queue state.</p>
                                            <div class="marketing-ops-health-grid mtop15">
                                                <div class="marketing-ops-metric">
                                                    <span class="marketing-ops-metric-label">Worker Leases</span>
                                                    <span class="marketing-ops-metric-value"><?= (int) ($queueDiagnostics['campaign_worker_leases_active'] ?? 0); ?></span>
                                                    <span class="marketing-ops-metric-helper">Active campaign worker ownership markers.</span>
                                                </div>
                                                <div class="marketing-ops-metric">
                                                    <span class="marketing-ops-metric-label">Stale Worker Leases</span>
                                                    <span class="marketing-ops-metric-value"><?= (int) ($queueDiagnostics['campaign_worker_leases_stale'] ?? 0); ?></span>
                                                    <span class="marketing-ops-metric-helper">Eligible for guarded stale recovery only.</span>
                                                </div>
                                                <div class="marketing-ops-metric">
                                                    <span class="marketing-ops-metric-label">Processing Recipients</span>
                                                    <span class="marketing-ops-metric-value"><?= (int) ($queueDiagnostics['recipients_processing'] ?? 0); ?></span>
                                                    <span class="marketing-ops-metric-helper">Rows currently owned by active workers.</span>
                                                </div>
                                                <div class="marketing-ops-metric">
                                                    <span class="marketing-ops-metric-label">Stale Processing</span>
                                                    <span class="marketing-ops-metric-value"><?= (int) ($queueDiagnostics['recipients_processing_stale'] ?? 0); ?></span>
                                                    <span class="marketing-ops-metric-helper">Processing rows past the stale timeout.</span>
                                                </div>
                                                <div class="marketing-ops-metric">
                                                    <span class="marketing-ops-metric-label">Queued Recipients</span>
                                                    <span class="marketing-ops-metric-value"><?= (int) ($queueDiagnostics['recipients_queued'] ?? 0); ?></span>
                                                    <span class="marketing-ops-metric-helper">Sendable recipients waiting for queue execution.</span>
                                                </div>
                                                <div class="marketing-ops-metric">
                                                    <span class="marketing-ops-metric-label">Failed Recipients</span>
                                                    <span class="marketing-ops-metric-value"><?= (int) ($queueDiagnostics['recipients_failed'] ?? 0); ?></span>
                                                    <span class="marketing-ops-metric-helper">Recipient rows currently marked failed.</span>
                                                </div>
                                                <div class="marketing-ops-metric">
                                                    <span class="marketing-ops-metric-label">Hourly Remaining</span>
                                                    <span class="marketing-ops-metric-value"><?= html_escape($queueRateLimit['hourly_remaining'] === null ? '∞' : (string) (int) $queueRateLimit['hourly_remaining']); ?></span>
                                                    <span class="marketing-ops-metric-helper">Current hourly send capacity.</span>
                                                </div>
                                                <div class="marketing-ops-metric">
                                                    <span class="marketing-ops-metric-label">Daily Remaining</span>
                                                    <span class="marketing-ops-metric-value"><?= html_escape($queueRateLimit['daily_remaining'] === null ? '∞' : (string) (int) $queueRateLimit['daily_remaining']); ?></span>
                                                    <span class="marketing-ops-metric-helper">Current daily send capacity.</span>
                                                </div>
                                                <div class="marketing-ops-metric">
                                                    <span class="marketing-ops-metric-label">Recovered Rows</span>
                                                    <span class="marketing-ops-metric-value"><?= (int) ($queueDiagnostics['recent_recovered_total'] ?? 0); ?></span>
                                                    <span class="marketing-ops-metric-helper">Rows recovered in the recent batch telemetry window.</span>
                                                </div>
                                            </div>
                                            <?php if (!empty($queueDiagnostics['schema_missing']) && is_array($queueDiagnostics['schema_missing'])) { ?>
                                                <p class="text-muted mtop15 no-margin">
                                                    Missing queue schema elements:
                                                    <?= html_escape(implode(', ', array_slice($queueDiagnostics['schema_missing'], 0, 6))); ?>
                                                </p>
                                            <?php } ?>
                                        </div>
                                        <div class="marketing-ops-section marketing-ops-compact-grid" data-marketing-dashboard-queue-diagnostics="1">
                                            <div class="marketing-ops-compact-card">
                                                <h5 class="marketing-ops-section-title">Recent Warnings</h5>
                                                <p class="marketing-ops-section-copy">Recent queue-level warning payloads from bounded batch telemetry.</p>
                                                <?php if (empty($recentQueueWarnings)) { ?>
                                                    <div class="marketing-ops-empty">No warnings in the recent window.</div>
                                                <?php } else { ?>
                                                    <div class="marketing-ops-badge-grid">
                                                        <?php foreach ($recentQueueWarnings as $warningCode => $warningCount) { ?>
                                                            <span class="marketing-ops-badge">
                                                                <?= html_escape(ucwords(str_replace('_', ' ', (string) $warningCode))); ?>: <?= (int) $warningCount; ?>
                                                            </span>
                                                        <?php } ?>
                                                    </div>
                                                <?php } ?>
                                            </div>
                                            <div class="marketing-ops-compact-card">
                                                <h5 class="marketing-ops-section-title">Failure Metrics</h5>
                                                <p class="marketing-ops-section-copy">Recent normalized provider and recipient failure signals retained for operator review.</p>
                                                <?php if (empty($recentFailureMetrics)) { ?>
                                                    <div class="marketing-ops-empty">No failure signals in the recent diagnostics window.</div>
                                                <?php } else { ?>
                                                    <div class="marketing-ops-badge-grid">
                                                        <?php foreach ($recentFailureMetrics as $metricCode => $metricCount) { ?>
                                                            <span class="marketing-ops-badge">
                                                                <?= html_escape(ucwords(str_replace('_', ' ', (string) $metricCode))); ?>: <?= (int) $metricCount; ?>
                                                            </span>
                                                        <?php } ?>
                                                    </div>
                                                <?php } ?>
                                            </div>
                                            <div class="marketing-ops-compact-card">
                                                <h5 class="marketing-ops-section-title">Operator Audit</h5>
                                                <p class="marketing-ops-section-copy">Recent manual queue and recovery actions recorded for administrator visibility.</p>
                                                <?php if (empty($recentOperatorActions)) { ?>
                                                    <div class="marketing-ops-empty">No recent operator actions.</div>
                                                <?php } else { ?>
                                                    <div class="marketing-ops-table-shell">
                                                        <div class="table-responsive">
                                                            <table class="table table-striped">
                                                                <thead>
                                                                    <tr>
                                                                        <th>Action</th>
                                                                        <th>Staff ID</th>
                                                                        <th>When</th>
                                                                    </tr>
                                                                </thead>
                                                                <tbody>
                                                                    <?php foreach ($recentOperatorActions as $operatorAction) { ?>
                                                                        <tr>
                                                                            <td><?= html_escape(ucwords(str_replace('_', ' ', (string) ($operatorAction['action'] ?? '')))); ?></td>
                                                                            <td><?= (int) ($operatorAction['performed_by'] ?? 0); ?></td>
                                                                            <td><?= !empty($operatorAction['event_at']) ? html_escape((string) $operatorAction['event_at']) : '-'; ?></td>
                                                                        </tr>
                                                                    <?php } ?>
                                                                </tbody>
                                                            </table>
                                                        </div>
                                                    </div>
                                                <?php } ?>
                                            </div>
                                        </div>
                                        <div class="marketing-ops-section" data-marketing-dashboard-retention-panel="1">
                                            <h5 class="marketing-ops-section-title">Telemetry Retention</h5>
                                            <p class="marketing-ops-section-copy">Bounded explicit cleanup policy for non-critical telemetry and stale click aggregates.</p>
                                            <div class="marketing-ops-retention-grid mtop15">
                                                <div class="marketing-ops-metric">
                                                    <span class="marketing-ops-metric-label">Mode</span>
                                                    <span class="marketing-ops-metric-value"><?= html_escape(ucwords($retentionModeLabel)); ?></span>
                                                    <span class="marketing-ops-metric-helper">Automatic cron deletion remains disabled.</span>
                                                </div>
                                                <div class="marketing-ops-metric">
                                                    <span class="marketing-ops-metric-label">Events</span>
                                                    <span class="marketing-ops-metric-value"><?= (int) ($retentionDiagnostics['event_retention_days'] ?? 0); ?>d</span>
                                                    <span class="marketing-ops-metric-helper">Non-critical event retention window.</span>
                                                </div>
                                                <div class="marketing-ops-metric">
                                                    <span class="marketing-ops-metric-label">Webhooks</span>
                                                    <span class="marketing-ops-metric-value"><?= (int) ($retentionDiagnostics['webhook_log_retention_days'] ?? 0); ?>d</span>
                                                    <span class="marketing-ops-metric-helper">Webhook log retention window.</span>
                                                </div>
                                                <div class="marketing-ops-metric">
                                                    <span class="marketing-ops-metric-label">Floor</span>
                                                    <span class="marketing-ops-metric-value"><?= (int) ($retentionDiagnostics['retention_floor_days'] ?? 0); ?>d</span>
                                                    <span class="marketing-ops-metric-helper">Cleanup never runs below this threshold.</span>
                                                </div>
                                                <div class="marketing-ops-metric">
                                                    <span class="marketing-ops-metric-label">Per-Run Cap</span>
                                                    <span class="marketing-ops-metric-value"><?= (int) ($retentionDiagnostics['delete_limit_per_run'] ?? 0); ?></span>
                                                    <span class="marketing-ops-metric-helper">Maximum rows processed in one cleanup run.</span>
                                                </div>
                                                <div class="marketing-ops-metric">
                                                    <span class="marketing-ops-metric-label">Event Candidates</span>
                                                    <span class="marketing-ops-metric-value"><?= (int) ($retentionDiagnostics['events_cleanup_candidate_preview'] ?? 0); ?><?= !empty($retentionDiagnostics['events_cleanup_candidate_more']) ? '+' : ''; ?></span>
                                                    <span class="marketing-ops-metric-helper">Current bounded preview of event cleanup candidates.</span>
                                                </div>
                                                <div class="marketing-ops-metric">
                                                    <span class="marketing-ops-metric-label">Webhook Candidates</span>
                                                    <span class="marketing-ops-metric-value"><?= (int) ($retentionDiagnostics['webhook_logs_cleanup_candidate_preview'] ?? 0); ?><?= !empty($retentionDiagnostics['webhook_logs_cleanup_candidate_more']) ? '+' : ''; ?></span>
                                                    <span class="marketing-ops-metric-helper">Current bounded preview of webhook log cleanup candidates.</span>
                                                </div>
                                                <div class="marketing-ops-metric">
                                                    <span class="marketing-ops-metric-label">Click Aggregate Candidates</span>
                                                    <span class="marketing-ops-metric-value"><?= (int) ($retentionDiagnostics['click_aggregate_candidate_preview'] ?? 0); ?><?= !empty($retentionDiagnostics['click_aggregate_candidate_more']) ? '+' : ''; ?></span>
                                                    <span class="marketing-ops-metric-helper">Old click tracking counters eligible for safe reset.</span>
                                                </div>
                                            </div>
                                            <div class="marketing-ops-badge-grid">
                                                <span class="marketing-ops-badge">Mode: <?= html_escape(ucwords($retentionModeLabel)); ?></span>
                                                <span class="marketing-ops-badge">Events: <?= (int) ($retentionDiagnostics['event_retention_days'] ?? 0); ?> days</span>
                                                <span class="marketing-ops-badge">Webhooks: <?= (int) ($retentionDiagnostics['webhook_log_retention_days'] ?? 0); ?> days</span>
                                                <span class="marketing-ops-badge">Floor: <?= (int) ($retentionDiagnostics['retention_floor_days'] ?? 0); ?> days</span>
                                                <span class="marketing-ops-badge">Per-run cap: <?= (int) ($retentionDiagnostics['delete_limit_per_run'] ?? 0); ?> rows</span>
                                            </div>
                                        </div>
                            </div>
                        </div>
                                        </div>
                            </div>
                        </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php init_tail(); ?>
</body>
</html>
