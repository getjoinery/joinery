#!/bin/bash
# @joinery-test
# name: mailbox_port_vectors
# tier: safe
# env: any
# needs: [node]
# timeout: 120
#
# The vectors the phone apps port the browser's Fortress-mail modules against
# (specs/fortress_mobile_apps.md WP1, WP5, WP7, WP9, WP10), rebuilt from the
# browser's own code and required to match the files byte for byte:
# search_core_vectors.json (mailbox_search_core.js), mime/*.expected.json
# (mailbox_mime.js and what drainPending() posts), device_ai_vectors.json
# (email-digest.js, verdict-check.js, MailboxFortress.judgeEntry()); and
# filter_match_cases.json, whose expected ids the PHP matcher wrote,
# replayed against mailbox_filter_match.js. A stale file fails here, not in
# the apps. Rebuild on purpose with `node port_vectors.mjs --write`.
# Shell-gate contract: exit 0 = pass, non-zero = fail.

set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if ! command -v node >/dev/null 2>&1; then
	echo "FAIL: node unavailable — cannot rebuild the port vectors (declare/enforce needs:[node])"
	exit 1
fi

node "$HERE/port_vectors.mjs"
