#!/bin/bash
# @joinery-test
# name: vault_device_link_code
# tier: safe
# env: any
# needs: [node]
# timeout: 60
#
# Runs assets/js/device-link.js under Node (device_link_code.mjs): the approval
# page seals a vault only to a device key the typed code was made from, so a key
# changed on the waiting row hands nothing over.
# Shell-gate contract: exit 0 = pass, non-zero = fail.

set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if ! command -v node >/dev/null 2>&1; then
	# A safe-tier gate must not pass by absence of its runtime. The runner's
	# needs:[node] probe turns a genuinely absent runtime into a reported SKIP;
	# reaching this line means node really is missing.
	echo "FAIL: node unavailable — cannot run the device link code check (declare/enforce needs:[node])"
	exit 1
fi

node "$HERE/device_link_code.mjs"
