<?php
$base_dir = '/var/www/uploads/known_faces';
if (!is_dir($base_dir)) {
    mkdir($base_dir, 0777, true);
}

$action = $_GET['action'] ?? '';

if ($action === 'list') {
    $files = glob($base_dir . '/*.jpg');
    $people = [];
    foreach ($files as $file) {
        $people[] = basename($file, '.jpg');
    }
    echo json_encode($people);
} 
elseif ($action === 'add') {
    if (!isset($_FILES['image'])) {
        http_response_code(400);
        die("No image uploaded");
    }
    $name = $_POST['name'] ?? 'Unknown';
    $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION) ?: 'jpg';
    $dest = $base_dir . '/' . $name . '.' . $ext;
    
    if (move_uploaded_file($_FILES['image']['tmp_name'], $dest)) {
        echo json_encode(["status" => "success", "name" => $name]);
    } else {
        http_response_code(500);
        echo json_encode(["status" => "error"]);
    }
} 
elseif ($action === 'delete') {
    $name = $_GET['name'] ?? '';
    $file = $base_dir . '/' . $name . '.jpg';
    if (!empty($name) && file_exists($file)) {
        unlink($file);
        echo json_encode(["status" => "success"]);
    } else {
        http_response_code(404);
        echo json_encode(["status" => "not found"]);
    }
} 
elseif ($action === 'sync') {
    // Trigger rclone sync via a dummy file or a command that the brain script can see
    file_put_contents('/var/www/uploads/force_sync.txt', time());
    echo json_encode(["status" => "sync_triggered"]);
}
?>
