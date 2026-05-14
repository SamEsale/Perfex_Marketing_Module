<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<style>
    .marketing-reports-shell {
        border: 1px solid #e2e8f0;
        border-radius: 18px;
        background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
        overflow: hidden;
    }
    .marketing-reports-body {
        padding: 22px 24px;
    }
    .marketing-reports-copy {
        margin: 8px 0 0;
        max-width: 760px;
        color: #64748b;
        font-size: 14px;
        line-height: 1.6;
    }
    .marketing-reports-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 18px;
        margin-top: 18px;
    }
    .marketing-reports-card {
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        background: #ffffff;
        overflow: hidden;
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.04);
    }
    .marketing-reports-card-body {
        padding: 18px 20px;
    }
    .marketing-reports-card-head {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 14px;
    }
    .marketing-reports-card-copy {
        margin: 8px 0 0;
        color: #64748b;
        font-size: 13px;
        line-height: 1.6;
    }
    .marketing-reports-card-head .btn {
        white-space: nowrap;
    }
    .marketing-reports-danger-zone {
        margin-top: 22px;
        border: 1px solid #fecaca;
        border-radius: 16px;
        background: linear-gradient(135deg, #fff7f7 0%, #ffffff 100%);
        overflow: hidden;
        box-shadow: 0 10px 28px rgba(127, 29, 29, 0.05);
    }
    .marketing-reports-danger-zone .panel-body {
        padding: 20px 22px;
    }
    .marketing-reports-danger-copy {
        margin: 10px 0 0;
        color: #7f1d1d;
        font-size: 13px;
        line-height: 1.7;
    }
    .marketing-reports-danger-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 16px;
        margin-top: 18px;
    }
    .marketing-reports-danger-card {
        border: 1px solid #fecaca;
        border-radius: 14px;
        background: #ffffff;
        padding: 18px;
    }
    .marketing-reports-danger-label {
        display: block;
        margin-bottom: 6px;
        font-size: 12px;
        font-weight: 600;
        color: #991b1b;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }
    .marketing-reports-danger-helper {
        margin: 8px 0 0;
        color: #7f1d1d;
        font-size: 12px;
        line-height: 1.6;
    }
    .marketing-reports-danger-card .form-group:last-child {
        margin-bottom: 0;
    }
    @media (max-width: 991px) {
        .marketing-reports-grid {
            grid-template-columns: repeat(1, minmax(0, 1fr));
        }
        .marketing-reports-danger-grid {
            grid-template-columns: repeat(1, minmax(0, 1fr));
        }
    }
    @media (max-width: 767px) {
        .marketing-reports-card-head {
            flex-direction: column;
            align-items: flex-start;
        }
    }
</style>
<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-12">
                <div class="panel_s marketing-reports-shell">
                    <div class="panel-body marketing-reports-body">
                        <h4 class="no-margin"><?= _l('marketing_reports_heading'); ?></h4>
                        <p class="marketing-reports-copy"><?= _l('marketing_reports_note'); ?></p>

                        <div class="marketing-reports-grid">
                            <div class="marketing-reports-card">
                                <div class="marketing-reports-card-body">
                                    <div class="marketing-reports-card-head">
                                        <div>
                                            <h4 class="no-margin"><?= _l('marketing_reports_recent_campaigns'); ?></h4>
                                            <p class="marketing-reports-card-copy"><?= _l('marketing_table_reset_view_note'); ?></p>
                                        </div>
                                        <button
                                            type="button"
                                            class="btn btn-default btn-sm"
                                            data-marketing-reset-table="campaigns"
                                            aria-controls="marketing-reports-recent-campaigns-table"
                                        >
                                            <?= _l('marketing_table_reset_view'); ?>
                                        </button>
                                    </div>
                                    <hr />
                                    <?php if (empty($recent_campaigns)) { ?>
                                        <p class="text-muted"><?= _l('marketing_no_campaigns_yet'); ?></p>
                                    <?php } else { ?>
                                        <div class="table-responsive">
                                            <table class="table dt-table" id="marketing-reports-recent-campaigns-table" data-marketing-reports-table="campaigns" data-marketing-default-length="25" data-marketing-default-order="[[0,&quot;asc&quot;]]">
                                                <thead>
                                                    <tr>
                                                        <th><?= _l('id'); ?></th>
                                                        <th><?= _l('marketing_name'); ?></th>
                                                        <th><?= _l('marketing_subject'); ?></th>
                                                        <th><?= _l('marketing_status'); ?></th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($recent_campaigns as $campaign) { ?>
                                                        <tr>
                                                            <td><?= html_escape($campaign['id']); ?></td>
                                                            <td><?= html_escape($campaign['name']); ?></td>
                                                            <td><?= html_escape($campaign['subject']); ?></td>
                                                            <td><?= html_escape($campaign['status']); ?></td>
                                                        </tr>
                                                    <?php } ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php } ?>
                                </div>
                            </div>

                            <div class="marketing-reports-card">
                                <div class="marketing-reports-card-body">
                                    <div class="marketing-reports-card-head">
                                        <div>
                                            <h4 class="no-margin"><?= _l('marketing_reports_recent_activity'); ?></h4>
                                            <p class="marketing-reports-card-copy"><?= _l('marketing_table_reset_view_note'); ?></p>
                                        </div>
                                        <button
                                            type="button"
                                            class="btn btn-default btn-sm"
                                            data-marketing-reset-table="activity"
                                            aria-controls="marketing-reports-recent-activity-table"
                                        >
                                            <?= _l('marketing_table_reset_view'); ?>
                                        </button>
                                    </div>
                                    <hr />
                                    <?php if (empty($recent_activity)) { ?>
                                        <p class="text-muted"><?= _l('marketing_no_activity_yet'); ?></p>
                                    <?php } else { ?>
                                        <div class="table-responsive">
                                            <table class="table dt-table" id="marketing-reports-recent-activity-table" data-marketing-reports-table="activity" data-marketing-default-length="25" data-marketing-default-order="[[3,&quot;desc&quot;]]">
                                                <thead>
                                                    <tr>
                                                        <th><?= _l('marketing_event_type'); ?></th>
                                                        <th><?= _l('marketing_provider'); ?></th>
                                                        <th><?= _l('marketing_email'); ?></th>
                                                        <th><?= _l('marketing_event_at'); ?></th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($recent_activity as $event) { ?>
                                                        <tr>
                                                            <td><?= html_escape($event['event_type']); ?></td>
                                                            <td><?= html_escape($event['provider']); ?></td>
                                                            <td><?= html_escape($event['email']); ?></td>
                                                            <td><?= html_escape($event['event_at']); ?></td>
                                                        </tr>
                                                    <?php } ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php } ?>
                                </div>
                            </div>
                        </div>

                        <div class="panel_s marketing-reports-danger-zone" data-marketing-reports-danger-zone="1">
                            <div class="panel-body">
                                <h4 class="no-margin text-danger"><?= _l('marketing_reports_cleanup_heading'); ?></h4>
                                <p class="marketing-reports-danger-copy">
                                    <?= _l('marketing_reports_cleanup_warning'); ?><br />
                                    <?= _l('marketing_reports_cleanup_export_warning'); ?>
                                </p>

                                <div class="marketing-reports-danger-grid">
                                    <div class="marketing-reports-danger-card" data-marketing-reports-cleanup-card="activity">
                                        <h5 class="no-margin"><?= _l('marketing_reports_cleanup_activity_title'); ?></h5>
                                        <p class="marketing-reports-danger-helper"><?= _l('marketing_reports_cleanup_activity_note'); ?></p>
                                        <?= form_open(admin_url('marketing/reports_cleanup')); ?>
                                            <input type="hidden" name="cleanup_target" value="activity" />
                                            <div class="form-group mtop15">
                                                <label class="marketing-reports-danger-label" for="marketing-reports-cleanup-activity-confirmation">
                                                    <?= _l('marketing_reports_cleanup_confirmation_label'); ?>
                                                </label>
                                                <input
                                                    type="text"
                                                    class="form-control"
                                                    id="marketing-reports-cleanup-activity-confirmation"
                                                    name="cleanup_confirmation"
                                                    value=""
                                                    autocomplete="off"
                                                    spellcheck="false"
                                                    placeholder="<?= html_escape($reports_cleanup['activity_confirmation_phrase'] ?? 'DELETE ACTIVITY REPORTS'); ?>"
                                                />
                                                <p class="marketing-reports-danger-helper">
                                                    <?= sprintf(
                                                        _l('marketing_reports_cleanup_confirmation_phrase'),
                                                        '<code>' . html_escape($reports_cleanup['activity_confirmation_phrase'] ?? 'DELETE ACTIVITY REPORTS') . '</code>'
                                                    ); ?>
                                                </p>
                                            </div>
                                            <button type="submit" class="btn btn-danger">
                                                <?= _l('marketing_reports_cleanup_activity_title'); ?>
                                            </button>
                                        <?= form_close(); ?>
                                    </div>

                                    <div class="marketing-reports-danger-card" data-marketing-reports-cleanup-card="campaigns">
                                        <h5 class="no-margin"><?= _l('marketing_reports_cleanup_campaigns_title'); ?></h5>
                                        <p class="marketing-reports-danger-helper"><?= _l('marketing_reports_cleanup_campaigns_note'); ?></p>
                                        <?= form_open(admin_url('marketing/reports_cleanup')); ?>
                                            <input type="hidden" name="cleanup_target" value="campaigns" />
                                            <div class="form-group mtop15">
                                                <label class="marketing-reports-danger-label" for="marketing-reports-cleanup-campaigns-confirmation">
                                                    <?= _l('marketing_reports_cleanup_confirmation_label'); ?>
                                                </label>
                                                <input
                                                    type="text"
                                                    class="form-control"
                                                    id="marketing-reports-cleanup-campaigns-confirmation"
                                                    name="cleanup_confirmation"
                                                    value=""
                                                    autocomplete="off"
                                                    spellcheck="false"
                                                    placeholder="<?= html_escape($reports_cleanup['campaigns_confirmation_phrase'] ?? 'DELETE CAMPAIGN REPORTS'); ?>"
                                                />
                                                <p class="marketing-reports-danger-helper">
                                                    <?= sprintf(
                                                        _l('marketing_reports_cleanup_confirmation_phrase'),
                                                        '<code>' . html_escape($reports_cleanup['campaigns_confirmation_phrase'] ?? 'DELETE CAMPAIGN REPORTS') . '</code>'
                                                    ); ?>
                                                </p>
                                            </div>
                                            <button type="submit" class="btn btn-danger">
                                                <?= _l('marketing_reports_cleanup_campaigns_title'); ?>
                                            </button>
                                        <?= form_close(); ?>
                                    </div>
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
<script>
    (function (window, document) {
        var tableDefaults = {};

        function getDataTableInstance(tableElement) {
            if (!tableElement || typeof window.jQuery === 'undefined') {
                return null;
            }

            var $ = window.jQuery;
            if (!$.fn || !$.fn.DataTable || typeof $.fn.DataTable.isDataTable !== 'function') {
                return null;
            }

            if (!$.fn.DataTable.isDataTable(tableElement)) {
                return null;
            }

            return $(tableElement).DataTable();
        }

        function parseDefaultOrder(tableElement) {
            var rawOrder = tableElement.getAttribute('data-marketing-default-order') || '';
            if (rawOrder === '') {
                return [];
            }

            try {
                var parsed = JSON.parse(rawOrder);
                return Array.isArray(parsed) ? parsed : [];
            } catch (error) {
                return [];
            }
        }

        function getDefaultLength(tableElement) {
            var parsed = parseInt(tableElement.getAttribute('data-marketing-default-length') || '25', 10);
            return parsed > 0 ? parsed : 25;
        }

        function ensureReportsTableDefaults(tableKey, attempt) {
            var table = document.querySelector('[data-marketing-reports-table="' + tableKey + '"]');
            var dataTable = getDataTableInstance(table);
            var defaultOrder;
            var defaultLength;

            if (!table) {
                return;
            }

            if (!dataTable) {
                if ((attempt || 0) >= 20) {
                    return;
                }

                window.setTimeout(function () {
                    ensureReportsTableDefaults(tableKey, (attempt || 0) + 1);
                }, 250);
                return;
            }

            if (!tableDefaults[tableKey]) {
                defaultOrder = parseDefaultOrder(table);
                defaultLength = getDefaultLength(table);
                tableDefaults[tableKey] = {
                    order: defaultOrder,
                    length: defaultLength
                };
            }

            defaultOrder = tableDefaults[tableKey].order;
            defaultLength = tableDefaults[tableKey].length;

            if (typeof dataTable.page.len === 'function' && dataTable.page.len() !== defaultLength) {
                dataTable.page.len(defaultLength);
            }

            if (Array.isArray(defaultOrder) && defaultOrder.length > 0 && typeof dataTable.order === 'function') {
                dataTable.order(defaultOrder);
            } else if (typeof dataTable.order === 'function') {
                dataTable.order([]);
            }

            if (typeof dataTable.page === 'function') {
                dataTable.page('first');
            }

            if (typeof dataTable.draw === 'function') {
                dataTable.draw(false);
            }
        }

        function resetReportsTable(tableKey) {
            var table = document.querySelector('[data-marketing-reports-table="' + tableKey + '"]');
            var dataTable = getDataTableInstance(table);
            var defaults = tableDefaults[tableKey] || null;

            if (!dataTable) {
                ensureReportsTableDefaults(tableKey, 0);
                return;
            }

            if (typeof dataTable.search === 'function') {
                dataTable.search('');
            }

            if (typeof dataTable.columns === 'function') {
                dataTable.columns().search('');
            }

            if (typeof dataTable.order === 'function') {
                if (defaults && Array.isArray(defaults.order) && defaults.order.length > 0) {
                    dataTable.order(defaults.order);
                } else {
                    dataTable.order([]);
                }
            }

            if (defaults && typeof dataTable.page.len === 'function') {
                dataTable.page.len(defaults.length);
            }

            if (typeof dataTable.page === 'function') {
                dataTable.page('first');
            }

            if (typeof dataTable.draw === 'function') {
                dataTable.draw(false);
            }
        }

        if (typeof window.jQuery === 'undefined') {
            return;
        }

        window.jQuery(function () {
            ensureReportsTableDefaults('campaigns', 0);
            ensureReportsTableDefaults('activity', 0);

            document.addEventListener('click', function (event) {
                var trigger = event.target.closest('[data-marketing-reset-table]');
                if (!trigger) {
                    return;
                }

                event.preventDefault();
                resetReportsTable(trigger.getAttribute('data-marketing-reset-table'));
            });
        });
    }(window, document));
</script>
</body>
</html>
