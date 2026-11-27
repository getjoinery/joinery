#!/usr/bin/env bash
# site_db_role.sh - Give a site its own PostgreSQL role, make that role the owner
# of the site's database and everything in it, and point the site at it
# (mail_access_hardening WP4).
#
# VERSION: 1.4 - root signs in as postgres by peer (_pg_admin.sh, specs/postgres_superuser_peer_auth.md);
#                db_admin.php is no longer written (host_housekeeping.sh deletes one an earlier run left)
# VERSION: 1.3 - the password generator reads a bounded slice of /dev/urandom: the unbounded pipe failed under
#                pipefail and the script left without a word (found on the owner's first run)
# VERSION: 1.2 - the connect proof runs before the config is rewritten, so a role that cannot connect leaves
#                the site on the pair it had (reviewer2 end-to-end B2)
# VERSION: 1.1 - reviewer2: the public schema changes hands too and the connect proof reads a table (B1);
#                owned sequences follow their tables (B2); log_statement off while the password is set (B6);
#                config literals read by the tokenizer (B7)
# VERSION: 1.0
#
# Usage (as root):
#   site_db_role.sh SITENAME [--db-name NAME] [--test-db NAME] [--no-config] [--print-sql]
#
# The role is joinery_{SITENAME}: LOGIN, NOSUPERUSER, NOCREATEROLE, CREATEDB
# (the test runner and the backup verifier create and drop their throwaway
# databases on this role), CONNECTION LIMIT 100. The website then connects as
# that role and can touch the site's own tables and nothing more: no COPY ...
# TO PROGRAM, no other site's database, no role or server setting.
#
# Idempotent, the shape of install_email.sh's role step: the role is created
# once; its attributes, password and ownership are (re)asserted every run; an
# object the role already owns is left alone. The ownership block is
# _db_ownership_sql.sh, deliberately not REASSIGN OWNED BY (which would move
# every database on the server).
#
# The site's config/Globalvars_site.php: dbusername/dbpassword (and the _test
# pair) become the role's; everything else, the secret_box_key included, is left
# byte for byte (_set_site_db_role.php). A config that already names the role
# keeps its password.
#
# The postgres connection is root's own, by peer (_pg_admin.sh), or on a machine
# not switched over yet the password it had. The role's password goes to psql
# over stdin, never argv.
#
# --print-sql prints the statements and connects to nothing (the test reads it).

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WEB_DIR="/var/www/html"

usage() {
    echo "usage: site_db_role.sh SITENAME [--db-name NAME] [--test-db NAME] [--no-config] [--print-sql]" >&2
    exit 1
}

SITENAME="${1:-}"
[[ -n "$SITENAME" ]] || usage
shift
if [[ ! "$SITENAME" =~ ^[a-z][a-z0-9_]{0,40}$ ]]; then
    echo "site_db_role: '$SITENAME' is not a site name (lower-case letters, digits, underscores)" >&2
    exit 1
fi

DB_NAME=""
TEST_DB=""
WRITE_CONFIG=true
PRINT_SQL=false
while [[ $# -gt 0 ]]; do
    case "$1" in
        --db-name)   DB_NAME="$2"; shift 2 ;;
        --test-db)   TEST_DB="$2"; shift 2 ;;
        --no-config) WRITE_CONFIG=false; shift ;;
        --print-sql) PRINT_SQL=true; shift ;;
        *) usage ;;
    esac
done

SITE_ROOT="$WEB_DIR/$SITENAME"
CONFIG="$SITE_ROOT/config/Globalvars_site.php"
ROLE="joinery_${SITENAME}"

log() { echo "site_db_role: $*"; }
fail() { echo "site_db_role: $*" >&2; exit 1; }

# The string literal assigned to $this->settings['NAME'] in a config file, read
# as PHP reads it (a password with a quote or a backslash included).
config_value() {  # FILE NAME
    [[ -r "$1" ]] || return 0
    php "$SCRIPT_DIR/_config_value.php" "$1" "$2" 2>/dev/null
}

[[ -n "$DB_NAME" ]] || DB_NAME="$(config_value "$CONFIG" dbname)"
[[ -n "$DB_NAME" ]] || DB_NAME="$SITENAME"
[[ -n "$TEST_DB" ]] || TEST_DB="$(config_value "$CONFIG" dbname_test)"
[[ -n "$TEST_DB" ]] || TEST_DB="test_${SITENAME}"
for name in "$DB_NAME" "$TEST_DB"; do
    [[ "$name" =~ ^[a-z][a-z0-9_]{0,62}$ ]] || fail "'$name' is not a database name this script will touch"
done

# The role's password: kept when the config already names the role, else new.
CURRENT_USER="$(config_value "$CONFIG" dbusername)"
ROLE_PW=""
if [[ "$CURRENT_USER" == "$ROLE" ]]; then
    ROLE_PW="$(config_value "$CONFIG" dbpassword)"
