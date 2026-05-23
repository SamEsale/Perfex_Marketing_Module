<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Imports extends AdminController
{
    const MAX_UPLOAD_SIZE_BYTES = 5242880;

    public function __construct()
    {
        parent::__construct();

        if (staff_cant('view', 'marketing') && staff_cant('import', 'marketing')) {
            access_denied('marketing');
        }

        $this->load->model('marketing/Marketing_imports_model', 'marketing_imports_model');
        $this->load->model('marketing/Marketing_audiences_model', 'marketing_audiences_model');
        $this->load->library('form_validation');
    }

    public function index()
    {
        if ($this->input->post('delete_import_id')) {
            if (staff_cant('delete', 'marketing')) {
                access_denied('marketing');
            }

            $importId = (int) $this->input->post('delete_import_id');

            if ($importId > 0 && $this->marketing_imports_model->delete_import($importId)) {
                set_alert('success', _l('marketing_import_deleted'));
            } else {
                set_alert('warning', _l('marketing_import_delete_failure'));
            }

            redirect(admin_url('marketing/imports'));
        }

        $selectedImportId = (int) $this->input->get('view_import_id');
        $selectedImport = $selectedImportId > 0 ? $this->marketing_imports_model->get_import($selectedImportId) : null;

        $data['title'] = _l('marketing_imports');
        $data['imports'] = $this->marketing_imports_model->get_recent(25);
        $data['can_import'] = staff_can('import', 'marketing');
        $data['can_delete'] = staff_can('delete', 'marketing');
        $data['total_count'] = $this->marketing_imports_model->count_all();
        $data['lists'] = $this->marketing_audiences_model->get_all_lists();
        $data['selected_import'] = $selectedImport;
        $data['selected_failures'] = $selectedImport ? $this->marketing_imports_model->get_failures((int) $selectedImport['id'], 200) : [];

        $this->load->view('marketing/imports/index', $data);
    }

    public function delete($id)
    {
        if (staff_cant('delete', 'marketing')) {
            access_denied('marketing');
        }

        if (!$this->input->post()) {
            show_404();
        }

        if (!$this->marketing_imports_model->get_import($id)) {
            show_404();
        }

        if ($this->marketing_imports_model->delete_import($id)) {
            set_alert('success', _l('marketing_import_deleted'));
        } else {
            set_alert('warning', _l('marketing_import_delete_failure'));
        }

        redirect(admin_url('marketing/imports'));
    }

    public function upload()
    {
        if (staff_cant('import', 'marketing')) {
            access_denied('marketing');
        }

        if (!$this->input->post()) {
            redirect(admin_url('marketing/imports'));
        }

        $listId = (int) $this->input->post('list_id');

        if (!$this->marketing_audiences_model->list_exists($listId)) {
            set_alert('danger', _l('marketing_import_list_required'));
            redirect(admin_url('marketing/imports'));
        }

        if (empty($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            set_alert('danger', _l('marketing_import_file_required'));
            redirect(admin_url('marketing/imports'));
        }

        $originalFilename = basename($_FILES['import_file']['name']);
        $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));

        if ($extension !== 'csv') {
            set_alert('danger', _l('marketing_import_file_invalid'));
            redirect(admin_url('marketing/imports'));
        }

        $mimeType = null;
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $_FILES['import_file']['tmp_name']);
            finfo_close($finfo);
        } elseif (function_exists('mime_content_type')) {
            $mimeType = mime_content_type($_FILES['import_file']['tmp_name']);
        }

        $allowedMimeTypes = [
            'text/plain',
            'text/csv',
            'application/csv',
            'text/comma-separated-values',
            'application/vnd.ms-excel',
        ];

        if ($mimeType !== null && !in_array($mimeType, $allowedMimeTypes, true)) {
            set_alert('danger', _l('marketing_import_file_invalid'));
            redirect(admin_url('marketing/imports'));
        }

        if ((int) $_FILES['import_file']['size'] > self::MAX_UPLOAD_SIZE_BYTES) {
            set_alert('danger', _l('marketing_import_file_too_large'));
            redirect(admin_url('marketing/imports'));
        }

        $uploadPath = $this->marketing_imports_model->get_storage_directory();
        if (!is_dir($uploadPath) && !mkdir($uploadPath, 0755, true)) {
            set_alert('danger', _l('marketing_import_file_move_failed'));
            redirect(admin_url('marketing/imports'));
        }

        $storedFileName = uniqid('marketing_import_', true) . '.csv';
        $storedFilePath = $uploadPath . $storedFileName;

        if (!is_uploaded_file($_FILES['import_file']['tmp_name']) || !move_uploaded_file($_FILES['import_file']['tmp_name'], $storedFilePath)) {
            set_alert('danger', _l('marketing_import_file_move_failed'));
            redirect(admin_url('marketing/imports'));
        }

        $inspection = $this->marketing_imports_model->inspect_csv_file($storedFilePath);
        if (empty($inspection['ok'])) {
            @unlink($storedFilePath);
            set_alert('danger', !empty($inspection['error_summary']) ? $inspection['error_summary'] : _l('marketing_import_failed'));
            redirect(admin_url('marketing/imports'));
        }

        $importId = $this->marketing_imports_model->create_import([
            'list_id' => $listId,
            'filename' => $originalFilename,
            'stored_file_name' => $storedFileName,
            'stored_file_path' => $storedFilePath,
            'file_size_bytes' => (int) $_FILES['import_file']['size'],
            'job_type' => Marketing_imports_model::JOB_TYPE_IMPORT,
            'source_import_id' => null,
            'status' => Marketing_imports_model::STATUS_QUEUED,
            'total_rows' => (int) ($inspection['total_rows'] ?? 0),
            'processed_rows' => 0,
            'imported_rows' => 0,
            'skipped_rows' => 0,
            'failed_rows' => 0,
            'current_offset' => 2,
            'mapping_json' => !empty($inspection['mapping']) ? json_encode($inspection['mapping']) : null,
            'error_log' => null,
            'last_error_summary' => null,
            'uploaded_by' => get_staff_user_id(),
            'lease_token' => null,
            'lease_started_at' => null,
            'lease_heartbeat_at' => null,
            'processing_started_at' => null,
            'completed_at' => null,
        ]);

        if ($importId <= 0) {
            @unlink($storedFilePath);
            set_alert('danger', _l('marketing_import_failed'));
            redirect(admin_url('marketing/imports'));
        }

        set_alert('success', sprintf(_l('marketing_import_queued'), (int) ($inspection['total_rows'] ?? 0)));

        redirect(admin_url('marketing/imports?view_import_id=' . $importId));
    }

    public function retry($id)
    {
        if (staff_cant('import', 'marketing')) {
            access_denied('marketing');
        }

        if (!$this->input->post()) {
            show_404();
        }

        $result = $this->marketing_imports_model->queue_failed_rows_retry((int) $id, get_staff_user_id());

        if (!empty($result['success'])) {
            set_alert('success', sprintf(_l('marketing_import_retry_queued'), (int) ($result['row_count'] ?? 0)));
            redirect(admin_url('marketing/imports?view_import_id=' . (int) ($result['import_id'] ?? 0)));
        }

        set_alert('warning', !empty($result['message']) ? $result['message'] : _l('marketing_import_retry_unavailable'));

        redirect(admin_url('marketing/imports?view_import_id=' . (int) $id));
    }

    public function requeue($id)
    {
        if (staff_cant('import', 'marketing')) {
            access_denied('marketing');
        }

        if (!$this->input->post()) {
            show_404();
        }

        $result = $this->marketing_imports_model->requeue_failed_import((int) $id, get_staff_user_id());

        if (!empty($result['success'])) {
            set_alert('success', _l('marketing_import_requeued'));
        } else {
            set_alert('warning', !empty($result['message']) ? $result['message'] : _l('marketing_import_requeue_unavailable'));
        }

        redirect(admin_url('marketing/imports?view_import_id=' . (int) $id));
    }
}
