#!/bin/bash
# @joinery-test
# name: runner_options
# tier: safe
# env: any
# needs: []
# timeout: 90
# covers: tests/run.php
#
# tests/run.php takes a tier and a fixed set of options. An option nobody
# declared is a typo, and a typo must be refused, not ignored: a runner that
# shrugged at `--help` or `--onyl=...` would quietly run the whole safe tier
# in its place and report green for a run the caller never meant. --help
# answers with the usage text and runs nothing; an unknown tier is refused
# the same way. --only names one test by path; a comma list names nothing and
# must say so rather than run everything.
#
# Every call here exits before discovery or at discovery, so this gate never
# runs a test and never takes the gate lock.

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
RUNNER="$ROOT/tests/run.php"
T=$(mktemp -d)
trap 'rm -rf "${T:?}"' EXIT
passed=0; failed=0

chk() {
    if [ "$2" = "$3" ]; then
        echo "  PASS: $1"; passed=$((passed+1))
    else
        echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed+1))
    fi
}

echo "== --help prints usage and runs nothing =="
php "$RUNNER" --help >"$T/out" 2>"$T/err"; rc=$?
chk "--help exits 0" "$rc" "0"
chk "--help prints the usage line" "$(grep -c '^Usage: php tests/run.php' "$T/out")" "1"
chk "--help names every option" "$(grep -cE '^  --(filter|only|changed|fresh|no-wait|serial|timeout|json|list|help)' "$T/out")" "10"
chk "--help runs no test" "$(grep -c 'PASS\|FAIL' "$T/out")" "0"
php "$RUNNER" -h >"$T/out2" 2>/dev/null; rc=$?
chk "-h is the same" "$rc:$(cmp -s "$T/out" "$T/out2" && echo same)" "0:same"

echo "== an option nobody declared is refused =="
php "$RUNNER" --bogus >"$T/out" 2>"$T/err"; rc=$?
chk "--bogus exits 2" "$rc" "2"
chk "--bogus is named" "$(grep -c "Unknown option '--bogus'" "$T/err")" "1"
chk "--bogus prints the usage after the complaint" "$(grep -c '^Usage: php tests/run.php' "$T/err")" "1"
chk "--bogus runs no test" "$(grep -c 'PASS\|FAIL\|Tests:' "$T/out")" "0"
php "$RUNNER" db --onyl=tests/unit/x_test.php >"$T/out" 2>"$T/err"; rc=$?
chk "a misspelled --only is refused, tier or not" "$rc:$(grep -c "Unknown option '--onyl=" "$T/err")" "2:1"

echo "== an unknown tier is refused =="
php "$RUNNER" nosuchtier >"$T/out" 2>"$T/err"; rc=$?
chk "unknown tier exits 2" "$rc" "2"
chk "unknown tier lists the tiers" "$(grep -c "Unknown tier 'nosuchtier'. Use one of: safe, db, test-db, live, deploy" "$T/err")" "1"
chk "unknown tier prints the usage" "$(grep -c '^Usage: php tests/run.php' "$T/err")" "1"

echo "== the declared options still parse =="
php "$RUNNER" --list --serial >"$T/out" 2>"$T/err"; rc=$?
chk "--list --serial exits 0" "$rc" "0"
chk "--list is a listing, not a run" "$(grep -c 'Discovered tests' "$T/out")" "1"
php "$RUNNER" --list --json >"$T/out" 2>"$T/err"; rc=$?
chk "--list --json exits 0 with JSON" "$rc:$(head -c1 "$T/out")" "0:{"

echo "== --only takes one path; a comma list names nothing =="
php "$RUNNER" --only=tests/unit/a_test.php,tests/unit/b_test.php >"$T/out" 2>"$T/err"; rc=$?
chk "a comma list is not a match" "$(grep -c 'matched no declared test' "$T/out" "$T/err" | awk -F: '{s+=$2} END{print (s>0)?"said":"silent"}')" "said"
chk "and nothing ran" "$rc:$(grep -c 'Nothing was executed' "$T/out" "$T/err" | awk -F: '{s+=$2} END{print (s>0)?"yes":"no"}')" "$rc:yes"
[ "$rc" -ne 0 ]; chk "with a non-zero exit" "$?" "0"

echo
if [ "$failed" -eq 0 ]; then
    echo "RESULT: PASS $passed $failed"
    exit 0
fi
echo "RESULT: FAIL $passed $failed"
exit 1
