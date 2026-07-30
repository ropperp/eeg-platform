// ============================================================
// P1 Smart Meter Reader fuer Kaernten Netz (Iskraemeco AM550)
// ESP32 NodeMCU - Liest verschluesselte DLMS/COSEM Daten
// ueber die P1 Kundenschnittstelle (RJ12)
//
// Hardware:
// - SBC-NODEMCU-ESP32
// - RJ12 Stecker
// - Ab 2026-07-30 OHNE BC547-Transistor (R1/R2/R3/Diode entfallen damit ebenfalls) --
//   Pin 5 liegt jetzt DIREKT auf RX2. Das entfernt die Pegelanpassung, die der Transistor
//   nebenbei auch geleistet hat: WENN die P1-Schnittstelle des Zaehlers dort tatsaechlich 5V
//   ausgibt (nicht nur 3.3V), liegt das ausserhalb der Spezifikation des ESP32-GPIOs (max. ca.
//   3.6V) -- unbedingt VOR dem ersten Anschliessen mit einem Multimeter nachmessen. Falls 5V:
//   nicht direkt verbinden, sondern zumindest einen einfachen Spannungsteiler (z.B. 10k/20k)
//   oder wieder einen Pegelwandler/Transistor dazwischenschalten.
//
// Pinout ESP32:
// RJ12 Pin 1 (+5V)        → VIN ESP32
// RJ12 Pin 2 (DataReq)    → D4 (GPIO4) ESP32 (3.3V-Logik, unveraendert -- kein Transistor hier)
// RJ12 Pin 3 (Data GND)   → GND ESP32
// RJ12 Pin 5 (Daten)      → RX2 (GPIO16) ESP32 (bis 2026-07-30: → BC547 → RX2, siehe oben)
// RJ12 Pin 6 (Power GND)  → GND ESP32
//
// Fuer Deep Sleep:
// digitalWrite(PIN_DATA_REQUEST, LOW);  // Meter deaktivieren
// esp_deep_sleep_start();
// Nach Aufwachen: digitalWrite(PIN_DATA_REQUEST, HIGH);
// ============================================================

#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <ArduinoOTA.h>
#include <HardwareSerial.h>
#include <WebServer.h>
#include <Preferences.h>
#include <mbedtls/gcm.h>
#include <PubSubClient.h>
#include <time.h>
#include <DNSServer.h>

// -- Konfiguration (im Setup gesetzt, bleibt im Flash) --
String cfgSsid    = "";
String cfgPass    = "";
String cfgRC      = "";
String cfgZaehler = "";
bool      apMode  = false;
DNSServer dnsServer;

// HTTP-Login fuer ALLE Weboberflaechen
const char* httpUser = "admin";
const char* httpPass = "GreenData2026!";

// -- MQTT (Ziel im Setup aenderbar) --
String cfgMqttHost = "10.0.0.250";  // Broker-Domain oder IP
int    cfgMqttPort = 1883;          // Broker-Port
String cfgMqttUser  = "";           // MQTT Benutzername (optional)
String cfgMqttPass  = "";           // MQTT Passwort (optional)
String cfgMqttTopic = "";           // Topic-Template (leer = Standard-Schema)
int    cfgStatusSec  = 30;          // Heartbeat-Intervall in Sekunden (Status-Topic, ESP-Online-Check)
int    cfgLiveSec    = 5;           // Live-Daten-Intervall in Sekunden -- BEWUSST getrennt von
                                     // cfgStatusSec: wie oft Bezug/Einspeisung gesendet werden,
                                     // hat nichts mit der Online/Offline-Ueberpruefung zu tun.
// Standard-Topic: eeg/<rc-nummer>/meter/<zaehlernummer>/live
// Custom-Topic:   Platzhalter {rc} und {zaehler} werden ersetzt

// -- Zeit (NTP fuer Zeitstempel im Payload) --
const char* ntpServer = "pool.ntp.org";

// Zwei moegliche Transportwege fuer PubSubClient -- WiFiClientSecure fuer TLS (Port 8883),
// WiFiClient fuer unverschluesselt (z.B. Port 1883 im eigenen, vertrauenswuerdigen Netz).
// applyMqttClientMode() waehlt anhand von cfgMqttPort, welcher aktiv ist (siehe unten).
WiFiClient       wifiClient;
WiFiClientSecure wifiClientSecure;
PubSubClient     mqttClient(wifiClient);

// ── Pin Definitionen ─────────────────────────────────────────
// D4 = GPIO4 → RJ12 Pin 2 (Data Request)
// HIGH = Meter sendet Daten, LOW = Meter stumm (fuer Deep Sleep)
#define PIN_DATA_REQUEST 4

// ── Hardware ─────────────────────────────────────────────────
// UART2: GPIO16=RX2, GPIO17=TX2 (nicht verwendet)
// Invertierung true seit Entfernen des BC547 (2026-07-30): der Transistor hat das Signal der
// P1-Schnittstelle vorher bereits invertiert (das war seine Aufgabe neben der Pegelanpassung),
// deshalb stand hier vorher "false". Ohne Transistor kommt das ROHE, weiterhin invertierte
// Signal des Zaehlers direkt an -- die Invertierung muss jetzt also die Software uebernehmen.
// Falls die empfangenen Frames trotzdem nur Muell ergeben: hier auf false zurueckstellen und
// erneut testen (siehe /-Weboberflaeche, Feld "Rohdaten (Hex)" -- bei falscher Polaritaet bleibt
// das Feld leer bzw. es werden nie gueltige 0xDB-Frames erkannt).
HardwareSerial P1Serial(2);
WebServer server(80);
Preferences prefs;

// ── Globale Variablen ────────────────────────────────────────
String aesKeyHex   = "";  // AES-128 Key (32 Hex-Zeichen)
String p1RawHex    = "";  // Rohdaten letzter Frame (Hex)
String p1Decrypted = "";  // Entschluesselter Plaintext

