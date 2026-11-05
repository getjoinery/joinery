#!/bin/bash
# @joinery-test
# name: unseal_daemon_go
# tier: safe
# env: any
# needs: []
# timeout: 300
#
# The unseal daemon's own Go tests (specs/unseal_daemon.md WP2), run from the
# platform's runner so they are part of `run.php safe` rather than something
# somebody remembers to do: go vet, the unit tests (the five operations, child
# windows, both clocks, the drop and hold, the socket's uid check, the shared
# X-Wing vectors, and that xwing.go is still the relay sealer's file), the
# same under the race detector, and a short run of the fuzz target over the
# framing and the request parser. The daemon must never exit on anything that
# arrives on its socket; the fuzz is the standing check of that.
#
# tests/vault/unseal_daemon_test.php drives the shipped binary from PHP.
#
# Go runs with the default -mod=readonly, so the gate never rewrites go.mod or
# go.sum in the held source directory. A crash the fuzz finds is saved under
# the source's testdata/fuzz/, which leaves the tree dirty and stops the next
# publish: on purpose, since a daemon an input can crash must not ship.
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC="$(cd "${HERE}/../../../maintenance_scripts/install_tools/joinery_unseal" 2>/dev/null && pwd)"

pass=0
fail=0
ok()  { echo "  PASS: $1"; pass=$((pass+1)); }
bad() { echo "  FAIL: $1"; fail=$((fail+1)); }
finish() {
    echo "================================"
    echo "unseal_daemon_go [safe]"
    echo "PASSED: ${pass}   FAILED: ${fail}   ($((pass+fail)) checks)"
    [ "${fail}" -eq 0 ] || exit 1
    exit 0
}

echo "== The daemon's Go tests =="
if [ -z "${SRC}" ] || ! command -v go >/dev/null 2>&1; then
    # A box with no Go toolchain, or no daemon source, cannot check this.
    echo "  SKIP: no go toolchain or no daemon source on this host"
    echo "================================"
    echo "unseal_daemon_go [safe]"
    echo "PASSED: 0   FAILED: 0   SKIPPED: 1   (0 checks)"
    exit 0
fi
export GOTOOLCHAIN=local
WORK="$(mktemp -d)"
trap 'rm -rf "${WORK}"' EXIT
export GOCACHE="${GOCACHE:-${WORK}/cache}"

if (cd "${SRC}" && go vet ./... >"${WORK}/vet" 2>&1); then
    ok "go vet is clean"
else
    bad "go vet reported problems"; sed 's/^/    /' "${WORK}/vet" | tail -20
fi

if (cd "${SRC}" && go test -count=1 ./... >"${WORK}/test" 2>&1); then
    ok "the unit tests pass (operations, child windows, clocks, drop and hold, socket uid, X-Wing vectors)"
else
    bad "the unit tests failed"; sed 's/^/    /' "${WORK}/test" | tail -30
fi

if (cd "${SRC}" && go test -race -count=1 ./... >"${WORK}/race" 2>&1); then
    ok "and under the race detector"
elif grep -q "race detector is not supported\|-race requires cgo" "${WORK}/race"; then
    echo "  SKIP: the race detector cannot run on this host"
else
    bad "the race detector found a problem"; sed 's/^/    /' "${WORK}/race" | tail -30
fi

if (cd "${SRC}" && go test -run=NONE -fuzz=FuzzServeStream -fuzztime=20s . >"${WORK}/fuzz" 2>&1); then
    ok "20 s of fuzzing the framing and request parser found no input that crashes the daemon"
else
    bad "the fuzz target found a crashing input (saved under testdata/fuzz)"; sed 's/^/    /' "${WORK}/fuzz" | tail -30
fi

finish
