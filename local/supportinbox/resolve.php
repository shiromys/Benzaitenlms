<?php
require_once(__DIR__ . '/../../config.php');
require_login();

$context = context_system::instance();
require_capability('local/supportinbox:manage', $context);

$id = required_param('id', PARAM_INT);
require_sesskey();

$message = $DB->get_record('local_supportinbox_messages', ['id' => $id], '*', MUST_EXIST);
$message->status = 'resolved';
$message->timeresolved = time();
$DB->update_record('local_supportinbox_messages', $message);

redirect(new moodle_url('/local/supportinbox/index.php'), 'Marked resolved.');