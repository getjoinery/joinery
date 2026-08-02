#!/bin/bash
# @joinery-test
# name: backup_key_file
# tier: db
# env: dev-only
# needs: []
# timeout: 180
#
# Envelope backups mint a key per run and point the engines at it with
# --key-file. Two properties have to hold or a backup is silently worthless:
#
#   * --key-file is actually the key used — a dump made with it restores with
#     it, and does NOT restore with the key that happens to be lying in $HOME
#   * an automated run that CANNOT encrypt fails instead of quietly writing a
#     plaintext archive; the project archive carries config/ (database password,
#     secret box key, agent signing key), so a silent downgrade is the whole
#     disaster in one step
#
# The full project-archive round trip needs a project directory plus a vhost and
# so belongs to the deploy tier on a real node; what is exercised here is every
# part of the contract that does not.

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
TOOLS="$ROOT/maintenance_scripts/sysadmin_tools"
BACKUP_DB="$TOOLS/backup_database.sh"
RESTORE_DB="$TOOLS/restore_database.sh"
BACKUP_PROJ="$TOOLS/backup_project.sh"

passed=0; failed=0
chk() {
    if [ "$2" = "$3" ]; then
        echo "  PASS: $1"; passed=$((passed+1))
    else
        echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed+1))
    fi
}

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
SRC="jt_kf_src_$SUF"
DST="jt_kf_dst_$SUF"
WORK=$(mktemp -d /tmp/jy_keyfile_gate_XXXXXX)
FAKE_HOME="$WORK/home"
mkdir -p "$FAKE_HOME"

cleanup() {
    cd /
    psql -U postgres -c "DROP DATABASE IF EXISTS $SRC;" >/dev/null 2>&1
    psql -U postgres -c "DROP DATABASE IF EXISTS $DST;" >/dev/null 2>&1
    rm -rf "$WORK"
}
trap cleanup EXIT

echo "== Fixture =="
psql -U postgres -c "CREATE DATABASE $SRC;" >/dev/null 2>&1
psql -U postgres -d "$SRC" -c "CREATE TABLE t (id int); INSERT INTO t SELECT generate_series(1,42);" >/dev/null 2>&1
SRC_ROWS=$(psql -U postgres -d "$SRC" -tAc "SELECT count(*) FROM t;" 2>/dev/null)
chk "fixture has rows" "$SRC_ROWS" "42"

# The key for THIS run, and a decoy in $HOME. If --key-file were ignored and the
# home key used instead, the decoy would decrypt the dump and the negative test
# below would fail — which is exactly what makes this a real check.
RUN_KEY="$WORK/run.key"
printf '%s' "$(head -c 32 /dev/urandom | base64)" > "$RUN_KEY"
chmod 600 "$RUN_KEY"
printf '%s' "$(head -c 32 /dev/urandom | base64)" > "$FAKE_HOME/.joinery_backup_key"
chmod 600 "$FAKE_HOME/.joinery_backup_key"

echo
echo "== backup_database.sh --key-file =="
cd "$WORK"
HOME="$FAKE_HOME" bash "$BACKUP_DB" --non-interactive --key-file "$RUN_KEY" "$SRC" >/dev/null 2>&1
ARCHIVE=$(ls -t "$WORK"/${SRC}-*.sql.gz.enc 2>/dev/null | head -1)
chk "encrypted dump produced" "$([ -n "$ARCHIVE" ] && echo yes || echo no)" "yes"

psql -U postgres -c "CREATE DATABASE $DST;" >/dev/null 2>&1
bash "$RESTORE_DB" "$DST" "$ARCHIVE" --non-interactive --db-user postgres --key-file "$RUN_KEY" >/dev/null 2>&1
DST_ROWS=$(psql -U postgres -d "$DST" -tAc "SELECT count(*) FROM t;" 2>/dev/null)
chk "restores with the key it was made with" "$DST_ROWS" "42"

# The decoy must NOT open it. This is the assertion that proves --key-file was
# honored rather than quietly falling through to the key in $HOME.
OUT=$(bash "$RESTORE_DB" "$DST" "$ARCHIVE" --non-interactive --db-user postgres \
        --key-file "$FAKE_HOME/.joinery_backup_key" 2>&1)
