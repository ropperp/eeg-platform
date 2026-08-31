# Regulatorik & Netzbetreiber-Mitteilungen

Chronologische Sammlung wichtiger Mitteilungen von Netzbetreibern/Behörden, die die Plattform
oder die Pilot-EEG (Strompool Feldkirchen Süd-West) betreffen könnten. Neueste zuerst. Dient
Patrick als Nachschlagewerk und als Beleg für die Diplomarbeit-Dokumentation, welche externen
Rahmenbedingungen sich wann geändert haben.

---

## 31.08.2026 — KNG-Kärnten Netz: Neudefinition der Nahebereiche ab 05.10.2026

**Quelle:** Mail von `netzbetreiber@kaerntennetz.at`, 31.08.2026, 08:24 Uhr, an alle
REG-Betreiber:innen (Regionale Erneuerbare Energiegemeinschaften), von Patrick weitergeleitet.

**Was sich ändert:** Der "Nahebereich" (das Gebiet, aus dem Zählpunkte einer REG stammen
dürfen) wird durch eine ElWG-Novelle (Elektrizitätswirtschaftsgesetz) neu definiert. Bisher galt
ein einzelner Abgang eines Umspannwerks als Regionalbereich -- ab **05.10.2026** gilt stattdessen
das **gesamte Umspannwerk**. Dadurch werden mehrere bisher getrennte Regional-IDs zu einer
gemeinsamen zusammengeführt (Beispiel aus der Mail: aus "xxR2" wird künftig "xxR1").

**Was das ausdrücklich NICHT bedeutet:**
- **Keine automatische Zusammenlegung** bestehender Energiegemeinschaften -- der Netzbetreiber
  führt das laut eigener Aussage explizit NICHT durch und darf das auch nicht.
- **Keine neuen Verträge nötig** für bestehende Mitglieder -- der erweiterte Nahebereich gilt ab
  05.10.2026 kraft Gesetz als vereinbart, bestehende Verträge bleiben gültig (werden nur nach der
  neuen Definition ausgelegt).

**Praktische Bedeutung für "Strom für alle" / Strompool Feldkirchen Süd-West:**
- Der zulässige Nahebereich wird ab 05.10.2026 größer (ganzes Umspannwerk statt nur ein Abgang)
  -- dadurch könnten künftig **mehr Zählpunkte grundsätzlich teilnahmeberechtigt** sein als
  bisher, die vorher einer anderen Regional-ID zugeordnet waren.
- Das betrifft **nicht automatisch bestehende Mitglieder** -- es öffnet nur potenziell den Kreis
  möglicher NEUER Mitglieder/Zählpunkte.
- Eine echte Fusion mit einer bisher komplett getrennten, eigenständigen Energiegemeinschaft
  (falls das je gewünscht wäre) bliebe trotzdem aufwändig: alle betroffenen Zählpunkte müssten
  bei der alten EEG abgemeldet und bei der neuen als **Neuanmeldung** angemeldet werden -- inkl.
  erneuter Kundenzustimmung über das Netzbetreiberportal. Kein automatischer Vorgang.

**Code-/Plattform-Bezug:** keiner -- die Plattform verwaltet keine Regional-IDs, das ist reine
Netzbetreiber-/EDA-Seite (per `grep` im Repo bestätigt: kein Vorkommen von "Regional-ID"/
"Nahebereich" im Code). Kein Handlungsbedarf an der Software.

**Handlungsbedarf für Patrick:** Aktuell keiner. Relevant wird es erst, falls ab Oktober
geprüft werden soll, ob der erweiterte Nahebereich neue, bisher nicht teilnahmeberechtigte
Zählpunkte/potenzielle Mitglieder in Reichweite bringt -- das wäre dann ein ganz normaler
Neuanmeldungs-Vorgang wie jeder andere, keine Sonderbehandlung nötig.

Weiterführend laut Mail: [energiegemeinschaften.gv.at](https://energiegemeinschaften.gv.at) samt
FAQ.
