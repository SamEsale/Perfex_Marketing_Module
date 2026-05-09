<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-12">
                <div class="panel_s">
                    <div class="panel-body">
                        <div class="tw-flex tw-justify-between tw-items-center">
                            <div>
                                <h4 class="no-margin"><?= _l('marketing_audiences'); ?></h4>
                                <p class="text-muted mtop10"><?= _l('marketing_audiences_placeholder'); ?></p>
                            </div>
                            <?php if ($can_create) { ?>
                                <a href="<?= admin_url('marketing/audiences/create'); ?>" class="btn btn-primary"><?= _l('marketing_create_audience'); ?></a>
                            <?php } ?>
                        </div>
                        <hr />
                        <div class="row mtop10">
                            <div class="col-md-6">
                                <h5 class="bold"><?= _l('marketing_lists'); ?></h5>
                                <p><?= html_escape($totals['lists']); ?></p>
                            </div>
                            <div class="col-md-6">
                                <h5 class="bold"><?= _l('marketing_contacts'); ?></h5>
                                <p><?= html_escape($totals['contacts']); ?></p>
                            </div>
                        </div>
                        <hr />
                        <?php if (empty($lists)) { ?>
                            <p class="text-muted no-margin"><?= _l('marketing_no_records_yet'); ?></p>
                        <?php } else { ?>
                            <div class="table-responsive">
                                <table class="table dt-table">
                                    <thead>
                                        <tr>
                                            <th><?= _l('id'); ?></th>
                                            <th><?= _l('marketing_name'); ?></th>
                                            <th><?= _l('marketing_source_mode'); ?></th>
                                            <th><?= _l('marketing_contacts'); ?></th>
                                            <th><?= _l('options'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($lists as $list) { ?>
                                            <tr>
                                                <td><?= html_escape($list['id']); ?></td>
                                                <td><?= html_escape($list['name']); ?></td>
                                                <td><?= html_escape($list['source_mode']); ?></td>
                                                <td><?= html_escape($list['members_count']); ?></td>
                                                <td>
                                                    <a href="<?= admin_url('marketing/audiences/members/' . $list['id']); ?>" class="btn btn-info btn-sm">
                                                        <?= _l('marketing_view_contacts'); ?>
                                                    </a>
                                                    <?php if ($can_edit) { ?>
                                                        <a href="<?= admin_url('marketing/audiences/edit/' . $list['id']); ?>" class="btn btn-default btn-sm">
                                                            <?= _l('edit'); ?>
                                                        </a>
                                                    <?php } ?>
                                                    <?php if ($can_delete) { ?>
                                                        <?= form_open(admin_url('marketing/audiences'), ['class' => 'inline-block mleft5']); ?>
                                                        <input type="hidden" name="delete_audience_id" value="<?= html_escape($list['id']); ?>">
                                                            <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('<?= html_escape(_l('confirm_action_prompt')); ?>');">
                                                                <?= _l('delete'); ?>
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
