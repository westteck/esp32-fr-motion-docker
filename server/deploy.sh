#!/bin/bash
# Deploy ESP32-CAM server to raggsy.com
# Run on linode server: bash deploy.sh

set -e

SITE_ROOT="/www/wwwroot/raggsy.com"
CAM_DIR="$SITE_ROOT/cam"
UPLOADS_DIR="$CAM_DIR/uploads"

echo "=== ESP32-CAM Deploy ==="

echo "Creating directories..."
sudo mkdir -p "$CAM_DIR" "$UPLOADS_DIR"

echo "Copying PHP files..."
sudo cp upload.php "$CAM_DIR/"
sudo cp frame.php "$CAM_DIR/"
sudo cp cameras.php "$CAM_DIR/"
sudo cp events.php "$CAM_DIR/"
sudo cp index.html "$CAM_DIR/"

echo "Setting permissions..."
sudo chown -R www:www "$CAM_DIR"
sudo chmod 755 "$CAM_DIR"
sudo chmod 755 "$CAM_DIR"/*.php "$CAM_DIR"/*.html
sudo chmod 775 "$UPLOADS_DIR"

echo "=== Done ==="
echo "Upload endpoint: http://raggsy.com/cam/upload.php?camId=cam1"
echo "Dashboard:       http://raggsy.com/cam/"
echo "Frame:           http://raggsy.com/cam/frame.php?camId=cam1"
echo "Events:          http://raggsy.com/cam/events.php?camId=cam1"
