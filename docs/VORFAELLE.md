# Vorfälle & Lösungen

Historisches Vorfallstagebuch der EEG-Plattform: jedes Symptom, die gefundene Ursache und der
angewendete Fix, chronologisch/thematisch wie ursprünglich in `CLAUDE.md` entstanden (am
02.10.2026 ausgelagert, weil die Datei auf über 2000 Zeilen angewachsen und dadurch unübersichtlich
geworden war). `CLAUDE.md` bleibt die kompakte Architektur-Referenz für einen neuen Chat-Kontext
und verweist am Anfang des entsprechenden Abschnitts auf diese Datei.

**Bei einem neuen, noch unklaren Symptom IMMER zuerst hier nachsehen** -- viele Fehlerbilder
(404 trotz laufender App, Login bricht plötzlich ab, EDA-Import schlägt fehl, Logo wird nicht
angezeigt, ...) sind schon einmal aufgetreten und hier mit Ursache und Fix dokumentiert.

## Bekannte Probleme & Lösungen

> **Pfad-/Mount-Übersicht:** Welches Host-Verzeichnis in welchen Container gehängt wird, steht
> vollständig in `docs/INFRASTRUKTUR_PFADE.md` (mit Diagramm). Bei DB-/Daten-„weg"-Symptomen
> IMMER zuerst dort nachsehen.

### Datenbank wirkt plötzlich leer / „relation does not exist" nach Container-Neustart
Symptom: Login/Abrechnung brechen ab, `\dt` zeigt kaum Tabellen, obwohl vorher Daten da waren —
oft nach `docker compose up -d`/Reboot/Image-Update. **Ursache (Vorfall 23.07.2026):** Das
`timescale/timescaledb-ha`-Image legt sein Datenverzeichnis unter **`/home/postgres/pgdata/data`**
ab, **nicht** `/var/lib/postgresql/data`. Der Mount stand aber auf `/var/lib/postgresql/data` →
PostgreSQL schrieb in flüchtigen Container-Speicher, der beim nächsten Container-Neubau weg war.
Die echten Daten lagen unangetastet auf der Platte, nur nicht gemountet. **Behoben** durch
korrekten Mount (`/opt/eeg/timescaledb:/home/postgres/pgdata`) + Image-Pin auf feste Digest in
`docker-compose.yml`. Diagnose/Details: `docs/INFRASTRUKTUR_PFADE.md`. Merksatz: **nie den
`:pg16`-Tag unbewusst neu ziehen**, PGDATA prüfen mit
`docker compose exec timescaledb bash -lc 'echo $PGDATA'`.

### Traefik: "client version 1.24 is too old"
Docker Engine 29.x unterstützt nur API ≥ 1.40. Traefik:latest behebt das, zusätzlich ist `DOCKER_API_VERSION=1.40` in der compose-Datei gesetzt.

### MQTT-Broker von außerhalb des lokalen Netzes erreichen (für Mitglieder-ESP32s zuhause)
**Seit 09.08.2026 eingerichtet und funktionsfähig.** `stromfueralle.at`/`portal.stromfueralle.at`
lösen auf die öffentliche IP des **nginx-Proxy-Hosts** (10.0.0.144 / 80.122.212.226) auf -- eine
andere Maschine als der EEG-Server (10.0.0.250, Raspberry Pi 5), auf dem Mosquitto läuft.
Zufällig hat auch dieser Host dieselbe öffentliche IP (80.122.212.226) wie Patricks
Heimnetz-Fritzbox, das ist aber kein Widerspruch -- beide hängen an derselben Fritzbox/
Internetleitung, nur an unterschiedlichen internen Zielen. Für MQTT läuft die Weiterleitung
deshalb bewusst **nicht** über den nginx-Proxy (der kann ohnehin nur HTTP/HTTPS auf Port
80/443, kein rohes TCP/MQTT) und auch nicht über Traefik, sondern als eigene, direkte Kette an
beidem vorbei:

```
Internet → Fritzbox (Portfreigabe 8883 → pfSense) → pfSense (NAT-Weiterleitung 8883 → 10.0.0.250)
         → Raspberry Pi (10.0.0.250), direkt an Mosquitto
```

