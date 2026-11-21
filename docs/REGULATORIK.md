# Regulatorik & Netzbetreiber-Mitteilungen

Chronologische Sammlung wichtiger Mitteilungen von Netzbetreibern/Behörden, die die Plattform
oder die Pilot-EEG (Strompool Feldkirchen Süd-West) betreffen könnten. Neueste zuerst. Dient
Patrick als Nachschlagewerk und als Beleg für die Diplomarbeit-Dokumentation, welche externen
Rahmenbedingungen sich wann geändert haben.

---

## 01.10.2026 — ElWG-Bürgerenergie in Kraft (§§ 65–72 ElWG)

**Quelle:** Elektrizitätswirtschaftsgesetz (ElWG), BGBl. I Nr. 91/2025, löst das ElWOG 2010 ab. Stand dieses
Eintrags: 21.11.2026. Die Paragraphen unten sind die für die Plattform relevanten; vor einer rechtlichen
Verwendung im Gesetzestext gegenprüfen.

**Paragraphen:**
- §§ 65–72 ElWG: Bürgerenergie (Energiegemeinschaften). Für Erneuerbare-Energie-Gemeinschaften gilt weiter
  § 79 Abs 1, 2 und 4 EAG (über § 66 Abs 1 ElWG).
- § 66: Energiegemeinschaften (ersetzt § 16c ElWOG).
- § 69: Pflichten als Lieferant (Lieferbedingungen, Informationsblatt, Rechnung, Rechnungsintervall).
- § 70 Abs 6 Z 4: „Regionalbereich“ (ersetzt „Nahebereich“; siehe auch Eintrag 31.08.2026).
- § 71: Messung, Datenbereitstellung und Zuordnung (ersetzt § 16e ElWOG).

**Übergang:** Bestehende EEG wurden automatisch übergeführt (§ 189 Abs 17 ElWG), bestehende Verträge bleiben
aufrecht (§ 189 Abs 13). Die registrierten Statuten der Pilot-EEG bleiben gültig; Verweise auf das ElWOG 2010
sind als Verweise auf das ElWG zu lesen. Die Statuten wurden deshalb nicht geändert, nur ein Hinweis darüber.

**Lieferantenpflichten (§ 69 ElWG):** Gelten, wenn ein Haushaltsmitglied mit Anlage > 30 kW oder die
Gemeinschaft bzw. sonstige Teilnehmer mit > 100 kW teilnehmen, oder wenn ein Organisator bestellt ist.
Inhalt: Allgemeine Lieferbedingungen und Informationsblatt vor Vertragsabschluss, Wahl monatlicher oder
jährlicher Rechnung (§ 69 Abs 1 Z 6, § 43 Abs 2), Rechnungsinhalt nach § 69 Abs 3 (Betrag, Fälligkeit, verwendete
Energiewerte deutlich abgesetzt, Name/Anschrift/Kontakt des Vertragspartners, Zählpunktbezeichnungen), Änderungen
der Bedingungen nur mit Mitteilung und frühestens einen Monat später.

**Mehrfachteilnahme:** Ein Zählpunkt/eine Person darf an bis zu 5 Gemeinschaften teilnehmen (§ 71 Abs 5).
Die Plattform lässt Mehrfachmitgliedschaft (Statuten: „andere EEG“) bereits zu; die Zahl 5 wird nicht geprüft.

**Idee für später:** Der Netzbetreiber muss Daten per Programmierschnittstelle bereitstellen (§ 71 Abs 1 Z 2).
Das könnte den EDA-Import per E-Mail/XLSX ablösen (eda-worker). Noch nicht umgesetzt, Netzbetreiber-Angebot abwarten.

**Umsetzung in der Plattform (Version 0.10.0):**
- Texte: „nicht auf Gewinn ausgerichteter Verein“ statt „gemeinnützig“ (Energiegemeinschaften sind laut BMF nicht
  gemeinnützig), Impressum, AGB-Haftung gegenüber Verbrauchern (§ 6 Abs 1 Z 9 KSchG), Datenschutz (Speicherdauern).
- Verträge: neue Fassung mit ElWG-Verweisen, unterschriebene Verträge bleiben in der Altfassung
  (`*_elwog2010.tex`). Verweise ohne von uns geprüfte ElWG-Nummer wurden allgemein formuliert („nach den Bestimmungen
  des ElWG“): Messung/Zuordnung § 71, Regionalbereich § 70 Abs 6 Z 4, Gemeinschaft § 66.
- Lieferantenpflichten: Schalter pro Gemeinschaft (Standard aus), siehe `docs/BETRIEBSHANDBUCH.md`.
- Nicht umgesetzt: Abrechnungsläufe je Mitglied nach Rechnungsintervall (der Wunsch wird gespeichert, die Läufe
  bleiben pro Zeitraum für alle).

**Handlungsbedarf für Patrick:** Impressum (Anschrift, Rechtsform) im Admin ergänzen; Pilot-EEG prüfen, ob die
Schwellen von § 69 erreicht sind (derzeit Schalter aus).

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
