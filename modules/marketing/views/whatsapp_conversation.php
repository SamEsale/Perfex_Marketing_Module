<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<?php
$conversation = isset($conversation) && is_array($conversation) ? $conversation : [];
$messages = isset($messages) && is_array($messages) ? $messages : [];
$returnTo = isset($return_to) ? trim((string) $return_to) : 'marketing';
$contactName = trim((string) ($conversation['contact_name'] ?? ''));
$contactPhone = trim((string) ($conversation['contact_phone'] ?? ($conversation['normalized_phone'] ?? '')));
$contactLabel = $contactName !== '' ? $contactName : ($contactPhone !== '' ? $contactPhone : _l('marketing_dashboard_whatsapp_contact_unknown'));
$leadConversion = isset($conversation['lead_conversion']) && is_array($conversation['lead_conversion']) ? $conversation['lead_conversion'] : [];
$conversionLabel = trim((string) ($leadConversion['label'] ?? _l('marketing_dashboard_whatsapp_convert_unavailable')));
$leadUrl = !empty($conversation['lead_url']) ? (string) $conversation['lead_url'] : '';
?>
<style>
    .marketing-whatsapp-shell {
        border: 1px solid #e2e8f0;
        border-radius: 18px;
        background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
        overflow: hidden;
    }
    .marketing-whatsapp-body {
        padding: 22px 24px;
    }
    .marketing-whatsapp-head {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 18px;
    }
    .marketing-whatsapp-copy {
        margin: 8px 0 0;
        color: #64748b;
        font-size: 14px;
        line-height: 1.6;
    }
    .marketing-whatsapp-badges {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 14px;
    }
    .marketing-whatsapp-badge {
        display: inline-flex;
        align-items: center;
        padding: 6px 10px;
        border-radius: 999px;
        background: #e2e8f0;
        color: #0f172a;
        font-size: 12px;
        font-weight: 600;
    }
    .marketing-whatsapp-grid {
        display: grid;
        grid-template-columns: minmax(0, 320px) minmax(0, 1fr);
        gap: 18px;
        margin-top: 20px;
    }
    .marketing-whatsapp-card {
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        background: #ffffff;
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.04);
        overflow: hidden;
    }
    .marketing-whatsapp-card-body {
        padding: 18px 20px;
    }
    .marketing-whatsapp-summary-list {
        display: grid;
        gap: 12px;
        margin-top: 14px;
    }
    .marketing-whatsapp-summary-row {
        display: flex;
        justify-content: space-between;
        gap: 16px;
        border-bottom: 1px solid #eef2f7;
        padding-bottom: 12px;
    }
    .marketing-whatsapp-summary-row:last-child {
        border-bottom: 0;
        padding-bottom: 0;
    }
    .marketing-whatsapp-summary-label {
        color: #64748b;
        font-size: 12px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }
    .marketing-whatsapp-summary-value {
        text-align: right;
        color: #0f172a;
        font-size: 13px;
        line-height: 1.6;
        word-break: break-word;
    }
    .marketing-whatsapp-action-row {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin-top: 18px;
    }
    .marketing-whatsapp-action-row form {
        margin: 0;
    }
    .marketing-whatsapp-timeline {
        display: grid;
        gap: 14px;
        margin-top: 14px;
    }
    .marketing-whatsapp-message {
        max-width: 78%;
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        background: #ffffff;
        padding: 14px 16px;
        box-shadow: 0 6px 20px rgba(15, 23, 42, 0.03);
    }
    .marketing-whatsapp-message.inbound {
        justify-self: start;
        background: #f8fafc;
    }
    .marketing-whatsapp-message.outbound {
        justify-self: end;
        background: #e8fff4;
        border-color: #b7e4c7;
    }
    .marketing-whatsapp-message-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 8px 12px;
        margin-bottom: 8px;
        color: #64748b;
        font-size: 12px;
    }
    .marketing-whatsapp-message-body {
        color: #0f172a;
        font-size: 14px;
        line-height: 1.7;
        word-break: break-word;
    }
    .marketing-whatsapp-message-foot {
        margin-top: 10px;
        color: #475569;
        font-size: 12px;
    }
    .marketing-whatsapp-empty {
        padding: 18px;
        border: 1px dashed #cbd5e1;
        border-radius: 14px;
        background: #f8fafc;
        color: #64748b;
    }
    details.marketing-whatsapp-details {
        margin-top: 8px;
    }
    details.marketing-whatsapp-details summary {
        cursor: pointer;
        color: #475569;
        font-size: 12px;
        font-weight: 600;
    }
    @media (max-width: 991px) {
        .marketing-whatsapp-grid {
            grid-template-columns: 1fr;
        }
    }
    @media (max-width: 767px) {
        .marketing-whatsapp-head {
            flex-direction: column;
            align-items: flex-start;
        }
        .marketing-whatsapp-message {
            max-width: 100%;
        }
        .marketing-whatsapp-summary-row {
            flex-direction: column;
        }
        .marketing-whatsapp-summary-value {
            text-align: left;
        }
    }
