#!/usr/bin/env python3
"""
AI Brain: face recognition over uploaded frames, plus scheduled cloud backup.

Fixes applied to the original version:

  * Dedup was keyed on filename, but upload.php overwrites the same
    `<camId>.jpg` every frame. The first frame went into `processed_files` and
    was never looked at again, so the brain analysed exactly one image and then
    idled forever. Keyed on (path, mtime_ns, size) now.
  * `glob("*.jpg")` was not recursive, so archived motion frames in
    `uploads/<camId>/` were never scanned.
  * Known faces were loaded once at startup; faces added via the dashboard were
    invisible until a container restart. The directory is now re-read when it
    changes.
  * `matches.index(True)` returned the first face under tolerance, not the
    closest. Uses face_distance + argmin.
  * No exception handling: one truncated JPEG (very likely, since PHP may still
    be writing) killed the process, and `restart: always` then re-ran a ~10
    minute dependency install. Every stage is now guarded.
  * Commands were written to one global `commands.json` that nothing ever
    cleared, so a single detection re-triggered recording on every 2s poll.
    Commands are now per-camera and consumed by the server on read.
  * `force_sync.txt` was written by the dashboard but never read. Honoured now.
  * rclone copied the entire uploads tree every 15 minutes with no age filter.
"""

from __future__ import annotations

import json
import logging
import os
import signal
import subprocess
import sys
import time
from collections import OrderedDict
from pathlib import Path
from typing import Any

logging.basicConfig(
    level=os.environ.get("LOG_LEVEL", "INFO").upper(),
    format="%(asctime)s [%(levelname)s] %(message)s",
    stream=sys.stdout,
)
log = logging.getLogger("brain")

# ─────────────────────────────────────────────────────────────────────────────
# Optional heavy dependencies
#
# dlib/face_recognition fail to build surprisingly often. If they are missing we
# degrade to "backup only" and report it, rather than crash-looping. The
# dashboard surfaces this instead of implying recognition is working.
# ─────────────────────────────────────────────────────────────────────────────
try:
    import numpy as np
    import face_recognition

    FACE_RECOGNITION_AVAILABLE = True
except Exception as exc:  # pragma: no cover - depends on build environment
    np = None  # type: ignore[assignment]
    face_recognition = None  # type: ignore[assignment]
    FACE_RECOGNITION_AVAILABLE = False
    log.error("face_recognition unavailable, running in backup-only mode: %s", exc)


def env_str(name: str, default: str) -> str:
    value = os.environ.get(name, "").strip()
    return value or default


def env_int(name: str, default: int) -> int:
    try:
        return int(os.environ.get(name, "") or default)
    except ValueError:
        return default


def env_float(name: str, default: float) -> float:
    try:
        return float(os.environ.get(name, "") or default)
    except ValueError:
        return default


DATA_ROOT = Path(env_str("DATA_ROOT", "/app/data"))
UPLOADS_DIR = DATA_ROOT / "uploads"
KNOWN_FACES_DIR = DATA_ROOT / "known_faces"
STATE_DIR = DATA_ROOT / "state"

RCLONE_REMOTE = env_str("RCLONE_REMOTE", "")
SYNC_INTERVAL_SEC = env_int("SYNC_INTERVAL_SEC", 900)
SYNC_MAX_AGE = env_str("SYNC_MAX_AGE", "24h")
FACE_TOLERANCE = env_float("FACE_TOLERANCE", 0.5)

SCAN_INTERVAL_SEC = env_float("SCAN_INTERVAL_SEC", 1.0)
# Ignore files younger than this; PHP may still be writing them. upload.php
# writes atomically via rename(), but archived frames and manual copies may not.
MIN_FILE_AGE_SEC = env_float("MIN_FILE_AGE_SEC", 0.5)
MAX_TRACKED_FILES = env_int("MAX_TRACKED_FILES", 5000)
MAX_DETECTION_LOG = env_int("MAX_DETECTION_LOG", 200)
# Do not re-trigger recording for the same camera more often than this.
COMMAND_COOLDOWN_SEC = env_int("COMMAND_COOLDOWN_SEC", 30)