// Geparste OBIS Messwerte
String zaehlerNr = "--";  // Zaehlernummer (0.0.96.1.1)
long eplusWh  = -1;       // P+ Zaehlerstand Bezug in Wh       (1.8.0)
long eminusWh = -1;       // P- Zaehlerstand Einspeisung in Wh (2.8.0)
long pplusW   = -1;       // Momentanleistung P+ Bezug in W    (1.7.0)
long pminusW  = -1;       // Momentanleistung P- Einspeis. in W (2.7.0)

// Zaehler-Erreichbarkeit (getrennt vom eigenen WLAN/MQTT-Online-Status): Zeitpunkt des
// letzten gueltigen P1-Telegramms. Faellt der Zaehler still (z.B. Inselbetrieb/Stromausfall
// beim Mitglied), bleibt der ESP ueber WLAN ggf. weiterhin erreichbar -- dieses Flag zeigt,
// dass das Problem beim Zaehler/Kunden liegt und nicht am ESP oder an der Plattform.
unsigned long lastValidFrameMillis = 0;
#define METER_TIMEOUT_MS 120000  // 2 Minuten ohne gueltiges Telegramm = Zaehler nicht erreichbar

bool meterReachable() {
  return lastValidFrameMillis != 0 && (millis() - lastValidFrameMillis) < METER_TIMEOUT_MS;
}

// ── Frame Buffer ─────────────────────────────────────────────
#define MAX_FRAME 1024    // Max. Framegroesse in Bytes
#define MAX_LOG   10      // Anzahl Log-Eintraege im Ringpuffer

uint8_t frameBuf[MAX_FRAME];
int framePos = 0;
String frameLog[MAX_LOG];
int logPos = 0;

// ── Hilfsfunktionen ──────────────────────────────────────────

void addLog(String entry) {
  frameLog[logPos % MAX_LOG] = entry;
  logPos++;
}

// Hex-String zu Byte-Array (fuer AES Key)
void hexToBytes(const char* hex, uint8_t* bytes, int len) {
  for (int i = 0; i < len; i++) {
    sscanf(hex + 2 * i, "%02hhx", &bytes[i]);
  }
}

// Byte-Array zu Hex-String (fuer Rohdaten Anzeige)
String bytesToHex(uint8_t* data, int len) {
  String hex = "";
  for (int i = 0; i < len; i++) {
    if (data[i] < 0x10) hex += "0";
    hex += String(data[i], HEX);
    hex += " ";
    if ((i + 1) % 16 == 0) hex += "\n";
  }
  return hex;
}

// AES Key maskieren: nur letzte 8 Zeichen sichtbar
String maskKey(String key) {
  if (key.length() != 32) return "";
  return String("XXXXXXXXXXXXXXXXXXXXXXXX") + key.substring(24);
}

// 4 Bytes Big-Endian als uint32 lesen
uint32_t read32(uint8_t* data, int offset) {
  return ((uint32_t)data[offset]   << 24) |
         ((uint32_t)data[offset+1] << 16) |
         ((uint32_t)data[offset+2] <<  8) |
         (uint32_t) data[offset+3];
}

// ── OBIS Parser ───────────────────────────────────────────────
// Liest Messwerte aus dem entschluesselten DLMS/COSEM Plaintext
//
// Plaintext Struktur (Iskraemeco AM550, Kaernten Netz):
// Byte 00:       0x0F = Data Notification Tag
// Byte 01-04:    Invoke ID
// Byte 05:       0x0C = DateTime Tag
// Byte 06-09:    Datum (Jahr 2 Bytes, Monat, Tag)
// Byte 10-15:    Zeit (Stunde, Minute, Sekunde, ...)
// Byte 16-29:    DLMS Header
// Byte 30-42:    Zaehlernummer (13 Bytes ASCII)
// Byte 43-55:    Uhrzeit/Datum OBIS Blocks
// Byte 56:       0x06 = Tag
// Byte 57-60:    P+ Zaehlerstand (1.8.0 Bezug) in Wh
// Byte 61:       0x06 = Tag
// Byte 62-65:    P- Zaehlerstand (2.8.0 Einspeisung) in Wh
// Byte 66:       0x06 = Tag
// Byte 67-70:    Q+ Zaehlerstand (3.8.0) in varh
// Byte 71:       0x06 = Tag
// Byte 72-75:    Q- Zaehlerstand (4.8.0) in varh
// Byte 76:       0x06 = Tag
// Byte 77-80:    Momentanleistung P+ (1.7.0 Bezug) in W
// Byte 81:       0x06 = Tag
// Byte 82-85:    Momentanleistung P- (2.7.0 Einspeisung) in W

// String fuer den Wert eines JSON-Felds absichern (Anfuehrungszeichen/Backslash escapen) --
// SSID und WLAN-Passwort kommen vom Nutzer und koennten sonst ungueltiges JSON erzeugen
String jsonEscape(String in) {
  String out = "";
  for (unsigned int i = 0; i < in.length(); i++) {
    char c = in.charAt(i);
    if (c == '"' || c == '\\') out += '\\';
    out += c;
  }
  return out;
}

// Zaehlernummer auf MQTT-topic-taugliche Zeichen reduzieren
String topicSafe(String in) {
  String out = "";
  for (unsigned int i = 0; i < in.length(); i++) {
    char c = in.charAt(i);
    if (isAlphaNumeric(c)) out += c;
  }
  return out;
}

// UTC-Zeitstempel ISO 8601 (leer, solange NTP nicht synchron)
String isoTimestamp() {
  time_t now = time(nullptr);
  if (now < 1700000000) return "";
  struct tm t;
  gmtime_r(&now, &t);
  char buf[25];
  strftime(buf, sizeof(buf), "%Y-%m-%dT%H:%M:%SZ", &t);
  return String(buf);
}

