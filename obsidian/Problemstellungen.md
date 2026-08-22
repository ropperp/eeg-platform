# Problemstellungen & offene Punkte

Sammlung organisatorischer/rechtlicher Problemstellungen rund um die EEG(s), die (noch) keine
Code-Änderung auslösen, aber als Kontext/Entscheidungsgrundlage festgehalten werden sollen --
Ergänzung zu `Infrastruktur.md` (dort geht es um Technik/Deployment) und
`Claude-Sitzungslog.md` (dort um konkrete Programmier-Aufträge). Neue Punkte hier ergänzen,
nicht löschen -- erledigte/entschiedene Punkte als solche markieren statt zu entfernen, damit
die Historie nachvollziehbar bleibt.

---

## 2026-08 — Kärnten Netz hat die Regionsgrenzen der Schaltanlagen neu gezogen

**Stand (Patrick, 22.–23.08.2026, aus einer Rundmail an ca. 16–17 Mitglieder/Interessenten
sowie einem Nachtrag im Chat):**

### Was ist passiert

Kärnten Netz hat innerhalb weniger Tage die Zuordnung ihrer Schaltanlagen zu den
"Regionalen IDs" (die Kennung, die eine regionale Energiegemeinschaft nach § 16c ElWOG 2010
einem bestimmten Netzgebiet zuordnet) neu gezogen -- betrifft laut Patrick nicht nur den
Bezirk Feldkirchen, sondern offenbar größere Teile des Netzgebiets. Konkretes Beispiel:
**Bodensdorf** (Wohnort von Patricks Mutter) gehörte vorher zu einem anderen Bereich und ist
jetzt auf einmal **Landskron/Villach** zugeordnet -- die Änderung ist also nicht auf
Feldkirchen beschränkt, sondern eine generelle Neuordnung durch Kärnten Netz.

Für die eigene EEG konkret: **Regionale ID 23R1** bezeichnete VORHER den **südwestlichen**
Teil von Feldkirchen (dort sitzt der bisherige Mitgliederstamm/"Altbestand"). Nach der
Neuordnung bezeichnet **dieselbe ID 23R1 jetzt den nordöstlichen** Teil von Feldkirchen --
Kärnten Netz hat also nicht nur Grenzen verschoben, sondern die ID-Nummer selbst einem anderen
Gebiet neu zugewiesen. Eine Möglichkeit, als EEG das eigene Netzgebiet zu wechseln bzw. bei der
ursprünglichen Zuordnung zu bleiben, gibt es laut Patrick nicht.

**Neue Ortschaftsliste unter 23R1** (Stand laut Kärnten Netz, aus Patricks Rundmail):
Stadtgebiet Feldkirchen, St. Ruprecht Feldkirchen, Glan, Untere Glan, Mattersdorf, St. Martin,
Fasching, Kalitsch, Rottendorf, Förolach, Agsdorf-Gegend, Tschwarzen, Haiden, Rogg, St. Urban,
Feistritz, Poitschach, Wachsenberg, Trenk, Zojach, Edling, Edern, Köttern, Glabegg, Kerschdorf.
Prüfbar über <https://kaerntennetz.at/erneuerbare-energiegemeinschaften-eeg.htm> (Adresse
eingeben, die "Regionale ID" ablesen -- nur bei 23R1 ist ein Beitritt zu DIESER EEG technisch
möglich).

### Warum das ein Problem ist

Der Vereinsname in **allen** Verträgen mit Kärnten Netz, in den Statuten, beim Bankkonto usw.
lautet durchgehend **"Erneuerbare-Energie-Gemeinschaft Strompool Feldkirchen Süd-West"**.
Geografisch deckt die EEG jetzt aber den **nordöstlichen** Teil ab -- der Name passt also nicht
mehr zur tatsächlichen Lage. Patrick weiß selbst nicht (mehr) genau, was ursprünglich exakt mit
"Süd-West" gemeint war bzw. ob das den Mitgliedern überhaupt bewusst ist/war.

### Entscheidung (Patrick, 23.08.2026)

**Keine Namensänderung.** Weder der Vereinsname noch der Name des Bankkontos werden geändert
-- das wäre laut Patrick viel zu aufwändig (Statuten, ZVR-Eintrag, Bankkonto, sämtliche
bestehenden Verträge mit Kärnten Netz und Mitgliedern müssten angepasst werden). Der Name
**"Strompool Feldkirchen Süd-West" bleibt bestehen**, auch wenn er geografisch nicht mehr exakt
zutrifft. Bewusste, akzeptierte Inkonsistenz -- kein offener Punkt, der noch zu klären wäre.

### Für den bestehenden Mitgliederstamm ("Altbestand") ändert sich nichts

Laut Patricks Rundmail an die Mitglieder: alle bereits teilnehmenden Mitglieder bleiben
unabhängig von der neuen Netzregion als Altbestand in der EEG bestehen -- Daten, Strom und
Abrechnung laufen unverändert über die bestehende EEG weiter. Nur für **neue** Interessenten
ist die Regionale ID 23R1 (jetzt nordöstlicher Teil) das entscheidende Kriterium, ob ein
Beitritt zu dieser EEG überhaupt möglich ist.

### Auswirkung auf die Plattform (Stand: keine)

Nach Rückfrage (Claude, 03.09.2026) wollte Patrick das Thema zunächst nur zur Kenntnis bringen
und dokumentiert haben -- **keine konkrete Code-/Plattform-Änderung ausgelöst.** Geprüft und
für unauffällig befunden:
- `webapp/src/views/pages/legal_statuten.php` nennt weiterhin nur die Kennung "Regionale ID:
  23R1", ohne die Ortschaften konkret aufzuzählen -- bleibt also technisch korrekt, auch wenn
  sich das dahinterliegende Gebiet geändert hat. Keine Anpassung nötig.
- Der Vereinsname taucht an vielen Stellen im Code fix hinterlegt auf (Startseite, Fußzeile,
  E-Mail-Vorlagen, PDF-Vorlagen usw.) -- da laut Entscheidung oben keine Umbenennung ansteht,
  ebenfalls keine Anpassung nötig.
- Keine Ortschaftsliste auf der öffentlichen Website vorhanden, die jetzt veraltet wäre.

**Offen/für später denkbar** (nicht entschieden, nur als Idee im Gespräch, siehe
Sitzungslog-Eintrag desselben Tages): die neue Ortschaftsliste zusätzlich auf der
Beitritts-Seite veröffentlichen, damit Interessenten vorab selbst prüfen können, ob sie
überhaupt im aktuellen 23R1-Gebiet liegen, statt das erst nach dem Ausfüllen der
Beitrittserklärung zu erfahren. Patrick wollte hierzu zuerst nur den Hintergrund festhalten,
weitere Ideen zur Website folgen laut Ankündigung noch in dieser Sitzung.

**Status:** dokumentiert, keine offene Entscheidung -- Namensfrage bewusst geschlossen,
Ortschaftsliste-auf-Website nur eine lose Idee, noch nicht beauftragt.
