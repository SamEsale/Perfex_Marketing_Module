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
        .marketing-unsubscribe-card p + p {
            margin-top: 10px;
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
        .marketing-unsubscribe-status.pending {
            background: #dbeafe;
            color: #1d4ed8;
        }
        .marketing-unsubscribe-meta {
            margin: 22px 0;
            padding: 16px;
            border-radius: 12px;
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            text-align: left;
        }
        .marketing-unsubscribe-meta strong {
            display: block;
            margin-bottom: 6px;
            font-size: 13px;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: #475569;
        }
        .marketing-unsubscribe-meta span {
            display: block;
            font-size: 15px;
            word-break: break-word;
        }
        .marketing-unsubscribe-form {
            margin-top: 22px;
            text-align: left;
        }
        .marketing-unsubscribe-checkbox {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            margin-bottom: 16px;
            font-size: 15px;
            line-height: 1.5;
            color: #334155;
        }
        .marketing-unsubscribe-checkbox input {
            margin-top: 4px;
        }
        .marketing-unsubscribe-button {
            display: inline-block;
            width: 100%;
            padding: 12px 18px;
            border: 0;
            border-radius: 10px;
            background: #b91c1c;
            color: #ffffff;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
        }
        .marketing-unsubscribe-note {
            margin-top: 14px;
            font-size: 13px;
            color: #64748b;
        }
    </style>
</head>
<body>
    <div class="marketing-unsubscribe-shell">
        <div class="marketing-unsubscribe-card">
            <?php
            $statusClass = $success ? 'success' : 'error';
            if (($mode ?? '') === 'confirm') {
                $statusClass = 'pending';
            }
            ?>
            <span class="marketing-unsubscribe-status <?= html_escape($statusClass); ?>">
                <?= html_escape($status_label ?? ($success ? _l('marketing_unsubscribe_status_success') : _l('marketing_unsubscribe_status_error'))); ?>
            </span>
            <h1><?= html_escape($title); ?></h1>
            <p><?= html_escape($message); ?></p>
            <?php if (($mode ?? '') === 'confirm'): ?>
                <?php if (!empty($email) || !empty($campaign_name)): ?>
                    <div class="marketing-unsubscribe-meta">
                        <?php if (!empty($email)): ?>
                            <strong><?= html_escape(_l('marketing_unsubscribe_email_label')); ?></strong>
                            <span><?= html_escape($email); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($campaign_name)): ?>
                            <strong><?= html_escape(_l('marketing_unsubscribe_campaign_label')); ?></strong>
                            <span><?= html_escape($campaign_name); ?></span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <form method="post" action="<?= html_escape($unsubscribe_url); ?>" class="marketing-unsubscribe-form">
                    <input type="hidden" name="<?= html_escape($csrf_name); ?>" value="<?= html_escape($csrf_hash); ?>">
                    <label class="marketing-unsubscribe-checkbox">
                        <input type="checkbox" name="unsubscribe_confirmation" value="confirm" required>
                        <span><?= html_escape(_l('marketing_unsubscribe_confirm_checkbox')); ?></span>
                    </label>
                    <button type="submit" class="marketing-unsubscribe-button"><?= html_escape(_l('marketing_unsubscribe_confirm_button')); ?></button>
                    <p class="marketing-unsubscribe-note"><?= html_escape(_l('marketing_unsubscribe_confirm_note')); ?></p>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
