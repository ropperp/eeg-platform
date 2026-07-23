# ESB-Ideen & Backlog (Hardware/Firmware ↔ Plattform)

Gemeinsame Ideen-Sammlung für die Ausleseeinheit (ESB), die sowohl von diesem Chat
(Plattform-Seite: `eeg-platform`) als auch vom ESB-Code-Chat (Firmware/Hardware) gelesen
werden kann. Neue Ideen hier ergänzen, nicht löschen — erledigte Punkte als „Umgesetzt"
markieren statt zu entfernen, damit die Historie nachvollziehbar bleibt.

---

## Offen

### 1. WLAN-Diagnoseinfos vom ESB an die Plattform übermitteln
**Idee (Patrick, 18.07.2026):** Sobald die Hardwarelösung steht, soll der ESB beim
Verbinden mit dem WLAN folgende Infos an die Plattform schicken:
- verbundene **SSID**
- **WLAN-Passwort** (des Netzes, mit dem der ESB verbunden ist)
- **IP-Adresse** des ESB im Kundennetz

Zweck: Obmänner und Admins sollen das irgendwo einsehen können, um bei Problemen
(Kunde hat kein Signal, ESB offline etc.) schnell zu helfen — telefonisch oder beim
Vor-Ort-Termin, um SSID/Passwort/IP mit dem tatsächlichen Router-Stand abzugleichen.

**Für die Umsetzung zu berücksichtigen (Plattform-Seite):**
- Neue Spalten/Tabelle für Zählpunkt bzw. ESB-Gerät: `wifi_ssid`, `wifi_ip`,
  vermutlich `wifi_password` — Übertragungsweg vermutlich MQTT (bestehender
  `mosquitto`-Broker) oder ein neuer Report-Endpoint, analog zum späteren
  Live-Daten-API (siehe Punkt 2 unten).
- Sichtbar nur für Obmänner/Admins (Manager-/Platform-Admin-Rollen), nicht für das
  Mitglied selbst — analog zur bestehenden Rollenlogik in `member_detail.php`.
- **Sicherheitshinweis:** Das WLAN-Passwort des Kunden landet damit im Klartext (oder
  zumindest wiederherstellbar) in der Datenbank. Sollte beim Bauen verschlüsselt
  gespeichert werden (nicht nur gehasht, da man es ja wieder anzeigen können muss) und
  in der UI evtl. hinter einem "anzeigen"-Klick versteckt statt immer offen sichtbar,
  damit es nicht z. B. versehentlich auf einem Screenshot landet.
- ESB-Seite: müsste die verbundene SSID, das eingegebene WLAN-Passwort und die per
  DHCP erhaltene IP beim Boot/Reconnect auslesen und mitschicken.

**Status:** noch nicht begonnen — zurückgestellt bis Hardware verfügbar ist (siehe auch
Punkt 2, gleiche Abhängigkeit).

---

### 2. API-Schnittstelle für Live-Energiedaten (Smart-Home-Anbindung)
**Idee (aus früherer Session, weiterhin gültig):** Der ESB soll Live-Bezug/-Einspeisung
und die aktuelle Autarkie-Quote der Gemeinschaft über eine API bereitstellen bzw.
abrufbar machen — z. B. für Node-RED oder andere Smart-Home-Systeme des Mitglieds.

**Bereits vorhanden (Plattform-Seite, Stand 18.07.2026):**
- Mitglieder-Bereich „🔌 API-Zugänge" (`/portal/my/api-keys`): Mitglieder können sich
  selbst API-Keys erzeugen (Name, optionale Gültigkeit, Widerruf).
- Test-Endpoint `GET /api/v1/me` (Bearer-Token-Auth gegen den erzeugten API-Key) —
  funktioniert bereits, wurde erfolgreich in Node-RED getestet.

