#!/bin/bash
# @joinery-test
# name: host_restrictions
# tier: safe
# env: any
# needs: []
# timeout: 60
# covers: [maintenance_scripts/sysadmin_tools/backup_files.sh, maintenance_scripts/sysadmin_tools/backup_project.sh, maintenance_scripts/install_tools/_restore_guard.sh, maintenance_scripts/install_tools/_host_restrictions.sh, maintenance_scripts/install_tools/host_restrictions.sh, maintenance_scripts/install_tools/site_housekeeping.sh, maintenance_scripts/install_tools/fix_permissions.sh, maintenance_scripts/install_tools/_plugin_installers_start.sh, maintenance_scripts/install_tools/install.sh, maintenance_scripts/sysadmin_tools/restore_project.sh, maintenance_scripts/sysadmin_tools/restore_chain.sh]
#
# The shell half of a host that refuses unsigned code (specs/host_restrictions.md).
# A restore on such a host checks what it was handed before anything is written:
# no top-level entry a site's backup has no reason to carry, and code the
# MACHINE's own verifier passes - asked of the live tree, given the staged one -
# and a data-only archive asks nothing; a host that does not refuse unsigned
# code is never asked. The files a restore keeps from the machine name every
# trust file PackageSignature reads. Every converge on such a host writes the
# code check the host report carries, and a host that stops refusing drops it.
# The publishing-box shortcut, the tree owner and the install wiring are pinned
# where they are decided. A fixture live tree carries the real reader and a
# stub verifier; the real verifier's verdicts are package_signature_test's.

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
TOOLS="$ROOT/maintenance_scripts/install_tools"
SYSADMIN="$ROOT/maintenance_scripts/sysadmin_tools"
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

if [ "$(id -u)" = "0" ]; then
    echo "  SKIP: run unprivileged; as root the fixtures are ignored and the host's own files are read"
    echo "RESULT: 0 passed, 0 failed"
    exit 0
fi

# The restrictions file, written by the real writer.
export JOINERY_HOST_RESTRICTIONS_FILE="$T/hr/restrictions"
bash "$TOOLS/host_restrictions.sh" multi-tenant > /dev/null

# A live tree: the real reader, and a stub verifier that logs its arguments and
# answers what STUB_VERIFY says (pass, or the lines to print and fail with).
LIVE="$T/live/public_html"
mkdir -p "$LIVE/utils" "$LIVE/includes"
cp "$ROOT/public_html/includes/HostRestrictions.php" "$LIVE/includes/"
cp "$ROOT/public_html/utils/host_restrictions.php" "$LIVE/utils/"
cat > "$LIVE/utils/verify_package.php" <<'PHP'
<?php
file_put_contents(getenv('STUB_VERIFY_LOG'), implode(' ', array_slice($argv, 1)) . "\n", FILE_APPEND);
$say = (string)getenv('STUB_VERIFY');
if ($say === '' || $say === 'pass') { echo "tree: ok\n"; exit(0); }
echo str_replace('|', "\n", $say) . "\n";
exit(1);
PHP
export STUB_VERIFY_LOG="$T/verify.log"
: > "$STUB_VERIFY_LOG"

. "$TOOLS/_restore_guard.sh"

stage() {  # NAME ENTRY... : a staged site root holding those top-level entries
    local d="$T/stage/$1"; shift
    rm -rf "$d"; mkdir -p "$d"
    for e in "$@"; do
        case "$e" in
            RELEASE_MANIFEST|RELEASE_MANIFEST.sig) echo x > "$d/$e" ;;
            *) mkdir -p "$d/$e"; echo x > "$d/$e/f" ;;
        esac
    done
    echo "$d"
}

echo "=== A restore on a host that refuses unsigned code ==="
S="$(stage clean public_html maintenance_scripts config uploads static_files storage RELEASE_MANIFEST RELEASE_MANIFEST.sig)"
STUB_VERIFY=pass restore_guard_check "$S" "$LIVE" 2> "$T/err"; rc=$?
chk "a site's own entries and code the verifier passes: go ahead" "$rc" "0"
chk "the machine's verifier was asked about the STAGED tree, with --tree" "$(tail -1 "$STUB_VERIFY_LOG")" "--tree $S"
S="$(stage planted public_html maintenance_scripts config vendor deploy RELEASE_MANIFEST)"
STUB_VERIFY=pass restore_guard_check "$S" "$LIVE" 2> "$T/err"; rc=$?
chk "a vendor/ or deploy/ in the archive: refused, each named" \
    "$rc|$(grep -c 'carries vendor at the site root' "$T/err")|$(grep -c 'carries deploy at the site root' "$T/err")|$(grep -c 'nothing was restored' "$T/err")" "1|1|1|1"
