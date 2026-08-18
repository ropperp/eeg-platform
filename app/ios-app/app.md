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

**Pflichtfelder beim Anlegen (`POST /api/v1/manager/members`):** `first_name`, `last_name`,
`email`, `address`, `zip`, `city` PLUS alle sechs rechtlichen Zustimmungen als `true`:
`zustimmung_mitgliedschaft`, `zustimmung_vollmacht`, `zustimmung_widerrufsfrist`,
`zustimmung_email_kommunikation`, `zustimmung_datenschutz`, `zustimmung_agb` (in der App z. B.
eine einzige Checkbox "Ich bestätige, dass die unterschriebene Beitrittserklärung vorliegt", die
alle sechs setzt). Optional gleich Zählpunkte mitgeben (`add_bezug_zp`/`add_einspeisung_zp` +
zugehörige Zählpunktnummer-Felder) -- Details in `docs/APP_API.md`.

### Smart-Home-API (separates System, für die Mitglieder-App NICHT relevant)

`GET /api/v1/me` und `GET /api/v1/live` verwenden ein anderes Auth-Schema (langlebige,
selbst erzeugte API-Keys für Node-RED/Home-Assistant, kein Bearer-Login-Token) -- nicht in
dieser App verwenden, nur zur Abgrenzung erwähnt.

---

## 6. Was bewusst NICHT Teil dieser App-Version ist

Bleibt Web-Portal-only (`https://portal.stromfueralle.at`), kein `/api/v1/*`-Endpunkt vorhanden:

- Abrechnung/Rechnungslauf-Erstellung, Freigabe, SEPA-XML, Mahnwesen
- EDA-Import (monatliche Energiedaten-Exportdatei)
- Vertrags-**Versand** als Obmann (Signieren als Mitglied ist enthalten, s. o.)
- EEG-Einstellungen (Logo, Tarif, Steuer, E-Mail-Signatur)
- Beitrittsanträge prüfen/genehmigen/ablehnen
- Postfach (Systembenachrichtigungen)
- Zählpunkt nachträglich bearbeiten/löschen/zuordnen (nur Anlegen bei Mitglied-Neuanlage und
  Ansehen sind in der App enthalten)
- Alle Platform-Admin-Funktionen (EEG anlegen, Nutzerverwaltung, Vorlagen, MQTT-Einstellungen)

Falls eine App-Version davon später gebraucht wird: neue Endpunkte nach demselben Muster wie die
bestehenden bauen (Bearer-Token prüfen via `AppApiAuth::requireAppAuth()`/`requireManagerAuth()`,
`DB::setCommunity()` setzen, JSON statt HTML liefern) -- ist explizit NICHT Teil des aktuellen
App-Umfangs, um das Risiko bei der Backend-Erweiterung überschaubar zu halten.

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
  `role == "member"`, den normalen Mitglied-Bereich. Ein Nutzer mit beiden Rollen loggt sich für
  jede Rolle separat ein (zwei unterschiedliche Access-Token, siehe "Mehrfach-Rolle" oben) --
  kein Rollenwechsel innerhalb einer laufenden Session ohne erneuten Login.
8. Beträge immer mit Komma als Dezimaltrennzeichen und "EUR" bzw. "€" passend zur
  deutschsprachigen Zielgruppe formatieren (`NumberFormatter` mit `locale = Locale(identifier: "de_AT")`).

---

*Strom für alle · Diplomarbeit HTL Kärnten 2026/27 · Textreferenz für die iOS-App-Entwicklung.*
