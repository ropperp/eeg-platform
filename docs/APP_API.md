# App-API (Mitglieder- & Obmann-Programmierschnittstelle)

Technische Referenz für die JSON-API unter `/api/v1/*`, mit der eine native App (iOS/Android)
gegen die EEG-Plattform arbeitet -- eigene Zählpunkte, Verbrauchsverlauf, Rechnungen inkl. PDF,
Verträge digital unterschreiben, Dokumente, DSGVO-Export, Support-Tickets, Profil/Passwort/2FA,
und (mit Obmann-Zugang) Mitglieder anlegen/bearbeiten/Dateien hochladen. Implementiert seit
30.08.2026 (`webapp/src/AppApiAuth.php`, `database/migrate_20260830.sql`,
`database/migrate_20260831.sql`, Routen in `webapp/public/index.php`).

> Falls diese Datei einer KI zum Programmieren der Client-App übergeben wurde: alle hier
> beschriebenen Endpunkte existieren bereits fertig implementiert und getestet auf dem Server --
> es muss nur noch der Client (die App) dagegen gebaut werden, nicht das Backend. Für eine
> vollständige Anleitung ausschließlich in Textform (kein HTML/Artifact) siehe `app.md` im
> Repo-Wurzelverzeichnis -- dort steht zusätzlich der komplette v1-Bildschirmplan.

## Übersicht

- **Base-URL:** `https://stromfueralle.at` (Produktivsystem) bzw. `https://portal.stromfueralle.at`
  (gleiche Webapp, andere Subdomain -- beide funktionieren identisch für die API).
- **Format:** JSON in beide Richtungen für die meisten Endpunkte. Requests mit JSON-Body:
  `Content-Type: application/json` (kein Formular-Encoding!). Datei-Uploads (Profilbild,
  Mitglied-Dateien) sind die Ausnahme: dort `multipart/form-data`, siehe die jeweiligen
  Endpunkte unten. Antworten: immer `Content-Type: application/json; charset=UTF-8`, außer bei
  PDF-Endpunkten (`application/pdf`) und Datei-Downloads (`Content-Type` der jeweiligen Datei).
- **Auth:** `Authorization: Bearer <access_token>` auf allen `/api/v1/*`-Endpunkten außer den
  Login-Endpunkten selbst.
- **Zwei Rollen im selben Token-System:** `role: "member"` (Mitglied, mit `member_id`) und
  `role: "manager"` (Obmann bzw. Platform-Admin, `member_id` meist `null` -- ein reiner
  Obmann-Account hat nicht zwingend eine eigene Mitgliedschaft in der EEG). Ein Account kann
  BEIDES gleichzeitig sein (z. B. Obmann, der auch selbst Mitglied ist) -- dann bietet der
  Login eine Auswahl an (siehe Auth-Flow unten). Jeder Endpunkt unten ist mit der nötigen Rolle
  markiert. Endpunkte, die eine `role: "manager"`-Berechtigung verlangen, liefern mit einem
  gültigen Mitglied-Token `403 {"error": "Diese Aktion erfordert einen Obmann-Zugang."}`.
- **Getrennt von der Smart-Home-API** (`member_api_keys`, `GET /api/v1/me` + `GET /api/v1/live`):
  Das sind langlebige, vom Mitglied selbst im Web-Portal erzeugte Schlüssel für Skripte/Node-RED
  (siehe `/portal/my/api-keys`) -- ein anderer Anwendungsfall als ein Mensch, der sich in der App
  anmeldet. Nicht verwechseln, unterschiedliche Endpunkte, unterschiedliches Token-Format.
- **Bewusst außerhalb dieser v1-API** (nur im Web-Portal): Abrechnung/Rechnungslauf-Erstellung,
  EDA-Import, Vertrags-Versand als Obmann (nur Signieren als Mitglied ist enthalten),
  EEG-Einstellungen, Beitrittsanträge freigeben, Postfach, Platform-Admin-Funktionen. Siehe
  "Bekannte Einschränkungen" unten.

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
| Weder Mitgliedschaft noch Obmann-Zugang | 403 | `{"error": "Dieser Account hat weder eine aktive Mitgliedschaft noch eine Obmann-Berechtigung in einer EEG."}` |
| Genau eine Rolle (Mitglied ODER Obmann in genau einer EEG) | 200 | siehe "Erfolgsantwort" unten |
| Mehrere Rollen (mehrere EEGs, oder gleichzeitig Mitglied UND Obmann) | 200 | siehe "Mehrfach-Rolle" unten |

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
haben (Account hat mehr als eine Rollen-Option -- mehrere EEGs und/oder gleichzeitig Mitglied
UND Obmann).

