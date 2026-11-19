<?php
defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_supportinbox_submit_message' => [
        'classname'   => 'local_supportinbox\external\submit_message',
        'methodname'  => 'execute',
        'description' => 'Insert a support message',
        'type'        => 'write',
        'ajax'        => false,
    ],
];

$services = [
    'Support Inbox Ingest' => [
        'functions' => ['local_supportinbox_submit_message'],
        'restrictedusers' => 1,
        'enabled' => 1,
        'shortname' => 'supportinboxingest',
    ],
];