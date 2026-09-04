#!/bin/bash

# postupgrade.sh - Executed as the very last step when updating an already-installed plugin.
# Runs as user "loxberry" AFTER postinstall.sh, only during updates (not on fresh install).
#
# Exit codes: 0 = success, 1 = warning, 2 = error (cancels installation)

PSHNAME=$2
PVERSION=$4

echo "<OK> postupgrade.sh: Update von Plugin $PSHNAME auf Version $PVERSION abgeschlossen"

exit 0
