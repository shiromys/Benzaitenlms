<?php
/**
 * Benzaiten LMS — SMTP connectivity diagnostic (temporary, remove after use)
 *
 * Tests raw network connectivity from THIS Railway container to every
 * plausible Bluehost mail host/port combination, and reports back exactly
 * what happened for each — no guessing, no repeated manual retries.
 *
 * WHERE THIS GOES: same repo root as contact-relay.php, add one more
 * COPY line to the Dockerfile (see below). Reachable at:
 *   https://api.benzaitenlms.com/smtp-diag.php
 *
 * SECURITY: this only tests connectivity, no credentials are used or
 * needed. Still, delete this file (and its Dockerfile COPY line) once
 * we're done diagnosing — no reason to leave a network-probing endpoint
 * sitting on a production server.
 */

header('Content-Type: application/json; charset=utf-8');

$hosts = ['box5362.bluehost.com', 'mail.benzaitenlms.com'];
$ports = [465, 587, 25];

$results = [];

foreach ($hosts as $host) {
    foreach ($ports as $port) {
        $start = microtime(true);
        $errno = 0;
        $errstr = '';

        // Port 465 is implicit TLS from the first byte; 587/25 start plaintext (STARTTLS later).
        $target = ($port === 465 ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);

        $fp = @stream_socket_client($target, $errno, $errstr, 6, STREAM_CLIENT_CONNECT, $ctx);
        $elapsedMs = round((microtime(true) - $start) * 1000);

        if ($fp) {
            stream_set_timeout($fp, 3);
            $banner = fread($fp, 512);
            fclose($fp);
            $results[] = [
                'host'   => $host,
                'port'   => $port,
                'ok'     => true,
                'banner' => trim((string)$banner),
                'ms'     => $elapsedMs,
            ];
        } else {
            $results[] = [
                'host'  => $host,
                'port'  => $port,
                'ok'    => false,
                'error' => $errno . ': ' . $errstr,
                'ms'    => $elapsedMs,
            ];
        }
    }
}

echo json_encode(['tested_from' => gethostname(), 'results' => $results], JSON_PRETTY_PRINT);