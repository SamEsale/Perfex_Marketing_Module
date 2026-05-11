<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php
$title = isset($title) && is_string($title) && $title !== '' ? $title : 'Marketing Analytics';
$analytics_warning_message = isset($analytics_warning_message) && is_string($analytics_warning_message)
    ? trim($analytics_warning_message)
    : 'Analytics is temporarily unavailable. A safe fallback view is being shown while runtime issues are contained.';
$events_pagination = isset($events_pagination) && is_array($events_pagination)
    ? array_merge(['page' => 1, 'per_page' => 20, 'total' => 0, 'total_pages' => 1], $events_pagination)
    : ['page' => 1, 'per_page' => 20, 'total' => 0, 'total_pages' => 1];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= html_escape($title); ?></title>
    <style>
        body {
            margin: 0;
            padding: 32px 20px;
            background: #f8fafc;
            color: #0f172a;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }
        .marketing-analytics-fallback-shell {
            max-width: 920px;
            margin: 0 auto;
        }
        .marketing-analytics-fallback-panel {
            border-radius: 18px;
            border: 1px solid #e2e8f0;
            background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
            box-shadow: 0 16px 34px rgba(15, 23, 42, .08);
            overflow: hidden;
        }
        .marketing-analytics-fallback-header {
            padding: 24px 24px 18px;
            border-bottom: 1px solid #e2e8f0;
        }
        .marketing-analytics-fallback-header h1 {
            margin: 0;
            font-size: 28px;
            line-height: 1.2;
        }
        .marketing-analytics-fallback-subtitle {
            margin: 10px 0 0;
            color: #475569;
            line-height: 1.6;
        }
        .marketing-analytics-fallback-warning {
            margin-top: 16px;
            padding: 14px 16px;
            border-radius: 12px;
            border: 1px solid #fcd34d;
            background: #fffbeb;
            color: #92400e;
            font-size: 13px;
            line-height: 1.6;
        }
        .marketing-analytics-fallback-body {
            padding: 24px;
        }
        .marketing-analytics-fallback-copy {
            margin: 0 0 12px;
            color: #475569;
            line-height: 1.7;
        }
        .marketing-analytics-fallback-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
            margin-top: 20px;
        }
        .marketing-analytics-fallback-card {
            border-radius: 14px;
            border: 1px solid #e2e8f0;
            background: #ffffff;
            padding: 18px 16px;
        }
        .marketing-analytics-fallback-card-label {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: #64748b;
        }
        .marketing-analytics-fallback-card-value {
            margin-top: 10px;
            font-size: 30px;
            font-weight: 700;
            color: #0f172a;
        }
        .marketing-analytics-fallback-marker {
            margin-top: 18px;
            font-size: 13px;
            font-weight: 700;
            color: #1d4ed8;
            letter-spacing: .04em;
            text-transform: uppercase;
        }
        @media (max-width: 767px) {
            .marketing-analytics-fallback-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- marketing_analytics_render_path=fallback-throwable -->
    <div class="marketing-analytics-fallback-shell">
        <div class="marketing-analytics-fallback-panel">
            <div class="marketing-analytics-fallback-header">
                <h1>Marketing Analytics Safe Fallback</h1>
                <p class="marketing-analytics-fallback-subtitle"><?= html_escape($title); ?></p>
                <div class="marketing-analytics-fallback-warning">
                    <?= html_escape($analytics_warning_message); ?>
                </div>
            </div>
            <div class="marketing-analytics-fallback-body">
                <p class="marketing-analytics-fallback-copy">
                    Analytics data could not be rendered safely on this request, so the module returned a contained fallback page instead of a server error.
                </p>
                <p class="marketing-analytics-fallback-copy">
                    No secrets, SQL, tokens, payloads, or raw exception traces are shown here.
                </p>
                <div class="marketing-analytics-fallback-grid">
                    <div class="marketing-analytics-fallback-card">
                        <div class="marketing-analytics-fallback-card-label">Total Events</div>
                        <div class="marketing-analytics-fallback-card-value"><?= (int) $events_pagination['total']; ?></div>
                    </div>
                    <div class="marketing-analytics-fallback-card">
                        <div class="marketing-analytics-fallback-card-label">Page</div>
                        <div class="marketing-analytics-fallback-card-value"><?= (int) $events_pagination['page']; ?></div>
                    </div>
                    <div class="marketing-analytics-fallback-card">
                        <div class="marketing-analytics-fallback-card-label">Rows Per Page</div>
                        <div class="marketing-analytics-fallback-card-value"><?= (int) $events_pagination['per_page']; ?></div>
                    </div>
                </div>
                <div class="marketing-analytics-fallback-marker">Marketing Analytics Safe Fallback</div>
            </div>
        </div>
    </div>
</body>
</html>
