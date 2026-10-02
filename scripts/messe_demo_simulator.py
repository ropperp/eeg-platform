#!/usr/bin/env python3
"""
scripts/messe_demo_simulator.py -- publiziert realistische, tageszeitabhängig schwankende
Live-Messwerte für 20 fiktive Zählpunkte (8 Einspeiser, 12 Verbraucher) über MQTT, damit bei
einer Messe-/Diplomarbeits-Präsentation ein "guter Energiefluss" mit vielen Teilnehmern gezeigt
werden kann (Patrick, 02.10.2026) -- OHNE die Werte irgendwo im Frontend vorzutäuschen: es läuft
exakt derselbe Pfad wie bei einer echten ESP32-Ausleseeinheit (mqtt-subscriber ->
esp_measurements -> Energiefluss-Visualisierung/Live-Dashboard/öffentliche Live-Anzeige).

Tagesprofile (Patrick, 02.10.2026 Nachbesserung: "eine Einspeisung, die nicht in der Nacht,
sondern in den Sonnenstunden funktioniert [...] das Gleiche bei den Verbrauchern, vielleicht vor
allem um die Mittagszeit, um die Abendzeit und in der Nacht [...] konstanter oder leicht
schwankender Strom von [...] Licht oder Kühlschränken"):
  - Einspeiser: 0 W vor Sonnenaufgang (06:00) und nach Sonnenuntergang (20:00), dazwischen eine
    Sinuskurve mit Höchstwert am "Sonnenmittag" (13:00) -- siehe producer_envelope().
  - Verbraucher: nie 0 (Kühlschrank/Standby-Grundlast auch nachts), mit Buckeln am Morgen
    (~07:30), Mittag (~12:30) und vor allem Abend (~19:00) -- siehe consumer_envelope().
  Jeder Zählpunkt hat zusätzlich eine EIGENE Baseline ("ein paar höhere, ein paar niedrigere"),
  die Kurven skalieren nur relativ dazu.

EIN TAG DAUERT standardmäßig nur 20 simulierte Minuten (--day-length-minutes, Default 20) statt
24 echte Stunden -- an einem Messestand sieht man sonst nie Sonnenauf-/-untergang oder den
Abend-Verbrauchspeak. Mit --day-length-minutes 1440 läuft die simulierte Uhr 1:1 mit der echten
Zeit (dann startet sie bei der aktuellen Uhrzeit, siehe --start-hour zum Überschreiben).

EIN-/AUSSCHALTEN am Messestand: einfach dieses Skript starten bzw. mit Strg+C beenden -- mehr
nicht. Die 20 Zählpunkte müssen dafür nur EINMAL vorher angelegt werden (siehe
scripts/messe_demo_setup.php), danach kann dieses Skript beliebig oft gestartet/gestoppt werden,
ohne die DB erneut anzufassen. Nach dem Stoppen fallen die simulierten Werte automatisch
innerhalb von rund 2 Minuten wieder aus der Live-Summe/dem Energiefluss heraus (communityLivePower()
zählt nur Messungen der letzten 2 Minuten) -- kein Aufräumschritt nötig, um die Anzeige zwischen
zwei Vorführungen kurz "ruhig" zu stellen. NUR für das endgültige Entfernen der 20 Zählpunkte aus
Mitgliederlisten/Zählpunkt-Zählungen nach der Messe ist scripts/messe_demo_teardown.php nötig --
bis dahin zählen sie (auch im gestoppten Zustand) weiter als "registrierte Zählpunkte".

Voraussetzung: die 20 Zählpunkte müssen vorher in der DB existieren, siehe
scripts/messe_demo_setup.php (legt sie unter einem fiktiven, is_demo=true-Mitglied in der
angegebenen Community an -- die exakt gleichen Zählernummern wie unten in METER_DEFS).

Topic-/Payload-Format exakt wie die echte Firmware (siehe mqtt-subscriber/main.py):
  Topic:   eeg/{community-slug}/meter/{zaehlernummer}/live
  Payload: {"pp": <W Bezug>, "pm": <W Einspeisung>, "ep": <Wh Zählerstand Bezug>,
            "em": <Wh Zählerstand Einspeisung>, "znr": "<Zählernummer>"}

Abhängigkeit: nur `paho-mqtt` (kein DB-/Webapp-Zugriff nötig). Installieren falls nötig:
  pip install paho-mqtt

Beispiele:
  # Direkt auf dem Pi / im Docker-Netz (kein TLS, interner Hostname), 20-Minuten-Tagzyklus:
  python3 scripts/messe_demo_simulator.py --community stromfueralle --host mosquitto --port 1883 \
      --user "$MQTT_USER" --password "$MQTT_PASSWORD"

  # Von außerhalb (Messe-Laptop, über die öffentlich erreichbare TLS-Adresse, selbstsigniertes
  # Zertifikat wie beim ESP32 -- siehe docs/VORFAELLE.md, Abschnitt MQTT-Fernzugriff), Start
  # direkt in der simulierten Mittagszeit, damit man nicht erst auf die PV-Produktion warten muss:
  python3 scripts/messe_demo_simulator.py --community stromfueralle --host stromfueralle.at \
      --port 8883 --user eeg-device --password "..." --insecure --start-hour 12.5
"""

