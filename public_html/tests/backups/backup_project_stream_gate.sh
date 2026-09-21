#!/bin/bash
# @joinery-test
# name: backup_project_stream
# tier: db
# env: dev-only
# timeout: 300
# needs: []
#
# backup_project.sh stream mode: the standalone whole-site archive streams to
# stdout with NO copy of the site tree made on the way.
#
# Against a throwaway tree and a throwaway database:
#
#   - the streamed archive has the layout restore_project.sh reads: one
#     top-level directory, the dump at its root, project_files/ and
#     shape.json under it — and restore_project.sh --dry-run accepts it
#   - it holds the same project files and the same database as the written
#     archive file mode produces
#   - while the stream is flowing, the staging directory holds only the dump
#     and the small metadata — there is no project_files/ copy
#   - symbolic links keep their targets; excluded directories (vendor/,
#     backups/) are in neither archive
#   - the report is written after the stream and says TAR_RC/ENC_RC
#
# The in-place restore onto /var/www/html/PROJECT needs root and a vhost and
# belongs to the deploy tier on a real node, as backup_key_file_gate.sh notes.

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
TOOLS="$ROOT/maintenance_scripts/sysadmin_tools"
BACKUP="$TOOLS/backup_project.sh"
RESTORE="$TOOLS/restore_project.sh"

passed=0; failed=0
chk() {
    if [ "$2" = "$3" ]; then
        echo "  PASS: $1"; passed=$((passed+1))
    else
        echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed+1))
    fi
}

# The database password the way the scripts themselves find it: read into the
# environment, never printed. The throwaway database is created and dropped
# with it.
if [ -z "${PGPASSWORD:-}" ] && [ -f "$ROOT/config/Globalvars_site.php" ]; then
    PGPASSWORD=$(grep "dbpassword.*=" "$ROOT/config/Globalvars_site.php" | head -1 | sed "s/.*'\(.*\)'.*/\1/")
    export PGPASSWORD
fi
if ! psql -U postgres -h localhost -c 'select 1' >/dev/null 2>&1; then
    echo "  SKIP: no postgres connection available"
    echo "RESULT: PASS 0 0"
    exit 0
fi

PROJ="jyps_$$"
W=$(mktemp -d /tmp/jy_project_stream_gate_XXXXXX)
cleanup() {
    psql -U postgres -h localhost -c "DROP DATABASE IF EXISTS \"$PROJ\"" >/dev/null 2>&1
    chmod -R u+rwX "$W" 2>/dev/null; rm -rf "$W"
}
trap cleanup EXIT
mkdir -p "$W/site/public_html/sub" "$W/site/config" "$W/site/vendor" "$W/site/backups" "$W/file" "$W/stream" "$W/x"

# A tree that says it is a container, so the vhost lookup is skipped.
cat > "$W/site/config/Globalvars_site.php" <<PHP
<?php
\$this->settings['deployment_environment'] = 'docker';
\$this->settings['dbusername'] = 'postgres';
\$this->settings['dbname'] = '$PROJ';
PHP
echo '<?php echo "hi";' > "$W/site/public_html/index.php"
head -c 3000000 /dev/urandom > "$W/site/public_html/sub/blob.bin"
echo "vendored" > "$W/site/vendor/lib.php"
echo "an earlier archive" > "$W/site/backups/old-20260101_000000.tar.gz.enc"
ln -s public_html/index.php "$W/site/link_to_index"
ln -s ./public_html "$W/site/link_to_dir"
ln "$W/site/public_html/index.php" "$W/site/public_html/index_hardlink.php"

psql -U postgres -h localhost -c "CREATE DATABASE \"$PROJ\" TEMPLATE template0" >/dev/null 2>&1
psql -U postgres -h localhost -d "$PROJ" -c "CREATE TABLE t (id int, name text); INSERT INTO t VALUES (1,'one'),(2,'two')" >/dev/null 2>&1
chk "throwaway database exists" "$(psql -U postgres -h localhost -tAc "select count(*) from t" "$PROJ")" "2"

head -c 32 /dev/urandom | base64 > "$W/key"; chmod 600 "$W/key"

# ── File mode ───────────────────────────────────────────────────────────────
echo "== File mode, for comparison =="
bash "$BACKUP" "$PROJ" --non-interactive --key-file "$W/key" --output-dir "$W/file" \
    --project-dir "$W/site" --name "$PROJ-file" > "$W/file/stdout" 2>"$W/file/stderr"; RC=$?
chk "file mode exits 0" "$RC" "0"
chk "file mode names its archive" "$(grep -o '^BACKUP_ARCHIVE=.*' "$W/file/stdout")" "BACKUP_ARCHIVE=$W/file/$PROJ-file.tar.gz.enc"
# Without --name the stamp is YYYYMMDD_HHMMSS — the one a management node's
# retention sorts a shelf by; a standalone project archive carrying any other
# shape sorted as the oldest thing on the shelf and was pruned first.
bash "$BACKUP" "$PROJ" --non-interactive --key-file "$W/key" --output-dir "$W/x" \
    --project-dir "$W/site" > "$W/x/stdout_default" 2>/dev/null
