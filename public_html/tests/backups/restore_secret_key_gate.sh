#!/bin/bash
# @joinery-test
# name: restore_secret_key
# tier: db
# env: dev-only
# needs: []
# timeout: 60
#
# restore_database.sh's key canary (check_secret_key): after a load into the
# site's own database it says, loudly and with the remedy, when the database's
# sealed values do not open with this site's secret_box_key
# (RESTORE_SECRET_KEY_MISMATCH): every secret setting would read as unset.
#
# Run against this site without restoring anything. The script's own function,
# lifted out of it, asks this site's database the real question read-only (its
# key opens the canary: nothing said); a restore into another database is
# passed over (a backup verification's scratch copy is not what the site
# reads); and a canary that does not open, which is what a dump from a site
# with another key carries, gives the warning and the remedy.
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SITE_ROOT="$(cd "${HERE}/../../.." && pwd)"
SCRIPT="${SITE_ROOT}/maintenance_scripts/sysadmin_tools/restore_database.sh"

pass=0
fail=0
ok()   { echo "  PASS: $1"; pass=$((pass+1)); }
bad()  { echo "  FAIL: $1"; fail=$((fail+1)); }
info() { echo "$@" >&2; }

echo "== The check is the script's own =="
eval "$(awk '/^check_secret_key\(\) \{/{f=1} f{print} f&&/^}$/{exit}' "${SCRIPT}")"
if type check_secret_key >/dev/null 2>&1; then
    ok "check_secret_key is lifted out of restore_database.sh"
else
    bad "restore_database.sh has no check_secret_key() to test"
fi

CONFIG_FILE="${SITE_ROOT}/config/Globalvars_site.php"
SITE_DB="$(command php -r 'require $argv[1] . "/public_html/includes/PathHelper.php"; echo Globalvars::get_instance()->get_setting("dbname", false, true);' "${SITE_ROOT}" 2>/dev/null)"
STATE_LOG="$(mktemp)"
# The real php, with what it answers kept, so a check can say which state the
# function saw and not only whether it warned.
php() { command php "$@" | tee -a "${STATE_LOG}"; }

echo "== This site's own database =="
DB_NAME="${SITE_DB}"
out="$(check_secret_key 2>&1)"
state="$(tail -n1 "${STATE_LOG}")"
[ "${state}" = "ok" ] && ok "this site's key opens its canary (state ${state})" || bad "the canary's state is '${state}', not ok"
[[ "${out}" != *RESTORE_SECRET_KEY_MISMATCH* ]] && ok "and nothing is said" || bad "a matching key was warned about: ${out}"

echo "== Another database =="
: > "${STATE_LOG}"
DB_NAME="not_${SITE_DB}"
out="$(check_secret_key 2>&1)"
state="$(tail -n1 "${STATE_LOG}")"
[ "${state}" = "other" ] && ok "a restore into another database is passed over" || bad "another database read as '${state}'"
[[ "${out}" != *RESTORE_SECRET_KEY_MISMATCH* ]] && ok "and nothing is said" || bad "another database was warned about: ${out}"

echo "== A canary another site sealed =="
php() { echo "dead"; }
DB_NAME="${SITE_DB}"
out="$(check_secret_key 2>&1)"
[[ "${out}" == *RESTORE_SECRET_KEY_MISMATCH* ]] && ok "RESTORE_SECRET_KEY_MISMATCH is printed" || bad "no warning for a canary that does not open: ${out}"
[[ "${out}" == *"--adopt-secret-key"* && "${out}" == *"secret_box_key"* ]] && ok "with the remedy" || bad "the warning names no remedy: ${out}"
unset -f php
rm -f "${STATE_LOG}"

echo
echo "================================"
echo "restore_secret_key [db]"
echo "PASSED: ${pass}   FAILED: ${fail}   ($((pass+fail)) checks)"
[ "${fail}" -eq 0 ] || exit 1
exit 0
