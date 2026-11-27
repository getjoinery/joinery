#!/usr/bin/env bash
# _pg_admin.sh - root reaches PostgreSQL as postgres by who it is (sourced).
#
# VERSION: 1.1 - reviewer2 build review: the gate holds on a config it cannot read (F26); held sites are given
#                their role where the peer line is already in force (F24); the account filter and the settle
#                step are functions here, so they are tested (F25)
# VERSION: 1.0 (specs/postgres_superuser_peer_auth.md)
#
# The postgres role has no password. pg_hba.conf signs root in over the local
# socket by its Linux user ("peer"), through one map in pg_ident.conf:
#
#   local   all   postgres   peer map=joinery_admin
#
#   joinery_admin   root       postgres
#   joinery_admin   postgres   postgres
#
# Functions:
#
#   pg_admin_auth
#       Makes `psql -U postgres` with no -h sign in, for the rest of the calling
#       shell. Peer first, with PGPASSWORD unset. A machine the converger has
#       not switched yet (a site still on the postgres role holds it back) is
#       signed in with the password it used before: PGPASSWORD as handed in,
#       the container's POSTGRES_PASSWORD, config/db_admin.php,
#       /root/.joinery_postgres_password, then a site config still naming
#       postgres. The first that signs in is exported as PGPASSWORD. Sets
#       PG_ADMIN_AUTH to peer or password. Returns 1 when nothing signs in.
#
#   pg_peer_gate_holds FS_ROOT
#       True when no site config on this machine names postgres and holds a
#       password, in either pair (dbusername/dbpassword, dbusername_test/
#       dbpassword_test), and every config could be read. A site still on
#       postgres signs in with that password over the socket as www-data (its
#       scheduled backup), which the peer line would refuse, so such a machine
#       keeps the line it has. Names each config that holds it on stdout.
#
#   pg_roles_for_held_sites FS_ROOT
#       Runs site_db_role.sh for each site the gate names. Returns 1 when any
#       is left on postgres.
#
#   pg_map_account_ok ACCOUNT
#       True when ACCOUNT may have a map line: a real account, not root, not the
#       web server's user, uid 1000 to 59999 (a person, not a system or dynamic account).
#
#   pg_peer_settle FS_ROOT
#       Once peer answers: clears the role's password, removes each
#       config/db_admin.php, and /root/.joinery_postgres_password on a machine
#       with a site. Prints what it did; returns 1 when the password stays.
#
#   pg_peer_converge FS_ROOT RUN_SYSTEM [ACCOUNT]
#       Writes the map (and ACCOUNT's line, a developer's box only), then, when
#       the gate holds, turns the local postgres line to peer and proves it. A
#       refused proof puts the old line back. Prints what it did. Returns 0 when
#       the peer line is in force and answers, 1 when it is not in force (gated,
#       deferred, or put back), 2 when it was put back after a refusal.
#
# Never prints a password.

PG_ADMIN_MAP_MARK="# joinery-admin-map"
PG_ADMIN_READER="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/_config_value.php"

