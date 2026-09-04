#!/bin/bash

# uninstall.sh - Executed when the plugin is uninstalled via the LoxBerry Plugin Manager.
# Runs as user "root".
# The plugin's own directories (config, data, html, cgi, templates, log, bin, sbin)
# are removed automatically by LoxBerry - no need to delete them here.
#
# Exit codes: 0 = success, 1 = warning

PSHNAME=$2

echo "<INFO> uninstall.sh: Plugin $PSHNAME wird entfernt, keine externen Aufraeumarbeiten notwendig"

exit 0
