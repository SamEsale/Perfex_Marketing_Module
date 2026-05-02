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
                        <?= form_open($action, ['id' => 'marketing_contact_form']); ?>
                        <div class="form-group">
                            <label for="email"><?= _l('marketing_email'); ?></label>
                            <input type="email" id="email" name="email" class="form-control" value="<?= html_escape($contact['email']); ?>" required />
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="first_name"><?= _l('marketing_first_name'); ?></label>
                                    <input type="text" id="first_name" name="first_name" class="form-control" value="<?= html_escape($contact['first_name']); ?>" />
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="last_name"><?= _l('marketing_last_name'); ?></label>
                                    <input type="text" id="last_name" name="last_name" class="form-control" value="<?= html_escape($contact['last_name']); ?>" />
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="full_name"><?= _l('marketing_full_name'); ?></label>
                            <input type="text" id="full_name" name="full_name" class="form-control" value="<?= html_escape($contact['full_name']); ?>" />
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="phone"><?= _l('marketing_phone'); ?></label>
                                    <input type="text" id="phone" name="phone" class="form-control" value="<?= html_escape($contact['phone']); ?>" />
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="company"><?= _l('marketing_company'); ?></label>
                                    <input type="text" id="company" name="company" class="form-control" value="<?= html_escape($contact['company']); ?>" />
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="status"><?= _l('marketing_status'); ?></label>
                                    <select id="status" name="status" class="form-control">
                                        <?php foreach ($status_options as $option) { ?>
                                            <option value="<?= html_escape($option); ?>" <?= $contact['status'] === $option ? 'selected' : ''; ?>>
                                                <?= html_escape(ucfirst($option)); ?>
                                            </option>
                                        <?php } ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="consent_status"><?= _l('marketing_consent_status'); ?></label>
                                    <select id="consent_status" name="consent_status" class="form-control">
                                        <?php foreach ($consent_status_options as $option) { ?>
                                            <option value="<?= html_escape($option); ?>" <?= $contact['consent_status'] === $option ? 'selected' : ''; ?>>
                                                <?= html_escape(ucfirst($option)); ?>
                                            </option>
                                        <?php } ?>
                                    </select>
                                </div>
                            </div>
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