import argparse
import json
import math
import os
import random
import signal
import ssl
import sys
import time

import paho.mqtt.client as mqtt

# Muss exakt zu scripts/messe_demo_setup.php (messeMeterDefinitions()) passen -- dort ist die
# Zuordnung Zählernummer -> Zählpunkt in der DB angelegt. Baseline-Watt bewusst breit gestreut
# ("ein paar höhere, ein paar niedrigere", Patrick 02.10.2026) statt alle gleich -- die
# Tagesprofile unten (producer_envelope/consumer_envelope) skalieren relativ dazu.
PRODUCER_BASELINES_W = [600, 900, 1200, 1500, 1800, 2200, 2600, 3000]
CONSUMER_BASELINES_W = [80, 120, 150, 200, 250, 300, 350, 450, 550, 700, 900, 1200]

SUNRISE_H = 6.0
SUNSET_H = 20.0


def producer_envelope(hour: float) -> float:
    """0 vor Sonnenaufgang/nach Sonnenuntergang, Sinuskurve mit Maximum 1.0 am Sonnenmittag."""
    if hour <= SUNRISE_H or hour >= SUNSET_H:
        return 0.0
    x = math.pi * (hour - SUNRISE_H) / (SUNSET_H - SUNRISE_H)
    return math.sin(x)


def consumer_envelope(hour: float) -> float:
    """Nie 0 (Kühlschrank/Standby-Grundlast), mit Buckeln am Morgen/Mittag/Abend. Rückgabewert
    ist ein Vielfaches der Zählpunkt-Baseline, grob im Bereich 0.3 (tiefste Nacht) bis ~1.4
    (Abendspitze)."""
    def bump(center: float, width: float, amp: float) -> float:
        return amp * math.exp(-((hour - center) ** 2) / (2 * width ** 2))
    floor = 0.35
    return (
        floor
        + bump(7.5, 1.3, 0.25)   # Morgens: aufstehen, Frühstück, Kaffeemaschine
        + bump(12.5, 1.6, 0.30)  # Mittag: Kochen
        + bump(19.0, 2.0, 0.55)  # Abend: Kochen, Licht, Fernseher -- stärkste Spitze
    )


def meter_defs() -> list[dict]:
    defs = []
    for i, baseline in enumerate(PRODUCER_BASELINES_W, start=1):
        defs.append({"type": "producer", "meter_code": f"90000001{i:02d}", "baseline_w": baseline})
    for i, baseline in enumerate(CONSUMER_BASELINES_W, start=1):
        defs.append({"type": "consumer", "meter_code": f"90000002{i:02d}", "baseline_w": baseline})
    return defs


