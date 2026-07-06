/*
  ESP32-CAM Multi-Function System v4
  - Live MJPEG Streaming
  - Command Polling (Record/Reboot/Settings)
  - Dual-rate Motion Uploads
  - STATIC IP Configuration
  - Targeted for Local Server 10.10.10.110:8787
*/

#include "esp_camera.h"
#include <WiFi.h>
#include <HTTPClient.h>
#include <WebServer.h>

// ── CONFIG ──────────────────────────────────────────
#define BOARD_TYPE 0

// WiFi & Static IP Configuration
const char* ssid       = "ludo5";
const char* pass       = "Vanillalotus849";

IPAddress local_IP     = IPAddress(10, 10, 10, 2);
IPAddress gateway      = IPAddress(10, 10, 10, 1);
IPAddress subnet       = IPAddress(255, 255, 255, 0);
IPAddress primaryDNS   = IPAddress(10, 10, 10, 10);
IPAddress secondaryDNS = IPAddress(0, 0, 0, 0); // No secondary DNS

const char* SERVER_IP     = "10.10.10.110";
const int   SERVER_PORT   = 8787;
const char* CAM_ID        = "cam1";

const int IDLE_INTERVAL_MS    = 1000;  
const int ACTIVE_INTERVAL_MS   = 200;   
const int COOLDOWN_MS          = 5000;  
const int MOTION_THRESHOLD_PCT = 12;   

// Polling Intervals
const int CMD_POLL_INTERVAL    = 2000; 
const int SETTINGS_POLL_INTERVAL = 10000; 
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
#endif

bool motionActive = false;
unsigned long lastMotionTime = 0;
size_t lastJpegSize = 0;
WebServer server(80); 

void handleStream() {
  WiFiClient client = server.client();
  client.print("HTTP/1.1 200 OK\\r\\n");
  client.print("Content-Type: multipart/x-mixed-replace; boundary=frame\\r\\n\\r\\n");
  
  while (client.connected()) {
    camera_fb_t * fb = esp_camera_fb_get();
    if (!fb) continue;
    
    client.print("--frame\\r\\n");
    client.print("Content-Type: image/jpeg\\r\\n");
    client.print("Content-Length: " + String(fb->len) + "\\r\\n\\r\\n");
    client.write(fb->buf, fb->len);
    client.print("\\r\\n");
    
    esp_camera_fb_return(fb);
    delay(100); 
  }
}

void checkCommands() {
  HTTPClient http;
  String url = "http://" + String(SERVER_IP) + ":" + String(SERVER_PORT) + "/uploads/commands.json";
  http.begin(url);
  int code = http.GET();
  
  if (code == 200) {
    String payload = http.getString();
    if (payload.indexOf("RECORD_VIDEO") != -1) {
      Serial.println("[CMD] Video Recording Triggered!");
      // Recording logic can be added here
    } else if (payload.indexOf("REBOOT") != -1) {
      Serial.println("[CMD] Rebooting...");
      delay(500);
      ESP.restart();
    }
  }
  http.end();
}

void syncSettings() {
  HTTPClient http;
  String url = "http://" + String(SERVER_IP) + ":" + String(SERVER_PORT) + "/uploads/settings.json";
  http.begin(url);
  if (http.GET() == 200) {
    String payload = http.getString();
    sensor_t* s = esp_camera_sensor_get();
    // Logic to parse settings.json and apply s->set_brightness etc.
  }
  http.end();
}

void setup() {
  Serial.begin(115200);
  
  // Configure Static IP
  if (!WiFi.config(local_IP, gateway, subnet, primaryDNS, secondaryDNS)) {
    Serial.println("STA Failed to configure Static IP");
  }

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

  if (esp_camera_init(&config) != ESP_OK) return;

  sensor_t* s = esp_camera_sensor_get();
  s->set_vflip(s, 1);

  Serial.printf("Connecting to %s...", ssid);
  WiFi.begin(ssid, pass);
  unsigned long start = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - start < 15000) {
    delay(500);
    Serial.print(".");
  }
  
  if (WiFi.status() == WL_CONNECTED) {
    Serial.printf("\\nWiFi connected. IP: %s\\n", WiFi.localIP().toString().c_str());
  } else {
    Serial.println("\\nWiFi failed. Restarting...");
    delay(5000);
    ESP.restart();
  }
  
  server.on("/stream", handleStream);
  server.begin();
}

bool uploadFrame(camera_fb_t* fb, bool motion) {
  HTTPClient http;
  String url = "http://" + String(SERVER_IP) + ":" + String(SERVER_PORT) + "/upload.php?camId=" + CAM_ID;
  if (motion) url += "&motion=1";
  http.begin(url);
  http.addHeader("Content-Type", "image/jpeg");
  int code = http.POST((uint8_t*)fb->buf, fb->len);
  http.end();
  return (code == 200);
}

void loop() {
  server.handleClient(); 
  
  unsigned long now = millis();
  static unsigned long lastCapture = 0;
  static unsigned long lastCmdPoll = 0;
  static unsigned long lastSettingsSync = 0;

  int interval = motionActive ? ACTIVE_INTERVAL_MS : IDLE_INTERVAL_MS;
  if (now - lastCapture >= interval) {
    camera_fb_t* fb = esp_camera_fb_get();
    if (fb) {
      bool motion = (abs((int)fb->len - (int)lastJpegSize) > (lastJpegSize * MOTION_THRESHOLD_PCT / 100));
      lastJpegSize = fb->len;
      
      if (motion) {
        motionActive = true;
        lastMotionTime = now;
      } else if (motionActive && now - lastMotionTime > COOLDOWN_MS) {
        motionActive = false;
      }
      
      uploadFrame(fb, motion);
      esp_camera_fb_return(fb);
    }
    lastCapture = now;
  }

  if (now - lastCmdPoll >= CMD_POLL_INTERVAL) {
     checkCommands();
     lastCmdPoll = now;
  }

  if (now - lastSettingsSync >= SETTINGS_POLL_INTERVAL) {
     syncSettings();
     lastSettingsSync = now;
  }
}
