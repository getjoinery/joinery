#!/bin/bash
# @joinery-test
# name: pg_admin
# tier: safe
# env: any
# needs: []
# timeout: 60
# covers: [maintenance_scripts/install_tools/_pg_admin.sh]
#
# _pg_admin.sh, root's peer sign-in to PostgreSQL (specs/postgres_superuser_peer_auth.md),
# run as an unprivileged user against a temporary tree with psql, pg_isready,
# pg_ctlcluster and service stubbed on PATH:
#
#   - the map account filter (F19): never root, the web server's user, a
#     system account or one that does not exist; a developer's account is;
#   - the gate holds on a config it cannot read (F26);
#   - the proof's three outcomes (F4): signed in keeps the peer line; refused
#     puts the earlier line back; no answer leaves the line and changes nothing;
#   - the settle step (F5): a password on the role is cleared, again on a later
#     run when a roles restore brought it back; db_admin.php goes; the root
#     record goes only on a machine with a site (F9).

set -u
SITE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
HELPER="$SITE_ROOT/maintenance_scripts/install_tools/_pg_admin.sh"
T=$(mktemp -d)
trap 'chmod -R u+rwX "${T:?}" 2>/dev/null; rm -rf "${T:?}"' EXIT
passed=0; failed=0
chk() {
    if [ "$2" = "$3" ]; then
        echo "  PASS: $1"; passed=$((passed+1))
    else
        echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed+1))
    fi
}

# --- stubs --------------------------------------------------------------------
# psql: STUB_PSQL=ok signs in; =refused answers as a peer refusal; =down as no
# server. The role's password is the file $T/state/haspw; ALTER ROLE ... NULL on
# stdin removes it.
mkdir -p "$T/bin" "$T/state"
cat > "$T/bin/psql" <<'STUB'
#!/bin/bash
case "${STUB_PSQL:-ok}" in
    refused) echo 'psql: error: connection to server on socket "/var/run/postgresql/.s.PGSQL.5432" failed: FATAL:  Peer authentication failed for user "postgres"' >&2; exit 2 ;;
    down) echo 'psql: error: connection to server on socket "/var/run/postgresql/.s.PGSQL.5432" failed: No such file or directory' >&2; exit 2 ;;
esac
args="$*"
if [[ "$args" == *"rolpassword IS NOT NULL"* ]]; then
    [ -f "$STUB_STATE/haspw" ] && echo t || echo f
    exit 0
fi
if [[ "$args" != *"-c"* && "$args" != *"-tAc"* ]]; then
    input="$(cat)"
    [[ "$input" == *"ALTER ROLE postgres PASSWORD NULL"* ]] && rm -f "$STUB_STATE/haspw"
fi
echo 1
STUB
cat > "$T/bin/pg_isready" <<'STUB'
#!/bin/bash
[ "${STUB_PSQL:-ok}" != down ]
STUB
cat > "$T/bin/pg_ctlcluster" <<'STUB'
#!/bin/bash
echo "$*" >> "$STUB_STATE/reloads"
STUB
cp "$T/bin/pg_ctlcluster" "$T/bin/service"
chmod +x "$T/bin/"*
export PATH="$T/bin:$PATH" STUB_STATE="$T/state"

# shellcheck source=/dev/null
. "$HELPER"

mkfs() {  # ROOT METHOD
    mkdir -p "$1/etc/postgresql/18/main"
    printf 'local   all             postgres                                %s\nlocal   all             all                                     scram-sha-256\n' "$2" > "$1/etc/postgresql/18/main/pg_hba.conf"
    printf '# ident\n' > "$1/etc/postgresql/18/main/pg_ident.conf"
}
mksite() {  # ROOT SITE USER PASSWORD
    mkdir -p "$1/var/www/html/$2/config"
    printf "<?php\n\$this->settings['dbusername'] = '%s';\n\$this->settings['dbpassword'] = '%s';\n" "$3" "$4" > "$1/var/www/html/$2/config/Globalvars_site.php"
}
line() { awk '$1=="local" && $3=="postgres" {$1=$2=$3=""; sub(/^ +/, ""); print}' "$1/etc/postgresql/18/main/pg_hba.conf"; }

echo "=== the map account filter (F19) ==="
ok() { pg_map_account_ok "$1" && echo yes || echo no; }
chk "root never has a map line" "$(ok root)" "no"
chk "nor the web server's user" "$(ok www-data)" "no"
chk "nor a system account" "$(ok daemon)" "no"
chk "nor the nobody account" "$(ok nobody)" "no"
chk "nor an account that does not exist" "$(ok no-such-account-xyz)" "no"
chk "nor an empty record" "$(ok '')" "no"
ME_UID="$(id -u)"
if [ "$ME_UID" -ge 1000 ] && [ "$ME_UID" -lt 60000 ]; then
    chk "a developer's account does" "$(ok "$(id -un)")" "yes"
