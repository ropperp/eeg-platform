# App-API (Mitglieder-Programmierschnittstelle)

Technische Referenz für die JSON-API unter `/api/v1/*`, mit der eine native Mitglieder-App
(iOS/Android) gegen die EEG-Plattform arbeitet -- eigene Zählpunkte, Verbrauchsverlauf,
Rechnungen inkl. PDF. Implementiert seit 30.08.2026 (`webapp/src/AppApiAuth.php`,
`database/migrate_20260830.sql`, Routen in `webapp/public/index.php`).

> Falls diese Datei einer KI zum Programmieren der Client-App übergeben wurde: alle hier
> beschriebenen Endpunkte existieren bereits fertig implementiert und getestet auf dem Server --
> es muss nur noch der Client (die App) dagegen gebaut werden, nicht das Backend.

## Übersicht

- **Base-URL:** `https://stromfueralle.at` (Produktivsystem) bzw. `https://portal.stromfueralle.at`
  (gleiche Webapp, andere Subdomain -- beide funktionieren identisch für die API).
- **Format:** JSON in beide Richtungen. Requests mit Body: `Content-Type: application/json`
  (kein Formular-Encoding!). Antworten: immer `Content-Type: application/json; charset=UTF-8`,
  außer beim PDF-Endpunkt (`application/pdf`).
- **Auth:** `Authorization: Bearer <access_token>` auf allen `/api/v1/*`-Endpunkten außer den
  Login-Endpunkten selbst.
- **Scope:** Die App ist reine **Mitglieder-Selbstbedienung** -- ein Account ohne eigene
  Mitgliedschaft (z. B. ein reiner Obmann-/Platform-Admin-Zugang ohne eigenen Mitgliedsdatensatz)
  kann sich hier nicht anmelden. Für Verwaltungsfunktionen gibt es das Web-Portal.
- **Getrennt von der Smart-Home-API** (`member_api_keys`, `GET /api/v1/me` + `GET /api/v1/live`):
  Das sind langlebige, vom Mitglied selbst im Web-Portal erzeugte Schlüssel für Skripte/Node-RED
  (siehe `/portal/my/api-keys`) -- ein anderer Anwendungsfall als ein Mensch, der sich in der App
  anmeldet. Nicht verwechseln, unterschiedliche Endpunkte, unterschiedliches Token-Format.

---

## Auth-Flow

```
POST /api/v1/login  (email + password)
        │
        ├─ 2FA aktiv? ──► { totp_required: true, login_ticket }
        │                       │
        │                       ▼
        │              POST /api/v1/login/2fa  (login_ticket + code)
        │                       │
        ▼                       ▼
   ┌─────────────────────────────────────┐
   │ Mitglied in genau 1 EEG?             │
   │  ja  → { access_token, refresh_token, member }
   │  nein (mehrere) → { community_selection_required: true, selection_ticket, memberships }
   │                       │
   │                       ▼
   │              POST /api/v1/login/select-community (selection_ticket + community_id)
   │                       │
   │                       ▼
   │              { access_token, refresh_token, member }
   │  keine aktive Mitgliedschaft → 403 Fehler
   └─────────────────────────────────────┘
```

Danach für jeden weiteren Request: `Authorization: Bearer <access_token>`.

- **Access-Token:** 15 Minuten gültig, selbst-signiert (kein DB-Zugriff zum Prüfen nötig).
- **Refresh-Token:** 30 Tage gültig, **rotiert bei jeder Erneuerung** (das alte wird beim Tausch
  sofort ungültig, das neue muss die App speichern). Im sicheren Speicher des Geräts ablegen
  (iOS: Keychain, Android: Keystore/EncryptedSharedPreferences) -- niemals in
  UserDefaults/SharedPreferences im Klartext.
- Läuft der Access-Token ab: still im Hintergrund `POST /api/v1/token/refresh` aufrufen, kein
  erneutes Login nötig. Schlägt DAS fehl (Refresh-Token ebenfalls abgelaufen/widerrufen): Nutzer
  muss sich neu anmelden.

### POST /api/v1/login

```json
// Request
{ "email": "mitglied@example.at", "password": "...", "device_label": "iPhone von Patrick" }
```
`device_label` optional, erscheint später als Bezeichnung dieses Geräts (z. B. für eine
künftige "Angemeldete Geräte"-Übersicht) -- ohne Angabe bleibt es leer.

