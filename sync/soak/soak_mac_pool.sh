#!/bin/bash
# Give one of the Mac mini's soak lines more accounts, and keep them purged.
#
# The Mac campaign (~/soak-mac on the mini, accounts soak-mac-N) and the FAT
# campaign (~/soak-fat, accounts soak-mac-fat-N) each run against the soak rig's
# instance, one fresh account per run, taken in order from its accounts.txt.
# It cannot make accounts itself: they are made inside the rig's container,
# the way soak_reset.sh makes soak-rig-N. This runs from the dev box, which
# can reach both:
#
#   1. reads the campaign's password from the mini's soak.env (never printed,
#      never on a command line: it travels on stdin);
#   2. makes <prefix>-<next>..<prefix>-<next+count-1> on the rig with
#      soak_account_setup.php, which is safe to run on an account that exists;
#   3. appends each one that came back OK to the mini's accounts.txt;
#   4. installs purge_soak.php on the rig, which keeps each line's pool
#      purged on its own without ever touching the account in use.
#
# It does not start the campaign.
#
# usage: soak_mac_pool.sh [count] [line]   (count default 30; line mac or fat, default mac)

set -euo pipefail

COUNT=${1:-30}
LINE=${2:-mac}
case "$LINE" in
	mac) PREFIX=soak-mac ;;
	fat) PREFIX=soak-mac-fat ;;
	*) echo "line must be mac or fat" >&2; exit 1 ;;
esac
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RIG="root@45.33.72.32"
RIG_SSH=(ssh -i "$HOME/.ssh/id_ed25519_claude" -o ConnectTimeout=10 "$RIG")
RIG_SCP=(scp -q -i "$HOME/.ssh/id_ed25519_claude" -o ConnectTimeout=10)
MINI=macmini
POOL="~/soak-$LINE/accounts.txt"
CONTAINER=drivetest

password=$(ssh "$MINI" "sed -n 's/^JD_SOAK_PASSWORD=//p' ~/soak-$LINE/soak.env")
if [ -z "$password" ]; then
	echo "no JD_SOAK_PASSWORD in the mini's ~/soak-$LINE/soak.env" >&2
	exit 1
fi

last=$(ssh "$MINI" "sed -n 's/^$PREFIX-\\([0-9]*\\)@.*/\\1/p' $POOL" | sort -n | tail -1)
first=$(( ${last:-0} + 1 ))
echo "== making $PREFIX-$first to $PREFIX-$((first + COUNT - 1))"

"${RIG_SCP[@]}" "$HERE/soak_account_setup.php" "$RIG:/root/soak_account_setup.php"
"${RIG_SSH[@]}" "docker cp /root/soak_account_setup.php $CONTAINER:/tmp/soak_account_setup.php >/dev/null"

made=0
for n in $(seq "$first" $((first + COUNT - 1))); do
	account="$PREFIX-$n@getjoinery.com"
	result=$(printf '%s' "$password" | "${RIG_SSH[@]}" \
		"docker exec -i -e SOAK_ACCOUNT=$account $CONTAINER sh -c 'SOAK_PASSWORD=\$(cat) php /tmp/soak_account_setup.php'" 2>&1 | tail -1)
	if [ "$result" != "OK" ]; then
		echo "   $PREFIX-$n FAILED: $result" >&2
		echo "   stopping; accounts.txt holds every account made before this one" >&2
		exit 1
	fi
	ssh "$MINI" "echo $account >> $POOL"
	made=$((made + 1))
done
echo "   made $made; the mini's pool now holds $(ssh "$MINI" "wc -l < $POOL") account(s)"

echo "== installing purge_soak.php on the rig"
"${RIG_SCP[@]}" "$HERE/purge_soak.php" "$RIG:/root/purge_soak.php"
"${RIG_SSH[@]}" "docker cp /root/purge_soak.php $CONTAINER:/tmp/purge_soak.php >/dev/null && docker exec $CONTAINER php /tmp/purge_soak.php 25 --max-accounts=8" | head -3
echo "== done (dry run shown above; the rig loop runs it with --apply between campaigns)"
