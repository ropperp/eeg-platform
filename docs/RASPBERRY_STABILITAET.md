# Raspberry Pi hängt sich auf — Ursachen, Diagnose & Selbstheilung

**Symptom:** Der Pi ist im Netzwerk noch sichtbar, die LEDs am Netzwerkanschluss leuchten,
aber SSH / Raspberry Pi Connect / Terminal reagieren nicht mehr. Erst ein Strom-Aus/-Ein
(oder Reset) bringt ihn zurück. Für einen Produktivserver inakzeptabel — deshalb hier a) die
wahrscheinlichen Ursachen, b) wie man die tatsächliche Ursache findet, c) wie sich der Pi im
Ernstfall **selbst neu startet**, ohne dass jemand zu Hause sein muss.

> Wichtig zuerst: „Ping geht, aber SSH nicht" ist der klassische Fingerabdruck eines
> **I/O-Stalls** — der Netzwerk-Stack sitzt im Kernel und antwortet noch auf Ping, während
> alles, was die Platte braucht (Login, Shell, Docker), hängt. Die Platte ist auf einem Pi in
> ~90 % der Fälle der Übeltäter (SD-Karte) oder der Arbeitsspeicher (Swap auf SD-Karte).

---

## 1. Sofort-Maßnahme: Selbstheilung per Hardware-Watchdog

Der Raspberry hat einen **eingebauten Hardware-Watchdog**. Ist er aktiv und das System
friert ein (Kernel bedient den Watchdog nicht mehr), löst der Chip nach ein paar Sekunden
automatisch einen **Reboot** aus — ohne Netzstecker ziehen. Genau das Richtige, wenn man nicht
daheim ist.

```bash
# 1. Watchdog-Modul aktivieren
echo "dtparam=watchdog=on" | sudo tee -a /boot/firmware/config.txt   # ältere OS: /boot/config.txt
# (danach einmal neu starten, damit /dev/watchdog erscheint)

# 2. systemd den Watchdog bedienen + bei komplettem Hänger neu starten lassen
sudo tee -a /etc/systemd/system.conf.d/watchdog.conf >/dev/null <<'EOF'
[Manager]
RuntimeWatchdogSec=15
RebootWatchdogSec=2min
EOF
sudo mkdir -p /etc/systemd/system.conf.d
sudo systemctl daemon-reexec
```

- `RuntimeWatchdogSec=15` → systemd „streichelt" den Watchdog alle 15 s; bleibt das aus
  (System eingefroren), rebootet die Hardware nach ~15 s selbst.
- Docker-Container starten dank `restart: always` (bereits in `docker-compose.yml` gesetzt)
  nach dem Reboot automatisch wieder — die Plattform ist also von allein wieder online.

> Der Watchdog kuriert das **Symptom** (Pi kommt von selbst zurück), nicht die Ursache. Die
> nächsten Punkte finden die Ursache, damit es gar nicht so oft passiert.

---

## 2. Ursache finden

Nach dem nächsten Hänger + Reboot **zuerst** die Logs von VOR dem Absturz ansehen:

```bash
# Kernel-/System-Meldungen aus dem vorherigen Boot (das '-1' = letzter Boot davor)
journalctl -b -1 -e --no-pager | tail -80

# Gezielt nach den üblichen Verdächtigen suchen
journalctl -b -1 --no-pager | grep -iE "oom|out of memory|killed process|EXT4-fs error|I/O error|mmc0|voltage|throttl|watchdog"
```

### a) SD-Karte am Ende / I/O-Fehler  ← häufigste Ursache
Postgres/TimescaleDB + Redis + Docker schreiben rund um die Uhr. SD-Karten haben begrenzte
Schreibzyklen; wenn Blöcke ausfallen, geht das Dateisystem in den Read-only-Modus oder
Schreibzugriffe hängen → genau das „Ping ja, SSH nein"-Bild.

```bash
dmesg | grep -iE "mmc0|EXT4-fs error|I/O error|read-only"   # nach dem Reboot
mount | grep " / "                                          # steht da "ro," ist die FS read-only
sudo smartctl -a /dev/mmcblk0 2>/dev/null || echo "(SD-Karten liefern meist keine SMART-Werte)"
```

