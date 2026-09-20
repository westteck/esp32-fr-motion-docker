/*
  ESP32-CAM uploader — v5

  Fixes applied to v4:

  1. CRLF BUG: every HTTP header in the MJPEG handler was written as
     "...\\r\\n", which the compiler turns into a literal backslash-r
     backslash-n rather than CRLF. The stream emitted malformed HTTP that no
     browser could parse, so it had never worked. Serial.printf("\\n") was
     affected too.

  2. BLOCKING STREAM: handleStream() looped `while (client.connected())`,
     which froze loop() for the entire viewing session. Motion uploads and
     command polling stopped the moment anyone opened the live view — a
     monitoring system that went blind whenever you looked at it. The on-device
     web server was also completely unauthenticated, giving anyone on the LAN
     the camera feed.

     Both problems are removed by deleting the on-device server. The dashboard
     now shows the latest frame from the server (frame.php), which the camera
     already uploads at up to 5 fps during motion. If a true MJPEG stream is
     wanted later, it must run in its own FreeRTOS task pinned to the other
     core, not inside loop().

  3. UPLOAD MISMATCH: unchanged on this side (raw JPEG body), but upload.php
     now actually accepts it. Previously the server demanded multipart and
     rejected every frame with HTTP 400.

  4. AUTH: uploads and polling now send X-Cam-Token. The endpoints were
     previously open to anyone who could reach the server.

  5. COMMANDS: the old code polled a single global commands.json that nothing
     ever cleared, so one RECORD_VIDEO re-fired every 2 seconds forever. The
     queue is now per-camera and consumed server-side on read. RECORD_VIDEO is
     also implemented (a high-rate burst) instead of being a TODO comment.

  6. WIFI: v4 only connected at boot. If the link dropped afterwards the device
     ran blind forever. There is now a reconnect path with a reboot backstop.

  7. SECRETS: credentials moved to secrets.h (gitignored).

  Setup: copy secrets.h.example to secrets.h and edit it. Set BOARD_TYPE below.

  Requires arduino-esp32 core >= 2.0.4. Older cores spell the SCCB pin fields
  `pin_sscb_sda` / `pin_sscb_scl`; if the sketch fails to compile on those two
  lines, your core is too old — update it rather than renaming the fields.
*/

#include "esp_camera.h"
#include <WiFi.h>
#include <HTTPClient.h>

#include "secrets.h"

// ── Board selection ─────────────────────────────────
// 0 = AI Thinker ESP32-CAM, 1 = Freenove WROVER-DEV
#define BOARD_TYPE 0

// ── Timing ──────────────────────────────────────────
static const uint32_t IDLE_INTERVAL_MS   = 1000;  // heartbeat frame rate
static const uint32_t ACTIVE_INTERVAL_MS = 200;   // 5 fps while motion is live
static const uint32_t COOLDOWN_MS        = 5000;  // motion latch after last hit
static const uint32_t CMD_POLL_MS        = 2000;
static const uint32_t SETTINGS_POLL_MS   = 30000;
static const uint32_t BURST_DURATION_MS  = 10000; // RECORD_VIDEO burst length
static const uint32_t HTTP_TIMEOUT_MS    = 5000;
static const uint32_t WIFI_ATTEMPT_MS    = 15000;

// Motion detection tunables (see detectMotion() for the caveat).
static const int  DEFAULT_MOTION_PCT     = 12;
static const uint8_t MOTION_CONFIRM_HITS = 2;

#if BOARD_TYPE == 0
  #define PWDN_GPIO_NUM    32
  #define RESET_GPIO_NUM   -1
  #define XCLK_GPIO_NUM     0
  #define SIOD_GPIO_NUM    26
  #define SIOC_GPIO_NUM    27
  #define Y9_GPIO_NUM      35
  #define Y8_GPIO_NUM      34
  #define Y7_GPIO_NUM      39
  #define Y6_GPIO_NUM      36
  #define Y5_GPIO_NUM      21
  #define Y4_GPIO_NUM      19
  #define Y3_GPIO_NUM      18
  #define Y2_GPIO_NUM       5
  #define VSYNC_GPIO_NUM   25
  #define HREF_GPIO_NUM    23
  #define PCLK_GPIO_NUM    22
  #define LED_FLASH         4
