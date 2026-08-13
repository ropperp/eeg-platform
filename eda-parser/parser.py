"""
EDA-XLSX-Parser
Importiert den monatlichen EDA-Energiedatenreport (Anwenderportal, Sheets "Gesamtübersicht" +
"Detailübersicht") in die Datenbank. Eine Datei deckt IMMER genau einen Kalendermonat ab -- für
einen Quartals-Abrechnungslauf werden drei Monatsdateien nacheinander hochgeladen/importiert,
die Beträge summieren sich beim Abrechnen automatisch (Billing::generateDrafts() summiert über
den ganzen Quartalszeitraum, siehe webapp/src/Billing.php).

Format bestätigt anhand einer echten Exportdatei (Patrick, 05.08.2026) -- vorher war hier eine
geratene Struktur ("Übersicht"/"Energiedaten"-Sheets mit 4-Spalten-Blöcken je Zählpunkt), die nie
an einer echten Datei getestet wurde und nicht dem tatsächlichen EDA-Format entsprach.

Aufbau der echten Datei (siehe docs/EDA_DATENQUALITAET.md, docs/AUFTEILUNGSSCHLUESSEL.md):
- "Gesamtübersicht": eine Zeile je Zählpunkt für den ganzen Monat, mit den bereits fertig
  aufgeteilten, ABRECHNUNGSRELEVANTEN Energiemengen (Spalte "Verbrauch, abrechnungsrelevante
  Energiemenge" bzw. "Erzeugung, abrechnungsrelevante Energiemenge") -- das ist exakt das, was
  Billing::generateDrafts() als kwh_teilnahme (Bezug) bzw. kwh_erzeugung (Einspeisung) braucht.
  Zusätzlich Status Datenübermittlung (Vollständig/Unvollständig), Datenqualität (L1/L2/L3,
  kommagetrennt möglich) und Stammdaten (Vorname/Nachname/Adresse) zur Orientierung.
- "Detailübersicht": dieselben Zählpunkte, aber mit den Einzelkomponenten (Gesamtverbrauch,
  Eigendeckung, Restüberschuss, ...) -- wird hier nur ergänzend für kwh_ueberschuss/
  kwh_restueberschuss gelesen (in Billing.php nicht abrechnungswirksam, aber Teil des Schemas/
  für Transparenz gedacht).

Datenquellen-Interface ist abstrakt gehalten → späterer Wechsel auf KEP-API ohne Umbau.

Erneuter Import für einen Zeitraum, der schon Daten hat ("Duplikat"): wird seit 13.08.2026
automatisch ÜBERSCHRIEBEN, SOLANGE dafür noch keine Rechnungen verschickt wurden (kein
Abrechnungslauf mit status 'released'/'done' für den Zeitraum, siehe
_billing_period_finalized()) -- Patrick: bei zunächst nur L3-Datenqualität soll ein späterer,
besserer EDA-Export den alten Import ersetzen können, statt manuell aufräumen zu müssen. Ist
der Zeitraum bereits abgerechnet, bleibt es beim harten Fehler wie zuvor (schützt bereits
verschickte Rechnungen).

Aufruf:
  python parser.py --file RC108175_2026070120260731_20260805T200419.xlsx \
                   --community strompool-feldkirchen \
                   --user-id <uuid>
"""

import argparse
import json
import logging
import os
from dataclasses import dataclass
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
    meter_code: str          # EDA liefert keine Zählernummer -- immer leer, siehe Kommentar unten
    type_hint: str            # 'consumer' | 'producer' -- direkt aus "Energierichtung", keine Vermutung mehr
    completeness: str         # COMPLETE | INCOMPLETE
    quality: str               # L1 | L2 | L3 (schlechtester Wert, falls die Datei mehrere kommagetrennt nennt)
    timeseries: pd.DataFrame  # eine Zeile je Monatsimport: time, kwh_erzeugung, kwh_teilnahme, kwh_ueberschuss, kwh_restueberschuss


