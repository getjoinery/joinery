#!/bin/bash
# @joinery-test
# name: vault_session_key_proof
# tier: safe
# env: any
# needs: [node]
# timeout: 60
#
# Runs the vendored X-Wing, vault-crypto.js and vault-keyring.js under Node
# (session_key_proof.mjs): a vault session opens only on the public key its own
# secret gives, so a wrapping shown beside another key's public half is refused.
# Shell-gate contract: exit 0 = pass, non-zero = fail.

set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if ! command -v node >/dev/null 2>&1; then
	# A safe-tier gate must not pass by absence of its runtime. The runner's
	# needs:[node] probe turns a genuinely absent runtime into a reported SKIP;
	# reaching this line means node really is missing.
	echo "FAIL: node unavailable — cannot run the session key proof (declare/enforce needs:[node])"
	exit 1
fi

node "$HERE/session_key_proof.mjs"
