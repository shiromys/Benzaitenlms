<?php
require_once(__DIR__ . '/../../config.php');

// admin_externalpage_setup() is the standard Moodle helper for admin pages:
// it does require_login(), checks the capability declared on the
// 'local_supportinbox_view' externalpage in settings.php, AND wires in the
// breadcrumb trail + the floating settings-gear navigation that every other
// admin page has.
admin_externalpage_setup('local_supportinbox_view');

$PAGE->set_title('Support Inbox');
$PAGE->set_heading('Support Inbox');

echo $OUTPUT->header();

echo '<style>
.supportinbox-table tr.status-new      { background-color: #fdecea !important; }
.supportinbox-table tr.status-replied  { background-color: #fff8e1 !important; }
.supportinbox-table tr.status-resolved { background-color: #e8f5e9 !important; }
.supportinbox-table td { vertical-align: top; padding: 8px; }
.supportinbox-modal-backdrop {
    display: none;
    position: fixed; top: 0; left: 0; width: 100%; height: 100%;
    background: rgba(0, 0, 0, 0.5); z-index: 9999;
    align-items: center; justify-content: center;
}
.supportinbox-modal-box {
    background: #fff; padding: 24px; border-radius: 6px;
    max-width: 420px; text-align: center; box-shadow: 0 4px 24px rgba(0,0,0,0.3);
}
.supportinbox-modal-box p { margin-bottom: 18px; font-size: 1rem; }
.supportinbox-modal-box .btn { margin: 0 6px; }
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
            ['class' => 'btn btn-success btn-sm supportinbox-resolve-btn']
        );
    }
    echo html_writer::tag('td', $actions);
    echo html_writer::end_tag('tr');
}

echo html_writer::end_tag('tbody');
echo html_writer::end_tag('table');

echo '<div class="supportinbox-modal-backdrop" id="supportinbox-resolve-modal">
  <div class="supportinbox-modal-box">
    <p>Are you sure you want to mark this support case as resolved?</p>
    <button type="button" class="btn btn-success btn-sm" id="supportinbox-resolve-yes">Yes</button>
    <button type="button" class="btn btn-secondary btn-sm" id="supportinbox-resolve-no">No</button>
  </div>
</div>
<script>
(function() {
    var modal = document.getElementById("supportinbox-resolve-modal");
    var yesBtn = document.getElementById("supportinbox-resolve-yes");
    var noBtn = document.getElementById("supportinbox-resolve-no");
    var pendingUrl = null;

    document.querySelectorAll(".supportinbox-resolve-btn").forEach(function(btn) {
        btn.addEventListener("click", function(e) {
            e.preventDefault();
            pendingUrl = btn.getAttribute("href");
            modal.style.display = "flex";
        });
    });

    yesBtn.addEventListener("click", function() {
        if (pendingUrl) {
            window.location.href = pendingUrl;
        }
    });

    noBtn.addEventListener("click", function() {
        modal.style.display = "none";
        pendingUrl = null;
    });
})();
</script>';

echo $OUTPUT->footer();