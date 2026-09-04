#!/bin/bash

# preinstall.sh - Executed before plugin files are copied to their destinations.
# Runs as user "loxberry" AFTER preroot.sh and AFTER preupgrade.sh (on updates).
#
# Exit codes: 0 = success, 1 = warning, 2 = error (cancels installation)

COMMAND=$0
PTEMPDIR=$1
PSHNAME=$2
PDIR=$3
PVERSION=$4
PTEMPPATH=$6

echo "<INFO> preinstall.sh: Plugin $PSHNAME Version $PVERSION wird nach $PDIR installiert"

exit 0
