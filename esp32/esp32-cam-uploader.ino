/*
  ESP32-CAM → Remote Server Uploader v2
  Software motion detection via JPEG size differencing.
  Dual rate: idle 1fps (checking), active 5fps (recording).

  Board: 0=AI Thinker, 1=Freenove WROVER-DEV
  Per-device: change CAM_ID to cam1/cam2/cam3.
*/

#include "esp_camera.h"
#include <WiFi.h>
#include <HTTPClient.h>

// ── CONFIG ──────────────────────────────────────────
#define BOARD_TYPE 0

struct WifiNet { const char* ssid; const char* pass; };
const WifiNet WIFI_NETS[] = {
  {"ludo",          "Vanillalotus849"},
  {"ATT8Fdp4tz",    "5u3kzu?g9=93"},
};
const int WIFI_NET_COUNT = sizeof(WIFI_NETS) / sizeof(WIFI_NETS[0]);

const char* SERVER_URL    = "http://raggsy.com/cam/upload.php";
const char* CAM_ID        = "cam1";

const int IDLE_INTERVAL_MS    = 1000;   // motion check rate
const int ACTIVE_INTERVAL_MS  = 200;    // recording rate (~5 FPS)
const int COOLDOWN_MS         = 5000;   // keep recording after motion stops
const int MOTION_THRESHOLD_PCT = 12;    // JPEG size change % to trigger
// ────────────────────────────────────────────────────

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
#else
  #error "Invalid BOARD_TYPE"
#endif

bool motionActive = false;
unsigned long lastMotionTime = 0;
size_t lastJpegSize = 0;

void setup() {
  Serial.begin(115200);
  Serial.println("\nESP32-CAM v2 starting...");

  camera_config_t config;
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
  config.pin_sscb_sda = SIOD_GPIO_NUM;
  config.pin_sscb_scl = SIOC_GPIO_NUM;
  config.pin_pwdn     = PWDN_GPIO_NUM;
  config.pin_reset    = RESET_GPIO_NUM;
  config.xclk_freq_hz = 20000000;
  config.pixel_format = PIXFORMAT_JPEG;
  config.frame_size   = FRAMESIZE_SVGA;
  config.jpeg_quality = 12;
  config.fb_count     = 2;

  esp_err_t err = esp_camera_init(&config);
  if (err != ESP_OK) {
    Serial.printf("Camera init failed: 0x%x\n", err);
    return;
  }

  sensor_t* s = esp_camera_sensor_get();
  s->set_vflip(s, 1);
  s->set_brightness(s, 1);
  s->set_contrast(s, 0);

  for (int i = 0; i < WIFI_NET_COUNT; i++) {
    Serial.printf("Trying WiFi: %s\n", WIFI_NETS[i].ssid);
    WiFi.begin(WIFI_NETS[i].ssid, WIFI_NETS[i].pass);
    unsigned long start = millis();
    while (WiFi.status() != WL_CONNECTED && millis() - start < 15000) {
      delay(500);
      Serial.print(".");
    }
    if (WiFi.status() == WL_CONNECTED) {
      Serial.printf("\nWiFi connected. IP: %s\n", WiFi.localIP().toString().c_str());
      break;
    }
    Serial.println(" failed");
  }

  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("All networks failed. Restarting in 10s...");
    delay(10000);
    ESP.restart();
  }
}

bool detectMotion(size_t currentSize) {
  if (lastJpegSize == 0) {
    lastJpegSize = currentSize;
    return false;
  }
  size_t diff = currentSize > lastJpegSize
    ? currentSize - lastJpegSize
    : lastJpegSize - currentSize;
  float pct = (float)diff / (float)lastJpegSize * 100.0;
  lastJpegSize = currentSize;
  return pct > MOTION_THRESHOLD_PCT;
}

bool uploadFrame(camera_fb_t* fb, bool motion) {
  HTTPClient http;
  String url = String(SERVER_URL) + "?camId=" + CAM_ID;
  if (motion) url += "&motion=1";
  http.begin(url);
  http.addHeader("Content-Type", "image/jpeg");
  http.setTimeout(5000);

  int code = http.POST((uint8_t*)fb->buf, fb->len);
  http.end();

  if (code == 200) {
    Serial.println(motion ? "Upload [MOTION]" : "Upload OK");
    return true;
  }
  Serial.printf("Upload failed: %d\n", code);
  return false;
}

void loop() {
  unsigned long now = millis();
  static unsigned long lastCapture = 0;

  int interval = motionActive ? ACTIVE_INTERVAL_MS : IDLE_INTERVAL_MS;

  if (now - lastCapture < interval) {
    delay(10);
    return;
  }

  camera_fb_t* fb = esp_camera_fb_get();
  if (!fb) {
    Serial.println("Capture failed");
    delay(100);
    return;
  }

  lastCapture = now;
  bool motion = detectMotion(fb->len);

  if (motion) {
    if (!motionActive) Serial.println("Motion START");
    motionActive = true;
    lastMotionTime = now;
  } else if (motionActive && now - lastMotionTime > COOLDOWN_MS) {
    Serial.println("Motion STOP");
    motionActive = false;
  }

  uploadFrame(fb, motion);
  esp_camera_fb_return(fb);
}
