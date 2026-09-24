#!/usr/bin/env bash
# rebase_site_container.sh — move a Docker site onto a newer base image whose
# PostgreSQL is a newer major version, carrying its database across.
#
# Version: 1.4 - The old image is kept by its id, not its name. install.sh rebuilds under the
#                same name (joinery-<site>), so after one swap the name meant the new image;
#                a second swap then tagged that as the rollback image, and rollback started
#                PostgreSQL 16 data on an 18 image (found in rehearsal R1). rollback ends at
#                'rolled_back', so another swap needs a fresh prepare; prepare refuses while
#                a move is in flight; swap refuses a container whose image changed since
#                prepare, or a rollback tag that already names another image. rollback hands
#                PostgreSQL's log directory back to the old image's postgres user first: the
#                new image's start command gave it to its own, whose ids differ.
# Version: 1.3 - Writes stop without stopping the container. Apache is the container's main
#                process, so "service apache2 stop" ended the container, its restart
#                policy started it again, and swap died on the next command (found in
#                rehearsal R1). stop_site_writes stops PHP-FPM, cron, the agent and
#                Postfix instead, and refuses to go on unless the container is still
#                up with no PHP-FPM left. A command that fails outside a die names its line
#                (it ended the run with no message). swap and rollback end by waiting up to
#                three minutes for the site to answer: a container spends its first minute
#                or two running its installers, and the front page read 000 (swap) or 502
#                (rollback) while it did, and a prepare run then saw the image's own
#                pg_hba before housekeeping had replaced it.
# Version: 1.2 - The web port on 127.0.0.1 (install.sh 2.82 publishes a proxied site there) and a
#                database port on 127.0.0.1 or on the address config/postgres_access.conf
#                declares are bindings install.sh recreates, so none is refused. pg_hba lines are no
#                longer carried across: host_housekeeping.sh rebuilds pg_hba from that
#                file at every container start, so prepare names any network line the
#                file does not declare, which a rebuild drops. The trial dump's size is
#                shown to a tenth of a MB; a small site read as "0 MB".
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
# the locale), every table's row count, the roles the container carries beyond
# its image, pg_hba lines its config/postgres_access.conf does not declare (a
# rebuild drops them), the old container's exact run arguments
# (for rollback), and a trial dump's size against the disk free here.
#
# swap stops the site's writes, dumps the database, keeps a copy of the old
# database volume and the old image, rebuilds the container with install.sh
# on a fresh database volume, sets the postgres password from the site's own
# environment, restores roles and database, and restarts the site
# with its data in place. It then compares every table's row count with the
# count taken after writes stopped, and prints the rollback command on any
# difference.
#
# Nothing here ever prints a password. The database password is read inside
# the container from its own environment, so it is never on a host command line.

set -euo pipefail
# A command that fails outside a die names itself instead of ending the run silently.
trap 'echo "FATAL: line ${LINENO} failed (exit $?); the stage recorded in /root/rebase/<site>/state says where the move stands" >&2' ERR

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
hba_undeclared() {  # $1 major, $2 the site's postgres_access.conf
    # The container's pg_hba lines admitting a network address, other than the
    # Docker host, that the site's config/postgres_access.conf does not
    # declare. host_housekeeping.sh rebuilds pg_hba from that file at every
    # container start, so a rebuild keeps none of these.
    local f="/etc/postgresql/$1/main/pg_hba.conf" gw
    gw="$(docker inspect -f '{{range .NetworkSettings.Networks}}{{.Gateway}}{{end}}' "$SITE")"
    comm -23 \
        <(docker exec "$SITE" cat "$f" \
            | awk -v gw="${gw}/32" '$1 ~ /^host/ && $4 !~ /^(127\.0\.0\.1|::1)(\/|$)/ && $4 != "localhost" && $4 != "samehost" && $4 != gw' \
            | sed -E 's/[[:space:]]+/ /g; s/ $//' | sort -u) \
        <({ [ -f "$2" ] && grep -E '^[[:space:]]*host' "$2"; } | sed -E 's/^[[:space:]]+//; s/[[:space:]]+/ /g; s/ $//' | sort -u)
}

