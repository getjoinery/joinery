#!/bin/bash
# @joinery-test
# name: joinery_direct_wire
# tier: safe
# env: any
# needs: []
# timeout: 180
#
# Joinery Direct's PHP/Go interop, the relay API's signed envelope, and the
# relay binary's own unit tests.
#
# This is the drift nothing else catches. An instance signature is only worth
# anything if both ends agree BYTE FOR BYTE on what was covered, and a
# divergence between includes/joinery_direct/DirectProtocol.php and the relay's
# direct_protocol.go would not throw anywhere: every delivery from a Joinery box
# would simply fail verification at the relay, which a sender reads as "peer
# unreachable" and silently downgrades to SMTP. Mail keeps flowing, nothing is
# ever marked verified, and nobody notices for weeks.
#
# So the real gate lives beside the Go source (direct_wire_gate.sh): PHP emits
# the signing bytes and Go emits them for the same deliberately awkward fixture,
# and the two are diffed. The same gate pins the relay API's request envelope
# and birth report (RelayProtocol.php against relay_protocol.go), whose drift
# would fail every spool pull just as silently. This wrapper runs it — and the
# relay's Go tests — from the platform's own runner, so the check is part of
# `run.php safe` rather than something somebody remembers to do.
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SEALER="${HERE}/../provisioning/relay-sealer"

pass=0
fail=0
ok()   { echo "  PASS: $1"; pass=$((pass+1)); }
bad()  { echo "  FAIL: $1"; fail=$((fail+1)); }

echo "== The relay binary builds and its own tests pass =="
if ! command -v go >/dev/null 2>&1; then
    # A box with no Go toolchain cannot check this, and saying so is better than
    # a green run that verified nothing.
    echo "  SKIP: no go toolchain on this host — the wire gate did not run"
    echo "================================"
    echo "joinery_direct_wire [safe]"
    echo "PASSED: 0   FAILED: 0   SKIPPED: 1   (0 checks)"
    exit 0
fi

if (cd "${SEALER}" && go vet ./... >/dev/null 2>&1); then
    ok "go vet is clean"
else
    bad "go vet reported problems"
fi

if (cd "${SEALER}" && go test ./... >/dev/null 2>&1); then
    ok "the relay's Go tests pass (wire forms, freshness, caps, decoys, sessions, relay routes)"
else
    bad "the relay's Go tests failed"
    (cd "${SEALER}" && go test ./... 2>&1 | tail -20)
fi

echo
echo "== PHP and Go sign the same bytes =="
GATE_OUT="$(bash "${SEALER}/direct_wire_gate.sh" 2>&1)"
GATE_CODE=$?
echo "${GATE_OUT}" | sed 's/^/  /'
if [ "${GATE_CODE}" -eq 0 ] && echo "${GATE_OUT}" | grep -q "RESULT: PASS"; then
    ok "the interop gate passed"
elif echo "${GATE_OUT}" | grep -q "^SKIP:"; then
    echo "  SKIP: the interop gate could not run here"
else
    bad "the interop gate failed — every Direct delivery or relay pull would fail verification"
fi

echo
echo "================================"
echo "joinery_direct_wire [safe]"
echo "PASSED: ${pass}   FAILED: ${fail}   ($((pass+fail)) checks)"
[ "${fail}" -eq 0 ] || exit 1
exit 0