```json
// Request
{ "selection_ticket": "...", "community_id": "<memberships[].community_id>", "role": "<memberships[].role>", "device_label": "..." }
```
`role` ist neu seit der Obmann-Erweiterung (30.08.2026 → 31.08.2026): notwendig, wenn derselbe
Account in DERSELBEN EEG sowohl Mitglied als auch Obmann ist -- `community_id` allein wäre dann
nicht mehr eindeutig. Fehlt `role` im Request, wird `"member"` angenommen (Rückwärtskompatibilität
mit älteren Clients, die das Feld noch nicht kennen). Erfolg: wie `/login`. 400 bei ungültiger
Kombination aus `community_id`/`role`, 401 bei abgelaufenem Ticket.

### Erfolgsantwort (alle drei Login-Endpunkte, bei genau einer Rollen-Option)

```json
{
  "access_token": "eyJ...siehe Format unten...",
  "refresh_token": "3f9a2b...(64 Hex-Zeichen)",
  "expires_in": 900,
  "role": "member",
  "account": {
    "member_id": "uuid",
    "name": "Anna Mustermann",
    "community_id": "uuid",
    "community_name": "EEG Feldkirchen Südwest"
  }
}
```
`role` ist `"member"` oder `"manager"`. Bei `role: "manager"` ist `account.member_id` meist
`null` (reiner Obmann-Account ohne eigene Mitgliedschaft in dieser EEG) und
`account.community_name` trägt den Zusatz " (Obmann)". Das gilt auch für Platform-Admins -- sie
bekommen wie im Web-Portal ein `manager`-Token, kein eigener dritter Rollenwert.

### Mehrfach-Rolle (statt der Erfolgsantwort)

