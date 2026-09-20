<?php
/**
 * Real system status, and the manual-sync trigger.
 *
 *   GET                 - brain health, recent face detections, sync state
 *   POST ?action=sync   - ask the brain to run an rclone pass now
 *
 * HONESTY NOTE — the dashboard used to fake all of this. The face indicator
 * picked a string with Math.random(), the Proton Drive panel hardcoded
 * "Healthy", and the sync button logged "Sync command sent to Brain container"
 * regardless of outcome (nothing read the flag file it wrote). Everything here
 * is now sourced from state the brain actually writes.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/common.php';

send_base_headers();
require_admin_auth();
require_csrf();

$state  = state_dir();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST') {
    if ((string) ($_GET['action'] ?? '') !== 'sync') {
        fail(400, 'Unknown action');
    }
    // The brain polls for this file each loop and deletes it when it starts.
    if (!json_write_atomic($state . '/force_sync.json', ['requestedAt' => time()])) {
        fail(500, 'Could not request sync');
    }
    json_out(['status' => 'sync_requested']);
}

if ($method !== 'GET') {
    fail(405, 'GET or POST required');
}

$brain = json_read($state . '/brain_status.json');
$sync  = json_read($state . '/sync_status.json');

$heartbeat = (int) ($brain['heartbeat'] ?? 0);
$brainAge  = $heartbeat > 0 ? time() - $heartbeat : null;

$detections = [];
foreach (json_read($state . '/detections.json') as $entry) {
    if (!is_array($entry)) {
        continue;
    }
    $detections[] = [
        'ts'    => (int) ($entry['ts'] ?? 0),
        'camId' => preg_match('/^[a-zA-Z0-9_-]{1,32}$/', (string) ($entry['camId'] ?? ''))
            ? (string) $entry['camId'] : 'unknown',
        'name'  => substr((string) ($entry['name'] ?? 'Unknown'), 0, 64),
        'known' => (bool) ($entry['known'] ?? false),
    ];
}
$detections = array_slice(array_reverse($detections), 0, 25);

json_out([
    'brain' => [
        // null when the brain has never reported in; the dashboard renders
        // that as "unknown" rather than inventing a healthy state.
        'running'       => $brainAge !== null && $brainAge < 60,
        'heartbeatAge'  => $brainAge,
        'knownFaces'    => (int) ($brain['knownFaces'] ?? 0),
        'framesScanned' => (int) ($brain['framesScanned'] ?? 0),
        'lastError'     => $brain['lastError'] ?? null,
        'faceRecognitionAvailable' => (bool) ($brain['faceRecognitionAvailable'] ?? false),
    ],
    'sync' => [
        'lastAttempt' => (int) ($sync['lastAttempt'] ?? 0),
        'lastSuccess' => (int) ($sync['lastSuccess'] ?? 0),
        'ok'          => (bool) ($sync['ok'] ?? false),
        'message'     => substr((string) ($sync['message'] ?? ''), 0, 500),
        'remote'      => (string) ($sync['remote'] ?? ''),
        'pending'     => is_file($state . '/force_sync.json'),
    ],
    'detections' => $detections,
    'serverTime' => time() * 1000,
]);
