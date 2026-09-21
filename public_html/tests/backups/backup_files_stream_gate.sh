#!/bin/bash
# @joinery-test
# name: backup_files_stream
# tier: db
# env: dev-only
# needs: []
# timeout: 300
#
# backup_files.sh stream mode, with real tar and real openssl.
#
# In stream mode the encrypted archive is stdout and nothing else is; the
# verdict (LEVEL, TAR_RC, ENC_RC) arrives afterwards in the report file,
# because tar's exit status exists only once its output has closed. The
# reader — BackupRunner — counts and hashes what it reads and completes the
# upload only after reading the report. What is pinned here:
#
#   - the stream decrypts to the same tree the file mode produces
#   - stdout carries archive bytes only (no LEVEL= or ARCHIVE= lines in it)
#   - the report is not written while the stream is still being produced,
#     and is complete when the script exits
#   - a tar failure of 2 or more is reported (TAR_RC=2, exit 1), which is
#     what the runner-side rule refuses
#   - incremental snapshots advance identically in both modes
#
# The runner-side rule itself (abort on a bad report, nothing on the shelf)
# is backup_runner_stream_test.php.

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
TOOLS="$ROOT/maintenance_scripts/sysadmin_tools"
BACKUP="$TOOLS/backup_files.sh"

passed=0; failed=0
chk() {
    if [ "$2" = "$3" ]; then
        echo "  PASS: $1"; passed=$((passed+1))
    else
        echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed+1))
    fi
}

W=$(mktemp -d /tmp/jy_files_stream_gate_XXXXXX)
trap 'chmod -R u+rwX "$W" 2>/dev/null; rm -rf "$W"' EXIT
mkdir -p "$W/site/sub" "$W/file_mode" "$W/stream_mode" "$W/x"

head -c 32 /dev/urandom | base64 > "$W/key"
chmod 600 "$W/key"

mk() { mkdir -p "$(dirname "$W/site/$1")"; echo "$2" > "$W/site/$1"; touch -d "$3" "$W/site/$1"; }
mk "keep.txt"      "original" "2026-01-01"
mk "sub/inner.txt" "inner"    "2026-01-01"
# Big enough that the pipeline cannot finish inside the pipe buffer: the
# report-timing check below depends on the producer being blocked on a
# reader that has not drained yet.
head -c 3000000 /dev/urandom > "$W/site/sub/blob.bin"; touch -d "2026-01-01" "$W/site/sub/blob.bin"

decrypt_list() {  # $1 = .enc path
    openssl enc -d -aes-256-cbc -pbkdf2 -pass fd:3 -in "$1" 3< "$W/key" | tar -tz | sort
}

# ── Full: file mode and stream mode produce the same tree ───────────────────
echo "== Full: stream mode decrypts to the tree file mode produces =="
bash "$BACKUP" site --project-dir "$W/site" --output-dir "$W/file_mode" --name files-0000 \
    --snar "$W/file.snar" --key-file "$W/key" > "$W/file_mode/stdout" 2>"$W/file_mode/stderr"; RC_FILE=$?
chk "file mode exits 0" "$RC_FILE" "0"

bash "$BACKUP" site --project-dir "$W/site" --archive - --report "$W/stream_mode/report" \
    --snar "$W/stream.snar" --key-file "$W/key" > "$W/stream_mode/files-0000.tar.gz.enc" 2>"$W/stream_mode/stderr"; RC_STREAM=$?
chk "stream mode exits 0" "$RC_STREAM" "0"

chk "the report carries LEVEL, TAR_RC and ENC_RC" \
    "$(tr '\n' ' ' < "$W/stream_mode/report")" "LEVEL=0 TAR_RC=0 ENC_RC=0 "
chk "the report carries no ARCHIVE, BYTES or SHA256 line (the reader has those)" \
    "$(grep -c -E '^(ARCHIVE|BYTES|SHA256)=' "$W/stream_mode/report")" "0"
chk "stdout is an openssl envelope, not text" \
    "$(head -c 8 "$W/stream_mode/files-0000.tar.gz.enc")" "Salted__"
chk "no report line leaked into the stream" \
    "$(grep -a -c -E '^(LEVEL|ARCHIVE|BYTES|SHA256|TAR_RC|ENC_RC)=' "$W/stream_mode/files-0000.tar.gz.enc")" "0"
chk "every human line went to stderr" \
    "$(grep -c 'Archiving' "$W/stream_mode/stderr")" "1"

decrypt_list "$W/file_mode/files-0000.tar.gz.enc" > "$W/x/file.list"
decrypt_list "$W/stream_mode/files-0000.tar.gz.enc" > "$W/x/stream.list"
chk "the streamed archive lists the same members as the written one" \
    "$(diff "$W/x/file.list" "$W/x/stream.list" >/dev/null && echo same || echo differ)" "same"