# Stop everything in the container that writes to the site's database, and
# leave the container running: Apache is its main process, so stopping Apache
# ends the container, and the restart policy starts it again with every writer
# back. PHP-FPM (every web request), cron (the site's tasks, and the agent's
# supervisor), the agent, and Postfix (inbound mail is delivered into the
# database) are what write. With PHP-FPM down, Apache answers 503.
stop_site_writes() {
    local started
    started="$(docker inspect -f '{{.State.StartedAt}}' "$SITE")"
    docker exec "$SITE" bash -c '
        for s in /etc/init.d/php*-fpm; do [ -e "$s" ] && service "$(basename "$s")" stop; done
        service cron stop; service postfix stop; pkill -x joinery-agent; true' > /dev/null 2>&1 || true
    if [ "$(docker inspect -f '{{.State.Running}} {{.State.StartedAt}}' "$SITE")" != "true ${started}" ]; then
        echo "${SITE} stopped or restarted while its writes were being stopped" >&2; return 1
    fi
    if docker exec "$SITE" pgrep -f '^php-fpm' > /dev/null 2>&1; then
        echo "PHP-FPM is still running in ${SITE}" >&2; return 1
    fi
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

# The front page's status once the container has finished starting: it runs
# its installers before Apache serves PHP, which takes a minute or two, and
# answers 000, 502 or 503 until then. Prints the last code seen.
wait_for_site() {
    local i code=000
    for i in $(seq 1 36); do
        code="$(curl -s -o /dev/null -m 10 -w '%{http_code}' -H "Host: $(state_get domain)" "http://127.0.0.1:$(state_get port)/" || true)"
        case "$code" in 000|502|503) sleep 5 ;; *) break ;; esac
    done
    echo "$code"
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
    case "$(state_get stage)" in
        swapping|swapped) die "${SITE} is at stage '$(state_get stage)': a move is in flight. Roll it back, or finish it, first" ;;
    esac

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

    # install.sh recreates exactly two bindings: the web port (127.0.0.1 behind
    # the host proxy, every interface for a site with no domain) and the
    # database port (web + 1000) on 127.0.0.1, or on the address the site's
    # config/postgres_access.conf publishes it on. Anything else was added by
    # hand for something outside this machine, and the rebuild would silently
    # drop it.
    ACCESS_FILE="$(vol_mp "${SITE}_config")/postgres_access.conf"
    DB_PUBLISH="$(awk '$1 == "publish" { print $2; exit }' "$ACCESS_FILE" 2>/dev/null || true)"
    DB_PUBLISH="${DB_PUBLISH:-127.0.0.1}"
    EXTRA_PORTS=""
    while IFS='|' read -r hip hport cport; do
        [ -z "$cport" ] && continue
        case "${cport%%/*}:${hip}:${hport}" in
            "80::${PORT}"|"80:0.0.0.0:${PORT}"|"80:127.0.0.1:${PORT}") ;;
            "5432:127.0.0.1:$((PORT + 1000))"|"5432:${DB_PUBLISH}:$((PORT + 1000))") ;;
            *) EXTRA_PORTS="${EXTRA_PORTS} ${hip:-0.0.0.0}:${hport}->${cport}" ;;
        esac
    done < <(docker inspect -f '{{range $p, $conf := .HostConfig.PortBindings}}{{range $conf}}{{.HostIp}}|{{.HostPort}}|{{$p}}{{println}}{{end}}{{end}}' "$SITE")
    UNDECLARED_HBA="$(hba_undeclared "$HAVE" "$ACCESS_FILE")"
    [ -z "$UNDECLARED_HBA" ] || die "${SITE}'s pg_hba admits from the network: $(printf '%s\n' "$UNDECLARED_HBA" | paste -sd ';' - | sed 's/;/; /g'). Its config/postgres_access.conf does not declare these, so the rebuild drops them. Declare the ones still needed, and remove the rest; nothing was changed."
    [ -z "$EXTRA_PORTS" ] || die "${SITE} publishes${EXTRA_PORTS}, which install.sh does not recreate — the rebuild would drop it and whatever depends on it. A database read from another machine is declared with a publish line in config/postgres_access.conf; nothing was changed."
    EXTRA_ENV="$(docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' "$SITE" | cut -d= -f1 \
        | grep -vxE 'PATH|DEBIAN_FRONTEND|SITENAME|DOMAIN_NAME|POSTGRES_PASSWORD|UPGRADE_SERVER|CLONE_FROM|CLONE_KEY|JOINERY_[A-Z_]+|BASE_IMAGE_VERSION|LANG|LC_ALL|TZ' || true)"
    # By id: install.sh rebuilds under the same name, so the name stops meaning
    # this image the moment the swap rebuilds.
    OLD_IMAGE="$(docker inspect -f '{{.Image}}' "$SITE")"

    : > "$STATE"; chmod 600 "$STATE"
    state_set stage prepared
    state_set base "$BASE"; state_set from_major "$HAVE"; state_set to_major "$WANT"
    state_set db "$DB"; state_set encoding "$ENC"; state_set collate "$COLL"; state_set ctype "$CTYPE"
    state_set domain "$DOMAIN"; state_set port "$PORT"; state_set old_image "$OLD_IMAGE"

    save_run_args "${WORK}/run_args"
    docker exec "$SITE" bash -c 'PGPASSWORD="$POSTGRES_PASSWORD" pg_dumpall -U postgres --roles-only' \
        | grep -vE '^(CREATE|ALTER) ROLE postgres[ ;]' > "${WORK}/roles.sql"; chmod 600 "${WORK}/roles.sql"
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
    echo "  domain ${DOMAIN:-?}, web port ${PORT}, image $(docker inspect -f '{{.Config.Image}}' "$SITE") (${OLD_IMAGE:7:12}) kept for rollback"
    echo "  $(wc -l < "${WORK}/counts.prepare.tsv") tables; trial dump $(awk -v b="$DUMP_BYTES" 'BEGIN { printf "%.1f", b / 1000000 }') MB in $((T1 - T0)) s"
    echo "  roles beyond postgres: $(grep -c '^CREATE ROLE' "${WORK}/roles.sql" || true)"
    DECLARED_HBA="$(grep -cE '^[[:space:]]*host' "$ACCESS_FILE" 2>/dev/null || true)"
    echo "  database published on ${DB_PUBLISH}; ${DECLARED_HBA:-0} pg_hba line(s) declared in config/postgres_access.conf"
    if [ -n "$EXTRA_ENV" ]; then
        echo "  environment install.sh does not set (review; the rebuild drops it):"
        printf '%s\n' "$EXTRA_ENV" | sed 's/^/    /'
    fi
    if [ -s "${WORK}/layer_changes.txt" ]; then
        echo "  changed in the container's own layer (review; the rebuild carries none of it):"
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
    [ "$(docker inspect -f '{{.Image}}' "$SITE")" = "$OLD_IMAGE" ] || die "${SITE} runs another image than it did at prepare; prepare again"
    KEPT="$(docker image inspect -f '{{.Id}}' "$KEEP_IMAGE" 2>/dev/null || true)"
    [ -z "$KEPT" ] || [ "$KEPT" = "$OLD_IMAGE" ] || die "${KEEP_IMAGE} already names another image (${KEPT:7:12}); it may be the only copy of an earlier rollback image. Nothing was changed"
    ! docker volume inspect "$BACKUP_VOL" > /dev/null 2>&1 || die "${BACKUP_VOL} already exists — a previous swap was not finished or rolled back"

    say "Stopping the site's writes (PHP-FPM, cron, the agent, Postfix)"
    stop_site_writes || die "the site's writes could not be stopped; nothing was moved. Restart it with: docker restart ${SITE}"
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
    stop_site_writes || die "the rebuilt site's writes could not be stopped. Roll back with: $0 ${SITE} rollback"

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

    count_rows "$DB" > "${WORK}/counts.after.tsv"
    if ! diff -q "${WORK}/counts.before.tsv" "${WORK}/counts.after.tsv" > /dev/null; then
        diff "${WORK}/counts.before.tsv" "${WORK}/counts.after.tsv" | head -20 >&2
        die "row counts differ after the restore (above). Roll back with: $0 ${SITE} rollback"
    fi
    say "Row counts match: $(wc -l < "${WORK}/counts.after.tsv") tables"

    say "Restarting ${SITE} with its data in place"
    docker restart "$SITE" > /dev/null
    wait_for_postgres || die "PostgreSQL did not come back after the restart. Roll back with: $0 ${SITE} rollback"
    CODE="$(wait_for_site)"
    say "Front page through the container's port: HTTP ${CODE}"
    case "$CODE" in 000|5*) say "WARNING: the site is not answering. Roll back with: $0 ${SITE} rollback" ;; esac
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
    # The new image's start command handed PostgreSQL's log directory to its own
    # postgres user; the old image's has other ids and predates that handoff, so
    # its server could not write its log and would not start. Hand it back.
    LOG_VOL="$(tr '\0' '\n' < "${WORK}/run_args" | sed -n 's#^\([^:]*\):/var/log/postgresql$#\1#p' | head -1)"
    if [ -n "$LOG_VOL" ]; then
        OLD_UID="$(docker run --rm --entrypoint id "$KEEP_IMAGE" -u postgres)"
        OLD_GID="$(docker run --rm --entrypoint id "$KEEP_IMAGE" -g postgres)"
        chown "0:${OLD_GID}" "$(vol_mp "$LOG_VOL")" && chmod 1775 "$(vol_mp "$LOG_VOL")"
        find "$(vol_mp "$LOG_VOL")" -maxdepth 1 -type f -name '*.log' -exec chown "${OLD_UID}:4" {} +
    fi
    say "Recreating ${SITE} on ${KEEP_IMAGE} with its old arguments"
    docker run -d "${ARGS[@]}" "$KEEP_IMAGE" > /dev/null
    wait_for_postgres || die "PostgreSQL did not start on the old image; the copy is still in ${BACKUP_VOL}"
    [ "$(db_major)" = "$FROM" ] || die "the rolled-back container does not see PostgreSQL ${FROM}; the copy is still in ${BACKUP_VOL}"
    # The data is back in ${SITE}_postgres and running; the copy has done its job,
    # and leaving it would block the next swap.
    docker volume rm "$BACKUP_VOL" > /dev/null
    state_set stage rolled_back
    say "Front page through the container's port: HTTP $(wait_for_site)"
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
