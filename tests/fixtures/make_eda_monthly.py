"""
Erzeugt einen SYNTHETISCHEN EDA-Energiedatenreport (Monatsexport) für Tests -- fiktive Zählpunkte,
keine echten Daten. Aufbau wie von eda-parser/parser.py erwartet (Blätter "Gesamtübersicht" und
"Detailübersicht", Kopfzeilen mit Auswertungszeitraum, Beschreibungszeile unter der Überschrift).

  python tests/fixtures/make_eda_monthly.py <ziel.xlsx> [JJJJ-MM]
"""
import calendar
import sys

from openpyxl import Workbook

ZAEHLPUNKTE = [  # (Zählpunkt, Energierichtung, kWh)
    ("AT0099900000000000000000000E2E001", "VERBRAUCH", 123.456),
    ("AT0099900000000000000000000E2E002", "ERZEUGUNG", 456.789),
]


def build(path: str, year: int, month: int) -> None:
    last = calendar.monthrange(year, month)[1]
    von, bis = f"01.{month:02d}.{year}", f"{last:02d}.{month:02d}.{year}"
    wb = Workbook()
    g = wb.active
    g.title = "Gesamtübersicht"
    g.append(["EC-ID", "TEST-E2E"])
    g.append(["Auswertungszeitraum von", von])
    g.append(["Auswertungszeitraum bis", bis])
    g.append([])
    g.append(["Zählpunktnummer", "Energierichtung", "Zeitraum", "Verbrauch, abrechnungsrelevante Energiemenge",
              "Erzeugung, abrechnungsrelevante Energiemenge", "Status Datenübermittlung", "Datenqualität"])
    g.append(["Bezeichnung so wie im EDA Portal"] + [""] * 6)
    for zp, richtung, kwh in ZAEHLPUNKTE:
        g.append([zp, richtung, f"{von} - {bis}", kwh if richtung == "VERBRAUCH" else 0,
                  kwh if richtung == "ERZEUGUNG" else 0, "Vollständig", "L1"])
    d = wb.create_sheet("Detailübersicht")
    d.append(["EC-ID", "TEST-E2E"])
    d.append([])
    d.append(["Zählpunktnummer", "Jahr", "Monat", "Datenübermittlung", "Datenqualität"])
    for zp, _, _ in ZAEHLPUNKTE:
        d.append([zp, year, month, "Vollständig", "L1"])
    wb.save(path)


if __name__ == "__main__":
    ym = sys.argv[2] if len(sys.argv) > 2 else "2026-01"
    build(sys.argv[1], int(ym[:4]), int(ym[5:7]))
