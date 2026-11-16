# ADR 0005: EDA-Import als eigener Worker mit Jobtabelle

**Status:** geplant

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

## Folgen
- Webapp-Image deutlich kleiner, keine blockierten Web-Anfragen bei großen Dateien.
- Ein zusätzlicher Dienst mit Healthcheck; Importe laufen asynchron (Statusanzeige nötig).
