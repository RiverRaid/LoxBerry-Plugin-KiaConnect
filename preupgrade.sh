#!/bin/bash

# preupgrade.sh - Executed as the first step when updating an already-installed plugin.
# Runs as user "loxberry" BEFORE preinstall.sh, only during updates (not on fresh install).
#
# Exit codes: 0 = success, 1 = warning, 2 = error (cancels installation)

PDIR=$3
PSHNAME=$2
PVERSION=$4

PCONFIG=$LBPCONFIG/$PDIR
PDATA=$LBPDATA/$PDIR

echo "<INFO> preupgrade.sh: Update von Plugin $PSHNAME auf Version $PVERSION wird vorbereitet"

# LoxBerry setzt den Config-Ordner bei jedem Update zurueck. Damit
# gespeicherte Zugangsdaten/Einstellungen ein Update ueberleben, sichern
# wir pluginconfig.json vorab - postupgrade.sh stellt sie wieder her.
if [ -f "$PCONFIG/pluginconfig.json" ]; then
	cp -f "$PCONFIG/pluginconfig.json" "/tmp/${PSHNAME}_pluginconfig.json.bak"
	echo "<INFO> preupgrade.sh: pluginconfig.json gesichert"
fi

# Dasselbe gilt fuer das Datenverzeichnis - state.json (aktueller Status
# je Fahrzeug) und die SoC-Verlaufsdateien fuer das Uebersichts-Diagramm
# sollen ein Update ebenfalls ueberleben. Die Python-venv wird bewusst
# NICHT gesichert, die baut postinstall.sh bei Bedarf ohnehin automatisch
# neu auf.
if [ -f "$PDATA/state.json" ] || ls "$PDATA"/history_*.jsonl >/dev/null 2>&1; then
	rm -rf "/tmp/${PSHNAME}_data.bak"
	mkdir -p "/tmp/${PSHNAME}_data.bak"
	cp -f "$PDATA/state.json" "/tmp/${PSHNAME}_data.bak/" 2>/dev/null
	cp -f "$PDATA"/history_*.jsonl "/tmp/${PSHNAME}_data.bak/" 2>/dev/null
	echo "<INFO> preupgrade.sh: state.json und Verlaufsdaten gesichert"
fi

exit 0
