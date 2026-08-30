#!/bin/bash
# @joinery-test
# name: approval_challenge_parity
# tier: safe
# env: any
# needs: [go, node]
# timeout: 180
#
# The agent seals a restore-approval challenge in Go. The person approving opens
# it in JavaScript, in their own browser, at the moment a restore is waiting.
# Nothing checks that those two agree at runtime — and if they ever stop
# agreeing, the failure is silent until somebody needs a restore, and it presents
# to them as "my recovery key does not work".
#
# So this seals a real challenge with the agent's own code and asks the SHIPPED
# browser file to open it. Both halves are the real ones: the Go is
# sealToRecoveryKey, the JavaScript is assets/js/recovery-readiness.js loaded
# whole.
#
# Skips (exit 0) where there is no agent source, no Go, or no Node — a box
# without them is not one where this can drift.
#
# Shell-gate contract: exit 0 = pass, non-zero = fail.

set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

AGENT_DIR="${JOINERY_AGENT_SOURCE:-$HOME/joinery-agent}"
if [ ! -d "$AGENT_DIR" ]; then
    AGENT_DIR="/home/user1/joinery-agent"
fi

if [ ! -f "$AGENT_DIR/approval.go" ]; then
    echo "SKIP: no agent source at $AGENT_DIR — nothing to check parity against"
    exit 0
fi
command -v go >/dev/null 2>&1   || { echo "SKIP: no Go toolchain on this box"; exit 0; }
command -v node >/dev/null 2>&1 || { echo "SKIP: no Node on this box"; exit 0; }

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
FIXTURE="$TMP/approval_fixture.json"

echo "Sealing a challenge with the agent's own code..."
if ! (cd "$AGENT_DIR" && JOINERY_APPROVAL_FIXTURE="$FIXTURE" \
        go test . -run TestApprovalChallengeFixture -count=1 >"$TMP/go.log" 2>&1); then
    echo "FAIL: the agent could not seal an approval challenge"
    cat "$TMP/go.log"
    exit 1
fi

if [ ! -s "$FIXTURE" ]; then
    echo "FAIL: the agent's fixture test wrote nothing — it may have been skipped"
    cat "$TMP/go.log"
    exit 1
fi

echo "Opening it with the browser code the approval screen ships..."
node "$HERE/open_approval_challenge.mjs" "$FIXTURE"
