# Aufteilungsschlüssel — wer berechnet ihn, und was macht die Plattform damit?

Kurze Klarstellung, die bei einer Prüfung/Diplomarbeits-Verteidigung mit hoher
Wahrscheinlichkeit gefragt wird: **Die Plattform berechnet den Aufteilungsschlüssel nicht
selbst.** Das ist kein fehlendes Feature, sondern entspricht der österreichischen
Rechtslage — die Berechnung ist gesetzlich Aufgabe des Netzbetreibers.

## Was ist der Aufteilungsschlüssel?

Erzeugt eine Erneuerbaren-Energie-Gemeinschaft (EEG) mehr oder weniger Strom als ihre
Mitglieder gerade verbrauchen, muss die überschüssige bzw. fehlende Menge auf die einzelnen
Mitglieder (Zählpunkte) aufgeteilt werden. Das EAG (Erneuerbaren-Ausbau-Gesetz) kennt dafür
zwei Modelle, die bei der Anmeldung der EEG beim Netzbetreiber je Zählpunkt festgelegt werden:

- **Statisch:** jedes Mitglied bekommt einen fix vereinbarten Prozentsatz der
  Gemeinschaftserzeugung zugewiesen (unabhängig vom tatsächlichen Verbrauch in diesem
  Zeitintervall).
- **Dynamisch:** die Zuteilung erfolgt pro Viertelstunde proportional zum tatsächlichen
  Verbrauch jedes Mitglieds in genau diesem Intervall — passt sich also laufend an.

## Wer berechnet das — und wo taucht das Ergebnis in dieser Plattform auf?

**Kärnten Netz** (der Verteilnetzbetreiber) wendet den bei ihm hinterlegten Schlüssel an und
liefert das Ergebnis über das **EDA-Anwenderportal** als monatlichen Energiedatenreport (XLSX,
Sheets „Gesamtübersicht"/„Detailübersicht") — dieselbe Datei, die unter „EDA-Daten importieren"
(`/portal/eda/upload`) hochgeladen wird. Eine Datei deckt immer genau einen Kalendermonat ab;
für einen Quartals-Abrechnungslauf werden die drei Monatsdateien des Quartals nacheinander
importiert (die Beträge summieren sich beim Abrechnen automatisch über den Zeitraum).

Für jeden Zählpunkt enthält das Sheet „Gesamtübersicht" pro Monat bereits die fertig
zugeteilte, abrechnungsrelevante Energiemenge — je nach „Energierichtung" (VERBRAUCH/ERZEUGUNG)
in einer der beiden Spalten:

| Reale EDA-Spalte (Gesamtübersicht)                    | Interne DB-Spalte (`eda_measurements`) | Bedeutung |
|--------------------------------------------------------|------------------------------------------|-----------|
| „Verbrauch, abrechnungsrelevante Energiemenge" (nur bei VERBRAUCH-Zeilen) | `kwh_teilnahme` | Bereits zugeteilte Bezugsmenge aus der Gemeinschaft — das, was laut Aufteilungsschlüssel auf diesen Zählpunkt entfällt und dem Mitglied verrechnet wird |
| „Erzeugung, abrechnungsrelevante Energiemenge" (nur bei ERZEUGUNG-Zeilen) | `kwh_erzeugung` | Von der Gemeinschaft tatsächlich verbrauchter Anteil der Erzeugung — Grundlage der Einspeisevergütung |

Zusätzlich liefert das Sheet „Detailübersicht" dieselben Zählpunkte mit den Einzelkomponenten
(Gesamtverbrauch, Eigendeckung, Restüberschuss usw.) — daraus werden nur noch
`kwh_ueberschuss`/`kwh_restueberschuss` ergänzend übernommen (rein informativ, siehe unten).

