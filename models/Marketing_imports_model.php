<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Marketing_imports_model extends App_Model
{
    const TABLE = 'marketing_imports';
    const FAILURES_TABLE = 'marketing_import_failures';

    const STATUS_QUEUED = 'queued';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_COMPLETED_WITH_ERRORS = 'completed_with_errors';
    const STATUS_FAILED = 'failed';

    const JOB_TYPE_IMPORT = 'csv_import';
    const JOB_TYPE_RETRY = 'retry_failed_rows';

    const DEFAULT_BATCH_SIZE = 500;
    const MAX_BATCH_SIZE = 1000;
    const DEFAULT_MAX_RUNTIME_SECONDS = 20;
    const DEFAULT_STALE_LOCK_SECONDS = 900;
    const PROGRESS_CHECKPOINT_SIZE = 25;
    const MAX_ERROR_LOG_LENGTH = 4000;
    const MAX_FAILURE_REASON_LENGTH = 255;
    const MAX_ROW_SIZE_BYTES = 100000;

    protected $table;
    protected $failuresTable;
    protected $listsTable;
    protected $ciInstance;
    protected $audiencesModelLoaded = false;

    public function __construct()
    {
        parent::__construct();

        $this->table = db_prefix() . self::TABLE;
        $this->failuresTable = db_prefix() . self::FAILURES_TABLE;
        $this->listsTable = db_prefix() . 'marketing_lists';
    }

    public function count_all()
    {
        return $this->db->count_all($this->table);
    }

    public function get_queue_diagnostics_summary($staleLockSeconds = null)
    {
        $summary = [
            'available' => false,
            'queued_count' => 0,
            'processing_count' => 0,
            'failed_count' => 0,
            'completed_count' => 0,
            'oldest_queued_at' => null,
            'oldest_queued_age_minutes' => null,
            'stale_processing_count' => 0,
            'stale_lock_seconds' => $this->clamp_stale_lock_seconds($staleLockSeconds !== null ? $staleLockSeconds : get_option('marketing_import_stale_lock_seconds')),
        ];

        if (!$this->db->table_exists($this->table) || !$this->db->field_exists('status', $this->table)) {
            return $summary;
        }

        $summary['available'] = true;

        $statusRows = $this->db
            ->select('status, COUNT(*) AS total', false)
            ->from($this->table)
            ->group_by('status')
            ->get()
            ->result_array();

        foreach ($statusRows as $row) {
            $status = trim((string) ($row['status'] ?? ''));
            $count = (int) ($row['total'] ?? 0);

            if ($status === self::STATUS_QUEUED) {
                $summary['queued_count'] = $count;
            } elseif ($status === self::STATUS_PROCESSING) {
                $summary['processing_count'] = $count;
            } elseif ($status === self::STATUS_FAILED) {
                $summary['failed_count'] = $count;
            } elseif (in_array($status, [self::STATUS_COMPLETED, self::STATUS_COMPLETED_WITH_ERRORS], true)) {
                $summary['completed_count'] += $count;
            }
        }

        if ($this->db->field_exists('datecreated', $this->table)) {
            $oldestQueued = $this->db
                ->select('datecreated')
                ->from($this->table)
                ->where('status', self::STATUS_QUEUED)
                ->order_by('datecreated', 'ASC')
                ->limit(1)
                ->get()
                ->row_array();

            $oldestQueuedAt = trim((string) ($oldestQueued['datecreated'] ?? ''));
            if ($oldestQueuedAt !== '') {
                $summary['oldest_queued_at'] = $oldestQueuedAt;
                $timestamp = strtotime($oldestQueuedAt);
                if ($timestamp !== false) {
                    $summary['oldest_queued_age_minutes'] = max(0, (int) floor((time() - $timestamp) / 60));
                }
            }
        }

        if ($this->db->field_exists('lease_heartbeat_at', $this->table)) {
            $staleCutoff = date('Y-m-d H:i:s', time() - max(60, (int) $summary['stale_lock_seconds']));
            $summary['stale_processing_count'] = (int) $this->db
                ->from($this->table)
                ->where('status', self::STATUS_PROCESSING)
                ->group_start()
                    ->where('lease_heartbeat_at IS NULL', null, false)
                    ->or_where('lease_heartbeat_at <=', $staleCutoff)
                ->group_end()
                ->count_all_results();
        }

        return $summary;
    }

    public function exists($id)
    {
        return total_rows($this->table, ['id' => (int) $id]) > 0;
    }

    public function get_recent($limit = 10)
    {
        return $this->db
            ->select('i.*, l.name AS list_name')
            ->from($this->table . ' i')
            ->join($this->listsTable . ' l', 'l.id = i.list_id', 'left')
            ->order_by('i.id', 'DESC')
            ->limit((int) $limit)
            ->get()
            ->result_array();
    }

    public function create_import($data)
    {
        $now = date('Y-m-d H:i:s');

        if (!isset($data['datecreated'])) {
            $data['datecreated'] = $now;
        }

        if (!isset($data['dateupdated'])) {
            $data['dateupdated'] = $now;
        }

        $this->db->insert($this->table, $data);

        return (int) $this->db->insert_id();
    }

    public function update_import($id, $data)
    {
        $data['dateupdated'] = isset($data['dateupdated']) ? $data['dateupdated'] : date('Y-m-d H:i:s');

        $this->db->where('id', (int) $id);

        return (bool) $this->db->update($this->table, $data);
    }

    public function get_import($id)
    {
        return $this->db
            ->select('i.*, l.name AS list_name')
            ->from($this->table . ' i')
            ->join($this->listsTable . ' l', 'l.id = i.list_id', 'left')
            ->where('i.id', (int) $id)
            ->limit(1)
            ->get()
            ->row_array();
    }

    public function get_failures($importId, $limit = 100)
    {
        return $this->db
            ->from($this->failuresTable)
            ->where('import_id', (int) $importId)
            ->order_by('row_number', 'ASC')
            ->order_by('id', 'ASC')
            ->limit((int) $limit)
            ->get()
            ->result_array();
    }

    public function count_failures($importId)
    {
        return total_rows($this->failuresTable, ['import_id' => (int) $importId]);
    }

    public function delete_import($id)
    {
        $importId = (int) $id;

        $this->db->where('import_id', $importId);
        $this->db->delete($this->failuresTable);

        $this->db->where('id', $importId);
        $this->db->delete($this->table);

        return $this->db->affected_rows() > 0;
    }

    public function get_storage_directory()
    {
        return APPPATH . 'cache/marketing_imports/';
    }

    public function inspect_csv_file($filePath)
    {
        $result = [
            'ok' => false,
            'total_rows' => 0,
            'mapping' => null,
            'error_summary' => '',
        ];

        if (!is_file($filePath)) {
            $result['error_summary'] = 'Import file not found.';

            return $result;
        }

        try {
            $file = new SplFileObject($filePath, 'r');
            $file->setFlags(SplFileObject::READ_CSV | SplFileObject::DROP_NEW_LINE);
            $file->setCsvControl(',');
            $file->rewind();

            $rawHeaders = $file->fgetcsv();

            if (!$this->csv_row_exists($rawHeaders) || $this->csv_row_is_blank($rawHeaders)) {
                $result['error_summary'] = 'CSV file is empty or invalid.';

                return $result;
            }

            $headers = array_map([$this, 'normalize_csv_header'], $rawHeaders);
            $headerIndex = $this->build_header_index($headers);

            if (!isset($headerIndex['email'])) {
                $result['error_summary'] = 'CSV must contain an email column. Accepted headers include: email, email address, e-mail.';

                return $result;
            }

            $totalRows = 0;

            while (!$file->eof()) {
                $row = $file->fgetcsv();

                if (!$this->csv_row_exists($row) || $this->csv_row_is_blank($row)) {
                    continue;
                }

                $totalRows++;
            }

            $result['ok'] = true;
            $result['total_rows'] = $totalRows;
            $result['mapping'] = [
                'job_type' => self::JOB_TYPE_IMPORT,
                'header_index' => $headerIndex,
                'headers' => $headers,
            ];
        } catch (Throwable $exception) {
            $result['error_summary'] = marketing_sanitize_log_message($exception->getMessage());
        }

        return $result;
    }

    public function queue_failed_rows_retry($importId, $staffId = null)
    {
        $sourceImport = $this->get_import($importId);

        if (!$sourceImport) {
            return [
                'success' => false,
                'message' => 'Import job not found.',
            ];
        }

        if ($this->resolve_file_path($sourceImport) === null) {
            return [
                'success' => false,
                'message' => 'The stored CSV file for this import is no longer available.',
            ];
        }

        $sourceFailures = $this->db
            ->select('id, row_number')
            ->from($this->failuresTable)
            ->where('import_id', (int) $importId)
            ->where_in('retry_status', ['open', 'failed_retry'])
            ->order_by('row_number', 'ASC')
            ->order_by('id', 'ASC')
            ->get()
            ->result_array();

        if (empty($sourceFailures)) {
            return [
                'success' => false,
                'message' => 'No failed rows are available to retry.',
            ];
        }

        $mapping = $this->decode_mapping($sourceImport['mapping_json']);
        $mapping['job_type'] = self::JOB_TYPE_RETRY;
        $mapping['retry_failure_ids'] = array_values(array_map(function ($row) {
            return (int) $row['id'];
        }, $sourceFailures));

        $importId = $this->create_import([
            'list_id' => (int) ($sourceImport['list_id'] ?? 0),
            'filename' => trim((string) ($sourceImport['filename'] ?? 'Retry Failed Rows')),
            'stored_file_name' => (string) ($sourceImport['stored_file_name'] ?? ''),
            'stored_file_path' => (string) ($sourceImport['stored_file_path'] ?? ''),
            'file_size_bytes' => (int) ($sourceImport['file_size_bytes'] ?? 0),
            'job_type' => self::JOB_TYPE_RETRY,
            'source_import_id' => (int) ($sourceImport['id'] ?? 0),
            'status' => self::STATUS_QUEUED,
            'total_rows' => count($sourceFailures),
            'processed_rows' => 0,
            'imported_rows' => 0,
            'skipped_rows' => 0,
            'failed_rows' => 0,
            'current_offset' => 0,
            'mapping_json' => json_encode($mapping),
            'error_log' => null,
            'last_error_summary' => null,
            'uploaded_by' => $staffId !== null ? (int) $staffId : null,
            'lease_token' => null,
            'lease_started_at' => null,
            'lease_heartbeat_at' => null,
            'processing_started_at' => null,
            'completed_at' => null,
        ]);

        if ($importId <= 0) {
            return [
                'success' => false,
                'message' => 'Unable to queue failed rows retry.',
            ];
        }

        $failureIds = array_values(array_map(function ($row) {
            return (int) $row['id'];
        }, $sourceFailures));

        if (!empty($failureIds)) {
            $this->db->where_in('id', $failureIds);
            $this->db->update($this->failuresTable, [
                'retry_status' => 'queued',
                'retry_import_id' => $importId,
                'dateupdated' => date('Y-m-d H:i:s'),
            ]);
        }

        return [
            'success' => true,
            'import_id' => $importId,
            'row_count' => count($failureIds),
        ];
    }

    public function requeue_failed_import($importId, $staffId = null)
    {
        $import = $this->get_import($importId);

        if (!$import) {
            return [
                'success' => false,
                'message' => 'Import job not found.',
            ];
        }

        if ((string) ($import['status'] ?? '') !== self::STATUS_FAILED) {
            return [
                'success' => false,
                'message' => 'Only failed imports can be requeued.',
            ];
        }

        if ($this->resolve_file_path($import) === null) {
            return [
                'success' => false,
                'message' => 'The stored CSV file for this import is no longer available.',
            ];
        }

        $jobType = $this->get_job_type($import, $this->decode_mapping($import['mapping_json']));
        $resetOffset = $jobType === self::JOB_TYPE_RETRY ? 0 : 2;

        $updated = $this->update_import((int) $import['id'], [
            'status' => self::STATUS_QUEUED,
            'processed_rows' => 0,
            'imported_rows' => 0,
            'skipped_rows' => 0,
            'failed_rows' => 0,
            'current_offset' => $resetOffset,
            'last_error_summary' => null,
            'error_log' => null,
            'lease_token' => null,
            'lease_started_at' => null,
            'lease_heartbeat_at' => null,
            'processing_started_at' => null,
            'completed_at' => null,
            'uploaded_by' => $staffId !== null ? (int) $staffId : (int) ($import['uploaded_by'] ?? 0),
        ]);

        if (!$updated) {
            return [
                'success' => false,
                'message' => 'Failed to requeue import job.',
            ];
        }

        return [
            'success' => true,
            'import_id' => (int) $import['id'],
        ];
    }

    public function process_queued_imports($options = [])
    {
        $batchSize = $this->clamp_batch_size(isset($options['batch_size']) ? $options['batch_size'] : get_option('marketing_import_batch_size'));
        $maxRuntime = $this->clamp_runtime_seconds(isset($options['max_runtime_seconds']) ? $options['max_runtime_seconds'] : get_option('marketing_import_max_runtime_seconds'));
        $staleLockSeconds = $this->clamp_stale_lock_seconds(isset($options['stale_lock_seconds']) ? $options['stale_lock_seconds'] : get_option('marketing_import_stale_lock_seconds'));
        $deadline = microtime(true) + $maxRuntime;

        $summary = [
            'job_found' => false,
            'job_id' => 0,
            'status' => 'idle',
            'processed_rows' => 0,
            'imported_rows' => 0,
            'skipped_rows' => 0,
            'failed_rows' => 0,
            'warnings' => [],
        ];

        $job = $this->claim_next_job($staleLockSeconds);
        if (!$job) {
            return $summary;
        }

        $summary['job_found'] = true;
        $summary['job_id'] = (int) $job['id'];

        try {
            $jobSummary = $this->process_import_job($job, $batchSize, $deadline, $staleLockSeconds);

            return array_merge($summary, $jobSummary);
        } catch (Throwable $exception) {
            $message = marketing_sanitize_log_message($exception->getMessage());
            $this->mark_job_failed($job, $message);

            return array_merge($summary, [
                'status' => self::STATUS_FAILED,
                'warnings' => [$message],
            ]);
        }
    }

    protected function process_import_job(array $job, $batchSize, $deadline, $staleLockSeconds)
    {
        $mapping = $this->decode_mapping($job['mapping_json']);
        $jobType = $this->get_job_type($job, $mapping);

        if ($jobType === self::JOB_TYPE_RETRY) {
            return $this->process_retry_batch($job, $mapping, $batchSize, $deadline);
        }

        return $this->process_csv_batch($job, $mapping, $batchSize, $deadline);
    }

    protected function process_csv_batch(array $job, array $mapping, $batchSize, $deadline)
    {
        $filePath = $this->resolve_file_path($job);
        if ($filePath === null || !is_file($filePath)) {
            $message = 'Import file not found.';
            $this->mark_job_failed($job, $message);

            return [
                'status' => self::STATUS_FAILED,
                'processed_rows' => 0,
                'imported_rows' => 0,
                'skipped_rows' => 0,
                'failed_rows' => 0,
                'warnings' => [$message],
            ];
        }

        $headerIndex = isset($mapping['header_index']) && is_array($mapping['header_index']) ? $mapping['header_index'] : [];
        if (!isset($headerIndex['email'])) {
            $inspection = $this->inspect_csv_file($filePath);
            if (empty($inspection['ok']) || empty($inspection['mapping']['header_index']['email'])) {
                $message = !empty($inspection['error_summary']) ? $inspection['error_summary'] : 'CSV header mapping is invalid.';
                $this->mark_job_failed($job, $message);

                return [
                    'status' => self::STATUS_FAILED,
                    'processed_rows' => 0,
                    'imported_rows' => 0,
                    'skipped_rows' => 0,
                    'failed_rows' => 0,
                    'warnings' => [$message],
                ];
            }

            $mapping = $inspection['mapping'];
            $headerIndex = $mapping['header_index'];
            $this->update_import($job['id'], [
                'mapping_json' => json_encode($mapping),
                'total_rows' => max((int) ($job['total_rows'] ?? 0), (int) ($inspection['total_rows'] ?? 0)),
            ]);
        }

        $counters = $this->initialize_counters($job);
        $file = new SplFileObject($filePath, 'r');
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::DROP_NEW_LINE);
        $file->setCsvControl(',');

        $currentLine = max(2, (int) ($job['current_offset'] ?? 2));
        $file->seek($currentLine - 1);

        $checkpointRows = 0;
        $warnings = [];

        while (!$file->eof() && $counters['batch_processed'] < $batchSize && microtime(true) < $deadline) {
            $row = $file->current();
            $lineNumber = (int) $file->key() + 1;
            $nextLine = $lineNumber + 1;
            $file->next();

            if (!$this->csv_row_exists($row) || $this->csv_row_is_blank($row)) {
                $currentLine = $nextLine;
                continue;
            }

            $counters['processed_rows']++;
            $counters['batch_processed']++;

            $result = $this->process_single_row($job, $row, $lineNumber, $headerIndex, isset($mapping['headers']) && is_array($mapping['headers']) ? $mapping['headers'] : []);

            if ($result['result'] === 'imported') {
                $counters['imported_rows']++;
            } elseif ($result['result'] === 'skipped') {
                $counters['skipped_rows']++;
            } else {
                $counters['failed_rows']++;
            }

            if ($result['message'] !== '') {
                $counters['error_log'] = $this->append_error_log($counters['error_log'], 'Row ' . $lineNumber . ': ' . $result['message']);
                $counters['last_error_summary'] = 'Row ' . $lineNumber . ': ' . $result['message'];
            }

            $currentLine = $nextLine;
            $checkpointRows++;

            if ($checkpointRows >= self::PROGRESS_CHECKPOINT_SIZE || microtime(true) >= $deadline - 1) {
                $this->persist_job_progress($job['id'], $counters, $currentLine, $job['lease_token']);
                $checkpointRows = 0;
            }
        }

        $isFinished = $this->csv_import_is_finished($file, $currentLine);

        return $this->finalize_job_batch($job, $counters, $currentLine, $isFinished, $warnings);
    }

    protected function process_retry_batch(array $job, array $mapping, $batchSize, $deadline)
    {
        $retryFailureIds = isset($mapping['retry_failure_ids']) && is_array($mapping['retry_failure_ids'])
            ? array_values(array_filter(array_map('intval', $mapping['retry_failure_ids'])))
            : [];

        if (empty($retryFailureIds)) {
            return $this->finalize_job_batch($job, $this->initialize_counters($job), 0, true, ['Retry job has no failed rows to process.']);
        }

        $filePath = $this->resolve_file_path($job);
        if ($filePath === null || !is_file($filePath)) {
            $message = 'Retry import file not found.';
            $this->mark_job_failed($job, $message);

            return [
                'status' => self::STATUS_FAILED,
                'processed_rows' => 0,
                'imported_rows' => 0,
                'skipped_rows' => 0,
                'failed_rows' => 0,
                'warnings' => [$message],
            ];
        }

        $headerIndex = isset($mapping['header_index']) && is_array($mapping['header_index']) ? $mapping['header_index'] : [];
        $headers = isset($mapping['headers']) && is_array($mapping['headers']) ? $mapping['headers'] : [];
        if (!isset($headerIndex['email'])) {
            $inspection = $this->inspect_csv_file($filePath);
            if (!empty($inspection['ok']) && !empty($inspection['mapping']['header_index']['email'])) {
                $mapping = array_merge($inspection['mapping'], $mapping);
                $headerIndex = $mapping['header_index'];
                $headers = isset($mapping['headers']) && is_array($mapping['headers']) ? $mapping['headers'] : [];
                $this->update_import($job['id'], [
                    'mapping_json' => json_encode($mapping),
                ]);
            }
        }

        if (!isset($headerIndex['email'])) {
            $message = 'CSV header mapping is invalid.';
            $this->mark_job_failed($job, $message);

            return [
                'status' => self::STATUS_FAILED,
                'processed_rows' => 0,
                'imported_rows' => 0,
                'skipped_rows' => 0,
                'failed_rows' => 0,
                'warnings' => [$message],
            ];
        }

        $currentOffset = max(0, (int) ($job['current_offset'] ?? 0));
        $remainingIds = array_slice($retryFailureIds, $currentOffset, $batchSize);
        $failureRows = $this->get_failure_rows_by_ids($remainingIds);
        $counters = $this->initialize_counters($job);
        $checkpointRows = 0;
        $warnings = [];

        foreach ($remainingIds as $failureId) {
            if (microtime(true) >= $deadline) {
                break;
            }

            $failureRow = isset($failureRows[$failureId]) ? $failureRows[$failureId] : null;
            $counters['processed_rows']++;
            $counters['batch_processed']++;

            if (!$failureRow) {
                $counters['failed_rows']++;
                $counters['error_log'] = $this->append_error_log($counters['error_log'], 'Retry source row is missing.');
                $counters['last_error_summary'] = 'Retry source row is missing.';
                $currentOffset++;
                continue;
            }

            $lineNumber = (int) ($failureRow['row_number'] ?? 0);
            $row = $this->read_csv_row_by_number($filePath, $lineNumber);

            if (!$this->csv_row_exists($row) || $this->csv_row_is_blank($row)) {
                $message = 'Retry source row is empty or unavailable.';
                $this->mark_retry_source_failure($failureId, 'failed_retry', (int) $job['id']);
                $this->upsert_failed_row((int) $job['id'], $lineNumber, [], $message);
                $counters['failed_rows']++;
                $counters['error_log'] = $this->append_error_log($counters['error_log'], 'Row ' . $lineNumber . ': ' . $message);
                $counters['last_error_summary'] = 'Row ' . $lineNumber . ': ' . $message;
                $currentOffset++;
                continue;
            }

            $result = $this->process_single_row($job, $row, $lineNumber, $headerIndex, $headers);

            if ($result['result'] === 'imported') {
                $counters['imported_rows']++;
                $this->mark_retry_source_failure($failureId, 'resolved', (int) $job['id']);
            } elseif ($result['result'] === 'skipped') {
                $counters['skipped_rows']++;
                $this->mark_retry_source_failure($failureId, 'resolved', (int) $job['id']);
            } else {
                $counters['failed_rows']++;
                $this->mark_retry_source_failure($failureId, 'failed_retry', (int) $job['id']);
            }

            if ($result['message'] !== '') {
                $counters['error_log'] = $this->append_error_log($counters['error_log'], 'Row ' . $lineNumber . ': ' . $result['message']);
                $counters['last_error_summary'] = 'Row ' . $lineNumber . ': ' . $result['message'];
            }

            $currentOffset++;
            $checkpointRows++;

            if ($checkpointRows >= self::PROGRESS_CHECKPOINT_SIZE || microtime(true) >= $deadline - 1) {
                $this->persist_job_progress($job['id'], $counters, $currentOffset, $job['lease_token']);
                $checkpointRows = 0;
            }
        }

        $isFinished = $currentOffset >= count($retryFailureIds);

        return $this->finalize_job_batch($job, $counters, $currentOffset, $isFinished, $warnings);
    }

    protected function finalize_job_batch(array $job, array $counters, $currentOffset, $isFinished, array $warnings = [])
    {
        $status = self::STATUS_PROCESSING;
        $completedAt = null;

        if ($isFinished) {
            if ($counters['failed_rows'] > 0) {
                $status = self::STATUS_COMPLETED_WITH_ERRORS;
            } else {
                $status = self::STATUS_COMPLETED;
            }

            $completedAt = date('Y-m-d H:i:s');
        }

        $update = [
            'status' => $status,
            'processed_rows' => $counters['processed_rows'],
            'imported_rows' => $counters['imported_rows'],
            'skipped_rows' => $counters['skipped_rows'],
            'failed_rows' => $counters['failed_rows'],
            'current_offset' => (int) $currentOffset,
            'error_log' => $counters['error_log'],
            'last_error_summary' => $counters['last_error_summary'],
            'lease_token' => null,
            'lease_started_at' => null,
            'lease_heartbeat_at' => null,
        ];

        if ($completedAt !== null) {
            $update['completed_at'] = $completedAt;
        }

        $this->update_import($job['id'], $update);

        return [
            'status' => $status,
            'processed_rows' => $counters['batch_processed'],
            'imported_rows' => $counters['imported_rows'] - (int) ($job['imported_rows'] ?? 0),
            'skipped_rows' => $counters['skipped_rows'] - (int) ($job['skipped_rows'] ?? 0),
            'failed_rows' => $counters['failed_rows'] - (int) ($job['failed_rows'] ?? 0),
            'warnings' => array_values(array_filter($warnings)),
        ];
    }

    protected function persist_job_progress($jobId, array $counters, $currentOffset, $leaseToken)
    {
        $this->update_import($jobId, [
            'status' => self::STATUS_PROCESSING,
            'processed_rows' => $counters['processed_rows'],
            'imported_rows' => $counters['imported_rows'],
            'skipped_rows' => $counters['skipped_rows'],
            'failed_rows' => $counters['failed_rows'],
            'current_offset' => (int) $currentOffset,
            'error_log' => $counters['error_log'],
            'last_error_summary' => $counters['last_error_summary'],
            'lease_token' => $leaseToken,
            'lease_heartbeat_at' => date('Y-m-d H:i:s'),
        ]);
    }

    protected function mark_job_failed(array $job, $message)
    {
        $sanitizedMessage = $this->sanitize_failure_reason($message);
        $errorLog = $this->append_error_log((string) ($job['error_log'] ?? ''), $sanitizedMessage);

        $this->update_import((int) $job['id'], [
            'status' => self::STATUS_FAILED,
            'last_error_summary' => $sanitizedMessage,
            'error_log' => $errorLog,
            'lease_token' => null,
            'lease_started_at' => null,
            'lease_heartbeat_at' => null,
            'completed_at' => date('Y-m-d H:i:s'),
        ]);
    }

    protected function initialize_counters(array $job)
    {
        return [
            'processed_rows' => (int) ($job['processed_rows'] ?? 0),
            'imported_rows' => (int) ($job['imported_rows'] ?? 0),
            'skipped_rows' => (int) ($job['skipped_rows'] ?? 0),
            'failed_rows' => (int) ($job['failed_rows'] ?? 0),
            'error_log' => (string) ($job['error_log'] ?? ''),
            'last_error_summary' => (string) ($job['last_error_summary'] ?? ''),
            'batch_processed' => 0,
        ];
    }

    protected function process_single_row(array $job, array $row, $lineNumber, array $headerIndex, array $headers)
    {
        $audiencesModel = $this->ensure_audiences_model_loaded();

        if (strlen(implode('', array_map('strval', $row))) > self::MAX_ROW_SIZE_BYTES) {
            $message = 'Row is too large.';
            $this->upsert_failed_row((int) $job['id'], (int) $lineNumber, $this->build_row_excerpt($row, $headerIndex, $headers), $message);

            return ['result' => 'failed', 'message' => $message];
        }

        $email = trim($this->csv_value($row, $headerIndex, 'email'));
        $normalizedEmail = function_exists('mb_strtolower') ? mb_strtolower($email) : strtolower($email);

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'Invalid email.';
            $this->upsert_failed_row((int) $job['id'], (int) $lineNumber, $this->build_row_excerpt($row, $headerIndex, $headers), $message);

            return ['result' => 'failed', 'message' => $message];
        }

        if ($audiencesModel->is_contact_suppressed($normalizedEmail)) {
            return ['result' => 'skipped', 'message' => 'Contact suppressed.'];
        }

        $contactId = $audiencesModel->upsert_contact([
            'email' => $email,
            'first_name' => $this->csv_value($row, $headerIndex, 'first_name'),
            'last_name' => $this->csv_value($row, $headerIndex, 'last_name'),
            'full_name' => $this->csv_value($row, $headerIndex, 'full_name'),
            'phone' => $this->csv_value($row, $headerIndex, 'phone'),
            'company' => $this->csv_value($row, $headerIndex, 'company'),
            'source_type' => 'csv',
        ]);

        if (!$contactId) {
            $message = 'Failed to create or update contact.';
            $this->upsert_failed_row((int) $job['id'], (int) $lineNumber, $this->build_row_excerpt($row, $headerIndex, $headers), $message);

            return ['result' => 'failed', 'message' => $message];
        }

        if (!$audiencesModel->add_contact_to_list((int) ($job['list_id'] ?? 0), (int) $contactId)) {
            return ['result' => 'skipped', 'message' => 'Contact already exists in audience or could not be added.'];
        }

        return ['result' => 'imported', 'message' => ''];
    }

    protected function claim_next_job($staleLockSeconds)
    {
        $staleCutoff = date('Y-m-d H:i:s', time() - max(60, (int) $staleLockSeconds));

        $candidates = $this->db
            ->select('*')
            ->from($this->table)
            ->group_start()
                ->where('status', self::STATUS_QUEUED)
                ->or_group_start()
                    ->where('status', self::STATUS_PROCESSING)
                    ->group_start()
                        ->where('lease_heartbeat_at IS NULL', null, false)
                        ->or_where('lease_heartbeat_at <=', $staleCutoff)
                    ->group_end()
                ->group_end()
            ->group_end()
            ->order_by("CASE WHEN status = '" . self::STATUS_PROCESSING . "' THEN 0 ELSE 1 END", '', false)
            ->order_by('id', 'ASC')
            ->limit(5)
            ->get()
            ->result_array();

        foreach ($candidates as $candidate) {
            $token = $this->generate_lease_token();
            $now = date('Y-m-d H:i:s');
            $update = [
                'status' => self::STATUS_PROCESSING,
                'lease_token' => $token,
                'lease_started_at' => $now,
                'lease_heartbeat_at' => $now,
                'processing_started_at' => !empty($candidate['processing_started_at']) ? $candidate['processing_started_at'] : $now,
                'completed_at' => null,
            ];

            $this->db->where('id', (int) $candidate['id']);
            $this->db->group_start();
                $this->db->where('status', self::STATUS_QUEUED);
                $this->db->or_group_start();
                    $this->db->where('status', self::STATUS_PROCESSING);
                    $this->db->group_start();
                        $this->db->where('lease_heartbeat_at IS NULL', null, false);
                        $this->db->or_where('lease_heartbeat_at <=', $staleCutoff);
                    $this->db->group_end();
                $this->db->group_end();
            $this->db->group_end();

            if ($this->db->update($this->table, $update) && $this->db->affected_rows() > 0) {
                $claimed = $this->get_import((int) $candidate['id']);
                if ($claimed) {
                    $claimed['lease_token'] = $token;

                    return $claimed;
                }
            }
        }

        return null;
    }

    protected function build_header_index(array $headers)
    {
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
                    $headerIndex[$field] = (int) $index;
                }
            }
        }

        return $headerIndex;
    }

    protected function get_job_type(array $job, array $mapping)
    {
        $jobType = trim((string) ($job['job_type'] ?? ''));
        if ($jobType !== '') {
            return $jobType;
        }

        $mappingType = trim((string) ($mapping['job_type'] ?? ''));

        return $mappingType !== '' ? $mappingType : self::JOB_TYPE_IMPORT;
    }

    protected function decode_mapping($mappingJson)
    {
        if (!is_string($mappingJson) || trim($mappingJson) === '') {
            return [];
        }

        $decoded = json_decode($mappingJson, true);

        return is_array($decoded) ? $decoded : [];
    }

    protected function resolve_file_path(array $job)
    {
        $storedFilePath = trim((string) ($job['stored_file_path'] ?? ''));
        if ($storedFilePath !== '') {
            if (is_file($storedFilePath)) {
                return $storedFilePath;
            }

            $relativePath = ltrim(str_replace(FCPATH, '', $storedFilePath), '/');
            if ($relativePath !== '') {
                $candidate = FCPATH . $relativePath;
                if (is_file($candidate)) {
                    return $candidate;
                }
            }
        }

        $storedFileName = trim((string) ($job['stored_file_name'] ?? ''));
        if ($storedFileName === '') {
            return null;
        }

        $candidates = [
            $this->get_storage_directory() . $storedFileName,
            FCPATH . 'uploads/marketing_imports/' . $storedFileName,
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    protected function append_error_log($existing, $message)
    {
        $sanitizedMessage = $this->sanitize_failure_reason($message);
        if ($sanitizedMessage === '') {
            return (string) $existing;
        }

        $combined = trim((string) $existing);
        $combined = $combined === '' ? $sanitizedMessage : $combined . "\n" . $sanitizedMessage;

        if (function_exists('mb_substr')) {
            return mb_substr($combined, -self::MAX_ERROR_LOG_LENGTH);
        }

        return substr($combined, -self::MAX_ERROR_LOG_LENGTH);
    }

    protected function sanitize_failure_reason($message)
    {
        $message = marketing_sanitize_log_message($message);
        $message = trim((string) $message);

        if ($message === '') {
            return '';
        }

        if (function_exists('mb_substr')) {
            return mb_substr($message, 0, self::MAX_FAILURE_REASON_LENGTH);
        }

        return substr($message, 0, self::MAX_FAILURE_REASON_LENGTH);
    }

    protected function build_row_excerpt(array $row, array $headerIndex, array $headers)
    {
        $preview = [];
        $preferredFields = ['email', 'first_name', 'last_name', 'full_name', 'phone', 'company'];

        foreach ($preferredFields as $field) {
            if (!isset($headerIndex[$field])) {
                continue;
            }

            $value = $this->csv_value($row, $headerIndex, $field);
            if ($value === null || $value === '') {
                continue;
            }

            $preview[$field] = $this->truncate_preview_value($value);
        }

        if (!empty($preview)) {
            return $preview;
        }

        foreach ($row as $index => $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }

            $label = isset($headers[$index]) && trim((string) $headers[$index]) !== ''
                ? trim((string) $headers[$index])
                : 'column_' . ($index + 1);

            $preview[$label] = $this->truncate_preview_value($value);

            if (count($preview) >= 4) {
                break;
            }
        }

        return $preview;
    }

    protected function truncate_preview_value($value)
    {
        $value = trim((string) $value);

        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, 160);
        }

        return substr($value, 0, 160);
    }

    protected function upsert_failed_row($importId, $rowNumber, array $rowExcerpt, $reason)
    {
        $existing = $this->db
            ->from($this->failuresTable)
            ->where('import_id', (int) $importId)
            ->where('row_number', (int) $rowNumber)
            ->limit(1)
            ->get()
            ->row_array();

        $payload = [
            'row_excerpt_json' => !empty($rowExcerpt) ? json_encode($rowExcerpt) : null,
            'failure_reason' => $this->sanitize_failure_reason($reason),
            'retry_status' => 'open',
            'retry_import_id' => null,
            'dateupdated' => date('Y-m-d H:i:s'),
        ];

        if ($existing) {
            $this->db->where('id', (int) $existing['id']);
            $this->db->update($this->failuresTable, $payload);

            return (int) $existing['id'];
        }

        $payload['import_id'] = (int) $importId;
        $payload['row_number'] = (int) $rowNumber;
        $payload['datecreated'] = date('Y-m-d H:i:s');

        $this->db->insert($this->failuresTable, $payload);

        return (int) $this->db->insert_id();
    }

    protected function get_failure_rows_by_ids(array $ids)
    {
        if (empty($ids)) {
            return [];
        }

        $rows = $this->db
            ->from($this->failuresTable)
            ->where_in('id', array_values(array_map('intval', $ids)))
            ->get()
            ->result_array();

        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(int) $row['id']] = $row;
        }

        return $indexed;
    }

    protected function mark_retry_source_failure($failureId, $status, $retryImportId)
    {
        $this->db->where('id', (int) $failureId);
        $this->db->update($this->failuresTable, [
            'retry_status' => trim((string) $status) !== '' ? trim((string) $status) : 'open',
            'retry_import_id' => (int) $retryImportId > 0 ? (int) $retryImportId : null,
            'dateupdated' => date('Y-m-d H:i:s'),
        ]);
    }

    protected function read_csv_row_by_number($filePath, $lineNumber)
    {
        if (!is_file($filePath) || (int) $lineNumber <= 1) {
            return null;
        }

        try {
            $file = new SplFileObject($filePath, 'r');
            $file->setFlags(SplFileObject::READ_CSV | SplFileObject::DROP_NEW_LINE);
            $file->setCsvControl(',');
            $file->seek((int) $lineNumber - 1);

            return $file->current();
        } catch (Throwable $exception) {
            return null;
        }
    }

    protected function csv_import_is_finished(SplFileObject $file, $currentLine)
    {
        if (!$file->eof()) {
            return false;
        }

        return true;
    }

    protected function get_ci_instance()
    {
        if (is_object($this->ciInstance)) {
            return $this->ciInstance;
        }

        $CI = &get_instance();
        if (!is_object($CI)) {
            throw new RuntimeException('Marketing import dependency loader unavailable');
        }

        $this->ciInstance = $CI;

        return $this->ciInstance;
    }

    protected function ensure_audiences_model_loaded()
    {
        $CI = $this->get_ci_instance();

        if (!isset($CI->load) || !is_object($CI->load)) {
            throw new RuntimeException('Marketing import dependency loader unavailable');
        }

        if (!$this->audiencesModelLoaded || !isset($CI->marketing_audiences_model) || !is_object($CI->marketing_audiences_model)) {
            $CI->load->model('marketing/Marketing_audiences_model', 'marketing_audiences_model');
            $this->audiencesModelLoaded = true;
        }

        if (!isset($CI->marketing_audiences_model) || !is_object($CI->marketing_audiences_model)) {
            throw new RuntimeException('Marketing audiences model unavailable');
        }

        return $CI->marketing_audiences_model;
    }

    protected function generate_lease_token()
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (Throwable $exception) {
            return md5(uniqid('marketing_import_', true));
        }
    }

    protected function csv_row_exists($row)
    {
        if (!is_array($row)) {
            return false;
        }

        if (count($row) === 1 && $row[0] === null) {
            return false;
        }

        return true;
    }

    protected function normalize_csv_header($header)
    {
        $header = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header);
        $header = trim($header);
        $header = function_exists('mb_strtolower') ? mb_strtolower($header) : strtolower($header);
        $header = str_replace(['_', '-'], ' ', $header);
        $header = preg_replace('/\s+/', ' ', $header);

        return trim($header);
    }

    protected function csv_row_is_blank($row)
    {
        if (!is_array($row)) {
            return true;
        }

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

        $index = (int) $headerIndex[$field];

        return isset($row[$index]) ? trim((string) $row[$index]) : null;
    }

    protected function clamp_batch_size($batchSize)
    {
        $batchSize = (int) $batchSize;
        if ($batchSize <= 0) {
            $batchSize = self::DEFAULT_BATCH_SIZE;
        }

        return max(1, min(self::MAX_BATCH_SIZE, $batchSize));
    }

    protected function clamp_runtime_seconds($seconds)
    {
        $seconds = (int) $seconds;
        if ($seconds <= 0) {
            $seconds = self::DEFAULT_MAX_RUNTIME_SECONDS;
        }

        return max(5, min(60, $seconds));
    }

    protected function clamp_stale_lock_seconds($seconds)
    {
        $seconds = (int) $seconds;
        if ($seconds <= 0) {
            $seconds = self::DEFAULT_STALE_LOCK_SECONDS;
        }

        return max(60, min(86400, $seconds));
    }
}
