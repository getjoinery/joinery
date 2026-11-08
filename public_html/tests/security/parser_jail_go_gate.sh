#!/bin/bash
# @joinery-test
# name: parser_jail_go
# tier: safe
# env: any
# needs: []
# timeout: 300
#
# The parser jail launcher's own Go tests (specs/parser_jail.md), run from the
# platform's runner so they check the SOURCE before it ships. The parser_jail
# gate checks the launcher installed on this box, which is the build from
# before a change until the converger installs the new one: a launcher that
# died on every run passed it and shipped. These run without root: go vet,
# argument parsing, the seccomp deny list, and that keeping the caller's
# descriptors from the fenced child leaves the launcher's own runtime working.
#
# Go runs with the default -mod=readonly, so the gate never rewrites go.mod
# in the held source directory.
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC="$(cd "${HERE}/../../../maintenance_scripts/install_tools/joinery_jail" 2>/dev/null && pwd)"

pass=0
fail=0
ok()  { echo "  PASS: $1"; pass=$((pass+1)); }
bad() { echo "  FAIL: $1"; fail=$((fail+1)); }
finish() {
    echo "================================"
    echo "parser_jail_go [safe]"
    echo "PASSED: ${pass}   FAILED: ${fail}   ($((pass+fail)) checks)"
    [ "${fail}" -eq 0 ] || exit 1
    exit 0
}

echo "== The launcher's Go tests =="
if [ -z "${SRC}" ] || ! command -v go >/dev/null 2>&1; then
    # A box with no Go toolchain, or no launcher source, cannot check this.
    echo "  SKIP: no go toolchain or no launcher source on this host"
    echo "================================"
    echo "parser_jail_go [safe]"
    echo "PASSED: 0   FAILED: 0   SKIPPED: 1   (0 checks)"
    exit 0
fi
export GOTOOLCHAIN=local CGO_ENABLED=0 GOFLAGS=-buildvcs=false
WORK="$(mktemp -d)"
trap 'rm -rf "${WORK}"' EXIT
export GOCACHE="${GOCACHE:-${WORK}/cache}"

if (cd "${SRC}" && go vet ./... >"${WORK}/vet" 2>&1); then
    ok "go vet is clean"
else
    bad "go vet reported problems"; sed 's/^/    /' "${WORK}/vet" | tail -20
fi

if (cd "${SRC}" && go test -count=1 ./... >"${WORK}/test" 2>&1); then
    ok "the unit tests pass (arguments, deny list, inherited descriptors kept from the child without closing the runtime's)"
else
    bad "the unit tests failed"; sed 's/^/    /' "${WORK}/test" | tail -30
fi

finish
