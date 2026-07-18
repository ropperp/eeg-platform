# Docker installieren

Die Plattform braucht **Docker** und das **Docker Compose Plugin** (`docker compose`, mit
Leerzeichen — das ist Teil von aktuellem Docker, nicht das alte, separate `docker-compose`).
Hier die Installation für macOS, Windows und Linux, jeweils per Kommandozeile und per
Installer von der offiziellen Docker-Webseite.

Nach der Installation (egal welcher Weg) prüfen:

```bash
docker --version
docker compose version
```

Beide Befehle müssen eine Versionsnummer ausgeben, sonst ist etwas schiefgelaufen.

---

## macOS

### Option A: Über die Webseite (Docker Desktop)

1. [https://www.docker.com/products/docker-desktop/](https://www.docker.com/products/docker-desktop/)
   öffnen, „Download for Mac" wählen (Apple Silicon oder Intel, je nach Mac).
2. Die heruntergeladene `Docker.dmg` öffnen und Docker in den Programme-Ordner ziehen.
3. Docker Desktop aus dem Launchpad starten und den Anweisungen folgen (einmalig
   Administratorrechte bestätigen).
4. Docker Desktop muss laufen (Wal-Symbol in der Menüleiste), bevor `docker`-Befehle im
   Terminal funktionieren.

### Option B: Über die Kommandozeile (Homebrew)

Voraussetzung: [Homebrew](https://brew.sh) ist installiert.

```bash
brew install --cask docker
open /Applications/Docker.app
```

Docker Desktop einmal starten (siehe oben) und warten, bis das Wal-Symbol in der Menüleiste
signalisiert, dass Docker bereit ist.

---

## Windows

Voraussetzung für beide Wege: **WSL2** (Windows Subsystem for Linux) muss aktiviert sein --
Docker Desktop installiert das bei Bedarf automatisch mit, sonst reicht vorab:

```powershell
wsl --install
```

(danach einmal neu starten).

### Option A: Über die Webseite (Docker Desktop)

1. [https://www.docker.com/products/docker-desktop/](https://www.docker.com/products/docker-desktop/)
   öffnen, „Download for Windows" wählen.
2. Installer (`Docker Desktop Installer.exe`) ausführen, "Use WSL 2 instead of Hyper-V"
   angehakt lassen.
3. Nach der Installation neu starten, Docker Desktop starten und den Anweisungen folgen.

### Option B: Über die Kommandozeile (winget)

In PowerShell (als Administrator):

```powershell
winget install Docker.DockerDesktop
```

Danach neu starten und Docker Desktop einmal manuell starten.

> Alle `./scripts/setup.sh`-Befehle dieser Anleitung sind Bash-Skripte -- unter Windows dafür
> entweder **WSL2** (`wsl` im Terminal öffnen, das Repo dort klonen) oder **Git Bash**
> verwenden, nicht die normale PowerShell/CMD.

---

## Linux (Ubuntu/Debian, auch Raspberry Pi OS)

### Option A: Offizielles Installationsskript (schnellster Weg)

```bash
curl -fsSL https://get.docker.com | sh
sudo usermod -aG docker $USER
```

Danach ab- und wieder anmelden (oder `newgrp docker` ausführen), damit `docker` ohne `sudo`
funktioniert.

### Option B: Über die offiziellen apt-Pakete (mehr Kontrolle)

```bash
# Docker-eigenes apt-Repository einrichten
sudo apt-get update
sudo apt-get install -y ca-certificates curl
sudo install -m 0755 -d /etc/apt/keyrings
sudo curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
sudo chmod a+r /etc/apt/keyrings/docker.asc
echo \
  "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu \
  $(. /etc/os-release && echo "$VERSION_CODENAME") stable" | \
  sudo tee /etc/apt/sources.list.d/docker.list > /dev/null
sudo apt-get update

# Docker Engine + Compose-Plugin installieren
sudo apt-get install -y docker-ce docker-ce-cli containerd.io docker-compose-plugin

# Ohne sudo nutzen können
sudo usermod -aG docker $USER
```

Danach ab- und wieder anmelden (oder `newgrp docker`).

Andere Distributionen (Fedora, Debian ohne Ubuntu-Repo, Arch, ...): offizielle Anleitung unter
[https://docs.docker.com/engine/install/](https://docs.docker.com/engine/install/) -- dort
die passende Distribution auswählen.

Es gibt auch **Docker Desktop für Linux** (grafische Oberfläche, wie bei macOS/Windows) --
für einen Server ohne Desktop-Umgebung (z.B. Raspberry Pi headless) ist aber die
Kommandozeilen-Installation oben der richtige Weg.

---

## Danach

Sobald `docker --version` und `docker compose version` funktionieren, weiter mit
[SETUP.md](../SETUP.md).
