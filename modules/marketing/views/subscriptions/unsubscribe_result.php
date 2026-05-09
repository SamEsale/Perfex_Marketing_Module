<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= html_escape($title); ?></title>
    <style>
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: #f5f7fb;
            color: #1f2937;
        }
        .marketing-unsubscribe-shell {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }
        .marketing-unsubscribe-card {
            width: 100%;
            max-width: 520px;
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 18px 60px rgba(15, 23, 42, 0.12);
            padding: 32px;
            text-align: center;
        }
        .marketing-unsubscribe-card h1 {
            margin: 0 0 12px;
            font-size: 28px;
        }
        .marketing-unsubscribe-card p {
            margin: 0;
            font-size: 16px;
            line-height: 1.6;
        }
        .marketing-unsubscribe-status {
            display: inline-block;
            margin-bottom: 18px;
            padding: 8px 14px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 600;
        }
        .marketing-unsubscribe-status.success {
            background: #dcfce7;
            color: #166534;
        }
        .marketing-unsubscribe-status.error {
            background: #fee2e2;
            color: #991b1b;
        }
    </style>
</head>
<body>
    <div class="marketing-unsubscribe-shell">
        <div class="marketing-unsubscribe-card">
            <span class="marketing-unsubscribe-status <?= $success ? 'success' : 'error'; ?>">
                <?= html_escape($success ? _l('marketing_unsubscribe_status_success') : _l('marketing_unsubscribe_status_error')); ?>
            </span>
            <h1><?= html_escape($title); ?></h1>
            <p><?= html_escape($message); ?></p>
        </div>
    </div>
</body>
</html>
