# Plattform-Statistiken (anonym)

Diese Datei enthält anonyme Kennzahlen zur Plattform — **keine Klarnamen, keine IBANs, keine personenbezogenen Daten**.

Zuletzt aktualisiert: *manuell aktualisieren, Datum eintragen*

---

## Schema-Statistiken

| Kennzahl | Wert |
|----------|------|
| Anzahl Tabellen | 13 |
| Davon TimescaleDB Hypertables | 2 (`esp_measurements`, `eda_measurements`) |
| Tabellen mit Row-Level Security | 10 |
| Anzahl RLS-Policies | 10 |

---

## Laufzeit-Statistiken (vom Raspi abfragen)

Die folgenden Werte müssen manuell abgefragt und eingetragen werden.  
Kein Automatismus, damit nie versehentlich echte Daten committed werden.

### Abfragen (auf dem Raspi ausführen):

```sql
-- Anzahl EEGs (Mandanten)
SELECT COUNT(*) AS communities FROM communities;

-- Anzahl Zählpunkte je Typ
SELECT type, COUNT(*) FROM metering_points GROUP BY type ORDER BY type;

-- Importierte EDA-Zeiträume (anonymisiert: nur Zeitraum, keine Zählpunktnummern)
SELECT
  DATE_TRUNC('month', period_from) AS monat,
  COUNT(*) AS importe,
  SUM(records_imported) AS datensaetze
FROM eda_imports
GROUP BY 1 ORDER BY 1 DESC
LIMIT 12;

-- Zeilenzahl je Hypertable
SELECT hypertable_name, approximate_row_count(format('%I', hypertable_name)::regclass) AS zeilen
FROM timescaledb_information.hypertables;

-- Datenbankgröße gesamt
SELECT pg_size_pretty(pg_database_size('eeg_platform')) AS db_groesse;

-- Größte Tabellen
SELECT
  relname AS tabelle,
  pg_size_pretty(pg_total_relation_size(relid)) AS groesse
FROM pg_catalog.pg_statio_user_tables
ORDER BY pg_total_relation_size(relid) DESC
LIMIT 10;
```

Ausführen:

```bash
docker compose exec timescaledb psql -U eeg -d eeg_platform -c "HIER SQL"
```

---

## Aktuelle Werte (Stichtag: ___________)

| Kennzahl | Wert |
|----------|------|
| Aktive EEGs | — |
| Mitglieder gesamt | — |
| Zählpunkte (consumer) | — |
| Zählpunkte (producer) | — |
| EDA-Importe gesamt | — |
| EDA-Messwerte gesamt (eda_measurements) | — |
| ESP32-Messwerte gesamt (esp_measurements) | — |
| Datenbankgröße | — |

*Werte ausfüllen nach Abfrage auf dem Raspi (keine Klarnamen, nur Zahlen).*

---

## TimescaleDB-Chunks

```sql
-- Übersicht der Chunks (automatisch von TimescaleDB verwaltet)
SELECT
  hypertable_name,
  chunk_name,
  range_start::date,
  range_end::date,
  pg_size_pretty(pg_total_relation_size(format('%I.%I', chunk_schema, chunk_name)::regclass)) AS chunk_groesse
FROM timescaledb_information.chunks
ORDER BY hypertable_name, range_start DESC;
```
