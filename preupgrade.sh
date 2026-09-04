#!/bin/bash

# preupgrade.sh - Executed as the first step when updating an already-installed plugin.
# Runs as user "loxberry" BEFORE preinstall.sh, only during updates (not on fresh install).
#
# Exit codes: 0 = success, 1 = warning, 2 = error (cancels installation)

PSHNAME=$2
PVERSION=$4

echo "<INFO> preupgrade.sh: Update von Plugin $PSHNAME auf Version $PVERSION wird vorbereitet"

exit 0
