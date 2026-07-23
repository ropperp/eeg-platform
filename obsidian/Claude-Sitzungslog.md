# Claude-Sitzungslog

Fortlaufende Selbstdokumentation aller Claude-Arbeitssitzungen rund um die EEG-Plattform:
Datum, verwendetes Modell, Werkzeug und der professionell zusammengefasste Auftrag.
Neueste Einträge oben. Format und Regeln: Abschnitt „Selbstdokumentation" in `CLAUDE.md`.
Einträge aus Cowork/Claude Chat liegen zusätzlich im Obsidian-Vault unter
`eeg-platform-notes/logs/JJJJ-MM-TT.md`.

---

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