fi
if [[ -z "$ROLE_PW" ]]; then
    # A bounded read first: under pipefail, `tr < /dev/urandom | head` ends with
    # tr killed by SIGPIPE and the assignment fails, and set -e then leaves the
    # script with no word said. 512 random bytes yield well over 32 letters.
    ROLE_PW="$(head -c 512 /dev/urandom | LC_ALL=C tr -dc 'A-Za-z0-9' | head -c 32)" || ROLE_PW=""
    [[ ${#ROLE_PW} -eq 32 ]] || fail "could not generate a password"
    # --print-sql still generates one, so this path is exercised; it prints a
    # placeholder, never the password.
    $PRINT_SQL && ROLE_PW="(generated, 32 characters)"
fi

# The SQL, in the order it runs. The password is the one value quoted by hand
# (doubled single quotes); the role and database names were validated above.
role_sql() {
    local pw_sql="${ROLE_PW//\'/\'\'}"
    # log_statement off for this one transaction, so the password is not written
    # to the PostgreSQL log on a server that logs DDL (superuser-only, and the
    # connection here is postgres).
    cat <<SQL
BEGIN;
SET LOCAL log_statement = 'none';
ALTER ROLE "${ROLE}" LOGIN NOSUPERUSER CREATEDB NOCREATEROLE CONNECTION LIMIT 100 PASSWORD '${pw_sql}';
COMMIT;
SQL
}
database_sql() {  # DBNAME
    echo "ALTER DATABASE \"$1\" OWNER TO \"${ROLE}\";"
}

if $PRINT_SQL; then
    echo "-- role ${ROLE} (created first when it does not exist: CREATE ROLE \"${ROLE}\" LOGIN)"
    role_sql
    for db in "$DB_NAME" "$TEST_DB"; do
        echo "-- database ${db} (when it exists)"
        database_sql "$db"
        echo "\\connect ${db}"
        bash "$SCRIPT_DIR/_db_ownership_sql.sh" "$ROLE"
    done
    exit 0
fi

[[ "$(id -u)" -eq 0 ]] || fail "run as root: it writes the site's config files"

# ---- the postgres connection ------------------------------------------------
# shellcheck source=_pg_admin.sh
. "$SCRIPT_DIR/_pg_admin.sh"
pg_admin_auth || true
admin_psql() { psql -U postgres -X -q -v ON_ERROR_STOP=1 "$@"; }
admin_psql -d postgres -tAc 'SELECT 1' >/dev/null 2>&1 \
    || fail "cannot connect to PostgreSQL as postgres (root signs in by peer: check the 'local all postgres' line in pg_hba.conf and the joinery_admin map in pg_ident.conf)"

db_exists() { [[ "$(admin_psql -d postgres -tAc "SELECT 1 FROM pg_database WHERE datname = '$1'")" == "1" ]]; }
db_exists "$DB_NAME" || fail "database '$DB_NAME' does not exist"

# ---- the role -------------------------------------------------------------------
if [[ "$(admin_psql -d postgres -tAc "SELECT 1 FROM pg_roles WHERE rolname = '${ROLE}'")" != "1" ]]; then
    admin_psql -d postgres -c "CREATE ROLE \"${ROLE}\" LOGIN"
    log "created role ${ROLE}"
fi
role_sql | admin_psql -d postgres
log "role ${ROLE}: LOGIN NOSUPERUSER CREATEDB NOCREATEROLE CONNECTION LIMIT 100, password set"

# ---- ownership ------------------------------------------------------------------
for db in "$DB_NAME" "$TEST_DB"; do
    if ! db_exists "$db"; then
        [[ "$db" == "$TEST_DB" ]] && log "test database ${db} does not exist yet; it is created on the role when the test runner next builds it"
        continue
    fi
    database_sql "$db" | admin_psql -d postgres
    # The same count the ownership block walks: a sequence a column owns follows
    # its table and is not counted on its own.
    before="$(admin_psql -d "$db" -tAc "SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname = 'public' AND c.relkind IN ('r','p','S','v','m','f') AND pg_get_userbyid(c.relowner) <> '${ROLE}'
          AND NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.classid = 'pg_class'::regclass AND d.objid = c.oid
                            AND d.refclassid = 'pg_class'::regclass AND d.deptype IN ('a','i'))")"
    schema_owner="$(admin_psql -d "$db" -tAc "SELECT pg_get_userbyid(nspowner) FROM pg_namespace WHERE nspname = 'public'")"
    bash "$SCRIPT_DIR/_db_ownership_sql.sh" "$ROLE" | admin_psql -d "$db"
    log "database ${db}: owner ${ROLE}; public schema was ${schema_owner}; ${before} object(s) handed over"
done

# ---- the role can do what the site needs --------------------------------------
# Before the config is pointed at it, so a role that cannot connect leaves the
# site on the pair it had. A real read through the schema, not SELECT 1: the
# schema's USAGE is what a superuser-owned public schema used to deny the role.
if PGPASSWORD="$ROLE_PW" psql -U "$ROLE" -h localhost -d "$DB_NAME" -X -v ON_ERROR_STOP=1 -tAc 'SELECT count(*) FROM stg_settings' >/dev/null 2>&1; then
    log "${ROLE} connects to ${DB_NAME} over localhost and reads its tables"
else
    fail "${ROLE} cannot read ${DB_NAME} over localhost; the site's config was not changed. Check pg_hba.conf allows password (scram) connections for it, and that the ownership step above ran"
fi

# ---- the site's config ----------------------------------------------------------
if $WRITE_CONFIG; then
    [[ -f "$CONFIG" ]] || fail "no config at $CONFIG"
    result="$(JOINERY_CFG_DB_ROLE="$ROLE" JOINERY_CFG_DB_PASSWORD="$ROLE_PW" php "$SCRIPT_DIR/_set_site_db_role.php" "$CONFIG")" \
        || fail "could not point $CONFIG at ${ROLE}"
    chmod 640 "$CONFIG"
    chown root:www-data "$CONFIG" 2>/dev/null || true
    log "Globalvars_site.php: dbusername ${ROLE} (${result})"
fi

echo "SITE_DB_ROLE_OK ${ROLE}"
