<?php
/**
 * Motion event timeline for one camera.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/common.php';

send_base_headers();
require_admin_auth();

$camId = require_cam_id();
$requestedLimit = (string) ($_GET['limit'] ?? '');
$limit = ctype_digit($requestedLimit) ? (int) $requestedLimit : 20;
$limit = min(max($limit, 1), 200);

$events = json_read(uploads_dir() . '/' . $camId . '_events.json');

// Drop malformed entries so the dashboard never has to defend against them.
// The old version echoed json_decode() output directly, emitting `null` on a
// corrupt file, which then threw on `events.length` in the browser.
$clean = [];
foreach ($events as $event) {
    if (!is_array($event)) {
        continue;
    }
    $file = clean_event_name((string) ($event['file'] ?? ''));
    $ts   = (int) ($event['ts'] ?? 0);
    if ($file !== null && $ts > 0) {
        $clean[] = ['ts' => $ts, 'file' => $file];
    }
}

// Newest first, capped.
$clean = array_slice(array_reverse($clean), 0, $limit);

json_out(['camId' => $camId, 'events' => $clean]);
