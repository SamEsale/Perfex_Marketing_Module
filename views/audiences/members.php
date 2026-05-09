<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<style>
    .marketing-members-toolbar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        flex-wrap: wrap;
        margin-bottom: 16px;
    }
    .marketing-add-contact-panel {
        margin-bottom: 20px;
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        background: #f8fafc;
    }
</style>
<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-12">
                <div class="panel_s">
                    <div class="panel-body">
                        <h4 class="no-margin"><?= _l('marketing_audience_members'); ?>: <?= html_escape($list['name']); ?></h4>
                        <p class="text-muted mtop10"><?= html_escape($list['description']); ?></p>
                        <hr />
                        <div class="marketing-members-toolbar">
                            <div>
                                <?php if ($can_add_contact) { ?>
                                    <a href="#marketing-add-contact-form" class="btn btn-info"><?= _l('marketing_add_contact'); ?></a>
                                <?php } ?>
                            </div>
                            <div>
                                <?php if ($can_delete && !empty($members)) { ?>
                                    <?= form_open(admin_url('marketing/audiences/members/' . $list['id']), ['id' => 'bulk-remove-members-form', 'class' => 'inline-block']); ?>
                                        <button type="submit" name="bulk_remove_members" value="1" class="btn btn-warning" onclick="return confirm('<?= html_escape(_l('confirm_action_prompt')); ?>');">
                                            <?= _l('marketing_bulk_remove_from_audience'); ?>
                                        </button>
                                    <?= form_close(); ?>
                                <?php } ?>
                            </div>
                        </div>
                        <?php if ($can_add_contact) { ?>
                            <div class="panel_s marketing-add-contact-panel" id="marketing-add-contact-form">
                                <div class="panel-body">
                                    <h5 class="bold no-margin"><?= _l('marketing_add_contact'); ?></h5>
                                    <p class="text-muted mtop10"><?= html_escape($list['name']); ?></p>
                                    <?= form_open($add_contact_action, ['id' => 'marketing-add-contact-form-inner']); ?>
                                        <input type="hidden" name="add_contact" value="1">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label for="email"><?= _l('marketing_email'); ?></label>
                                                    <input type="email" id="email" name="email" class="form-control" value="<?= html_escape($contact_form['email']); ?>" required>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label for="full_name"><?= _l('marketing_full_name'); ?></label>
                                                    <input type="text" id="full_name" name="full_name" class="form-control" value="<?= html_escape($contact_form['full_name']); ?>">
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label for="first_name"><?= _l('marketing_first_name'); ?></label>
                                                    <input type="text" id="first_name" name="first_name" class="form-control" value="<?= html_escape($contact_form['first_name']); ?>">
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label for="last_name"><?= _l('marketing_last_name'); ?></label>
                                                    <input type="text" id="last_name" name="last_name" class="form-control" value="<?= html_escape($contact_form['last_name']); ?>">
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label for="phone"><?= _l('marketing_phone'); ?></label>
                                                    <input type="text" id="phone" name="phone" class="form-control" value="<?= html_escape($contact_form['phone']); ?>">
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label for="company"><?= _l('marketing_company'); ?></label>
                                                    <input type="text" id="company" name="company" class="form-control" value="<?= html_escape($contact_form['company']); ?>">
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label for="status"><?= _l('marketing_status'); ?></label>
                                                    <select id="status" name="status" class="form-control">
                                                        <?php foreach ($status_options as $option) { ?>
                                                            <option value="<?= html_escape($option); ?>" <?= $contact_form['status'] === $option ? 'selected' : ''; ?>>
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
                                                            <option value="<?= html_escape($option); ?>" <?= $contact_form['consent_status'] === $option ? 'selected' : ''; ?>>
                                                                <?= html_escape(ucfirst($option)); ?>
                                                            </option>
                                                        <?php } ?>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                        <button type="submit" class="btn btn-primary"><?= _l('marketing_add_contact'); ?></button>
                                    <?= form_close(); ?>
                                </div>
                            </div>
                        <?php } ?>
                        <?php if (empty($members)) { ?>
                            <p class="text-muted"><?= _l('marketing_no_records_yet'); ?></p>
                        <?php } else { ?>
                            <div class="table-responsive">
                                <table class="table dt-table">
                                    <thead>
                                        <tr>
                                            <?php if ($can_delete) { ?>
                                                <th>
                                                    <input type="checkbox" id="marketing-members-select-all">
                                                </th>
                                            <?php } ?>
                                            <th><?= _l('id'); ?></th>
                                            <th><?= _l('marketing_email'); ?></th>
                                            <th><?= _l('marketing_first_name'); ?></th>
                                            <th><?= _l('marketing_last_name'); ?></th>
                                            <th><?= _l('marketing_full_name'); ?></th>
                                            <th><?= _l('marketing_phone'); ?></th>
                                            <th><?= _l('marketing_company'); ?></th>
                                            <th><?= _l('marketing_status'); ?></th>
                                            <th><?= _l('marketing_consent_status'); ?></th>
                                            <th><?= _l('marketing_date_added'); ?></th>
                                            <th><?= _l('options'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($members as $member) { ?>
                                            <tr>
                                                <?php if ($can_delete) { ?>
                                                    <td>
                                                        <input type="checkbox" name="member_ids[]" value="<?= (int) $member['id']; ?>" class="marketing-member-checkbox" form="bulk-remove-members-form">
                                                    </td>
                                                <?php } ?>
                                                <td><?= html_escape($member['id']); ?></td>
                                                <td><?= html_escape($member['email']); ?></td>
                                                <td><?= html_escape($member['first_name']); ?></td>
                                                <td><?= html_escape($member['last_name']); ?></td>
                                                <td><?= html_escape($member['full_name']); ?></td>
                                                <td><?= html_escape($member['phone']); ?></td>
                                                <td><?= html_escape($member['company']); ?></td>
                                                <td><?= html_escape($member['status']); ?></td>
                                                <td><?= html_escape($member['consent_status']); ?></td>
                                                <td><?= html_escape($member['dateadded']); ?></td>
                                                <td>
                                                    <?php if ($can_edit) { ?>
                                                        <a href="<?= admin_url('marketing/audiences/edit_contact/' . $member['id']); ?>" class="btn btn-default btn-sm">
                                                            <?= _l('marketing_edit_contact'); ?>
                                                        </a>
                                                    <?php } ?>
                                                    <?php if ($can_delete) { ?>
                                                        <?= form_open(admin_url('marketing/audiences/members/' . $list['id']), ['class' => 'inline-block mtop5']); ?>
                                                <input type="hidden" name="remove_member_contact_id" value="<?= html_escape($member['id']); ?>">
                                                            <button type="submit" class="btn btn-warning btn-sm mright5 mbot5" onclick="return confirm('<?= html_escape(_l('confirm_action_prompt')); ?>');">
                                                                <?= _l('marketing_remove_from_audience'); ?>
                                                            </button>
                                                        <?= form_close(); ?>
                                                        <?= form_open(admin_url('marketing/audiences/delete_contact/' . $member['id']), ['class' => 'inline-block mtop5']); ?>
                                                            <input type="hidden" name="list_id" value="<?= html_escape($list['id']); ?>">
                                                            <button type="submit" class="btn btn-danger btn-sm mright5 mbot5" onclick="return confirm('<?= html_escape(_l('confirm_action_prompt')); ?>');">
                                                                <?= _l('marketing_delete_contact'); ?>
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
                        <a href="<?= admin_url('marketing/audiences'); ?>" class="btn btn-default"><?= _l('back'); ?></a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php init_tail(); ?>
<script>
    (function () {
        var selectAll = document.getElementById('marketing-members-select-all');
        if (!selectAll) {
            return;
        }

        selectAll.addEventListener('change', function () {
            var checkboxes = document.querySelectorAll('.marketing-member-checkbox');
            for (var i = 0; i < checkboxes.length; i++) {
                checkboxes[i].checked = selectAll.checked;
            }
        });
    }());
</script>
</body>
</html>