S="$(stage edited public_html maintenance_scripts config RELEASE_MANIFEST)"
STUB_VERIFY='style theme: looks (unsigned; nothing in it runs)|fail: plugin evil: unsigned: no manifest|fail: core: tampered: x' \
    restore_guard_check "$S" "$LIVE" 2> "$T/err"; rc=$?
chk "code the verifier fails: refused, every failure named, a style theme not" \
    "$rc|$(grep -c 'fail: plugin evil' "$T/err")|$(grep -c 'fail: core' "$T/err")|$(grep -c 'style theme' "$T/err")" "1|1|1|0"
: > "$STUB_VERIFY_LOG"
S="$(stage dataonly config uploads storage)"
STUB_VERIFY='fail: core: x' restore_guard_check "$S" "$LIVE" 2> "$T/err"; rc=$?
chk "a data-only archive: no code to check, the verifier not asked" "$rc|$(wc -l < "$STUB_VERIFY_LOG" | tr -d ' ')" "0|0"
mkdir -p "$T/noverify/public_html/utils" "$T/noverify/public_html/includes"
cp "$LIVE/utils/host_restrictions.php" "$T/noverify/public_html/utils/"; cp "$LIVE/includes/HostRestrictions.php" "$T/noverify/public_html/includes/"
S="$(stage nover public_html RELEASE_MANIFEST)"
restore_guard_check "$S" "$T/noverify/public_html" 2> "$T/err"; rc=$?
chk "code to check and no verifier on the machine: refused" "$rc|$(grep -c 'no verifier' "$T/err")" "1|1"
S="$(stage nolive public_html RELEASE_MANIFEST)"
restore_guard_check "$S" "$T/nothing/public_html" 2> "$T/err"; rc=$?
chk "no live tree to ask: the restriction counts as on, and the restore is refused" "$rc" "1"

S="$(stage upgrade public_html maintenance_scripts config deploy upgrades upgrade_archives public_html_last RELEASE_MANIFEST)"
restore_guard_drop_upgrade_trees "$S" 2> "$T/err"; rc=$?
chk "an upgrade's own trees are dropped from a staged archive, each said, and nothing else" \
    "$rc|$(ls "$S" | LC_ALL=C sort | tr '\n' ' ')|$(grep -c 'belongs to the machine' "$T/err")" "0|RELEASE_MANIFEST config maintenance_scripts public_html |4"
STUB_VERIFY=pass restore_guard_check "$S" "$LIVE" 2> "$T/err"; rc=$?
chk "and what is left goes ahead" "$rc" "0"
chk "restore_project drops them before it asks, on every host" \
    "$([ "$(grep -n 'restore_guard_drop_upgrade_trees "$BACKUP_DIR/project_files"' "$SYSADMIN/restore_project.sh" | cut -d: -f1)" -lt "$(grep -n 'if restore_guard_restricted "$LIVE_PUBLIC_HTML"' "$SYSADMIN/restore_project.sh" | cut -d: -f1)" ] && echo yes)" "yes"
chk "backup_project leaves the same trees, and the site root's vendor/, out in both modes, anchored" \
    "$(grep -c "^UPGRADE_TREES=(deploy upgrades upgrade_archives 'public_html_\*')" "$SYSADMIN/backup_project.sh")|$(grep -c '^ROOT_ONLY_EXCLUDES=("${UPGRADE_TREES\[@\]}" vendor)' "$SYSADMIN/backup_project.sh")|$(grep -c 'for x in "${ROOT_ONLY_EXCLUDES\[@\]}"; do RSYNC_EXCLUDES+=(--exclude="/${x}/"); done' "$SYSADMIN/backup_project.sh")|$(grep -c 'for x in "${ROOT_ONLY_EXCLUDES\[@\]}"; do TAR_EXCLUDES+=(--exclude="./${x}"); done' "$SYSADMIN/backup_project.sh")|$(grep -c '^TREE_EXCLUDES=.*vendor' "$SYSADMIN/backup_project.sh")" "1|1|1|1|0"

echo "=== A backup carries the signed vendor/ trees, and leaves out only the site root's ==="
FX="$T/fx/fxsite"
mkdir -p "$FX/vendor/composer" "$FX/public_html/assets/vendor/argon2" "$FX/public_html/plugins/p/vendor/lib" "$FX/config"
echo a > "$FX/vendor/composer/x.php"; echo b > "$FX/public_html/assets/vendor/argon2/a.js"
echo c > "$FX/public_html/plugins/p/vendor/lib/l.php"; echo d > "$FX/config/c"
head -c 32 /dev/urandom | base64 > "$T/fx.key"
OUT="$(bash "$SYSADMIN/backup_files.sh" fxsite --project-dir "$FX" --output-dir "$T/fx" --name files-0000 --snar "$T/fx.snar" --key-file "$T/fx.key" 2>/dev/null)"
ARC="$(echo "$OUT" | grep '^ARCHIVE=' | cut -d= -f2)"
LIST="$(openssl enc -aes-256-cbc -d -pbkdf2 -pass file:"$T/fx.key" -in "$ARC" 2>/dev/null | tar -tzf - 2>/dev/null)"
chk "backup_files: public_html/assets/vendor and a plugin's vendor/ are carried, the site root's vendor/ is not" \
    "$(grep -c '^fxsite/public_html/assets/vendor/argon2/a.js$' <<< "$LIST")|$(grep -c '^fxsite/public_html/plugins/p/vendor/lib/l.php$' <<< "$LIST")|$(grep -c '^fxsite/vendor/' <<< "$LIST")" "1|1|0"

