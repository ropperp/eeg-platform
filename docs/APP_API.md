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
- **Noch nicht in dieser API** (nur im Web-Portal, Ziel ist aber volle Parität -- siehe
  `app/ios-app/APP_PARITY_BACKLOG.md` für den aktuellen Stand): Abrechnung/
  Rechnungslauf-Erstellung, EDA-Import, Vertrags-Versand als Obmann (nur Signieren als Mitglied
  ist enthalten), EEG-Einstellungen (Tarif/Steuer/Logo/Signatur -- Platform-Admin-Verwaltung
  einer EEG unter `role: "admin"` ist dagegen bereits enthalten), Beitrittsanträge freigeben,
  Postfach. Die Platform-Admin-Funktionen (`role: "admin"`) sind seit 19.08.2026 größtenteils
  enthalten, siehe Abschnitt "Admin-Endpunkte" unten.

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
- `POST /api/v1/switch-role` -- Body `{"community_id": "...", "role": "member"|"manager", "member_id": "...", "device_label": "..."}`
  (aus `GET /api/v1/roles` gewählt). `member_id` ist bei normalen Accounts überflüssig (leer
  lassen) -- nur relevant, wenn `GET /api/v1/roles` für dieselbe `community_id` zwei
  `role="member"`-Einträge mit unterschiedlichem `member_id` liefert (Demo-Logins mit mehreren
  Mitglied-Identitäten, siehe `migrate_20260905.sql`); dann per `member_id` disambiguieren, sonst
  wird immer der erste Treffer gewählt. Liefert wie beim Login ein KOMPLETT NEUES
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
> Uhrzeit und Offset, IMMER normalisiert auf UTC (`+00:00`, nie ein anderer Offset) -- z. B.
> `"2026-08-18T17:03:00+00:00"`. Auch reine Kalenderdaten ohne eigentliche Uhrzeit
> (`member_since`, `geburtsdatum`, ...) kommen so (`T00:00:00+00:00`, als UTC-Mitternacht
> konstruiert, nicht über die Server-Zeitzone hergeleitet -- sonst hätte sich das Kalenderdatum
> beim Umrechnen verschieben können). In Swift: `JSONDecoder().dateDecodingStrategy = .iso8601`
> funktioniert damit direkt, keine eigene Formatter-Logik nötig. Trotzdem defensiv als `Date?`
> (optional) modellieren, nicht `Date` -- viele Felder sind legitim `null` (z. B. `last_invoice`
> vor der ersten Rechnung, `sent_at` vor dem Versand).

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

### GET /api/v1/consumption/interval?date=YYYY-MM-DD

Viertelstündlicher Verbrauch vs. gemeinschaftliche Eigendeckung für EINEN Tag (96 Intervalle) --
Grundlage für ein Tages-Diagramm in der App, analog `/portal/my/verbrauch` im Web-Portal.
Implementiert seit 04.09.2026 (`database/migrate_20260904.sql`,
`eda-parser/parser_interval.py`) -- Datenquelle ist ein zweiter, eigener EDA-Export-Typ
("Energiedaten"-Sheet, echte Viertelstundenwerte) neben dem bereits vorhandenen monatlichen
Energiedatenreport, hat mit der Abrechnung nichts zu tun. `date` optional, Default heute (an
den meisten Tagen ohne Daten, siehe `has_data` -- der Obmann lädt diese Werte nicht täglich
hoch, sondern alle paar Tage nachträglich).

```json
{ "date": "2026-07-26", "has_data": true,
  "total_messung_kwh": 2.41, "total_gemeinschaft_kwh": 0.87,
  "intervals": [
    { "zeit": "00:00", "verbrauch_w": 100, "gemeinschaft_w": 23 },
    { "zeit": "00:15", "verbrauch_w": 132, "gemeinschaft_w": 18 }
  ] }
```
`intervals` hat immer genau 96 Einträge (00:00 bis 23:45, 15-Minuten-Schritte). `verbrauch_w`/
`gemeinschaft_w` sind `null`, wenn für diesen Zeitpunkt kein Wert vorliegt (nicht 0 -- ein
echter Nullverbrauch wird von einem fehlenden Wert unterschieden). `verbrauch_w` ist die
Durchschnittsleistung des Viertelstunden-Intervalls in Watt (kWh × 4000), direkt vergleichbar
mit `current_power_w` aus `/api/v1/current-power`. `gemeinschaft_w` ist der Anteil davon, der
aus der Energiegemeinschaft gedeckt wurde -- die Differenz kam aus dem öffentlichen Netz.

