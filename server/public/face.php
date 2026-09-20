<?php
/**
 * Serves a known-face thumbnail.
 *
 * The faces directory lives outside the document root, so this endpoint is the
 * only way to read it. That keeps nginx from ever serving those files directly
 * (and from ever being tricked into executing one).
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/common.php';

send_base_headers();
require_admin_auth();

$dir  = known_faces_dir();
$name = clean_person_name((string) ($_GET['name'] ?? ''));
$path = $dir . '/' . $name . '.jpg';

if (!is_file($path)) {
    fail(404, 'Not found');
}

$real = assert_within($dir, $path);

header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($real));
header('Cache-Control: private, max-age=60');
readfile($real);
