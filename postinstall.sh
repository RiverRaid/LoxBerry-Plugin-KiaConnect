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

# hyundai_kia_connect_api benoetigt Python >=3.12. Viele LoxBerry-Systeme
# (auch dieses) laufen aber noch mit einem aelteren System-Python (z.B.
# 3.9 auf Debian Bullseye). Daher wird eine eigene, portable Python-3.12-
# Umgebung heruntergeladen (python-build-standalone, vorkompiliert, kein
# Bauen aus dem Quellcode noetig) und ausschliesslich fuer dieses Plugin
# verwendet - das System-Python bleibt unangetastet.
PYSTANDALONE_RELEASE="20260901"
PYSTANDALONE_VERSION="3.12.14"
PYDIR="$PDATA/python312"

ARCH=$(uname -m)
case "$ARCH" in
	aarch64)
		PYTARGET="aarch64-unknown-linux-gnu"
		;;
	armv7l)
		PYTARGET="armv7-unknown-linux-gnueabihf"
		;;
	x86_64)
		PYTARGET="x86_64-unknown-linux-gnu"
		;;
	*)
		echo "<ERROR> postinstall.sh: Nicht unterstuetzte Architektur: $ARCH"
		exit 2
		;;
esac

if [ ! -x "$PYDIR/bin/python3.12" ]; then
	echo "<INFO> postinstall.sh: Lade portable Python-$PYSTANDALONE_VERSION-Umgebung fuer $PYTARGET"
	PYURL="https://github.com/astral-sh/python-build-standalone/releases/download/${PYSTANDALONE_RELEASE}/cpython-${PYSTANDALONE_VERSION}+${PYSTANDALONE_RELEASE}-${PYTARGET}-install_only.tar.gz"
	rm -rf "$PDATA/python312.tmp"
	mkdir -p "$PDATA/python312.tmp"
	curl -sL "$PYURL" -o "$PDATA/python312.tmp/python312.tar.gz"
	if [ $? -ne 0 ] || [ ! -s "$PDATA/python312.tmp/python312.tar.gz" ]; then
		echo "<ERROR> postinstall.sh: Download der Python-Umgebung fehlgeschlagen"
		rm -rf "$PDATA/python312.tmp"
		exit 2
	fi
	tar xzf "$PDATA/python312.tmp/python312.tar.gz" -C "$PDATA/python312.tmp"
	rm -rf "$PYDIR"
	mv "$PDATA/python312.tmp/python" "$PYDIR"
	rm -rf "$PDATA/python312.tmp"
	if [ ! -x "$PYDIR/bin/python3.12" ]; then
		echo "<ERROR> postinstall.sh: Entpacken der Python-Umgebung fehlgeschlagen"
		exit 2
	fi
else
	echo "<INFO> postinstall.sh: Portable Python-Umgebung existiert bereits, wird wiederverwendet"
fi

# Marker-Datei mit der Python-Version, mit der die venv gebaut wurde.
# Weicht sie von der aktuell gewuenschten Version ab (z.B. weil ein
# spaeteres Plugin-Update auf eine neuere Python-Version umstellt), wird
# die venv verworfen und neu gebaut, statt eine inkompatible venv
# weiterzuverwenden.
VENVDIR="$PDATA/venv"
VENV_MARKER="$VENVDIR/.kia2lox_python_version"
if [ -d "$VENVDIR" ] && [ "$(cat "$VENV_MARKER" 2>/dev/null)" != "$PYSTANDALONE_VERSION" ]; then
	echo "<INFO> postinstall.sh: Vorhandene Python-venv passt nicht zur aktuellen Python-Version, wird neu erstellt"
	rm -rf "$VENVDIR"
fi

if [ ! -d "$VENVDIR" ]; then
	echo "<INFO> postinstall.sh: Erzeuge Python-venv fuer das Plugin"
	"$PYDIR/bin/python3.12" -m venv "$VENVDIR"
	if [ $? -ne 0 ]; then
		echo "<ERROR> postinstall.sh: Anlegen der Python-venv fehlgeschlagen"
		exit 2
	fi
	echo "$PYSTANDALONE_VERSION" > "$VENV_MARKER"
else
	echo "<INFO> postinstall.sh: Python-venv existiert bereits, wird wiederverwendet"
fi

echo "<INFO> postinstall.sh: Installiere/aktualisiere Python-Abhaengigkeiten"
"$VENVDIR/bin/pip" install --quiet --upgrade pip
"$VENVDIR/bin/pip" install --quiet "hyundai_kia_connect_api==4.28.0"
if [ $? -ne 0 ]; then
	echo "<ERROR> postinstall.sh: Installation der Python-Abhaengigkeiten fehlgeschlagen"
	exit 2
fi

echo "<OK> postinstall.sh erfolgreich abgeschlossen"

exit 0
