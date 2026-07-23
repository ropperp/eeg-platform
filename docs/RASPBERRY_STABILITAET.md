# Raspberry Pi hängt sich auf — Ursachen, Diagnose & Selbstheilung

**Symptom:** Der Pi ist im Netzwerk noch sichtbar, die LEDs am Netzwerkanschluss leuchten,
aber SSH / Raspberry Pi Connect / Terminal reagieren nicht mehr. Erst ein Strom-Aus/-Ein
(oder Reset) bringt ihn zurück. Für einen Produktivserver inakzeptabel — deshalb hier a) die
wahrscheinlichen Ursachen, b) wie man die tatsächliche Ursache findet, c) wie sich der Pi im
Ernstfall **selbst neu startet**, ohne dass jemand zu Hause sein muss.

> Wichtig zuerst: „Ping geht (z. B. 0,3 ms), aber SSH/Pi-Connect/Terminal reagieren nicht"
> ist der klassische Fingerabdruck dafür, dass der **Kernel noch lebt** (er beantwortet den
> Ping), aber der **Userspace bzw. der Datenträger hängt** — alles, was auf die Platte oder
> auf einen abgeschossenen Dienst zugreift (Login, sshd, Docker), bleibt stehen.
>
> **Konkreter Befund auf diesem Pi (2026-07-23):**
> ```
> /dev/nvme0n1p2 on / type ext4 (rw,noatime)
> ```
> Das Root-Dateisystem liegt auf einer **NVMe-SSD über PCIe** (`nvme0n1`, nicht `sdX` über
> USB) und war zum Prüfzeitpunkt **read-write** (`rw`), nicht read-only. Damit sind die beiden
> Verdächtigen, die man bei einer USB-SSD zuerst prüft, **ausgeschlossen**: Es gibt keinen
> USB-SATA-/UAS-Adapter, der resetten könnte, und das FS war nicht read-only remountet. Der
> vorherige Stand dieser Doku (USB-SATA-Reset als Hauptverdacht) trifft für dieses Setup
> **nicht** zu.
>
> Ebenfalls beobachtet:
> ```
> journalctl -k -b -1  →  „no persistent journal was found"
> ```
> Das Journal wird nur im RAM gehalten und ist nach einem Reboot **weg** — genau der Boot vor
> dem Absturz, den man bräuchte, lässt sich damit nicht mehr ansehen. **Erster, wichtigster
> Schritt ist deshalb, das Journal persistent zu machen** (Abschnitt 2), sonst bleibt der
> nächste Hänger wieder blind.
>
> Verbleibende wahrscheinlichste Ursachen bei NVMe+rw, in dieser Reihenfolge:
> **(1) OOM-Killer** hat unter Speicherdruck sshd/Dienste abgeschossen, während der Kernel
> weiterläuft; **(2) Unterspannung/Throttling** (Pi 5 will 5 V/5 A, NVMe-HAT zieht zusätzlich)
> → Brownout; **(3) NVMe-/PCIe-Link-Aussetzer** (z. B. `nvme … I/O timeout`, PCIe-Gen-3 am
> Limit) → I/O-Stall; **(4) systemd-/Dienst-Deadlock**. Die Diagnosen unten zeigen, welcher
> Fall vorliegt — aber nur, wenn das Journal persistent ist.

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

### 0) ZUERST: Journal persistent machen (sonst bleibt der nächste Hänger blind)
Auf diesem Pi meldet `journalctl -k -b -1` aktuell „no persistent journal was found" — die Logs
liegen nur im RAM und sind nach dem Reboot weg. Genau die Meldungen kurz **vor** dem Einfrieren
(OOM, Unterspannung, NVMe-Timeout …) wären aber der entscheidende Hinweis. Deshalb einmalig:

```bash
sudo mkdir -p /var/log/journal
sudo sed -i 's/^#\?Storage=.*/Storage=persistent/' /etc/systemd/journald.conf
sudo systemctl restart systemd-journald
journalctl --list-boots        # danach muss mehr als nur der aktuelle Boot (0) auftauchen
```

Ab jetzt überlebt das Journal einen Reboot, und nach dem nächsten Hänger zeigt `journalctl -b -1`
tatsächlich den Boot davor. **Ohne diesen Schritt** liefern die folgenden `-b -1`-Befehle nichts.

### Logs von VOR dem Absturz ansehen
Nach dem nächsten Hänger + Reboot **zuerst** die Logs von VOR dem Absturz ansehen:

```bash
# Kernel-/System-Meldungen aus dem vorherigen Boot (das '-1' = letzter Boot davor)
journalctl -b -1 -e --no-pager | tail -80

# Gezielt nach den üblichen Verdächtigen suchen
journalctl -b -1 --no-pager | grep -iE "oom|out of memory|killed process|EXT4-fs error|I/O error|mmc0|voltage|throttl|watchdog"
```

