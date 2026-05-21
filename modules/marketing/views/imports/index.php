<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php
$statusLabel = static function ($status) {
    $status = trim((string) $status);
    $map = [
        'queued' => _l('marketing_import_status_queued'),
        'processing' => _l('marketing_import_status_processing'),
        'completed' => _l('marketing_import_status_completed'),
        'completed_with_errors' => _l('marketing_import_status_completed_with_errors'),
        'failed' => _l('marketing_import_status_failed'),
        'uploaded' => _l('marketing_import_status_uploaded'),
    ];

    return isset($map[$status]) ? $map[$status] : ucfirst($status !== '' ? $status : 'queued');
};

$statusClass = static function ($status) {
    $status = trim((string) $status);
    $map = [
        'queued' => 'warning',
        'processing' => 'info',
        'completed' => 'success',
        'completed_with_errors' => 'warning',
        'failed' => 'danger',
        'uploaded' => 'warning',
    ];

    return isset($map[$status]) ? $map[$status] : 'default';
};

$jobTypeLabel = static function ($jobType) {
    $jobType = trim((string) $jobType);

    if ($jobType === 'retry_failed_rows') {
        return _l('marketing_import_job_type_retry');
    }

    return _l('marketing_import_job_type_csv');
};

$retryStatusLabel = static function ($status) {
    $status = trim((string) $status);
    $map = [
        'open' => _l('marketing_import_retry_status_open'),
        'queued' => _l('marketing_import_retry_status_queued'),
        'resolved' => _l('marketing_import_retry_status_resolved'),
        'failed_retry' => _l('marketing_import_retry_status_failed_retry'),
    ];

    return isset($map[$status]) ? $map[$status] : ucfirst($status !== '' ? $status : 'open');
};

$formatBytes = static function ($bytes) {
    $bytes = (float) $bytes;
    if ($bytes <= 0) {
        return '0 B';
    }

    $units = ['B', 'KB', 'MB', 'GB'];
    $power = (int) floor(log($bytes, 1024));
    $power = max(0, min($power, count($units) - 1));
    $value = $bytes / pow(1024, $power);

    return number_format($value, $power === 0 ? 0 : 1) . ' ' . $units[$power];
};
?>
<?php init_head(); ?>
<style>
    .marketing-import-shell {
        display: grid;
        gap: 20px;
    }
    .marketing-import-summary-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 12px;
        margin-bottom: 18px;
    }
    .marketing-import-summary-card {
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        padding: 14px 16px;
        background: #f8fafc;
    }
    .marketing-import-summary-label {
        display: block;
        color: #64748b;
        font-size: 12px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }
    .marketing-import-summary-value {
        display: block;
        margin-top: 6px;
        font-size: 22px;
        font-weight: 700;
        color: #0f172a;
    }
    .marketing-import-note {
        margin: 0 0 18px;
    }
    .marketing-import-table td,
    .marketing-import-table th {
        vertical-align: top !important;
    }
    .marketing-import-progress {
        min-width: 180px;
    }
    .marketing-import-progress-bar {
        height: 8px;
        border-radius: 999px;
        background: #e2e8f0;
        overflow: hidden;
        margin-bottom: 6px;
    }
    .marketing-import-progress-bar > span {
        display: block;
        height: 100%;
        background: linear-gradient(90deg, #1d4ed8, #38bdf8);
    }
    .marketing-import-meta {
        font-size: 12px;
        color: #64748b;
        line-height: 1.5;
    }
    .marketing-import-pill {
        display: inline-flex;
        align-items: center;
        padding: 5px 10px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 600;
    }
    .marketing-import-pill-success {
        background: #dcfce7;
        color: #166534;
    }
    .marketing-import-pill-info {
        background: #dbeafe;
        color: #1d4ed8;
    }
    .marketing-import-pill-warning {
        background: #fef3c7;
        color: #92400e;
    }
    .marketing-import-pill-danger {
        background: #fee2e2;
        color: #b91c1c;
    }
    .marketing-import-details-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 12px;
        margin-bottom: 18px;
    }
    .marketing-import-detail-card {
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 12px 14px;
        background: #fff;
    }
    .marketing-import-detail-card strong {
        display: block;
        color: #64748b;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        margin-bottom: 4px;
    }
    .marketing-import-detail-card span {
        color: #0f172a;
        font-weight: 600;
    }
    .marketing-import-failure-preview {
        margin: 0;
        white-space: pre-wrap;
        font-size: 12px;
        color: #334155;
    }
    .marketing-import-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }
    @media (max-width: 1199px) {
        .marketing-import-summary-grid,
        .marketing-import-details-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }
    @media (max-width: 767px) {
        .marketing-import-summary-grid,
        .marketing-import-details-grid {
            grid-template-columns: 1fr;
        }
        .marketing-import-actions .btn {
            width: 100%;
        }
    }
