#!/bin/bash
# @joinery-test
# name: sync_engine
# tier: safe
# env: any
# needs: [rust]
# covers: [sync/**]
# timeout: 300
#
# The sync client's decision-making, everywhere it is a pure function
# ({repo root}/sync/): the filesystem adaptation rules (jd-vfs), the
# reconciliation matrix, conflict policy, operation ordering, naming, and
# mass-delete guard (jd-core), where secrets and login items belong
# (jd-platform), and the health model the tray draws (jd-daemon, jd-shell).
#
# These are the parts that decide whether somebody's file gets deleted, and
# they are written as pure functions precisely so they can be exercised
# exhaustively without a network, a disk, or a clock. Everything here runs in
# milliseconds, which is why it belongs in the safe tier rather than behind a
# live gate.
# Shell-gate contract: exit 0 = pass, non-zero = fail.

set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SYNC_DIR="$(cd "$HERE/../../../.." && pwd)/sync"

# A safe-tier gate must NOT pass by absence of its runtime (see
# drive_crypto_gate.sh). The runner's needs:[rust] probe reports a genuinely
# missing toolchain as SKIP; reaching this line without cargo is a failure.
if ! command -v cargo >/dev/null 2>&1; then
	if [ -x "$HOME/.cargo/bin/cargo" ]; then
		export PATH="$HOME/.cargo/bin:$PATH"
	elif [ -x /home/user1/.cargo/bin/cargo ]; then
		export PATH="/home/user1/.cargo/bin:$PATH"
	else
		echo "FAIL: cargo unavailable — cannot build the sync engine (declare/enforce needs:[rust])"
		exit 1
	fi
fi

nice -n 19 cargo test -p jd-vfs -p jd-core -p jd-platform -p jd-daemon \
	--manifest-path "$SYNC_DIR/Cargo.toml" --quiet

# jd-shell has no library target, so its pure presentation logic is reached
# through the binary's own tests rather than a lib test.
nice -n 19 cargo test --bin joinery-drive-tray --manifest-path "$SYNC_DIR/Cargo.toml" --quiet

echo "sync engine gate: jd-vfs + jd-core + jd-platform + jd-daemon + jd-shell green"
