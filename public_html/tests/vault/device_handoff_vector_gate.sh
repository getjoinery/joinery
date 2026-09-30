#!/bin/bash
# @joinery-test
# name: device_handoff_vector
# tier: safe
# env: any
# needs: [node]
# timeout: 60
#
# Rebuilds tests/vault/fixtures/device_handoff_vector.json with the browser's
# own vault-crypto.js from seeded randomness and requires the file to match
# byte for byte, then opens every blob in it (the handoff, the row DEK, the
# fields, the gz search text, a part). The phone apps test their crypto
# against this file (specs/fortress_mobile_apps.md WP1), so a stale file is a
# failure here, not in the apps. See device_handoff_vector.mjs.
# Shell-gate contract: exit 0 = pass, non-zero = fail.

set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if ! command -v node >/dev/null 2>&1; then
	echo "FAIL: node unavailable — cannot run the handoff vector check (declare/enforce needs:[node])"
	exit 1
fi

node "$HERE/device_handoff_vector.mjs"
