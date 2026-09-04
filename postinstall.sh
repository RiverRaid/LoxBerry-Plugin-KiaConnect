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

echo "<INFO> postinstall.sh: Setze Ausfuehrungsrechte fuer Cron-Skript"
chmod 755 "$PBIN/cron.sh" 2>/dev/null

echo "<OK> postinstall.sh erfolgreich abgeschlossen"

exit 0
