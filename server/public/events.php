<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$camId = $_GET['camId'] ?? null;
if (!$camId || !preg_match('/^[a-zA-Z0-9_-]+$/', $camId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid camId']);
    exit;
}

$eventsFile = __DIR__ . '/../uploads/' . $camId . '_events.json';
if (!file_exists($eventsFile)) {
    echo json_encode([]);
    exit;
}

$events = json_decode(file_get_contents($eventsFile), true);
echo json_encode($events);
