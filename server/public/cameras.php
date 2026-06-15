<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$uploadsDir = __DIR__ . '/../uploads';
$cameras = [];

if (is_dir($uploadsDir)) {
    foreach (glob($uploadsDir . '/*.jpg') as $file) {
        $id = basename($file, '.jpg');
        if (str_ends_with($id, '_events')) continue;
        $cameras[] = [
            'id' => $id,
            'lastFrame' => round(filemtime($file) * 1000),
            'motion' => (time() - filemtime($file)) < 6
        ];
    }
}

echo json_encode($cameras);