echo "=== A host that does not refuse unsigned code is never asked ==="
mv "$T/hr" "$T/hr.off"
: > "$STUB_VERIFY_LOG"
S="$(stage free public_html vendor RELEASE_MANIFEST)"
STUB_VERIFY='fail: core: x' restore_guard_check "$S" "$LIVE" 2> "$T/err"; rc=$?
chk "no restrictions: anything goes ahead, the verifier not asked" "$rc|$(wc -l < "$STUB_VERIFY_LOG" | tr -d ' ')" "0|0"
mv "$T/hr.off" "$T/hr"

echo "=== The files a restore keeps from the machine ==="
for f in $(php -r 'require $argv[1]; foreach (array("KEYS_FILE","STATEMENT_KEYS_FILE","LOG_KEYS_FILE","LOG_REQUIRED_FILE","LOG_OPTIONAL_FILE") as $c) echo constant("PackageSignature::$c"), "\n";' "$ROOT/public_html/includes/PackageSignature.php") \
         config/tree_owner config/agent_signing_key config/release_statement_key config/code_check config/disk_allowance; do
    chk "a trust file the machine supplies: $f" "$(restore_guard_trust_file "$f" && echo trust)" "trust"
done
chk "the machine's config and backup identity are kept, but not as trust files" \
    "$(restore_guard_trust_file config/Globalvars_site.php && echo trust)|$(restore_guard_trust_file config/backup_site_key && echo trust)|$(printf '%s\n' "${MACHINE_CONFIG_FILES[@]}" | grep -c 'Globalvars_site.php\|backup_site_key')" "||2"
chk "a file of the site's own is not the machine's" "$(restore_guard_trust_file config/mail_settings && echo trust)" ""

echo "=== Both restore scripts check before they write, and keep the machine's files ==="
RP="$SYSADMIN/restore_project.sh"
chk "restore_project: the check runs before the dry run's exit and before perform_restore" \
    "$([ "$(grep -n 'restore_guard_check "$BACKUP_DIR/project_files"' "$RP" | cut -d: -f1)" -lt "$(grep -n '^if \[ "$DRY_RUN" = true \]; then' "$RP" | cut -d: -f1)" ] \
       && [ "$(grep -n 'restore_guard_check "$BACKUP_DIR/project_files"' "$RP" | cut -d: -f1)" -lt "$(grep -n '^if perform_restore' "$RP" | cut -d: -f1)" ] && echo yes)" "yes"