### a) Datenträger: NVMe-/PCIe-Link-Aussetzer → I/O-Stall
Dieser Pi bootet von **NVMe über PCIe** (`/dev/nvme0n1`), das Root-FS war zuletzt read-write.
Damit ist der klassische **USB-SATA-/UAS-Reset** (die häufigste „Ping ja, SSH nein"-Ursache bei
Pi + *USB*-SSD) hier **ausgeschlossen** — es gibt keinen USB-Bridge-Chip, der resetten könnte,
und ein read-only remountetes FS wurde nicht beobachtet. Was bei NVMe stattdessen passieren kann:
Der **PCIe-Link zur NVMe setzt kurz aus** (grenzwertige Signalintegrität, PCIe Gen 3 am Limit,
schwache Stromversorgung des HAT) → der Kernel meldet einen `nvme … I/O timeout` / `controller
reset`, jeder schreibende Dienst hängt, der Kernel pingt weiter.

Nach dem nächsten Hänger + Reboot prüfen (‑b ‑1 = der Boot davor; **setzt persistentes Journal
aus Schritt 0 voraus**):
```bash
mount | grep " / "                     # steht da "ro," war das Root-FS doch read-only remountet
journalctl -k -b -1 | grep -iE "nvme|pcie|EXT4-fs error|I/O error|timeout|read-only|reset|controller"
sudo smartctl -a /dev/nvme0n1 | grep -iE "error|temperature|percentage|critical"   # NVMe-Gesundheit/Temp
```
- Tauchen dort **`nvme … timeout` / `pcie … link` / `controller reset`** kurz vor dem Einfrieren
  auf, ist es der NVMe-/PCIe-Link. Abhilfe:
  1. **PCIe-Generation testweise auf Gen 2 festnageln** (stabiler als das oft grenzwertige
     Gen 3): in `/boot/firmware/config.txt` `dtparam=pciex1_gen=2` setzen und neu starten.
  2. **Sitz von HAT/Flachbandkabel** prüfen, kurzes/originales PCIe-FFC verwenden, NVMe fest
     verschraubt.
  3. **NVMe-Kühlung** — NVMe drosselt/aussetzt bei Hitze; `smartctl` zeigt die Temperatur.
  4. **Stromversorgung** des HAT sicherstellen (siehe c) Unterspannung).
- Steht das FS wider Erwarten doch auf „ro", hilft kurzfristig nur ein Reboot; der Watchdog
  (Punkt 1) erledigt das von selbst, weil dann auch systemd/journald beim Schreiben hängen und
  den Watchdog nicht mehr bedienen → Hardware-Reboot.

> Der frühere Hauptverdacht **USB-SATA-/UAS-Reset** gilt nur für Pi mit **USB**-SSD und ist hier
> gegenstandslos (NVMe über PCIe). Sollte künftig doch mal eine USB-SSD/SD-Karte im Spiel sein:
> `dmesg | grep -iE "uas|usb|mmc0"` bzw. `usb-storage.quirks=<id>:u` in `cmdline.txt`.

### b) Zu wenig RAM / Swap  ← einer der zwei Hauptverdächtigen bei NVMe+rw
timescaledb + redis + latex (`pdflatex` ist speicherhungrig) + node + python zusammen können
den RAM füllen. Ohne genügend Swap führt das zu Thrashing → Hänger, oder der OOM-Killer schießt
einen Dienst (u. U. sshd) ab, während der Kernel weiterläuft → exakt „Ping ja, SSH nein".

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
2. **Journal persistent machen** (Abschnitt 2.0) — ohne das bleibt jeder künftige Hänger
   undiagnostizierbar. Danach **NVMe-/PCIe-Link im Auge behalten** (Abschnitt 2a): bei
   `nvme timeout`-Meldungen PCIe auf Gen 2 festnageln, HAT/Kabel/Kühlung prüfen. Die NVMe-über-
   PCIe-Anbindung ist bereits die stabilste Variante (kein USB-SATA-Bridge-Problem mehr).
3. **Ordentliches Netzteil + Kühlung** (Pi 5: original 5 V/5 A; SSD zieht zusätzlich Strom).
4. **Externes Monitoring** (z. B. ein Uptime-Check von außen, der dir eine Mail/Push schickt,
   wenn `https://stromfueralle.at` nicht antwortet) — dann weißt du sofort Bescheid, statt es
   erst vom Kunden zu erfahren. Kann später über die vorhandene Microsoft-Graph-Mail-Anbindung
   oder einen kostenlosen Dienst (UptimeRobot o. ä.) laufen.
5. **Backups** laufen bereits (`scripts/backup.sh`, NAS-Sync) — im SD-Karten-Todesfall ist das
   die Rettung. Regelmäßig testen, dass ein Restore wirklich funktioniert.
