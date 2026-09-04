#!/bin/bash

# postinstall.sh - Executed after all plugin files have been copied.
# Runs as user "loxberry" AFTER files are installed and BEFORE postupgrade.sh.
#
# Exit codes: 0 = success, 1 = warning, 2 = error (cancels installation)

COMMAND=$0
PTEMPDIR=$1
PSHNAME=$2
PDIR=$3
PVERSION=$4
PTEMPPATH=$6

PDATA=$LBPDATA/$PDIR
PCONFIG=$LBPCONFIG/$PDIR
PBIN=$LBPBIN/$PDIR

echo "<INFO> postinstall.sh: Erzeuge Verzeichnisse fuer Konfiguration und Daten"
mkdir -p "$PCONFIG"
mkdir -p "$PDATA"

echo "<INFO> postinstall.sh: Setze Ausfuehrungsrechte fuer Python-Skript"
chmod 755 "$PBIN/kia2lox_poll.py" 2>/dev/null

VENVDIR="$PDATA/venv"
if [ ! -d "$VENVDIR" ]; then
	echo "<INFO> postinstall.sh: Erzeuge Python-Umgebung (venv)"
	python3 -m venv "$VENVDIR"
	if [ $? -ne 0 ]; then
		echo "<ERROR> postinstall.sh: Anlegen der Python-venv fehlgeschlagen"
		exit 2
	fi
else
	echo "<INFO> postinstall.sh: Python-Umgebung existiert bereits, wird wiederverwendet"
fi

echo "<INFO> postinstall.sh: Installiere/aktualisiere Python-Abhaengigkeiten"
"$VENVDIR/bin/pip" install --quiet --upgrade pip
"$VENVDIR/bin/pip" install --quiet --upgrade hyundai_kia_connect_api
if [ $? -ne 0 ]; then
	echo "<ERROR> postinstall.sh: Installation der Python-Abhaengigkeiten fehlgeschlagen"
	exit 2
fi

echo "<OK> postinstall.sh erfolgreich abgeschlossen"

exit 0