chk "restore_project: asks the tree it runs from, never the target" "$(grep -c 'LIVE_PUBLIC_HTML="$(cd "$(dirname "${BASH_SOURCE\[0\]}")/../../public_html"' "$RP")" "1"
chk "restore_project: loops over the shared list, and drops the archive's trust file on a restricted host" \
    "$(grep -c 'machine_files=("${MACHINE_CONFIG_FILES\[@\]}")' "$RP")|$(grep -c 'on this host the machine supplies it' "$RP")" "1|1"
RC="$SYSADMIN/restore_chain.sh"
chk "restore_chain: the check in a scratch directory comes before anything is held, applied or loaded" \
    "$([ "$(grep -n 'restore_guard_check "${CHECK_PARENT}/${ARCHIVE_ROOT}"' "$RC" | cut -d: -f1)" -lt "$(grep -n '^# ── Keep what belongs to this machine' "$RC" | cut -d: -f1)" ] \
       && [ "$(grep -n 'restore_guard_check "${CHECK_PARENT}/${ARCHIVE_ROOT}"' "$RC" | cut -d: -f1)" -lt "$(grep -n '^# ── Apply' "$RC" | cut -d: -f1)" ] && echo yes)" "yes"
chk "restore_chain: the scratch pass leaves the data folders out, and is removed either way" \
    "$(grep -c -- '--exclude="${ARCHIVE_ROOT}/uploads" --exclude="${ARCHIVE_ROOT}/static_files" --exclude="${ARCHIVE_ROOT}/storage"' "$RC")|$(grep -c 'rm -rf "${CHECK_PARENT:?}"' "$RC")" "1|3"
chk "restore_chain: holds every machine file, and removes the chain's trust files on a restricted host" \
    "$(grep -c 'MACHINE_FILES=("${MACHINE_CONFIG_FILES\[@\]}")' "$RC")|$(grep -c "Removed the chain's \${rel}: on this host the machine supplies it" "$RC")" "1|1"
chk "both refuse when the guard is missing on a host that has restrictions" \
    "$(grep -c 'which checks a restore' "$RP")|$(grep -c 'which checks a restore' "$RC")" "1|1"

echo "=== Every converge on a restricted host writes the code check ==="
SITE="$T/site/hksite"
mkdir -p "$SITE/config" "$T/hkroot/etc"
cp -a "$T/live/public_html" "$SITE/public_html"
hk() { JOINERY_SITE_HOUSEKEEPING_ROOT="$T/hkroot" JOINERY_VERIFY_PACKAGE="$SITE/public_html/utils/verify_package.php" \
       bash "$TOOLS/site_housekeeping.sh" --no-cron hksite "$SITE" > "$T/hk.out" 2>&1; }
STUB_VERIFY=pass hk
chk "code as shipped: ok and when" "$(head -c 3 "$SITE/config/code_check")|$(wc -l < "$SITE/config/code_check" | tr -d ' ')|$(stat -c %a "$SITE/config/code_check")" "ok |1|644"
STUB_VERIFY=$'style\ttheme\tlooks|fail\tplugin\tevil\tunsigned\t|fail\tcore\t\ttampered\tpublic_html/x.php' hk
chk "code not as shipped: failed, then one line per package, and no style theme" \
    "$(head -c 6 "$SITE/config/code_check")|$(grep -c $'^fail\t' "$SITE/config/code_check")|$(grep -c 'looks' "$SITE/config/code_check")|$(grep -c $'^fail\tcore\t\ttampered\tpublic_html/x.php$' "$SITE/config/code_check")" "failed|2|0|1"
chk "and the run says so" "$(grep -c 'is not what was shipped (2 package(s))' "$T/hk.out")" "1"
STUB_VERIFY='garbage' hk
chk "a verifier that answers nothing readable: failed, never ok" "$(head -c 6 "$SITE/config/code_check")|$(grep -c $'^fail\tcheck\t\tunreadable' "$SITE/config/code_check")" "failed|1"
mv "$T/hr" "$T/hr.off"
hk
chk "a host that stops refusing unsigned code keeps no code check" "$(test -e "$SITE/config/code_check" && echo kept)" ""
mv "$T/hr.off" "$T/hr"

echo "=== Where the rest is decided ==="
PIS="$TOOLS/_plugin_installers_start.sh"
chk "the converger's publishing-box shortcut never applies on a restricted host" \
    "$(grep -c 'if \[\[ -f "${SITE_ROOT}/config/agent_signing_key" \]\] && ! plugin_host_refuses_unsigned; then' "$PIS")" "1"
FP="$TOOLS/fix_permissions.sh"
chk "fix_permissions: on a restricted host the tree owner is root, decided before the group is read" \
    "$([ "$(grep -n 'host_restriction_on "$SITE_ROOT/public_html" unsigned_packages' "$FP" | cut -d: -f1)" -lt "$(grep -n '^TREE_GROUP=' "$FP" | cut -d: -f1)" ] && echo yes)|$(grep -A3 'host_restriction_on "$SITE_ROOT/public_html" unsigned_packages' "$FP" | grep -c 'TREE_OWNER="root"')" "yes|1"
IN="$TOOLS/install.sh"
FN="$(sed -n '/^multi_tenant_host_install() {/,/^}/p' "$IN")"
chk "install.sh docker --multi-tenant writes the restrictions, and a failure stops the install" \
    "$(grep -c 'bash "$SCRIPT_DIR/host_restrictions.sh" multi-tenant' <<< "$FN")|$(grep -A3 'host_restrictions.sh" multi-tenant' <<< "$FN" | grep -c 'return 1')" "1|1"

DSD="$(sed -n '/^do_site_docker() {/,/^}/p' "$IN")"
chk "install.sh site asks for the restrictions in its limits check, before any image, network or spec is made" \
    "$(printf '%s\n' "$DSD" | awk '/run_spec_restrictions_present \|\| \{ print_error "Nothing was changed."; exit 1; \}/ { r = NR } /# \(end of the limits check\)/ { e = NR } /site_engine build/ && !b { b = NR } /run_spec_network_lines "\$SITENAME"/ && !n { n = NR } END { print (r && r < e && r < b && r < n) ? "yes" : "no" }')" "yes"

echo "RESULT: ${passed} passed, ${failed} failed"
[ "$failed" -eq 0 ]
