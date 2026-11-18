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
1. **Dienst und Übernahme** (18.11.2026): Container (Compose-Profil `keycloak`), Realm, Erweiterung,
   `scripts/keycloak_setup.sh`, `webapp/bin/keycloak_import_users.php`, Migration `users.keycloak_id`.
2. **Portal-Anmeldung über Keycloak** (19.11.2026), Schalter `AUTH_PROVIDER` (`keycloak-test` = nur über
   `/portal/login?keycloak=1`, `keycloak` = für alle):
   - OIDC mit PKCE (`KeycloakAuthController`, `KeycloakOidc`, `Jwt`): Rücksprung `/portal/auth/callback`
     (der Portal-Host leitet alles außer `/portal/*` um), ID-Token voll geprüft (RS256, iss, aud, exp, nonce).
     Danach baut das Portal seine Sitzung wie bisher (`Auth::establishSession`).
   - Abmelden beendet Portal- und Keycloak-Sitzung; Backchannel `/api/kc/backchannel-logout` beendet die
     Portal-Sitzung, wenn Keycloak die gemeinsame Sitzung beendet (sid -> PHP-Sitzung in Redis).
   - **Das Portal bleibt die Quelle** für Profil, Sperre, Passwort, 2FA und `platform_admin`. Trigger auf
     `users`/`user_roles` schreiben jede Änderung in `keycloak_sync_queue`; die Webapp arbeitet sie nach jeder
     Anfrage ab (`KeycloakSync`, bricht beim ersten Fehler ab), ein Cron (`bin/keycloak_sync.php`) holt
     Liegengebliebenes nach. Passwörter gehen als Hash, nie im Klartext. Die Keycloak-Kontoseite ist aus,
     damit dort nichts abweicht. Google verknüpfen/lösen und Keycloak-Passkeys gibt es im Portal-Profil
     (Anwendungs-Aktionen `idp_link:google`, `webauthn-register-passwordless`).
   - Login-Seite: Theme `keycloak/themes/stromfueralle` (Glas-Design), Links „Passwort vergessen“ und
     „Mitglied werden“ zum Portal über Client-Attribute `sfa.forgot.url`/`sfa.join.url`.
   - Rückfall: Ist Keycloak nicht erreichbar, zeigt `/portal/login` die bisherige Anmeldung mit Hinweis.
     Sonst ist die bisherige Anmeldung (Formular, 2FA, Portal-Passkey) gesperrt. Die Portal-Passkeys bleiben
     für den Notzugang (Rückfall, Steuerzentrale).
   - Die App meldet sich weiter am Portal an (`/api/v1/login`); weil die Passwörter im Portal gepflegt
     werden, bleibt das stimmig.
3. App-API über Keycloak (optional, nur wenn die App später echtes OIDC bekommt).
4. **Steuerzentrale über Keycloak** (19.11.2026): Client `konsole` (`Konsole\Sso`), wer im Portal angemeldet
   ist, kommt ohne Eingabe hinein; Zugang nur mit Realm-Rolle `platform_admin` (Claim `roles` im ID-Token).
   Weiter ohne Datenbank und Redis: Anmeldezustand im signierten Cookie, Backchannel-Abmeldung
   (`http://konsole:8090/kc/backchannel-logout`) sperrt die Sitzung über `/data/revoked.json`. Abmelden in
   der Konsole meldet überall ab. Passkey-Notzugang bleibt (`/?passkey=1`, automatisch bei Keycloak-Ausfall).
   **Restrisiko:** Die Konsole prüft nicht selbst, ob die Keycloak-Anmeldung mit zweitem Faktor war. Wer
   2FA oder einen Passkey eingerichtet hat, muss ihn bei jeder Keycloak-Anmeldung nutzen (auch nach Google).
   Deshalb: **Platform-Admins richten 2FA oder einen Passkey ein.**
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
