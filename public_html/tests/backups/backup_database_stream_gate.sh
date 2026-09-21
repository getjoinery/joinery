#!/bin/bash
# @joinery-test
# name: backup_database_stream
# tier: db
# env: dev-only
# timeout: 180
# needs: []
#
# backup_database.sh: pg_dump | gzip | openssl in one pipeline, and a stream
# mode that puts the encrypted dump on stdout.
#
# Against a throwaway database:
#
#   - the streamed dump decrypts and loads into a second throwaway database
#     with the same rows as the written one
#   - no plaintext temp file exists at any point, in either mode (the
#     /tmp/jy_backup_* glob is empty before, during and after)
#   - stdout carries the dump only; every human line is on stderr
#   - the report says DUMP_RC/ENC_RC, and is written after the stream
#   - a pg_dump failure is reported after the stream (DUMP_RC=1, exit 1),
#     which is what the runner-side rule refuses
#
# The runner-side rule itself (abort on a bad report, nothing on the shelf)
# is backup_runner_stream_test.php.

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
TOOLS="$ROOT/maintenance_scripts/sysadmin_tools"
BACKUP="$TOOLS/backup_database.sh"

passed=0; failed=0
chk() {
    if [ "$2" = "$3" ]; then
        echo "  PASS: $1"; passed=$((passed+1))
    else
        echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed+1))
    fi
}

if [ -z "${PGPASSWORD:-}" ] && [ -f "$ROOT/config/Globalvars_site.php" ]; then
    PGPASSWORD=$(grep "dbpassword.*=" "$ROOT/config/Globalvars_site.php" | head -1 | sed "s/.*'\(.*\)'.*/\1/")
    export PGPASSWORD
fi
if ! psql -U postgres -h localhost -c 'select 1' >/dev/null 2>&1; then
    echo "  SKIP: no postgres connection available"
    echo "RESULT: PASS 0 0"
    exit 0
fi

SRC="jyds_src_$$"
DST="jyds_dst_$$"
W=$(mktemp -d /tmp/jy_database_stream_gate_XXXXXX)
cleanup() {
    psql -U postgres -h localhost -c "DROP DATABASE IF EXISTS \"$SRC\"" >/dev/null 2>&1
    psql -U postgres -h localhost -c "DROP DATABASE IF EXISTS \"$DST\"" >/dev/null 2>&1
    rm -rf "$W"
}
trap cleanup EXIT
mkdir -p "$W/file" "$W/stream" "$W/x"

psql -U postgres -h localhost -c "CREATE DATABASE \"$SRC\" TEMPLATE template0" >/dev/null 2>&1
psql -U postgres -h localhost -c "CREATE DATABASE \"$DST\" TEMPLATE template0" >/dev/null 2>&1
# Enough rows that the pipeline cannot finish inside the pipe buffer, so the
# slow-reader checks below see the run mid-stream.
psql -U postgres -h localhost -d "$SRC" -c "CREATE TABLE t (id int, payload text); INSERT INTO t SELECT g, md5(random()::text) || repeat('x', 200) FROM generate_series(1, 20000) g" >/dev/null 2>&1
chk "source database has its rows" "$(psql -U postgres -h localhost -tAc 'select count(*) from t' "$SRC")" "20000"

head -c 32 /dev/urandom | base64 > "$W/key"; chmod 600 "$W/key"
stale() { ls /tmp/jy_backup_* 2>/dev/null | wc -l | tr -d ' '; }
chk "no jy_backup_ temp file before" "$(stale)" "0"

