#!/bin/bash
# @joinery-test
# name: mailbox_search_index
# tier: safe
# env: any
# needs: [node]
# timeout: 600
#
# Runs MailboxSearchCore (the pure half of a browser's search index over
# Fortress mail, specs/client_custody_mail.md § R5) under Node: tokenizer,
# encodings, merge equals a build from scratch, queries equal a brute-force
# scan, the device_hits vector PHP decodes, and the 100,000-message size and
# speed benchmark (numbers printed).
# Shell-gate contract: exit 0 = pass, non-zero = fail.

set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if ! command -v node >/dev/null 2>&1; then
	# A safe-tier gate must not pass by absence of its runtime. The runner's
	# needs:[node] probe turns a genuinely absent runtime into a reported SKIP;
	# reaching this line means node really is missing.
	echo "FAIL: node unavailable — cannot run the search-index harness (declare/enforce needs:[node])"
	exit 1
fi

node "$HERE/search_index.mjs"
