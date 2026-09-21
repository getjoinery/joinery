#!/bin/bash
# @joinery-test
# name: backup_verify_gate
# tier: db
# env: dev-only
# needs: []
# timeout: 600
#
# Backup verification, end to end, with real tar, real openssl and a real
# PostgreSQL: the engine (includes/BackupVerifier.php) driven through the
# operator's shell entry (maintenance_scripts/sysadmin_tools/verify_backup.sh)
# over a fixture chain built the way backup_chain_gate.sh builds one.
#
# No fetch, on purpose: BackupFetch refuses anything but a signed https link
# (backup_fetch_test.php pins that), and the fetch half of a verify is
# stage_chain's code, covered by its own tests. What is asserted here is the
# proof itself:
#
#   * level 2 opens and reads every artifact the run depends on and says how
#     many, how big, and how many entries
#   * one artifact flipped by a byte fails, naming the artifact, and nothing
#     is left behind
#   * level 3 replays the tree into scratch with the fixture's EXACT file set
#     (the deletion replayed, not resurrected) and loads the dump into a
#     throwaway database with a table count above zero and the fixture's row
#     count
#   * after a pass and after a failure the scratch tree and the throwaway
#     database are gone

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
TOOLS="$ROOT/maintenance_scripts/sysadmin_tools"
BACKUP="$TOOLS/backup_files.sh"
DBBACKUP="$TOOLS/backup_database.sh"
VERIFY="$TOOLS/verify_backup.sh"
ENVTOOL="$TOOLS/backup_envelope.php"

passed=0; failed=0
chk() {
    if [ "$2" = "$3" ]; then
        echo "  PASS: $1"; passed=$((passed+1))
    else
        echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed+1))
    fi
}
kv() { printf '%s\n' "$1" | sed -n "s/^$2=//p" | head -1; }

# Database password the way the engines resolve it.
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

W=$(mktemp -d /tmp/jy_verify_gate_XXXXXX)
SRC="jt_vb_src_$$"
cleanup() {
    dropdb -U postgres --if-exists "$SRC" >/dev/null 2>&1
    rm -rf "$W"
}
trap cleanup EXIT
mkdir -p "$W/site" "$W/arts"

# ── The fixture chain ───────────────────────────────────────────────────────
echo "== Fixture chain =="
php "$ENVTOOL" mint --artifact chain \
    --key-out "$W/chain.key" --sidecar-out "$W/envelope.json" >/dev/null 2>&1
chk "chain key minted" "$([ -s "$W/chain.key" ] && echo yes || echo no)" "yes"

SNAR="$W/site.snar"
mk() { mkdir -p "$(dirname "$W/site/$1")"; echo "$2" > "$W/site/$1"; touch -d "$3" "$W/site/$1"; }

mk "keep.txt"        "original" "2026-01-01"
mk "will_change.txt" "before"   "2026-01-01"
mk "will_delete.txt" "doomed"   "2026-01-01"
mk "sub/nested.txt"  "nested"   "2026-01-01"
OUT=$(bash "$BACKUP" testsite --project-dir "$W/site" --output-dir "$W/arts" \
        --name files-0000 --snar "$SNAR" --key-file "$W/chain.key" 2>/dev/null)
F0=$(kv "$OUT" ARCHIVE); B0=$(kv "$OUT" BYTES); H0=$(kv "$OUT" SHA256)
chk "run 0 is a full" "$(kv "$OUT" LEVEL)" "0"

mk "will_change.txt" "after" "2026-06-01"
rm -f "$W/site/will_delete.txt"
mk "added.txt" "new file" "2026-06-01"
OUT=$(bash "$BACKUP" testsite --project-dir "$W/site" --output-dir "$W/arts" \
        --name files-0001 --snar "$SNAR" --key-file "$W/chain.key" 2>/dev/null)
F1=$(kv "$OUT" ARCHIVE); B1=$(kv "$OUT" BYTES); H1=$(kv "$OUT" SHA256)
chk "run 1 is an incremental" "$(kv "$OUT" LEVEL)" "1"

# A database with known content, dumped with the chain key the way a run
# dumps it, and filed positionally the way the chain files it.
dropdb -U postgres --if-exists "$SRC" >/dev/null 2>&1
createdb -U postgres "$SRC"
psql -U postgres -d "$SRC" -q -c \
    "CREATE TABLE t(id int primary key, v text); INSERT INTO t SELECT g,'row'||g FROM generate_series(1,500) g;"
