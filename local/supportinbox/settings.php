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

    $ADMIN->add('localplugins', new admin_externalpage(
        'local_supportinbox_view', 'Support Inbox',
        new moodle_url('/local/supportinbox/index.php'), 'local/supportinbox:manage'
    ));
}