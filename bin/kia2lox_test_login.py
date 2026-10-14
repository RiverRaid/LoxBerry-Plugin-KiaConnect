#!/usr/bin/env python3
"""
Kia2Lox - testet Kia-Connect-Zugangsdaten mit einem echten Login, ohne sie
zu speichern. Wird von der Einstellungsseite (PHP) aufgerufen.

Liest die Zugangsdaten als JSON von STDIN (nicht als Kommandozeilen-
Argument, damit sie nicht in der Prozessliste sichtbar sind):
    {"username": "...", "password": "...", "pin": "", "brand": "kia"}
bzw. fuer die Pleos-API:
    {"source": "pleos", "client_id": "...", "client_secret": "...", "vin": "", "brand": "kia"}

Gibt das Ergebnis als einzeilige JSON-Zeile auf STDOUT aus:
    {"ok": true, "vehicle_count": 1}
    {"ok": false, "error": "..."}
"""

import json
import sys

from hyundai_kia_connect_api import VehicleManager

import kia2lox_pleos

REGION_EUROPE = 1
BRAND_KIA_ID = 1
BRAND_HYUNDAI_ID = 2
BRAND_IDS = {"kia": BRAND_KIA_ID, "hyundai": BRAND_HYUNDAI_ID}


def test_pleos(payload: dict) -> None:
    """Pleos-Zugangsdaten pruefen: Token holen und die (ggf. per VIN
    gewaehlten) Fahrzeuge des Pleos-Kontos auflisten."""

    client_id = (payload.get("client_id") or "").strip()
    client_secret = (payload.get("client_secret") or "").strip()
    brand = payload.get("brand") or "kia"
    if not client_id or not client_secret:
        print(json.dumps({"ok": False, "error": "Client ID oder Client Secret fehlt"}))
        return
    try:
        token = kia2lox_pleos.get_token(client_id, client_secret, brand)
        vehicles = kia2lox_pleos.select_vehicles(kia2lox_pleos.get_vehicles(token, brand), payload.get("vin") or "")
    except Exception as exc:  # noqa: BLE001
        print(json.dumps({"ok": False, "error": str(exc)}))
        return
    print(json.dumps({"ok": True, "vehicle_count": len(vehicles), "vehicle_name": vehicles[0].get("sellname") or ""}))


def main() -> None:
    try:
        payload = json.load(sys.stdin)
    except json.JSONDecodeError:
        print(json.dumps({"ok": False, "error": "Ungueltige Eingabe"}))
        return

    if payload.get("source") == "pleos":
        test_pleos(payload)
        return

    username = (payload.get("username") or "").strip()
    password = payload.get("password") or ""
    pin = (payload.get("pin") or "").strip()
    brand = payload.get("brand") or "kia"

    if not username or not password:
        print(json.dumps({"ok": False, "error": "Benutzername oder Passwort fehlt"}))
        return

    manager = VehicleManager(
        region=REGION_EUROPE,
        brand=BRAND_IDS.get(brand, BRAND_KIA_ID),
        username=username,
        password=password,
        pin=pin,
    )

    try:
        login_result = manager.login()
    except Exception as exc:  # noqa: BLE001 - Login kann diverse Fehler werfen
        print(json.dumps({"ok": False, "error": str(exc)}))
        return

    if login_result is not True:
        print(json.dumps({"ok": False, "error": f"Zusaetzliche Bestaetigung noetig (OTP/2FA): {login_result}"}))
        return

    print(json.dumps({"ok": True, "vehicle_count": len(manager.vehicles)}))


if __name__ == "__main__":
    main()
