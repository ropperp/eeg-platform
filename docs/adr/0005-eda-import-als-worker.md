# ADR 0005: EDA-Import als eigener Worker mit Jobtabelle

**Status:** angenommen, umgesetzt am 16.11.2026

## Kontext
Der EDA-Import (Python, pandas/openpyxl) läuft als Unterprozess der Webapp (`EdaParserRunner.php`).
Ein großer Import blockiert einen PHP-FPM-Prozess, und das Webapp-Image trägt Python und pandas
(mehrere hundert MB) nur dafür mit.

## Entscheidung
- Eigener Container `eda-worker` aus dem bestehenden Python-Code, DB-Rolle `eeg_worker`.
- Upload bzw. Postfach-Abruf legt einen Job in einer PostgreSQL-Tabelle an (keine neue
  Message-Queue); der Worker holt Jobs mit `SELECT … FOR UPDATE SKIP LOCKED`, schreibt Fortschritt,
  Ergebnis und Fehler, Wiederholung idempotent.
- Die Oberfläche zeigt den Status und lädt nach.
- Erst wenn der Worker stabil läuft, verschwinden Python und pandas aus dem Webapp-Image.

## Umsetzung (16.11.2026)
- Tabelle `eda_import_jobs` (`database/migrate_20261118.sql`, RLS wie die übrigen Tabellen).
- `eda-parser/worker.py` ruft `parser.py`/`parser_interval.py` unverändert als Unterprozess auf und
  speichert stdout/stderr/Exit-Code wie früher `EdaParserRunner.php`; abgestürzte Jobs werden höchstens
  dreimal wiederholt, Parser-Fehler nicht.
- Portal-Upload: Job anlegen, Statusseite mit automatischem Nachladen (`assets/js/eda-job-status.js`).
- Postfach-Abruf: `EdaParserRunner::run()` legt den Job an und wartet -- `EdaAutoImporter` unverändert.
- Python und pandas sind direkt im ersten Schritt aus dem Webapp-Image entfernt (Rückfall: `git revert`).

## Folgen
- Webapp-Image deutlich kleiner, keine blockierten Web-Anfragen bei großen Dateien.
- Ein zusätzlicher Dienst mit Healthcheck; Importe laufen asynchron (Statusanzeige nötig).