@dataclass
class LoadResult:
    metering_points: list[MeteringPointData]
    # Der im Datei-Kopf deklarierte Auswertungszeitraum (immer ein voller Kalendermonat) --
    # bewusst NICHT aus den (teils unterjährig verkürzten) Teilnahmezeiträumen einzelner
    # Zählpunkte abgeleitet, damit Billing::missingMonths() zuverlässig erkennt, welcher Monat
    # importiert wurde (Patrick, 05.08.2026: "damit man wirklich alle Daten von diesem Quartal
    # hat und nicht gar einen Monat vergessen hat").
    period_from: object
    period_to: object


class EnergyDataSource(Protocol):
    """Interface: heute XLSX-Import, morgen KEP-API — gleiche Ausgabe."""
    def load(self, source: str, community_id: str) -> LoadResult: ...


class XlsxDataSource:
    """Liest den EDA-Energiedatenreport (Monatsexport) aus dem Anwenderportal."""

    def load(self, filepath: str, community_id: str) -> LoadResult:
        log.info("Lese XLSX: %s", filepath)
        xl = pd.ExcelFile(filepath)

        sheet_gesamt = self._find_sheet(xl, "Gesamtübersicht")
        sheet_detail = self._find_sheet(xl, "Detailübersicht")
        if not sheet_gesamt or not sheet_detail:
            raise ValueError(
                "XLSX hat nicht die erwarteten Sheets 'Gesamtübersicht' und 'Detailübersicht' "
                "(EDA-Energiedatenreport, Monatsexport). "
                f"Tatsächlich vorhandene Sheets: {xl.sheet_names}"
            )

        overview = self._parse_gesamtuebersicht(xl, sheet_gesamt)
        detail = self._parse_detailuebersicht(xl, sheet_detail)
        period_from, period_to = self._parse_auswertungszeitraum(xl, sheet_gesamt)

        result = []
        for zaehlpunkt_nr, meta in overview.items():
            zusatz = detail.get(zaehlpunkt_nr, {})
            row = {
                "time": meta["time"],
                "kwh_erzeugung": meta["kwh_wert"] if meta["type_hint"] == "producer" else None,
                "kwh_teilnahme": meta["kwh_wert"] if meta["type_hint"] == "consumer" else None,
                "kwh_ueberschuss": zusatz.get("kwh_ueberschuss"),
                "kwh_restueberschuss": zusatz.get("kwh_restueberschuss"),
            }
            result.append(MeteringPointData(
                zaehlpunkt_nr=zaehlpunkt_nr,
                meter_code="",  # EDA-Report enthält keine Zählernummer, nur die Zählpunktnummer
                type_hint=meta["type_hint"],
                completeness=meta["completeness"],
                quality=meta["quality"],
                timeseries=pd.DataFrame([row]),
            ))
        return LoadResult(metering_points=result, period_from=period_from, period_to=period_to)

    @staticmethod
    def _parse_auswertungszeitraum(xl: pd.ExcelFile, sheet_name: str) -> tuple:
        """Liest "Auswertungszeitraum von"/"bis" aus dem Datei-Kopf (immer ein voller
        Kalendermonat) -- siehe Kommentar bei LoadResult."""
        raw = pd.read_excel(xl, sheet_name=sheet_name, header=None)
        von = bis = None
        for i in range(min(10, len(raw))):
            label = str(raw.iloc[i, 0]).strip().lower() if not pd.isna(raw.iloc[i, 0]) else ""
            if label == "auswertungszeitraum von":
                von = pd.to_datetime(str(raw.iloc[i, 1]).strip(), dayfirst=True, errors="coerce")
            elif label == "auswertungszeitraum bis":
                bis = pd.to_datetime(str(raw.iloc[i, 1]).strip(), dayfirst=True, errors="coerce")
        if von is None or bis is None or pd.isna(von) or pd.isna(bis):
            raise ValueError('„Auswertungszeitraum von/bis" im Datei-Kopf nicht gefunden oder unlesbar.')
        tz = "Europe/Vienna"
        return von.tz_localize(tz), bis.tz_localize(tz)

    @staticmethod
    def _find_sheet(xl: pd.ExcelFile, expected_name: str) -> str | None:
        """Findet ein Sheet unabhängig von Groß-/Kleinschreibung und Leerzeichen."""
        target = expected_name.strip().lower()
        for name in xl.sheet_names:
            if name.strip().lower() == target:
                return name
        return None

    @staticmethod
    def _find_header_row(raw: pd.DataFrame, marker: str = "zählpunktnummer") -> int:
        """Die echten Spaltenüberschriften stehen nicht in Zeile 1 -- davor liegen EC-ID,
        Auswertungszeitraum-von/bis usw. Sucht die Zeile, die "Zählpunktnummer" enthält, statt
        eine feste Zeilennummer anzunehmen (robuster gegenüber künftigen Formatanpassungen)."""
        for i in range(min(15, len(raw))):
            for val in raw.iloc[i]:
                if isinstance(val, str) and val.strip().lower() == marker:
                    return i
        raise ValueError(f"Header-Zeile mit Spalte '{marker}' nicht gefunden.")

    @staticmethod
    def _find_col(columns, keywords: list[str]) -> str | None:
        """Sucht eine Spalte per Substring (case-insensitive) -- robust gegenüber kleinen
        Formulierungsunterschieden zwischen EDA-Exportversionen, statt exakter Spaltennamen."""
        for col in columns:
            col_str = str(col).lower()
            for kw in keywords:
                if kw.lower() in col_str:
                    return col
        return None

    @staticmethod
    def _worst_quality(raw_value: str) -> str:
        """Datenqualität kann kommagetrennt mehrere Kategorien nennen (z.B. "L1,L3", wenn sich
        innerhalb des Monats die Qualität geändert hat). Für die Abrechnung zählt der
        SCHLECHTESTE enthaltene Wert (L3 blockiert die Freigabe, siehe Billing::ABRECHNUNGS_QUALITY
        bzw. docs/EDA_DATENQUALITAET.md)."""
        s = str(raw_value).upper()
        if "L3" in s: return "L3"
        if "L2" in s: return "L2"
        return "L1"

    def _parse_gesamtuebersicht(self, xl: pd.ExcelFile, sheet_name: str) -> dict:
        raw = pd.read_excel(xl, sheet_name=sheet_name, header=None)
        header_row = self._find_header_row(raw)
        df = pd.read_excel(xl, sheet_name=sheet_name, header=header_row)
        df = df.iloc[1:]  # direkt unter der Kopfzeile steht eine Beschreibungszeile ("Bezeichnung so wie im EDA Portal ...") -- keine Daten

        col_zp       = self._find_col(df.columns, ["Zählpunktnummer"])
        col_richtung = self._find_col(df.columns, ["Energierichtung"])
        col_zeitraum = self._find_col(df.columns, ["Zeitraum"])
        col_verbrauch = self._find_col(df.columns, ["Verbrauch, abrechnungsrelevante", "Verbrauch,abrechnungsrelevante"])
        col_erzeugung = self._find_col(df.columns, ["Erzeugung, abrechnungsrelevante", "Erzeugung,abrechnungsrelevante"])
        col_status   = self._find_col(df.columns, ["Status Datenübermittlung"])
        col_qualitaet = self._find_col(df.columns, ["Datenqualität"])
        if not all([col_zp, col_richtung, col_zeitraum, col_verbrauch, col_erzeugung, col_status, col_qualitaet]):
            raise ValueError(
                "Gesamtübersicht: nicht alle erwarteten Spalten gefunden "
                f"(Zählpunktnummer/Energierichtung/Zeitraum/abrechnungsrelevante Mengen/Status/Datenqualität). "
                f"Vorhandene Spalten: {list(df.columns)}"
            )

        result = {}
        for _, row in df.iterrows():
            zp = row[col_zp]
            if pd.isna(zp) or not str(zp).strip().upper().startswith("AT"):
                continue
            zp = str(zp).strip()

            richtung = str(row[col_richtung]).strip().upper()
            type_hint = "producer" if richtung == "ERZEUGUNG" else "consumer"

            # "Zeitraum" ist der tatsächliche Teilnahmezeitraum dieses Zählpunkts im Monat
            # (z.B. "02.07.2026-31.07.2026" bei unterjährigem Beitritt) -- das Enddatum wird als
            # Zeitstempel dieser Monatszeile verwendet (siehe Billing.php: SUM über
            # period_from..period_to je Quartal, drei Monatszeilen fallen automatisch zusammen).
            zeitraum = str(row[col_zeitraum]).strip()
            bis_str = zeitraum.split("-")[-1].strip()
            ts = pd.to_datetime(bis_str, dayfirst=True, errors="coerce")
            if pd.isna(ts):
                log.warning("Zählpunkt %s: Zeitraum '%s' nicht lesbar, überspringe.", zp, zeitraum)
                continue
            ts = ts.tz_localize("Europe/Vienna", ambiguous="NaT", nonexistent="NaT")

            kwh_wert = row[col_erzeugung] if type_hint == "producer" else row[col_verbrauch]
            kwh_wert = float(kwh_wert) if not pd.isna(kwh_wert) else 0.0

            status = str(row[col_status]).strip().lower()
            completeness = "COMPLETE" if status == "vollständig" else "INCOMPLETE"

            result[zp] = {
                "type_hint": type_hint,
                "time": ts,
                "kwh_wert": kwh_wert,
                "completeness": completeness,
                "quality": self._worst_quality(row[col_qualitaet]),
            }
        return result

    def _parse_detailuebersicht(self, xl: pd.ExcelFile, sheet_name: str) -> dict:
        """Liefert nur die Ergänzungswerte kwh_ueberschuss/kwh_restueberschuss je Zählpunkt (aus
        den Detailspalten "Gesamt/Überschusserzeugung..." und "Restüberschuss..."). Die
        abrechnungsrelevanten Hauptwerte kommen bewusst aus der Gesamtübersicht (siehe
        _parse_gesamtuebersicht), nicht von hier -- das erspart, "Eigendeckung"/"Restüberschuss"
        selbst nachzurechnen, was EDA bereits fertig geliefert hat."""
        raw = pd.read_excel(xl, sheet_name=sheet_name, header=None)
        header_row = self._find_header_row(raw)
        df = pd.read_excel(xl, sheet_name=sheet_name, header=header_row)

        col_zp = self._find_col(df.columns, ["Zählpunktnummer"])
        col_ueberschuss = self._find_col(df.columns, ["Gesamt/Überschusserzeugung", "Überschusserzeugung"])
        col_rest = self._find_col(df.columns, ["Restüberschuss"])
        if not col_zp:
            return {}

        result = {}
        for _, row in df.iterrows():
            zp = row[col_zp]
            # Filtert automatisch die Beschreibungs-/Markerzeilen ("Summe der Energiedaten",
            # "Energiedaten je Zählpunkt") UND die Community-Gesamtzeile heraus -- deren
            # Zählpunktnummer-Zelle ist leer.
            if pd.isna(zp) or not str(zp).strip().upper().startswith("AT"):
                continue
            zp = str(zp).strip()
            result[zp] = {
                "kwh_ueberschuss": float(row[col_ueberschuss]) if col_ueberschuss and not pd.isna(row[col_ueberschuss]) else None,
                "kwh_restueberschuss": float(row[col_rest]) if col_rest and not pd.isna(row[col_rest]) else None,
            }
        return result


