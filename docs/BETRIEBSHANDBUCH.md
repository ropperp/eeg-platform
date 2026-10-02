# Betriebshandbuch -- Update-Historie

Historische Sammlung aller "Einmalig nach dem Update vom ..."-Anleitungen der EEG-Plattform:
pro Feature/Fix der genaue Schritt (Migration, Setup-Skript, Cron-Eintrag), der NACH einem
`git pull` einmalig nötig war/ist, chronologisch wie ursprünglich in `CLAUDE.md` entstanden
(am 02.10.2026 ausgelagert, weil die Datei auf über 2000 Zeilen angewachsen und dadurch
unübersichtlich geworden war). `CLAUDE.md` bleibt die kompakte Architektur-Referenz für einen
neuen Chat-Kontext.

**Für ein bereits laufendes, aktuelles System relevant:** nur der jeweils NEUESTE Eintrag (am
Ende dieser Datei) beschreibt noch ausstehende Schritte -- alle älteren sind auf Patricks
Produktivserver längst angewendet und dienen nur noch als Nachschlagewerk (z. B. bei einer
Neuinstallation von einem alten Tag/Backup aus, oder um nachzuvollziehen, WARUM ein Schritt
nötig war).

## Update (laufendes System)

```bash
cd /opt/eeg-platform
git pull origin main
docker compose up -d --build
```

> **Einmalig nach dem Update vom 25.09.2026** (Webapp-Router jetzt Traefik-File-Provider
> statt Docker-Labels, siehe Abschnitt "Webapp-Router" weiter oben): kein Host-Verzeichnis
> anzulegen, `docker/traefik/dynamic.yml` liegt schon im Repo und wird einfach mitgemountet.
> `docker compose up -d --build` reicht -- Traefik erkennt die neue `command:`-Zeile
> (`--providers.file.filename=...`) und den neuen Mount automatisch beim Neustart des
> `traefik`-Containers. Kurz verifizieren, dass die Seite danach weiterhin normal erreichbar
> ist (`curl -H "Host: stromfueralle.at" http://localhost/`).

> **Einmalig nach dem Update vom 14.07.2026** (Verträge/Dateien-Migration): Das neue
> Storage-Volume muss auf dem Host existieren, BEVOR `docker compose up -d --build` läuft,
> sonst legt Docker es automatisch mit root-Rechten an und PHP (www-data, UID 82 im
> Alpine-Image) kann nicht mehr in `storage/uploads` schreiben:
> ```bash
> sudo mkdir -p /opt/eeg/webapp-storage/{uploads,pdfs}
> sudo chown -R 82:82 /opt/eeg/webapp-storage
> ```

> **Einmalig nach dem Update vom 16.07.2026** (Platform-Admin-Dateiverwaltung für
> LaTeX-Vorlagen, `/admin/templates`): gleiches Muster wie oben, diesmal für
> `/opt/eeg/latex-templates` (wird sowohl von `webapp` als auch von `latex-service` gemountet):
> ```bash
> sudo mkdir -p /opt/eeg/latex-templates
> sudo chown -R 82:82 /opt/eeg/latex-templates
> ```
> `latex-service` läuft als root und darf trotz `82:82`-Eigentümer weiterhin schreiben --
> `82:82` ist nur nötig, damit `webapp` (www-data) darüber Uploads speichern kann. Bleibt das
> Verzeichnis beim ersten Start leer, kopiert `latex-service` (siehe
> `latex-service/docker/entrypoint.sh`) einmalig seine mitgelieferten Standard-Vorlagen hinein.

> **Einmalig nach dem Update vom 30.07.2026** (MQTT-Broker mit TLS + Zugangsdaten statt offen/
> anonym): Mosquitto verlangt jetzt `allow_anonymous false` + ein Zertifikat für Port 8883 --
> ohne beides startet der Container gar nicht (fehlende Dateien in `mosquitto.conf`). Einmalig:
> ```bash
> ./scripts/mqtt_secure_setup.sh
> ```
> Erzeugt ein selbstsigniertes Zertifikat unter `/opt/eeg/mosquitto/certs` (10 Jahre gültig,
> ESP32-Geräte prüfen es nicht -- `setInsecure()` --, verschlüsselt die Verbindung aber trotzdem),
> generiert `MQTT_USER`/`MQTT_PASSWORD` in `.env`, schreibt die Passwort-Datei
> (`/opt/eeg/mosquitto/passwd`) und startet `mosquitto` + `mqtt-subscriber` neu. **Wichtig:**
> Danach verliert JEDES bereits im Feld laufende ESP32-Gerät die Verbindung, bis im eigenen
> `/config`-Formular (Zahnrad-Symbol) Benutzername/Passwort nachgetragen werden (Port 8883
> empfohlen, sobald der Broker auch von außerhalb des lokalen Netzes erreichbar sein soll --
> aktuell nur im 10.0.0.0/24-Netz, siehe Abschnitt weiter unten zu externem MQTT-Zugriff).
> Bei einer echten Neuinstallation ruft `scripts/setup.sh` dieses Skript automatisch mit auf,
> nichts weiter zu tun.

> **Einmalig nach dem Update vom 25.08.2026** (automatischer EDA-Postfach-Import): der
> monatliche EDA-Energiedatenreport kann jetzt automatisch importiert werden, statt ihn von
> Hand über `/portal/eda/upload` hochzuladen -- `EdaAutoImporter.php` liest ein zentrales
> Postfach über Microsoft Graph aus, lädt die Exportdatei herunter und übergibt sie an
> `eda-parser/parser.py` (Community-Zuordnung über die Marktpartner-ID im Dateinamen,
> z. B. `RC108175_...`). Einmalig einzurichten, alles über die Platform-Admin-Oberfläche außer
> dem Cron-Eintrag:
> 1. **Shared Mailbox `eda@stromfueralle.at`** in Microsoft 365 anlegen (wie
>    `noreply@stromfueralle.at`, siehe `docs/vorlagen/Anleitung_Mailversand_Azure_GraphAPI.md`).
> 2. **Zusätzliche Anwendungsberechtigung `Mail.Read`** (Application Permission, Admin-Zustimmung
>    erteilen) für dieselbe Azure-App-Registrierung `stromfueralle-mailer` -- sie hat bereits
>    `Mail.Send` für den Mailversand, `Mail.Read` erlaubt ihr zusätzlich, JEDES Postfach im
>    Tenant zu lesen (genau wie bei `Mail.Send` deshalb bewusst ein eigenes, dediziertes
>    Postfach statt eines persönlichen).
> 3. Im EDA-Anwenderportal einen eigenen Export-User anlegen, dessen Login-E-Mail (bzw. dessen
>    Benachrichtigungsadresse) auf `eda@stromfueralle.at` zeigt -- **das eigentliche Anfordern/
>    Auslösen des Exports im Portal bleibt vorerst ein manueller Schritt** (Login + Klick auf
>    "Export"), nur das Abholen des danach gemailten Downloads passiert automatisch.
> 4. Platform-Admin → Einstellungen → Abschnitt "EDA-Automatik": Postfachadresse
>    eintragen (Feld leer = Automatik aus). Bei jeder EEG (Platform-Admin → EEG bearbeiten)
>    optional die EDA-Login-Zugangsdaten hinterlegen (nur zur zentralen Aufbewahrung,
>    verschlüsselt wie WLAN-Passwörter -- nicht für einen automatisierten Login).
> 5. Cron-Eintrag auf dem Host (einmal täglich reicht, EDA-Exporte fallen ohnehin nur monatlich an):
>    ```bash
>    ( crontab -l 2>/dev/null; echo "0 7 * * * cd /opt/eeg-platform && docker compose exec -T webapp php < scripts/eda_auto_import.php >> /var/log/eeg-eda-import.log 2>&1" ) | crontab -
>    ```
> Zum Testen ohne auf den Cron zu warten: Platform-Admin → Einstellungen → "Jetzt
> prüfen". Kann eine Mail nicht automatisch verarbeitet werden (z. B. Community nicht
> zuordenbar, Download schlägt fehl), bleibt sie ungelesen im Postfach und es geht eine
> Alarm-Mail an die Backup-Alarm-Adressen -- Fallback bleibt in jedem Fall der manuelle Upload
> über `/portal/eda/upload`.
>
> **EDA-Exportmail-Format verifiziert (Patrick, 13.08.2026, anhand einer echten Mail):**
> Absender `no-reply@eda.at`, Betreff `EDA Portal – Energiedatenreport RC108175` (Marktpartner-ID
> steht auch im Betreff, nicht nur im Dateinamen), kein Anhang -- stattdessen ein signierter,
> 7 Tage gültiger Download-Link im HTML-Mailtext auf
> `https://prod-api.eda-portal.at/exports/download/<uuid>?expires=...&signature=...`.
> `EdaAutoImporter.php` entsprechend angepasst: prüft den Absender (alles andere im Postfach wird
> ignoriert statt fälschlich als fehlgeschlagener Import behandelt zu werden), sucht gezielt nach
> einem Link auf diese Export-Domain statt dem ersten beliebigen `href` in der Mail, gleicht die
> Marktpartner-ID aus Dateiname UND Betreff gegeneinander ab, und erzwingt eine `.xlsx`-Endung
> beim Speichern (der Link selbst enthält nur eine UUID, keine erkennbare Dateiendung).
> **Live-Download bestätigt (13.08.2026):** beim ersten echten Auto-Import-Lauf hat die
> komplette Kette funktioniert -- Absendererkennung, Download-Link-Suche, Herunterladen OHNE
> Portal-Session, Dateibenennung, Community-Zuordnung, Parser-Start. Der Lauf endete zwar mit
> einem Fehler, aber einem inhaltlichen (siehe "Erneuter Import" unten), nicht am Download
> selbst -- die zuvor offene Frage ist damit positiv beantwortet, kein Login-Schritt nötig.
>
> **Erneuter Import für einen Zeitraum mit bereits vorhandenen Daten** ("Duplikat"): wird seit
> demselben Tag automatisch überschrieben, SOLANGE noch keine Rechnungen für den Zeitraum
> verschickt wurden (kein Abrechnungslauf mit status 'released'/'done') -- z. B. wenn zunächst
> nur L3-Datenqualität vorlag und ein späterer Export bessere Werte liefert. Ist der Zeitraum
> schon abgerechnet, bleibt es beim harten Fehler. Siehe `_billing_period_finalized()` in
> `eda-parser/parser.py`.

