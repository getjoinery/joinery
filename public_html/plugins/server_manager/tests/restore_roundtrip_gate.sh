#!/bin/bash
# @joinery-test
# name: restore_roundtrip
# tier: db
# env: dev-only
# needs: []
# timeout: 180
#
# The single restore engine (maintenance_scripts/sysadmin_tools/restore_database.sh)
# is the code every restore path now delegates to, yet nothing ever EXECUTED a
# restore before this gate. It exercises the whole contract against a real
# PostgreSQL on throwaway databases:
#
#   * encrypted backup -> restore with the right key -> row-count equality
#   * plaintext .sql.gz backup -> restore -> row-count equality
#   * wrong key  -> DECRYPT_FAILED marker, target database UNTOUCHED
#   * truncated  -> ARCHIVE_CORRUPT marker, target database UNTOUCHED
#   * plain .sql -> RESTORE_OK; a non-SQL file named .sql -> ARCHIVE_CORRUPT,
#     target UNTOUCHED (the pre-destroy shape check)
#   * mid-load failure AFTER the schema drop -> RESTORE_LOAD_FAILED + exit 6
#     (the one path allowed to modify the database and then fail)
#   * dump from a newer PostgreSQL -> RESTORE_SERVER_TOO_OLD, target UNTOUCHED
#   * target database absent -> created and loaded
#   * an unattended restore keeps NOTHING of what it destroys, and the two flags
#     that used to control that are refused rather than ignored
#
# The "untouched" assertions are the point: verify-before-destroy means a bad
# key or corrupt archive can never leave a half-dropped database behind.

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)"
TOOLS="$ROOT/maintenance_scripts/sysadmin_tools"
ENGINE="$TOOLS/restore_database.sh"
BACKUP="$TOOLS/backup_database.sh"

passed=0; failed=0
chk() {
    if [ "$2" = "$3" ]; then
        echo "  PASS: $1"; passed=$((passed+1))
    else
        echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed+1))
    fi
}

# Database password: prefer an already-set PGPASSWORD, else derive postgres's
# password from the local site config the same way the scripts do.
if [ -z "${PGPASSWORD:-}" ]; then
    CFG="$ROOT/config/Globalvars_site.php"
    if [ -f "$CFG" ]; then
        PGPASSWORD=$(grep "dbpassword.*=" "$CFG" | head -1 | sed "s/.*'\(.*\)'.*/\1/")
        export PGPASSWORD
    fi
fi

if ! psql -U postgres -c "SELECT 1;" >/dev/null 2>&1; then
    echo "  SKIP: no postgres connection available"
    echo "RESULT: PASS 0 0"
    exit 0
fi

SUF=$$
SRC="jt_rt_src_$SUF"
DST="jt_rt_dst_$SUF"
WORK=$(mktemp -d)
KEYF="$WORK/key"; openssl rand -base64 32 > "$KEYF"
BADKEY="$WORK/badkey"; openssl rand -base64 32 > "$BADKEY"

cleanup() {
    dropdb -U postgres --if-exists "$SRC" >/dev/null 2>&1
    dropdb -U postgres --if-exists "$DST" >/dev/null 2>&1
    rm -rf "$WORK"
}
trap cleanup EXIT

# --- Build a source database with known content -------------------------------
dropdb -U postgres --if-exists "$SRC" >/dev/null 2>&1
createdb -U postgres "$SRC"
psql -U postgres -d "$SRC" -q -c \
    "CREATE TABLE t(id int primary key, v text); INSERT INTO t SELECT g,'row'||g FROM generate_series(1,500) g;"
SRC_COUNT=$(psql -U postgres -d "$SRC" -XtAc "SELECT count(*) FROM t")
chk "source database seeded" "$SRC_COUNT" "500"

# --- Encrypted backup via the shipped backup script ---------------------------
( cd "$WORK" && BACKUP_ENCRYPTION_KEY="$(cat "$KEYF")" bash "$BACKUP" --non-interactive "$SRC" >/dev/null 2>&1 )
ENC=$(ls -t "$WORK/$SRC"-*.sql.gz.enc 2>/dev/null | head -1)
chk "encrypted archive produced" "$([ -n "$ENC" ] && [ -f "$ENC" ] && echo yes)" "yes"

# --- 1: correct-key encrypted restore into a fresh DB -------------------------
dropdb -U postgres --if-exists "$DST" >/dev/null 2>&1; createdb -U postgres "$DST"
M=$(bash "$ENGINE" "$DST" "$ENC" --non-interactive --key-file "$KEYF" --db-user postgres 2>/dev/null)
chk "encrypted restore marker" "$M" "RESTORE_OK"
chk "encrypted restore row count" \
    "$(psql -U postgres -d "$DST" -XtAc "SELECT count(*) FROM t" 2>/dev/null)" "500"

