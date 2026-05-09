<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-12">
                <div class="panel_s">
                    <div class="panel-body">
                        <div class="tw-flex tw-items-center tw-justify-between">
                            <div>
                                <h4 class="no-margin"><?= _l('marketing_campaigns'); ?></h4>
                                <p class="text-muted mtop10"><?= _l('marketing_campaigns_placeholder'); ?></p>
                            </div>
                            <div>
                                <?php if ($can_create) { ?>
                                    <a href="<?= admin_url('marketing/campaigns/create'); ?>" class="btn btn-primary"><?= _l('marketing_create_campaign'); ?></a>
                                <?php } ?>
                            </div>
                        </div>
                        <hr />
                        <?php if (empty($campaigns)) { ?>
                            <p class="text-muted no-margin"><?= _l('marketing_no_records_yet'); ?></p>
                        <?php } else { ?>
                            <div class="table-responsive">
                                <table class="table dt-table">
                                    <thead>
                                        <tr>
                                            <th><?= _l('id'); ?></th>
                                            <th><?= _l('marketing_name'); ?></th>
                                            <th><?= _l('marketing_subject'); ?></th>
                                            <th><?= _l('marketing_campaign_list'); ?></th>
                                            <th><?= _l('marketing_status'); ?></th>
                                            <th><?= _l('options'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($campaigns as $campaign) { ?>
                                            <tr>
                                                <td><?= (int) $campaign['id']; ?></td>
                                                <td><?= html_escape($campaign['name']); ?></td>
                                                <td><?= html_escape($campaign['subject']); ?></td>
                                                <td><?= html_escape($campaign['audience_name'] ?: '-'); ?></td>
                                                <td><?= html_escape($campaign['status']); ?></td>
                                                <td>
                                                    <a href="<?= admin_url('marketing/campaigns/show/' . $campaign['id']); ?>" class="btn btn-default">
                                                        <?= _l('view'); ?>
                                                    </a>
                                                    <a href="<?= admin_url('marketing/campaigns/preview/' . $campaign['id']); ?>" class="btn btn-default">
                                                        <?= _l('marketing_preview_campaign_button'); ?>
                                                    </a>
                                                    <?php if ($can_edit && !empty($campaign['can_edit_campaign'])) { ?>
                                                        <a href="<?= admin_url('marketing/campaigns/edit/' . $campaign['id']); ?>" class="btn btn-default">
                                                            <?= _l('edit'); ?>
                                                        </a>
                                                    <?php } ?>
                                                    <?php if ($can_send && in_array($campaign['status'], ['draft', 'scheduled', 'sending'], true) && (int) $campaign['queued_recipients'] === 0) { ?>
                                                        <?= form_open(admin_url('marketing/campaigns'), ['class' => 'inline-block']); ?>
                                                            <input type="hidden" name="prepare_campaign_id" value="<?= (int) $campaign['id']; ?>">
                                                            <button type="submit" class="btn btn-info">
                                                                <?= _l('marketing_campaign_prepare_recipients'); ?>
                                                            </button>
                                                        <?= form_close(); ?>
                                                    <?php } ?>
                                                    <?php if ($can_send && in_array($campaign['status'], ['draft', 'scheduled', 'sending'], true) && (int) $campaign['queued_recipients'] > 0) { ?>
                                                        <?= form_open(admin_url('marketing/campaigns'), ['class' => 'inline-block']); ?>
                                                            <input type="hidden" name="send_campaign_id" value="<?= (int) $campaign['id']; ?>">
                                                            <button type="submit" class="btn btn-success">
                                                                <?= _l('marketing_campaign_send_campaign'); ?>
                                                            </button>
                                                        <?= form_close(); ?>
                                                    <?php } ?>
                                                    <?php if ($can_delete && !empty($campaign['can_delete_campaign'])) { ?>
                                                        <?= form_open(admin_url('marketing/campaigns'), ['class' => 'inline-block']); ?>
                                                            <input type="hidden" name="delete_campaign_id" value="<?= (int) $campaign['id']; ?>">
                                                            <button type="submit" class="btn btn-danger" onclick="return confirm('Delete this campaign?');">
                                                                <?= _l('delete'); ?>
                                                            </button>
                                                        <?= form_close(); ?>
                                                    <?php } ?>
                                                    <?php if ($can_send && !empty($campaign['can_reset_campaign'])) { ?>
                                                        <?= form_open(admin_url('marketing/campaigns'), ['class' => 'inline-block']); ?>
                                                            <input type="hidden" name="reset_campaign_id" value="<?= (int) $campaign['id']; ?>">
                                                            <button type="submit" class="btn btn-default">
                                                                <?= _l('marketing_campaign_reset_to_draft_button'); ?>
                                                            </button>
                                                        <?= form_close(); ?>
                                                    <?php } ?>
                                                </td>
                                            </tr>
                                        <?php } ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php } ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php init_tail(); ?>
</body>
</html>
