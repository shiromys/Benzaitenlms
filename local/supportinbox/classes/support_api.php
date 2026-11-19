<?php
namespace local_supportinbox;

defined('MOODLE_INTERNAL') || die();

/**
 * Router + handlers for the /support/v1 Support API.
 *
 * Status mapping note: the ticket table's internal `status` column keeps
 * its original values (new, replied, resolved, closed) for backwards
 * compatibility with index.php/reply.php/resolve.php. This class is the
 * single place that translates those to/from the Nexus spec's enum
 * (open, pending, resolved, closed) - see map_status_out()/map_status_in().
 */
class support_api {

    /** @var \stdClass the authenticated api key record for this request */
    protected static $apikey;

    /** @var string X-Nexus-Agent header value, for logging/attribution */
    protected static $agent;

    const RATE_LIMIT_PER_MINUTE = 60;
    const MAX_PAGE_SIZE = 100;
    const DEFAULT_PAGE_SIZE = 50;
    const ATTACHMENT_LINK_TTL = 900; // 15 minutes, per spec section 7.

    public static function handle_request(): void {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = trim((string)($_SERVER['PATH_INFO'] ?? ''), '/');
        $segments = $path === '' ? [] : explode('/', $path);

        // /health is the one route that doesn't require a key, so monitoring
        // can hit it without provisioning a credential.
        if ($method === 'GET' && ($segments === [] || $segments === ['health'])) {
            self::respond(200, ['status' => 'ok', 'time' => self::iso8601(time())]);
            return;
        }

        self::authenticate();
        self::enforce_rate_limit();

        $idempotencykey = self::header('Idempotency-Key');
        $cached = null;
        if (in_array($method, ['POST', 'PATCH'], true) && $idempotencykey) {
            $cached = self::check_idempotency($idempotencykey, $method, $path);
            if ($cached !== null) {
                self::respond((int)$cached->statuscode, json_decode($cached->responsebody, true), false);
                return;
            }
        }

        [$status, $body] = self::dispatch($method, $segments);

        if (in_array($method, ['POST', 'PATCH'], true) && $idempotencykey) {
            self::store_idempotency($idempotencykey, $method, $path, $status, $body);
        }

        self::respond($status, $body);
    }

    // ------------------------------------------------------------------
    // Routing
    // ------------------------------------------------------------------

    protected static function dispatch(string $method, array $segments): array {
        $root = $segments[0] ?? '';

        if ($root === 'tickets') {
            if (count($segments) === 1 && $method === 'GET') {
                return self::list_tickets();
            }
            if (count($segments) === 2 && $method === 'GET') {
                return self::get_ticket((int)$segments[1]);
            }
            if (count($segments) === 2 && $method === 'PATCH') {
                return self::patch_ticket((int)$segments[1]);
            }
            if (count($segments) === 3 && $segments[2] === 'messages' && $method === 'POST') {
                return self::post_message((int)$segments[1]);
            }
        }

        if ($root === 'users') {
            if (count($segments) === 1 && $method === 'GET') {
                return self::list_users();
            }
            if (count($segments) === 2 && $method === 'GET') {
                return self::get_user((int)$segments[1]);
            }
            if (count($segments) === 3 && $segments[2] === 'tickets' && $method === 'GET') {
                return self::get_user_tickets((int)$segments[1]);
            }
            if (count($segments) === 3 && $segments[2] === 'activity' && $method === 'GET') {
                return self::get_user_activity((int)$segments[1]);
            }
            if (count($segments) === 3 && $segments[2] === 'actions' && $method === 'POST') {
                return self::post_user_action((int)$segments[1]);
            }
        }

        return [404, ['error' => ['code' => 'not_found', 'message' => 'No such endpoint.']]];
    }

    // ------------------------------------------------------------------
    // Auth, rate limiting, idempotency
    // ------------------------------------------------------------------

    protected static function authenticate(): void {
        $auth = self::header('Authorization');
        if (!$auth || stripos($auth, 'Bearer ') !== 0) {
            self::respond(401, ['error' => ['code' => 'unauthorized', 'message' => 'Missing or malformed Authorization header. Expected: Bearer <key>.']]);
        }
        $rawkey = trim(substr($auth, 7));
        $callerip = $_SERVER['REMOTE_ADDR'] ?? null;

        $record = api_key_manager::verify($rawkey, $callerip);
        if (!$record) {
            self::respond(401, ['error' => ['code' => 'unauthorized', 'message' => 'Invalid, revoked, or IP-blocked API key.']]);
        }

        self::$apikey = $record;
        self::$agent = self::header('X-Nexus-Agent') ?: 'unknown';
    }