> **Einmalig nach dem Update vom 10.08.2026** (MQTT-Zugangsdaten in der Plattform sichtbar/
> änderbar, seit dem gleichen Tag auch per Knopfdruck automatisch angewendet): bisher lagen
> `MQTT_USER`/`MQTT_PASSWORD` ausschließlich in `.env` auf dem Server (zufälliger 24-stelliger
> Hex-String, nirgends auf der Plattform selbst einsehbar). Jetzt gibt es unter Platform-Admin →
> Einstellungen → "MQTT-Zugangsdaten" ein Formular (inkl. "einfaches Passwort
> vorschlagen"-Button) -- "Speichern & anwenden" trägt den Wunschwert in die DB
> (`platform_mqtt_config`, `pending_apply=true`) ein. Die Webapp kann Docker/Dateien auf dem Host
> nicht direkt anfassen, deshalb übernimmt ein Host-Cron-Job das eigentliche Anwenden:
> ```bash
> # einmalig einrichten, z.B. jede Minute:
> ( crontab -l 2>/dev/null; echo "* * * * * cd /opt/eeg-platform && bash scripts/mqtt_apply_pending.sh >> /var/log/eeg-mqtt-apply.log 2>&1" ) | crontab -
> ```
> `scripts/mqtt_apply_pending.sh` prüft `pending_apply`, ruft bei Bedarf
> `scripts/mqtt_secure_setup.sh --apply` auf (liest Benutzername/Passwort aus der DB, schreibt
> sie nach `.env`, erzeugt die Mosquitto-Passwort-Datei neu, startet `mosquitto` +
> `mqtt-subscriber` neu) und markiert die Änderung danach in der DB als erledigt
> (`applied_at`) -- die Plattform-Oberfläche zeigt diesen Status an. Ohne diesen Cron-Job bleibt
> eine gespeicherte Änderung als "wird in Kürze angewendet" hängen; manueller Fallback (auch
> ohne eingerichteten Cron) bleibt `./scripts/mqtt_secure_setup.sh --apply` direkt auf dem
> Server. Wie bei jeder Änderung der MQTT-Zugangsdaten: danach verliert jedes bereits im Feld
> laufende ESP32-Gerät die Verbindung, bis im eigenen `/config`-Formular das neue Passwort
> nachgetragen wird.

> **Einmalig nach dem Update vom 17.08.2026** (OWASP-Audit-Fixes -- RLS greift jetzt tatsächlich,
> TOTP-Secrets verschlüsselt, Brute-Force-Schutz, CSRF-Schutz, Security-Header,
> Passwort-Leak-Check): mehrere Punkte brauchen je ein einmaliges Setup-Skript, das nicht
> automatisch beim `git pull && docker compose up -d --build` mitläuft. **Genaue Reihenfolge,
> Begründung und Garantie "kein Datenverlust, keine Neu-Registrierung" ausführlich in
> `docs/DEPLOY_OWASP_AUDIT.md`** -- hier nur die Kurzfassung:
> ```bash
> cd /opt/eeg-platform
> git pull origin main
> docker compose exec -T timescaledb psql -U eeg -d eeg_platform < database/migrate_20260822.sql
> ./scripts/redis_secure_setup.sh
> ./scripts/db_runtime_role_setup.sh
> docker compose up -d --build
> docker compose exec -T webapp php < scripts/migrate_encrypt_totp_secrets.php
> ```
> **Reihenfolge wichtig, nicht vertauschen** (Vorfall 17.08.2026, Patrick komplett ausgesperrt):
> `redis_secure_setup.sh`/`db_runtime_role_setup.sh` MÜSSEN vor `docker compose up -d --build`
> laufen, nicht danach. Grund: das neue `docker-compose.yml` bindet
> `/opt/eeg/redis-config/redis.conf` als Datei in den redis-Container -- existiert diese Datei
> auf dem Host noch nicht, wenn `docker compose up` den redis-Container zum ersten Mal mit der
> neuen Compose-Datei startet, legt Docker für den Bind-Mount automatisch ein leeres
> **Verzeichnis** an diesem Pfad an (Standard-Docker-Verhalten, gleiches Muster wie beim
> Storage-Verzeichnis oben). Redis kann seine Konfiguration dann nicht mehr lesen ("Redis
> connection not available" im webapp-Log), jede Sitzung schlägt fehl, JEDER Login -- auch nach
> erneutem Anmeldeversuch/Browser-Daten-löschen, weil das Problem rein serverseitig ist -- landet
> auf der "Sitzung abgelaufen"-Seite. `redis_secure_setup.sh` legt die Datei selbst an, BEVOR es
> intern `docker compose up -d --force-recreate redis webapp` aufruft -- läuft es dagegen NACH
> einem bereits erfolgten `docker compose up -d --build`, ist der Pfad auf dem Host schon als
> Verzeichnis "verseucht" und das Skript kann dort keine Datei mehr schreiben.
>
> **Fix, falls das schon passiert ist:**
> ```bash
> docker compose stop redis
> sudo rm -rf /opt/eeg/redis-config/redis.conf   # der fälschlich angelegte Ordner
> ./scripts/redis_secure_setup.sh                # schreibt die Datei jetzt korrekt + startet neu
> ```
> Kein Datenverlust dabei -- nur alle gerade aktiven Sitzungen müssen sich einmal neu anmelden.
>
> Jeder einzelne Schritt läuft bis zu seiner Ausführung im bisherigen (unsicheren) Fallback
> weiter -- keine Downtime, keine Reihenfolge-Falle, siehe Tabelle in der verlinkten Doku. Bei
> einer **Neuinstallation** ruft `scripts/setup.sh` `redis_secure_setup.sh` und
> `db_runtime_role_setup.sh` automatisch mit auf (gleiches Muster wie `mqtt_secure_setup.sh`).

> **Einmalig nach dem Update vom 03.09.2026** (Push-Benachrichtigungen für die iOS-App --
> Obmann/Admin bei neuem Postfach-Element, Mitglied bei neuer Rechnung, Mitglied bei
> Einspeisung über selbst gesetzter Schwelle mit Hysterese, Patrick 19.08.2026: "ja leg mit den
> Push-Benachrichtigungen los"): Datenbank-Trigger füllen `push_notifications_queue`
> (`database/migrate_20260903.sql`), `Push.php` leert sie über Apples APNs (HTTP/2 + ES256-JWT,
> siehe Klassendoc). Braucht zusätzlich das PHP-`curl`-Modul (jetzt im `webapp`-Image, da
> PHPs eingebauter `http://`-Wrapper kein HTTP/2 kann) -- kommt automatisch mit dem nächsten
> `docker compose up -d --build`, kein Extra-Schritt.
> ```bash
> cd /opt/eeg-platform
> git pull origin main
> docker compose exec -T timescaledb psql -U eeg -d eeg_platform < database/migrate_20260903.sql
> docker compose up -d --build
> ( crontab -l 2>/dev/null; echo "* * * * * cd /opt/eeg-platform && docker compose exec -T webapp php < scripts/send_pending_push.php >> /var/log/eeg-push.log 2>&1" ) | crontab -
> ```
> **Ohne Apples echte Zugangsdaten bleibt die Warteschlange liegen, sonst passiert nichts
> Schlimmes** -- `Push::sendPending()` prüft `platform_apns_config` zuerst und rührt die Queue
> gar nicht an, wenn dort noch nichts hinterlegt ist (kein Fehlerspam, einfach nichts zu tun).
> Patrick muss dafür einmalig in seinem Apple-Developer-Account einen APNs-Auth-Key (.p8)
> erzeugen (Team-ID, Key-ID, Bundle-ID der iOS-App, Inhalt der .p8-Datei) und über
> Platform-Admin → Einstellungen → "Push-Benachrichtigungen" (bzw. direkt
> `POST /api/v1/admin/settings/apns`) hinterlegen -- sobald das steht, greift der nächste
> Cron-Lauf automatisch, kein Neustart nötig. Test ohne auf eine echte Auslösung zu warten:
> `POST /api/v1/admin/settings/apns/test` (erfordert vorher ein über
> `POST /api/v1/push/register` registriertes eigenes Gerät).

> **Einmalig nach dem Update vom 04.09.2026** (Viertelstunden-Verbrauchsdiagramm für Mitglieder
> -- Patrick, 03.09.2026: "wie viel sie viertelstündlich verbrauchen und wie viel davon
> energiegemeinschaftlich genutzt wird"): nur die Migration nötig, sonst nichts (kein neues
> Python-Paket, `openpyxl`/`pandas` sind schon da):
> ```bash
> cd /opt/eeg-platform
> git pull origin main
> docker compose exec -T timescaledb psql -U eeg -d eeg_platform < database/migrate_20260904.sql
> docker compose up -d --build
> ```
> Datenquelle ist ein **zweiter, eigener EDA-Export-Typ** ("Energiedaten"-Sheet, echte
> Viertelstundenwerte) neben dem bisherigen monatlichen Energiedatenreport -- beide werden im
> EDA-Anwenderportal separat exportiert, hat mit der Abrechnung nichts zu tun (eigene Tabelle
> `eda_interval_data`, siehe Kommentar in der Migration). Unter Platform-Admin bzw.
> Obmann-Bereich → "EDA-Daten importieren" gibt es dafür jetzt eine zweite Upload-Karte inkl.
> Anzeige "Daten vorhanden bis ..., es fehlen X Tage" -- da EDA maximal einen Monat pro Export
> erlaubt, aber auch kürzere/überlappende Zeiträume liefert, einfach alle paar Tage den
> aktuellen Ausschnitt hochladen (ein überschneidender Zeitraum wird automatisch überschrieben,
> nicht wie beim Monatsimport als Duplikat abgelehnt). Mitglieder sehen das Diagramm unter
> "Mein Verbrauch" im Portal bzw. in der App (`GET /api/v1/consumption/interval`).

> **Einmalig nach dem Update vom 05.09.2026** (Demo-Login für Präsentation/Diplomarbeit-Review --
> Patrick, 05.09.2026: "ich möchte schon bitte gerne einen einzigen Login haben ... es sollen
> bitte schon für einen Login alle 4 Rollen sein"): EIN Login, umschaltbar zwischen
> Plattform-Admin, Obmann und ZWEI unabhängig wählbaren, komplett fiktiven Mitglied-Identitäten
> ("Verbraucher 1"/"Einspeiser 1") in derselben EEG -- dafür musste `user_roles` erstmals mehr
> als eine 'member'-Zeile je (community_id, user_id) erlauben (neue Spalte `member_id`, siehe
> Kommentar in der Migration). Der Login ist über `users.is_demo` PLATTFORMWEIT UND
> ROLLENÜBERGREIFEND schreibgeschützt (jeder POST wird zentral in `Router.php` bzw.
> `AppApiAuth::requireAppAuth()` abgelehnt, außer dem Rollenwechsel selbst) -- unabhängig davon,
> welche Rollen ihm zugewiesen sind, kann er nirgends etwas verändern. `members.is_demo`
> schließt die beiden fiktiven Mitglied-Identitäten zusätzlich explizit von echten
> Abrechnungsläufen (`Billing.php`) und der Mitgliederstatistik im Obmann-Dashboard aus.
> ```bash
> cd /opt/eeg-platform
> git pull origin main
> docker compose exec -T timescaledb psql -U eeg -d eeg_platform < database/migrate_20260905.sql
> docker compose up -d --build
> ./scripts/create_demo_login.sh        # fragt E-Mail + Passwort interaktiv ab, KEINE Rollen
> docker compose exec -T webapp php < scripts/create_demo_members.php   # legt "Verbraucher 1"/
>                                                                        # "Einspeiser 1" an
> ```
> `create_demo_members.php` sucht die echten Mitglieder Stefanie Schwaiger und Daniel Ropper,
> kopiert deren aktive Zählpunkte samt kompletter EDA-Messreihen (`eda_measurements`,
> `eda_interval_data`) auf zwei fiktive Mitglied-Datensätze in derselben EEG -- gleiche
> Verbrauchszahlen fürs Diagramm, aber neuer Name, neue (mit "DEMO-" statt "AT" beginnende,
> garantiert nie mit einem echten EDA-Import kollidierende) Zählpunktnummer, keine echte
> Adresse/Telefonnummer/Geburtsdatum (alles frei erfunden, nicht von den echten
> Vorlage-Mitgliedern abgeleitet -- Patrick, 05.09.2026: "damit was personenbezogen sein kann,
> unkennbar oder unlesbar ist"). Danach im Platform-Admin-Backoffice ("Benutzer verwalten") den
> neu angelegten Demo-Login öffnen und unter "Rolle hinzufügen" alle vier Rollen zuweisen (bei
> `member` jeweils die passende Mitglied-Identität im neuen Feld "Mitglied-Identität" wählen).
> `create_demo_login.sh` legt den Login nur einmalig an (E-Mail bereits vergeben -> Passwort wird
> aktualisiert, Rollen bleiben unangetastet).
>
> **`create_demo_members.php` ist ein SYNC, kein Einmal-Skript** (Patrick, 05.09.2026: "Die
> Daten sollen immer gleich sein mit den aktuell gültigen Daten"): der Mitglied-Datensatz selbst
> (Name/Adresse/Kundennummer/member_id) wird nur beim allerersten Lauf angelegt und danach
> unverändert wiederverwendet (sonst würden Rollenzuweisungen im Admin-Backoffice, die auf die
> member_id zeigen, bei jedem Lauf ungültig). Die Zählpunkte + ALLE Messdaten
> (`eda_measurements`, `eda_interval_data`) werden dagegen bei JEDEM Lauf komplett gelöscht und
> frisch aus dem aktuellen Stand des jeweiligen Vorlage-Mitglieds neu kopiert -- damit "Verbraucher
> 1"/"Einspeiser 1" nach jedem neuen EDA-Import automatisch aktuell bleiben. Damit das ohne
> manuelles Nachtriggern gilt (unabhängig davon, ob die Vorlage-Daten per Auto-Import oder
> manuellem Upload aktualisiert wurden), als täglichen Cron-Job einrichten:
> ```bash
> ( crontab -l 2>/dev/null; echo "30 7 * * * cd /opt/eeg-platform && docker compose exec -T webapp php < scripts/create_demo_members.php >> /var/log/eeg-demo-sync.log 2>&1" ) | crontab -
> ```
> (bewusst 7:30 Uhr, kurz NACH dem täglichen EDA-Auto-Import-Cron um 7:00 Uhr, siehe oben --
> damit ein frisch importierter Tag noch am selben Morgen in die Demo-Daten übernommen wird).
>
> **Wichtig -- "richtiger DEMO-Acc" (Patrick, 05.09.2026):** in ALLEN vier Rollen sind
> ausnahmslos alle Funktionen, Felder und Buttons sichtbar, nichts ist ausgeblendet -- die
> Read-only-Sperre (`Auth::isDemo()`) greift ausschließlich beim tatsächlichen Absenden eines
> Formulars (POST) und zeigt dann eine freundliche Hinweisseite statt eines rohen Fehlers, sonst
> verhält sich die Oberfläche wie bei jedem echten Account. `create_demo_members.php` befüllt
> seither auch Kundennummer, IBAN/BIC/Kontoinhaber (klar erkennbare Platzhatzer-IBAN --
> unbedenklich, da `is_demo`-Mitglieder nie eine `invoices`-Zeile bekommen), Stromlieferant und
> alle Beitritts-Zustimmungen, damit Mitglied-Detailseiten vollständig statt leer wirken.
> Bewusst NICHT vorbelegt: der Vertragsstatus (`contract_bezug_status`/
> `contract_einspeisung_status` bleiben `'none'`) -- ein "signierter" Vertrag ohne echt erzeugte
> PDF-Datei würde beim Ansehen nur einen kaputten Download-Link zeigen. Wer für die Präsentation
> auch einen fertig signierten Beispielvertrag zeigen will: einmalig über den EIGENEN echten
> Obmann-Account (nicht den Demo-Login, der ist read-only) für "Verbraucher 1"/"Einspeiser 1"
> einen Vertrag erzeugen/signieren -- der Demo-Login kann ihn danach ganz normal ansehen.

> **Einmalig nach dem Update vom 06.09.2026** (Live-ESP-Spiegelung für die Demo-Mitglieder --
> Patrick, 05./06.09.2026: "du sollst bitte die Echtzeit-Werte zum Einspeisen von Daniel Ropper
> synchronisieren und die Echtzeit-Daten von Stefanie Schwaiger für den Verbraucher verwenden.
> Aber bitte in Echtzeit."): "Verbraucher 1"/"Einspeiser 1" haben keine eigene ESP32-Hardware --
> statt einer synthetischen Simulation (bewusst abgelehnt, siehe Konversation) spiegelt ein
> DB-Trigger auf `esp_measurements` jetzt JEDE neue Live-Messung des jeweiligen echten
> Vorlage-Zählpunkts sofort (kein Polling, keine Verzögerung) auch auf den zugehörigen
> Demo-Zählpunkt -- echte Live-Daten, nur unter fiktiver Identität. `mqtt-subscriber` schreibt
> ca. alle 5s eine neue Zeile (siehe `migrate_20260903.sql`), die Demo-Kachel "Aktuelle Leistung"
> bewegt sich dadurch im selben Takt wie beim echten Vorlage-Mitglied. Der Trigger zieht dabei
> auch `esp_online`/`esp_last_seen_at`/`meter_reachable` am Demo-Zählpunkt mit, wodurch er auch
> in der "ESP online: X von Y"-Zählung normal mitzählt (bisher blieb er dort unsichtbar, weil
> `esp_last_seen_at` nie gesetzt wurde -- kein Fehler, aber jetzt eben ein "online" wirkender
> Zählpunkt statt einem, der wie "noch nie installiert" aussieht).
> ```bash
> cd /opt/eeg-platform
> git pull origin main
> docker compose exec -T timescaledb psql -U eeg -d eeg_platform < database/migrate_20260906.sql
> docker compose exec -T webapp php < scripts/create_demo_members.php
> ```
> Kein `docker compose up -d --build` nötig (reine DB-Änderung, kein Code in `webapp`/
> `mqtt-subscriber` geändert). Der zweite Befehl trägt bei den beiden Demo-Zählpunkten
> `mirror_source_metering_point_id` auf den jeweiligen echten Vorlage-Zählpunkt ein -- ohne das
> hätte der neue Trigger nichts zu spiegeln. Ab dann läuft die Spiegelung von selbst weiter,
> unabhängig vom täglichen Sync-Cron oben (der ist nur für die EDA-/Abrechnungsdaten nötig).

> **Stolperstein bei der Rollenzuweisung des Demo-Logins (Patrick, 06.09.2026, per Screenshot):**
> im Platform-Admin-Backoffice (`/admin/users/:id` -> "Rolle hinzufügen") erscheint das Feld
> "Mitglied-Identität" erst, NACHDEM in der Rolle-Auswahl "member" ausgewählt wurde -- leicht zu
> übersehen. Wird eine `member`-Rolle OHNE dieses Feld gespeichert, landet sie in `user_roles`
> mit `member_id = NULL` und führt für den Demo-Login ins Leere (kein `members`-Datensatz mit
> `user_id` = Demo-Login, siehe migrate_20260905.sql) -- "Aktuelle Rollen" zeigt dann `member`
> mit Mitglied "--". Das Formular hat seit diesem Update einen Hinweistext dazu bekommen.
> Bereits falsch angelegte Rollen reparieren (räumt eine `member`-Rolle ohne Mitglied-Identität
> auf und trägt stattdessen "Verbraucher 1"/"Einspeiser 1" korrekt ein, sicher erneut ausführbar):
> ```bash
> docker compose exec -T webapp php < scripts/assign_demo_member_roles.php
> ```
> Danach beim Demo-Login einmal neu anmelden (bzw. neu laden, falls gerade eingeloggt), damit die
> Session die neuen Rollen sieht.

> **PII-Maskierung für Obmann/Admin im Demo-Login (Patrick, 06.09.2026: "wie sieht es mit dem
> read only mit ***-verpixelten/unkennbar gemachten Daten bei Obmann und Admin-Acc aus? [...]
> ich möchte das die verwaltung als obmann und admin auch herzeigen können"):** reine
> Code-Änderung, keine Migration/kein Skript nötig -- mit dem nächsten `git pull && docker
> compose up -d --build` aktiv. `demoMask*()` in `functions.php` maskiert personenbezogene
> Felder ECHTER Mitglieder/Logins (NIE die beiden fiktiven Demo-Mitglieder selbst), sobald
> `Auth::isDemo()` aktiv ist -- Vorname: erste 4 Buchstaben + Punkte, Nachname/E-Mail/Adresse/
> IBAN/Zählpunktnummer: komplett unkenntlich, Telefonnummer: nur die letzten 4 Stellen sichtbar,
> Geburtsdatum: komplett maskiert, Profilbild: Default-Avatar statt echtem Foto. Eingebaut in die
> Kernseiten der "Verwaltung": Obmann-Mitgliederliste (`/portal/members`) + Mitglied-Detailseite
> (`/portal/members/:id`), Platform-Admin-Nutzerliste (`/admin`) + Nutzerdetailseite
> (`/admin/users/:id`, inkl. Mitglied-Identität-Auswahlfeld) + EEG-Mitgliederliste
> (`/admin/communities/:id`). Die damals noch offenen Lücken (Aktivitätslog, Beitrittsanträge,
> Postfach, Support-Tickets, Rechnungsliste, Mitglied-Bearbeiten-Formular) sind in späteren
> Updates desselben Tages (siehe die Einträge weiter unten in diesem Abschnitt) alle geschlossen
> worden -- `demoMaskAuditLog()`, `demoMaskApplication(s)()`, `demoMaskNotification(s)()`,
> `demoMaskSupportMessages()`, `demoMaskMembers()` in der Rechnungsliste, sowie `denyDemoPage()`
> für `/portal/members/:id/edit`.

> **Stolperstein Pre-Launch-Popup (Patrick, 06.09.2026, per Screenshot):** ein Demo-Login saß
> beim allerersten Aufruf der Mitglied-Ansicht ("Verbraucher 1"/"Einspeiser 1") hinter dem
> Pre-Launch-Hinweis-Popup ("Willkommen! Ein kurzer Hinweis...") fest -- der "Gelesen"-Button
> dahinter ist ein POST (`/portal/ack-prelaunch`) und wurde von der Read-only-Sperre blockiert,
> landete auf der "Nur Lesezugriff"-Seite statt das Popup zu schließen; da der dahinterliegende
> Seiteninhalt bewusst per `pointer-events:none` gesperrt ist, kam man so gar nicht mehr weiter.
> Behoben: das Popup wird für Demo-Logins jetzt grundsätzlich gar nicht mehr angezeigt (der
> Hinweistext richtet sich an echte, neue Mitglieder und ist für eine Präsentation irrelevant),
> zusätzlich steht `/portal/ack-prelaunch` als zweite, folgenlose Ausnahme neben
> `/portal/switch-role` auf der Demo-Erlaubnisliste in `Router.php` (falls es je doch auftaucht).
> Reine Code-Änderung, kein Migrations-/Setup-Skript nötig -- mit dem nächsten `git pull &&
> docker compose up -d --build` aktiv.

> **Drei Nachbesserungen vom 06.09.2026:**
>
> **1. Energiefluss doppelt gezählt (Patrick: "es dürfen die Daten nicht doppelt in dem
> Energiefluss angezeigt werden"):** die Live-ESP-Spiegelung vom selben Tag (siehe oben) hat
> einen Community-weiten Zähl-Bug ausgelöst -- `communityLivePower()` (Obmann-/Mitglied-Dashboard,
> `/portal/api/live-power`, `/api/v1/live`) UND die öffentliche `/api/live/:slug` (Grundlage von
> `live.stromfueralle.at`, für JEDEN Besucher sichtbar!) summierten Leistung/Energie über ALLE
> Zählpunkte der Community, ohne gespiegelte Demo-Zählpunkte auszuschließen -- die echte Messung
> UND ihre Spiegelung zählten doppelt. Behoben durch `mirror_source_metering_point_id IS NULL`
> in allen betroffenen Summen/Zählungen. Live an einer Scratch-DB verifiziert (500 W echt blieb
> 500 W in der Summe, nicht 1000 W). Reine Code-Änderung.
>
> **2. platform_admin/manager im Demo-Login fehlten trotz manueller Zuweisung:** `scripts/
> assign_demo_member_roles.php` (siehe oben) legt jetzt zusätzlich platform_admin + manager
> selbst an, falls sie fehlen sollten (statt sich nur auf die manuelle Zuweisung über die
> Admin-Oberfläche zu verlassen), UND gibt am Ende den tatsächlichen Rollenstand aus der DB aus:
> ```bash
> docker compose exec -T webapp php < scripts/assign_demo_member_roles.php
> ```
> Sicher erneut ausführbar (prüft vor jedem Insert per SELECT, legt nie eine zweite/doppelte
> Rolle an). Bei Unklarheit über den tatsächlichen Rollenstand: die Ausgabe dieses Skripts ist
> die verlässliche Quelle, nicht die Vermutung über die Admin-Oberfläche.
>
> **3. Einspeiser hatten kein Verbrauchs-Äquivalent-Diagramm (Patrick: "warum haben die
> Einspeiser nicht die Möglichkeit, ihre eingespeiste Leistung in einem Diagramm einzusehen?"):**
> neue, spiegelbildliche Seite `/portal/my/einspeisung` (bzw. `GET
> /api/v1/production/interval` für die App) für Mitglieder mit Einspeise-/Prosumer-Zählpunkten --
> nutzt dieselbe `eda_interval_data`-Tabelle, aber `energy_direction='GENERATION'`. Card dafür
> auf dem Mitglied-Dashboard, analog zur bestehenden Verbrauchs-Karte. Reine Code-Änderung.
>
> Alle drei Punkte reine Code-Änderungen, kein Migrations-/Setup-Skript nötig außer Punkt 2 (das
> bereits bekannte Rollen-Skript) -- mit dem nächsten `git pull && docker compose up -d --build`
> aktiv.

> **Weitere Nachbesserungen vom 06.09.2026, nach dem ersten echten Login-Versuch als Demo-Admin:**
>
> **1. Absturz beim Öffnen von /portal/dashboard als Demo-Admin** ("DB::setCommunity():
> Argument #1 ($communityId) must be of type string, null given"): `scripts/
> assign_demo_member_roles.php` hatte platform_admin mit `community_id=NULL` angelegt (rein
> funktional korrekt, `Auth::isPlatformAdmin()` braucht keine Community) -- `/portal/dashboard`
> leitet aber JEDEN mit `Auth::isManager()` (das gilt auch für platform_admin) auf
> `manager_dashboard.php` weiter, das zwingend eine aktive Community braucht und sonst abstürzt.
> Doppelt behoben: `/portal/dashboard` weicht jetzt auf `/admin` aus, wenn keine Community aktiv
> ist (schützt auch echte platform_admin-Accounts vor demselben Absturz), UND das Rollen-Skript
> setzt für neu angelegte/reparierte platform_admin-Rollen dieselbe Community wie die
> Mitglied-Identitäten (genau wie beim manuellen Anlegen über die Admin-Oberfläche). Ein
> bestehender kaputter Zustand wird beim nächsten Lauf automatisch repariert:
> ```bash
> docker compose exec -T webapp php < scripts/assign_demo_member_roles.php
> ```
>
> **2. "ESP online: 3 von 4" statt korrekt "X von 2"** (Patrick, per Screenshot, obwohl nur 2
> echte ESPs existieren): eine ZWEITE, von `communityLivePower()` unabhängige Zähl-Stelle in
> `manager_dashboard.php` (Status-Kachel "ESP online" + "Registrierte Zählpunkte") hatte
> denselben, beim ersten Fix übersehenen Doppelzählungs-Bug -- gespiegelte Demo-Zählpunkte
> (`mirror_source_metering_point_id`) wurden auch hier mitgezählt. Ergänzt um dieselbe
> `mirror_source_metering_point_id IS NULL`-Bedingung. Reine Code-Änderung.
>
> **3. Echte Zugangsdaten im Klartext für Demo-Admin sichtbar** (Patrick: "die ganzen
> E-Mail-Einstellungen, Sachen wie die Graph API von Microsoft [...] verpixelt oder mit
> Sternchen"): die Read-only-Sperre verhindert zwar jede Änderung, aber NICHT das bloße Ansehen
> -- drei Stellen zeigten echte, entschlüsselte Zugangsdaten im Klartext-Formularfeld:
> MQTT-Passwort + Geräte-Fernkonfigurationspasswort (`/admin/mail-settings`), EDA-Portal-Passwort
> je EEG (`/admin/communities/:id`), und das Heim-WLAN-Passwort eines Mitglieds (Endpunkt
> `/portal/members/:id/metering-points/:mpid/wifi-info`, per GET abrufbar -- von der POST-only
> Sperre nicht erfasst). Das Microsoft-Graph-Client-Secret selbst war bereits vorher sicher (nie
> im Klartext, nur ein Passwort-Feld mit Platzhalter) -- Tenant-/Client-ID zusätzlich maskiert,
> obwohl technisch keine Geheimnisse (Azure-Identifikatoren, kein Client-Secret), auf Patricks
> ausdrücklichen Wunsch. Alle vier jetzt für Demo-Logins maskiert (`demoMaskFull()`), beim
> WLAN-Endpunkt zusätzlich geprüft, ob das betroffene Mitglied ECHT ist (fiktive Demo-Mitglieder
> selbst bleiben unmaskiert, wie überall sonst auch). Reine Code-Änderung.

> **Einmalig nach dem Update vom 06.09.2026** (Datei-Downloads für den Demo-Account komplett
> gesperrt + weitere PII-Lücken geschlossen -- Patrick, 06.09.2026: "Die Dateien dürfen nie, in
> gar keinem Fall, irgendwie installiert oder heruntergeladen werden können. Ich würde da voll
> gegen das Datenschutzrecht verstoßen."): reine Code-Änderung, kein Migrations-/Setup-Skript
> nötig -- mit dem nächsten `git pull && docker compose up -d --build` aktiv.
>
> **1. Datei-Downloads:** die bisherige Read-only-Sperre (`Router.php`/`AppApiAuth.php`) blockt
> nur POST -- alle Datei-/PDF-Download-Routen sind aber GET und liefen deshalb weiterhin durch
> (gleiches Lückenmuster wie schon beim WLAN-/MQTT-/EDA-Passwort). Neue zentrale Helper
> `denyDemoFileDownload()` (Web, zeigt die "Nur Lesezugriff"-Seite) bzw.
> `denyDemoApiFileDownload($ctx)` (App-API, JSON-403) in `index.php`, jeweils ganz am Anfang der
> Route aufgerufen -- bei den Vertrags-PDF-Routen (`/portal/members/:id/contract/bezug` u.ä.)
> verhindert der frühe `return` dabei auch gleich einen Status-Update-Nebeneffekt, den das bloße
> Ansehen sonst auslöst. Betrifft ausnahmslos JEDE Datei, egal ob sie technisch zu einem echten
> oder einem fiktiven Demo-Mitglied gehört: Mitglieder-Uploads (`/portal/files/...`,
> `/portal/my/documents/...`), Beitrittserklärungen (`/portal/applications/:id/formular`),
> Bezugs-/Einspeisevereinbarungen, Rechnungen (inkl. `/portal/billing/preview`-Vorlage), die
> SEPA-Sammellastschrift-XML eines Abrechnungslaufs, LaTeX-Vorlagen/Logos
> (`/admin/templates/:name/download`, `/portal/settings/logo/preview`), Profilbilder/Avatare und
> der DSGVO-Selbstauskunft-Export -- jeweils jedes GET-Pendant im Web-Portal UND in der App-API.
> Bloßes Browsen/Hineinklicken in Datei-LISTEN bleibt erlaubt ("Hineinklicken wäre nämlich schon
> cool zu können") -- nur der eigentliche Dateitransfer wird geblockt.
>
> **2. `/portal/files` + `/portal/files/:id`:** die Mitgliederliste dieser Seite hatte eine
> eigene, bisher ungemaskte Abfrage (Screenshot-bestätigt: Namen/E-Mails vollständig im Klartext
> sichtbar) -- jetzt wie überall sonst über `demoMaskMembers()`/`demoMaskMember()` maskiert.
>
> **3. Postfach:** Name in "Neue Beitrittserklärung: ..."-Meldungen sowie die Zählernummer in
> "Unbekannte Zählernummer gemeldet"-Meldungen (noch keinem Mitglied zugeordnetes ESP) sind hier
> freier Fließtext statt eigener Spalten (siehe `notify_unknown_meter()` in
> `mqtt-subscriber/main.py`) -- neue Funktion `demoMaskNotification()` ersetzt gezielt das
> bekannte Textmuster je Benachrichtigungstyp.
>
> **4. Support-Tickets:** Namen echter Mitglieder in Ticketliste (`/portal/support`) und
> -detail (`/portal/support/:id`) maskiert (`demoMaskMembers()`/`demoMaskMember()`, dafür `m.is_demo`
> in beide Abfragen mit aufgenommen) -- eigene Tickets der beiden fiktiven Demo-Mitglieder
> (Verbraucher 1/Einspeiser 1) bleiben unmaskiert und lassen sich weiterhin ganz normal anlegen.
>
> **5. Obmann-Einstellungen (`/portal/settings`, gleiche Felder auch auf
> `/admin/communities/:id`):** ZVR-Nummer und EEG-Name bleiben bewusst sichtbar (Vereins-
> Stammdaten, keine PII). Neue Funktionen `demoMaskCommunitySettings()` (Kontakt-E-Mail/
> Kontoinhaber komplett unkenntlich, Gläubiger-ID/Marktpartner-ID nur die ersten paar Zeichen --
> Patrick nannte Letzteres "PIC"; mangels eines Felds mit diesem Namen auf `marktpartner_id`
> gemappt, ggf. korrigieren falls etwas anderes gemeint war), `demoMaskSettingsUser()` (Name des
> eingeloggten Obmann-Kontos im Unterschrift-Bereich, nur 3 Anfangsbuchstaben statt der sonst
> üblichen 4) und `demoMaskTaxConfig()` (UID-Nummer, nur die ersten 3 Zeichen).
>
> Alle neuen `demoMask*`-Funktionen unit-getestet (`tests/functions_test.php`) und zusätzlich
> gegen eine Scratch-DB mit echten und fiktiven Mitgliedern/Tickets/Postfach-Meldungen/
> EEG-Stammdaten live verifiziert (u.a. `Stefanie Schwaiger` -> `Stef•••• •••••••••`, `Verbraucher
> 1` bleibt unmaskiert, ZVR-Nummer bleibt sichtbar).

> **Einmalig nach dem Update vom 06.09.2026** (Aktivitätslog + Beitrittsanträge maskiert, WLAN-Info
> ohne Klick sichtbar -- reine Code-Änderung, kein Migrations-/Setup-Skript nötig):
>
> **1. Aktivitätslog (`/admin/log`, `/admin/log/export`, `/api/v1/admin/log`):** die/der
> Handelnde (aus `users`) wird wie überall über `demoMaskUser()` maskiert. `beschreibung` ist
> dagegen freier Fließtext aus über 50 verschiedenen `logAudit()`-Aufrufstellen im ganzen Code
> (Mitgliedernamen, E-Mails, IBANs, ...) -- ein gezielter Textbaustein-Ersatz je Aufrufer wie bei
> `demoMaskNotification()` wäre hier nicht robust pflegbar, deshalb neue Funktion
> `demoMaskAuditLog()`: `beschreibung` wird für den Demo-Zugang komplett durch "Details
> ausgeblendet (Demo-Zugang)." ersetzt, Aktion/Objekttyp/EEG/Zeitpunkt bleiben sichtbar. Der
> Markdown-Export (`/admin/log/export`) fällt zusätzlich unter die generelle
> Datei-Download-Sperre (`denyDemoFileDownload()`, siehe oben).
>
> **2. Beitrittsanträge (`/portal/applications`, `/portal/applications/:id`):** eigene Tabelle
> `membership_applications` mit eigenen Spaltennamen (`iban`/`bic` statt `member_iban`/
> `member_bic`, `bezug_zaehlpunkt` statt `znr_bezug`, ...), deshalb neue Funktion
> `demoMaskApplication()` statt `demoMaskMember()`. Unterschriftsbilder (Beitritt + SEPA-Mandat)
> werden komplett ausgeblendet statt maskiert. Das PDF-Formular selbst
> (`/portal/applications/:id/formular`) war bereits über die Datei-Download-Sperre vom letzten
> Update abgedeckt.
>
> **3. WLAN-Info ohne Klick sichtbar** (Patrick, 23.08.2026, per Screenshot: "nicht darunter den
> kleinen Schriftzug 'WLAN-Info anzeigen'"): auf der Mitglied-Detailseite (`/portal/members/:id`)
> zeigte ein Klick auf "WLAN-Info anzeigen" bisher SSID/IP/WLAN-Passwort in einem `alert()`-Popup.
> Jetzt lädt `member_detail.php` diese Info für jeden Zählpunkt mit Zähler automatisch beim
> Öffnen der Seite per AJAX nach und zeigt sie direkt in der Tabelle an -- kein Klick, kein
> Popup mehr nötig. Die bestehende Sicherheitsvorkehrung bleibt dabei erhalten: das
> WLAN-Passwort landet weiterhin NICHT im initial vom Server gerenderten HTML, sondern kommt
> weiterhin über den separaten, authentifizierten Endpunkt
> `/portal/members/:id/metering-points/:mpid/wifi-info` -- nur eben automatisch statt erst nach
> einem Klick. Die dortige Demo-Maskierung (echte Mitglieder maskiert, fiktive Demo-Mitglieder
> unmaskiert, siehe Update vom 06.09.2026 weiter oben) ist davon unberührt und greift unverändert.

> **Einmalig nach dem Update vom 06.09.2026** (WLAN-Info-Popup zurückgebaut + für Demo-Zugang
> komplett ausgeblendet, Rechnungsliste maskiert -- reine Code-Änderung, kein Migrations-/
> Setup-Skript nötig):
>
> **1. WLAN-Info wieder Popup, für Demo-Zugang aber komplett unsichtbar** (Patrick, 23.08.2026:
> "das dann schon rechtlich jetzt nicht okay ist, dass ein Demo-Account das sieht [...] soll gar
> nicht sehen, dass es die Möglichkeit gibt" + "ich nämlich Platz sparen muss" -- die automatisch
> geladene Inline-Variante vom Update davor war ein Missverständnis): `member_detail.php` zeigt
> den Button "WLAN-Info anzeigen" (mit `alert()`-Popup wie ursprünglich) jetzt nur noch für
> Obmann/Platform-Admin -- `Auth::isDemo()` blendet den Button komplett aus, nicht nur den Inhalt,
> damit im Demo-Zugang nicht einmal erkennbar ist, dass WLAN-Zugangsdaten grundsätzlich
> nachsehbar wären. Der zugrundeliegende Endpunkt
> `/portal/members/:id/metering-points/:mpid/wifi-info` bleibt zusätzlich wie gehabt maskiert
> (Verteidigung in der Tiefe, falls er doch direkt aufgerufen wird).
>
> **2. Rechnungsliste (`/portal/billing/invoices`, `/portal/billing/invoices/:id/edit`):**
> Mitgliedernamen/E-Mail/IBAN/Mandatsreferenz jetzt über `demoMaskMembers()`/`demoMaskMember()`
> maskiert (Patrick, 23.08.2026: "bitte für zukünftige Rechnungen [...] auch wieder maskieren").
> Rechnungs-PDFs selbst waren bereits über die Datei-Download-Sperre vom vorletzten Update
> abgedeckt, SEPA-Sammellastschrift-Vorschau ebenso -- hier ging es nur um die bislang
> ungemaskte Listen-/Bearbeiten-Ansicht.

> **Einmalig nach dem Update vom 07.09.2026** (Mitglied-Bearbeiten-Formular für Demo-Zugang
> gesperrt, Namen in Support-Ticket-Nachrichten maskiert -- reine Code-Änderung, kein
> Migrations-/Setup-Skript nötig):
>
> **1. `/portal/members/:id/edit`:** dieses Formular zeigt echte Werte vorbefüllt in
> Eingabefeldern (IBAN, Adresse, Geburtsdatum, ...) -- eine spaltenweise Maskierung wie bei den
> reinen Anzeige-Seiten wäre hier nicht sinnvoll (verfälscht ein Formular, in dem ohnehin nicht
> gespeichert werden kann). Neuer genereller Helper `denyDemoPage(string $message)` (Refactor von
> `denyDemoFileDownload()`, das jetzt nur noch einen festen Text an ihn weiterreicht) zeigt
> stattdessen direkt die "Nur Lesezugriff"-Seite (Patrick, 24.08.2026: "/members/<id>/edit darf
> nicht verfügbar sein"). Der "Bearbeiten"-Button auf der Mitglied-Detailseite bleibt bewusst
> sichtbar (führt nur zur Sperr-Seite) -- Patricks Grundprinzip "alle Funktionen und Buttons
> sichtbar" gilt weiterhin, anders als beim WLAN-Info-Button (dort sollte nicht einmal die
> Möglichkeit erkennbar sein).
>
> **2. Support-Ticket-Nachrichten:** die bereits bestehende Maskierung des Ticket-Headers
> (`/portal/support/:id`) griff nicht auf die einzelnen Nachrichten im Thread durch -- `author_label`
> in `support_ticket_messages` ist freier Text (voller Name zum Zeitpunkt des Absendens, keine
> members-Fremdschlüssel-Spalte), Patrick per Screenshot: "steht drinnen trotzdem immer der volle
> Name". Neue Funktion `demoMaskSupportMessages()` maskiert sowohl Mitglied- als auch
> Verwaltungs-Nachrichten (`author_label = Auth::userName()`, ebenfalls ein echter Name) --
> eigene Nachrichten der beiden fiktiven Demo-Mitglieder bleiben unmaskiert.

> **Einmalig nach dem Update vom 07.09.2026** (Einspeisung-Diagramm zeigt jetzt Gesamterzeugung
> vs. gemeinschaftlich genutzten Anteil, neuer Monats-/Tages-Picker für beide
> Viertelstunden-Diagramme -- Patrick, 06.09.2026 [Folgetermin]: "Gleich wie bei den Verbrauchern
> zu den Einspeisern darstellen, wie viel sie einspeisen und wie viel davon in der
> Energiegemeinschaft verwendet wurde [...] gesamte Einspeisung [...] in Grau [...] was
> Energiegemeinschaftlich genutzt wurde bitte in Gelb" + "beim langsam hin- und herscrollen
> gefällt mir das nicht [...] über eine Eingabe oder über Pfeiltasten zu den Monaten springen
> [...] mit Zahlen [...] wenn Daten vorhanden sind [...] grün/gelb, wenn noch keine Daten
> vorhanden sind [...] Grau"):
> ```bash
> cd /opt/eeg-platform
> git pull origin main
> docker compose exec -T timescaledb psql -U eeg -d eeg_platform < database/migrate_20260907.sql
> docker compose up -d --build
> ```
> **1. Neue EDA-Kennzahl-Spalte importiert:** `eda-parser/parser_interval.py` liest seither auch
> die dritte GENERATION-Spalte im "Energiedaten"-Sheet ("Gesamt-/Überschusserzeugung", bisher nur
> die ersten zwei Kennzahlen wurden gebraucht) als neue Spalte `kwh_erzeugung_gesamt` in
> `eda_interval_data` -- die eigene GESAMTE Erzeugung des Zählpunkts, im Unterschied zu
> `kwh_messung` (bei GENERATION gemeinschaftsweite Summe über ALLE Einspeiser, nicht
> mitgliedsspezifisch) und `kwh_gemeinschaft` (nur der über den Teilnahmefaktor zugeteilte,
> tatsächlich gemeinschaftlich genutzte Anteil). **Spaltenbeschriftung noch NICHT gegen eine
> echte Exportdatei verifiziert** (anders als die beiden bereits länger genutzten Spalten) --
> der Parser loggt jetzt aber eine Warnung ("Kennzahl-Spalte für kwh_erzeugung_gesamt nicht
> gefunden"), falls die tatsächliche Beschriftung abweicht, statt still ohne den neuen Wert zu
> importieren. Bei einer solchen Warnung: tatsächliche Spaltenbeschriftung in der XLSX-Datei
> prüfen und `TARGET_LABELS["GENERATION"]["kwh_erzeugung_gesamt"]` entsprechend anpassen.
>
> **2. Bereits importierte Tage bleiben ohne Gesamterzeugung, bis sie erneut hochgeladen
> werden** -- die neue Spalte wurde vorher nicht gelesen, ein rückwirkendes Befüllen passiert
> nicht automatisch. `/portal/my/einspeisung` erkennt das je Tag (`has_erzeugung_gesamt`) und
> zeigt für ältere Tage weiterhin die bisherige Einzel-Linien-Ansicht mit Hinweistext, für neu
> importierte Tage das neue gestapelte Diagramm (grau = Gesamterzeugung, gelb = gemeinschaftlich
> genutzter Anteil, gleiches Muster wie das bestehende Verbrauchs-Diagramm). Wer möchte, dass
> auch bereits hochgeladene Tage die Gesamtfläche zeigen, muss die jeweilige EDA-Datei einfach
> erneut über `/portal/eda/upload` hochladen (überlappende Zeiträume werden automatisch
> überschrieben, siehe bestehende Import-Logik).
>
> **3. Neuer Monats-/Tages-Picker** (`webapp/src/views/partials/interval_day_picker.php`, von
> `/portal/my/verbrauch` UND `/portal/my/einspeisung` gemeinsam genutzt) ersetzt das bisherige
> reine "Vortag"/"Folgetag"/`<input type="date">` -- zeigt den gewählten Monat als Zahlen-Raster
> (1 bis 28/29/30/31 je nach Monat), grün (Verbraucher) bzw. gelb (Einspeiser) hinterlegt für
> Tage MIT Daten, grau für Tage ohne. Monats-Wechsel per Pfeil-Buttons, `<input type="month">`
> ODER Pfeiltasten (nur wenn der Fokus nicht gerade in einem Eingabefeld liegt). Grundlage:
> neue Funktion `memberIntervalMonthAvailability()` (ein einfaches `SELECT DISTINCT time::date`
> je Monat, kein zusätzlicher Tabellen-Overhead). Kein Verhalten der App-API geändert -- die
> App bekommt seit diesem Update zusätzlich `has_erzeugung_gesamt`/`erzeugung_gesamt_w` in
> `/api/v1/production/interval` (siehe `docs/APP_API.md`), müsste aber selbst noch eine eigene
> native Monats-/Tages-Navigation umsetzen, falls gewünscht -- das war hier nicht Teil des
> Auftrags (Patricks Beschreibung "hin- und herscrollen" bezog sich auf das Web-Portal).
>
> **4. Nachbesserung (07.09.2026): dezentes Hintergrund-Gitter in beiden Diagrammen** (Patrick:
> "wäre beim Diagramm bei den Einspeisern und Beziehern noch interessant, wenn man im
> Hintergrund so ein graues Gitter einzeichnen würde, mit ein bisschen genauerer
> Zeitunterteilung, vielleicht im Stunden- oder im 2-Stunden-Takt. Das Gleiche auf der
> Leistungshöhe [...] in 10 Teilungen"): neuer gemeinsamer Partial
> `webapp/src/views/partials/interval_chart_grid.php` zeichnet senkrechte Linien alle 2 Stunden
> (12 Linien) und waagrechte Linien in 10 gleich große Abschnitte der Leistungsachse (9
> Trennlinien) -- `var(--gray-200)`, hinter den eigentlichen Flächen (verschwindet dadurch dort,
> wo die Fläche opak ist, genau wie bei den meisten Chart-Bibliotheken üblich). Muss direkt nach
> dem öffnenden `<svg>`-Tag eingebunden werden (vor den Flächen/Linien), erwartet die im Scope
> bereits berechneten `$x`/`$yFromW`/`$n`/`$maxW`/`$H`/`$padL` aus der jeweiligen Seite. Reine
> Code-Änderung, kein Migrations-/Setup-Skript nötig -- mit dem nächsten `git pull && docker
> compose up -d --build` aktiv.
>
> **5. Nachbesserung (08.09.2026): jede Gitterlinie beschriftet** (Patrick: "Schreib auch bei
> jedem Gitterstreifen die Uhrzeit unten der x-Achse und bei der y-Achse auch bei jedem Streifen
> die Leistung in Watt, damit man das besser ablesen kann und nicht ausrechnen müsste"). Der
> Partial zeichnet jetzt zu jeder der 12 Zeitlinien (00:00, 02:00, ..., 22:00) und jeder der 11
> Leistungslinien (0 bis Maximum in 10 gleich großen Schritten) eine passende Beschriftung --
> ersetzt die bisherige, viel gröbere Beschriftung (nur 5 feste Uhrzeiten bzw. nur 0/Maximum),
> die vorher direkt in `my_verbrauch.php`/`my_einspeisung.php` gezeichnet wurde (jetzt entfernt,
> einzige Beschriftungsquelle ist der Partial, damit Linie und Zahl garantiert zusammenpassen).

> **Einmalig nach dem Update vom 08.09.2026** (zwei unabhängige Bugs behoben, beim Sichten der
> Server-Logs zu einer anderen Anfrage entdeckt -- reine Wartung, kein neues Feature):
> ```bash
> cd /opt/eeg-platform
> git pull origin main
> docker compose exec -T timescaledb psql -U eeg -d eeg_platform < database/migrate_20260908.sql
> docker compose up -d --build
> ```
> **1. `audit_log` fehlte die Spalte `aktion`** -- JEDER `logAudit()`-Aufruf (Datei-Uploads,
> Mitglieder-Änderungen, Abrechnung, ...) schlug seit jeher mit
> `SQLSTATE[42703]: column "aktion" of relation "audit_log" does not exist` fehl. Kein Datenverlust
> und keine kaputte Funktionalität -- `logAudit()` ist bewusst fehlertolerant (try/catch, siehe
> Kommentar dort), die eigentliche Aktion lief immer normal durch, nur der Aktivitätslog-Eintrag
> ging verloren. Vermutliche Ursache: die Tabelle wurde auf diesem Server schon VOR
> `migrate_20260716.sql` (das sie per `CREATE TABLE IF NOT EXISTS` inkl. `aktion`-Spalte anlegt)
> in einer älteren Form angelegt, wodurch das `IF NOT EXISTS` seither nie griff. Migration fügt
> die fehlende Spalte nachträglich hinzu (idempotent -- auf Servern, wo sie schon existiert, ein
> reines No-Op).
>
> **2. Sidebar-Badge "Mitglieder" (ESP-Fehler-Anzahl) funktionierte nie** -- `PHP Warning:
> Undefined variable $membersWithEspError in portal.php`. Der Berechnungs-Block für alle
> Sidebar-Zähler (Neuanmeldungen, Postfach, Support, ESP-Fehler, ...) stand bisher NACH dem
> "Mitglieder"-Link, der `$membersWithEspError` aber schon vorher braucht -- die Variable
> existierte an der Stelle schlicht noch nicht. Fix: kompletten Berechnungs-Block an den Anfang
> des Verwaltungs-Menüs verschoben (vor den ersten Link, der eine der Variablen liest). Reine
> Code-Änderung, kein Migrations-/Setup-Skript nötig.

> **Einmalig nach dem Update vom 09.09.2026** (audit_log auf Patricks Server hatte noch ZWEI
> weitere, tiefer liegende Schema-Probleme über migrate_20260908.sql hinaus, außerdem: EDA-
> Intervallparser-Warnungen waren bei einem erfolgreichen Import bisher komplett unsichtbar --
> dadurch blieb "warum hat Daniel Roppers Einspeisung keine graue Gesamtfläche" lange ungeklärt):
> ```bash
> cd /opt/eeg-platform
> git pull origin main
> docker compose exec -T timescaledb psql -U eeg -d eeg_platform < database/migrate_20260909.sql
> docker compose exec -T timescaledb psql -U eeg -d eeg_platform < database/migrate_20260910.sql
> docker compose up -d --build
> ```
> **1. `audit_log` fehlten NACH migrate_20260908.sql immer noch `entity_typ`, `beschreibung`,
> `ist_fehler`.** `\d audit_log` auf Patricks Server zeigte eine komplett andere, ältere
> Tabellenstruktur (`action`/`entity_type`/`details`/`actor_label`/`ip`/`aenderungen` statt
> `aktion`/`entity_typ`/`beschreibung`/`ist_fehler`) -- vermutlich Rest eines früh verworfenen
> Schema-Entwurfs. `migrate_20260908.sql` hatte nur `aktion` ergänzt, weil PostgreSQL die
> INSERT-Zielspaltenliste von links nach rechts prüft und beim ERSTEN unbekannten Namen abbricht
> (`aktion` steht als drittes in der Liste `community_id, user_id, aktion, entity_typ, ...` --
> die dahinterliegenden fehlenden Spalten kamen dadurch nie zum Vorschein). `migrate_20260909.sql`
> ergänzt die restlichen drei.
>
> **2. Selbst danach scheiterte jede Einfügung weiterhin** mit `null value in column "action" [...]
> violates not-null constraint` -- zwei Alt-Spalten aus demselben verworfenen Entwurf (`action`,
> `entity_type`) waren `NOT NULL` OHNE Default, der aktuelle Code befüllt sie aber nie (nur
> `aktion`/`entity_typ`). `migrate_20260910.sql` entfernt den `NOT NULL`-Zwang auf beiden
> (Spalten/eventuell vorhandene historische Werte bleiben unangetastet). Erst danach lief
> `logAudit()` auf diesem Server tatsächlich fehlerfrei durch -- zu erkennen u.a. daran, dass der
> zuvor NEUESTE Audit-Log-Eintrag überhaupt vom 21.06.2026 stammte (jede Protokollierung seither
> war lautlos gescheitert, ohne dass irgendeine echte Funktion davon betroffen war).
>
> **3. EDA-Intervallparser: `log.warning()` landete bei einem ERFOLGREICHEN Import nirgends.**
> `EdaParserRunner::runInterval()` (`webapp/src/EdaParserRunner.php`) verwirft `stderr` bei
> Erfolg komplett -- nur bei einem fehlgeschlagenen Lauf fließt es in die Fehlerdiagnose ein.
> Warnungen aus `IntervalXlsxDataSource.load()` (u.a. genau die "Kennzahl-Spalte für
> kwh_erzeugung_gesamt nicht gefunden"-Warnung aus dem 07.09.2026-Update) waren dadurch bei
> jedem erfolgreichen Import unsichtbar -- weder im Audit-Log noch auf der Upload-Ergebnisseite
> noch in `docker compose logs`, obwohl genau diese Seite (`eda_upload.php`) `warnings` pro
> Zeile längst anzeigt. Fix: `LoadResult` trägt jetzt eine eigene `warnings`-Liste,
> `import_to_db()` übernimmt sie in ihre bestehende `warnings`-Liste (landet dadurch in
> `eda_interval_imports`, im Audit-Log UND direkt auf der Upload-Ergebnisseite). Die Warnung
> nennt jetzt zusätzlich die tatsächlich in der Datei gefundenen Spaltenbezeichnungen.
>
> **4. Dadurch sofort gefunden: `kwh_erzeugung_gesamt`-Spaltenname war falsch geraten.** Die im
> 07.09.2026-Update eingetragene Annahme `"gesamt-/überschusserzeugung"` (MIT Bindestrich vor dem
> Schrägstrich) war nie gegen eine echte Exportdatei verifiziert worden. Patricks erster echter
> Test (09.09.2026, Datei für Daniel Roppers Zählpunkt) zeigte über die neue Warnung sofort die
> tatsächliche Bezeichnung: `"Gesamt/Überschusserzeugung, Gemeinschaftsüberschuss [kWh]"` -- OHNE
> Bindestrich. `TARGET_LABELS["GENERATION"]["kwh_erzeugung_gesamt"]` korrigiert auf
> `"gesamt/überschusserzeugung"` (Substring-Match, kollisionsfrei gegen die drei übrigen
> GENERATION-Spalten verifiziert). **Wer die Gesamterzeugung schon vor diesem Fix importiert
> hat, muss die jeweilige Datei einmal erneut über `/portal/eda/upload-interval` hochladen** --
> überlappende Zeiträume werden automatisch überschrieben.
>
> **Merksatz für ähnliche Fälle:** bei "Parser/Import lief angeblich erfolgreich durch, aber ein
> erwarteter Wert fehlt trotzdem" IMMER zuerst prüfen, ob eine `log.warning()`-Meldung im
> Erfolgsfall überhaupt irgendwo sichtbar gemacht wird (stdout/JSON-Ergebnis, nicht nur stderr) --
> sonst bleibt die eigentliche Ursache (hier: falsch geratener Spaltenname) unsichtbar, obwohl der
> Code sie technisch längst "wusste".

> **Einmalig nach dem Update vom 31.08.2026** (EDA-Intervallparser: GENERATION-Spaltenzuordnung
> für "Meine Einspeisung" grundlegend korrigiert -- der Fix vom 09.09.2026 oben hatte die
> Sichtbarkeit hergestellt, aber die zugrundeliegende Spaltenzuordnung war zusätzlich inhaltlich
> falsch):
> ```bash
> cd /opt/eeg-platform
> git pull origin main
> docker compose exec -T timescaledb psql -U eeg -d eeg_platform < database/migrate_20260911.sql
> docker compose up -d --build
> ```
> Auch nach dem Fix vom 09.09.2026 (Spaltenname korrigiert, keine Warnung mehr, Spalte wird
> gefunden) zeigte "Meine Einspeisung" bei Daniel Ropper weiterhin keine graue Gesamtfläche.
> Patrick hat daraufhin die beiden echten Exportdateien direkt geteilt -- Analyse mit openpyxl
> gegen alle 6 Zählpunkte / 2.688 Zeilen je Zählpunkt zeigte: die Spalte "Erzeugung lt. Messung
> entsprechend dem Teilnahmefaktor und EC-ID" (bisher als `kwh_gemeinschaft`, "nur der über den
> Teilnahmefaktor zugeteilte, reduzierte Anteil") ist in JEDER geprüften Zeile IDENTISCH zu
> "Gesamte gemeinschaftliche Erzeugung" -- entgegen dem Namen also bereits die GESAMTE Erzeugung
> des Zählpunkts, kein reduzierter Anteil. Die ursprünglich für "Gesamterzeugung" angenommene
> Spalte ("Gesamt-/Überschusserzeugung, Gemeinschaftsüberschuss") ist dagegen in JEDER Zeile
> beider Dateien leer -- vom Netzbetreiber nie befüllt. Tatsächlich befüllt und bisher komplett
> ungenutzt: eine vierte Spalte "Restüberschuss bei EG und je ZP" -- in allen 2.688 geprüften
> Zeilen kleiner-gleich der Teilnahmefaktor-Spalte, also der ans Netz abgegebene, NICHT
> gemeinschaftlich genutzte Rest. Neue Berechnung in `eda-parser/parser_interval.py`:
> `kwh_erzeugung_gesamt` = Teilnahmefaktor-Spalte direkt, `kwh_gemeinschaft` (der tatsächlich
> gemeinschaftlich genutzte, gelbe Anteil) = Teilnahmefaktor-Spalte minus Restüberschuss. Gegen
> beide echten Exportdateien verifiziert: 0 Verletzungen von `gemeinschaft <= gesamt`.
> **Wer die Gesamterzeugung schon vor diesem Fix importiert hat (auch nach dem 09.09.2026-Fix),
> muss die jeweilige Datei einmal erneut über `/portal/eda/upload-interval` hochladen** --
> überlappende Zeiträume werden automatisch überschrieben. **Merksatz:** bei EDA-Exportspalten,
> deren Name eine "Reduktion"/"Zuteilung" suggeriert (hier "...entsprechend dem
> Teilnahmefaktor..."), nicht ungeprüft von der Namensbedeutung auf den tatsächlichen Inhalt
> schließen -- ein direkter Blick in eine echte Exportdatei (mehrere Zählpunkte/Zeilen
> vergleichen) ist zuverlässiger als eine plausible Spaltenbeschriftung.

> **Einmalig nach dem Update vom 24.08.2026** (Energiefluss-Grafik neu gezeichnet, geometrisch
> statt mit starren CSS-Connectors -- reine Code-Änderung, kein Migrations-/Setup-Skript nötig):
> `webapp/src/views/partials/energy_flow.php` (gemeinsam genutzt von `manager_dashboard.php` und
> `member_dashboard.php`) zeichnet die Verbindungslinien + animierten Energie-Impulse zwischen
> PV-/Netz-/Verbrauch-Kreisen und dem EEG-Knoten als SVG, per JS aus den tatsächlichen
> Kreis-Positionen/-Radien berechnet (`getBoundingClientRect()`), statt fixer CSS-Connector-Divs
> mit Lücke zum Kreisrand (Patrick, 24.08.2026, nach Vorbild der Fronius-Energiefluss-Darstellung:
> "Die Animation darf nicht erst mehrere Pixel/Abstände außerhalb des Kreises beginnen").
> **Ausschließlich gerade Linien** -- eine erste Fassung hatte die PV-Verbindung noch als
> Bezier-Kurve um den Text "676 W"/"PV-Erzeugung" herumgeführt, das wurde von Patrick im selben
> Update wieder verworfen ("ABSOLUT KEINE KURVEN [...] Die Verbindung soll immer die direkte
> kürzeste gerade Strecke [...] sein"): die Linie läuft jetzt bewusst gerade durch den Text
> hindurch, der Text bleibt unverändert an seiner Position, nur unter der Linie (z-index).
> **Genau EIN Energie-Impuls je aktiver Verbindung** (nicht mehrere gleichzeitig) -- bewegt sich
> von Kreisrand zu Kreisrand (~1s), verschwindet vollständig, macht exakt 0,5s Pause, startet neu
> ("Impuls → Ziel → verschwinden → 0,5 s Pause → Impuls → ..."). Technisch über SVG
> `<animateMotion>`/`<mpath>` mit `begin="0s;<eigene-id>.end+0.5s"` gelöst -- ein
> Standard-SMIL-Idiom für eine sich selbst wiederholende Animation mit Pause zwischen den
> Durchläufen (`repeatCount="indefinite"` kennt keine Pause zwischen Wiederholungen). Richtung
> aus den tatsächlichen Leistungswerten abgeleitet (PV->EEG, EEG->Verbrauch, Netz<->EEG je nach
> Vorzeichen), keine Animation bei 0 W. Farben/Typografie/Kreisgrößen/Layout unangetastet.
> Beide Fassungen vor dem jeweiligen Commit mit Playwright gegen das echte `app.css` gerendert
> und verifiziert -- bei der zweiten Fassung zusätzlich das SMIL-Timing selbst per
> `page.evaluate()`-Polling (nicht nur Screenshots) auf exakt 1s Bewegung + 0,5s Pause geprüft.
> **Netz/Verbrauch strikt waagrecht** (dritte Nachbesserung, selbes Update, Patrick: "das schiefe
> gefällt mir nicht"): beide Linien nehmen jetzt bewusst die Y-Koordinate des EEG-Knotens als
> gemeinsame Höhe (`trimHorizontal()`), statt der individuell gemessenen Kreis-Mitte -- letztere
> konnte durch unterschiedlich hohe Beschriftungen um ein, zwei Pixel abweichen und die Linie
> dadurch leicht schräg wirken lassen. Per `getAttribute('d')`-Vergleich verifiziert (y1 === y2).
>
> **Wichtigster Fund (selbes Update): der eigentliche Grund für die leere öffentliche
> Live-Anzeige war ein TimescaleDB-SkipScan-Bug, nicht (nur) die DB-Rolle** -- siehe "Bekannte
> Probleme" weiter oben für die volle Diagnose und den Fix (JOIN statt `NOT IN (SELECT ...)`
> in `/api/live/:slug`).
>
> **Zusätzlich (selbes Update): Live-Anzeige zeigt bei einem Fehler jetzt eine sichtbare
> Meldung statt stillschweigend nichts zu tun.** `webapp/src/views/pages/live.php` (öffentliche
> `/live`-Suchseite) ließ den Nutzer bisher ohne jeden Hinweis im Unklaren, wenn `/api/live/:slug`
> fehlschlug (Patrick, 24.08.2026: Namen eingetippt, aber Anzeige blieb einfach leer). Zwei Fixes:
> (1) Enter im Suchfeld lädt jetzt direkt bei genau einem Treffer oder exakter
> Namensübereinstimmung, auch ohne auf einen Dropdown-Eintrag zu klicken; (2) ein fehlgeschlagener
> Abruf zeigt jetzt eine Fehlermeldung an (den Fehlertext der Route, falls JSON, sonst
> "Fehler `<Statuscode>`") statt nichts anzuzeigen. Das deckt aber NICHT jeden Fall ab: bei einer
> unbehandelten PHP-Exception in der Route liefert `index.php`s globaler `set_exception_handler`
> eine generische HTML-Fehlerseite statt JSON zurück (deren "Technische Details"-Zeile zusätzlich
> nur für eingeloggte Nutzer sichtbar ist) -- die Live-Seite zeigt in diesem Fall nur "Fehler 500",
> der tatsächliche Exception-Text steht dann ausschließlich in `docker compose logs webapp`
> (`error_log()`-Zeile mit Präfix `[unhandled]`/`[fatal]`).
>
> **Vierte Nachbesserung (selbes Update): Chart.js lokal eingebunden statt vom CDN.** War die
> eigentliche, dritte Ursache für die weiterhin leere Live-Anzeige im Browser (Backend laut
> `curl` bereits korrekt) -- die eigene CSP blockierte das externe `<script>`-Tag. Volle
> Diagnose + Fix siehe "Bekannte Probleme" oben ("Live-Anzeige [...] zeigt keine Daten", Punkt 3).
>
> **Fünfte Nachbesserung (selbes Update): Kreis-Mittelpunkte von Netz/Verbrauch korrekt auf Höhe
> der EEG-Linie ausgerichtet** (Patrick, 24.08.2026: "Bitte die Kreise so weit runter, dass sie
> mit dem Mittelpunkt auf Höhe der Linie sind. [...] so sieht es ja gar nicht gleich aus"). Bisher
> zentrierte `.eflow-middle` (`align-items:center`) die komplette Kreis+Wert+Label-Säule als
> Ganzes -- beim EEG-Hub (nur ein Kreis, kein Text darunter) fällt Kreis-Mitte und Säulen-Mitte
> zusammen, bei Netz/Verbrauch (Kreis + Wert + Label darunter) liegt die Kreis-Mitte dadurch
> spürbar über der Säulen-Mitte, sichtbar als Linie, die nicht durch die Kreis-Mitte lief. Fix:
> Wert+Label (neuer Wrapper `.eflow-text`) per `position:absolute` unterhalb des Kreises aus dem
> Höhen-Fluss herausgenommen -- `.eflow-node` besteht layouttechnisch dadurch nur noch aus dem
> Kreis selbst, exakt wie `.eflow-hub`, wodurch `align-items:center` jetzt wirklich die
> Kreis-MITTELPUNKTE zueinander ausrichtet. Der frei werdende Platz darunter wird über
> `margin-bottom` in rem reserviert (kein Bezug zu den Pixelwerten der Animation). Per Playwright
> geometrisch verifiziert: `getBoundingClientRect()`-Mittelpunkte von Netz-/Hub-/Verbrauch-Kreis
> exakt deckungsgleich (Differenz 0,00px) in beiden getesteten Szenarien.
>
> **Sechste Nachbesserung (selbes Update): synchronisierte Zwei-Phasen-Impulse statt
> unabhängiger Einzel-Verbindungen** (Patrick, 24.08.2026: "was noch cool wäre ist, dass zuerst
> alle Energieflüsse rein in die EEG gehen, und dann nach den 0,5 sec. alle Flüsse raus gehen" --
> mit zwei Beispielen: Einspeisung ins Netz = PV→EEG zuerst, dann gemeinsam EEG→Netz UND
> EEG→Verbrauch; zu wenig Eigendeckung = PV→EEG UND Netz→EEG gemeinsam zuerst, dann
> EEG→Verbrauch). Jede Verbindung kettet ihren Impuls weiterhin per SMIL an ihre EIGENE id
> (`begin="<startOffset>s;<eigene-id>.end+2s"`, dasselbe bewährte Selbstreferenz-Idiom wie zuvor)
> -- "rein"-Verbindungen (PV immer, Netz bei Bezug) bekommen `startOffset=0`,
> "raus"-Verbindungen (Verbrauch immer, Netz bei Einspeisung) `startOffset=1.5`. Da ALLE
> Verbindungen gegen dieselbe Dokument-Zeitachse starten, laufen gleich-phasige Verbindungen
> zwangsläufig exakt synchron, ohne dass eine Verbindung auf eine andere verweisen müsste.
> **Stolperstein dabei:** ein erster Versuch mit einem gemeinsamen, separat per JS erzeugten
> unsichtbaren "Zeitgeber-Element" (ein `<animate>` auf einem verborgenen r=0-Kreis), an das sich
> alle Impulse einer Phase per `begin="zeitgeber-id.begin"` anhängen, blieb in Chromium komplett
> wirkungslos -- die referenzierenden Impulse feuerten nie, mit Playwright-Zeitstempel-Polling
> zweifelsfrei bestätigt (Opacity blieb über 6,5s durchgehend 0). Vermutlich eine Einschränkung
> von Chromiums SMIL-Sync-Base-Auflösung bei Querverweisen zwischen zwei zur Laufzeit per
> `appendChild()` neu eingefügten Elementen. Bewusst NICHT so umgesetzt, stattdessen der oben
> beschriebene Ansatz mit parametrisiertem Start-Offset -- **Merksatz:** SMIL-Selbstreferenz
> (Element verweist auf sein EIGENES `.end`-Ereignis) ist in Chromium robust, Querverweise
> zwischen zwei UNABHÄNGIG erzeugten dynamischen Elementen (`id.begin`/`id.end` eines ANDEREN
> Elements) dagegen nicht verlässlich -- im Zweifel lieber mehrere synchron startende
> Selbstreferenz-Ketten mit identischem Start-Offset statt einer gemeinsamen Zeitgeber-Referenz.
> Per Playwright-`page.evaluate()`-Polling verifiziert: PV+Netz feuern in der Defizit-Szene exakt
> gleichzeitig (identische Opacity-Werte bei jeder Stichprobe), Netz+Verbrauch entsprechend in
> der Überschuss-Szene, beide Phasen exakt alle 3s (0s/3s/6s bzw. 1,5s/4,5s/7,5s).
>
> **Siebte Nachbesserung (selbes Update, danach korrigiert -- siehe Achte Nachbesserung): Logo im
> Dark-Mode zeigte scheinbar weiterhin das Light-Mode-Logo** (Patrick, 24.08.2026: "Kann es sein,
> dass im Darkmode auch das Logo vom light mode genommen wird. Wir haben aber ein eigens.").
> CSS-Umschaltung (`[data-theme="dark"]`) und die PHP-Route `/logo-:variant.png` waren beide
> bereits korrekt. Erste Vermutung -- fehlendes Cache-Busting bei `<img src="/logo-dark.png">`
> (Route setzt `Cache-Control: public, max-age=3600`, URL blieb bei jedem Upload gleich) -- war
> real und sinnvoll (Fix: neue Helper-Funktion `logoAssetUrl()` hängt seither `?v=<filemtime>`
> an, `base.php`/`portal.php` binden das Logo jetzt darüber ein), löste Patricks eigentliches
> Symptom aber NICHT: der Cache-Bust griff nie, weil der erneute Logo-UPLOAD selbst in Safari
> fehlschlug (Fehlermeldung nie beim Server angekommen) -- siehe Achte Nachbesserung für die
> tatsächliche Ursache und den Fix.
>
> **Achte Nachbesserung (24.08.2026, nach erneutem Test): tatsächliche Ursache war ein
> Safari-spezifischer Upload-Fehler, keine Serving-/Cache-Frage.** Patrick, per Screenshot: Safari
> zeigt beim erneuten Logo-Upload "Safari kann die Seite nicht öffnen [...] request body stream
> exhausted (NSURLErrorDomain:-1021)" -- die neue Datei kommt dadurch NIE beim Server an, die
> alte bleibt liegen, was wie ein hartnäckiger Cache-/Serving-Bug aussieht, aber keiner ist.
> Volle Diagnose + Fix (`uploadRedirect()`-Helper statt `header('Location: ...')` in
> Upload-Handlern) siehe "Bekannte Probleme" oben ("Datei-Upload in Safari schlägt mit 'request
> body stream exhausted' fehl").

> **Einmalig nach dem Update vom 02.-04.10.2026** (Abrechnungs-Fixes vor der Q3-Freigabe --
> Rechnungsdatum/Fälligkeit an die Freigabe gekoppelt, Rechnungen nach Freigabe eingefroren,
> Lösch-Schutz für freigegebene Läufe, SEPA-Sammelüberweisung für Gutschriften, Rechnungs-Mail
> mit PDF-Anhang bei Freigabe -- siehe `docs/VORFAELLE.md`, Abschnitt "Rechnungsnummern pro EEG
> statt plattformweit", für die Geschichte der zwischenzeitlich geänderten und wieder
> zurückgesetzten Rechnungsnummern-Eindeutigkeit):
> ```bash
> cd /opt/eeg-platform
> git pull origin main
> docker compose exec -T timescaledb psql -U eeg -d eeg_platform < database/migrate_20261002.sql
> docker compose exec -T timescaledb psql -U eeg -d eeg_platform < database/migrate_20261003.sql
> docker compose exec -T timescaledb psql -U eeg -d eeg_platform < database/migrate_20261004.sql
> docker compose up -d --build
> ```
> Alle drei Migrationen sind idempotent und können auch auf einem bereits laufenden System ohne
> Downtime nacheinander eingespielt werden (bauen nur Spalten/Constraints/eine Mail-Vorlage auf,
> keine Daten werden gelöscht/umgeschrieben). `migrate_20261002.sql` legt dabei kurzzeitig auch
> eine inzwischen durch `migrate_20261003.sql` wieder entfernte Constraint an -- beide müssen in
> dieser Reihenfolge laufen, nicht nur die neuere allein.
>
> **Rechnungs-Mail mit PDF-Anhang (neu seit 04.10.2026):** bei jeder Freigabe eines
> Abrechnungslaufs bekommt jetzt JEDES Mitglied mit E-Mail-Adresse UND erteilter Zustimmung
> ("Zustellung von Rechnungen [...] per E-Mail") automatisch eine Mail mit der Rechnung als
> PDF-Anhang -- unabhängig vom Saldo (Forderung, Gutschrift oder 0,00 €). Vorher ging bei der
> Freigabe nur an Mitglieder mit einzuziehendem Saldo die separate SEPA-Vorabinfo raus,
> Mitglieder mit Gutschrift oder ohne App-Login erfuhren von ihrer Rechnung nie automatisch.
> Vorlage anpassbar unter Platform-Admin → Einstellungen → E-Mail-Vorlagen → "Rechnung verfügbar
> (bei Freigabe)". Kein weiterer Schritt nötig, läuft automatisch mit der nächsten Freigabe.