chk "the \$HOME key does NOT open it" "$(echo "$OUT" | grep -c 'DECRYPT_FAILED')" "1"

echo
echo "== --key-file refusals =="
OUT=$(HOME="$FAKE_HOME" bash "$BACKUP_DB" --non-interactive --key-file "$WORK/nope.key" "$SRC" 2>&1)
chk "a missing key file is refused" "$(echo "$OUT" | grep -ci 'does not exist')" "1"

: > "$WORK/empty.key"
OUT=$(HOME="$FAKE_HOME" bash "$BACKUP_DB" --non-interactive --key-file "$WORK/empty.key" "$SRC" 2>&1)
chk "an empty key file is refused" "$(echo "$OUT" | grep -ci 'is empty')" "1"

echo
echo "== backup_project.sh never downgrades to plaintext =="
# No --key-file, no BACKUP_ENCRYPTION_KEY, no key in $HOME: an automated run has
# no way to encrypt and must stop. This check runs before the project directory
# is looked at, so it needs no project on disk.
rm -f "$FAKE_HOME/.joinery_backup_key"
OUT=$(HOME="$FAKE_HOME" env -u BACKUP_ENCRYPTION_KEY bash "$BACKUP_PROJ" \
        no_such_project_$SUF --non-interactive --output-dir "$WORK" 2>&1)
RC=$?
chk "refuses when it cannot encrypt" "$(echo "$OUT" | grep -ci 'no key is available')" "1"
chk "and exits non-zero" "$([ $RC -ne 0 ] && echo yes || echo no)" "yes"
chk "stops before touching the project" "$(echo "$OUT" | grep -ci 'Project directory does not exist')" "0"

# --plaintext is a deliberate choice, so it proceeds past the key gate and only
# then complains about the missing project. That ordering is what proves the
# refusal above came from the key gate and not from something incidental.
OUT=$(HOME="$FAKE_HOME" env -u BACKUP_ENCRYPTION_KEY bash "$BACKUP_PROJ" \
        no_such_project_$SUF --non-interactive --plaintext --output-dir "$WORK" 2>&1)
chk "--plaintext passes the key gate deliberately" \
    "$(echo "$OUT" | grep -ci 'Project directory does not exist')" "1"

# A supplied key also passes the gate, reaching the same project check.
OUT=$(HOME="$FAKE_HOME" env -u BACKUP_ENCRYPTION_KEY bash "$BACKUP_PROJ" \
        no_such_project_$SUF --non-interactive --key-file "$RUN_KEY" --output-dir "$WORK" 2>&1)
chk "--key-file passes the key gate" \
    "$(echo "$OUT" | grep -ci 'Project directory does not exist')" "1"

OUT=$(HOME="$FAKE_HOME" env -u BACKUP_ENCRYPTION_KEY bash "$BACKUP_PROJ" \
        no_such_project_$SUF --non-interactive --key-file "$WORK/nope.key" --output-dir "$WORK" 2>&1)
chk "a missing --key-file is refused" "$(echo "$OUT" | grep -ci "does not exist")" "1"

echo
echo "== Archive encryption round trip =="
# The streaming shape the script uses: tar never lands on disk in the clear, and
# the key crosses on fd 3 rather than argv.
mkdir -p "$WORK/payload"
echo "dbpassword = 'hunter2'" > "$WORK/payload/Globalvars_site.php"
( cd "$WORK" && tar -czf - payload \
    | openssl enc -aes-256-cbc -salt -pbkdf2 -pass fd:3 -out "$WORK/archive.tar.gz.enc" ) 3< "$RUN_KEY"
chk "encrypted archive written" "$([ -s "$WORK/archive.tar.gz.enc" ] && echo yes || echo no)" "yes"
chk "secrets are not readable in the archive" \
    "$(grep -ac 'hunter2' "$WORK/archive.tar.gz.enc" 2>/dev/null || true)" "0"

mkdir -p "$WORK/extract"
( openssl enc -aes-256-cbc -d -pbkdf2 -pass fd:3 -in "$WORK/archive.tar.gz.enc" \
    | tar -xz -C "$WORK/extract" ) 3< "$RUN_KEY"
