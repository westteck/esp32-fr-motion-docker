# Project Brief — ESP32-CAM Motion & Face Recognition

Internal handoff notes. User-facing setup lives in `README.md`.

## Goal

ESP32-CAM modules upload motion-triggered frames to a local server. The server
runs face recognition, asks the camera for a capture burst when it sees an
unknown face, and backs everything up to Proton Drive.

## Deployment model

One model only: **Docker stack on a local server**, reached at
`http://<server-ip>:8787/`.

A second, incompatible deployment (remote Linode, `raggsy.com/cam/`, aaPanel,
`deploy.sh`, no AI) previously coexisted in this repo with no indication of which
was current. Its files conflicted with the Docker model — `deploy.sh` never
copied `manage_faces.php` or the dashboard the README pointed at, and the old
dashboard fetched absolute `/cameras.php` paths that 404'd under the `/cam/`
subdirectory it was installed into. The Linode model has been removed.

## Components

| Path | Role |
|---|---|
| `esp32/esp32-cam-uploader.ino` | Firmware: capture, motion heuristic, upload, command/settings polling |
| `esp32/secrets.h.example` | Credential template; real `secrets.h` is gitignored |
| `server/public/*.php` | HTTP API (see the endpoint table in `README.md`) |
| `server/public/lib/common.php` | Auth, input validation, atomic JSON, path containment |
| `server/public/index.html` | Dashboard |
| `brain_script.py` | Face recognition loop + rclone backup |
| `Dockerfile.brain` | Pinned image for dlib/face_recognition and the rclone binary |
| `docker/php-custom.ini` | PHP limits and hardening |

## Storage layout

Everything mutable lives under one data root, bind-mounted into both the web and
brain containers (`$DATA_DIR`, default `./data`):

```
data/
  uploads/
    <camId>.jpg                latest frame
    <camId>/<millis>.jpg       archived motion frames
    <camId>_events.json        motion timeline
    <camId>_status.json        liveness + motion flag
    <camId>_settings.json      sensor settings
    <camId>_command.json       pending command (deleted when collected)
  known_faces/<name>.jpg       face database
  state/
    brain_status.json           heartbeat, counters, last error
    detections.json             recent recognition results
    sync_status.json            backup outcome
    force_sync.json             manual sync request flag
```

A single shared root is deliberate. The two directories were previously split
(`uploads/known_faces` for PHP, `known_faces` for the brain), so faces added via
the dashboard never reached the recogniser and every face matched as Unknown.

## Auth model

- `CAM_TOKEN` — devices. Upload, command poll, settings read.
- `ADMIN_TOKEN` — dashboard. Everything else, plus a session cookie so `<img>`
  tags can load frames.
- Both are required; endpoints fail closed while unset.
- A camera token is never sufficient for a management action: the hardware is
  physically reachable and its flash can be dumped.

## Known limitations

- **Motion detection** compares compressed JPEG sizes, a proxy for scene
  complexity rather than movement. It reacts to lighting changes and misses
  low-entropy motion. Server-side face recognition is the accurate filter.
  Improving this on-device means decoding frames, which the hardware is too slow
  for at useful frame rates.
- **No on-device MJPEG stream.** Removed; see `README.md` for why and what a
  correct implementation would require.
- **No video files.** `RECORD_VIDEO` produces a 10-second 5 fps frame burst.
- **No HTTPS.** LAN only. `SESSION_COOKIE_SECURE=1` once TLS is in front.
- **The brain runs as root** in its container so it can read files written by
  php-fpm's `www-data` in the shared bind mount. A mismatched non-root UID fails
  silently, which is worse.
- **Single point of failure.** If the server is down the cameras buffer nothing;
  frames are lost rather than queued.

## Credentials

None are stored in this repo. `esp32/secrets.h` and `.env` are gitignored.

Commits up to and including `912c610` contain plaintext WiFi passwords, including
a third party's home network. They are still in git history. **Rotate those
passwords**; stripping them from the working tree does not revoke them. If the
repo was ever pushed publicly, treat both networks as compromised.

## Open work

- TLS termination in front of nginx.
- Per-camera detection log, rather than one shared list.
- Buffer frames on the ESP32 when the server is unreachable.
- Automated tests; there are currently none.
