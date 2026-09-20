<?php
/**
 * Per-camera sensor settings.
 *
 *   GET  ?camId=X (cam or admin token) - current settings, for the firmware
 *   POST ?camId=X (admin token)        - update settings from the dashboard
 *
 * Values are clamped to the ranges the ESP32 camera driver accepts, so a bad
 * dashboard request cannot put the sensor into an invalid state.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/common.php';

send_base_headers();

/** field => [min, max, default] */
const SETTING_RANGES = [
    'brightness'  => [-2, 2, 0],
    'contrast'    => [-2, 2, 0],
    'saturation'  => [-2, 2, 0],
    // Lower is better quality. 10-63 is the driver's usable range.
    'quality'     => [10, 63, 12],
    // FRAMESIZE_QVGA(5) .. FRAMESIZE_UXGA(13)
    'framesize'   => [5, 13, 8],
    'vflip'       => [0, 1, 1],
    'hmirror'     => [0, 1, 0],
    // Percentage change in JPEG size that counts as motion.
    'motionPct'   => [1, 90, 12],
    'recordUnknownOnly' => [0, 1, 1],
];

$camId = require_cam_id();
$file  = uploads_dir() . '/' . $camId . '_settings.json';

function defaults(): array
{
    $out = [];
    foreach (SETTING_RANGES as $key => [, , $default]) {
        $out[$key] = $default;
    }
    return $out;
}

function clamp_settings(array $input, array $base): array
{
    $out = $base;
    foreach (SETTING_RANGES as $key => [$min, $max, $default]) {
        if (!array_key_exists($key, $input)) {
            continue;
        }
        $value = $input[$key];
        if (!is_numeric($value)) {
            fail(400, "Setting '$key' must be numeric");
        }
        $out[$key] = max($min, min($max, (int) $value));
    }
    return $out;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST') {
    require_admin_auth();
    require_csrf();

    $raw   = (string) file_get_contents('php://input');
    $input = $raw !== '' ? json_decode($raw, true) : $_POST;
    if (!is_array($input)) {
        fail(400, 'Expected a JSON object');
    }

    $current = json_read($file, defaults());
    $updated = clamp_settings($input, array_merge(defaults(), $current));
    $updated['updatedAt'] = time();

    if (!json_write_atomic($file, $updated)) {
        fail(500, 'Could not save settings');
    }
    json_out(['status' => 'saved', 'settings' => $updated]);
}

if ($method !== 'GET') {
    fail(405, 'GET or POST required');
}

require_any_auth();
json_out(['camId' => $camId, 'settings' => json_read($file, defaults())]);
