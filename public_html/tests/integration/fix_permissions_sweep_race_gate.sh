#!/bin/bash
# @joinery-test
# name: fix_permissions_sweep_race
# tier: safe
# env: any
# needs: []
# timeout: 60
# covers: [maintenance_scripts/install_tools/fix_permissions.sh]
#
# fix_permissions.sh walks the site tree under set -e, and a test fixture's
# directory can be removed while it walks. findutils 4.9's -ignore_readdir_race
# does not cover a directory that vanishes between being listed and being
# opened, so the sweep ended with a false "the tree may still be writable"
# alarm. Its tree sweeps go through sweep_find, which tolerates "No such file
# or directory" for a path strictly under a sweep root and nothing else.
#
# This gate lifts sweep_find out of the script and runs it on a scratch tree;
# it never runs the sweep itself. FIX_PERMISSIONS_SCRIPT names another copy.

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
SCRIPT="${FIX_PERMISSIONS_SCRIPT:-$ROOT/maintenance_scripts/install_tools/fix_permissions.sh}"
T=$(mktemp -d)
trap 'chmod -R u+rwx "$T" 2>/dev/null; rm -rf "$T"' EXIT
passed=0; failed=0

chk() {
    if [ "$2" = "$3" ]; then
        echo "  PASS: $1"; passed=$((passed+1))
    else
        echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed+1))
    fi
}

sed -n '/^sweep_find() {$/,/^}$/p' "$SCRIPT" > "$T/sweep_find.sh"
chk "the script defines sweep_find" "$(grep -c '^sweep_find() {$' "$T/sweep_find.sh")" "1"
# shellcheck source=/dev/null
. "$T/sweep_find.sh"

tree() {
    rm -rf "$T/site"
    for i in $(seq 1 20); do mkdir -p "$T/site/zz/d$i/inner"; touch "$T/site/zz/d$i/f"; done
}
# On the first file inside zz/dN, remove every other zz/d* directory: they were
# listed with zz and are gone before find opens them.
VANISH='case "$1" in */zz/d*/f) for x in '"$T"'/site/zz/d*; do [ "$x" = "${1%/f}" ] || rm -rf "$x"; done ;; esac'

echo "=== What the sweep tolerates ==="
tree
sweep_find "$T/site" -ignore_readdir_race \( -type f -o -type d \) -exec sh -c "$VANISH" _ {} \; 2>"$T/err"
chk "a directory removed mid-walk does not fail the sweep" "$?" "0"
chk "and says nothing about it" "$(wc -c < "$T/err")" "0"

tree; touch "$T/site/gone"
sweep_find "$T/site" -ignore_readdir_race -type f -name gone -exec sh -c 'rm -f "$1"; chmod 600 "$1"' _ {} + 2>/dev/null
chk "a file removed before chmod reaches it does not fail the sweep" "$?" "0"

tree
sweep_find "$T/site" -ignore_readdir_race -type f -exec true {} +
chk "a clean walk passes" "$?" "0"

echo "=== What still fails it ==="
sweep_find "$T/no-such-root" -ignore_readdir_race -type f 2>/dev/null
chk "a missing root fails: the sweep is pointed somewhere wrong" "$([ $? -ne 0 ] && echo failed)" "failed"

tree
sweep_find "$T/site" -ignore_readdir_race -type f -exec false {} + 2>/dev/null
chk "a command failing with no message fails" "$([ $? -ne 0 ] && echo failed)" "failed"

if [ "$(id -u)" -ne 0 ]; then
    tree; mkdir -p "$T/site/locked/sub"; chmod 000 "$T/site/locked"
    sweep_find "$T/site" -ignore_readdir_race -type d 2>"$T/err"
    chk "an unreadable directory fails" "$([ $? -ne 0 ] && echo failed)" "failed"
    chk "and its message is shown" "$(grep -c 'Permission denied' "$T/err")" "1"
    chmod 755 "$T/site/locked"

    tree
    sweep_find "$T/site" -ignore_readdir_race -type f -name f -exec chown root {} + 2>"$T/err"
    chk "a chown it is not allowed to make fails" "$([ $? -ne 0 ] && echo failed)" "failed"
    chk "and says so" "$( [ -s "$T/err" ] && echo said )" "said"
else
    echo "  SKIP: the permission checks need a non-root account"
fi

echo "=== Every tree sweep goes through it ==="
chk "no tree sweep calls find directly" \
    "$(grep -cE '^\s+find "(\$\{EXEC_ROOTS\[@\]\}|\$\{DATA_ROOTS\[@\]\}|\$CONFIG_DIR" -mindepth)' "$SCRIPT")" "0"
chk "eight tree sweeps, each with -ignore_readdir_race after its roots" \
    "$(grep -cE '^\s+sweep_find "(\$\{EXEC_ROOTS\[@\]\}|\$\{DATA_ROOTS\[@\]\}|\$CONFIG_DIR)" -ignore_readdir_race ' "$SCRIPT")" "8"

echo ""
echo "fix_permissions_sweep_race: $passed passed, $failed failed"
[ "$failed" -eq 0 ]
