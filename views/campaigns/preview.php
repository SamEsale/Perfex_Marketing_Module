<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-10 col-md-offset-1">
                <div class="panel_s">
                    <div class="panel-body">
                        <h4 class="no-margin"><?= _l('marketing_campaign_preview'); ?></h4>
                        <p class="text-muted mtop10"><?= _l('marketing_campaign_preview_note'); ?></p>
                        <hr />

                        <?php if (empty($preview_html)) { ?>
                            <p class="text-muted"><?= _l('marketing_no_records_yet'); ?></p>
                        <?php } else { ?>
                            <div class="panel panel-default">
                                <div class="panel-body">
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
