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

# LoxBerrys eigene Log-Level-Werte fuer die Pluginverwaltung (siehe
# loglevel_select_html() in loxberry_web.php - die Werte sind bewusst
# nicht durchgehend 0-7, sondern genau diese fuenf): 0=Aus, 3=Fehler,
# 4=Warnung, 6=Info, 7=Debug.
LOG_LEVEL_OFF = 0
LOG_LEVEL_ERROR = 3
LOG_LEVEL_WARNING = 4
LOG_LEVEL_INFO = 6
LOG_LEVEL_DEBUG = 7

# Jedes eigene Log-Tag einer dieser vier Stufen zugeordnet: CRITICAL zaehlt
# zu Fehler (ein kritischer Fehler ist immer noch ein Fehler), OK zu Info
# (eine Erfolgsmeldung ist informativ). Eine Zeile wird nur geschrieben,
# wenn der eingestellte Log-Level mindestens so hoch ist wie die Stufe
# ihres Tags.
TAG_LOG_LEVELS = {
    "CRITICAL": LOG_LEVEL_ERROR,
    "ERROR": LOG_LEVEL_ERROR,
    "WARNING": LOG_LEVEL_WARNING,
    "OK": LOG_LEVEL_INFO,
    "INFO": LOG_LEVEL_INFO,
    "DEBUG": LOG_LEVEL_DEBUG,
}

# Werden einmalig zu Beginn von main() gesetzt und danach von log() gelesen.
LOG_LEVEL = LOG_LEVEL_INFO
EXPLICIT_RUN = False


def load_loglevel() -> int:
    """Liest den in der LoxBerry-Pluginverwaltung fuer dieses Plugin
    eingestellten Log-Level aus der zentralen Plugin-Datenbank. Kann die
    Datei nicht gelesen/geparst werden (z.B. andere Rechte), wird auf
    LOG_LEVEL_INFO zurueckgefallen."""

    try:
        with open(PLUGINDATABASE_PATH, "r", encoding="utf-8") as f:
            db = json.load(f)
        plugins = db.get("plugins", db) if isinstance(db, dict) else {}
        for entry in plugins.values():
            if isinstance(entry, dict) and entry.get("folder") == PLUGIN_FOLDER:
                return int(entry.get("loglevel", LOG_LEVEL_INFO))
    except (OSError, ValueError, TypeError, json.JSONDecodeError):
        pass
    return LOG_LEVEL_INFO


def log(level: str, msg: str) -> None:
    """Schreibt eine Zeile mit LoxBerry-typischem Level-Tag (<OK>, <INFO>,
    <WARNING>, <ERROR>, <CRITICAL>) nach stdout - landet je nach Aufrufer
    entweder ueber den Cron-Wrapper oder den manuellen/HTTP-Trigger-Aufruf
    in poll.log. Bei einem regulaeren Cron-Durchlauf wird dabei der
    eingestellte Log-Level beachtet und leisere Zeilen unterdrueckt (bei
    LOG_LEVEL_OFF komplett - siehe TAG_LOG_LEVELS). Explizite Aufrufe
    (Force-Refresh-Button, HTTP-Trigger) protokollieren dagegen immer
    alles, damit inc_vehicles.php::kia2lox_manual_refresh() Fehler
    zuverlaessig an ihrem <ERROR>/<CRITICAL>-Tag erkennt, unabhaengig vom
    eingestellten Log-Level - das betrifft ohnehin nicht poll.log, da
    dieser Pfad ueber proc_open() laeuft und nicht ueber den Cron-Wrapper."""

    if not EXPLICIT_RUN and LOG_LEVEL < TAG_LOG_LEVELS[level]:
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


def _parse_iso(iso_str: str, reference: datetime.datetime) -> datetime.datetime:
    """Parst einen gespeicherten ISO-Zeitstempel. Werte ohne Zeitzone (sollte
    hier nicht vorkommen, da alle eigenen Zeitstempel ueber
    datetime.now().astimezone() erzeugt werden) werden defensiv mit der
    Zeitzone von reference versehen, damit die Subtraktion nicht abstuerzt."""

    value = datetime.datetime.fromisoformat(iso_str)
    if value.tzinfo is None:
        value = value.replace(tzinfo=reference.tzinfo)
    return value