chk "the default archive name carries the YYYYMMDD_HHMMSS stamp" \
    "$(grep -o "^BACKUP_ARCHIVE=.*" "$W/x/stdout_default" | grep -cE "/$PROJ-[0-9]{8}_[0-9]{6}\.tar\.gz\.enc$")" "1"
rm -f "$W/x/$PROJ"-*.tar.gz.enc

# ── Stream mode, watched ────────────────────────────────────────────────────
echo "== Stream mode: no copy of the tree is staged =="
# A slow reader: take the first 4 KB — by then the dump is staged and tar is
# blocked on the un-drained pipe — look at the staging directory, then drain.
bash "$BACKUP" "$PROJ" --non-interactive --key-file "$W/key" --output-dir "$W/stream" \
    --project-dir "$W/site" --name "$PROJ-stream" --archive - --report "$W/stream/report" 2>"$W/stream/stderr" | {
    head -c 4096 > "$W/stream/out.enc"
    sleep 0.5
    staging=$(ls -d "$W/stream"/.staging_* 2>/dev/null | head -1)
    if [ -n "$staging" ]; then
        ls -A "$staging/$PROJ-stream" > "$W/x/staged.list" 2>/dev/null
        [ -e "$W/stream/report" ] && echo early > "$W/x/timing" || echo notyet > "$W/x/timing"
    else
        echo "no staging dir" > "$W/x/staged.list"; echo unknown > "$W/x/timing"
    fi
    cat >> "$W/stream/out.enc"
}
RC=${PIPESTATUS[0]}
chk "stream mode exits 0" "$RC" "0"
chk "the report says TAR_RC=0 ENC_RC=0" "$(tr '\n' ' ' < "$W/stream/report")" "TAR_RC=0 ENC_RC=0 "
chk "no report while the stream was still flowing" "$(cat "$W/x/timing")" "notyet"
chk "the staging directory held the dump while the stream flowed" \
    "$(grep -c "^$PROJ-.*\.sql\.gz\.enc$" "$W/x/staged.list")" "1"
chk "and no project_files/ copy" "$(grep -c '^project_files$' "$W/x/staged.list")" "0"
chk "the staging directory is gone afterwards" "$(ls -d "$W/stream"/.staging_* 2>/dev/null | wc -l | tr -d ' ')" "0"
chk "no archive file was written" "$(ls "$W/stream"/*.tar.gz.enc 2>/dev/null | wc -l | tr -d ' ')" "0"
chk "stdout is an openssl envelope" "$(head -c 8 "$W/stream/out.enc")" "Salted__"
chk "every human line went to stderr" "$(grep -c 'Stream mode' "$W/stream/stderr")" "1"

# ── Same contents ───────────────────────────────────────────────────────────
echo "== The streamed archive matches the written one =="
mkdir -p "$W/x/file" "$W/x/stream"
openssl enc -d -aes-256-cbc -pbkdf2 -pass fd:3 -in "$W/file/$PROJ-file.tar.gz.enc" 3< "$W/key" | tar -xz -C "$W/x/file"
openssl enc -d -aes-256-cbc -pbkdf2 -pass fd:3 -in "$W/stream/out.enc" 3< "$W/key" | tar -xz -C "$W/x/stream"; RC=$?
chk "the streamed archive extracts" "$RC" "0"
chk "one top-level directory, named for the archive" "$(ls "$W/x/stream")" "$PROJ-stream"
F="$W/x/file/$PROJ-file"; S="$W/x/stream/$PROJ-stream"
chk "project_files/ is under it" "$([ -d "$S/project_files" ] && echo yes || echo no)" "yes"
chk "the dump is at its root" "$(ls "$S"/*.sql.gz.enc 2>/dev/null | wc -l | tr -d ' ')" "1"
chk "shape.json is beside the dump" "$([ -f "$S/shape.json" ] && echo yes || echo no)" "yes"
chk "backup_info.txt is beside the dump" "$([ -f "$S/backup_info.txt" ] && echo yes || echo no)" "yes"
chk "project_files/ trees are identical" \
    "$(diff -r --no-dereference "$F/project_files" "$S/project_files" >/dev/null && echo same || echo differ)" "same"
chk "the big file survived byte for byte" "$(cmp -s "$W/site/public_html/sub/blob.bin" "$S/project_files/public_html/sub/blob.bin" && echo same || echo differ)" "same"
chk "a file symlink keeps its target" "$(readlink "$S/project_files/link_to_index")" "public_html/index.php"
chk "a hard link extracts, its target renamed with the member it points at" "$(cmp -s "$S/project_files/public_html/index.php" "$S/project_files/public_html/index_hardlink.php" && [ "$(stat -c %h "$S/project_files/public_html/index_hardlink.php")" = "2" ] && echo linked || echo broken)" "linked"
chk "a directory symlink keeps its ./ target untouched by the rename" "$(readlink "$S/project_files/link_to_dir")" "./public_html"
chk "vendor/ is in neither archive" "$([ -e "$F/project_files/vendor" ] || [ -e "$S/project_files/vendor" ] && echo present || echo absent)" "absent"
chk "backups/ (earlier archives) is in neither archive" "$([ -e "$F/project_files/backups" ] || [ -e "$S/project_files/backups" ] && echo present || echo absent)" "absent"
chk "no stray ./ member and nothing outside the top directory" "$(openssl enc -d -aes-256-cbc -pbkdf2 -pass fd:3 -in "$W/stream/out.enc" 3< "$W/key" | tar -tz | grep -v "^$PROJ-stream/" | wc -l | tr -d ' ')" "0"