void parseObis(uint8_t* plain, int len) {
  // Mindestlaenge und Sanity Check
  if (len < 86)         return;
  if (plain[0] != 0x0f) return;  // kein Data Notification
  if (plain[5] != 0x0c) return;  // kein DateTime

  // Zaehlernummer (13 Bytes ASCII ab Offset 30)
  zaehlerNr = "";
  for (int i = 30; i < 43 && i < len; i++) {
    zaehlerNr += (char)plain[i];
  }

  // Zaehlerstaende
  long newEplus  = read32(plain, 57);  // 1.8.0 P+ Bezug in Wh
  long newEminus = read32(plain, 62);  // 2.8.0 P- Einspeisung in Wh

  // Momentanleistung
  long newPplus  = read32(plain, 77);  // 1.7.0 Momentan P+ in W
  long newPminus = read32(plain, 82);  // 2.7.0 Momentan P- in W

  // Plausibilitaetscheck: max. 100kW Momentanleistung
  // Verhindert fehlerhafte Werte bei beschaedigten Frames

  if (newPplus < 0 || newPminus < 0 || newPplus > 100000 || newPminus > 100000) {
    addLog("Ungueltige Leistung verworfen");
    return;
  }

  // Geprueft - Werte uebernehmen
  eplusWh  = newEplus;
  eminusWh = newEminus;
  pplusW   = newPplus;
  pminusW  = newPminus;
  lastValidFrameMillis = millis();  // Zaehler ist erreichbar (unabhaengig vom Publish-Throttle)

  // Sicherheitsnetz: nur senden, wenn die im /config-Formular eingetragene Zaehlernummer mit der
  // tatsaechlich aus dem P1-Telegramm gelesenen uebereinstimmt -- die Plattform ordnet Daten
  // ausschliesslich nach der im MQTT-Topic uebertragenen (= konfigurierten) Zaehlernummer einem
  // Zaehlpunkt zu, OHNE das gegen die echte Geraete-Zaehlernummer zu pruefen. Ein Tippfehler in
  // der Konfiguration wuerde sonst Daten unbemerkt dem falschen Zaehlpunkt zuordnen. topicSafe()
  // auf beiden Seiten, damit Leerzeichen/Fuellzeichen im Telegramm keinen falschen Mismatch
  // ausloesen. Lokales Dashboard (/, /data) zeigt die gelesenen Werte trotzdem an, damit ein
  // Mismatch beim Einrichten ueberhaupt auffaellt.
  if (cfgZaehler.length() > 0 && topicSafe(zaehlerNr) != topicSafe(cfgZaehler)) {
    addLog("Zaehlernummer-Mismatch: konfiguriert " + cfgZaehler + ", gelesen " + zaehlerNr);
    return;
  }

  // ── MQTT Publish (gedrosselt auf cfgLiveSec, Standard 5s) ────────────
  // Bewusst UNABHAENGIG von cfgStatusSec (Heartbeat/Online-Check) -- wie oft Live-Werte
  // gesendet werden, hat nichts mit der Online/Offline-Ueberpruefung des ESP zu tun.
  static unsigned long lastMqttPublish = 0;
  if (millis() - lastMqttPublish < (unsigned long)cfgLiveSec * 1000) return;
  lastMqttPublish = millis();

  mqttReconnect();
  if (mqttClient.connected()) {
    String zn = topicSafe(cfgZaehler);
    if (cfgRC.length() > 0 && zn.length() > 0) {
      String topic;
      if (cfgMqttTopic.length() > 0) {
        topic = cfgMqttTopic;
        topic.replace("{rc}",      cfgRC);
        topic.replace("{zaehler}", zn);
      } else {
        topic = String("eeg/") + cfgRC + "/meter/" + zn + "/live";
      }
      String payload = "{";
      payload += "\"pp\":"    + String(pplusW)   + ",";
      payload += "\"pm\":"    + String(pminusW)  + ",";
      payload += "\"ep\":"    + String(eplusWh)  + ",";
      payload += "\"em\":"    + String(eminusWh) + ",";
      payload += "\"znr\":\"" + zaehlerNr        + "\"";
      payload += "}";
      mqttClient.publish(topic.c_str(), payload.c_str());
    }
  }
}

// ── AES-128-GCM Entschluesselung ─────────────────────────────
// Entschluesselt DLMS General-Glo-Ciphering Frame
//
// Frame Aufbau:
// 0xDB        = General-Glo-Ciphering Tag
// 0x08        = System Title Laenge (immer 8)
// [8 Bytes]   = System Title (Geraete-ID, Teil des IV)
// [1 Byte]    = Length
// [1 Byte]    = Security Control
//               0x20 = nur verschluesselt (kein Auth Tag)
//               0x30 = verschluesselt + Auth Tag
// [4 Bytes]   = Frame Counter (Teil des IV)
// [n Bytes]   = AES-128-GCM verschluesselte Daten
// [12 Bytes]  = Auth Tag (nur bei Security Control 0x30)
//
// IV = System Title (8 Bytes) + Frame Counter (4 Bytes)