    protected static function enforce_rate_limit(): void {
        global $DB;
        $windowstart = intdiv(time(), 60) * 60;

        // MySQL-specific upsert: this site's db driver is mysqli (see
        // config.php), and the unique index on (apikeyid, windowstart) makes
        // this atomic across concurrent requests.
        $DB->execute(
            "INSERT INTO {local_supportinbox_ratelimit} (apikeyid, windowstart, requestcount)
             VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE requestcount = requestcount + 1",
            [self::$apikey->id, $windowstart]
        );

        $count = $DB->get_field('local_supportinbox_ratelimit', 'requestcount',
            ['apikeyid' => self::$apikey->id, 'windowstart' => $windowstart]);

        if ($count > self::RATE_LIMIT_PER_MINUTE) {
            header('Retry-After: ' . (60 - (time() - $windowstart)));
            self::respond(429, ['error' => ['code' => 'rate_limited', 'message' => 'Too many requests. Retry after the window resets.']]);
        }
    }

    protected static function check_idempotency(string $key, string $method, string $path): ?\stdClass {
        global $DB;
        $requesthash = self::request_hash($method, $path);
        $record = $DB->get_record('local_supportinbox_idempotency', [
            'apikeyid' => self::$apikey->id,
            'idempotencykey' => $key,
        ]);
        if (!$record) {
            return null;
        }
        // Same idempotency key reused for a materially different request:
        // don't silently replay the wrong cached response.
        if ($record->requesthash !== $requesthash) {
            self::respond(409, ['error' => ['code' => 'idempotency_conflict', 'message' => 'Idempotency-Key was already used for a different request.']]);
        }
        return $record;
    }

    protected static function store_idempotency(string $key, string $method, string $path, int $status, array $body): void {
        global $DB;
        $DB->insert_record('local_supportinbox_idempotency', (object)[
            'apikeyid' => self::$apikey->id,
            'idempotencykey' => $key,
            'requesthash' => self::request_hash($method, $path),
            'statuscode' => $status,
            'responsebody' => json_encode($body),
            'timecreated' => time(),
        ]);
    }

    protected static function request_hash(string $method, string $path): string {
        return hash('sha256', $method . ' ' . $path . ' ' . self::raw_body());
    }

    // ------------------------------------------------------------------
    // Tickets
    // ------------------------------------------------------------------

