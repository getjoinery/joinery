#!/bin/bash
# @joinery-test
# name: sync_macos
# tier: live
# env: dev-only
# needs: [macmini, rust]
# timeout: 1800
#
# The sync client, built and exercised on a real Mac.
#
# Everything the simulator can decide about macOS it already decides on Linux —
# the filesystem's rules are data, so the case-folding and normalization
# scenarios run in the safe tier. What it cannot do is find out whether an actual
# APFS volume behaves the way we told the simulator it does, or whether the real
# Keychain holds anything.
#
# So this gate builds the whole workspace natively and runs its tests, and the
# macOS-only assertions are ordinary test files in the repository rather than a
# program this script writes:
#
#   sync/jd-vfs/tests/macos_volume.rs     the volume probe, the NFD round trip,
#                                         symlinked-root resolution, and the
#                                         case-clash premise
#   sync/jd-platform/tests/native_keychain.rs
#                                         real Keychain custody, and a secret
#                                         read back by a SECOND process — the
#                                         only check an in-process map fails
#
# Both are `#[cfg]`-ed to the platforms they apply to and are inert elsewhere,
# so they are reviewable, run automatically on any Mac, and cannot drift from
# the code the way a generated program does.
#
# The source of truth is this checkout; the mini gets a copy and builds there
# (the established iOS-gate pattern). Never run this alongside a model load on
# the mini — the memory budget is a house rule.
# Shell-gate contract: exit 0 = pass, non-zero = fail.

set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SYNC_DIR="$(cd "$HERE/../../../.." && pwd)/sync"
REMOTE_DIR='~/joinery-sync-gate'

if ! ssh -o ConnectTimeout=10 -o BatchMode=yes macmini true 2>/dev/null; then
	echo "FAIL: the Mac mini is unreachable (declare/enforce needs:[macmini])"
	exit 1
fi

echo "--- copying the workspace to the mini ---"
ssh macmini "mkdir -p $REMOTE_DIR"
# Excluding target/: it holds Linux artifacts that are useless there and large
# enough to make the copy the slowest part of the gate.
rsync -az --delete --exclude 'target/' "$SYNC_DIR/" "macmini:joinery-sync-gate/"

echo "--- building and testing natively ---"
ssh macmini "bash -lc '
set -euo pipefail
export PATH=\"\$HOME/.cargo/bin:\$PATH\"
command -v cargo >/dev/null || { echo \"FAIL: no cargo on the mini — install with rustup\"; exit 1; }
cd joinery-sync-gate
cargo test --workspace --quiet
'"

echo "--- the tray builds against the native APIs ---"
# No test target of its own (it is a binary), so building it is the check that
# the macOS tray code compiles against the real frameworks rather than only
# cross-checking.
ssh macmini "bash -lc '
set -euo pipefail
export PATH=\"\$HOME/.cargo/bin:\$PATH\"
cd joinery-sync-gate
cargo build -p jd-shell --quiet
'"

echo "sync macOS gate: built and green on the mini"
