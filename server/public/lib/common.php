<?php
/**
 * Shared configuration, validation and auth helpers.
 *
 * Every endpoint must include this file and call one of the require_*_auth()
 * functions before touching the filesystem.
 */

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// Paths
//
// All mutable state lives under a single data root so that the PHP container
// and the brain container can share one bind mount. Splitting these was the
// bug that stopped the face database from ever reaching the recogniser.
// ─────────────────────────────────────────────────────────────────────────────

function data_root(): string
{
    $root = getenv('DATA_ROOT') ?: dirname(__DIR__, 3) . '/data';
    return rtrim($root, '/');
}

function uploads_dir(): string
{
    return ensure_dir(data_root() . '/uploads');
}

function known_faces_dir(): string
{
    return ensure_dir(data_root() . '/known_faces');
}

function state_dir(): string
{
    return ensure_dir(data_root() . '/state');
}

function ensure_dir(string $path): string
{
    if (!is_dir($path) && !mkdir($path, 0o750, true) && !is_dir($path)) {
        fail(500, 'Storage unavailable');
    }
    return $path;
}

// ─────────────────────────────────────────────────────────────────────────────
// Responses
// ─────────────────────────────────────────────────────────────────────────────

function json_out(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(int $status, string $message): never
{
    json_out(['error' => $message], $status);
}

/**
 * Same-origin only. The previous wildcard `Access-Control-Allow-Origin: *`
 * let any website on the internet read the camera JSON feeds.
 */
function send_base_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
}

// ─────────────────────────────────────────────────────────────────────────────
// Auth
//
// Two separate secrets:
//   CAM_TOKEN   - the cameras. Upload frames, poll commands/settings.
//   ADMIN_TOKEN - the dashboard. Everything else.
//
// A camera token must never be accepted for a management action: the devices
// are physically accessible and their flash can be dumped.
// ─────────────────────────────────────────────────────────────────────────────

function expected_token(string $envVar): string
{
    $token = (string) (getenv($envVar) ?: '');
    if (strlen($token) < 16) {
        // Fail closed. An unset token must never mean "allow everyone".
        error_log("$envVar is unset or too short; refusing all requests");
        fail(500, 'Server auth not configured');
    }
    return $token;
}

function presented_token(string $header): string
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $header));
    return (string) ($_SERVER[$key] ?? '');
}

function require_cam_auth(): void
{
    if (!hash_equals(expected_token('CAM_TOKEN'), presented_token('X-Cam-Token'))) {
        fail(401, 'Unauthorized');
    }
}

/**
 * Browser session support.
 *
 * <img> tags cannot send an X-Admin-Token header, so the dashboard exchanges
 * the admin token for a session cookie once (login.php) and thereafter loads
 * frames and face thumbnails as ordinary image URLs.
 */
function session_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Strict',
        'path'     => '/',
        // Set SESSION_COOKIE_SECURE=1 once the stack is behind TLS.
        'secure'   => getenv('SESSION_COOKIE_SECURE') === '1',
    ]);
    session_name('esp32cam');
    session_start();
}

function admin_session_active(): bool
{
    session_boot();
    return ($_SESSION['admin'] ?? false) === true;
}

function start_admin_session(): void
{
    session_boot();
    session_regenerate_id(true);
    $_SESSION['admin']   = true;
    $_SESSION['sinceTs'] = time();
}

function end_admin_session(): void
{
    session_boot();
    $_SESSION = [];
    session_destroy();
}

function admin_token_presented(): bool
{
    return hash_equals(expected_token('ADMIN_TOKEN'), presented_token('X-Admin-Token'));
}

function require_admin_auth(): void
{
    if (admin_token_presented() || admin_session_active()) {
        return;
    }
    fail(401, 'Unauthorized');
}

/** Either token is acceptable (e.g. reading a frame). */
function require_any_auth(): void
{
    if (hash_equals(expected_token('CAM_TOKEN'), presented_token('X-Cam-Token'))) {
        return;
    }
    require_admin_auth();
}

/**
 * CSRF guard for state-changing requests made with a session cookie.
 *
 * SameSite=Strict already blocks cross-site cookie delivery; requiring a custom
 * header adds a second barrier, because a cross-origin HTML form cannot set one
 * without passing a CORS preflight that we never answer.
 */
