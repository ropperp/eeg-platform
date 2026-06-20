# Datenbank-Dokumentation

## Eingesetztes System

**PostgreSQL 16** mit der Erweiterung **TimescaleDB** (aktuell: timescaledb-ha:pg16).

### Begründung

| Anforderung | Warum PostgreSQL + TimescaleDB |
|-------------|-------------------------------|
| Multi-Tenant mit strikter Datentrennung | Row-Level Security (RLS) auf DB-Ebene — kein Tenant kann je Daten eines anderen sehen, auch bei einem Bug im PHP-Code |
| 15-Minuten-Zeitreihendaten (EDA) | TimescaleDB Hypertables: automatisches Partitionieren nach Zeit, native Zeitreihenfunktionen (`time_bucket`, `first`, `last`) |
| ESP32-Echtzeit-Messwerte | TimescaleDB komprimiert Zeitreihendaten automatisch, typisch 10–20× kleiner als Plain-PostgreSQL |
| Historisierte Tarife und Steuern | Standard-SQL mit `valid_from`-Logik; kein NoSQL-Workaround nötig |
| ACID-Transaktionen | Abrechnungen müssen atomar sein (alle Rechnungen oder keine) |
| Österreichisches Recht | `pg_dump` erzeugt saubere, nachprüfbare Backups |

### Alternativen, die ausgeschieden wurden

- **MySQL/MariaDB**: kein RLS, kein natives TimescaleDB-Äquivalent
- **InfluxDB**: gut für Zeitreihendaten, aber kein relationales Modell für Stammdaten — zwei Datenbanken wäre zu komplex
- **SQLite**: kein Multi-User, kein RLS, nicht skalierbar

---

## Architektur

### Multi-Tenant via Row-Level Security

Jede mandantenspezifische Tabelle hat eine `community_id`-Spalte. Vor jeder Abfrage setzt die PHP-Schicht:

```sql
SET LOCAL app.community_id = 'uuid-der-community';
```

Die RLS-Policy erlaubt nur Zeilen, bei denen `community_id` diesem Wert entspricht:

```sql
CREATE POLICY community_isolation ON members
    USING (community_id = current_setting('app.community_id', true)::uuid);
```

**Ergebnis:** Selbst wenn ein PHP-Bug die falsche `community_id` übergibt, liefert PostgreSQL leere Ergebnisse — keine Datenlecks zwischen Mandanten.

**Ausnahme:** `DB::fetchOne()` läuft ohne vorheriges `setCommunity()` und ohne RLS. Wird nur für mandantenübergreifende Lookups (z. B. User-Auth, Communities-Tabelle) verwendet.

### Zwei strikt getrennte Datenpfade

```
ESP32 → MQTT → mqtt-subscriber → esp_measurements   (Visualisierung, NICHT Abrechnung)
EDA-XLSX → eda-parser → eda_measurements            (Abrechnung, gesetzliche Grundlage)
```

Diese Trennung ist im österreichischen EAG verankert: Abrechnungsgrundlage sind ausschließlich die offiziellen 15-Minuten-Messwerte des Netzbetreibers (EDA-Portal). ESP32-Daten dienen nur zur Echtzeit-Visualisierung und sind in der UI entsprechend als "orientierend" gekennzeichnet.

### TimescaleDB Hypertables

| Tabelle | Chunk-Intervall | Begründung |
|---------|----------------|------------|
| `esp_measurements` | 1 Tag | Hohe Schreibfrequenz (ca. alle 5–10 s je Zählpunkt) |
| `eda_measurements` | 7 Tage | Geringere Schreibfrequenz (15-Min-Werte, nur bei Import) |

Chunks werden automatisch erstellt. Ältere Chunks können später mit TimescaleDB-Komprimierung verkleinert werden.

### Historisierte Konfiguration

Tarife (`tariff_config`) und Steuern (`tax_config`) werden nie überschrieben, sondern mit neuem `valid_from`-Datum hinzugefügt. Die Abrechnung liest immer den zum Abrechnungszeitraum gültigen Eintrag:

```sql
SELECT * FROM tariff_config
WHERE community_id = $1 AND valid_from <= $period_to
ORDER BY valid_from DESC
LIMIT 1;
```

**Grund:** Rechnungen müssen auch Jahre später mit den damals gültigen Tarifen nachvollziehbar sein.

---

## Verbindungsparameter

Die Webapp verbindet sich über PDO mit folgenden Parametern (aus `.env`):

```
Host:     timescaledb   (Docker-interner Hostname)
Port:     5432
User:     DB_USER
Password: DB_PASSWORD
Database: DB_NAME
```

Verbindungs-Pooling ist nicht konfiguriert (PHP-FPM übernimmt das durch persistente Verbindungen nicht — bei höherer Last PgBouncer nachschalten).

---

## Migrationen

Beim **ersten Start** führt TimescaleDB `database/init.sql` automatisch aus (Docker `initdb`-Mechanismus).

Für spätere Änderungen am laufenden System:

```bash
# Migrationsdatei erstellen
nano database/migrate_YYYYMMDD.sql

# Einspielen
make migrate FILE=database/migrate_YYYYMMDD.sql

# Datei committen (damit Fabian/Alexander nachziehen können)
git add database/migrate_YYYYMMDD.sql && git commit -m "..."
```

`init.sql` ist die Single Source of Truth für einen Neustart von Null. Sie wird bei bestehender DB nicht nochmals ausgeführt.

---

## Vollständiges Schema

Maschinenlesbares Schema (nur Struktur, keine Daten): [`docs/schema.sql`](schema.sql)

Aktualisieren:

```bash
make schema   # generiert docs/schema.sql aus der laufenden DB
```

ER-Diagramm als Mermaid: [`docs/er-diagramm.md`](er-diagramm.md)
