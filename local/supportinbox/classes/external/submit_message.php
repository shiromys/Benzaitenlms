<?php
namespace local_supportinbox\external;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;

class submit_message extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([
            'name'    => new external_value(PARAM_TEXT, 'Sender name'),
            'email'   => new external_value(PARAM_EMAIL, 'Sender email'),
            'subject' => new external_value(PARAM_TEXT, 'Subject', VALUE_DEFAULT, ''),
            'message' => new external_value(PARAM_RAW, 'Message body'),
            'source'  => new external_value(PARAM_ALPHANUMEXT, 'contact_form or direct_mail', VALUE_DEFAULT, 'contact_form'),
        ]);
    }

    public static function execute($name, $email, $subject, $message, $source) {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'name' => $name,
            'email' => $email,
            'subject' => $subject,
            'message' => $message,
            'source' => $source,
        ]);

        $record = new \stdClass();
        $record->name = $params['name'];
        $record->email = $params['email'];
        $record->subject = $params['subject'];
        $record->message = $params['message'];
        $record->source = $params['source'];
        $record->status = 'new';
        $record->priority = 'normal';
        $record->timecreated = time();
        $record->timemodified = time();

        $id = $DB->insert_record('local_supportinbox_messages', $record);
        $record->id = $id;

        $threadrow = (object)[
            'ticketid' => $id,
            'type' => 'user',
            'author' => $params['name'],
            'body_text' => $params['message'],
            'body_html' => null,
            'timecreated' => $record->timecreated,
        ];
        $threadrow->id = $DB->insert_record('local_supportinbox_thread', $threadrow);

        // Notify the Application Nexus (or any other configured webhook
        // consumer) that a new ticket - and its first message - exist.
        \local_supportinbox\webhook_sender::enqueue('ticket.created', (string)$id, []);
        \local_supportinbox\webhook_sender::enqueue('message.created', (string)$threadrow->id, []);

        return ['id' => $id];
    }

    public static function execute_returns() {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'New row id'),
        ]);
    }
}