`eda-parser/parser.py` übernimmt die bereits fertig berechneten Werte unverändert in
`eda_measurements`. `Billing::generateDrafts()` (`webapp/src/Billing.php`) summiert direkt
`kwh_teilnahme` (→ „Bezug aus der Gemeinschaft", wird dem Mitglied verrechnet) und
`kwh_erzeugung` (→ Einspeisevergütung) über den Abrechnungszeitraum. `kwh_ueberschuss`/
`kwh_restueberschuss` fließen **nicht** in die Rechnungsberechnung ein (der Restüberschuss --
das ins öffentliche Netz eingespeiste bzw. vom Netz bezogene Residuum -- wird bereits außerhalb
der EEG-Plattform vom jeweiligen Netzbetreiber/Lieferanten verrechnet). **Die Plattform
übernimmt also nur bereits fertig aufgeteilte Werte — sie entscheidet nicht, welches Modell
gilt oder wie viel Prozent wer bekommt.**

## Warum keine eigene Berechnung in der Plattform?

1. **Rechtlich nicht unsere Aufgabe:** Der Netzbetreiber ist laut EAG für die korrekte
   Anwendung des bei ihm registrierten Schlüssels verantwortlich, nicht die EEG-Plattform.
2. **Datenbasis fehlt teilweise:** Für eine dynamische Berechnung bräuchte man
   Viertelstunden-Verbrauchswerte in Echtzeit von *allen* Zählpunkten gleichzeitig, bevor die
   amtlichen EDA-Daten überhaupt vorliegen — die Plattform hätte hier immer nur vorläufige,
   nicht bindende Zahlen.
3. **Einheitliche Quelle der Wahrheit:** Würde die Plattform selbst rechnen und der
   Netzbetreiber zu einem (auch nur geringfügig) anderen Ergebnis kommen, gäbe es zwei
   unterschiedliche „Wahrheiten" für dieselbe Abrechnung — das amtliche EDA-Ergebnis muss immer
   das verbindliche sein.

## Wo das gewählte Modell dokumentiert wird

Welches Modell (statisch/dynamisch) beim Netzbetreiber für eine EEG hinterlegt ist, wird nicht
aus den EDA-Zahlen ersichtlich — die Datei enthält nur das Ergebnis, nicht das Verfahren. Damit
das trotzdem irgendwo für die eigene EEG nachvollziehbar/dokumentiert ist (z.B. für eine
Prüfung oder einen Betreiberwechsel), gibt es in den EEG-Einstellungen
(`/portal/settings`) ein reines Info-Freitextfeld „Aufteilungsschlüssel (Info)"
(`communities.aufteilungsschluessel_info`, `migrate_20260818.sql`) — rein dokumentarisch, hat
keinen Einfluss auf die Abrechnung.

## Abgrenzung zur Live-Kennzahl im Mitglieder-Dashboard (seit 30.07.2026)

Seit die Ausleseeinheiten (ESP32) Leistungswerte in Echtzeit liefern, zeigt das
Mitglieder-Dashboard (`/portal/dashboard`) zusätzlich eine **selbst berechnete** Live-Schätzung
„Einspeisung in die Gemeinschaft" (`ownEinspeisungInGemeinschaftKwh()` in `public/index.php`) —
auf Wunsch von Patrick, der die Grundsatzentscheidung oben bewusst kennt und trotzdem eine
sofort sichtbare Kennzahl wollte, bevor der offizielle EDA-Import vorliegt. Das ist eine
bewusste Ergänzung, **keine Abkehr** vom Grundsatz oben:

- Sie ersetzt an keiner Stelle die EDA-Werte — „Bezug aus der Gemeinschaft"/„Eigene Erzeugung"
  auf derselben Seite bleiben unverändert EDA-basiert und sind weiterhin das, was tatsächlich
  in Rechnung gestellt wird (`Billing::generateDrafts()` liest ausschließlich `eda_measurements`).
- Sie ist im UI explizit als „(Live-Schätzung)" gekennzeichnet, mit Hinweistext, dass für die
  Rechnung der offizielle EDA-Import zählt — genau um die oben beschriebene „zwei Wahrheiten"-
  Verwechslung zu vermeiden.
- Die Methode (proportionale Aufteilung von `min(Gesamt-Bezug, Gesamt-Einspeisung)` je
  Viertelstunden-Fenster nach eigenem Erzeugungsanteil) entspricht zwar konzeptionell dem
  *dynamischen* EAG-Modell, ist aber eine eigene, vorläufige Näherung der Plattform aus
  ESP-Messwerten — nicht das beim Netzbetreiber tatsächlich hinterlegte, rechtsverbindliche
  Verfahren (das könnte auch statisch sein, oder bei „dynamisch" in Details abweichen).
