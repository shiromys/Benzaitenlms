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
    // Deliberately separate from :manage above. Being able to read/reply to
    // tickets does not imply being trusted to mint bearer keys that let an
    // external system (the Application Nexus) call the support API — that's
    // a higher-trust, infrastructure-level action, so it defaults to nobody
    // and must be granted explicitly (normally only to the site admin role).
    'local/supportinbox:managekeys' => [
        'riskbitmask' => RISK_PERSONAL | RISK_CONFIG,
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [],
    ],
];