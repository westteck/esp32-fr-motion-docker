<?php
/**
 * Known-faces database management.
 *
 * SECURITY NOTE — this file previously allowed unauthenticated remote code
 * execution. Both the filename (`$_POST['name']`) and the file extension
 * (taken from the uploaded filename) were attacker-controlled and unvalidated,
 * so `name=../../html/shell` with a `.php` upload wrote a webshell into the
 * document root. The fixes:
 *
 *   1. Every action now requires ADMIN_TOKEN.
 *   2. Names are whitelisted via clean_person_name() (basename + charset).
 *   3. The extension is hardcoded to .jpg; the client cannot influence it.
 *   4. Content is verified as a real JPEG before it is stored.
 *   5. Storage lives outside the document root, so nginx cannot execute it.
 *   6. assert_within() re-checks the resolved path before any unlink().
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/common.php';

send_base_headers();
require_admin_auth();
require_csrf();

$dir    = known_faces_dir();
$action = (string) ($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Mutating actions must not be reachable by GET; `delete` used to be, which
// meant any <img src> on any page could remove a trusted face.
if (in_array($action, ['add', 'delete'], true) && $method !== 'POST') {
    fail(405, 'POST required');
}

switch ($action) {
    case 'list':
        $people = [];
        foreach (glob($dir . '/*.jpg') ?: [] as $file) {
            $people[] = [
                'name'  => basename($file, '.jpg'),
                'added' => (int) filemtime($file),
            ];
        }
        usort($people, fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        json_out(['faces' => $people]);

    case 'add':
        if (!isset($_FILES['image']) || !is_array($_FILES['image'])) {
            fail(400, 'No image uploaded');
        }
        $upload = $_FILES['image'];
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            fail(400, 'Upload failed');
        }
        if (($upload['size'] ?? 0) <= 0 || $upload['size'] > MAX_FACE_BYTES) {
            fail(413, 'Image must be between 1 byte and 4 MiB');
        }
        if (!is_uploaded_file($upload['tmp_name'])) {
            fail(400, 'Invalid upload');
        }

        $name  = clean_person_name((string) ($_POST['name'] ?? ''));
        $bytes = (string) file_get_contents($upload['tmp_name']);
        assert_is_jpeg($bytes);

        // Extension is ours, never the client's.
        $dest = $dir . '/' . $name . '.jpg';
        if (file_exists($dest) && ($_POST['overwrite'] ?? '') !== '1') {
            fail(409, 'A face with that name already exists');
        }

        // Write to a temp file in the same directory, then rename, so the
        // brain never picks up a half-written encoding source.
        $tmp = $dir . '/.' . bin2hex(random_bytes(8)) . '.tmp';
        if (file_put_contents($tmp, $bytes) === false || !rename($tmp, $dest)) {
            @unlink($tmp);
            fail(500, 'Could not store image');
        }
        @chmod($dest, 0o640);

        json_out(['status' => 'success', 'name' => $name]);

    case 'delete':
        $name = clean_person_name((string) ($_POST['name'] ?? $_GET['name'] ?? ''));
        $path = $dir . '/' . $name . '.jpg';
        if (!is_file($path)) {
            fail(404, 'Not found');
        }
        // Belt and braces: the name is already whitelisted, but confirm the
        // resolved path is genuinely inside the faces directory.
        $real = assert_within($dir, $path);
        if (!unlink($real)) {
            fail(500, 'Could not delete');
        }
        json_out(['status' => 'success', 'name' => $name]);

    default:
        fail(400, 'Unknown action');
}
