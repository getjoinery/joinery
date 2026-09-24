#!/bin/bash
# @joinery-test
# name: site_init_clone_load
# tier: safe
# env: any
# needs: []
# timeout: 30
# covers: [maintenance_scripts/install_tools/_site_init.sh]
#
# A site cloned from another site loads the source's database through one pipe
# (curl | openssl | gunzip | psql). Pinned here, without a network or a server:
#
#   - the load stops at the first SQL error (psql -v ON_ERROR_STOP=1) and its
#     stderr is kept for the failure message, not discarded
#   - a dump written by a newer PostgreSQL than this server is refused before any
#     statement reaches psql, and an older one passes through byte for byte
#   - the export key is read from JOINERY_CLONE_KEY and reaches curl and openssl
#     through 0600 files, never as an argument any process could read
#
# The version check is the function _site_init.sh defines, lifted out of the
# script and run against fixture streams.

set -u
SITE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
SCRIPT="$SITE_ROOT/maintenance_scripts/install_tools/_site_init.sh"
T=$(mktemp -d)
trap 'rm -rf "$T"' EXIT
passed=0; failed=0

chk() {
    if [ "$2" = "$3" ]; then
        echo "  PASS: $1"; passed=$((passed+1))
    else
        echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed+1))
    fi
}

echo "=== The load stops on an error and keeps what psql said ==="
LOAD_LINE="$(grep -n 'psql -U postgres -d "\$SITENAME" -q' "$SCRIPT")"
chk "the clone load runs psql with ON_ERROR_STOP" "$(printf '%s\n' "$LOAD_LINE" | grep -c 'ON_ERROR_STOP=1')" "1"
chk "and does not discard its stderr" "$(printf '%s\n' "$LOAD_LINE" | grep -c '2>/dev/null')" "0"
chk "the stream passes the version check before psql" "$(grep -c 'refuse_newer_dump 2>>"\$LOAD_ERR" |' "$SCRIPT")" "1"

echo "=== The export key never reaches a command line (B7) ==="
chk "the key is read from JOINERY_CLONE_KEY" "$(grep -c '^CLONE_KEY="${JOINERY_CLONE_KEY:-}"$' "$SCRIPT")" "1"
chk "no curl carries the bearer header as an argument" "$(grep -c 'Authorization: Bearer ${CLONE_KEY}"' "$SCRIPT")" "0"
chk "every clone curl reads the header from the 0600 file" "$(grep -c -- '-H @"$CLONE_SECRETS/auth"' "$SCRIPT")" "5"
chk "openssl reads the key from a file, not -pass pass:" "$(grep -c -- '-pass pass:' "$SCRIPT"):$(grep -c -- '-pass file:"$CLONE_SECRETS/key"' "$SCRIPT")" "0:1"
chk "the secrets directory is private and removed on exit" "$(grep -c 'chmod 700 "$CLONE_SECRETS"' "$SCRIPT"):$(grep -c "trap 'rm -rf \"\$CLONE_SECRETS\"' EXIT" "$SCRIPT")" "1:1"

echo "=== A newer dump is refused before any SQL; an older one passes untouched ==="
FN="$(awk '/^    refuse_newer_dump\(\) \{/,/^    \}$/' "$SCRIPT")"
chk "the version check is defined once" "$(printf '%s\n' "$FN" | grep -c 'refuse_newer_dump() {')" "1"

HEADER18=$'--\n-- PostgreSQL database dump\n--\n\n-- Dumped from database version 18.1\n-- Dumped by pg_dump version 18.1\n'
HEADER16=$'--\n-- PostgreSQL database dump\n--\n\n-- Dumped from database version 16.4\n-- Dumped by pg_dump version 16.4\n'
BODY=$'SET statement_timeout = 0;\nCREATE TABLE t (id integer);\nCOPY public.t (id) FROM stdin;\n1\n\\.\n'

run_check() {  # $1 target major, $2 stream file, $3 output file; prints the function's status
    bash -c "$FN
TARGET_PG_MAJOR=$1
refuse_newer_dump < '$2' > '$3' 2>'$3.err'
echo \$?"
}

printf '%s%s' "$HEADER18" "$BODY" > "$T/dump18.sql"
printf '%s%s' "$HEADER16" "$BODY" > "$T/dump16.sql"

rc="$(run_check 16 "$T/dump18.sql" "$T/out1")"
chk "an 18 dump into a 16 server is refused" "$rc" "3"
chk "and no statement got through" "$(grep -c '^\(SET\|CREATE\|COPY\)' "$T/out1")" "0"
chk "and the refusal names both versions" "$(grep -c 'runs PostgreSQL 18 and this server runs 16' "$T/out1.err")" "1"

rc="$(run_check 18 "$T/dump16.sql" "$T/out2")"
chk "a 16 dump into an 18 server passes" "$rc" "0"
chk "byte for byte" "$(cmp -s "$T/dump16.sql" "$T/out2" && echo same)" "same"

rc="$(run_check 18 "$T/dump18.sql" "$T/out3")"
chk "the same version passes" "$rc:$(cmp -s "$T/dump18.sql" "$T/out3" && echo same)" "0:same"

rc="$(run_check 0 "$T/dump18.sql" "$T/out4")"
chk "an unreadable server version refuses nothing" "$rc:$(cmp -s "$T/dump18.sql" "$T/out4" && echo same)" "0:same"

printf '%s' "$BODY" > "$T/noheader.sql"
rc="$(run_check 16 "$T/noheader.sql" "$T/out5")"
chk "a stream with no version header passes untouched" "$rc:$(cmp -s "$T/noheader.sql" "$T/out5" && echo same)" "0:same"

echo
echo "site_init_clone_load gate: $passed passed, $failed failed"
[ "$failed" -eq 0 ]