_pg_admin_try() {  # [PASSWORD] -> 0 when psql -U postgres signs in
    if [[ $# -eq 0 ]]; then
        env -u PGPASSWORD PGPASSFILE=/nonexistent/pgpass psql -w -X -U postgres -d postgres -tAc 'SELECT 1' >/dev/null 2>&1
    else
        PGPASSWORD="$1" PGPASSFILE=/nonexistent/pgpass psql -w -X -U postgres -d postgres -tAc 'SELECT 1' >/dev/null 2>&1
    fi
}

_pg_admin_value() {  # FILE NAME
    [[ -r "$1" ]] || return 0
    php "$PG_ADMIN_READER" "$1" "$2" 2>/dev/null
}

pg_admin_auth() {
    if _pg_admin_try; then
        unset PGPASSWORD
        PG_ADMIN_AUTH=peer
        return 0
    fi
    local pw cfg
    local -a candidates=()
    [[ -n "${PGPASSWORD:-}" ]] && candidates+=("$PGPASSWORD")
    [[ -n "${POSTGRES_PASSWORD:-}" ]] && candidates+=("$POSTGRES_PASSWORD")
    for cfg in /var/www/html/*/config/db_admin.php; do
        pw="$(_pg_admin_value "$cfg" dbadminpassword)"
        [[ -n "$pw" ]] && candidates+=("$pw")
    done
    if [[ -r /root/.joinery_postgres_password ]]; then
        pw="$(tr -d '\n' < /root/.joinery_postgres_password)"
        [[ -n "$pw" ]] && candidates+=("$pw")
    fi
    for cfg in /var/www/html/*/config/Globalvars_site.php; do
        if [[ "$(_pg_admin_value "$cfg" dbusername)" == "postgres" ]]; then
            pw="$(_pg_admin_value "$cfg" dbpassword)"
            [[ -n "$pw" ]] && candidates+=("$pw")
        fi
    done
    for pw in "${candidates[@]}"; do
        if _pg_admin_try "$pw"; then
            export PGPASSWORD="$pw"
            PG_ADMIN_AUTH=password
            return 0
        fi
    done
    # A developer's ~/.pgpass, on a box the converger has not given its line yet.
    if env -u PGPASSWORD psql -w -X -U postgres -d postgres -tAc 'SELECT 1' >/dev/null 2>&1; then
        unset PGPASSWORD
        PG_ADMIN_AUTH=password
        return 0
    fi
    return 1
}

pg_peer_gate_holds() {  # FS_ROOT
    local fs="$1" cfg sfx user held=0
    for cfg in "$fs"/var/www/html/*/config/Globalvars_site.php; do
        [[ -e "$cfg" ]] || continue
        # A config whose answer cannot be read holds the line: unreadable, or
        # no database user read from it (php missing or failing, a value that
        # is not a plain literal).
        if [[ ! -r "$cfg" || -z "$(_pg_admin_value "$cfg" dbusername)" ]]; then
            echo "$cfg"
            held=1
            continue
        fi
        for sfx in "" _test; do
            user="$(_pg_admin_value "$cfg" "dbusername${sfx}")"
            if [[ "$user" == "postgres" && -n "$(_pg_admin_value "$cfg" "dbpassword${sfx}")" ]]; then
                echo "$cfg"
                held=1
                break
            fi
        done
    done
    [[ "$held" == 0 ]]
}

# Gives each site the gate holds back its own role (site_db_role.sh, as root,
# signed in by peer or the password this machine holds). For a machine that is
# on the peer line already (an install.sh server run again, a container started
# on an image that carries the line), where such a site's www-data backup would
# otherwise be refused. Says what it did; returns 1 when any site is left on
# postgres.
pg_roles_for_held_sites() {  # FS_ROOT
    local fs="$1" cfg site rc=0 out
    local tool
    tool="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/site_db_role.sh"
    while IFS= read -r cfg; do
        [[ -n "$cfg" ]] || continue
        site="$(basename "$(dirname "$(dirname "$cfg")")")"
        if [[ -f "$tool" ]] && out="$(bash "$tool" "$site" 2>&1 </dev/null)"; then
            echo "PostgreSQL: ${site} was still signing in as postgres; it has its own role now (site_db_role.sh)"
        else
            echo "PostgreSQL: WARNING - ${site} still signs in as postgres and site_db_role.sh ${site} failed: $(printf '%s' "$out" | tail -1). Its scheduled backup is refused until it succeeds"
            rc=1
        fi
    done < <(pg_peer_gate_holds "$fs")
    return "$rc"
}

# The method on the "local all postgres" line, the rest of the line after it.
_pg_postgres_line_method() {  # HBA
    awk '$1 == "local" && $2 == "all" && $3 == "postgres" { $1 = $2 = $3 = ""; sub(/^ +/, ""); print; exit }' "$1"
}

_pg_set_postgres_line() {  # HBA METHOD...
    local hba="$1"; shift
    local tmp
    tmp="$(mktemp)"
    awk -v m="$*" '
        !done && $1 == "local" && $2 == "all" && $3 == "postgres" {
            printf "local   all             postgres                                %s\n", m; done = 1; next }
        { print }' "$hba" > "$tmp"
    cat "$tmp" > "$hba"
    rm -f "$tmp"
}

_pg_write_map() {  # IDENT ACCOUNT
    local ident="$1" account="$2" tmp
    tmp="$(mktemp)"
    [[ -f "$ident" ]] && grep -vF "$PG_ADMIN_MAP_MARK" "$ident" > "$tmp"
    {
        printf 'joinery_admin   root            postgres   %s\n' "$PG_ADMIN_MAP_MARK"
        printf 'joinery_admin   postgres        postgres   %s\n' "$PG_ADMIN_MAP_MARK"
        [[ -n "$account" ]] && printf 'joinery_admin   %-15s postgres   %s\n' "$account" "$PG_ADMIN_MAP_MARK"
    } >> "$tmp"
    if [[ -f "$ident" ]] && cmp -s "$tmp" "$ident"; then
        rm -f "$tmp"
        return 1
    fi
    cat "$tmp" > "$ident"
    rm -f "$tmp"
    chown postgres:postgres "$ident" 2>/dev/null || true
    chmod 640 "$ident"
    return 0
}

_pg_reload() {  # VERSION
    pg_ctlcluster "$1" main reload >/dev/null 2>&1 || service postgresql reload >/dev/null 2>&1
}

pg_peer_converge() {  # FS_ROOT RUN_SYSTEM [ACCOUNT]
    local fs="$1" run_system="$2" account="${3:-}"
    local pg_dir hba ident ver old changed rc=0 out
    for pg_dir in "$fs"/etc/postgresql/*/main; do
        hba="${pg_dir}/pg_hba.conf"
        ident="${pg_dir}/pg_ident.conf"
        [[ -f "$hba" ]] || continue
        ver="$(basename "$(dirname "$pg_dir")")"
        changed=0
        if _pg_write_map "$ident" "$account"; then
            echo "PostgreSQL ${ver}: pg_ident.conf holds the joinery_admin map"
            changed=1
        fi
        old="$(_pg_postgres_line_method "$hba")"
        if [[ "$old" != "peer map=joinery_admin" ]]; then
            if ! pg_peer_gate_holds "$fs" >/dev/null; then
                [[ "$changed" == 1 && "$run_system" == 1 ]] && _pg_reload "$ver"
                rc=1
                continue
            fi
            if [[ -z "$old" ]]; then
                echo "PostgreSQL ${ver}: pg_hba.conf has no 'local all postgres' line; left as it is"
                rc=1
                continue
            fi
            _pg_set_postgres_line "$hba" "peer map=joinery_admin"
            echo "PostgreSQL ${ver}: root signs in as postgres by peer (was: ${old})"
            changed=1
        fi
        [[ "$run_system" == 1 ]] || continue
        [[ "$changed" == 1 ]] && _pg_reload "$ver"
        # The proof: signed in, refused, or no answer at all.
        pg_isready -q >/dev/null 2>&1 || { rc=1; continue; }
        if out="$(env -u PGPASSWORD PGPASSFILE=/nonexistent/pgpass psql -w -X -U postgres -d postgres -tAc 'SELECT 1' 2>&1)"; then
            continue
        fi
        if [[ "$out" == *"Peer authentication failed"* || "$out" == *"no pg_hba.conf entry"* || "$out" == *"password"* ]]; then
            if [[ -n "$old" && "$old" != "peer map=joinery_admin" ]]; then
                _pg_set_postgres_line "$hba" "$old"
                _pg_reload "$ver"
                echo "PostgreSQL ${ver}: peer sign-in was refused, so the line is back to '${old}': ${out}"
            else
                echo "PostgreSQL ${ver}: peer sign-in is refused: ${out}"
            fi
            return 2
        fi
        rc=1
    done
    return "$rc"
}

pg_map_account_ok() {  # ACCOUNT
    local account="$1" uid
    [[ -n "$account" && "$account" != root && "$account" != www-data ]] || return 1
    uid="$(id -u "$account" 2>/dev/null)" || return 1
    [[ "$uid" =~ ^[0-9]+$ && "$uid" -ge 1000 && "$uid" -lt 60000 ]]
}

pg_peer_settle() {  # FS_ROOT
    local fs="$1" f rc=0
    if [[ "$(env -u PGPASSWORD PGPASSFILE=/nonexistent/pgpass psql -w -X -U postgres -d postgres -tAc "SELECT rolpassword IS NOT NULL FROM pg_authid WHERE rolname = 'postgres'" 2>/dev/null)" == "t" ]]; then
        if printf 'ALTER ROLE postgres PASSWORD NULL;\n' | env -u PGPASSWORD PGPASSFILE=/nonexistent/pgpass psql -w -X -q -v ON_ERROR_STOP=1 -U postgres -d postgres >/dev/null 2>&1; then
            echo "PostgreSQL: the postgres role's password is cleared (root signs in by peer)"
        else
            echo "PostgreSQL: WARNING - the postgres role's password could not be cleared"
            return 1
        fi
    fi
    for f in "$fs"/var/www/html/*/config/db_admin.php; do
        [[ -f "$f" ]] || continue
        rm -f "$f" && echo "removed ${f}: root signs in to PostgreSQL by peer"
    done
    if [[ -f "$fs/root/.joinery_postgres_password" ]] \
            && compgen -G "$fs/var/www/html/*/config/Globalvars_site.php" >/dev/null; then
        rm -f "$fs/root/.joinery_postgres_password" \
            && echo "removed /root/.joinery_postgres_password: root signs in to PostgreSQL by peer"
    fi
    return "$rc"
}
