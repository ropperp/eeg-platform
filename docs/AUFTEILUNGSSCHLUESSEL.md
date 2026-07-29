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
liefert das Ergebnis über das **EDA-Anwenderportal** als Monatsexport (XLSX) — dieselbe Datei,
die unter „EDA-Daten importieren" (`/portal/eda/upload`) hochgeladen wird. Für jeden Zählpunkt
enthält diese Datei bereits die fertig aufgeteilten Viertelstundenwerte:

| EDA-Spalte             | Bedeutung                                                          |
|-------------------------|---------------------------------------------------------------------|
| `kwh_erzeugung`         | Erzeugung des Zählpunkts (Einspeiser)                               |
| `kwh_teilnahme`         | **Bereits zugeteilte** Menge — das, was laut Aufteilungsschlüssel auf diesen Zählpunkt entfällt |
| `kwh_ueberschuss`       | Überschuss, der ins öffentliche Netz eingespeist wurde              |
| `kwh_restueberschuss`   | Verbleibender Überschuss nach Verrechnung                           |

`eda-parser/parser.py` importiert diese Spalten unverändert in `eda_measurements`.
`Billing::generateDrafts()` (`webapp/src/Billing.php`) summiert direkt `kwh_teilnahme` (→
„Bezug aus der Gemeinschaft", wird dem Mitglied verrechnet) und `kwh_erzeugung` (→
Einspeisevergütung) über den Abrechnungszeitraum. **Die Plattform übernimmt also nur bereits
fertig aufgeteilte Werte — sie entscheidet nicht, welches Modell gilt oder wie viel Prozent
wer bekommt.**

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
