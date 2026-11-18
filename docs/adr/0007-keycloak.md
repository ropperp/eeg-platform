# ADR 0007: Keycloak als Anmeldedienst

**Status:** angenommen (18.11.2026), ersetzt [ADR 0004](0004-eigener-auth-stack.md). Umsetzung in Schritten,
Stand siehe unten.

## Kontext
Gewünscht sind: einmal anmelden und auf `portal.` und `admin.` (Steuerzentrale) angemeldet sein, Anmeldung
mit Google, und dass niemand dafür ein neues Passwort setzen muss. Dazu die Grundsatzentscheidung, für
Querschnittsaufgaben wie die Anmeldung auf bewährte, gepflegte Software zu setzen statt eigenen Code.
Verglichen: eigener Stack erweitern, Keycloak, Zitadel (selbst betrieben, kostenlos). Apple-Login entfällt
(kostenpflichtiges Entwicklerkonto).

## Entscheidung
- **Keycloak** (Open Source, CNCF, sehr verbreitet) als eigener Container `keycloak`, öffentlich unter
  `auth.<DOMAIN>`. Eigene Datenbank `keycloak` mit eigener Rolle in der bestehenden TimescaleDB, kein
  Zugriff auf die Plattform-Daten.
- **Keycloak beantwortet nur „wer ist das?“.** Rollen je EEG (Obmann, Mitglied), RLS und Fachlogik bleiben
  in der Plattform. Verknüpfung über `users.keycloak_id` (= `sub`), nie über die E-Mail-Adresse.
  Nur `platform_admin` wird als Realm-Rolle gespiegelt (für die Steuerzentrale ohne Datenbank).
- **Stille Übernahme der Passwörter:** Argon2id-Hashes übernimmt Keycloak direkt. Die bcrypt-Hashes (alle
  Konten vor dem 15.11.2026) prüft eine eigene, kleine Erweiterung `bcrypt-legacy`
  (`keycloak/providers`, bcrypt aus dem mitgelieferten BouncyCastle, keine Fremdbibliothek). Keycloak
  hasht nach der ersten erfolgreichen Anmeldung selbst auf Argon2 um. Kein Übergangs-Login, keine
  Infoseite, niemand muss etwas tun. TOTP (2FA) wird mit übernommen. Passkeys nicht (erst seit 17.11.2026,
  wenige Konten): die richtet man in Keycloak einmal neu ein.
- **Google:** Identity-Provider in Keycloak, standardmäßig aus (`GOOGLE_LOGIN_ENABLED`). Verknüpft nur mit
  einem bestehenden Konto gleicher, von Google bestätigter E-Mail; legt nie ein neues Konto an (Mitglied
  wird man über die Beitrittserklärung). Wer 2FA eingerichtet hat, muss sie auch nach Google bestätigen.
- **E-Mails** (Passwort vergessen usw.) bleiben in der Plattform (Microsoft Graph). Keycloak verschickt
  keine Mails; das Portal setzt ein neues Passwort über die Admin-API.
- **Absicherung:** Öffentlich routet Traefik nur `/realms/stromfueralle/` und `/resources/`. Admin-Konsole,
  Admin-API und `master`-Realm sind nur über `127.0.0.1:8081` am Server erreichbar (SSH-Tunnel). Die
  Webapp spricht Keycloak intern an (`http://keycloak:8080`) über das Dienstkonto des Clients `portal`,
  das nur Benutzer verwalten darf (Scope `kc-admin-api` nur auf Anfrage). Brute-Force-Schutz, Ereignis-
  Protokoll und Passwort-Richtlinie (Argon2) im Realm.
- Realm-Konfiguration als Datei `keycloak/realm/stromfueralle-realm.json`, Geheimnisse als Platzhalter
  aus der `.env`. Keycloak liest sie nur beim **ersten** Start; spätere Änderungen am Realm in der
  Admin-Konsole nachziehen (und in der Datei, damit eine Neuinstallation gleich ist).

## Schritte
1. **Dienst und Übernahme** (dieser Stand): Container (Compose-Profil `keycloak`), Realm, Erweiterung,
   `scripts/keycloak_setup.sh`, `webapp/bin/keycloak_import_users.php`, Migration `users.keycloak_id`.
   Für Mitglieder ändert sich noch nichts.
2. Portal-Anmeldung über Keycloak (OIDC mit PKCE), eigene Login-Seite im Plattform-Design,
   Abgleich bei Änderungen (Neuanlage, E-Mail, Sperre, Löschen, Passwort), Abmelden überall, Google.
3. App-API: `/api/v1/login` prüft das Passwort über Keycloak (Client `app`), App bleibt unverändert.
4. Steuerzentrale über Keycloak (nur `platform_admin`, mit zweitem Faktor), Passkey-Notzugang bleibt.
5. Alte Anmelde-Wege und `users.password_hash`/`totp_secret` entfernen, wenn alles über Keycloak läuft.

## Folgen
- Ein Container mehr (Java, ca. 0,5–1 GB RAM, `mem_limit: 1g`), Updates per Dependabot (`/keycloak`).
- Fällt Keycloak aus, kann sich niemand neu anmelden (bestehende Sitzungen laufen weiter). Die
  Steuerzentrale behält deshalb ihren Passkey-Notzugang (ADR 0006).
- `bcrypt-legacy` nutzt eine interne Keycloak-Schnittstelle, die sich bei Updates ändern kann.
  `tests/e2e/keycloak_run.sh` (CI) prüft Übernahme und Anmeldung bei jedem Image-Update. Sind alle Konten
  umgestellt, kann die Erweiterung entfallen.
- Tests: `tests/keycloak_mapper_test.php` (Unit), `tests/e2e/keycloak_run.sh` (echtes Keycloak +
  TimescaleDB: Import, Anmeldung mit altem Passwort, Umstellung auf Argon2, 2FA, Rollen, Rechte).
