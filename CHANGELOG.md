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