| Fall | HTTP | Body |
|---|---|---|
| Falsches Passwort/unbekannte E-Mail | 401 | `{"error": "E-Mail oder Passwort falsch."}` |
| Zu viele Fehlversuche (5/E-Mail bzw. 20/IP in 15 Min) | 429 | `{"error": "Zu viele Fehlversuche. Bitte in 15 Minuten erneut versuchen."}` |
| 2FA aktiv | 200 | `{"totp_required": true, "login_ticket": "..."}` |
| Keine aktive Mitgliedschaft | 403 | `{"error": "Dieser Account hat keine aktive Mitgliedschaft. Die App ist nur für Mitglieder gedacht."}` |
| Mitglied in mehreren EEGs | 200 | siehe "Mehrfach-Mitgliedschaft" unten |
| Erfolg (genau 1 EEG) | 200 | siehe "Erfolgsantwort" unten |

### POST /api/v1/login/2fa

```json
// Request
{ "login_ticket": "...", "code": "123456" }
```
`login_ticket` kommt aus der `/login`-Antwort, 5 Minuten gültig. Fehlerfälle: 401 (Ticket
ungültig/abgelaufen -- neu einloggen), 429 (5 Fehlversuche/15 Min, eigener Zähler pro Account),
401 mit `{"error": "Code ungültig oder abgelaufen."}`. Erfolg: wie `/login`.

### POST /api/v1/login/select-community

Nur nötig, wenn `/login` oder `/login/2fa` `community_selection_required: true` zurückgegeben
haben (Account ist Mitglied in mehreren EEGs gleichzeitig).

```json
// Request
{ "selection_ticket": "...", "community_id": "<eine der zurückgegebenen memberships[].community_id>", "device_label": "..." }
```
Erfolg: wie `/login`. 400 bei ungültiger `community_id`, 401 bei abgelaufenem Ticket.

### Erfolgsantwort (alle drei Login-Endpunkte, bei genau einer aktiven Mitgliedschaft)

```json
{
  "access_token": "eyJ...siehe Format unten...",
  "refresh_token": "3f9a2b...(64 Hex-Zeichen)",
  "expires_in": 900,
  "member": {
    "id": "uuid",
    "name": "Anna Mustermann",
    "community_id": "uuid",
    "community_name": "EEG Feldkirchen Südwest"
  }
}
```

### Mehrfach-Mitgliedschaft (statt der Erfolgsantwort)

```json
{
  "community_selection_required": true,
  "selection_ticket": "...",
  "memberships": [
    { "community_id": "uuid-a", "community_name": "EEG Feldkirchen Südwest" },
    { "community_id": "uuid-b", "community_name": "EEG Klagenfurt Ost" }
  ]
}
```

### POST /api/v1/token/refresh

```json
// Request
{ "refresh_token": "..." }
// Antwort (Erfolg)
{ "access_token": "...", "refresh_token": "...", "expires_in": 900 }
```
401 bei ungültigem/abgelaufenem/bereits-benutztem Refresh-Token → Nutzer muss sich neu anmelden.
**Wichtig:** das zurückgegebene `refresh_token` ist ein NEUES -- das alte ist ab sofort ungültig
(Rotation, Diebstahlschutz). Immer überschreiben, nie das alte weiterverwenden.

### POST /api/v1/logout

```json
// Request
{ "refresh_token": "..." }
// Antwort (immer)
{ "status": "ok" }
```
Meldet nur DIESES Gerät ab (widerruft dessen Refresh-Token). Der Access-Token bleibt technisch
bis zu seinem Ablauf (max. 15 Min) gültig -- für eine App i. d. R. unkritisch, da er lokal nach
dem Logout ohnehin verworfen wird.

---

## Daten-Endpunkte

Alle mit `Authorization: Bearer <access_token>`. Bei fehlendem/ungültigem/abgelaufenem Token:
`401 {"error": "..."}` (Token ist abgelaufen → `/api/v1/token/refresh` aufrufen und Request
wiederholen).

### GET /api/v1/dashboard

Für den Startbildschirm der App -- ein Request, alle wichtigen Kennzahlen.

```json
{
  "member": { "id": "uuid", "name": "Anna Mustermann" },
  "current_power_w": 320.0,
  "community": { "bezug_w": 4200, "einspeisung_w": 1800, "active_meters": 12, "total_meters": 14 },
  "current_month": { "label": "August 2026", "teilnahme_kwh": 245.3, "erzeugung_kwh": 0.0 },
  "last_invoice": { "id": "uuid", "rechnungsnummer": "RC108175-2026-Q2-001", "saldo_eur": 42.10, "created_at": "2026-07-01T08:00:00+00:00" }
}
```
`current_power_w`: positiv = Bezug, negativ = Einspeisung, `null` = kein aktueller Messwert
(kein ESP32 / gerade offline). `current_month`/`last_invoice`: `null`, wenn (noch) keine Daten
vorhanden.

### GET /api/v1/consumption?months=6