    protected static function list_tickets(): array {
        global $DB;

        $limit = min(self::MAX_PAGE_SIZE, max(1, (int)(self::query('limit') ?: self::DEFAULT_PAGE_SIZE)));
        $cursor = self::query('cursor');
        $status = self::query('status');
        $assignee = self::query('assignee');
        $updatedsince = self::query('updated_since');
        $q = self::query('q');

        $where = [];
        $params = [];

        if ($cursor !== null && $cursor !== '') {
            $where[] = 'id < ?';
            $params[] = (int)$cursor;
        }
        if ($status) {
            $internal = self::map_status_in($status);
            $where[] = 'status = ?';
            $params[] = $internal;
        }
        if ($assignee) {
            $where[] = 'assignee = ?';
            $params[] = $assignee;
        }
        if ($updatedsince) {
            $ts = strtotime($updatedsince);
            if ($ts !== false) {
                $where[] = 'timemodified >= ?';
                $params[] = $ts;
            }
        }
        if ($q) {
            $where[] = '(' . $DB->sql_like('subject', '?', false) . ' OR ' . $DB->sql_like('message', '?', false) . ' OR ' . $DB->sql_like('email', '?', false) . ')';
            $like = '%' . $DB->sql_like_escape($q) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $sql = "SELECT * FROM {local_supportinbox_messages}";
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY id DESC';

        $rows = $DB->get_records_sql($sql, $params, 0, $limit + 1);
        $rows = array_values($rows);

        $hasmore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        $items = array_map([self::class, 'ticket_to_nexus'], $rows);
        $result = ['items' => $items];
        if ($hasmore) {
            $result['next_cursor'] = (string)end($rows)->id;
        }
        return [200, $result];
    }

    protected static function get_ticket(int $id): array {
        global $DB;
        $ticket = $DB->get_record('local_supportinbox_messages', ['id' => $id]);
        if (!$ticket) {
            return [404, ['error' => ['code' => 'not_found', 'message' => 'Ticket not found.']]];
        }
        $out = self::ticket_to_nexus($ticket);
        $out['messages'] = self::thread_to_nexus($id);
        return [200, $out];
    }

    protected static function patch_ticket(int $id): array {
        global $DB;
        $ticket = $DB->get_record('local_supportinbox_messages', ['id' => $id]);
        if (!$ticket) {
            return [404, ['error' => ['code' => 'not_found', 'message' => 'Ticket not found.']]];
        }

        $input = self::json_body();
        if ($input === null) {
            return [400, ['error' => ['code' => 'bad_request', 'message' => 'Body must be valid JSON.']]];
        }

        $changed = false;

        if (array_key_exists('status', $input)) {
            $internal = self::map_status_in((string)$input['status']);
            if ($internal === null) {
                return [400, ['error' => ['code' => 'bad_request', 'message' => 'status must be one of open, pending, resolved, closed.']]];
            }
            $ticket->status = $internal;
            if ($internal === 'resolved') {
                $ticket->timeresolved = time();
            }
            $changed = true;
        }

        if (array_key_exists('priority', $input)) {
            if (!in_array($input['priority'], ['low', 'normal', 'high', 'urgent'], true)) {
                return [400, ['error' => ['code' => 'bad_request', 'message' => 'priority must be one of low, normal, high, urgent.']]];
            }
            $ticket->priority = $input['priority'];
            $changed = true;
        }

        if (array_key_exists('assignee', $input)) {
            $ticket->assignee = $input['assignee'] === null ? null : (string)$input['assignee'];
            $changed = true;
        }

        if (!$changed) {
            return [400, ['error' => ['code' => 'bad_request', 'message' => 'Provide at least one of status, priority, assignee.']]];
        }

        $ticket->timemodified = time();
        $DB->update_record('local_supportinbox_messages', $ticket);

        webhook_sender::enqueue('ticket.updated', (string)$ticket->id, self::ticket_to_nexus($ticket));

        return [200, self::ticket_to_nexus($ticket)];
    }

    protected static function post_message(int $ticketid): array {
        global $DB;
        $ticket = $DB->get_record('local_supportinbox_messages', ['id' => $ticketid]);
        if (!$ticket) {
            return [404, ['error' => ['code' => 'not_found', 'message' => 'Ticket not found.']]];
        }

        $input = self::json_body();
        if ($input === null || empty($input['body_text'])) {
            return [400, ['error' => ['code' => 'bad_request', 'message' => 'body_text is required.']]];
        }
        $type = $input['type'] ?? 'reply';
        if (!in_array($type, ['reply', 'note'], true)) {
            return [400, ['error' => ['code' => 'bad_request', 'message' => 'type must be reply or note.']]];
        }

        $message = (object)[
            'ticketid' => $ticketid,
            'type' => $type,
            'author' => self::$agent,
            'body_text' => $input['body_text'],
            'body_html' => $input['body_html'] ?? null,
            'timecreated' => time(),
        ];
        $message->id = $DB->insert_record('local_supportinbox_thread', $message);

        $ticket->timemodified = time();

        if ($type === 'reply') {
            $sent = self::send_reply_email($ticket, $input['body_text'], $input['body_html'] ?? null);
            if (!$sent) {
                return [502, ['error' => ['code' => 'upstream_email_failed', 'message' => 'Message was recorded but the email to the user could not be sent. Check the server log.']]];
            }
            if ($ticket->status === 'new') {
                $ticket->status = 'replied';
            }
            $ticket->timereplied = time();
            $ticket->reply_body = $input['body_text']; // backwards compatibility with index.php's old column.
        }

        $DB->update_record('local_supportinbox_messages', $ticket);

        webhook_sender::enqueue('message.created', (string)$message->id, self::message_to_nexus($message));
        webhook_sender::enqueue('ticket.updated', (string)$ticket->id, self::ticket_to_nexus($ticket));

        return [201, self::message_to_nexus($message)];
    }

    protected static function send_reply_email(\stdClass $ticket, string $text, ?string $html): bool {
        $apikey = get_config('local_supportinbox', 'resend_api_key');
        $from = get_config('local_supportinbox', 'from_email') ?: 'info@benzaitenlms.com';
        if (empty($apikey)) {
            error_log('local_supportinbox support_api: resend_api_key is not configured; cannot send reply.');
            return false;
        }

        $payload = [
            'from' => "Support <{$from}>",
            'to' => [$ticket->email],
            'subject' => 'Re: ' . ($ticket->subject ?: 'your support request'),
            'text' => $text,
        ];
        if ($html) {
            $payload['html'] = $html;
        }

        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apikey,