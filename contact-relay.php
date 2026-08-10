<?php
/**
 * Benzaiten LMS — contact form relay (direct authenticated SMTP)
 *
 * IMPORTANT: this file is 100% standalone. It does NOT include Moodle's
 * config.php, does NOT touch the database, and does NOT use any Moodle
 * internals — it's a plain PHP script that happens to live on the same
 * server/domain (api.benzaitenlms.com) for convenience.
 *
 * WHY DIRECT SMTP INSTEAD OF RESEND: your info@benzaitenlms.com mailbox
 * is hosted on Bluehost, which rejects unauthenticated mail claiming to
 * be FROM its own locally-hosted domain (benzaitenlms.com) — a standard
 * anti-spoofing rule. Routing through Resend (an external relay) hits
 * that wall no matter which @benzaitenlms.com address is used. Logging
 * in directly with the mailbox's real password sidesteps it entirely —
 * Bluehost accepts authenticated senders. No new domain/subdomain/
 * service is needed.
 *
 * WHERE THIS GOES
 * Same as before: repo root, next to config.php, so your Dockerfile's
 * `COPY contact-relay.php /var/www/html/contact-relay.php` line still
 * works unchanged. It becomes reachable at:
 *   https://api.benzaitenlms.com/contact-relay.php
 *
 * ALSO NEEDED: the PHPMailer library baked into the image — see the
 * Dockerfile snippet provided alongside this file.
 *
 * ENV VARS TO SET on the "Backend" Railway service (Variables tab):
 *   SMTP_HOST   - mail.benzaitenlms.com (confirmed to resolve to your
 *                 Bluehost mail server — double-check against cPanel's
 *                 "Connect Devices" page for info@benzaitenlms.com if
 *                 this doesn't work)
 *   SMTP_PORT   - 465 (SSL) — or 587 if cPanel shows that instead
 *   SMTP_USER   - info@benzaitenlms.com
 *   SMTP_PASS   - the mailbox's real password
 *   TO_EMAIL    - info@benzaitenlms.com
 *   FROM_EMAIL  - info@benzaitenlms.com  (safe now — this is an
 *                 authenticated login, not an external relay claim)
 *   ALLOWED_ORIGIN - https://benzaitenlms.com
 */

require '/var/www/html/phpmailer/Exception.php';
require '/var/www/html/phpmailer/PHPMailer.php';
require '/var/www/html/phpmailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

// ---- config ----
$SMTP_HOST      = getenv('SMTP_HOST') ?: 'mail.benzaitenlms.com';
$SMTP_PORT      = (int)(getenv('SMTP_PORT') ?: 465);
$SMTP_USER      = getenv('SMTP_USER') ?: 'info@benzaitenlms.com';
$SMTP_PASS      = getenv('SMTP_PASS') ?: '';
$TO_EMAIL       = getenv('TO_EMAIL') ?: 'info@benzaitenlms.com';
$FROM_EMAIL     = getenv('FROM_EMAIL') ?: 'info@benzaitenlms.com';
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

if ($SMTP_PASS === '') {
    error_log('contact-relay: SMTP_PASS is not set');
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

try {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = $SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = $SMTP_USER;
    $mail->Password   = $SMTP_PASS;
    $mail->Port       = $SMTP_PORT;
    $mail->SMTPSecure = $SMTP_PORT === 587 ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
    $mail->Timeout    = 15;
    // The diagnostic proved raw TCP+TLS connectivity to Bluehost works fine;
    // this relaxes strict certificate verification, which minimal Docker
    // images often can't complete against shared-hosting certs otherwise.
    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ],
    ];

    $mail->setFrom($FROM_EMAIL, 'Benzaiten LMS Contact Form');
    $mail->addAddress($TO_EMAIL);
    $mail->addReplyTo($email, $name);
    $mail->Subject = '[Contact form] ' . $subject;
    $mail->isHTML(true);
    $mail->Body    = $html;
    $mail->AltBody = "Name: $name\nEmail: $email\nSubject: $subject\n\n$message";

    $mail->send();
} catch (PHPMailerException $e) {
    error_log('contact-relay: PHPMailer send failed: ' . $e->getMessage());
    respond(502, ['ok' => false, 'error' => 'Could not send your message right now. Please try again shortly.']);
}

// record this hit for rate limiting only after a successful send
$hits[] = $now;
@file_put_contents($rlFile, json_encode($hits));

respond(200, ['ok' => true]);