_shutdown = False


def _handle_signal(signum: int, _frame: Any) -> None:
    global _shutdown
    log.info("Received signal %s, shutting down", signum)
    _shutdown = True


# ─────────────────────────────────────────────────────────────────────────────
# Atomic JSON helpers (PHP reads these files concurrently)
# ─────────────────────────────────────────────────────────────────────────────


def write_json_atomic(path: Path, payload: Any) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp = path.with_suffix(path.suffix + f".{os.getpid()}.tmp")
    try:
        tmp.write_text(json.dumps(payload), encoding="utf-8")
        os.replace(tmp, path)  # atomic within a filesystem
    except OSError as exc:
        log.warning("Could not write %s: %s", path, exc)
        tmp.unlink(missing_ok=True)


def read_json(path: Path, default: Any) -> Any:
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except (OSError, ValueError):
        return default


# ─────────────────────────────────────────────────────────────────────────────
# Known faces
# ─────────────────────────────────────────────────────────────────────────────


class FaceDatabase:
    """Known-face encodings, reloaded when the directory changes on disk."""

    def __init__(self, directory: Path) -> None:
        self.directory = directory
        self.encodings: list[Any] = []
        self.names: list[str] = []
        self._signature: tuple[tuple[str, int, int], ...] | None = None

    def _current_signature(self) -> tuple[tuple[str, int, int], ...]:
        entries = []
        try:
            for path in sorted(self.directory.glob("*.jpg")):
                try:
                    stat = path.stat()
                except OSError:
                    continue
                entries.append((path.name, stat.st_mtime_ns, stat.st_size))
        except OSError as exc:
            log.warning("Cannot list %s: %s", self.directory, exc)
        return tuple(entries)

    def refresh_if_changed(self) -> bool:
        """Returns True if the database was reloaded."""
        signature = self._current_signature()
        if signature == self._signature:
            return False

        self._signature = signature
        self.encodings = []
        self.names = []

        if not FACE_RECOGNITION_AVAILABLE:
            return True

        for name, _mtime, _size in signature:
            path = self.directory / name
            try:
                image = face_recognition.load_image_file(str(path))
                found = face_recognition.face_encodings(image)
            except Exception as exc:
                log.warning("Could not encode %s: %s", name, exc)
                continue
            if not found:
                log.warning("No face found in %s, skipping", name)
                continue
            if len(found) > 1:
                log.warning("%s contains %d faces, using the first", name, len(found))
            self.encodings.append(found[0])
            self.names.append(path.stem)

        log.info("Face database loaded: %d known face(s) %s", len(self.names), self.names)
        return True

    def identify(self, encoding: Any) -> tuple[str, float | None]:
        """Returns (name, distance). name is 'Unknown' when nothing matches."""
        if not self.encodings:
            return "Unknown", None
        distances = face_recognition.face_distance(self.encodings, encoding)
        best = int(np.argmin(distances))
        best_distance = float(distances[best])
        # Pick the *closest* match, not merely the first one under tolerance.
        if best_distance <= FACE_TOLERANCE:
            return self.names[best], best_distance
        return "Unknown", best_distance


# ─────────────────────────────────────────────────────────────────────────────
# Frame scanning
# ─────────────────────────────────────────────────────────────────────────────


def camera_id_for(path: Path) -> str:
    """
    Latest frames are `uploads/<camId>.jpg`.
    Archived motion frames are `uploads/<camId>/<millis>.jpg`.
    """
    if path.parent == UPLOADS_DIR:
        return path.stem
    return path.parent.name


def candidate_frames() -> list[Path]:
    try:
        # Recursive: the original non-recursive glob missed every archived
        # motion frame, which is where the interesting images actually are.
        return sorted(UPLOADS_DIR.glob("**/*.jpg"))
    except OSError as exc:
        log.warning("Cannot scan %s: %s", UPLOADS_DIR, exc)
        return []


