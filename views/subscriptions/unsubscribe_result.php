<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= html_escape($title); ?></title>
    <style>
        :root {
            color-scheme: light;
        }
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background:
                radial-gradient(circle at top left, rgba(191, 219, 254, 0.8), transparent 32%),
                linear-gradient(180deg, #f8fbff 0%, #eef3f8 100%);
            color: #172033;
        }
        .marketing-unsubscribe-page {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 28px 18px;
        }
        .marketing-unsubscribe-card {
            width: 100%;
            max-width: 620px;
            background: rgba(255, 255, 255, 0.96);
            border: 1px solid rgba(148, 163, 184, 0.18);
            border-radius: 24px;
            box-shadow: 0 24px 80px rgba(15, 23, 42, 0.14);
            padding: 34px 30px 28px;
        }
        .marketing-unsubscribe-eyebrow {
            margin: 0 0 10px;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            color: #64748b;
        }
        .marketing-unsubscribe-card h1 {
            margin: 0;
            font-size: 31px;
            line-height: 1.15;
            color: #0f172a;
        }
        .marketing-unsubscribe-copy {
            margin: 14px 0 0;
            font-size: 16px;
            line-height: 1.6;
            color: #475569;
        }
        .marketing-unsubscribe-callout {
            margin-top: 20px;
            padding: 16px 18px;
            border-radius: 16px;
            background: #fff7ed;
            border: 1px solid #fed7aa;
            color: #9a3412;
            font-size: 14px;
            line-height: 1.6;
        }
        .marketing-unsubscribe-status {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 18px;
            padding: 9px 15px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 600;
            letter-spacing: 0.01em;
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
        .marketing-unsubscribe-details {
            margin: 24px 0 0;
            padding: 18px;
            border-radius: 18px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
        }
        .marketing-unsubscribe-details-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }
        .marketing-unsubscribe-detail {
            min-width: 0;
        }
        .marketing-unsubscribe-detail strong {
            display: block;
            margin-bottom: 6px;
            font-size: 12px;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: #64748b;
        }
        .marketing-unsubscribe-detail span {
            display: block;
            font-size: 15px;
            word-break: break-word;
            color: #0f172a;
        }
        .marketing-unsubscribe-form {
            margin-top: 24px;
        }
        .marketing-unsubscribe-checkbox-row {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 18px;
            padding: 16px 18px;
            border-radius: 16px;
            background: #fff;
            border: 1px solid #e2e8f0;
            font-size: 15px;
            line-height: 1.5;
            color: #334155;
        }
        .marketing-unsubscribe-checkbox-row input {
            width: 18px;
            height: 18px;
            margin-top: 2px;
            accent-color: #b91c1c;
        }
        .marketing-unsubscribe-button {
            display: inline-block;
            width: 100%;
            padding: 14px 18px;
            border: 0;
            border-radius: 14px;
            background: linear-gradient(180deg, #dc2626 0%, #b91c1c 100%);
            color: #ffffff;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            box-shadow: 0 14px 34px rgba(185, 28, 28, 0.24);
        }
        .marketing-unsubscribe-note {
            margin-top: 14px;
            font-size: 13px;
            color: #64748b;
            line-height: 1.6;
        }
        .marketing-unsubscribe-footer {
            margin-top: 20px;
            font-size: 12px;
            color: #94a3b8;
        }
        @media (max-width: 640px) {
            .marketing-unsubscribe-card {
                padding: 24px 20px 22px;
                border-radius: 20px;
            }
            .marketing-unsubscribe-card h1 {
                font-size: 26px;
            }
            .marketing-unsubscribe-details-grid {
                grid-template-columns: 1fr;
            }
            .marketing-unsubscribe-page {
                padding: 18px 12px;
            }
        }
    </style>
</head>
<body>
    <div class="marketing-unsubscribe-page">
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
            <p class="marketing-unsubscribe-eyebrow">Marketing Email Preferences</p>
            <h1><?= html_escape($title); ?></h1>
            <p class="marketing-unsubscribe-copy"><?= html_escape($message); ?></p>
            <?php if (($mode ?? '') === 'confirm'): ?>
                <div class="marketing-unsubscribe-callout">
                    Opening this link does not unsubscribe you automatically. Your email preferences will only change after you confirm below.
                </div>
                <?php if (!empty($email) || !empty($campaign_name)): ?>
                    <div class="marketing-unsubscribe-details">
                        <div class="marketing-unsubscribe-details-grid">
                            <?php if (!empty($email)): ?>
                                <div class="marketing-unsubscribe-detail">
                                    <strong><?= html_escape(_l('marketing_unsubscribe_email_label')); ?></strong>
                                    <span><?= html_escape($email); ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($campaign_name)): ?>
                                <div class="marketing-unsubscribe-detail">
                                    <strong><?= html_escape(_l('marketing_unsubscribe_campaign_label')); ?></strong>
                                    <span><?= html_escape($campaign_name); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
                <form method="post" action="<?= html_escape($unsubscribe_url); ?>" class="marketing-unsubscribe-form">
                    <label class="marketing-unsubscribe-checkbox-row">
                        <input type="checkbox" name="unsubscribe_confirmation" value="confirm" required>
                        <span><?= html_escape(_l('marketing_unsubscribe_confirm_checkbox')); ?></span>
                    </label>
                    <button type="submit" class="marketing-unsubscribe-button"><?= html_escape(_l('marketing_unsubscribe_confirm_button')); ?></button>
                    <p class="marketing-unsubscribe-note"><?= html_escape(_l('marketing_unsubscribe_confirm_note')); ?></p>
                </form>
            <?php endif; ?>
            <div class="marketing-unsubscribe-footer">This page is a secure public confirmation page for your email preferences.</div>
        </div>
    </div>
</body>
</html>
