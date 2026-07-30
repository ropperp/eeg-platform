# Claude-Sitzungslog

Fortlaufende Selbstdokumentation aller Claude-Arbeitssitzungen rund um die EEG-Plattform:
Datum, verwendetes Modell, Werkzeug und der professionell zusammengefasste Auftrag.
Neueste Einträge oben. Format und Regeln: Abschnitt „Selbstdokumentation" in `CLAUDE.md`.
Einträge aus Cowork/Claude Chat liegen zusätzlich im Obsidian-Vault unter
`eeg-platform-notes/logs/JJJJ-MM-TT.md`.

---

## 2026-07-30 (6) — Claude Code — Claude Sonnet 5
**Auftrag:** Nach dem Ausführen von `scripts/mqtt_secure_setup.sh` startete der
Mosquitto-Container nicht mehr (unhealthy) -- Produktions-Ausfall des MQTT-Brokers.
**Ergebnis:** Ursache gefunden: `server.key` wurde `root:root` mit `chmod 600` angelegt, der
`eclipse-mosquitto`-Container läuft aber als eigener, nicht-root User und konnte den privaten
Schlüssel nicht lesen -- Mosquitto behandelt einen Listener-Fehler als fatal, der gesamte
Broker startete deshalb gar nicht erst. Sofort-Fix per `chmod 644` durchgegeben, Skript für
künftige Läufe korrigiert und gemerged. Broker danach wieder erreichbar.

---

## 2026-07-30 (7) — Claude Code — Claude Sonnet 5
**Auftrag:** Bestätigung, dass der geplante Weg für externen MQTT-Zugriff (Fritzbox →
pfSense → Raspberry Pi direkt, ohne über den nginx-Proxy zu laufen) richtig ist; Wunsch nach
einer konfigurierbaren ESP-Offline-Schwelle (Minuten ohne Meldung, bis ein Gerät als offline
gilt) und einer klarer strukturierten Einstellungsseite (ESP32/Ausleseeinheit getrennt von
anderen Plattform-Daten).
**Ergebnis:** Netzwerk-Plan in CLAUDE.md/obsidian/Infrastruktur.md bestätigt/dokumentiert.
Neue Einstellung `esp_offline_after_minutes` (Standard 5, `migrate_20260823.sql`) --
`espOfflineAfterMinutes()` in index.php, verwendet in `member_detail.php` und
`manager_dashboard.php` statt des bisherigen reinen `esp_online`-Flags ohne Zeitbezug.
Platform-Admin-Einstellungsseite (`/admin/mail-settings`) jetzt in „Plattform-Technik"
(Testmodus, neu: ESP32/Ausleseeinheiten) und „E-Mail (Microsoft Graph)" unterteilt. Alle 77
Tests weiterhin grün.

---

## 2026-07-30 (5) — Claude Code — Claude Sonnet 5
**Auftrag:** TLS + Benutzername/Passwort auf dem MQTT-Broker einrichten; das eigene
Test-ESP32 liefert bereits Live-Werte, aber die kommen nur alle 30s (Heartbeat-Intervall,
sollte vom Live-Daten-Intervall getrennt und auf ~5s einstellbar sein); WLAN-Passwort fehlt
in der WLAN-Info-Anzeige (SSID/IP kommen an, Passwort nicht).
**Ergebnis:** `scripts/mqtt_secure_setup.sh` (neu, automatisch von `scripts/setup.sh`
mitaufgerufen) erzeugt ein selbstsigniertes Zertifikat + Zugangsdaten, Mosquitto verlangt
jetzt beides auf beiden Ports (1883 + 8883/TLS). ESP32-Firmware wählt automatisch
`WiFiClientSecure`, sobald Port 8883 konfiguriert ist. Neues, vom Heartbeat unabhängiges
`live-daten-intervall`-Feld (Standard 5s statt fix 30s). WLAN-Passwort-Bug gefunden und
behoben: wurde nur einmalig beim allerersten MQTT-Connect mitgeschickt statt bei jedem
Heartbeat -- genau der verpasste Moment war beim Testgerät die Ursache. Alle 77 Tests
weiterhin grün, Python-/Bash-/YAML-Syntax geprüft.

---