def _billing_period_finalized(conn, community_id, period_from, period_to) -> bool:
    """Prüft, ob für den übergebenen Zeitraum (ein Kalendermonat, siehe file_period_from/
    file_period_to) bereits ein ABGESCHLOSSENER Abrechnungslauf existiert (status 'released'
    oder 'done', siehe billing_runs-CHECK-Constraint in init.sql) -- egal ob Monats- oder
    Quartalslauf, deshalb ein Bereichs- statt Exaktvergleich: period_from/period_to des
    Abrechnungslaufs müssen den Import-Zeitraum vollständig einschließen. Grundlage für die
    Überschreiben-Entscheidung in import_to_db() unten (Patrick, 13.08.2026: solange noch keine
    Rechnungen für den Zeitraum verschickt wurden, darf ein erneuter Import mit besserer
    Datenqualität -- z.B. wenn zunächst nur L3-Werte verfügbar waren -- den alten überschreiben;
    danach nicht mehr, um bereits verschickte Rechnungen nicht rückwirkend falsch aussehen zu
    lassen)."""
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT 1 FROM billing_runs
            WHERE community_id = %s AND status IN ('released', 'done')
              AND period_from <= %s AND period_to >= %s
            LIMIT 1
            """,
            (community_id, period_from, period_to)
        )
        return cur.fetchone() is not None


def import_to_db(
    conn,
    community_id: str,
    data: list[MeteringPointData],
    filename: str,
    user_id: str | None,
    file_period_from,
    file_period_to,
) -> dict:
    """
    Siehe docs/ESP_IDEEN.md Punkt 3: gleicht die im EDA-Export enthaltenen Zählpunkte mit dem
    Bestand ab und meldet Abweichungen ausformuliert statt nur mit einer knappen Log-Zeile.

    - Zählpunkte, die bei uns AKTIV registriert sind, im Export aber fehlen -> Warnung.
    - Zählpunkte, die im Export auftauchen, aber bei uns unbekannt sind -> werden automatisch
      angelegt (member_id NULL, active=false), damit ihre Energiedaten nicht verloren gehen,
      aber OHNE sie irgendeinem Mitglied zuzuordnen oder aktiv/abrechenbar zu machen -- das
      würde falsch raten. Ein Obmann muss sie manuell zuordnen und prüfen/aktivieren (siehe
      /portal/metering-points/unassigned).
    """
    warnings = []
    neu_angelegt = []
    total_records = 0

    with conn.cursor(cursor_factory=psycopg2.extras.RealDictCursor) as cur:
        # Registrierte Zählpunkte der Community laden (auch inaktive, damit ein bereits
        # deaktivierter/noch nicht zugeordneter Zählpunkt nicht ein zweites Mal angelegt wird)
        cur.execute(
            "SELECT id, zaehlpunkt_nr FROM metering_points WHERE community_id = %s",
            (community_id,)
        )
        registered = {row["zaehlpunkt_nr"]: str(row["id"]) for row in cur.fetchall()}

        cur.execute(
            "SELECT id, zaehlpunkt_nr FROM metering_points WHERE community_id = %s AND active = true",
            (community_id,)
        )
        aktiv = {row["zaehlpunkt_nr"] for row in cur.fetchall()}

    zp_in_xlsx = {d.zaehlpunkt_nr for d in data}

    # Fehlende Zählpunkte: bei uns AKTIV, aber nicht im Export enthalten.
    for missing in aktiv - zp_in_xlsx:
        warnings.append(
            f"Zählpunkt {missing} ist bei uns aktiv, taucht im EDA-Export für diesen Zeitraum "
            "aber nicht auf — evtl. Abmeldung, Zählerwechsel oder Datenlücke; bitte prüfen."
        )
        log.warning("Fehlender Zählpunkt: %s", missing)

    # Unbekannte Zählpunkte: im Export enthalten, bei uns noch gar nicht registriert ->
    # automatisch anlegen, aber bewusst inaktiv und ohne Mitglied-Zuordnung. Der Typ kommt jetzt
    # direkt aus der EDA-"Energierichtung" (type_hint) statt aus einer Erzeugung/Verbrauch-Summen-
    # Vermutung wie zuvor -- eindeutig statt geraten, weil EDA jede Richtung als eigenen Zählpunkt
    # meldet (siehe Prosumer-Korrektur vom 30.07.2026: ein physischer Zähler kann zwei
    # Zählpunktnummern haben, aber jede Zeile im Export ist eindeutig VERBRAUCH oder ERZEUGUNG).
    with conn.cursor() as cur:
        for mp_data in data:
            zp = mp_data.zaehlpunkt_nr
            if zp in registered:
                continue

            cur.execute(
                """
                INSERT INTO metering_points
                    (community_id, member_id, zaehlpunkt_nr, meter_code, type, active, registered_at)
                VALUES (%s, NULL, %s, %s, %s, false, CURRENT_DATE)
                RETURNING id
                """,
                (community_id, zp, mp_data.meter_code, mp_data.type_hint)
            )
            new_id = str(cur.fetchone()[0])
            registered[zp] = new_id
            neu_angelegt.append({
                "zaehlpunkt_nr": zp,
                "meter_code": mp_data.meter_code,
                "type_guess": mp_data.type_hint,
                "metering_point_id": new_id,
            })
            log.warning("Zählpunkt %s automatisch angelegt (Typ laut EDA: %s, unzugeordnet)", zp, mp_data.type_hint)

    # Eine zusammenfassende Warnung (nicht pro Zählpunkt -- die Details stehen im eigenen
    # "Neu angelegt"-Abschnitt der UI) sorgt dafür, dass der Import trotzdem als "warning" statt
    # "ok" markiert wird, solange noch etwas zuzuordnen ist.
    if neu_angelegt:
        warnings.append(
            f"{len(neu_angelegt)} neu angelegte, noch nicht zugeordnete Zählpunkte "
            "(siehe Abschnitt „Neu angelegt\" oben)."
        )

    # Erneuter Import für einen Zeitraum, der schon Daten hat: erlaubt, SOLANGE dafür noch kein
    # abgeschlossener Abrechnungslauf existiert (siehe _billing_period_finalized() oben) -- z.B.
    # weil zunächst nur eine L3-Datenqualität verfügbar war und ein späterer EDA-Export bessere
    # Werte liefert. Einmal pro Aufruf geprüft (gilt für die ganze Datei/den ganzen Zeitraum,
    # nicht pro Zählpunkt einzeln).
    overwrite_allowed = not _billing_period_finalized(conn, community_id, file_period_from, file_period_to)
    overwritten: list[str] = []
    old_imports_cleaned = False

    with conn.cursor() as cur:
        for mp_data in data:
            zp = mp_data.zaehlpunkt_nr
            if zp not in registered:
                continue

            mp_id = registered[zp]

            if mp_data.timeseries.empty:
                warnings.append(f"Keine Energiedaten für Zählpunkt {zp}")
                continue

            # Nur für den Duplikat-Check innerhalb dieses Zählpunkts -- der GESAMTE Zeitraum
            # dieses Imports (für eda_imports.period_from/period_to) kommt aus dem Datei-Kopf
            # (file_period_from/file_period_to), nicht aus diesen Einzelwerten (siehe LoadResult).
            ts_min = mp_data.timeseries["time"].min()
            ts_max = mp_data.timeseries["time"].max()

            # Duplikat-Check: dieser Zählpunkt hat für exakt dieses Monatsdatum schon einen
            # Wert? (Ein Quartal besteht bewusst aus DREI separaten Monatsimporten mit je einem
            # anderen Datum je Zählpunkt -- die kollidieren hier nicht miteinander.)
            cur.execute(
                """
                SELECT COUNT(*) FROM eda_measurements
                WHERE community_id = %s AND metering_point_id = %s
                  AND time >= %s AND time <= %s
                """,
                (community_id, mp_id, ts_min, ts_max)
            )
            existing = cur.fetchone()[0]
            if existing > 0 and not overwrite_allowed:
                raise ValueError(
                    f"Duplikat: Zählpunkt {zp} hat bereits {existing} Datensatz/Datensätze "
                    f"für den Zeitraum {ts_min} – {ts_max}. Import abgebrochen -- für diesen "
                    "Zeitraum wurden bereits Rechnungen verschickt (Abrechnungslauf "
                    "'released'/'done'), ein Überschreiben würde bereits verschickte "
                    "Rechnungen rückwirkend falsch aussehen lassen. Nur über einen neuen "
                    "Abrechnungslauf/manuell korrigierbar."
                )
            if existing > 0 and overwrite_allowed:
                # Erlaubtes Überschreiben (siehe overwrite_allowed oben): alte Messwerte dieses
                # Zählpunkts für exakt diesen Zeitraum löschen, danach unten normal neu einfügen.
                cur.execute(
                    """
                    DELETE FROM eda_measurements
                    WHERE community_id = %s AND metering_point_id = %s
                      AND time >= %s AND time <= %s
                    """,
                    (community_id, mp_id, ts_min, ts_max)
                )
                overwritten.append(zp)
                if not old_imports_cleaned:
                    # Der/die alte(n) eda_imports-Protokolleintrag/-einträge für exakt diesen
                    # Zeitraum werden ersetzt (nicht als zweiter Eintrag daneben stehen gelassen,
                    # siehe "Bisherige Importe" auf /portal/eda/upload) -- einmal pro Lauf, nicht
                    # pro Zählpunkt.
                    cur.execute(
                        "DELETE FROM eda_imports WHERE community_id = %s AND period_from = %s AND period_to = %s",
                        (community_id, file_period_from, file_period_to)
                    )
                    old_imports_cleaned = True

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
            log.info("Zählpunkt %s: %d Datensatz/Datensätze importiert (%s, %s)", zp, len(rows), mp_data.quality, mp_data.completeness)

        if overwritten:
            warnings.append(
                f"{len(overwritten)} Zählpunkt(e) für diesen Zeitraum bereits vorhanden gewesen "
                "und mit diesem Import überschrieben (noch kein abgeschlossener Abrechnungslauf "
                "für den Zeitraum)."
            )
            log.warning("%d Zählpunkt(e) überschrieben: %s", len(overwritten), ", ".join(overwritten))

        # Import-Protokoll
        cur.execute(
            """
            INSERT INTO eda_imports
                (community_id, imported_by, filename, period_from, period_to,
                 records_imported, warnings, neu_angelegt, status)
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s)
            RETURNING id
            """,
            (
                community_id,
                user_id,
                filename,
                file_period_from,
                file_period_to,
                total_records,
                json.dumps(warnings),
                json.dumps(neu_angelegt),
                "warning" if warnings else "ok",
            )
        )
        import_id = cur.fetchone()[0]

    conn.commit()
    log.info(
        "Import abgeschlossen: %d Datensätze, %d Warnungen, %d neu angelegt, %d überschrieben (Import-ID: %s)",
        total_records, len(warnings), len(neu_angelegt), len(overwritten), import_id
    )

    return {
        "import_id": str(import_id),
        "records": total_records,
        "warnings": warnings,
        "neu_angelegt": neu_angelegt,
        "overwritten": overwritten,
        "period_from": str(file_period_from),
        "period_to": str(file_period_to),
    }


def main():
    parser = argparse.ArgumentParser(description="EDA-XLSX-Importer (Monatsexport)")
    parser.add_argument("--file", required=True, help="Pfad zur XLSX-Datei")
    parser.add_argument("--community", required=True, help="Community-Slug")
    parser.add_argument("--user-id", help="UUID des importierenden Users")
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

        source = XlsxDataSource()
        loaded = source.load(args.file, community_id)
        result = import_to_db(
            conn, community_id, loaded.metering_points, os.path.basename(args.file), args.user_id,
            loaded.period_from, loaded.period_to,
        )
        print(json.dumps(result, indent=2, ensure_ascii=False))

    finally:
        conn.close()


if __name__ == "__main__":
    main()
