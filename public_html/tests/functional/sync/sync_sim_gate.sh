#!/bin/bash
# @joinery-test
# name: sync_sim
# tier: safe
# env: any
# needs: [rust]
# timeout: 600
#
# The two harnesses the sync client is verified with, held to their own
# standards: the simulation harness ({repo root}/sync/jd-sim/) — virtual
# filesystem with per-OS personality, mock server implementing the Part-I
# contract, controlled clock and seeded RNG, fault matrix — and the soak rig
# ({repo root}/sync/jd-soak/) — persona state machines, the journal the no-loss
# oracle reads, the tree differ, the six settle assertions, and the conductor.
#
# This gate covers the harnesses THEMSELVES — that the world the engine is tested in
# behaves the way a world does. A simulator with a bug in it does not report a
# problem; it reports a clean run, which is worse than no simulator at all. So
# the mock server is held to its own contract here (feed resets, chunk offset
# conflicts, dedup, quota at completion, idempotent replay, version history),
# the virtual filesystem is held to its personalities, and the seeded RNG is
# pinned to a fixed output stream so a regression seed frozen in the repo still
# reproduces its bug a year later.
#
# jd-sim is entirely in memory with no real clock. jd-soak's own tests are mostly
# the same, plus a handful that run the whole conductor for a couple of seconds
# against a faked server and a scratch directory — including the one that matters
# most, which puts the rig in a world with no client running at all and requires
# it to FAIL. A verifier that passed there would pass over a broken client too.
#
# The multi-week campaign is operations rather than a test tier, and the bounded
# live cycle against real daemons is tests/functional/sync/sync_soak_gate.sh.
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
		echo "FAIL: cargo unavailable — cannot build the verification harnesses (declare/enforce needs:[rust])"
		exit 1
	fi
fi

# More threads than cores on purpose. jd-soak's conductor tests are dominated by
# deliberate waiting — a storm segment, a settle deadline — rather than by work,
# so the default (one thread per core) leaves the box idle and the gate slow.
cargo test -p jd-sim -p jd-soak --manifest-path "$SYNC_DIR/Cargo.toml" --quiet \
	-- --test-threads=8

echo "sync sim gate: jd-sim and jd-soak green"
