"""
Kia2Lox - Zugriff auf die offizielle Pleos Vehicle Data API
(https://document.pleos.ai/en/api-reference/vehicle-data-api/intro) als
Alternative zum App-Login ueber hyundai_kia_connect_api.

Hintergrund: Seit Oktober 2026 lehnt Kia/Hyundai EU den Token-Tausch der
inoffiziellen App-Schnittstelle mit Fehler 9035 ("Unusual activity") ab.
Pleos ist der offizielle, rein lesende Weg (nur Europa): der Benutzer holt
sich auf pleos.ai/playground einmalig Client ID + Client Secret, damit gibt
es pro Abfrage einen frischen Access-Token (gueltig 1 Stunde, kein
Refresh-Token noetig). Ein Force-Refresh (Fahrzeug aufwecken) ist ueber
Pleos nicht moeglich - geliefert wird immer der zuletzt vom Fahrzeug
selbst gesendete Stand.

Bewusst nur mit der Standardbibliothek (urllib), damit fuer diese
Datenquelle keine zusaetzlichen Pakete in der venv noetig sind.
"""

import datetime
import json
import urllib.error
import urllib.request

PLEOS_BASE_URL = "https://api.pleos.ai"
PLEOS_TIMEOUT = 30


class PleosError(RuntimeError):
    pass


def _request(method: str, path: str, brand: str, token: str = None, body: dict = None) -> dict:
    headers = {
        "Brand": brand,
        "Accept": "application/json",
        "Content-Type": "application/json",
    }
    if token:
        headers["Authorization"] = "Bearer " + token
    data = json.dumps(body).encode("utf-8") if body is not None else None
    req = urllib.request.Request(PLEOS_BASE_URL + path, data=data, headers=headers, method=method)
    try:
        with urllib.request.urlopen(req, timeout=PLEOS_TIMEOUT) as resp:
            raw = resp.read()
    except urllib.error.HTTPError as exc:
        raw = exc.read()
        try:
            err = (json.loads(raw) or {}).get("error") or {}
            detail = f"{err.get('code', '?')} {err.get('message', '')}".strip()
        except ValueError:
            detail = raw.decode("utf-8", errors="replace")[:200]
        raise PleosError(f"Pleos {path}: HTTP {exc.code} - {detail}") from None
    except urllib.error.URLError as exc:
        raise PleosError(f"Pleos {path}: {exc.reason}") from None
    try:
        return json.loads(raw or b"{}")
    except ValueError:
        raise PleosError(f"Pleos {path}: ungueltige Antwort") from None


def get_token(client_id: str, client_secret: str, brand: str) -> str:
    res = _request("POST", "/v1/auth/personal-token", brand,
                   body={"clientId": client_id, "clientSecret": client_secret})
    token = (res.get("data") or {}).get("accessToken")
    if not token:
        raise PleosError("Pleos: kein Access-Token erhalten")
    return token


def get_vehicles(token: str, brand: str) -> list:
    res = _request("GET", "/v1/vehicles/consent", brand, token=token)
    return (res.get("data") or {}).get("vehicles") or []


def select_vehicles(vehicles: list, vin: str) -> list:
    """Mit hinterlegter VIN nur genau dieses Fahrzeug, sonst das erste im
    Pleos-Konto - ein Kia2Lox-Fahrzeug sendet an genau ein UDP-Ziel, mehrere
    Autos eines Kontos wuerden sich dort gegenseitig ueberschreiben."""

    vin = (vin or "").strip().upper()
    if vin:
        match = [v for v in vehicles if (v.get("vin") or "").upper() == vin]
        if not match:
            raise PleosError(f"Pleos: Fahrzeug mit VIN {vin} nicht im Pleos-Konto gefunden")
        return match
    if not vehicles:
        raise PleosError("Pleos: keine Fahrzeuge im Pleos-Konto freigegeben")
    return vehicles[:1]


def _parse_timestamp(value):
    if not value:
        return None
    try:
        # Pleos liefert UTC mit "Z" (teils mit Millisekunden) - fuer Anzeige
        # und Vergleiche in die lokale Zeitzone umrechnen, wie die uebrigen
        # Zeitstempel in state.json.
        return datetime.datetime.fromisoformat(value.replace("Z", "+00:00")).astimezone()
    except ValueError:
        return None


def _parse_range(powertrain: dict):
    for entry in powertrain.get("distanceToEmpties") or []:
        value = entry.get("value")
        if entry.get("type") == "EV" and isinstance(value, (int, float)):
            return round(value)
    return None


def fetch_vehicle_status(token: str, brand: str, vehicle: dict) -> dict:
    """Liefert die fuer Kia2Lox relevanten Werte eines Fahrzeugs im selben
    Format wie der Kia-Connect-Zweig in kia2lox_poll.py."""

    vin = vehicle.get("vin")
    batteries = _request("GET", f"/v1/vehicles/{vin}/batteries", brand, token=token).get("data") or {}
    charge = batteries.get("charge") or {}

    # Die Reichweite steht nicht in /batteries, sondern in /powertrains -
    # und ist laut Pleos-Kompatibilitaetsliste nicht fuer alle Modelle
    # verfuegbar (z.B. Niro EV: "-"). Fehlt sie, bleibt RANGE leer statt
    # die ganze Abfrage scheitern zu lassen.
    range_km = None
    try:
        powertrain = _request("GET", f"/v1/vehicles/{vin}/powertrains", brand, token=token).get("data") or {}
        range_km = _parse_range(powertrain)
    except PleosError:
        pass

    soc = charge.get("stateOfCharge")
    plugin = charge.get("plugin")
    if plugin == "connected":
        plugged = 1
    elif plugin == "disconnected":
        plugged = 0
    else:
        plugged = None

    return {
        "name": vehicle.get("sellname") or vehicle.get("nickname") or vin,
        "soc": round(soc) if isinstance(soc, (int, float)) else None,
        "range_km": range_km,
        "charging": 1 if charge.get("charging") else 0,
        "plugged": plugged,
        "last_updated_at": _parse_timestamp(batteries.get("timestamp")),
    }
