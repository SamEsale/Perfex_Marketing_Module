<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Marketing_schema
{
    protected $ci;
    protected $db;
    protected $definitions = [];

    public function __construct()
    {
        $this->ci = &get_instance();
        $this->ci->load->database();
        $this->db = $this->ci->db;
        $this->definitions = $this->build_definitions();
    }

    public function table_exists($table)
    {
        return $this->db->table_exists($this->resolve_table_name($table));
    }

    public function column_exists($table, $column)
    {
        return $this->db->field_exists($column, $this->resolve_table_name($table));
    }

    public function get_schema_inventory()
    {
        $inventory = [];

        foreach ($this->definitions as $key => $definition) {
            $inventory[$key] = [
                'table' => $definition['table'],
                'label' => $definition['label'],
                'required_columns' => array_keys($definition['columns']),
                'repairable_columns' => array_values(array_keys(array_filter($definition['columns'], function ($column) {
                    return !empty($column['repairable']);
                }))),
            ];
        }

        return $inventory;
    }

    public function get_missing_schema_report()
    {
        return $this->get_readiness_report();
    }

    public function get_readiness_report()
    {
        $report = [
            'ok' => true,
            'missing_tables' => [],
            'missing_columns' => [],
            'unsafe_missing_columns' => [],
            'orphan_tables' => [],
            'inventory' => $this->get_schema_inventory(),
        ];

        foreach ($this->definitions as $key => $definition) {
            $table = $definition['table'];
            if (!$this->db->table_exists($table)) {
                $report['missing_tables'][] = [
                    'key' => $key,
                    'table' => $table,
                    'label' => $definition['label'],
                ];
                $report['ok'] = false;
                continue;
            }

            foreach ($definition['columns'] as $column => $columnDefinition) {
                if ($this->db->field_exists($column, $table)) {
                    continue;
                }

                $target = !empty($columnDefinition['repairable']) ? 'missing_columns' : 'unsafe_missing_columns';
                $report[$target][] = [
                    'key' => $key,
                    'table' => $table,
                    'label' => $definition['label'],
                    'column' => $column,
                    'description' => $columnDefinition['sql'],
                ];
                $report['ok'] = false;
            }
        }

        $report['orphan_tables'] = $this->get_orphan_duplicate_tables();
        if (!empty($report['orphan_tables'])) {
            $report['ok'] = false;
        }

        return $report;
    }

    public function ensure_minimum_schema()
    {
        $result = [
            'created_tables' => [],
            'added_columns' => [],
            'skipped_columns' => [],
            'added_indexes' => [],
            'skipped_indexes' => [],
            'errors' => [],
        ];

        foreach ($this->definitions as $key => $definition) {
            $table = $definition['table'];

            if (!$this->db->table_exists($table)) {
                if ($this->run_query($this->build_create_table_sql($definition), $table)) {
                    $result['created_tables'][] = $table;
                } else {
                    $result['errors'][] = 'Failed creating table ' . $table;
                    continue;
                }
            }

            foreach ($definition['columns'] as $column => $columnDefinition) {
                if ($this->db->field_exists($column, $table)) {
                    continue;
                }

                if (empty($columnDefinition['repairable'])) {
                    $result['skipped_columns'][] = $table . '.' . $column;
                    continue;
                }

                $sql = 'ALTER TABLE ' . $this->quote_identifier($table)
                    . ' ADD COLUMN ' . $this->quote_identifier($column) . ' ' . $columnDefinition['sql'];

                if ($this->run_query($sql, $table . '.' . $column)) {
                    $result['added_columns'][] = $table . '.' . $column;
                } else {
                    $result['errors'][] = 'Failed adding column ' . $table . '.' . $column;
                }
            }
        }

        $this->ensure_safe_indexes($result);

        $result['report'] = $this->get_readiness_report();
        $this->log_orphan_table_warnings($result['report']['orphan_tables'] ?? []);
        $result['ok'] = empty($result['errors']) && $result['report']['ok'];

        return $result;
    }

    protected function build_definitions()
    {
        return [
            'campaigns' => [
                'table' => db_prefix() . 'marketing_campaigns',
                'label' => 'Campaigns',
                'columns' => [
                    'id' => ['sql' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT', 'repairable' => false],
                    'name' => ['sql' => "VARCHAR(191) NOT NULL DEFAULT ''", 'repairable' => true],
                    'subject' => ['sql' => "VARCHAR(255) NOT NULL DEFAULT ''", 'repairable' => true],
                    'preview_text' => ['sql' => 'VARCHAR(255) NULL', 'repairable' => true],
                    'from_name' => ['sql' => "VARCHAR(191) NOT NULL DEFAULT ''", 'repairable' => true],
                    'from_email' => ['sql' => "VARCHAR(191) NOT NULL DEFAULT ''", 'repairable' => true],
                    'reply_to_email' => ['sql' => 'VARCHAR(191) NULL', 'repairable' => true],
                    'list_id' => ['sql' => 'INT UNSIGNED NULL', 'repairable' => true],
                    'status' => ['sql' => "VARCHAR(50) NOT NULL DEFAULT 'draft'", 'repairable' => true],
                    'delivery_channel' => ['sql' => "VARCHAR(30) NOT NULL DEFAULT 'email'", 'repairable' => true],
                    'editor_type' => ['sql' => "VARCHAR(50) NOT NULL DEFAULT 'builder'", 'repairable' => true],
                    'builder_state' => ['sql' => 'LONGTEXT NULL', 'repairable' => true],
                    'html_content' => ['sql' => 'LONGTEXT NULL', 'repairable' => true],
                    'text_content' => ['sql' => 'LONGTEXT NULL', 'repairable' => true],
                    'whatsapp_template_name' => ['sql' => 'VARCHAR(190) NULL', 'repairable' => true],
                    'whatsapp_template_language' => ['sql' => 'VARCHAR(30) NULL', 'repairable' => true],
                    'whatsapp_template_var1' => ['sql' => 'VARCHAR(255) NULL', 'repairable' => true],
                    'whatsapp_template_var2' => ['sql' => 'VARCHAR(255) NULL', 'repairable' => true],
                    'whatsapp_template_var3' => ['sql' => 'VARCHAR(255) NULL', 'repairable' => true],
                    'scheduled_at' => ['sql' => 'DATETIME NULL', 'repairable' => true],
                    'send_started_at' => ['sql' => 'DATETIME NULL', 'repairable' => true],
                    'send_completed_at' => ['sql' => 'DATETIME NULL', 'repairable' => true],
                    'source_type' => ['sql' => "VARCHAR(50) NOT NULL DEFAULT 'smtp'", 'repairable' => true],
                    'track_opens' => ['sql' => 'TINYINT(1) NOT NULL DEFAULT 1', 'repairable' => true],
                    'track_clicks' => ['sql' => 'TINYINT(1) NOT NULL DEFAULT 1', 'repairable' => true],
                    'created_by' => ['sql' => 'INT UNSIGNED NULL', 'repairable' => true],
                    'updated_by' => ['sql' => 'INT UNSIGNED NULL', 'repairable' => true],
                    'datecreated' => ['sql' => 'DATETIME NULL', 'repairable' => true],
                    'dateupdated' => ['sql' => 'DATETIME NULL', 'repairable' => true],
                ],
                'indexes' => [
                    'PRIMARY KEY (`id`)',
                    'KEY `status` (`status`)',
                    'KEY `list_id` (`list_id`)',
                    'KEY `scheduled_at` (`scheduled_at`)',
                ],
            ],
            'lists' => [
                'table' => db_prefix() . 'marketing_lists',
                'label' => 'Audiences / Lists',
                'columns' => [
                    'id' => ['sql' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT', 'repairable' => false],
                    'name' => ['sql' => "VARCHAR(191) NOT NULL DEFAULT ''", 'repairable' => true],
                    'description' => ['sql' => 'TEXT NULL', 'repairable' => true],
                    'source_mode' => ['sql' => "VARCHAR(50) NOT NULL DEFAULT 'manual'", 'repairable' => true],
                    'created_by' => ['sql' => 'INT UNSIGNED NULL', 'repairable' => true],
                    'datecreated' => ['sql' => 'DATETIME NULL', 'repairable' => true],
                    'dateupdated' => ['sql' => 'DATETIME NULL', 'repairable' => true],
                ],
                'indexes' => [
                    'PRIMARY KEY (`id`)',
                    'UNIQUE KEY `name_unique` (`name`)',
                ],
            ],
            'contacts' => [
                'table' => db_prefix() . 'marketing_contacts',
                'label' => 'Contacts',
                'columns' => [
                    'id' => ['sql' => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT', 'repairable' => false],
                    'email' => ['sql' => "VARCHAR(191) NOT NULL DEFAULT ''", 'repairable' => true],
                    'normalized_email' => ['sql' => "VARCHAR(191) NOT NULL DEFAULT ''", 'repairable' => true],
                    'first_name' => ['sql' => 'VARCHAR(100) NULL', 'repairable' => true],
                    'last_name' => ['sql' => 'VARCHAR(100) NULL', 'repairable' => true],
                    'full_name' => ['sql' => 'VARCHAR(191) NULL', 'repairable' => true],
                    'phone' => ['sql' => 'VARCHAR(100) NULL', 'repairable' => true],
                    'company' => ['sql' => 'VARCHAR(191) NULL', 'repairable' => true],
                    'source_type' => ['sql' => "VARCHAR(50) NOT NULL DEFAULT 'manual'", 'repairable' => true],
                    'source_rel_type' => ['sql' => 'VARCHAR(50) NULL', 'repairable' => true],
                    'source_rel_id' => ['sql' => 'INT UNSIGNED NULL', 'repairable' => true],
                    'lead_id' => ['sql' => 'INT UNSIGNED NULL', 'repairable' => true],
                    'client_id' => ['sql' => 'INT UNSIGNED NULL', 'repairable' => true],
                    'contact_id' => ['sql' => 'INT UNSIGNED NULL', 'repairable' => true],
                    'status' => ['sql' => "VARCHAR(50) NOT NULL DEFAULT 'active'", 'repairable' => true],
                    'consent_status' => ['sql' => "VARCHAR(50) NOT NULL DEFAULT 'unknown'", 'repairable' => true],
                    'last_source_sync_at' => ['sql' => 'DATETIME NULL', 'repairable' => true],
                    'datecreated' => ['sql' => 'DATETIME NULL', 'repairable' => true],
                    'dateupdated' => ['sql' => 'DATETIME NULL', 'repairable' => true],
                ],
                'indexes' => [
                    'PRIMARY KEY (`id`)',
                    'UNIQUE KEY `normalized_email_unique` (`normalized_email`)',
                    'KEY `status` (`status`)',
                ],
            ],
            'members' => [
                'table' => db_prefix() . 'marketing_list_members',
                'label' => 'List Members',
                'columns' => [
                    'id' => ['sql' => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT', 'repairable' => false],
                    'list_id' => ['sql' => 'INT UNSIGNED NOT NULL DEFAULT 0', 'repairable' => true],
                    'marketing_contact_id' => ['sql' => 'BIGINT UNSIGNED NOT NULL DEFAULT 0', 'repairable' => true],
                    'member_status' => ['sql' => "VARCHAR(50) NOT NULL DEFAULT 'active'", 'repairable' => true],
                    'added_via' => ['sql' => "VARCHAR(50) NOT NULL DEFAULT 'manual'", 'repairable' => true],
                    'dateadded' => ['sql' => 'DATETIME NULL', 'repairable' => true],
                    'datecreated' => ['sql' => 'DATETIME NULL', 'repairable' => true],
                    'dateupdated' => ['sql' => 'DATETIME NULL', 'repairable' => true],
                ],
                'indexes' => [
                    'PRIMARY KEY (`id`)',
                    'UNIQUE KEY `list_member_unique` (`list_id`,`marketing_contact_id`)',
                    'KEY `list_id` (`list_id`)',
                    'KEY `marketing_contact_id` (`marketing_contact_id`)',
                ],
            ],
            'campaign_recipients' => [
                'table' => db_prefix() . 'marketing_campaign_recipients',
                'label' => 'Campaign Recipients',
                'columns' => [
                    'id' => ['sql' => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT', 'repairable' => false],
                    'campaign_id' => ['sql' => 'INT UNSIGNED NOT NULL DEFAULT 0', 'repairable' => true],
                    'marketing_contact_id' => ['sql' => 'BIGINT UNSIGNED NOT NULL DEFAULT 0', 'repairable' => true],
                    'channel' => ['sql' => "VARCHAR(30) NOT NULL DEFAULT 'email'", 'repairable' => true],
                    'destination' => ['sql' => 'VARCHAR(190) NULL', 'repairable' => true],
                    'email' => ['sql' => "VARCHAR(191) NOT NULL DEFAULT ''", 'repairable' => true],
                    'status' => ['sql' => "VARCHAR(50) NOT NULL DEFAULT 'queued'", 'repairable' => true],
                    'processing_token' => ['sql' => 'VARCHAR(96) NULL', 'repairable' => true],
                    'outbound_attempt_token' => ['sql' => 'VARCHAR(96) NULL', 'repairable' => true],
                    'queued_at' => ['sql' => 'DATETIME NULL', 'repairable' => true],
                    'sent_at' => ['sql' => 'DATETIME NULL', 'repairable' => true],
                    'failed_at' => ['sql' => 'DATETIME NULL', 'repairable' => true],
                    'message_id' => ['sql' => 'VARCHAR(255) NULL', 'repairable' => true],
                    'provider_message_id' => ['sql' => 'VARCHAR(255) NULL', 'repairable' => true],
                    'failure_reason' => ['sql' => 'TEXT NULL', 'repairable' => true],
                    'open_count' => ['sql' => 'INT UNSIGNED NOT NULL DEFAULT 0', 'repairable' => true],
                    'click_count' => ['sql' => 'INT UNSIGNED NOT NULL DEFAULT 0', 'repairable' => true],
                    'unique_click_count' => ['sql' => 'INT UNSIGNED NOT NULL DEFAULT 0', 'repairable' => true],
                    'last_clicked_url' => ['sql' => 'TEXT NULL', 'repairable' => true],
                    'last_event_at' => ['sql' => 'DATETIME NULL', 'repairable' => true],
                    'datecreated' => ['sql' => 'DATETIME NULL', 'repairable' => true],
                    'dateupdated' => ['sql' => 'DATETIME NULL', 'repairable' => true],
                ],
                'indexes' => [
                    'PRIMARY KEY (`id`)',
                    'UNIQUE KEY `campaign_contact_unique` (`campaign_id`,`marketing_contact_id`)',
                    'KEY `campaign_id` (`campaign_id`)',
                    'KEY `marketing_contact_id` (`marketing_contact_id`)',
                    'KEY `status` (`status`)',
                    'KEY `channel` (`channel`)',
                    'KEY `destination` (`destination`)',
                    'KEY `campaign_status` (`campaign_id`,`status`)',
                ],
            ],
            'events' => [
                'table' => db_prefix() . 'marketing_events',
                'label' => 'Events',
                'columns' => [
                    'id' => ['sql' => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT', 'repairable' => false],
                    'campaign_id' => ['sql' => 'INT UNSIGNED NULL', 'repairable' => true],
                    'campaign_recipient_id' => ['sql' => 'BIGINT UNSIGNED NULL', 'repairable' => true],
                    'marketing_contact_id' => ['sql' => 'BIGINT UNSIGNED NULL', 'repairable' => true],
                    'event_type' => ['sql' => "VARCHAR(100) NOT NULL DEFAULT ''", 'repairable' => true],
                    'provider' => ['sql' => "VARCHAR(50) NOT NULL DEFAULT 'smtp'", 'repairable' => true],
                    'provider_event_id' => ['sql' => 'VARCHAR(255) NULL', 'repairable' => true],
                    'provider_message_id' => ['sql' => 'VARCHAR(255) NULL', 'repairable' => true],
                    'sns_message_id' => ['sql' => 'VARCHAR(255) NULL', 'repairable' => true],
                    'email' => ['sql' => 'VARCHAR(191) NULL', 'repairable' => true],
                    'url' => ['sql' => 'TEXT NULL', 'repairable' => true],
                    'ip_address' => ['sql' => 'VARCHAR(64) NULL', 'repairable' => true],
                    'user_agent' => ['sql' => 'VARCHAR(255) NULL', 'repairable' => true],
                    'payload_json' => ['sql' => 'LONGTEXT NULL', 'repairable' => true],
                    'event_at' => ['sql' => 'DATETIME NULL', 'repairable' => true],
                    'datecreated' => ['sql' => 'DATETIME NULL', 'repairable' => true],
                    'dateupdated' => ['sql' => 'DATETIME NULL', 'repairable' => true],
                ],
                'indexes' => [
                    'PRIMARY KEY (`id`)',
                    'KEY `campaign_id` (`campaign_id`)',
                    'KEY `campaign_recipient_id` (`campaign_recipient_id`)',
                    'KEY `event_type` (`event_type`)',
                ],
            ],
            'public_links' => [
                'table' => db_prefix() . 'marketing_public_links',
                'label' => 'Public Tracking Links',
                'columns' => [
                    'id' => ['sql' => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT', 'repairable' => false],
                    'token_hash' => ['sql' => "CHAR(64) NOT NULL DEFAULT ''", 'repairable' => true],
                    'token_type' => ['sql' => "VARCHAR(30) NOT NULL DEFAULT 'click'", 'repairable' => true],
                    'campaign_id' => ['sql' => 'INT UNSIGNED NULL', 'repairable' => true],
                    'campaign_recipient_id' => ['sql' => 'BIGINT UNSIGNED NULL', 'repairable' => true],
                    'marketing_contact_id' => ['sql' => 'BIGINT UNSIGNED NULL', 'repairable' => true],
                    'normalized_email' => ['sql' => 'VARCHAR(191) NULL', 'repairable' => true],
                    'original_url' => ['sql' => 'TEXT NULL', 'repairable' => true],
                    'click_count' => ['sql' => 'INT UNSIGNED NOT NULL DEFAULT 0', 'repairable' => true],
                    'human_click_count' => ['sql' => 'INT UNSIGNED NOT NULL DEFAULT 0', 'repairable' => true],
                    'unique_click_count' => ['sql' => 'INT UNSIGNED NOT NULL DEFAULT 0', 'repairable' => true],
                    'last_clicked_at' => ['sql' => 'DATETIME NULL', 'repairable' => true],
                    'last_human_clicked_at' => ['sql' => 'DATETIME NULL', 'repairable' => true],
                    'last_used_at' => ['sql' => 'DATETIME NULL', 'repairable' => true],
                    'is_active' => ['sql' => 'TINYINT(1) NOT NULL DEFAULT 1', 'repairable' => true],
                    'payload_json' => ['sql' => 'LONGTEXT NULL', 'repairable' => true],
                    'datecreated' => ['sql' => 'DATETIME NULL', 'repairable' => true],
                    'dateupdated' => ['sql' => 'DATETIME NULL', 'repairable' => true],
                ],
                'indexes' => [
                    'PRIMARY KEY (`id`)',
                    'UNIQUE KEY `token_hash_unique` (`token_hash`)',
                    'KEY `token_type` (`token_type`)',
                    'KEY `campaign_id` (`campaign_id`)',
                    'KEY `campaign_recipient_id` (`campaign_recipient_id`)',
                    'KEY `marketing_contact_id` (`marketing_contact_id`)',
                ],
            ],
            'suppressions' => [
                'table' => db_prefix() . 'marketing_suppressions',
                'label' => 'Suppressions',
                'columns' => [
                    'id' => ['sql' => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT', 'repairable' => false],
                    'email' => ['sql' => "VARCHAR(191) NOT NULL DEFAULT ''", 'repairable' => true],
                    'normalized_email' => ['sql' => "VARCHAR(191) NOT NULL DEFAULT ''", 'repairable' => true],
                    'phone' => ['sql' => 'VARCHAR(100) NULL', 'repairable' => true],
                    'reason' => ['sql' => "VARCHAR(50) NOT NULL DEFAULT ''", 'repairable' => true],
                    'source' => ['sql' => "VARCHAR(50) NOT NULL DEFAULT 'system'", 'repairable' => true],
                    'provider_message_id' => ['sql' => 'VARCHAR(255) NULL', 'repairable' => true],
                    'notes' => ['sql' => 'TEXT NULL', 'repairable' => true],
                    'active' => ['sql' => 'TINYINT(1) NOT NULL DEFAULT 1', 'repairable' => true],
                    'datecreated' => ['sql' => 'DATETIME NULL', 'repairable' => true],
                    'dateupdated' => ['sql' => 'DATETIME NULL', 'repairable' => true],
                ],
                'indexes' => [
                    'PRIMARY KEY (`id`)',
                    'KEY `email` (`email`)',
                    'KEY `normalized_email` (`normalized_email`)',
                    'KEY `phone` (`phone`)',
                    'KEY `active_reason` (`active`,`reason`)',
                ],
            ],
            'imports' => [
                'table' => db_prefix() . 'marketing_imports',
                'label' => 'Imports',
                'columns' => [
                    'id' => ['sql' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT', 'repairable' => false],
                    'list_id' => ['sql' => 'INT UNSIGNED NULL', 'repairable' => true],
                    'filename' => ['sql' => "VARCHAR(255) NOT NULL DEFAULT ''", 'repairable' => true],
                    'stored_file_name' => ['sql' => 'VARCHAR(255) NULL', 'repairable' => true],
                    'status' => ['sql' => "VARCHAR(50) NOT NULL DEFAULT 'uploaded'", 'repairable' => true],
                    'total_rows' => ['sql' => 'INT NOT NULL DEFAULT 0', 'repairable' => true],
                    'imported_rows' => ['sql' => 'INT NOT NULL DEFAULT 0', 'repairable' => true],
                    'skipped_rows' => ['sql' => 'INT NOT NULL DEFAULT 0', 'repairable' => true],
                    'failed_rows' => ['sql' => 'INT NOT NULL DEFAULT 0', 'repairable' => true],
                    'mapping_json' => ['sql' => 'LONGTEXT NULL', 'repairable' => true],
                    'error_log' => ['sql' => 'LONGTEXT NULL', 'repairable' => true],
                    'uploaded_by' => ['sql' => 'INT UNSIGNED NULL', 'repairable' => true],
                    'datecreated' => ['sql' => 'DATETIME NULL', 'repairable' => true],
                    'dateupdated' => ['sql' => 'DATETIME NULL', 'repairable' => true],
                    'completed_at' => ['sql' => 'DATETIME NULL', 'repairable' => true],
                ],
                'indexes' => [
                    'PRIMARY KEY (`id`)',
                    'KEY `list_id` (`list_id`)',
                    'KEY `status` (`status`)',
                ],
            ],
            'integrations' => [
                'table' => db_prefix() . 'marketing_channel_integrations',
                'label' => 'Channel Integrations',
                'columns' => [
                    'id' => ['sql' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT', 'repairable' => false],
                    'channel' => ['sql' => "VARCHAR(50) NOT NULL DEFAULT ''", 'repairable' => true],
                    'is_enabled' => ['sql' => 'TINYINT(1) NOT NULL DEFAULT 0', 'repairable' => true],
                    'access_token' => ['sql' => 'TEXT NULL', 'repairable' => true],
                    'refresh_token' => ['sql' => 'TEXT NULL', 'repairable' => true],
                    'external_account_id' => ['sql' => 'VARCHAR(191) NULL', 'repairable' => true],
                    'settings_json' => ['sql' => 'LONGTEXT NULL', 'repairable' => true],
                    'last_sync_at' => ['sql' => 'DATETIME NULL', 'repairable' => true],
                    'datecreated' => ['sql' => 'DATETIME NULL', 'repairable' => true],
                    'dateupdated' => ['sql' => 'DATETIME NULL', 'repairable' => true],
                ],
                'indexes' => [
                    'PRIMARY KEY (`id`)',
                    'UNIQUE KEY `channel_unique` (`channel`)',
                ],
            ],
            'conversations' => [
                'table' => db_prefix() . 'marketing_conversations',
                'label' => 'Conversations',
                'columns' => [
                    'id' => ['sql' => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT', 'repairable' => false],
                    'campaign_id' => ['sql' => 'INT UNSIGNED NULL', 'repairable' => true],
                    'marketing_contact_id' => ['sql' => 'BIGINT UNSIGNED NULL', 'repairable' => true],
                    'email' => ['sql' => 'VARCHAR(191) NULL', 'repairable' => true],
                    'contact_email' => ['sql' => 'VARCHAR(191) NULL', 'repairable' => true],
                    'subject' => ['sql' => 'VARCHAR(255) NULL', 'repairable' => true],
                    'last_message_at' => ['sql' => 'DATETIME NULL', 'repairable' => true],
                    'status' => ['sql' => "VARCHAR(50) NOT NULL DEFAULT 'open'", 'repairable' => true],
                    'source_type' => ['sql' => "VARCHAR(50) NOT NULL DEFAULT 'email_reply'", 'repairable' => true],
                    'datecreated' => ['sql' => 'DATETIME NULL', 'repairable' => true],
                    'dateupdated' => ['sql' => 'DATETIME NULL', 'repairable' => true],
                ],
                'indexes' => [
                    'PRIMARY KEY (`id`)',
                    'KEY `campaign_id` (`campaign_id`)',
                    'KEY `marketing_contact_id` (`marketing_contact_id`)',
                ],
            ],
            'messages' => [
                'table' => db_prefix() . 'marketing_messages',
                'label' => 'Conversation Messages',
                'columns' => [
                    'id' => ['sql' => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT', 'repairable' => false],
                    'conversation_id' => ['sql' => 'BIGINT UNSIGNED NOT NULL DEFAULT 0', 'repairable' => true],
                    'campaign_id' => ['sql' => 'INT UNSIGNED NULL', 'repairable' => true],
                    'marketing_contact_id' => ['sql' => 'BIGINT UNSIGNED NULL', 'repairable' => true],
                    'direction' => ['sql' => "VARCHAR(50) NOT NULL DEFAULT 'inbound'", 'repairable' => true],
                    'email' => ['sql' => 'VARCHAR(191) NULL', 'repairable' => true],
                    'from_email' => ['sql' => 'VARCHAR(191) NULL', 'repairable' => true],
                    'subject' => ['sql' => 'VARCHAR(255) NULL', 'repairable' => true],
                    'message' => ['sql' => 'MEDIUMTEXT NULL', 'repairable' => true],
                    'body' => ['sql' => 'MEDIUMTEXT NULL', 'repairable' => true],
                    'body_text' => ['sql' => 'MEDIUMTEXT NULL', 'repairable' => true],
                    'body_html' => ['sql' => 'LONGTEXT NULL', 'repairable' => true],
                    'provider_message_id' => ['sql' => 'VARCHAR(255) NULL', 'repairable' => true],
                    'datecreated' => ['sql' => 'DATETIME NULL', 'repairable' => true],
                ],
                'indexes' => [
                    'PRIMARY KEY (`id`)',
                    'KEY `conversation_id` (`conversation_id`)',
                    'KEY `campaign_id` (`campaign_id`)',
                ],
            ],
            'webhook_logs' => [
                'table' => db_prefix() . 'marketing_webhook_logs',
                'label' => 'Webhook Logs',
                'columns' => [
                    'id' => ['sql' => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT', 'repairable' => false],
                    'provider' => ['sql' => "VARCHAR(50) NOT NULL DEFAULT 'ses_sns'", 'repairable' => true],
                    'event_type' => ['sql' => 'VARCHAR(100) NULL', 'repairable' => true],
                    'message_type' => ['sql' => 'VARCHAR(100) NULL', 'repairable' => true],
                    'signature_valid' => ['sql' => 'TINYINT(1) NOT NULL DEFAULT 0', 'repairable' => true],
                    'payload_json' => ['sql' => 'LONGTEXT NULL', 'repairable' => true],
                    'headers_json' => ['sql' => 'LONGTEXT NULL', 'repairable' => true],
                    'processed' => ['sql' => 'TINYINT(1) NOT NULL DEFAULT 0', 'repairable' => true],
                    'processed_at' => ['sql' => 'DATETIME NULL', 'repairable' => true],
                    'error_message' => ['sql' => 'TEXT NULL', 'repairable' => true],
                    'datecreated' => ['sql' => 'DATETIME NULL', 'repairable' => true],
                    'dateupdated' => ['sql' => 'DATETIME NULL', 'repairable' => true],
                ],
                'indexes' => [
                    'PRIMARY KEY (`id`)',
                    'KEY `provider_processed` (`provider`,`processed`)',
                ],
            ],
        ];
    }

    protected function build_create_table_sql(array $definition)
    {
        $parts = [];
        foreach ($definition['columns'] as $column => $columnDefinition) {
            $parts[] = $this->quote_identifier($column) . ' ' . $columnDefinition['sql'];
        }

        foreach ($definition['indexes'] as $index) {
            $parts[] = $index;
        }

        $charset = !empty($this->db->char_set) ? $this->db->char_set : 'utf8';
        $collation = !empty($this->db->dbcollat) ? $this->db->dbcollat : '';
        $charsetSql = ' ENGINE=InnoDB DEFAULT CHARSET=' . $charset;
        if ($collation !== '') {
            $charsetSql .= ' COLLATE=' . $collation;
        }

        return 'CREATE TABLE IF NOT EXISTS ' . $this->quote_identifier($definition['table']) . " (\n  "
            . implode(",\n  ", $parts)
            . "\n)" . $charsetSql;
    }

    protected function resolve_table_name($table)
    {
        if (isset($this->definitions[$table])) {
            return $this->definitions[$table]['table'];
        }

        return $table;
    }

    protected function quote_identifier($identifier)
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    protected function run_query($sql, $context)
    {
        $result = $this->db->query($sql);
        if ($result === false) {
            $error = $this->db->error();
            log_message('error', 'Marketing schema operation failed: context=' . $context . ' error=' . ($error['message'] ?? 'unknown'));
            return false;
        }

        log_message('info', 'Marketing schema operation succeeded: context=' . $context);

        return true;
    }

    protected function ensure_safe_indexes(array &$result)
    {
        foreach ($this->get_safe_index_definitions() as $indexDefinition) {
            $table = $indexDefinition['table'];
            if (!$this->db->table_exists($table)) {
                $result['skipped_indexes'][] = $table . ' missing_table';
                continue;
            }

            $columns = array_values(array_filter(array_map('trim', $indexDefinition['columns'])));
            if (empty($columns)) {
                $result['skipped_indexes'][] = $table . '.' . $indexDefinition['name'] . ' empty_columns';
                continue;
            }

            $missingColumns = [];
            foreach ($columns as $column) {
                if (!$this->db->field_exists($column, $table)) {
                    $missingColumns[] = $column;
                }
            }

            if (!empty($missingColumns)) {
                $result['skipped_indexes'][] = $table . '.' . $indexDefinition['name'] . ' missing_columns=' . implode(',', $missingColumns);
                continue;
            }

            if ($this->index_columns_exist($table, $columns)) {
                continue;
            }

            if ($this->index_name_exists($table, $indexDefinition['name'])) {
                $result['skipped_indexes'][] = $table . '.' . $indexDefinition['name'] . ' existing_name';
                continue;
            }

            $sql = 'ALTER TABLE ' . $this->quote_identifier($table)
                . ' ADD INDEX ' . $this->quote_identifier($indexDefinition['name'])
                . ' (' . implode(', ', array_map([$this, 'quote_identifier'], $columns)) . ')';

            if ($this->run_query($sql, $table . '.' . $indexDefinition['name'])) {
                $result['added_indexes'][] = $table . '.' . $indexDefinition['name'];
            } else {
                $result['errors'][] = 'Failed adding index ' . $table . '.' . $indexDefinition['name'];
            }
        }
    }

    protected function get_safe_index_definitions()
    {
        return [
            [
                'table' => db_prefix() . 'marketing_list_members',
                'name' => 'list_id',
                'columns' => ['list_id'],
            ],
            [
                'table' => db_prefix() . 'marketing_list_members',
                'name' => 'marketing_contact_id',
                'columns' => ['marketing_contact_id'],
            ],
            [
                'table' => db_prefix() . 'marketing_campaign_recipients',
                'name' => 'campaign_id',
                'columns' => ['campaign_id'],
            ],
            [
                'table' => db_prefix() . 'marketing_campaign_recipients',
                'name' => 'marketing_contact_id',
                'columns' => ['marketing_contact_id'],
            ],
            [
                'table' => db_prefix() . 'marketing_campaign_recipients',
                'name' => 'status',
                'columns' => ['status'],
            ],
            [
                'table' => db_prefix() . 'marketing_campaign_recipients',
                'name' => 'channel',
                'columns' => ['channel'],
            ],
            [
                'table' => db_prefix() . 'marketing_campaign_recipients',
                'name' => 'destination',
                'columns' => ['destination'],
            ],
            [
                'table' => db_prefix() . 'marketing_events',
                'name' => 'campaign_id',
                'columns' => ['campaign_id'],
            ],
            [
                'table' => db_prefix() . 'marketing_events',
                'name' => 'recipient_id',
                'columns' => ['recipient_id'],
            ],
            [
                'table' => db_prefix() . 'marketing_events',
                'name' => 'campaign_recipient_id',
                'columns' => ['campaign_recipient_id'],
            ],
            [
                'table' => db_prefix() . 'marketing_events',
                'name' => 'event_type',
                'columns' => ['event_type'],
            ],
            [
                'table' => db_prefix() . 'marketing_events',
                'name' => 'provider_message_id',
                'columns' => ['provider_message_id'],
            ],
            [
                'table' => db_prefix() . 'marketing_events',
                'name' => 'provider_event_id',
                'columns' => ['provider_event_id'],
            ],
            [
                'table' => db_prefix() . 'marketing_events',
                'name' => 'provider_sns_message_id',
                'columns' => ['provider', 'sns_message_id'],
            ],
            [
                'table' => db_prefix() . 'marketing_conversations',
                'name' => 'campaign_id',
                'columns' => ['campaign_id'],
            ],
            [
                'table' => db_prefix() . 'marketing_conversations',
                'name' => 'contact_id',
                'columns' => ['contact_id'],
            ],
            [
                'table' => db_prefix() . 'marketing_conversations',
                'name' => 'marketing_contact_id',
                'columns' => ['marketing_contact_id'],
            ],
            [
                'table' => db_prefix() . 'marketing_messages',
                'name' => 'conversation_id',
                'columns' => ['conversation_id'],
            ],
            [
                'table' => db_prefix() . 'marketing_suppressions',
                'name' => 'email',
                'columns' => ['email'],
            ],
            [
                'table' => db_prefix() . 'marketing_suppressions',
                'name' => 'normalized_email',
                'columns' => ['normalized_email'],
            ],
            [
                'table' => db_prefix() . 'marketing_suppressions',
                'name' => 'phone',
                'columns' => ['phone'],
            ],
            [
                'table' => db_prefix() . 'marketing_suppressions',
                'name' => 'active_reason',
                'columns' => ['active', 'reason'],
            ],
        ];
    }

    protected function get_index_inventory($table)
    {
        $rows = $this->db->query('SHOW INDEX FROM ' . $this->quote_identifier($table))->result_array();
        $inventory = [];

        foreach ($rows as $row) {
            $keyName = isset($row['Key_name']) ? (string) $row['Key_name'] : '';
            $sequence = isset($row['Seq_in_index']) ? (int) $row['Seq_in_index'] : 0;
            $columnName = isset($row['Column_name']) ? (string) $row['Column_name'] : '';

            if ($keyName === '' || $sequence <= 0 || $columnName === '') {
                continue;
            }

            if (!isset($inventory[$keyName])) {
                $inventory[$keyName] = [];
            }

            $inventory[$keyName][$sequence] = $columnName;
        }

        foreach ($inventory as $keyName => $columns) {
            ksort($columns);
            $inventory[$keyName] = array_values($columns);
        }

        return $inventory;
    }

    protected function index_name_exists($table, $indexName)
    {
        $inventory = $this->get_index_inventory($table);

        return array_key_exists($indexName, $inventory);
    }

    protected function index_columns_exist($table, array $columns)
    {
        $inventory = $this->get_index_inventory($table);

        foreach ($inventory as $indexedColumns) {
            if ($indexedColumns === array_values($columns)) {
                return true;
            }
        }

        return false;
    }

    protected function get_orphan_duplicate_tables()
    {
        $tables = $this->db->list_tables();
        if (!is_array($tables) || empty($tables)) {
            return [];
        }

        $prefix = (string) db_prefix();
        if ($prefix === '') {
            return [];
        }

        $duplicatePrefix = $prefix . $prefix . 'marketing_';
        $orphans = [];

        foreach ($tables as $table) {
            $table = (string) $table;
            if (strpos($table, $duplicatePrefix) !== 0) {
                continue;
            }

            $canonicalTable = substr($table, strlen($prefix));
            $orphans[] = [
                'table' => $table,
                'canonical_table' => $canonicalTable,
                'label' => 'Legacy duplicate table',
                'exists_canonical' => $canonicalTable !== '' ? $this->db->table_exists($canonicalTable) : false,
            ];
        }

        usort($orphans, function ($left, $right) {
            return strcmp((string) ($left['table'] ?? ''), (string) ($right['table'] ?? ''));
        });

        return $orphans;
    }

    protected function log_orphan_table_warnings(array $orphanTables)
    {
        foreach ($orphanTables as $item) {
            $table = (string) ($item['table'] ?? '');
            $canonicalTable = (string) ($item['canonical_table'] ?? '');

            if ($table === '') {
                continue;
            }

            log_message(
                'warning',
                'Marketing schema legacy duplicate table detected: table=' . $table
                . ($canonicalTable !== '' ? ' canonical=' . $canonicalTable : '')
            );
        }
    }
}
