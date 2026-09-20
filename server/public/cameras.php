<?php
/**
 * Lists known cameras with liveness and real motion state.
 *
 * Previously this derived `motion` from the frame's mtime being under 6 seconds
 * old, which — because the firmware uploads on every interval whether or not
 * anything moved — actually meant "camera is online". The dashboard's red
 * motion glow was therefore meaningless. Motion now comes from the flag the
 * camera sent with the frame, persisted by upload.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/common.php';

send_base_headers();
require_admin_auth();

$uploads = uploads_dir();
$now     = time();
$cameras = [];

// Status files are the source of truth for which cameras exist.
foreach (glob($uploads . '/*_status.json') ?: [] as $statusFile) {
    $id = basename($statusFile, '_status.json');
    if (!preg_match('/^[a-zA-Z0-9_-]{1,32}$/', $id)) {
        continue;
    }

    $status    = json_read($statusFile);
    $lastFrame = (int) ($status['lastFrame'] ?? 0);
    $frameFile = $uploads . '/' . $id . '.jpg';

    // Trust the file's mtime over the recorded value if they disagree.
    if (is_file($frameFile)) {
        $lastFrame = max($lastFrame, (int) filemtime($frameFile));
    }

    $age = $now - $lastFrame;

    $cameras[] = [
        'id'         => $id,
        // Milliseconds, for the dashboard's cache-busting query string.
        'lastFrame'  => $lastFrame * 1000,
        'ageSec'     => $age,
        // Online means we heard from it recently; motion is a separate signal.
        'online'     => $age < 15,
        'motion'     => (bool) ($status['motion'] ?? false) && $age < 15,
        'lastMotion' => (int) ($status['lastMotion'] ?? 0) * 1000,
    ];
}

usort($cameras, fn(array $a, array $b): int => strcmp($a['id'], $b['id']));

json_out(['cameras' => $cameras, 'serverTime' => $now * 1000]);
