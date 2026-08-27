#!/bin/bash
# @joinery-test
# name: mailbox_timestamp_ladder
# tier: safe
# env: any
# needs: [node]
# timeout: 60
#
# Runs the reader's timestamp ladder under Node (the helpers live inside a
# DOM-bound IIFE, so timestamp_ladder.mjs slices them out and evaluates them
# alone). Pins the four rungs and the clock-skew edge.
# Shell-gate contract: exit 0 = pass, non-zero = fail.

set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if ! command -v node >/dev/null 2>&1; then
	# A safe-tier gate must not pass by absence of its runtime. The runner's
	# needs:[node] probe turns a genuinely absent runtime into a reported SKIP;
	# reaching this line means node really is missing.
	echo "FAIL: node unavailable — cannot run the timestamp-ladder harness (declare/enforce needs:[node])"
	exit 1
fi

node "$HERE/timestamp_ladder.mjs"