chk "and the members include the tree" "$(grep -c 'site/sub/blob.bin' "$W/x/stream.list")" "1"

mkdir -p "$W/x/restore"
openssl enc -d -aes-256-cbc -pbkdf2 -pass fd:3 -in "$W/stream_mode/files-0000.tar.gz.enc" 3< "$W/key" | tar -xz -C "$W/x/restore"
chk "the streamed archive restores the bytes" \
    "$(cmp -s "$W/site/sub/blob.bin" "$W/x/restore/site/sub/blob.bin" && echo same || echo differ)" "same"

chk "both snapshots exist" "$([ -s "$W/file.snar" ] && [ -s "$W/stream.snar" ] && echo yes || echo no)" "yes"
chk "the snapshot is owner-only in stream mode" "$(stat -c %a "$W/stream.snar")" "600"

# ── The report is written after the stream, not during it ───────────────────
echo "== The report is not written while the stream is still flowing =="
rm -f "$W/stream_mode/report"
# A slow reader: take the first 4 KB, look for the report while the producer
# is blocked on the un-drained pipe, then drain the rest.
bash "$BACKUP" site --project-dir "$W/site" --archive - --report "$W/stream_mode/report" \
    --key-file "$W/key" 2>/dev/null | {
    head -c 4096 > /dev/null
    sleep 0.5
    if [ -e "$W/stream_mode/report" ]; then echo early > "$W/x/timing"; else echo notyet > "$W/x/timing"; fi
    cat > /dev/null
}
chk "no report while bytes were still being produced" "$(cat "$W/x/timing")" "notyet"
chk "the report is there once the stream has closed and the script exited" \
    "$([ -s "$W/stream_mode/report" ] && echo yes || echo no)" "yes"

# ── Incrementals advance identically ────────────────────────────────────────
echo "== Incremental: both modes advance the snapshot the same way =="
mk "added.txt" "new" "2026-02-01"
rm "$W/site/keep.txt"
bash "$BACKUP" site --project-dir "$W/site" --output-dir "$W/file_mode" --name files-0001 \
    --snar "$W/file.snar" --key-file "$W/key" > "$W/file_mode/stdout1" 2>/dev/null; RC_FILE=$?
bash "$BACKUP" site --project-dir "$W/site" --archive - --report "$W/stream_mode/report1" \
    --snar "$W/stream.snar" --key-file "$W/key" > "$W/stream_mode/files-0001.tar.gz.enc" 2>/dev/null; RC_STREAM=$?
chk "file-mode incremental exits 0" "$RC_FILE" "0"
chk "stream-mode incremental exits 0" "$RC_STREAM" "0"
chk "file mode reports level 1" "$(grep -o '^LEVEL=.*' "$W/file_mode/stdout1")" "LEVEL=1"
chk "stream mode reports level 1" "$(grep -o '^LEVEL=.*' "$W/stream_mode/report1")" "LEVEL=1"
decrypt_list "$W/file_mode/files-0001.tar.gz.enc" > "$W/x/file1.list"
decrypt_list "$W/stream_mode/files-0001.tar.gz.enc" > "$W/x/stream1.list"
chk "the incrementals list the same members" \
    "$(diff "$W/x/file1.list" "$W/x/stream1.list" >/dev/null && echo same || echo differ)" "same"
chk "the incremental carries the added file" "$(grep -c 'site/added.txt' "$W/x/stream1.list")" "1"
chk "and not the unchanged blob" "$(grep -c 'site/sub/blob.bin' "$W/x/stream1.list")" "0"
chk "the streamed incremental is small" \
    "$([ "$(stat -c %s "$W/stream_mode/files-0001.tar.gz.enc")" -lt 20000 ] && echo yes || echo no)" "yes"
# The two snapshots describe the same tree at the same point. GNU tar's
# snapshot opens with the moment it was taken (version, seconds, nanoseconds)
# and then records the directory state; everything after the header is the
# tree, and it must be identical between the modes.
snar_body() { tr '\0' '\n' < "$1" | tail -n +4; }
chk "the two snapshots record the same tree" \
    "$(diff <(snar_body "$W/file.snar") <(snar_body "$W/stream.snar") >/dev/null && echo same || echo differ)" "same"

# ── A tar failure is reported after the stream ──────────────────────────────
echo "== A tar failure of 2 or more is reported and the exit status says so =="
if sudo -n -l 2>/dev/null | grep -Eq 'NOPASSWD:([[:space:]]*[A-Z]+:)*[[:space:]]*ALL([[:space:]]|$)'; then
    echo "  SKIP: this account has passwordless sudo, so no file is unreadable to tar"
