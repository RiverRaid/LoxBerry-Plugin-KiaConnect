#!/bin/bash

# postroot.sh - Executed as the absolute last installation step, after everything else.
# Runs as user "root" AFTER postinstall.sh and AFTER postupgrade.sh.
#
# Exit codes: 0 = success, 1 = warning, 2 = error (cancels installation)

echo "<INFO> postroot.sh: keine root-Nacharbeit notwendig (Python-venv und Cron-Jobs laufen als Benutzer loxberry, siehe postinstall.sh)"

exit 0