## 2026-07-30 (4) — Claude Code — Claude Sonnet 5
**Auftrag:** Nachfrage, ob die früher gewünschte Benachrichtigung bei unbekannter
Zählernummer tatsächlich umgesetzt ist (Patrick hat testweise einen ESP32 eingerichtet und
nichts davon gesehen); außerdem wo die WLAN-Diagnoseinfos (SSID/IP) in der Plattform zu
finden sind und ob/wie sich der MQTT-Broker über die eigene Domain statt nur im lokalen
Netz erreichen lässt.
**Ergebnis:** Bestätigt und behoben -- die Meldung war nie als sichtbare Benachrichtigung
umgesetzt, nur als Log-Zeile im mqtt-subscriber-Container. Erzeugt jetzt eine offene
Meldung im Postfach (`/portal/postfach`), eine je unbekannter Zählernummer. Im Chat erklärt:
WLAN-Info-Anzeige existiert bereits (Mitglied-Detailseite), erscheint aber erst, sobald ein
Zählpunkt mit passender Zählernummer angelegt ist -- vorher werden die WLAN-Daten mangels
Zuordnung gar nicht gespeichert. Architektur-Erklärung, dass die Domain aktuell NICHT zum
MQTT-Broker durchroutet (nginx-Proxy terminiert nur HTTP/HTTPS, andere Maschine als der
EEG-Server) und dass ein externer Zugriff (nötig für Mitglieder-ESP32s außerhalb des
eigenen Netzes) einen Router-Port-Forward plus TLS/Authentifizierung auf Mosquitto
voraussetzen würde (aktuell `allow_anonymous true`, kein TLS aktiv) -- noch nicht
umgesetzt, offen für eine spätere Session.
**Auftrag:** Vier gemeldete Probleme aus dem laufenden Betrieb: fehlendes Icon beim neuen
Support-Menüpunkt, Wunsch nach einer beim Scrollen fixierten Sidebar/Kopfzeile, ein im Dark
Mode unlesbarer Code-Schnipsel in der hellblauen Info-Box unter „API-Zugänge", sowie die
Bitte um eine Anleitung, was jetzt auf den ESP32 hochgeladen werden muss (inkl. Rückfrage,
ob der Zählernummer-Abgleich vor dem Senden tatsächlich stattfindet).
**Ergebnis:** `icon()` hängt jetzt einen `filemtime()`-Cache-Buster an die Sprite-URL (Ursache
des fehlenden Icons: Browser lieferten die alte, gecachte Sprite-Datei ohne das neue Symbol
weiter aus). Sidebar ist jetzt `position:sticky` (Desktop), mobile Icon-Leiste ausgenommen.
Code-Tags in der API-Zugänge-Info-Box bekommen ein festes helles Inline-Styling, das die
globale Dark-Mode-Regel überstimmt. In der Firmware fehlte tatsächlich ein Abgleich: die
Plattform ordnete Daten bisher nur nach der manuell konfigurierten Zählernummer zu, ohne sie
gegen die im P1-Telegramm gelesene zu prüfen -- jetzt wird bei Abweichung nicht gesendet.
Ausführliche ESP32-Setup-/Test-Anleitung (Board, MQTT-Zugangsdaten, Ablauf der Pipeline,
Vorschlag für einen Test-Zählpunkt zum Simulieren von Echtzeitdaten ohne Hardware) im Chat
beantwortet. Alle 77 Tests weiterhin grün.

---

## 2026-07-30 (2) — Claude Code — Claude Sonnet 5
**Auftrag:** Korrektur "ESB" → "ESP" (die Ausleseeinheit heißt ESP32, nicht ESB) in Spalten,
Code und Doku; ein Support-Ticket-System für Mitglieder (statt E-Mail-Verkehr, mit
Benachrichtigung an eine konfigurierbare Adresse); Review der ESP32-Firmware, damit die
bereits vorbereiteten WLAN-Diagnosefelder tatsächlich mitgeschickt werden; zusätzlich
Erfassung, ob der Smart Meter für den ESP erreichbar ist (zur Unterscheidung von
Inselbetrieb/Stromausfall beim Mitglied gegenüber einem Plattform-/ESP-Problem).
**Ergebnis:** `esb_online`/`esb_last_seen_at` → `esp_online`/`esp_last_seen_at` (rename-sicher
in `migrate_20260817.sql`), `docs/ESB_IDEEN.md` → `docs/ESP_IDEEN.md`, alle Code-/UI-Referenzen
angepasst. Firmware schickt jetzt SSID/IP (jeder Heartbeat) und WLAN-Passwort (bei Boot/
Reconnect) sowie `meter_ok` mit; neue Spalten `meter_reachable`/`meter_last_seen_at`
(`migrate_20260820.sql`), Anzeige in `member_detail.php` und Manager-Dashboard. Neues
Support-Ticket-System (`support_tickets`/`support_ticket_messages`, `migrate_20260821.sql`,
RLS) unter `/portal/my/support` (Mitglied) und `/portal/support` (Manager/Platform-Admin),
konfigurierbare Benachrichtigungsadresse in den E-Mail-Einstellungen (Standard
`office@stromfueralle.at`). Alle 77 Tests weiterhin grün.

---

