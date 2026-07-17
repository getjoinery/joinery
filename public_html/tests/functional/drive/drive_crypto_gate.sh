#!/bin/bash
# @joinery-test
# name: drive_crypto_roundtrip
# tier: safe
# env: any
# needs: [node]
# timeout: 60
#
# Runs the DriveCrypto browser-crypto round-trip harness under Node's WebCrypto
# (the passkey PRF unlocker can't run under Playwright, so the crypto is proven
# directly here — specs/implemented/drive_encryption.md, docs/testing.md).
# Shell-gate contract: exit 0 = pass, non-zero = fail.

set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if ! command -v node >/dev/null 2>&1; then
	# A safe-tier gate must NOT pass by absence of its runtime — exiting 0 here
	# made the entire client-crypto surface read green on any node-less box. The
	# runner's `needs: [node]` probe turns a genuinely absent runtime into a
	# reported SKIP; if we still reach this line, node really is missing → fail.
	echo "FAIL: node unavailable — cannot run the DriveCrypto round-trip harness (declare/enforce needs:[node])"
	exit 1
fi

node "$HERE/drive_crypto_roundtrip.mjs"