String decryptP1(uint8_t* raw, int len) {
  if (aesKeyHex.length() != 32) return "Kein gueltiger AES-Key gespeichert.";
  if (len < 20)                  return "Frame zu kurz";
  if (raw[0] != 0xDB)            return "Kein DLMS Frame (erwartet 0xDB)";
  if (raw[1] != 0x08)            return "Ungueltige System Title Laenge";

  // System Title fuer IV
  int systemTitleLen   = raw[1];      // = 8
  uint8_t* systemTitle = raw + 2;     // Bytes 2-9
  int offset = 2 + systemTitleLen;    // = 10

  // Length Field (1 oder 3 Bytes)
  if (raw[offset] & 0x80) {
    offset += 3;
  } else {
    offset += 1;
  }

  // Security Control Byte
  uint8_t securityControl = raw[offset++];

  // IV = System Title + Frame Counter
  uint8_t iv[12];
  memcpy(iv,     systemTitle,  8);
  memcpy(iv + 8, raw + offset, 4);
  offset += 4;

  // Auth Tag vorhanden wenn Bit 4 gesetzt (0x30)
  bool hasAuthTag = (securityControl & 0x10) != 0;

  int cipherLen = hasAuthTag ? len - offset - 12 : len - offset;
  if (cipherLen <= 0) return "Frame ungueltig";

  uint8_t* cipherData = raw + offset;
  uint8_t* authTag    = hasAuthTag ? raw + len - 12 : nullptr;
  int authTagLen      = hasAuthTag ? 12 : 0;

  // AES Key von Hex zu Bytes
  uint8_t aesKey[16];
  hexToBytes(aesKeyHex.c_str(), aesKey, 16);

  // mbedTLS GCM Entschluesselung
  uint8_t* plainData = new uint8_t[cipherLen];
  mbedtls_gcm_context gcm;
  mbedtls_gcm_init(&gcm);

  int ret = mbedtls_gcm_setkey(&gcm, MBEDTLS_CIPHER_ID_AES, aesKey, 128);
  if (ret != 0) { delete[] plainData; return "Key-Fehler"; }

  if (hasAuthTag) {
    // Mit Authentication Tag (Security Control 0x30)
    ret = mbedtls_gcm_auth_decrypt(&gcm, cipherLen, iv, 12,
      nullptr, 0, authTag, authTagLen, cipherData, plainData);
  } else {
    // Ohne Authentication Tag (Security Control 0x20)
    // Iskraemeco AM550 verwendet 0x20
    size_t outLen = 0;
    ret = mbedtls_gcm_starts(&gcm, MBEDTLS_GCM_DECRYPT, iv, 12);
    if (ret == 0) ret = mbedtls_gcm_update(&gcm, cipherData, cipherLen,
                                            plainData, cipherLen, &outLen);
    if (ret == 0) {
      uint8_t dummy[16];
      size_t dummyLen = 0;
      mbedtls_gcm_finish(&gcm, dummy, sizeof(dummy), &dummyLen, dummy, 0);
    }
  }

  mbedtls_gcm_free(&gcm);

  if (ret != 0) { delete[] plainData; return "Entschluesselung fehlgeschlagen - Key falsch?"; }

  // OBIS Werte aus Plaintext parsen
  parseObis(plainData, cipherLen);

  // Plaintext als lesbaren String (nicht-druckbare = Punkt)
  String result = "";
  for (int i = 0; i < cipherLen; i++) {
    if (plainData[i] >= 0x20 && plainData[i] < 0x7f) {
      result += (char)plainData[i];
    } else {
      result += ".";
    }
  }
  delete[] plainData;
  return result;
}

// ── HTML Webseite ─────────────────────────────────────────────
// Seite wird nur einmal geladen
// Messwerte werden per AJAX alle 2s vom /data Endpoint geholt
String buildPage() {
  String h = "";

  h += "<!DOCTYPE html><html><head>";
  h += "<meta charset='utf-8'>";
  h += "<title>P1 Smart Meter</title>";
  h += "<style>";
  h += "* { box-sizing: border-box; margin: 0; padding: 0; }";
  h += "body { font-family: sans-serif; background: #111; color: #eee; padding: 20px; }";
  h += "h1 { font-size: 20px; font-weight: 500; margin-bottom: 4px; }";
  h += ".sub { font-size: 13px; color: #888; margin-bottom: 20px; }";
  h += ".topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }";
  h += "a.btn { background: none; border: 1px solid #444; border-radius: 8px; padding: 8px 14px;";
  h += "  color: #ccc; font-size: 13px; cursor: pointer; text-decoration: none; display: inline-block; }";
  h += "a.btn:hover { background: #1e1e1e; }";
  h += ".grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px,1fr)); gap: 12px; margin-bottom: 20px; }";
  h += ".card { background: #1a1a1a; border-radius: 8px; padding: 16px; }";
  h += ".card p { font-size: 13px; color: #888; margin-bottom: 4px; }";
  h += ".card span { font-size: 22px; font-weight: 500; }";
  h += ".card.full { grid-column: 1 / -1; }";
  h += ".raw { background: #1a1a1a; border: 1px solid #333; border-radius: 12px; padding: 20px; }";
  h += ".raw p { font-size: 13px; color: #888; margin-bottom: 8px; }";
  h += "pre { font-size: 12px; font-family: monospace; color: #aaa; white-space: pre-wrap; word-break: break-all; }";
  h += "</style></head><body>";

  // Titelzeile
  h += "<div class='topbar'>";
  h += "<div><h1>P1 Smart Meter</h1><p class='sub'>Echtzeitdaten der Kundenschnittstelle</p></div>";
  h += "<a class='btn' href='/config'>Einstellungen</a>";
  h += "</div>";

  // Messkarten
  h += "<div class='grid'>";
  h += "<div class='card full'><p>Zaehler-Nr</p><span id='znr'>--</span></div>";
  h += "<div class='card'><p>Momentanleistung P+ Bezug (1.7.0)</p><span id='pp'>-- W</span></div>";
  h += "<div class='card'><p>Momentanleistung P- Einspeisung (2.7.0)</p><span id='pm'>-- W</span></div>";
  h += "<div class='card'><p>Zaehlerstand P+ Bezug (1.8.0)</p><span id='ep'>-- kWh</span></div>";
  h += "<div class='card'><p>Zaehlerstand P- Einspeisung (2.8.0)</p><span id='em'>-- kWh</span></div>";
  h += "</div>";

  // Rohdaten
  h += "<div class='raw'>";
  h += "<p>Rohdaten (Hex)</p><pre id='raw'>-</pre>";
  h += "</div>";

  // JavaScript - AJAX Datenabruf alle 2 Sekunden
  // Seite wird NICHT neu geladen, nur Werte werden aktualisiert
  h += "<script>";
  h += "function loadData(){";
  h += "  fetch('/data').then(function(r){ return r.json(); }).then(function(d){";
  h += "    if(d.znr) document.getElementById('znr').textContent = d.znr;";
  h += "    if(d.pp >= 0) document.getElementById('pp').textContent = d.pp + ' W';";
  h += "    if(d.pm >= 0) document.getElementById('pm').textContent = d.pm + ' W';";
  h += "    if(d.ep >= 0) document.getElementById('ep').textContent = (d.ep/1000).toFixed(3) + ' kWh';";
  h += "    if(d.em >= 0) document.getElementById('em').textContent = (d.em/1000).toFixed(3) + ' kWh';";
  h += "    document.getElementById('raw').textContent = d.raw || '-';";
  h += "  }).catch(function(){});";
  h += "}";
  h += "setInterval(loadData, 2000);";
  h += "loadData();";
  h += "</script>";
  h += "</body></html>";
  return h;
}

