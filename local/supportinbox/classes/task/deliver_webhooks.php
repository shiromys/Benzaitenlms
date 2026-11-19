<?php
namespace local_supportinbox\task;

defined('MOODLE_INTERNAL') || die();

use local_supportinbox\webhook_sender;

/**
 * Runs every minute (see db/tasks.php) and retries any webhook delivery
 * that is due and hasn't exhausted its backoff schedule yet.
 */
class deliver_webhooks extends \core\task\scheduled_task {

    public function get_name() {
        return get_string('task_deliver_webhooks', 'local_supportinbox');
    }

    public function execute() {
        global $DB;

        $due = $DB->get_records_select(
            'local_supportinbox_webhookqueue',
            'status = ? AND nextattempttime <= ?',
            ['pending', time()],
            'nextattempttime ASC',
            '*',
            0,
            50 // Don't let one slow run try to drain an unbounded backlog.
        );

        foreach ($due as $row) {
            webhook_sender::attempt_delivery($row);
        }
    }
}