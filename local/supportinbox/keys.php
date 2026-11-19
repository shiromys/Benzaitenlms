<?php
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/../../lib/formslib.php');

require_login();

$context = context_system::instance();
require_capability('local/supportinbox:managekeys', $context);

$PAGE->set_url('/local/supportinbox/keys.php');
$PAGE->set_context($context);
$PAGE->set_title(get_string('apikeys', 'local_supportinbox'));
$PAGE->set_heading(get_string('apikeys', 'local_supportinbox'));
$PAGE->set_pagelayout('admin');

$revokeid = optional_param('revoke', 0, PARAM_INT);
$justcreatedraw = null;

// ---- Handle revoke --------------------------------------------------------
if ($revokeid && confirm_sesskey()) {
    \local_supportinbox\api_key_manager::revoke($revokeid);
    redirect(new moodle_url('/local/supportinbox/keys.php'), get_string('apikeys_revoked', 'local_supportinbox'));
}

// ---- Handle create ----------------------------------------------------------
if (optional_param('createkey', 0, PARAM_BOOL) && confirm_sesskey()) {
    $label       = required_param('label', PARAM_TEXT);
    $environment = required_param('environment', PARAM_ALPHA);
    $ipallowlist = optional_param('ipallowlist', '', PARAM_TEXT);

    if ($label !== '' && in_array($environment, ['staging', 'production'], true)) {
        $result = \local_supportinbox\api_key_manager::create($label, $environment, $USER->id, $ipallowlist);
        $justcreatedraw = $result['rawkey'];
    }
}

// ---- Handle webhook settings save -----------------------------------------
if (optional_param('savewebhook', 0, PARAM_BOOL) && confirm_sesskey()) {
    $webhookurl    = optional_param('webhookurl', '', PARAM_URL);
    $webhooksecret = optional_param('webhooksecret', '', PARAM_RAW_TRIMMED);
    set_config('nexus_webhook_url', $webhookurl, 'local_supportinbox');
    if ($webhooksecret !== '') {
        // Only overwrite the secret if the admin actually typed a new one;
        // the field is always rendered blank so an existing secret is never
        // echoed back to the browser.
        set_config('nexus_webhook_secret', $webhooksecret, 'local_supportinbox');
    }
    redirect(new moodle_url('/local/supportinbox/keys.php'), get_string('changessaved'));
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('apikeys', 'local_supportinbox'));
echo html_writer::tag('p', get_string('apikeys_intro', 'local_supportinbox'));

// ---- One-time raw key banner ------------------------------------------------
if ($justcreatedraw !== null) {
    echo html_writer::start_div('alert alert-success');
    echo html_writer::tag('h4', get_string('apikeys_created_heading', 'local_supportinbox'));
    echo html_writer::tag('p', get_string('apikeys_created_warning', 'local_supportinbox'));
    echo html_writer::tag('pre', s($justcreatedraw), [
        'style' => 'font-size:1.1em; padding:10px; background:#fff; border:1px solid #ccc; user-select:all;',
    ]);
    echo html_writer::tag('p', html_writer::tag('strong', 'Authorization: Bearer ' . s($justcreatedraw)));
    echo html_writer::end_div();
}

// ---- Create form --------------------------------------------------------------
echo html_writer::tag('h4', get_string('apikeys_create', 'local_supportinbox'));
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $PAGE->url->out(false)]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'createkey', 'value' => '1']);

echo html_writer::start_div('form-group');
echo html_writer::tag('label', get_string('apikeys_label', 'local_supportinbox'), ['for' => 'id_label']);
echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'label', 'id' => 'id_label', 'class' => 'form-control',
    'placeholder' => 'Application Nexus - production', 'required' => 'required',
]);
echo html_writer::end_div();

echo html_writer::start_div('form-group');
echo html_writer::tag('label', get_string('apikeys_environment', 'local_supportinbox'), ['for' => 'id_environment']);
echo html_writer::start_tag('select', ['name' => 'environment', 'id' => 'id_environment', 'class' => 'form-control']);
echo html_writer::tag('option', 'staging', ['value' => 'staging']);
echo html_writer::tag('option', 'production', ['value' => 'production']);
echo html_writer::end_tag('select');
echo html_writer::end_div();

echo html_writer::start_div('form-group');
echo html_writer::tag('label', get_string('apikeys_ipallowlist', 'local_supportinbox'), ['for' => 'id_ipallowlist']);
echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'ipallowlist', 'id' => 'id_ipallowlist', 'class' => 'form-control',
    'placeholder' => '203.0.113.10, 203.0.113.11',
]);
echo html_writer::end_div();