def queue_command(cam_id: str, action: str) -> None:
    """
    Writes a per-camera command. commands.php deletes it once the camera has
    collected it, so each command fires exactly once.
    """
    write_json_atomic(
        UPLOADS_DIR / f"{cam_id}_command.json",
        {"id": f"{int(time.time() * 1000):x}", "action": action, "ts": int(time.time())},
    )
    log.info("[ACTION] %s queued for %s", action, cam_id)


def record_unknown_only(cam_id: str) -> bool:
    settings = read_json(UPLOADS_DIR / f"{cam_id}_settings.json", {})
    return bool(settings.get("recordUnknownOnly", 1))


def log_detection(detections: list[dict[str, Any]], entry: dict[str, Any]) -> None:
    detections.append(entry)
    del detections[:-MAX_DETECTION_LOG]
    write_json_atomic(STATE_DIR / "detections.json", detections)


def scan_frame(
    path: Path,
    faces: FaceDatabase,
    detections: list[dict[str, Any]],
    last_command_at: dict[str, float],
) -> None:
    cam_id = camera_id_for(path)

    try:
        image = face_recognition.load_image_file(str(path))
        locations = face_recognition.face_locations(image)
        encodings = face_recognition.face_encodings(image, locations)
    except Exception as exc:
        # Truncated or corrupt JPEG. Log and move on; do not take the loop down.
        log.debug("Skipping %s: %s", path.name, exc)
        return

    if not encodings:
        return

    now = time.time()
    for encoding in encodings:
        name, distance = faces.identify(encoding)
        known = name != "Unknown"

        log_detection(
            detections,
            {
                "ts": int(now),
                "camId": cam_id,
                "name": name,
                "known": known,
                "distance": round(distance, 3) if distance is not None else None,
                "frame": path.name,
            },
        )

        if known:
            log.info("[%s] Match: %s (distance %.3f)", cam_id, name, distance or 0.0)
            continue

        log.warning("[%s] Unknown face detected", cam_id)
        if not record_unknown_only(cam_id):
            continue
        # Rate limit: a person walking past produces dozens of frames.
        if now - last_command_at.get(cam_id, 0.0) < COMMAND_COOLDOWN_SEC:
            continue
        queue_command(cam_id, "RECORD_VIDEO")
        last_command_at[cam_id] = now


# ─────────────────────────────────────────────────────────────────────────────
# Cloud backup
# ─────────────────────────────────────────────────────────────────────────────


def run_rclone_sync() -> None:
    if not RCLONE_REMOTE:
        write_json_atomic(
            STATE_DIR / "sync_status.json",
            {
                "lastAttempt": int(time.time()),
                "ok": False,
                "message": "RCLONE_REMOTE is not set; backup disabled",
                "remote": "",
            },
        )
        return

    log.info("[SYNC] Backing up to %s", RCLONE_REMOTE)
    started = int(time.time())
    status: dict[str, Any] = {"lastAttempt": started, "remote": RCLONE_REMOTE}
    previous = read_json(STATE_DIR / "sync_status.json", {})

    try:
        result = subprocess.run(
            [
                "rclone",
                "copy",
                str(UPLOADS_DIR),
                RCLONE_REMOTE,
                # Only recent files: copying the whole tree every 15 minutes
                # got slower forever as the archive grew.
                f"--max-age={SYNC_MAX_AGE}",
                "--transfers=4",
                "--retries=2",
                "--stats=0",
                "--log-level=ERROR",
            ],
            capture_output=True,
            text=True,
            timeout=env_int("SYNC_TIMEOUT_SEC", 600),
            check=False,
        )
        status["ok"] = result.returncode == 0
        status["message"] = (
            "Backup complete"
            if result.returncode == 0
            else (result.stderr or result.stdout or "unknown error").strip()[:500]
        )
        status["lastSuccess"] = started if result.returncode == 0 else int(previous.get("lastSuccess", 0))
        log.info("[SYNC] %s", status["message"])
    except subprocess.TimeoutExpired:
        status.update(
            ok=False,
            message="rclone timed out",
            lastSuccess=int(previous.get("lastSuccess", 0)),
        )
        log.error("[SYNC] rclone timed out")
    except (OSError, ValueError) as exc:
        status.update(
            ok=False,
            message=f"Could not run rclone: {exc}"[:500],
            lastSuccess=int(previous.get("lastSuccess", 0)),
        )
        log.error("[SYNC] %s", exc)

    write_json_atomic(STATE_DIR / "sync_status.json", status)


