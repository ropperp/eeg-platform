# Raspberry Pi hängt sich auf — Ursachen, Diagnose & Selbstheilung

**Symptom:** Der Pi ist im Netzwerk noch sichtbar, die LEDs am Netzwerkanschluss leuchten,
aber SSH / Raspberry Pi Connect / Terminal reagieren nicht mehr. Erst ein Strom-Aus/-Ein
(oder Reset) bringt ihn zurück. Für einen Produktivserver inakzeptabel — deshalb hier a) die
wahrscheinlichen Ursachen, b) wie man die tatsächliche Ursache findet, c) wie sich der Pi im
Ernstfall **selbst neu startet**, ohne dass jemand zu Hause sein muss.

> Wichtig zuerst: „Ping geht (z. B. 0,3 ms), aber SSH/Pi-Connect/Terminal reagieren nicht"
> ist der klassische Fingerabdruck dafür, dass der **Kernel noch lebt** (er beantwortet den
> Ping), aber der **Userspace bzw. der Datenträger hängt** — alles, was auf die Platte oder
> auf einen abgeschossenen Dienst zugreift (Login, sshd, Docker), bleibt stehen. Bei einem
> **Pi 5 mit SSD** (nicht SD-Karte) sind die wahrscheinlichsten Ursachen in dieser Reihenfolge:
> **(1) USB-SATA-/UAS-Reset des SSD-Adapters** → Root-Dateisystem geht read-only → sshd kann
> nicht mehr schreiben; **(2) OOM-Killer** hat unter Speicherdruck sshd/Dienste abgeschossen,
> während der Kernel weiterläuft; **(3) Unterspannung** (Pi 5 will 5 V/5 A, SSD zieht mit) →
> USB-Brownout. SD-Karten-Verschleiß ist bei dir also NICHT der Hauptverdacht — die folgenden
> Diagnosen zeigen, welcher der drei Fälle vorliegt.

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

### a) USB-SSD-/Datenträger-Reset → Dateisystem read-only  ← Hauptverdacht bei SSD
Auch eine gute SSD hilft nichts, wenn der **USB-SATA-Adapter** (die Bridge zwischen SSD und
Pi) unter Last oder bei Spannungsschwankungen kurz aussetzt: Der Kernel bekommt einen
I/O-Fehler, remountet das Root-Dateisystem **read-only**, und ab da hängt jeder Dienst, der
schreiben will (sshd-Login, Docker, Postgres) — der Kernel selbst pingt aber weiter. Das ist
der mit Abstand häufigste Grund für „Ping ja, SSH nein" bei einem Pi mit USB-SSD.

Nach dem nächsten Hänger + Reboot **zuerst** prüfen (‑b ‑1 = der Boot davor):
```bash
mount | grep " / "                     # steht da "ro," war das Root-FS read-only -> Treffer
journalctl -k -b -1 | grep -iE "usb|uas|ata|nvme|EXT4-fs error|I/O error|read-only|reset"
```
- Tauchen dort **USB-Resets / „uas" / „reset SuperSpeed"** kurz vor dem Einfrieren auf, ist es
  die USB-SATA-Bridge. Abhilfe:
  1. **Anderes/kürzeres USB-Kabel** und einen USB-3-Port am Pi verwenden.
  2. **UAS für den Adapter abschalten** (viele billige Bridges sind mit UAS instabil, mit dem
     älteren `usb-storage`-Treiber aber stabil). Adapter-ID mit `lsusb` ermitteln und in
     `/boot/firmware/cmdline.txt` `usb-storage.quirks=<id>:u` ergänzen (siehe Raspberry-Pi-Foren
     zu „UAS quirks").
  3. Auf dem Pi 5 wenn möglich **NVMe-SSD über den PCIe-/M.2-HAT** statt USB-SATA nutzen — das
     ist der stabilste und schnellste Weg und umgeht das USB-Bridge-Problem ganz.
- Steht das FS auf „ro", hilft kurzfristig nur ein Reboot; der Watchdog (Punkt 1) erledigt das
  von selbst, weil dann auch systemd/journald beim Schreiben hängen und den Watchdog nicht mehr
  bedienen können → Hardware-Reboot.

> SD-Karten-Verschleiß (begrenzte Schreibzyklen, Blockausfall → read-only) erzeugt dasselbe
> Bild, ist bei dir aber nicht relevant, weil das OS auf SSD läuft. Falls doch mal eine
> SD-Karte im Spiel ist: `sudo smartctl -a /dev/mmcblk0` liefert bei SD meist keine Werte,
> Diagnose dann nur über `dmesg | grep -i mmc0`.

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
1. **Watchdog aktivieren** (Punkt 1) — Pi heilt sich selbst, egal was die Ursache ist. Für den
   Fall, dass der Kernel noch lebt (Ping ja) aber nur ein Dienst tot ist, zusätzlich sinnvoll:
   ein kleiner systemd-Timer, der alle paar Minuten die eigene Website/DB prüft und bei
   wiederholtem Fehlschlag `systemctl reboot` auslöst (health-check-basierter Selbst-Reboot).
2. **SSD-Anbindung stabilisieren** — du nutzt bereits eine SSD; entscheidend ist die
   *Anbindung*: am Pi 5 nach Möglichkeit **NVMe über PCIe/M.2-HAT** statt USB-SATA, sonst ein
   gutes Kabel + ggf. UAS abschalten (siehe Punkt 2a). Das beseitigt den Hauptverdacht.
3. **Ordentliches Netzteil + Kühlung** (Pi 5: original 5 V/5 A; SSD zieht zusätzlich Strom).
4. **Externes Monitoring** (z. B. ein Uptime-Check von außen, der dir eine Mail/Push schickt,
   wenn `https://stromfueralle.at` nicht antwortet) — dann weißt du sofort Bescheid, statt es
   erst vom Kunden zu erfahren. Kann später über die vorhandene Microsoft-Graph-Mail-Anbindung
   oder einen kostenlosen Dienst (UptimeRobot o. ä.) laufen.
5. **Backups** laufen bereits (`scripts/backup.sh`, NAS-Sync) — im SD-Karten-Todesfall ist das
   die Rettung. Regelmäßig testen, dass ein Restore wirklich funktioniert.