#elif BOARD_TYPE == 1
  #define PWDN_GPIO_NUM    -1
  #define RESET_GPIO_NUM   -1
  #define XCLK_GPIO_NUM    21
  #define SIOD_GPIO_NUM    26
  #define SIOC_GPIO_NUM    27
  #define Y9_GPIO_NUM      35
  #define Y8_GPIO_NUM      34
  #define Y7_GPIO_NUM      39
  #define Y6_GPIO_NUM      36
  #define Y5_GPIO_NUM      19
  #define Y4_GPIO_NUM      18
  #define Y3_GPIO_NUM       5
  #define Y2_GPIO_NUM       4
  #define VSYNC_GPIO_NUM   25
  #define HREF_GPIO_NUM    23
  #define PCLK_GPIO_NUM    22
  #define LED_FLASH        -1
#endif

// ── State ───────────────────────────────────────────
static bool     motionActive    = false;
static uint32_t lastMotionTime  = 0;
static uint32_t burstUntil      = 0;
static size_t   baselineSize    = 0;
static uint8_t  motionHits      = 0;
static int      motionPct       = DEFAULT_MOTION_PCT;
static uint8_t  consecutiveUploadFailures = 0;

static String baseUrl() {
  return String("http://") + SERVER_IP + ":" + String(SERVER_PORT);
}

// ─────────────────────────────────────────────────────
// WiFi
// ─────────────────────────────────────────────────────
static bool connectWiFi() {
  WiFi.mode(WIFI_STA);
  WiFi.setSleep(false);          // sleep adds seconds of latency to uploads

#if USE_STATIC_IP
  IPAddress ip(STATIC_IP_OCTETS);
  IPAddress gw(GATEWAY_OCTETS);
  IPAddress mask(SUBNET_OCTETS);
  IPAddress dns(DNS_OCTETS);
  if (!WiFi.config(ip, gw, mask, dns)) {
    Serial.println("Static IP config failed, falling back to DHCP");
  }
#endif

  Serial.printf("Connecting to %s", WIFI_SSID);
  WiFi.begin(WIFI_SSID, WIFI_PASS);

  uint32_t start = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - start < WIFI_ATTEMPT_MS) {
    delay(250);
    Serial.print(".");
  }

  if (WiFi.status() == WL_CONNECTED) {
    Serial.printf("\nConnected. IP: %s\n", WiFi.localIP().toString().c_str());
    return true;
  }
  Serial.println("\nWiFi connection failed");
  return false;
}

// Called from loop(). v4 had no recovery path at all once the link dropped.
static void ensureWiFi() {
  if (WiFi.status() == WL_CONNECTED) return;

  static uint8_t failures = 0;
  Serial.println("WiFi lost, reconnecting...");
  WiFi.disconnect();
  if (connectWiFi()) {
    failures = 0;
    return;
  }
  if (++failures >= 3) {
    Serial.println("WiFi unrecoverable, restarting");
    delay(1000);
    ESP.restart();
  }
}

// ─────────────────────────────────────────────────────
// HTTP helpers
// ─────────────────────────────────────────────────────
static bool uploadFrame(camera_fb_t* fb, bool motion) {
  HTTPClient http;
  String url = baseUrl() + "/upload.php?camId=" + CAM_ID + (motion ? "&motion=1" : "");

  if (!http.begin(url)) return false;
  http.setTimeout(HTTP_TIMEOUT_MS);
  http.addHeader("Content-Type", "image/jpeg");
  http.addHeader("X-Cam-Token", CAM_TOKEN);

  int code = http.POST(fb->buf, fb->len);
  if (code != 200) {
    Serial.printf("Upload failed: HTTP %d\n", code);
  }
  http.end();
  return code == 200;
}

// Minimal integer extractor, so we do not need to pull in a JSON library.
// Returns `fallback` when the key is absent or unparseable.
static int jsonInt(const String& body, const char* key, int fallback) {
  String needle = String("\"") + key + "\"";
  int at = body.indexOf(needle);
  if (at < 0) return fallback;
  at = body.indexOf(':', at + needle.length());
  if (at < 0) return fallback;

  int i = at + 1;
  while (i < (int)body.length() && (body[i] == ' ' || body[i] == '"')) i++;

  bool negative = (i < (int)body.length() && body[i] == '-');
  if (negative) i++;

  int start = i;
  while (i < (int)body.length() && isDigit(body[i])) i++;
  if (i == start) return fallback;

  int value = body.substring(start, i).toInt();
  return negative ? -value : value;
}