( cd "$W/arts" && bash "$DBBACKUP" --non-interactive --key-file "$W/chain.key" "$SRC" >/dev/null 2>&1 )
DUMP=$(ls "$W/arts"/${SRC}-*.sql.gz.enc 2>/dev/null | head -1)
chk "the fixture database dumped" "$([ -s "$DUMP" ] && echo yes || echo no)" "yes"
mv "$DUMP" "$W/arts/db-0001.sql.gz.enc"
D1B=$(stat -c %s "$W/arts/db-0001.sql.gz.enc"); D1H=$(sha256sum "$W/arts/db-0001.sql.gz.enc" | cut -d' ' -f1)

cat > "$W/arts/manifest.json" <<JSON
{
  "version": 1,
  "chain_id": "chain-20260802_120000",
  "slug": "testsite",
  "created": "2026-08-02T12:00:00Z",
  "updated": "2026-08-02T12:05:00Z",
  "runs": [
    {"seq": 0, "level": 0, "time": "2026-08-02T12:00:00Z",
     "artifacts": {"files": {"name": "$(basename "$F0")", "bytes": $B0, "sha256": "$H0"}}},
    {"seq": 1, "level": 1, "time": "2026-08-02T12:05:00Z",
     "artifacts": {"files": {"name": "$(basename "$F1")", "bytes": $B1, "sha256": "$H1"},
                   "db":    {"name": "db-0001.sql.gz.enc", "bytes": $D1B, "sha256": "$D1H"}}}
  ]
}
JSON

leftover_dbs() { psql -U postgres -XtAc "SELECT count(*) FROM pg_database WHERE datname LIKE 'verify\\_testsite\\_%'"; }

# ── Level 2: opened and read ────────────────────────────────────────────────
echo "== Level 2: opened and read =="
OUT=$(bash "$VERIFY" --artifacts "$W/arts" --key-file "$W/chain.key" --level 2 2>/dev/null); RC=$?
chk "level 2 exits 0" "$RC" "0"
chk "and reports a pass" "$(kv "$OUT" VERIFY_RESULT)" "pass"
chk "at level 2" "$(kv "$OUT" VERIFY_LEVEL)" "2"
chk "of the newest run" "$(kv "$OUT" VERIFY_RUN)" "chain-20260802_120000/1"
chk "with the run's time" "$(kv "$OUT" VERIFY_RUN_TIME)" "2026-08-02 12:05:00"
chk "having read the full, the incremental and the dump" "$(kv "$OUT" VERIFY_ARTIFACTS)" "3"
chk "and every byte of them" "$(kv "$OUT" VERIFY_BYTES)" "$((B0 + B1 + D1B))"
chk "and listed entries" "$([ "$(kv "$OUT" VERIFY_FILES)" -gt 0 ] && echo yes || echo no)" "yes"
chk "with no reason line on a pass" "$(kv "$OUT" VERIFY_REASON)" ""
chk "offloaded files are reported unproven on the shell path (no site key here)" "$(kv "$OUT" VERIFY_OBJECTS)" "0"
chk "the chain directory is left as it was" \
    "$(ls "$W/arts" | sort | tr '\n' ' ')" "db-0001.sql.gz.enc files-0000.tar.gz.enc files-0001.tar.gz.enc manifest.json "

echo "== Level 2 as at run 0 =="
OUT=$(bash "$VERIFY" --artifacts "$W/arts" --key-file "$W/chain.key" --level 2 --seq 0 2>/dev/null); RC=$?
chk "run 0 passes" "$(kv "$OUT" VERIFY_RESULT)" "pass"
chk "and reads only the full (run 0 has no dump)" "$(kv "$OUT" VERIFY_ARTIFACTS)" "1"

# ── One flipped byte fails, naming the artifact ─────────────────────────────
echo "== A damaged artifact =="
cp -r "$W/arts" "$W/arts_bad"
printf '\x00' | dd of="$W/arts_bad/$(basename "$F1")" bs=1 seek=100 count=1 conv=notrunc 2>/dev/null
OUT=$(bash "$VERIFY" --artifacts "$W/arts_bad" --key-file "$W/chain.key" --level 2 2>/dev/null); RC=$?
chk "a flipped byte exits 1" "$RC" "1"
chk "and reports a failure" "$(kv "$OUT" VERIFY_RESULT)" "fail"
chk "naming the artifact" "$(kv "$OUT" VERIFY_REASON | grep -c "$(basename "$F1")")" "1"
chk "and saying why" "$(kv "$OUT" VERIFY_REASON | grep -ci 'recorded hash')" "1"
chk "having read only what came before it" "$(kv "$OUT" VERIFY_ARTIFACTS)" "1"

