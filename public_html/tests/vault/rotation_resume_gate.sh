#!/bin/bash
# @joinery-test
# name: vault_rotation_resume
# tier: safe
# env: any
# needs: [node]
# timeout: 60
#
# Runs assets/js/joinery-sealed.js under Node (rotation_resume.mjs): a committed
# key rotation keeps the new key reopenable on the next page, with no drop of
# the tab's resume half racing the new key's put; a stopped one forgets it.
# Shell-gate contract: exit 0 = pass, non-zero = fail.

set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if ! command -v node >/dev/null 2>&1; then
	# A safe-tier gate must not pass by absence of its runtime. The runner's
	# needs:[node] probe turns a genuinely absent runtime into a reported SKIP;
	# reaching this line means node really is missing.
	echo "FAIL: node unavailable — cannot run the rotation resume harness (declare/enforce needs:[node])"
	exit 1
fi

node "$HERE/rotation_resume.mjs"
