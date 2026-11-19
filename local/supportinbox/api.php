<?php
/**
 * Benzaiten LMS — Support API (Application Nexus integration)
 *
 * Implements the endpoints required by "Application Nexus: Integration
 * Spec for App Teams" (Nov 17, 2026) under the path /support/v1/*.
 *
 * This script is reached two ways:
 *   - Directly:            /local/supportinbox/api.php/tickets
 *   - Through nginx alias: /support/v1/tickets
 *     (see /06-support-api.conf at the repo root, which rewrites
 *     /support/v1/<rest> to this script with PATH_INFO = /<rest>, the same
 *     pattern already used for pluginfile.php in 05-pathinfo.conf.)
 *
 * Auth: Authorization: Bearer <key>, issued and revoked from Site
 * administration > Support > Support API keys (local/supportinbox/keys.php).
 * This endpoint is deliberately NOT behind require_login() — the caller is
 * an external system authenticating with its own bearer key, not a Moodle
 * session — so NO_MOODLE_COOKIES must be set before config.php loads,
 * exactly as Moodle's own webservice/rest/server.php does.
 */

define('NO_MOODLE_COOKIES', true);
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/api_key_manager.php');
require_once(__DIR__ . '/classes/support_api.php');

use local_supportinbox\support_api;

header('Content-Type: application/json; charset=utf-8');

try {
    support_api::handle_request();
} catch (\Throwable $e) {
    // Never leak internals to an external caller; the full exception still
    // hits Moodle's normal PHP error log via the default handler below.
    error_log('local_supportinbox api.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => ['code' => 'internal_error', 'message' => 'Unexpected server error.']]);
}