def _elapsed_since(iso_str, now: datetime.datetime):
    """Vergangene Zeit seit dem gespeicherten ISO-Zeitstempel, oder None wenn
    kein Zeitstempel vorhanden ist."""

    if not iso_str:
        return None
    return now - _parse_iso(iso_str, now)


def _warning_thresholds(vehicle_config: dict) -> dict:
    return {
        "full": datetime.timedelta(hours=int(vehicle_config.get("full_hours", DEFAULT_FULL_HOURS) or DEFAULT_FULL_HOURS)),
        "full_parked": datetime.timedelta(hours=int(vehicle_config.get("full_parked_hours", DEFAULT_FULL_PARKED_HOURS) or DEFAULT_FULL_PARKED_HOURS)),
        "recharge_needed": datetime.timedelta(days=int(vehicle_config.get("recharge_reminder_days", DEFAULT_RECHARGE_REMINDER_DAYS) or DEFAULT_RECHARGE_REMINDER_DAYS)),
        "low_battery": datetime.timedelta(hours=int(vehicle_config.get("low_battery_hours", DEFAULT_LOW_BATTERY_HOURS) or DEFAULT_LOW_BATTERY_HOURS)),
    }


def _raw_warning_states(vehicle_config: dict, vstate: dict, now: datetime.datetime) -> dict:
    """Berechnet aus den in vstate gespeicherten *_since-Zeitstempeln, ob die
    fuer die jeweilige Warnung konfigurierte Dauer bereits erreicht ist -
    unabhaengig davon, wie aktuell die zugrunde liegenden Kia-Connect-Daten
    sind. Liefert je Warnung (waere_faellig, Schwellwert) zurueck; der
    Schwellwert wird fuer die Datenaktualitaets-Pruefung derselben Warnung
    wiederverwendet (siehe stale_warning_needs_refresh() und
    update_battery_health_state())."""

    thresholds = _warning_thresholds(vehicle_config)

    full_elapsed = _elapsed_since(vstate.get("full_soc_since"), now)
    full_parked_elapsed = _elapsed_since(vstate.get("full_since"), now)
    low_elapsed = _elapsed_since(vstate.get("low_since"), now)
    last_full = vstate.get("last_full_charge_at")
    recharge_elapsed = _elapsed_since(last_full, now)

    return {
        "full": (full_elapsed is not None and full_elapsed >= thresholds["full"], thresholds["full"]),
        "full_parked": (full_parked_elapsed is not None and full_parked_elapsed >= thresholds["full_parked"], thresholds["full_parked"]),
        "recharge_needed": (last_full is None or recharge_elapsed >= thresholds["recharge_needed"], thresholds["recharge_needed"]),
        "low_battery": (low_elapsed is not None and low_elapsed >= thresholds["low_battery"], thresholds["low_battery"]),
    }


def stale_warning_needs_refresh(vehicle_config: dict, vstate: dict, now: datetime.datetime) -> bool:
    """Prueft rein anhand des zuletzt gespeicherten Zustands (ohne neue
    Kia-Connect-Anfrage), ob mindestens eine der 4 Warnungen aktuell wegen
    veralteter Daten unterdrueckt wuerde - und damit ein ausserplanmaessiger
    Force-Refresh sinnvoll waere (siehe Checkbox "Bei veralteten Daten
    automatisch aktualisieren" in den Einstellungen)."""

    kia_last_updated_at = (vstate.get("last_values") or {}).get("kia_last_updated_at")
    data_age = _elapsed_since(kia_last_updated_at, now)

    for would_warn, threshold in _raw_warning_states(vehicle_config, vstate, now).values():
        if would_warn and (data_age is None or data_age >= threshold):
            return True
    return False