function require_csrf(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        return;
    }
    if (admin_token_presented()) {
        return; // scripted client, not a browser session
    }
    if (presented_token('X-Requested-With') !== 'esp32cam-dashboard') {
        fail(403, 'Missing CSRF header');
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Input validation
// ─────────────────────────────────────────────────────────────────────────────

function require_cam_id(): string
{
    $camId = (string) ($_GET['camId'] ?? '');
    if ($camId === '' || !preg_match('/^[a-zA-Z0-9_-]{1,32}$/', $camId)) {
        fail(400, 'Invalid or missing camId');
    }
    return $camId;
}

/**
 * Person names become filenames, so they are whitelisted rather than escaped.
 * basename() strips any directory component before the pattern is applied, so
 * `../../html/shell` cannot survive this function.
 */
function clean_person_name(string $raw): string
{
    $name = basename(trim($raw));
    if ($name === '' || !preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._-]{0,63}$/', $name)) {
        fail(400, 'Name must be 1-64 chars: letters, digits, space, dot, underscore, hyphen');
    }
    if (str_contains($name, '..')) {
        fail(400, 'Invalid name');
    }
    return $name;
}

/**
 * Archived event filenames are always `<unix-millis>.jpg`. Millisecond
 * precision is 13 digits today; allow up to 16 for headroom.
 */
function clean_event_name(string $raw): ?string
{
    return preg_match('/^[0-9]{1,16}\.jpg$/', $raw) ? $raw : null;
}

/**
 * Defence in depth: confirm a resolved path really sits inside its directory
 * even after every other check has passed.
 */
function assert_within(string $dir, string $path): string
{
    $realDir  = realpath($dir);
    $realPath = realpath($path);
    if ($realDir === false || $realPath === false || !str_starts_with($realPath, $realDir . DIRECTORY_SEPARATOR)) {
        fail(400, 'Invalid path');
    }
    return $realPath;
}

// ─────────────────────────────────────────────────────────────────────────────
// Image validation
// ─────────────────────────────────────────────────────────────────────────────

const MAX_IMAGE_BYTES = 524288;      // 512 KiB, plenty for SVGA q12
const MAX_FACE_BYTES  = 4194304;     // 4 MiB, phone photos are larger

function assert_is_jpeg(string $bytes): void
{
    // Magic bytes first: cheap, and rejects the obvious cases.
    if (strncmp($bytes, "\xFF\xD8\xFF", 3) !== 0) {
        fail(415, 'Only JPEG accepted');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
    if ($mime !== 'image/jpeg') {
        fail(415, 'Only JPEG accepted');
    }
    // getimagesizefromstring rejects files that merely start with JPEG magic.
    $info = @getimagesizefromstring($bytes);
    if ($info === false || $info[2] !== IMAGETYPE_JPEG || $info[0] < 16 || $info[1] < 16) {
        fail(415, 'Not a valid JPEG image');
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Atomic JSON state files
//
// The old read-modify-write of the events file used LOCK_EX on write but no
// lock on read, so three cameras uploading at once could corrupt it.
// ─────────────────────────────────────────────────────────────────────────────

function json_read(string $file, array $default = []): array
{
    if (!is_file($file)) {
        return $default;
    }
    $fh = @fopen($file, 'rb');
    if ($fh === false) {
        return $default;
    }
    try {
        if (!flock($fh, LOCK_SH)) {
            return $default;
        }
        $raw = stream_get_contents($fh);
        flock($fh, LOCK_UN);
    } finally {
        fclose($fh);
    }
    $decoded = json_decode((string) $raw, true);
    return is_array($decoded) ? $decoded : $default;
}

/** Write via temp file + rename so readers never observe a partial file. */
function json_write_atomic(string $file, array $data): bool
{
    $tmp = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';
    $ok  = file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_SLASHES));
    if ($ok === false) {
        @unlink($tmp);
        return false;
    }
    @chmod($tmp, 0o640);
    if (!rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/**
 * Read-modify-write under an exclusive lock on a separate lock file.
 * $mutator receives the current value and returns the new one.
 */
function json_mutate(string $file, callable $mutator, array $default = []): array
{
    $lock = fopen($file . '.lock', 'c');
    if ($lock === false) {
        fail(500, 'Storage unavailable');
    }
    try {
        flock($lock, LOCK_EX);
        $updated = $mutator(json_read($file, $default));
        json_write_atomic($file, $updated);
        return $updated;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Tunables
// ─────────────────────────────────────────────────────────────────────────────

function env_int(string $name, int $default): int
{
    $raw = getenv($name);
    return ($raw !== false && $raw !== '' && ctype_digit((string) $raw)) ? (int) $raw : $default;
}
