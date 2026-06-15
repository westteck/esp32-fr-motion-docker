<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$camId = $_GET['camId'] ?? null;
if (!$camId || !preg_match('/^[a-zA-Z0-9_-]+$/', $camId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid or missing camId']);
    exit;
}

if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'No image uploaded']);
    exit;
}

$file = $_FILES['image'];
if ($file['size'] > 512 * 1024) {
    http_response_code(413);
    echo json_encode(['error' => 'Image too large']);
    exit;
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file['tmp_name']);
if ($mime !== 'image/jpeg') {
    http_response_code(415);
    echo json_encode(['error' => 'Only JPEG accepted']);
    exit;
}

$uploadsDir = __DIR__ . '/../uploads';
if (!is_dir($uploadsDir)) mkdir($uploadsDir, 0755, true);

$dest = $uploadsDir . '/' . $camId . '.jpg';
move_uploaded_file($file['tmp_name'], $dest);

$isMotion = ($_GET['motion'] ?? '0') === '1';
if ($isMotion) {
    $eventDir = $uploadsDir . '/' . $camId;
    if (!is_dir($eventDir)) mkdir($eventDir, 0755, true);
    $ts = time();
    copy($dest, $eventDir . '/' . $ts . '.jpg');

    $eventsFile = $uploadsDir . '/' . $camId . '_events.json';
    $events = file_exists($eventsFile) ? json_decode(file_get_contents($eventsFile), true) : [];
    $events[] = ['ts' => $ts, 'file' => $ts . '.jpg'];
    if (count($events) > 500) $events = array_slice($events, -500);
    file_put_contents($eventsFile, json_encode($events), LOCK_EX);
}

$timestamp = round(microtime(true) * 1000);
echo json_encode(['ok' => true, 'camId' => $camId, 'timestamp' => $timestamp, 'motion' => $isMotion]);
