<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_supportinbox', get_string('pluginname', 'local_supportinbox'));
    $ADMIN->add('localplugins', $settings);

    $settings->add(new admin_setting_configtext(
        'local_supportinbox/resend_api_key', 'Resend API key',
        'Same key used by contact-relay.php.', '', PARAM_RAW_TRIMMED
    ));

    $settings->add(new admin_setting_configtext(
        'local_supportinbox/from_email', 'From email',
        'Must be Resend-verified, e.g. info@benzaitenlms.com', '', PARAM_EMAIL
    ));

        // Top-level "Support" tab in Site administration, placed right after Users
    // (so it sits next to Users/Courses/Grades/... instead of being buried
    // under Local plugins).
    $ADMIN->add('root', new admin_category(
        'local_supportinbox_category',
        get_string('pluginname', 'local_supportinbox')
    ), 'courses');

    $ADMIN->add('local_supportinbox_category', new admin_externalpage(
        'local_supportinbox_view',
        get_string('pluginname', 'local_supportinbox'),
        new moodle_url('/local/supportinbox/index.php'),
        'local/supportinbox:manage'
    ));
}