echo html_writer::empty_tag('input', [
    'type' => 'submit', 'value' => get_string('apikeys_create', 'local_supportinbox'), 'class' => 'btn btn-primary mt-2',
]);
echo html_writer::end_tag('form');

// ---- Existing keys table -------------------------------------------------------
echo html_writer::tag('h4', get_string('apikeys_existing', 'local_supportinbox'), ['class' => 'mt-4']);
$keys = \local_supportinbox\api_key_manager::list_all();

if (empty($keys)) {
    echo html_writer::tag('p', get_string('apikeys_none', 'local_supportinbox'));
} else {
    echo html_writer::start_tag('table', ['class' => 'generaltable']);
    echo html_writer::tag('thead', html_writer::tag('tr',
        html_writer::tag('th', get_string('apikeys_label', 'local_supportinbox')) .
        html_writer::tag('th', get_string('apikeys_environment', 'local_supportinbox')) .
        html_writer::tag('th', 'Key prefix') .
        html_writer::tag('th', 'Created') .
        html_writer::tag('th', get_string('apikeys_lastused', 'local_supportinbox')) .
        html_writer::tag('th', 'Status') .
        html_writer::tag('th', 'Actions')
    ));
    echo html_writer::start_tag('tbody');
    foreach ($keys as $k) {
        $status = $k->revoked
            ? html_writer::tag('span', get_string('apikeys_status_revoked', 'local_supportinbox'), ['class' => 'badge badge-danger'])
            : html_writer::tag('span', get_string('apikeys_status_active', 'local_supportinbox'), ['class' => 'badge badge-success']);
        $lastused = $k->lastusedtime ? userdate($k->lastusedtime, '%d %b %Y %H:%M') : get_string('apikeys_lastused_never', 'local_supportinbox');
        $actions = $k->revoked ? '' : html_writer::link(
            new moodle_url('/local/supportinbox/keys.php', ['revoke' => $k->id, 'sesskey' => sesskey()]),
            get_string('apikeys_revoke', 'local_supportinbox'),
            ['class' => 'btn btn-outline-danger btn-sm', 'onclick' => 'return confirm(' . json_encode(get_string('apikeys_revoke_confirm', 'local_supportinbox')) . ');']
        );
        echo html_writer::tag('tr',
            html_writer::tag('td', s($k->label)) .
            html_writer::tag('td', s($k->environment)) .
            html_writer::tag('td', html_writer::tag('code', s($k->keyprefix) . '...')) .
            html_writer::tag('td', userdate($k->timecreated, '%d %b %Y %H:%M')) .
            html_writer::tag('td', $lastused) .
            html_writer::tag('td', $status) .
            html_writer::tag('td', $actions)
        );
    }
    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
}

// ---- Webhook settings ---------------------------------------------------------
echo html_writer::tag('h4', get_string('apikeys_webhook_heading', 'local_supportinbox'), ['class' => 'mt-4']);
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $PAGE->url->out(false)]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'savewebhook', 'value' => '1']);

echo html_writer::start_div('form-group');
echo html_writer::tag('label', get_string('apikeys_webhook_url', 'local_supportinbox'), ['for' => 'id_webhookurl']);
echo html_writer::empty_tag('input', [
    'type' => 'url', 'name' => 'webhookurl', 'id' => 'id_webhookurl', 'class' => 'form-control',
    'value' => s(get_config('local_supportinbox', 'nexus_webhook_url')),
    'placeholder' => 'https://nexus.example.com/webhooks/benzaiten',
]);
echo html_writer::tag('small', get_string('apikeys_webhook_url_desc', 'local_supportinbox'), ['class' => 'form-text text-muted']);
echo html_writer::end_div();

echo html_writer::start_div('form-group');
echo html_writer::tag('label', get_string('apikeys_webhook_secret', 'local_supportinbox'), ['for' => 'id_webhooksecret']);
echo html_writer::empty_tag('input', [
    'type' => 'password', 'name' => 'webhooksecret', 'id' => 'id_webhooksecret', 'class' => 'form-control',
    'placeholder' => get_config('local_supportinbox', 'nexus_webhook_secret') ? '(unchanged)' : '',
    'autocomplete' => 'new-password',
]);
echo html_writer::tag('small', get_string('apikeys_webhook_secret_desc', 'local_supportinbox'), ['class' => 'form-text text-muted']);
echo html_writer::end_div();

echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('savechanges'), 'class' => 'btn btn-secondary mt-2']);
echo html_writer::end_tag('form');

echo $OUTPUT->footer();