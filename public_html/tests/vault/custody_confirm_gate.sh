#!/bin/bash
# @joinery-test
# name: vault_custody_confirm
# tier: safe
# env: any
# needs: [node]
# timeout: 60
#
# Runs assets/js/joinery-sealed.js under Node (custody_confirm.mjs): moving
# stored row keys off a browser-held vault asks the person first, and anything
# but an explicit yes leaves the vault shut and posts nothing.
# Shell-gate contract: exit 0 = pass, non-zero = fail.

set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if ! command -v node >/dev/null 2>&1; then
	# A safe-tier gate must not pass by absence of its runtime. The runner's
	# needs:[node] probe turns a genuinely absent runtime into a reported SKIP;
	# reaching this line means node really is missing.
	echo "FAIL: node unavailable — cannot run the custody confirmation check (declare/enforce needs:[node])"
	exit 1
fi

node "$HERE/custody_confirm.mjs"
