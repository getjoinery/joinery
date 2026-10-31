#!/bin/bash
# @joinery-test
# name: xwing_browser
# tier: safe
# env: any
# needs: [node]
# timeout: 60
#
# The browser's X-Wing (assets/vendor/noble/ and assets/js/vault-crypto.js),
# run unchanged in node and held to tests/vault/fixtures/xwing_vector.json, the
# file the PHP implementation is held to: the vendored module's pin, the draft
# vectors, the v2 seal byte for byte, scope separation, the refusal to seal to
# an old key, and the key-box half-open. See xwing_vector.mjs.
# Shell-gate contract: exit 0 = pass, non-zero = fail.

set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if ! command -v node >/dev/null 2>&1; then
	echo "FAIL: node unavailable — cannot run the browser X-Wing check (declare/enforce needs:[node])"
	exit 1
fi

node "$HERE/xwing_vector.mjs"
