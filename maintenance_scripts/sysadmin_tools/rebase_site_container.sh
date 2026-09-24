#!/usr/bin/env bash
# rebase_site_container.sh — move a Docker site onto a newer base image whose
# PostgreSQL is a newer major version, carrying its database across.
#
# Version: 1.1 - prepare refuses a container that publishes a port install.sh will not recreate
#                (scrolldaddy publishes its database on the private network for its DNS
#                resolvers; a rebuild would put it back on loopback and cut them off), and
#                names any environment variable install.sh does not set.
# Version: 1.0
#
# A site container keeps its database on the `<site>_postgres` volume, one
# cluster per major under /var/lib/postgresql/<major>/main. A base image that
# carries a newer PostgreSQL cannot open that cluster, and the start command
# refuses to try. This moves the data the one way that also rebuilds every
# index under the new image's collation: a dump from the old server, restored
# into the new one.
#
# Run as root on the Docker host, from the extracted release that carries the
# target base image — its install.sh is what rebuilds the container, so the new
# container is exactly what a fresh install of this site would be.
#
#   rebase_site_container.sh <site> prepare     nothing destructive; the site keeps serving
#   rebase_site_container.sh <site> swap [-- <install.sh site flags>]
#                                               the site is down until this finishes
#   rebase_site_container.sh <site> rollback    back onto the old image and the old database
#   rebase_site_container.sh <site> finish      remove the rollback copies (after a week)
#
# prepare records what the move will need and refuses anything it cannot do:
# the database's name, encoding and locale (refused when the new image lacks
# the locale), every table's row count, the roles and pg_hba lines the
# container carries beyond its image, the old container's exact run arguments
# (for rollback), and a trial dump's size against the disk free here.
#
# swap stops the site's writes, dumps the database, keeps a copy of the old
# database volume and the old image, rebuilds the container with install.sh
# on a fresh database volume, sets the postgres password from the site's own
# environment, restores roles, database and pg_hba lines, and restarts the site
# with its data in place. It then compares every table's row count with the
# count taken after writes stopped, and prints the rollback command on any
# difference.
#
# Nothing here ever prints a password. The database password is read inside
# the container from its own environment, so it is never on a host command line.

set -euo pipefail

SITE="${1:-}"
STAGE="${2:-}"
shift 2 2>/dev/null || true
INSTALL_FLAGS=()
if [ "${1:-}" = "--" ]; then shift; INSTALL_FLAGS=("$@"); fi

case "$STAGE" in prepare|swap|rollback|finish) ;; *)
    echo "usage: $0 <site> prepare|swap|rollback|finish [-- <install.sh site flags>]" >&2
    exit 2
esac
[[ "$SITE" =~ ^[a-z0-9_-]{1,50}$ ]] || { echo "invalid site name: ${SITE}" >&2; exit 2; }

WORK="/root/rebase/${SITE}"
STATE="${WORK}/state"
TOOLS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
INSTALL_SH="$(cd "${TOOLS_DIR}/../install_tools" 2>/dev/null && pwd)/install.sh"

