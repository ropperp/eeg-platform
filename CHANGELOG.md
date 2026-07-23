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