# --- 2: wrong key -> DECRYPT_FAILED, target untouched -------------------------
psql -U postgres -d "$DST" -q -c "INSERT INTO t VALUES (99999,'sentinel');" >/dev/null 2>&1
M=$(bash "$ENGINE" "$DST" "$ENC" --non-interactive --key-file "$BADKEY" --db-user postgres 2>/dev/null)
chk "wrong-key marker is DECRYPT_FAILED" "$M" "DECRYPT_FAILED"
chk "wrong-key left the target untouched" \
    "$(psql -U postgres -d "$DST" -XtAc "SELECT count(*) FROM t WHERE id=99999" 2>/dev/null)" "1"

# --- 3: truncated archive -> ARCHIVE_CORRUPT, target untouched ----------------
PLAIN="$WORK/plain.sql.gz"; pg_dump -U postgres "$SRC" | gzip > "$PLAIN"
head -c 200 "$PLAIN" > "$WORK/trunc.sql.gz"
M=$(bash "$ENGINE" "$DST" "$WORK/trunc.sql.gz" --non-interactive --db-user postgres 2>/dev/null)
chk "truncated marker is ARCHIVE_CORRUPT" "$M" "ARCHIVE_CORRUPT"
chk "truncated left the target untouched" \
    "$(psql -U postgres -d "$DST" -XtAc "SELECT count(*) FROM t WHERE id=99999" 2>/dev/null)" "1"

# --- 4: plaintext .sql.gz roundtrip ------------------------------------------
dropdb -U postgres --if-exists "$DST" >/dev/null 2>&1; createdb -U postgres "$DST"
M=$(bash "$ENGINE" "$DST" "$PLAIN" --non-interactive --db-user postgres 2>/dev/null)
chk "plaintext restore marker" "$M" "RESTORE_OK"
chk "plaintext restore row count" \
    "$(psql -U postgres -d "$DST" -XtAc "SELECT count(*) FROM t" 2>/dev/null)" "500"

# --- 5: plain .sql roundtrip ---------------------------------------------------
RAWSQL="$WORK/raw.sql"; pg_dump -U postgres "$SRC" > "$RAWSQL"
dropdb -U postgres --if-exists "$DST" >/dev/null 2>&1; createdb -U postgres "$DST"
M=$(bash "$ENGINE" "$DST" "$RAWSQL" --non-interactive --db-user postgres 2>/dev/null)
chk "plain .sql restore marker" "$M" "RESTORE_OK"
chk "plain .sql restore row count" \
    "$(psql -U postgres -d "$DST" -XtAc "SELECT count(*) FROM t" 2>/dev/null)" "500"

# --- 6: non-SQL file named .sql -> shape check refuses PRE-destroy -------------
NOTSQL="$WORK/notsql.sql"
printf '<html><body>502 Bad Gateway</body></html>\n%s\n' "junk line" > "$NOTSQL"
psql -U postgres -d "$DST" -q -c "INSERT INTO t VALUES (99998,'sentinel2');" >/dev/null 2>&1
M=$(bash "$ENGINE" "$DST" "$NOTSQL" --non-interactive --db-user postgres 2>/dev/null)
chk "non-SQL .sql marker is ARCHIVE_CORRUPT" "$M" "ARCHIVE_CORRUPT"
chk "non-SQL .sql left the target untouched" \
    "$(psql -U postgres -d "$DST" -XtAc "SELECT count(*) FROM t WHERE id=99998" 2>/dev/null)" "1"

# --- 7: mid-load failure AFTER the schema drop -> RESTORE_LOAD_FAILED ----------
# Valid SQL head (passes the shape check), then a statement that fails: the
# schema is dropped, the load starts, and ON_ERROR_STOP aborts partway. This is
# the only path allowed to modify the database and then fail — the marker and
# exit code must say so honestly.
MIDFAIL="$WORK/midfail.sql"
{
    echo "CREATE TABLE t(id int primary key, v text);"
    echo "INSERT INTO t VALUES (1,'a');"
    echo "INSERT INTO no_such_table VALUES (1);"
} > "$MIDFAIL"
M=$(bash "$ENGINE" "$DST" "$MIDFAIL" --non-interactive --db-user postgres 2>/dev/null)
RC=$?
chk "mid-load failure marker is RESTORE_LOAD_FAILED" "$M" "RESTORE_LOAD_FAILED"
chk "mid-load failure exit code is 6" "$RC" "6"
chk "mid-load failure did replace the schema (partial load visible)" \
    "$(psql -U postgres -d "$DST" -XtAc "SELECT count(*) FROM t" 2>/dev/null)" "1"