// Gibt das Status-Topic zurueck, oder "" wenn rc/zaehler noch nicht gesetzt
String statusTopic() {
  String zn = topicSafe(cfgZaehler);
  if (cfgRC.length() == 0 || zn.length() == 0) return "";
  return String("eeg/") + cfgRC + "/meter/" + zn + "/status";
}

// Waehlt anhand des konfigurierten Ports den Transport: 8883 = TLS (WiFiClientSecure),
// alles andere = unverschluesselt (WiFiClient). setInsecure() prueft KEIN Zertifikat --
// verschluesselt die Verbindung trotzdem, ohne dass jedes Geraet ein CA-Zertifikat pflegen
// muss (selbstsigniertes Server-Zertifikat, siehe scripts/mqtt_secure_setup.sh). Muss nach
// jeder Aenderung von cfgMqttPort erneut aufgerufen werden (Boot + /config-Speichern).
void applyMqttClientMode() {
  if (mqttClient.connected()) mqttClient.disconnect();
  if (cfgMqttPort == 8883) {
    wifiClientSecure.setInsecure();
    mqttClient.setClient(wifiClientSecure);
  } else {
    mqttClient.setClient(wifiClient);
  }
  mqttClient.setServer(cfgMqttHost.c_str(), cfgMqttPort);
}

void mqttReconnect() {
  if (mqttClient.connected()) return;
  String st = statusTopic();
  bool ok;
  if (st.length() > 0) {
    // connect() mit LWT: Broker publiziert "offline" bei Verbindungsabbruch
    if (cfgMqttUser.length() > 0) {
      ok = mqttClient.connect("esp32-p1-meter",
             cfgMqttUser.c_str(), cfgMqttPass.c_str(),
             st.c_str(), 0, true, "{\"status\":\"offline\"}");
    } else {
      ok = mqttClient.connect("esp32-p1-meter",
             st.c_str(), 0, true, "{\"status\":\"offline\"}");
    }
  } else {
    if (cfgMqttUser.length() > 0) {
      ok = mqttClient.connect("esp32-p1-meter", cfgMqttUser.c_str(), cfgMqttPass.c_str());
    } else {
      ok = mqttClient.connect("esp32-p1-meter");
    }
  }
  if (ok) {
    Serial.println("MQTT verbunden");
    if (st.length() > 0) {
      // Sofort "online" publishen (retained), damit Broker-Retain das LWT ueberschreibt --
      // inkl. WLAN-Diagnoseinfos (SSID/IP/Passwort), da dies der eigentliche Boot-/
      // Reconnect-Moment ist (siehe docs/ESP_IDEEN.md Punkt 1). Das Passwort nur hier statt bei
      // jedem periodischen Heartbeat mitzuschicken haelt es seltener im Klartext auf der Leitung.
      String hb = "{\"status\":\"online\",\"ssid\":\"" + jsonEscape(WiFi.SSID()) +
                  "\",\"ip\":\"" + WiFi.localIP().toString() + "\"";
      if (cfgPass.length() > 0) hb += ",\"wifi_password\":\"" + jsonEscape(cfgPass) + "\"";
      hb += "}";
      mqttClient.publish(st.c_str(), hb.c_str(), true);
    }
  }
}

// ── Passwortschutz fuer jede Weboberflaeche ───────────────────
bool requireAuth() {
  if (!server.authenticate(httpUser, httpPass)) {
    server.requestAuthentication();
    return false;
  }
  return true;
}

// ── Konfiguration laden ───────────────────────────────────────
void loadConfig() {
  prefs.begin("p1meter", false);
  aesKeyHex   = prefs.getString("aeskey",   "");
  cfgSsid     = prefs.getString("ssid",     "");
  cfgPass     = prefs.getString("pass",     "");
  cfgRC       = prefs.getString("rc",       "");
  cfgZaehler  = prefs.getString("zaehler",  "");
  cfgMqttHost = prefs.getString("mqtt_host", "10.0.0.250");
  cfgMqttPort = prefs.getInt("mqtt_port", 1883);
  cfgMqttUser  = prefs.getString("mqtt_user",  "");
  cfgMqttPass  = prefs.getString("mqtt_pass",  "");
  cfgMqttTopic = prefs.getString("mqtt_topic", "");
  cfgStatusSec = prefs.getInt("status_sec", 30);
  cfgLiveSec   = prefs.getInt("live_sec", 5);
}

// ── Mit gespeichertem WLAN verbinden (true bei Erfolg) ────────
bool connectSTA() {
  if (cfgSsid.length() == 0) return false;
  WiFi.mode(WIFI_STA);
  // Modem-Sleep (WiFi-Stromsparmodus) ist auf dem ESP32 standardmaessig aktiv und verzoegert/
  // verwirft dabei eingehende Multicast-Pakete (mDNS) -- macht das Geraet fuer die
  // OTA-Netzwerkport-Erkennung der Arduino-IDE (basiert auf mDNS) unzuverlaessig sichtbar
  // ("mal da, mal nicht"). Deaktivieren kostet bei einem staendig am Netzteil haengenden
  // Geraet keinen relevanten Strom, macht mDNS/OTA aber deutlich zuverlaessiger.
  WiFi.setSleep(false);
  if (cfgPass.length() == 0) WiFi.begin(cfgSsid.c_str());          // offenes Netz
  else                       WiFi.begin(cfgSsid.c_str(), cfgPass.c_str());
  unsigned long start = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - start < 15000) {
    delay(250);
    Serial.print(".");
  }
  return WiFi.status() == WL_CONNECTED;
}

// ── WLAN-Scan als JSON (Setup-Portal) ─────────────────────────
void handleScan() {
  int n = WiFi.scanNetworks();
  String j = "[";
  for (int i = 0; i < n; i++) {
    if (i) j += ",";
    String ss = WiFi.SSID(i);
    ss.replace("\"", "");
    bool open = (WiFi.encryptionType(i) == WIFI_AUTH_OPEN);
    j += "{\"ssid\":\"" + ss + "\",\"rssi\":" + String(WiFi.RSSI(i)) +
         ",\"open\":" + (open ? "true" : "false") + "}";
  }
  j += "]";
  WiFi.scanDelete();
  server.send(200, "application/json", j);
}

