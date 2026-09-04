#!/usr/bin/env python3
"""
Kia2Lox - fragt den Ladezustand ueber Kia Connect ab und sendet die Werte
per UDP an den konfigurierten Loxone Miniserver.

Wird regelmaessig per Cron (cron/cron.30min) aufgerufen. Das Abfrageintervall
selbst wird spaeter ueber die Plugin-Einstellungen gesteuert.
"""

import argparse
import datetime
import json
import os
import socket
import sys

from hyundai_kia_connect_api import VehicleManager

REGION_EUROPE = 1
BRAND_KIA_ID = 1

PLUGIN_FOLDER = "kia2lox"
PCONFIG_BASE = os.environ.get("LBPCONFIG", "/opt/loxberry/config/plugins")
CONFIG_PATH = os.path.join(PCONFIG_BASE, PLUGIN_FOLDER, "pluginconfig.json")


def load_config() -> dict:
    if not os.path.exists(CONFIG_PATH):
        print(f"FEHLER: Konfigurationsdatei nicht gefunden: {CONFIG_PATH}")
        sys.exit(1)
    with open(CONFIG_PATH, "r", encoding="utf-8") as f:
        return json.load(f)


def send_udp(ip: str, port: int, message: str) -> None:
    with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as sock:
        sock.sendto(message.encode("utf-8"), (ip, port))
    print(f"  UDP gesendet an {ip}:{port} -> {message}")


def main() -> None:
    parser = argparse.ArgumentParser(description="Kia2Lox Ladezustand abfragen und per UDP senden")
    parser.add_argument("--force", action="store_true", help="Frisches Update vom Fahrzeug anfordern")
    args = parser.parse_args()

    config = load_config()

    now = datetime.datetime.now().isoformat(timespec="seconds")
    mode = "force" if args.force else "passiv"
    print(f"[{now}] Kia2Lox Abfrage startet ({mode})")

    manager = VehicleManager(
        region=REGION_EUROPE,
        brand=BRAND_KIA_ID,
        username=config["kia_username"],
        password=config["kia_password"],
        pin=config.get("kia_pin", ""),
    )

    login_result = manager.login()
    if login_result is not True:
        print(f"FEHLER: Login benoetigt zusaetzliche Bestaetigung (OTP/2FA): {login_result}")
        sys.exit(1)

    udp_ip = config["udp_target_ip"]
    udp_port = int(config["udp_target_port"])

    for vehicle_id in manager.vehicles:
        if args.force:
            manager.force_refresh_vehicle_state(vehicle_id)
        else:
            manager.update_vehicle_with_cached_state(vehicle_id)

        vehicle = manager.get_vehicle(vehicle_id)

        soc = vehicle.ev_battery_percentage
        range_km = round(vehicle.ev_driving_range) if vehicle.ev_driving_range is not None else None
        charging = 1 if vehicle.ev_battery_is_charging else 0
        plugged = vehicle.ev_battery_is_plugged_in

        print(
            f"  {vehicle.name}: SOC={soc}% RANGE={range_km}km "
            f"CHARGING={charging} PLUGGED={plugged} (Stand: {vehicle.last_updated_at})"
        )

        send_udp(udp_ip, udp_port, f"SOC={soc}")
        send_udp(udp_ip, udp_port, f"RANGE={range_km}")
        send_udp(udp_ip, udp_port, f"CHARGING={charging}")
        send_udp(udp_ip, udp_port, f"PLUGGED={plugged}")

    print("Fertig.")


if __name__ == "__main__":
    main()
