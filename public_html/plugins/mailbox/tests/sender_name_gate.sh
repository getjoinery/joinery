#!/bin/bash
# @joinery-test
# name: mailbox_sender_name
# tier: safe
# env: any
# needs: [node]
# timeout: 60
#
# Runs the reader's sender-label helpers under Node (they live inside a DOM-bound
# IIFE, so sender_name.mjs slices them out and evaluates them alone). The PHP half
# of the same feature — what ingest stores in iem_sender — is covered by
# sender_display_name_test.php.
# Shell-gate contract: exit 0 = pass, non-zero = fail.

set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if ! command -v node >/dev/null 2>&1; then
	# A safe-tier gate must not pass by absence of its runtime. The runner's
	# needs:[node] probe turns a genuinely absent runtime into a reported SKIP;
	# reaching this line means node really is missing.
	echo "FAIL: node unavailable — cannot run the sender-label harness (declare/enforce needs:[node])"
	exit 1
fi

node "$HERE/sender_name.mjs"
