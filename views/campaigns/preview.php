<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<?php $isWhatsappCampaign = ($campaign['delivery_channel'] ?? 'email') === 'whatsapp'; ?>
<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-10 col-md-offset-1">
                <div class="panel_s">
                    <div class="panel-body">
                        <div class="marketing-campaign-preview-shell" data-marketing-campaign-preview-shell="1">
                            <div class="marketing-campaign-preview-header">
                                <div>
                                    <h4 class="marketing-campaign-preview-title"><?= _l('marketing_campaign_preview'); ?></h4>
                                    <p class="marketing-campaign-review-note no-margin"><?= _l('marketing_campaign_preview_review_note'); ?></p>
                                </div>
                                <div class="marketing-campaign-preview-actions">
                                    <a href="<?= admin_url('marketing/campaigns/show/' . $campaign['id']); ?>" class="btn btn-default"><?= _l('marketing_campaign_preview_back_to_detail'); ?></a>
                                </div>
                            </div>
                            <div class="marketing-campaign-preview-meta">
                                <span class="marketing-campaign-preview-chip"><?= html_escape($campaign['name']); ?></span>
                                <span class="marketing-campaign-preview-chip"><?= html_escape($isWhatsappCampaign ? _l('marketing_campaign_delivery_channel_whatsapp') : _l('marketing_campaign_delivery_channel_email')); ?></span>
                            </div>
                        </div>
                        <hr />

                        <?php if (empty($preview_html)) { ?>
                            <p class="text-muted"><?= _l('marketing_no_records_yet'); ?></p>
                        <?php } else { ?>
                            <div class="panel panel-default marketing-campaign-preview-shell">
                                <div class="panel-body marketing-campaign-preview-rendered">
                                    <?= $preview_html; ?>
                                </div>
                            </div>
                        <?php } ?>

                        <a href="<?= admin_url('marketing/campaigns/show/' . $campaign['id']); ?>" class="btn btn-default"><?= _l('back'); ?></a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php init_tail(); ?>
</body>
</html>
