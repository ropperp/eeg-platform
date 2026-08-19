# Strom für alle — Anweisungen für den Xcode-Agenten (iOS-App)

Dieses Dokument ist die vollständige, reine Textform der Spezifikation für die native
iOS-Begleit-App zur EEG-Plattform "Strom für alle". Es ersetzt einen zuvor als HTML-Artifact
übergebenen "App-Baukasten", den ein Agent in Xcode nicht öffnen/lesen kann -- alle Inhalte
daraus (Farben, Typografie, Funktionsumfang, API-Referenz, Bildschirmplan) sind hier komplett in
Text/Markdown/Code-Blöcken enthalten, nichts fehlt.

**Ausgangslage:** Das komplette Backend (`/api/v1/*`) existiert bereits fertig implementiert,
getestet und auf dem Produktivserver deployt. Es muss NUR NOCH der iOS-Client dagegen gebaut
werden -- keine Backend-Arbeit nötig, außer die App entdeckt beim Bauen einen echten Bug (dann
bitte im Repo `webapp/public/index.php` bzw. `webapp/src/AppApiAuth.php` melden/fixen, nicht
workaround-en).

**Technische Referenz (ausführlicher, mit allen Request/Response-Beispielen):**
`docs/APP_API.md` im selben Repo, eine Ebene über `app/ios-app/`. Dieses Dokument hier fasst die
wichtigsten Punkte zusammen und ergänzt Design/Bildschirmplan -- bei Detailfragen zu einem
Endpunkt (exaktes JSON-Feld, Fehlercode) dort nachsehen.

---

## 1. Projekt-Setup

- **Zielordner:** `app/ios-app/` in diesem Repo. Xcode-Projekt dort anlegen (`.xcodeproj` bzw.
  `.xcodeproj`-Paket landet direkt in diesem Ordner), nicht an anderer Stelle im Repo.
- **Sprache/Framework:** Swift + SwiftUI (nativ, keine Cross-Platform-Frameworks wie React
  Native/Flutter).
- **Minimale Deployment-Version:** iOS 16 oder neuer ist ausreichend (keine Notwendigkeit für
  ältere Versionen).
- **Empfohlene Ordnerstruktur** (siehe auch `app/ios-app/README.md`):
  ```
  app/ios-app/
    StromFuerAlle.xcodeproj/
    StromFuerAlle/
      App/            -- App-Einstiegspunkt (@main), Root-Navigation
      Networking/      -- API-Client, Token-Handling (Keychain), Codable-Requests
      Models/          -- Codable-Structs passend zu den JSON-Antworten unten
      Views/           -- SwiftUI-Screens, nach Bereich gruppiert (Auth/, Dashboard/, Invoices/, Contracts/, Documents/, Support/, Profile/, Manager/)
      Resources/        -- Assets.xcassets (Farben, Icons, Logo)
  ```
- **Netzwerk-Basis-URL:** `https://stromfueralle.at` (Produktivsystem). Als konfigurierbare
  Konstante anlegen (`APIConfig.baseURL`), nicht hart verstreut im Code.

---

## 2. Design: Farben

Identisch mit dem bestehenden Web-Portal (`webapp/public/assets/css/app.css`) -- die App soll
optisch wie derselbe Dienst wirken, nicht wie ein separates Produkt. Als `Color Set` im
Asset-Katalog anlegen (je ein "Any Appearance"- und "Dark Appearance"-Wert), damit
`Color("AccentGreen")` automatisch zwischen Hell/Dunkel wechselt.

### Hell (Standard)

| Name | Hex | Verwendung |
|---|---|---|
| Grün (Primär) | `#16A34A` | Haupt-Akzentfarbe, primäre Buttons, aktive Zustände |
| Grün, dunkel | `#15803D` | Hover/Pressed-Zustand von Primär-Elementen |
| Grün, hell | `#DCFCE7` | Hintergrund für Badges/Erfolgsmeldungen |
| Blau (Akzent) | `#2563EB` | Links, sekundäre Aktionen |
| Text | `#1F2937` | Haupttext |
| Text, sekundär | `#4B5563` | Sekundärtext, Beschreibungen |
| Text, schwach | `#6B7280` | Platzhalter, sehr untergeordneter Text |
| Rahmen | `#E5E7EB` | Trennlinien, Card-Ränder |
| Hintergrund | `#F9FAFB` | Seitenhintergrund |
| Fläche | `#FFFFFF` | Card-/Panel-Hintergrund |
| Fläche 2 | `#F3F4F6` | Sekundäre Fläche (z. B. Icon-Hintergrund) |
| Fehler / Rot | `#DC2626` | Fehlermeldungen, destruktive Aktionen |
| Fehler-Hintergrund | `#FEF2F2` | Hintergrund für Fehlerboxen |
| Warnung | `#92400E` | Warntext |
| Warnung-Hintergrund | `#FFFBEB` | Hintergrund für Warnboxen |

### Dunkel (System-Dark-Mode)

