<?php
namespace local_supportinbox;

defined('MOODLE_INTERNAL') || die();

/**
 * Event observers. Currently just the GDPR/CCPA deletion notice required by
 * spec section 7 ("Apps must tell the Nexus about user deletions ... through
 * a user.deleted webhook").
 */
class observer {

    public static function user_deleted(\core\event\user_deleted $event): void {
        webhook_sender::enqueue('user.deleted', (string)$event->objectid, []);
    }
}