def sync_requested() -> bool:
    """Honours the dashboard's manual-sync button."""
    flag = STATE_DIR / "force_sync.json"
    if not flag.exists():
        return False
    flag.unlink(missing_ok=True)
    log.info("[SYNC] Manual sync requested")
    return True


# ─────────────────────────────────────────────────────────────────────────────
# Main loop
# ─────────────────────────────────────────────────────────────────────────────


def main() -> int:
    signal.signal(signal.SIGTERM, _handle_signal)
    signal.signal(signal.SIGINT, _handle_signal)

    for directory in (UPLOADS_DIR, KNOWN_FACES_DIR, STATE_DIR):
        directory.mkdir(parents=True, exist_ok=True)

    log.info("Starting AI Brain (data root %s)", DATA_ROOT)
    if not FACE_RECOGNITION_AVAILABLE:
        log.warning("Face recognition disabled; only cloud backup will run")

    faces = FaceDatabase(KNOWN_FACES_DIR)
    faces.refresh_if_changed()

    # (path, mtime_ns, size) -> True. An OrderedDict gives LRU eviction instead
    # of the original wholesale .clear(), which caused every file to be
    # reprocessed at once.
    seen: OrderedDict[tuple[str, int, int], bool] = OrderedDict()
    detections: list[dict[str, Any]] = read_json(STATE_DIR / "detections.json", [])
    if not isinstance(detections, list):
        detections = []
    last_command_at: dict[str, float] = {}

    frames_scanned = 0
    last_sync = 0.0
    last_error: str | None = None

    while not _shutdown:
        cycle_started = time.time()

        try:
            faces.refresh_if_changed()

            if FACE_RECOGNITION_AVAILABLE:
                for path in candidate_frames():
                    if _shutdown:
                        break
                    try:
                        stat = path.stat()
                    except OSError:
                        continue

                    # Let in-flight writes settle before reading.
                    if cycle_started - stat.st_mtime < MIN_FILE_AGE_SEC:
                        continue

                    # Keyed on content identity, not just name, so an
                    # overwritten <camId>.jpg is treated as a new frame.
                    key = (str(path), stat.st_mtime_ns, stat.st_size)
                    if key in seen:
                        continue

                    scan_frame(path, faces, detections, last_command_at)
                    frames_scanned += 1

                    seen[key] = True
                    while len(seen) > MAX_TRACKED_FILES:
                        seen.popitem(last=False)

            if sync_requested() or (cycle_started - last_sync >= SYNC_INTERVAL_SEC):
                run_rclone_sync()
                last_sync = cycle_started

            last_error = None

        except Exception as exc:  # keep the loop alive whatever happens
            last_error = f"{type(exc).__name__}: {exc}"[:500]
            log.exception("Cycle failed")

        write_json_atomic(
            STATE_DIR / "brain_status.json",
            {
                "heartbeat": int(time.time()),
                "knownFaces": len(faces.names),
                "framesScanned": frames_scanned,
                "lastError": last_error,
                "faceRecognitionAvailable": FACE_RECOGNITION_AVAILABLE,
            },
        )

        elapsed = time.time() - cycle_started
        time.sleep(max(0.1, SCAN_INTERVAL_SEC - elapsed))

    log.info("Stopped")
    return 0


if __name__ == "__main__":
    sys.exit(main())
