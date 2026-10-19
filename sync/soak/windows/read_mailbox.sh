#!/bin/bash
# Read the soak lines' mailbox (soak-mailbox@getjoinery.com on the soak
# instance) from the dev box: copies every message not fetched yet into DIR
# and prints the new ledger lines. The kit's computer sends one message per
# run (<line>-<computer>-run<N>-<time>-green.txt or -NOT-GREEN.txt), the
# evidence of a run that was not green (-evidence.tgz), the test suite's
# summary, and a note when it is installed and uninstalled (make_pc_kit.sh).
#
# usage: read_mailbox.sh [DIR]     (default ~/drive-work/soak-mailbox)

set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DIR=${1:-$HOME/drive-work/soak-mailbox}
CONF="$HOME/.config/joinery-soak/mailbox.env"
[ -f "$CONF" ] || { echo "no $CONF: make_pc_kit.sh makes it" >&2; exit 1; }

set -a
# shellcheck disable=SC1090
. "$CONF"
set +a
new=$(cd "$HERE/../.." && cargo run -q --release -p jd-soak -- fetch "$DIR" --server https://drivetest.getjoinery.com)
echo "$new" | tail -1
# One name a line, read as a line: a name may hold spaces.
echo "$new" | sed '$d' | while IFS= read -r name; do
	case "$name" in
		# Shown with cat -v: the text came from another computer, and an
		# escape sequence in it must not act on this terminal.
		*.txt) echo "--- $name" | cat -v; head -1 -- "$DIR/$name" | cat -v ;;
		*) echo "--- $name (in $DIR)" | cat -v ;;
	esac
done