fi
F="$T/map"; mkfs "$F" scram-sha-256; mksite "$F" s1 joinery_s1 pw
pg_peer_converge "$F" 0 "$(id -un)" >/dev/null
chk "a good account writes exactly one line of its own" "$(grep -c "^joinery_admin   $(id -un) " "$F/etc/postgresql/18/main/pg_ident.conf")" "1"
pg_peer_converge "$F" 0 "" >/dev/null
chk "and without one the line goes" "$(grep -c "^joinery_admin   $(id -un) " "$F/etc/postgresql/18/main/pg_ident.conf")" "0"

echo "=== the gate holds on what it cannot read (F26) ==="
F="$T/gate"; mkfs "$F" scram-sha-256; mksite "$F" s1 joinery_s1 pw
chk "a site on its own role does not hold the line" "$(pg_peer_gate_holds "$F" >/dev/null && echo open || echo held)" "open"
mkdir -p "$F/var/www/html/s2/config"; printf "<?php\n\$this->settings['dbusername'] = getenv('X');\n" > "$F/var/www/html/s2/config/Globalvars_site.php"
chk "a database user that is not a plain literal holds it" "$(pg_peer_gate_holds "$F" | grep -c s2/config)" "1"
rm -rf "$F/var/www/html/s2"
mkdir -p "$F/var/www/html/staging/config"; printf "<?php\n\$this->settings['webDir'] = 'http://test.example.com';\n" > "$F/var/www/html/staging/config/Globalvars_site.php"
chk "a config with no database pair at all (a release-staging tree) does not hold it" "$(pg_peer_gate_holds "$F" >/dev/null && echo open || echo held)" "open"
rm -rf "$F/var/www/html/staging"; mksite "$F" s3 joinery_s3 pw; chmod 000 "$F/var/www/html/s3/config/Globalvars_site.php"
if [ ! -r "$F/var/www/html/s3/config/Globalvars_site.php" ]; then
    chk "an unreadable config holds it" "$(pg_peer_gate_holds "$F" | grep -c s3/config)" "1"
fi
chmod 644 "$F/var/www/html/s3/config/Globalvars_site.php"

echo "=== the proof (F4) ==="
F="$T/proof-ok"; mkfs "$F" scram-sha-256; mksite "$F" s1 joinery_s1 pw
rc=0; STUB_PSQL=ok pg_peer_converge "$F" 1 "" >/dev/null || rc=$?
chk "signed in: the peer line stays and the answer is 0" "$(line "$F")|$rc" "peer map=joinery_admin|0"
F="$T/proof-refused"; mkfs "$F" scram-sha-256; mksite "$F" s1 joinery_s1 pw
rc=0; out="$(STUB_PSQL=refused pg_peer_converge "$F" 1 "" 2>&1)" || rc=$?
chk "refused: the earlier line is back and the answer is 2" "$(line "$F")|$rc" "scram-sha-256|2"
chk "and it says so" "$(printf '%s\n' "$out" | grep -c "line is back to 'scram-sha-256'")" "1"
F="$T/proof-down"; mkfs "$F" scram-sha-256; mksite "$F" s1 joinery_s1 pw
rc=0; STUB_PSQL=down pg_peer_converge "$F" 1 "" >/dev/null || rc=$?
chk "no answer: the line is left as written and the answer is 1" "$(line "$F")|$rc" "peer map=joinery_admin|1"

echo "=== the settle step (F5, F9) ==="
F="$T/settle"; mkfs "$F" "peer map=joinery_admin"; mksite "$F" s1 joinery_s1 pw
mkdir -p "$F/root"; echo old > "$F/root/.joinery_postgres_password"; echo '<?php' > "$F/var/www/html/s1/config/db_admin.php"
touch "$T/state/haspw"
out="$(STUB_PSQL=ok pg_peer_settle "$F")"
chk "a password on the role is cleared" "$([ -f "$T/state/haspw" ] && echo kept || echo cleared)" "cleared"
chk "db_admin.php goes" "$([ -f "$F/var/www/html/s1/config/db_admin.php" ] && echo kept || echo gone)" "gone"
chk "and the root record, on a machine with a site" "$([ -f "$F/root/.joinery_postgres_password" ] && echo kept || echo gone)" "gone"
out="$(STUB_PSQL=ok pg_peer_settle "$F")"
chk "a second run says nothing" "$out" ""
touch "$T/state/haspw"
out="$(STUB_PSQL=ok pg_peer_settle "$F")"
chk "a password a roles restore brought back is cleared on the next run" "$([ -f "$T/state/haspw" ] && echo kept || echo cleared)|$(printf '%s\n' "$out" | grep -c 'password is cleared')" "cleared|1"
F="$T/settle-nosite"; mkfs "$F" "peer map=joinery_admin"; mkdir -p "$F/root"; echo old > "$F/root/.joinery_postgres_password"
STUB_PSQL=ok pg_peer_settle "$F" >/dev/null
chk "a machine with no site keeps the root record for a retry" "$([ -f "$F/root/.joinery_postgres_password" ] && echo kept || echo gone)" "kept"

echo
echo "pg_admin gate: $passed passed, $failed failed"
[ "$failed" -eq 0 ]