class VirtualMeter:
    """Ein einzelner simulierter Zählpunkt -- der aktuelle Leistungswert nähert sich bei jedem
    Tick exponentiell (Glättungsfaktor 0.3) dem tageszeitabhängigen Zielwert
    (baseline_w * Tagesprofil-Envelope) an, mit zusätzlichem kleinem Rauschen -- wirkt dadurch
    "lebendig" (sichtbares Schwanken), folgt aber erkennbar dem Tagesverlauf statt chaotisch zu
    springen. Energiezähler (ep/em) laufen parallel realistisch mit."""

    def __init__(self, meter_code: str, kind: str, baseline_w: float):
        self.meter_code = meter_code
        self.kind = kind  # "producer" | "consumer"
        self.baseline_w = baseline_w
        self.current_w = 0.0
        # Realistische, aber beliebige Start-Zählerstände (Wh) -- echte Zähler starten nie bei 0.
        self.energy_wh = random.randint(500_000, 5_000_000)

    def tick(self, interval_s: float, sim_hour: float) -> dict:
        envelope = producer_envelope(sim_hour) if self.kind == "producer" else consumer_envelope(sim_hour)
        target = self.baseline_w * envelope
        noise = random.gauss(0, max(target, self.baseline_w * 0.05) * 0.08)
        self.current_w = max(0.0, self.current_w + (target - self.current_w) * 0.3 + noise)

        w = round(self.current_w)
        self.energy_wh += round(w * interval_s / 3600)

        if self.kind == "producer":
            pp, pm = 0, w
        else:
            pp, pm = w, 0
        return {
            "pp": pp,
            "pm": pm,
            "ep": self.energy_wh if self.kind != "producer" else 0,
            "em": self.energy_wh if self.kind == "producer" else 0,
            "znr": self.meter_code,
        }


def load_dotenv_fallback(key: str) -> str | None:
    """Liest einen Wert aus der .env im Repo-Root, falls die Umgebungsvariable selbst nicht
    gesetzt ist -- praktisch, wenn das Skript direkt (ohne docker compose exec) z.B. auf dem Pi
    oder Patricks Laptop mit einer Kopie der .env daneben gestartet wird."""
    env_path = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), ".env")
    if not os.path.isfile(env_path):
        return None
    try:
        with open(env_path, encoding="utf-8") as f:
            for line in f:
                line = line.strip()
                if not line or line.startswith("#") or "=" not in line:
                    continue
                k, v = line.split("=", 1)
                if k.strip() == key:
                    return v.strip().strip('"').strip("'")
    except OSError:
        pass
    return None


