#!/usr/bin/env python3
"""
scripts/messe_demo_simulator.py -- publiziert realistische, schwankende Live-Messwerte für
20 fiktive Zählpunkte (8 Einspeiser, 12 Verbraucher) über MQTT, damit bei einer Messe-/
Diplomarbeits-Präsentation ein "guter Energiefluss" mit vielen Teilnehmern gezeigt werden kann
(Patrick, 02.10.2026) -- OHNE die Werte irgendwo im Frontend vorzutäuschen: es läuft exakt
derselbe Pfad wie bei einer echten ESP32-Ausleseeinheit (mqtt-subscriber -> esp_measurements ->
Energiefluss-Visualisierung/Live-Dashboard/öffentliche Live-Anzeige).

Voraussetzung: die 20 Zählpunkte müssen vorher in der DB existieren, siehe
scripts/messe_demo_setup.php (legt sie unter einem fiktiven, is_demo=true-Mitglied in der
angegebenen Community an -- die exakt gleichen Zählernummern wie unten in METER_DEFS). Nach der
Messe scripts/messe_demo_teardown.php nicht vergessen.

Topic-/Payload-Format exakt wie die echte Firmware (siehe mqtt-subscriber/main.py):
  Topic:   eeg/{community-slug}/meter/{zaehlernummer}/live
  Payload: {"pp": <W Bezug>, "pm": <W Einspeisung>, "ep": <Wh Zählerstand Bezug>,
            "em": <Wh Zählerstand Einspeisung>, "znr": "<Zählernummer>"}

Abhängigkeit: nur `paho-mqtt` (kein DB-/Webapp-Zugriff nötig). Installieren falls nötig:
  pip install paho-mqtt

Beispiele:
  # Direkt auf dem Pi / im Docker-Netz (kein TLS, interner Hostname):
  python3 scripts/messe_demo_simulator.py --community stromfueralle --host mosquitto --port 1883 \
      --user "$MQTT_USER" --password "$MQTT_PASSWORD"

  # Von außerhalb (Messe-Laptop, über die öffentlich erreichbare TLS-Adresse, selbstsigniertes
  # Zertifikat wie beim ESP32 -- siehe docs/VORFAELLE.md, Abschnitt MQTT-Fernzugriff):
  python3 scripts/messe_demo_simulator.py --community stromfueralle --host stromfueralle.at \
      --port 8883 --user eeg-device --password "..." --insecure
"""

import argparse
import json
import os
import random
import signal
import ssl
import sys
import time

import paho.mqtt.client as mqtt

# Muss exakt zu scripts/messe_demo_setup.php (messeMeterDefinitions()) passen -- dort ist die
# Zuordnung Zählernummer -> Zählpunkt in der DB angelegt. Baseline-Watt bewusst breit gestreut
# ("ein paar höhere, ein paar niedrigere", Patrick 02.10.2026) statt alle gleich.
PRODUCER_BASELINES_W = [600, 900, 1200, 1500, 1800, 2200, 2600, 3000]
CONSUMER_BASELINES_W = [80, 120, 150, 200, 250, 300, 350, 450, 550, 700, 900, 1200]


def meter_defs() -> list[dict]:
    defs = []
    for i, baseline in enumerate(PRODUCER_BASELINES_W, start=1):
        defs.append({
            "type": "producer",
            "meter_code": f"90000001{i:02d}",
            "baseline_w": baseline,
        })
    for i, baseline in enumerate(CONSUMER_BASELINES_W, start=1):
        defs.append({
            "type": "consumer",
            "meter_code": f"90000002{i:02d}",
            "baseline_w": baseline,
        })
    return defs


class VirtualMeter:
    """Ein einzelner simulierter Zählpunkt -- hält seinen aktuellen Leistungswert als langsamen,
    begrenzten Random-Walk um die eigene Baseline (wirkt dadurch "lebendig", ohne auf die
    tatsächliche Tageszeit/Sonnenstand angewiesen zu sein -- an einem Messestand egal, ob
    gerade Tag oder Abend ist). Energiezähler (ep/em) laufen parallel realistisch mit."""

    def __init__(self, meter_code: str, kind: str, baseline_w: float):
        self.meter_code = meter_code
        self.kind = kind  # "producer" | "consumer"
        self.baseline_w = baseline_w
        self.current_w = baseline_w
        # Realistische, aber beliebige Start-Zählerstände (Wh) -- echte Zähler starten nie bei 0.
        self.energy_wh = random.randint(500_000, 5_000_000)

    def tick(self, interval_s: float) -> dict:
        # Begrenzter Random-Walk: kleine Schritte, gelegentlich etwas größer (Wolke/Verbraucher
        # an-/ausschalten), geklammert auf 25%-170% der eigenen Baseline -- bleibt dadurch immer
        # im plausiblen Bereich für diesen Zählpunkt, wandert aber sichtbar.
        step = random.gauss(0, self.baseline_w * 0.06)
        if random.random() < 0.05:
            step += random.choice([-1, 1]) * self.baseline_w * random.uniform(0.2, 0.5)
        self.current_w = max(self.baseline_w * 0.25, min(self.baseline_w * 1.7, self.current_w + step))

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
    args = parser.parse_args()

    if not args.user or not args.password:
        print("Fehler: --user/--password fehlen (auch nicht über MQTT_USER/MQTT_PASSWORD bzw. .env gefunden).", file=sys.stderr)
        sys.exit(1)

    meters = [VirtualMeter(d["meter_code"], d["type"], d["baseline_w"]) for d in meter_defs()]
    print(f"{len(meters)} simulierte Zählpunkte ({len([m for m in meters if m.kind == 'producer'])} Einspeiser, "
          f"{len([m for m in meters if m.kind == 'consumer'])} Verbraucher) für Community '{args.community}'.")

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
    tick_n = 0
    try:
        while not stop:
            total_prod_w = total_cons_w = 0
            for m in meters:
                payload = m.tick(args.interval)
                topic = f"eeg/{args.community}/meter/{m.meter_code}/live"
                client.publish(topic, json.dumps(payload), qos=0)
                if m.kind == "producer":
                    total_prod_w += payload["pm"]
                else:
                    total_cons_w += payload["pp"]
            tick_n += 1
            if tick_n % 6 == 0:  # ca. alle 30s (bei Default-Intervall 5s) eine Statuszeile
                print(f"[{time.strftime('%H:%M:%S')}] Einspeisung gesamt: {total_prod_w} W -- Bezug gesamt: {total_cons_w} W")
            time.sleep(args.interval)
    finally:
        client.loop_stop()
        client.disconnect()
        print("\nBeendet, MQTT-Verbindung geschlossen.")


if __name__ == "__main__":
    main()