static bool jsonHasAction(const String& body, const char* action) {
  return body.indexOf(String("\"") + action + "\"") >= 0;
}

static void applySettings(const String& body) {
  sensor_t* s = esp_camera_sensor_get();
  if (!s) return;

  s->set_brightness(s, jsonInt(body, "brightness", 0));
  s->set_contrast(s,   jsonInt(body, "contrast", 0));
  s->set_saturation(s, jsonInt(body, "saturation", 0));
  s->set_quality(s,    jsonInt(body, "quality", 12));
  s->set_vflip(s,      jsonInt(body, "vflip", 1));
  s->set_hmirror(s,    jsonInt(body, "hmirror", 0));

  int framesize = jsonInt(body, "framesize", FRAMESIZE_SVGA);
  if (framesize >= FRAMESIZE_QVGA && framesize <= FRAMESIZE_UXGA) {
    s->set_framesize(s, (framesize_t)framesize);
    // Frame size changes invalidate the size baseline.
    baselineSize = 0;
  }

  int pct = jsonInt(body, "motionPct", DEFAULT_MOTION_PCT);
  motionPct = constrain(pct, 1, 90);
}

static void syncSettings() {
  HTTPClient http;
  String url = baseUrl() + "/settings.php?camId=" + CAM_ID;
  if (!http.begin(url)) return;
  http.setTimeout(HTTP_TIMEOUT_MS);
  http.addHeader("X-Cam-Token", CAM_TOKEN);

  if (http.GET() == 200) {
    applySettings(http.getString());
  }
  http.end();
}

static void checkCommands() {
  HTTPClient http;
  String url = baseUrl() + "/commands.php?camId=" + CAM_ID;
  if (!http.begin(url)) return;
  http.setTimeout(HTTP_TIMEOUT_MS);
  http.addHeader("X-Cam-Token", CAM_TOKEN);

  if (http.GET() == 200) {
    String payload = http.getString();

    // The server deletes the command as it hands it over, so each one is
    // delivered at most once and cannot re-trigger on the next poll.
    if (jsonHasAction(payload, "REBOOT")) {
      Serial.println("[CMD] Reboot requested");
      http.end();
      delay(250);
      ESP.restart();
    } else if (jsonHasAction(payload, "RECORD_VIDEO")) {
      // No SD card in this build, so "record" means a high-rate burst of
      // motion-flagged frames, which the server archives as an event.
      Serial.println("[CMD] Record burst requested");
      burstUntil = millis() + BURST_DURATION_MS;
      motionActive = true;
      lastMotionTime = millis();
    } else if (jsonHasAction(payload, "FLASH_ON")) {
#if LED_FLASH >= 0
      digitalWrite(LED_FLASH, HIGH);
#endif
    } else if (jsonHasAction(payload, "FLASH_OFF")) {
#if LED_FLASH >= 0
      digitalWrite(LED_FLASH, LOW);
#endif
    }
  }
  http.end();
}

// ─────────────────────────────────────────────────────
// Motion detection
//
// CAVEAT: this compares compressed JPEG sizes, which is a proxy for scene
// complexity, not for movement. It reacts to lights switching on and misses
// motion that does not change image entropy. It is kept because decoding
// frames on-device is too slow, but the server-side face recognition is the
// real filter. v4 additionally left the baseline at 0, so the very first frame
// always reported motion, and it compared against the immediately preceding
// frame, making sustained movement look like stillness.
// ─────────────────────────────────────────────────────
static bool detectMotion(size_t frameSize) {
  if (baselineSize == 0) {
    baselineSize = frameSize;      // prime on first frame, do not fire
    return false;
  }

  size_t delta = (frameSize > baselineSize) ? frameSize - baselineSize
                                            : baselineSize - frameSize;
  bool exceeded = delta > (baselineSize * (size_t)motionPct / 100);

  if (exceeded) {
    // Require consecutive hits so a single compression blip is not an event.
    if (motionHits < 255) motionHits++;
  } else {
    motionHits = 0;
    // Drift the baseline towards the quiet scene (exponential moving average),
    // so slow changes in daylight do not read as permanent motion.
    baselineSize = (baselineSize * 7 + frameSize) / 8;
  }

  return motionHits >= MOTION_CONFIRM_HITS;
}

