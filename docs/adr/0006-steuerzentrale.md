# ADR 0006: Steuerzentrale als eigener Container mit Docker-Socket

**Status:** angenommen, umgesetzt am 17.11.2026

## Kontext
Für Updates und Störungen soll ein Platform-Admin ohne Terminal einen Wartungshinweis mit voraussichtlichem
Ende setzen, den Zustand aller Dienste sehen und einzelne Container neu starten können, auch dann, wenn
Webapp, Datenbank oder Traefik hängen. Eine Seite innerhalb der Webapp fiele genau dann mit aus.

## Entscheidung
- Eigener Container `konsole` (`konsole/`), erreichbar unter `admin.<DOMAIN>`. Der nginx-Proxy spricht ihn
  **direkt** auf Port 8090 an, nicht über Traefik. Keine `depends_on`-Abhängigkeit.
- Anmeldung **nur per Passkey** eines Platform-Admins. Es sind dieselben Passkeys wie im Portal: rpId ist
  `<DOMAIN>`, damit gelten sie auf allen Subdomains. Die Prüfung nutzt `webapp/src/WebAuthn.php` (beim Bauen
  kopiert). Die öffentlichen Schlüssel kommen aus `user_passkeys` und werden in `/opt/eeg/konsole/passkeys.json`
  gespiegelt. Fällt die Datenbank aus, gilt die letzte Kopie.
- Sitzung und Anmelde-Challenge als HMAC-signierte Cookies (Schlüssel aus `APP_SECRET` abgeleitet) mit
  `__Host-`-Präfix, `Secure`, `HttpOnly` und `SameSite=Strict`, 30 Minuten gleitend. Kein Redis, keine DB.
  Jede Änderung braucht ein CSRF-Token. Jede Anmeldung und Aktion landet in `/opt/eeg/konsole/audit.log`.
- **Docker-Socket:** Er ist Vollzugriff auf den Server (deshalb seit 15.11.2026 nicht mehr bei Traefik).
  Die Konsole nutzt davon nur Container auflisten, Logs lesen und Neustart, und das nur für eine feste Liste
  von Diensten (`konsole/src/Docker.php`). Kein Anlegen, kein `exec`, keine frei wählbaren Pfade. Der Prozess
  läuft als root mit nur `DAC_OVERRIDE` (für den Traefik-Ordner), `no-new-privileges` und schreibgeschütztem
  Dateisystem. Der Code ist klein und hat keine Fremdbibliotheken.
- **Wartungsmodus:** Die Konsole legt `docker/traefik/maintenance.yml` ab. Traefik liest den ganzen Ordner
  (`providers.file.directory`, `watch`), und ein Router mit hoher Priorität schickt alle öffentlichen Hosts
  auf die Wartungsseite der Konsole (HTTP 500, `Retry-After`; 503 würde der nginx-Proxy durch seine Ausfall-Seite ersetzen). Beim Beenden wird die Datei gelöscht.
  `GET /wartung.json` (CORS nur für die eigenen Hosts) liefert Text und Ende maschinenlesbar, z. B. für die
  statische Ausfall-Seite am nginx-Proxy.
- Eingebauter PHP-Server (`php -S`, 3 Worker): genügt für ein bis zwei gleichzeitige Admins und braucht keine
  weiteren Prozesse.

## Folgen
- Wer die Konsole übernimmt, hat den Server. Das Risiko ist bewusst angenommen und abgefedert durch
  Passkey-only, die feste Aktionsliste, CSRF, das Audit-Log und eine kleine Angriffsfläche.
- Der Pi selbst lässt sich aus der Konsole nicht neu starten (kein Host-Zugriff). Wird das gebraucht, dann
  über eine systemd-Path-Unit auf dem Host als eigener Schritt.
- Tests: `tests/konsole_test.php` (Anmeldung mit nachgebautem Passkey, Sitzung, CSRF, Wartung, Dienst-Liste)
  und `tests/e2e/konsole_run.sh` (echtes Docker und Traefik: Neustart, Logs, Wartung ein/aus, „Webapp weg,
  Konsole lebt“).
