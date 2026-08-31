#!/bin/bash
# @joinery-test
# name: sync_crypto_parity
# tier: safe
# env: any
# needs: [rust, node]
# covers: [sync/**, public_html/assets/js/vault-crypto.js, public_html/assets/js/drive-crypto.js]
# timeout: 300
#
# Cross-implementation crypto parity: the Rust jd-crypto crate ({repo
# root}/sync/) must byte-match the browser's client-custody crypto
# (assets/js/vault-crypto.js + drive-crypto.js). Round-trips vectors BOTH
# directions — Rust encrypts / Node decrypts, and vice versa: content
# (multi-chunk, empty, exact-boundary), metadata (incl. mtime), sealed boxes,
# wrapped secret keys (recovery + Argon2id passphrase KEKs), and the AAD
# transplant / chunk-reorder / tamper / wrong-AD refusals.
# Shell-gate contract: exit 0 = pass, non-zero = fail.

set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SYNC_DIR="$(cd "$HERE/../../../.." && pwd)/sync"

# A safe-tier gate must NOT pass by absence of its runtime (see
# drive_crypto_gate.sh). The runner's needs:[rust,node] probe reports a real
# absence as SKIP; reaching these lines with a missing runtime is a failure.
if ! command -v node >/dev/null 2>&1; then
	echo "FAIL: node unavailable — cannot run the parity harness (declare/enforce needs:[node])"
	exit 1
fi
if ! command -v cargo >/dev/null 2>&1; then
	if [ -x "$HOME/.cargo/bin/cargo" ]; then
		export PATH="$HOME/.cargo/bin:$PATH"
	elif [ -x /home/user1/.cargo/bin/cargo ]; then
		export PATH="/home/user1/.cargo/bin:$PATH"
	else
		echo "FAIL: cargo unavailable — cannot build jd-crypto (declare/enforce needs:[rust])"
		exit 1
	fi
fi

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

echo "building jd-crypto-parity (release)..."
nice -n 19 cargo build --release -p jd-crypto --manifest-path "$SYNC_DIR/Cargo.toml" --quiet
BIN="$SYNC_DIR/target/release/jd-crypto-parity"
MJS="$HERE/sync_crypto_parity.mjs"

echo "--- rust emits, node verifies ---"
"$BIN" emit "$WORK/rust_vectors.json"
node "$MJS" verify "$WORK/rust_vectors.json"

echo "--- node emits, rust verifies ---"
node "$MJS" emit "$WORK/node_vectors.json"
"$BIN" verify "$WORK/node_vectors.json"

echo "parity gate: both directions green"
