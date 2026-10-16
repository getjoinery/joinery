#!/bin/bash
# Give the Windows soak line (C:\soak-win in the Windows VM on the Mac mini)
# accounts on the soak rig's instance, the way soak_mac_pool.sh does for the
# Mac line. Run from the dev box, which can reach the rig, the mini and,
# through the mini, the VM:
#
#   1. reads the soak password from the mini's ~/soak-mac/soak.env (never
#      printed, never on a command line: it travels on stdin);
#   2. writes the VM's C:\soak-win\soak.env with it, if there is none yet,
#      in a file only the VM's account can read;
#   3. makes soak-mac-win-<next>..soak-mac-win-<next+count-1> on the rig with
#      soak_account_setup.php, which is safe to run on an account that exists;
#   4. appends each one that came back OK to the VM's accounts.txt;
#   5. installs purge_soak.php on the rig, which keeps every soak line's pool
#      purged on its own (soak-mac-win-* is one) without touching the account
#      a line is using.
#
# It does not start the line.
#
# usage: soak_win_pool.sh [count]      (default 30)

set -euo pipefail

COUNT=${1:-30}
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RIG="root@45.33.72.32"
RIG_SSH=(ssh -i "$HOME/.ssh/id_ed25519_claude" -o ConnectTimeout=10 "$RIG")
RIG_SCP=(scp -q -i "$HOME/.ssh/id_ed25519_claude" -o ConnectTimeout=10)
MINI=macmini
CONTAINER=drivetest
UTMCTL=/Applications/UTM.app/Contents/MacOS/utmctl

ip=$(ssh "$MINI" "$UTMCTL ip-address Windows" 2>/dev/null | head -1)
case "$ip" in
	*.*.*.*) ;;
	*) echo "the Windows VM has no address (is it running?)" >&2; exit 1 ;;
esac
VM=(ssh -o ConnectTimeout=15 -o BatchMode=yes -J "$MINI" -i "$HOME/.ssh/id_ed25519_claude" "joinery@$ip")

password=$(ssh "$MINI" 'sed -n "s/^JD_SOAK_PASSWORD=//p" ~/soak-mac/soak.env')
if [ -z "$password" ]; then
	echo "no JD_SOAK_PASSWORD in the mini's ~/soak-mac/soak.env" >&2
	exit 1
fi

echo "== the VM's soak.env"
# Created empty, closed to everyone but the VM's account, and only then
# written, so the password is never in a file others can read.
printf 'JD_SOAK_PASSWORD=%s\n' "$password" | "${VM[@]}" '
	$f = "C:\soak-win\soak.env"
	$body = [Console]::In.ReadToEnd()
	if (Test-Path $f) { "   already there"; exit 0 }
	New-Item -ItemType File -Force $f | Out-Null
	icacls $f /inheritance:r /grant:r "$($env:USERNAME):F" | Out-Null
	[IO.File]::WriteAllText($f, $body)
	"   written, readable by $env:USERNAME only"'

last=$("${VM[@]}" 'if (Test-Path C:\soak-win\accounts.txt) { Get-Content C:\soak-win\accounts.txt }' |
	sed -n 's/^soak-mac-win-\([0-9]*\)@.*/\1/p' | sort -n | tail -1)
first=$(( ${last:-0} + 1 ))
echo "== making soak-mac-win-$first to soak-mac-win-$((first + COUNT - 1))"

"${RIG_SCP[@]}" "$HERE/../soak_account_setup.php" "$RIG:/root/soak_account_setup.php"
"${RIG_SSH[@]}" "docker cp /root/soak_account_setup.php $CONTAINER:/tmp/soak_account_setup.php >/dev/null"

made=0
for n in $(seq "$first" $((first + COUNT - 1))); do
	account="soak-mac-win-$n@getjoinery.com"
	result=$(printf '%s' "$password" | "${RIG_SSH[@]}" \
		"docker exec -i -e SOAK_ACCOUNT=$account $CONTAINER sh -c 'SOAK_PASSWORD=\$(cat) php /tmp/soak_account_setup.php'" 2>&1 | tail -1)
	if [ "$result" != "OK" ]; then
		echo "   soak-mac-win-$n FAILED: $result" >&2
		echo "   stopping; accounts.txt holds every account made before this one" >&2
		exit 1
	fi
	"${VM[@]}" "[IO.File]::AppendAllText('C:\soak-win\accounts.txt', '$account' + [char]10)"
	made=$((made + 1))
done
echo "   made $made; the VM's pool now holds $("${VM[@]}" '(Get-Content C:\soak-win\accounts.txt).Count') account(s)"

echo "== installing purge_soak.php on the rig"
"${RIG_SCP[@]}" "$HERE/../purge_soak.php" "$RIG:/root/purge_soak.php"
"${RIG_SSH[@]}" "docker cp /root/purge_soak.php $CONTAINER:/tmp/purge_soak.php >/dev/null && docker exec $CONTAINER php /tmp/purge_soak.php 25 --max-accounts=8" | head -12
echo "== done (dry run shown above; the rig loop runs it with --apply between campaigns)"
