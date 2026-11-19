<?php
require_once(__DIR__ . '/../../config.php');
require_login();

$context = context_system::instance();
require_capability('local/supportinbox:manage', $context);

$PAGE->set_url('/local/supportinbox/index.php');
$PAGE->set_context($context);
$PAGE->set_title('Support Inbox');
$PAGE->set_heading('Support Inbox');

echo $OUTPUT->header();

echo '<style>
.supportinbox-table tr.status-new      { background-color: #fdecea !important; }
.supportinbox-table tr.status-replied  { background-color: #fff8e1 !important; }
.supportinbox-table tr.status-resolved { background-color: #e8f5e9 !important; }
.supportinbox-table td { vertical-align: top; padding: 8px; }
</style>';

$messages = $DB->get_records('local_supportinbox_messages', null, 'timecreated DESC');

echo html_writer::start_tag('table', ['class' => 'generaltable supportinbox-table']);
echo html_writer::start_tag('thead');
echo html_writer::tag('tr',
    html_writer::tag('th', 'Received') .
    html_writer::tag('th', 'Source') .
    html_writer::tag('th', 'From') .
    html_writer::tag('th', 'Subject') .
    html_writer::tag('th', 'Message') .
    html_writer::tag('th', 'Status') .
    html_writer::tag('th', 'Actions')
);
echo html_writer::end_tag('thead');
echo html_writer::start_tag('tbody');

foreach ($messages as $m) {
    $rowclass = 'status-' . $m->status;
    echo html_writer::start_tag('tr', ['class' => $rowclass]);
    echo html_writer::tag('td', userdate($m->timecreated, '%d %b %Y %H:%M'));
    echo html_writer::tag('td', s($m->source));
    echo html_writer::tag('td', s($m->name) . '<br><small>' . s($m->email) . '</small>');
    echo html_writer::tag('td', s($m->subject));
    echo html_writer::tag('td', nl2br(s($m->message)));
    echo html_writer::tag('td', s($m->status));

    $actions = html_writer::link(
        new moodle_url('/local/supportinbox/reply.php', ['id' => $m->id]),
        'Reply',
        ['class' => 'btn btn-primary btn-sm']
    );
    $actions .= ' ';
    if ($m->status !== 'resolved') {
        $actions .= html_writer::link(
            new moodle_url('/local/supportinbox/resolve.php', ['id' => $m->id, 'sesskey' => sesskey()]),
            'Mark Resolved',
            ['class' => 'btn btn-success btn-sm']
        );
    }
    echo html_writer::tag('td', $actions);
    echo html_writer::end_tag('tr');
}

echo html_writer::end_tag('tbody');
echo html_writer::end_tag('table');

echo $OUTPUT->footer();