</style>
<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-12">
                <div class="marketing-import-shell">
                    <?php if ($can_import) { ?>
                        <?php $this->load->view('marketing/imports/upload', ['lists' => $lists]); ?>
                    <?php } ?>

                    <div class="panel_s" data-marketing-import-queue="1">
                        <div class="panel-body">
                            <h4 class="no-margin"><?= _l('marketing_imports'); ?></h4>
                            <p class="text-muted mtop10"><?= _l('marketing_imports_placeholder'); ?></p>
                            <hr />

                            <div class="marketing-import-summary-grid">
                                <div class="marketing-import-summary-card">
                                    <span class="marketing-import-summary-label"><?= _l('marketing_total_imports'); ?></span>
                                    <span class="marketing-import-summary-value"><?= html_escape($total_count); ?></span>
                                </div>
                                <div class="marketing-import-summary-card">
                                    <span class="marketing-import-summary-label"><?= _l('marketing_import_status_queued'); ?></span>
                                    <span class="marketing-import-summary-value"><?= html_escape(count(array_filter($imports, function ($import) { return in_array((string) ($import['status'] ?? ''), ['queued', 'uploaded'], true); }))); ?></span>
                                </div>
                                <div class="marketing-import-summary-card">
                                    <span class="marketing-import-summary-label"><?= _l('marketing_import_status_processing'); ?></span>
                                    <span class="marketing-import-summary-value"><?= html_escape(count(array_filter($imports, function ($import) { return (string) ($import['status'] ?? '') === 'processing'; }))); ?></span>
                                </div>
                                <div class="marketing-import-summary-card">
                                    <span class="marketing-import-summary-label"><?= _l('marketing_import_status_completed'); ?></span>
                                    <span class="marketing-import-summary-value"><?= html_escape(count(array_filter($imports, function ($import) { return in_array((string) ($import['status'] ?? ''), ['completed', 'completed_with_errors'], true); }))); ?></span>
                                </div>
                            </div>

                            <p class="text-muted marketing-import-note"><?= _l('marketing_import_refresh_note'); ?></p>

                            <?php if (empty($imports)) { ?>
                                <p class="text-muted no-margin"><?= _l('marketing_no_records_yet'); ?></p>
                            <?php } else { ?>
                                <div class="table-responsive" data-marketing-import-jobs="1">
                                    <table class="table marketing-import-table">
                                        <thead>
                                            <tr>
                                                <th><?= _l('id'); ?></th>
                                                <th><?= _l('marketing_filename'); ?></th>
                                                <th><?= _l('marketing_import_target_audience'); ?></th>
                                                <th><?= _l('marketing_status'); ?></th>
                                                <th><?= _l('marketing_import_progress'); ?></th>
                                                <th><?= _l('marketing_imported_rows'); ?></th>
                                                <th><?= _l('marketing_skipped_rows'); ?></th>
                                                <th><?= _l('marketing_failed_rows'); ?></th>
                                                <th><?= _l('marketing_updated_at'); ?></th>
                                                <th><?= _l('options'); ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($imports as $import) { ?>
                                                <?php
                                                $totalRows = (int) ($import['total_rows'] ?? 0);
                                                $processedRows = (int) ($import['processed_rows'] ?? 0);
                                                $progressPercent = $totalRows > 0 ? max(0, min(100, (int) round(($processedRows / max(1, $totalRows)) * 100))) : null;
                                                ?>
                                                <tr>
                                                    <td><?= html_escape($import['id']); ?></td>
                                                    <td>
                                                        <strong><?= html_escape($import['filename']); ?></strong>
                                                        <div class="marketing-import-meta">
                                                            <?= html_escape($jobTypeLabel($import['job_type'] ?? '')); ?><?php if (!empty($import['source_import_id'])) { ?> · <?= _l('marketing_import_source_import'); ?> #<?= html_escape($import['source_import_id']); ?><?php } ?>
                                                        </div>
                                                    </td>
                                                    <td><?= html_escape(!empty($import['list_name']) ? $import['list_name'] : '—'); ?></td>
                                                    <td>
                                                        <span class="marketing-import-pill marketing-import-pill-<?= html_escape($statusClass($import['status'] ?? 'queued')); ?>">
                                                            <?= html_escape($statusLabel($import['status'] ?? 'queued')); ?>
                                                        </span>
                                                    </td>
                                                    <td class="marketing-import-progress">
                                                        <?php if ($progressPercent !== null) { ?>
                                                            <div class="marketing-import-progress-bar">
                                                                <span style="width: <?= html_escape($progressPercent); ?>%;"></span>
                                                            </div>
                                                            <div class="marketing-import-meta">
                                                                <?= html_escape($processedRows); ?> / <?= html_escape($totalRows); ?> · <?= html_escape($progressPercent); ?>%
                                                            </div>
                                                        <?php } else { ?>
                                                            <div class="marketing-import-meta"><?= _l('marketing_import_progress_unknown'); ?></div>
                                                        <?php } ?>
                                                    </td>
                                                    <td><?= html_escape($import['imported_rows']); ?></td>
                                                    <td><?= html_escape($import['skipped_rows']); ?></td>
                                                    <td><?= html_escape($import['failed_rows']); ?></td>
                                                    <td><?= html_escape(!empty($import['dateupdated']) ? $import['dateupdated'] : '—'); ?></td>
                                                    <td>
                                                        <div class="marketing-import-actions">
                                                            <a href="<?= admin_url('marketing/imports?view_import_id=' . (int) $import['id']); ?>" class="btn btn-default btn-sm">
                                                                <?= _l('marketing_import_view_failed_rows'); ?>
                                                            </a>
                                                            <?php if ($can_import && (string) ($import['status'] ?? '') === 'failed') { ?>
                                                                <?= form_open(admin_url('marketing/imports/requeue/' . (int) $import['id']), ['class' => 'inline-block']); ?>
                                                                    <button type="submit" class="btn btn-warning btn-sm">
                                                                        <?= _l('marketing_import_requeue'); ?>
                                                                    </button>
                                                                <?= form_close(); ?>
                                                            <?php } ?>
                                                            <?php if ($can_import && (int) ($import['failed_rows'] ?? 0) > 0 && in_array((string) ($import['status'] ?? ''), ['completed_with_errors', 'failed'], true)) { ?>
                                                                <?= form_open(admin_url('marketing/imports/retry/' . (int) $import['id']), ['class' => 'inline-block']); ?>
                                                                    <button type="submit" class="btn btn-primary btn-sm">
                                                                        <?= _l('marketing_import_retry_failed_rows'); ?>
                                                                    </button>
                                                                <?= form_close(); ?>
                                                            <?php } ?>
                                                            <?php if ($can_delete) { ?>
                                                                <?= form_open(admin_url('marketing/imports'), ['class' => 'inline-block']); ?>
                                                                    <input type="hidden" name="delete_import_id" value="<?= html_escape($import['id']); ?>">
                                                                    <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('<?= html_escape(_l('confirm_action_prompt')); ?>');">
                                                                        <?= _l('delete'); ?>
                                                                    </button>
                                                                <?= form_close(); ?>
                                                            <?php } ?>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php } ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php } ?>
                        </div>
                    </div>

                    <?php if (!empty($selected_import)) { ?>
                        <?php
                        $selectedTotalRows = (int) ($selected_import['total_rows'] ?? 0);
                        $selectedProcessedRows = (int) ($selected_import['processed_rows'] ?? 0);
                        $selectedProgress = $selectedTotalRows > 0 ? max(0, min(100, (int) round(($selectedProcessedRows / max(1, $selectedTotalRows)) * 100))) : null;
                        ?>
                        <div class="panel_s" data-marketing-import-failures="1">
                            <div class="panel-body">
                                <div class="pull-right">
                                    <span class="marketing-import-pill marketing-import-pill-<?= html_escape($statusClass($selected_import['status'] ?? 'queued')); ?>">
                                        <?= html_escape($statusLabel($selected_import['status'] ?? 'queued')); ?>
                                    </span>
                                </div>
                                <h4 class="no-margin"><?= _l('marketing_import_failed_rows_heading'); ?></h4>
                                <p class="text-muted mtop10"><?= _l('marketing_import_failed_rows_note'); ?></p>
                                <hr />

                                <div class="marketing-import-details-grid">
                                    <div class="marketing-import-detail-card">
                                        <strong><?= _l('marketing_filename'); ?></strong>
                                        <span><?= html_escape($selected_import['filename']); ?></span>
                                    </div>
                                    <div class="marketing-import-detail-card">
                                        <strong><?= _l('marketing_import_target_audience'); ?></strong>
                                        <span><?= html_escape(!empty($selected_import['list_name']) ? $selected_import['list_name'] : '—'); ?></span>
                                    </div>
                                    <div class="marketing-import-detail-card">
                                        <strong><?= _l('marketing_import_file_size'); ?></strong>
                                        <span><?= html_escape($formatBytes((int) ($selected_import['file_size_bytes'] ?? 0))); ?></span>
                                    </div>
                                    <div class="marketing-import-detail-card">
                                        <strong><?= _l('marketing_import_job_type'); ?></strong>
                                        <span><?= html_escape($jobTypeLabel($selected_import['job_type'] ?? '')); ?></span>
                                    </div>
                                    <div class="marketing-import-detail-card">
                                        <strong><?= _l('marketing_total_rows'); ?></strong>
                                        <span><?= html_escape($selected_import['total_rows']); ?></span>
                                    </div>
                                    <div class="marketing-import-detail-card">
                                        <strong><?= _l('marketing_processed_rows'); ?></strong>
                                        <span><?= html_escape($selected_import['processed_rows']); ?></span>
                                    </div>
                                    <div class="marketing-import-detail-card">
                                        <strong><?= _l('marketing_import_progress'); ?></strong>
                                        <span><?= $selectedProgress !== null ? html_escape($selectedProgress . '%') : _l('marketing_import_progress_unknown'); ?></span>
                                    </div>
                                    <div class="marketing-import-detail-card">
                                        <strong><?= _l('marketing_import_current_offset'); ?></strong>
                                        <span><?= html_escape($selected_import['current_offset']); ?></span>
                                    </div>
                                    <div class="marketing-import-detail-card">
                                        <strong><?= _l('marketing_created_at'); ?></strong>
                                        <span><?= html_escape(!empty($selected_import['datecreated']) ? $selected_import['datecreated'] : '—'); ?></span>
                                    </div>
                                    <div class="marketing-import-detail-card">
                                        <strong><?= _l('marketing_updated_at'); ?></strong>
                                        <span><?= html_escape(!empty($selected_import['dateupdated']) ? $selected_import['dateupdated'] : '—'); ?></span>
                                    </div>
                                    <div class="marketing-import-detail-card">
                                        <strong><?= _l('marketing_completed_at'); ?></strong>
                                        <span><?= html_escape(!empty($selected_import['completed_at']) ? $selected_import['completed_at'] : '—'); ?></span>
                                    </div>
                                    <div class="marketing-import-detail-card">
                                        <strong><?= _l('marketing_import_last_error_summary'); ?></strong>
                                        <span><?= html_escape(!empty($selected_import['last_error_summary']) ? $selected_import['last_error_summary'] : _l('marketing_import_error_summary_none')); ?></span>
                                    </div>
                                </div>

                                <?php if ($can_import && ((string) ($selected_import['status'] ?? '') === 'failed' || ((int) ($selected_import['failed_rows'] ?? 0) > 0 && (string) ($selected_import['status'] ?? '') === 'completed_with_errors'))) { ?>
                                    <div class="marketing-import-actions mbot15">
                                        <?php if ((string) ($selected_import['status'] ?? '') === 'failed') { ?>
                                            <?= form_open(admin_url('marketing/imports/requeue/' . (int) $selected_import['id']), ['class' => 'inline-block']); ?>
                                                <button type="submit" class="btn btn-warning">
                                                    <?= _l('marketing_import_requeue'); ?>
                                                </button>
                                            <?= form_close(); ?>
                                        <?php } ?>
                                        <?php if ((int) ($selected_import['failed_rows'] ?? 0) > 0) { ?>
                                            <?= form_open(admin_url('marketing/imports/retry/' . (int) $selected_import['id']), ['class' => 'inline-block']); ?>
                                                <button type="submit" class="btn btn-primary">
                                                    <?= _l('marketing_import_retry_failed_rows'); ?>
                                                </button>
                                            <?= form_close(); ?>
                                        <?php } ?>
                                    </div>
                                <?php } ?>

                                <p class="text-muted"><?= _l('marketing_import_failed_rows_limit_note'); ?></p>

                                <?php if (empty($selected_failures)) { ?>
                                    <p class="text-muted no-margin"><?= _l('marketing_import_no_failed_rows'); ?></p>
                                <?php } else { ?>
                                    <div class="table-responsive">
                                        <table class="table">
                                            <thead>
                                                <tr>
                                                    <th><?= _l('marketing_import_row_number'); ?></th>
                                                    <th><?= _l('marketing_import_failure_reason'); ?></th>
                                                    <th><?= _l('marketing_import_retry_status'); ?></th>
                                                    <th><?= _l('marketing_import_row_excerpt'); ?></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($selected_failures as $failure) { ?>
                                                    <?php $excerpt = json_decode((string) ($failure['row_excerpt_json'] ?? ''), true); ?>
                                                    <tr>
                                                        <td><?= html_escape($failure['row_number']); ?></td>
                                                        <td><?= html_escape($failure['failure_reason']); ?></td>
                                                        <td><?= html_escape($retryStatusLabel($failure['retry_status'] ?? 'open')); ?></td>
                                                        <td>
                                                            <?php if (!empty($excerpt) && is_array($excerpt)) { ?>
                                                                <pre class="marketing-import-failure-preview"><?= html_escape(json_encode($excerpt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
                                                            <?php } else { ?>
                                                                <span class="text-muted">—</span>
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
                    <?php } ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php init_tail(); ?>
</body>
</html>
