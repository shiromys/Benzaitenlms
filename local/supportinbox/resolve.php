<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$id = required_param('id', PARAM_INT);

admin_externalpage_setup('local_supportinbox_view');
$PAGE->set_url('/local/supportinbox/resolve.php', ['id' => $id]);

require_sesskey();

$message = $DB->get_record('local_supportinbox_messages', ['id' => $id], '*', MUST_EXIST);
$message->status = 'resolved';
$message->timeresolved = time();
$DB->update_record('local_supportinbox_messages', $message);

redirect(new moodle_url('/local/supportinbox/index.php'), 'Marked resolved.');