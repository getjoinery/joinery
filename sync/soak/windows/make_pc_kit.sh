#!/bin/bash
# Make the kit that runs the Windows soak line on a person's own x64 Windows
# computer, one nobody can reach: unzipped and started with install.cmd, it
# runs the client's test suite once and then the soak, and sends every result
# to the line's mailbox on the soak instance (jd-soak send), which
# read_mailbox.sh reads back here. Run from the dev box, which reaches the
# rig, the mini and, through the mini, the Windows VM that builds the kit:
#
#   1. the mailbox account, soak-mailbox@getjoinery.com, with a password of its
#      own kept in ~/.config/joinery-soak/mailbox.env (made once);
#   2. a pool of soak-rig-pc-N accounts on the rig, one per run, with a
#      password of the line's own in ~/.config/joinery-soak/pc_pool.env (made
#      once; the kit goes to somebody else's computer, so it carries no other
#      line's password); purge_soak.php keeps the line purged like every other
#      (an account made ahead holds nothing and is never purged before its
#      turn);
#   3. the x64 client, orchestrator and test programs, built in the VM by
#      kit_build.ps1 from the repo's HEAD (or DIR);
#   4. the kit folder, zipped: install.cmd, install_pc.ps1, uninstall.cmd,
#      README.txt, files\ (what goes to C:\soak-win) and secrets\ (the two
#      passwords, which install_pc.ps1 removes from the kit once copied).
#
# The zip holds the soak and mailbox passwords: test accounts on the test
# instance, and nothing else. Hand it to its one computer, not around.
#
# usage: make_pc_kit.sh [--accounts N] [--out FILE.zip] [--built] [DIR]
#        N accounts in the pool (default 240: twenty days at a run every two
#        hours); DIR contains sync/ (default: git HEAD); --built takes the
#        programs from the VM's last kit build (C:\soak-win\kit) instead of
#        building them again, when only this folder's scripts have changed

set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO=/var/www/html/joinerytest
RIG="root@45.33.72.32"
KEY="$HOME/.ssh/id_ed25519_claude"
RIG_SSH=(ssh -i "$KEY" -o ConnectTimeout=10 "$RIG")
RIG_SCP=(scp -q -i "$KEY" -o ConnectTimeout=10)
MINI=macmini
CONTAINER=drivetest
UTMCTL=/Applications/UTM.app/Contents/MacOS/utmctl
CONF="$HOME/.config/joinery-soak"
MAILBOX=soak-mailbox@getjoinery.com
LINE=pc
SEED=39000

accounts=240
out=
built=0
while [ $# -gt 0 ]; do
	case "$1" in
		--accounts) accounts=$2; shift 2 ;;
		--out) out=$2; shift 2 ;;
		--built) built=1; shift ;;
		*) break ;;
	esac
done
DIR=${1:-}

ip=$(ssh "$MINI" "$UTMCTL ip-address Windows" 2>/dev/null | head -1)
case "$ip" in
	*.*.*.*) ;;
	*) echo "the Windows VM has no address (is it running?)" >&2; exit 1 ;;
esac
VM=(ssh -o ConnectTimeout=15 -o BatchMode=yes -J "$MINI" -i "$KEY" "joinery@$ip")
VM_SCP=(scp -q -o ConnectTimeout=15 -o BatchMode=yes -J "$MINI" -i "$KEY")

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
kit="$work/joinery-drive-soak-kit"
mkdir -p "$kit/files" "$kit/secrets"

# An account on the rig, made or confirmed; the password travels on stdin.
make_account() { # <account> <password>
	local result
	result=$(printf '%s' "$2" | "${RIG_SSH[@]}" \
		"docker exec -i -e SOAK_ACCOUNT=$1 $CONTAINER sh -c 'SOAK_PASSWORD=\$(cat) php /tmp/soak_account_setup.php'" 2>&1 | tail -1)
	[ "$result" = "OK" ] || { echo "   $1 FAILED: $result" >&2; return 1; }
}

echo "== the rig's account tool"
"${RIG_SCP[@]}" "$HERE/../soak_account_setup.php" "$RIG:/root/soak_account_setup.php"
"${RIG_SSH[@]}" "docker cp /root/soak_account_setup.php $CONTAINER:/tmp/soak_account_setup.php >/dev/null"

echo "== the mailbox, $MAILBOX"
mkdir -p "$CONF"
chmod 700 "$CONF"
if [ ! -f "$CONF/mailbox.env" ]; then
	(umask 077; printf 'JD_REPORT_ACCOUNT=%s\nJD_REPORT_PASSWORD=%s\n' "$MAILBOX" \
		"$(head -c 24 /dev/urandom | base64 | tr -d '/+=')aA1" > "$CONF/mailbox.env")
	echo "   a password made, in $CONF/mailbox.env"
fi
mailbox_password=$(sed -n 's/^JD_REPORT_PASSWORD=//p' "$CONF/mailbox.env")
make_account "$MAILBOX" "$mailbox_password"
echo "   there"

echo "== the pool: soak-rig-$LINE-1 to soak-rig-$LINE-$accounts"
made="$CONF/pc_pool.txt"
if [ ! -f "$CONF/pc_pool.env" ]; then
	(umask 077; printf 'JD_SOAK_PASSWORD=%s\n' "$(head -c 24 /dev/urandom | base64 | tr -d '/+=')aA1" > "$CONF/pc_pool.env")
	# Every account made so far has another password: made again, which
	# sets this one (soak_account_setup.php is safe on an account that exists).
	rm -f "$made"
	echo "   a password of the line's own, in $CONF/pc_pool.env"