## 2026-07-30 — Claude Code — Claude Sonnet 5
**Auftrag:** Eigenes Hero-Banner-Foto statt der SVG-Landschafts-Illustration auf der
Startseite, hochladbar unter Admin → Dateien, mit Zuschneiden auf exakt Ziel-Breite/-Höhe
(analog zum bestehenden Profilbild-Zuschnitt). Außerdem Frage nach den einzuspielenden
Migrationen sowie ein akuter Produktions-Bug (Login bricht mit „column esb_last_seen_at
does not exist" ab, weil eine Migration noch nicht eingespielt war).
**Ergebnis:** Neuer Registry-Eintrag `hero-banner.png` unter `/admin/templates` (gleiche
Custom-Datei-mit-Fallback-Logik wie Logo), neue Zuschneiden-Oberfläche `rect-crop.js`
(Schwester-Skript zu `avatar-crop.js`, aber für beliebiges Ziel-Seitenverhältnis statt nur
quadratisch) mit Zoom/Verschieben auf 1600×640, dynamische Auslieferung über
`/hero-banner-image`. Ohne eigenes Foto bleibt die SVG-Illustration unverändert. Keine neue
Migration nötig. Dem Nutzer die drei ausstehenden Migrationen (`migrate_20260817.sql`,
`migrate_20260818.sql`, `migrate_20260819.sql`) benannt und den Login-Fehler auf die fehlende
`esb_last_seen_at`-Spalte aus `migrate_20260817.sql` zurückgeführt.

---

## 2026-07-29 (Phase 2) — Claude Code — Claude Sonnet 5
**Auftrag:** Fortsetzung des Emoji-Sweeps (Phase 1 war nur die Startseite) über die
restliche Anwendung — Mitgliederverwaltung, Abrechnung, Dateien, Beitritts-/Vertragsflow,
kompletter Admin-Bereich —, jeweils mit Zwischenstand nach jedem Batch, jederzeit
rückgängig machbar.
**Ergebnis:** Alle verbleibenden ~190 Emoji-Vorkommen in ~38 Dateien durch `icon()`
ersetzt, in 6 committeten Batches (Sidebar-Layout, Mitgliederverwaltung, Abrechnung/
Rechnungen, Dateien/Dokumente, Beitritt/Verträge, Admin-Bereich, restliche Einzelseiten).
Sprite um 17 weitere Icons ergänzt (wrench, paperclip, folder-open, note-pencil,
download-simple, globe, x, puzzle-piece, sign-out, eye, device-mobile, laptop, image,
calculator, plus, check, hourglass, flask). Zwei Sonderfälle bewusst anders gelöst:
`<option>`-Werte in `<select>` bleiben reiner Text (kein Markup-Rendering dort möglich);
die client-seitige IBAN-Live-Validierung und die Upload-Statusanzeige nutzen jetzt
dieselbe SVG-Sprite-Referenz per `innerHTML` (mit Escaping) statt Emoji. Eine bewusste
Ausnahme bleibt: das ⚠️-Symbol im Markdown-Export des Audit-Logs (reiner Textexport,
kein HTML). Alle 73 Tests durchgehend grün, nach jedem Batch committet auf
`claude/stromfueralle-footer-pages-trqb5c`. Damit ist der komplette Emoji-Sweep
(Phase 1 + 2) abgeschlossen.

## 2026-07-29 — Claude Code — Claude Sonnet 5
**Auftrag:** Vor jeder Änderung committen/sicherbar machen; passende, hochwertige
Animationsbibliotheken (z. B. Motion/GSAP) für dezente Übergänge/Hover-/Scroll-Effekte
vorschlagen; alle generischen Emojis durch ein einheitliches, professionelles Icon-Set
(z. B. Phosphor Icons) ersetzen; schrittweise mit Vorschau nach jedem größeren Schritt,
jede Änderung einzeln rückgängig machbar.
**Ergebnis:** Phase 1 (Startseite + gemeinsamer Theme-Toggle): selbst gehostetes
Phosphor-Icon-Sprite (`webapp/public/assets/icons/phosphor-sprite.svg`) + neuer Helfer
`icon()` in `functions.php` (3 neue Tests), ersetzt Emojis im Theme-Toggle, Sidebar-Menü
und auf der Startseite (7 Icons). GSAP 3.12.5 + ScrollTrigger self-hosted unter
`assets/js/vendor/` (kein CDN), neues `site-animations.js` blendet den Hero-Text auf der
Startseite sanft ein — bewusst nur dort, Portal/Admin bleiben animationsfrei
(schnelles CRUD-Arbeiten soll nicht ausgebremst werden). Progressive-Enhancement-Muster:
`js-anim`-Klasse synchron in `<head>` gesetzt, CSS blendet nur dann aus, Script entfernt
die Klasse wieder falls GSAP nicht lädt — nie dauerhaft unsichtbarer Inhalt. Per Playwright
in Light/Dark/Hover visuell geprüft (dabei einen `.from()`-vs-`.fromTo()`-Bug gefunden und
vor dem Commit behoben). Alle 73 Tests grün, gecommittet auf
`claude/stromfueralle-footer-pages-trqb5c`. Offen: Phase 2 (verbleibende Emojis in
~42 weiteren Dateien: Rechtstexte, Beitreten-Flow, Portal/Admin-Backoffice) nach Rückmeldung.

## 2026-07-24 (nachts) — Claude Code — Claude Opus 4.8
**Auftrag:** Nachweisen, dass die Sicherungen wirklich laufen (inkl. Alarm ins Admin-Postfach),
Zähler- und Mitgliederdaten sicher abdecken, Stammdaten von den (später großen) Messwerten
trennen und eine Wiederherstellungs-Möglichkeit auf der Plattform. Außerdem Bugfix: in der
Passwort-vergessen-Mail standen rohe Platzhalter statt der Anrede.
**Ergebnis:** Bugfix — Reset-Route übergibt jetzt anrede/nachname (`salutationVarsForEmail()`),
plus generelles Sicherheitsnetz `stripUnresolvedPlaceholders()` im Mailer (4 Tests); functions.php
zusätzlich in den Alarm-Skripten eingebunden. Backup: `backup.sh` erzeugt zusätzlich einen
Stammdaten-Dump (`eeg_stamm_*.dump`, ohne Messwerte, Tabellenliste dynamisch = alle public-Tabellen
minus Hypertables), beide Dumps werden validiert, `last_backup.json` als Statusdatei. `restore.sh`
erkennt den Stammdaten-Dump und ersetzt nur dessen Tabellen (`--clean --if-exists`), Messwerte
bleiben erhalten. Neue Admin-Seite `/admin/backups` (Backup-Verzeichnis `:ro` gemountet): warnt
sichtbar bei überfälliger Sicherung, listet alle Sicherungen mit Restore-Befehl. Bewusst KEIN
ausführender Restore-Button in der Web-UI (bräuchte Docker-Zugriff der Webapp = Host-Übernahme bei
einer einzigen Web-Lücke) — begründet in docs/BACKUP.md und auf der Seite selbst.
Hinweis festgehalten: Messwerte sind Hypertables und nicht einzeln dumpbar → im Volldump.
Alle 70 Tests grün.

## 2026-07-24 (spätabends) — Claude Code — Claude Opus 4.8
**Auftrag:** Punkt 4 von 4: TOTP-2FA mit Ein-/Ausschalter (Passkeys später).
**Ergebnis:** Abhängigkeitsfreie TOTP-Funktionen (base32, totpCodeAt/Verify, Provisioning-URI),
gegen RFC-6238-Testvektoren geprüft (9 Tests). `migrate_20260816` (users.totp_secret/enabled).
Auth in checkPassword()+establishSession() aufgeteilt → zweistufiger Login (Passwort → Code,
`/portal/login/2fa`). Selbst-Verwaltung im Profil: aktivieren mit Code-Bestätigung
(`/portal/profile/2fa/setup|enable`), deaktivieren jederzeit. Setup-Seite zeigt Setup-Schlüssel +
otpauth-Link (Apple Passwörter/Authenticator). Notfall-Reset per SQL dokumentiert. Alle 66 Tests
grün. Damit sind alle vier gewünschten Punkte (Rücklastschrift/Mahnwesen, Jahresübersicht,
Audit-Vorher/Nachher, 2FA) umgesetzt. Gemergt (#17 folgend).

## 2026-07-24 (abends) — Claude Code — Claude Opus 4.8
**Auftrag:** Punkt 3 von 4: Audit-Log mit Vorher→Nachher-Werten (wer/wo/was von X auf Y).
**Ergebnis:** `migrate_20260815` (audit_log.aenderungen JSONB). Reine, getestete Helfer
`auditNormalizeValue`/`auditDiff`/`auditChangesText` (5 Tests) + `logAuditDiff()`. Instrumentiert:
EEG-Stammdaten, Mitglied-Bearbeitung, E-Mail-Vorlagen, Mail-Konfiguration (Secret/Logo nur als
„geändert", nie im Klartext). Nebenbei-Fix: Vorlagen-Speicherroute-Whitelist um
`sepa_prenotification` und `mahnung` ergänzt (vorher HTTP 400). Alle 57 Tests grün. Gemergt (#16 folgend).
Offen: TOTP-2FA (mit Ein-/Ausschalter) als letzter der vier Punkte.

## 2026-07-24 (spätnachmittags) — Claude Code — Claude Opus 4.8
**Auftrag:** Punkt 2 von 4: Jahresübersicht/-abrechnung pro Mitglied.
**Ergebnis:** Helfer `memberJahresUebersicht()` (alle Rechnungen eines Jahres aus dem
Quartals-Präfix, Netto/USt/Brutto via taxBreakdown, Jahressummen, Jahresliste). Routen für
Obmann (`/portal/members/:id/jahresuebersicht[/:jahr]`) und Mitglied
(`/portal/my/jahresuebersicht[/:jahr]`); druckbare Standalone-Seite `jahresuebersicht.php`
(Browser-Druck→PDF, kein LaTeX nötig), Jahr per Klick wechselbar. Verlinkt am Mitglied und
unter „Meine Dokumente". Keine Migration. Alle 52 Tests grün. Gemergt (#15 folgend).

## 2026-07-24 (nachmittags) — Claude Code — Claude Opus 4.8
**Auftrag:** Rücklastschrift + Mahnwesen als erster von vier nächsten Punkten (danach
Jahresübersicht, Audit-Log Vorher/Nachher, TOTP-2FA mit Ein-/Ausschalter; Passkeys später).
**Ergebnis:** `migrate_20260814` (communities.mahngebuehr_eur; invoices.mahnstufe /
mahn_gebuehr_summe_eur / letzte_mahnung_at / ruecklastschrift_at; Vorlage `mahnung`). Routen
`/portal/billing/invoices/:id/ruecklastschrift` und `.../mahnung` (Stufe 1–3, Gebühr aufschlagen,
Mail mit Brutto/Gebühren/Gesamt/Frist/IBAN). UI in der Rechnungsliste (Rücklastschrift- und
Mahn-Buttons, Mahnstufen-Badge), Mahngebühr-Feld in den EEG-Einstellungen, Vorlage im Admin
editierbar + Vorschau-Testnutzer. `mahnstufeText()` mit 5 Tests. Alle 52 Tests grün. Gemergt (#14 folgend).

## 2026-07-24 (mittags) — Claude Code — Claude Opus 4.8
**Auftrag:** Jeder Container soll healthy/unhealthy anzeigen; bei Problemen den Platform-Admin
per Postfach benachrichtigen und den Dienst 1–2× automatisch neu starten. Außerdem einen
Dark/Light-Kontrast-Bug bei Buttons beheben (Text im Dark Mode dunkelgrau, schlecht lesbar).
**Ergebnis:** Healthchecks für traefik (`--ping`) und mqtt-subscriber (Heartbeat-Datei +
`threading`-Loop in main.py) ergänzt — alle Container zeigen jetzt einen Healthstatus. Wächter
`scripts/health_monitor.sh` (Host-Cron) startet unhealthy/gestoppte Dienste 1–2× neu und mailt
bei anhaltendem Problem via `scripts/health_alert.php` (6-h-Cooldown). Kontrast-Bug: getönte
Buttons waren feste Inline-Hex ohne Dark-Variante → neue `.btn-tint-*`-Klassen (hell+dunkel),
alle betroffenen Buttons repo-weit umgestellt. Alle 47 Tests grün. Gemergt (Fortsetzung #13).
Offen/als Nächstes: Rücklastschrift+Mahnwesen, Jahresübersicht, Audit-Log mit Vorher/Nachher, 2FA.

## 2026-07-24 (vormittags) — Claude Code — Claude Opus 4.8
**Auftrag:** E-Mail-Vorlagen laut Mandatsdatei umsetzen (formelle Anrede „Sehr geehrter Herr
{{nachname}}" statt „Hallo {{vorname}}", neue Platzhalter {{anrede}}/{{nachname}}). Außerdem der
Fall Franz Lorenz (Vertrag) / Burgi Lorenz (E-Mail): pro Mitglied wählbare E-Mail-Anrede
(Automatisch/Herr/Frau/Familie), einstellbar beim Bearbeiten und beim Freigeben einer
Online-Beitrittserklärung.
**Ergebnis:** `mailSalutation()` (7 Tests) + Spalte `members.email_anrede_mode`
(`migrate_20260813`, inkl. Umstellung aller 7 Vorlagen auf {{anrede}} {{nachname}}). Alle
6 Mitglieder-Mailtypen verkabelt (Einladung, Deaktivierung, 3× Vertrag, SEPA-Vorabinfo);
Passwort-Reset bewusst ohne Namen. Auswahlfeld im Mitglied-Formular und im Freigabe-Dialog,
Vorschau-Testnutzer + Platzhalterhilfe ergänzt. Hinweis: die Vertragsvorlagen wurden mit
„im Mitgliederportal … digital unterschreiben" statt dem Mandats-Wortlaut „im Anhang" gesetzt,
da der aktuelle Flow per Link signiert (kein Anhang). Alle 47 Tests grün. Gemergt (Fortsetzung #12).

## 2026-07-24 (früh) — Claude Code — Claude Opus 4.8
**Auftrag:** Signatur-Logo besser steuerbar: Größe (px) einstellbar und Position frei wählbar
(zwischen Grußformel und Impressum, nicht immer am Ende). Außerdem die E-Mail-Vorschau für
JEDE Vorlage (Rechnung, Passwort-Reset …) mit einem Test-Nutzer, dessen Variablen gefüllt sind.
Bestätigt: die SEPA-Testdatei wurde von der Sparkasse (George) akzeptiert.
**Ergebnis:** Logo-Breite/-Höhe in `platform_mail_config` (`migrate_20260812`), `{{logo}}`-
Platzhalter für die Position (Mailer::send ersetzt ihn bzw. hängt sonst ans Ende an). Vorschau
umgebaut: Vorlagen-Dropdown + Test-Nutzer (alle Platzhalter), Live-Größe/Position, Betreff live.
Alle 40 Tests grün. Gemergt (Fortsetzung #11).

## 2026-07-23 (nachts) — Claude Code — Claude Opus 4.8
**Auftrag:** Nachbesserungen: (1) das Bank-Prüftool wies die SEPA-Testdatei wegen ungültiger
Beispiel-IBAN ab – korrigieren; (2) die Rechnungsliste soll immer Brutto anzeigen; (3) in den
E-Mail-Einstellungen eine Vorschau, wie die Mail in Smartphone- und Laptop-Breite aussieht.
**Ergebnis:** (1) Mod-97-gültige AT-Test-IBANs im Test-Generator + korrigierte Datei geschickt;
echter SEPA-Export überspringt jetzt zusätzlich ungültige IBANs. (2) Rechnungsliste zeigt
Brutto (via `taxBreakdown`, LATERAL-Join auf `tax_config`). (3) Live-Vorschau (375 px / 820 px)
mit Signatur + Logo in den E-Mail-Einstellungen. Alle 40 Tests grün. Gemergt (Fortsetzung #10).

## 2026-07-23 (spätabends) — Claude Code — Claude Opus 4.8
**Auftrag:** Drei Wünsche: (1) ein Logo/Bild in der E-Mail-Signatur, auch bei No-Reply
sichtbar; (2) eine SEPA-Test-XML-Datei mit Beispieldaten, um sie schon vor den ersten
EDA-Daten beim Bank-Prüftool zu testen; (3) das Steuermodell netto/brutto (20 % USt) neben
Kleinunternehmer umsetzen.
**Ergebnis:** (1) Signatur-Logo im Platform-Admin hochladbar, als Inline-CID-Bild in jede
Mail eingebettet (`Mailer.php`, `platform_mail_config.signature_logo_*`, `migrate_20260811`).
(2) Route `/portal/billing/sepa-test-xml` + Button liefert eine `pain.008`-Beispieldatei;
zusätzlich eine fertige Datei direkt an Patrick geschickt. (3) `taxBreakdown()` (7 Tests)
zentralisiert netto/USt/brutto; Rechnung-PDF, SEPA-Einzug und Vorabinfo nutzen jetzt Brutto,
Kleinunternehmer bleibt Default. Alle 40 Tests grün. Gemergt als PR (Fortsetzung von #9).

## 2026-07-23 (abends) — Claude Code — Claude Opus 4.8
**Auftrag:** SEPA-Lastschrift-Abwicklung fertigstellen: Sammellastschrift (pain.008) je
freigegebenem Abrechnungslauf herunterladbar, Aufteilung Einzug (Saldo > 0) vs. Überweisung
durch den Obmann (Saldo < 0), Zahlungsstatus-Verfolgung unter *Rechnungen* und eine
SEPA-Vorabinformation per Mail bei der Freigabe (Abbuchung = Rechnungsdatum + 14 Tage).
**Ergebnis:** Neue Routen `/portal/billing/:id/sepa-xml` (nutzt die getestete
`sepaPain008Xml()`, Format `.08`/`.02` je EEG) und `/portal/billing/invoices/:id/mark-paid`;
Zahlungsstatus-Spalte + Fortschritt „X von Y erledigt" in `billing_invoices.php`; Vorabinfo-Mail
bei der Freigabe (`sendSepaPrenotifications()`, Vorlage `sepa_prenotification` in
`migrate_20260810.sql`, im Admin editierbar). Alle 33 Tests grün, PHP-Lint sauber. CHANGELOG
(Unreleased) und Doku ergänzt.

## 2026-07-23 (nachmittags) — Claude Code — Claude Opus 4.8
**Auftrag:** Produktions-Notfall: nach Deploy + Container-Neubau wirkte die Datenbank leer
(Login/Abrechnung defekt). Ursache finden, Daten retten, dauerhaft absichern; außerdem tägliche
Backups mit Fehler-Alarm einrichten und die Pfad-/Mount-Struktur dokumentieren.
**Ergebnis:** Ursache = `timescaledb-ha`-Image legt PGDATA unter `/home/postgres/pgdata/data` ab,
Mount stand aber auf `/var/lib/postgresql/data` → DB lief auf flüchtigem Container-Speicher, nach
Neubau „weg". Echte Daten (Cluster bis 18.06.) lagen unangetastet auf der Platte; wiederhergestellt
aus `backups/eeg_20260716_1859.dump` (TimescaleDB pre/post_restore), danach alle Migrationen
nachgezogen. Mount korrigiert + Image auf feste Digest gepinnt (`docker-compose.yml`). Backup
gehärtet (`scripts/backup.sh` mit Gültigkeitsprüfung, Rotation, E-Mail-Alarm via
`scripts/backup_alert.php`), Cron auf 02:00 dokumentiert inkl. „wirklich installiert?"-Check.
Neue Doku `docs/INFRASTRUKTUR_PFADE.md` (Pfade/Mounts + Diagramm), in CLAUDE.md + Obsidian
verlinkt. Anschließend Backup-Kette gehärtet: Prüf-Bug in `backup.sh` (pg_restore über Pipe)
und STDERR-Crash im Alarm-Mailer gefixt; `backup-storage.sh` sichert jetzt das komplette
`webapp-storage` inkl. `pdfs/` und Beitrittserklärungen/SEPA-Mandate (vorher leeres 45-Byte-
Archiv); `sync-to-nas.sh` alarmiert ebenfalls bei Fehlschlag; zwei konfigurierbare
Alarm-Empfänger-Adressen im Platform-Admin (`migrate_20260806`). Cron auf 02:00 (DB) / 02:05
(Dateien) / 02:20 (NAS) gesetzt und getestet.

## 2026-07-23 — Claude Code — Claude Opus 4.8
**Auftrag:** Git-Versionierung mit Branches und Tags einführen und künftig beim Committen/Pushen
verwenden (inkl. Erklärung des Nutzens); das starre 60-Tage-Freigabefenster der Abrechnung durch
ein an der EDA-Datenqualität orientiertes Kriterium ersetzen (Variable aus dem Eder-XLSX-Monats-
bericht); die Raspberry-Stabilitätsdoku an den tatsächlichen Befund (NVMe über PCIe, Root-FS
read-write) anpassen.
**Ergebnis:** `CHANGELOG.md` (SemVer) angelegt und Git-Workflow-Abschnitt in CLAUDE.md +
Infrastruktur.md ergänzt; Tags `v0.9.0` (Meilenstein) und `v0.9.1` gesetzt; Arbeit auf dem
Branch `claude/stromfueralle-footer-pages-trqb5c` gebündelt. Abrechnungs-Freigabe hängt nun an
`billing_runs.eda_status` + automatischer L3-Prüfung statt am Kalender (Billing::finalize/
datenqualitaetProblem/setEdaStatus, Migration `migrate_20260805`, UI + Route + `docs/
EDA_DATENQUALITAET.md`); dabei den EDA-Filter von `('L2','L3')` auf `('L1','L2')` korrigiert
(L1 = gemessener Echtwert wurde fälschlich ausgeschlossen, L3 = nicht belastbar mitgerechnet).
`docs/RASPBERRY_STABILITAET.md` überarbeitet: USB-SATA/read-only ausgeschlossen, persistentes
journald empfohlen, Verdacht auf OOM/Unterspannung/NVMe-Link refokussiert. 28 Tests grün.

## 2026-07-22 — Claude Code — Claude Opus 4.8
**Auftrag:** Umfangreiche Folge-Runde: die gelieferte neue 4-spaltige Rechnungsvorlage
PHP-seitig anbinden (inkl. Positionen pro Zählpunkt), drei aus dem Ideen-Feedback gewünschte
Features umsetzen (Rechnungs-Einzelbearbeitung vor Versand, DSGVO-Datenexport, automatisierte
Tests + CI) und die Raspberry-Diagnose an das tatsächliche Setup (Pi 5 mit SSD) anpassen.
**Ergebnis:** Neue `rechnung.tex` als Repo-Standard + PHP auf 4 Spalten und
Pro-Zählpunkt-Positionen umgestellt (mit echtem pdflatex verifiziert); `invoice_items` um
`zaehlpunkt_nr` erweitert. Abrechnung zweistufig (berechnen → einzeln bearbeiten → freigeben,
Billing::generateDrafts/finalize/recalcInvoiceSaldo, end-to-end gegen Postgres getestet).
DSGVO-Export pro Mitglied (Selbst- und Manager-Auskunft) + `docs/DSGVO.md`. Abhängigkeitsfreie
Test-Suite (`tests/`, 28 Tests) + GitHub-Actions-CI, reine Funktionen nach `src/functions.php`
ausgelagert. `docs/RASPBERRY_STABILITAET.md` auf SSD-Realität überarbeitet (USB-SATA/UAS-Reset
→ read-only-FS als Hauptverdacht). Commits: Rechnung/4-Spalten, Einzelbearbeitung, DSGVO,
Tests+CI, Raspberry-Doku.

## 2026-07-20 16:20 — Claude Code — Claude Opus 4.8
**Auftrag:** Weitere Runde Rechnungs-/Abrechnungsarbeit sowie zwei Betriebsanliegen: den
anteiligen Mitgliedsbeitrag bei unterjährigem Beitritt umsetzen, die Ursache für sporadische
Raspberry-Pi-Aufhänger (im Netz sichtbar, aber kein SSH) klären und absichern, und einen
Berater-Agenten für Diplomarbeits-/Plattform-Ideen anlegen.
**Ergebnis:** `Billing.php` rechnet den Mitgliedsbeitrag jetzt anteilig nach aktiven
Monaten im Abrechnungszeitraum (voll dabei = unverändert, verifiziert). Docker-Log-Rotation
(`x-logging`) in `docker-compose.yml` ergänzt und `docs/RASPBERRY_STABILITAET.md` (Ursachen,
Diagnose, Hardware-Watchdog-Selbstheilung) angelegt, in CLAUDE.md + Obsidian verlinkt. Neuer
Sub-Agent `.claude/agents/diplomarbeit-berater.md`. Die 4-Spalten-Umstellung der
Positionstabelle (`RAW_ZUSATZPOSITIONEN_LISTE`/`RAW_STEUER_ZEILE`) sowie die Pro-Zählpunkt-
Darstellung wurden bewusst zurückgestellt, bis die neue `rechnung.tex` vorliegt (Vorlagen-
Kopplung, sonst Kompilierfehler in Produktion).

## 2026-07-20 08:30 — Cowork — Claude Fable 5
**Auftrag:** Einführung einer Selbstdokumentation für alle Claude-Werkzeuge (Claude Code,
Claude Chat, Cowork): Jede Sitzung soll künftig Datum, verwendetes Modell und den
professionell formulierten Auftrag protokollieren; die zugehörige Anweisung soll in
`CLAUDE.md` aufgenommen und auf GitHub verfügbar gemacht werden.
**Ergebnis:** Abschnitt „Selbstdokumentation" in `CLAUDE.md` ergänzt, diese Log-Datei
angelegt (inkl. Backfill aus der Git-Historie), `obsidian/Infrastruktur.md` mitaktualisiert,
täglichen Obsidian-Sync-Task um ein Lauf-Protokoll erweitert. Push auf GitHub erfolgt durch
Patrick (Cowork pusht vereinbarungsgemäß nicht).

## 2026-07-20 07:05 — Cowork (geplanter Task) — Claude Fable 5
**Auftrag:** Täglicher automatischer Abgleich der Markdown-Dokumentation des Repos mit dem
Obsidian-Vault.
**Ergebnis:** Alle 15 Doku-Dateien bereits identisch mit `origin/main` (ccc9d07), keine
Änderungen nötig. Task anschließend auf reines Lesen vom GitHub-Stand umgestellt
(nur `git fetch`/`git show`, niemals committen/mergen/pushen).

---

## Backfill (rekonstruiert am 2026-07-20; Modell nachträglich nicht mehr feststellbar)

Claude-Code-Sitzungen laut Git-Historie (`origin/main`):

| Datum | Arbeiten |
|---|---|
| 2026-07-19 | Rechnungs-Template: Anrede, getrennte Adresszeilen, Kundennummer, SEPA-Mandatsreferenz, Zahlungstext; E-Mail-Signatur, Rechnungs-Testvorschau, EEG-Logo, Variablen-Export, manuelle Rechnungspositionen; drei Abrechnungs-Bugs behoben; konfigurierbarer Reply-To-Header |
| 2026-07-18 | Ein-Befehl-Setup (`scripts/setup.sh`) inkl. Migrations-Bugfix; Test-Endpoint für API-Keys; Kontrast-Bugfix Dark/Light-Mode; ESB-Ideen-Backlog angelegt; Logo-Upload im Platform-Admin (inkl. nginx-Routing-Fix); dezente Startseiten-Animationen; Footer-Link zur Kärnten-Netz-Netzgebietsprüfung |
| 2026-07-17 | Infoblatt (Website-PDF) zur Vorlagenverwaltung `/admin/templates` hinzugefügt |
| 2026-07-16 | Mitglieder-API-Zugänge (Vorbereitung Smart-Home-API); Mitglied-Dashboard-Platzhalter; Revert der Portal-Zugang-Änderung; LaTeX-Vorlagen-Dateiverwaltung im Platform-Admin |

Cowork-/Chat-Sitzungen der letzten Zeit (Titel laut Sitzungsliste, ohne genaue
Datumszuordnung): Rechnungslayout Solar, No-Reply-Postfach & E-Mail-Signatur (2 Sitzungen),
Fronius EVO, Hausverteiler/Zähler-Absicherung Kärnten, KHS-Schaltplan-Überarbeitung,
Obsidian-Doku-Sync (mehrere Läufe), Infoblatt mit 2 Seiten, virtueller Gemeinschaftsspeicher,
Prüfung Höfferer-Energiegemeinschafts-Vereinbarungen, deutsche Vertragsvorlagen,
Sparkasse-Lastschrift-Anforderungen, 3D-Druck Schriftzug.
