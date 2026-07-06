import os
import time
import cv2
import face_recognition
import subprocess
from pathlib import Path

# Configuration
UPLOADS_DIR = Path("/app/uploads")
KNOWN_FACES_DIR = Path("/app/known_faces")
COMMAND_FILE = UPLOADS_DIR / "commands.json"
RCLONE_REMOTE = "proton:Tanricllc/esp32-cam-backups"
SYNC_INTERVAL_SEC = 900 # Sync every 15 minutes

def load_known_faces():
    known_encodings = []
    known_names = []
    
    if not KNOWN_FACES_DIR.exists():
        KNOWN_FACES_DIR.mkdir(parents=True, exist_ok=True)
        print(f"[INFO] Created known_faces directory at {KNOWN_FACES_DIR}")
        return known_encodings, known_names

    for img_path in KNOWN_FACES_DIR.glob("*.jpg"):
        print(f"[INFO] Loading known face: {img_path.stem}")
        img = face_recognition.load_image_file(str(img_path))
        encoding = face_recognition.face_encodings(img)
        if encoding:
            known_encodings.append(encoding[0])
            known_names.append(img_path.stem)
            
    return known_encodings, known_names

def update_command(action):
    with open(COMMAND_FILE, "w") as f:
        f.write(f'{{"action": "{action}", "timestamp": {time.time()}}} ')
    print(f"[ACTION] {action}")

def run_rclone_sync():
    print(f"[SYNC] Starting backup to {RCLONE_REMOTE}...")
    try:
        # Using 'copy' instead of 'sync' to prevent deleting files on Proton Drive if they are deleted locally
        result = subprocess.run(
            ["rclone", "copy", str(UPLOADS_DIR), RCLONE_REMOTE],
            capture_output=True, text=True
        )
        if result.returncode == 0:
            print("[SYNC] Backup successful.")
        else:
            print(f"[SYNC] Error: {result.stderr}")
    except Exception as e:
        print(f"[SYNC] Failed to run rclone: {e}")

def main():
    print("Starting ESP32-CAM AI Brain & Sync Engine...")
    known_encodings, known_names = load_known_faces()
    
    processed_files = set()
    last_sync_time = 0
    
    while True:
        now = time.time()
        
        # 1. Face Detection & Recording Logic
        for img_path in UPLOADS_DIR.glob("*.jpg"):
            if img_path.name not in processed_files:
                print(f"[SCAN] Processing {img_path.name}...")
                image = face_recognition.load_image_file(str(img_path))
                face_locations = face_recognition.face_locations(image)
                face_encodings = face_recognition.face_encodings(image, face_locations)
                
                if not face_encodings:
                    print("  -> No faces detected.")
                else:
                    for encoding in face_encodings:
                        matches = face_recognition.compare_faces(known_encodings, encoding, tolerance=0.6)
                        name = "Unknown"
                        if True in matches:
                            name = known_names[matches.index(True)]
                        
                        if name == "Unknown":
                            print(f"  -> ALERT: Unknown face detected! Triggering Record.")
                            update_command("RECORD_VIDEO")
                        else:
                            print(f"  -> Match: {name}. Logged.")
                
                processed_files.add(img_path.name)
        
        # 2. Memory Cleanup
        if len(processed_files) > 1000:
            processed_files.clear()
            
        # 3. Rclone Cloud Sync Logic
        if now - last_sync_time >= SYNC_INTERVAL_SEC:
            run_rclone_sync()
            last_sync_time = now
            
        time.sleep(1)

if __name__ == "__main__":
    main()