</style>
<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-12">
                <div class="panel_s marketing-whatsapp-shell">
                    <div class="panel-body marketing-whatsapp-body">
                        <div class="marketing-whatsapp-head">
                            <div>
                                <h4 class="no-margin"><?= _l('marketing_whatsapp_conversation_title'); ?></h4>
                                <p class="marketing-whatsapp-copy"><?= _l('marketing_whatsapp_conversation_note'); ?></p>
                                <div class="marketing-whatsapp-badges">
                                    <span class="marketing-whatsapp-badge"><?= _l('marketing_channel'); ?>: <?= html_escape((string) ($conversation['channel'] ?? 'whatsapp')); ?></span>
                                    <span class="marketing-whatsapp-badge"><?= _l('marketing_provider'); ?>: <?= html_escape((string) ($conversation['provider'] ?? 'whatsapp_cloud')); ?></span>
                                    <span class="marketing-whatsapp-badge"><?= _l('marketing_whatsapp_conversation_unread_messages'); ?>: <?= (int) ($conversation['unread_count'] ?? 0); ?></span>
                                    <?php if (!empty($conversation['lead_id'])) { ?>
                                        <span class="marketing-whatsapp-badge"><?= _l('marketing_whatsapp_conversation_linked_lead'); ?> #<?= (int) $conversation['lead_id']; ?></span>
                                    <?php } ?>
                                </div>
                            </div>
                            <div>
                                <a href="<?= admin_url('marketing'); ?>" class="btn btn-default"><?= _l('marketing_whatsapp_conversation_back'); ?></a>
                            </div>
                        </div>

                        <div class="marketing-whatsapp-grid">
                            <div class="marketing-whatsapp-card">
                                <div class="marketing-whatsapp-card-body">
                                    <h5 class="no-margin"><?= _l('marketing_whatsapp_conversation_summary'); ?></h5>
                                    <div class="marketing-whatsapp-summary-list">
                                        <div class="marketing-whatsapp-summary-row">
                                            <span class="marketing-whatsapp-summary-label"><?= _l('marketing_dashboard_whatsapp_contact'); ?></span>
                                            <span class="marketing-whatsapp-summary-value"><?= html_escape($contactLabel); ?></span>
                                        </div>
                                        <div class="marketing-whatsapp-summary-row">
                                            <span class="marketing-whatsapp-summary-label"><?= _l('marketing_whatsapp_conversation_contact_phone'); ?></span>
                                            <span class="marketing-whatsapp-summary-value"><?= $contactPhone !== '' ? html_escape($contactPhone) : '-'; ?></span>
                                        </div>
                                        <div class="marketing-whatsapp-summary-row">
                                            <span class="marketing-whatsapp-summary-label"><?= _l('marketing_whatsapp_conversation_last_activity'); ?></span>
                                            <span class="marketing-whatsapp-summary-value"><?= !empty($conversation['last_activity_at']) ? html_escape((string) $conversation['last_activity_at']) : '-'; ?></span>
                                        </div>
                                        <div class="marketing-whatsapp-summary-row">
                                            <span class="marketing-whatsapp-summary-label"><?= _l('marketing_whatsapp_conversation_linked_lead'); ?></span>
                                            <span class="marketing-whatsapp-summary-value">
                                                <?php if (!empty($conversation['lead_id']) && $leadUrl !== '') { ?>
                                                    <a href="<?= html_escape($leadUrl); ?>">#<?= (int) $conversation['lead_id']; ?></a>
                                                <?php } elseif (!empty($conversation['lead_id'])) { ?>
                                                    #<?= (int) $conversation['lead_id']; ?>
                                                <?php } else { ?>
                                                    <?= _l('marketing_dashboard_whatsapp_lead_unlinked'); ?>
                                                <?php } ?>
                                            </span>
                                        </div>
                                        <?php if (!empty($conversation['datecreated'])) { ?>
                                            <div class="marketing-whatsapp-summary-row">
                                                <span class="marketing-whatsapp-summary-label"><?= _l('marketing_date_added'); ?></span>
                                                <span class="marketing-whatsapp-summary-value"><?= html_escape((string) $conversation['datecreated']); ?></span>
                                            </div>
                                        <?php } ?>
                                        <?php if (!empty($conversation['dateupdated'])) { ?>
                                            <div class="marketing-whatsapp-summary-row">
                                                <span class="marketing-whatsapp-summary-label"><?= _l('marketing_whatsapp_conversation_updated_at'); ?></span>
                                                <span class="marketing-whatsapp-summary-value"><?= html_escape((string) $conversation['dateupdated']); ?></span>
                                            </div>
                                        <?php } ?>
                                    </div>

                                    <div class="marketing-whatsapp-action-row">
                                        <?php if ((int) ($conversation['unread_count'] ?? 0) > 0) { ?>
                                            <?= form_open(admin_url('marketing/mark_whatsapp_conversation_read'), ['class' => 'inline-block']); ?>
                                                <input type="hidden" name="conversation_id" value="<?= (int) ($conversation['id'] ?? 0); ?>">
                                                <input type="hidden" name="return_to" value="<?= html_escape($returnTo); ?>">
                                                <button type="submit" class="btn btn-default"><?= _l('marketing_dashboard_whatsapp_mark_read_action'); ?></button>
                                            <?= form_close(); ?>
                                        <?php } ?>
                                        <?php if (empty($conversation['lead_id']) && !empty($leadConversion['actionable'])) { ?>
                                            <?= form_open(admin_url('marketing/convert_whatsapp_conversation'), ['class' => 'inline-block']); ?>
                                                <input type="hidden" name="conversation_id" value="<?= (int) ($conversation['id'] ?? 0); ?>">
                                                <input type="hidden" name="return_to" value="<?= html_escape($returnTo); ?>">
                                                <button type="submit" class="btn btn-default"><?= html_escape($conversionLabel); ?></button>
                                            <?= form_close(); ?>
                                        <?php } elseif (empty($conversation['lead_id'])) { ?>
                                            <span class="text-muted"><?= html_escape($conversionLabel); ?></span>
                                        <?php } ?>
                                    </div>
                                </div>
                            </div>

                            <div class="marketing-whatsapp-card">
                                <div class="marketing-whatsapp-card-body">
                                    <h5 class="no-margin"><?= _l('marketing_whatsapp_conversation_timeline'); ?></h5>
                                    <p class="marketing-whatsapp-copy"><?= _l('marketing_whatsapp_conversation_timeline_note'); ?></p>

                                    <?php if (empty($messages)) { ?>
                                        <div class="marketing-whatsapp-empty mtop15"><?= _l('marketing_whatsapp_conversation_no_messages'); ?></div>
                                    <?php } else { ?>
                                        <div class="marketing-whatsapp-timeline">
                                            <?php foreach ($messages as $message) { ?>
                                                <?php
                                                $direction = trim((string) ($message['direction'] ?? 'inbound'));
                                                $directionClass = $direction === 'outbound' ? 'outbound' : 'inbound';
                                                $bodyText = trim((string) ($message['body_text'] ?? ''));
                                                $bodyText = $bodyText !== '' ? $bodyText : _l('marketing_whatsapp_conversation_message_empty');
                                                $messageType = trim((string) ($message['message_type'] ?? ''));
                                                $providerMessageId = trim((string) ($message['provider_message_id'] ?? ''));
                                                $mediaSummary = trim((string) ($message['media_summary'] ?? ''));
                                                ?>
                                                <div class="marketing-whatsapp-message <?= html_escape($directionClass); ?>">
                                                    <div class="marketing-whatsapp-message-meta">
                                                        <span><?= _l('marketing_whatsapp_conversation_direction'); ?>: <?= html_escape($direction !== '' ? ucfirst($direction) : 'Inbound'); ?></span>
                                                        <?php if ($messageType !== '') { ?>
                                                            <span><?= _l('marketing_whatsapp_conversation_message_type'); ?>: <?= html_escape($messageType); ?></span>
                                                        <?php } ?>
                                                        <?php if (!empty($message['datecreated'])) { ?>
                                                            <span><?= html_escape((string) $message['datecreated']); ?></span>
                                                        <?php } ?>
                                                    </div>
                                                    <div class="marketing-whatsapp-message-body"><?= nl2br(html_escape($bodyText)); ?></div>
                                                    <?php if ($mediaSummary !== '') { ?>
                                                        <div class="marketing-whatsapp-message-foot"><?= html_escape($mediaSummary); ?></div>
                                                    <?php } ?>
                                                    <?php if ($providerMessageId !== '' || !empty($message['context_provider_message_id']) || !empty($message['provider_status'])) { ?>
                                                        <details class="marketing-whatsapp-details">
                                                            <summary><?= _l('marketing_whatsapp_conversation_message_meta'); ?></summary>
                                                            <div class="marketing-whatsapp-message-foot">
                                                                <?php if ($providerMessageId !== '') { ?>
                                                                    <div><?= _l('marketing_whatsapp_conversation_provider_message_id'); ?>: <code><?= html_escape($providerMessageId); ?></code></div>
                                                                <?php } ?>
                                                                <?php if (!empty($message['context_provider_message_id'])) { ?>
                                                                    <div><?= _l('marketing_whatsapp_conversation_context_message_id'); ?>: <code><?= html_escape((string) $message['context_provider_message_id']); ?></code></div>
                                                                <?php } ?>
                                                                <?php if (!empty($message['provider_status'])) { ?>
                                                                    <div><?= _l('marketing_status'); ?>: <?= html_escape((string) $message['provider_status']); ?></div>
                                                                <?php } ?>
                                                            </div>
                                                        </details>
                                                    <?php } ?>
                                                </div>
                                            <?php } ?>
                                        </div>
                                    <?php } ?>
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