Monatlicher Verlauf für ein Balkendiagramm. `months` optional, 1–24, Default 6.

```json
{ "months": [
  { "month": "2026-08", "label": "August 2026", "teilnahme_kwh": 245.3, "erzeugung_kwh": 0.0 },
  { "month": "2026-07", "label": "Juli 2026", "teilnahme_kwh": 210.1, "erzeugung_kwh": 0.0 }
] }
```
Neueste zuerst. Nur Monate mit belastbarer Datenqualität (L1/L2) -- ein fehlender Monat in der
Liste heißt "noch keine verlässlichen Daten", nicht zwingend "kein Verbrauch".

### GET /api/v1/invoices

```json
{ "invoices": [
  {
    "id": "uuid", "rechnungsnummer": "RC108175-2026-Q2-001", "saldo_eur": 42.10,
    "quartal": "2026-Q2", "period_from": "2026-04-01", "period_to": "2026-06-30",
    "sent_at": "2026-07-01T08:00:00+00:00", "created_at": "2026-07-01T07:55:00+00:00"
  }
] }
```
`saldo_eur`: positiv = Mitglied zahlt, negativ = Guthaben. `sent_at: null` = Rechnung ist
angelegt, aber noch nicht offiziell versendet (in der App ggf. ausblenden oder als "in
Bearbeitung" markieren).

### GET /api/v1/invoices/:id/pdf

Liefert die Rechnung als PDF (`Content-Type: application/pdf`), live gerendert (kein Caching
nötig). 404, wenn die ID nicht existiert; 403, wenn sie einem anderen Mitglied gehört.

### GET /api/v1/metering-points

```json
{ "metering_points": [
  { "id": "uuid", "zaehlpunkt_nr": "AT0070000000000000000000012345", "type": "consumer", "active": true, "registered_at": "2026-01-15" }
] }
```
`type`: `consumer` (Verbraucher), `producer` (Einspeiser/PV), `prosumer` (beides).

---

## Fehlerformat

Alle Fehlerantworten: `{"error": "<lesbarer deutscher Text>"}`, passender HTTP-Status
(401 = Auth fehlt/ungültig, 403 = kein Zugriff auf diese Ressource, 404 = nicht gefunden,
429 = Rate-Limit, 400 = ungültige Eingabe).

## Rate-Limiting

- Login (`/login`): 5 Fehlversuche pro E-Mail bzw. 20 pro IP-Adresse, jeweils 15-Minuten-Fenster.
- 2FA-Code (`/login/2fa`): 5 Fehlversuche pro Account, 15-Minuten-Fenster.
- Beide Zähler sind mit dem Web-Login geteilt (derselbe Redis-Zähler) -- ein Angreifer kann die
  Sperre nicht umgehen, indem er zwischen App- und Web-Login wechselt.
- Datenendpunkte (`/dashboard`, `/consumption`, ...) sind aktuell NICHT extra rate-limitiert
  (nur durch die kurze Access-Token-Gültigkeit natürlich begrenzt).

## Sicherheitshinweise für die Client-Implementierung

1. **Refresh-Token sicher speichern** (iOS Keychain / Android Keystore), niemals im Klartext in
   UserDefaults, SharedPreferences, Logs oder Crash-Reports.
2. **HTTPS ist Pflicht** -- die Server-Domain hat ein gültiges Zertifikat, kein
   Certificate-Pinning nötig, aber niemals `http://` verwenden.
3. **Access-Token nicht cachen/persistieren** über einen App-Neustart hinaus -- beim Start immer
   frisch per `/token/refresh` holen (er ist eh nur 15 Minuten gültig).
4. Bei 401 auf einem Daten-Endpunkt: einmal `/token/refresh` versuchen und den Request
   wiederholen; schlägt auch das fehl, zum Login-Bildschirm zurück.
5. Bei 429: die App sollte die Fehlermeldung anzeigen und NICHT automatisch sofort erneut
   versuchen (das würde den Rate-Limiter unnötig weiter befeuern).

## Bekannte Einschränkungen (bewusste v1-Vereinfachungen)

- Kein Endpunkt zum Auflisten/Abmelden anderer angemeldeter Geräte (nur das eigene per
  `/logout`) -- könnte später ergänzt werden (`app_sessions`-Tabelle trägt bereits
  `device_label`/`last_used_at`, die Grundlage ist also schon da).
- Kein Push-Notification-Mechanismus (z. B. "neue Rechnung verfügbar").
- Kein Wechsel der aktiven Community nach dem Login, ohne sich neu anzumelden (bei
  Mehrfach-Mitgliedschaft ist der Access-Token an genau eine Community gebunden).