def fmt_hour(h: float) -> str:
    h = h % 24
    return f"{int(h):02d}:{int((h % 1) * 60):02d}"


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--community", required=True, help="Community-Slug (siehe Ausgabe von messe_demo_setup.php)")
    parser.add_argument("--host", default=os.environ.get("MQTT_HOST", "mosquitto"))
    parser.add_argument("--port", type=int, default=int(os.environ.get("MQTT_PORT", "1883")))
    parser.add_argument("--user", default=os.environ.get("MQTT_USER") or load_dotenv_fallback("MQTT_USER"))
    parser.add_argument("--password", default=os.environ.get("MQTT_PASSWORD") or load_dotenv_fallback("MQTT_PASSWORD"))
    parser.add_argument("--insecure", action="store_true", help="TLS-Zertifikat nicht prüfen (selbstsigniert, wie beim ESP32 setInsecure())")
    parser.add_argument("--no-tls", action="store_true", help="Ohne TLS verbinden (z.B. intern im Docker-Netz auf Port 1883)")
    parser.add_argument("--interval", type=float, default=5.0, help="Sekunden zwischen zwei Messwerten je Zählpunkt (Default 5s, wie die echte Firmware)")
    parser.add_argument("--day-length-minutes", type=float, default=20.0,
                         help="Wie viele echte Minuten einem simulierten 24h-Tag entsprechen (Default 20 -- "
                              "voller Tag-/Nachtzyklus alle 20 Minuten, damit man am Messestand nicht auf "
                              "Sonnenauf-/untergang warten muss). 1440 = echtzeit-synchron.")
    parser.add_argument("--start-hour", type=float, default=None,
                         help="Simulierte Startzeit in Stunden, z.B. 12.5 = 12:30 (Default: aktuelle echte Uhrzeit)")
    args = parser.parse_args()

    if not args.user or not args.password:
        print("Fehler: --user/--password fehlen (auch nicht über MQTT_USER/MQTT_PASSWORD bzw. .env gefunden).", file=sys.stderr)
        sys.exit(1)

    meters = [VirtualMeter(d["meter_code"], d["type"], d["baseline_w"]) for d in meter_defs()]
    start_hour = args.start_hour if args.start_hour is not None else (time.localtime().tm_hour + time.localtime().tm_min / 60)
    print(f"{len(meters)} simulierte Zählpunkte ({len([m for m in meters if m.kind == 'producer'])} Einspeiser, "
          f"{len([m for m in meters if m.kind == 'consumer'])} Verbraucher) für Community '{args.community}'.")
    print(f"Simulierter Tag dauert {args.day_length_minutes:.0f} echte Minuten, Start bei {fmt_hour(start_hour)} Uhr.")

    client = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2, client_id="messe-demo-simulator-" + str(random.randint(1000, 9999)))
    client.username_pw_set(args.user, args.password)
    if not args.no_tls:
        client.tls_set(cert_reqs=ssl.CERT_NONE if args.insecure else ssl.CERT_REQUIRED)
        if args.insecure:
            client.tls_insecure_set(True)
    client.connect(args.host, args.port, keepalive=30)
    client.loop_start()

    # Direkt beim Start einmal "online" melden (status-Topic), damit die Zählpunkte nicht erst
    # auf den ersten Live-Tick warten müssen, um in der "ESP online"-Zählung aufzutauchen --
    # genau wie eine echte Firmware das beim Booten tut.
    for m in meters:
        topic = f"eeg/{args.community}/meter/{m.meter_code}/status"
        client.publish(topic, json.dumps({"status": "online", "fw": "messe-sim"}), qos=1, retain=True)

    stop = False

    def handle_sigint(_sig, _frame):
        nonlocal stop
        stop = True

    signal.signal(signal.SIGINT, handle_sigint)
    signal.signal(signal.SIGTERM, handle_sigint)

    print("Simulation läuft -- Strg+C zum Beenden.")
    start_real = time.monotonic()
    tick_n = 0
    try:
        while not stop:
            elapsed_s = time.monotonic() - start_real
            sim_hour = (start_hour + elapsed_s / (args.day_length_minutes * 60) * 24) % 24

            total_prod_w = total_cons_w = 0
            for m in meters:
                payload = m.tick(args.interval, sim_hour)
                topic = f"eeg/{args.community}/meter/{m.meter_code}/live"
                client.publish(topic, json.dumps(payload), qos=0)
                if m.kind == "producer":
                    total_prod_w += payload["pm"]
                else:
                    total_cons_w += payload["pp"]
            tick_n += 1
            if tick_n % 6 == 0:  # ca. alle 30s (bei Default-Intervall 5s) eine Statuszeile
                print(f"[simuliert {fmt_hour(sim_hour)}] Einspeisung gesamt: {total_prod_w} W -- Bezug gesamt: {total_cons_w} W")
            time.sleep(args.interval)
    finally:
        client.loop_stop()
        client.disconnect()
        print("\nBeendet, MQTT-Verbindung geschlossen.")


if __name__ == "__main__":
    main()
