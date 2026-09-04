#!/usr/bin/env python3
"""
Kia2Lox - testet Kia-Connect-Zugangsdaten mit einem echten Login, ohne sie
zu speichern. Wird von der Einstellungsseite (PHP) aufgerufen.

Liest die Zugangsdaten als JSON von STDIN (nicht als Kommandozeilen-
Argument, damit sie nicht in der Prozessliste sichtbar sind):
    {"username": "...", "password": "...", "pin": ""}

Gibt das Ergebnis als einzeilige JSON-Zeile auf STDOUT aus:
    {"ok": true, "vehicle_count": 1}
    {"ok": false, "error": "..."}
"""

import json
import sys

from hyundai_kia_connect_api import VehicleManager

REGION_EUROPE = 1
BRAND_KIA_ID = 1


def main() -> None:
    try:
        payload = json.load(sys.stdin)
    except json.JSONDecodeError:
        print(json.dumps({"ok": False, "error": "Ungueltige Eingabe"}))
        return

    username = (payload.get("username") or "").strip()
    password = payload.get("password") or ""
    pin = (payload.get("pin") or "").strip()

    if not username or not password:
        print(json.dumps({"ok": False, "error": "Benutzername oder Passwort fehlt"}))
        return

    manager = VehicleManager(
        region=REGION_EUROPE,
        brand=BRAND_KIA_ID,
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
