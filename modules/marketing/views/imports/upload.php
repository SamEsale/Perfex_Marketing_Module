<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<div class="panel_s">
    <div class="panel-body">
        <h4 class="no-margin"><?= _l('marketing_import_upload'); ?></h4>
        <p class="text-muted mtop10"><?= _l('marketing_import_upload_note'); ?></p>
        <div class="alert alert-info mtop15">
            <?= _l('marketing_import_upload_queue_note'); ?>
        </div>
        <hr />
        <?php if (empty($lists)) { ?>
            <p class="text-muted"><?= _l('marketing_import_no_lists'); ?></p>
        <?php } else { ?>
            <?= form_open_multipart(admin_url('marketing/imports/upload'), ['id' => 'marketing_import_form']); ?>
                <div class="form-group">
                    <label for="list_id"><?= _l('marketing_import_select_list'); ?></label>
                    <select id="list_id" name="list_id" class="form-control" required>
                        <option value=""><?= _l('marketing_choose_list'); ?></option>
                        <?php foreach ($lists as $list) { ?>
                            <option value="<?= html_escape($list['id']); ?>"><?= html_escape($list['name']); ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="import_file"><?= _l('marketing_import_file'); ?></label>
                    <input type="file" id="import_file" name="import_file" accept=".csv" class="form-control" required />
                </div>
                <button type="submit" class="btn btn-primary"><?= _l('marketing_import_upload_button'); ?></button>
            <?= form_close(); ?>
        <?php } ?>
    </div>
</div>
