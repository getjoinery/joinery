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
# every other site-root directory stay untouched. The disk-space check before
# download asks for room measured from the live code tree, not a flat figure.
# Staging and the downloaded archives live in the deploy root, never in uploads/
# (specs/one_data_root.md D6), and a deploy empties the archives.
#
# The cleanup and disk-space blocks are EXTRACTED FROM utils/upgrade.php rather than copied
# here, so this exercises the code that actually ships. upgrade.php itself is
# NEVER executed by this gate (running it starts a real upgrade).

set -u
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)/utils/upgrade.php"
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

echo "== the disk-space check asks for room measured from the live code =="
# The deploy root takes the archives, a staged copy and, on rollback, a failed
# copy: 3x the live tree, all asked of the deploy root.
awk '/Check disk space before download/{f=1} f{print} f&&/^\t\t}$/{exit}' "$SRC" > "$T/space.txt"
chk "space block found in upgrade.php" "$(grep -q 'live_bytes' "$T/space.txt" && echo yes)" "yes"
{
    echo '<?php'
    echo '$full_site_dir = $argv[1]; $deploy_root = $argv[2];'
    echo '$live_directory = $full_site_dir."/public_html";'
    # Only the measuring half: the refusal compares against real free space.
    sed '/\$free_space = disk_free_space/,$d' "$T/space.txt"
    echo 'echo $live_bytes, " ", $space_bytes, " ", $min_required, "\n";'
} > "$T/space.php"
php -l "$T/space.php" >/dev/null 2>&1
chk "extracted space block parses" "$?" "0"
SP="$T/spacesite"
mkdir -p "$SP/public_html" "$SP/uploads"
head -c 2097152 /dev/zero > "$SP/public_html/big.bin"
read -r live needs floor <<< "$(php "$T/space.php" "$SP" "$SP")"
chk "live tree measured" "$([ "$live" -ge 2097152 ] && echo yes)" "yes"
chk "the deploy root is asked for 3x the live tree" "$needs" "$((3 * live))"
chk "never less than 500MB" "$floor" "$((500 * 1024 * 1024))"
chk "free space is read at the deploy root" "$(grep -c 'disk_free_space(\$deploy_root)' "$T/space.txt")" "1"

echo "== staging and the archives live in the deploy root, never in uploads/ =="
chk "staging is the deploy root's upgrades/" "$(grep -c "^\s*\$stage_location = \$deploy_root.'/upgrades/';" "$SRC")" "1"
chk "archives are the deploy root's upgrade_archives/" "$(grep -c "^\s*\$archive_location = \$deploy_root.'/upgrade_archives/';" "$SRC")" "1"
chk "the core archive downloads there" "$(grep -c '\$file_download_location = \$archive_location . basename' "$SRC")" "1"
chk "no code path stages or downloads into uploads/" \
    "$(grep -v '^\s*//' "$SRC" | grep -c "uploads/upgrades/'\|'/uploads/' \. basename")" "0"

echo "== a successful deploy empties the archives and what older releases left in uploads/ =="
awk '/Empty the archive directory/{f=1} f{print} f&&/old_stage_location\) \. . 2>&1.\);/{getline; print; exit}' "$SRC" > "$T/purge.txt"
chk "purge block found" "$(grep -c 'old_stage_location' "$T/purge.txt")" "3"
{
    echo '<?php'
    echo 'function upgrade_echo($m) { echo strip_tags($m), "\n"; }'
    echo '$full_site_dir = $argv[1]; $archive_location = $argv[1]."/upgrade_archives/"; $verbose = true;'
    cat "$T/purge.txt"
} > "$T/purge.php"
php -l "$T/purge.php" >/dev/null 2>&1
chk "extracted purge block parses" "$?" "0"
PS="$T/purgesite"
mkdir -p "$PS/upgrade_archives" "$PS/uploads/upgrades/public_html" "$PS/uploads/photos"
echo a > "$PS/upgrade_archives/joinery-core-1.2.tar.gz"
echo b > "$PS/upgrade_archives/joinery-plugin-x-1.tar.gz"
echo c > "$PS/uploads/joinery-core-1.1.tar.gz"
echo d > "$PS/uploads/photos/keep.jpg"
echo e > "$PS/uploads/keep.pdf"
out=$(php "$T/purge.php" "$PS")
chk "archives emptied" "$(find "$PS/upgrade_archives" -type f | wc -l)" "0"
chk "an old archive in uploads/ removed" "$([ -e "$PS/uploads/joinery-core-1.1.tar.gz" ] && echo present || echo gone)" "gone"
chk "old staging in uploads/ removed" "$([ -e "$PS/uploads/upgrades" ] && echo present || echo gone)" "gone"
chk "uploaded files untouched" "$(cat "$PS/uploads/photos/keep.jpg" "$PS/uploads/keep.pdf" | tr -d '\n')" "de"
chk "the purge is reported" "$(echo "$out" | grep -c 'Purged 3 downloaded upgrade archive')" "1"

echo
if [ "$failed" -eq 0 ]; then
    echo "RESULT: PASS $passed $failed"
    exit 0
fi
echo "RESULT: FAIL $passed $failed"
exit 1
