# Kia2Lox

LoxBerry-Plugin, das den Ladezustand eines Kia/Hyundai Elektroautos über
[Kia Connect](https://www.kia.com) ausliest und per UDP an einen Loxone
Miniserver sendet.

**Status: In aktiver Entwicklung.** Aktuell ist nur das Plugin-Grundgerüst
vorhanden, die eigentliche Kia-Connect-Anbindung folgt in den nächsten
Etappen.

## Funktionsumfang (geplant)

- Ladezustand (SoC), Reichweite, Lade- und Steckerstatus per UDP an Loxone
- Einstellbares Abfrageintervall (30–120 Minuten)
- Optionales "Force Refresh" (weckt das Fahrzeug für aktuellere Daten),
  konfigurierbar von "nie" bis 4x täglich
- Force-Refresh-Trigger von Loxone aus per HTTP-Aufruf (virtueller Ausgang)
- Miniserver-Auswahl direkt aus der LoxBerry-Konfiguration
- Fertige Vorlagen für virtuellen UDP-Eingang/Ausgang zum Download

## Installation

Über die LoxBerry Plugin-Verwaltung (Konfiguration → Plugins → Plugin
hinzufügen) und dortige Angabe der Release-URL, oder manuell als ZIP.

## Entwicklung

Dieses Projekt basiert auf der offiziellen
[LoxBerry Plugin-Struktur](https://wiki.loxberry.de/entwickler/grundlagen_zur_erstellung_eines_plugins).

Das Verzeichnis [`01_test`](01_test/) enthält ein eigenständiges
Test-Script zur Kia-Connect-Anbindung außerhalb des LoxBerry-Plugins.

## Lizenz

[MIT](LICENSE)
