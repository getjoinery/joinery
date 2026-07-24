#!/bin/bash
# @joinery-test
# name: restore_project_capture
# tier: safe
# env: any
# needs: []
# timeout: 60
#
# B-1 regression trap. restore_project.sh captures the backup directory with
#   BACKUP_DIR=$(verify_archive ...)
# so verify_archive must emit EXACTLY ONE line on stdout — the path — and route
# every informational line to stderr. When it printed status lines to stdout,
# the capture became a multi-line blob, every downstream directory test failed,
# and the script still exited 0 having restored nothing ("RESTORE COMPLETE").
#
# The verify_archive function and the print_* helpers are EXTRACTED from the
# shipped script (not copied), so a revert of either the stderr redirection or
# the single-line contract fails this gate. restore_project.sh itself is never
# executed (it would sudo-move directories and touch Apache).

set -uo pipefail

SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)/maintenance_scripts/sysadmin_tools/restore_project.sh"
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT
passed=0; failed=0
chk() {
    if [ "$2" = "$3" ]; then
        echo "  PASS: $1"; passed=$((passed+1))
    else
        echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed+1))
    fi
}

# --- Extract the shipped print_* block and verify_archive ---------------------
p_start=$(grep -n '^print_info() {' "$SRC" | head -1 | cut -d: -f1)
pdr=$(grep -n '^print_dry_run() {' "$SRC" | head -1 | cut -d: -f1)
p_end=$(awk 'NR>='"$pdr"' && /^\}$/{print NR; exit}' "$SRC")
v_start=$(grep -n '^verify_archive() {' "$SRC" | head -1 | cut -d: -f1)
v_end=$(awk 'NR>='"$v_start"' && /^\}$/{print NR; exit}' "$SRC")

chk "print helpers located" "$([ -n "$p_start" ] && [ -n "$p_end" ] && echo yes)" "yes"
chk "verify_archive located" "$([ -n "$v_start" ] && [ -n "$v_end" ] && echo yes)" "yes"

HARNESS="$WORK/harness.sh"
{
    echo '#!/usr/bin/env bash'
    echo 'set -uo pipefail'
    echo 'RED= GREEN= YELLOW= BLUE= CYAN= NC='
    echo 'DRY_RUN=false SKIP_DATABASE=true SKIP_FILES=false SKIP_APACHE=true'
    sed -n "${p_start},${p_end}p" "$SRC"
    sed -n "${v_start},${v_end}p" "$SRC"
    echo 'verify_archive "$1" "$2"'
} > "$HARNESS"

bash -n "$HARNESS" 2>/dev/null
chk "extracted harness parses" "$?" "0"

# --- Build a minimal valid project archive ------------------------------------
BK="PROJ-20260101_000000"
mkdir -p "$WORK/src/$BK/project_files"
echo '<?php' > "$WORK/src/$BK/project_files/serve.php"
echo 'info'  > "$WORK/src/$BK/backup_info.txt"
tar czf "$WORK/archive.tar.gz" -C "$WORK/src" "$BK"

# --- Run verify_archive, capturing STDOUT ONLY --------------------------------
EXTRACT="$WORK/extract"; mkdir -p "$EXTRACT"
STDOUT=$(bash "$HARNESS" "$WORK/archive.tar.gz" "$EXTRACT" 2>/dev/null)

LINES=$(printf '%s\n' "$STDOUT" | grep -c .)
chk "verify_archive emits exactly one stdout line" "$LINES" "1"
chk "that one line is the extracted backup directory" \
    "$([ -d "$STDOUT" ] && basename "$STDOUT")" "$BK"

echo
if [ "$failed" -eq 0 ]; then
    echo "RESULT: PASS $passed $failed"
    exit 0
fi
echo "RESULT: FAIL $passed $failed"
exit 1
