<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-8 col-md-offset-2">
                <div class="panel_s">
                    <div class="panel-body">
                        <h4 class="no-margin"><?= html_escape($title); ?></h4>
                        <hr />
                        <?= form_open($action, ['id' => 'marketing_audience_form']); ?>
                        <div class="form-group">
                            <label for="name"><?= _l('marketing_name'); ?></label>
                            <input type="text" id="name" name="name" class="form-control" value="<?= html_escape($list['name']); ?>" required />
                        </div>
                        <div class="form-group">
                            <label for="description"><?= _l('description'); ?></label>
                            <textarea id="description" name="description" class="form-control" rows="4"><?= html_escape($list['description']); ?></textarea>
                        </div>
                        <div class="form-group">
                            <label for="source_mode"><?= _l('marketing_source_mode'); ?></label>
                            <select id="source_mode" name="source_mode" class="form-control">
                                <?php foreach (['manual' => 'Manual', 'csv' => 'CSV', 'perfex_sync' => 'Perfex Sync', 'mixed' => 'Mixed'] as $value => $label) { ?>
                                    <option value="<?= html_escape($value); ?>" <?= $list['source_mode'] === $value ? 'selected' : ''; ?>><?= html_escape($label); ?></option>
                                <?php } ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary"><?= _l('submit'); ?></button>
                        <a href="<?= admin_url('marketing/audiences'); ?>" class="btn btn-default"><?= _l('back'); ?></a>
                        <?= form_close(); ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php init_tail(); ?>
</body>
</html>
