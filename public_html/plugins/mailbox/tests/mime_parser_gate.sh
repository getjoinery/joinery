#!/bin/bash
# @joinery-test
# name: mailbox_mime_parser
# tier: safe
# env: any
# needs: [node]
# timeout: 60
#
# Runs the browser MIME parser (assets/mailbox_mime.js) under Node over the
# hand-written messages in fixtures/mime/ and checks exact decoded values:
# headers and encoded words, nested multipart bodies, part numbers, inline
# flags, filenames, and attachment bytes. See mime_parser.mjs.
# Shell-gate contract: exit 0 = pass, non-zero = fail.

set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if ! command -v node >/dev/null 2>&1; then
	# A safe-tier gate must not pass by absence of its runtime. The runner's
	# needs:[node] probe turns a genuinely absent runtime into a reported SKIP;
	# reaching this line means node really is missing.
	echo "FAIL: node unavailable — cannot run the MIME parser harness (declare/enforce needs:[node])"
	exit 1
fi

node "$HERE/mime_parser.mjs"
