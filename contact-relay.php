<?php
/* =====================================================================
   Benzaiten LMS — contact-relay.php (Resend API version)
   Replaces PHP mail() with Resend's HTTP API — reliable on Railway,
   which has no local mail server for mail() to hand off to.

   Required Railway service variables (Settings → Variables):
     RESEND_API_KEY   = re_xxxxxxxxxxxxxxxxxxxxxxxxxxxx   (from resend.com)
     FROM_EMAIL        = info@benzaitenlms.com              (must be on a domain verified in Resend)
     TO_EMAIL           = info@ctekksolutions.net            (where you actually want to receive it)
     TURNSTILE_SECRET  = 0x4xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx  (from Cloudflare Turnstile widget)

   After editing/uploading this file OR changing any Railway variable,
   redeploy the service so the change actually takes effect.
   ===================================================================== */

// ---- CORS: allow the marketing site to call this API -----------------
// Both the bare domain and the www subdomain serve the live site, so both
// must be allowed — a visitor can land on either one.
$allowedOrigins = ['https://benzaitenlms.com', 'https://www.benzaitenlms.com'];
$requestOrigin  = $_SERVER['HTTP_ORIGIN'] ?? '';
header('Access-Control-Allow-Origin: ' . (in_array($requestOrigin, $allowedOrigins, true) ? $requestOrigin : 'https://benzaitenlms.com'));
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

header('Content-Type: application/json');

// ---- Parse incoming JSON body -----------------------------------------
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid request.']);
    exit;
}

// ---- Basic field validation --------------------------------------------
$name    = trim($data['name']    ?? '');
$email   = trim($data['email']   ?? '');
$subject = trim($data['subject'] ?? '');
$message = trim($data['message'] ?? '');

if ($name === '' || $email === '' || $message === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Please fill in all fields with a valid email address.']);
    exit;
}

// ---- Cloudflare Turnstile verification ---------------------------------
$turnstileSecret = getenv('TURNSTILE_SECRET');
$turnstileToken  = $data['turnstileToken'] ?? '';

if (empty($turnstileToken)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Verification missing. Please try again.']);
    exit;
}

$verifyPayload = [
    'secret'   => $turnstileSecret,
    'response' => $turnstileToken,
    'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
];

$verifyCh = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
curl_setopt_array($verifyCh, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query($verifyPayload),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT        => 10,
]);

$verifyResponse = curl_exec($verifyCh);
$verifyCurlError = curl_error($verifyCh);
curl_close($verifyCh);

$verifyResult = ($verifyResponse !== false && $verifyCurlError === '')
    ? json_decode($verifyResponse, true)
    : null;

if (empty($verifyResult['success'])) {
    error_log('Turnstile verification failed: ' . ($verifyCurlError ?: json_encode($verifyResult['error-codes'] ?? [])));
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Verification failed. Please try again.']);
    exit;
}

// ---- Send via Resend API ------------------------------------------------
$resendApiKey = getenv('RESEND_API_KEY');
$fromEmail    = getenv('FROM_EMAIL') ?: 'info@benzaitenlms.com';
$toEmail      = getenv('TO_EMAIL')   ?: 'info@ctekksolutions.net';

if (empty($resendApiKey)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Email service is not configured. Please email us directly.']);
    exit;
}

$emailSubject = $subject !== '' ? $subject : 'New contact form message — Benzaiten LMS';

$htmlBody = sprintf(
    '<h2>New contact form submission</h2>
     <p><strong>Name:</strong> %s</p>
     <p><strong>Email:</strong> %s</p>
     <p><strong>Subject:</strong> %s</p>
     <p><strong>Message:</strong></p>
     <p>%s</p>',
    htmlspecialchars($name),
    htmlspecialchars($email),
    htmlspecialchars($emailSubject),
    nl2br(htmlspecialchars($message))
);

$payload = json_encode([
    'from'     => "Benzaiten LMS Contact Form <{$fromEmail}>",
    'to'       => [$toEmail],
    'reply_to' => $email,          // hit "Reply" and it goes straight to the visitor
    'subject'  => $emailSubject,
    'html'     => $htmlBody,
]);

$ch = curl_init('https://api.resend.com/emails');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $resendApiKey,
        'Content-Type: application/json',
    ],
    CURLOPT_TIMEOUT => 15,
]);

$response   = curl_exec($ch);
$httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError  = curl_error($ch);
curl_close($ch);

if ($curlError || $httpStatus < 200 || $httpStatus >= 300) {
    error_log('Resend send failed: HTTP ' . $httpStatus . ' curl_error=' . $curlError . ' response=' . $response);
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'Could not send your message right now. Please email us directly.']);
    exit;
}

$supportInboxToken = getenv('SUPPORTINBOX_WSTOKEN');
if (!empty($supportInboxToken)) {
    $wsUrl = 'https://api.benzaitenlms.com/webservice/rest/server.php?' . http_build_query([
        'wstoken' => $supportInboxToken,
        'wsfunction' => 'local_supportinbox_submit_message',
        'moodlewsrestformat' => 'json',
        'name' => $name, 'email' => $email, 'subject' => $emailSubject,
        'message' => $message, 'source' => 'contact_form',
    ]);
    $wsCh = curl_init($wsUrl);
    curl_setopt_array($wsCh, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true]);
    curl_exec($wsCh);
    curl_close($wsCh);
}

echo json_encode(['ok' => true]);