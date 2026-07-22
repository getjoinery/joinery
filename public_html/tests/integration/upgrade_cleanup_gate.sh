#!/bin/bash
# @joinery-test
# name: upgrade_cleanup
# tier: safe
# env: any
# needs: []
# timeout: 60
#
# A failed deploy is preserved as public_html_failed_<timestamp> for diagnosis,
# but a completed newer deploy ends that usefulness — without cleanup every
# failure parks a full site copy on disk forever (a 213M one sat unnoticed on
# jeremytunnell for days). The success path of utils/upgrade.php must remove
# all preserved failed trees, and must remove ONLY those: the live tree and
# every other site-root directory stay untouched.
#
# The cleanup block is EXTRACTED FROM utils/upgrade.php rather than copied
# here, so this exercises the code that actually ships. upgrade.php itself is
# NEVER executed by this gate (running it starts a real upgrade).

set -u
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)/utils/upgrade.php"
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

echo "== the shipped cleanup block extracts and parses =="
awk '/Remove preserved failed-deployment trees/{f=1} f{print} f&&/^\t\t}$/{exit}' "$SRC" > "$T/block.txt"
chk "block found in upgrade.php" "$(grep -c "glob(.full_site_dir . '/public_html_failed_" "$T/block.txt")" "1"
{
    echo '<?php'
    echo 'function upgrade_echo($m) { echo strip_tags($m), "\n"; }'
    echo '$full_site_dir = $argv[1]; $verbose = true;'
    cat "$T/block.txt"
} > "$T/cleanup.php"
php -l "$T/cleanup.php" >/dev/null 2>&1
chk "extracted block parses" "$?" "0"

echo "== every preserved failed tree is removed =="
SITE="$T/site"
mkdir -p "$SITE/public_html" "$SITE/public_html_last" \
         "$SITE/public_html_failed_20260719_130421/deep/nested" \
         "$SITE/public_html_failed_20260721_010101" \
         "$SITE/uploads" "$SITE/config"
echo live > "$SITE/public_html/index.php"
echo old > "$SITE/public_html_failed_20260719_130421/deep/nested/file.php"
# A stray FILE matching the pattern must survive: the block only takes dirs.
echo notes > "$SITE/public_html_failed_notes.txt"

out=$(php "$T/cleanup.php" "$SITE")
chk "first failed tree removed"    "$([ -d "$SITE/public_html_failed_20260719_130421" ] && echo present || echo gone)" "gone"
chk "second failed tree removed"   "$([ -d "$SITE/public_html_failed_20260721_010101" ] && echo present || echo gone)" "gone"
chk "both removals reported"       "$(echo "$out" | grep -c 'Removed preserved failed deployment')" "2"

echo "== nothing else is touched =="
chk "live tree untouched"          "$(cat "$SITE/public_html/index.php")" "live"
chk "rollback backup untouched"    "$([ -d "$SITE/public_html_last" ] && echo present)" "present"
chk "uploads untouched"            "$([ -d "$SITE/uploads" ] && echo present)" "present"
chk "config untouched"             "$([ -d "$SITE/config" ] && echo present)" "present"
chk "matching plain file survives" "$([ -f "$SITE/public_html_failed_notes.txt" ] && echo present)" "present"

echo "== a clean site root is a quiet no-op =="
out=$(php "$T/cleanup.php" "$SITE")
chk "no removals reported"         "$(echo "$out" | grep -c 'Removed preserved failed deployment')" "0"
chk "no warnings emitted"          "$(echo "$out" | grep -c 'Could not remove')" "0"

echo "== the cleanup sits in the success path =="
cleanup_line=$(grep -n 'Remove preserved failed-deployment trees' "$SRC" | head -1 | cut -d: -f1)
backup_rm_line=$(grep -n 'Remove the rollback backup' "$SRC" | head -1 | cut -d: -f1)
complete_line=$(grep -n 'Upgrade Complete!' "$SRC" | head -1 | cut -d: -f1)
rollback_line=$(grep -n 'performRollback' "$SRC" | head -1 | cut -d: -f1)
chk "after the rollback-backup removal (same terminal block)" \
    "$([ -n "$cleanup_line" ] && [ -n "$backup_rm_line" ] && [ "$cleanup_line" -gt "$backup_rm_line" ] && echo yes)" "yes"
chk "before the completion banner" \
    "$([ -n "$cleanup_line" ] && [ -n "$complete_line" ] && [ "$cleanup_line" -lt "$complete_line" ] && echo yes)" "yes"
chk "after the failure/rollback exit (never runs on a failed deploy)" \
    "$([ -n "$cleanup_line" ] && [ -n "$rollback_line" ] && [ "$cleanup_line" -gt "$rollback_line" ] && echo yes)" "yes"

echo
if [ "$failed" -eq 0 ]; then
    echo "RESULT: PASS $passed $failed"
    exit 0
fi
echo "RESULT: FAIL $passed $failed"
exit 1