**Beste Lösung:** Vom **USB-SSD** statt SD-Karte booten (Pi 4/5 können das nativ). Eine
kleine SSD kostet wenig, hält um Größenordnungen länger und ist deutlich schneller — für eine
Datenbank praktisch Pflicht. Minimal-Variante: zumindest `/opt/eeg` (die Daten-Volumes) auf
eine SSD legen und dorthin mounten.

### b) Zu wenig RAM / Swap auf SD-Karte
timescaledb + redis + latex (`pdflatex` ist speicherhungrig) + node + python zusammen können
den RAM füllen. Ohne (oder mit SD-Karten-)Swap führt das zu Thrashing → Hänger, oder der
OOM-Killer schießt einen Container ab.

```bash
free -h                                   # aktuelle RAM-/Swap-Auslastung
journalctl -b -1 | grep -i "killed process"   # hat der OOM-Killer zugeschlagen?
docker stats --no-stream                  # welcher Container frisst RAM?
```

Abhilfe: **zram-Swap** (komprimierter RAM-Swap statt SD-Karte) und Container-Speicherlimits:
```bash
sudo apt install -y zram-tools            # legt automatisch komprimierten Swap im RAM an
```

### c) Unterspannung / Überhitzung
Ein zu schwaches Netzteil (Pi 5 will 5 V / 5 A) verursacht sporadische Brownouts →
Einfrieren bei genau „LEDs an, aber tot". Ebenso Throttling ohne Kühlkörper/Lüfter.

```bash
vcgencmd get_throttled     # 0x0 = alles gut. Alles andere = Unterspannung/Throttling (siehe Bit-Tabelle)
vcgencmd measure_temp      # dauerhaft > 80 °C = Kühlung nötig
```

Abhilfe: **Original-Netzteil** verwenden, Kühlkörper/aktiven Lüfter nachrüsten.

### d) Docker-Logs / Platte voll
War früher unbegrenzt — seit dem Log-Rotation-Fix in `docker-compose.yml` (`x-logging`,
max. 3 × 10 MB pro Container) gedeckelt. Trotzdem regelmäßig prüfen:
```bash
df -h /                              # Root-FS voll? -> alles hängt
docker system df                     # Platzverbrauch von Images/Containern/Volumes
docker system prune -f               # alte, ungenutzte Images/Container aufräumen
```

---

## 3. Ist es eine Docker-Einstellung?
Nicht direkt — Docker selbst bringt den Pi nicht zum Absturz. Aber Docker **verstärkt** die
zwei häufigsten Ursachen: viele Schreibzugriffe (SD-Karte) und viel RAM-Verbrauch. Was im Repo
bereits dagegen gesetzt ist:
- `restart: always` auf allen Containern → nach Reboot automatisch wieder online.
- `x-logging` (Log-Rotation) → Logs können die Platte nicht mehr volllaufen lassen.

Optional sinnvoll (Host-seitig, nicht im Repo): Speicherlimits pro Container, z. B. in einer
`docker-compose.override.yml` **nur** mit `mem_limit`/`deploy.resources` — Achtung: **keine**
override-Datei anlegen, die Traefik deaktiviert oder Ports umbiegt (siehe Warnung in
`CLAUDE.md`).

---

## 4. Grundsatz-Empfehlung für den Produktivbetrieb
1. **Watchdog aktivieren** (Punkt 1) — Pi heilt sich selbst, egal was die Ursache ist.
2. **Von SSD statt SD-Karte booten** — beseitigt die mit Abstand häufigste Absturzursache.
3. **Ordentliches Netzteil + Kühlung.**
4. **Externes Monitoring** (z. B. ein Uptime-Check von außen, der dir eine Mail/Push schickt,
   wenn `https://stromfueralle.at` nicht antwortet) — dann weißt du sofort Bescheid, statt es
   erst vom Kunden zu erfahren. Kann später über die vorhandene Microsoft-Graph-Mail-Anbindung
   oder einen kostenlosen Dienst (UptimeRobot o. ä.) laufen.
5. **Backups** laufen bereits (`scripts/backup.sh`, NAS-Sync) — im SD-Karten-Todesfall ist das
   die Rettung. Regelmäßig testen, dass ein Restore wirklich funktioniert.
