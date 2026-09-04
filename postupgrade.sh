#!/bin/bash

# postupgrade.sh - Executed as the very last step when updating an already-installed plugin.
# Runs as user "loxberry" AFTER postinstall.sh, only during updates (not on fresh install).
#
# Exit codes: 0 = success, 1 = warning, 2 = error (cancels installation)

PDIR=$3
PSHNAME=$2
PVERSION=$4

PCONFIG=$LBPCONFIG/$PDIR
BACKUP="/tmp/${PSHNAME}_pluginconfig.json.bak"

if [ -f "$BACKUP" ]; then
	mkdir -p "$PCONFIG"
	cp -f "$BACKUP" "$PCONFIG/pluginconfig.json"
	chmod 640 "$PCONFIG/pluginconfig.json"
	rm -f "$BACKUP"
	echo "<OK> postupgrade.sh: pluginconfig.json wiederhergestellt"
fi

echo "<OK> postupgrade.sh: Update von Plugin $PSHNAME auf Version $PVERSION abgeschlossen"

exit 0