Beide Freigaben sind eingerichtet: Fritzbox unter Internet → Freigaben → Portfreigaben
(Gerät „pfSense", Port 8883 „MQTT TLS"), pfSense unter Firewall → NAT → Port Forward (WAN,
TCP/UDP, Ziel-Port 8883 → Umleitungsziel 10.0.0.250:8883, Beschreibung „MQTT TLS EEG Raspi").
Nur Port 8883 (TLS) ist extern freigegeben, 1883 (unverschlüsselt) bleibt intern/lokal --
erst seit Mosquitto TLS + Zugangsdaten verlangt (siehe `scripts/mqtt_secure_setup.sh`,
Abschnitt „Update" oben) ist die Weiterleitung überhaupt vertretbar.

> **Stolperstein bei der Einrichtung (09.08.2026):** Die pfSense-NAT-Regel allein reichte nicht --
> `nc`/Online-Port-Checker (z. B. yougetsignal.com „open port finder") zeigten Port 8883 von
> außen weiterhin als **geschlossen**, obwohl sowohl die Fritzbox-Portfreigabe als auch die
> pfSense-NAT-Regel korrekt eingetragen und aktiv waren. Ursache: die NAT-Regel hatte keine
> zugehörige Freigabe unter **Firewall → Rules → WAN** -- pfSense übersetzt die Zieladresse
> zwar (NAT), die Standard-Firewall (default deny) blockte das Paket aber trotzdem, weil dafür
> zusätzlich eine eigene Allow-Regel nötig ist (wird beim Anlegen einer Portweiterleitung über
> den Wizard normalerweise automatisch mit erzeugt, hier aber gefehlt). Behoben durch komplettes
> Neuanlegen der NAT-Regel (dabei automatisch samt zugehöriger WAN-Firewall-Regel erzeugt) --
> danach sofort erreichbar. **Merksatz:** Bei "NAT-Regel korrekt, Port trotzdem von außen zu"
> zuerst Firewall → Rules → WAN auf eine aktive Freigabe für den betroffenen Port prüfen.

Von außen testen (unabhängig vom eigenen Netz, das selbst ausgehende Verbindungen auf
unüblichen Ports blockieren kann): Online-Port-Checker wie
[yougetsignal.com](https://www.yougetsignal.com/tools/open-ports/) oder
[canyouseeme.org](https://canyouseeme.org) mit der aktuellen Fritzbox-WAN-IP + Port 8883.

Alle eingehenden Nachrichten live mitlesen (Debugging, lokal im Netz):
```bash
docker compose exec mosquitto mosquitto_sub -h localhost -t 'eeg/#' -v -u "$MQTT_USER" -P "$MQTT_PASSWORD"
```
Von einem Mac/PC außerhalb des lokalen Netzes (z. B. testweise vom eigenen Laptop, braucht
`brew install mosquitto` für `mosquitto_sub`):
```bash
mosquitto_sub -h stromfueralle.at -p 8883 --insecure -t 'eeg/#' -v -u eeg-device -P "$MQTT_PASSWORD"
```
`--insecure`, weil das Zertifikat selbstsigniert ist (genau wie beim ESP32 via `setInsecure()`).

### 404 von Traefik trotz laufendem webapp
Mögliche Ursachen, in dieser Reihenfolge prüfen:

**a) Ungültige Router-Regel (Traefik v3-Syntax!)** — wir laufen auf `traefik:latest` = v3.x.
In v3 akzeptiert `Host()` nur noch **einen** Wert pro Aufruf; die alte v2-Syntax
`Host(\`a\`, \`b\`)` für mehrere Domains ist ungültig und lässt den Router fehlschlagen
(genau das hat schon einmal alles auf 404 gesetzt). Für mehrere Hosts immer:
```
Host(`a`) || Host(`b`)
```
Prüfen mit:
```bash
docker logs traefik --tail 100 | grep -i error   # Rule-Parse-Fehler auftauchen lassen
docker compose config | grep "routers.*rule"     # gerenderte Labels ansehen
```

**b) `docker-compose.override.yml` vorhanden mit `traefik.enable=false`.**
```bash
ls /opt/eeg-platform/docker-compose.override.yml   # sollte nicht existieren
rm /opt/eeg-platform/docker-compose.override.yml   # falls vorhanden
docker compose up -d --force-recreate webapp
```

### Domain in Labels falsch (z.B. noch 10.0.0.250.nip.io)
```bash
grep DOMAIN /opt/eeg-platform/.env              # prüfen
sed -i 's/^DOMAIN=.*/DOMAIN=stromfueralle.at/' /opt/eeg-platform/.env
docker compose up -d --force-recreate webapp    # Labels neu setzen
```

### webapp startet nicht (Port 80 belegt)
Entweder override-Datei vorhanden (siehe oben) oder Traefik läuft nicht:
```bash
docker ps | grep traefik
docker compose up -d traefik
```

### www-Subdomain hinzufügen (z.B. www.stromfueralle.at)
Traefik-Seite (10.0.0.250, dieses Repo) ist bereits so konfiguriert, dass der
webapp-Router sowohl `stromfueralle.at` als auch `www.stromfueralle.at` matcht
(`docker compose up -d --build` nach `git pull` reicht hier).

Die SSL-Terminierung passiert aber auf dem **separaten nginx-Proxy-Host (10.0.0.144)**,
der nicht Teil dieses Repos ist. **Nicht** `sudo certbot --nginx --expand -d ... -d www...`
direkt verwenden — der nginx-Plugin-Modus schreibt dabei automatisch in die vhost-Datei und
hat in der Praxis den bestehenden `server_name`-Block zerlegt/dupliziert, wodurch parallel
mehrere Zertifikats-Lineages (`stromfueralle.at`, `stromfueralle.at-0001`,
`www.stromfueralle.at`) entstanden sind und die Hauptdomain ihr Zertifikat verlor. Stattdessen:
```bash
# 1. Zertifikat erweitern OHNE dass certbot die nginx-Config anfasst (certonly!)
sudo certbot certonly --nginx \
  --cert-name stromfueralle.at --expand \
  -d stromfueralle.at -d www.stromfueralle.at -d traefik.stromfueralle.at

# 2. vhost-Datei sichern und explizit selbst schreiben (nicht certbot überlassen)
sudo cp /etc/nginx/sites-available/70_stromfueralle.conf \
        /etc/nginx/sites-available/70_stromfueralle.conf.bak-$(date +%s)
sudo nano /etc/nginx/sites-available/70_stromfueralle.conf
#   server_name stromfueralle.at www.stromfueralle.at;   (in beiden server{}-Blöcken)
#   ssl_certificate/-_key bleiben auf .../live/stromfueralle.at/... (unverändert)

# 3. Testen, laden, verifizieren — ERST DANACH ggf. übrige Zertifikate löschen
sudo nginx -t && sudo systemctl reload nginx
sudo certbot certificates | grep -A6 "Certificate Name: stromfueralle.at$"
curl -vI https://stromfueralle.at 2>&1 | grep -i subject
curl -vI https://www.stromfueralle.at 2>&1 | grep -i subject
```
`--cert-name stromfueralle.at --expand` stellt sicher, dass genau die bestehende Lineage unter
`/etc/letsencrypt/live/stromfueralle.at/` erweitert wird (Pfad in der vhost-Config bleibt gültig)
statt eine neue `-0001`-Lineage anzulegen.

### portal-Subdomain für den Login freischalten (erledigt -- Setup-Referenz, ursprünglich 2026-07-15)
Ziel: Der "Anmelden"-Button auf der Hauptseite verlinkt jetzt auf
`https://portal.stromfueralle.at/portal/login` (App-seitig bereits umgesetzt). Traefik
(10.0.0.250, dieses Repo) hat für `portal.stromfueralle.at` schon einen Router auf dieselbe
webapp — Code-seitig ist also nichts weiter zu tun. Es fehlt aber noch, genau wie bei
`www` oben, die SSL-Terminierung auf dem **nginx-Proxy-Host (10.0.0.144)**:
```bash
# 1. Zertifikat um die portal-Subdomain erweitern (certonly, NICHT --nginx-Plugin-Modus
#    die vhost-Datei anfassen lassen -- siehe Warnung bei "www-Subdomain hinzufügen" oben)
sudo certbot certonly --nginx \
  --cert-name stromfueralle.at --expand \
  -d stromfueralle.at -d www.stromfueralle.at -d traefik.stromfueralle.at -d portal.stromfueralle.at

# 2. vhost-Datei sichern und explizit selbst um einen server{}-Block für portal erweitern
sudo cp /etc/nginx/sites-available/70_stromfueralle.conf \
        /etc/nginx/sites-available/70_stromfueralle.conf.bak-$(date +%s)
sudo nano /etc/nginx/sites-available/70_stromfueralle.conf
#   Am Dateiende die beiden folgenden server{}-Blöcke einfügen (gleiches Zertifikat wie
#   der Hauptblock, .../live/stromfueralle.at/... bleibt unverändert):
#
#   server {
#       listen 443 ssl;
#       server_name portal.stromfueralle.at;
#       ssl_certificate     /etc/letsencrypt/live/stromfueralle.at/fullchain.pem;
#       ssl_certificate_key /etc/letsencrypt/live/stromfueralle.at/privkey.pem;
#       include             /etc/letsencrypt/options-ssl-nginx.conf;
#       ssl_dhparam         /etc/letsencrypt/ssl-dhparams.pem;
#       client_max_body_size 20M;
#       location / {
#           proxy_pass         http://10.0.0.250;
#           proxy_set_header   Host              $host;
#           proxy_set_header   X-Real-IP         $remote_addr;
#           proxy_set_header   X-Forwarded-For   $proxy_add_x_forwarded_for;
#           proxy_set_header   X-Forwarded-Proto https;
#       }
#   }
#   server {
#       listen 80;
#       server_name portal.stromfueralle.at;
#       return 301 https://$host$request_uri;
#   }

# 3. Testen, laden, verifizieren
sudo nginx -t && sudo systemctl reload nginx
curl -vI https://portal.stromfueralle.at/portal/login 2>&1 | grep -i subject
```
Vorher zeigt der Anmelden-Button testweise nur relativ auf `/portal/login`, solange man sich
bereits auf `portal.stromfueralle.at` befindet (schützt vor einer Redirect-Schleife, falls die
Subdomain noch nicht erreichbar ist) — sobald DNS + SSL stehen, greift der absolute Link.

> Wichtig unabhängig von nginx: seit dem Session-Cookie-Fix (siehe "Update"-Abschnitt,
> `.stromfueralle.at`-weite Cookie-Domain) muss auch der Webapp-Container mit dem aktuellen
> Code laufen (`git pull && docker compose up -d --build`), sonst wird eine auf einer Domain
> begonnene Session auf der anderen weiterhin nicht erkannt (wirkt wie "sofort ausgeloggt"
> bzw. Admin-Bereich bleibt scheinbar auf der Hauptdomain hängen).

### Datei-/Profilbild-Upload: 500 im Browser, aber webapp-Access-Log zeigt nur 200/302
Stand 16.07.2026, reproduzierbar bei **jedem** Datei- und Profilbild-Upload, in jedem Browser
(nicht nur groß oder gelegentlich). `docker compose logs webapp` (= nginx-**Access**-Log im
Container) zeigt für den fehlschlagenden Request gar nichts oder nur unbeteiligte GETs — der
Request scheitert also, bevor er im Access-Log landet. Zwei Sackgassen auf dem Weg zur Ursache,
damit sie nicht nochmal verfolgt werden:
- `docker compose logs traefik` ist normalerweise leer, weil Traefik ohne `--accesslog=true`
  (nicht gesetzt in `docker-compose.yml`) grundsätzlich keine einzelnen Requests loggt, nur
  eigene Fehler ab Level ERROR. Kein Hinweis auf einen Traefik-Fehler.
- Fehlendes `proxy_http_version 1.1;` in der nginx-Proxy-Config auf 10.0.0.144 sah zunächst
  nach der Ursache aus (Connection-reset-Meldungen dort), war aber nicht die eigentliche
  Ursache -- dieser Fix ist trotzdem sinnvoll (verhindert HTTP/1.0-Verbindungen zum Backend)
  und bleibt gesetzt, hat das Problem hier aber nicht behoben.

**Tatsächliche Ursache**, sichtbar erst im nginx-**Fehler**-Log INNERHALB des webapp-Containers
(nicht `docker compose logs`, das ist nur der Access-Log-Teil von stdout!):
```bash
docker compose exec webapp cat /var/log/nginx/error.log
```
zeigt:
```
[crit] open() "/var/lib/nginx/tmp/client_body/0000000001" failed (13: Permission denied),
request: "POST /portal/profile/photo HTTP/1.1", host: "portal.stromfueralle.at"
```
`webapp/docker/nginx.conf` setzt `user www-data;` (passend zum PHP-FPM-User), aber das
Alpine-nginx-Paket (`apk add nginx` im Dockerfile) legt `/var/lib/nginx` SAMT `tmp/*` (u.a.
`client_body` -- Zwischenspeicher für POST-Bodies, die den kleinen In-Memory-Puffer von nginx
übersteigen) beim Install mit dem eigenen `nginx`-System-User und Modus 750 an, NICHT
`www-data`. Kleine Requests ohne Datei-Anhang (Login, Formularfelder) bleiben unter dem
Puffer-Limit und brauchen dieses Verzeichnis nie, weshalb der Bug nur bei Uploads auffällt.
nginx scheitert dabei NOCH VOR PHP-FPM und liefert sein eigenes Standard-500 aus, weshalb weder
die App-eigene Fehlerseite noch ein Log-Eintrag im Access-Log auftaucht.

> **Erster Fix-Versuch war unvollständig:** Nur `chown -R www-data:www-data /var/lib/nginx/tmp`
> zu setzen behebt das Problem NICHT zuverlässig. Linux verlangt Ausführungsrecht auf JEDES
> Verzeichnis im Pfad, nicht nur auf das Ziel -- `/var/lib/nginx` selbst (der Elternordner von
> `tmp`) blieb dabei weiterhin `nginx:nginx` mit Modus 750 (keinerlei Rechte für "andere"),
> wodurch `www-data` gar nicht erst hineinkonnte, ganz gleich wie `tmp/` selbst berechtigt war.
> Das erklärt auch das trügerische Verhalten: manche Uploads (deren Body zufällig unter dem
> nginx-Puffer bleibt und `client_body/` nie braucht) funktionierten, andere (die den Puffer
> überschreiten) scheiterten weiterhin mit demselben Permission-denied-Fehler -- unabhängig von
> der tatsächlichen Dateigröße, rein zufällig je nach komprimierter Body-Größe.

**Fix:** in `webapp/Dockerfile` direkt nach dem Storage-Chown ergänzt (bereits im Repo, ab
Commit dieser Doku-Aktualisierung) -- chownt den kompletten Elternordner, nicht nur `tmp`:
```dockerfile
RUN chown -R www-data:www-data /var/lib/nginx
```
Wirkt erst nach einem echten Image-Rebuild (Berechtigungen werden beim `docker build` gesetzt,
nicht zur Laufzeit):
```bash
cd /opt/eeg-platform
git pull origin main
docker compose up -d --build
```
Danach zur Kontrolle direkt im Container prüfen (WICHTIG: diesmal auch den Elternordner selbst,
nicht nur seinen Inhalt):
```bash
docker compose exec webapp ls -la /var/lib/nginx/
# /var/lib/nginx selbst UND tmp/ (inkl. client_body/, proxy/, fastcgi/, uwsgi/, scgi/)
# sollten jetzt alle www-data:www-data gehören
```

### Datei-Upload in Safari schlägt mit "request body stream exhausted" fehl (JavaScript-Fehlermeldung im Browser, kein Server-Fehler)
Vorfall 24.08.2026 (Logo-Upload unter `/admin/templates`), Patrick per Screenshot: Safari zeigt
statt der Upload-Seite eine eigene Fehlerseite "Safari kann die Seite nicht öffnen [...] Fehler:
'request body stream exhausted' (NSURLErrorDomain:-1021)". Die Datei kommt dabei NIE beim Server
an -- kein Eintrag in `docker compose logs webapp`, `move_uploaded_file()` läuft nie, die alte
Datei bleibt einfach liegen. Wirkt dadurch leicht wie ein Cache-/Serving-Bug (genau so zuerst
missverstanden -- die alte Datei "kommt einfach nicht weg"), ist aber ein reiner
Browser-/Netzwerk-Fehler, kein PHP-/nginx-Problem.

**Ursache:** ein seit Jahren bekannter, dokumentierter WebKit-Bug. Antwortet der Server auf einen
größeren `multipart/form-data`-POST (Datei-Upload) mit einem klassischen HTTP-3xx-Redirect
(`header('Location: ...')` -- das "POST/Redirect/GET"-Muster, das praktisch jeder Upload-Handler
in diesem Repo bisher verwendet hat), versucht Safari intern, den ursprünglichen Request
nachzuvollziehen -- der dafür nötige Datei-Stream aus `<input type="file">` wurde beim ersten
Senden aber bereits vollständig verbraucht und lässt sich nicht zurückspulen. Andere Browser
(Chrome, Firefox) sind davon nicht betroffen, weshalb es sich isoliert unter Safari zeigt.

**Fix:** neue Hilfsfunktion `uploadRedirect()` (`webapp/public/index.php`, direkt nach
`marketingUrl()`) ersetzt `header('Location: ...'); exit;` in Upload-Handlern durch eine echte
200-OK-Antwort mit einer winzigen HTML-Seite (Meta-Refresh + JS-`location.replace()` als
Fallback) -- kein 3xx-Status mehr, den Safari nachsenden müsste, funktioniert aber in jedem
Browser identisch. Angewendet auf `/admin/templates/:name/upload` (Logos, LaTeX-Vorlagen,
Infoblatt, Hero-Banner -- alle über denselben Handler) und `/portal/settings/logo`
(EEG-eigenes Logo für Rechnungen/Verträge, strukturell derselbe große-Datei-plus-Redirect-Fall).
**Bewusst NICHT** auf die übrigen ~15 kleineren `$_FILES`-Handler im Repo (Profilbilder,
EDA-Excel-Importe, Beitritts-Unterschriften) ausgeweitet -- dort bisher kein Fehlerbericht, und
eine Blanket-Änderung an allen Upload-Routen wäre ein unnötig großer Diff ohne bestätigten
Nutzen; bei einem ähnlichen Fehlerbild dort (Safari, "request body stream exhausted") dasselbe
Muster (`uploadRedirect()` statt `header('Location: ...')`) übertragen.
Reine Code-Änderung, kein Migrations-/Setup-Skript nötig -- mit dem nächsten
`git pull && docker compose up -d --build` aktiv.

> **Nachtrag (24.08.2026, per HAR-Analyse):** die WebKit-Redirect-Theorie oben ist real und der
> Fix bleibt sinnvoll, war aber NICHT die Ursache für Patricks konkreten Fall -- der Fehler trat
> auch in Chrome auf (reines WebKit-Problem hätte das nicht erklärt). Ein vom Browser
> exportiertes HAR (`chrome://net-export` bzw. DevTools-Network-Tab → Rechtsklick → "Save all as
> HAR") zeigte den eigentlichen Widerspruch: `Content-Length: 31830` angekündigt, aber nur
> `bodySize: 371` tatsächlich gesendet (exakt nur das multipart-Gerüst, null Bytes der PNG-Datei
> selbst) -- der Browser ist beim LESEN der Datei von der Festplatte gescheitert, noch bevor
> irgendein Netzwerk-Request überhaupt zum Tragen kam. Ursache: die Datei lag in einem
> iCloud-Drive-Ordner und war dort nur als Platzhalter vorhanden (Dateigröße korrekt bekannt,
> Bytes aber nicht lokal vorhanden) -- kein Bug in diesem Repo. **Merksatz:** bei einem
> Browser-Datei-Lese-Fehler beim Upload (nicht nur Safari) HAR-Export anfordern und
> `Content-Length` gegen `bodySize`/tatsächlich übertragene Bytes vergleichen -- eine massive
> Diskrepanz zeigt ein lokales Datei-Lese-Problem (Cloud-Sync-Platzhalter, Berechtigungen,
> Datei zwischenzeitlich verschoben/gelöscht), keinen Server-/Netzwerk-Fehler.

### Logo-Upload wirkt nie -- zwei fest eingecheckte Platzhalter-Dateien überschatten die PHP-Route (Vorfall 24.08.2026, ECHTE Ursache, gelöst)
Nachdem das iCloud-Problem oben behoben war (Sync eingeschaltet, Upload kam laut Server-seitigem
`md5sum`-Vergleich korrekt an -- `logo-dark.png` und `logo-light.png` waren jetzt zwei
tatsächlich unterschiedliche Dateien unter `/var/www/html/latex-templates/`), zeigte
`https://stromfueralle.at/logo-dark.png` weiterhin das helle Logo -- reproduzierbar auch per
`curl` direkt auf dem Server (kein Browser-Cache im Spiel). `md5sum` von `curl`-Download und der
tatsächlich hochgeladenen Datei stimmten NICHT überein -- der Hash entsprach exakt `logo-light.png`.

**Ursache:** `webapp/public/logo-dark.png` und `logo-light.png` waren als ECHTE, statische
Dateien im Git-Repo eingecheckt (Commit vom 17.08.2026, als Notlösung für ein anderes Problem:
"Header-Logo war nie in Git, ein voller Image-Rebuild hat es verloren" -- beide damals aus
`assets/images/logo.png` befüllt, also von Anfang an byte-identisch). `webapp/docker/nginx.conf`
liefert `*.png`-Anfragen aber bewusst per `try_files $uri /index.php?$query_string;` aus -- prüft
also ZUERST, ob eine echte Datei mit genau diesem Namen unter `public/` existiert, und liefert
diese direkt aus, OHNE jemals `index.php` (und damit die dynamische `/logo-:variant.png`-Route
mit ihrer `adminFilePath()`-Logik für Live-Uploads) zu erreichen. Die beiden 17.08.2026 fest
eingecheckten Dateien haben dieses gesamte dynamische System seither lautlos überschattet --
JEDER Logo-Upload über `/admin/templates` seit diesem Datum landete zwar korrekt unter
`/var/www/html/latex-templates/logo-*.png`, wurde aber NIE ausgeliefert, weil nginx nie so weit
kam. Der Docker-Kommentar in `nginx.conf` (Zeile 55) beschreibt genau diese beiden Pfade explizit
als "dynamische PHP-Routen, keine echten Dateien" -- der stille Widerspruch dazu blieb über einen
Monat unbemerkt, weil zuvor niemand ein tatsächlich abweichendes Dark-Mode-Logo hochgeladen hatte
(die mitgelieferte Standard-Fallback-Logik in `Dockerfile`, Zeilen 34-38, füllt `logo-light.png`
UND `logo-dark.png` ebenfalls beide aus derselben Quellgrafik -- ein frischer Install sieht daher
ohnehin für beide Varianten dasselbe Bild, ganz unabhängig von diesem Bug).

**Fix:** `git rm webapp/public/logo-dark.png webapp/public/logo-light.png` -- die beiden
statischen Platzhalter sind ersatzlos entfernt, `nginx.conf`s `try_files`-Fallback reicht Anfragen
an diese Pfade jetzt wie ursprünglich vorgesehen an `index.php` weiter, wo `adminFilePath()`
korrekt zuerst das Live-Upload-Volume (`/var/www/html/latex-templates/`), sonst die
`Dockerfile`-Standardgrafik prüft. Kein Datenverlust -- die Standard-Fallback-Kopien in
`latex-templates-default/` (Dockerfile, Zeile 37-38) bleiben unverändert bestehen, ein frischer
Install zeigt weiterhin sofort ein Logo. Reine Datei-Löschung, kein Migrations-/Setup-Skript
nötig -- mit dem nächsten `git pull && docker compose up -d --build` aktiv (WICHTIG: ein reines
`git pull` ohne `--build` reicht hier nicht, die beiden Dateien liegen bereits im Docker-Image
und werden erst durch einen echten Image-Rebuild entfernt).

**Diagnose-Weg, der zur eigentlichen Ursache führte** (für ein ähnliches "Upload wirkt nie"-Bild
in Zukunft): (1) `docker compose exec webapp sh -c 'ls -la .../logo-*.png; md5sum .../logo-*.png'`
bestätigte, dass die LIVE-Dateien im Upload-Volume tatsächlich unterschiedlich UND aktuell waren
-- der Upload selbst funktionierte also. (2) `curl -s -o /tmp/x.png ".../logo-dark.png" &&
md5sum /tmp/x.png` (direkt auf dem Server, kein Browser/kein Cache) lieferte trotzdem den Hash
der LIGHT-Datei -- das schloss Browser-Cache und alle client-seitigen Erklärungen endgültig aus
und bewies, dass der Server selbst (unabhängig vom PHP-Code) die falschen Bytes ausliefert.
(3) Erst danach der Blick auf die tatsächliche Dateiebene (`find webapp/public -iname "logo*"`)
zeigte die beiden fest eingecheckten Schattendateien. **Merksatz:** wenn `curl` direkt auf dem
Server schon die falsche Datei liefert, obwohl der PHP-Code korrekt aussieht, IMMER prüfen, ob
nginx den Request per `try_files`/`location`-Block schon VOR der PHP-Route an eine echte,
gleichnamige Datei im `public/`-Verzeichnis abbiegt.

### Logo auf portal.stromfueralle.at komplett unsichtbar (Vorfall 25.08.2026, Nebenwirkung des vorigen Fixes, gelöst)
Direkt nachdem die beiden Schattendateien oben entfernt wurden (Fix für "Logo-Upload wirkt nie"):
`stromfueralle.at` zeigte das Logo jetzt korrekt, aber auf `portal.stromfueralle.at` (Login-Seite
UND eingeloggter Backoffice-Bereich) fehlte es komplett -- keine falsche Grafik, einfach gar
keine. Per HAR-Export (DevTools → Network-Tab → "Save all as HAR") bestätigt: der Bild-Request
`https://portal.stromfueralle.at/logo-light.png?v=...` bekam **302 Found** mit
`Location: https://stromfueralle.at/logo-light.png?v=...` zurück -- eine domainübergreifende
Weiterleitung. Die eigene CSP (`img-src 'self'`, siehe `webapp/docker/nginx.conf`) erlaubt
Bilder aber nur vom selben Origin -- der Browser verweigert es, einer Bild-Weiterleitung auf
eine ANDERE Domain zu folgen, das `<img>` bleibt dadurch leer, ganz ohne sichtbaren Fehler.

**Ursache:** eine bereits bestehende Domain-Trennungs-Logik ganz am Anfang von `index.php`
(noch vor `new Router()`): alles außer `/portal/*` und `/admin/*` wird von
`portal.stromfueralle.at` auf die Hauptdomain umgeleitet (bewusst so gedacht, damit die
Login-/Backoffice-Subdomain nicht versehentlich Marketing-Seiten mit anzeigt). `/logo-light.png`
fällt nicht unter `/portal`/`/admin`, wurde also mit umgeleitet. **Vorher nie aufgefallen, weil
diese Logik nie erreicht wurde:** solange die beiden statischen Schattendateien (siehe oben)
noch existierten, fing nginx den `/logo-*.png`-Request bereits auf Dateisystem-Ebene ab und
lieferte ihn direkt aus -- die Anfrage kam bei `index.php` (und damit bei dieser
Domain-Trennungs-Logik) nie an. Das Entfernen der Schattendateien hat diesen bereits vorher
bestehenden, aber bis dahin folgenlosen Bug erst sichtbar gemacht.

**Fix:** neue Ausnahme `$isSharedAsset` in `webapp/public/index.php` (direkt bei der
Domain-Trennung) schließt `/logo-light.png` und `/logo-dark.png` explizit von der
Portal→Hauptdomain-Weiterleitung aus -- diese beiden Pfade werden jetzt auf JEDER Domain lokal
beantwortet, kein Redirect mehr nötig, da es sich um echte geteilte Assets handelt (von
`base.php` UND `portal.php` eingebunden, siehe `logoAssetUrl()`). `/infoblatt.pdf` und
`/hero-banner-image` bewusst NICHT in die Ausnahme aufgenommen -- die sind ausschließlich in
`home.php`/`legal_beitreten.php` (reine Marketing-Seiten über `base.php`) verlinkt, werden also
nie von der Portal-Subdomain aus angefragt und wären dort ohnehin schon durch dieselbe
Domain-Trennung für die umgebende SEITE selbst nie erreichbar. Reine Code-Änderung, kein
Migrations-/Setup-Skript nötig -- mit dem nächsten `git pull && docker compose up -d --build`
aktiv.

### SSL-Zertifikat fehlt/ungültig auf stromfueralle.at
Diagnose auf dem nginx-Proxy-Host (10.0.0.144):
```bash
sudo certbot certificates                              # Alle Lineages + SAN-Listen prüfen —
                                                         # auf Duplikate wie stromfueralle.at-0001 achten!
ls -la /etc/letsencrypt/live/stromfueralle.at/          # Dateien noch vorhanden?
sudo nginx -t                                           # Config-Syntaxfehler?
sudo journalctl -u certbot.timer --since "-2d"          # Auto-Renewal fehlgeschlagen?
sudo tail -50 /var/log/nginx/error.log
```
Häufigste Ursachen:
- **Mehrere Zertifikats-Lineages für dieselbe Domain** (z.B. durch `certbot --nginx --expand`,
  siehe oben) — die vhost-Config zeigt dann evtl. nicht mehr auf die Lineage, die tatsächlich
  alle benötigten Domains enthält, oder `certbot --nginx` hat beim Schreiben den
  `server_name`-Block der Hauptdomain verändert. Fix: siehe "www-Subdomain hinzufügen" oben
  (Konsolidierung auf eine Lineage, vhost-Datei explizit selbst schreiben, danach überzählige
  Lineages mit `sudo certbot delete --cert-name <name>` entfernen — erst nach Verifikation!).
- **Auto-Renewal fehlgeschlagen** (Rate-Limit, DNS/Port-80-Problem während Renewal) →
  `sudo certbot renew --dry-run` zum Testen, danach `sudo certbot renew`.
- **nginx wurde nach Renewal/Änderung nicht neu geladen** → `sudo systemctl reload nginx`.
Nach jeder Änderung: `sudo nginx -t && sudo systemctl reload nginx`.

### Raspberry Pi hängt sich auf (im Netz sichtbar, aber kein SSH/Terminal mehr)
Klassischer I/O-Stall (SD-Karte am Ende, RAM/Swap voll, Unterspannung oder volllaufende
Platte). Ausführliche Diagnose, Ursachen und v. a. **Selbstheilung per Hardware-Watchdog**
(Pi rebootet sich bei Einfrieren selbst, ohne dass jemand daheim sein muss):
→ `docs/RASPBERRY_STABILITAET.md`. Im Repo bereits abgesichert: `restart: always` auf allen
Containern (Autostart nach Reboot) und Docker-Log-Rotation (`x-logging` in
`docker-compose.yml`, max. 3 × 10 MB/Container), damit die Logs die Platte nicht volllaufen
lassen.

> **Update (23.09.2026, gehäufte Hänger der letzten 1–2 Wochen):** Bis die eigentliche Ursache
> über die Diagnoseschritte in `docs/RASPBERRY_STABILITAET.md` geklärt ist, läuft als
> Übergangs-Mitigation ein täglicher Zwangs-Reboot um 03:00 Uhr (ca. 1 Minute Downtime, danach
> starten alle Container dank `restart: always` automatisch wieder) -- senkt die
> Wahrscheinlichkeit, dass der Pi tagsüber für Mitglieder unerreichbar hängt:
> ```bash
> echo "0 3 * * * root /sbin/reboot" | sudo tee /etc/cron.d/eeg-daily-reboot
> sudo chmod 644 /etc/cron.d/eeg-daily-reboot
> ```
> Log für Fehler/Neustarts einzelner **Dienste** (nicht des ganzen Pi): `/var/log/eeg-health.log`
> (aus `scripts/health_monitor.sh`, alle 5 Min. per Cron, siehe Abschnitt „Container-Healthchecks
> & Selbstheilung" weiter unten) -- `tail -100 /var/log/eeg-health.log` zeigt die letzten
> Einträge. Hängt dagegen der **ganze Pi** (SSH nicht mehr erreichbar), steht dazu nichts in
> dieser Datei -- dafür `docs/RASPBERRY_STABILITAET.md` Abschnitt 2 (zuerst Journal persistent
> machen, dann nach dem nächsten Hänger `journalctl -b -1`).
>
> **Nachtrag (23.09.2026): beide Diagnose-Voraussetzungen fehlten auf diesem Pi tatsächlich.**
> `tail /var/log/eeg-health.log` → „No such file or directory" (der Cron-Job aus „Container-
> Healthchecks & Selbstheilung" war nie eingerichtet) und `journalctl -b -1` → „no persistent
> journal was found" (Journal nie auf `Storage=persistent` gestellt, siehe
> `docs/RASPBERRY_STABILITAET.md` Abschnitt 2.0). Beides jetzt nachgeholt, zusätzlich der in
> Abschnitt 1 von `docs/RASPBERRY_STABILITAET.md` beschriebene **Hardware-Watchdog** aktiviert
> (bisher ebenfalls nicht gesetzt) -- damit rebootet sich der Pi bei einem kompletten Einfrieren
> künftig selbst nach ~15 s, statt unbegrenzt zu hängen. Der tägliche 3-Uhr-Reboot (oben) aktiviert
> `/dev/watchdog` beim ersten automatischen Neustart mit.
>
> **Nachtrag (24.09.2026): `Storage=persistent` wirkt auf diesem Pi trotz korrekter Konfiguration
> nicht -- journald bleibt dauerhaft beim flüchtigen Runtime-Journal.** Nach einem echten
> Freeze-Vorfall (Vater musste den Pi hart vom Strom trennen, da der Watchdog zu diesem Zeitpunkt
> noch nicht aktiviert war) zeigte sich beim Nachprüfen: `/var/log/journal/<machine-id>/` blieb
> nach jedem Neustart leer bzw. verschwand komplett -- unabhängig von erneutem `mkdir`/
> `systemd-tmpfiles --create`/`journalctl --flush`. Ausführliche Diagnose (u. a. `stat`, `mount`,
> `df`, `systemd-detect-virt` [Ergebnis: `none`, also echte Hardware, kein Container], Prüfung von
> `/etc/machine-id` [korrekt committed, kein First-Boot-Zustand], der vollständigen
> `systemd-journald.service`-Unit [kein Sandboxing/`ProtectSystem`], sogar `SYSTEMD_LOG_LEVEL=debug`
> im laufenden Dienst) fand **keine** Fehlermeldung und **keine** der üblichen Ursachen (Rechte,
> Platz, Container, Maschinen-ID, Config-Syntax, Credential-Override über `ImportCredential=
> journal.*`) -- journald versucht schlicht nie, die persistente Journal-Datei zu öffnen, auch im
> Debug-Log nicht. Vermutlich eine Eigenheit dieses Cloud-Init-Images/dieser systemd-Version, die
> sich per Ferndiagnose nicht weiter eingrenzen ließ, ohne riskant in den laufenden Dienst
> einzugreifen (`strace` auf einem Produktivsystem).
>
> **Workaround statt Ursachenforschung:** `scripts/journal_persist_workaround.sh` legt einen
> eigenen, einfachen Dienst (`journal-persist.service`) an, der `journalctl -f` in eine normale
> Textdatei (`/var/log/journal-persist.log`, per `logrotate` täglich rotiert, 14 Tage) mitschreibt
> -- komplett unabhängig von journalds eigener (hier offenbar kaputter) Speicher-Logik, überlebt
> jeden Reboot einfach als gewöhnliche Datei:
> ```bash
> sudo bash scripts/journal_persist_workaround.sh
> ```
> Nach einem künftigen Hänger/Reboot Logs von VOR dem Absturz also **nicht** über `journalctl -b -1`
> suchen (liefert weiterhin "no persistent journal was found"), sondern direkt in
> `/var/log/journal-persist.log` nachsehen (z. B. um den bekannten Ausfallzeitpunkt herum grep'en).
> `docs/RASPBERRY_STABILITAET.md` Abschnitt 2.0 entsprechend um diesen Hinweis ergänzt.
>
> **Nachtrag (25.09.2026): echter ~28-minütiger Hänger, Watchdog-Reboot deutlich später als
> konfiguriert -- offener Punkt, noch ungeklärt.** Erster echter Praxistest des am selben Tag
> gebauten Erreichbarkeits-Monitorings (Node-RED, siehe unten): Alarm um 22:08:55 Uhr
> ("backend_down", HTTP 503). `uptime -s` zeigte danach einen Neustart erst um **22:27:28 Uhr** --
> rund 28 Minuten Hänger, deutlich länger als der konfigurierte Watchdog-Reboot-Timeout von
> ~2 Minuten (`RebootWatchdogUSec=2min`, siehe `docs/RASPBERRY_STABILITAET.md` Abschnitt 1).
> `journal-persist.log` (siehe oben) hat für genau dieses Zeitfenster **nichts** aufgezeichnet --
> passt zu einem schweren I/O-Stau, der auch das Schreiben der eigenen Log-Datei blockiert hätte,
> nicht zu einem sauberen, plangemäßen Watchdog-Reboot. `vcgencmd get_throttled` direkt danach
> zeigte `0x0` (sauber, keine Unterspannung/Überhitzung als Ursache). Zwei vorangegangene
> Neustarts um 21:56 und 21:58 Uhr (nur ~2 Min. auseinander, ebenfalls vollständig im Log
> sichtbar) waren dagegen kein Systemfehler, sondern Patrick hatte den Pi bewusst zweimal
> manuell neu gestartet, um seiner Mutter die neue Wartungsseite vorzuführen -- nicht mit dem
> eigentlichen Hänger danach verwechseln. **Offene Frage für den nächsten Vorfall:** warum
> petted der Watchdog offenbar so viel länger als konfiguriert, bzw. warum enthält
> `journal-persist.log` für das Hänger-Fenster selbst gar nichts? Bei einer Wiederholung zuerst
> dort nachsehen, ob sich das Muster (Log-Lücke + verzögerter Reboot) wiederholt.
>
> **Nachtrag (26.09.2026): Wiederholung am Folgetag, diesmal mit starkem Hinweis auf die
> Stromversorgung statt Software/Kernel.** Vier vom Node-RED-Monitoring gemeldete Ausfälle
> zwischen 03:09 und 10:18 Uhr -- nach genauerer Analyse aber nur EIN echter Totalabsturz
> (06:48–07:28 Uhr, `uptime -s` zeigte als einzigen Reboot seit dem Vortag `07:22:29`; Patrick
> musste über eine Loxone-Steckdose den Strom hart trennen, kein Watchdog-Reboot hat gegriffen).
> Die übrigen drei Meldungen (03:09, 07:49, 10:09) waren kein zweiter Absturz -- 03:09 passt zum
> täglichen 3-Uhr-Reboot, 07:49 und 10:09 zeigen laut `eeg-health.log` nur kurze
> Container-Nachwehen (`docker compose up -d`-Neustarts durch `health_monitor.sh`, System selbst
> lief laut `uptime -s` durchgehend weiter). **`journal-persist.log` zeigte diesmal exakt dasselbe
> Muster wie beim ersten Vorfall:** komplette Stille von 04:57:13 bis 07:23:04 Uhr (fast 2,5 h),
> dann sofort eine frische Boot-Meldung (`Initial clock synchronization`) -- kein Software-Hänger
> hätte das simple `journalctl -f`-Mitschreiben selbst zum Stillstand gebracht, das spricht für
> einen echten Kernel-/Hardware-Freeze oder einen tatsächlichen Stromausfall.
>
> **Entscheidender Fund: `sudo nvme smart-log /dev/nvme0n1`** (das Gerät bootet von einer NVMe-SSD,
> NICHT von einer SD-Karte, wie die obige Diagnose ursprünglich vermutet hatte -- `df -h` zeigt
> `/dev/nvme0n1p2`). Die SSD selbst ist kerngesund (`critical_warning: 0`, `media_errors: 0`,
> `available_spare: 100%`, `percentage_used: 0%`) -- schließt eine verschlissene/kaputte SSD als
> Ursache aus. **Aber `unsafe_shutdowns: 131` bei nur `power_cycles: 170` insgesamt** -- rund 77%
> aller bisherigen Einschaltvorgänge dieses Geräts folgten auf einen UNSAUBEREN Stromverlust,
> nicht auf ein reguläres Herunterfahren (ein normaler `reboot`, auch der tägliche 3-Uhr-Cron,
> benachrichtigt die SSD vorher ordentlich und zählt nicht als "unsafe"). Bei 1007
> Betriebsstunden insgesamt ist das ein durchgehendes Muster über die gesamte Gerätelaufzeit, das
> die seit 25.09.2026 neu eingerichtete Überwachung jetzt erstmals sichtbar macht -- **deutet
> stark auf ein Problem bei der Stromversorgung selbst hin (Netzteil, Kabel, Steckdose), nicht auf
> Software/Kernel/SD-Karte/SSD.** Nebenbei: `sudo dpkg --configure -a` war nötig (unterbrochener
> dpkg-Zustand, vermutlich Nebenwirkung eines der harten Stromausfälle mitten in einem
> Paket-Vorgang) -- lief beim zweiten Versuch sauber durch.
>
> **Nächster Schritt, physisch vor Ort statt per Fernwartung:** Loxone-Steckdose auf eigene
> Automatisierungen/Zeitschaltungen prüfen, Netzteil/Kabel auf Beschädigung bzw. Unterdimensionierung
> prüfen (Raspberry Pi 5 braucht ein echtes 5V/5A-USB-C-PD-Netzteil), alle Steckverbindungen neu
> stecken, wenn möglich probeweise ein anderes, bekannt funktionierendes Netzteil testen.

### Live-Anzeige (öffentlich, `/api/live/:slug`) zeigt keine Daten
Vorfall 24.08.2026, DREI UNABHÄNGIGE Ursachen nacheinander gefunden -- falls das Symptom wieder
auftritt, alle drei Diagnosewege der Reihe nach prüfen, nicht nur den ersten:

**1. Veraltete GRANTs der eingeschränkten Laufzeit-Rolle (behoben, aber war nicht die ganze
Ursache).** Die Rolle `eeg_app` (siehe `scripts/db_runtime_role_setup.sh`) hatte keine aktuellen
GRANTs mehr -- vermutlich, weil das Skript nach einer neueren Migration nicht erneut gelaufen
ist. Fix, sicher wiederholbar:
```bash
cd /opt/eeg-platform && ./scripts/db_runtime_role_setup.sh
```
Diagnose davor:
```bash
grep APP_DB_USER /opt/eeg-platform/.env               # läuft die Webapp überhaupt als eeg_app?
docker compose logs webapp --tail 100 | grep -i "permission denied\|PDOException"
docker compose exec timescaledb psql -U eeg -d eeg_platform -c "\dp esp_measurements"
```
(`docker compose ...` immer im Repo-Root `/opt/eeg-platform` ausführen, sonst "no configuration
file provided: not found".)

**2. TimescaleDB-SkipScan-Bug bei `NOT IN (SELECT ...)` auf einem Hypertable (eigentliche
Ursache, seit demselben Update behoben).** Nach Fix 1 blieb das Symptom bestehen. Das eigentliche
Log zeigte:
```
[unhandled] PDOException: SQLSTATE[XX000]: Internal error: 7 ERROR: unsupported subplan type
for SkipScan: Result in /var/www/html/src/DB.php:66
```
Ursache: `/api/live/:slug` schloss gespiegelte Demo-Zählpunkte (`mirror_source_metering_point_id`,
siehe migrate_20260906.sql) über `metering_point_id NOT IN (SELECT id FROM metering_points
WHERE ...)` aus. TimescaleDBs SkipScan-Optimierung für `DISTINCT ON` auf `esp_measurements`
(einem Hypertable) kommt mit diesem NOT-IN-Subplan nicht zurecht und wirft intern einen Fehler --
reproduzierbar bei JEDEM Aufruf dieser Route. `communityLivePower()` (dieselbe Ausschluss-Logik,
aber für die eingeloggte Dashboard-Ansicht) war davon nie betroffen, weil sie von Anfang an einen
`JOIN metering_points mp ON mp.id = ... AND mp.mirror_source_metering_point_id IS NULL` statt
eines NOT-IN-Subplans verwendet hat. **Fix:** `/api/live/:slug` auf dasselbe JOIN-Muster
umgestellt (kein Migrations-/Setup-Skript nötig, reine Code-Änderung) -- bei einem ähnlichen
Fehlerbild (SkipScan/Subplan-Fehler im Log) grundsätzlich zuerst prüfen, ob irgendwo noch eine
`NOT IN (SELECT ...)`-Variante der `mirror_source_metering_point_id`-Ausschlussregel existiert,
und auf JOIN umstellen.
**Wichtige Nebenerkenntnis:** die generische Fehlerseite zeigt den technischen Fehlertext NUR für
eingeloggte Nutzer (`Auth::check()`-Gate in `renderFatalErrorPage()`) -- ein anonymer `curl`-Test
liefert deshalb nur "Es ist ein unerwarteter Fehler aufgetreten" ohne jedes Detail. Der einzige
Weg zum tatsächlichen Fehlertext ist `docker compose logs webapp | grep -i unhandled` (aus dem
Repo-Root, siehe oben).

**3. Chart.js-CDN-Skript von der eigenen Content-Security-Policy blockiert (dritte, eigentliche
Ursache für den weiterhin leeren Browser -- Fix 1+2 behoben bereits den Server, per `curl`
bestätigt vollständig korrektes JSON, aber im Browser blieb die Anzeige leer bzw. zeigte
"Verbindung zu den Live-Daten fehlgeschlagen").** `live.php` lud Chart.js bisher extern über
`<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js">`. Die
CSP aus dem OWASP-Audit (`webapp/docker/nginx.conf`, 14.08.2026) setzt aber bewusst
`script-src 'self' 'unsafe-inline'` OHNE jede fremde Domain -- genau um das Nachladen fremder
Skripte zu verbieten. Der Browser blockierte das Skript deshalb lautlos (kein Netzwerkfehler,
einfach eine CSP-Verletzung in der Browser-Konsole), `Chart` blieb undefiniert, `drawChart()`
warf eine Exception, die vom eigenen `catch`-Block in `refresh()` als generische
"Verbindung fehlgeschlagen"-Meldung angezeigt wurde -- obwohl der Datenabruf selbst erfolgreich
war. **Fix:** Chart.js lokal eingebunden statt von einem fremden CDN geladen (passend zur
CSP-Absicht, ohne sie aufzuweichen) -- `npm pack chart.js@4` (registry.npmjs.org, im Gegensatz
zu `cdn.jsdelivr.net` von diesem Sandbox-Proxy direkt erreichbar), `dist/chart.umd.min.js`
daraus nach `webapp/public/assets/js/vendor/chart.umd.min.js` kopiert (gleiches Vendor-Muster
wie die bereits vorhandenen `gsap.min.js`/`ScrollTrigger.min.js` dort), `live.php`s
`<script src="...">` auf `/assets/js/vendor/chart.umd.min.js` umgestellt. Kein
Migrations-/Setup-Skript nötig, reine Code-/Datei-Änderung -- mit dem nächsten
`git pull && docker compose up -d --build` aktiv. **Merksatz bei "Backend liefert laut curl
korrekte Daten, Browser zeigt trotzdem nichts/einen Fehler":** immer auch an die
Content-Security-Policy denken, besonders wenn irgendwo ein `<script src="https://...">` auf
eine fremde Domain zeigt -- `grep -rn "https://.*\.js\"" webapp/src` findet solche Stellen,
sollte im gesamten Code eigentlich nie wieder vorkommen.

### ESP32-Firmware: automatisches OTA-Update von GitHub Releases -- erster echter Hardware-Test (10.09.2026)

Das Auto-Update-Feature (`esp32-firmware/p1-smart-meter/p1-smart-meter.ino`,
`checkForFirmwareUpdate()`) war ursprünglich ohne ESP32-Toolchain geschrieben und nie kompiliert
worden. Patrick hat es an einem Tag über mehrere Testrunden mit echter Hardware auf Herz und
Nieren geprüft -- sechs unabhängige Fehler kamen dabei zum Vorschein, jeder erst durch den
vorigen Fix sichtbar geworden (Log-Ringpuffer nirgends angezeigt → `IncompleteInput` beim
JSON-Parsen → `Wrong HTTP Code` bei einem von GitHub nicht befolgten Redirect →
`connection refused` trotz aufgelöstem Redirect → Compile-Fehler durch eine in Patricks
ESP32-Core-Version nicht mehr existierende API → erneut `connection refused`, diesmal durch zu
knapp aufeinanderfolgende TLS-Verbindungen ohne Pause dazwischen). Ab Version `1.3.1`/`1.3.2`
lief der komplette Zyklus (erkennen → herunterladen → flashen → neu starten) erstmals fehlerfrei
durch, rein automatisch über WLAN. Vollständige Chronologie mit Ursache und Fix je Fehler:
`esp32-firmware/p1-smart-meter/README.md`, Abschnitt "Debugging-Geschichte des ersten echten
Testlaufs". Betrifft ausschließlich die ESP32-Firmware, keine Plattform-/Server-Änderung -- kein
Migrations-/Setup-Skript nötig.

### latex-service dauerhaft "unhealthy" trotz laufendem Dienst (Vorfall 24.09.2026, gelöst)
Beim ersten echten Testlauf von `scripts/health_monitor.sh` (siehe Abschnitt "Raspberry Pi hängt
sich auf" oben -- der Cron dafür war auf Patricks Pi bis dahin nie eingerichtet gewesen) zeigte
sich: `latex-service` war dauerhaft `unhealthy`, obwohl `docker compose logs latex-service` ganz
normal "latex-service listening on :3210" zeigt und der Dienst grundsätzlich funktioniert.
**Ursache:** der Healthcheck in `docker-compose.yml` rief `curl -f http://localhost:3210/health`
auf -- das `node:20-slim`-Basisimage von `latex-service/Dockerfile` installiert aber KEIN `curl`.
Der Healthcheck schlug deshalb bei JEDEM Aufruf mit "executable file not found" fehl, unabhängig
vom tatsächlichen Zustand des Dienstes -- Docker markierte den Container dauerhaft unhealthy,
`health_monitor.sh` versuchte ihn alle 5 Minuten neu zu starten (erfolglos, weil der Healthcheck
ja weiterhin fehlschlägt, ganz gleich wie oft neu gestartet wird) und verschickte wiederholt
Alarm-Mails. **Fix:** Healthcheck auf Node's eingebautes `fetch()` umgestellt (kein zusätzliches
Paket nötig, Node 20 hat globales `fetch` bereits eingebaut):
```yaml
test: ["CMD", "node", "-e", "fetch('http://localhost:3210/health').then(r=>process.exit(r.ok?0:1)).catch(()=>process.exit(1))"]
```
Reine compose-Konfig-Änderung, kein Image-Rebuild zwingend nötig (der Healthcheck steht in
`docker-compose.yml`, nicht im Dockerfile) -- mit dem nächsten `git pull && docker compose up -d`
(bzw. `--build`, funktioniert genauso) aktiv.

> **Zusätzlicher Stolperstein beim erstmaligen Einrichten des Health-Monitor-Crons auf einem
> NICHT-root-Cron-Nutzer** (wie Patricks `admin`-User): `/var/log` gehört auf den meisten
> Systemen `root` und ist für andere Nutzer nicht beschreibbar -- ein Cron-Eintrag, der per
> `>> /var/log/eeg-health.log` in eine noch NICHT existierende Datei schreiben will, scheitert
> deshalb lautlos (Cron mailt Fehler standardmäßig lokal zu, was hier nicht eingerichtet ist).
> Einmalig die Datei als root anlegen und dem Cron-Nutzer übergeben, danach funktioniert das
> reine Anhängen (`>>`) auch ohne Schreibrecht auf das Verzeichnis selbst:
> ```bash
> sudo touch /var/log/eeg-health.log
> sudo chown <cron-user>:<cron-user> /var/log/eeg-health.log
> ```

### Abrechnung: "Abrechnungslauf nicht gefunden" trotz gerade angelegtem Lauf (Vorfall 26.09.2026, gelöst)
Patrick wollte erstmals eine MONATLICHE Testabrechnung (Juli) statt der üblichen Quartalsabrechnung
durchführen -- Lauf unter `/portal/billing` angelegt, EDA-Datei hochgeladen (taucht unter "EDA
Imports" korrekt auf), aber ein Klick auf "Rechnungs-Entwürfe berechnen" lieferte "Abrechnungslauf
nicht gefunden", obwohl der Lauf nachweislich existierte -- auch nach Löschen und Neu-Anlegen.

**Ursache:** `POST /portal/billing/generate` (und ebenso `/portal/billing/release`) hat -- anders
als JEDE andere `/portal/billing/*`-Route -- **kein `DB::setCommunity($communityId)` vor dem Aufruf
von `Billing::generateDrafts()`/`Billing::finalize()`** gesetzt. Beide Methoden lesen den Lauf aber
als ALLERERSTE Abfrage per rohem `SELECT * FROM billing_runs WHERE id = ?` (ohne `community_id` in
der WHERE-Klausel), noch bevor sie selbst irgendwo `DB::setCommunity()` aufrufen. Row-Level-Security
(`database/init.sql`: `CREATE POLICY community_isolation ON billing_runs USING (community_id =
current_setting('app.community_id', true)::uuid)`) verwirft dadurch bei dieser einen Abfrage
AUSNAHMSLOS JEDE Zeile -- `app.community_id` war für diese Anfrage schlicht noch nie gesetzt worden
(jede PHP-FPM-Anfrage startet mit einer frischen DB-Verbindung, kein zeilenübergreifender Zustand).
Der Lauf existierte die ganze Zeit völlig korrekt, RLS hat ihn nur unsichtbar gemacht.

**Fix:** `DB::setCommunity($communityId);` in beiden Routen ergänzt, direkt nach
`Auth::activeCommunityId()`, exakt wie in allen anderen `/portal/billing/*`-Routen bereits üblich.
Reine Code-Änderung, kein Migrations-/Setup-Skript nötig -- mit dem nächsten
`git pull && docker compose up -d --build` aktiv. **Merksatz:** bei JEDER neuen Route, die eine
RLS-geschützte Tabelle per ID abfragt, MUSS `DB::setCommunity()` VOR der ersten Abfrage stehen --
sonst liefert die Abfrage nicht etwa einen Fehler, sondern lautlos "nichts gefunden", was leicht als
Datenproblem statt als fehlender Community-Kontext missverstanden wird.

> **Zweiter, unabhängiger Fund beim selben Test: EDA-Import warnte fälschlich vor "fehlenden"
> Zählpunkten später beigetretener Mitglieder.** Patrick: "schau auch immer, ob die Mitglieder in
> diesem Zeitraum schon dabei waren, weil ich immer wieder die Nachricht bekomme, dass gewisse
> Mitglieder noch nicht in dieser XLSX-Datei vorhanden sind -- das ist deswegen, weil ein paar erst
> später beigetreten sind." `eda-parser/parser.py` (`import_to_db()`) verglich bisher ausschließlich
> den HEUTIGEN `active`-Status eines Zählpunkts gegen den Datei-Inhalt, unabhängig vom importierten
> Zeitraum -- ein erst im September beigetretenes Mitglied ist heute aktiv, konnte im JULI-Export
> aber unmöglich auftauchen, wurde also fälschlich als "fehlender Zählpunkt, evtl. Abmeldung/
> Zählerwechsel" gemeldet. **Fix:** dieselbe Grenze wie in `Billing::generateDrafts()`
> (`member_since <= period_to`) jetzt auch hier, zusätzlich `member_until` für zwischenzeitlich
> ausgetretene Mitglieder berücksichtigt -- auf reine Kalendertage (`.date()`) verglichen, um
> dieselbe Zeitzonen-Fallgrube wie bei `Billing::missingMonths()` von vornherein zu vermeiden.
> Reine Code-Änderung, kein Migrations-/Setup-Skript nötig.
>
> **Offene Frage/Feature-Wunsch von Patrick, NICHT umgesetzt (Architektur-Entscheidung nötig):**
> er hätte gerne, dass beim Anlegen eines Abrechnungszeitraums direkt die zugehörige EDA-Datei
> ausgewählt/hochgeladen wird, damit beide "zusammengehören", statt wie bisher rein über
> überlappende Datumsbereiche zwischen `billing_runs` und `eda_imports`/`eda_measurements`
> zugeordnet zu werden (kein FK zwischen den beiden Tabellen). Mit dem Fix oben funktioniert die
> bestehende datumsbasierte Zuordnung aber bereits korrekt -- ob die explizite Verknüpfung als
> UX-Verbesserung trotzdem gewünscht ist, muss Patrick noch entscheiden, bevor das umgesetzt wird.

### Rechnungs-PDF: Feedback nach der ersten echten Abrechnung (26.09.2026)
Direkt nach dem oben behobenen Bug lief Patricks erste echte Testabrechnung durch -- drei
Kleinigkeiten am `rechnung.tex`-Layout bzw. der PHP-Erweiterung fielen ihm dabei auf:

**1. Gutschrift wurde als Minusbetrag mit falscher Beschriftung angezeigt.** Der grüne
Summenbalken zeigte bei einem Guthaben-Fall bisher `Ihr Guthaben` + den (negativen) Betrag,
z. B. "EUR -3,90". Patrick: "Ihr Guthaben sollten Sie ja keines haben, weil wir es ja
überweisen, und dann ist es kein Guthaben mehr, sondern eine Gutschrift" -- außerdem sollte der
Betrag dort positiv stehen ("Ihre Gutschrift von €3,90"), während die "Summe netto" in der
Tabelle darüber bewusst weiter negativ bleibt (korrekte Vorzeichenlogik in der Rechenkette).
**Fix:** `$summeLabel`/`$zahlungText` in `webapp/public/index.php` (Route
`/portal/invoices/:id/pdf`) von "Ihr Guthaben" auf "Ihre Gutschrift" umgestellt,
`SUMME_BRUTTO` (nur diese eine Variable, ausschließlich für den grünen Balken in
`rechnung.tex` verwendet) mit `abs()` immer positiv befüllt.

**2. Rechnungsnummer zu lang.** Bisher `RE-260001_RC108175_Muster_Erika` (Jahr+laufende Nummer +
Marktpartner-ID + Nach-/Vorname) -- Patrick: "bitte machen wir da nur die Rechnungsnummer, also
nur RE-260001. Die RC-Nummer und den Vornamen, Namen, lassen wir weg." `Billing::
generateDrafts()` baut die Nummer jetzt nur noch aus Präfix + laufender Nummer zusammen: die
dafür nicht mehr gebrauchte `slugName()`-Hilfsfunktion (Umlaut-Transliteration für den Namensteil)
wurde mit entfernt, da danach ungenutzt. Bereits vergebene, längere Nummern bestehender
Rechnungen bleiben unverändert (kein rückwirkendes Umbenennen, nur neu berechnete/künftige
Läufe betroffen).

**3. Kopfzeilen-Layout (Kundennummer/Rechnungs-Nr./Rechnungsdatum/Fälligkeitsdatum/SEPA-
Mandatsref.) noch nicht final geklärt.** Alle rechtsbündig (`rechnung.tex`, Zeile ~116:
`\begin{tabular}{@{}r r@{}}`) -- Patrick: technisch noch im Druckbereich, "passt mir nicht so
ganz", wollte aber selbst erst nach der kürzeren Rechnungsnummer (Punkt 2) nochmal draufschauen,
ob es dann schon passt. **Bewusst NICHT blind geändert** -- abwarten, ob nach Punkt 2 noch
Bedarf besteht, und falls ja, was genau (z. B. linksbündig statt rechtsbündig, oder eine andere
Spaltenaufteilung) gewünscht ist.

Reine Code-Änderungen (Punkt 1 + 2), kein Migrations-/Setup-Skript nötig -- mit dem nächsten
`git pull && docker compose up -d --build` aktiv.

### Gutschriften-Übersicht zum manuellen Überweisen (26.09.2026)
Es gibt aktuell keinen automatisierten SEPA-Überweisungsexport (nur den bestehenden SEPA-
**Lastschrift**-XML-Export für die einzuziehenden, positiven Salden) -- Guthaben von Mitgliedern
(negativer Rechnungssaldo) musste Patrick bisher Rechnung für Rechnung einzeln öffnen, um
Kontoinhaber/IBAN/Betrag/Verwendungszweck fürs Online-Banking abzutippen. Patrick: "wäre es cool,
wenn da für jeden Kunden so ein Pop-up aufkommt mit dem Kontoinhaber des Bankkontos, IBAN, dem
Betrag und dem Verwendungszweck [...] wo du einfach nur auf einen Button klickst und diese Zeile
dann kopierst [...] dann kann ich somit die Überweisungen alle nach der Reihe gleich an meine
Kunden die Gutschrift überweisen."

**Neue Seite `/portal/billing/gutschriften`** (optional `?run_id=<uuid>` auf einen einzelnen Lauf
eingeschränkt, siehe Nachbesserung unten -- `webapp/src/views/pages/billing_gutschriften.php`,
Route in `webapp/public/index.php`) listet Rechnungen mit
`saldo_eur < 0` auf -- pro Mitglied eine Karte mit Kontoinhaber, IBAN, (falls vorhanden) BIC,
Betrag und Verwendungszweck (= Rechnungsnummer, gleiches Muster wie bei der bestehenden
Mahnungs-E-Mail-Vorlage). **Bewusst EIN eigener „Kopieren"-Button je Einzelfeld statt eines
kombinierten Textblocks** -- ein Online-Banking-Überweisungsformular (Sparkasse u.ä.) hat für
Empfänger/IBAN/Betrag/Verwendungszweck ohnehin getrennte Eingabefelder, ein kombinierter String
müsste beim Einfügen wieder manuell zerlegt werden. Technisch reines `navigator.clipboard.
writeText()` auf `data-value`-Attributen (kein externes Skript, kein CSP-Konflikt -- läuft über
dasselbe `script-src 'self' 'unsafe-inline'` wie die übrigen Inline-`<script>`-Blöcke im Portal),
Button zeigt kurz "Kopiert!" als Feedback.

Betrag und Reihenfolge der Felder folgen exakt derselben Logik wie im Rechnungs-PDF selbst
(`taxBreakdown()` auf `saldo_eur`, `abs($tax['brutto'])` -- siehe "Rechnungs-PDF: Feedback..."
oben) und derselben Anzeigename-Priorität (`invoice_name` > `company_name` > Titel+Vor-/Nachname)
wie `renderInvoicePdf()`, damit der hier angezeigte Betrag garantiert mit dem "Ihre Gutschrift
von ..."-Betrag auf dem PDF übereinstimmt. Kontoinhaber fällt auf den Anzeigenamen zurück, wenn
das separate `kontoinhaber`-Feld leer ist (Konto läuft auf den Namen des Mitglieds -- Regelfall,
siehe Kommentar bei der Beitritts-Route).

**Erreichbar auf zwei Wegen:** (1) automatisch direkt nach `/portal/billing/release`, wenn der
gerade freigegebene Lauf mindestens eine Gutschrift enthält (statt wie bisher immer zurück zur
Abrechnungsliste); (2) jederzeit später über einen neuen "Gutschriften"-Button bei bereits
abgeschlossenen (`status = 'done'`) Läufen in `/portal/billing`.

**Für den Demo-Zugang komplett gesperrt** (`denyDemoPage()`, wie beim WLAN-Info-Feld oder dem
Mitglied-Bearbeiten-Formular) -- echte Kontoinhaber-Namen/IBANs sind dieselbe Sensibilität wie
dort, eine maskierte Version einer reinen Kopier-Liste wäre ohnehin witzlos.

Reine Code-Änderung (neue Route + neue View, keine neue Tabelle/Spalte -- alle nötigen Felder
existieren bereits auf `members`/`invoices`), kein Migrations-/Setup-Skript nötig -- mit dem
nächsten `git pull && docker compose up -d --build` aktiv.

> **Nachbesserung (26.09.2026): Gutschriften als abhakbare Erledigungsliste + Login-Erinnerung +
> E-Mail bei fälliger SEPA-Lastschrift.** Patrick, direkt nach dem ersten Feature oben: "Passt
> das auch, einen Abhaken mit „Überweisung durchgeführt" [...] irgendwie so? Erst wenn's
> durchgeführt ist, soll es dann irgendwo weg sein, damit ich auch nicht vergesse, dass ich noch
> Geld an meine Mitglieder überweisen muss. Vielleicht darf das auch beim Admin und beim Obmann
> jedes Mal bei jedem Login auftauchen [...] und er kommt dann genau mit einem „Später"- oder
> einem „Jetzt durchführen"-Button auf die Seite." Zusätzlich: "Ich möchte gerne per E-Mail
> verständigt werden, wenn die Pre-Notification-Zeit vorbei ist [...] damit ich jetzt die
> SEPA-Lastschrift-XML-Datei bei der Sparkasse hochladen [...] kann."
> ```bash
> cd /opt/eeg-platform
> git pull origin main
> docker compose exec -T timescaledb psql -U eeg -d eeg_platform < database/migrate_20260926.sql
> docker compose up -d --build
> ( crontab -l 2>/dev/null; echo "0 8 * * * cd /opt/eeg-platform && docker compose exec -T webapp php < scripts/sepa_faelligkeit_check.php >> /var/log/eeg-sepa-faelligkeit.log 2>&1" ) | crontab -
> ```
> **1. Gutschriften abhaken.** Neue Spalte `invoices.gutschrift_ausgezahlt_at` -- solange NULL,
> gilt eine Gutschrift als offen. `/portal/billing/gutschriften` (ehemals `/portal/billing/:id/
> gutschriften`, jetzt ohne `:id` und mit optionalem `?run_id=` -- Route + View gemeinsam für den
> Einzellauf-Aufruf UND die kommunityweite Sicht über alle Läufe genutzt) zeigt nur noch OFFENE
> Gutschriften und bekommt pro Karte einen Button "Überweisung durchgeführt"
> (`POST /portal/billing/gutschriften/:invoiceId/erledigt`), der die Spalte setzt -- danach
> verschwindet die Karte aus der Liste. Bewusst KEIN separates "Abbrechen" als eigener
> Datenbank-Zustand: der native Browser-`confirm()`-Dialog vor dem Absenden (gleiches Muster wie
> überall sonst im Portal, z.B. beim Löschen eines Laufs) IST die Abbrechen-Möglichkeit -- ein
> zusätzlicher persistenter "abgebrochen"-Status hätte keinen erkennbaren Nutzen (nichts wurde ja
> tatsächlich unternommen, es bleibt einfach offen).
>
> **2. Login-Erinnerung für Obmann/Platform-Admin.** `layouts/portal.php` zählt bei jedem
> Seitenaufruf (mit aktiver Community, nicht im Demo-Zugang) die offenen Gutschriften der
> Community; sind es mehr als 0 UND wurde die Erinnerung in dieser Login-Sitzung noch nicht mit
> "Später" weggeklickt (`$_SESSION['gutschriften_reminder_dismissed']`), erscheint ein Modal
> (gleiches Muster wie das bestehende Pre-Launch-Popup) mit der Anzahl offener Gutschriften und
> zwei Buttons: "Später" (`POST /portal/gutschriften-erinnerung/spaeter`, blendet das Modal nur
> für den Rest DIESER Sitzung aus) und "Jetzt durchführen" (Link zu
> `/portal/billing/gutschriften`, schließt das Modal nicht dauerhaft -- solange noch offene
> Gutschriften bestehen, erscheint es beim nächsten Seitenaufruf wieder, außer "Später" wurde
> geklickt). Die Dismiss-Flag wird -- exakt wie beim Pre-Launch-Popup -- in
> `Auth::establishSession()` bei JEDEM Login zurückgesetzt, erscheint also wirklich bei jedem
> neuen Login erneut, nicht nur einmal pro Browser-Sitzung.
>
> **3. E-Mail bei fälliger SEPA-Lastschrift.** Neue Spalten `billing_runs.
> sepa_xml_heruntergeladen_at` (wird beim ERSTEN Download der Sammellastschrift-XML unter
> `/portal/billing/:id/sepa-xml` gesetzt, per `COALESCE()` nur einmalig) und `billing_runs.
> sepa_faelligkeit_erinnerung_gesendet_at` (verhindert eine doppelte Mail bei mehrfachem
> Cron-Lauf). Neues Skript `scripts/sepa_faelligkeit_check.php` (gleiches Aufruf-/Empfänger-Muster
> wie `scripts/health_alert.php`, aber pro Community statt platform-weit) prüft täglich alle
> freigegebenen Läufe: ist `released_at + communities.sepa_prenotification_days` (Standard 14
> Tage, dieselbe Frist wie bei der SEPA-Vorabinformation) verstrichen, die Sammellastschrift-XML
> aber noch NICHT heruntergeladen, UND enthält der Lauf überhaupt einzuziehende (nicht nur
> Gutschrift-)Rechnungen, geht eine Mail an alle `manager`-Nutzer dieser Community. Ein reiner
> Gutschriften-Lauf (keine einzuziehende Rechnung) wird dabei sofort als "erledigt" markiert,
> ohne eine Mail zu verschicken -- er braucht ja keine SEPA-Einreichung.
>
> Reine Code-/Migrations-Änderung, kein weiteres Setup-Skript nötig -- mit dem nächsten
> `git pull && docker compose up -d --build` (bzw. der obigen Migration + dem Cron-Eintrag) aktiv.

### EDA-Datenqualitäts-Detailbericht (01.10.2026)
Mit Quartalsende Q3 (Juli-September) stand die erste echte Quartalsabrechnung an. Patrick wollte
vor der Freigabe selbst nachvollziehen können, ob die EDA-Daten dafür schon reif sind, statt
erst beim (automatisch durch `Billing::datenqualitaetProblem()` ohnehin gesperrten) Freigabe-
versuch davon zu erfahren: "kannst du mir [...] sagen, wie viele Daten fehlerhaft sind, also L3?
Wie viele sind L2 und wie viele sind L1? Wenn Daten L2 oder L3 sind, mir sagen, von welchem
Kunden und von welchem Zeitraum [...] ob ich jetzt wirklich die 60 Tage [...] habe, oder ob ich
schon abrechnen kann."

Bisher zeigte `/portal/eda/upload` pro Import nur eine Gesamtzahl ("X belastbar" = L1+L2
zusammengefasst, "Y L3") -- ohne Aufschlüsselung nach L1/L2 getrennt und ohne zu sagen, WELCHE
Zählpunkte/Mitglieder konkret betroffen sind.

**Neue Funktion `edaQualityReport($communityId, $periodFrom, $periodTo)`**
(`webapp/public/index.php`) liest `eda_measurements` für den Zeitraum aus und liefert sowohl die
Zähler (L1/L2/L3 getrennt, nicht mehr zusammengefasst) als auch eine Detailliste jedes NICHT-L1-
Datensatzes (Mitglied, Zählpunkt, Typ, Monat, Qualität) -- gemeinsamer Partial
`webapp/src/views/partials/eda_quality_report.php`, genutzt an zwei Stellen:

1. **Sofort nach jedem Upload** auf `/portal/eda/upload` (für den gerade importierten Monat).
2. **Nachträglich für bereits bestehende Importe** über einen neuen "Details ansehen"-Link in
   der "Bisherige Importe"-Tabelle (sobald L2 oder L3 vorkommt) -> neue Route
   `GET /portal/eda/imports/:id/quality` + View `eda_import_quality.php` -- damit lassen sich
   auch Juli und August (längst hochgeladen) nachträglich prüfen, ohne sie erneut hochzuladen.
   Für den Demo-Zugang komplett gesperrt (`denyDemoPage()`, zeigt echte Mitgliedernamen).

Die "Datenqualität"-Spalte in der Importliste zeigt jetzt ebenfalls L1/L2/L3 als drei getrennte
Badges statt der bisherigen "belastbar"-Zusammenfassung. Für eine komplette Quartalsabrechnung
(3 Monatsdateien) prüft Patrick damit einfach alle drei Monatsimporte einzeln durch -- eine
eigene quartalsübergreifende Aggregatsicht gibt es bewusst (noch) nicht, da jeder Monatsimport
ohnehin schon die komplette Aufschlüsselung für genau seinen Zeitraum liefert.

Reine Code-Änderung (keine neue Tabelle/Spalte -- `eda_measurements.quality` existiert bereits
seit Projektbeginn), kein Migrations-/Setup-Skript nötig -- mit dem nächsten
`git pull && docker compose up -d --build` aktiv.

> **Nachbesserung (01.10.2026): EDA-Mehrmonats-/Quartalsexport führt zu irreführender
> "Monat"-Anzeige -- entdeckt bei Patricks erstem echten Q3-Testlauf.** Patrick hatte statt der
> üblichen drei Monatsdateien EINE Datei mit frei gewähltem Zeitraum (01.07.-30.09.2026, ganzes
> Quartal auf einmal) aus dem EDA-Portal exportiert und hochgeladen -- der neue Detailbericht
> zeigte daraufhin 16 L3-Einträge, ALLE mit "Monat: September 2026", was auf den ersten Blick wie
> ein Darstellungsfehler aussah. **Tatsächliche Ursache, per direkter Analyse der hochgeladenen
> XLSX bestätigt:** EDA liefert bei einem selbst gewählten Mehrmonats-/Quartalszeitraum pro
> Zählpunkt NUR EINE Zeile für den KOMPLETTEN Zeitraum (`Zeitraum`-Spalte z. B.
> "02.07.2026-30.09.2026" statt einer eigenen Zeile je Kalendermonat), mit oft gemischter
> Datenqualität in einer Zelle ("L1,L2,L3") -- der Parser wertet das schon seit jeher korrekt auf
> den schlechtesten enthaltenen Wert ab (`_worst_quality()`), genau wie von der Datei selbst in
> der Spaltenbeschreibung verlangt ("L1 und L2 abrechnungs-relevant, L3 nicht"). `eda_measurements.
> time` speichert dabei das BIS-Datum des gesamten Zeitraums (hier 30.09., daher "September") --
> kein Bug, aber bei einer derart breiten Zeile irreführend, weil sie fälschlich nahelegt, nur der
> September sei betroffen, obwohl die Zeile den ganzen Quartalszeitraum repräsentiert. Kein
> Einfluss auf den Rechnungsbetrag selbst (`Billing::generateDrafts()` summiert ohnehin nur über
> den ganzen Zeitraum, egal ob als eine große oder drei kleinere Zeilen gespeichert) -- betrifft
> ausschließlich die Darstellung/Granularität der Datenqualitätsprüfung.
>
> **Fix:** `edaQualityReport()` erkennt jetzt selbst, ob der abgefragte Zeitraum mehr als ~35 Tage
> umfasst (`is_multi_month`); ist das der Fall, wird statt eines einzelnen (irreführenden)
> Monatsnamens die ECHTE Zeitspanne angezeigt ("Juli – September 2026" bzw. Spaltenüberschrift
> "Zeitraum" statt "Monat"), zusätzlich ein erklärender Warnhinweis im Bericht selbst, der
> empfiehlt, für eine monatsgenaue Aufschlüsselung stattdessen die einzelnen Monate getrennt zu
> exportieren/hochzuladen.
>
> **Empfehlung an Patrick für die laufende Q3-Abrechnung:** Juli und August zusätzlich/stattdessen
> einzeln als Monatsdateien hochladen (beide über einen Monat alt, sehr wahrscheinlich schon
> L1/L2) -- zeigt dann klar, dass nur der aktuelle Monat (September, naturgemäß noch nicht final
> abgeschlossen) die Freigabe blockiert, statt scheinbar das ganze Quartal. Die Freigabe selbst
> bleibt so oder so korrekt gesperrt, bis auch September nachweislich keine L3-Werte mehr hat --
> das ändert sich durch getrennte Monatsdateien nicht, nur die Übersichtlichkeit, WAS genau noch
> fehlt.
>
> Reine Code-Änderung, kein Migrations-/Setup-Skript nötig -- mit dem nächsten
> `git pull && docker compose up -d --build` aktiv.

> **Zweite Nachbesserung (01.10.2026): monatsgenaue Datenqualität -- die Information war die
> ganze Zeit schon in der Datei, wurde nur nicht gelesen.** Patrick, direkt im Anschluss an die
> obige Empfehlung: "Sieht man in der Monatsreport von den drei Monaten nicht auch die einzelnen
> Monate [...] wo man heraussehen kann, welcher Monat oder welcher Tag jetzt wirklich schuld ist
> via L3?" Per direkter `pandas`-Analyse der von Patrick hochgeladenen Quartalsdatei bestätigt:
> die **„Detailübersicht"**-Sheet (Abschnitt „Energiedaten je Zählpunkt", Spalten „Jahr"/„Monat")
> enthält -- unabhängig davon, ob ein Einzelmonat oder ein ganzes Quartal exportiert wurde --
> IMMER eine eigene Zeile je Zählpunkt UND Kalendermonat, jede mit eigener Datenqualität. Für
> Patricks konkrete Datei: **jeder einzelne Zählpunkt zeigte Juli="L1,L2" und August="L1,L2"
> (sauber), nur September="L1,L2,L3"** -- die Empfehlung aus der ersten Nachbesserung (Juli/
> August separat exportieren, um das zu sehen) war also technisch richtig, aber unnötig: dieselbe
> Information steckt schon in der EINEN bereits hochgeladenen Quartalsdatei, nur in einem
> bisher ungenutzten Sheet-Abschnitt.
> ```bash
> cd /opt/eeg-platform
> git pull origin main
> docker compose exec -T timescaledb psql -U eeg -d eeg_platform < database/migrate_20261001.sql
> docker compose up -d --build
> ```
> **Fix:** neue Tabelle `eda_measurement_quality_monthly` (Zählpunkt + Kalendermonat + Qualität +
> Vollständigkeit, UPSERT bei jedem Import). `eda-parser/parser.py`s `_parse_detailuebersicht()`
> liest jetzt zusätzlich diese Monatszeilen aus (`LoadResult.monthly_quality`), `import_to_db()`
> schreibt sie in die neue Tabelle. `edaQualityReport()` (PHP) bevorzugt diese Tabelle, sobald sie
> für den abgefragten Zeitraum Einträge hat -- zeigt dann pro L2/L3-Eintrag den ECHTEN, exakten
> Kalendermonat statt nur der groben Zeitraum-Angabe aus der ersten Nachbesserung oben (die als
> Fallback für ältere Importe von VOR diesem Update bestehen bleibt, weil für die noch keine
> monatsgenauen Daten in der DB existieren). Ein TAGESGENAUER Stand (Patricks "oder welcher Tag")
> liefert EDA in keinem der beiden Sheets -- nur Monatsgranularität ist möglich.
>
> **Für Patricks laufende Q3-Abrechnung:** einfach dieselbe bereits hochgeladene Quartalsdatei
> nach diesem Update erneut hochladen (Duplikat-Überschreiben ist erlaubt, solange der Zeitraum
> noch nicht abgerechnet ist) -- zeigt dann sofort korrekt "nur September betroffen" an, ohne
> dass Juli/August zusätzlich einzeln exportiert werden müssen.
>
> Reine Code-/Migrations-Änderung, kein weiteres Setup-Skript nötig -- mit dem nächsten
> `git pull && docker compose up -d --build` (bzw. der obigen Migration) aktiv.

> **Dritte Nachbesserung (01.10.2026): Absturz beim ersten echten Test, weil die Migration auf
> Patricks Server noch fehlte -- plus ein zweiter, dabei entdeckter Bug (Demo-Zählpunkte lösten
> immer eine falsche "Fehlender Zählpunkt"-Warnung aus).** Patrick hat die oben beschriebene
> Quartalsdatei direkt erneut hochgeladen, bevor `migrate_20261001.sql` tatsächlich auf seinem
> Server gelaufen war (das fehlte in der letzten Anweisung an ihn) -- der Import-Lauf stürzte
> deshalb mit `psycopg2.errors.UndefinedTable: relation "eda_measurement_quality_monthly" does
> not exist` ab. Kein Datenverlust: `import_to_db()` erreicht `conn.commit()` erst ganz am Ende,
> die Exception kam mitten in der neuen Monats-Insert-Schleife, PostgreSQL verwirft die
> komplette, noch nicht committete Transaktion automatisch beim Verbindungsende -- der Import war
> schlicht nie passiert, nichts Halbes/Kaputtes blieb in der DB zurück. Fix: einfach die Migration
> nachholen (siehe Befehl oben) und die Datei erneut hochladen.
>
> **Zweiter Fund, aus derselben Fehlermeldung:** der Traceback zeigte u. a. `Fehlender
> Zählpunkt: DEMO-EINSPEISER1-001` und `Fehlender Zählpunkt: DEMO-VERBRAUCHER1-001` --
> die beiden fiktiven Demo-Zählpunkte (siehe Update vom 05./06.09.2026) sind bei uns zwar
> `active = true`, tauchen aber naturgemäß NIE in einem echten EDA-Export auf. Die "aktiv"-Abfrage
> in `eda-parser/parser.py` (Grundlage der "Fehlender Zählpunkt"-Warnung) hatte bisher keinen
> `is_demo`-Filter -- hätte also bei JEDEM künftigen echten EDA-Import dauerhaft zwei
> Fehlalarm-Warnungen erzeugt. Fix: `AND m.is_demo = false` in dieser Abfrage ergänzt (gleiches
> Muster wie `Billing.php`/die Mitgliederstatistik, die Demo-Mitglieder bereits an anderer Stelle
> ausschließen). Die übrigen beiden in Patricks Lauf gemeldeten Zählpunkte
> (`AT007000095600000010190002587601A`, `AT0070000956010000000000000001464`) sind echte,
> bestehende Zählpunkte -- das ist die Warnung wie vorgesehen, kein Bug; wert, dass Patrick selbst
> kurz prüft, ob diese beiden Mitglieder/Zählpunkte im Exportzeitraum tatsächlich fehlen sollten.
>
> Reine Code-Änderung, kein weiteres Migrations-/Setup-Skript nötig -- mit dem nächsten
> `git pull && docker compose up -d --build` aktiv.

### Externer Sicherheits-Scan (25.09.2026): drei echte Lücken gefunden und behoben, ein
### gemeldeter Befund als Fehlalarm widerlegt
Patrick hat auf eigene Initiative zwei kostenlose externe Scanner (Cookiebot Mobile-Scan,
Sitechecker.pro "Website Safety Report") sowie später einen ausführlichen KI-generierten
Blackbox-Sicherheitsreport gegen `stromfueralle.at` laufen lassen und die Ergebnisse geteilt.
Drei reale Lücken bestätigt und noch am selben Tag behoben:

**1. Session-Cookie hatte in Produktion NIE das `Secure`-Flag.** Ursache: `webapp` terminiert
selbst nie TLS (das macht ausschließlich der externe nginx-Proxy, 10.0.0.144) und bekam den
`X-Forwarded-Proto https`-Header vom Proxy bisher nie an PHP weitergereicht --
`$_SERVER['HTTPS']` (geprüft in `Auth::start()`s `session_set_cookie_params(['secure' =>
isset($_SERVER['HTTPS'])...])`) war dadurch serverseitig IMMER leer, obwohl die Seite für jeden
Besucher ausschließlich über HTTPS läuft. **Fix:** neue `map $http_x_forwarded_proto
$fastcgi_https {...}`-Direktive + `fastcgi_param HTTPS $fastcgi_https;` in
`webapp/docker/nginx.conf` (bewusst als `map` statt fix `on`, damit lokale Entwicklung über
`docker-compose.override.yml` -- kein `X-Forwarded-Proto` dort -- weiterhin ohne Secure-Cookie-
über-Klartext-HTTP-Problem funktioniert). Live per `curl` verifiziert: `Set-Cookie: eeg_session=
...; secure; HttpOnly; SameSite=Lax`.

**2. nginx-/PHP-Version wurden an jeden Besucher/Scanner verraten -- auf ZWEI unabhängigen
Ebenen.** `webapp/docker/nginx.conf` bekam `server_tokens off;`, `webapp/docker/php.ini`
`expose_php = Off` (kein `X-Powered-By: PHP/x.y.z` mehr). **Wichtiger Zwischenfund:** die von
Sitechecker gemeldete `nginx/1.22.1` stammte entgegen meiner ersten (falschen) Vermutung NICHT
von `webapp` (das läuft auf `nginx/1.30.4`, per `docker compose exec webapp nginx -v` bestätigt),
sondern vom externen nginx-Proxy (10.0.0.144) selbst -- ein Reverse Proxy generiert seinen
`Server`-Header standardmäßig selbst aus der EIGENEN `server_tokens`-Einstellung, reicht NICHT
automatisch den Header des Backends durch. Auf 10.0.0.144 stand `server_tokens off;` in
`/etc/nginx/nginx.conf` bereits, aber ausgerechnet dort **auskommentiert** -- Fix war ein simples
Entkommentieren + `nginx -t && systemctl reload nginx` auf dem Proxy-Host, kein Repo-Code.

**3. `robots.txt`/`security.txt` fehlten, drei zusätzliche Security-Header fehlten.** Neue
`webapp/public/robots.txt` (sperrt Crawler pauschal aus `/portal/`, `/admin/`, `/api/`) und
`webapp/public/.well-known/security.txt` (RFC 9116, Kontakt `office@stromfueralle.at`, gültig
bis 25.09.2027) -- beide brauchen in `webapp/docker/nginx.conf` je einen eigenen
`location = ...`-Block mit `default_type text/plain;`, weil `.txt` nicht in nginx' `mime.types`
steht und sonst der globale `default_type application/octet-stream;` gegriffen und die Dateien
zum Download statt zur Anzeige angeboten hätte. Zusätzlich drei neue `add_header`-Zeilen im
`server{}`-Block: `Permissions-Policy` (deaktiviert ungenutzte Browser-APIs wie Kamera/Mikro/
Standort), `Cross-Origin-Opener-Policy: same-origin`, `Cross-Origin-Resource-Policy: same-site`
(bewusst "same-site" statt "same-origin", weil sonst `portal.`/`admin.`/`live.stromfueralle.at`
sich gegenseitig keine Ressourcen mehr einbetten dürften).

**Ein gemeldeter Befund als Fehlalarm widerlegt, kein Bug:** der Blackbox-Report bemängelte
fehlende Login-Ratenbegrenzung (13 Fehlversuche ohne erkennbare Bremse). `RateLimiter.php`
(seit dem OWASP-Audit vom 13.08.2026 vorhanden) implementiert tatsächlich beide dort
dokumentierten unabhängigen Zähler korrekt UND beide sind am Login (`POST /portal/login` in
`index.php`) tatsächlich verdrahtet: `isLoginBlocked()` prüft E-Mail-Zähler (Limit 5/15 Min)
ODER IP-Zähler (Limit 20/15 Min), `registerLoginFailure()` erhöht beide. Ein Test mit 13
Versuchen bleibt unter dem IP-Limit von 20 -- und sofern dabei (wie bei einem Blackbox-Test
üblich) unterschiedliche E-Mail-Adressen probiert wurden, erreicht keine einzelne davon je das
Einzel-Limit von 5. Kein Fix nötig, reines Schwellenwert-/Testmethodik-Artefakt, kein fehlender
Code.

Reine Code-Änderungen (Punkt 1 + 3), kein Migrations-/Setup-Skript nötig -- mit dem nächsten
`git pull && docker compose up -d --build` aktiv. Punkt 2's eigentlicher Fix lag außerhalb
dieses Repos (externer Proxy-Host).

> **DMARC behoben (26.09.2026):** `_dmarc.stromfueralle.at TXT "v=DMARC1; p=none;
> rua=mailto:office@stromfueralle.at; fo=1"` gesetzt (bewusst `p=none` -- reiner
> Beobachtungsmodus, blockt nichts. Nach einigen Wochen ohne Auffälligkeiten in den Reports auf
> `p=quarantine`, später `p=reject` hochstufen). Damit ist der komplette externe
> Sicherheits-Review vom 25./26.09.2026 abgeschlossen -- einziger bewusst unverändert
> gebliebener Punkt: `Domain=.stromfueralle.at` beim Session-Cookie (Report schlägt
> `__Host-`-Präfix vor -- würde aber den bereits bewusst gelösten "sofort ausgeloggt beim
> Domain-Wechsel"-Bug zwischen Haupt- und Portal-Domain wieder einführen, siehe
> Auth::start()-Kommentar, NICHT blind übernehmen).

> **F-03/F-07 behoben (26.09.2026, per Nachtest #2 bestätigt):** DKIM eingerichtet (die beiden
> von Microsoft 365 verlangten `selector1._domainkey`/`selector2._domainkey`-CNAMEs stehen im
> DNS, Signatur nachweisbar aktiv -- nur DMARC selbst fehlt noch, siehe oben) und CAA-Record
> (`stromfueralle.at CAA 0 issue "letsencrypt.org"`) gesetzt, beide extern verifiziert.
> **Eine Aussage aus Nachtest #2 stimmt NICHT mit dem überein, was wir selbst per `certbot
> certificates`/`curl` kurz vorher verifiziert hatten:** der Report behauptet, jede Domain
> (`stromfueralle.at`/`www.`/`portal.`) habe jetzt "ihr eigenes, einzelnes Zertifikat" --
> tatsächlich ist es weiterhin EIN gemeinsames Zertifikat (Lineage `stromfueralle.at`) mit allen
> drei Namen im SAN, exakt wie beim F-04-Fix eingerichtet (`CN=stromfueralle.at`, per curl auf
> beiden Domains bestätigt). Diese eine Behauptung im Report also nicht ungeprüft übernehmen --
> möglicherweise eine Fehlinterpretation des Scan-Ergebnisses auf der Report-Seite.

> **F-04 (traefik.stromfueralle.at) behoben (25.09.2026):** die `stromfueralle.at`-Zertifikats-
> Lineage auf dem Proxy-Host (10.0.0.144) enthielt `stromfueralle.at`, `www.stromfueralle.at`,
> `portal.stromfueralle.at` UND `traefik.stromfueralle.at` -- Letzteres hatte aber (siehe
> "www-Subdomain hinzufügen" oben) nie einen eigenen `server{}`-Block bekommen, wurde also von
> irgendeinem anderen vhost auf demselben Host mit dessen (falschem) Zertifikat beantwortet.
> Domain per `sudo certbot certonly --nginx --cert-name stromfueralle.at -d stromfueralle.at
> -d www.stromfueralle.at -d portal.stromfueralle.at` aus der Lineage entfernt (certbot fragt
> dabei explizit "You are also removing previously included domain(s): traefik.stromfueralle.at
> -- Did you intend to make this change?" -- (U)pdate bestätigen). **Stolperstein dabei:** ein
> erster Versuch OHNE `--cert-name` hat NICHT die bestehende Lineage aktualisiert, sondern eine
> zweite, parallele Lineage `stromfueralle.at-0001` angelegt (unbenutzt, da die vhost-Config
> weiterhin auf die ursprüngliche `stromfueralle.at`-Lineage zeigte) -- musste per `certbot
> delete --cert-name stromfueralle.at-0001` erst wieder entfernt werden, bevor der Befehl MIT
> `--cert-name` die eigentlich gewünschte, bestehende Lineage traf. **Merksatz:** beim Ändern
> (nicht nur Erweitern) der Domain-Liste einer bestehenden Zertifikats-Lineage IMMER
> `--cert-name <name>` explizit mitgeben -- sonst vergleicht certbot nur den Domain-NAMEN
> (nicht die Lineage) und legt bei jeder Abweichung stillschweigend eine neue Lineage an, ohne
> die eigentlich gemeinte zu berühren. Danach DNS-Eintrag für `traefik.stromfueralle.at` bei
> helloly gelöscht -- Subdomain löst seither gar nicht mehr auf, kein Zertifikatsfehler mehr
> möglich. `admin.`/`live.stromfueralle.at` bleiben weiterhin außerhalb der Zertifikats-Lineage
> (nie dokumentiert gewesen, unklar ob/wo sie aktuell per HTTPS erreichbar sein sollen -- kein
> Teil dieses Fixes, offene Frage für später).

> **F-10 (öffentliche Live-Anzeige bei wenigen Zählpunkten) -- Patricks bewusste Entscheidung,
> keine Aktion (25.09.2026):** "Da machen wir gar nichts, weil keiner weiß, ja, trotzdem, wem
> die 10 Punkte gehören, und das ist ganz egal. Ich brauche keine Mindestanzahl." Bleibt wie es
> ist -- `/api/live/:slug`/`/live/:slug` zeigt weiterhin die Summe über alle Zählpunkte einer
> EEG, unabhängig von deren Anzahl, kein Schwellenwert eingebaut.

> **Nachtest (25.09.2026) + Korrektur zu HSTS (F-01):** ein zweiter externer Blackbox-Test nach
> obigen Fixes bestätigte F-08 (`robots.txt`/`security.txt`) und F-09 (Permissions-Policy/COOP/
> CORP) als tatsächlich behoben -- UND lieferte damit den Beweis, dass `add_header`-Zeilen aus
> `webapp/docker/nginx.conf` unverändert bis zum Browser durchkommen, weil der externe
> nginx-Proxy Response-Header beim `proxy_pass` einfach weiterreicht. Das widerlegt meine
> vorherige Einschätzung, HSTS müsse auf dem externen Proxy-Host (10.0.0.144) ergänzt werden --
> `add_header Strict-Transport-Security "max-age=31536000" always;` steht jetzt stattdessen
> direkt in `webapp/docker/nginx.conf`, wie die übrigen Security-Header. Bewusst OHNE
> `includeSubDomains` (würde Browser zwingen, JEDE Subdomain nur noch über HTTPS zu laden) und
> ohne `preload` (quasi unumkehrbarer Schritt, eigene bewusste Entscheidung nötig, kein
> Automatismus) -- F-04 ist zwar seither bereinigt (`traefik.stromfueralle.at` komplett aus DNS/
> Zertifikat entfernt, siehe eigener Eintrag oben), aber `admin.`/`live.stromfueralle.at` stehen
> weiterhin NICHT im Zertifikat, also `includeSubDomains` weiterhin nicht ergänzen, bis das
> geklärt ist.

### Rechnungsnummern pro EEG statt plattformweit -- noch am selben Tag wieder verworfen (02.10.2026)
Im Rahmen der Abrechnungs-Fixes vom 02.10.2026 wurde `invoices.rechnungsnummer` zunächst von
einer globalen `UNIQUE`-Constraint auf `UNIQUE(community_id, rechnungsnummer)` umgestellt --
Beweggrund: seit das `RC<Marktpartner-ID>`-Präfix am 26.09.2026 aus der Nummer entfernt wurde
(nur noch "RE-\<Jahr\>\<laufende Nummer\>"), hätte die erste Abrechnung einer zweiten EEG mit
"RE-260001" theoretisch an einer bereits von einer anderen EEG vergebenen Nummer scheitern
können -- und jede EEG ist als eigenständiger Verein ohnehin für ihre eigene, lückenlose
Rechnungsnummerierung verantwortlich (§ 11 UStG).

**Patrick, noch am selben Tag:** "ich als Plattform für Strom für alle [...] könnte [sonst]
nicht unterscheiden, welche Rechnung für die eine Energiegemeinschaft und welche für die andere
ist [...] das werden wir schon plattformweit [...] und nicht für jede Energiegemeinschaft
einzeln wieder von 1 anfangen." Als Plattformbetreiber über mehrere EEGs hinweg ist für ihn die
eindeutige Identifizierbarkeit jeder einzelnen Rechnungsnummer wichtiger als eine für jede EEG
isoliert lückenlose Zählung -- eine bewusste, informierte Entscheidung trotz des damit
verbundenen Nachteils (eine einzelne EEG sieht in ihren eigenen Rechnungsnummern ggf. Lücken,
weil dazwischen Rechnungen anderer EEGs liegen).

**Fix:** `migrate_20261003.sql` setzt die Constraint auf eine einzige, globale
`UNIQUE(rechnungsnummer)` zurück. `Billing::generateDrafts()` ermittelt die laufende Nummer
seither wieder OHNE `community_id`-Filter (plattformweit `MAX(...)` je Jahr), der Advisory-Lock
zur Serialisierung ist ebenfalls global (`invoice_seq_global`) statt pro EEG. Betrifft nur die
Zählung/Eindeutigkeit selbst -- an Format ("RE-\<Jahr\>\<lfd. Nr.\>") und den übrigen
Abrechnungs-Fixes vom 02.10.2026 (Rechnungsdatum/Fälligkeit, PDF-Einfrieren, Lösch-Schutz)
ändert sich nichts.

**Merksatz:** bei einer Mehrmandanten-Plattform zwei unterschiedliche, teils widersprüchliche
Anforderungen an Rechnungsnummern im Kopf behalten -- (a) aus Sicht der einzelnen EEG (eigener
Verein) sollten sie lückenlos und nachvollziehbar sein, (b) aus Sicht des Plattformbetreibers
müssen sie über alle Mandanten hinweg eindeutig UND unterscheidbar sein. Beides gleichzeitig
geht nur mit einem EEG-spezifischen Bestandteil in der Nummer selbst (wie das ursprüngliche
`RC<Marktpartner-ID>`-Präfix) -- ohne einen solchen Bestandteil muss man sich für eines der
beiden entscheiden, wie hier für (b).

### Rechnungsnummern: dritte Kehrtwende am selben Tag -- jetzt pro EEG + RC-Nummer-Suffix (02.10.2026)
Direkt im Anschluss an die zweite Kehrtwende oben (zurück auf plattformweit fortlaufend) kam
noch am selben Tag die dritte: **Patrick:** "Leider machen wir es doch noch mal wieder zurück,
sodass jede Energiegemeinschaft von 1 anfängt. Wir machen das ja im Namen der
Energiegemeinschaft. Oder wir machen es doch mit der [...] RC-Nummer der jeweiligen
Energiegemeinschaft, weil man dann wirklich die [Rechnungen] auseinanderhalten kann. Aber dann
halt wirklich nur die RC-Nummer [...], weil den Namen [...] brauchen wir da nicht dabei."

Die Lösung erfüllt beide bisherigen, scheinbar widersprüchlichen Anforderungen gleichzeitig:
laufende Nummer wieder PRO EEG ab 0001 (jede EEG stellt ja in ihrem eigenen Namen aus, § 11 UStG
verlangt lückenlose Zählung pro ausstellendem Verein) -- UND zusätzlich die Marktpartner-ID
(RC-Nummer, OHNE Namen) als Suffix, z. B. `RE-260001_RC108175`. Da zwei EEGs nie dieselbe
Marktpartner-ID haben, ist die komplette Nummer dadurch automatisch plattformweit eindeutig,
ganz ohne einen gemeinsamen Zähler über alle EEGs hinweg -- Patricks eigentliches Bedürfnis
("als Plattform unterscheiden können, welche Rechnung zu welcher EEG gehört") ist damit
genauso erfüllt wie die lückenlose Pro-EEG-Zählung.

**Fix:** `Billing::generateDrafts()` lädt jetzt die Marktpartner-ID der EEG und bricht mit einer
klaren Fehlermeldung ab, falls sie noch nicht hinterlegt ist (verhindert, dass zwei EEGs ohne
RC-Nummer sich eine kollidierende Nummer teilen könnten). Die laufende Nummer wird wieder PRO
`community_id` ermittelt (MAX über `SUBSTRING(rechnungsnummer FROM ... FOR 4)` statt `RIGHT(...,
4)`, weil `RIGHT` seit dem Suffix die letzten 4 Zeichen der RC-NUMMER statt der laufenden Nummer
geliefert hätte), der Advisory-Lock ebenfalls wieder pro EEG statt global. Die globale
`UNIQUE(rechnungsnummer)`-Constraint aus der zweiten Kehrtwende (`migrate_20261003.sql`) bleibt
unverändert bestehen und wird durch den Suffix nie verletzt -- keine weitere Migration nötig.

**Merksatz, diesmal hoffentlich endgültig:** ein EEG-spezifischer Bestandteil IN der
Rechnungsnummer selbst (nicht eine plattformweite Zählung) ist der einzige Weg, der sowohl
"jede EEG zählt lückenlos in ihrem eigenen Namen" als auch "jede Nummer ist plattformweit
eindeutig/einer EEG zuordenbar" gleichzeitig erfüllt -- genau das war schon das allererste
Format vom 06.08.2026 (nur mit zusätzlichem Namen, den es jetzt nicht mehr braucht).

### Messe-Demo: Fiktive Einspeiser produzieren nachts -- Container lief in UTC statt Europe/Vienna (05.10.2026)
Patrick, kurz nach dem ersten echten Test des neuen Hintergrund-Schalters: "Ich verstehe
eigentlich nicht, warum jetzt die neuen Zugänge einspeisen. Ich weiß zum Beispiel, dass wir über
die Über-Nacht-Einspeisung [...] circa gerade 700 W habe. Ich habe gerade 1.500 W Einspeisung.
Das kann nicht sein, wenn bei den fiktiven Einspeisern nur von 6 bis 22 Uhr eingespeist wird."

**Ursache:** `demo_simulation_tick()` (`mqtt-subscriber/main.py`) las die aktuelle Uhrzeit bisher
über `time.localtime()` -- das liest die Zeitzone DES CONTAINERS, nicht die des Raspberry-Pi-
Hosts. Das Basis-Image `python:3.12-slim` hat kein System-`tzdata`-Paket installiert und nirgends
war `TZ` gesetzt, `time.localtime()` fiel deshalb stillschweigend auf UTC zurück. Bei einem Test
um ca. 21:50 Uhr österreichischer Zeit (CEST, UTC+2) las der Code dadurch "19:50 Uhr" -- laut
`messe_producer_envelope()` (Sonnenuntergang bei 20:00) noch ein kleiner, aber nicht
verschwindender Tageslicht-Faktor (~3,8 %), macht über alle 8 fiktiven Einspeiser aufsummiert
rund 500-550 W zusätzliche "Phantom-Einspeisung" -- passt ziemlich genau zur beobachteten
Differenz (700 W echt gemessen vs. 1.500 W angezeigt gesamt).

**Fix:** `mqtt-subscriber/requirements.txt` um das reine Python-Paket `tzdata` (IANA-Zeitzonen-
datenbank, keine System-/apt-Abhängigkeit, funktioniert unabhängig vom Basis-Image) ergänzt,
`demo_simulation_tick()` berechnet die Uhrzeit jetzt explizit über
`datetime.now(timezone.utc).astimezone(ZoneInfo("Europe/Vienna"))` statt sich auf die
(hier falsch konfigurierte) System-Zeitzone zu verlassen -- berücksichtigt automatisch auch den
Sommer-/Winterzeit-Wechsel.

**Merksatz:** in einem Docker-Container NIE von `time.localtime()`/`datetime.now()` ohne
`tz=`-Argument ausgehen, wenn eine bestimmte Zeitzone (nicht UTC) gemeint ist -- schlanke
Basis-Images (`-slim`, `-alpine`) haben standardmäßig kein `tzdata` installiert und laufen dann
lautlos in UTC, ganz unabhängig davon, in welcher Zeitzone der Host tatsächlich steht. Explizit
`zoneinfo.ZoneInfo(...)` verwenden (ggf. mit dem `tzdata`-Pip-Paket als Fallback) statt sich auf
eine implizit korrekt konfigurierte System-Zeitzone zu verlassen.

### Hero-Banner auf der Startseite lädt sichtbar langsam/grau nach (02.10.2026)
Patrick: "Dieses [Hero-Banner] braucht immer ziemlich lange, bis es geladen wird, wenn ich die
Seite aufrufe. [...] Weil das nämlich ziemlich schlimm aussieht, wenn's erst ein graues Bild ist
und dann langsam erst die Farbe kommt."

**Ursache:** das eigene Hero-Foto (`home.php`) hängt nur als CSS-`background-image` an `.hero` --
solche `url()`-Referenzen entdeckt der Browser-Preload-Scanner erst beim Aufbau der CSSOM
(nachdem das zugehörige `<style>` geparst und die Regel gematcht ist), nicht schon beim ersten,
sehr frühen HTML-Scan wie bei einem `<img src>`. Zusätzlich stand auf der ausliefernden Route
(`/hero-banner-image`, `webapp/public/index.php`) nur `Cache-Control: public, max-age=3600` --
unnötig kurz, obwohl die URL über `?v=<filemtime>` bereits dauerhaft inhalts-versioniert ist
(ändert sich automatisch bei neuem Upload) und unter derselben URL nie andere Bytes liefert.

**Fix:**
- `webapp/src/views/layouts/base.php`: neue, generische `$extraHead`-Konvention -- eine Seite
  kann vor dem `ob_start()` beliebiges zusätzliches `<head>`-Markup setzen, `base.php` gibt es
  (falls vorhanden) direkt vor `</head>` aus.
- `webapp/src/views/pages/home.php`: setzt darüber, wenn ein eigenes Hero-Foto hochgeladen ist,
  `<link rel="preload" as="image" fetchpriority="high" href="...">` auf exakt dieselbe
  `?v=<filemtime>`-URL wie das `background-image` -- der Browser lädt das Bild dadurch parallel
  zu allem anderen, statt erst nach dem CSS-Parsing.
- `webapp/public/index.php` (`/hero-banner-image`): `Cache-Control` von `max-age=3600` auf
  `public, max-age=31536000, immutable` verlängert -- gefahrlos, weil die URL bereits
  inhalts-versioniert ist.

**Merksatz:** ein per CSS-`background-image` eingebundenes, aber inhaltlich wichtiges Bild (hier:
der erste visuelle Eindruck der Startseite) verdient i. d. R. zusätzlich einen
`<link rel="preload">`-Hinweis im `<head>` -- der Preload-Scanner behandelt `background-image`
spürbar später als ein `<img src>` oder ein explizites Preload.

### Energiefluss-Animation: Glow-Trail bei der PV-Verbindung unsichtbar (02.10.2026)
Patrick, nach dem ersten Test der neuen rAF-Animation (PR #210): "Dieser Glow dahinter, sodass
es so ein bisschen mehr animiert aussieht, den haben wir jetzt nicht. Wir haben jetzt nur die
Kugel, die da hin- und herschwingt."

**Ursache:** Der schimmernde Trail hinter dem Punkt ist eine `<line>` mit
`stroke="url(#gradient)"`, der Gradient wurde aber ohne explizites `gradientUnits` erzeugt --
Default ist `objectBoundingBox`, ein Verlauf, der sich IMMER an der Bounding-Box der
gezeichneten Form orientiert (Standardrichtung waagrecht, x1=0%/x2=100%). Die PV->EEG-Verbindung
steht layoutbedingt immer exakt SENKRECHT übereinander (PV-Kreis liegt direkt über dem
EEG-Hub) -- die Bounding-Box des Trails hat für diese Verbindung also eine Breite von 0. Laut
SVG-Spezifikation kollabiert ein Gradient mit identischem Start-/Endpunkt (hier: beide
x-Koordinaten fallen bei Breite 0 zusammen) zu einem einzelnen Punkt und wird nur noch einfarbig
(ohne jeden Verlauf/Fade) gerendert -- optisch kaum von "nichts da" zu unterscheiden, vor allem
bei der ohnehin meist recht dezenten obersten Verbindung.

**Fix:** `gradientUnits="userSpaceOnUse"` statt des Defaults, dazu werden `x1/y1/x2/y2` des
Gradients jeden Animations-Frame explizit auf die AKTUELLEN Trail-Endpunkte gesetzt (dieselben
Koordinaten wie die Trail-Linie selbst) -- funktioniert dadurch unabhängig von der
Verbindungsrichtung (senkrecht/waagrecht/schräg). Zusätzlich Trail/Glow kräftiger gemacht
(längerer Trail, größerer Punkt, stärkerer drop-shadow-Blur) und das Netz-Symbol von einem
generischen Stecker-Icon auf ein selbst gezeichnetes Hochspannungsmast-Icon (`ph-pylon`)
umgestellt (Patrick: "können wir auch das Netzsymbol so wie bei mir in der Loxone-App nehmen,
also so einen Strommasten").

**Merksatz:** bei einem SVG-Gradient, der eine PER-JS BEWEGTE Form (Linie/Pfad) einfärbt, NIE auf
den Default `objectBoundingBox` verlassen, sobald die Form auch mal achsenparallel mit
Breite/Höhe 0 vorkommen kann (senkrechte oder waagrechte Linie) -- `userSpaceOnUse` mit jeden
Frame aktualisierten Koordinaten ist die robuste Variante, unabhängig von der Ausrichtung.

### Energiefluss-Animation: "rein dann raus"-Reihenfolge lief doch gleichzeitig + Netz-Icon sah wie ein Windrad aus (02.10.2026)
Patrick, nach einem Live-Test mit echtem Server-Screenshot: "Der Mast sieht aus wie ein Windrad,
und das zuerst rein und dann raus, das ist es noch nicht. Das ist alles gleichzeitig."

**Ursache Reihenfolge:** `makeConnector()` (`assets/js/energy-flow.js`) hat für ALLE drei
Verbindungen hart `phase: 'in'` gesetzt. `applyValues()` hat bei jedem Datenrefresh nur
`conn.netz.phase` passend zum Vorzeichen aktualisiert -- `conn.verbrauch.phase` blieb dadurch für
immer auf dem Erzeugungs-Default `'in'` stehen, obwohl Verbrauch konzeptionell IMMER Phase "raus"
sein muss (ein Mitglied bezieht nur, speist nie zurück in den Pool). PV und Verbrauch liefen
dadurch fälschlich in derselben Phase gleichzeitig, statt dass Verbrauch auf das Eintreffen in
der EEG-Kugel wartet. **Fix:** `makeConnector()` nimmt jetzt eine explizite initiale Phase
entgegen (PV fest `'in'`, Verbrauch fest `'out'`, Netz weiterhin dynamisch durch `applyValues()`
aktualisiert). Mit einem lokalen Playwright-Test verifiziert: PV bewegt sich jetzt allein zuerst,
nach der Pause erst gemeinsam Netz+Verbrauch -- exakt wie beabsichtigt.

**Ursache Icon:** Das erste `ph-pylon`-Icon (PR #211) hatte vier diagonale "Arme" mit kleinen
Endstücken, symmetrisch um die Mastspitze angeordnet -- bei 28px Darstellungsgröße liest sich
das als rotierender Rotor/Windrad statt als Hochspannungsmast. **Fix:** Icon neu gezeichnet ohne
diagonale Elemente -- stattdessen ein klassischer waagrechter Querarm mit zwei kurzen
Isolator-"Tropfen" an den Enden (wie bei einer echten Überlandleitung), auf einem schlicht
verjüngten Mast mit Standfuß. Eine Zwischenfassung ganz ohne Querarm/Isolatoren (nur Mast) wurde
verworfen, weil sie wie ein Verkehrshütchen aussah -- der Querarm ist also kein Zierrat, sondern
das Element, das das Icon überhaupt erst als Strommast erkennbar macht.

**Merksatz:** ein Icon, das bei voller Größe eindeutig aussieht, kann bei der tatsächlichen
Einsatzgröße (hier 28px in einem 64px-Kreis) etwas völlig anderes suggerieren, v.a. bei radial-
symmetrischen Elementen (Rotationsassoziation). Immer in der TATSÄCHLICHEN Zielgröße prüfen, nicht
nur vergrößert.

### Netz-Icon: dritter Anlauf -- einfaches Linien-Icon statt gefülltem Silhouetten-Icon (02.10.2026)
Patrick, nach dem zweiten Icon-Versuch (PR #212): "Der Mast schaut immer scheiße aus. Bitte mach
einfach einen schönen Strommasten. Von mir aus einen senkrechten Stab und einen quer oder zwei,
die wie ein Zelt, ein spitzes Zelt, zusammenhängend oben, einen quer."

**Ursache:** Beide bisherigen Versuche waren gefüllte Silhouetten-Icons (massive Flächen), analog
zum Stil der übrigen Phosphor-Icons -- bei einem so schlanken, strebenreichen Motiv wie einem
Hochspannungsmast wirkt eine ausgefüllte Fläche bei 28px aber zwangsläufig wie ein unförmiger
Klecks statt wie ein Mast.

**Fix:** Komplett neu als reines Strich-/Linien-Icon (`stroke`, `fill="none"`, kein
Silhouetten-Pfad mehr) -- exakt Patricks Beschreibung: ein senkrechter Stab (Mitte), zwei
schräge Streben, die oben spitz zusammenlaufen ("Zelt"), plus zwei waagrechte Querbalken.
Deutlich klarer erkennbar als beide Vorversionen, weil es nicht mehr auf Flächen-Kontrast,
sondern auf eine einfache Strichzeichnung setzt -- für ein derart schlankes Motiv die passendere
Technik als ein gefülltes Silhouetten-Icon.

**Merksatz:** nicht jedes Motiv passt zum Silhouetten-Stil des restigen Icon-Sets -- bei dünnen,
strebenartigen Formen (Mast, Antenne, Gerüst) liefert ein Linien-Icon (`stroke` statt `fill`)
bei kleiner Darstellungsgröße zuverlässiger ein erkennbares Ergebnis.

### Netz-Icon: vierter Anlauf -- detailliertes Referenzbild nachgezeichnet (02.10.2026)
Patrick schickte ein Referenzbild eines klassischen Hochspannungsmast-Icons (Gittermast mit
zwei Traversen, Isolator-Girlanden, X-verstrebtem sich verjüngendem Turm) mit der Bitte: "Bitte
verwende die Maße. Beziehungsweise zeichne die jetzt nach, aber in dieser Form."

**Vorgehen:** Das Referenzbild lässt sich nicht direkt in eine 28px-Kachel übernehmen -- bei
voller Detailtreue (mehrere X-Gitterfelder, 3-4 Zacken pro Isolator) wird das Icon bei
tatsächlicher Einsatzgröße zu einem unleserlichen dunklen Klecks (mit Playwright verifiziert,
siehe Screenshot-Vergleich). Lösung: dieselbe Formensprache (Spitze oben, zwei Traversen mit
Isolator-Zacken, X-verstrebter Turm mit zwei Beinen) beibehalten, aber die Wiederholungsanzahl
reduziert (2 Gitterfelder statt 4, 2 Zacken pro Isolator statt 4) -- bei voller Größe optisch
sehr nah am Referenzbild, bei 28px klar als Hochspannungsmast erkennbar statt als Klecks.
Pfad-Koordinaten wurden parametrisch per Python-Skript erzeugt (nicht von Hand abgezählt), um
Mast-Verjüngung, Traversen-Spannweite und Gitterfelder konsistent zu berechnen -- mit Playwright
sowohl in Originalgröße (Vergleich mit dem Referenzbild) als auch in der tatsächlichen
28px/64px-Kreis-Darstellung (alle drei Farbzustände, über die echte Sprite-Datei via `<use>`
über einen lokalen HTTP-Server) verifiziert.

**Merksatz:** bei einem als Vorlage gegebenen Referenzbild immer zuerst klären/prüfen, in
welcher Zielgröße das Ergebnis tatsächlich angezeigt wird -- volle Detailtreue und Lesbarkeit
bei Icon-Größe stehen oft im Widerspruch, die Lösung ist meist eine reduzierte Wiederholungszahl
bei gleicher Formensprache, nicht ein kategorisch anderes Icon.

### Netz-Icon: fünfter Anlauf -- Linien-Icon mit stroke="currentColor" rendert in Safari anders als im Test (02.10.2026)
Patrick, nach PR #214 (Linien-Icon mit `stroke="currentColor"`, in diesem Chat per Playwright/
Chromium verifiziert): "Sieht doch immer anders aus." Auf Nachfrage bestätigt: das Icon sieht in
seinem echten Browser (Safari, siehe Screenshot) anders aus als in den hier gezeigten
Vorschau-Bildern.

**Ursache (vermutet, nicht in Safari selbst nachstellbar -- diese Umgebung hat nur Chromium für
Playwright installiert):** Das Icon aus PR #214 war das ERSTE Icon in der Sprite-Datei, das
`stroke="currentColor"` + `fill="none"` statt des sonst durchgängig verwendeten Musters
`fill="currentColor"` (über die globale `.icon{fill:currentColor}`-Regel in app.css, OHNE eigene
fill/stroke-Attribute auf dem Pfad) nutzt. Safari/WebKit ist bekannt dafür, Cross-Browser-
Eigenheiten bei `<use>` + referenziertem `<symbol>` + eigenen Fill/Stroke-Präsentationsattributen
zu haben (u.a. Vererbung von `currentColor` durch die Use-Shadow-Grenze, Umgang mit
Präsentationsattributen vs. geerbten Werten) -- in dieser Entwicklungsumgebung lässt sich das
nicht gegentesten, da hier nur Chromium (über Playwright) zur Verfügung steht.

**Fix:** Icon komplett auf das bewährte, plattformweit einheitliche Muster umgestellt -- jede
vorher als `stroke`-Linie gezeichnete Strecke wird jetzt rechnerisch in ein gefülltes Rechteck
("Quad") umgewandelt (klassische Stroke-zu-Fill-Konvertierung), Gelenkpunkte bekommen zur
Vermeidung von Kerben an den Verbindungsstellen einen kleinen gefüllten Kreis (rundes Join).
Das Icon besteht dadurch jetzt aus purem `fill`, ganz ohne eigenes `stroke`/`fill`-Attribut auf
dem Pfad -- exakt dasselbe Rendering-Verfahren wie alle anderen, seit Monaten unauffällig
funktionierenden Icons dieser Sprite-Datei (Sonne, Gebäude, etc.).

**Merksatz:** in einem Icon-Set, das durchgängig auf EINE Technik setzt (hier: gefüllte
Silhouetten über eine globale `currentColor`-Fill-Regel), nicht als einziges Icon auf eine
andere Technik (hier: `stroke`) wechseln, auch wenn sie in der eigenen Testumgebung funktioniert
-- Browser-Eigenheiten bei weniger gängigen Kombinationen (hier `<use>` + `stroke` +
`currentColor`-Vererbung) lassen sich ohne Zugriff auf den jeweiligen Browser (hier: Safari)
nicht zuverlässig vorab ausschließen. Bei Unsicherheit das bereits bewährte Verfahren
nachbilden, statt ein neues einzuführen.

### Netz-Icon: sechster Anlauf -- schlichter Holzmast statt Gittermast-Turm (02.10.2026)
Patrick schickte mitten in der Sitzung ein weiteres, schlichteres Referenzbild (ein einzelner
gerader Mast mit zwei Querarmen, Isolator-Knubbeln an den Enden, dünnen Streben darunter) mit:
"Die Masten brauchen unten bitte noch ein paar Beine. Können wir bitte einfach diesen neuen
Mast-Icon vielleicht einfacher [machen]."

**Umsetzung:** Icon komplett neu aufgebaut -- weg vom sich verjüngenden Gittermast-Turm (zwei
Beine, X-verstrebter Korpus) aus den PR #213/#214/#215, hin zu einem einzelnen geraden Mast mit
zwei Querarmen (schmaler oben, breiter unten), kleinen Isolator-Punkten an den Enden und dünnen
V-Streben darunter -- UND, wie gewünscht, ein paar kurze gespreizte Standbeine am unteren Ende.
Erster Entwurf hatte deutlich zu dicke Querarme/Isolatoren (wirkte dadurch klobig/blob-artig,
siehe erster Screenshot-Vergleich dieser Session) -- auf eine durchgängig dünne, gleichmäßige
"Strichstärke" für Mast, Querarme, Streben UND Beine vereinheitlicht, das traf die gewünschte
Leichtigkeit des Referenzbilds deutlich besser.

**Technik unverändert aus dem vorherigen Fix (PR #215):** auch dieses Icon besteht nur aus
gefüllten Flächen (jede Linie als gefülltes Rechteck, jedes Gelenk als kleiner gefüllter Kreis),
kein `stroke`-Attribut -- vermeidet die in PR #215 gefundene Safari-Rendering-Diskrepanz von
Anfang an. In der tatsächlichen 28px/64px-Kreis-Darstellung über die echte Sprite-Datei
verifiziert.

### Beitrittserklärung-PDF: Unterschriften "schweben" neben/über der Linie statt darauf zu sitzen (03.10.2026)
Patrick schickte sein eigenes Test-PDF mit konkretem Feedback: die SEPA-Unterschrift (schwarz
ausgekritzeltes Feld) sitzt zu weit links statt mittig auf ihrer Linie; die Haupt-Unterschrift
unten ("Unterschrift (Kontoinhaber:in / Mitglied)") und das Datum bei "Ort, Datum" schweben
sichtbar über ihrer Linie statt darauf aufzuliegen; zusätzlich sind die Mitglieds-/Rechnungsdaten
oben (Name, Anschrift, Telefon, Geb.-Dat., E-Mail) bei Online-Ausfüllung schlecht lesbar.

**Ursache Positionierung:** Die Unterschrift-Bilder wurden per `\makebox[0pt][l]{\raisebox{...}
[0pt][0pt]{\includegraphics{...}}}` eingebunden -- eine bewusste Technik, damit das Bild über der
Linie "schwebt" statt sie nach unten zu schieben (Breite UND Höhe 0, beeinflusst also den
restlichen Satz nicht). `[l]` verankert das Bild dabei aber am LINKEN Rand der Null-Breiten-Box,
also am Anfang der Linie -- nicht in deren Mitte.

**Ursache Unterschrift-"Schweben":** Die Unterschrift-Canvas im Browser ist immer 600x180px groß,
tatsächlich unterschrieben wird aber nur in einem kleinen Teil davon -- der Rest bleibt
transparent (`clearRect()`, kein weißer Hintergrund im Bitmap). Ohne Zuschnitt landet das ganze,
größtenteils leere 600x180-PNG in der PDF; wie weit die eigentliche Tinte von Bild-Ober-/Unterkante
entfernt ist, hängt dadurch rein vom Zufall ab, wo genau der Unterschreibende innerhalb der
Fläche gezeichnet hat -- nicht von der LaTeX-Positionierung.

**Fix:**
- Neues `\floatsig{<halbe Linienbreite>}{<Anhebung>}{<Bild>}`-Makro (in allen drei betroffenen
  LaTeX-Vorlagen: `beitrittserklaerung_formular.tex`, `bezugsvereinbarung.tex`,
  `einspeisevereinbarung.tex`) -- verschiebt den Null-Breiten-Anker auf die Linienmitte
  (`\hspace`), zentriert das Bild dort (`\makebox[0pt][c]`) und macht die Verschiebung danach
  wieder rückgängig, damit die nachfolgend gezeichnete `\rule` unverändert am linken Rand
  beginnt. Ersetzt den alten, links-verankerten `\makebox[0pt][l]`-Mechanismus überall dort, wo
  er vorkam (auch in den beiden Vertragsvorlagen, nicht nur in der Beitrittserklärung).
- Neue gemeinsame Funktion `trimSignatureCanvas()` (`assets/js/signature-pad-trim.js`): schneidet
  die Unterschrift-Canvas client-seitig auf die tatsächlich gezeichnete Fläche zu (Bounding-Box
  der nicht-transparenten Pixel, plus etwas Rand), BEVOR sie als PNG gespeichert wird --
  eingebunden an allen drei Stellen, an denen eine Unterschrift-Canvas erfasst wird
  (Beitrittsformular, Vertragsunterschrift des Mitglieds, eigene Unterschrift im
  Obmann-Profil/Einstellungen). Dadurch hat das gespeicherte PNG überhaupt erst eine
  aussagekräftige Bounding-Box, auf die sich `\floatsig` sinnvoll zentrieren/andocken lässt.
  **Wichtig:** betrifft nur NEU erfasste Unterschriften -- bereits gespeicherte (wie Patricks
  eigenes Test-PDF) bleiben unbeschnitten, bis neu unterschrieben wird.
- "Ort, Datum"-Zeile: Abstand zwischen Text und Linie von `0.35cm` auf `0.08cm` reduziert.
- Mitglieds-/Rechnungsdaten-Tabelle: Schrift von `\footnotesize` auf `\small` angehoben.

**Merksatz:** eine Null-Größen-Box (`\makebox[0pt]`), die bewusst keinen Platz im Satzspiegel
beansprucht, lässt sich trotzdem frei im Raum positionieren -- `\hspace` VOR der Box verschiebt
nur den (unsichtbaren) Anker, nicht den nachfolgenden Satzfluss, solange man die Verschiebung mit
`\hspace{-...}` danach wieder aufhebt. Und: ein Bild, dessen eigene Bounding-Box viel transparenten
Leerraum um den eigentlichen Inhalt enthält, lässt sich in LaTeX nicht zuverlässig zentrieren --
das Zuschneiden gehört an die Quelle (hier: beim Erfassen im Browser), nicht in die
Positionierungs-Logik der Vorlage.

### Vorlagen-Fixes erreichten den Server nie -- persistentes Volume fror .tex-Dateien seit dem allerersten Start ein (04.10.2026)
Patrick testete den Unterschrift-Fix aus dem vorherigen Vorfall erneut und schickte drei frische
Test-PDFs (Ropper, Ostermann, "wd") sowie die auf seinem Server tatsächlich aktive
`beitrittserklaerung_formular.tex` mit: "irgendwas passt noch nicht, weil jetzt irgendeine
Zentimeterangabe auch bei der Unterschrift dabei ist." Alle drei PDFs zeigten tatsächlich
"3.25cm2pt" als sichtbaren Klartext direkt über jeder Unterschrift.

**Ursache:** `entrypoint.sh` (latex-service) hat beim ALLERERSTEN Start (leeres Volume
`/opt/eeg/latex-templates`) früher pauschal ALLE mitgelieferten Standard-Vorlagen einmalig ins
Volume kopiert, damit latex-service nicht ohne jede Vorlage dasteht. Das Volume hat seitdem
Vorrang vor der im Image mitgelieferten Fassung -- unabhängig davon, ob ein Admin die jeweilige
Datei je über `/admin/templates` tatsächlich angepasst hat. Jedes künftige
`git pull && docker compose up -d --build` lieferte dadurch zwar ein neues Image mit frischen
Standard-Vorlagen, das Volume blieb aber für IMMER auf dem Stand des allerersten Starts
eingefroren -- `beitrittserklaerung_formular.tex` auf dem Server kannte das neu eingeführte
`\floatsig`-Makro (siehe vorheriger Vorfall) deshalb schlicht nicht. LaTeX behandelt einen
unbekannten Befehl mit Argumenten nicht als Fehler, der die PDF-Erzeugung abbricht, sondern gibt
die Argumente ersatzweise als Klartext aus -- daher "3.25cm2pt" direkt im Dokument statt eines
sofort sichtbaren Fehlers. Betraf vermutlich nicht nur die drei heute geänderten Vorlagen,
sondern grundsätzlich jede .tex-Datei, die seit dem allerersten Start nie über die
Admin-Oberfläche neu hochgeladen wurde -- jeder in der Vergangenheit gemergte Vorlagen-Fix könnte
auf demselben Weg nie auf dem Produktivserver angekommen sein, ohne dass das bisher aufgefallen
wäre.

**Fix:**
- `latex-service/service.js`: neue `resolveTemplatePath()` -- Volume hat Vorrang, fällt aber
  jetzt live auf `templates-default` (im Image) zurück, wenn die angeforderte Datei im Volume
  fehlt. Exakt dasselbe Zwei-Ebenen-Muster wie `adminFilePath()` in `webapp/public/index.php`
  für Logo/Hero-Banner (das Problem existierte dort NICHT, weil dieses Muster dort von Anfang an
  so gebaut war).
- `entrypoint.sh`: das einmalige pauschale Hineinkopieren beim ersten Start entfernt -- mit dem
  neuen Fallback in `service.js` überflüssig (und würde das Problem bei jeder Neuinstallation
  sofort wieder reproduzieren).
- **Einmaliger manueller Schritt auf dem Produktivserver nötig** (kein Code kann das von hier aus
  nachholen): die bereits im Volume eingefrorenen, nie über die Admin-Oberfläche tatsächlich
  angepassten .tex-Dateien müssen einmalig gelöscht werden, damit der neue Fallback überhaupt
  greifen kann -- siehe `docs/BETRIEBSHANDBUCH.md`, Eintrag vom 04.10.2026.

**Merksatz:** ein "Volume hat Vorrang vor Image"-Muster für Admin-Customization braucht IMMER
einen Fallback beim tatsächlichen Lesezugriff (nicht nur eine einmalige Kopier-Aktion beim
Containerstart) -- sonst "versteinert" jede Datei, die auch nur zufällig einmal ins Volume
gelangt ist (und sei es nur, weil ein Setup-Skript sie dorthin kopiert hat), auf dem Stand genau
dieses Zeitpunkts, für immer, ohne dass das irgendwo sichtbar wird. Admin-Customization-Dateien
sollten nur dann im Volume landen, wenn sie tatsächlich über die dafür vorgesehene
Upload-Funktion hochgeladen wurden -- nie durch einen pauschalen Kopiervorgang im Hintergrund.

### Unterschrift-Zuschnitt zweite Runde: Führungslinie im Canvas statt reiner Tinten-Bounding-Box (04.10.2026)
Zusätzlich zum obigen Vorfall wünschte sich Patrick eine konzeptionelle Verbesserung: "wenn ich
mit Ropper unterschreibe, [sollen] die Ps unter die Linie gehen [...] wir können [...] eine Linie
im Unterschriftsfeld machen, auf der man fast ganz unten unterschreibt. Wenn man unter die Linie
kommt, ist es noch unter der Linie."

**Vorherige Fassung (voriger Vorfall):** schnitt die Unterschrift-Canvas auf die Tinten-
Bounding-Box zu und zentrierte diese Box komplett mittig auf die gedruckte Linie. Dadurch sitzt
JEDE Unterschrift gleich mittig, unabhängig davon, ob sie eigentlich eher über oder unter einer
gedachten Grundlinie verläuft -- Unterlängen (z. B. das "p" in "Ropper") wurden dadurch nicht
wie beim echten Unterschreiben auf Papier unterhalb der Linie sichtbar.

**Fix:** sichtbare, gestrichelte Führungslinie im Unterschrift-Canvas (`.sig-pad-wrap`/
`.sig-pad-guide` in app.css, bei 72,22 % der Canvas-Höhe = Pixel 130 von 180 -- als Prozentwert,
nicht fixer Pixelwert, weil `settings.php` denselben 600x180-Canvas bei abweichender CSS-Höhe
anzeigt). Der Zuschnitt (`assets/js/signature-pad-trim.js`) verwendet jetzt ein FESTES Fenster
relativ zu dieser Führungslinie (95px darüber, 35px darunter) statt der dynamischen
Tinten-Bounding-Box -- nur falls die Tinte darüber hinausgeht, wird das Fenster erweitert, damit
nie etwas abgeschnitten wird. Die neue PHP-Funktion `signatureRaise()` berechnet daraus die
`\floatsig`-Anhebung proportional zur jeweiligen Bildhöhe (35/130 ≈ 26,9 % der Höhe) --
dieselbe feste Position der Führungslinie INNERHALB des Zuschnitts landet dadurch bei jedem Bild
exakt auf der gedruckten Linie, Unterlängen erscheinen automatisch darunter.

**Verifiziert** mit einer synthetischen Testunterschrift (wellenförmiger Hauptkörper auf der
Führungslinie + eine Schlaufe, die bewusst unter die Führungslinie reicht, wie ein "p"):
im gerenderten Test-PDF liegt der Hauptkörper exakt auf der gedruckten Linie, die Schlaufe
hängt sichtbar darunter -- genau wie gewünscht.

### Signatur-Fix am Server sichtbar wirkungslos, weil der Browser noch die alte signature-pad-trim.js auslieferte (04.10.2026)
Patrick hatte den Volume-Fix (siehe oben) bereits erfolgreich auf dem Server eingespielt --
"3.25cm2pt" war in seinem nächsten Test-PDF tatsächlich weg. Trotzdem lag die Unterschrift im
nächsten Test (diesmal mit einem nachgezeichneten Rechteck zur genauen Kontrolle) weiterhin
spürbar über der gedruckten Linie, nicht darauf: "Es hat sich noch nichts geändert."

**Ursache:** `webapp/docker/nginx.conf` liefert alle `.js`/`.css`/Bild-Dateien mit
`expires 30d; add_header Cache-Control "public, immutable";` aus -- ein für wiederkehrende
Besucher sehr aggressives Caching, bei dem der Browser die Datei bis zu 30 Tage lang nicht
einmal neu beim Server nachfragt. In `base.php` und `portal.php` hingen die `<script>`-Tags für
`password-toggle.js` und `signature-pad-trim.js` dabei OHNE den im Projekt an anderer Stelle
(`app.css`, Logos, Icon-Sprite, `energy-flow.js`) längst etablierten
`?v=<?= @filemtime(...) ?>`-Cache-Bust-Parameter. Patricks Browser führte dadurch sehr
wahrscheinlich weiterhin eine veraltete Fassung von `signature-pad-trim.js` aus (möglicherweise
sogar noch die Runde-1-Fassung von der vorigen Sitzung, nicht die neue Führungslinien-Logik
dieser Sitzung) -- unabhängig davon, wie oft der Server selbst korrekt neu gebaut wurde.

**Fix:** dieselbe `?v=<?= @filemtime(ROOT . '/public/assets/js/<datei>') ?: time() ?>`-Syntax,
die im Projekt bereits für andere statische Assets verwendet wird, auch für diese beiden
`<script>`-Tags in `base.php` und `portal.php` ergänzt. Dadurch ändert sich die URL automatisch
bei jeder Dateiänderung (der `filemtime()`-Zeitstempel ist Teil der URL) -- der Browser muss die
neue Version zwingend frisch laden, unabhängig vom `immutable`-Cache-Header.

**Merksatz:** diese nginx-Konfiguration cached JEDE `.js`/`.css`-Datei pauschal 30 Tage lang,
`immutable`. Ein neu zu einem Layout hinzugefügtes `<script>`- oder `<link>`-Tag auf eine eigene
(nicht per CDN/Vendor fremd verwaltete) Datei braucht deshalb von Anfang an den
`?v=<?= @filemtime(...) ?: time() ?>`-Cache-Bust -- sonst erreicht jede spätere Änderung an
genau dieser Datei wiederkehrende Besucher für bis zu 30 Tage nicht, ganz unabhängig davon, wie
korrekt der Server-seitige Deploy tatsächlich war. Ein scheinbar wirkungsloser Fix ist deshalb
immer auch ein Grund, diesen Cache-Bust-Parameter auf den beteiligten `<script>`/`<link>`-Tags
zu prüfen, bevor man an der eigentlichen Logik weitersucht.
