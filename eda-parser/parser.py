"""
EDA-XLSX-Parser
Importiert 15-Min-Energiedaten vom EDA-Anwenderportal in die Datenbank.
Dateiformat: RC108175_2026-05-11T00_00-2026-06-11T23_45.xlsx

Datenquellen-Interface ist abstrakt gehalten → späterer Wechsel auf KEP-API ohne Umbau.

Aufruf:
  python parser.py --file RC108175_2026-05-11T00_00-2026-06-11T23_45.xlsx \
                   --community strompool-feldkirchen \
                   --user-id <uuid>
"""

import argparse
import json
import logging
import os
import re
import uuid
from dataclasses import dataclass
from datetime import datetime, timezone
from typing import Protocol

import pandas as pd
import psycopg2
import psycopg2.extras

logging.basicConfig(level=logging.INFO, format="%(asctime)s [%(levelname)s] %(message)s")
log = logging.getLogger(__name__)

DB_DSN = (
    f"host={os.environ.get('DB_HOST', 'localhost')} "
    f"port={os.environ.get('DB_PORT', '5432')} "
    f"dbname={os.environ.get('DB_NAME', 'eeg_platform')} "
    f"user={os.environ.get('DB_USER', 'eeg')} "
    f"password={os.environ.get('DB_PASSWORD', '')}"
)


@dataclass
class MeteringPointData:
    zaehlpunkt_nr: str
    meter_code: str
    completeness: str     # COMPLETE | INCOMPLETE
    quality: str          # L1 | L2 | L3
    timeseries: pd.DataFrame  # columns: time, kwh_erzeugung, kwh_teilnahme, kwh_ueberschuss, kwh_restueberschuss


class EnergyDataSource(Protocol):
    """Interface: heute XLSX-Import, morgen KEP-API — gleiche Ausgabe."""
    def load(self, source: str, community_id: str) -> list[MeteringPointData]: ...


class XlsxDataSource:
    """Liest die EDA-XLSX aus dem Anwenderportal."""

    def load(self, filepath: str, community_id: str) -> list[MeteringPointData]:
        log.info("Lese XLSX: %s", filepath)
        xl = pd.ExcelFile(filepath)

        if "Übersicht" not in xl.sheet_names or "Energiedaten" not in xl.sheet_names:
            raise ValueError("XLSX hat nicht die erwarteten Sheets 'Übersicht' und 'Energiedaten'")

        overview = self._parse_overview(xl)
        energy = self._parse_energy(xl)

        result = []
        for zaehlpunkt_nr, meta in overview.items():
            ts = energy.get(zaehlpunkt_nr)
            if ts is None:
                log.warning("Zählpunkt %s in Übersicht, aber nicht in Energiedaten", zaehlpunkt_nr)
                ts = pd.DataFrame()
            result.append(MeteringPointData(
                zaehlpunkt_nr=zaehlpunkt_nr,
                meter_code=meta["meter_code"],
                completeness=meta["completeness"],
                quality=meta["quality"],
                timeseries=ts,
            ))
        return result

    def _parse_overview(self, xl: pd.ExcelFile) -> dict:
        df = pd.read_excel(xl, sheet_name="Übersicht", header=0)
        result = {}
        for _, row in df.iterrows():
            # Spaltennamen variieren je nach Export — flexibel per Substring suchen
            zp = self._find_col(row, ["Zählpunkt", "ZP", "Metering"])
            mc = self._find_col(row, ["MeterCode", "Meter Code"])
            comp = self._find_col(row, ["Vollständigkeit", "Completeness", "COMPLETE"])
            qual = self._find_col(row, ["Qualität", "Quality", "Datenqualität"])
            if zp:
                result[str(zp).strip()] = {
                    "meter_code": str(mc).strip() if mc else "",
                    "completeness": "COMPLETE" if comp and "COMPLETE" in str(comp).upper() else "INCOMPLETE",
                    "quality": str(qual).strip() if qual else "L1",
                }
        return result

    def _parse_energy(self, xl: pd.ExcelFile) -> dict:
        df = pd.read_excel(xl, sheet_name="Energiedaten", header=None)

        # Zeilenpositionen der Zählpunktnummern und MeterCodes aus Header ermitteln
        # Erste Spalte = Zeitstempel, danach je Zählpunkt 4 Spaltengruppen
        header_rows = df.iloc[:5]
        col_map = {}  # zaehlpunkt_nr → start_col_index

        for col in range(1, len(df.columns), 4):
            for row_idx in range(5):
                val = str(header_rows.iloc[row_idx, col]) if col < len(df.columns) else ""
                if val.startswith("AT") and len(val) > 30:
                    meter_code_val = str(header_rows.iloc[row_idx + 1, col]) if row_idx + 1 < 5 else ""
                    col_map[val.strip()] = {
                        "start": col,
                        "meter_code": meter_code_val.strip(),
                    }
                    break

        # Datenzeilen: ab Zeile wo erster Wert ein Datum ist
        data_start = 0
        for i, row in df.iterrows():
            val = row.iloc[0]
            if isinstance(val, datetime) or (isinstance(val, str) and re.match(r"\d{2}\.\d{2}\.\d{4}", val)):
                data_start = i
                break

        result = {}
        for zp, info in col_map.items():
            c = info["start"]
            mc = info["meter_code"]
            rows = []
            for i in range(data_start, len(df)):
                ts_raw = df.iloc[i, 0]
                if pd.isna(ts_raw):
                    continue
                ts = pd.to_datetime(ts_raw, dayfirst=True, errors="coerce")
                if pd.isna(ts):
                    continue
                ts = ts.tz_localize("Europe/Vienna", ambiguous="NaT", nonexistent="NaT")

                def safe(col_offset):
                    try:
                        v = df.iloc[i, c + col_offset]
                        return float(v) / 1000.0 if not pd.isna(v) else None  # Wh → kWh
                    except (IndexError, ValueError, TypeError):
                        return None

                rows.append({
                    "time": ts,
                    "kwh_erzeugung": safe(0),
                    "kwh_teilnahme": safe(1),
                    "kwh_ueberschuss": safe(2),
                    "kwh_restueberschuss": safe(3),
                })
            result[zp] = pd.DataFrame(rows)
        return result

    @staticmethod
    def _find_col(row, keywords: list[str]):
        for key in row.index:
            for kw in keywords:
                if kw.lower() in str(key).lower():
                    return row[key]
        return None


