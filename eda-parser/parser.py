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

Mehrmonatige Dateien (z.B. ein ganzes Quartal in einer Datei, seit 14.11.2026): der Gesamtwert
der Gesamtübersicht wird anhand der Monatszeilen der Detailübersicht auf die einzelnen Monate
aufgeteilt (eine eda_measurements-Zeile je Monat, Summe unverändert), siehe split_into_months().
Schon früher importierte Quartalsdateien: einmalig `--resplit` (Vorschau) bzw.
`--resplit --apply`, siehe resplit_existing().

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

# BEWUSST weiterhin DB_USER (Schema-Besitzer), NICHT die eingeschränkte APP_DB_USER-Rolle aus
# scripts/db_runtime_role_setup.sh (gleicher Grund wie mqtt-subscriber/main.py, Vorfall
# 17.08.2026: Row-Level Security blockierte dort jede Abfrage, weil app.community_id nie
# gesetzt wurde). Dieser Import läuft als vertrauenswürdiger Batch-Prozess (von PHP via
# EdaParserRunner.php gestartet, kein direkter HTTP-Zugriff von außen) und schreibt u.a. neue
# metering_points-Zeilen für noch unbekannte Zählpunkte -- RLS böte hier keinen zusätzlichen
# Schutz gegen eine realistische Bedrohung, würde aber ohne ein SET app.community_id vor jeder
# einzelnen Abfrage denselben Ausfall verursachen wie beim MQTT-Dienst.
_DB_USER = os.environ.get("DB_USER", "eeg")
_DB_PASSWORD = os.environ.get("DB_PASSWORD", "")

