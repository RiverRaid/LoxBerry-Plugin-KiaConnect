#!/bin/bash

# preroot.sh - Executed as the very first installation step, before everything else.
# Runs as user "root" BEFORE preinstall.sh and BEFORE preupgrade.sh.
#
# Exit codes: 0 = success, 1 = warning, 2 = error (cancels installation)

echo "<INFO> preroot.sh: keine root-Vorbereitung notwendig (keine Systempakete oder Berechtigungen ausserhalb des Plugin-Verzeichnisses noetig)"

exit 0
