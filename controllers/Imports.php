<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Imports extends AdminController
{
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

        $data['title']       = _l('marketing_imports');
        $data['imports']     = $this->marketing_imports_model->get_recent(10);
        $data['can_import']  = staff_can('import', 'marketing');
        $data['can_delete']  = staff_can('delete', 'marketing');
        $data['total_count'] = $this->marketing_imports_model->count_all();
        $data['lists']       = $this->marketing_audiences_model->get_all_lists();

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
        $extension        = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));

        if ($extension !== 'csv') {
            set_alert('danger', _l('marketing_import_file_invalid'));
            redirect(admin_url('marketing/imports'));
        }

        $mimeType = null;
        if (function_exists('finfo_open')) {
            $finfo    = finfo_open(FILEINFO_MIME_TYPE);
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

        if ($_FILES['import_file']['size'] > (5 * 1024 * 1024)) {
            set_alert('danger', _l('marketing_import_file_too_large'));
            redirect(admin_url('marketing/imports'));
        }

        $uploadPath = FCPATH . 'uploads/marketing_imports/';
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

        $importId = $this->marketing_imports_model->create_import([
            'list_id'          => $listId,
            'filename'         => $originalFilename,
            'stored_file_name' => $storedFileName,
            'status'           => 'processing',
            'total_rows'       => 0,
            'imported_rows'    => 0,
            'skipped_rows'     => 0,
            'failed_rows'      => 0,
            'mapping_json'     => null,
            'error_log'        => null,
            'uploaded_by'      => get_staff_user_id(),
            'datecreated'      => date('Y-m-d H:i:s'),
        ]);

        if (!$importId) {
            set_alert('danger', _l('marketing_import_failed'));
            redirect(admin_url('marketing/imports'));
        }

        $summary = $this->process_csv_import($storedFilePath, $listId);

        $this->marketing_imports_model->update_import($importId, [
            'total_rows'    => $summary['total_rows'],
            'imported_rows' => $summary['imported_rows'],
            'skipped_rows'  => $summary['skipped_rows'],
            'failed_rows'   => $summary['failed_rows'],
            'error_log'     => $summary['error_log'],
            'status'        => $summary['status'],
            'completed_at'  => date('Y-m-d H:i:s'),
        ]);

        if ($summary['status'] === 'completed') {
            set_alert('success', _l('marketing_import_success'));
        } else {
            set_alert('warning', _l('marketing_import_failed'));
        }

        redirect(admin_url('marketing/imports'));
    }

    protected function process_csv_import($filePath, $listId)
    {
        $summary = [
            'total_rows'    => 0,
            'imported_rows' => 0,
            'skipped_rows'  => 0,
            'failed_rows'   => 0,
            'error_log'     => '',
            'status'        => 'completed',
        ];

        if (!file_exists($filePath)) {
            $summary['status']    = 'failed';
            $summary['error_log'] = 'Import file not found.';
            return $summary;
        }

        $handle = fopen($filePath, 'r');

        if ($handle === false) {
            $summary['status']    = 'failed';
            $summary['error_log'] = 'Unable to open import file.';
            return $summary;
        }

        $rawHeaders = fgetcsv($handle);

        if ($rawHeaders === false) {
            fclose($handle);
            $summary['status']    = 'failed';
            $summary['error_log'] = 'CSV file is empty or invalid.';
            return $summary;
        }

        $headers = array_map([$this, 'normalize_csv_header'], $rawHeaders);

        $aliases = [
            'email' => ['email', 'e mail', 'email address', 'mail', 'contact email'],
            'first_name' => ['first name', 'firstname', 'given name'],
            'last_name' => ['last name', 'lastname', 'surname', 'family name'],
            'full_name' => ['full name', 'name', 'contact name'],
            'phone' => ['phone', 'phone number', 'mobile', 'telephone'],
            'company' => ['company', 'company name', 'organization', 'business'],
        ];

        $headerIndex = [];

        foreach ($headers as $index => $header) {
            foreach ($aliases as $field => $acceptedHeaders) {
                if (($header === $field || in_array($header, $acceptedHeaders, true)) && !isset($headerIndex[$field])) {
                    $headerIndex[$field] = $index;
                }
            }
        }

        if (!isset($headerIndex['email'])) {
            fclose($handle);
            $summary['status']    = 'failed';
            $summary['error_log'] = 'CSV must contain an email column. Accepted headers include: email, email address, e-mail.';
            return $summary;
        }

        $rowNumber = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;

            if ($this->csv_row_is_blank($row)) {
                continue;
            }

            $summary['total_rows']++;

            if (strlen(implode('', $row)) > 100000) {
                $summary['skipped_rows']++;
                $summary['error_log'] .= "Row {$rowNumber}: Row is too large.\n";
                continue;
            }

            $email           = trim($this->csv_value($row, $headerIndex, 'email'));
            $normalizedEmail = mb_strtolower($email);

            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $summary['failed_rows']++;
                $summary['error_log'] .= "Row {$rowNumber}: Invalid email.\n";
                continue;
            }

            if ($this->marketing_audiences_model->is_contact_suppressed($normalizedEmail)) {
                $summary['skipped_rows']++;
                $summary['error_log'] .= "Row {$rowNumber}: Contact suppressed.\n";
                continue;
            }

            $contactId = $this->marketing_audiences_model->upsert_contact([
                'email'       => $email,
                'first_name'  => $this->csv_value($row, $headerIndex, 'first_name'),
                'last_name'   => $this->csv_value($row, $headerIndex, 'last_name'),
                'full_name'   => $this->csv_value($row, $headerIndex, 'full_name'),
                'phone'       => $this->csv_value($row, $headerIndex, 'phone'),
                'company'     => $this->csv_value($row, $headerIndex, 'company'),
                'source_type' => 'csv',
            ]);

            if (!$contactId) {
                $summary['failed_rows']++;
                $summary['error_log'] .= "Row {$rowNumber}: Failed to create or update contact.\n";
                continue;
            }

            if (!$this->marketing_audiences_model->add_contact_to_list($listId, $contactId)) {
                $summary['skipped_rows']++;
                $summary['error_log'] .= "Row {$rowNumber}: Contact already exists in audience or could not be added.\n";
                continue;
            }

            $summary['imported_rows']++;
        }

        fclose($handle);

        if ($summary['failed_rows'] > 0) {
            $summary['status'] = 'failed';
        }

        return $summary;
    }

    protected function normalize_csv_header($header)
    {
        $header = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header);
        $header = trim($header);
        $header = mb_strtolower($header);
        $header = str_replace(['_', '-'], ' ', $header);
        $header = preg_replace('/\s+/', ' ', $header);

        return trim($header);
    }

    protected function csv_row_is_blank($row)
    {
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    protected function csv_value($row, $headerIndex, $field)
    {
        if (!isset($headerIndex[$field])) {
            return null;
        }

        $index = $headerIndex[$field];

        return isset($row[$index]) ? trim($row[$index]) : null;
    }
}
