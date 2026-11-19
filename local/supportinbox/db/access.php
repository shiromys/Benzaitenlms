<?php
defined('MOODLE_INTERNAL') || die();

$capabilities = [
    'local/supportinbox:manage' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],
];