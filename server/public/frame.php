<?php
$camId = $_GET['camId'] ?? null;
if (!$camId || !preg_match('/^[a-zA-Z0-9_-]+$/', $camId)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid camId']);
    exit;
}

$event = $_GET['event'] ?? null;
if ($event && preg_match('/^[0-9]+\.jpg$/', $event)) {
    $file = __DIR__ . '/../uploads/' . $camId . '/' . $event;
} else {
    $file = __DIR__ . '/../uploads/' . $camId . '.jpg';
}

if (!file_exists($file)) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'No frame yet']);
    exit;
}

header('Content-Type: image/jpeg');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Content-Length: ' . filesize($file));
readfile($file);