fd=$(ls "$F"/*.sql.gz.enc); sd=$(ls "$S"/*.sql.gz.enc)
openssl enc -d -aes-256-cbc -pbkdf2 -pass fd:3 -in "$fd" 3< "$W/key" | gunzip > "$W/x/file.sql"
openssl enc -d -aes-256-cbc -pbkdf2 -pass fd:3 -in "$sd" 3< "$W/key" | gunzip > "$W/x/stream.sql"
# pg_dump salts each dump with a one-off \restrict token; everything else is the data.
chk "the dumps decrypt to the same SQL" "$(diff <(grep -v '^\\\(un\)\?restrict ' "$W/x/file.sql") <(grep -v '^\\\(un\)\?restrict ' "$W/x/stream.sql") >/dev/null && echo same || echo differ)" "same"
chk "and the SQL carries the rows" "$(grep -c "^1	one$" "$W/x/stream.sql")" "1"

# ── restore_project.sh accepts it ───────────────────────────────────────────
echo "== restore_project.sh reads the streamed archive =="
cp "$W/stream/out.enc" "$W/x/$PROJ-stream.tar.gz.enc"
OUT=$(bash "$RESTORE" "$PROJ" "$W/x/$PROJ-stream.tar.gz.enc" --dry-run --key-file "$W/key" 2>&1); RC=$?
chk "--dry-run verification passes" "$RC" "0"
chk "it found the database backup" "$(echo "$OUT" | grep -c 'Database backup found')" "1"
chk "it found the project files" "$(echo "$OUT" | grep -c 'Project files found')" "1"
chk "it read the shape" "$(echo "$OUT" | grep -c 'Shape recorded')" "1"

# ── Argument contract ───────────────────────────────────────────────────────
echo "== Argument contract =="
bash "$BACKUP" "$PROJ" --non-interactive --key-file "$W/key" --output-dir "$W/stream" --project-dir "$W/site" --archive - >/dev/null 2>"$W/x/noreport"; RC=$?
chk "--archive - without --report is refused" "$RC" "1"
chk "and says why" "$(grep -c 'requires --report' "$W/x/noreport")" "1"

# ── --exclude-from: the offloaded files' local paths ────────────────────────
echo "== --exclude-from leaves out exactly the listed paths =="
mkdir -p "$W/site/static_files/uploads/avatar" "$W/site/uploads" "$W/x/ex"
echo "cloud blob"    > "$W/site/static_files/uploads/photo_x1.jpg"
echo "its variant"   > "$W/site/static_files/uploads/avatar/photo_x1.jpg"
echo "not offloaded" > "$W/site/static_files/uploads/kept_y2.jpg"
echo "restricted"    > "$W/site/uploads/photo_x1.jpg"
echo "same name, unlisted path" > "$W/site/public_html/photo_x1.jpg"
printf '%s\n' static_files/uploads/photo_x1.jpg static_files/uploads/avatar/photo_x1.jpg uploads/photo_x1.jpg > "$W/x/exclude"
bash "$BACKUP" "$PROJ" --non-interactive --key-file "$W/key" --output-dir "$W/stream" --project-dir "$W/site" \
    --name "$PROJ-ex" --archive - --report "$W/stream/report_ex" --exclude-from "$W/x/exclude" > "$W/stream/ex.enc" 2>"$W/stream/ex.err"; RC=$?
chk "stream mode with --exclude-from exits 0" "$RC" "0"
openssl enc -d -aes-256-cbc -pbkdf2 -pass fd:3 -in "$W/stream/ex.enc" 3< "$W/key" | tar -tz > "$W/x/ex.list"
P="$PROJ-ex/project_files"
chk "the listed original is left out" "$(grep -c "^$P/static_files/uploads/photo_x1.jpg$" "$W/x/ex.list")" "0"
chk "the listed variant is left out" "$(grep -c "^$P/static_files/uploads/avatar/photo_x1.jpg$" "$W/x/ex.list")" "0"
chk "the listed restricted path is left out" "$(grep -c "^$P/uploads/photo_x1.jpg$" "$W/x/ex.list")" "0"
chk "an unlisted file beside them stays" "$(grep -c "^$P/static_files/uploads/kept_y2.jpg$" "$W/x/ex.list")" "1"
chk "a same-named file at an unlisted path stays" "$(grep -c "^$P/public_html/photo_x1.jpg$" "$W/x/ex.list")" "1"
chk "the uploads directories themselves are still archived" "$(grep -c "^$P/static_files/uploads/avatar/$" "$W/x/ex.list")" "1"

echo
echo "RESULT: $([ $failed -eq 0 ] && echo PASS || echo FAIL) $passed $failed"
[ $failed -eq 0 ]