say() { echo "[$(date -u +%H:%M:%S)] $*"; }
die() { echo "FATAL: $*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "run as root on the Docker host"
command -v docker > /dev/null || die "docker is not installed here"

state_get() { grep "^$1=" "$STATE" 2>/dev/null | tail -1 | cut -d= -f2-; }
state_set() {
    local tmp; tmp="$(mktemp "${WORK}/.state.XXXXXX")"
    { grep -v "^$1=" "$STATE" 2>/dev/null || true; printf '%s=%s\n' "$1" "$2"; } > "$tmp"
    mv "$tmp" "$STATE"
}
vol_mp() { docker volume inspect -f '{{ .Mountpoint }}' "$1"; }

# SQL against the site's server as postgres, authenticated with the password the
# container was started with — read inside the container, never on this host's
# command line. The SQL arrives on stdin.
psql_in() {  # $1 database
    docker exec -i "$SITE" bash -c 'PGPASSWORD="$POSTGRES_PASSWORD" psql -U postgres -XtA -F "$(printf "\t")" -v ON_ERROR_STOP=1 -d "$1"' _ "$1"
}

# Every table's exact row count, one "schema.table<TAB>count" line each.
count_rows() {  # $1 database
    printf '%s\n' "SELECT format('SELECT %L, count(*) FROM %I.%I', n.nspname || '.' || c.relname, n.nspname, c.relname)
FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE c.relkind IN ('r', 'p') AND n.nspname NOT IN ('pg_catalog', 'information_schema') AND n.nspname NOT LIKE 'pg_toast%'
ORDER BY 1 \\gexec" | psql_in "$1" | sort
}

db_major() { docker exec "$SITE" bash -c 'cat /var/lib/postgresql/*/main/PG_VERSION 2>/dev/null' | tr -cd '0-9\n' | sort -n | tail -1; }
image_major() { docker run --rm --entrypoint ls "$1" /usr/lib/postgresql 2>/dev/null | sort -n | tail -1; }
target_base() {
    local v
    v="$(sed -n 's/^BASE_IMAGE_VERSION="\([^"]*\)".*/\1/p' "$INSTALL_SH" | head -1)"
    [ -n "$v" ] || die "could not read BASE_IMAGE_VERSION from ${INSTALL_SH}"
    echo "joinery-base:${v}"
}
norm_locale() { echo "$1" | tr 'A-Z' 'a-z' | tr -d '-'; }

# The old container's run arguments, exactly as it runs now, one per line
# (NUL-separated: values may hold spaces). Written 0600: the environment
# carries the database password.
save_run_args() {
    local out="$1" hip hport cport e name dest restart
    : > "$out"; chmod 600 "$out"
    restart="$(docker inspect -f '{{.HostConfig.RestartPolicy.Name}}' "$SITE")"
    { printf '%s\0' --name "$SITE" --hostname "$(docker inspect -f '{{.Config.Hostname}}' "$SITE")"
      [ -n "$restart" ] && [ "$restart" != "no" ] && printf '%s\0' --restart "$restart"
      while IFS='|' read -r hip hport cport; do
          [ -z "$cport" ] && continue
          cport="${cport%%/*}"
          if [ -n "$hip" ]; then printf '%s\0' -p "${hip}:${hport}:${cport}"; else printf '%s\0' -p "${hport}:${cport}"; fi
      done < <(docker inspect -f '{{range $p, $conf := .HostConfig.PortBindings}}{{range $conf}}{{.HostIp}}|{{.HostPort}}|{{$p}}{{println}}{{end}}{{end}}' "$SITE")
      while IFS= read -r e; do
          [ -z "$e" ] && continue
          case "$e" in PATH=*|DEBIAN_FRONTEND=*) continue ;; esac
          printf '%s\0' -e "$e"
      done < <(docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' "$SITE")
      while read -r name dest; do
          [ -z "$name" ] && continue
          printf '%s\0' -v "${name}:${dest}"
      done < <(docker inspect -f '{{range .Mounts}}{{if eq .Type "volume"}}{{.Name}} {{.Destination}}{{println}}{{end}}{{end}}' "$SITE")
    } >> "$out"
}

# pg_hba lines the container carries that its own image does not: somebody
# added them by hand (a resolver reading this database over the network, say).
# The rebuilt container starts from the new image's file, so these are what
# must be carried across.
hba_extras() {  # $1 image the container was built from, $2 major
    local f="/etc/postgresql/$2/main/pg_hba.conf"
    comm -23 \
        <(docker exec "$SITE" cat "$f" | grep -v '^[[:space:]]*#' | grep -v '^[[:space:]]*$' | sed 's/[[:space:]]\+/ /g' | sort -u) \
        <(docker run --rm --entrypoint cat "$1" "$f" | grep -v '^[[:space:]]*#' | grep -v '^[[:space:]]*$' | sed 's/[[:space:]]\+/ /g' | sort -u)
}

# Set the postgres role's password to the one the site's config uses. A fresh
# cluster under an existing config has the image's own password; the start
# command sets it only when there is no config yet. Same trust swap as
# Dockerfile.template: the local postgres line goes to trust for one command
# and back, and the password travels on stdin.
set_postgres_password() {
    docker exec -i "$SITE" bash -s <<'EOS'
set -e
PG_CONF="$(ls -1d /etc/postgresql/*/main/pg_hba.conf | sort -V | tail -1)"
METHOD="$(awk '$1=="local" && $2=="all" && $3=="postgres" {print $4; exit}' "$PG_CONF")"
if [ -z "$METHOD" ] || [ "$METHOD" = "trust" ]; then
    echo "no method to restore on the local postgres line in $PG_CONF" >&2; exit 1
fi
sed -i -E 's/^(local[[:space:]]+all[[:space:]]+postgres[[:space:]]+)[A-Za-z0-9-]+/\1trust/' "$PG_CONF"
service postgresql reload > /dev/null
PW_SQL="${POSTGRES_PASSWORD//"'"/"''"}"
rc=0
printf "ALTER USER postgres PASSWORD '%s';\n" "$PW_SQL" | su -c "psql -q -v ON_ERROR_STOP=1" postgres || rc=$?
sed -i -E "s/^(local[[:space:]]+all[[:space:]]+postgres[[:space:]]+)[A-Za-z0-9-]+/\1${METHOD}/" "$PG_CONF"
service postgresql reload > /dev/null
if grep -qE '^local[[:space:]]+all[[:space:]]+postgres[[:space:]]+trust' "$PG_CONF"; then
    echo "local postgres left on trust in $PG_CONF" >&2; exit 1
fi
exit $rc
EOS
}

wait_for_postgres() {
    local i
    for i in $(seq 1 60); do
        docker exec "$SITE" pg_isready -q 2>/dev/null && return 0
        sleep 2
    done
    return 1
}

mkdir -p "$WORK"; chmod 700 /root/rebase "$WORK"

# ─────────────────────────────────────────────────────────────────── prepare
if [ "$STAGE" = "prepare" ]; then
    [ -f "$INSTALL_SH" ] || die "no install.sh at ${INSTALL_SH} — run this from the extracted release that carries the new base image"
    [ "$(docker inspect -f '{{.State.Status}}' "$SITE" 2>/dev/null)" = "running" ] || die "container ${SITE} is not running"

    BASE="$(target_base)"
    if ! docker image inspect "$BASE" > /dev/null 2>&1; then
        say "Building ${BASE} (one-time per host, 5-10 minutes)"
        bash "$INSTALL_SH" build-base
    fi
    HAVE="$(db_major)"; WANT="$(image_major "$BASE")"
    [ -n "$HAVE" ] || die "could not read the PostgreSQL version of ${SITE}'s database"
    [ -n "$WANT" ] || die "could not read the PostgreSQL version inside ${BASE}"
    [ "$HAVE" != "$WANT" ] || die "${SITE} already runs PostgreSQL ${HAVE}, the version ${BASE} carries — nothing to move"
    [ "$HAVE" -lt "$WANT" ] || die "${SITE} runs PostgreSQL ${HAVE}, newer than the ${WANT} in ${BASE}"

    DB="$(docker exec "$SITE" bash -c "sed -n \"s/^\\\$this->settings\['dbname'\] = '\([^']*\)'.*/\1/p\" /var/www/html/${SITE}/config/Globalvars_site.php" | head -1)"
    [ -n "$DB" ] || die "could not read the database name from ${SITE}'s config"
    IFS=$'\t' read -r ENC COLL CTYPE < <(printf '%s\n' "SELECT pg_encoding_to_char(encoding), datcollate, datctype FROM pg_database WHERE datname = '${DB}'" | psql_in postgres)
    [ -n "${ENC:-}" ] || die "database ${DB} not found in ${SITE}"
    LOCALES="$(docker run --rm --entrypoint locale "$BASE" -a 2>/dev/null)"
    for loc in "$COLL" "$CTYPE"; do
        case "$(norm_locale "$loc")" in c|posix|c.utf8) continue ;; esac
        printf '%s\n' "$LOCALES" | while read -r l; do norm_locale "$l"; done | grep -qx "$(norm_locale "$loc")" \
            || die "database ${DB} uses locale ${loc}, which ${BASE} does not have"
    done

    DOMAIN="$(docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' "$SITE" | sed -n 's/^DOMAIN_NAME=//p' | head -1)"
    PORT="$(docker inspect -f '{{range $p, $conf := .HostConfig.PortBindings}}{{if eq $p "80/tcp"}}{{range $conf}}{{.HostPort}}{{end}}{{end}}{{end}}' "$SITE")"
    [ -n "$PORT" ] || die "could not read ${SITE}'s web port"

    # install.sh recreates exactly two bindings: the web port on every
    # interface and the database port (web + 1000) on loopback. Anything else
    # was added by hand for something outside this machine, and the rebuild
    # would silently drop it.
    EXTRA_PORTS=""
    while IFS='|' read -r hip hport cport; do
        [ -z "$cport" ] && continue
        case "${cport%%/*}:${hip}:${hport}" in
            "80::${PORT}"|"80:0.0.0.0:${PORT}") ;;
            "5432:127.0.0.1:$((PORT + 1000))") ;;
            *) EXTRA_PORTS="${EXTRA_PORTS} ${hip:-0.0.0.0}:${hport}->${cport}" ;;
        esac
    done < <(docker inspect -f '{{range $p, $conf := .HostConfig.PortBindings}}{{range $conf}}{{.HostIp}}|{{.HostPort}}|{{$p}}{{println}}{{end}}{{end}}' "$SITE")
    [ -z "$EXTRA_PORTS" ] || die "${SITE} publishes${EXTRA_PORTS}, which install.sh does not recreate — the rebuild would drop it and whatever depends on it (scrolldaddy's DNS resolvers read its database this way). Carry it deliberately first (spec B8); nothing was changed."
    EXTRA_ENV="$(docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' "$SITE" | cut -d= -f1 \
        | grep -vxE 'PATH|DEBIAN_FRONTEND|SITENAME|DOMAIN_NAME|POSTGRES_PASSWORD|UPGRADE_SERVER|CLONE_FROM|CLONE_KEY|JOINERY_[A-Z_]+|BASE_IMAGE_VERSION|LANG|LC_ALL|TZ' || true)"
    OLD_IMAGE="$(docker inspect -f '{{.Config.Image}}' "$SITE")"

    : > "$STATE"; chmod 600 "$STATE"
    state_set stage prepared
    state_set base "$BASE"; state_set from_major "$HAVE"; state_set to_major "$WANT"
    state_set db "$DB"; state_set encoding "$ENC"; state_set collate "$COLL"; state_set ctype "$CTYPE"
    state_set domain "$DOMAIN"; state_set port "$PORT"; state_set old_image "$OLD_IMAGE"

    save_run_args "${WORK}/run_args"
    docker exec "$SITE" bash -c 'PGPASSWORD="$POSTGRES_PASSWORD" pg_dumpall -U postgres --roles-only' \
        | grep -vE '^(CREATE|ALTER) ROLE postgres[ ;]' > "${WORK}/roles.sql"; chmod 600 "${WORK}/roles.sql"
    hba_extras "$OLD_IMAGE" "$HAVE" > "${WORK}/hba_extra.conf"
    docker diff "$SITE" 2>/dev/null | grep -E ' /etc/(postgresql|cron\.d)|/var/spool/cron' > "${WORK}/layer_changes.txt" || true
    docker exec "$SITE" bash -c "dpkg -l 'php[0-9]*-*' 2>/dev/null | grep '^ii' | tr -s ' ' | cut -d' ' -f2 | sort" > "${WORK}/php_packages.txt" || true
    count_rows "$DB" > "${WORK}/counts.prepare.tsv"

    say "Trial dump of ${DB}..."
    T0=$(date +%s)
    DUMP_BYTES=$(docker exec "$SITE" bash -c 'PGPASSWORD="$POSTGRES_PASSWORD" pg_dump -U postgres -Fc "$1"' _ "$DB" | wc -c)
    T1=$(date +%s)
    PG_BYTES=$(du -sb "$(vol_mp "${SITE}_postgres")" | cut -f1)
    FREE=$(df -B1 --output=avail /root | tail -1 | tr -d ' ')
    NEED=$(( DUMP_BYTES + PG_BYTES + PG_BYTES / 10 ))
    state_set dump_bytes "$DUMP_BYTES"; state_set dump_seconds "$((T1 - T0))"; state_set volume_bytes "$PG_BYTES"

    say "Plan for ${SITE}:"
    echo "  PostgreSQL ${HAVE} -> ${WANT} (${BASE}), database ${DB} (${ENC}, ${COLL})"
    echo "  domain ${DOMAIN:-?}, web port ${PORT}, image ${OLD_IMAGE} kept for rollback"
    echo "  $(wc -l < "${WORK}/counts.prepare.tsv") tables; trial dump $((DUMP_BYTES / 1000000)) MB in $((T1 - T0)) s"
    echo "  roles beyond postgres: $(grep -c '^CREATE ROLE' "${WORK}/roles.sql" || true)"
    echo "  pg_hba lines beyond the image: $(wc -l < "${WORK}/hba_extra.conf")"
    [ -s "${WORK}/hba_extra.conf" ] && sed 's/^/    /' "${WORK}/hba_extra.conf"
    if [ -n "$EXTRA_ENV" ]; then
        echo "  environment install.sh does not set (review; the rebuild drops it):"
        printf '%s\n' "$EXTRA_ENV" | sed 's/^/    /'
    fi
    if [ -s "${WORK}/layer_changes.txt" ]; then
        echo "  changed in the container's own layer (review; pg_hba lines are carried, the rest is not):"
        sed 's/^/    /' "${WORK}/layer_changes.txt"
    fi
    echo "  disk: needs about $((NEED / 1000000)) MB here, $((FREE / 1000000)) MB free"
    [ "$FREE" -gt "$NEED" ] || die "not enough disk on this host for the dump and the rollback copy"
    say "Prepared. Next: $0 ${SITE} swap"
    exit 0