| Name | Hex | Verwendung |
|---|---|---|
| Grün (Primär) | `#22C55E` | wie oben, angepasst für Dark Mode |
| Grün, hell | `#14532D` | Badge-Hintergrund im Dark Mode |
| Blau (Akzent) | `#60A5FA` | Links im Dark Mode |
| Hintergrund | `#0F172A` | Seitenhintergrund Dark Mode |
| Fläche | `#1E293B` | Card-Hintergrund Dark Mode |
| Fläche 2 | `#263244` | Sekundäre Fläche Dark Mode |
| Text | `#F1F5F9` | Haupttext Dark Mode |
| Text, sekundär | `#CBD5E1` | Sekundärtext Dark Mode |
| Rahmen | `#334155` | Trennlinien Dark Mode |
| Fehler / Rot | `#F87171` | Fehlermeldungen Dark Mode |
| Warnung | `#FCD34D` | Warntext Dark Mode |

Für den Grundton eignet sich `UIColor.systemGreen` als Ausgangspunkt, angepasst auf `#16A34A`
statt Apples Standardgrün. SwiftUI unterstützt automatisches Hell/Dunkel-Umschalten über
Asset-Catalog-Farbsets -- kein manueller `@Environment(\.colorScheme)`-Check nötig, außer für
Spezialfälle.

---

## 3. Design: Typografie & Logo

Die Web-Plattform nutzt bewusst die native Systemschrift (`system-ui`, auf iOS automatisch San
Francisco). Für die App: einfach `Font.system(...)` mit den Standard-Text-Styles verwenden
(`.largeTitle`, `.title`, `.body`, `.caption` usw.), **keine eigene Schriftart einbinden**. Das
ergibt automatisch ein natives, vertrautes Erscheinungsbild und bleibt konsistent mit dem
Web-Portal.

- **Titel:** `Font.system(.title, design: .default).bold()` -- z. B. "Meine Rechnungen"
- **Fließtext:** `Font.system(.body)` -- z. B. "Ihr aktueller Verbrauch liegt bei 245,3 kWh in diesem Monat."
- **Zahlen/Beträge:** monospaced Ziffern für Tabellen-artige Ausrichtung --
  `Font.system(.body).monospacedDigit()`, z. B. "EUR 42,10 · 320 W"

**Logo:** Im Repo unter `webapp/public/assets/images/logo.png` (PNG mit transparentem
Hintergrund) -- direkt als App-Icon-Grundlage bzw. Splash-Screen-Logo verwendbar. Für das
eigentliche App-Icon (quadratisch, von iOS gefordert) empfiehlt sich eine freigestellte,
quadratische Version, da die vorhandene Datei ein breites Rechteck/Wordmark ist.

---

## 4. Auth-Flow (zuerst implementieren, alles andere baut darauf auf)

Zwei Rollen im selben Token-System: **Mitglied** (`role: "member"`) und **Obmann/Manager**
(`role: "manager"`, umfasst auch Platform-Admins). Ein Account kann beides gleichzeitig sein.

```
POST /api/v1/login  (email + password)
        │
        ├─ 2FA aktiv? ──► { totp_required: true, login_ticket }
        │                       │
        │                       ▼
        │              POST /api/v1/login/2fa  (login_ticket + code)
        │                       │
        ▼                       ▼
   ┌─────────────────────────────────────────────────────────┐
   │ Genau eine Rollen-Option (Mitglied ODER Obmann, 1 EEG)?   │
   │  ja   → { access_token, refresh_token, role, account }    │
   │  nein (mehrere) → { community_selection_required: true,   │
   │                      selection_ticket, memberships }       │
   │                       │                                    │
   │                       ▼                                    │
   │              POST /api/v1/login/select-community            │
   │              (selection_ticket + community_id + role)        │
   │                       │                                       │
   │                       ▼                                        │
   │              { access_token, refresh_token, role, account }     │
   │  weder Mitgliedschaft noch Obmann-Zugang → 403 Fehler             │
   └───────────────────────────────────────────────────────────────┘
```

Danach für jeden weiteren Request: Header `Authorization: Bearer <access_token>`.

- **Access-Token:** 15 Minuten gültig, selbst-signiert.
- **Refresh-Token:** 30 Tage gültig, **rotiert bei jeder Erneuerung** -- das alte wird beim
  Tausch sofort ungültig, das neue MUSS gespeichert werden. In der iOS-**Keychain** ablegen,
  niemals in UserDefaults im Klartext.
- Läuft der Access-Token ab: still im Hintergrund `POST /api/v1/token/refresh` aufrufen, kein
  erneutes Login nötig. Schlägt das fehl: Nutzer muss sich neu anmelden.

### POST /api/v1/login

Request: `{"email": "...", "password": "...", "device_label": "iPhone von Patrick"}`
(`device_label` optional, Bezeichnung dieses Geräts).

| Fall | HTTP | Body |
|---|---|---|
| Falsches Passwort/unbekannte E-Mail | 401 | `{"error": "E-Mail oder Passwort falsch."}` |
| Zu viele Fehlversuche | 429 | `{"error": "Zu viele Fehlversuche. Bitte in 15 Minuten erneut versuchen."}` |
| 2FA aktiv | 200 | `{"totp_required": true, "login_ticket": "..."}` |
| Weder Mitgliedschaft noch Obmann-Zugang | 403 | `{"error": "..."}` |
| Genau eine Rollen-Option | 200 | siehe "Erfolgsantwort" unten |
| Mehrere Rollen-Optionen | 200 | siehe "Mehrfach-Rolle" unten |

### POST /api/v1/login/2fa

Request: `{"login_ticket": "...", "code": "123456"}`. `login_ticket` aus der `/login`-Antwort,
5 Minuten gültig. Fehler: 401 (Ticket ungültig/abgelaufen), 429 (zu viele Fehlversuche), 401
(Code falsch). Erfolg: wie `/login`.

