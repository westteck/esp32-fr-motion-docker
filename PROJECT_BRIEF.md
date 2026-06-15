# ESP32-CAM Multi-Camera System — Project Brief

## Goal
3 ESP32-CAM modules at a friend's house upload images to raggsy.com. Web dashboard for live viewing. Motion-triggered recording.

## Architecture
```
ESP32-CAM (cam1/cam2/cam3) ──WiFi──▶ raggsy.com/cam/upload.php
                                          │
                                   stores JPEGs on disk
                                          │
                                   raggsy.com/cam/ — dashboard
```

## What's Built

### ESP32 Firmware (`esp32/esp32-cam-uploader.ino`)
- Software motion detection (JPEG size differencing, 12% threshold)
- Dual capture rate: idle 1fps, active 5fps (200ms interval)
- 5s cooldown after motion stops
- Multi-WiFi: tries `ludo` (home) then `ATT8Fdp4tz` (Phil's), 15s timeout each, reboots if all fail
- Board selector: `BOARD_TYPE 0` = AI Thinker ESP32-CAM, `1` = Freenove WROVER-DEV
- Posts to `http://raggsy.com/cam/upload.php?camId=camX&motion=1`
- Per-device config: change `CAM_ID` and `BOARD_TYPE` before flashing

### Server (`server/public/`) — PHP, zero dependencies
| File | Purpose |
|------|---------|
| `upload.php` | Receives JPEG, saves latest frame, archives motion frames to `camId/` dir, writes `camId_events.json` |
| `frame.php` | Serves latest frame or historical event frame (`?event=timestamp.jpg`) |
| `cameras.php` | JSON list of cameras with lastFrame timestamp and motion state |
| `events.php` | JSON motion event timeline per camera |
| `index.html` | Dashboard: live grid, red glow on motion, motion badge, clickable event thumbnails, fullscreen viewer |

### Deploy
- Repo: `git@github.com:westteck/phil-esp32-cam.git`
- Linode: cloned at `~/esp32-cam-server`
- Deploy: `bash server/deploy.sh` (needs sudo, copies to `/www/wwwroot/raggsy.com/cam/`)
- Update workflow: `git pull && bash server/deploy.sh`

## What's NOT Done
- ESP32s not yet flashed (Arduino IDE 1.8.19 installed locally, CP2102 at `/dev/ttyUSB0`)
- Deploy script not yet run on linode (needs sudo password)
- No Telegram integration
- No HTTPS (HTTP only, nginx already on server)

## Flashing Instructions (for reference)
1. Arduino IDE → Preferences → add `https://raw.githubusercontent.com/espressif/arduino-esp32/gh-pages/package_esp32_index.json`
2. Boards Manager → install "esp32 by Espressif Systems"
3. Open `esp32-cam-uploader.ino`, set `BOARD_TYPE` and `CAM_ID`
4. Wire CP2102: 5V→5V, GND→GND, TXD→U0R, RXD→U0T, IO0→GND (flash mode)
5. Board: AI Thinker ESP32-CAM (or Wrover Module for Freenove), Port: /dev/ttyUSB0
6. Upload, press RST when "Connecting..." appears
7. Remove IO0→GND, press RST to run

## WiFi Credentials (in firmware)
- Home: `ludo` / `Vanillalotus849`
- Phil's: `ATT8Fdp4tz` / `5u3kzu?g9=93`

## Server
- Domain: raggsy.com
- Stack: nginx, PHP 8.3, aaPanel
- Dashboard: http://raggsy.com/cam/
- Upload endpoint: http://raggsy.com/cam/upload.php?camId=cam1