fi

[ -f "$STATE" ] || die "no prepared state for ${SITE} — run prepare first"
DB="$(state_get db)"; FROM="$(state_get from_major)"; TO="$(state_get to_major)"
OLD_IMAGE="$(state_get old_image)"; BACKUP_VOL="${SITE}_postgres_pg${FROM}"
KEEP_IMAGE="joinery-${SITE}:pre-rebase-pg${FROM}"

# ─────────────────────────────────────────────────────────────────────── swap
if [ "$STAGE" = "swap" ]; then
    [ "$(state_get stage)" = "prepared" ] || die "${SITE} is at stage '$(state_get stage)', not prepared"
    [ -f "$INSTALL_SH" ] || die "no install.sh at ${INSTALL_SH}"
    [ "$(db_major)" = "$FROM" ] || die "${SITE}'s database is no longer PostgreSQL ${FROM}; prepare again"
    ! docker volume inspect "$BACKUP_VOL" > /dev/null 2>&1 || die "${BACKUP_VOL} already exists — a previous swap was not finished or rolled back"

    say "Stopping the site's writes (Apache, cron)"
    docker exec "$SITE" bash -c 'service apache2 stop; service cron stop' > /dev/null 2>&1 || true
    count_rows "$DB" > "${WORK}/counts.before.tsv"
    say "Dumping ${DB}"
    docker exec "$SITE" bash -c 'PGPASSWORD="$POSTGRES_PASSWORD" pg_dump -U postgres -Fc --create "$1"' _ "$DB" > "${WORK}/${DB}.dump"
    chmod 600 "${WORK}/${DB}.dump"
    [ -s "${WORK}/${DB}.dump" ] || die "the dump is empty; the site's writes are stopped — restart it with: docker restart ${SITE}"
    docker exec "$SITE" bash -c 'PGPASSWORD="$POSTGRES_PASSWORD" pg_dumpall -U postgres --roles-only' \
        | grep -vE '^(CREATE|ALTER) ROLE postgres[ ;]' > "${WORK}/roles.sql"
    save_run_args "${WORK}/run_args"

    # The agent's identity must outlive the old container. install.sh carries
    # it across its own rebuild, but this removes the container first, so the
    # volume is seeded here the same way.
    if ! docker volume inspect "${SITE}_agent" > /dev/null 2>&1 \
        && docker cp "${SITE}:/etc/joinery-agent" - > /dev/null 2>&1; then
        docker volume create "${SITE}_agent" > /dev/null
        docker cp "${SITE}:/etc/joinery-agent" - | docker run --rm -i -v "${SITE}_agent:/dst" --entrypoint tar "$OLD_IMAGE" -x -p -C /dst --strip-components=1
        say "Carried the agent's identity into ${SITE}_agent"
    fi

    say "Keeping the old image as ${KEEP_IMAGE} and the old database volume as ${BACKUP_VOL}"
    docker tag "$OLD_IMAGE" "$KEEP_IMAGE"
    docker stop "$SITE" > /dev/null
    docker volume create "$BACKUP_VOL" > /dev/null
    cp -a "$(vol_mp "${SITE}_postgres")/." "$(vol_mp "$BACKUP_VOL")/"
    [ "$(find "$(vol_mp "${SITE}_postgres")" -type f | wc -l)" = "$(find "$(vol_mp "$BACKUP_VOL")" -type f | wc -l)" ] \
        || die "the copy of ${SITE}_postgres is incomplete; nothing removed. Roll back with: $0 ${SITE} rollback"
    state_set stage swapping
    docker rm "$SITE" > /dev/null
    docker volume rm "${SITE}_postgres" > /dev/null

    say "Rebuilding ${SITE} with install.sh on $(state_get base)"
    PWFILE="$(mktemp "${WORK}/.pw.XXXXXX")"; chmod 600 "$PWFILE"
    tr '\0' '\n' < "${WORK}/run_args" | sed -n 's/^POSTGRES_PASSWORD=//p' | head -1 > "$PWFILE"
    [ -s "$PWFILE" ] || die "no POSTGRES_PASSWORD in the old container's environment. Roll back with: $0 ${SITE} rollback"
    ( cd "$(dirname "$INSTALL_SH")" && bash "$INSTALL_SH" -y site --docker --password-file="$PWFILE" \
        ${INSTALL_FLAGS[@]+"${INSTALL_FLAGS[@]}"} "$SITE" "$(state_get domain)" "$(state_get port)" ) \
        || { rm -f "$PWFILE"; die "install.sh could not rebuild ${SITE}. Roll back with: $0 ${SITE} rollback"; }
    rm -f "$PWFILE"

    wait_for_postgres || die "PostgreSQL did not start in the rebuilt container. Roll back with: $0 ${SITE} rollback"
    [ "$(db_major)" = "$TO" ] || die "the rebuilt container runs PostgreSQL $(db_major), not ${TO}. Roll back with: $0 ${SITE} rollback"
    docker exec "$SITE" bash -c 'service apache2 stop; service cron stop' > /dev/null 2>&1 || true

    say "Setting the postgres password from the site's environment"
    set_postgres_password || die "could not set the postgres password. Roll back with: $0 ${SITE} rollback"
    say "Restoring roles, then ${DB}"
    psql_in postgres < "${WORK}/roles.sql" > /dev/null \
        || die "the roles did not restore. Roll back with: $0 ${SITE} rollback"
    if printf '%s\n' "SELECT 1 FROM pg_database WHERE datname = '${DB}'" | psql_in postgres | grep -q 1; then
        # The rebuilt container's own first start may have made an empty one.
        printf '%s\n' "DROP DATABASE \"${DB}\";" | psql_in postgres > /dev/null
    fi
    docker exec -i "$SITE" bash -c 'PGPASSWORD="$POSTGRES_PASSWORD" pg_restore -U postgres --create --exit-on-error -d postgres' \
        < "${WORK}/${DB}.dump" || die "the restore failed. Roll back with: $0 ${SITE} rollback"
    docker exec "$SITE" bash -c 'PGPASSWORD="$POSTGRES_PASSWORD" vacuumdb -U postgres --analyze-only -q "$1"' _ "$DB" || true

    if [ -s "${WORK}/hba_extra.conf" ]; then
        say "Carrying $(wc -l < "${WORK}/hba_extra.conf") pg_hba line(s) across"
        docker exec -i "$SITE" bash -c 'cat >> "$(ls -1d /etc/postgresql/*/main/pg_hba.conf | sort -V | tail -1)" && service postgresql reload > /dev/null' \
            < "${WORK}/hba_extra.conf"
    fi

    count_rows "$DB" > "${WORK}/counts.after.tsv"
    if ! diff -q "${WORK}/counts.before.tsv" "${WORK}/counts.after.tsv" > /dev/null; then
        diff "${WORK}/counts.before.tsv" "${WORK}/counts.after.tsv" | head -20 >&2
        die "row counts differ after the restore (above). Roll back with: $0 ${SITE} rollback"
    fi
    say "Row counts match: $(wc -l < "${WORK}/counts.after.tsv") tables"

    say "Restarting ${SITE} with its data in place"
    docker restart "$SITE" > /dev/null
    wait_for_postgres || die "PostgreSQL did not come back after the restart. Roll back with: $0 ${SITE} rollback"
    sleep 5
    CODE="$(curl -s -o /dev/null -w '%{http_code}' -H "Host: $(state_get domain)" "http://127.0.0.1:$(state_get port)/" || true)"
    say "Front page through the container's port: HTTP ${CODE}"
    state_set stage swapped
    say "Swapped. Check the site, its login and admin, its agent on the management node,"
    say "and one backup. Roll back with: $0 ${SITE} rollback   Finish (after a week): $0 ${SITE} finish"
    exit 0