// ── 1. Oberflaeche: Setup-Portal (NUR WLAN) ───────────────────
String buildWifiPortal() {
  String h = "";
  h += "<!DOCTYPE html><html><head><meta charset='utf-8'>";
  h += "<meta name='viewport' content='width=device-width,initial-scale=1'>";
  h += "<title>P1 WLAN-Einrichtung</title><style>";
  h += "*{box-sizing:border-box;margin:0;padding:0}";
  h += "body{font-family:sans-serif;background:#111;color:#eee;padding:20px;max-width:480px;margin:auto}";
  h += "h1{font-size:20px;font-weight:500;margin-bottom:4px}";
  h += ".sub{font-size:13px;color:#888;margin-bottom:20px}";
  h += "h3{font-size:15px;font-weight:500;margin:0 0 10px}";
  h += "label{font-size:13px;color:#888;display:block;margin:10px 0 6px}";
  h += "input{width:100%;background:#111;border:1px solid #444;border-radius:8px;padding:10px 12px;color:#eee;font-size:14px}";
  h += "button{margin-top:10px;background:#2563eb;border:none;border-radius:8px;padding:11px 16px;color:#fff;font-size:14px;cursor:pointer}";
  h += ".sec{background:#1a1a1a;border:1px solid #333;border-radius:12px;padding:16px;margin-bottom:16px}";
  h += "#nets div{padding:8px 10px;border:1px solid #333;border-radius:8px;margin-top:6px;cursor:pointer;font-size:14px;display:flex;justify-content:space-between}";
  h += "#nets div:hover{background:#222}";
  h += ".hint{font-size:12px;color:#666;margin-top:4px}";
  h += "#msg{display:none;background:#14532d;color:#86efac;border:1px solid #166634;border-radius:8px;padding:10px;margin-top:12px;font-size:13px}";
  h += "</style></head><body>";
  h += "<h1>P1 Smart Meter</h1><p class='sub'>Schritt 1 von 2 - WLAN verbinden</p>";
  h += "<div class='sec'><h3>WLAN</h3>";
  h += "<button onclick='scan()'>Netzwerke suchen</button>";
  h += "<div id='nets'></div>";
  h += "<label>SSID</label><input id='ssid' placeholder='Netzwerkname'>";
  h += "<label>Passwort</label><input id='pass' type='password' placeholder='leer lassen bei offenem WLAN'>";
  h += "<div class='hint'>Fuer offene Netze das Passwortfeld leer lassen.</div></div>";
  h += "<button onclick='save()'>Speichern &amp; verbinden</button>";
  h += "<div id='msg'></div>";
  h += "<script>";
  h += "function scan(){fetch('/scan').then(function(r){return r.json();}).then(function(a){";
  h += "var n=document.getElementById('nets');n.innerHTML='';";
  h += "a.forEach(function(w){var d=document.createElement('div');";
  h += "d.innerHTML=\"<span>\"+w.ssid+\"</span><span>\"+(w.open?'offen':'gesichert')+\"</span>\";";
  h += "d.onclick=function(){document.getElementById('ssid').value=w.ssid;";
  h += "if(w.open)document.getElementById('pass').value='';};n.appendChild(d);});});}";
  h += "function save(){var b=new URLSearchParams();";
  h += "b.append('ssid',document.getElementById('ssid').value);";
  h += "b.append('pass',document.getElementById('pass').value);";
  h += "fetch('/save',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:b.toString()})";
  h += ".then(function(){var m=document.getElementById('msg');m.style.display='block';";
  h += "m.textContent='Gespeichert. Der ESP startet neu und verbindet sich.';});}";
  h += "</script></body></html>";
  return h;
}

void handleSaveWifi() {
  cfgSsid = server.arg("ssid");
  cfgPass = server.arg("pass");
  prefs.putString("ssid", cfgSsid);
  prefs.putString("pass", cfgPass);
  server.send(200, "text/plain", "OK");
  delay(800);
  ESP.restart();
}

// ── Access Point + Portal ─────────────────────────────────────
void startConfigPortal() {
  uint64_t chip = ESP.getEfuseMac();
  char apName[24];
  snprintf(apName, sizeof(apName), "P1-Setup-%04X", (uint16_t)(chip & 0xFFFF));
  WiFi.mode(WIFI_AP_STA);
  WiFi.softAP(apName);
  IPAddress apIP = WiFi.softAPIP();
  dnsServer.start(53, "*", apIP);
  Serial.println("Setup-AP: " + String(apName) + "  ->  http://" + apIP.toString());

  server.on("/",     []() { server.send(200, "text/html", buildWifiPortal()); });
  server.on("/scan", handleScan);
  server.on("/save", HTTP_POST, handleSaveWifi);
  server.onNotFound([]() { server.send(200, "text/html", buildWifiPortal()); });
  server.begin();
}

