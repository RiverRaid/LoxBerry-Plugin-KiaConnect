"""
Eigenstaendiges Testscript ausserhalb des LoxBerry-Plugins: Ladezustand
eines einzelnen Kia-e-Autos ueber Kia Connect abfragen und die Werte per
UDP an den Loxone Miniserver senden. Nuetzlich, um die Kia-Connect-
Zugangsdaten und die eigene Netzwerkverbindung schnell zu pruefen, ohne
das Plugin zu installieren. Das echte Plugin (bin/kia2lox_poll.py)
unterstuetzt zusaetzlich mehrere Fahrzeuge, Abfrage-Intervalle und
Batterie-Zustandswarnungen.

Vor dem ersten Start:
1. Datei "config.example.json" kopieren und in "config.json" umbenennen
2. In "config.json" Benutzername, Passwort, Ziel-IP und Ziel-Port eintragen
   (pin kann normalerweise leer bleiben "")

Aufruf:
    python test_battery.py            -> liest nur die zuletzt vom Auto gemeldeten Werte (passiv)
    python test_battery.py --force    -> fordert vom Auto zusaetzlich ein frisches Update an
                                          (weckt das Auto, nur sparsam verwenden!)
"""

import argparse
import json
import os
import socket
import sys

from hyundai_kia_connect_api import VehicleManager
from hyundai_kia_connect_api.const import REGIONS, BRANDS

REGION_EUROPE = 1
BRAND_KIA_ID = 1

CONFIG_PATH = os.path.join(os.path.dirname(__file__), "config.json")


def load_config() -> dict:
    if not os.path.exists(CONFIG_PATH):
        print(f"FEHLER: Konfigurationsdatei nicht gefunden: {CONFIG_PATH}")
        print('Bitte "config.example.json" kopieren, in "config.json" umbenennen und ausfuellen.')
        sys.exit(1)

    with open(CONFIG_PATH, "r", encoding="utf-8") as f:
        return json.load(f)


def send_udp(ip: str, port: int, message: str) -> None:
    with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as sock:
        sock.sendto(message.encode("utf-8"), (ip, port))
    print(f"  UDP gesendet an {ip}:{port} -> {message}")


def main() -> None:
    parser = argparse.ArgumentParser(description="Kia Connect Ladezustand abfragen und per UDP senden")
    parser.add_argument(
        "--force",
        action="store_true",
        help="Frisches Update vom Fahrzeug anfordern (weckt das Auto)",
    )
    args = parser.parse_args()

    config = load_config()

    print(f"Region: {REGIONS[REGION_EUROPE]}, Marke: {BRANDS[BRAND_KIA_ID]}")
    print("Verbinde mit Kia Connect ...")

    manager = VehicleManager(
        region=REGION_EUROPE,
        brand=BRAND_KIA_ID,
        username=config["username"],
        password=config["password"],
        pin=config.get("pin", ""),
    )

    login_result = manager.login()
    if login_result is not True:
        print("FEHLER: Login benoetigt zusaetzliche Bestaetigung (OTP/2FA).")
        print(f"Details: {login_result}")
        sys.exit(1)

    print(f"Login erfolgreich. Gefundene Fahrzeuge: {len(manager.vehicles)}")

    udp_ip = config["udp_target_ip"]
    udp_port = int(config["udp_target_port"])

    for vehicle_id in manager.vehicles:
        if args.force:
            print("Fordere frisches Update vom Fahrzeug an (Force Refresh) ...")
            manager.force_refresh_vehicle_state(vehicle_id)
        else:
            manager.update_vehicle_with_cached_state(vehicle_id)

        vehicle = manager.get_vehicle(vehicle_id)

        soc = vehicle.ev_battery_percentage
        range_km = round(vehicle.ev_driving_range) if vehicle.ev_driving_range is not None else None
        charging = 1 if vehicle.ev_battery_is_charging else 0
        plugged = vehicle.ev_battery_is_plugged_in

        print("")
        print(f"--- Fahrzeug: {vehicle.name} ({vehicle.model}) ---")
        print(f"Ladezustand (SoC):     {soc} %")
        print(f"Reichweite (elektr.):  {range_km} {vehicle.ev_driving_range_unit}")
        print(f"Laedt gerade:          {charging}")
        print(f"Steckerstatus (roh):   {plugged}  (0=nicht eingesteckt, vermutlich 1=AC/2=DC)")
        print(f"Letztes Update:        {vehicle.last_updated_at}")

        print("")
        print("Sende Werte per UDP ...")
        send_udp(udp_ip, udp_port, f"SOC={soc}")
        send_udp(udp_ip, udp_port, f"RANGE={range_km}")
        send_udp(udp_ip, udp_port, f"CHARGING={charging}")
        send_udp(udp_ip, udp_port, f"PLUGGED={plugged}")


if __name__ == "__main__":
    main()
