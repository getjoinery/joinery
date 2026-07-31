#!/bin/bash
# @joinery-test
# name: sync_sim
# tier: safe
# env: any
# needs: [rust]
# timeout: 600
#
# The sync engine's simulation harness ({repo root}/sync/jd-sim/): the virtual
# filesystem with per-OS personality, the mock server implementing the Part-I
# contract, the controlled clock and seeded RNG, and the fault matrix.
#
# This gate covers the harness ITSELF — that the world the engine is tested in
# behaves the way a world does. A simulator with a bug in it does not report a
# problem; it reports a clean run, which is worse than no simulator at all. So
# the mock server is held to its own contract here (feed resets, chunk offset
# conflicts, dedup, quota at completion, idempotent replay, version history),
# the virtual filesystem is held to its personalities, and the seeded RNG is
# pinned to a fixed output stream so a regression seed frozen in the repo still
# reproduces its bug a year later.
#
# Everything is in memory with no real clock, so the whole suite runs in
# milliseconds and belongs in the safe tier.
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
		echo "FAIL: cargo unavailable — cannot build the simulation harness (declare/enforce needs:[rust])"
		exit 1
	fi
fi

cargo test -p jd-sim --manifest-path "$SYNC_DIR/Cargo.toml" --quiet

echo "sync sim gate: jd-sim green"