# ── File mode ───────────────────────────────────────────────────────────────
echo "== File mode: one pipeline, no temp file =="
( cd "$W/file" && bash "$BACKUP" --non-interactive --key-file "$W/key" "$SRC" > "$W/file/stdout" 2>&1 ); RC=$?
chk "file mode exits 0" "$RC" "0"
FILE_DUMP=$(ls "$W/file"/*.sql.gz.enc 2>/dev/null | head -1)
chk "it wrote the dump" "$([ -s "$FILE_DUMP" ] && echo yes || echo no)" "yes"
chk "the dump name carries the YYYYMMDD_HHMMSS stamp" "$(basename "$FILE_DUMP" | grep -cE "^$SRC-[0-9]{8}_[0-9]{6}\.sql\.gz\.enc$")" "1"
chk "no jy_backup_ temp file after file mode" "$(stale)" "0"
chk "file mode is owner-only" "$(stat -c %a "$FILE_DUMP")" "600"

# ── Stream mode, watched ────────────────────────────────────────────────────
echo "== Stream mode: the dump is stdout, and nothing is on disk =="
bash "$BACKUP" --non-interactive --key-file "$W/key" --archive - --report "$W/stream/report" "$SRC" 2>"$W/stream/stderr" | {
    head -c 4096 > "$W/stream/out.enc"
    sleep 0.5
    echo "$(stale)" > "$W/x/during"
    [ -e "$W/stream/report" ] && echo early > "$W/x/timing" || echo notyet > "$W/x/timing"
    cat >> "$W/stream/out.enc"
}
RC=${PIPESTATUS[0]}
chk "stream mode exits 0" "$RC" "0"
chk "the report says DUMP_RC=0 ENC_RC=0" "$(tr '\n' ' ' < "$W/stream/report")" "DUMP_RC=0 ENC_RC=0 "
chk "no report while the stream was still flowing" "$(cat "$W/x/timing")" "notyet"
chk "no jy_backup_ temp file during the stream" "$(cat "$W/x/during")" "0"
chk "no jy_backup_ temp file after" "$(stale)" "0"
chk "stdout is an openssl envelope" "$(head -c 8 "$W/stream/out.enc")" "Salted__"
chk "no text leaked into the stream" "$(grep -a -c 'Backing up\|Found config\|Using encryption' "$W/stream/out.enc")" "0"
chk "every human line went to stderr" "$([ "$(grep -c 'Backing up' "$W/stream/stderr")" -ge 1 ] && echo yes || echo no)" "yes"
chk "nothing was written to the working directory" "$(ls "$W/stream" | grep -vc 'report\|stderr\|out.enc')" "0"

# ── Same contents, and it loads ─────────────────────────────────────────────
echo "== The streamed dump equals the written one and loads =="
openssl enc -d -aes-256-cbc -pbkdf2 -pass fd:3 -in "$FILE_DUMP" 3< "$W/key" | gunzip > "$W/x/file.sql"
openssl enc -d -aes-256-cbc -pbkdf2 -pass fd:3 -in "$W/stream/out.enc" 3< "$W/key" | gunzip > "$W/x/stream.sql"; RC=$?
chk "the streamed dump decrypts and decompresses" "$RC" "0"
# pg_dump salts each dump with a one-off \restrict token; everything else is the data.
chk "the two dumps are the same SQL" "$(diff <(grep -v '^\\\(un\)\?restrict ' "$W/x/file.sql") <(grep -v '^\\\(un\)\?restrict ' "$W/x/stream.sql") >/dev/null && echo same || echo differ)" "same"
psql -U postgres -h localhost -q -d "$DST" -f "$W/x/stream.sql" >/dev/null 2>&1
chk "the streamed dump loads into a throwaway database" "$(psql -U postgres -h localhost -tAc 'select count(*) from t' "$DST")" "20000"
chk "with the same content" "$(psql -U postgres -h localhost -tAc 'select md5(string_agg(payload, chr(10) order by id)) from t' "$DST")" \
    "$(psql -U postgres -h localhost -tAc 'select md5(string_agg(payload, chr(10) order by id)) from t' "$SRC")"

# ── A pg_dump failure is reported after the stream ──────────────────────────
echo "== A pg_dump failure is reported after the stream =="
bash "$BACKUP" --non-interactive --key-file "$W/key" --archive - --report "$W/stream/report2" "jyds_no_such_$$" > "$W/stream/failed.enc" 2>"$W/stream/stderr2"; RC=$?
chk "the script exits 1" "$RC" "1"
chk "the report says DUMP_RC=1" "$(grep -o '^DUMP_RC=.*' "$W/stream/report2")" "DUMP_RC=1"
chk "and ENC_RC=0 (openssl wrapped what it got; the reader must read the report)" "$(grep -o '^ENC_RC=.*' "$W/stream/report2")" "ENC_RC=0"
chk "stderr names the failure" "$(grep -c 'Error during pg_dump' "$W/stream/stderr2")" "1"
chk "no jy_backup_ temp file after a failure" "$(stale)" "0"

# ── Argument contract ───────────────────────────────────────────────────────
echo "== Argument contract =="
bash "$BACKUP" --non-interactive --key-file "$W/key" --archive - "$SRC" >/dev/null 2>"$W/x/noreport"; RC=$?
chk "--archive - without --report is refused" "$RC" "1"
chk "and says why" "$(grep -c 'requires --report' "$W/x/noreport")" "1"
bash "$BACKUP" --non-interactive --key-file "$W/key" --archive - --report "$W/x/r" >/dev/null 2>"$W/x/nodb"; RC=$?
chk "--archive - with no database named is refused" "$RC" "1"
chk "and says so" "$(grep -c 'ONE database' "$W/x/nodb")" "1"

echo
echo "RESULT: $([ $failed -eq 0 ] && echo PASS || echo FAIL) $passed $failed"
[ $failed -eq 0 ]
