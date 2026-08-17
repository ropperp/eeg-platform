# Deploy: OWASP-Audit-Fixes (13.08.2026)

Alle Punkte aus dem Login-/Session-/Berechtigungs-Audit sind so gebaut, dass die Plattform ohne
jedes Zutun in ihrem bisherigen (unsicheren) Zustand weiterläuft, solange die jeweiligen
einmaligen Setup-Skripte nicht gelaufen sind -- **kein Big-Bang-Cutover, kein Zeitfenster, in
dem irgendetwas kaputt ist.** Trotzdem sollte der Reihe nach vorgegangen werden, damit jeder
Schritt sofort seine volle Wirkung entfaltet statt nur im Fallback-Modus zu laufen.

## Kurzfassung (copy-paste, auf dem Server im Repo-Root)

```bash
cd /opt/eeg-platform
git pull origin main

# 1. RLS-Migration (Schema-Änderung, läuft noch mit der alten Rolle -- sicher, keine Downtime)
docker compose exec -T timescaledb psql -U eeg -d eeg_platform < database/migrate_20260822.sql

# 2. Neue Container-Images bauen & starten (Security-Header, CSRF, Rate-Limiting,
#    TOTP-Verschlüsselung, Passwort-Leak-Check -- alles sofort aktiv, unabhängig von Schritt 3-5)
docker compose up -d --build

# 3. Eingeschränkte DB-Rolle einrichten (behebt den kritischsten Befund: RLS greift jetzt wirklich)
./scripts/db_runtime_role_setup.sh

# 4. Redis-Passwort einrichten (Session-Speicher bisher unauthentifiziert)
./scripts/redis_secure_setup.sh

# 5. Bestehende TOTP-Secrets nachträglich verschlüsseln (einmalig, optional -- neue/geänderte
#    Secrets sind ohnehin schon ab Schritt 2 verschlüsselt, das hier holt nur ALTE nach)
docker compose exec -T webapp php < scripts/migrate_encrypt_totp_secrets.php
```

Danach zur Kontrolle: `docker compose ps` (alle Container `healthy`), `docker compose logs webapp
--tail 50` (keine Fehler beim Start).

## Warum diese Reihenfolge, und warum keine andere Reihenfolge etwas kaputt macht

| Schritt | Was passiert, wenn er NOCH NICHT gelaufen ist | Was passiert, wenn er VORZEITIG (vor Schritt 2) läuft |
|---|---|---|
| 1. RLS-Migration | Policies fehlen/FORCE fehlt -- aber irrelevant, solange die App noch als Tabellenbesitzer verbindet (RLS greift für den Besitzer ohnehin nie) | Unproblematisch -- reine Schema-Änderung (neue Policy, `FORCE ROW LEVEL SECURITY`), betrifft nur eine Rolle, die noch gar nicht existiert |
| 2. `docker compose up -d --build` | Alter Code läuft weiter (kein Sicherheitsgewinn, aber auch kein Risiko) | -- (dieser Schritt bringt den neuen Code) |
| 3. `db_runtime_role_setup.sh` | `webapp/src/DB.php` etc. verbinden weiter als bisherige Rolle (`DB_USER`) -- **RLS bleibt wirkungslos, aber die Plattform funktioniert normal weiter** | Kann vor ODER nach Schritt 2 laufen -- legt nur eine zusätzliche Rolle an, `DB_USER` bleibt unverändert Schema-Besitzer |
| 4. `redis_secure_setup.sh` | Redis bleibt unauthentifiziert (wie bisher) -- Sessions funktionieren normal weiter | Kann vor ODER nach Schritt 2 laufen -- `Auth::start()` liest `REDIS_PASSWORD` erst zur Laufzeit |
| 5. `migrate_encrypt_totp_secrets.php` | Bestehende 2FA-Nutzer funktionieren trotzdem: `totpSecretFromStorage()` erkennt automatisch, ob ein Secret noch Klartext oder schon verschlüsselt ist | Kann jederzeit laufen, auch mehrfach (überspringt bereits verschlüsselte Secrets) |

Einzig **Schritt 1 vor Schritt 3** ist eine echte Empfehlung (nicht Pflicht): die neue
eingeschränkte Rolle aus Schritt 3 bekommt sonst zwar trotzdem ihre Rechte, aber die
`invoice_items`-Policy aus der Migration wäre bis Schritt 1 noch nicht da -- mit der
eingeschränkten Rolle (Schritt 3) UND ohne die Migration (Schritt 1) blieben Rechnungspositionen
kurzzeitig leer. In der oben stehenden Kurzfassung ist das bereits so einsortiert.

## Kein Datenverlust, keine Neu-Registrierung

- **RLS-Fix:** `DB_USER` bleibt unverändert Schema-Besitzer aller Tabellen. Die neue Rolle
  (`APP_DB_USER`, Default `eeg_app`) besitzt keine einzige Tabelle, bekommt nur `GRANT`s --
  bestehende Daten sind davon nicht betroffen.
- **TOTP-Verschlüsselung:** kein Nutzer muss 2FA neu einrichten, siehe Tabelle oben.
- **Redis-Passwort:** bereits eingeloggte Nutzer bleiben eingeloggt, ihre Session-Daten in Redis
  werden nicht angetastet, nur der Zugriffsweg wird abgesichert.
- **CSRF-Schutz / Rate-Limiting / Security-Header / Passwort-Leak-Check:** reine
  Verhaltensänderungen im Code, keine Datenmigration nötig.

## Danach: laufender Betrieb

- `scripts/db_runtime_role_setup.sh` und `scripts/redis_secure_setup.sh` sind sicher erneut
  ausführbar (idempotent) -- ändern ohne `--force` nichts an einem bereits gesetzten Passwort.
- `scripts/migrate_encrypt_totp_secrets.php` kann jederzeit erneut laufen (überspringt bereits
  verschlüsselte Secrets), macht danach aber nichts mehr -- kein Cron-Eintrag nötig.
- Bei einer **Neuinstallation** ruft `scripts/setup.sh` `redis_secure_setup.sh` und
  `db_runtime_role_setup.sh` automatisch mit auf (gleiches Muster wie
  `mqtt_secure_setup.sh`) -- nichts weiter zu tun. `migrate_encrypt_totp_secrets.php` ist dort
  nicht nötig, da eine frische Installation noch keine bestehenden TOTP-Secrets hat.