### POST /api/v1/login/select-community

Nur nötig bei `community_selection_required: true`. Request:
`{"selection_ticket": "...", "community_id": "...", "role": "member"|"manager", "device_label": "..."}`.
`role` ist nötig, wenn derselbe Account in derselben EEG sowohl Mitglied als auch Obmann ist.

### Erfolgsantwort

```json
{
  "access_token": "eyJ...",
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
Bei `role: "manager"` ist `account.member_id` meist `null` (reiner Obmann-Account ohne eigene
Mitgliedschaft) und `community_name` trägt den Zusatz " (Obmann)".

### Mehrfach-Rolle (statt Erfolgsantwort)

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
Client muss dann eine Auswahl anbieten (nicht nur EEG, ggf. auch Rolle).

### POST /api/v1/token/refresh

Request: `{"refresh_token": "..."}` → `{"access_token": "...", "refresh_token": "...", "expires_in": 900}`.
Das zurückgegebene `refresh_token` ist ein NEUES -- immer überschreiben.

### POST /api/v1/logout

Request: `{"refresh_token": "..."}` → immer `{"status": "ok"}`. Meldet nur dieses Gerät ab.

### Fehlerformat (gilt für ALLE Endpunkte)

`{"error": "<lesbarer deutscher Text>"}`, passender HTTP-Status: 401 = Auth fehlt/ungültig
(→ Refresh versuchen), 403 = kein Zugriff/falsche Rolle, 404 = nicht gefunden, 429 = Rate-Limit
(nicht automatisch sofort erneut versuchen), 400 = ungültige Eingabe.

---

## 5. Alle Endpunkte im Überblick

Alle folgenden Endpunkte brauchen `Authorization: Bearer <access_token>`. Endpunkte mit JSON-Body
brauchen `Content-Type: application/json`; Datei-Uploads brauchen `multipart/form-data`
(explizit markiert unten). Vollständige Feldlisten/Beispiele: `docs/APP_API.md`.

### Mitglied-Endpunkte (role: member; mit einem reinen Obmann-Token 403)

| Methode | Pfad | Zweck |
|---|---|---|
| GET | `/api/v1/dashboard` | Startbildschirm: aktuelle Leistung, Community-Live-Werte, Verbrauchsmonat, letzte Rechnung |
| GET | `/api/v1/current-power` | **Leichtgewichtiger Live-Poll** (auch role: manager) -- alle 5s pollen für automatisch aktualisierte Leistung ohne Reload, siehe Abschnitt 9 |
| GET | `/api/v1/consumption?months=6` | Monatlicher Verbrauchs-/Erzeugungsverlauf (1–24 Monate) |
| GET | `/api/v1/invoices` | Rechnungsliste (Metadaten) |
| GET | `/api/v1/invoices/:id/pdf` | Einzelne Rechnung als PDF |
| GET | `/api/v1/metering-points` | Eigene Zählpunkte |
| GET | `/api/v1/contracts/status` | Vertragsstatus Bezug/Einspeisung |
| GET | `/api/v1/contracts/:type/pdf` | Vertrag als PDF (`:type` = `bezug`/`einspeisung`) |
| POST | `/api/v1/contracts/:type/sign` | Vertrag digital unterschreiben (`zustimmung` + `signature_image` als `data:image/png;base64,...`) |
| GET | `/api/v1/documents` | Eigene/vom Obmann hochgeladene Dateien |
| GET | `/api/v1/documents/:fileid/download` | Einzelne Datei herunterladen |
| GET | `/api/v1/dsgvo-export` | DSGVO-Selbstauskunft als JSON-Download |
| GET | `/api/v1/support` | Eigene Support-Tickets |
| POST | `/api/v1/support` | Neues Ticket (`subject`, `message`, `category`) |
| GET | `/api/v1/support/:id` | Ticket-Detail inkl. Nachrichtenverlauf |
| POST | `/api/v1/support/:id/reply` | Antwort im Ticket (`message`) |

### Konto-Endpunkte (role: member ODER manager -- betreffen den Login-Account)

| Methode | Pfad | Zweck |
|---|---|---|
| GET | `/api/v1/profile` | Eigenes Profil (E-Mail, Name, 2FA-Status, Foto-Status) |
| POST | `/api/v1/profile` | Profil bearbeiten (`email`, `first_name`, `last_name`) |
| POST | `/api/v1/profile/photo` | Profilbild setzen (**multipart**, Feld `photo`) |
| POST | `/api/v1/password` | Passwort ändern (`current_password`, `new_password`, `confirm_password`) |
| GET | `/api/v1/2fa/setup` | 2FA-Einrichtung starten (Secret + otpauth-URI + Setup-Ticket) |
| POST | `/api/v1/2fa/enable` | 2FA aktivieren (`setup_ticket` + `code`) |
| POST | `/api/v1/2fa/disable` | 2FA deaktivieren (kein Body) |

### Rollen-/Community-Wechsel OHNE Neuanmeldung (seit 19.08.2026)

| Methode | Pfad | Zweck |
|---|---|---|
| GET | `/api/v1/roles` | Alle Rollen-Optionen des eingeloggten Accounts, mit `active: true` bei der gerade aktiven |
| POST | `/api/v1/switch-role` | Wechselt zu `community_id`+`role` aus obiger Liste -- liefert komplett neues Token-Paar, siehe Abschnitt 9 |

### Obmann-Endpunkte (role: manager; mit einem Mitglied-Token 403)

| Methode | Pfad | Zweck |
|---|---|---|
| GET | `/api/v1/manager/members` | Mitgliederliste der eigenen EEG |
| GET | `/api/v1/manager/members/:id` | Mitglied-Detail inkl. Zählpunkten + Dateien |
| POST | `/api/v1/manager/members` | Neues Mitglied anlegen (Pflichtfelder + 6 Zustimmungen, siehe unten) |
| POST | `/api/v1/manager/members/:id` | Mitglied-Stammdaten bearbeiten |
| POST | `/api/v1/manager/members/:id/files` | Datei für ein Mitglied hochladen (**multipart**, Feld `file` + optional `name`) |
| GET | `/api/v1/manager/members/:id/files/:fileid/download` | Mitglied-Datei herunterladen |
| POST | `/api/v1/manager/members/:id/photo` | Profilbild eines Mitglieds setzen (**multipart**, Feld `photo`) |

**WICHTIG -- vollständige Feldliste für "Mitglied anlegen" (`POST /api/v1/manager/members`):**
Im ersten Durchlauf hat das Formular nur die sechs Pflichtfelder umgesetzt -- das reicht nicht,
das Backend/der Web-Auftritt kennt deutlich mehr Felder, die alle in der App eingebbar sein
sollen (auch wenn viele optional bleiben). Vollständige Liste:

| Feld | Pflicht? | Typ/Beispiel | Hinweis |
|---|---|---|---|
| `salutation` | optional | `"Herr"` / `"Frau"` / leer | Picker |
| `titel` | optional | `"Dipl.-Ing."` | Freitext |
| `first_name` | **Pflicht** | Freitext | |
| `last_name` | **Pflicht** | Freitext | |
| `company_name` | optional | Freitext | für Firmenmitglieder |
| `address` | **Pflicht** | Freitext | Straße + Nr. |
| `zip` | **Pflicht** | Freitext | PLZ |
| `city` | **Pflicht** | Freitext | |
| `email` | **Pflicht** | E-Mail | Login-Adresse des neuen Accounts |
| `phone` | optional | Freitext | |
| `invoice_uid` | optional | Freitext | UID-Nummer für die Rechnung (Firmenmitglieder) |
| `member_iban` | optional | IBAN | wird serverseitig validiert (400 bei ungültiger Prüfsumme) |
| `member_bic` | optional | BIC | |
| `kontoinhaber` | optional | Freitext | falls abweichend vom Mitgliedsnamen |
| `konto_adresse` | optional | Freitext | falls abweichend |
| `member_since` | optional | Datum, Default heute | Beitrittsdatum |
| `member_until` | optional | Datum, Default `2099-12-31` | i. d. R. leer lassen |
| `geburtsdatum` | optional | Datum | |
| `stromlieferant` | optional | Freitext | bisheriger Lieferant |
| `speicher_status` | optional | Freitext/Picker | ob ein Batteriespeicher vorhanden ist |
| `speicher_kwh` | optional | Zahl | Speicherkapazität |
| `andere_eeg` | optional | Bool | ob das Mitglied noch in einer anderen EEG ist |
| `andere_eeg_name` | optional | Freitext | nur relevant wenn `andere_eeg` an |
| `email_anrede_mode` | optional | `"auto"`/`"herr"`/`"frau"`/`"familie"`, Default `auto` | steuert die Anrede in automatischen E-Mails |

**Zusätzlich als EINE gemeinsame Checkbox** ("Ich bestätige, dass die unterschriebene
Beitrittserklärung vorliegt") alle sechs rechtlichen Zustimmungen auf `true` setzen:
`zustimmung_mitgliedschaft`, `zustimmung_vollmacht`, `zustimmung_widerrufsfrist`,
`zustimmung_email_kommunikation`, `zustimmung_datenschutz`, `zustimmung_agb`.

**Optional gleich einen oder zwei Zählpunkte mitgeben** (eigene Sektion im Formular, z. B.
"Zählpunkt jetzt schon anlegen?"):
- Bezug: `add_bezug_zp` (Bool) + `bezug_zaehlpunkt_nr` (Pflicht wenn an) + optional
  `bezug_meter_code` (13-stellige Zählernummer) + optional `bezug_jahresverbrauch_kwh`
- Einspeisung: `add_einspeisung_zp` (Bool) + `einspeisung_zaehlpunkt_nr` (Pflicht wenn an) +
  optional `einspeisung_meter_code` + optional `einspeisung_engpassleistung_kw` + optional
  `einspeisung_geplante_einspeisung_kwh`

Gleiche Feldliste gilt sinngemäß für `POST /api/v1/manager/members/:id` (Bearbeiten) --
ausgenommen `email` (wird beim Bearbeiten nicht geändert) und die Zustimmungs-/Zählpunkt-Felder.
`GET /api/v1/manager/members/:id` liefert bereits alle aktuell befüllten Werte zum Vorausfüllen
des Bearbeiten-Formulars zurück.

### Smart-Home-API (separates System, für die Mitglieder-App NICHT relevant)

`GET /api/v1/me` und `GET /api/v1/live` verwenden ein anderes Auth-Schema (langlebige,
selbst erzeugte API-Keys für Node-RED/Home-Assistant, kein Bearer-Login-Token) -- nicht in
dieser App verwenden, nur zur Abgrenzung erwähnt.

---

## 6. Was aktuell NOCH NICHT Teil der App ist (Ziel: vollständige Parität)

**Update 19.08.2026:** Patrick möchte VOLLE Feature-Parität mit dem Web-Portal, inkl.
Platform-Admin-Funktionen ("Alles soll eins zu eins sein") -- die ursprüngliche bewusste
Einschränkung dieses Dokuments ist damit aufgehoben, nicht mehr aktuell. Die folgende Liste ist
jetzt eine ARBEITSLISTE, kein "bleibt für immer Web-only". Laufend aktuell gehalten (und um
neue Ideen ergänzt) in **`app/ios-app/APP_PARITY_BACKLOG.md`** -- dort auch nachsehen, was seit
diesem Dokument schon neu dazugekommen ist, bevor mit einem Bereich hier begonnen wird.

Noch ohne `/api/v1/*`-Entsprechung, Backend-Arbeit jeweils noch nötig:

- Abrechnung/Rechnungslauf-Erstellung, Freigabe, SEPA-XML, Mahnwesen
- EDA-Import (monatliche Energiedaten-Exportdatei)
- Vertrags-**Versand** als Obmann (Signieren als Mitglied ist bereits enthalten, s. o.)
- EEG-Einstellungen (Logo, Tarif, Steuer, E-Mail-Signatur, Stammdaten)
- Beitrittsanträge prüfen/genehmigen/ablehnen
- Postfach (Systembenachrichtigungen)
- Zählpunkt nachträglich bearbeiten/löschen/zuordnen (nur Anlegen bei Mitglied-Neuanlage und
  Ansehen sind aktuell in der App enthalten)
- Alle Platform-Admin-Funktionen (E-Mail-/Graph-Einstellungen, Mail-/LaTeX-Vorlagen,
  EEG-Verwaltung plattformweit, Nutzer & Rollen, Aktivitätslog, Backups, MQTT-Einstellungen +
  Fernkonfiguration, Testmodus)

Neue Endpunkte dafür nach demselben Muster wie die bestehenden bauen (Bearer-Token prüfen via
`AppApiAuth::requireAppAuth()`/`requireManagerAuth()`, `DB::setCommunity()` setzen, JSON statt
HTML liefern) -- das Backend dafür entsteht schrittweise, Reihenfolge wird jeweils mit Patrick
abgestimmt und in `APP_PARITY_BACKLOG.md` nachgeführt. Bitte in der App KEINE Endpunkte für
diese Bereiche erfinden/raten, solange sie noch nicht in `docs/APP_API.md` auftauchen --
stattdessen den jeweiligen Menüpunkt vorerst ausblenden/deaktivieren, bis das Backend nachzieht.

**Zur Rolle "Platform-Admin" -- aktueller Stand, wird sich noch ändern:** Ein Platform-Admin-
Account bekommt beim App-Login aktuell noch GENAU DIESELBEN Rechte/Endpunkte wie ein normaler
Obmann (`role: "manager"`, kein dritter Rollenwert, spiegelt `Auth::isManager()` im
Web-Backend). Das ist NICHT das Zielbild -- Platform-Admin-exklusive Funktionen sollen laut
Patrick ebenfalls in die App, siehe Liste oben/Backlog. Bis das Backend dafür da ist, sieht ein
Platform-Admin-Account in der App vorerst weiterhin nur den normalen Obmann-Umfang (Mitglieder,
Konto) -- kein Darstellungsfehler, sondern der aktuelle Zwischenstand auf dem Weg zur vollen
Parität.

---

## 7. Vorschlag: Bildschirme

### Auth
1. **Login** -- E-Mail/Passwort, ggf. 2FA-Code, ggf. Rollen-/EEG-Auswahl.

### Mitglied-Bereich
2. **Dashboard** -- aktuelle Leistung, Community-Werte, letzter Verbrauchsmonat, letzte Rechnung.
3. **Verbrauch** -- Balkendiagramm der letzten Monate.
4. **Rechnungen** -- Liste, Tippen öffnet PDF-Ansicht/Teilen (`ShareLink`/`UIActivityViewController`).
5. **Zählpunkte** -- eigene Zählpunkte mit Typ/Status.
6. **Verträge** -- Status Bezug/Einspeisung, PDF ansehen, bei Status "erstellt" Unterschriften-Bildschirm
   (`PencilKit`-Canvas → PNG → Base64 → `POST /contracts/:type/sign`).
7. **Dokumente** -- eigene Dateien, Download.
8. **Support** -- Ticket-Liste, Ticket-Detail mit Chatverlauf, neue Nachricht/neues Ticket.
9. **Profil** -- eigene Daten bearbeiten, Profilbild, Passwort ändern, 2FA ein-/ausschalten
   (QR-Code aus `otpauth_uri` rendern, z. B. mit `CoreImage`-`CIFilter.qrCodeGenerator()`).
10. **DSGVO-Export** -- Button, der die JSON-Datei herunterlädt und über `ShareLink` anbietet.

### Obmann-Bereich (nur sichtbar/erreichbar, wenn `role == "manager"`)
11. **Mitgliederliste** -- Suche/Filter über `GET /api/v1/manager/members`.
12. **Mitglied-Detail** -- Stammdaten, Zählpunkte, Dateien; Bearbeiten-Formular.
13. **Mitglied anlegen** -- Formular mit den Pflichtfeldern + Zustimmungs-Checkbox (siehe oben).
14. **Datei-Upload** -- Kamera/Fotobibliothek/Dateien-App als Quelle, `multipart/form-data`-Upload.

### Konto (für beide Rollen gemeinsam)
15. **Kontoeinstellungen** -- Profil/Passwort/2FA (Endpunkte aus Abschnitt "Konto-Endpunkte"
    oben), angemeldetes Gerät abmelden (`POST /logout`).

---

## 8. Praktische Hinweise für die Implementierung

1. **Refresh-Token sicher speichern** (iOS Keychain), niemals im Klartext in UserDefaults, Logs
   oder Crash-Reports.
2. **HTTPS ist Pflicht** -- gültiges Zertifikat auf dem Server, kein Certificate-Pinning nötig,
   aber niemals `http://` verwenden.
3. **Access-Token nicht über einen App-Neustart hinaus persistieren** -- beim Start immer frisch
   per `/token/refresh` holen (ohnehin nur 15 Minuten gültig).
4. Bei 401 auf einem Daten-Endpunkt: einmal `/token/refresh` versuchen und den Request
   wiederholen; schlägt auch das fehl, zurück zum Login-Bildschirm.
5. Bei 429: Fehlermeldung anzeigen, NICHT automatisch sofort erneut versuchen.
6. **Datei-Uploads sind Standard-`multipart/form-data`**, nicht Base64-in-JSON -- mit
  `URLSession.shared.upload(for:from:)` bzw. einem manuell gebauten `multipart/form-data`-Body
  umsetzen (Feldname exakt wie oben angegeben: `photo` bzw. `file` + optional `name`).
7. **Rollen-UI:** Ist `role == "manager"`, zusätzlich einen Obmann-Tab/-Bereich anzeigen; ist
  `role == "member"`, den normalen Mitglied-Bereich. Hat der Account laut `GET /api/v1/roles`
  MEHR als eine Option, einen Rollen-/EEG-Umschalter anzeigen (z. B. im Konto-Bereich) --
  `POST /api/v1/switch-role` liefert ein neues Token-Paar OHNE erneute Passworteingabe, siehe
  Abschnitt 9.3. Hat der Account nur eine Option, keinen Umschalter anzeigen.
8. Beträge immer mit Komma als Dezimaltrennzeichen und "EUR" bzw. "€" passend zur
  deutschsprachigen Zielgruppe formatieren (`NumberFormatter` mit `locale = Locale(identifier: "de_AT")`).

---

## 9. Runde 2: Nachbesserungen nach dem ersten Build (19.08.2026)

Der erste Durchlauf hat die App bereits gegen das Backend gebaut. Beim Testen sind ein echter
Server-Bug (jetzt behoben) und mehrere Verbesserungswünsche aufgefallen. Alles hier ist NEU seit
dem ersten Durchlauf -- die Abschnitte 1-8 oben bleiben unverändert gültig.

### 9.1 Behobener Server-Bug: "Unerwartete Antwort vom Server" beim Mitglied-Detail

**War ein echter Backend-Bug, ist bereits gefixt, kein Workaround in der App nötig** -- aber
bitte trotzdem prüfen/anpassen, wie unten beschrieben. Ursache: PostgreSQL liefert Zeitstempel
im eigenen Format zurück (`"2026-08-18 17:03:00+00"` -- Leerzeichen statt `"T"`, Offset ohne
Doppelpunkt), das ist KEIN gültiges striktes ISO-8601 und ließ Swifts
`JSONDecoder`/`ISO8601DateFormatter` (Standardeinstellung) fehlschlagen -- besonders beim
Mitglied-Detail, das gleich mehrere Datumsfelder auf einmal liefert (`member_since`,
`member_until`, `geburtsdatum`, mehrere `registered_at`/`created_at`), daher dort am
zuverlässigsten reproduzierbar. **Alle** `/api/v1/*`-Antworten liefern Zeitstempel jetzt als
sauberes ISO-8601 mit Uhrzeit+Offset, z. B. `"2026-08-18T17:03:00+00:00"` (auch reine
Kalenderdaten wie `geburtsdatum` -- dann mit `T00:00:00+00:00`).

**Was in der App zu tun ist:**
1. Sicherstellen, dass `JSONDecoder().dateDecodingStrategy = .iso8601` gesetzt ist (Standard-
   `ISO8601DateFormatter` reicht, keine eigene Formatter-Logik nötig) -- falls stattdessen z. B.
   eine eigene, an das alte Format angepasste Parsing-Logik gebaut wurde, die jetzt entfernen.
2. Alle Datumsfelder in den Codable-Models als `Date?` (OPTIONAL) modellieren, nicht `Date` --
   viele sind legitim `null` (`last_invoice` vor der ersten Rechnung, `sent_at` vor Versand,
   `geburtsdatum` wenn nicht erfasst usw.). Ein non-optionales `Date`-Feld lässt den GESAMTEN
   Decode fehlschlagen, sobald genau dieses eine Feld `null` ist -- das war vermutlich die
   zweite, unabhängige Ursache für "Unerwartete Antwort vom Server" bei manchen Mitgliedern.
3. Generell: Fehler beim JSON-Decodieren nicht mit einer generischen "Unerwartete Antwort vom
   Server"-Meldung verschlucken, sondern (zumindest im Debug-Build) den tatsächlichen
   `DecodingError` loggen (`context.debugDescription`) -- damit ein künftiges Feld-Mismatch
   sofort erkennbar ist, statt wieder nur als vages Symptom sichtbar zu werden.

### 9.2 Aktuelle Leistung automatisch aktualisieren (ohne Reload)

Neuer, leichtgewichtiger Endpunkt dafür: **`GET /api/v1/current-power`** (funktioniert mit
`role: "member"` UND `role: "manager"`):
```json
{
  "current_power_w": 320.0,
  "community": { "bezug_w": 4200, "einspeisung_w": 1800, "active_meters": 12, "total_meters": 14 }
}
```
Auf jedem Bildschirm, der die aktuelle Leistung zeigt (Dashboard, ggf. Mitglied-Detail): alle
5 Sekunden pollen (`Timer.publish` in Combine, oder eine `Task` mit `Task.sleep` in einer
`while`-Schleife, solange der Screen sichtbar ist -- Polling beim Verlassen des Screens
stoppen). NICHT den ganzen Bildschirm/das ganze ViewModel neu laden, nur die betroffenen
Zahlen aktualisieren (z. B. mit einer sanften Zahlen-Animation, `.contentTransition(.numericText())`
o. ä.). 5 Sekunden ist auch das Sende-Intervall der ESP32-Geräte -- schnelleres Pollen bringt
nichts, da sich serverseitig nicht öfter etwas ändert.

### 9.3 Rollen-/EEG-Wechsel ohne Neuanmeldung

Siehe auch Abschnitt 5. Konkreter Ablauf für einen Umschalter im Konto-Bereich:
1. Beim Öffnen des Konto-Bereichs `GET /api/v1/roles` aufrufen. Nur EINEN Eintrag? Keinen
   Umschalter anzeigen. Mehrere? Liste anzeigen, aktive Option (`active: true`) markiert.
2. Tippt der Nutzer eine andere Option an: `POST /api/v1/switch-role` mit deren
   `community_id`+`role`. Antwort hat exakt dieselbe Struktur wie die Login-Erfolgsantwort
   (`access_token`, `refresh_token`, `role`, `account`).
3. Beide Token im Keychain durch die neuen ersetzen, App-Zustand (aktive Rolle, geladene Daten)
   zurücksetzen und zum jeweiligen Start-Bildschirm (Mitglied-Dashboard bzw. Obmann-
   Mitgliederliste) navigieren -- wie nach einem frischen Login, nur ohne Passworteingabe.

### 9.4 Design: Card-Hintergrund im Dark Mode zu dunkel

Rückmeldung: der dunkelblaue Seitenhintergrund gefällt, aber die Cards/Fenster darauf wirken in
zu dunklem Grau -- zu wenig Kontrast zum Hintergrund. Bitte für die Card-/Panel-Fläche im Dark
Mode ein SPÜRBAR helleres Grau verwenden als aktuell umgesetzt (Richtwert aus der Farbtabelle in
Abschnitt 2: `Fläche` = `#1E293B`, `Fläche 2` = `#263244` -- eher in Richtung `Fläche 2` oder
noch etwas heller gehen, damit sich Card vs. Hintergrund klar abhebt). Gilt für JEDEN Screen mit
Cards/Panels, nicht nur einen -- insbesondere auch für die Übersichts-/Dashboard-Seite, dort
wurde es zuerst bemerkt.

### 9.5 Neue Idee: Energiefluss-Grafik (animiert)

Zusätzlicher Wunsch fürs Dashboard (Mitglied UND Obmann-Bereich): eine Grafik, die den
Energiefluss der EEG visualisiert, ähnlich einem Sankey-/Fluss-Diagramm:
- **Links:** Netz (öffentliches Stromnetz)
- **Oben:** Einspeiser (Community-Erzeuger, PV-Anlagen)
- **Rechts:** Bezieher (Community-Verbraucher)
- **Mitte:** die EEG selbst als zentraler Knoten
- Animierte Linien/Pfeile zwischen den Knoten, die den aktuellen Energiefluss zeigen (Richtung +
  Stärke, z. B. Linienbreite oder Animationsgeschwindigkeit proportional zur Leistung).

**Datengrundlage (kein neuer Endpunkt nötig, bereits vorhanden):** `GET /api/v1/current-power`
(Abschnitt 9.2) liefert `community.bezug_w` (= Fluss Einspeiser→EEG→Bezieher, Summe der
Community-weiten Last) und `community.einspeisung_w` (= Fluss Einspeiser→EEG, Summe der
Community-weiten Erzeugung). Netz-Fluss ist rechnerisch die Differenz:
- `netz_bezug_w = max(0, community.bezug_w - community.einspeisung_w)` -- wie viel zusätzlich
  aus dem öffentlichen Netz geholt werden muss, weil die Community-Erzeugung nicht reicht (Fluss
  Netz→EEG).
- `netz_einspeisung_w = max(0, community.einspeisung_w - community.bezug_w)` -- Überschuss, der
  ins öffentliche Netz zurückgespeist wird (Fluss EEG→Netz).
- Ist `community.einspeisung_w >= community.bezug_w`, fließt kein Strom vom Netz (Autarkie
  100 %, siehe `community_autarkie_pct` in `/api/v1/dashboard`).

Rein clientseitig mit SwiftUI umsetzbar (Pfade/Shapes + `.animation()` auf die Breite/Opazität
der Flusslinien, aktualisiert bei jedem 5s-Poll aus 9.2) -- keine Backend-Änderung nötig, reine
Visualisierungsarbeit in der App.

### 9.6 Feature-Parität: alle vorhandenen Endpunkte auch tatsächlich nutzen

Rückmeldung: es sollen "auch die Funktionen und Einstellungen, die auf der Plattform sind", in
der App auftauchen. Der erste Durchlauf hat einen Teil der bereits fertigen Endpunkte aus
Abschnitt 5 noch nicht in eigene Bildschirme umgesetzt (z. B. fehlten beim Mitglied-Anlegen-
Formular etliche Felder, siehe 9.1/oben in Abschnitt 5). **Bitte den kompletten Bildschirmplan
aus Abschnitt 7 nochmal gegen Abschnitt 5 (bzw. `docs/APP_API.md`) durchgehen und prüfen, dass
JEDER dort gelistete Endpunkt tatsächlich einen erreichbaren Bildschirm/eine erreichbare Aktion
in der App hat** -- insbesondere: Verträge (Status+PDF+Unterschreiben), eigene Dokumente,
DSGVO-Export, Support-Tickets, 2FA-Verwaltung, sowie im Obmann-Bereich Mitglied bearbeiten
und Datei-/Foto-Upload für ein Mitglied. Was AKTUELL NOCH NICHT existiert, steht in Abschnitt 6
bzw. `APP_PARITY_BACKLOG.md` (Abrechnung, EDA-Import, EEG-Einstellungen, Beitrittsanträge,
Postfach, Platform-Admin-Funktionen, ...) -- dafür bitte KEINE Endpunkte erfinden/raten, die es
noch nicht gibt; die kommen schrittweise nach (siehe 9.8 unten).

### 9.7 Behobener Server-Bug: `DecodingError.keyNotFound("id")` bei `ManagerMemberDetail`

**Das ist ein Fehler im Swift-Model, nicht im Server** -- zur Klarstellung, damit nicht am
Backend danach gesucht wird. Die tatsächliche Serverantwort von `GET /api/v1/manager/members/:id`
(unverändert, exakt wie in `docs/APP_API.md` dokumentiert):
```json
{
  "member": { "id": "uuid", "kundennummer": 10003, "...": "..." },
  "metering_points": [ { "id": "uuid", "...": "..." } ],
  "files": [ { "id": "uuid", "...": "..." } ]
}
```
`id` liegt hier VERSCHACHTELT unter `member.id`, NICHT auf der obersten Ebene der Antwort. Der
Fehler `Key 'id' not found` bedeutet: das `ManagerMemberDetail`-Codable-Struct hat (vermutlich
für `Identifiable`-Konformität) ein `id`-Feld auf oberster Ebene erwartet/synthetisiert, das es
in der echten Antwort so nicht gibt. Korrektur im Swift-Model, z. B.:
```swift
struct ManagerMemberDetailResponse: Codable {
    let member: ManagerMember
    let meteringPoints: [MeteringPoint]
    let files: [MemberFile]

    enum CodingKeys: String, CodingKey {
        case member
        case meteringPoints = "metering_points"
        case files
    }
}
struct ManagerMember: Codable, Identifiable {
    let id: String
    // ... weitere Felder
}
```
`Identifiable`-Konformität (falls für eine `List`/`ForEach` gebraucht) gehört auf `ManagerMember`
selbst (dessen `id` existiert wirklich), nicht auf den äußeren Response-Wrapper. Gleiches Muster
bitte bei ALLEN anderen verschachtelten Antworten prüfen (z. B. `GET /api/v1/support/:id` mit
`ticket`+`messages`, `GET /api/v1/profile` mit `user`) -- dort liegt `id` ebenfalls jeweils
innerhalb des verschachtelten Objekts, nicht auf oberster Ebene.

### 9.8 Vollständige Feature-Parität (neues Ziel, ersetzt die bisherige bewusste Einschränkung)

Patrick, 19.08.2026: "Bitte baue mir alle Funktionen von der Plattform in die App ein [...]
Alles soll eins zu eins sein." Die in Abschnitt 6 (alte Fassung) beschriebene bewusste
Einschränkung ist damit aufgehoben -- Ziel ist jetzt vollständige Parität zwischen Web-Portal
und App, inklusive Platform-Admin-Funktionen (E-Mail-/Graph-Einstellungen, Vorlagen,
EEG-Verwaltung, Nutzerverwaltung, Aktivitätslog, Backups, MQTT). Das Backend dafür entsteht
schrittweise (jeweils eigene PRs, mit denselben Tests/Sorgfalt wie die bisherigen
App-API-Endpunkte) -- **`app/ios-app/APP_PARITY_BACKLOG.md`** ist die laufend aktuelle
Fortschritts-/Aufgabenliste dafür (was fehlt noch, was ist schon fertig). Bitte diese Datei vor
jeder neuen Xcode-Runde neu einlesen, um zu sehen, was seit dem letzten Mal an neuen
Endpunkten dazugekommen ist, statt sich nur auf den Stand dieses `app.md` zu verlassen.

---

*Strom für alle · Diplomarbeit HTL Kärnten 2026/27 · Textreferenz für die iOS-App-Entwicklung.*