chk "round trips back to the original bytes" \
    "$(cat "$WORK/extract/payload/Globalvars_site.php" 2>/dev/null)" "dbpassword = 'hunter2'"

echo
echo "== restore_project.sh opens encrypted archives =="
RESTORE_PROJ="$TOOLS/restore_project.sh"

# A project-shaped backup directory, encrypted the way backup_project.sh writes.
STAGE="$WORK/stage/mysite-2026-01-01-000000"
mkdir -p "$STAGE/project_files" "$STAGE/apache_config"
echo "Backup Information" > "$STAGE/backup_info.txt"
echo "site content" > "$STAGE/project_files/index.php"
( cd "$WORK/stage" && tar -czf - mysite-2026-01-01-000000 \
    | openssl enc -aes-256-cbc -salt -pbkdf2 -pass fd:3 -out "$WORK/proj.tar.gz.enc" ) 3< "$RUN_KEY"

OUT=$(bash "$RESTORE_PROJ" mysite "$WORK/proj.tar.gz.enc" --dry-run --key-file "$RUN_KEY" 2>&1)
RC=$?
chk "encrypted archive opens with --key-file" "$([ $RC -eq 0 ] && echo yes || echo no)" "yes"
chk "and its contents are seen" "$(echo "$OUT" | grep -ci 'Backup info file found')" "1"

WRONG="$WORK/wrong.key"
printf '%s' "$(head -c 32 /dev/urandom | base64)" > "$WRONG"
OUT=$(bash "$RESTORE_PROJ" mysite "$WORK/proj.tar.gz.enc" --dry-run --key-file "$WRONG" 2>&1)
RC=$?
chk "a wrong key fails the restore" "$([ $RC -ne 0 ] && echo yes || echo no)" "yes"
chk "and says the key may be wrong" "$(echo "$OUT" | grep -ci 'key may be wrong')" "1"

# Detection reads the openssl magic, not the name: a renamed archive still
# opens, which is what stops extension-sniffing from becoming load-bearing.
cp "$WORK/proj.tar.gz.enc" "$WORK/renamed.bin"
OUT=$(bash "$RESTORE_PROJ" mysite "$WORK/renamed.bin" --dry-run --key-file "$RUN_KEY" 2>&1)
chk "a renamed encrypted archive still opens" "$(echo "$OUT" | grep -ci 'Backup info file found')" "1"

# An encrypted archive with no key at all must say so plainly rather than
# reporting a corrupt file.
OUT=$(HOME="$FAKE_HOME" bash "$RESTORE_PROJ" no_such_project_$SUF "$WORK/proj.tar.gz.enc" --dry-run 2>&1)
RC=$?
chk "no key available is reported as such" "$(echo "$OUT" | grep -ci 'encrypted and no key is available')" "1"
chk "and the restore stops" "$([ $RC -ne 0 ] && echo yes || echo no)" "yes"

# Plaintext archives keep working unchanged.
( cd "$WORK/stage" && tar -czf "$WORK/proj.tar.gz" mysite-2026-01-01-000000 )
OUT=$(bash "$RESTORE_PROJ" mysite "$WORK/proj.tar.gz" --dry-run 2>&1)
chk "plaintext archives still restore" "$(echo "$OUT" | grep -ci 'Backup info file found')" "1"

echo
echo "== Node backup lifecycle (what the job steps do, in order) =="
# Mirrors JobCommandBuilder's step sequence end to end: mint -> engine encrypts
# with the minted key -> relabel the envelope onto the finished archive ->
# destroy the plaintext key -> restore later using nothing but the envelope and
# the site's own key. If any step's naming or ordering is wrong, the last
# decrypt fails, which is the only assertion that matters.
ENV_TOOL="$TOOLS/backup_envelope.php"
KEYGEN="$TOOLS/escrow_keypair.php"
LC="$WORK/lifecycle"
mkdir -p "$LC/config" "$LC/backups"

php "$KEYGEN" generate --private-out "$LC/recovery.key" > "$LC/recovery.pub" 2>/dev/null
REC_PUB=$(cat "$LC/recovery.pub")

