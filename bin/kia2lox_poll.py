#!/usr/bin/env python3
"""
Kia2Lox - fragt fuer alle konfigurierten Fahrzeuge den Ladezustand ueber
Kia Connect ab und sendet die Werte per UDP an den jeweils hinterlegten
Loxone Miniserver.

Wird regelmaessig per Cron (cron/cron.30min) aufgerufen. Welches Fahrzeug
wie oft tatsaechlich abgefragt wird, steuert spaeter jedes Fahrzeug selbst
ueber sein eigenes Intervall in den Plugin-Einstellungen.
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

# Ab diesem Wert gilt der Akku als "voll" (100%, mit etwas Toleranz fuer
# Rundung/leichte Messschwankungen).
FULL_SOC_THRESHOLD = 99

# Wie lange der Akku ununterbrochen "voll" gemessen werden muss, bevor
# FULL=1 gesendet wird (unabhaengig davon, ob noch eingesteckt/am Laden).
FULL_HOURS = 3

# Wie lange das Fahrzeug zusaetzlich eingesteckt und nicht mehr ladend bei
# vollem Akku stehen darf, bevor FULLPARKED=1 gesendet wird.
FULL_PARKED_HOURS = 3

# Nach wie vielen Tagen ohne vollen Ladezustand RECHARGE100=1 gesendet wird
# (Empfehlung zum Zellausgleich).
RECHARGE_REMINDER_DAYS = 30

PLUGIN_FOLDER = "kia2lox"
PCONFIG_BASE = os.environ.get("LBPCONFIG", "/opt/loxberry/config/plugins")
PDATA_BASE = os.environ.get("LBPDATA", "/opt/loxberry/data/plugins")
CONFIG_PATH = os.path.join(PCONFIG_BASE, PLUGIN_FOLDER, "pluginconfig.json")
STATE_PATH = os.path.join(PDATA_BASE, PLUGIN_FOLDER, "state.json")


def load_config() -> dict:
    if not os.path.exists(CONFIG_PATH):
        print(f"FEHLER: Konfigurationsdatei nicht gefunden: {CONFIG_PATH}")
        sys.exit(1)
    with open(CONFIG_PATH, "r", encoding="utf-8") as f:
        config = json.load(f)
    if "vehicles" not in config or not isinstance(config["vehicles"], list):
        print('FEHLER: Konfigurationsdatei enthaelt keine Liste "vehicles"')
        sys.exit(1)
    return config


def load_state() -> dict:
    if not os.path.exists(STATE_PATH):
        return {}
    with open(STATE_PATH, "r", encoding="utf-8") as f:
        return json.load(f)


def save_state(state: dict) -> None:
    os.makedirs(os.path.dirname(STATE_PATH), exist_ok=True)
    with open(STATE_PATH, "w", encoding="utf-8") as f:
        json.dump(state, f, indent=2)


def get_vehicle_state(state: dict, vehicle_id: str) -> dict:
    return state.setdefault(
        vehicle_id,
        {"last_full_charge_at": None, "full_since": None, "full_soc_since": None},
    )


def update_battery_health_state(vstate: dict, soc: int, plugged, charging: int, now: datetime.datetime) -> tuple[int, int, int]:
    """Aktualisiert den Zustand fuer die Batteriepflege-Hinweise eines
    einzelnen Fahrzeugs und gibt (full, full_parked, recharge_needed) als
    0/1 zurueck.

    full:            Akku misst seit FULL_HOURS ununterbrochen >= 100%,
                      unabhaengig von Stecker-/Ladestatus.
    full_parked:     zusaetzlich seit FULL_PARKED_HOURS eingesteckt und
                      nicht mehr ladend (klassisches "steht auf dem
                      Ladegeraet voll herum").
    recharge_needed: seit RECHARGE_REMINDER_DAYS keinen vollen Ladestand
                      mehr erreicht (Zellausgleich empfohlen).
    """

    is_full = soc is not None and soc >= FULL_SOC_THRESHOLD
    is_idle_full = is_full and bool(plugged) and not charging

    if is_full:
        vstate["last_full_charge_at"] = now.isoformat()
        if not vstate.get("full_soc_since"):
            vstate["full_soc_since"] = now.isoformat()
    else:
        vstate["full_soc_since"] = None

    if is_idle_full:
        if not vstate.get("full_since"):
            vstate["full_since"] = now.isoformat()
    else:
        vstate["full_since"] = None

    full = 0
    if vstate.get("full_soc_since"):
        full_soc_since = datetime.datetime.fromisoformat(vstate["full_soc_since"])
        if now - full_soc_since >= datetime.timedelta(hours=FULL_HOURS):
            full = 1

    full_parked = 0
    if vstate.get("full_since"):
        full_since = datetime.datetime.fromisoformat(vstate["full_since"])
        if now - full_since >= datetime.timedelta(hours=FULL_PARKED_HOURS):
            full_parked = 1

    recharge_needed = 0
    last_full = vstate.get("last_full_charge_at")
    if last_full is None:
        recharge_needed = 1
    else:
        last_full_dt = datetime.datetime.fromisoformat(last_full)
        if now - last_full_dt >= datetime.timedelta(days=RECHARGE_REMINDER_DAYS):
            recharge_needed = 1

    return full, full_parked, recharge_needed


def send_udp(ip: str, port: int, message: str) -> None:
    with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as sock:
        sock.sendto(message.encode("utf-8"), (ip, port))
    print(f"  UDP gesendet an {ip}:{port} -> {message}")


def poll_vehicle_config(vehicle_config: dict, vstate: dict, force: bool, now: datetime.datetime) -> None:
    label = vehicle_config.get("name", vehicle_config.get("id", "?"))

    manager = VehicleManager(
        region=REGION_EUROPE,
        brand=BRAND_KIA_ID,
        username=vehicle_config["kia_username"],
        password=vehicle_config["kia_password"],
        pin=vehicle_config.get("kia_pin", ""),
    )

    login_result = manager.login()
    if login_result is not True:
        print(f"FEHLER [{label}]: Login benoetigt zusaetzliche Bestaetigung (OTP/2FA): {login_result}")
        return

    udp_ip = vehicle_config["udp_target_ip"]
    udp_port = int(vehicle_config["udp_target_port"])

    for kia_vehicle_id in manager.vehicles:
        if force:
            manager.force_refresh_vehicle_state(kia_vehicle_id)
        else:
            manager.update_vehicle_with_cached_state(kia_vehicle_id)

        vehicle = manager.get_vehicle(kia_vehicle_id)

        soc = vehicle.ev_battery_percentage
        range_km = round(vehicle.ev_driving_range) if vehicle.ev_driving_range is not None else None
        charging = 1 if vehicle.ev_battery_is_charging else 0
        plugged = vehicle.ev_battery_is_plugged_in

        full, full_parked, recharge_needed = update_battery_health_state(vstate, soc, plugged, charging, now)

        print(
            f"  [{label}] {vehicle.name}: SOC={soc}% RANGE={range_km}km "
            f"CHARGING={charging} PLUGGED={plugged} "
            f"FULL={full} FULLPARKED={full_parked} RECHARGE100={recharge_needed} "
            f"(Stand: {vehicle.last_updated_at})"
        )

        send_udp(udp_ip, udp_port, f"SOC={soc}")
        send_udp(udp_ip, udp_port, f"RANGE={range_km}")
        send_udp(udp_ip, udp_port, f"CHARGING={charging}")
        send_udp(udp_ip, udp_port, f"PLUGGED={plugged}")
        send_udp(udp_ip, udp_port, f"FULL={full}")
        send_udp(udp_ip, udp_port, f"FULLPARKED={full_parked}")
        send_udp(udp_ip, udp_port, f"RECHARGE100={recharge_needed}")


def main() -> None:
    parser = argparse.ArgumentParser(description="Kia2Lox Ladezustand abfragen und per UDP senden")
    parser.add_argument("--force", action="store_true", help="Frisches Update vom Fahrzeug anfordern")
    parser.add_argument("--vehicle", help="Nur dieses eine Fahrzeug abfragen (id aus pluginconfig.json)")
    args = parser.parse_args()

    config = load_config()
    state = load_state()

    now = datetime.datetime.now().astimezone()
    mode = "force" if args.force else "passiv"
    print(f"[{now.isoformat(timespec='seconds')}] Kia2Lox Abfrage startet ({mode})")

    vehicles = config["vehicles"]
    if args.vehicle:
        vehicles = [v for v in vehicles if v.get("id") == args.vehicle]
        if not vehicles:
            print(f"FEHLER: Kein Fahrzeug mit id={args.vehicle} in der Konfiguration gefunden")
            sys.exit(1)

    if not vehicles:
        print("Keine Fahrzeuge konfiguriert, nichts zu tun.")
        return

    for vehicle_config in vehicles:
        vehicle_id = vehicle_config.get("id")
        if not vehicle_id:
            print(f"FEHLER: Fahrzeug ohne id in der Konfiguration uebersprungen: {vehicle_config.get('name')}")
            continue
        vstate = get_vehicle_state(state, vehicle_id)
        try:
            poll_vehicle_config(vehicle_config, vstate, args.force, now)
        except Exception as exc:  # noqa: BLE001 - ein Fahrzeug darf die anderen nicht blockieren
            label = vehicle_config.get("name", vehicle_id)
            print(f"FEHLER [{label}]: Abfrage fehlgeschlagen: {exc}")

    save_state(state)
    print("Fertig.")


if __name__ == "__main__":
    main()
