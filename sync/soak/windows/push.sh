#!/bin/bash
# From the dev box: put a source tree and this line's scripts into the Windows
# VM on the Mac mini, and start a build there.
#
# The tree is the repo's HEAD by default (git archive, so exactly what is
# committed and nothing in the working copy), or any directory holding a
# sync/ tree. The build stages new binaries; the line picks them up at the
# start of its next run (reset.ps1), never under a run in flight.
#
# usage: push.sh [--scripts-only] [--label TEXT] [DIR]
#        DIR contains sync/ (default: git HEAD); TEXT names that tree in the
#        run logs (default: the commit, or the tree's content hash)

set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO=/var/www/html/joinerytest
UTMCTL=/Applications/UTM.app/Contents/MacOS/utmctl

scripts_only=0
label=
while [ $# -gt 0 ]; do
	case "$1" in
		--scripts-only) scripts_only=1; shift ;;
		--label) label=$2; shift 2 ;;
		*) break ;;
	esac
done
DIR=${1:-}

ip=$(ssh macmini "$UTMCTL ip-address Windows" 2>/dev/null | head -1)
case "$ip" in
	*.*.*.*) ;;
	*) echo "the Windows VM has no address (is it running? ssh macmini $UTMCTL status Windows)" >&2; exit 1 ;;
esac
VM=(ssh -o ConnectTimeout=15 -o BatchMode=yes -J macmini -i "$HOME/.ssh/id_ed25519_claude" "joinery@$ip")
VM_SCP=(scp -q -o ConnectTimeout=15 -o BatchMode=yes -J macmini -i "$HOME/.ssh/id_ed25519_claude")

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

echo "== scripts to C:\\soak-win"
"${VM[@]}" 'New-Item -ItemType Directory -Force C:\soak-win | Out-Null'
for f in build.ps1 device.ps1 forever.ps1 install.ps1 provision.ps1 reset.ps1 fleet.json; do
	"${VM_SCP[@]}" "$HERE/$f" "joinery@$ip:C:/soak-win/$f"
done
[ "$scripts_only" = 1 ] && { echo "== done (scripts only)"; exit 0; }

if [ -z "$DIR" ]; then
	DIR="$work/tree"
	mkdir -p "$DIR"
	git -C "$REPO" archive HEAD sync | tar x -C "$DIR"
	source="$(git -C "$REPO" log -1 --format='%h %s')"
else
	source="tree $DIR ($(cd "$DIR" && find sync -type f -not -path '*/target/*' -print0 | sort -z | xargs -0 cat | md5sum | cut -c1-8))"
fi
[ -n "$label" ] && source="$label"
echo "$source" > "$work/SOURCE"
echo "== source: $source"
tar -czf "$work/sync-src.tgz" --exclude=target --exclude=.git -C "$DIR" sync -C "$work" --transform 's,^SOURCE$,sync/SOURCE,' SOURCE
"${VM_SCP[@]}" "$work/sync-src.tgz" "joinery@$ip:C:/soak-win/sync-src.tgz"

echo "== building (SoakWin-Build); read: Get-Content C:\\soak-win\\build.log -Tail 30"
"${VM[@]}" 'Start-ScheduledTask SoakWin-Build'
