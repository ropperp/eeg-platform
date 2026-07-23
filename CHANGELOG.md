# Changelog

Alle nennenswerten Änderungen an der EEG-Plattform, versioniert nach
[Semantic Versioning](https://semver.org/lang/de/): **MAJOR.MINOR.PATCH**.

- **PATCH** (z. B. 0.9.0 → 0.9.1): Bugfix, keine neuen Funktionen.
- **MINOR** (z. B. 0.9.0 → 0.10.0): neue, rückwärtskompatible Funktion.
- **MAJOR** (z. B. 0.x → 1.0.0): großer Umbau bzw. der erste echte Produktivstart.

`0.x`-Versionen = vor dem echten Produktivstart (noch in Entwicklung/Test). Die Version `1.0.0`
markiert den ersten produktiven Echtbetrieb.

Jeder Eintrag entspricht einem Git-**Tag** (`vX.Y.Z`). So lässt sich jederzeit ein bestimmter,
getesteter Stand deployen oder dorthin zurückrollen (siehe „Bestimmte Version deployen" in
`SETUP.md`/`README.md`).

---

## [Unreleased]
Änderungen, die noch keinem Versions-Tag zugeordnet sind, sammeln sich hier.

### Neu / Funktionen
- **Zwei-Faktor-Authentifizierung (TOTP), pro Konto ein-/ausschaltbar.** Optionaler zweiter Faktor
  beim Login per 6-stelligem Code (RFC 6238, abhängigkeitsfrei; kompatibel mit Apple Passwörter,
  Google Authenticator etc.). Aktivieren/Deaktivieren jederzeit selbst im Profil – beim Einrichten
  wird der Code einmal bestätigt, bevor 2FA scharf geschaltet wird. Login ist dann zweistufig
  (Passwort → Code). Neue Spalten `users.totp_secret`/`totp_enabled` (`migrate_20260816`),
  TOTP-Kernfunktionen mit RFC-Testvektoren abgesichert (9 Tests). **Notfall-Reset** (App verloren):
  `UPDATE users SET totp_enabled=false, totp_secret=NULL WHERE email='…';`.
- **Audit-Log mit Vorher→Nachher-Werten.** Änderungen an Konfiguration/Stammdaten werden jetzt
  feldgenau protokolliert: **wer**, **wann**, **wo** und **welcher Wert von X auf Y** – lesbar
  („IBAN: „—" → „AT…"") und maschinenlesbar (`audit_log.aenderungen` JSONB, `migrate_20260815`).
  Instrumentiert: EEG-Stammdaten, Mitglied-Bearbeitung, E-Mail-Vorlagen und Mail-Konfiguration
  (sensible Werte wie Client-Secret/Logo werden nur als „geändert" vermerkt, nie im Klartext).
  Der bestehende Markdown-Export enthält die Diffs automatisch mit.
- **Jahresübersicht pro Mitglied.** Eine druckbare Zusammenfassung aller Rechnungen eines
  Kalenderjahres (Quartale Q1–Q4) mit Netto/USt/Brutto je Rechnung, Zahlungsstatus/Mahnstufe und
  Jahressummen. Erreichbar für den Obmann (`/portal/members/:id/jahresuebersicht`, Button am
  Mitglied) und für das Mitglied selbst im Portal (unter „Meine Dokumente"). Jahr per Klick
  wechselbar, „Drucken / als PDF speichern" über den Browser (keine eigene LaTeX-Vorlage nötig).
- **Rücklastschrift + Mahnwesen.** Wird ein SEPA-Einzug von der Bank zurückgebucht, meldet der
  Obmann die **Rücklastschrift** (Rechnung wieder offen). Danach lässt sich stufenweise mahnen:
  **Stufe 1 Zahlungserinnerung → 2 = 1. Mahnung → 3 = letzte Mahnung**, je mit E-Mail
  (Vorlage `mahnung`, im Admin editierbar) und optionaler, je EEG konfigurierbarer **Mahngebühr**
  (`communities.mahngebuehr_eur`), die sich aufsummiert. Die Mail nennt den offenen Brutto-Betrag,
  die Gebühren, den Gesamtbetrag, eine Zahlungsfrist und die EEG-IBAN zur Überweisung. Neue
  Spalten `invoices.mahnstufe`, `mahn_gebuehr_summe_eur`, `letzte_mahnung_at`, `ruecklastschrift_at`
  (`migrate_20260814`); Aktionen direkt in der Rechnungsliste.
- **Container-Healthchecks + Selbstheilung & Alarm.** Jetzt hat **jeder** Container einen
  Healthcheck (auch `traefik` per `--ping` und der `mqtt-subscriber` per Heartbeat-Datei) —
  `docker compose ps` zeigt für alle `healthy`/`unhealthy` statt nur „Up". Neuer Wächter
  `scripts/health_monitor.sh` (als Cron auf dem Host): startet einen unhealthy/gestoppten Dienst
  1–2× automatisch neu und alarmiert bei anhaltendem Problem das Admin-Postfach
  (`scripts/health_alert.php`, gleiche Microsoft-Graph-Anbindung wie der Backup-Alarm),
  mit 6-h-Cooldown gegen Mail-/Neustart-Fluten.
- **Dark-Mode-Kontrast der Aktions-Buttons behoben.** Getönte Buttons (grün/blau/amber/rot)
  waren als feste Inline-Farben gesetzt und schalteten im Dark Mode nicht mit (heller Button auf
  dunklem Grund, schlechter Kontrast). Neue Klassen `.btn-tint-*` mit eigener Hell- und
  Dunkel-Variante; alle betroffenen Buttons (Mitglied-Aktionen, Zahlungsstatus, Freigeben/Ablehnen …)
  darauf umgestellt.
- **Formelle E-Mail-Anrede + Anrede-Modus je Mitglied.** Alle Mitglieder-Mails (Einladung,
  Vertrag, Deaktivierung, SEPA-Vorabinfo) verwenden jetzt `{{anrede}} {{nachname}}`
  („Sehr geehrter Herr Lorenz") statt „Hallo {{vorname}}". Neues Feld **E-Mail-Anrede** am
  Mitglied (`members.email_anrede_mode`: automatisch / Herr / Frau / **Familie**) – einstellbar
  in der Mitglieder-Bearbeitung und beim Freigeben einer Online-Beitrittserklärung. Löst den
  Fall, dass z. B. die Ehefrau die Mails liest, der Vertrag aber auf den Mann läuft
  („Sehr geehrte Familie Lorenz"). Das Geschlecht der Person bleibt davon unberührt, der
  Nachname immer der des Vertragspartners. Passwort-Reset bleibt bewusst ohne Namen
  („Liebes Mitglied"). Alle Vorlagen auf die neue Fassung umgestellt (`migrate_20260813`).
- **E-Mail-Vorschau je Vorlage (Smartphone + Laptop):** in den E-Mail-Einstellungen zeigt eine
  Live-Vorschau, wie eine ausgehende Mail aussieht – in Smartphone-Breite (375 px) und
  Laptop-Breite (≈820 px), aktualisiert beim Tippen. **Vorlagen-Auswahl** (Rechnung/Vorabinfo,
  Passwort-Reset, Einladung, Verträge …) mit einem **Test-Nutzer**, der alle Platzhalter
  (`{{vorname}}`, `{{betrag}}`, `{{link}}` …) mit Beispiel-Werten füllt.
- **Signatur-Logo: Größe & Position steuerbar.** Breite/Höhe in Pixel einstellbar
  (`platform_mail_config.signature_logo_width/height`; nur Breite oder Höhe → proportional).
  Mit dem Platzhalter `{{logo}}` lässt sich das Bild an eine beliebige Stelle der Signatur
  setzen (z. B. zwischen Grußformel und Impressum) statt nur ans Ende.
- **Rechnungsliste in Brutto:** die Betrags-Spalte zeigt jetzt immer den Brutto-Betrag
  (bei Kleinunternehmer identisch mit netto, bei Standard inkl. USt) – konsistent mit
  Rechnung, SEPA-Einzug und Vorabinfo.
- **Steuer netto/brutto (USt-Ausweis):** neben der Kleinunternehmerregelung jetzt ein
  Standard-Pfad mit Umsatzsteuer (Default 20 %, je EEG einstellbar). Tarife bleiben netto;
  bei „Standard" weist die Rechnung Netto, USt und Brutto aus, und **SEPA-Einzug wie
  Vorabinfo verwenden den Brutto-Betrag**. Zentral in der getesteten Funktion `taxBreakdown()`
  (7 Tests). Kleinunternehmer bleibt Default — für bestehende EEGs ändert sich nichts.
- **SEPA-Test-XML mit Beispieldaten** (`/portal/billing/sepa-test-xml`, Button in der
  Abrechnung): erzeugt eine `pain.008`-Datei mit Platzhalter-Schuldnern zum Prüfen im
  Bank-/Banking-Tool, **bevor** echte EDA-Daten vorliegen — nutzt die hinterlegte
  Gläubiger-ID/IBAN, ohne DB-Eintrag oder echten Einzug.
- **Logo/Bild in der E-Mail-Signatur:** im Platform-Admin (E-Mail-Einstellungen) hochladbar,
  wird als Inline-Bild (Content-ID) unter jede ausgehende Mail gesetzt — auch bei
  No-Reply-Absendern in Outlook/Gmail zuverlässig sichtbar. In der DB als Base64 gehalten
  (`platform_mail_config.signature_logo_*`), übersteht so einen Geräteumzug.
- **SEPA-Lastschrift-Export (pain.008):** je freigegebenem Abrechnungslauf eine
  Sammellastschrift als XML herunterladbar (`/portal/billing/:id/sepa-xml`). Format pro EEG
  umschaltbar zwischen `pain.008.001.08` (Standard) und `.02` (`communities.sepa_pain_version`),
  Gläubiger-ID in den EEG-Einstellungen. Es werden nur einzuziehende Rechnungen (Saldo > 0) mit
  gültigem Mandat aufgenommen; Mandatsreferenz stammt aus `members.mandatsreferenz`. Reine,
  getestete Generatorfunktion `sepaPain008Xml()` (5 Tests). **Vor dem ersten Echt-Einzug mit dem
  Prüftool der Bank validieren.**
- **Einzug vs. Überweisung nach Vorzeichen + Zahlungsstatus:** unter *Rechnungen* zeigt jede
  freigegebene Rechnung ihren Zahlungsstatus (offen / eingezogen / überwiesen). Positive Salden
  werden per SEPA eingezogen und nach Bankbestätigung als „eingezogen" markiert, negative Salden
  (EEG schuldet dem Mitglied) vom Obmann überwiesen und als „überwiesen" markiert. Fortschritts-
  anzeige „X von Y erledigt" — der Abrechnungsprozess gilt erst als abgeschlossen, wenn alle
  Rechnungen erledigt sind (`invoices.payment_status`, `paid_at`).
- **SEPA-Vorabinformation (Pre-Notification):** bei der Freigabe eines Laufs geht am selben Tag
  an jedes einzuziehende Mitglied eine Mail mit dem Abbuchungsdatum raus (= Rechnungsdatum +
  Vorlauftage der EEG, Default **14**, `communities.sepa_prenotification_days`). Vorlage im
  Platform-Admin editierbar (`sepa_prenotification`), `invoices.prenotified_at` verhindert
  Doppel-Mails.
- **Audit-Log als Markdown exportierbar** (`/admin/log/export`) — wer/wann/was, für spätere
  Auswertung (auch per KI). Konfigurations-/Einstellungsänderungen werden protokolliert.
- **Verträge pro EEG abschaltbar** (`communities.contracts_enabled`): blendet Bezugs-/
  Einspeisevereinbarung überall aus (Mitglieder-Portal, Obmann-Ansichten, Dateien, Vertrags-
  status), wenn eine EEG ohne separate Verträge arbeitet.
- **Preisliste mit Tarif-Historie:** die öffentliche Preisliste zeigt neben dem aktuellen Tarif
  eine Änderungshistorie, damit Mitglieder Preisänderungen nachvollziehen können.

### Behoben
- **E-Mail-Vorlagen SEPA-Vorabinfo & Mahnung im Admin editierbar.** Beide Keys fehlten in der
  Whitelist der Vorlagen-Speicherroute – ein Speichern lief auf HTTP 400. Ergänzt.
- **SEPA-Test-XML: Beispiel-IBANs prüfziffern-gültig.** Die Debtor-IBANs der Testdatei waren
  frei erfunden und wurden vom Bank-Prüftool (zu Recht) als ungültig abgewiesen – jetzt
  Mod-97-korrekte AT-Test-IBANs. Zusätzlich überspringt der echte SEPA-Export Mitglieder mit
  ungültiger IBAN (statt die ganze Sammellastschrift von der Bank zurückweisen zu lassen).

### Behoben / Betrieb (Vorfall 23.07.2026)
- **DB-Datenverlust-Fallstrick behoben:** `timescaledb-ha`-Mount auf das echte PGDATA
  (`/opt/eeg/timescaledb:/home/postgres/pgdata`) korrigiert und Image auf feste Digest gepinnt.
  Der bewegliche `:pg16`-Tag hatte PGDATA unbemerkt verschoben, wodurch die DB nach einem
  Container-Neubau leer wirkte.
- **Backup gehärtet** (`scripts/backup.sh`): prüft den Dump auf Gültigkeit (nicht leer, lesbar),
  rotiert (letzte 14), und **alarmiert per E-Mail** ans Admin-Postfach bei Fehlschlag
  (`scripts/backup_alert.php`, Microsoft-Graph-Versand). Cron-Zeitplan auf 02:00 dokumentiert
  inkl. Prüfschritt „ist der Cron wirklich installiert".
- **Neue Doku** `docs/INFRASTRUKTUR_PFADE.md`: vollständige Pfad-/Mount-Übersicht mit Diagramm
  und Erklärung des PGDATA-Fallstricks; in CLAUDE.md + Obsidian verlinkt.
- **Backup-Prüfung repariert:** `backup.sh` verwarf gültige Dumps fälschlich (pg_restore -l über
  eine Pipe schlägt bei custom-format immer fehl); jetzt Prüfung über eine seekbare Datei.
  `backup_alert.php` crashte an nicht definiertem `STDERR` (Aufruf per stdin).
- **Datei-Backup repariert & erweitert:** `backup-storage.sh` sicherte nur `uploads/avatars` +
  `uploads/members` (Ergebnis: leeres 45-Byte-Archiv). Jetzt wird **das komplette**
  `webapp-storage` gesichert (inkl. `pdfs/` mit Verträgen/Rechnungen und den
  Beitrittserklärungen = SEPA-Mandaten), mit Inhalts-Prüfung und Fehler-Alarm.
- **NAS-Sync** (`sync-to-nas.sh`) alarmiert jetzt ebenfalls per E-Mail bei Fehlschlag.
- **Alarm-Empfänger konfigurierbar** (Platform-Admin → E-Mail-Einstellungen, zwei Felder;
  `migrate_20260806`), damit die Alarmierung nach einem Geräteumzug ohne Code-Änderung
  weiterläuft.

## [0.9.1] – 2026-07-23
### Geändert
- **Abrechnungs-Freigabe nach EDA-Datenqualität statt starrer 60-Tage-Frist**: Ein Lauf kann
  freigegeben werden, sobald die Werte belastbar sind (EDA-Monatsbericht meldet den Zeitraum als
  vollständig **und** es liegen keine L3-Ersatzwerte mehr vor) — auch früher als nach 60 Tagen,
  bzw. gesperrt, solange die Daten unvollständig sind. Neue Spalte `billing_runs.eda_status`
  (aus dem Eder-XLSX-Monatsbericht), Auswahl in der Abrechnungsübersicht; `freigabe_nach` bleibt
  nur noch informativ. Siehe `docs/EDA_DATENQUALITAET.md`.
### Behoben
- **Abrechnungs-Datenfilter**: Die EDA-Mengensummen wurden über `quality IN ('L2','L3')`
  gebildet — das schloss die **besten** Werte (L1, gemessen) aus und rechnete die **nicht
  belastbaren** (L3) mit. Korrigiert auf `('L1','L2')`.
### Doku
- `docs/RASPBERRY_STABILITAET.md` an den tatsächlichen Befund angepasst (NVMe über PCIe,
  Root-FS read-write → USB-SATA/read-only ausgeschlossen; persistentes journald empfohlen,
  Verdacht auf OOM/Unterspannung/NVMe-Link/systemd refokussiert).

## [0.9.0] – 2026-07-22
Erster versionierter Meilenstein: die Plattform ist funktional weitgehend vollständig, aber
noch vor dem echten Produktivstart.

### Enthalten (Funktionsumfang zum Zeitpunkt 0.9.0)
- **Mandantenfähigkeit**: mehrere EEGs auf einer Installation, Datentrennung via PostgreSQL
  Row-Level Security.
- **Mitgliederverwaltung**: Anlage manuell und über ein öffentliches Online-Beitrittsformular
  (inkl. IBAN-Prüfziffernvalidierung, Zählpunktübernahme, E-Signatur der Beitrittserklärung).
- **Verträge**: Bezugs-/Einspeisevereinbarung als LaTeX-PDF, E-Signatur-Workflow, Versand per
  Microsoft-Graph-Mail.
- **Abrechnung**: Quartalslauf auf Basis der EDA-Daten, anteiliger Mitgliedsbeitrag bei
  unterjährigem Beitritt, zweistufiger Ablauf (berechnen → Rechnungen einzeln prüfen/anpassen →
  freigeben), manuelle Zusatzpositionen, 60-Tage-Korrekturfenster.
- **Rechnung**: neues 4-spaltiges Layout, Positionen pro Zählpunkt, Kleinunternehmer-/
  UID-Ausweis, SEPA-Vorabankündigung, eigenes EEG-Logo, konfigurierbarer Footer (Kontakt/Bank).
- **Plattform-Admin**: EEG-Verwaltung, Benutzer-/Rollenverwaltung, E-Mail-Einstellungen
  (Microsoft Graph, Signatur, Reply-To), Datei-/Vorlagen-Manager mit Variablen-Referenz.
- **Mitglieder-Portal**: eigene Verträge/Rechnungen/Dateien, API-Zugänge, DSGVO-Datenexport.
- **Betrieb**: Ein-Befehl-Setup (`scripts/setup.sh`), Backup/NAS-Sync, Docker-Log-Rotation,
  Doku zur Raspberry-Stabilität (Watchdog).
- **Qualität**: abhängigkeitsfreie Test-Suite (`tests/`) + GitHub-Actions-CI.

[Unreleased]: https://github.com/ropperp/eeg-platform/compare/v0.9.1...HEAD
[0.9.1]: https://github.com/ropperp/eeg-platform/compare/v0.9.0...v0.9.1
[0.9.0]: https://github.com/ropperp/eeg-platform/releases/tag/v0.9.0
