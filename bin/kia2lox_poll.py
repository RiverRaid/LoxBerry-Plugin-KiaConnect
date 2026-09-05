#!/usr/bin/env python3
"""
Kia2Lox - fragt fuer alle konfigurierten Fahrzeuge den Ladezustand ueber
Kia Connect ab und sendet die Werte per UDP an den jeweils hinterlegten
Loxone Miniserver.

Wird alle 5 Minuten per Cron (cron/cron.05min) aufgerufen. Ob bei einem
Durchlauf tatsaechlich abgefragt wird - und ob passiv (gecachter Stand)
oder per Force-Refresh (weckt das Fahrzeug) - entscheidet jedes Fahrzeug
selbst anhand seiner eigenen Einstellungen (siehe should_poll_passive_now()
und should_force_refresh_now()).
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

# Standardwerte fuer die Batteriepflege-Schwellwerte, falls ein Fahrzeug
# (z.B. nach einem Upgrade von einer aelteren Version) noch keine eigenen
# Werte in der pluginconfig.json hinterlegt hat. Der Benutzer kann diese
# Werte pro Fahrzeug in den Einstellungen ("Warnungen") anpassen.
DEFAULT_FULL_SOC_THRESHOLD = 99
DEFAULT_FULL_HOURS = 3
DEFAULT_FULL_PARKED_HOURS = 3
DEFAULT_RECHARGE_REMINDER_DAYS = 30
DEFAULT_LOW_SOC_THRESHOLD = 10
DEFAULT_LOW_BATTERY_HOURS = 3

# Wie lange der SOC-Verlauf fuer das Uebersichts-Diagramm aufgehoben wird.
HISTORY_RETENTION_DAYS = 90

PLUGIN_FOLDER = "kia2lox"
PCONFIG_BASE = os.environ.get("LBPCONFIG", "/opt/loxberry/config/plugins")
PDATA_BASE = os.environ.get("LBPDATA", "/opt/loxberry/data/plugins")
CONFIG_PATH = os.path.join(PCONFIG_BASE, PLUGIN_FOLDER, "pluginconfig.json")
STATE_PATH = os.path.join(PDATA_BASE, PLUGIN_FOLDER, "state.json")
PLUGINDATABASE_PATH = "/opt/loxberry/data/system/plugindatabase.json"

# Schwellwerte wie in LoxBerrys eigenem Log-System (loxberry_log.php):
# eine Zeile wird nur geschrieben, wenn der in der Pluginverwaltung
# eingestellte Log-Level (0=nur Notfaelle ... 7=Debug) groesser als der
# hier hinterlegte Schwellwert ist.
LOG_LEVEL_THRESHOLDS = {"DEBUG": 6, "INFO": 5, "OK": 4, "WARNING": 3, "ERROR": 2, "CRITICAL": 1}

# Werden einmalig zu Beginn von main() gesetzt und danach von log() gelesen.
LOG_LEVEL = 6
EXPLICIT_RUN = False


def load_loglevel() -> int:
    """Liest den in der LoxBerry-Pluginverwaltung fuer dieses Plugin
    eingestellten Log-Level aus der zentralen Plugin-Datenbank. Kann die
    Datei nicht gelesen/geparst werden (z.B. andere Rechte), wird auf 6
    (INFO) zurueckgefallen."""

    try:
        with open(PLUGINDATABASE_PATH, "r", encoding="utf-8") as f:
            db = json.load(f)
        plugins = db.get("plugins", db) if isinstance(db, dict) else {}
        for entry in plugins.values():
            if isinstance(entry, dict) and entry.get("folder") == PLUGIN_FOLDER:
                return int(entry.get("loglevel", 6))
    except (OSError, ValueError, TypeError, json.JSONDecodeError):
        pass
    return 6


def log(level: str, msg: str) -> None:
    """Schreibt eine Zeile mit LoxBerry-typischem Level-Tag (<OK>, <INFO>,
    <WARNING>, <ERROR>, <CRITICAL>) nach stdout - landet je nach Aufrufer
    entweder ueber den Cron-Wrapper oder den manuellen/HTTP-Trigger-Aufruf
    in poll.log. Bei einem regulaeren Cron-Durchlauf wird dabei der
    eingestellte Log-Level beachtet und leisere Zeilen unterdrueckt.
    Explizite Aufrufe (Force-Refresh-Button, HTTP-Trigger) protokollieren
    dagegen immer alles, damit inc_vehicles.php::kia2lox_manual_refresh()
    Fehler zuverlaessig an ihrem <ERROR>/<CRITICAL>-Tag erkennt, unabhaengig
    vom eingestellten Log-Level."""

    if not EXPLICIT_RUN and LOG_LEVEL <= LOG_LEVEL_THRESHOLDS[level]:
        return
    print(f"<{level}> {msg}")


def history_path(vehicle_id: str) -> str:
    return os.path.join(PDATA_BASE, PLUGIN_FOLDER, f"history_{vehicle_id}.jsonl")


def append_history(vehicle_id: str, now: datetime.datetime, soc, charging=None, plugged=None) -> None:
    """Haengt einen Messpunkt (SOC + Lade-/Steckerstatus) an den Verlauf
    des Fahrzeugs an (fuer das Ladezustands-Diagramm in der Uebersicht,
    das die Punkte je nach Status faerbt) und entfernt dabei gleich
    Eintraege, die aelter als HISTORY_RETENTION_DAYS sind."""

    if soc is None:
        return

    path = history_path(vehicle_id)
    cutoff = now - datetime.timedelta(days=HISTORY_RETENTION_DAYS)
    entries = []
    if os.path.exists(path):
        with open(path, "r", encoding="utf-8") as f:
            for line in f:
                line = line.strip()
                if not line:
                    continue
                try:
                    entry = json.loads(line)
                    entry_at = datetime.datetime.fromisoformat(entry["at"])
                except (ValueError, KeyError, TypeError):
                    continue
                if entry_at >= cutoff:
                    entries.append(entry)

    entries.append({
        "at": now.isoformat(),
        "soc": soc,
        "charging": charging,
        "plugged": plugged,
    })

    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, "w", encoding="utf-8") as f:
        for entry in entries:
            f.write(json.dumps(entry) + "\n")


def load_config() -> dict:
    if not os.path.exists(CONFIG_PATH):
        log("CRITICAL", f"Konfigurationsdatei nicht gefunden: {CONFIG_PATH}")
        sys.exit(1)
    with open(CONFIG_PATH, "r", encoding="utf-8") as f:
        config = json.load(f)
    if "vehicles" not in config or not isinstance(config["vehicles"], list):
        log("CRITICAL", 'Konfigurationsdatei enthaelt keine Liste "vehicles"')
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
        {
            "last_full_charge_at": None,
            "full_since": None,
            "full_soc_since": None,
            "low_since": None,
            "last_passive_poll_at": None,
            "poll_log": [],
        },
    )


def update_battery_health_state(vehicle_config: dict, vstate: dict, soc: int, plugged, charging: int, now: datetime.datetime) -> tuple[int, int, int, int]:
    """Aktualisiert den Zustand fuer die Batteriepflege-Hinweise eines
    einzelnen Fahrzeugs und gibt (full, full_parked, recharge_needed,
    low_battery) als 0/1 zurueck. Die Schwellwerte kommen pro Fahrzeug aus
    vehicle_config (vom Benutzer in den Einstellungen -> "Warnungen"
    einstellbar), mit Fallback auf die DEFAULT_*-Werte.

    full:            Akku misst seit full_hours ununterbrochen >= full_soc_threshold,
                      unabhaengig von Stecker-/Ladestatus.
    full_parked:     zusaetzlich seit full_parked_hours eingesteckt und
                      nicht mehr ladend (klassisches "steht auf dem
                      Ladegeraet voll herum").
    recharge_needed: seit recharge_reminder_days keinen vollen Ladestand
                      mehr erreicht (Zellausgleich empfohlen).
    low_battery:     Akku ist seit low_battery_hours ununterbrochen unter
                      low_soc_threshold und laedt dabei nicht (Fahrzeug
                      wurde mit niedrigem Akku stehen gelassen).
    """

    full_soc_threshold = int(vehicle_config.get("full_soc_threshold", DEFAULT_FULL_SOC_THRESHOLD) or DEFAULT_FULL_SOC_THRESHOLD)
    full_hours = int(vehicle_config.get("full_hours", DEFAULT_FULL_HOURS) or DEFAULT_FULL_HOURS)
    full_parked_hours = int(vehicle_config.get("full_parked_hours", DEFAULT_FULL_PARKED_HOURS) or DEFAULT_FULL_PARKED_HOURS)
    recharge_reminder_days = int(vehicle_config.get("recharge_reminder_days", DEFAULT_RECHARGE_REMINDER_DAYS) or DEFAULT_RECHARGE_REMINDER_DAYS)
    low_soc_threshold = int(vehicle_config.get("low_soc_threshold", DEFAULT_LOW_SOC_THRESHOLD) or DEFAULT_LOW_SOC_THRESHOLD)
    low_battery_hours = int(vehicle_config.get("low_battery_hours", DEFAULT_LOW_BATTERY_HOURS) or DEFAULT_LOW_BATTERY_HOURS)

    is_full = soc is not None and soc >= full_soc_threshold
    is_idle_full = is_full and bool(plugged) and not charging
    is_low_idle = soc is not None and soc < low_soc_threshold and not charging

    if is_low_idle:
        if not vstate.get("low_since"):
            vstate["low_since"] = now.isoformat()
    else:
        vstate["low_since"] = None

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
        if now - full_soc_since >= datetime.timedelta(hours=full_hours):
            full = 1

    full_parked = 0
    if vstate.get("full_since"):
        full_since = datetime.datetime.fromisoformat(vstate["full_since"])
        if now - full_since >= datetime.timedelta(hours=full_parked_hours):
            full_parked = 1

    recharge_needed = 0
    last_full = vstate.get("last_full_charge_at")
    if last_full is None:
        recharge_needed = 1
    else:
        last_full_dt = datetime.datetime.fromisoformat(last_full)
        if now - last_full_dt >= datetime.timedelta(days=recharge_reminder_days):
            recharge_needed = 1

    low_battery = 0
    if vstate.get("low_since"):
        low_since = datetime.datetime.fromisoformat(vstate["low_since"])
        if now - low_since >= datetime.timedelta(hours=low_battery_hours):
            low_battery = 1

    return full, full_parked, recharge_needed, low_battery


def _within_window(enabled, start, end, now: datetime.datetime) -> bool:
    if not enabled:
        return True
    start = start or "00:00"
    end = end or "23:59"
    current = now.strftime("%H:%M")
    if start <= end:
        return start <= current <= end
    # Zeitfenster geht ueber Mitternacht (z.B. 22:00 - 06:00).
    return current >= start or current <= end


def should_poll_passive_now(vehicle_config: dict, vstate: dict, now: datetime.datetime) -> bool:
    """Passive Abfrage: liest nur den zuletzt von Kia Connect gemeldeten
    (gecachten) Stand, weckt das Fahrzeug nicht auf."""

    mode = vehicle_config.get("passive_mode", "interval")
    if mode == "never":
        return False

    if mode == "custom":
        times = vehicle_config.get("passive_custom_times") or []
        return now.strftime("%H:%M") in times

    # Default/Fallback: "interval".
    if not _within_window(
        vehicle_config.get("passive_window_enabled"),
        vehicle_config.get("passive_window_from"),
        vehicle_config.get("passive_window_to"),
        now,
    ):
        return False

    interval = int(vehicle_config.get("passive_interval_minutes", 60) or 60)
    last_poll = vstate.get("last_passive_poll_at")
    if not last_poll:
        return True
    last_poll_dt = datetime.datetime.fromisoformat(last_poll)
    return now - last_poll_dt >= datetime.timedelta(minutes=interval)


def should_force_refresh_now(vehicle_config: dict, now: datetime.datetime) -> bool:
    """Force-Refresh: weckt das Fahrzeug aktiv fuer einen frischen Stand.
    Laeuft unabhaengig von der passiven Abfrage zu fest eingestellten
    Uhrzeiten (typischerweise 1-4x taeglich)."""

    times = vehicle_config.get("force_times") or []
    return now.strftime("%H:%M") in times


def log_poll_attempt(vstate: dict, now: datetime.datetime, kind: str, ok: bool) -> None:
    """Merkt sich Erfolg/Fehler dieser Abfrage fuer die "Heute geplant"
    Anzeige in den Einstellungen. Nur der heutige Tag wird behalten."""

    today = now.strftime("%Y-%m-%d")
    log = vstate.setdefault("poll_log", [])
    log[:] = [entry for entry in log if entry.get("date") == today]
    log.append({"date": today, "time": now.strftime("%H:%M"), "kind": kind, "ok": ok})


def send_udp(ip: str, port: int, message: str) -> None:
    with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as sock:
        sock.sendto(message.encode("utf-8"), (ip, port))
    log("DEBUG", f"UDP gesendet an {ip}:{port} -> {message}")


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
        raise RuntimeError(f"Login benötigt zusätzliche Bestätigung (OTP/2FA): {login_result}")

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

        full, full_parked, recharge_needed, low_battery = update_battery_health_state(vehicle_config, vstate, soc, plugged, charging, now)

        vstate["last_values"] = {
            "soc": soc,
            "range_km": range_km,
            "charging": charging,
            "plugged": plugged,
            "full": full,
            "full_parked": full_parked,
            "recharge_needed": recharge_needed,
            "low_battery": low_battery,
            "kia_name": vehicle.name,
            "kia_last_updated_at": str(vehicle.last_updated_at) if vehicle.last_updated_at else None,
            "updated_at": now.isoformat(),
        }
        append_history(vehicle_config["id"], now, soc, charging=charging, plugged=plugged)

        last_updated_str = vehicle.last_updated_at.strftime("%Y-%m-%d %H:%M:%S") if vehicle.last_updated_at else "-"
        log(
            "OK",
            f"[{label}] {vehicle.name}: SOC={soc}% RANGE={range_km}km "
            f"CHARGING={charging} PLUGGED={plugged} "
            f"FULL={full} FULLPARKED={full_parked} RECHARGE100={recharge_needed} "
            f"LOWBATTERY={low_battery} (Stand: {last_updated_str})"
        )

        send_udp(udp_ip, udp_port, f"SOC={soc}")
        send_udp(udp_ip, udp_port, f"RANGE={range_km}")
        send_udp(udp_ip, udp_port, f"CHARGING={charging}")
        send_udp(udp_ip, udp_port, f"PLUGGED={plugged}")
        send_udp(udp_ip, udp_port, f"FULL={full}")
        send_udp(udp_ip, udp_port, f"FULLPARKED={full_parked}")
        send_udp(udp_ip, udp_port, f"RECHARGE100={recharge_needed}")
        send_udp(udp_ip, udp_port, f"LOWBATTERY={low_battery}")


def main() -> None:
    global LOG_LEVEL, EXPLICIT_RUN

    parser = argparse.ArgumentParser(description="Kia2Lox Ladezustand abfragen und per UDP senden")
    parser.add_argument("--force", action="store_true", help="Frisches Update vom Fahrzeug anfordern")
    parser.add_argument("--vehicle", help="Nur dieses eine Fahrzeug abfragen (id aus pluginconfig.json)")
    args = parser.parse_args()

    LOG_LEVEL = load_loglevel()
    EXPLICIT_RUN = bool(args.vehicle)

    config = load_config()
    state = load_state()

    now = datetime.datetime.now().astimezone()
    trigger = "manuell/HTTP-Trigger" if args.vehicle else "Cron"

    vehicles = config["vehicles"]
    if args.vehicle:
        vehicles = [v for v in vehicles if v.get("id") == args.vehicle]
        if not vehicles:
            log("ERROR", f"Kein Fahrzeug mit id={args.vehicle} in der Konfiguration gefunden")
            sys.exit(1)

    if not vehicles:
        return

    # Ein explizit per --vehicle angestossener Aufruf (manueller
    # "Jetzt aktualisieren"-Button, spaeter HTTP-Trigger) fragt immer
    # sofort mit --force ab. Beim regulaeren Cron-Durchlauf ueber alle
    # Fahrzeuge entscheidet jedes Fahrzeug anhand seiner eigenen
    # Passiv-/Force-Refresh-Einstellungen, ob und wie es abgefragt wird.
    explicit_vehicle = bool(args.vehicle)

    # Der Cron-Puls laeuft alle 5 Minuten, aber laengst nicht jeder
    # Durchlauf soll wirklich etwas tun. Ohne diesen Vorab-Check wuerde
    # jeder Durchlauf trotzdem "Abfrage startet"/"Fertig." ins Log
    # schreiben, auch wenn kein Fahrzeug faellig ist - daher hier still
    # abbrechen, wenn es (noch) nichts zu tun gibt.
    if not explicit_vehicle:
        any_due = False
        for vehicle_config in vehicles:
            vehicle_id = vehicle_config.get("id")
            if not vehicle_id or not vehicle_config.get("kia_connected"):
                continue
            vstate_preview = state.get(vehicle_id) or {}
            if should_force_refresh_now(vehicle_config, now) or should_poll_passive_now(vehicle_config, vstate_preview, now):
                any_due = True
                break
        if not any_due:
            return

    log("INFO", f"{now.strftime('%Y-%m-%d %H:%M:%S')} Kia2Lox Abfrage startet ({trigger})")

    for vehicle_config in vehicles:
        vehicle_id = vehicle_config.get("id")
        label = vehicle_config.get("name", vehicle_id)
        if not vehicle_id:
            log("ERROR", f"Fahrzeug ohne id in der Konfiguration uebersprungen: {vehicle_config.get('name')}")
            continue

        # Solange die Zugangsdaten noch nie erfolgreich getestet wurden
        # (kia_connected), keine Kia-Connect-Anfragen versuchen - auch
        # nicht bei einem expliziten manuellen/HTTP-Trigger-Aufruf. Bei
        # einem expliziten Aufruf zaehlt das als Fehler, damit der Aufrufer
        # (PHP) das erkennt und keinen Erfolg meldet.
        if not vehicle_config.get("kia_connected"):
            if explicit_vehicle:
                log("ERROR", f"[{label}]: Zugangsdaten noch nicht erfolgreich getestet - keine Abfrage moeglich.")
            else:
                log("WARNING", f"[{label}] uebersprungen: Zugangsdaten noch nicht erfolgreich getestet.")
            continue

        vstate = get_vehicle_state(state, vehicle_id)

        if explicit_vehicle:
            do_force = args.force
            do_poll = True
        else:
            do_force = should_force_refresh_now(vehicle_config, now)
            do_poll = do_force or should_poll_passive_now(vehicle_config, vstate, now)

        if not do_poll:
            continue

        vstate["last_passive_poll_at"] = now.isoformat()
        ok = True
        try:
            poll_vehicle_config(vehicle_config, vstate, do_force, now)
        except Exception as exc:  # noqa: BLE001 - ein Fahrzeug darf die anderen nicht blockieren
            ok = False
            log("ERROR", f"[{label}]: Abfrage fehlgeschlagen: {exc}")
        vstate["last_poll_ok"] = ok
        log_poll_attempt(vstate, now, "force" if do_force else "passive", ok)

    save_state(state)
    log("INFO", "Fertig.")


if __name__ == "__main__":
    main()
