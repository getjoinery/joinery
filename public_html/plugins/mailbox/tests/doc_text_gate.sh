#!/bin/bash
# @joinery-test
# name: mailbox_doc_text
# tier: safe
# env: any
# needs: [node]
# timeout: 60
#
# The browser's document reader (assets/mailbox_doc_text.js), which reads a
# Fortress attachment's text on the owner's device, held to the server's
# (DocumentText): the same text for every fixture in fixtures/doc_text/, the
# server's zip and XML refusals, and the preview button offered where the
# server's canPreview() offers it. See doc_text.mjs; rebuild the fixtures and
# the server's answers on purpose with `node doc_text.mjs --write`.
# Shell-gate contract: exit 0 = pass, non-zero = fail.

set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if ! command -v node >/dev/null 2>&1; then
	echo "FAIL: node unavailable — cannot run the document reader harness (declare/enforce needs:[node])"
	exit 1
fi

node "$HERE/doc_text.mjs"