def update_battery_health_state(vehicle_config: dict, vstate: dict, soc: int, plugged, charging: int, kia_last_updated_at, now: datetime.datetime) -> tuple[int, int, int, int]:
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

    Eine Warnung wird zusaetzlich unterdrueckt, solange kia_last_updated_at
    (der Zeitpunkt, zu dem Kia Connect selbst die Fahrzeugdaten zuletzt
    aktualisiert hat) laenger her ist als die fuer diese Warnung eingestellte
    Zeit - ein passiver Abruf liest ja nur den gecachten Stand, der sich in
    der Zwischenzeit laengst geaendert haben kann. vstate["stale_warning_pending"]
    haelt fest, ob das gerade der Fall ist (fuer den ausserplanmaessigen
    Force-Refresh in main(), siehe stale_warning_needs_refresh()).
    """

    full_soc_threshold = int(vehicle_config.get("full_soc_threshold", DEFAULT_FULL_SOC_THRESHOLD) or DEFAULT_FULL_SOC_THRESHOLD)
    low_soc_threshold = int(vehicle_config.get("low_soc_threshold", DEFAULT_LOW_SOC_THRESHOLD) or DEFAULT_LOW_SOC_THRESHOLD)

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

    raw = _raw_warning_states(vehicle_config, vstate, now)
    data_age = _elapsed_since(kia_last_updated_at, now)
    stale_pending = False
    results = {}
    for key, (would_warn, threshold) in raw.items():
        if would_warn and (data_age is None or data_age >= threshold):
            stale_pending = True
            results[key] = 0
        else:
            results[key] = 1 if would_warn else 0
    vstate["stale_warning_pending"] = stale_pending

    return results["full"], results["full_parked"], results["recharge_needed"], results["low_battery"]


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


def _current_grid_slot(window_enabled, window_from_hm, interval_minutes: int, now: datetime.datetime) -> datetime.datetime:
    """Liefert den Beginn des aktuellen (juengsten bereits erreichten)
    Rasterpunkts als aware datetime. Das Raster startet taeglich neu bei
    window_from (oder Mitternacht, falls kein Zeitfenster aktiv ist) und
    wiederholt sich alle interval_minutes - z.B. Fenster 08:00-16:00,
    Intervall 2h ergibt 08:00, 10:00, 12:00, 14:00, 16:00."""

    anchor_hm = window_from_hm if window_enabled else "00:00"
    hour, minute = (int(p) for p in (anchor_hm or "00:00").split(":"))
    anchor = now.replace(hour=hour, minute=minute, second=0, microsecond=0)
    if anchor > now:
        anchor -= datetime.timedelta(days=1)
    slots_passed = (now - anchor) // datetime.timedelta(minutes=interval_minutes)
    return anchor + datetime.timedelta(minutes=slots_passed * interval_minutes)


PASSIVE_SLOT_TOLERANCE_MINUTES = 10


def should_poll_passive_now(vehicle_config: dict, vstate: dict, now: datetime.datetime) -> bool:
    """Passive Abfrage: liest nur den zuletzt von Kia Connect gemeldeten
    (gecachten) Stand, weckt das Fahrzeug nicht auf.

    Die faelligen Zeitpunkte im Intervall-Modus bilden ein festes Raster aus
    Zeitfenster-Beginn und Intervall (siehe _current_grid_slot()) - das
    Raster steht unabhaengig davon fest, ob zwischendurch schon
    manuell/per HTTP-Trigger aktualisiert wurde. vstate["last_scheduled_passive_slot"]
    haelt dafuer getrennt von last_passive_poll_at fest, welcher Rasterpunkt
    zuletzt durch einen planmaessigen (Cron-)Abruf bedient wurde - ein
    manueller Abruf schreibt dort bewusst nichts hinein, siehe
    mark_passive_slot_served().

    Ausnahme: fand irgendein Abruf (auch manuell/Force/Stale-Refresh, siehe
    last_passive_poll_at) innerhalb von PASSIVE_SLOT_TOLERANCE_MINUTES um
    den aktuellen Rasterpunkt statt, gilt dieser trotzdem als bedient - so
    entsteht kein zusaetzlicher Kia-Connect-Abruf kurz vor/nach einem
    ohnehin schon frischen Stand. Dieser Rasterpunkt wird dabei dauerhaft
    als bedient vermerkt, damit nach Ablauf der Toleranz kein "Nachhol"-
    Abruf mehr stattfindet."""

    mode = vehicle_config.get("passive_mode", "interval")
    if mode == "never":
        return False

    if mode == "custom":
        times = vehicle_config.get("passive_custom_times") or []
        return now.strftime("%H:%M") in times

    # Default/Fallback: "interval".
    window_enabled = bool(vehicle_config.get("passive_window_enabled"))
    window_from = vehicle_config.get("passive_window_from")
    window_to = vehicle_config.get("passive_window_to")
    if not _within_window(window_enabled, window_from, window_to, now):
        return False

    interval = int(vehicle_config.get("passive_interval_minutes", 60) or 60)
    slot = _current_grid_slot(window_enabled, window_from, interval, now)

    last_slot = vstate.get("last_scheduled_passive_slot")
    if last_slot and _parse_iso(last_slot, now) >= slot:
        return False

    last_poll = vstate.get("last_passive_poll_at")
    if last_poll:
        last_poll_dt = _parse_iso(last_poll, now)
        if abs((last_poll_dt - slot).total_seconds()) <= PASSIVE_SLOT_TOLERANCE_MINUTES * 60:
            vstate["last_scheduled_passive_slot"] = slot.isoformat()
            return False

    return True


def mark_passive_slot_served(vehicle_config: dict, vstate: dict, now: datetime.datetime) -> None:
    """Merkt den aktuellen Intervall-Rasterpunkt als bedient. Wird nach
    jedem planmaessigen (Cron-, nicht explizit/manuellen) Abruf aufgerufen -
    unabhaengig davon, ob er ueber die Passiv- oder Force-Zeitplanung bzw.
    den Stale-Refresh ausgeloest wurde -, damit derselbe Rasterpunkt nicht
    kurz danach noch zusaetzlich einen eigenen Passiv-Abruf ausloest.
    Manuelle/explizite Abrufe rufen dies bewusst nicht auf, damit sie das
    Raster nicht verschieben (siehe should_poll_passive_now())."""

    if vehicle_config.get("passive_mode", "interval") != "interval":
        return  # "custom"/"never" nutzen kein Raster.
    window_enabled = bool(vehicle_config.get("passive_window_enabled"))
    window_from = vehicle_config.get("passive_window_from")
    interval = int(vehicle_config.get("passive_interval_minutes", 60) or 60)
    slot = _current_grid_slot(window_enabled, window_from, interval, now)
    vstate["last_scheduled_passive_slot"] = slot.isoformat()


def should_force_refresh_now(vehicle_config: dict, now: datetime.datetime) -> bool:
    """Force-Refresh: weckt das Fahrzeug aktiv fuer einen frischen Stand.
    Laeuft unabhaengig von der passiven Abfrage zu fest eingestellten
    Uhrzeiten (typischerweise 1-4x taeglich)."""

    times = vehicle_config.get("force_times") or []
    return now.strftime("%H:%M") in times


def log_poll_attempt(vstate: dict, now: datetime.datetime, kind: str, ok: bool, source: str) -> None:
    """Merkt sich Erfolg/Fehler dieser Abfrage fuer die "Heute geplant"
    Anzeige in den Einstellungen. Nur der heutige Tag wird behalten.

    source unterscheidet "cron" (planmaessiger Cron-Durchlauf, inklusive des
    ausserplanmaessigen Stale-Force-Refresh - beides automatisches
    Plugin-Verhalten) von "manual" (expliziter --vehicle-Aufruf ueber den
    "Jetzt aktualisieren"-Button oder den HTTP-Trigger). Die "Heute
    geplant"-Vorschau zeigt nur "cron"-Eintraege, damit einzelne manuelle
    Klicks nicht wie Teil des eigentlichen Zeitplans aussehen."""

    today = now.strftime("%Y-%m-%d")
    log = vstate.setdefault("poll_log", [])
    log[:] = [entry for entry in log if entry.get("date") == today]
    log.append({"date": today, "time": now.strftime("%H:%M"), "kind": kind, "ok": ok, "source": source})


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
        kia_last_updated_at = str(vehicle.last_updated_at) if vehicle.last_updated_at else None

        full, full_parked, recharge_needed, low_battery = update_battery_health_state(vehicle_config, vstate, soc, plugged, charging, kia_last_updated_at, now)

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
            "kia_last_updated_at": kia_last_updated_at,
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
    if args.vehicle:
        trigger = f"manuell/HTTP-Trigger, {'Force-Refresh' if args.force else 'Passiv-Refresh'}"
    else:
        trigger = "Cron"

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
    # schreiben, auch wenn kein Fahrzeug faellig ist - daher hier bei
    # INFO und leiser nur eine DEBUG-Zeile schreiben (damit bei
    # Log-Level "Debug" trotzdem jeder Cron-Tick sichtbar ist) und dann
    # abbrechen.
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
            if (
                vehicle_config.get("stale_auto_refresh_enabled", True)
                and not vstate_preview.get("stale_warning_pending", False)
                and stale_warning_needs_refresh(vehicle_config, vstate_preview, now)
            ):
                any_due = True
                break
        if not any_due:
            log("DEBUG", f"{now.strftime('%Y-%m-%d %H:%M:%S')} Cron-Tick: kein Fahrzeug faellig, ueberspringe.")
            # should_poll_passive_now() kann als Nebeneffekt einen
            # Rasterpunkt per Toleranz als bedient markiert haben (siehe
            # dort) - das muss trotz des fruehen Abbruchs gespeichert werden.
            save_state(state)
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

            # Eine Warnung ist wegen veralteter Kia-Connect-Daten unterdrueckt
            # (siehe update_battery_health_state) - ausserplanmaessig einmalig
            # per Force-Refresh versuchen, aktuelle Daten zu bekommen. Greift
            # auch dann, wenn dieses Fahrzeug laut eigenem Zeitplan diesen
            # Zyklus sonst gar nicht dran waere. vstate["stale_warning_pending"]
            # verhindert Wiederholungen, solange die Daten stale bleiben - wird
            # erst zurueckgesetzt, sobald wieder frische Daten da sind oder die
            # zugrunde liegende Warnbedingung selbst nicht mehr zutrifft.
            if (
                not do_force
                and vehicle_config.get("stale_auto_refresh_enabled", True)
                and not vstate.get("stale_warning_pending", False)
                and stale_warning_needs_refresh(vehicle_config, vstate, now)
            ):
                log("INFO", f"[{label}]: Warnung durch veraltete Kia-Connect-Daten unterdrueckt - einmaliger Force-Refresh.")
                do_force = True
                do_poll = True

        if not do_poll:
            continue

        # Bei Cron-Durchlaeufen mit mehreren Fahrzeugen sagt die
        # "Abfrage startet"-Zeile oben nicht, welche Art Abfrage ein
        # einzelnes Fahrzeug bekommt - daher hier pro Fahrzeug festhalten.
        log("INFO", f"[{label}]: {'Force-Refresh' if do_force else 'Passiv-Refresh'}")

        vstate["last_passive_poll_at"] = now.isoformat()
        if not explicit_vehicle:
            # Rasterpunkt schon vor dem eigentlichen Abruf als bedient
            # markieren (nicht erst nach Erfolg) - sonst wuerde ein
            # fehlgeschlagener Abruf alle 5 Minuten erneut versucht, statt
            # bis zum naechsten Rasterpunkt zu warten (wie schon bisher bei
            # last_passive_poll_at).
            mark_passive_slot_served(vehicle_config, vstate, now)
        ok = True
        try:
            poll_vehicle_config(vehicle_config, vstate, do_force, now)
        except Exception as exc:  # noqa: BLE001 - ein Fahrzeug darf die anderen nicht blockieren
            ok = False
            log("ERROR", f"[{label}]: Abfrage fehlgeschlagen: {exc}")
        vstate["last_poll_ok"] = ok
        log_poll_attempt(vstate, now, "force" if do_force else "passive", ok, "manual" if explicit_vehicle else "cron")

        # ERROR-Ausgang: 1 bei jedem fehlgeschlagenen Abruf (bleibt 1, bis
        # wieder ein Abruf gelingt), 0 sobald wieder ein Abruf erfolgreich
        # war. poll_vehicle_config() sendet die eigenen UDP-Werte nur im
        # Erfolgsfall, daher hier separat und unabhaengig vom Ausgang.
        udp_ip = vehicle_config.get("udp_target_ip")
        udp_port = vehicle_config.get("udp_target_port")
        if udp_ip and udp_port:
            send_udp(udp_ip, int(udp_port), f"ERROR={0 if ok else 1}")

    save_state(state)
    log("INFO", "Fertig.")


if __name__ == "__main__":
    main()
