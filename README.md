# ⚡ EEG-Plattform

Mandantenfähige SaaS-Plattform zur Verwaltung von Erneuerbaren-Energie-Gemeinschaften (EEGs) nach österreichischem EAG/ElWOG.

Entwickelt als Diplomarbeit 2026/27 an der HTL Kärnten (Patrick Ropper, Fabian, Alexander).

---

## Was die Plattform kann

- **Mehrere EEGs** (Mandanten) auf einer Installation — vollständige Datentrennung via PostgreSQL Row-Level Security
- **Manager-Portal**: Mitgliederverwaltung, Vertragsgenerierung (PDF), EDA-Datenimport, Quartalsabrechnung
- **Mitglieder-Portal**: Eigener Verbrauch, Echtzeit-Daten vom ESP32, Rechnungen als PDF
- **Live-Dashboard**: Öffentliche Ansicht der aggregierten Gemeinschaftsdaten (kein Login nötig)
- **Plattform-Admin**: Verwaltung aller EEGs, Benutzerverwaltung
- **PDF-Dokumente**: Bezugs-/Einspeisevereinbarungen und Rechnungen per LaTeX generiert
- **60-Tage-Korrekturfenster**: Gesetzlich vorgeschriebene Sperrfrist vor Abrechnungsfreigabe, hardcodiert

## Schnellstart

```bash
git clone https://github.com/ropperp/eeg-platform.git
cd eeg-platform
cp .env.example .env
# .env mit echten Werten befüllen (Domain, Passwörter, SMTP)
docker compose up -d
```

> Das allein reicht NICHT für einen funktionierenden ersten Login (Storage-Verzeichnisse,
> Datenbank-Migrations und Admin-Passwort fehlen dann noch) — unbedingt der vollständigen
> Anleitung folgen:

Detaillierte Anleitung → [SETUP.md](SETUP.md)

## Update (laufendes System)

```bash
git pull
docker compose up -d --build
```

## Dokumentation

| Datei | Inhalt |
|-------|--------|
| [SETUP.md](SETUP.md) | Schritt-für-Schritt-Installation auf einem neuen Gerät |
| [docs/PROJEKTSTAND.md](docs/PROJEKTSTAND.md) | Architektur, Schema, Fertigstellungsgrad |
| [database/init.sql](database/init.sql) | Vollständiges Datenbankschema |

## Tech-Stack

| Komponente | Technologie |
|------------|-------------|
| Datenbank | PostgreSQL 16 + TimescaleDB |
| Backend | PHP 8.2 (kein Framework, eigener Router) |
| Webserver | nginx (im selben Container wie PHP) |
| Reverse Proxy | Traefik v3 + Let's Encrypt |
| Session-Cache | Redis 7 |
| MQTT-Broker | Eclipse Mosquitto 2 |
| MQTT→DB | Python 3.12 (paho-mqtt + psycopg2) |
| EDA-Parser | Python 3.12 (pandas + openpyxl) |
| PDF-Service | Node.js 20 + pdflatex (TeX Live) |
| Container | Docker Compose |
| Zielplattform | Raspberry Pi 5 (läuft auch auf x86) |
