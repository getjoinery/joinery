#!/bin/bash
# Return the soak rig to a fresh state, so a campaign measures the client and
# not the leftovers of the last campaign.
#
# Every run so far was reset by hand, and the hand-reset had a step that is easy
# to skip and expensive to skip: a reset wipes /soak/bundles, which is where the
# evidence from the run you just finished lives. So archiving comes first here,
# unconditionally, before anything is removed.
#
# The rig signs in as a fresh account each time. Reusing one across resets leaves
# the server holding the previous run's tree while the devices start empty, and
# every verdict afterwards is about that mismatch.
set -euo pipefail

ENV_FILE=/etc/jd-soak.env
FLEET=/soak/fleet.json
CONTAINER=drivetest
LABEL="${1:-pre-reset}"

stamp=$(date +%Y%m%d-%H%M%S)
archive="/root/soak-evidence/${LABEL}-${stamp}"

echo "== archiving current evidence to $archive"
mkdir -p "$archive"
cp -a /soak/bundles/. "$archive/" 2>/dev/null || true
cp -a /soak/journal "$archive/journal" 2>/dev/null || true
for f in /soak/strace-soak-a.log /soak/strace-soak-b.log /soak/killer.log; do
	[ -f "$f" ] && cp -a "$f" "$archive/" || true
done
# The trashes, which the reset below wipes. The engine never unlinks, so a file
# it decided to remove is *in* one of these — which makes them the difference
# between a no-loss verdict that is a measurement and one that is an accusation.
# Runs 19 to 21 cannot be re-derived because this step did not exist for them.
for d in a b; do
	src="/var/lib/soak-$d/.local/share/Trash"
	[ -d "$src" ] && cp -a "$src" "$archive/trash-soak-$d" || true
done

# What the client BELIEVED, alongside what was on the disk. An audited-green
# failure is a disagreement between a device and the server, and neither the
# journal nor the bundles record the third party to it: the entry rows saying
# which name the client thought was agreed. Without these the only way to read
# such a failure is to guess, and the guess costs a campaign. Both files are a
# few megabytes; the tree listings are text.
for d in a b; do
	src="/soak/device-$d/home/state/state.db"
	[ -f "$src" ] && sqlite3 -readonly "$src" ".backup ${archive}/device-${d}-state.db" 2>/dev/null || true
	[ -d "/soak/device-$d/root" ] && \
		find "/soak/device-$d/root" -printf "%y %s %p\n" \
		> "${archive}/device-${d}-tree.txt" 2>/dev/null || true
done

echo "== stopping the fleet"
systemctl stop soak-device@a soak-device@b 2>/dev/null || true
sleep 2
pkill -x strace 2>/dev/null || true
# Deliberately not "pkill -f jd-soak orchestrate": run over ssh, that pattern
# matches the ssh command line carrying it and kills the shell mid-reset.
pkill -x jd-soak 2>/dev/null || true
sleep 1

echo "== wiping trees, journal and both trashes"
rm -rf /soak/device-a/root /soak/device-a/home
rm -rf /soak/device-b/root /soak/device-b/home
rm -rf /soak/journal /soak/bundles
rm -rf /var/lib/soak-a/.local/share/Trash /var/lib/soak-b/.local/share/Trash
rm -f /soak/strace-soak-a.log /soak/strace-soak-b.log

echo "== recreating directories"
mkdir -p /soak/journal /soak/bundles
for d in a b; do
	mkdir -p "/soak/device-$d/root" "/soak/device-$d/home"
	# setgid on the root: the actors run as root and the daemon as its own
	# account, so group ownership has to survive every file either one creates.
	chown "soak-$d:soak-$d" "/soak/device-$d/root" "/soak/device-$d/home"
	chmod 2775 "/soak/device-$d/root"
	chmod 2755 "/soak/device-$d/home"
	mkdir -p "/var/lib/soak-$d/.local/share/Trash/files" \
	         "/var/lib/soak-$d/.local/share/Trash/info"
	chown -R "soak-$d:soak-$d" "/var/lib/soak-$d/.local/share/Trash"
done

echo "== allocating the next rig account"
# shellcheck disable=SC1090
set -a; . "$ENV_FILE"; set +a
current="${JD_SOAK_ACCOUNT:-}"
n=$(printf '%s' "$current" | sed -n 's/^soak-rig-\([0-9]*\)@.*/\1/p')
if [ -z "$n" ]; then
	echo "cannot read a rig number out of JD_SOAK_ACCOUNT; fix $ENV_FILE by hand" >&2
	exit 1
fi
next=$((n + 1))
new_account="soak-rig-${next}@getjoinery.com"
umask 077
{
	printf 'JD_SOAK_ACCOUNT=%s\n' "$new_account"
	printf 'JD_SOAK_PASSWORD=%s\n' "$JD_SOAK_PASSWORD"
} > "$ENV_FILE"
chmod 600 "$ENV_FILE"
export JD_SOAK_ACCOUNT="$new_account"
echo "   rig account is now soak-rig-${next}"

echo "== creating the account, tier and membership on the server"
docker cp /root/soak_account_setup.php "$CONTAINER:/tmp/soak_account_setup.php" >/dev/null
docker exec \
	-e SOAK_ACCOUNT="$JD_SOAK_ACCOUNT" \
	-e SOAK_PASSWORD="$JD_SOAK_PASSWORD" \
	"$CONTAINER" php /tmp/soak_account_setup.php

echo "== provisioning devices"
jd-soak provision "$FLEET"

# provision runs as root and writes into the device homes; the daemons cannot
# read what root left behind unless ownership goes back.
for d in a b; do
	chown -R "soak-$d:soak-$d" "/soak/device-$d/home"
done

echo "== starting the fleet"
systemctl start soak-device@a soak-device@b
sleep 3
systemctl is-active soak-device@a soak-device@b

echo "== reset complete"
