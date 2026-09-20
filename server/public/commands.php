<?php
/**
 * Camera command queue.
 *
 *   GET  ?camId=X            (cam token)   - fetch and consume pending command
 *   POST ?camId=X&action=... (admin token) - enqueue a command
 *
 * DESIGN NOTE — the firmware used to poll a static `uploads/commands.json`
 * over plain nginx. Nothing ever cleared it, so a single RECORD_VIDEO written
 * by the brain re-triggered on every 2-second poll, forever. There was also one
 * shared file for all cameras. Commands are now per-camera and consumed on
 * read, so each one fires exactly once.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/common.php';

send_base_headers();

const ALLOWED_ACTIONS = ['RECORD_VIDEO', 'REBOOT', 'FLASH_ON', 'FLASH_OFF'];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$camId  = require_cam_id();
$file   = uploads_dir() . '/' . $camId . '_command.json';

if ($method === 'POST') {
    require_admin_auth();
    require_csrf();

    $action = strtoupper((string) ($_GET['action'] ?? $_POST['action'] ?? ''));
    if (!in_array($action, ALLOWED_ACTIONS, true)) {
        fail(400, 'Unknown action');
    }

    $command = [
        'id'     => bin2hex(random_bytes(8)),
        'action' => $action,
        'ts'     => time(),
    ];
    if (!json_write_atomic($file, $command)) {
        fail(500, 'Could not queue command');
    }
    json_out(['status' => 'queued'] + $command);
}

if ($method !== 'GET') {
    fail(405, 'GET or POST required');
}

// Cameras poll this. A camera token is enough to read its own queue.
require_cam_auth();

$command = json_read($file);
if ($command === [] || !isset($command['action'])) {
    json_out(['action' => null]);
}

// Stale commands are dropped rather than executed: a REBOOT queued while the
// camera was offline should not fire when it comes back an hour later.
$maxAge = env_int('COMMAND_TTL_SEC', 120);
$fresh  = (time() - (int) ($command['ts'] ?? 0)) <= $maxAge;

// Consume regardless, so it fires at most once either way.
@unlink($file);

json_out($fresh ? $command : ['action' => null, 'dropped' => 'stale']);