def import_to_db(
    conn,
    community_id: str,
    data: list[MeteringPointData],
    filename: str,
    user_id: str | None,
) -> dict:
    warnings = []
    total_records = 0

    with conn.cursor(cursor_factory=psycopg2.extras.RealDictCursor) as cur:
        # Registrierte Zählpunkte der Community laden
        cur.execute(
            "SELECT id, zaehlpunkt_nr FROM metering_points WHERE community_id = %s AND active = true",
            (community_id,)
        )
        registered = {row["zaehlpunkt_nr"]: str(row["id"]) for row in cur.fetchall()}

    zp_in_xlsx = {d.zaehlpunkt_nr for d in data}
    zp_registered = set(registered.keys())

    # Fehlende Zählpunkte: in DB registriert, aber nicht in XLSX
    for missing in zp_registered - zp_in_xlsx:
        warnings.append(f"Zählpunkt {missing} in DB registriert, aber nicht in XLSX")
        log.warning("Fehlender Zählpunkt: %s", missing)

    # Unbekannte Zählpunkte: in XLSX, aber nicht in DB registriert (kein Fehler, nur Info)
    for unknown in zp_in_xlsx - zp_registered:
        log.info("Zählpunkt %s in XLSX, aber nicht in DB registriert — wird übersprungen", unknown)

    period_from = None
    period_to = None

    with conn.cursor() as cur:
        for mp_data in data:
            zp = mp_data.zaehlpunkt_nr
            if zp not in registered:
                continue

            mp_id = registered[zp]

            if mp_data.timeseries.empty:
                warnings.append(f"Keine Energiedaten für Zählpunkt {zp}")
                continue

            # Zeitraum ermitteln
            ts_min = mp_data.timeseries["time"].min()
            ts_max = mp_data.timeseries["time"].max()
            if period_from is None or ts_min < period_from:
                period_from = ts_min
            if period_to is None or ts_max > period_to:
                period_to = ts_max

            # Duplikat-Check: gleicher Zeitraum schon importiert?
            cur.execute(
                """
                SELECT COUNT(*) FROM eda_measurements
                WHERE community_id = %s AND metering_point_id = %s
                  AND time >= %s AND time <= %s
                """,
                (community_id, mp_id, ts_min, ts_max)
            )
            existing = cur.fetchone()[0]
            if existing > 0:
                raise ValueError(
                    f"Duplikat: Zählpunkt {zp} hat bereits {existing} Datensätze "
                    f"für den Zeitraum {ts_min} – {ts_max}. Import abgebrochen."
                )

            # Einfügen
            rows = [
                (
                    row["time"],
                    community_id,
                    mp_id,
                    mp_data.meter_code,
                    row.get("kwh_erzeugung"),
                    row.get("kwh_teilnahme"),
                    row.get("kwh_ueberschuss"),
                    row.get("kwh_restueberschuss"),
                    mp_data.quality,
                    mp_data.completeness,
                )
                for _, row in mp_data.timeseries.iterrows()
                if not pd.isna(row["time"])
            ]

            psycopg2.extras.execute_values(
                cur,
                """
                INSERT INTO eda_measurements
                    (time, community_id, metering_point_id, meter_code,
                     kwh_erzeugung, kwh_teilnahme, kwh_ueberschuss, kwh_restueberschuss,
                     quality, completeness)
                VALUES %s
                ON CONFLICT DO NOTHING
                """,
                rows,
            )
            total_records += len(rows)
            log.info("Zählpunkt %s: %d Datensätze importiert (%s, %s)", zp, len(rows), mp_data.quality, mp_data.completeness)

        # Import-Protokoll
        cur.execute(
            """
            INSERT INTO eda_imports
                (community_id, imported_by, filename, period_from, period_to,
                 records_imported, warnings, status)
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s)
            RETURNING id
            """,
            (
                community_id,
                user_id,
                filename,
                period_from,
                period_to,
                total_records,
                json.dumps(warnings),
                "warning" if warnings else "ok",
            )
        )
        import_id = cur.fetchone()[0]

    conn.commit()
    log.info("Import abgeschlossen: %d Datensätze, %d Warnungen (Import-ID: %s)", total_records, len(warnings), import_id)

    return {
        "import_id": str(import_id),
        "records": total_records,
        "warnings": warnings,
        "period_from": str(period_from) if period_from else None,
        "period_to": str(period_to) if period_to else None,
    }


