# EDA-Datenqualität & Abrechnungs-Freigabe

Diese Notiz erklärt, **wann** eine Quartalsabrechnung freigegeben werden darf und **warum** wir
das nicht mehr an einer starren 60-Tage-Frist festmachen, sondern an der Datenqualität der
EDA-Messwerte. Grundlage sind die offiziellen „Erklärungen zum Monatsreport" der EDA
(Energie-Daten-Austausch) sowie der Monatsbericht des Abrechnungs-Tools (Eder-XLSX).

## Wertekategorien L1 / L2 / L3

Jeder Viertelstundenwert, den Kärnten Netz über die EDA an die Plattform liefert, trägt eine
**Wertekategorie**, die aussagt, wie belastbar der Wert ist:

| Kategorie | Bedeutung | Für die Abrechnung? |
|-----------|-----------|---------------------|
| **L1** | Echtwert, tatsächlich gemessen | ✅ ja – bester Wert |
| **L2** | Ersatzwert, **belastbar** (rechnerisch ermittelt, ändert sich mit hoher Wahrscheinlichkeit nicht mehr) | ✅ ja |
| **L3** | Ersatzwert, **nicht belastbar** (grobe Schätzung, ändert sich sehr wahrscheinlich noch) | ❌ **nein** – laut EDA ausdrücklich **nicht** abzurechnen |

> **Wichtige Klarstellung:** L3 ist die **schlechteste**, noch veränderliche Qualität – nicht die
> beste. Ein Zeitraum ist erst dann abrechenbar, wenn **keine L3-Werte mehr** enthalten sind
> (alle Werte L1 oder L2). „Warten, bis sich bei L3 nichts mehr ändert" heißt praktisch: warten,
> bis die L3-Werte durch L1/L2 ersetzt wurden.

Im „Status Datenübermittlung" des Monatsberichts steht ein Zeitraum auf **„Unvollständig"**,
solange Viertelstundenwerte fehlen **oder** noch L3-Ersatzwerte enthalten sind – sonst
„Vollständig".

## Wie die Plattform das umsetzt

**1. Nur belastbare Werte werden abgerechnet.**
`Billing::generateDrafts()` summiert die EDA-Energiemengen nur über Werte der Qualität
**L1/L2** (`Billing::ABRECHNUNGS_QUALITY`); L3-Werte fließen nicht in Bezug/Einspeisung ein.

> Historische Anmerkung: Früher filterte der Code auf `quality IN ('L2','L3')` – das schloss die
> **besten** Werte (L1) aus und rechnete die **unzuverlässigen** (L3) mit. Seit
> `migrate_20260805` / diesem Commit ist der Filter auf `('L1','L2')` korrigiert.

**2. Freigabe hängt an der Datenqualität, nicht am Kalender.**
`Billing::finalize()` prüft über `Billing::datenqualitaetProblem()` zwei Dinge:

- Der aus dem EDA-Monatsbericht übernommene Gesamtstatus des Laufs
  (`billing_runs.eda_status`) darf **nicht** `unvollstaendig` sein.
  Werte: `unbekannt` (Ausgangswert, blockiert nicht) · `vollstaendig` · `unvollstaendig`.
  Gesetzt wird er in der Abrechnungsübersicht (Spalte „EDA-Datenqualität") anhand des
  Monatsberichts.
- Im Abrechnungszeitraum dürfen **keine L3-Werte** mehr in `eda_measurements` liegen (automatische
  Prüfung, unabhängig vom manuell gesetzten Status).

Ist beides erfüllt, kann sofort freigegeben werden – auch deutlich früher als nach 60 Tagen.
Umgekehrt bleibt die Freigabe gesperrt, solange die Daten nicht belastbar sind, selbst wenn 60
Tage längst vorbei wären.

**3. Die alte 60-Tage-Frist** ist damit kein Sperr-Kriterium mehr. Die Spalte
`billing_runs.freigabe_nach` wird weiter befüllt, dient aber nur noch als grober informativer
Richtwert.

## Quelle: EDA-Monatsbericht (Energiedatenreport)

Der Monatsbericht enthält die Sheets „Gesamtübersicht" und „Detailübersicht" mit den
relevanten OBIS-Kennzahlen, z. B.:

| Größe | OBIS |
|-------|------|
| Gesamtverbrauch | 1-1.9.0 G.01 |
| Verbrauch lt. Messung | 1-1.9.0 G.01T |
| Anteil gemeinschaftliche Erzeugung | 1-1.2.9.0 G.02 |

**Seit 05.08.2026 automatisch:** `eda-parser/parser.py` liest diese Datei direkt beim Import
unter „EDA-Daten importieren" (`/portal/eda/upload`) ein (bestätigtes reales Format anhand einer
echten Exportdatei -- vorher war hier eine geratene, nie getestete Sheet-/Spaltenstruktur
hinterlegt, die nicht zur echten Datei passte). Pro Zählpunkt übernimmt der Import direkt die
Spalte „Status Datenübermittlung" (Vollständig/Unvollständig → `eda_measurements.completeness`)
und „Datenqualität" (L1/L2/L3, ggf. kommagetrennt → schlechtester enthaltener Wert wird
gespeichert). Der **Gesamtstatus eines Abrechnungslaufs** (`billing_runs.eda_status`) wird
weiterhin manuell in der Abrechnungsübersicht gesetzt (eine Datei deckt nur einen Monat ab, ein
Lauf aber ein ganzes Quartal aus drei Monatsdateien -- der automatische L3-Check in
`Billing::datenqualitaetProblem()` sperrt die Freigabe aber ohnehin unabhängig davon, sobald
irgendwo im Zeitraum noch L3-Werte liegen).
