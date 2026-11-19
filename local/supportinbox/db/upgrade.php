<?php
defined('MOODLE_INTERNAL') || die();

/**
 * local_supportinbox upgrade steps.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_supportinbox_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    // Nexus Support API: add ticket-level columns (priority, assignee, timemodified)
    // and the new child/support tables (thread, apikeys, idempotency, ratelimit,
    // auditlog, webhookqueue), then backfill the thread table from the old
    // single message/reply_body columns so existing tickets keep their history.
    if ($oldversion < 2026111910) {

        $table = new xmldb_table('local_supportinbox_messages');

        $field = new xmldb_field('priority', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'normal', 'status');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('assignee', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'priority');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'timecreated');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $index = new xmldb_index('timemodified', XMLDB_INDEX_NOTUNIQUE, ['timemodified']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        $index = new xmldb_index('assignee', XMLDB_INDEX_NOTUNIQUE, ['assignee']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        $index = new xmldb_index('email', XMLDB_INDEX_NOTUNIQUE, ['email']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // Backfill timemodified for existing rows so it isn't 0 for old tickets.
        $DB->execute("UPDATE {local_supportinbox_messages} SET timemodified = timecreated WHERE timemodified = 0");

        $thread = new xmldb_table('local_supportinbox_thread');
        if (!$dbman->table_exists($thread)) {
            $thread->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $thread->add_field('ticketid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $thread->add_field('type', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
            $thread->add_field('author', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $thread->add_field('body_text', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $thread->add_field('body_html', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $thread->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $thread->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $thread->add_index('ticketid_timecreated', XMLDB_INDEX_NOTUNIQUE, ['ticketid', 'timecreated']);
            $dbman->create_table($thread);
        }

        $apikeys = new xmldb_table('local_supportinbox_apikeys');
        if (!$dbman->table_exists($apikeys)) {
            $apikeys->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $apikeys->add_field('label', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $apikeys->add_field('environment', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'staging');
            $apikeys->add_field('keyhash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
            $apikeys->add_field('keyprefix', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, null);
            $apikeys->add_field('scope', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'support');
            $apikeys->add_field('ipallowlist', XMLDB_TYPE_CHAR, '1000', null, null, null, null);
            $apikeys->add_field('revoked', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $apikeys->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $apikeys->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $apikeys->add_field('timerevoked', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $apikeys->add_field('lastusedtime', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $apikeys->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $apikeys->add_index('keyhash', XMLDB_INDEX_UNIQUE, ['keyhash']);
            $dbman->create_table($apikeys);
        }

        $idem = new xmldb_table('local_supportinbox_idempotency');
        if (!$dbman->table_exists($idem)) {
            $idem->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $idem->add_field('apikeyid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $idem->add_field('idempotencykey', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $idem->add_field('requesthash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
            $idem->add_field('statuscode', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $idem->add_field('responsebody', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $idem->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $idem->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $idem->add_index('apikeyid_idempotencykey', XMLDB_INDEX_UNIQUE, ['apikeyid', 'idempotencykey']);
            $dbman->create_table($idem);
        }

        $rl = new xmldb_table('local_supportinbox_ratelimit');
        if (!$dbman->table_exists($rl)) {
            $rl->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $rl->add_field('apikeyid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $rl->add_field('windowstart', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $rl->add_field('requestcount', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $rl->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $rl->add_index('apikeyid_windowstart', XMLDB_INDEX_UNIQUE, ['apikeyid', 'windowstart']);
            $dbman->create_table($rl);
        }

        $audit = new xmldb_table('local_supportinbox_auditlog');
        if (!$dbman->table_exists($audit)) {
            $audit->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $audit->add_field('apikeyid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $audit->add_field('agent', XMLDB_TYPE_CHAR, '255', null, null, null, null);
            $audit->add_field('method', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
            $audit->add_field('path', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $audit->add_field('statuscode', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $audit->add_field('ip', XMLDB_TYPE_CHAR, '45', null, null, null, null);
            $audit->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $audit->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $audit->add_index('timecreated', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);
            $audit->add_index('apikeyid', XMLDB_INDEX_NOTUNIQUE, ['apikeyid']);
            $dbman->create_table($audit);
        }

        $wq = new xmldb_table('local_supportinbox_webhookqueue');
        if (!$dbman->table_exists($wq)) {
            $wq->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $wq->add_field('event', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
            $wq->add_field('objectid', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $wq->add_field('payload', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $wq->add_field('attempts', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $wq->add_field('nextattempttime', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $wq->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'pending');
            $wq->add_field('lasterror', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $wq->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $wq->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $wq->add_index('status_nextattempttime', XMLDB_INDEX_NOTUNIQUE, ['status', 'nextattempttime']);
            $dbman->create_table($wq);
        }

        // Backfill the thread table from the old single message/reply_body columns,
        // but only once: skip any ticket that already has thread rows (re-runs,
        // or tickets created after this upgrade already use the thread table).
        $tickets = $DB->get_recordset('local_supportinbox_messages');
        foreach ($tickets as $ticket) {
            if ($DB->record_exists('local_supportinbox_thread', ['ticketid' => $ticket->id])) {
                continue;
            }
            $DB->insert_record('local_supportinbox_thread', (object)[
                'ticketid'   => $ticket->id,
                'type'       => 'user',
                'author'     => $ticket->name,
                'body_text'  => $ticket->message,
                'body_html'  => null,
                'timecreated' => $ticket->timecreated,
            ]);
            if (!empty($ticket->reply_body)) {
                $DB->insert_record('local_supportinbox_thread', (object)[
                    'ticketid'   => $ticket->id,
                    'type'       => 'reply',
                    'author'     => 'support',
                    'body_text'  => $ticket->reply_body,
                    'body_html'  => null,
                    'timecreated' => $ticket->timereplied ?: $ticket->timecreated,
                ]);
            }
        }
        $tickets->close();

        upgrade_plugin_savepoint(true, 2026111910, 'local', 'supportinbox');
    }

    return true;
}