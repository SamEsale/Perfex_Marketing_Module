<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Analytics extends AdminController
{
    private const PRIMARY_RENDER_PATH = 'primary-direct';
    private const FALLBACK_RENDER_PATH = 'fallback-throwable';

    public function __construct()
    {
        parent::__construct();

        if (staff_cant('view', 'marketing') && staff_cant('view_own', 'marketing')) {
            access_denied('marketing');
        }

        $this->load->model('marketing/Marketing_events_model', 'marketing_events_model');
        $this->load->model('marketing/Marketing_dashboard_model', 'marketing_dashboard_model');
    }

    public function index()
    {
        $hasRuntimeWarning = false;
        $analyticsSummary = $this->get_default_analytics_summary();
        $recentEvents = [];
        $retentionDiagnostics = $this->get_default_retention_diagnostics();
        $topClickedLinks = [];

        if ($this->input->post('run_retention_cleanup')) {
            if (staff_cant('settings', 'marketing')) {
                access_denied('marketing');
            }

            try {
                if (!method_exists($this->marketing_events_model, 'run_retention_cleanup')) {
                    throw new RuntimeException('run_retention_cleanup unavailable');
                }

                $summary = $this->marketing_events_model->run_retention_cleanup();
                $eventsDeleted = (int) ($summary['events_deleted'] ?? 0);
                $webhookLogsDeleted = (int) ($summary['webhook_logs_deleted'] ?? 0);
                $clickLinksReset = (int) ($summary['click_links_reset'] ?? 0);
                $clickLinksSkipped = (int) ($summary['click_links_skipped'] ?? 0);
                $warnings = isset($summary['warnings']) && is_array($summary['warnings'])
                    ? array_values(array_filter(array_map('trim', $summary['warnings'])))
                    : [];

                if ($eventsDeleted > 0 || $webhookLogsDeleted > 0 || $clickLinksReset > 0) {
                    $message = 'Retention cleanup completed. Event rows cleaned: ' . $eventsDeleted
                        . '. Webhook logs cleaned: ' . $webhookLogsDeleted
                        . '. Click aggregate rows reset: ' . $clickLinksReset . '.';
                    if ($clickLinksSkipped > 0) {
                        $message .= ' Protected click aggregate rows skipped: ' . $clickLinksSkipped . '.';
                    }
                    set_alert(
                        'success',
                        $message
                    );
                } elseif (!empty($warnings)) {
                    set_alert(
                        'warning',
                        'Retention cleanup completed without deleting rows. Warnings: ' . implode(', ', $warnings) . '.'
                    );
                } else {
                    $message = 'Retention cleanup completed. No eligible telemetry rows or stale click aggregates required changes.';
                    if ($clickLinksSkipped > 0) {
                        $message .= ' Protected click aggregate rows skipped: ' . $clickLinksSkipped . '.';
                    }
                    set_alert('info', $message);
                }
            } catch (Throwable $exception) {
                $hasRuntimeWarning = true;
                $this->log_analytics_runtime_failure('Marketing retention cleanup failed from analytics: ', $exception);
                set_alert('danger', 'Retention cleanup failed. No telemetry rows or click aggregates were changed.');
            }

            redirect(admin_url('marketing/analytics'));
        }

        $page = max(1, (int) $this->input->get('page'));
        $perPage = (int) $this->input->get('per_page');
        if ($perPage <= 0) {
            $perPage = 20;
        }
        $perPage = min($perPage, 100);
        $offset = ($page - 1) * $perPage;
        $totalEvents = 0;
        try {
            $totalEvents = max(0, (int) $this->marketing_events_model->count_all_events());
        } catch (Throwable $exception) {
            $hasRuntimeWarning = true;
            $this->log_analytics_runtime_failure('Marketing analytics event count unavailable: ', $exception);
        }
        $totalPages = max(1, (int) ceil($totalEvents / $perPage));

        if ($page > $totalPages && $totalEvents > 0) {
            $page = $totalPages;
            $offset = ($page - 1) * $perPage;
        }

        try {
            $summary = $this->marketing_dashboard_model->get_analytics_summary();
            if (is_array($summary)) {
                $analyticsSummary = array_replace_recursive($analyticsSummary, $summary);
            }
        } catch (Throwable $exception) {
            $hasRuntimeWarning = true;
            $this->log_analytics_runtime_failure('Marketing analytics summary unavailable: ', $exception);
        }

        try {
            $rows = $this->marketing_events_model->get_events_paginated($perPage, $offset);
            if (is_array($rows)) {
                $recentEvents = $rows;
            }
        } catch (Throwable $exception) {
            $hasRuntimeWarning = true;
            $this->log_analytics_runtime_failure('Marketing analytics events unavailable: ', $exception);
        }

        try {
            if (method_exists($this->marketing_events_model, 'get_retention_diagnostics')) {
                $diagnostics = $this->marketing_events_model->get_retention_diagnostics();
                if (is_array($diagnostics)) {
                    $retentionDiagnostics = array_merge($retentionDiagnostics, $diagnostics);
                }
            }
        } catch (Throwable $exception) {
            $hasRuntimeWarning = true;
            $this->log_analytics_runtime_failure('Marketing analytics retention diagnostics unavailable: ', $exception);
        }

        try {
            if (method_exists($this->marketing_dashboard_model, 'get_top_clicked_links')) {
                $rows = $this->marketing_dashboard_model->get_top_clicked_links(5);
                if (is_array($rows)) {
                    $topClickedLinks = $rows;
                }
            }
        } catch (Throwable $exception) {
            $hasRuntimeWarning = true;
            $this->log_analytics_runtime_failure('Marketing analytics top clicked links unavailable: ', $exception);
        }

        $data = $this->get_default_analytics_view_data();
        $data['title']      = _l('marketing_analytics');
        $data['stats']      = $analyticsSummary;
        $data['recent']     = $recentEvents;
        $data['top_clicked_links'] = $topClickedLinks;
        $data['retention_diagnostics'] = $retentionDiagnostics;
        $data['can_run_retention_cleanup'] = staff_can('settings', 'marketing');
        $data['analytics_warning_message'] = $hasRuntimeWarning
            ? 'Some analytics data is temporarily unavailable. Showing a safe fallback state for any failed sections.'
            : '';
        $data['events_pagination'] = [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $totalEvents,
            'total_pages' => $totalPages,
        ];

        return $this->render_analytics_response($data);
    }

    protected function get_default_analytics_summary()
    {
        return [
            'campaigns' => [
                'total' => 0,
                'draft' => 0,
                'sending' => 0,
                'sent' => 0,
                'failed' => 0,
            ],
            'recipients' => [
                'total' => 0,
                'queued' => 0,
                'processing' => 0,
                'sent' => 0,
                'failed' => 0,
            ],
            'events' => [
                'total_records' => 0,
                'accepted_sent' => 0,
                'accepted_sent_events' => 0,
                'delivered' => 0,
                'open' => 0,
                'click' => 0,
                'human_clicks' => 0,
                'unique_human_clicks' => 0,
                'tracked_link_human_clicks' => 0,
                'tracked_link_unique_human_clicks' => 0,
                'raw_click_events' => 0,
                'bounce' => 0,
                'complaint' => 0,
                'failed_rejected' => 0,
                'send_readiness_failed' => 0,
                'unsubscribe' => 0,
                'delivery_delay' => 0,
            ],
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

    protected function get_default_analytics_view_data()
    {
        return [
            'title' => _l('marketing_analytics'),
            'stats' => $this->get_default_analytics_summary(),
            'recent' => [],
            'top_clicked_links' => [],
            'retention_diagnostics' => $this->get_default_retention_diagnostics(),
            'can_run_retention_cleanup' => false,
            'events_pagination' => [
                'page' => 1,
                'per_page' => 20,
                'total' => 0,
                'total_pages' => 1,
            ],
            'analytics_warning_message' => '',
            'analytics_render_failed' => false,
        ];
    }

    protected function render_analytics_response(array $data)
    {
        $data = array_replace_recursive($this->get_default_analytics_view_data(), $data);
        $baselineOutputLevel = ob_get_level();

        try {
            $this->set_analytics_render_path_header(self::PRIMARY_RENDER_PATH);
            $this->load->view('marketing/analytics/index', $data);

            return;
        } catch (Throwable $exception) {
            $this->log_analytics_runtime_failure('Marketing analytics primary render failed: ', $exception);

            return $this->render_fallback_response($data, $baselineOutputLevel, 'primary_render_throwable', $exception);
        }
    }

    protected function render_fallback_response(array $data, $baselineOutputLevel = null, $reasonCode = 'unknown_fallback_path', ?Throwable $primaryException = null)
    {
        $reasonCode = $this->sanitize_fallback_reason_code($reasonCode);

        if (is_int($baselineOutputLevel) || ctype_digit((string) $baselineOutputLevel)) {
            $this->clean_output_buffers_to_level((int) $baselineOutputLevel);
        }
        $this->clear_pending_output_body();
        $this->append_analytics_fallback_diagnostic_log($reasonCode, $primaryException);

        $fallbackData = array_replace_recursive($this->get_default_analytics_view_data(), [
            'title' => $data['title'] ?? _l('marketing_analytics'),
            'can_run_retention_cleanup' => !empty($data['can_run_retention_cleanup']),
            'analytics_warning_message' => 'Analytics is temporarily unavailable. A safe fallback view is being shown while runtime issues are contained.',
            'analytics_render_failed' => true,
        ]);

        try {
            $html = $this->load->view('marketing/analytics/fallback', $fallbackData, true);
            if (!is_string($html) || trim($html) === '') {
                throw new RuntimeException('analytics fallback view returned empty output');
            }

            return $this->send_html_response(
                $html,
                $this->get_fallback_response_headers($reasonCode)
            );
        } catch (Throwable $exception) {
            $this->log_analytics_runtime_failure('Marketing analytics emergency fallback render failed: ', $exception);
            $this->append_analytics_fallback_diagnostic_log('fallback_render_throwable', $exception);
        }

        if (is_int($baselineOutputLevel) || ctype_digit((string) $baselineOutputLevel)) {
            $this->clean_output_buffers_to_level((int) $baselineOutputLevel);
        }
        $this->clear_pending_output_body();
        $this->append_analytics_fallback_diagnostic_log('minimal_fallback');

        return $this->send_html_response(
            $this->build_minimal_fallback_html($fallbackData),
            $this->get_fallback_response_headers('minimal_fallback')
        );
    }

    protected function clean_output_buffers_to_level($baselineOutputLevel)
    {
        while (ob_get_level() > $baselineOutputLevel) {
            if (!@ob_end_clean()) {
                break;
            }
        }
    }

    protected function clear_pending_output_body()
    {
        if (!isset($this->output)) {
            return;
        }

        $this->output->set_output('');
    }

    protected function send_html_response($html, array $headers = [])
    {
        $this->clear_pending_output_body();

        $this->output
            ->set_status_header(200)
            ->set_content_type('text/html', 'utf-8');

        foreach ($headers as $header) {
            if (is_string($header) && $header !== '') {
                $this->output->set_header($header);
            }
        }

        $this->output->set_output((string) $html);

        return;
    }

    protected function set_analytics_render_path_header($renderPath)
    {
        if (!isset($this->output)) {
            return;
        }

        $this->output->set_header('X-Marketing-Analytics-Render-Path: ' . trim((string) $renderPath));
    }

    protected function get_fallback_response_headers($reasonCode)
    {
        $reasonCode = $this->sanitize_fallback_reason_code($reasonCode);

        return [
            'X-Marketing-Analytics-Render-Path: ' . self::FALLBACK_RENDER_PATH,
            'X-Marketing-Analytics-Fallback: ' . $reasonCode,
        ];
    }

    protected function sanitize_fallback_reason_code($reasonCode)
    {
        $reasonCode = strtolower(trim((string) $reasonCode));
        $reasonCode = preg_replace('/[^a-z0-9_-]+/', '_', $reasonCode);
        $reasonCode = trim((string) $reasonCode, '_');

        return $reasonCode !== '' ? $reasonCode : 'unknown_fallback_path';
    }

    protected function append_analytics_fallback_diagnostic_log($reasonCode, ?Throwable $exception = null)
    {
        if (!defined('APPPATH')) {
            return;
        }

        $reasonCode = $this->sanitize_fallback_reason_code($reasonCode);
        $logDirectory = rtrim(APPPATH, '/\\') . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR;
        $logFile = $logDirectory . 'marketing-analytics-diagnostic-' . date('Y-m-d') . '.log';
        $exceptionClass = $exception ? get_class($exception) : 'none';
        $message = $exception ? $this->sanitize_log_message($exception->getMessage()) : 'none';
        $message = function_exists('mb_substr')
            ? mb_substr($message, 0, 180)
            : substr($message, 0, 180);
        $location = 'none';

        if ($exception && $exception->getFile()) {
            $location = basename((string) $exception->getFile()) . ':' . (int) $exception->getLine();
        }

        $entry = sprintf(
            "[%s] reason=%s render_path=%s exception=%s message=%s location=%s\n",
            date('c'),
            $reasonCode,
            self::FALLBACK_RENDER_PATH,
            $exceptionClass,
            str_replace(["\r", "\n"], ' ', $message),
            $location
        );

        @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
    }

    protected function build_minimal_fallback_html(array $data)
    {
        $title = html_escape((string) ($data['title'] ?? 'Marketing Analytics'));
        $warning = html_escape((string) ($data['analytics_warning_message'] ?? 'Analytics is temporarily unavailable.'));

        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . $title . '</title>'
            . '<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f8fafc;color:#0f172a;margin:0;padding:32px;}'
            . '.marketing-fallback{max-width:760px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:24px;box-shadow:0 12px 30px rgba(15,23,42,.08);}'
            . '.marketing-fallback h1{margin:0 0 12px;font-size:24px;}.marketing-fallback p{margin:0 0 12px;line-height:1.6;color:#475569;}'
            . '.marketing-fallback .warning{margin-bottom:16px;padding:14px 16px;border-radius:12px;border:1px solid #fcd34d;background:#fffbeb;color:#92400e;}</style></head><body>'
            . '<div class="marketing-fallback"><h1>Marketing Analytics Safe Fallback</h1><div class="warning">' . $warning . '</div>'
            . '<p>Analytics data could not be rendered safely, so the module returned a contained fallback page instead of a server error.</p>'
            . '<p>No secrets, raw payloads, or diagnostic traces are shown here.</p>'
            . '<p class="marketing-fallback-marker">' . $title . '</p></div></body></html>';
    }

    protected function log_analytics_runtime_failure($prefix, Throwable $exception)
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
}
