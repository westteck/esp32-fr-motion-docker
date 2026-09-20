<?php
/**
 * Receives a frame from a camera.
 *
 * PROTOCOL NOTE — this endpoint used to require multipart/form-data
 * (`$_FILES['image']`) while the firmware POSTed a raw JPEG body with
 * `Content-Type: image/jpeg`. Every upload therefore failed with
 * "400 No image uploaded" and the system never moved a single frame
 * end to end. Raw bodies are now the primary path (it avoids building a
 * multipart buffer in the ESP32's limited RAM); multipart is still accepted so
 * that curl and the dashboard can post test frames.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/common.php';

send_base_headers();
require_cam_auth();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    fail(405, 'POST required');
}

$camId    = require_cam_id();
$isMotion = ($_GET['motion'] ?? '0') === '1';

// ── Read the image, whichever way it arrived ─────────────────────────────────
$bytes = null;

if (isset($_FILES['image']) && is_array($_FILES['image'])) {
    $upload = $_FILES['image'];
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        fail(400, 'Upload failed');
    }
    if (($upload['size'] ?? 0) > MAX_IMAGE_BYTES) {
        fail(413, 'Image too large');
    }
    if (!is_uploaded_file($upload['tmp_name'])) {
        fail(400, 'Invalid upload');
    }
    $bytes = (string) file_get_contents($upload['tmp_name']);
} else {
    // Raw body. Cap the read so an oversized POST cannot exhaust memory.
    $declared = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($declared > MAX_IMAGE_BYTES) {
        fail(413, 'Image too large');
    }
    $stream = fopen('php://input', 'rb');
    if ($stream === false) {
        fail(400, 'No image uploaded');
    }
    $bytes = (string) stream_get_contents($stream, MAX_IMAGE_BYTES + 1);
    fclose($stream);
}

if ($bytes === '' ) {
    fail(400, 'No image uploaded');
}
if (strlen($bytes) > MAX_IMAGE_BYTES) {
    fail(413, 'Image too large');
}

assert_is_jpeg($bytes);

// ── Store the latest frame atomically ───────────────────────────────────────
$uploads = uploads_dir();
$latest  = $uploads . '/' . $camId . '.jpg';
$tmp     = $uploads . '/.' . $camId . '.' . bin2hex(random_bytes(6)) . '.tmp';

if (file_put_contents($tmp, $bytes) === false || !rename($tmp, $latest)) {
    @unlink($tmp);
    fail(500, 'Could not store frame');
}
@chmod($latest, 0o640);

$now = time();

// Record liveness and the real motion flag separately from file mtime, so the
// dashboard can distinguish "camera online" from "motion detected". The old
// code inferred motion from mtime, which only ever meant "uploaded recently".
json_write_atomic($uploads . '/' . $camId . '_status.json', [
    'camId'      => $camId,
    'lastFrame'  => $now,
    'motion'     => $isMotion,
    'lastMotion' => $isMotion ? $now : (int) (json_read($uploads . '/' . $camId . '_status.json')['lastMotion'] ?? 0),
]);

// ── Archive motion frames ───────────────────────────────────────────────────
$archived = null;

if ($isMotion) {
    $eventDir = ensure_dir($uploads . '/' . $camId);

    // Unix seconds collide at 5 fps, which silently overwrote frames. Use a
    // millisecond-precision name and keep the integer ts for the timeline.
    $archived = sprintf('%d.jpg', (int) round(microtime(true) * 1000));
    if (file_put_contents($eventDir . '/' . $archived, $bytes) === false) {
        fail(500, 'Could not archive frame');
    }
    @chmod($eventDir . '/' . $archived, 0o640);

    $eventsFile = $uploads . '/' . $camId . '_events.json';
    $maxEvents  = env_int('MAX_EVENTS_PER_CAM', 500);
    $maxAgeDays = env_int('MAX_EVENT_AGE_DAYS', 14);
    $cutoff     = $now - ($maxAgeDays * 86400);

    json_mutate($eventsFile, function (array $events) use (
        $archived, $now, $maxEvents, $cutoff, $eventDir
    ): array {
        $events[] = ['ts' => $now, 'file' => $archived];

        // Drop entries that are too old, then trim to the count limit.
        $events = array_values(array_filter(
            $events,
            fn($e): bool => is_array($e) && (int) ($e['ts'] ?? 0) >= $cutoff
        ));

        $dropped = [];
        if (count($events) > $maxEvents) {
            $dropped = array_slice($events, 0, count($events) - $maxEvents);
            $events  = array_slice($events, -$maxEvents);
        }

        // The old code trimmed the JSON but never deleted the JPEGs, so the
        // disk filled up indefinitely. Delete what we just forgot about.
        foreach ($dropped as $old) {
            $name = clean_event_name((string) ($old['file'] ?? ''));
            if ($name !== null && is_file($eventDir . '/' . $name)) {
                @unlink($eventDir . '/' . $name);
            }
        }

        return $events;
    });

    // Sweep any orphans left by earlier crashes or pre-fix versions.
    prune_orphans($eventDir, $cutoff);
}

json_out([
    'ok'        => true,
    'camId'     => $camId,
    'timestamp' => (int) round(microtime(true) * 1000),
    'motion'    => $isMotion,
    'archived'  => $archived,
    'bytes'     => strlen($bytes),
]);

/**
 * Deletes archived frames older than the retention cutoff. Cheap enough to run
 * on motion uploads, and it is the only thing standing between a 5 fps camera
 * and a full disk.
 */
function prune_orphans(string $eventDir, int $cutoff): void
{
    // Only sweep occasionally; this runs on a hot path.
    if (random_int(1, 200) !== 1) {
        return;
    }
    foreach (glob($eventDir . '/*.jpg') ?: [] as $file) {
        if (filemtime($file) < $cutoff) {
            @unlink($file);
        }
    }
}
