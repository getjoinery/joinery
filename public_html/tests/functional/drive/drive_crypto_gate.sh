#!/bin/bash
# @joinery-test
# name: drive_crypto_roundtrip
# tier: safe
# env: any
# needs: []
# timeout: 60
#
# Runs the DriveCrypto browser-crypto round-trip harness under Node's WebCrypto
# (the passkey PRF unlocker can't run under Playwright, so the crypto is proven
# directly here — specs/implemented/drive_encryption.md, docs/testing.md).
# Shell-gate contract: exit 0 = pass, non-zero = fail.

set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if ! command -v node >/dev/null 2>&1; then
	echo "SKIP: node unavailable — cannot run the DriveCrypto round-trip harness"
	exit 0
fi

node "$HERE/drive_crypto_roundtrip.mjs"
