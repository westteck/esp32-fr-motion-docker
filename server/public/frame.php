<?php
/**
 * Serves the latest frame for a camera, or one archived motion frame.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/common.php';

send_base_headers();
require_admin_auth();

$camId   = require_cam_id();
$uploads = uploads_dir();

$requested = (string) ($_GET['event'] ?? '');
if ($requested !== '') {
    $event = clean_event_name($requested);
    if ($event === null) {
        fail(400, 'Invalid event');
    }
    $dir  = $uploads . '/' . $camId;
    $path = $dir . '/' . $event;
} else {
    $dir  = $uploads;
    $path = $uploads . '/' . $camId . '.jpg';
}

if (!is_file($path)) {
    fail(404, 'No frame yet');
}

$real = assert_within($dir, $path);
$size = filesize($real);

header('Content-Type: image/jpeg');
header('Content-Length: ' . $size);
// Archived frames are immutable; the live frame must never be cached.
if ($requested !== '') {
    header('Cache-Control: private, max-age=3600, immutable');
} else {
    header('Cache-Control: no-cache, no-store, must-revalidate');
}
readfile($real);
