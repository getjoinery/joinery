#!/bin/bash
# @joinery-test
# name: sync_engine
# tier: safe
# env: any
# needs: [rust]
# timeout: 300
#
# The sync engine's decision core ({repo root}/sync/): the filesystem
# adaptation rules (jd-vfs) and the reconciliation matrix, conflict policy,
# operation ordering, and mass-delete guard (jd-core).
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

cargo test -p jd-vfs -p jd-core --manifest-path "$SYNC_DIR/Cargo.toml" --quiet

echo "sync engine gate: jd-vfs + jd-core green"