def check_billing_readiness(conn, community_id: str, quartal: str) -> dict:
    """
    Prüft ob für das Quartal eine Abrechnung möglich ist.
    Regeln:
    1. Alle registrierten Zählpunkte müssen COMPLETE sein
    2. 60-Tage-Korrekturfenster muss abgelaufen sein
    3. Vollständiger Zeitraum muss abgedeckt sein
    """
    from datetime import date, timedelta

    # Quartal-Zeitraum bestimmen
    year, q = quartal.split("-Q")
    year = int(year)
    q = int(q)
    quarter_starts = {1: (1, 1), 2: (4, 1), 3: (7, 1), 4: (10, 1)}
    quarter_ends = {1: (3, 31), 2: (6, 30), 3: (9, 30), 4: (12, 31)}
    period_from = date(year, *quarter_starts[q])
    period_to = date(year, *quarter_ends[q])
    freigabe_nach = period_to + timedelta(days=60)  # 60-Tage-Korrekturfenster

    issues = []

    if date.today() < freigabe_nach:
        issues.append(
            f"60-Tage-Korrekturfenster noch nicht abgelaufen "
            f"(Freigabe ab {freigabe_nach.strftime('%d.%m.%Y')} möglich)"
        )

    with conn.cursor(cursor_factory=psycopg2.extras.RealDictCursor) as cur:
        cur.execute(
            "SELECT id, zaehlpunkt_nr FROM metering_points WHERE community_id = %s AND active = true",
            (community_id,)
        )
        metering_points = cur.fetchall()

        for mp in metering_points:
            cur.execute(
                """
                SELECT COUNT(*) as cnt,
                       COUNT(*) FILTER (WHERE completeness = 'COMPLETE') as complete_cnt
                FROM eda_measurements
                WHERE community_id = %s AND metering_point_id = %s
                  AND time >= %s AND time < %s + INTERVAL '1 day'
                """,
                (community_id, mp["id"], period_from, period_to)
            )
            row = cur.fetchone()
            if row["cnt"] == 0:
                issues.append(f"Keine EDA-Daten für Zählpunkt {mp['zaehlpunkt_nr']}")
            elif row["complete_cnt"] < row["cnt"]:
                issues.append(
                    f"Zählpunkt {mp['zaehlpunkt_nr']}: "
                    f"{row['cnt'] - row['complete_cnt']} von {row['cnt']} Intervallen INCOMPLETE"
                )

    return {
        "ready": len(issues) == 0,
        "quartal": quartal,
        "period_from": str(period_from),
        "period_to": str(period_to),
        "freigabe_nach": str(freigabe_nach),
        "issues": issues,
    }


def main():
    parser = argparse.ArgumentParser(description="EDA-XLSX-Importer")
    parser.add_argument("--file", required=True, help="Pfad zur XLSX-Datei")
    parser.add_argument("--community", required=True, help="Community-Slug")
    parser.add_argument("--user-id", help="UUID des importierenden Users")
    parser.add_argument("--check-billing", help="Quartal prüfen z.B. 2026-Q2")
    args = parser.parse_args()

    conn = psycopg2.connect(DB_DSN)

    try:
        # Community-ID auflösen
        with conn.cursor() as cur:
            cur.execute("SELECT id FROM communities WHERE slug = %s", (args.community,))
            row = cur.fetchone()
            if not row:
                raise SystemExit(f"Community '{args.community}' nicht gefunden")
            community_id = str(row[0])

        if args.check_billing:
            result = check_billing_readiness(conn, community_id, args.check_billing)
            print(json.dumps(result, indent=2, ensure_ascii=False))
            return

        source = XlsxDataSource()
        data = source.load(args.file, community_id)
        result = import_to_db(conn, community_id, data, os.path.basename(args.file), args.user_id)
        print(json.dumps(result, indent=2, ensure_ascii=False))

    finally:
        conn.close()


if __name__ == "__main__":
    main()
