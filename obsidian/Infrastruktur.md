---
tags: [eeg-platform, infrastruktur, stromfueralle]
quelle: CLAUDE.md (eeg-platform Repo-Root)
---

# EEG-Plattform — Infrastruktur

> Spiegel von `CLAUDE.md` im [eeg-platform](https://github.com/ropperp/eeg-platform)-Repo.
> Bei jeder Änderung an `CLAUDE.md` wird diese Notiz mit aktualisiert.

## Git-Workflow: Branches, Tags & Versionierung

Seit 0.9.0 mit schlanker Branch-/Tag-Strategie:

- **`main`** ist immer deploybar (`git pull && docker compose up -d --build`). Kleine, klare
  Änderungen dürfen direkt auf `main`.
- **Feature-Branches** (`feature/<kurzname>` bzw. der von der Umgebung vorgegebene
  `claude/<...>`-Branch) für größere/riskante Arbeit → testen (`make test` + CI) → per Pull
  Request nach `main` mergen. Hält `main` jederzeit lauffähig. Auf einem fest vorgegebenen
  Arbeits-Branch (Claude Code on the web): PR sofort selbst erstellen UND mergen, ohne
  nachzufragen (Patrick, 07.08.2026).
- **Tags** (`vX.Y.Z`, Semantic Versioning) markieren getestete Stände: PATCH = Bugfix,
  MINOR = neue Funktion, MAJOR/`1.0.0` = großer Umbau bzw. Produktivstart. `0.x` = vor dem
  Produktivstart. Jeder Tag hat einen `CHANGELOG.md`-Eintrag.

**Nutzen:** Ein Tag ist ein benannter Fixpunkt → jederzeit einen getesteten Stand deployen oder
dorthin zurückrollen, im Changelog nachlesen was sich geändert hat, und gegenüber der HTL/
Diplomarbeit den Funktionsstand pro Zeitpunkt dokumentieren.

```bash
git switch -c feature/mein-thema        # neuen Branch beginnen
git push -u origin feature/mein-thema   # dann PR nach main
git tag -a v0.9.1 -m "0.9.1 – ..." && git push origin v0.9.1   # Release taggen
git checkout v0.9.0 && docker compose up -d --build            # Stand deployen/zurückrollen
```

## Netzwerk-Architektur

```
Internet
   │
   ▼ Port 443 (HTTPS)
nginx-Proxy (10.0.0.144 / öffentliche IP: 80.122.212.226)
   │  SSL-Terminierung via Certbot/Let's Encrypt
   │  Zertifikat: /etc/letsencrypt/live/stromfueralle.at/
   │
   ▼ HTTP Port 80 (intern: 10.0.0.250)
Traefik (Docker, Port 80)
   │  Routing per Host-Header
   │
   ▼
webapp (nginx + PHP 8.2, internes Docker-Netz)
```

### nginx-Proxy-Config (auf 10.0.0.144)
Datei: `/etc/nginx/sites-available/70_stromfueralle.conf`

```nginx
server {
    listen 443 ssl;
    server_name stromfueralle.at www.stromfueralle.at;
    ssl_certificate     /etc/letsencrypt/live/stromfueralle.at/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/stromfueralle.at/privkey.pem;
    include             /etc/letsencrypt/options-ssl-nginx.conf;
    ssl_dhparam         /etc/letsencrypt/ssl-dhparams.pem;
    client_max_body_size 20M;
    location / {
        proxy_pass         http://10.0.0.250;
        proxy_set_header   Host              $host;
        proxy_set_header   X-Real-IP         $remote_addr;
        proxy_set_header   X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto https;
    }
}
server {
    listen 80;
    server_name stromfueralle.at www.stromfueralle.at;
    return 301 https://$host$request_uri;
}
```

> `client_max_body_size 20M;` muss hier gesetzt sein (Standard-Limit von nginx ist nur 1 MB) — sonst
> liefert **dieser** nginx-Proxy bei Datei-Uploads (z. B. Ausweis-Scan, Beitrittserklärung-PDF) einen
> `413 Request Entity Too Large`, obwohl `webapp/docker/nginx.conf` und `php.ini` im Repo bereits
> korrekt auf 20M stehen. Nach Änderung: `sudo nginx -t && sudo systemctl reload nginx`.

> `www.stromfueralle.at` muss als SAN im Zertifikat enthalten sein, sonst liefert nginx
> für www das Default-Zertifikat aus und Browser zeigen einen SSL-Fehler.

## EEG-Server (10.0.0.250)

### Verzeichnis
```
/opt/eeg-platform/   ← Git-Repo (branch: main)
/opt/eeg/            ← Persistente Daten (DB, Redis, Mosquitto, Traefik-Certs, Webapp-Storage)
```

> `/opt/eeg/webapp-storage` (→ `/var/www/html/storage`) enthält Mitglieder-Uploads,
> Beitrittserklärungen und generierte PDFs. Vorher nur im Container — ging bei jedem `--build`
> verloren. Seit 14.07.2026 ein echtes Volume, unbedingt ins Backup aufnehmen.

### Docker-Stack

| Service | Image | Ports (Host) | Zweck |
|---------|-------|-------------|-------|
| traefik | traefik:latest | 80:80 | Reverse Proxy, liest Docker-Labels |
| timescaledb | timescale/timescaledb-ha:pg16 | — | PostgreSQL + TimescaleDB |
| redis | redis:7-alpine | — | Session-Cache |
| mosquitto | eclipse-mosquitto:2 | 1883, 8883 | MQTT-Broker |
| mqtt-subscriber | (build) | — | MQTT → DB |
| webapp | (build) | — | nginx + PHP 8.2 |
| latex-service | (build) | — | PDF-Generator |

### Wichtige Traefik-Details
- Traefik hört **nur auf Port 80** (kein HTTPS, kein Let's Encrypt) — SSL macht der nginx-Proxy
- `DOCKER_API_VERSION=1.40` gesetzt (Docker Engine 29.x braucht mindestens 1.40)
- `--providers.docker.exposedbydefault=false` → nur Container mit `traefik.enable=true` werden geroutet
- **Traefik v3-Falle:** `Host()` akzeptiert nur noch **einen** Wert pro Aufruf. Mehrere Hosts
  immer mit `Host(\`a\`) || Host(\`b\`)`, NICHT `Host(\`a\`, \`b\`)` (v2-Syntax, bricht den Router).

### Webapp-Router-Labels
```yaml
traefik.enable=true
traefik.http.routers.webapp.rule=Host(`stromfueralle.at`) || Host(`www.stromfueralle.at`)
traefik.http.routers.webapp.entrypoints=web
traefik.http.routers.live.rule=Host(`live.stromfueralle.at`)
traefik.http.routers.portal.rule=Host(`portal.stromfueralle.at`)
traefik.http.routers.admin.rule=Host(`admin.stromfueralle.at`)
traefik.http.routers.webapp-legacy.rule=Host(`webapp.mechtronix.at`)
traefik.http.services.webapp.loadbalancer.server.port=80
```

## .env auf dem Server

Datei: `/opt/eeg-platform/.env` (nicht in Git)

```env
DB_USER=eeg
DB_PASSWORD=<sicheres Passwort>
DB_NAME=eeg_platform
DOMAIN=stromfueralle.at
APP_SECRET=<64-Zeichen zufällig>
LATEX_API_KEY=<random>
SMTP_HOST=smtp-relay.brevo.com
SMTP_USER=<email>
SMTP_PASSWORD=<passwort>
```

## Update (laufendes System)

```bash
cd /opt/eeg-platform
git pull origin main
docker compose up -d --build
```

> **Einmalig nach dem Update vom 14.07.2026:** Storage-Volume vorher anlegen, sonst
> Rechteproblem (www-data/UID 82 im Alpine-Image kann sonst nicht schreiben):
> ```bash
> sudo mkdir -p /opt/eeg/webapp-storage/{uploads,pdfs}
> sudo chown -R 82:82 /opt/eeg/webapp-storage
> ```

> **Einmalig nach dem Update vom 16.07.2026** (Platform-Admin-Dateiverwaltung für
> LaTeX-Vorlagen, `/admin/templates`): gleiches Muster für `/opt/eeg/latex-templates`
> (gemountet in `webapp` UND `latex-service`):
> ```bash
> sudo mkdir -p /opt/eeg/latex-templates
> sudo chown -R 82:82 /opt/eeg/latex-templates
> ```
> `latex-service` läuft als root und darf trotzdem weiterhin schreiben. Bleibt das Verzeichnis
> beim ersten Start leer, kopiert `latex-service` einmalig seine mitgelieferten
> Standard-Vorlagen hinein.

> **Einmalig nach dem Update vom 30.07.2026** (MQTT-Broker mit TLS + Zugangsdaten statt offen/
> anonym): Mosquitto verlangt jetzt `allow_anonymous false` + ein Zertifikat für Port 8883 --
> ohne beides startet der Container gar nicht. Einmalig:
> ```bash
> ./scripts/mqtt_secure_setup.sh
> ```
> Erzeugt ein selbstsigniertes Zertifikat unter `/opt/eeg/mosquitto/certs` (10 Jahre gültig,
> ESP32-Geräte prüfen es nicht -- `setInsecure()` --, verschlüsselt trotzdem), generiert
> `MQTT_USER`/`MQTT_PASSWORD` in `.env`, schreibt die Passwort-Datei
> (`/opt/eeg/mosquitto/passwd`) und startet `mosquitto` + `mqtt-subscriber` neu. **Wichtig:**
> Danach verliert JEDES bereits im Feld laufende ESP32-Gerät die Verbindung, bis im eigenen
> `/config`-Formular Benutzername/Passwort nachgetragen werden. Bei einer echten
> Neuinstallation ruft `scripts/setup.sh` dieses Skript automatisch mit auf.

> **Einmalig nach dem Update vom 25.08.2026** (automatischer EDA-Postfach-Import): der
> monatliche EDA-Energiedatenreport kann jetzt automatisch importiert werden, statt ihn von
> Hand über `/portal/eda/upload` hochzuladen -- `EdaAutoImporter.php` liest ein zentrales
> Postfach über Microsoft Graph aus, lädt die Exportdatei herunter und übergibt sie an
> `eda-parser/parser.py` (Community-Zuordnung über die Marktpartner-ID im Dateinamen,
> z. B. `RC108175_...`). Einmalig einzurichten:
> 1. Shared Mailbox `eda@stromfueralle.at` in Microsoft 365 anlegen (wie `noreply@...`).
> 2. Zusätzliche Anwendungsberechtigung `Mail.Read` (Application Permission, Admin-Zustimmung)
>    für dieselbe Azure-App `stromfueralle-mailer` -- sie hat bereits `Mail.Send`.
> 3. Im EDA-Anwenderportal einen Export-User anlegen, dessen Login-/Benachrichtigungsadresse auf
>    `eda@stromfueralle.at` zeigt -- **das Anfordern/Auslösen des Exports im Portal selbst
>    bleibt vorerst manuell** (Login + Klick auf "Export"), nur das Abholen danach läuft automatisch.
> 4. Platform-Admin → Einstellungen → "EDA-Automatik": Postfachadresse eintragen (leer =
>    aus). Je EEG optional EDA-Login-Zugangsdaten hinterlegen (nur zentrale Aufbewahrung,
>    verschlüsselt wie WLAN-Passwörter).
> 5. Cron (einmal täglich reicht):
>    ```bash
>    ( crontab -l 2>/dev/null; echo "0 7 * * * cd /opt/eeg-platform && docker compose exec -T webapp php < scripts/eda_auto_import.php >> /var/log/eeg-eda-import.log 2>&1" ) | crontab -
>    ```
> Testen ohne Cron abzuwarten: Platform-Admin → Einstellungen → "Jetzt prüfen". Nicht
> automatisch verarbeitbare Mails bleiben ungelesen + Alarm-Mail; Fallback bleibt der manuelle
> Upload über `/portal/eda/upload`. **Format an einer echten EDA-Mail verifiziert (13.08.2026):**
> Absender `no-reply@eda.at`, Marktpartner-ID auch im Betreff, signierter Download-Link (7 Tage
> gültig, kein Anhang) auf `prod-api.eda-portal.at/exports/download/...` -- `EdaAutoImporter.php`
> entsprechend angepasst (Absender-Filter, gezielte Link-Suche, Marktpartner-ID-Gegenprobe).
> **Live-Download bestätigt (13.08.2026):** erster echter Auto-Import-Lauf hat komplett
> funktioniert (Absender, Link, Download OHNE Portal-Session, Zuordnung) -- kein Login-Schritt
> nötig. Zusätzlich: ein erneuter Import für einen Zeitraum mit bereits vorhandenen Daten wird
> jetzt automatisch überschrieben, solange dafür noch keine Rechnungen verschickt wurden (siehe
> `_billing_period_finalized()` in `eda-parser/parser.py`) -- praktisch bei anfangs nur
> L3-Datenqualität.

> **Einmalig nach dem Update vom 10.08.2026** (MQTT-Zugangsdaten in der Plattform sichtbar/
> änderbar, per Knopfdruck automatisch angewendet): Platform-Admin → Einstellungen →
> "MQTT-Zugangsdaten" → "Speichern & anwenden" speichert einen Wunschwert in der DB
> (`platform_mqtt_config`, `pending_apply=true`) -- die Webapp kann Docker/Dateien auf dem Host
> nicht direkt anfassen, ein Host-Cron übernimmt das Anwenden:
> ```bash
> ( crontab -l 2>/dev/null; echo "* * * * * cd /opt/eeg-platform && bash scripts/mqtt_apply_pending.sh >> /var/log/eeg-mqtt-apply.log 2>&1" ) | crontab -
> ```
> Prüft `pending_apply`, ruft bei Bedarf `mqtt_secure_setup.sh --apply` auf (schreibt `.env`,
> erzeugt die Passwort-Datei neu, startet `mosquitto` + `mqtt-subscriber` neu) und markiert die
> Änderung als erledigt (`applied_at`, in der Oberfläche sichtbar). Ohne Cron-Job manueller
> Fallback: `./scripts/mqtt_secure_setup.sh --apply` direkt auf dem Server. Danach jedes
> ESP32-Gerät im `/config`-Formular auf das neue Passwort umstellen.

> **Einmalig nach dem Update vom 17.08.2026** (OWASP-Audit-Fixes -- RLS greift jetzt tatsächlich,
> TOTP-Secrets verschlüsselt, Brute-Force-Schutz, CSRF-Schutz, Security-Header,
> Passwort-Leak-Check): genaue Reihenfolge/Begründung in `docs/DEPLOY_OWASP_AUDIT.md`, Kurzfassung:
> ```bash
> cd /opt/eeg-platform
> git pull origin main
> docker compose exec -T timescaledb psql -U eeg -d eeg_platform < database/migrate_20260822.sql
> ./scripts/redis_secure_setup.sh
> ./scripts/db_runtime_role_setup.sh
> docker compose up -d --build
> docker compose exec -T webapp php < scripts/migrate_encrypt_totp_secrets.php
> ```
> **Reihenfolge wichtig** (Vorfall 17.08.2026, Patrick komplett ausgesperrt, "Sitzung
> abgelaufen" bei jedem Login): `redis_secure_setup.sh` MUSS vor `docker compose up -d --build`
> laufen, sonst legt Docker `/opt/eeg/redis-config/redis.conf` fälschlich als leeres
> Verzeichnis statt als Datei an (Bind-Mount auf noch nicht existierenden Host-Pfad) -- Redis
> kann dann keine Konfiguration mehr lesen, jede Sitzung schlägt fehl. Fix falls schon
> passiert: `docker compose stop redis && sudo rm -rf /opt/eeg/redis-config/redis.conf &&
> ./scripts/redis_secure_setup.sh` (kein Datenverlust, nur aktive Sitzungen müssen sich neu
> anmelden). Das Skript heilt diesen Zustand seit dem Fix auch selbst, falls es doch mal in
> falscher Reihenfolge läuft. Bei einer Neuinstallation ruft `scripts/setup.sh`
> `redis_secure_setup.sh` und `db_runtime_role_setup.sh` automatisch mit auf.

> **Einmalig nach dem Update vom 03.09.2026** (Push-Benachrichtigungen für die iOS-App --
> Postfach an Obmann/Admin, neue Rechnung an Mitglied, Einspeisung-Schwelle mit Hysterese an
> Mitglied): DB-Trigger füllen `push_notifications_queue`, `Push.php` liefert per APNs aus
> (ES256-JWT + HTTP/2-cURL, deshalb PHP-`curl`-Modul jetzt im `webapp`-Image). Setup:
> ```bash
> cd /opt/eeg-platform
> git pull origin main
> docker compose exec -T timescaledb psql -U eeg -d eeg_platform < database/migrate_20260903.sql
> docker compose up -d --build
> ( crontab -l 2>/dev/null; echo "* * * * * cd /opt/eeg-platform && docker compose exec -T webapp php < scripts/send_pending_push.php >> /var/log/eeg-push.log 2>&1" ) | crontab -
> ```
> Ohne Apples echte APNs-Zugangsdaten bleibt die Warteschlange einfach liegen (kein
> Fehlerspam). Patrick muss einmalig in seinem Apple-Developer-Account einen Auth-Key (.p8)
> erzeugen und Team-ID/Key-ID/Bundle-ID/.p8-Inhalt über Platform-Admin → Einstellungen
> hinterlegen -- danach greift der nächste Cron-Lauf automatisch.

> **Einmalig nach dem Update vom 05.09.2026** (Demo-Login für Präsentation/Diplomarbeit-Review --
> EIN Login, umschaltbar zwischen Plattform-Admin, Obmann und zwei unabhängig wählbaren, komplett
> fiktiven Mitglied-Identitäten "Verbraucher 1"/"Einspeiser 1" in derselben EEG): `user_roles`
> erlaubt jetzt über die neue Spalte `member_id` mehr als eine 'member'-Zeile je Login+EEG. Der
> Login ist über `users.is_demo` plattform- und rollenübergreifend schreibgeschützt (jeder POST
> zentral blockiert, außer dem Rollenwechsel selbst); `members.is_demo` schließt die beiden
> fiktiven Identitäten zusätzlich von echten Abrechnungsläufen aus.
> ```bash
> cd /opt/eeg-platform
> git pull origin main
> docker compose exec -T timescaledb psql -U eeg -d eeg_platform < database/migrate_20260905.sql
> docker compose up -d --build
> ./scripts/create_demo_login.sh
> docker compose exec -T webapp php < scripts/create_demo_members.php
> ```
> `create_demo_members.php` kopiert die Verbrauchsdaten von Stefanie Schwaiger/Daniel Ropper auf
> zwei fiktive Mitglied-Datensätze (neuer Name, neue Zählpunktnummer, keine echte
> Adresse/Telefonnummer/Geburtsdatum, aber vollständig ausgefüllte Platzhalterfelder wie
> Kundennummer/IBAN/Zustimmungen, damit die Detailseiten vollständig wirken) -- ist ein SYNC, kein
> Einmal-Skript: der Mitglied-Datensatz wird nur beim ersten Lauf angelegt, die Messdaten aber bei
> JEDEM Lauf komplett neu aus dem aktuellen Stand der Vorlage-Mitglieder kopiert ("Daten sollen
> immer gleich sein mit den aktuell gültigen Daten") -- als täglicher Cron-Job eingerichtet, kurz
> nach dem EDA-Auto-Import. Danach im Platform-Admin-Backoffice den neuen Login öffnen und alle
> vier Rollen zuweisen. In allen vier Rollen sind ausnahmslos alle Funktionen/Felder/Buttons
> sichtbar (nichts ausgeblendet) -- nur
> das tatsächliche Absenden eines Formulars ist gesperrt (freundliche Hinweisseite statt Fehler).
> Details: siehe `CLAUDE.md` im Repo.

> **Einmalig nach dem Update vom 06.09.2026** (Live-ESP-Spiegelung für die Demo-Mitglieder --
> "in Echtzeit", keine Simulation): ein DB-Trigger auf `esp_measurements` spiegelt jede neue
> Live-Messung der echten Vorlage-Mitglieder (Stefanie Schwaiger/Daniel Ropper, alle ~5s über
> `mqtt-subscriber`) sofort auch auf den jeweiligen Demo-Zählpunkt -- echte Live-Daten unter
> fiktiver Identität, kein Polling. Zieht dabei auch `esp_online`/`esp_last_seen_at` mit, der
> Demo-Zählpunkt zählt dadurch auch bei "ESP online: X von Y" normal mit.
> ```bash
> cd /opt/eeg-platform
> git pull origin main
> docker compose exec -T timescaledb psql -U eeg -d eeg_platform < database/migrate_20260906.sql
> docker compose exec -T webapp php < scripts/create_demo_members.php
> ```
> Kein Rebuild nötig (reine DB-Änderung). Details: siehe `CLAUDE.md` im Repo.

> **Stolperstein Rollenzuweisung Demo-Login:** im Platform-Admin-Backoffice erscheint das Feld
> "Mitglied-Identität" beim Rolle-Hinzufügen erst NACH Auswahl von "member" -- wird das
> übersehen, landet die Rolle mit `member_id = NULL` und führt ins Leere. Reparatur (sicher
> erneut ausführbar): `docker compose exec -T webapp php < scripts/assign_demo_member_roles.php`.
> Details: siehe `CLAUDE.md` im Repo.

> **PII-Maskierung für Obmann/Admin im Demo-Login:** reine Code-Änderung (`git pull` +
> `docker compose up -d --build`). `demoMask*()` in `functions.php` maskiert personenbezogene
> Felder ECHTER Mitglieder/Logins für den Demo-Account (Vorname 4 Buchstaben + Punkte,
> Nachname/E-Mail/Adresse/IBAN/Zählpunktnummer komplett unkenntlich, Telefon nur letzte 4
> Stellen, Geburtsdatum maskiert, Profilbild → Default-Avatar) -- eingebaut in
> Mitgliederliste/-detail (Obmann) sowie Nutzerliste/-detail/EEG-Mitgliederliste
> (Platform-Admin). Noch nicht abgedeckt: Aktivitätslog, Anträge, Postfach, Support-Tickets,
> Rechnungsliste, Bearbeiten-Formulare -- beim Vorführen vorerst meiden. Details: `CLAUDE.md`.

> **Stolperstein Pre-Launch-Popup Demo-Login:** ein Demo-Login saß beim allerersten Aufruf der
> Mitglied-Ansicht hinter dem Pre-Launch-Hinweis-Popup fest -- der "Gelesen"-Button dahinter ist
> ein POST und wurde von der Read-only-Sperre blockiert, der gesperrte Seiteninhalt dahinter
> ließ sich so nicht mehr erreichen. Behoben: Popup wird für Demo-Logins gar nicht mehr gezeigt,
> zusätzlich `/portal/ack-prelaunch` als folgenlose Ausnahme in `Router.php` erlaubt. Reine
> Code-Änderung. Details: `CLAUDE.md`.

> **Drei Nachbesserungen vom 06.09.2026:**
> 1. **Energiefluss doppelt gezählt**: die Live-ESP-Spiegelung hatte einen Community-weiten
>    Zähl-Bug ausgelöst -- `communityLivePower()` UND die öffentliche `/api/live/:slug`
>    (`live.stromfueralle.at`, für jeden Besucher sichtbar) summierten Leistung über ALLE
>    Zählpunkte, ohne gespiegelte Demo-Zählpunkte auszuschließen (echte Messung + Spiegelung
>    zählten doppelt). Behoben durch `mirror_source_metering_point_id IS NULL` in allen
>    betroffenen Summen. Live an einer Scratch-DB verifiziert. Reine Code-Änderung.
> 2. **platform_admin/manager im Demo-Login fehlten trotz manueller Zuweisung**:
>    `scripts/assign_demo_member_roles.php` legt sie jetzt selbst an, falls sie fehlen, und gibt
>    den tatsächlichen Rollenstand aus der DB aus -- `docker compose exec -T webapp php <
>    scripts/assign_demo_member_roles.php`.
> 3. **Einspeiser hatten kein Diagramm für ihre Einspeisung**: neue Seite
>    `/portal/my/einspeisung` (App: `GET /api/v1/production/interval`), Spiegelbild des
>    Verbrauchsdiagramms mit `energy_direction='GENERATION'`. Reine Code-Änderung.
>
> Details: `CLAUDE.md`.

> **Weitere Nachbesserungen vom 06.09.2026, nach dem ersten echten Login als Demo-Admin:**
> 1. **Absturz beim Öffnen von /portal/dashboard als Demo-Admin**: `assign_demo_member_roles.php`
>    hatte platform_admin mit `community_id=NULL` angelegt, `manager_dashboard.php` braucht aber
>    zwingend eine aktive Community. Doppelt behoben: `/portal/dashboard` weicht bei fehlender
>    Community auf `/admin` aus (schützt auch echte platform_admin-Accounts), und das
>    Rollen-Skript setzt/repariert jetzt immer eine echte Community. Erneut ausführen:
>    `docker compose exec -T webapp php < scripts/assign_demo_member_roles.php`.
> 2. **"ESP online: 3 von 4" statt korrekt**: eine zweite, unabhängige Zählstelle in
>    `manager_dashboard.php` hatte denselben Doppelzählungs-Bug wie zuvor `communityLivePower()`
>    -- ergänzt um dieselbe `mirror_source_metering_point_id IS NULL`-Bedingung.
> 3. **Echte Zugangsdaten im Klartext für Demo-Admin sichtbar**: MQTT-Passwort +
>    Geräte-Fernkonfigurationspasswort (`/admin/mail-settings`), EDA-Portal-Passwort je EEG
>    (`/admin/communities/:id`), Mitglied-WLAN-Passwort (GET-Endpunkt, von der POST-only Sperre
>    nicht erfasst) -- alle jetzt für Demo-Logins maskiert. Microsoft-Graph-Client-Secret war
>    bereits sicher, Tenant-/Client-ID zusätzlich maskiert (keine echten Geheimnisse, aber auf
>    Patricks Wunsch).
>
> Details: `CLAUDE.md`.

> **Update vom 06.09.2026 -- Datei-Downloads für den Demo-Account komplett gesperrt + weitere
> PII-Lücken geschlossen** (Patrick: "Die Dateien dürfen nie, in gar keinem Fall [...]
> heruntergeladen werden [...] Ich würde da voll gegen das Datenschutzrecht verstoßen."): reine
> Code-Änderung, kein Migrations-/Setup-Skript nötig.
> 1. **Datei-Downloads:** die POST-only Read-only-Sperre erfasste Datei-/PDF-Downloads (alles
>    GET-Routen) bisher nicht. Neue zentrale Helper `denyDemoFileDownload()` (Web) /
>    `denyDemoApiFileDownload()` (App-API) blocken jetzt ausnahmslos jede Datei -- Mitglieder-
>    Uploads, Beitrittserklärungen, Verträge, Rechnungen, SEPA-Sammellastschrift, LaTeX-Vorlagen/
>    Logos, Avatare, DSGVO-Export. Bloßes Browsen in Datei-LISTEN bleibt erlaubt.
> 2. **`/portal/files`:** eigene, bisher ungemaskte Mitgliederliste jetzt maskiert
>    (Screenshot-bestätigte Lücke).
> 3. **Postfach:** Name in "Neue Beitrittserklärung"-Meldungen + Zählernummer in "Unbekannte
>    Zählernummer"-Meldungen maskiert (neue Funktion `demoMaskNotification()`, da hier freier
>    Fließtext statt eigener Spalten).
> 4. **Support-Tickets:** Namen echter Mitglieder in Liste/Detail maskiert, eigene Demo-Tickets
>    bleiben sichtbar/anlegbar.
> 5. **Obmann-Einstellungen (`/portal/settings`):** ZVR-Nummer + EEG-Name bleiben sichtbar,
>    Kontakt-E-Mail/Kontoinhaber komplett maskiert, Gläubiger-ID/Marktpartner-ID/UID-Nummer nur
>    die ersten paar Zeichen sichtbar.
>
> Details: `CLAUDE.md`.

> **Update vom 06.09.2026 -- Aktivitätslog + Beitrittsanträge maskiert, WLAN-Info ohne Klick
> sichtbar:** reine Code-Änderung.
> 1. **Aktivitätslog:** Handelnde(r) maskiert, freier Fließtext (`beschreibung`, über 50
>    verschiedene Vorlagen im Code) komplett durch Platzhalter ersetzt statt einzeln geparst --
>    Aktion/Objekttyp/EEG/Zeitpunkt bleiben sichtbar. Markdown-Export fällt zusätzlich unter die
>    Datei-Download-Sperre.
> 2. **Beitrittsanträge:** eigene Maskierungsfunktion (eigene Spaltennamen), Unterschriftsbilder
>    komplett ausgeblendet.
> 3. **WLAN-Info:** zeigt SSID/IP/Passwort jetzt automatisch beim Öffnen der Mitglied-Detailseite
>    inline an statt erst nach einem Klick im `alert()`-Popup -- weiterhin per separatem,
>    authentifiziertem Endpunkt nachgeladen (nicht im initialen HTML), Demo-Maskierung dort
>    unverändert aktiv.
>
> Details: `CLAUDE.md`.

> **Update vom 06.09.2026 -- WLAN-Info-Popup zurückgebaut + für Demo-Zugang komplett ausgeblendet,
> Rechnungsliste maskiert:** reine Code-Änderung. Die automatische Inline-Anzeige vom Update davor
> war ein Missverständnis (Patrick wollte "Platz sparen" UND dass der Demo-Zugang nicht einmal
> sieht, DASS es die Möglichkeit gibt) -- der Button "WLAN-Info anzeigen" mit `alert()`-Popup ist
> zurück, aber für `Auth::isDemo()` komplett ausgeblendet statt nur maskiert. Zusätzlich:
> Rechnungsliste (`/portal/billing/invoices` + `.../edit`) maskiert jetzt Mitgliedernamen/E-Mail/
> IBAN/Mandatsreferenz.
>
> Details: `CLAUDE.md`.

Bei neuen DB-Migrations:
```bash
docker compose exec -T timescaledb psql -U eeg -d eeg_platform < database/migrate_YYYYMMDD.sql
```

## Container-Healthchecks & Selbstheilung

Jeder Container hat einen `healthcheck` (in `docker-compose.yml`) — `docker compose ps` zeigt für
alle `healthy`/`unhealthy` statt nur „Up", inkl. `traefik` (`--ping`) und `mqtt-subscriber`
(Heartbeat-Datei `/tmp/mqtt_subscriber_healthy`, solange die MQTT-Verbindung steht).

`scripts/health_monitor.sh` (Host-Cron): startet unhealthy/gestoppte Dienste 1–2× automatisch neu
und alarmiert bei anhaltendem Problem das Admin-Postfach (`scripts/health_alert.php`, gleiche
Microsoft-Graph-Anbindung wie der Backup-Alarm), mit 6-h-Cooldown je Dienst gegen Mail-Fluten.

Einrichten (einmalig, Host):
```bash
( crontab -l 2>/dev/null; echo "*/5 * * * * cd /opt/eeg-platform && bash scripts/health_monitor.sh >> /var/log/eeg-health.log 2>&1" ) | crontab -
```

## Bekannte Probleme & Lösungen

> **Pfad-/Mount-Übersicht:** vollständig in `docs/INFRASTRUKTUR_PFADE.md` (mit Diagramm). Bei
> „DB/Daten weg"-Symptomen zuerst dort nachsehen.

### Datenbank wirkt plötzlich leer nach Container-Neustart (PGDATA-Fallstrick, 23.07.2026)
`timescaledb-ha` legt PGDATA unter `/home/postgres/pgdata/data` ab, **nicht**
`/var/lib/postgresql/data`. Falscher Mount → PostgreSQL schrieb in flüchtigen Container-Speicher,
nach Container-Neubau „leer". Behoben: Mount `/opt/eeg/timescaledb:/home/postgres/pgdata` +
Image-Pin auf feste Digest. Nie den `:pg16`-Tag unbewusst neu ziehen. Details:
`docs/INFRASTRUKTUR_PFADE.md`.

### Traefik: "client version 1.24 is too old"
Docker Engine 29.x unterstützt nur API ≥ 1.40 → `DOCKER_API_VERSION=1.40` in der compose-Datei.

### MQTT-Broker von außerhalb des lokalen Netzes erreichen (für Mitglieder-ESP32s zuhause)
**Seit 09.08.2026 eingerichtet und funktionsfähig.** Die Domain routet nicht zum Broker
(anderer Host als der EEG-Server, nginx-Proxy terminiert ohnehin nur HTTP/HTTPS -- zufällig
dieselbe öffentliche IP wie Patricks Fritzbox, da beide an derselben Leitung hängen, aber
unterschiedliche interne Ziele). Die Weiterleitung läuft stattdessen direkt am Heimnetz-Router
vorbei an nginx/Traefik: Fritzbox (Portfreigabe 8883 → pfSense) → pfSense (NAT-Weiterleitung
8883 → 10.0.0.250) → Raspberry Pi, direkt an Mosquitto. Nur Port 8883 (TLS) extern freigegeben,
1883 bleibt intern.

> **Stolperstein bei der Einrichtung:** pfSense-NAT-Regel korrekt, Port laut Online-Port-Checker
> trotzdem von außen zu -- Ursache war eine fehlende zugehörige Freigabe unter
> Firewall → Rules → WAN (NAT übersetzt die Adresse, die Standard-Firewall blockt das Paket aber
> trotzdem ohne eigene Allow-Regel dafür). Behoben durch Neuanlegen der NAT-Regel (dabei
> automatisch mit WAN-Freigabe erzeugt). Merksatz: "NAT korrekt, Port trotzdem zu" → zuerst
> Firewall → Rules → WAN prüfen.

Von außen testen: Online-Port-Checker (yougetsignal.com, canyouseeme.org) mit der
Fritzbox-WAN-IP + Port 8883 -- nicht vom eigenen Rechner aus (kann selbst unübliche
ausgehende Ports blockieren). Alle Nachrichten live mitlesen (lokales Netz):
```bash
docker compose exec mosquitto mosquitto_sub -h localhost -t 'eeg/#' -v -u "$MQTT_USER" -P "$MQTT_PASSWORD"
```

### 404 von Traefik trotz laufendem webapp
1. **Ungültige Router-Regel (Traefik v3-Syntax)** — `Host(\`a\`, \`b\`)` ist v2-Syntax und
   lässt den Router fehlschlagen. Immer `Host(\`a\`) || Host(\`b\`)` verwenden.
   Prüfen: `docker logs traefik --tail 100 | grep -i error` und
   `docker compose config | grep "routers.*rule"`.
2. **`docker-compose.override.yml` vorhanden** mit `traefik.enable=false` (nur für lokale
   Entwicklung gedacht — auf Produktion löschen).
3. **Domain in `.env` falsch** → `DOMAIN` prüfen, dann `docker compose up -d --force-recreate webapp`.

### www-Subdomain hinzufügen
Traefik-Seite ist im Repo bereits fertig konfiguriert. Auf dem **separaten
nginx-Proxy-Host (10.0.0.144)** NICHT `certbot --nginx --expand` direkt verwenden — das
schreibt automatisch in die vhost-Datei und hat schon einmal den `server_name`-Block
zerlegt/dupliziert (mehrere Zertifikats-Lineages, Hauptdomain verlor ihr Zertifikat).
Stattdessen `certbot certonly --nginx --cert-name stromfueralle.at --expand -d ... -d www...`
(rührt die nginx-Config nicht an) und die vhost-Datei danach selbst schreiben.
Details/vollständiges Skript: siehe `CLAUDE.md` im Repo.

### portal-Subdomain für Login (ausstehend, Stand 2026-07-15)
Anmelden-Button verlinkt jetzt auf `portal.stromfueralle.at/portal/login`. Traefik-Routing
steht bereits, es fehlt noch die SSL-Terminierung auf 10.0.0.144 (gleiches Vorgehen wie bei
www oben, zusätzlich `-d portal.stromfueralle.at` im certbot-Aufruf + eigener server{}-Block
in der vhost-Datei). Details/vollständiges Skript: siehe `CLAUDE.md` im Repo.

### SSL-Zertifikat fehlt/ungültig
Meist: mehrere Zertifikats-Lineages für dieselbe Domain (`stromfueralle.at`,
`stromfueralle.at-0001`, `www.stromfueralle.at`) — Diagnose mit `sudo certbot certificates`,
Konsolidierung auf eine Lineage, dann `sudo certbot delete --cert-name <name>` für die
überzähligen (erst nach Verifikation!).

### Raspberry Pi hängt sich auf (Ping ja, SSH nein)
Klassischer I/O-Stall — meist SD-Karte am Ende, RAM/Swap voll, Unterspannung oder volle Platte.
Vollständige Diagnose + Selbstheilung per Hardware-Watchdog (Pi rebootet sich bei Einfrieren
selbst): `docs/RASPBERRY_STABILITAET.md`. Bereits abgesichert: `restart: always` auf allen
Containern + Docker-Log-Rotation (`x-logging` in `docker-compose.yml`).

### Datei-/Profilbild-Upload: 500 im Browser (Stand 16.07.2026, gelöst)
Jeder Upload brach ab, `docker compose logs webapp` (nur Access-Log) zeigte nichts. Echte
Ursache erst sichtbar im nginx-**Fehler**-Log IM Container:
```bash
docker compose exec webapp cat /var/log/nginx/error.log
```
→ `open() "/var/lib/nginx/tmp/client_body/..." failed (13: Permission denied)`. Grund:
`nginx.conf` setzt `user www-data;`, aber Alpines nginx-Paket legt `/var/lib/nginx` SAMT
`tmp/*` mit dem eigenen `nginx`-User und Modus 750 an. Kleine Requests (Login) brauchen dieses
Zwischenspeicher-Verzeichnis nie, jeder Datei-/Profilbild-Upload (sobald der Body den
nginx-Puffer übersteigt) schon -- nginx scheitert dabei schon vor PHP-FPM und liefert sein
eigenes 500 aus.
**Erster Fix-Versuch unvollständig:** Nur `tmp/` selbst zu chownen reicht nicht -- der
Elternordner `/var/lib/nginx` blieb `nginx:nginx` mit Modus 750 (keine Rechte für "andere"),
wodurch `www-data` gar nicht hineinkonnte (Linux braucht Ausführungsrecht auf JEDEN
Pfad-Bestandteil). Erklärt das trügerische Verhalten: kleine Uploads (Body bleibt unter dem
nginx-Puffer, `client_body/` nie gebraucht) funktionierten, größere scheiterten weiter.
Fix in `webapp/Dockerfile` (bereits im Repo) -- chownt den ganzen Elternordner:
```dockerfile
RUN chown -R www-data:www-data /var/lib/nginx
```
Braucht einen echten Rebuild (`docker compose up -d --build`), reines `up -d` reicht nicht.
Zwei Sackgassen unterwegs, die NICHT die Ursache waren: leeres `docker compose logs traefik`
(normal, kein Accesslog konfiguriert) und fehlendes `proxy_http_version 1.1;` im nginx-Proxy auf
10.0.0.144 (sinnvoller Fix, hat dieses Problem aber nicht behoben). Details: siehe `CLAUDE.md`.

## Claude-Sitzungslog (Selbstdokumentation)

Jede Claude-Sitzung (Claude Code / Claude Chat / Cowork) dokumentiert am Ende Datum,
verwendetes Modell, den **ursprünglichen Prompt möglichst wörtlich** (Patrick braucht das für
die Diplomarbeit-Dokumentation) sowie zusätzlich den professionell zusammengefassten Auftrag:
Claude Code schreibt in `obsidian/Claude-Sitzungslog.md` im Repo (wird in den Vault
gespiegelt), Cowork/Chat direkt in den Vault unter `eeg-platform-notes/logs/JJJJ-MM-TT.md`.
Details: Abschnitt „Selbstdokumentation" in `CLAUDE.md`.