### GET /api/v1/production/interval?date=YYYY-MM-DD

Spiegelbild von `/api/v1/consumption/interval`, für die eigene Einspeisung -- Grundlage für ein
Tages-Diagramm für Einspeiser/Prosumer, analog `/portal/my/einspeisung` im Web-Portal.
Implementiert seit 06.09.2026 (Patrick: "warum haben die Einspeiser nicht die Möglichkeit, ihre
eingespeiste Leistung in einem Diagramm einzusehen?"). Nur relevant für Mitglieder mit
mindestens einem aktiven Einspeise-/Prosumer-Zählpunkt.

```json
{ "date": "2026-07-26", "has_data": true, "has_erzeugung_gesamt": true,
  "total_messung_kwh": 812.4, "total_gemeinschaft_kwh": 4.92, "total_erzeugung_gesamt_kwh": 6.10,
  "intervals": [
    { "zeit": "00:00", "einspeisung_w": null, "erzeugung_gesamt_w": null },
    { "zeit": "12:00", "einspeisung_w": 340, "erzeugung_gesamt_w": 480 }
  ] }
```
`total_messung_kwh` ist die GESAMTE gemeinschaftliche Erzeugung der EEG an diesem Tag (nicht
mitgliedsspezifisch -- nur als grober Kontext mitgeliefert, in der Regel nicht anzeigen).
`total_gemeinschaft_kwh`/`einspeisung_w` sind die eigene, individuell zugerechnete Einspeisung
(nach Teilnahmefaktor) -- der energiegemeinschaftlich GENUTZTE Anteil der eigenen Erzeugung.
Seit 07.09.2026 (`database/migrate_20260907.sql`, Patrick: "wie viel sie einspeisen und wie viel
davon in der Energiegemeinschaft verwendet wurde") zusätzlich `total_erzeugung_gesamt_kwh`/
`erzeugung_gesamt_w` -- die eigene GESAMTE Erzeugung des Zählpunkts (Grundlage für ein
gestapeltes Diagramm analog zu `/consumption/interval`: Gesamterzeugung als Gesamtfläche,
`einspeisung_w` als der darin enthaltene, gemeinschaftlich genutzte Teil). `has_erzeugung_gesamt`
ist `false` für Tage, die vor dieser Migration importiert wurden (die Spalte wurde damals noch
nicht gelesen) -- in diesem Fall ist `erzeugung_gesamt_w`/`total_erzeugung_gesamt_kwh` für den
ganzen Tag `null`/`0`, die App sollte dann auf eine reine Einzel-Linien-Ansicht (nur
`einspeisung_w`) zurückfallen statt ein optisch falsches gestapeltes Diagramm zu zeigen (die
gemeinschaftlich genutzte Fläche würde sonst über eine Gesamtfläche von 0 W hinausragen).
`intervals` hat wie bei `/consumption/interval` immer 96 Einträge, beide `_w`-Felder sind `null`
ohne Wert für diesen Zeitpunkt.

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
`name` trägt seit 19.08.2026 IMMER eine Dateiendung (`filenameWithExtension()`,
`webapp/src/functions.php`) -- die frei getippte Anzeige-Bezeichnung wird serverseitig um die
tatsächliche Endung des gespeicherten Pfads ergänzt, falls sie noch fehlt. Vorher konnte `name`
endungslos sein (z.B. `"Beitrittserklärung"`), wodurch iOS eine über `/download` heruntergeladene
Datei nicht öffnen konnte, obwohl `Content-Type`/`Content-Disposition` korrekt gesetzt waren.

### GET /api/v1/documents/:fileid/download

Lädt eine einzelne Datei herunter (`Content-Disposition: attachment` MIT Dateiendung, passender
`Content-Type`). 404, wenn die Datei nicht existiert oder einem anderen Mitglied gehört.

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

## Push-Benachrichtigungen (alle Rollen)

Implementiert seit 03.09.2026 (`migrate_20260903.sql`, `webapp/src/Push.php`). Drei Auslöser,
serverseitig über Datenbank-Trigger erkannt (siehe Migration): neues Postfach-Element → Push an
alle Obmänner/Admins der EEG; neue Rechnung verfügbar (Abrechnungslauf freigegeben) → Push ans
betroffene Mitglied; eigene Einspeisung übersteigt die selbst gesetzte Schwelle → Push ans
Mitglied, MIT Hysterese (keine erneute Push, solange der Wert oben bleibt -- erst nach
zwischenzeitlichem Abfallen unter die Schwelle löst der nächste Anstieg wieder aus). Zustellung
läuft asynchron über einen Host-Cron (jede Minute, siehe `CLAUDE.md`) -- zwischen dem
auslösenden Ereignis und der tatsächlichen Push-Zustellung liegt normalerweise weniger als eine
Minute, keine Garantie auf Echtzeit.

### POST /api/v1/push/register

Registriert/aktualisiert das aktuelle Gerät für Push-Benachrichtigungen -- direkt nach dem
Login aufrufen (bzw. sobald der Nutzer Push in den iOS-Systemeinstellungen erlaubt hat). Body:
`{"device_token": "...", "device_label": "iPhone von Max"}` (`device_token` = der von APNs
gelieferte Token aus `didRegisterForRemoteNotificationsWithDeviceToken`, `device_label`
optional). Ein Gerät gehört immer nur einem Account gleichzeitig -- meldet sich ein anderer
Account auf demselben Gerät an, übernimmt dieser Account den Token automatisch. Erfolg:
`{"status": "ok"}`.

### POST /api/v1/push/unregister

Meldet das Gerät wieder ab (z.B. beim Logout). Body: `{"device_token": "..."}`. Erfolg:
`{"status": "ok"}`.

### GET /api/v1/notifications/settings

Nur mit Mitglied-Token (404 bei Obmann-/Admin-Token). Eigene Benachrichtigungs-Einstellungen:
```json
{ "notify_new_invoice": true, "einspeisung_threshold_w": 2000 }
```
`einspeisung_threshold_w: null` = Einspeisung-Push deaktiviert.

### POST /api/v1/notifications/settings

Nur mit Mitglied-Token. Body: `{"notify_new_invoice": true, "einspeisung_threshold_w": 2000}`
(`einspeisung_threshold_w`: `null` oder `0` deaktiviert die Einspeisung-Push). Erfolg:
`{"status": "ok"}`.

---

## Admin-Endpunkte (role: admin)

Alle mit `Authorization: Bearer <access_token>` und `role: "admin"` (403 mit jedem anderen
Token). Implementiert seit 19.08.2026 (`migrate_20260902.sql`). Anders als `role: "manager"` ist
ein Admin-Token an KEINE einzelne EEG gebunden -- alle Endpunkte wirken plattformweit über alle
EEGs hinweg. Ein Account bekommt die `admin`-Rollen-Option in `GET /api/v1/roles`/beim Login nur,
wenn er in `user_roles` eine `platform_admin`-Zeile hat (unabhängig von deren `community_id`).

Secrets (`client_secret`, `mqtt_password`, EDA-Portal-Passwort) werden NIE im Klartext
zurückgegeben, nur als `..._set: true/false` -- exakt wie das Web-Formular sie nie vorbefüllt.
Update-Endpunkte folgen demselben "Feld nicht mitschicken = unverändert lassen, Feld leer
mitschicken = wirklich löschen"-Prinzip wie im Web (`array_key_exists()`-Prüfung serverseitig).

### GET /api/v1/admin/overview

```json
{ "communities": [
  { "id": "uuid", "name": "EEG Feldkirchen Südwest", "active": true, "marktpartner_id": "RC108175" }
], "user_count": 42 }
```

### GET /api/v1/admin/users

Alle Login-Accounts + ihre Rollen (plattformweit).
```json
{ "users": [
  { "id": "uuid", "email": "obmann@example.at", "name": "Max Muster", "active": true,
    "roles": [ { "role": "manager", "community_id": "uuid", "community_name": "EEG A" } ] }
] }
```

### GET /api/v1/admin/users/:id

Detail eines Nutzers inkl. `role_id` je Rolle (für `.../roles/delete`).

### POST /api/v1/admin/users/:id/roles

Body: `{"role": "platform_admin"|"manager"|"member", "community_id": "uuid oder weglassen"}`.
`community_id` nur für `manager`/`member` nötig, bei `platform_admin` üblicherweise weglassen
(globale Rolle). 400 bei ungültiger Rolle.

### POST /api/v1/admin/users/:id/roles/delete

Body: `{"role_id": "uuid"}` (aus `GET /api/v1/admin/users/:id`). 400, wenn das die letzte
verbleibende `platform_admin`-Rolle plattformweit wäre (sonst könnte sich niemand mehr als Admin
anmelden, weder Web noch App).

### POST /api/v1/admin/users/:id/delete

Löscht den Login-Account. 400 beim eigenen Account oder beim letzten verbleibenden
Platform-Admin. Verknüpfte Mitglieder bleiben erhalten, verlieren nur die Login-Verknüpfung.

### GET /api/v1/admin/communities/:id

```json
{
  "community": {
    "id": "uuid", "name": "EEG Feldkirchen Südwest", "slug": "eeg-feldkirchen-sudwest",
    "marktpartner_id": "RC108175", "zvr_number": "...", "address": "...", "iban": "...",
    "bic": "...", "active": true, "eda_login_email": "...", "eda_login_password_set": true
  },
  "members": [ { "id": "uuid", "kundennummer": 10001, "name": "Anna Mustermann", "company_name": null, "email": "...", "status": "active" } ]
}
```

### POST /api/v1/admin/communities

Legt eine neue EEG an. Body: `{"name": "...", "marktpartner_id": "...", "address": "..."}`
(`name` Pflicht). Erfolg: `{"status": "ok", "id": "uuid"}`.

### POST /api/v1/admin/communities/:id

Bearbeitet Stammdaten. Body: `name`, `marktpartner_id`, `zvr_number`, `address`, `iban`, `bic`,
`active` (bool), `eda_login_email`, `eda_login_password` (nur bei tatsächlicher Änderung
mitschicken).

### POST /api/v1/admin/communities/:id/delete

**UNWIDERRUFLICH** -- löscht die EEG inkl. ALLER Mitglieder, Verträge, Zählpunkte, Rechnungen
(Kaskade). Die App MUSS vorher eine eigene, deutliche Bestätigung einholen (z. B. Namen der EEG
erneut eintippen lassen).

### GET /api/v1/admin/log?community_id=uuid

Aktivitätslog, `community_id` optional zum Filtern. Neueste 500 Einträge, absteigend.
```json
{ "entries": [
  { "id": "uuid", "created_at": "...", "user_name": "Max Muster", "community_name": "EEG A",
    "aktion": "member.create", "entity_typ": "member", "entity_id": "uuid",
    "beschreibung": "Mitglied ... angelegt", "ist_fehler": false }
] }
```

### GET /api/v1/admin/settings

Gesammelte Plattform-Einstellungen in einer Antwort (Mail/Graph, Mail-Vorlagen, MQTT,
Plattform-Technik, APNs/Push) -- Aufteilung siehe `mail`/`mail_templates`/`platform`/`mqtt`/`apns`
im JSON. `apns`: `{"team_id", "key_id", "bundle_id", "private_key_set": true|false, "sandbox",
"configured": true|false}` -- `configured` fasst zusammen, ob ALLE Pflichtfelder gesetzt sind
(genau das, was `Push::sendPending()` selbst prüft, bevor es überhaupt einen Zustellversuch
macht).

### POST /api/v1/admin/settings/mail

Body (alle optional, nur mitschicken was geändert werden soll): `tenant_id`, `client_id`,
`client_secret`, `sender_address`, `reply_to`, `signature_html`, `backup_alert_email_1`,
`backup_alert_email_2`, `support_notification_email`, `eda_import_mailbox_address`.

### POST /api/v1/admin/settings/mail/test

Body: `{"to": "test@example.at"}`. Sendet eine Test-Mail mit der aktuellen Konfiguration.

### POST /api/v1/admin/settings/mail-templates

Body: `{"key": "invite", "subject": "...", "body_html": "..."}`. `key` einer von
`password_reset`, `invite`, `member_deactivated`, `contract_bezug`, `contract_einspeisung`,
`contract_both`, `sepa_prenotification`, `mahnung`.

### POST /api/v1/admin/settings/mqtt

Body: `{"mqtt_user": "...", "mqtt_password": "..."}`. Setzt `pending_apply=true` -- die
tatsächliche Anwendung auf den Broker läuft asynchron über den Host-Cron
(`scripts/mqtt_apply_pending.sh`), typischerweise binnen einer Minute.

### POST /api/v1/admin/settings/mqtt-device-reconfig

Body: `{"device_mqtt_host": "...", "device_mqtt_port": 8883, "device_mqtt_user": "...", "device_mqtt_pass": "..."}`.
Broadcastet neue MQTT-Zugangsdaten an ALLE bereits im Feld laufenden Geräte.

### POST /api/v1/admin/settings/test-mode

Body: `{"test_mode": true|false}`.

### POST /api/v1/admin/settings/esp

Body: `{"esp_offline_after_minutes": 5}`.

### POST /api/v1/admin/settings/apns

Setzt die APNs-Zugangsdaten für Push-Benachrichtigungen (siehe `webapp/src/Push.php`, echte
Werte aus Patricks Apple-Developer-Account, siehe `CLAUDE.md`). Body (alle optional, nur
mitschicken was geändert werden soll): `team_id`, `key_id`, `bundle_id`, `private_key` (voller
`.p8`-Dateiinhalt inkl. `BEGIN/END PRIVATE KEY`-Zeilen, wird verschlüsselt gespeichert und nie
wieder im Klartext zurückgegeben -- wie `client_secret` bei `/api/v1/admin/settings/mail`),
`sandbox` (bool, Entwicklungs- statt Produktiv-APNs-Server). Erfolg: `{"status": "ok"}`.

### POST /api/v1/admin/settings/apns/test

Schickt eine Test-Push an alle eigenen registrierten Geräte des aufrufenden Admin-Accounts
(vorher per `POST /api/v1/push/register` registrieren). Erfolg: `{"status": "ok"}`, sonst 400
(APNs nicht konfiguriert) oder 500 mit Fehlerdetails.

### GET /api/v1/admin/backups

Rein lesende Backup-Übersicht (Alter/Größe der letzten Sicherungen je Art).

### Noch nicht in der App-API (siehe `app/ios-app/APP_PARITY_BACKLOG.md`)

LaTeX-Vorlagen-Verwaltung (Upload/Download/Variablen-Referenz), Audit-Log-Export als Markdown,
manueller EDA-Postfach-Import-Testlauf, Mail-Signatur-Logo-Upload -- bewusst zurückgestellt
(Datei-lastige/Desktop-orientierte Funktionen, geringerer Nutzen unterwegs vom Handy aus).

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
- Kein Push-Notification-Mechanismus (z. B. "neue Rechnung verfügbar") -- angefragt (Patrick,
  19.08.2026), noch nicht umgesetzt, siehe `app/ios-app/APP_PARITY_BACKLOG.md`.
- Zählpunkte können in der App bisher nur ANGESEHEN werden (`GET /api/v1/metering-points` bzw.
  als Teil von `GET /api/v1/manager/members/:id`) bzw. optional bei der Mitglied-Neuanlage
  gleich mit angelegt werden -- nachträgliches Hinzufügen/Bearbeiten/Löschen eines Zählpunkts
  bleibt vorerst Web-Portal-only (`/portal/members/:id/metering-points`).
- Noch NICHT in dieser App-API (Ziel ist volle Parität, siehe
  `app/ios-app/APP_PARITY_BACKLOG.md` für den laufend aktuellen Stand): Abrechnung/
  Rechnungslauf-Erstellung, EDA-Import, Vertrags-**Versand** als Obmann (Signieren als Mitglied
  ist enthalten, siehe `POST /api/v1/contracts/:type/sign`), EEG-Einstellungen
  (Tarif/Steuer/Logo/Signatur), Beitrittsanträge freigeben/ablehnen, Postfach, sowie innerhalb
  der Admin-Endpunkte: LaTeX-Vorlagen-Verwaltung, Audit-Log-Export, manueller
  EDA-Import-Testlauf, Mail-Signatur-Logo-Upload.