// ── 2. Oberflaeche: zaehler/rc/key + MQTT hinter Zahnrad ──────
String buildSettingsPage() {
  String keyHint = (aesKeyHex.length() == 32) ? "ein schluessel ist gespeichert" : "noch kein schluessel gespeichert";
  String h = "";
  h += "<!DOCTYPE html><html><head><meta charset='utf-8'>";
  h += "<meta name='viewport' content='width=device-width,initial-scale=1'>";
  h += "<title>P1 Zuordnung</title><style>";
  h += "*{box-sizing:border-box;margin:0;padding:0}";
  h += "body{font-family:sans-serif;background:#111;color:#eee;padding:20px;max-width:480px;margin:auto}";
  h += ".topbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:4px}";
  h += "h1{font-size:20px;font-weight:500}";
  h += ".sub{font-size:13px;color:#888;margin-bottom:20px}";
  h += ".gear{background:none;border:1px solid #444;border-radius:8px;color:#ccc;font-size:18px;cursor:pointer;padding:5px 10px}";
  h += "label{font-size:13px;color:#888;display:block;margin:12px 0 6px}";
  h += "input{width:100%;background:#1a1a1a;border:1px solid #444;border-radius:8px;padding:10px 12px;color:#eee;font-size:14px;font-family:monospace}";
  h += "button.save{margin-top:14px;background:#2563eb;border:none;border-radius:8px;padding:11px 16px;color:#fff;cursor:pointer}";
  h += ".danger{background:#7f1d1d;border:none;border-radius:8px;padding:11px 16px;color:#fff;cursor:pointer}";
  h += ".sec{background:#1a1a1a;border:1px solid #333;border-radius:12px;padding:16px;margin-top:14px}";
  h += ".hint{font-size:12px;color:#666;margin-top:4px}a{color:#60a5fa}";
  h += "#msg{display:none;background:#14532d;color:#86efac;border:1px solid #166634;border-radius:8px;padding:10px;margin-top:12px;font-size:13px}";
  h += "</style></head><body>";
  h += "<div class='topbar'><h1>zuordnung</h1><button class='gear' onclick='toggleMqtt()' title='mqtt-einstellungen'>&#9881;</button></div>";
  h += "<p class='sub'>schritt 2 von 2 - zaehler &amp; eeg</p>";
  h += "<label>zaehlernummer (13-stellig)</label><input id='zaehler' value='" + cfgZaehler + "' placeholder='1kfm00...'>";
  h += "<label>rc-nummer (marktpartner-id)</label><input id='rc' value='" + cfgRC + "' placeholder='rc108175'>";
  h += "<div class='hint'>wird automatisch klein geschrieben</div>";
  h += "<label>verschluesselungskey (32 zeichen)</label><input id='key' maxlength='32' value='" + aesKeyHex + "' placeholder='leer lassen, um den gespeicherten zu behalten'>";
  h += "<div class='hint'>" + keyHint + "</div>";
  h += "<div id='mqtt' class='sec' style='display:none'>";
  h += "<label>mqtt-topic (optional)</label><input id='mtopic' value='" + cfgMqttTopic + "' placeholder='leer = eeg/{rc}/meter/{zaehler}/live'>";
  h += "<div class='hint'>platzhalter: {rc} = rc-nummer, {zaehler} = zaehlernummer. leer lassen = standard-schema.</div>";
  h += "<label>mqtt-zieladresse (domain oder ip)</label><input id='mhost' value='" + cfgMqttHost + "' placeholder='z.B. broker.energieblick.at'>";
  h += "<label>mqtt-port</label><input id='mport' type='number' value='" + String(cfgMqttPort) + "' placeholder='1883'>";
  h += "<div class='hint'>1883 = unverschluesselt, 8883 = TLS (Zertifikat wird nicht geprueft, Verbindung trotzdem verschluesselt).</div>";
  h += "<label>mqtt-benutzername</label><input id='muser' value='" + cfgMqttUser + "' placeholder='vom Obmann/Admin erhalten'>";
  h += "<label>mqtt-passwort</label><input id='mpass' type='password' placeholder='leer lassen = unveraendert'>";
  h += "<div class='hint'>benutzername und passwort nur leer lassen, wenn der broker (noch) keine Anmeldung verlangt.</div>";
  h += "<label>heartbeat-intervall (sekunden)</label><input id='ssec' type='number' min='10' max='300' value='" + String(cfgStatusSec) + "' placeholder='30'>";
  h += "<div class='hint'>wie oft der esp seinen online-status an eeg/{rc}/meter/{zaehler}/status meldet. default: 30 s.</div>";
  h += "<label>live-daten-intervall (sekunden)</label><input id='lsec' type='number' min='2' max='300' value='" + String(cfgLiveSec) + "' placeholder='5'>";
  h += "<div class='hint'>wie oft Bezug/Einspeisung gesendet werden -- unabhaengig vom Heartbeat-Intervall oben. default: 5 s.</div>";
  h += "</div>";
  h += "<button class='save' onclick='save()'>speichern</button>";
  h += "<div id='msg'></div>";
  h += "<p style='margin-top:22px'><a href='/'>zum dashboard</a></p>";
  h += "<p style='margin-top:10px'><button class='danger' onclick='forget()'>wlan vergessen &amp; neu einrichten</button></p>";
  h += "<script>";
  h += "function toggleMqtt(){var m=document.getElementById('mqtt');m.style.display=(m.style.display=='none')?'block':'none';}";
  h += "function save(){var b=new URLSearchParams();";
  h += "b.append('zaehler',document.getElementById('zaehler').value);";
  h += "b.append('rc',document.getElementById('rc').value);";
  h += "b.append('key',document.getElementById('key').value);";
  h += "b.append('mqtt_topic',document.getElementById('mtopic').value);";
  h += "b.append('mqtt_host',document.getElementById('mhost').value);";
  h += "b.append('mqtt_port',document.getElementById('mport').value);";
  h += "b.append('mqtt_user',document.getElementById('muser').value);";
  h += "b.append('mqtt_pass',document.getElementById('mpass').value);";
  h += "b.append('status_sec',document.getElementById('ssec').value);";
  h += "b.append('live_sec',document.getElementById('lsec').value);";
  h += "fetch('/saveconfig',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:b.toString()})";
  h += ".then(function(){var m=document.getElementById('msg');m.style.display='block';m.textContent='gespeichert.';});}";
  h += "function forget(){if(confirm('wlan-daten loeschen und neu starten?'))";
  h += "fetch('/forgetwifi',{method:'POST'}).then(function(){alert('esp startet neu im setup-modus.');});}";
  h += "</script></body></html>";
  return h;
}