```json
{
  "community_selection_required": true,
  "selection_ticket": "...",
  "memberships": [
    { "role": "member",  "community_id": "uuid-a", "community_name": "EEG Feldkirchen Südwest" },
    { "role": "manager", "community_id": "uuid-a", "community_name": "EEG Feldkirchen Südwest (Obmann)" },
    { "role": "member",  "community_id": "uuid-b", "community_name": "EEG Klagenfurt Ost" }
  ]
}
```
Das erste und zweite Beispiel oben zeigen denselben Account, der in EEG A sowohl Mitglied als
auch Obmann ist -- der Client muss dem Nutzer dann eine Auswahl anbieten ("Als Mitglied
anmelden" / "Als Obmann anmelden"), nicht nur eine EEG-Auswahl.

### Rolle/Community wechseln, OHNE sich neu anzumelden

Seit 19.08.2026: hat ein Account mehrere Rollen-Optionen (z. B. Mitglied UND Obmann, oder
Mitglied in mehreren EEGs), muss die App dafür NICHT jedes Mal ausloggen/neu einloggen lassen.

- `GET /api/v1/roles` -- alle Rollen-Optionen des eingeloggten Accounts, mit `active: true` bei
  der gerade über den Access-Token aktiven:
  ```json
  { "roles": [
    { "role": "member",  "community_id": "uuid-a", "community_name": "EEG A",         "name": "Anna Mustermann", "active": false },
    { "role": "manager", "community_id": "uuid-a", "community_name": "EEG A (Obmann)", "name": "EEG A",           "active": true  }
  ] }
  ```
- `POST /api/v1/switch-role` -- Body `{"community_id": "...", "role": "member"|"manager", "device_label": "..."}`
  (aus `GET /api/v1/roles` gewählt). Liefert wie beim Login ein KOMPLETT NEUES
  `access_token`/`refresh_token`-Paar für die gewählte Rolle zurück (gleiche Antwortstruktur
  wie die Login-Erfolgsantwort oben) -- dieses ersetzt das bisherige Token-Paar im Client. 400
  bei ungültiger Kombination.

Praktisch: das ist der App-Ersatz für den "Rolle wechseln"-Dropdown oben rechts im Web-Portal.
Ein Account, der nur EINE Rolle hat, sieht in `GET /api/v1/roles` entsprechend nur einen
Eintrag -- die App sollte den Umschalter dann gar nicht erst anzeigen (kein Rollenwechsel
nötig/möglich).

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

## Daten-Endpunkte (role: member, außer wo anders vermerkt)

Alle mit `Authorization: Bearer <access_token>`. Die meisten brauchen `role: "member"` (liefern
mit einem reinen Obmann-Token `403 {"error": "Kein Mitgliedskonto in dieser EEG."}`) --
Ausnahme ist `GET /api/v1/current-power` (funktioniert mit BEIDEN Rollen, siehe dort). Bei
fehlendem/ungültigem/abgelaufenem Token: `401 {"error": "..."}` (Token ist abgelaufen →
`/api/v1/token/refresh` aufrufen und Request wiederholen).

> **Datumsformat:** Alle Zeitstempel-/Datumsfelder in JSON-Antworten sind striktes ISO-8601 mit
> Uhrzeit und Offset, z. B. `"2026-08-18T17:03:00+00:00"` -- auch reine Kalenderdaten ohne
> eigentliche Uhrzeit (`member_since`, `geburtsdatum`, ...) kommen so (Mitternacht UTC). In
> Swift: `JSONDecoder().dateDecodingStrategy = .iso8601` funktioniert damit direkt, keine
> eigene Formatter-Logik nötig. Trotzdem defensiv als `Date?` (optional) modellieren, nicht
> `Date` -- viele Felder sind legitim `null` (z. B. `last_invoice` vor der ersten Rechnung,
> `sent_at` vor dem Versand).

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

### GET /api/v1/current-power (role: member ODER manager)

Leichtgewichtiger Endpunkt zum Pollen der aktuellen Leistung (z. B. alle 5s), OHNE bei jedem
Aufruf die komplette `/api/v1/dashboard`-Antwort inkl. der schwereren Monatsaggregation neu zu
berechnen -- damit sich "aktuelle Leistung" live aktualisieren lässt, ohne den ganzen Bildschirm
neu zu laden. Web-Pendant: `/portal/api/current-power` + `/portal/api/live-power` (dort clientseitig
per `fetch()` alle 5s abgefragt, gleiche Empfehlung für die App).

```json
{
  "current_power_w": 320.0,
  "community": { "bezug_w": 4200, "einspeisung_w": 1800, "active_meters": 12, "total_meters": 14 }
}
```
Mit `role: "manager"`-Token ist `current_power_w` immer `null` (ein reiner Obmann-Account hat
keine eigenen Zählpunkte) -- `community` ist bei beiden Rollen gleich befüllt. Empfehlung fürs
Polling-Intervall: 5 Sekunden (deckt sich mit dem Sende-Intervall der ESP32-Geräte), nicht
kürzer -- schneller ändert sich serverseitig ohnehin nichts.

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
    "quartal": "2026-Q2", "period_from": "2026-04-01T00:00:00+00:00", "period_to": "2026-06-30T00:00:00+00:00",
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

## Verträge, Dokumente, DSGVO, Support (role: member)

Alle wieder mit `Authorization: Bearer <access_token>`, `role: "member"` nötig (403 mit reinem
Obmann-Token). Implementiert seit 31.08.2026, spiegeln die `/portal/my/*`-Seiten des Web-Portals
1:1 (gleiche Validierung, gleiche Datenbank-Helferfunktionen).

### GET /api/v1/contracts/status

```json
{
  "contracts_enabled": true,
  "bezug":       { "status": "signed",  "signed_at": "2026-08-01T10:00:00+00:00" },
  "einspeisung": null
}
```
`bezug`/`einspeisung` sind `null`, wenn kein aktiver Zählpunkt dieses Typs existiert (dann gibt
es dafür auch keinen Vertrag). `status`: `none` (noch kein Vertrag erzeugt), `created` (versendet,
wartet auf Unterschrift), `signed` (gültig).

### GET /api/v1/contracts/:type/pdf

`:type` ist `bezug` oder `einspeisung`. Liefert den aktuellen Vertrag als PDF
(`Content-Type: application/pdf`) -- funktioniert unabhängig vom Status (auch vor der
Unterschrift, zum Durchlesen). 404 bei unbekanntem Typ/deaktivierten Verträgen/keinem
Mitgliedskonto, 400 wenn kein passender Zählpunkt registriert ist.

### POST /api/v1/contracts/:type/sign

Digitale Unterschrift, nur möglich solange der Vertrag im Status `created` ist.

```json
// Request
{ "zustimmung": true, "signature_image": "data:image/png;base64,iVBORw0KG..." }
```
`signature_image` MUSS mit `data:image/png;base64,` beginnen (Unterschriftsfeld als PNG
exportiert, z. B. `PencilKit`/`UIImage.pngData()` auf iOS). 400 bei fehlender Zustimmung,
ungültigem Bildformat, oder wenn der Vertrag nicht im Status `created` ist. Erfolg:
`{"status": "ok"}` -- setzt den Vertrag auf `signed`, benachrichtigt den Obmann.

### GET /api/v1/documents

Eigene hochgeladene bzw. vom Obmann für das Mitglied hochgeladene Dateien (Ausweis-Scan,
Beitrittserklärung, ...).

```json
{ "documents": [
  { "id": "uuid", "name": "Ausweis Vorderseite.jpg", "mime": "image/jpeg", "created_at": "2026-07-01T09:00:00+00:00" }
] }
```

### GET /api/v1/documents/:fileid/download

Lädt eine einzelne Datei herunter (`Content-Disposition: attachment`, passender `Content-Type`).
404, wenn die Datei nicht existiert oder einem anderen Mitglied gehört.

### GET /api/v1/dsgvo-export

DSGVO-Selbstauskunft (Art. 15/20 DSGVO) als JSON-Datei-Download (`Content-Disposition:
attachment`) -- alle gespeicherten personenbezogenen Daten strukturiert, ohne
sicherheitskritische Felder (Passwort-Hash, Unterschriftsbilder).

### Support-Tickets

- `GET /api/v1/support` -- eigene Tickets, Liste mit `id`/`subject`/`category`/`status`/
  `created_at`/`updated_at`.
- `POST /api/v1/support` -- neues Ticket. Body: `{"subject": "...", "message": "...",
  "category": "problem"}` (`category`: `"problem"` oder `"feature"`, Default `"problem"`).
  Antwort: `{"id": "uuid", "status": "ok"}`. Löst eine interne Benachrichtigungsmail aus.
- `GET /api/v1/support/:id` -- Ticket-Detail inkl. `messages[]`
  (`author_label`/`is_staff`/`message`/`created_at`, chronologisch aufsteigend).
- `POST /api/v1/support/:id/reply` -- Antwort im Ticket. Body: `{"message": "..."}`. Setzt den
  Status automatisch auf `offen` zurück (falls der Obmann bereits geantwortet/geschlossen hatte).

400 bei leerem `subject`/`message`, 404 bei unbekannter/fremder Ticket-`id`.

---

## Profil, Passwort, 2FA (role: member ODER manager)

Diese Endpunkte betreffen den **Login-Account** (`users`-Tabelle), nicht den Mitgliedsdatensatz
-- funktionieren deshalb mit JEDEM gültigen Token, egal ob `role: "member"` oder
`role: "manager"` (auch für einen reinen Obmann-Account ohne eigene Mitgliedschaft).

### GET /api/v1/profile

```json
{
  "user": { "id": "uuid", "email": "obmann@example.at", "first_name": "Max", "last_name": "Muster", "totp_enabled": false },
  "role": "manager",
  "has_photo": true
}
```

### POST /api/v1/profile

Body: `{"email": "...", "first_name": "...", "last_name": "..."}` (alle drei Pflichtfelder).
400 bei leerem Feld oder ungültiger E-Mail-Adresse. Erfolg: `{"status": "ok"}`.

### POST /api/v1/profile/photo

`multipart/form-data`, Feld `photo`. Hängt am Mitgliedsdatensatz, falls vorhanden (erscheint
dann auch in der Mitgliederliste des Obmanns), sonst direkt am Login-Account. 400 bei fehlendem/
ungültigem Bild.

### POST /api/v1/password

Body: `{"current_password": "...", "new_password": "...", "confirm_password": "..."}`
(`confirm_password` optional -- fehlt es, wird `new_password` selbst als Bestätigung genommen).
400 bei falschem aktuellem Passwort, zu kurzem neuem Passwort (< 8 Zeichen), nicht
übereinstimmender Bestätigung, oder wenn das neue Passwort in bekannten Datenlecks auftaucht
(`isPasswordBreached()`, HaveIBeenPwned-Bereich, k-Anonymity). Erfolg: `{"status": "ok"}`.

### 2FA einrichten

Die App hält KEINE Server-Session (bewusst stateless, siehe Auth-Flow oben) -- deshalb läuft die
2FA-Einrichtung anders als im Web-Portal über ein kurzlebiges, signiertes **Setup-Ticket** statt
über `$_SESSION`, muss aber sonst genauso in zwei Schritten ablaufen:

1. `GET /api/v1/2fa/setup` →
   ```json
   { "secret": "JBSWY3DPEHPK3PXP", "otpauth_uri": "otpauth://totp/...", "setup_ticket": "..." }
   ```
   `otpauth_uri` kann direkt als QR-Code gerendert werden (z. B. für einen Wechsel von
   Google Authenticator zur App, meist aber nicht nötig -- s. u.); `secret` reicht auch als
   reiner Text zum manuellen Eintragen in eine externe Authenticator-App.
2. `POST /api/v1/2fa/enable` mit `{"setup_ticket": "...", "code": "123456"}` (Code aus einer
   TOTP-App auf Basis von `secret`/`otpauth_uri`) → bei richtigem Code `{"status": "ok"}`,
   2FA ist ab sofort aktiv. `setup_ticket` ist 5 Minuten gültig und an den anfragenden Account
   gebunden (400, wenn abgelaufen oder von einem anderen Account). 400 bei falschem Code.
3. `POST /api/v1/2fa/disable` (kein Body) → deaktiviert 2FA sofort, kein Code nötig (Nutzer ist
   ja bereits authentifiziert). `{"status": "ok"}`.

---

## Obmann-Endpunkte: Mitgliederverwaltung (role: manager)

Alle mit `Authorization: Bearer <access_token>` und `role: "manager"` -- liefern mit einem
Mitglied-Token `403 {"error": "Diese Aktion erfordert einen Obmann-Zugang."}`. Implementiert
seit 31.08.2026, spiegeln die `/portal/members*`-Seiten des Web-Portals (gleiche Validierung,
gleiche `createMemberRecord()`-Logik inkl. KdNr-Vergabe und Erstlogin-Einladungsmail). Wie im
Web-Portal ist der Manager-Token an genau EINE EEG gebunden (bei mehreren EEGs wählt der
Login-Flow eine aus) -- alle folgenden Endpunkte wirken nur innerhalb dieser einen EEG.

### GET /api/v1/manager/members

Mitgliederliste der eigenen EEG.

```json
{ "members": [
  {
    "id": "uuid", "kundennummer": 10042, "name": "Anna Mustermann", "company_name": null,
    "email": "anna@example.at", "phone": "+43 664 1234567", "city": "Klagenfurt",
    "member_since": "2026-01-15T00:00:00+00:00", "member_until": "2099-12-31T00:00:00+00:00",
    "metering_point_count": 1, "open_amount_eur": 0.0
  }
] }
```

### GET /api/v1/manager/members/:id

Mitglied-Detail inkl. Zählpunkten und Dateien (Stammdatenfelder, `metering_points[]`,
`files[]` -- gleiche Struktur wie `GET /api/v1/metering-points` bzw.
`GET /api/v1/documents` oben). 404, wenn die `id` nicht zur eigenen EEG gehört.

### POST /api/v1/manager/members

Legt ein neues Mitglied an ("von unterwegs ein Mitglied hinzufügen"). Pflichtfelder im
JSON-Body: `first_name`, `last_name`, `email`, `address`, `zip`, `city`. Zusätzlich MÜSSEN alle
sechs rechtlichen Zustimmungen mitgeschickt werden (jeweils `true`):
`zustimmung_mitgliedschaft`, `zustimmung_vollmacht`, `zustimmung_widerrufsfrist`,
`zustimmung_email_kommunikation`, `zustimmung_datenschutz`, `zustimmung_agb` (z. B. eine
gemeinsame "Ich bestätige, dass die unterschriebene Beitrittserklärung vorliegt"-Checkbox in der
App, die alle sechs auf `true` setzt).

Optionale Felder: `salutation`, `titel`, `company_name`, `phone`, `invoice_uid`, `member_iban`
(wird validiert, 400 bei ungültiger Prüfsumme), `member_bic`, `kontoinhaber`, `konto_adresse`,
`member_since` (Default heute), `member_until` (Default `2099-12-31`), `geburtsdatum`,
`stromlieferant`, `speicher_status`, `speicher_kwh`, `andere_eeg` (bool), `andere_eeg_name`,
`email_anrede_mode` (`auto`/`herr`/`frau`/`familie`).

Optional gleich einen Zählpunkt anlegen: `add_bezug_zp: true` + `bezug_zaehlpunkt_nr` (und
optional `bezug_meter_code`, `bezug_jahresverbrauch_kwh`) bzw. `add_einspeisung_zp: true` +
`einspeisung_zaehlpunkt_nr` (und optional `einspeisung_meter_code`,
`einspeisung_engpassleistung_kw`, `einspeisung_geplante_einspeisung_kwh`). 400, wenn eine
Zählpunktnummer schon einem anderen Mitglied gehört oder beide Zählpunkte dieselbe Nummer hätten.

```json
// Erfolgsantwort
{
  "status": "ok", "member_id": "uuid", "kundennummer": 10042,
  "invite_sent": true, "temp_password": null
}
```
`invite_sent: true` heißt: der neue Login-Account hat eine Erstlogin-E-Mail bekommen (Link zur
eigenen Passwortvergabe, 24h gültig) -- kein Temp-Passwort nötig. `temp_password` ist nur gesetzt,
wenn der Mailversand fehlgeschlagen ist ODER der Account schon existierte (dann `null`) -- als
Fallback, den der Obmann notfalls selbst weitergeben kann.

### POST /api/v1/manager/members/:id

Bearbeitet die Stammdaten eines bestehenden Mitglieds. Gleiche Felder wie beim Anlegen, außer
`email` (Login-E-Mail wird hier nicht geändert) und den Zustimmungsfeldern/Zählpunkt-Feldern
(Zählpunkte laufen über eigene Endpunkte, aktuell nur im Web-Portal). Pflichtfelder:
`first_name`, `last_name`, `address`, `zip`, `city`. Erfolg: `{"status": "ok"}`.

### POST /api/v1/manager/members/:id/files

Datei-Upload für ein Mitglied (Ausweis-Scan, unterschriebene Beitrittserklärung, ...).
**`multipart/form-data`** (nicht JSON!) mit Feld `file` (die Datei) und optional `name`
(Anzeige-Bezeichnung, Default = Dateiname). Bewusst Standard-Multipart statt Base64-in-JSON --
funktioniert direkt mit `URLSession`-Multipart-Uploads unter iOS, ohne 33 % Base64-Overhead bei
z. B. einem mehrere MB großen Foto. Erfolg: `{"status": "ok", "id": "uuid"}`.

### GET /api/v1/manager/members/:id/files/:fileid/download

Lädt eine Mitglied-Datei herunter (gleiche Antwort wie der Mitglied-Endpunkt oben, nur mit
Obmann-Berechtigung für JEDES Mitglied der eigenen EEG statt nur die eigenen Dateien).

### POST /api/v1/manager/members/:id/photo

Setzt das Profilbild eines Mitglieds. `multipart/form-data`, Feld `photo`. Erfolg:
`{"status": "ok"}`.

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
- Kein Wechsel der aktiven Rolle/Community nach dem Login, ohne sich neu anzumelden (der
  Access-Token ist an genau eine Kombination aus Community UND Rolle gebunden).
- Zählpunkte können in der App bisher nur ANGESEHEN werden (`GET /api/v1/metering-points` bzw.
  als Teil von `GET /api/v1/manager/members/:id`) bzw. optional bei der Mitglied-Neuanlage
  gleich mit angelegt werden -- nachträgliches Hinzufügen/Bearbeiten/Löschen eines Zählpunkts
  bleibt vorerst Web-Portal-only (`/portal/members/:id/metering-points`).
- Bewusst NICHT in dieser App-API (bleibt Web-Portal-only, siehe Übersicht oben):
  Abrechnung/Rechnungslauf-Erstellung, EDA-Import, Vertrags-**Versand** als Obmann (Signieren
  als Mitglied ist enthalten, siehe `POST /api/v1/contracts/:type/sign`), EEG-Einstellungen,
  Beitrittsanträge freigeben/ablehnen, Postfach, Platform-Admin-Funktionen (EEG anlegen,
  Mailvorlagen, LaTeX-Vorlagen, ...). Grund: höheres Risiko bei knapper Testzeit (siehe
  RLS-Vorfälle im CLAUDE.md-Änderungsverlauf) und geringerer Nutzen unterwegs vom Handy aus
  gegenüber den oben implementierten Funktionen.