# 1. mint, under a working name — the archive has no name yet
php "$ENV_TOOL" mint --recovery-pub "$REC_PUB" --artifact pending \
    --key-out "$LC/backups/.jy_envelope.key" \
    --sidecar-out "$LC/backups/.jy_envelope.keys.json" \
    --site-key "$LC/config/backup_site_key" >/dev/null 2>&1
chk "1. envelope minted" "$([ -f "$LC/backups/.jy_envelope.key" ] && echo yes || echo no)" "yes"

# 2. the engine encrypts with it and picks its own timestamped name
mkdir -p "$LC/src/site-2026-08-02-120000/project_files"
echo "dbpassword = 'hunter2'" > "$LC/src/site-2026-08-02-120000/project_files/Globalvars_site.php"
( cd "$LC/src" && tar -czf - site-2026-08-02-120000 \
    | openssl enc -aes-256-cbc -salt -pbkdf2 -pass fd:3 \
        -out "$LC/backups/site-2026-08-02-120000.tar.gz.enc" ) 3< "$LC/backups/.jy_envelope.key"

# 3. relabel onto the finished archive, exactly as the finalize step does
ARCHIVE=$(ls -t "$LC"/backups/*.tar.gz.enc 2>/dev/null | head -1)
php "$ENV_TOOL" relabel --sidecar "$LC/backups/.jy_envelope.keys.json" \
    --artifact "$ARCHIVE" --out "$ARCHIVE.keys.json" >/dev/null 2>&1
chk "3. envelope now sits beside the archive" "$([ -f "$ARCHIVE.keys.json" ] && echo yes || echo no)" "yes"
chk "   and the working copy is gone" \
    "$([ -f "$LC/backups/.jy_envelope.keys.json" ] && echo yes || echo no)" "no"
chk "   and it names the archive it belongs to" \
    "$(grep -o 'site-2026-08-02-120000.tar.gz.enc' "$ARCHIVE.keys.json" | head -1)" \
    "site-2026-08-02-120000.tar.gz.enc"

# 4. destroy the plaintext key
rm -f "$LC/backups/.jy_envelope.key"
chk "4. no plaintext key is left beside the archive" \
    "$(ls "$LC"/backups/*.key 2>/dev/null | wc -l)" "0"

# 5. restore with nothing but what is on the node: the archive, its envelope,
#    and the site's own key. No operator, no password manager.
php "$ENV_TOOL" open --sidecar "$ARCHIVE.keys.json" \
    --private "$LC/config/backup_site_key" --key-out "$LC/recovered.key" >/dev/null 2>&1
mkdir -p "$LC/out"
( openssl enc -aes-256-cbc -d -pbkdf2 -pass fd:3 -in "$ARCHIVE" | tar -xz -C "$LC/out" ) 3< "$LC/recovered.key"
chk "5. the node restores itself from the envelope alone" \
    "$(cat "$LC/out/site-2026-08-02-120000/project_files/Globalvars_site.php" 2>/dev/null)" \
    "dbpassword = 'hunter2'"

# ...and the operator's recovery key opens the same archive independently, which
# is what makes losing the whole node survivable.
php "$ENV_TOOL" open --sidecar "$ARCHIVE.keys.json" \
    --private "$LC/recovery.key" --key-out "$LC/recovered2.key" >/dev/null 2>&1
chk "   the recovery key opens it too" \
    "$(cmp -s "$LC/recovered.key" "$LC/recovered2.key" && echo same || echo different)" "same"

# Losing the site key must cost nothing.
rm -f "$LC/config/backup_site_key"
mkdir -p "$LC/out2"
( openssl enc -aes-256-cbc -d -pbkdf2 -pass fd:3 -in "$ARCHIVE" | tar -xz -C "$LC/out2" ) 3< "$LC/recovered2.key"
chk "   losing the site key loses nothing" \
    "$(cat "$LC/out2/site-2026-08-02-120000/project_files/Globalvars_site.php" 2>/dev/null)" \
    "dbpassword = 'hunter2'"

echo
echo "RESULT: $([ $failed -eq 0 ] && echo PASS || echo FAIL) $passed $failed"
[ $failed -eq 0 ]
