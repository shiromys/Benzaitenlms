<?php
defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\core\event\user_deleted',
        'callback' => '\local_supportinbox\observer::user_deleted',
    ],
];