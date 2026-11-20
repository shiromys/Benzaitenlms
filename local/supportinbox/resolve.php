<?php
require_once(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);

admin_externalpage_setup('local_supportinbox_view');
$PAGE->set_url('/local/supportinbox/resolve.php', ['id' => $id]);

require_sesskey();

$message = $DB->get_record('local_supportinbox_messages', ['id' => $id], '*', MUST_EXIST);
$message->status = 'resolved';
$message->timeresolved = time();
// Nexus Support API: without this, a ticket resolved from this admin page
// would never show as updated to the Application Nexus - neither its
// updated_at field nor updated_since= polling would catch it, and it
// wouldn't get a ticket.updated webhook either.
$message->timemodified = time();
$DB->update_record('local_supportinbox_messages', $message);

\local_supportinbox\webhook_sender::enqueue('ticket.updated', (string)$message->id, []);

redirect(new moodle_url('/local/supportinbox/index.php'), 'Marked resolved.');