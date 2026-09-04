#!/bin/bash

# preupgrade.sh - Executed as the first step when updating an already-installed plugin.
# Runs as user "loxberry" BEFORE preinstall.sh, only during updates (not on fresh install).
#
# Exit codes: 0 = success, 1 = warning, 2 = error (cancels installation)

PDIR=$3
PSHNAME=$2
PVERSION=$4

PCONFIG=$LBPCONFIG/$PDIR

echo "<INFO> preupgrade.sh: Update von Plugin $PSHNAME auf Version $PVERSION wird vorbereitet"

# LoxBerry setzt den Config-Ordner bei jedem Update zurueck. Damit
# gespeicherte Zugangsdaten/Einstellungen ein Update ueberleben, sichern
# wir pluginconfig.json vorab - postupgrade.sh stellt sie wieder her.
if [ -f "$PCONFIG/pluginconfig.json" ]; then
	cp -f "$PCONFIG/pluginconfig.json" "/tmp/${PSHNAME}_pluginconfig.json.bak"
	echo "<INFO> preupgrade.sh: pluginconfig.json gesichert"
fi

exit 0