fi

# ─────────────────────────────────────────────────────────────────── rollback
if [ "$STAGE" = "rollback" ]; then
    case "$(state_get stage)" in swapping|swapped) ;; *) die "${SITE} is at stage '$(state_get stage)'; nothing to roll back" ;; esac
    docker volume inspect "$BACKUP_VOL" > /dev/null 2>&1 || die "no ${BACKUP_VOL} to roll back to"
    docker image inspect "$KEEP_IMAGE" > /dev/null 2>&1 || die "no ${KEEP_IMAGE} to roll back to"
    say "Removing the rebuilt container and its database volume"
    docker stop "$SITE" > /dev/null 2>&1 || true
    docker rm "$SITE" > /dev/null 2>&1 || true
    docker volume rm "${SITE}_postgres" > /dev/null 2>&1 || true
    docker volume create "${SITE}_postgres" > /dev/null
    cp -a "$(vol_mp "$BACKUP_VOL")/." "$(vol_mp "${SITE}_postgres")/"
    mapfile -d '' ARGS < "${WORK}/run_args"
    if docker volume inspect "${SITE}_agent" > /dev/null 2>&1 && ! printf '%s\n' "${ARGS[@]}" | grep -q ':/etc/joinery-agent$'; then
        ARGS+=(-v "${SITE}_agent:/etc/joinery-agent")
    fi
    say "Recreating ${SITE} on ${KEEP_IMAGE} with its old arguments"
    docker run -d "${ARGS[@]}" "$KEEP_IMAGE" > /dev/null
    wait_for_postgres || die "PostgreSQL did not start on the old image; the copy is still in ${BACKUP_VOL}"
    [ "$(db_major)" = "$FROM" ] || die "the rolled-back container does not see PostgreSQL ${FROM}; the copy is still in ${BACKUP_VOL}"
    # The data is back in ${SITE}_postgres and running; the copy has done its job,
    # and leaving it would block the next swap.
    docker volume rm "$BACKUP_VOL" > /dev/null
    state_set stage prepared
    say "Rolled back: ${SITE} runs PostgreSQL ${FROM} on ${KEEP_IMAGE} again. Prepare again before another swap."
    exit 0
fi

# ───────────────────────────────────────────────────────────────────── finish
if [ "$STAGE" = "finish" ]; then
    [ "$(state_get stage)" = "swapped" ] || die "${SITE} is at stage '$(state_get stage)', not swapped"
    docker volume rm "$BACKUP_VOL" > /dev/null
    docker rmi "$KEEP_IMAGE" > /dev/null 2>&1 || true
    rm -f "${WORK}/${DB}.dump" "${WORK}/run_args" "${WORK}/roles.sql"
    state_set stage finished
    say "Finished: ${BACKUP_VOL}, ${KEEP_IMAGE} and the dump are gone. ${SITE} runs PostgreSQL ${TO}."
fi