else
    head -c 2000 /dev/urandom > "$W/site/sub/secret.bin"; chmod 000 "$W/site/sub/secret.bin"
    bash "$BACKUP" site --project-dir "$W/site" --archive - --report "$W/stream_mode/report2" \
        --key-file "$W/key" > "$W/stream_mode/failed.enc" 2>"$W/stream_mode/stderr2"; RC=$?
    chmod 600 "$W/site/sub/secret.bin"; rm -f "$W/site/sub/secret.bin"
    chk "the script exits 1" "$RC" "1"
    chk "the report says TAR_RC=2" "$(grep -o '^TAR_RC=.*' "$W/stream_mode/report2")" "TAR_RC=2"
    chk "and ENC_RC=0 (openssl was fine; it is tar that failed)" "$(grep -o '^ENC_RC=.*' "$W/stream_mode/report2")" "ENC_RC=0"
    chk "bytes did stream before the verdict — which is why the reader must wait for the report" \
        "$([ "$(stat -c %s "$W/stream_mode/failed.enc")" -gt 64 ] && echo yes || echo no)" "yes"
    chk "stderr names the failure" "$(grep -c 'tar exit 2' "$W/stream_mode/stderr2")" "1"
fi

# ── Argument contract ───────────────────────────────────────────────────────
echo "== Argument contract =="
bash "$BACKUP" site --project-dir "$W/site" --archive - --key-file "$W/key" >/dev/null 2>"$W/x/noreport"; RC=$?
chk "--archive - without --report is refused" "$RC" "1"
chk "and says why" "$(grep -c 'requires --report' "$W/x/noreport")" "1"
bash "$BACKUP" site --project-dir "$W/site" --archive "$W/x/somefile" --report "$W/x/r" --key-file "$W/key" >/dev/null 2>"$W/x/notdash"; RC=$?
chk "--archive with anything but - is refused" "$RC" "1"

# ── --exclude-from: the offloaded files' local paths ────────────────────────
echo "== --exclude-from leaves out exactly the listed paths =="
mk "static_files/uploads/photo_x1.jpg"        "cloud blob"      "2026-01-01"
mk "static_files/uploads/avatar/photo_x1.jpg" "its variant"     "2026-01-01"
mk "static_files/uploads/kept_y2.jpg"         "not offloaded"   "2026-01-01"
mk "uploads/photo_x1.jpg"                     "restricted twin" "2026-01-01"
mk "sub/photo_x1.jpg"                         "same name, elsewhere: not a listed path" "2026-01-01"
printf '%s\n' static_files/uploads/photo_x1.jpg static_files/uploads/avatar/photo_x1.jpg uploads/photo_x1.jpg > "$W/x/exclude"
bash "$BACKUP" site --project-dir "$W/site" --archive - --report "$W/stream_mode/report3" \
    --key-file "$W/key" --exclude-from "$W/x/exclude" > "$W/stream_mode/ex.enc" 2>"$W/stream_mode/ex.err"; RC=$?
chk "stream mode with --exclude-from exits 0" "$RC" "0"
decrypt_list "$W/stream_mode/ex.enc" > "$W/x/ex.list"
chk "the listed original is left out" "$(grep -c '^site/static_files/uploads/photo_x1.jpg$' "$W/x/ex.list")" "0"
chk "the listed variant is left out" "$(grep -c '^site/static_files/uploads/avatar/photo_x1.jpg$' "$W/x/ex.list")" "0"
chk "the listed restricted path is left out" "$(grep -c '^site/uploads/photo_x1.jpg$' "$W/x/ex.list")" "0"
chk "an unlisted file beside them stays" "$(grep -c '^site/static_files/uploads/kept_y2.jpg$' "$W/x/ex.list")" "1"
chk "a same-named file at an unlisted path stays (paths, not names)" "$(grep -c '^site/sub/photo_x1.jpg$' "$W/x/ex.list")" "1"
chk "the uploads directories themselves are still archived" "$(grep -c '^site/static_files/uploads/avatar/$' "$W/x/ex.list")" "1"
bash "$BACKUP" site --project-dir "$W/site" --archive - --report "$W/x/r4" --key-file "$W/key" \
    --exclude-from "$W/x/does_not_exist" >/dev/null 2>"$W/x/noexcl"; RC=$?
chk "an unreadable --exclude-from file is refused" "$RC" "1"
chk "and named" "$(grep -c 'exclude-from file not readable' "$W/x/noexcl")" "1"

echo
echo "RESULT: $([ $failed -eq 0 ] && echo PASS || echo FAIL) $passed $failed"
[ $failed -eq 0 ]