fi
password=$(sed -n 's/^JD_SOAK_PASSWORD=//p' "$CONF/pc_pool.env")
# Kept: a second kit draws from the same pool, after the accounts the first
# one was given.
touch "$made"
have=$(wc -l < "$made")
for n in $(seq $((have + 1)) "$accounts"); do
	make_account "soak-rig-$LINE-$n@getjoinery.com" "$password"
	echo "soak-rig-$LINE-$n@getjoinery.com" >> "$made"
done
cp "$made" "$kit/files/accounts.txt"
echo "   $(wc -l < "$made") account(s)"

if [ "$built" = 0 ]; then
echo "== the source"
if [ -z "$DIR" ]; then
	DIR="$work/tree"
	mkdir -p "$DIR"
	git -C "$REPO" archive HEAD sync | tar x -C "$DIR"
	source="$(git -C "$REPO" log -1 --format='%h %s')"
else
	source="tree $DIR ($(cd "$DIR" && find sync -type f -not -path '*/target/*' -print0 | sort -z | xargs -0 cat | md5sum | cut -c1-8))"
fi
# The label rides in the archive, not in DIR: DIR is somebody's tree.
echo "$source" > "$work/SOURCE"
echo "   $source"
tar -czf "$work/kit-src.tgz" --exclude=target --exclude=.git -C "$DIR" sync -C "$work" --transform 's,^SOURCE$,sync/SOURCE,' SOURCE
"${VM_SCP[@]}" "$work/kit-src.tgz" "joinery@$ip:C:/soak-win/kit-src.tgz"
"${VM_SCP[@]}" "$HERE/kit_build.ps1" "joinery@$ip:C:/soak-win/kit_build.ps1"

echo "== building in the VM (C:\\soak-win\\kit-build.log)"
"${VM[@]}" '
	$a = New-ScheduledTaskAction -Execute powershell.exe -Argument "-ExecutionPolicy Bypass -NoProfile -File C:\soak-win\kit_build.ps1" -WorkingDirectory C:\soak-win
	$s = New-ScheduledTaskSettingsSet -ExecutionTimeLimit ([TimeSpan]::Zero) -MultipleInstances IgnoreNew -Priority 7
	$p = New-ScheduledTaskPrincipal -UserId $env:USERNAME -LogonType S4U -RunLevel Highest
	Register-ScheduledTask -TaskName SoakWin-KitBuild -Action $a -Settings $s -Principal $p -Force | Out-Null
	Start-ScheduledTask SoakWin-KitBuild'
while :; do
	sleep 60
	# Windows ends its lines with a carriage return, which no pattern below expects.
	last=$("${VM[@]}" 'Get-Content C:\soak-win\kit-build.log -Tail 1' 2>/dev/null | tr -d '\r' || true)
	case "$last" in *"=== kit build exit"*) break ;; esac
done
echo "   $last"
"${VM[@]}" 'Unregister-ScheduledTask SoakWin-KitBuild -Confirm:$false' || true
case "$last" in *"exit 0") ;; *)
	"${VM[@]}" 'Get-Content C:\soak-win\kit-build.log -Tail 40'
	echo "the kit build failed" >&2; exit 1 ;;
esac
else
	echo "== the VM's last kit build: $("${VM[@]}" 'Get-Content C:\soak-win\kit\bin\SOURCE' | tr -d '\r')"
fi
# The build stays in the VM for the next --built.
"${VM[@]}" 'tar -czf C:\soak-win\kit.tgz -C C:\soak-win kit'
"${VM_SCP[@]}" "joinery@$ip:C:/soak-win/kit.tgz" "$work/kit.tgz"
"${VM[@]}" 'Remove-Item C:\soak-win\kit.tgz -Force'
tar -xzf "$work/kit.tgz" -C "$work"
cp -r "$work/kit/bin" "$work/kit/tests" "$kit/files/"
echo "   $(wc -l < "$kit/files/tests/programs.txt") test programs"

echo "== the kit"
for f in device.ps1 forever.ps1 install.ps1 power.ps1 provision.ps1 reset.ps1 run_tests.ps1 uninstall.ps1 fleet.json; do
	cp "$HERE/$f" "$kit/files/$f"
done
printf 'LINE=%s\nSEED=%s\n' "$LINE" "$SEED" > "$kit/files/line.env"
cp "$HERE/install_pc.ps1" "$kit/"
# What a person opens or double-clicks, with Windows line ends: cmd reads a
# batch file with Unix ones wrongly in places, and Notepad once showed them
# as one line.
for f in install.cmd uninstall.cmd; do sed 's/$/\r/' "$HERE/$f" > "$kit/$f"; done
sed 's/$/\r/' "$HERE/kit_readme.txt" > "$kit/README.txt"
cp "$kit/uninstall.cmd" "$kit/files/uninstall.cmd"
(umask 077
	printf 'JD_SOAK_PASSWORD=%s\n' "$password" > "$kit/secrets/soak.env"
	cp "$CONF/mailbox.env" "$kit/secrets/report.env")
out=${out:-$HOME/drive-work/joinery-drive-soak-kit-$(date -u +%Y%m%d).zip}
mkdir -p "$(dirname "$out")"
rm -f "$out"
(cd "$work" && umask 077 && python3 -m zipfile -c "$out" "$(basename "$kit")")
echo "== $out ($(du -h "$out" | cut -f1)); it holds the soak and mailbox passwords"