# --- 7b: dump from a newer PostgreSQL -> refused BEFORE the schema drop --------
# The version is written into the dump header, so this is knowable with nothing
# yet destroyed. 99 rather than a real number keeps the assertion true whatever
# major this box runs. Restoring an 18 dump into a 16 server is what found it:
# the load died on SET transaction_timeout at line 13, target already emptied.
dropdb -U postgres --if-exists "$DST" >/dev/null 2>&1; createdb -U postgres "$DST"
psql -U postgres -d "$DST" -q -c "CREATE TABLE t(id int primary key, v text);" >/dev/null 2>&1
psql -U postgres -d "$DST" -q -c "INSERT INTO t VALUES (99997,'sentinel3');" >/dev/null 2>&1
TOONEW="$WORK/toonew.sql"
{
    echo "--"
    echo "-- PostgreSQL database dump"
    echo "--"
    echo ""
    echo "-- Dumped from database version 99.1"
    echo "-- Dumped by pg_dump version 99.1"
    echo ""
    echo "CREATE TABLE t(id int primary key, v text);"
} > "$TOONEW"
M=$(bash "$ENGINE" "$DST" "$TOONEW" --non-interactive --db-user postgres 2>/dev/null)
chk "newer-dump marker is RESTORE_SERVER_TOO_OLD" "${M%% *}" "RESTORE_SERVER_TOO_OLD"
chk "newer-dump left the target untouched" \
    "$(psql -U postgres -d "$DST" -XtAc "SELECT count(*) FROM t WHERE id=99997" 2>/dev/null)" "1"

# --- 8: target database absent -> created and loaded ---------------------------
dropdb -U postgres --if-exists "$DST" >/dev/null 2>&1
M=$(bash "$ENGINE" "$DST" "$PLAIN" --non-interactive --db-user postgres 2>/dev/null)
chk "absent-target restore marker (createdb branch)" "$M" "RESTORE_OK"
chk "absent-target restore row count" \
    "$(psql -U postgres -d "$DST" -XtAc "SELECT count(*) FROM t" 2>/dev/null)" "500"

# --- 9: nothing is saved before the schema is dropped -------------------------
#
# The inverse of what this section used to assert, and the change is a decision
# rather than a regression (owner, 2026-08-30). A restore happens because the
# current state is wrong, so dumping it first preserved the thing being
# discarded and left a full copy of the database, per restore, for ever.
#
# It is checked rather than merely removed because "no file appears" is exactly
# the kind of property that comes back by accident — a caller re-adding a flag,
# a merge restoring a stage. And the flags are checked as REFUSALS: the script
# rejects unknown options, so a caller still passing one gets a failed restore
# rather than a silently ignored argument, and that is worth knowing here rather
# than on a node.
dropdb -U postgres --if-exists "$DST" >/dev/null 2>&1; createdb -U postgres "$DST"
psql -U postgres -d "$DST" -q -c "CREATE TABLE t(id int, v text); INSERT INTO t VALUES (1,'about-to-be-destroyed');" >/dev/null 2>&1
rm -f "$WORK"/*-pre-restore.sql.gz*
M=$(bash "$ENGINE" "$DST" "$PLAIN" --non-interactive --db-user postgres 2>/dev/null)
chk "unattended restore still succeeds" "$M" "RESTORE_OK"
chk "an unattended restore leaves no dump of what it destroyed" \
    "$(ls "$WORK"/*-pre-restore.sql.gz* 2>/dev/null | wc -l)" "0"
# Nowhere else either: a dump written to the caller's working directory would be
# the same copy in a worse place.
chk "and none in the working directory" \
    "$(ls ./*-pre-restore.sql.gz* 2>/dev/null | wc -l)" "0"
# The destroyed row is genuinely gone — proving the restore really did replace
# the schema, so the absence above is "nothing was kept" and not "nothing ran".
chk "the state it replaced is gone" \
    "$(psql -U postgres -d "$DST" -XtAc "SELECT count(*) FROM t WHERE v='about-to-be-destroyed'" 2>/dev/null)" "0"

# The two flags are gone, and the script refuses them rather than ignoring them.
dropdb -U postgres --if-exists "$DST" >/dev/null 2>&1; createdb -U postgres "$DST"
bash "$ENGINE" "$DST" "$PLAIN" --non-interactive --no-pre-restore-dump --db-user postgres >/dev/null 2>&1
chk "--no-pre-restore-dump is refused, not ignored" "$?" "1"
bash "$ENGINE" "$DST" "$PLAIN" --non-interactive --pre-restore-dump-dir "$WORK" --db-user postgres >/dev/null 2>&1
chk "--pre-restore-dump-dir is refused, not ignored" "$?" "1"

# No caller may still be passing them. A refused flag is a failed restore, and
# the callers that used to pass these are the dashboard's own restore paths.
# Invocations only. A test that ASSERTS the flags are gone names them too, and
# so does restore_database.sh's own changelog; neither passes one to anything.
CALLERS=$(grep -rn -- "--no-pre-restore-dump\|--pre-restore-dump-dir" \
    "$ROOT/maintenance_scripts" "$ROOT/public_html/plugins" 2>/dev/null \
    | grep -v "/tests/" | grep -v "restore_database.sh:" | grep -v "^\s*#" | wc -l)
chk "no caller still passes a removed flag" "$CALLERS" "0"

echo
if [ "$failed" -eq 0 ]; then
    echo "RESULT: PASS $passed $failed"
    exit 0
fi
echo "RESULT: FAIL $passed $failed"
exit 1
