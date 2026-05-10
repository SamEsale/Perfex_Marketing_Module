<?php

defined('BASEPATH') or exit('No direct script access allowed');

$moduleOptions = function_exists('marketing_get_option_names')
    ? marketing_get_option_names()
    : [];

foreach ($moduleOptions as $option) {
    delete_option($option);
}

$legacyOptions = [
    'marketing_smtp_' . 'crypto',
    'marketing_smtp_' . 'aws_configuration_set',
];

foreach ($legacyOptions as $option) {
    delete_option($option);
}

require_once __DIR__ . '/migrations/001_initial_schema.php';

$migration = new Migration_Initial_schema();
$migration->down();
