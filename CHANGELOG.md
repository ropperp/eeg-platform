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

[Unreleased]: https://github.com/ropperp/eeg-platform/compare/v0.9.0...HEAD
[0.9.0]: https://github.com/ropperp/eeg-platform/releases/tag/v0.9.0
