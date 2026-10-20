#!/bin/bash
# @joinery-test
# name: restore_own_tools
# tier: db
# env: dev-only
# needs: []
# timeout: 300
# covers: [maintenance_scripts/sysadmin_tools/restore_chain.sh]
#
# A chain restore finishes with its own release's tools (B23).
#
# restore_chain.sh runs from the site it restores, and its extraction puts
# back the tools the backup carried. A run from before a change brings back the
# tool from before it: the database restore of an older run refused
# --restored-run, after the files were already restored. So:
#
#   * the restore of a chain whose files carry an older restore_database.sh
#     restores the database with this release's, and succeeds;
#   * the restored tree holds the tool the backup carried, exactly;
#   * the reconcile is taken from the same copy.
#
# Real tar, openssl and postgres, on a throwaway project and databases.
# Run unprivileged: as root the restore would take the real site's lock.

set -uo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
TOOLS="$ROOT/maintenance_scripts/sysadmin_tools"
passed=0; failed=0
pass() { echo "  PASS: $1"; passed=$((passed+1)); }
fail() { echo "  FAIL: $1"; [ -n "${2:-}" ] && echo "        $2"; failed=$((failed+1)); }
chk() { if [ "$2" = "$3" ]; then pass "$1"; else fail "$1" "got '$2', want '$3'"; fi; }
finish() { echo; echo "RESULT: $([ $failed -eq 0 ] && echo PASS || echo FAIL) $passed $failed"; [ $failed -eq 0 ]; exit $?; }

if [ "$(id -u)" = "0" ]; then
    echo "  SKIP: run unprivileged - as root the restore would take the real site's lock"; finish
fi
if [ -z "${PGPASSWORD:-}" ] && [ -f "$ROOT/config/Globalvars_site.php" ]; then
    PGPASSWORD=$(grep "dbpassword.*=" "$ROOT/config/Globalvars_site.php" | head -1 | sed "s/.*'\(.*\)'.*/\1/")
    export PGPASSWORD
fi
if ! psql -U postgres -XtAc "SELECT 1" >/dev/null 2>&1; then
    echo "  SKIP: no postgres connection available"; finish
fi

PROJ="jt_owntools_$$"          # the site's name, and the restored database
SRC_DB="jt_owntools_src_$$"    # the database the chain was taken of
W="$(mktemp -d /tmp/jy_owntools_gate_XXXXXX)"
cleanup() {
    dropdb -U postgres --if-exists "$SRC_DB" >/dev/null 2>&1
    dropdb -U postgres --if-exists "$PROJ" >/dev/null 2>&1
    rm -rf "${W:?}"
}
trap cleanup EXIT
S="$W/src/$PROJ"; T="$W/dst/$PROJ"

# A release's tools inside a site tree, as an install lays them out.
tools_into() {
    mkdir -p "$1/maintenance_scripts/install_tools"
    cp -a "$TOOLS" "$1/maintenance_scripts/sysadmin_tools"
    cp -a "$ROOT/maintenance_scripts/install_tools/"*.sh "$ROOT/maintenance_scripts/install_tools/"*.conf \
        "$1/maintenance_scripts/install_tools/"
}

echo "== The source: a site whose tools are from before a change =="
mkdir -p "$S/public_html" "$S/config"
echo '<?php echo 1;' > "$S/public_html/index.php"
tools_into "$S"
# The older database tool: it knows none of this release's options.
OLD_ENGINE='#!/bin/bash
echo "OLD restore_database.sh ran with: $*" >&2
exit 1'
printf '%s\n' "$OLD_ENGINE" > "$S/maintenance_scripts/sysadmin_tools/restore_database.sh"
createdb -U postgres "$SRC_DB" && psql -U postgres -q -d "$SRC_DB" -c "CREATE TABLE owntools_marker (id int); INSERT INTO owntools_marker VALUES (23);" >/dev/null 2>&1

echo "== A chain of it =="
mkdir -p "$W/arts"
php "$TOOLS/backup_envelope.php" mint --artifact chain --key-out "$W/chain.key" --sidecar-out "$W/envelope.json" >/dev/null 2>&1
out=$(bash "$TOOLS/backup_files.sh" site --project-dir "$S" --output-dir "$W/arts" --name files-0000 \
        --snar "$W/chain.snar" --key-file "$W/chain.key" 2>/dev/null)
F=$(echo "$out" | sed -n 's/^ARCHIVE=//p'); FB=$(echo "$out" | sed -n 's/^BYTES=//p'); FH=$(echo "$out" | sed -n 's/^SHA256=//p')
( cd "$W/arts" && bash "$TOOLS/backup_database.sh" --non-interactive --key-file "$W/chain.key" "$SRC_DB" >/dev/null 2>&1 )
D=$(ls "$W/arts/$SRC_DB"-*.sql.gz.enc 2>/dev/null | head -1)
if [ -n "$F" ] && [ -f "$F" ] && [ -n "$D" ]; then pass "the files archive and the database dump are made"; else fail "the chain could not be made" "$F / $D"; finish; fi
cat > "$W/arts/manifest.json" <<JSON
{"version": 1, "chain_id": "chain-20261020_040000", "slug": "$PROJ",
 "runs": [{"seq": 0, "level": 0, "time": "2026-10-20T04:00:00Z",
           "artifacts": {"files": {"name": "$(basename "$F")", "bytes": $FB, "sha256": "$FH"},
                         "db": {"name": "$(basename "$D")", "bytes": $(stat -c %s "$D"), "sha256": "$(sha256sum "$D" | cut -d' ' -f1)"}}}]}
JSON

echo "== Restored by the release on the target, run from the target's own tree =="
mkdir -p "$T/config"
tools_into "$T"
if bash "$T/maintenance_scripts/sysadmin_tools/restore_chain.sh" "$PROJ" --target-dir "$T" --artifacts "$W/arts" \
        --key-file "$W/chain.key" --force --skip-ssl > "$W/log" 2>&1; then
    pass "the restore succeeds"
else
    fail "the restore failed" "$(grep -E 'ERROR|✗|OLD restore' "$W/log" | head -5)"
fi
if grep -q 'OLD restore_database.sh ran' "$W/log"; then
    fail "the database was restored with the tool the backup carried" "$(grep 'OLD restore' "$W/log" | head -1)"
else
    pass "the database was restored with this release's tool, not the one the backup carried"
fi
chk "the database is the chain's" "$(psql -U postgres -d "$PROJ" -XtAc 'SELECT id FROM owntools_marker' 2>/dev/null)" "23"
chk "the restored tree holds the tool the backup carried, exactly" \
    "$(cmp -s "$T/maintenance_scripts/sysadmin_tools/restore_database.sh" "$S/maintenance_scripts/sysadmin_tools/restore_database.sh" && echo same)" "same"
chk "the reconcile is taken from the same copy of this release's tools" \
    "$(grep -c 'RECONCILE="${TOOLS_TMP}/sysadmin_tools/reconcile_site.sh"' "$TOOLS/restore_chain.sh")" "1"

finish