DB_DSN = (
    f"host={os.environ.get('DB_HOST', 'localhost')} "
    f"port={os.environ.get('DB_PORT', '5432')} "
    f"dbname={os.environ.get('DB_NAME', 'eeg_platform')} "
    f"user={_DB_USER} "
    f"password={_DB_PASSWORD}"
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
    # Monatsgenaue Datenqualität je Zählpunkt aus der Detailübersicht ("Energiedaten je
    # Zählpunkt"-Abschnitt, siehe _parse_detailuebersicht()) -- unabhängig davon, ob die Datei
    # einen Einzelmonat oder einen längeren, selbst gewählten Zeitraum (z.B. ein ganzes Quartal)
    # abdeckt, liefert EDA hier IMMER eine eigene Zeile je Kalendermonat. Jedes Element:
    # {"zaehlpunkt_nr", "month" (date, erster Tag des Monats), "quality", "completeness"}.
    monthly_quality: list[dict]


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
        detail, monthly_quality, monthly_values = self._parse_detailuebersicht(xl, sheet_detail)
        period_from, period_to = self._parse_auswertungszeitraum(xl, sheet_gesamt)
        quality_by_month = {(q["zaehlpunkt_nr"], q["month"]): q for q in monthly_quality}

        result = []
        split_notes = []
        for zaehlpunkt_nr, meta in overview.items():
            zusatz = detail.get(zaehlpunkt_nr, {})
            row = {
                "time": meta["time"],
                "teilnahme_von": meta.get("teilnahme_von"),
                "kwh_erzeugung": meta["kwh_wert"] if meta["type_hint"] == "producer" else None,
                "kwh_teilnahme": meta["kwh_wert"] if meta["type_hint"] == "consumer" else None,
                "kwh_ueberschuss": zusatz.get("kwh_ueberschuss"),
                "kwh_restueberschuss": zusatz.get("kwh_restueberschuss"),
            }
            rows = [row]
            # Mehrmonatige Datei (z.B. ein ganzes Quartal in EINER Datei): die Gesamtübersicht
            # hat nur EINE Zeile für den ganzen Zeitraum -- bisher landete der komplette Wert
            # deshalb mit dem Enddatum (z.B. 30.09.) im letzten Monat (Patrick, 14.11.2026:
            # "die ganze Leistung vom Quartal 3 [...] alles im September-Balken"). Jetzt wird er
            # anhand der Monatszeilen der Detailübersicht auf die einzelnen Monate aufgeteilt;
            # die Summe bleibt exakt gleich (Abrechnung unverändert), siehe split_into_months().
            split = split_into_months(
                meta["kwh_wert"], monthly_values.get(zaehlpunkt_nr, {}), meta["type_hint"],
                period_from, period_to, meta.get("teilnahme_von"),
            )
            if split is not None:
                months, note = split
                if note:
                    split_notes.append(f"{zaehlpunkt_nr}: {note}")
                rows = []
                for m in months:
                    q = quality_by_month.get((zaehlpunkt_nr, m["month"]), {})
                    rows.append({
                        "time": m["time"],
                        "teilnahme_von": m["teilnahme_von"],
                        "kwh_erzeugung": m["kwh"] if meta["type_hint"] == "producer" else None,
                        "kwh_teilnahme": m["kwh"] if meta["type_hint"] == "consumer" else None,
                        "kwh_ueberschuss": m.get("kwh_ueberschuss"),
                        "kwh_restueberschuss": m.get("kwh_restueberschuss"),
                        "quality": q.get("quality"),
                        "completeness": q.get("completeness"),
                    })
            result.append(MeteringPointData(
                zaehlpunkt_nr=zaehlpunkt_nr,
                meter_code="",  # EDA-Report enthält keine Zählernummer, nur die Zählpunktnummer
                type_hint=meta["type_hint"],
                completeness=meta["completeness"],
                quality=meta["quality"],
                timeseries=pd.DataFrame(rows),
            ))
        for n in split_notes:
            log.warning("Monatsaufteilung: %s", n)
        return LoadResult(
            metering_points=result, period_from=period_from, period_to=period_to,
            monthly_quality=monthly_quality,
        )

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
            zeitraum_teile = zeitraum.split("-")
            von_str = zeitraum_teile[0].strip()
            bis_str = zeitraum_teile[-1].strip()
            ts = pd.to_datetime(bis_str, dayfirst=True, errors="coerce")
            if pd.isna(ts):
                log.warning("Zählpunkt %s: Zeitraum '%s' nicht lesbar, überspringe.", zp, zeitraum)
                continue
            ts = ts.tz_localize("Europe/Vienna", ambiguous="NaT", nonexistent="NaT")
            # "von" wird NICHT als Zeitstempel (tz-aware) gespeichert, sondern als reines Datum --
            # genau die Zeitzonen-Falle vermeiden, die z.B. bei eda_imports.period_from/period_to
            # (siehe Billing::missingMonths()) schon einmal zu einem Tag-Versatz geführt hat.
            # Für den vollen Monat (Normalfall, kein unterjähriger Beitritt) ist "von" der
            # Monatserste und für die Anzeige ohnehin irrelevant (siehe teilnahme_ab in
            # Billing::generateDrafts() -- nur Werte NACH Zeitraumbeginn werden angezeigt).
            von_ts = pd.to_datetime(von_str, dayfirst=True, errors="coerce")
            teilnahme_von = von_ts.date() if not pd.isna(von_ts) else None

            kwh_wert = row[col_erzeugung] if type_hint == "producer" else row[col_verbrauch]
            kwh_wert = float(kwh_wert) if not pd.isna(kwh_wert) else 0.0

            status = str(row[col_status]).strip().lower()
            completeness = "COMPLETE" if status == "vollständig" else "INCOMPLETE"

            result[zp] = {
                "type_hint": type_hint,
                "time": ts,
                "teilnahme_von": teilnahme_von,
                "kwh_wert": kwh_wert,
                "completeness": completeness,
                "quality": self._worst_quality(row[col_qualitaet]),
            }
        return result

    def _parse_detailuebersicht(self, xl: pd.ExcelFile, sheet_name: str) -> tuple[dict, list[dict]]:
        """Liefert (a) die Ergänzungswerte kwh_ueberschuss/kwh_restueberschuss je Zählpunkt (aus
        den Detailspalten "Gesamt/Überschusserzeugung..." und "Restüberschuss...") -- die
        abrechnungsrelevanten Hauptwerte kommen bewusst aus der Gesamtübersicht (siehe
        _parse_gesamtuebersicht), nicht von hier, das erspart, "Eigendeckung"/"Restüberschuss"
        selbst nachzurechnen, was EDA bereits fertig geliefert hat; UND (b) eine monatsgenaue
        Datenqualitäts-Liste je Zählpunkt (siehe LoadResult.monthly_quality) -- die
        "Detailübersicht" hat IMMER eine eigene Zeile je Kalendermonat (Spalten Jahr/Monat),
        selbst wenn die Datei einen längeren, selbst gewählten Zeitraum (z.B. ein ganzes Quartal)
        abdeckt, anders als die Gesamtübersicht (dort nur EINE Zeile für den kompletten
        Zeitraum). Patrick, 01.10.2026: wollte sehen können, welcher MONAT konkret für ein L3
        verantwortlich ist, statt nur eine für den ganzen Zeitraum abgewertete Gesamtzahl."""
        raw = pd.read_excel(xl, sheet_name=sheet_name, header=None)
        header_row = self._find_header_row(raw)
        df = pd.read_excel(xl, sheet_name=sheet_name, header=header_row)

        col_zp = self._find_col(df.columns, ["Zählpunktnummer"])
        col_ueberschuss = self._find_col(df.columns, ["Gesamt/Überschusserzeugung", "Überschusserzeugung"])
        col_rest = self._find_col(df.columns, ["Restüberschuss"])
        col_jahr = self._find_col(df.columns, ["Jahr"])
        col_monat = self._find_col(df.columns, ["Monat"])
        col_uebermittlung = self._find_col(df.columns, ["Datenübermittlung"])
        col_qualitaet = self._find_col(df.columns, ["Datenqualität"])
        if not col_zp:
            return {}, [], {}

        # Alle Zahlenspalten je Zählpunkt und Monat (für split_into_months()) -- bewusst ALLE,
        # nicht eine fest angenommene Spalte: welche davon zur abrechnungsrelevanten Menge der
        # Gesamtübersicht passt, wird dort anhand der Summe geprüft.
        skip_cols = {c for c in (col_zp, col_jahr, col_monat) if c}
        monthly_values: dict = {}
        month_rows_seen: set = set()

        result = {}
        monthly_quality = []
        for _, row in df.iterrows():
            zp = row[col_zp]
            # Filtert automatisch die Beschreibungs-/Markerzeilen ("Summe der Energiedaten",
            # "Energiedaten je Zählpunkt") UND die Community-Gesamtzeile heraus -- deren
            # Zählpunktnummer-Zelle ist leer.
            if pd.isna(zp) or not str(zp).strip().upper().startswith("AT"):
                continue
            zp = str(zp).strip()
            ueb = float(row[col_ueberschuss]) if col_ueberschuss and not pd.isna(row[col_ueberschuss]) else None
            rest = float(row[col_rest]) if col_rest and not pd.isna(row[col_rest]) else None
            has_month = bool(col_jahr and col_monat and not pd.isna(row[col_jahr]) and not pd.isna(row[col_monat]))
            if has_month:
                # Mehrere Monatszeilen (mehrmonatige Datei): Zusatzwerte über den Zeitraum
                # summieren -- vorher gewann stillschweigend die LETZTE Monatszeile.
                prev = result.get(zp) if zp in month_rows_seen else None
                result[zp] = {
                    "kwh_ueberschuss": _add_opt(prev["kwh_ueberschuss"] if prev else None, ueb),
                    "kwh_restueberschuss": _add_opt(prev["kwh_restueberschuss"] if prev else None, rest),
                }
                month_rows_seen.add(zp)
                month_key = f"{int(row[col_jahr]):04d}-{int(row[col_monat]):02d}-01"
                values = {}
                for c in df.columns:
                    if c in skip_cols:
                        continue
                    v = pd.to_numeric(row[c], errors="coerce")
                    if not pd.isna(v):
                        values[str(c)] = float(v)
                values["__ueberschuss"] = ueb
                values["__restueberschuss"] = rest
                monthly_values.setdefault(zp, {})[month_key] = values
            elif zp not in month_rows_seen:
                result[zp] = {"kwh_ueberschuss": ueb, "kwh_restueberschuss": rest}

            if col_jahr and col_monat and col_qualitaet and not pd.isna(row[col_jahr]) and not pd.isna(row[col_monat]):
                jahr = int(row[col_jahr])
                monat = int(row[col_monat])
                status = str(row[col_uebermittlung]).strip().lower() if col_uebermittlung else ""
                monthly_quality.append({
                    "zaehlpunkt_nr": zp,
                    "month": f"{jahr:04d}-{monat:02d}-01",
                    "quality": self._worst_quality(row[col_qualitaet]),
                    "completeness": "COMPLETE" if status == "vollständig" else "INCOMPLETE",
                })
        return result, monthly_quality, monthly_values


def _add_opt(a, b):
    """Summe zweier optionaler Werte (None = kein Wert)."""
    if a is None:
        return b
    if b is None:
        return a
    return a + b


# Spalten der Detailübersicht, die am ehesten der abrechnungsrelevanten Menge der
# Gesamtübersicht entsprechen -- nur eine Vorauswahl/Reihenfolge, entschieden wird anhand der
# Summe (siehe split_into_months()).
_MONTH_COL_HINTS = {
    "consumer": ["eigendeckung gemeinschaftliche", "eigendeckung gem", "anteil gemeinschaftliche",
                 "anteil gem", "abrechnungsrelevant"],
    "producer": ["erzeugung lt. messung entsprechend", "abrechnungsrelevant",
                 "gesamte gemeinschaftliche erzeugung", "gesamt/überschuss", "überschusserzeugung"],
}


def split_into_months(total, month_values: dict, type_hint: str, period_from, period_to, teilnahme_von=None):
    """Teilt den Gesamtwert eines Zählpunkts aus einer MEHRMONATIGEN Datei auf die einzelnen
    Kalendermonate auf. Liefert None, wenn nichts aufzuteilen ist (Datei mit nur einem Monat,
    keine Monatszeilen), sonst (monate, hinweis) mit je Monat
    {"month": "JJJJ-MM-01", "time": Monatsletzter (bzw. Periodenende), "teilnahme_von", "kwh",
     "kwh_ueberschuss", "kwh_restueberschuss"}.

    Welche Spalte der Detailübersicht die Monatswerte liefert, wird NICHT geraten: zuerst wird
    eine Spalte gesucht, deren Monatswerte in Summe exakt den Gesamtwert ergeben (dann werden sie
    1:1 übernommen). Gibt es keine, wird der Gesamtwert im Verhältnis der naheliegendsten Spalte
    aufgeteilt (hinweis gesetzt). Die Summe der Monate ist in jedem Fall exakt der Gesamtwert --
    Rechnungen über den ganzen Zeitraum ändern sich dadurch nicht."""
    tz = "Europe/Vienna"
    pf = pd.Timestamp(period_from).tz_localize(None) if pd.Timestamp(period_from).tzinfo else pd.Timestamp(period_from)
    pt = pd.Timestamp(period_to).tz_localize(None) if pd.Timestamp(period_to).tzinfo else pd.Timestamp(period_to)
    if (pf.year, pf.month) == (pt.year, pt.month):
        return None

    start_date = teilnahme_von if teilnahme_von is not None else pf.date()
    months = []
    for key in sorted(month_values):
        m_start = pd.Timestamp(key)
        m_end = (m_start + pd.offsets.MonthEnd(0)).normalize()
        if m_start > pt or m_end < pf.normalize():
            continue
        end = min(m_end, pt.normalize())
        if end.date() < start_date:
            continue  # Monat vor Teilnahmebeginn -- da kann nichts verrechnet worden sein
        months.append((key, m_start, end))
    if len(months) < 2:
        return None

    total = float(total or 0.0)
    all_cols = []
    for key, _, _ in months:
        for c in month_values[key]:
            if not c.startswith("__") and c not in all_cols:
                all_cols.append(c)
    hints = _MONTH_COL_HINTS.get(type_hint, [])
    preferred = [c for h in hints for c in all_cols if h in c.lower()]
    ordered = list(dict.fromkeys(preferred + all_cols))

    def col_values(c):
        return [float(month_values[k].get(c) or 0.0) for k, _, _ in months]

    tolerance = max(0.05, abs(total) * 0.002)
    chosen, exact = None, False
    if abs(total) < 1e-9:
        weights = [0.0] * len(months)
        exact = True
    else:
        for c in ordered:
            vals = col_values(c)
            if abs(sum(vals) - total) <= tolerance and all(v >= 0 for v in vals):
                chosen, exact, weights = c, True, vals
                break
        if chosen is None:
            for c in preferred:
                vals = col_values(c)
                if sum(vals) > 0 and all(v >= 0 for v in vals):
                    chosen, weights = c, vals
                    break
        if chosen is None:
            return None

    note = None
    if exact:
        amounts = [round(v, 4) for v in weights]
    else:
        s = sum(weights)
        amounts = [round(total * v / s, 4) for v in weights]
        note = (f"Monatswerte im Verhältnis der Spalte „{chosen}“ aufgeteilt "
                "(keine Spalte ergab exakt den Gesamtwert)")
    # Rundungsrest in den letzten Monat, damit die Summe exakt dem Gesamtwert entspricht
    amounts[-1] = round(amounts[-1] + (round(total, 4) - round(sum(amounts), 4)), 4)

    out = []
    for (key, m_start, end), kwh in zip(months, amounts):
        von = max(m_start.date(), start_date)
        out.append({
            "month": key,
            "time": pd.Timestamp(end).tz_localize(tz),
            "teilnahme_von": von,
            "kwh": kwh,
            "kwh_ueberschuss": month_values[key].get("__ueberschuss"),
            "kwh_restueberschuss": month_values[key].get("__restueberschuss"),
        })
    return out, note


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
              AND period_from <= (%s::timestamptz AT TIME ZONE 'Europe/Vienna')::date
              AND period_to >= (%s::timestamptz AT TIME ZONE 'Europe/Vienna')::date
            LIMIT 1
            """,
            # Als Kalendertag in Wiener Zeit vergleichen: period_from/period_to der Datei sind
            # tz-aware (00:00 Wien = 22:00/23:00 UTC am VORTAG), die des Abrechnungslaufs reine
            # DATE-Werte -- der direkte Vergleich lief in einer UTC-Sitzung um diesen Versatz
            # daneben, die Sperre griff dadurch nie (gefunden 14.11.2026).
            (community_id, str(period_from), str(period_to))
        )
        return cur.fetchone() is not None


def _row_text(row, key):
    """Optionaler Text-Wert einer Zeitreihen-Zeile (fehlende Spalte/NaN -> None)."""
    v = row.get(key) if hasattr(row, "get") else None
    if v is None or (isinstance(v, float) and pd.isna(v)):
        return None
    return str(v)


def _insert_measurements(cur, community_id, mp_id, mp_data: MeteringPointData) -> int:
    """Schreibt die Zeitreihe eines Zählpunkts (eine Zeile je Monat) nach eda_measurements."""
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
            _row_text(row, "quality") or mp_data.quality,
            _row_text(row, "completeness") or mp_data.completeness,
            row.get("teilnahme_von"),
        )
        for _, row in mp_data.timeseries.iterrows()
        if not pd.isna(row["time"])
    ]
    rows = [tuple(None if (isinstance(v, float) and pd.isna(v)) else v for v in r) for r in rows]
    psycopg2.extras.execute_values(
        cur,
        """
        INSERT INTO eda_measurements
            (time, community_id, metering_point_id, meter_code,
             kwh_erzeugung, kwh_teilnahme, kwh_ueberschuss, kwh_restueberschuss,
             quality, completeness, teilnahme_von)
        VALUES %s
        ON CONFLICT DO NOTHING
        """,
        rows,
    )
    return len(rows)


def resplit_existing(conn, uploads_dir: str, apply: bool) -> list[dict]:
    """Einmalige Reparatur für schon importierte MEHRMONATIGE Dateien (z.B. Q3 2026 in einer
    Datei), deren Gesamtwert noch als EIN Datensatz am Periodenende liegt: liest die beim Import
    gespeicherte Originaldatei (storage/uploads/<eda_imports.filename>) erneut und ersetzt den
    einen Datensatz durch Monatswerte -- aber NUR, wenn die Summe exakt gleich bleibt. Damit
    ändern sich bereits verschickte Rechnungen über den ganzen Zeitraum nicht; deshalb ist das
    (anders als ein normaler erneuter Import) auch für schon abgerechnete Zeiträume erlaubt.
    Ohne apply=True wird nur angezeigt, was passieren würde."""
    report = []
    with conn.cursor(cursor_factory=psycopg2.extras.RealDictCursor) as cur:
        cur.execute(
            """
            SELECT i.id, i.community_id, i.filename, i.period_from, i.period_to, c.name AS eeg,
                   (i.period_from AT TIME ZONE 'Europe/Vienna')::date AS von,
                   (i.period_to AT TIME ZONE 'Europe/Vienna')::date AS bis
            FROM eda_imports i JOIN communities c ON c.id = i.community_id
            WHERE date_trunc('month', i.period_from AT TIME ZONE 'Europe/Vienna')
               <> date_trunc('month', i.period_to AT TIME ZONE 'Europe/Vienna')
            ORDER BY i.imported_at
            """
        )
        imports = cur.fetchall()

    for imp in imports:
        community_id = str(imp["community_id"])
        entry = {"import": imp["filename"], "eeg": imp["eeg"],
                 "zeitraum": f"{imp['von']:%d.%m.%Y}–{imp['bis']:%d.%m.%Y}",
                 "aufgeteilt": 0, "uebersprungen": []}
        report.append(entry)
        path = os.path.join(uploads_dir, os.path.basename(imp["filename"]))
        if not os.path.isfile(path):
            entry["fehler"] = f"Originaldatei nicht gefunden: {path}"
            continue
        try:
            loaded = XlsxDataSource().load(path, community_id)
        except Exception as e:  # noqa: BLE001 -- Bericht statt Abbruch für die übrigen Dateien
            entry["fehler"] = f"Datei nicht lesbar: {e}"
            continue

        with conn.cursor() as cur:
            cur.execute("SELECT zaehlpunkt_nr, id FROM metering_points WHERE community_id = %s", (community_id,))
            mp_ids = {r[0]: str(r[1]) for r in cur.fetchall()}
            for mp in loaded.metering_points:
                if len(mp.timeseries) < 2 or mp.zaehlpunkt_nr not in mp_ids:
                    continue
                mp_id = mp_ids[mp.zaehlpunkt_nr]
                col = "kwh_erzeugung" if mp.type_hint == "producer" else "kwh_teilnahme"
                cur.execute(
                    f"""
                    SELECT COUNT(*), COALESCE(SUM({col}), 0) FROM eda_measurements
                    WHERE community_id = %s AND metering_point_id = %s AND time >= %s AND time <= %s
                    """,
                    (community_id, mp_id, imp["period_from"], imp["period_to"]),
                )
                count, old_sum = cur.fetchone()
                new_sum = float(mp.timeseries[col].fillna(0).sum())
                if count != 1:
                    entry["uebersprungen"].append(
                        f"{mp.zaehlpunkt_nr}: {'schon monatsweise vorhanden' if count > 1 else 'keine Daten in der Datenbank'}")
                    continue
                if abs(float(old_sum) - new_sum) > 0.01:
                    entry["uebersprungen"].append(
                        f"{mp.zaehlpunkt_nr}: Wert in der Datenbank ({float(old_sum):.2f} kWh) weicht von der Datei ab ({new_sum:.2f} kWh)")
                    continue
                if apply:
                    cur.execute(
                        """
                        DELETE FROM eda_measurements
                        WHERE community_id = %s AND metering_point_id = %s AND time >= %s AND time <= %s
                        """,
                        (community_id, mp_id, imp["period_from"], imp["period_to"]),
                    )
                    _insert_measurements(cur, community_id, mp_id, mp)
                entry["aufgeteilt"] += 1
        if apply:
            conn.commit()
    return report


def import_to_db(
    conn,
    community_id: str,
    data: list[MeteringPointData],
    filename: str,
    user_id: str | None,
    file_period_from,
    file_period_to,
    monthly_quality: list[dict] | None = None,
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

        # Bewusst zusätzlich auf m.member_since/member_until eingegrenzt (Patrick, 26.09.2026:
        # "schau auch immer, ob die Mitglieder in diesem Zeitraum schon dabei waren" -- vorher
        # zählte hier ausschließlich der HEUTIGE aktiv-Status des Zählpunkts, unabhängig vom
        # importierten Zeitraum. Ein Mitglied, das z.B. erst im September beigetreten ist,
        # erschien beim Import einer JULI-Datei fälschlich als "fehlender Zählpunkt" -- der
        # Zählpunkt ist ja heute aktiv, konnte im Juli-Export aber unmöglich auftauchen. Gleiche
        # Grenze wie in Billing::generateDrafts() (member_since <= period_to), zusätzlich
        # member_until berücksichtigt für zwischenzeitlich ausgetretene Mitglieder. Auf reine
        # Kalendertage (.date()) statt tz-aware Timestamps verglichen, um dieselbe
        # Zeitzonen-Fallgrube wie bei Billing::missingMonths() von vornherein zu vermeiden
        # (member_since/member_until sind reine DATE-Spalten).
        period_from_date = file_period_from.date() if hasattr(file_period_from, "date") else file_period_from
        period_to_date = file_period_to.date() if hasattr(file_period_to, "date") else file_period_to
        cur.execute(
            """
            SELECT mp.zaehlpunkt_nr
            FROM metering_points mp
            JOIN members m ON m.id = mp.member_id
            WHERE mp.community_id = %s AND mp.active = true
              AND m.is_demo = false
              AND m.member_since <= %s
              AND (m.member_until IS NULL OR m.member_until >= %s)
            """,
            (community_id, period_to_date, period_from_date)
        )
        aktiv = {row["zaehlpunkt_nr"] for row in cur.fetchall()}

    zp_in_xlsx = {d.zaehlpunkt_nr for d in data}

    # Fehlende Zählpunkte: bei uns AKTIV (und schon Mitglied während dieses Zeitraums), aber
    # nicht im Export enthalten.
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

    # Monatsgenaue Datenqualität (siehe _parse_detailuebersicht()) je Zählpunkt speichern --
    # UPSERT, damit ein erneuter Import (egal ob derselbe oder ein überlappender Zeitraum) den
    # zuletzt bekannten Stand je Monat einfach aktualisiert, ohne Duplikate oder einen eigenen
    # Lösch-Schritt zu brauchen (anders als bei eda_measurements, UNIQUE(community_id,
    # metering_point_id, month) reicht hier als Schutz).
    if monthly_quality:
        with conn.cursor() as cur:
            for mq in monthly_quality:
                mp_id = registered.get(mq["zaehlpunkt_nr"])
                if not mp_id:
                    continue
                cur.execute(
                    """
                    INSERT INTO eda_measurement_quality_monthly
                        (community_id, metering_point_id, month, quality, completeness, updated_at)
                    VALUES (%s, %s, %s, %s, %s, now())
                    ON CONFLICT (community_id, metering_point_id, month)
                    DO UPDATE SET quality = EXCLUDED.quality, completeness = EXCLUDED.completeness,
                                  updated_at = now()
                    """,
                    (community_id, mp_id, mq["month"], mq["quality"], mq["completeness"])
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
            inserted = _insert_measurements(cur, community_id, mp_id, mp_data)
            total_records += inserted
            log.info("Zählpunkt %s: %d Datensatz/Datensätze importiert (%s, %s)", zp, inserted, mp_data.quality, mp_data.completeness)

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
    parser.add_argument("--file", help="Pfad zur XLSX-Datei")
    parser.add_argument("--community", help="Community-Slug")
    parser.add_argument("--user-id", help="UUID des importierenden Users")
    parser.add_argument("--resplit", action="store_true",
                        help="Schon importierte mehrmonatige Dateien nachträglich auf Monate aufteilen "
                             "(ohne --apply nur Vorschau)")
    parser.add_argument("--apply", action="store_true", help="Mit --resplit: Änderungen wirklich schreiben")
    parser.add_argument("--uploads-dir", default="/var/www/html/storage/uploads",
                        help="Mit --resplit: Ordner der gespeicherten Originaldateien")
    args = parser.parse_args()

    conn = psycopg2.connect(DB_DSN)

    if args.resplit:
        try:
            report = resplit_existing(conn, args.uploads_dir, args.apply)
            print(json.dumps({"apply": args.apply, "imports": report}, indent=2, ensure_ascii=False, default=str))
        finally:
            conn.close()
        return
    if not args.file or not args.community:
        parser.error("--file und --community sind erforderlich (oder --resplit)")

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
            loaded.period_from, loaded.period_to, loaded.monthly_quality,
        )
        print(json.dumps(result, indent=2, ensure_ascii=False))

    finally:
        conn.close()


if __name__ == "__main__":
    main()
