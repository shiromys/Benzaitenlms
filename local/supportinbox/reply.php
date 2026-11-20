<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$id = required_param('id', PARAM_INT);

$id = required_param('id', PARAM_INT);

admin_externalpage_setup('local_supportinbox_view');

$message = $DB->get_record('local_supportinbox_messages', ['id' => $id], '*', MUST_EXIST);

$PAGE->set_url('/local/supportinbox/reply.php', ['id' => $id]);
$PAGE->set_title('Reply to ' . $message->email);

if (optional_param('submit', 0, PARAM_BOOL) && confirm_sesskey()) {
    $replytext = required_param('replytext', PARAM_RAW);

    $apikey = get_config('local_supportinbox', 'resend_api_key');
    $from = get_config('local_supportinbox', 'from_email');

    $payload = json_encode([
        'from'    => "Support <{$from}>",
        'to'      => [$message->email],
        'subject' => 'Re: ' . $message->subject,
        'text'    => $replytext,
    ]);

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apikey,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlerror = curl_error($ch);
    curl_close($ch);

    if (!$curlerror && $httpcode >= 200 && $httpcode < 300) {
        $message->status = 'replied';
        $message->reply_body = $replytext;
        $message->timereplied = time();
        // Nexus Support API: keep this manual-reply path in step with what
        // POST /tickets/{id}/messages does, so a reply sent from this admin
        // page is just as visible to the Application Nexus as one sent
        // through the API - same timemodified bump, same thread row, same
        // webhook notification.
        $message->timemodified = time();
        $DB->update_record('local_supportinbox_messages', $message);

        $threadrow = (object)[
            'ticketid'   => $message->id,
            'type'       => 'reply',
            'author'     => fullname($USER),
            'body_text'  => $replytext,
            'body_html'  => null,
            'timecreated' => time(),
        ];
        $threadrow->id = $DB->insert_record('local_supportinbox_thread', $threadrow);

        \local_supportinbox\webhook_sender::enqueue('message.created', (string)$threadrow->id, []);
        \local_supportinbox\webhook_sender::enqueue('ticket.updated', (string)$message->id, []);

        redirect(new moodle_url('/local/supportinbox/index.php'), 'Reply sent.');
    } else {
        error_log('local_supportinbox: Resend reply failed, HTTP ' . $httpcode . ' curl_error=' . $curlerror . ' response=' . $response);
        \core\notification::error('Could not send reply. Check the server log.');
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading('Reply to ' . s($message->name) . ' &lt;' . s($message->email) . '&gt;');
echo html_writer::tag('p', '<strong>Original message:</strong><br>' . nl2br(s($message->message)));

echo html_writer::start_tag('form', ['method' => 'post']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::tag('textarea', '', ['name' => 'replytext', 'rows' => 8, 'style' => 'width:100%']);
echo html_writer::empty_tag('input', [
    'type' => 'submit', 'name' => 'submit', 'value' => 'Send reply', 'class' => 'btn btn-primary mt-2',
]);
echo html_writer::end_tag('form');

echo $OUTPUT->footer();