# A wrong key: the hashes are right, the decrypt is not.
head -c 32 /dev/urandom | base64 > "$W/badkey"
OUT=$(bash "$VERIFY" --artifacts "$W/arts" --key-file "$W/badkey" --level 2 2>/dev/null); RC=$?
chk "a wrong key fails" "$(kv "$OUT" VERIFY_RESULT)" "fail"
chk "naming the first artifact it could not open" "$(kv "$OUT" VERIFY_REASON | grep -c "$(basename "$F0")")" "1"

# ── Level 3: rehearsed ──────────────────────────────────────────────────────
echo "== Level 3: rehearsed =="
BEFORE_DBS=$(leftover_dbs)
OUT=$(bash "$VERIFY" --artifacts "$W/arts" --key-file "$W/chain.key" --level 3 2>/dev/null); RC=$?
chk "level 3 exits 0" "$RC" "0"
chk "and reports a pass" "$(kv "$OUT" VERIFY_RESULT)" "pass"
chk "at level 3" "$(kv "$OUT" VERIFY_LEVEL)" "3"
# The file set as at run 1, EXACTLY: the deletion replayed, the addition present.
chk "the scratch tree held exactly the run-1 file set" "$(kv "$OUT" VERIFY_FILES)" "4"
chk "the throwaway database had tables" "$([ "$(kv "$OUT" VERIFY_TABLES)" -gt 0 ] && echo yes || echo no)" "yes"
chk "and the fixture's rows came back" "$(kv "$OUT" VERIFY_ROWS | tr ',' '\n' | grep -c '^t:500$')" "1"
chk "the scratch tree is gone afterwards" "$([ -e "$W/arts/scratch" ] && echo present || echo gone)" "gone"
chk "and the throwaway database is gone" "$(leftover_dbs)" "$BEFORE_DBS"
chk "and the chain directory is left as it was" \
    "$(ls "$W/arts" | sort | tr '\n' ' ')" "db-0001.sql.gz.enc files-0000.tar.gz.enc files-0001.tar.gz.enc manifest.json "

echo "== Level 3 on the damaged chain =="
OUT=$(bash "$VERIFY" --artifacts "$W/arts_bad" --key-file "$W/chain.key" --level 3 2>/dev/null); RC=$?
chk "a damaged chain fails a rehearsal too" "$(kv "$OUT" VERIFY_RESULT)" "fail"
chk "at level 3" "$(kv "$OUT" VERIFY_LEVEL)" "3"
chk "the scratch tree is gone after a failure" "$([ -e "$W/arts_bad/scratch" ] && echo present || echo gone)" "gone"
chk "and no throwaway database was left behind" "$(leftover_dbs)" "$BEFORE_DBS"

# A rehearsal aimed at the wrong project name is refused by the restore, and
# still cleans up after itself.
OUT=$(bash "$VERIFY" --artifacts "$W/arts" --key-file "$W/chain.key" --level 3 --project wrongname 2>/dev/null); RC=$?
chk "a wrong --project fails rather than restoring somewhere else" "$(kv "$OUT" VERIFY_RESULT)" "fail"
chk "the scratch tree is gone after that too" "$([ -e "$W/arts/scratch" ] && echo present || echo gone)" "gone"

# ── Requests the entry refuses ──────────────────────────────────────────────
echo "== Refusals =="
bash "$VERIFY" --artifacts "$W/arts" --key-file "$W/chain.key" --level 1 >/dev/null 2>&1; RC=$?
chk "level 1 is not something this entry runs" "$RC" "2"
bash "$VERIFY" --artifacts "$W/arts" --level 2 >/dev/null 2>&1; RC=$?
chk "no key file, no verify" "$RC" "2"
bash "$VERIFY" --artifacts "$W/nowhere" --key-file "$W/chain.key" >/dev/null 2>&1; RC=$?
chk "a missing chain directory is refused" "$RC" "2"

echo
echo "RESULT: $([ $failed -eq 0 ] && echo PASS || echo FAIL) $passed $failed"
[ $failed -eq 0 ]
