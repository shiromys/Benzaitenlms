<?php
namespace local_supportinbox;

defined('MOODLE_INTERNAL') || die();

/**
 * Outbound webhooks to the Application Nexus: ticket.created, ticket.updated,
 * message.created, user.deleted (spec section 5, plus the GDPR/CCPA
 * requirement in section 7).
 *
 * Per spec: "Each webhook body contains the event, the app name, a timestamp
 * and the object's id. The Nexus then fetches full details through the
 * normal endpoints." So the wire payload is deliberately minimal — it is
 * not a copy of the ticket/message/user resource.
 */
class webhook_sender {

    const APP_NAME = 'benzaiten';

    /** Seconds to wait before each retry attempt; spans well over 24h. */
    const BACKOFF_SCHEDULE = [60, 300, 900, 3600, 14400, 43200, 86400];

    /**
     * Queue an event and make one immediate best-effort delivery attempt.
     * If that attempt fails, the row is left pending for the scheduled
     * retry task (classes/task/deliver_webhooks.php) to keep trying with
     * backoff. If no webhook URL is configured, this is a silent no-op —
     * the Nexus is expected to fall back to polling updated_since instead.
     */
    public static function enqueue(string $event, string $objectid, array $data = []): void {
        global $DB;

        if (empty(get_config('local_supportinbox', 'nexus_webhook_url'))) {
            return;
        }

        $row = (object)[
            'event' => $event,
            'objectid' => $objectid,
            'payload' => json_encode($data),
            'attempts' => 0,
            'nextattempttime' => time(),
            'status' => 'pending',
            'lasterror' => null,
            'timecreated' => time(),
        ];
        $row->id = $DB->insert_record('local_supportinbox_webhookqueue', $row);

        self::attempt_delivery($row);
    }

    /**
     * Try to deliver one queued webhook. Updates its row with the outcome.
     * Called both for the immediate attempt in enqueue() and by the
     * scheduled retry task.
     */
    public static function attempt_delivery(\stdClass $row): void {
        global $DB;

        $url = get_config('local_supportinbox', 'nexus_webhook_url');
        $secret = get_config('local_supportinbox', 'nexus_webhook_secret');

        if (empty($url)) {
            $DB->update_record('local_supportinbox_webhookqueue', (object)[
                'id' => $row->id, 'status' => 'failed', 'lasterror' => 'No webhook URL configured.',
            ]);
            return;
        }

        $body = json_encode([
            'event' => $row->event,
            'app' => self::APP_NAME,
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'id' => $row->objectid,
        ]);

        $headers = ['Content-Type: application/json'];
        if (!empty($secret)) {
            $signature = hash_hmac('sha256', $body, $secret);
            $headers[] = 'X-Signature: sha256=' . $signature;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlerror = curl_error($ch);
        curl_close($ch);

        $ok = !$curlerror && $httpcode >= 200 && $httpcode < 300;
        $attempts = (int)$row->attempts + 1;

        if ($ok) {
            $DB->update_record('local_supportinbox_webhookqueue', (object)[
                'id' => $row->id, 'status' => 'sent', 'attempts' => $attempts,
            ]);
            return;
        }

        $error = $curlerror ?: ('HTTP ' . $httpcode . ': ' . substr((string)$response, 0, 500));

        if ($attempts >= count(self::BACKOFF_SCHEDULE)) {
            $DB->update_record('local_supportinbox_webhookqueue', (object)[
                'id' => $row->id, 'status' => 'failed', 'attempts' => $attempts, 'lasterror' => $error,
            ]);
            error_log("local_supportinbox webhook_sender: giving up on {$row->event} #{$row->objectid} after {$attempts} attempts: {$error}");
            return;
        }

        $delay = self::BACKOFF_SCHEDULE[$attempts - 1];
        $DB->update_record('local_supportinbox_webhookqueue', (object)[
            'id' => $row->id,
            'status' => 'pending',
            'attempts' => $attempts,
            'nextattempttime' => time() + $delay,
            'lasterror' => $error,
        ]);
    }
}