void handleSaveConfig() {
  if (!requireAuth()) return;
  cfgRC = server.arg("rc");
  cfgRC.toLowerCase();                 // rc-nummer klein speichern
  cfgZaehler = server.arg("zaehler");
  prefs.putString("rc",      cfgRC);
  prefs.putString("zaehler", cfgZaehler);
  String k = server.arg("key");
  if (k.length() == 32) {              // key nur ueberschreiben, wenn neu eingegeben
    aesKeyHex = k;
    prefs.putString("aeskey", k);
  }
  // MQTT-Ziel (Domain/IP + Port) speichern und live anwenden
  cfgMqttHost = server.arg("mqtt_host");
  if (cfgMqttHost.length() == 0) cfgMqttHost = "10.0.0.250";
  cfgMqttPort = server.arg("mqtt_port").toInt();
  if (cfgMqttPort <= 0) cfgMqttPort = 1883;
  prefs.putString("mqtt_host", cfgMqttHost);
  prefs.putInt("mqtt_port", cfgMqttPort);
  cfgMqttTopic = server.arg("mqtt_topic");
  prefs.putString("mqtt_topic", cfgMqttTopic);
  cfgStatusSec = server.arg("status_sec").toInt();
  if (cfgStatusSec < 10) cfgStatusSec = 30;
  prefs.putInt("status_sec", cfgStatusSec);
  cfgLiveSec = server.arg("live_sec").toInt();
  if (cfgLiveSec < 2) cfgLiveSec = 5;
  prefs.putInt("live_sec", cfgLiveSec);
  cfgMqttUser = server.arg("mqtt_user");
  prefs.putString("mqtt_user", cfgMqttUser);
  String newPass = server.arg("mqtt_pass");
  if (newPass.length() > 0) {
    cfgMqttPass = newPass;
    prefs.putString("mqtt_pass", cfgMqttPass);
  }
  applyMqttClientMode();
  server.send(200, "text/plain", "OK");
}

void handleForgetWifi() {
  if (!requireAuth()) return;
  prefs.putString("ssid", "");
  prefs.putString("pass", "");
  server.send(200, "text/plain", "OK");
  delay(800);
  ESP.restart();
}

// ── Setup ─────────────────────────────────────────────────────
void setup() {
  Serial.begin(115200);

  pinMode(PIN_DATA_REQUEST, OUTPUT);
  digitalWrite(PIN_DATA_REQUEST, HIGH);

  loadConfig();

  if (connectSTA()) {
    apMode = false;
    Serial.println("\nWLAN verbunden. IP: " + WiFi.localIP().toString());

    configTime(0, 0, ntpServer);   // NTP (UTC) fuer ts im Payload

    ArduinoOTA.setHostname("p1-smartmeter");
    ArduinoOTA.setPassword("GreenData2026!");
    ArduinoOTA.onStart([]() { Serial.println("OTA Start"); });
    ArduinoOTA.onEnd([]()   { Serial.println("OTA Ende");  });
    ArduinoOTA.onError([](ota_error_t e) { Serial.printf("OTA Fehler: %u\n", e); });
    ArduinoOTA.begin();

    server.on("/", []() {
      server.send(200, "text/html", buildPage());
    });
    server.on("/data", []() {
      String json = "{";
      json += "\"znr\":\"" + zaehlerNr + "\",";
      json += "\"pp\":"  + String(pplusW)   + ",";
      json += "\"pm\":"  + String(pminusW)  + ",";
      json += "\"ep\":"  + String(eplusWh)  + ",";
      json += "\"em\":"  + String(eminusWh) + ",";
      String r = p1RawHex;
      r.replace("\n", "\\n");
      json += "\"raw\":\"" + r + "\"";
      json += "}";
      server.send(200, "application/json", json);
    });
    server.on("/config",     []() { if (!requireAuth()) return; server.send(200, "text/html", buildSettingsPage()); });
    server.on("/saveconfig", HTTP_POST, handleSaveConfig);
    server.on("/forgetwifi", HTTP_POST, handleForgetWifi);

    server.begin();
    Serial.println("Webserver gestartet");

    applyMqttClientMode();

    P1Serial.begin(115200, SERIAL_8N1, 16, 17, true);  // invert=true, siehe Kommentar bei P1Serial oben

  } else {
    apMode = true;
    Serial.println("\nKein WLAN gespeichert/erreichbar -> Setup-Portal");
    startConfigPortal();
  }
}

// ── Loop ──────────────────────────────────────────────────────
void loop() {
  if (apMode) {
    dnsServer.processNextRequest();
    server.handleClient();
    return;
  }

  mqttClient.loop();

  // Periodischer Heartbeat auf Status-Topic (retain=true)
  static unsigned long lastHeartbeat = 0;
  if (mqttClient.connected()) {
    unsigned long now = millis();
    if (now - lastHeartbeat >= (unsigned long)cfgStatusSec * 1000) {
      lastHeartbeat = now;
      String st = statusTopic();
      if (st.length() > 0) {
        String ts = isoTimestamp();
        String hb = "{\"status\":\"online\"";
        if (ts.length() > 0) hb += ",\"ts\":\"" + ts + "\"";
        hb += ",\"ssid\":\"" + jsonEscape(WiFi.SSID()) + "\"";
        hb += ",\"ip\":\"" + WiFi.localIP().toString() + "\"";
        hb += ",\"meter_ok\":" + String(meterReachable() ? "true" : "false");
        // wifi_password bewusst NICHT bei jedem periodischen Heartbeat mitschicken -- nur beim
        // MQTT-(Re-)Connect in mqttReconnect() (= einmal pro Boot, und ein WLAN-Wechsel ueber
        // das Config-Formular fuehrt immer zu ESP.restart(), also ebenfalls zu einem frischen
        // Connect). So liegt das Passwort seltener auf der Leitung, ohne dass es bei einem
        // echten WLAN-/Boot-Ereignis fehlt.
        hb += "}";
        mqttClient.publish(st.c_str(), hb.c_str(), true);
      }
    }
  }

  ArduinoOTA.handle();
  server.handleClient();

  while (P1Serial.available()) {
    uint8_t b = P1Serial.read();

    if (b == 0xDB && framePos == 0) {
      frameBuf[framePos++] = b;
    } else if (b == 0xDB && framePos > 0) {
      if (frameBuf[1] != 0x08) {
        framePos = 0;
        frameBuf[framePos++] = b;
      }
    } else if (framePos > 0) {
      if (framePos < MAX_FRAME) frameBuf[framePos++] = b;
    }

    if (framePos == 106 && frameBuf[1] == 0x08) {
      p1RawHex    = bytesToHex(frameBuf, framePos);
      p1Decrypted = decryptP1(frameBuf, framePos);
      framePos = 0;
    }
  }
}