**Noch offen:** die eigentlichen Live-Daten-Endpoints (z. B. `GET /api/v1/live` mit
Bezug/Einspeisung in Watt + Autarkie-Quote der Community). Bewusst zurückgestellt, bis
die Zähler-/Dashboard-Pipeline produktionsreif ist (das Mitglieder-Dashboard zeigt die
Verbrauchsanzeige aktuell nur als „🚧 in Bearbeitung"-Platzhalter, siehe
`member_dashboard.php`) — sonst müsste die API zweimal gebaut werden.

**Status:** zurückgestellt, siehe Platform-Task #64.

---

### 3. EDA-Monatsexport-Import mit automatischem Zählpunkt-Abgleich
**Idee (Patrick, 24.07.2026):** Beim Hochladen des **EDA-Monatsexports** (der Datei, die man
ohnehin für die Quartalsabrechnung importiert) soll die Plattform die enthaltenen Zählpunkte
automatisch mit dem eigenen Bestand abgleichen und Abweichungen klar melden — nicht nur eine
generische Fehlermeldung, sondern jeweils **ausformuliert, warum** etwas nicht passt.

**Gewünschtes Verhalten:**
1. Alle Zählpunkte (Zählpunktnummern) aus der Datei extrahieren.
2. Mit den in der EEG angelegten Zählpunkten abgleichen:
   - **In der Datei, aber noch nicht angelegt →** automatisch anlegen (soweit eindeutig
     möglich) und im Report auflisten, was neu angelegt wurde.
   - **In der Plattform aktiv, aber in der Datei nicht enthalten („fehlt") →** Warnung mit
     Begründung (z. B. „Zählpunkt AT00… ist bei uns aktiv, taucht im EDA-Export für diesen
     Monat aber nicht auf — evtl. Abmeldung, Zählerwechsel oder Datenlücke; bitte prüfen.").
   - **In der Datei, aber keinem Mitglied/keiner Anlage zuordenbar („zu viel") →** Warnung mit
     Begründung (z. B. „Zählpunkt AT00… ist im EDA-Export, gehört aber zu keinem Mitglied dieser
     EEG — gehört er hierher, fehlt eine Zuordnung; sonst ist es ein Fremd-Zählpunkt.").
3. **Ergebnis als übersichtlicher Report:** Abschnitte „neu angelegt", „Warnungen (fehlt/zu
   viel)", „Fehler", jeweils mit **verständlicher, ausformulierter Begründung** statt rohem
   „Error". Import soll auch bei Warnungen durchlaufen, klare Fehler (unlesbare Datei, falsches
   Format) sauber erklären.

**Für die Umsetzung zu klären / berücksichtigen (Plattform-Seite):**
- **Dateiformat des EDA-Monatsexports** genau festlegen (Eder-XLSX?): welche Spalten enthalten
  Zählpunktnummer, Zeitraum, Werte, Wertekategorie (L1/L2/L3, siehe `docs/EDA_DATENQUALITAET.md`)?
- **Zuordnung Zählpunkt → Mitglied** bei automatisch angelegten: welchem Mitglied zuordnen? Wohl
  zunächst „offen/nicht zugeordnet" markieren und der Obmann verknüpft manuell — sonst rät die
  Plattform falsch.
- Aufsetzen auf dem bestehenden EDA-Import (`eda_imports`/`eda_measurements`, `migrate_20260716`)
  und der Abrechnungs-Datenqualitätslogik (`Billing::datenqualitaetProblem()`).
- Nachvollziehbarkeit: Import-Lauf + Ergebnis im **Audit-Log** festhalten (seit 2026-08-15 mit
  Vorher/Nachher-Werten) — wer hat wann welche Zählpunkte automatisch angelegt.

**Status:** noch nicht begonnen — Backlog. Nächster sinnvoller Schritt, sobald echte
EDA-Exportdateien vorliegen (Format-Muster nötig).

---

## Umgesetzt

*(noch keine Einträge — sobald ein Punkt aus „Offen" fertig ist, hierher verschieben
mit kurzem Verweis auf Commit/PR.)*
