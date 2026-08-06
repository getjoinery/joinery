#!/bin/bash
# Stand the soak rig up on a host. Run as root on the soak VPS.
#
#   setup-host.sh --server https://drivetest.getjoinery.com [--base /soak] [--devices 2]
#
# What it makes:
#   - one unix account per device, which is what lets a network fault cut ONE
#     device's traffic (iptables -m owner --uid-owner) rather than the fleet's
#   - a sync root and a state home per device, owned by that account
#   - the fleet description the four jd-soak roles all read
#   - a systemd template unit per device, with Restart=always — that supervisor
#     is what turns kill -9 into reboot semantics instead of a device that never
#     comes back
#   - the orchestrator unit, which is not started here: it wants credentials in
#     the environment first
#
# It does NOT link the devices. That needs the soak account's password, which
# belongs in the environment and not in a script:
#
#   JD_SOAK_ACCOUNT=... JD_SOAK_PASSWORD=... jd-soak provision /soak/fleet.json
#
# Version 1.0

set -euo pipefail

BASE=/soak
DEVICES=2
SERVER=""
BIN_DIR=/usr/local/bin

while [ $# -gt 0 ]; do
	case "$1" in
		--base)    BASE="$2"; shift 2 ;;
		--devices) DEVICES="$2"; shift 2 ;;
		--server)  SERVER="$2"; shift 2 ;;
		--bin-dir) BIN_DIR="$2"; shift 2 ;;
		*) echo "unknown option: $1" >&2; exit 2 ;;
	esac
done

if [ -z "$SERVER" ]; then
	echo "setup-host.sh needs --server URL — the soak instance, never dev" >&2
	exit 2
fi
if [ "$(id -u)" != "0" ]; then
	echo "setup-host.sh has to run as root: it creates accounts and installs units" >&2
	exit 2
fi

for tool in iptables systemctl; do
	if ! command -v "$tool" >/dev/null 2>&1; then
		# Refused rather than degraded. A rig that silently cannot inject its
		# faults reports a green run over an adversary that was never there,
		# which is worse than not running at all.
		echo "FAIL: $tool is not installed, so the chaos agent could not inject faults" >&2
		exit 1
	fi
done

for binary in joinery-drive jd-soak; do
	if [ ! -x "$BIN_DIR/$binary" ]; then
		echo "FAIL: $BIN_DIR/$binary is missing — build the workspace and install both binaries first" >&2
		exit 1
	fi
done

echo "Setting up the soak rig under $BASE against $SERVER"

# ---------------------------------------------------------------------------
# Accounts and directories
# ---------------------------------------------------------------------------

letters="a b c d e f g h"
i=0
for letter in $letters; do
	i=$((i + 1))
	[ "$i" -le "$DEVICES" ] || break

	user="soak-$letter"
	home="$BASE/device-$letter/home"
	root="$BASE/device-$letter/root"

	if ! id "$user" >/dev/null 2>&1; then
		useradd --system --create-home --home-dir "/var/lib/$user" --shell /usr/sbin/nologin "$user"
		echo "  created account $user"
	fi
	mkdir -p "$home" "$root"
	chown -R "$user:$user" "$BASE/device-$letter"
	# The actors run as root and write into the sync roots; the daemon runs as
	# the device account and has to be able to change what they wrote. Group
	# write plus the setgid bit keeps that true for files created later.
	chmod 2775 "$root"
done

# The journals and bundles are the evidence, and they belong to nobody but the
# orchestrator. Device accounts get no access at all: a daemon that could write
# to the journal directory could, in principle, be the thing that corrupted it.
mkdir -p "$BASE/journal" "$BASE/bundles"
chmod 700 "$BASE/journal" "$BASE/bundles"

# ---------------------------------------------------------------------------
# The fleet description
# ---------------------------------------------------------------------------

if [ -f "$BASE/fleet.json" ]; then
	echo "  $BASE/fleet.json already exists — leaving it alone"
else
	"$BIN_DIR/jd-soak" init "$BASE/fleet.json" --base "$BASE" --server "$SERVER" --devices "$DEVICES"
fi

# ---------------------------------------------------------------------------
# Units
# ---------------------------------------------------------------------------

cat > /etc/systemd/system/soak-device@.service <<UNIT
[Unit]
Description=Joinery Drive sync daemon for soak device %i
After=network-online.target

[Service]
Type=simple
User=soak-%i
Environment=JOINERY_DRIVE_HOME=$BASE/device-%i/home
ExecStart=$BIN_DIR/joinery-drive daemon
# The supervisor the chaos matrix depends on. A killed daemon has to come back
# on its own, or a kill is not a fault the client recovers from — it is a device
# that left the fleet.
Restart=always
RestartSec=2
StandardOutput=append:$BASE/device-%i/home/logs/daemon.log
StandardError=append:$BASE/device-%i/home/logs/daemon.log

[Install]
WantedBy=multi-user.target
UNIT

cat > /etc/systemd/system/jd-soak.service <<UNIT
[Unit]
Description=Joinery Drive sync soak campaign
After=network-online.target

[Service]
Type=simple
User=root
# Root because faults are iptables rules and unit restarts. The orchestrator
# never opens a device's state store — it reads disks and the API, like any
# other observer.
EnvironmentFile=/etc/jd-soak.env
ExecStart=$BIN_DIR/jd-soak orchestrate $BASE/fleet.json
Restart=on-failure
RestartSec=30

[Install]
WantedBy=multi-user.target
UNIT

if [ ! -f /etc/jd-soak.env ]; then
	# Created empty and locked down rather than filled in: the password goes in
	# by hand, and a script that wrote one would put it in the shell history of
	# whoever ran it.
	install -m 600 /dev/null /etc/jd-soak.env
	cat > /etc/jd-soak.env <<'ENV'
# The soak account on the soak instance. Fill these in before starting
# jd-soak.service. This file is mode 600 on purpose.
JD_SOAK_ACCOUNT=
JD_SOAK_PASSWORD=
ENV
	chmod 600 /etc/jd-soak.env
	echo "  wrote /etc/jd-soak.env — put the soak account credentials in it"
fi

systemctl daemon-reload

i=0
for letter in $letters; do
	i=$((i + 1))
	[ "$i" -le "$DEVICES" ] || break
	mkdir -p "$BASE/device-$letter/home/logs"
	chown -R "soak-$letter:soak-$letter" "$BASE/device-$letter/home"
	systemctl enable "soak-device@$letter.service" >/dev/null
done

echo
echo "Done. Next:"
echo "  1. put the soak account in /etc/jd-soak.env"
echo "  2. set -a; . /etc/jd-soak.env; set +a"
echo "  3. jd-soak provision $BASE/fleet.json"
i=0
for letter in $letters; do
	i=$((i + 1))
	[ "$i" -le "$DEVICES" ] || break
	echo "  4. systemctl start soak-device@$letter"
done
echo "  5. systemctl start jd-soak"
echo
echo "The report lands at $BASE/journal/report.txt; bundles at $BASE/bundles."