// ─────────────────────────────────────────────────────
// Setup / loop
// ─────────────────────────────────────────────────────
void setup() {
  Serial.begin(115200);
  delay(100);
  Serial.println();
  Serial.printf("ESP32-CAM uploader v5 — camera id: %s\n", CAM_ID);

#if LED_FLASH >= 0
  pinMode(LED_FLASH, OUTPUT);
  digitalWrite(LED_FLASH, LOW);
#endif

  camera_config_t config = {};
  config.ledc_channel = LEDC_CHANNEL_0;
  config.ledc_timer   = LEDC_TIMER_0;
  config.pin_d0       = Y2_GPIO_NUM;
  config.pin_d1       = Y3_GPIO_NUM;
  config.pin_d2       = Y4_GPIO_NUM;
  config.pin_d3       = Y5_GPIO_NUM;
  config.pin_d4       = Y6_GPIO_NUM;
  config.pin_d5       = Y7_GPIO_NUM;
  config.pin_d6       = Y8_GPIO_NUM;
  config.pin_d7       = Y9_GPIO_NUM;
  config.pin_xclk     = XCLK_GPIO_NUM;
  config.pin_pclk     = PCLK_GPIO_NUM;
  config.pin_vsync    = VSYNC_GPIO_NUM;
  config.pin_href     = HREF_GPIO_NUM;
  config.pin_sccb_sda = SIOD_GPIO_NUM;
  config.pin_sccb_scl = SIOC_GPIO_NUM;
  config.pin_pwdn     = PWDN_GPIO_NUM;
  config.pin_reset    = RESET_GPIO_NUM;
  config.xclk_freq_hz = 20000000;
  config.pixel_format = PIXFORMAT_JPEG;
  config.frame_size   = FRAMESIZE_SVGA;
  config.jpeg_quality = 12;
  config.fb_count     = psramFound() ? 2 : 1;
  config.fb_location  = psramFound() ? CAMERA_FB_IN_PSRAM : CAMERA_FB_IN_DRAM;
  config.grab_mode    = CAMERA_GRAB_LATEST;

  esp_err_t err = esp_camera_init(&config);
  if (err != ESP_OK) {
    // v4 returned silently here, leaving a device that looked alive but had no
    // camera. Restart so the watchdog story is honest.
    Serial.printf("Camera init failed (0x%x), restarting\n", err);
    delay(3000);
    ESP.restart();
  }

  sensor_t* s = esp_camera_sensor_get();
  if (s) s->set_vflip(s, 1);

  if (!connectWiFi()) {
    delay(3000);
    ESP.restart();
  }

  syncSettings();
}

void loop() {
  ensureWiFi();

  uint32_t now = millis();
  static uint32_t lastCapture = 0;
  static uint32_t lastCmdPoll = 0;
  static uint32_t lastSettingsSync = 0;

  bool bursting = (int32_t)(burstUntil - now) > 0;
  uint32_t interval = (motionActive || bursting) ? ACTIVE_INTERVAL_MS : IDLE_INTERVAL_MS;

  if (now - lastCapture >= interval) {
    lastCapture = now;

    camera_fb_t* fb = esp_camera_fb_get();
    if (fb) {
      bool motion = detectMotion(fb->len);

      if (motion) {
        motionActive = true;
        lastMotionTime = now;
      } else if (motionActive && !bursting && now - lastMotionTime > COOLDOWN_MS) {
        motionActive = false;
      }

      // Archive during a burst too, so a RECORD_VIDEO command produces frames.
      bool flagMotion = motion || bursting;

      if (uploadFrame(fb, flagMotion)) {
        consecutiveUploadFailures = 0;
      } else if (++consecutiveUploadFailures >= 30) {
        // Persistent failure usually means a wedged TCP stack.
        Serial.println("Too many upload failures, restarting");
        esp_camera_fb_return(fb);
        delay(500);
        ESP.restart();
      }

      esp_camera_fb_return(fb);
    } else {
      Serial.println("Frame capture failed");
    }
  }

  if (now - lastCmdPoll >= CMD_POLL_MS) {
    lastCmdPoll = now;
    checkCommands();
  }

  if (now - lastSettingsSync >= SETTINGS_POLL_MS) {
    lastSettingsSync = now;
    syncSettings();
  }

  delay(10);   // yield to the WiFi stack and keep the watchdog fed
}
