<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-12">
                <?php if ($can_import) { ?>
                    <?php $this->load->view('marketing/imports/upload', ['lists' => $lists]); ?>
                <?php } ?>
                <div class="panel_s">
                    <div class="panel-body">
                        <h4 class="no-margin"><?= _l('marketing_imports'); ?></h4>
                        <p class="text-muted mtop10"><?= _l('marketing_imports_placeholder'); ?></p>
                        <hr />
                        <p><strong><?= _l('marketing_total_imports'); ?>:</strong> <?= html_escape($total_count); ?></p>
                        <?php if ($can_import) { ?>
                            <p class="text-muted"><?= _l('marketing_imports_permission_ready'); ?></p>
                        <?php } ?>
                        <hr />
                        <?php if (empty($imports)) { ?>
                            <p class="text-muted no-margin"><?= _l('marketing_no_records_yet'); ?></p>
                        <?php } else { ?>
                            <div class="table-responsive">
                                <table class="table dt-table">
                                    <thead>
                                        <tr>
                                            <th><?= _l('id'); ?></th>
                                            <th><?= _l('marketing_filename'); ?></th>
                                            <th><?= _l('marketing_status'); ?></th>
                                            <th><?= _l('marketing_total_rows'); ?></th>
                                            <th><?= _l('marketing_imported_rows'); ?></th>
                                            <th><?= _l('marketing_skipped_rows'); ?></th>
                                            <th><?= _l('marketing_failed_rows'); ?></th>
                                            <th><?= _l('marketing_completed_at'); ?></th>
                                            <th><?= _l('options'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($imports as $import) { ?>
                                            <tr>
                                                <td><?= html_escape($import['id']); ?></td>
                                                <td><?= html_escape($import['filename']); ?></td>
                                                <td><?= html_escape($import['status']); ?></td>
                                                <td><?= html_escape($import['total_rows']); ?></td>
                                                <td><?= html_escape($import['imported_rows']); ?></td>
                                                <td><?= html_escape($import['skipped_rows']); ?></td>
                                                <td><?= html_escape($import['failed_rows']); ?></td>
                                                <td><?= html_escape($import['completed_at']); ?></td>
                                                <td>
                                                    <?php if ($can_delete) { ?>
                                                        <?= form_open(admin_url('marketing/imports'), ['class' => 'inline-block']); ?>
                                                            <input type="hidden" name="delete_import_id" value="<?= html_escape($import['id']); ?>">
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
