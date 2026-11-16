# Messe-/Präsentations-Demo (simulierte Werte, seit 02.10.2026)

> Ausgelagert aus `CLAUDE.md` (16.11.2026). Wörtliche Zitate wurden entfernt (öffentliches Repo).

Zweck: Auf einer Messe bzw. in Präsentationen einen glaubwürdigen Energiefluss zeigen (8 Einspeiser, 12 Verbraucher), solange noch keine echten Ausleseeinheiten verbaut sind. Läuft als dauerhafter Hintergrund-Schalter, nicht als Kommandozeilen-Skript.

**Bedienung (Obmann-Einstellungen, ganz oben bei "Stammdaten"):** Checkbox "Simulierte Werte
anzeigen (Messe-/Präsentations-Demo)" -- einfach an-/abschalten, kein Server-/
Kommandozeilenzugriff nötig (`communities.messe_demo_enabled`, siehe `migrate_20261005.sql`).

**Technisch:** läuft als Daemon-Thread direkt im ohnehin dauerhaft laufenden
`mqtt-subscriber`-Container (`demo_simulation_loop()` in `mqtt-subscriber/main.py`) -- kein
separater Prozess, übersteht Reboots/Abstürze wie jeder andere Thread dieses Containers. Pollt
alle 5s, welche EEGs `messe_demo_enabled = true` haben, legt bei Bedarf automatisch (idempotent)
20 fiktive Zählpunkte (8 Einspeiser, 12 Verbraucher) unter einem eigenen `is_demo=true`-Mitglied
an und publiziert echte MQTT-Live-Nachrichten im exakten Firmware-Format -- läuft also über den
kompletten echten Pfad (mqtt-subscriber -> esp_measurements -> Energiefluss/Live-Dashboard/
öffentliche Live-Anzeige), kein Frontend-Fake. `is_demo=true` schließt sie wie die bestehenden
Demo-Login-Zählpunkte automatisch von jedem echten Abrechnungslauf aus
(`Billing::generateDrafts()` filtert `m.is_demo = false`) -- ohne jede Auswirkung auf
Abrechnungen.

**Tagesprofil, echtzeit-synchron** (Nachbesserung 02.10.2026; ein 20-Minuten-Zeitraffer wurde verworfen): Einspeiser liefern 0 W vor 06:00/nach 20:00, dazwischen eine
Sinuskurve mit Höchstwert um 13:00; Verbraucher haben nachts eine Kühlschrank-/Standby-Grundlast
(nie 0) und je einen Buckel morgens, mittags und (am stärksten) abends -- 1:1 mit der echten
Uhrzeit, kein Zeitraffer, weil der Schalter wochenlang durchlaufen soll.

**Einspeisung nach echtem Wetter** (seit 08.11.2026; im November bei Nebel passten 11-12 kW Einspeisung nicht mehr): die Einspeiser rechnen mit der aktuellen Einstrahlung auf eine
30° nach Süden geneigte Fläche vom kostenlosen Wetterdienst **Open-Meteo** (kein API-Schlüssel,
Abruf alle 10 min aus dem `mqtt-subscriber`-Container, braucht also ausgehendes HTTPS zu
`api.open-meteo.com`) -- Leistung = Nennleistung × Einstrahlung/1000 × 0,85, bei gemeldetem Nebel
zusätzlich × 0,3. Sonnenauf-/-untergang aus dem echten Sonnenstand statt fix 06/20 Uhr; nachts
exakt 0 W. Standort per `MESSE_DEMO_LAT`/`MESSE_DEMO_LON` in der `.env` (leer = Feldkirchen in
Kärnten). Ist Open-Meteo nicht erreichbar: Schätzung aus dem Sonnenstand (halbe
Schönwetter-Leistung) und Warnung im Log (`docker compose logs mqtt-subscriber | grep Wetter`).
Die Oberfläche zeigt bei 0 W Einspeisung „aktuell keine Einspeisung“ statt „0 W“.

**Sichtbarer Hinweis, wenn aktiv** (die Werte sind fiktiv und stimmen noch nicht mit echten Anlagen überein): sowohl auf dem
Energiefluss im Obmann-/Mitglied-Dashboard (`energy_flow.php`) als auch auf der öffentlichen
Live-Anzeige (`/live/:slug`, über `demo_simulation` im `/api/live/:slug`-JSON) erscheint ein
deutlicher gelber Hinweistext, solange der Schalter an ist.

**Zum Ausschalten:** Checkbox einfach wieder abwählen -- die 20 Zählpunkte bleiben als
"registrierte Zählpunkte" bestehen (für ein schnelles Wieder-Einschalten später), zählen aber
nach dem Abschalten innerhalb von ~2 Minuten nicht mehr in die Live-Summe mit
(`communityLivePower()`/`/api/live/:slug` zählen nur Messungen der letzten 2 Minuten).

