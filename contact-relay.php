<?php
/**
 * Benzaiten LMS — contact form relay (Moodle backend, Resend HTTP API)
 *
 * IMPORTANT: this file is 100% standalone. It does NOT include Moodle's
 * config.php, does NOT touch the database, and does NOT use any Moodle
 * internals — it's just a plain PHP script that happens to live on the
 * same server/domain (api.benzaitenlms.com) for convenience, reusing the
 * same Resend account Moodle's outgoing mail already uses. There is no
 * path by which this can affect Moodle itself (the earlier outage was
 * caused by editing Moodle's Additional HTML in the database — this
 * script is unrelated to that and carries none of that risk).
 *
 * WHERE THIS GOES
 * Add this file to wherever your Dockerfile copies Moodle's PHP source
 * into the image (commonly /var/www/html or similar), so it ends up
 * reachable at:  https://api.benzaitenlms.com/contact-relay.php
 *
 * ENV VARS TO SET on the "Backend" Railway service (Variables tab):
 *   RESEND_API_KEY   - the SAME key already used as the SMTP password
 *                       in Moodle's Site administration -> Server ->
 *                       Email -> Outgoing mail configuration.
 *   FROM_EMAIL        - e.g. "Benzaiten LMS Contact Form <contact@benzaitenlms.com>"
 *                       (must be on the domain already verified in Resend)
 *   TO_EMAIL           - e.g. info@benzaitenlms.com
 *   ALLOWED_ORIGIN     - e.g. https://benzaitenlms.com
 */

// ---- config ----
$RESEND_API_KEY = getenv('RESEND_API_KEY') ?: '';
$FROM_EMAIL     = getenv('FROM_EMAIL') ?: 'Benzaiten LMS Contact Form <contact@benzaitenlms.com>';
$TO_EMAIL       = getenv('TO_EMAIL') ?: 'info@benzaitenlms.com';
$ALLOWED_ORIGIN = getenv('ALLOWED_ORIGIN') ?: 'https://benzaitenlms.com';

// ---- CORS (the form is on benzaitenlms.com, this endpoint is on api.benzaitenlms.com) ----
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
if ($origin === $ALLOWED_ORIGIN || $origin === 'https://www.' . ltrim(str_replace('https://', '', $ALLOWED_ORIGIN), 'www.')) {
    header('Access-Control-Allow-Origin: ' . $origin);
} else {
    header('Access-Control-Allow-Origin: ' . $ALLOWED_ORIGIN);
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Vary: Origin');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

function respond($code, $body) {
    http_response_code($code);
    echo json_encode($body);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
}

// ---- basic per-IP rate limit: 5 submissions per 10 minutes ----
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rlFile = sys_get_temp_dir() . '/bz_contact_rl_' . md5($ip) . '.json';
$now = time();
$windowSeconds = 600;
$maxHits = 5;
$hits = [];
if (is_file($rlFile)) {
    $decoded = json_decode(@file_get_contents($rlFile), true);
    if (is_array($decoded)) $hits = $decoded;
}
$hits = array_values(array_filter($hits, function ($t) use ($now, $windowSeconds) {
    return ($now - $t) < $windowSeconds;
}));
if (count($hits) >= $maxHits) {
    respond(429, ['ok' => false, 'error' => 'Too many messages sent — please try again in a few minutes.']);
}

// ---- parse + validate input ----
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) $data = $_POST; // fallback if posted as form-data

$name    = trim((string)($data['name'] ?? ''));
$email   = trim((string)($data['email'] ?? ''));
$subject = trim((string)($data['subject'] ?? ''));
$message = trim((string)($data['message'] ?? ''));
$gotcha  = trim((string)($data['_gotcha'] ?? ''));

// honeypot: bots fill this hidden field — silently "succeed" without sending
if ($gotcha !== '') {
    respond(200, ['ok' => true]);
}

if ($name === '' || $email === '' || $subject === '' || $message === '') {
    respond(400, ['ok' => false, 'error' => 'Please fill in all required fields.']);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(400, ['ok' => false, 'error' => 'Please enter a valid email address.']);
}
if (strlen($name) > 200 || strlen($subject) > 200 || strlen($message) > 5000) {
    respond(400, ['ok' => false, 'error' => 'One of the fields is too long.']);
}

if ($RESEND_API_KEY === '') {
    error_log('contact-relay: RESEND_API_KEY is not set');
    respond(500, ['ok' => false, 'error' => 'Something went wrong. Please try again or email info@benzaitenlms.com directly.']);
}

function esc_html($s) {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

$html = '<h2>New message from benzaitenlms.com contact form</h2>'
      . '<p><strong>Name:</strong> ' . esc_html($name) . '</p>'
      . '<p><strong>Email:</strong> ' . esc_html($email) . '</p>'
      . '<p><strong>Subject:</strong> ' . esc_html($subject) . '</p>'
      . '<p><strong>Message:</strong></p><p>' . nl2br(esc_html($message)) . '</p>';

$payload = json_encode([
    'from'     => $FROM_EMAIL,
    'to'       => [$TO_EMAIL],
    'reply_to' => $email,
    'subject'  => '[Contact form] ' . $subject,
    'html'     => $html,
]);

$ch = curl_init('https://api.resend.com/emails');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $RESEND_API_KEY,
        'Content-Type: application/json',
    ],
    CURLOPT_TIMEOUT        => 15,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr || $httpCode < 200 || $httpCode >= 300) {
    error_log('contact-relay: Resend send failed, http=' . $httpCode . ' curl_err=' . $curlErr . ' body=' . $response);
    respond(502, ['ok' => false, 'error' => 'Could not send your message right now. Please try again shortly.']);
}

// record this hit for rate limiting only after a successful send
$hits[] = $now;
@file_put_contents($rlFile, json_encode($hits));

respond(200, ['ok' => true]);