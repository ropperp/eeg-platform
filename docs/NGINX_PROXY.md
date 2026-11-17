# nginx-Proxy & Netzwerk-Architektur

> Ausgelagert aus `CLAUDE.md` (16.11.2026). Die Kurzfassung steht dort unter „Netzwerk“. Der nginx-Proxy (10.0.0.144) ist ein eigener Host, seine Config liegt **nicht** im Repo.

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
webapp (nginx + PHP 8.4, internes Docker-Netz)
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
    include             snippets/eeg-maintenance.conf;
    location / {
        proxy_http_version 1.1;
        proxy_set_header   Connection        "";
        proxy_pass         http://10.0.0.250;
        proxy_set_header   Host              $host;
        proxy_set_header   X-Real-IP         $remote_addr;
        proxy_set_header   X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto https;
        proxy_intercept_errors on;
        proxy_connect_timeout 5s;
    }
}
server {
    listen 80;
    server_name stromfueralle.at www.stromfueralle.at;
    return 301 https://$host$request_uri;
}
```
Derselbe `include snippets/eeg-maintenance.conf;` + `proxy_intercept_errors on;` steht auch im
`portal.stromfueralle.at`-Block weiter unten in derselben Datei.

> `client_max_body_size 20M;` muss hier gesetzt sein (Standard-Limit von nginx ist nur 1 MB) — sonst
> liefert **dieser** nginx-Proxy bei Datei-Uploads (z. B. Ausweis-Scan, Beitrittserklärung-PDF) einen
> `413 Request Entity Too Large`, obwohl `webapp/docker/nginx.conf` und `php.ini` im Repo bereits
> korrekt auf 20M stehen. Nach Änderung: `sudo nginx -t && sudo systemctl reload nginx`.

> `www.stromfueralle.at` muss als SAN im Zertifikat enthalten sein (siehe "www-Subdomain hinzufügen" unten), sonst liefert nginx für www das Default-Zertifikat aus und Browser zeigen einen SSL-Fehler.

### Eigene Wartungsseite bei Serverausfall (seit 25.09.2026)
Statt nginx' eigener hässlicher Standard-Fehlerseite bzw. eines nackten Verbindungsfehlers im
Browser zeigt der nginx-Proxy jetzt eine eigene, zum Corporate Design passende Seite
("Kurzschluss" — animierte durchhängende/reißende Stromleitung zwischen zwei Masten), sobald
`10.0.0.250` (Traefik/Pi) ein 502/503/504 liefert oder gar nicht erreichbar ist:
- `/etc/nginx/error_pages/wartung.html` — eigenständige, selbst-enthaltene HTML-Datei (kein
  externes CSS/JS/Font, läuft auch wenn sonst alles down ist), `<meta http-equiv="refresh"
  content="30">` prüft automatisch alle 30s neu. Nur auf dem nginx-Proxy-Host abgelegt, nicht im
  Git-Repo (reiner Deploy-Artefakt für einen anderen Host, wie die restliche nginx-Proxy-Config).
- `/etc/nginx/snippets/eeg-maintenance.conf` — der wiederverwendbare `error_page`/`location`-Block,
  in beide `server{}`-Blöcke (Hauptdomain + `portal`) eingebunden.
- `proxy_intercept_errors on;` ist nötig, sonst reicht nginx Traefiks eigene 502/503/504-Antwort
  roh durch statt der eigenen Seite. `proxy_connect_timeout 5s;` sorgt dafür, dass ein komplett
  unerreichbarer Pi (nicht nur ein toter Webserver dahinter) schneller erkannt wird als mit
  nginx' Standard-Timeout.
- **Bewusst NICHT auf HTTP 404 erweitert:** die Wartungsseite fängt nur 502/503/504 ab. 404 lässt
  die App selbst über ihre eigene, echte `404.php`-Seite beantworten -- ein pauschales Abfangen
  aller 404 würde die auch überdecken.
- Voraussetzung dafür, dass ein nur gestopptes/abgestürztes `webapp` (nicht der ganze Pi) überhaupt
  ein 502/503/504 statt eines nackten Traefik-404 liefert: siehe "Webapp-Router" weiter oben

### Steuerzentrale `admin.stromfueralle.at` (seit 17.11.2026)
Eigener Container `konsole` auf dem Pi, Port **8090**, direkt angesprochen (nicht über Traefik), damit
sie auch bei hängendem Traefik erreichbar bleibt. Hintergrund: `docs/adr/0006-steuerzentrale.md`.

Datei `/etc/nginx/sites-available/71_admin_stromfueralle.conf`, zuerst **nur Port 80**. HTTPS ergänzt
danach `certbot --nginx -d admin.stromfueralle.at`, das ein eigenes Zertifikat holt und den 443-Block samt
Weiterleitung selbst einträgt.

```nginx
server {
    listen 80;
    server_name admin.stromfueralle.at;

    location / {
        proxy_http_version 1.1;
        proxy_set_header   Connection        "";
        proxy_pass         http://10.0.0.250:8090;
        proxy_set_header   Host              $host;
        proxy_set_header   X-Real-IP         $remote_addr;
        proxy_set_header   X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto $scheme;
        proxy_connect_timeout 5s;
        # Kein proxy_intercept_errors und keine Wartungsseite: die Konsole soll ihre eigenen
        # Antworten zeigen, und auf die Wartungsseite umzuleiten wäre hier sinnlos.
    }
}
```

Aktivieren:
```bash
sudo ln -s /etc/nginx/sites-available/71_admin_stromfueralle.conf /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d admin.stromfueralle.at        # danach https://admin.stromfueralle.at
```

Die Anmeldung (Passkey, `__Host-`-Cookie mit `Secure`) funktioniert **nur über HTTPS**, über reines
`http://` erscheint nur die Login-Seite.

**Wartungstext auch auf der statischen Wartungsseite** (`/etc/nginx/error_pages/wartung.html`, greift bei
502/503/504 und damit auch im Wartungsmodus, weil die Konsole dort 503 liefert): an passender Stelle
einfügen. Ist auch der Pi weg, bleibt der bisherige Standardtext stehen.
```html
<p id="wartung-text" style="white-space:pre-line"></p>
<p id="wartung-bis"></p>
<script>
fetch('https://admin.stromfueralle.at/wartung.json', { cache: 'no-store' })
  .then(function (r) { return r.json(); })
  .then(function (d) {
    if (!d.active) return;
    if (d.message) document.getElementById('wartung-text').textContent = d.message;
    if (d.until) document.getElementById('wartung-bis').textContent =
      'Voraussichtlich wieder erreichbar: ' + new Date(d.until).toLocaleString('de-AT', { dateStyle: 'short', timeStyle: 'short' }) + ' Uhr';
  })
  .catch(function () {});
</script>
```
