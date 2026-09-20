<?php
/**
 * Exchanges the admin token for a session cookie.
 *
 *   POST {"token": "..."}  - log in
 *   DELETE                 - log out
 *   GET                    - report whether the current session is valid
 *
 * Needed because <img> tags cannot carry an X-Admin-Token header, so frame and
 * face thumbnails rely on the cookie.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/common.php';

send_base_headers();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    json_out(['authenticated' => admin_session_active()]);
}

if ($method === 'DELETE' || ($method === 'POST' && ($_GET['action'] ?? '') === 'logout')) {
    end_admin_session();
    json_out(['authenticated' => false]);
}

if ($method !== 'POST') {
    fail(405, 'POST required');
}

$raw   = (string) file_get_contents('php://input');
$input = $raw !== '' ? json_decode($raw, true) : $_POST;
if (!is_array($input)) {
    fail(400, 'Expected a JSON object');
}

$presented = (string) ($input['token'] ?? '');

// Constant-time comparison, and a small fixed delay so that repeated guessing
// is slow. The token is 256 bits of entropy, so this is belt and braces.
usleep(200000);

if ($presented === '' || !hash_equals(expected_token('ADMIN_TOKEN'), $presented)) {
    error_log('Failed dashboard login from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    fail(401, 'Invalid token');
}

start_admin_session();
json_out(['authenticated' => true]);
