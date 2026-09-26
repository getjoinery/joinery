#!/usr/bin/env bash
# rebase_site_container.sh — move a Docker site onto a newer base image whose
# PostgreSQL is a newer major version, carrying its database across.
#
# Version: 1.7 - No database access can be declared (specs/dns_resolvers_read_over_https.md WP7):
#                config/postgres_access.conf is not read. prepare refuses any pg_hba line
#                admitting a network address other than the Docker host. A database port
#                published on an address other than 127.0.0.1 is named in the plan as
#                dropped, not refused: with no network pg_hba line nothing can use it, and
#                the rebuild publishes it on 127.0.0.1. Any other hand-made binding still
#                refuses.
# Version: 1.6 - rollback brings back the old container, not just its image (B25). What upgrades
#                and the core installers had added in its own layer was gone: getjoinery's
#                rollback came up without PHP apcu and sqlite3, without its agent and the
#                host converger's cron, and with the image's pg_hba admitting 0.0.0.0/0.
#                Once the old start command has run, rollback installs the PHP extensions
#                the site's code declares plus every PHP package prepare recorded, runs
#                every core installer as a current image's start command does, waits for
#                the agent, and checks the manifest again.
# Version: 1.5 - Two things the rebuild replaced outside the site's volumes, found moving
#                joinerydemo. (1) The domain comes from the host vhost that proxies to the
#                site's web port, not the container's DOMAIN_NAME: joinerydemo's said
#                joinerydemo.site while the host served demo.getjoinery.com, install.sh
#                rendered the host vhost for the stale name, and the site went dark behind
#                its edge (526). getjoinery_orgs's says getjoinery.com, another site's name.
#                prepare refuses a site served under several names and names any other
#                enabled vhost file on its port; swap disables those once install.sh has
#                written <site>.conf. (2) The signed release manifest sits in the
#                container's own layer, not on a volume, and install.sh lays in whatever
#                release its upgrade server serves (0.8.426 over a 0.8.430 tree): the agent
#                then refuses every backup. swap carries the old container's manifest into
#                the new one and stops unless every file matches it; prepare and swap refuse
#                a site whose files already do not. swap keeps the site's host vhost files,
#                and rollback puts them back along with the manifest.
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
# its image, pg_hba lines admitting another machine (none may; a rebuild
# drops them), the old container's exact run arguments
# (for rollback), the name the host's vhost serves the site under (install.sh
# rewrites that vhost for the name it is given), that every file matches the
# site's signed release manifest, and a trial dump's size against the disk
# free here.
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

# The container's pg_hba lines admitting a network address other than the
# Docker host (its gateway) and loopback. A Joinery database answers only its
# own machine, and host_housekeeping.sh strips such lines at every container
# start; one here was added by hand since, for something outside this machine,
# and the rebuild would silently drop it.
hba_network() {  # $1 major
    local f="/etc/postgresql/$1/main/pg_hba.conf" gw
    gw="$(docker inspect -f '{{range .NetworkSettings.Networks}}{{.Gateway}}{{end}}' "$SITE")"
    docker exec "$SITE" cat "$f" \
        | awk -v gw="${gw}/32" '$1 ~ /^host/ && $4 !~ /^(127\.0\.0\.1|::1)(\/|$)/ && $4 != "localhost" && $4 != "samehost" && $4 != gw' \
        | sed -E 's/[[:space:]]+/ /g; s/ $//' | sort -u
}

# The enabled host vhosts that proxy to this site's web port, one path each.
# install.sh renders the host vhost for the name it is given, so the name comes
# from what the host serves, never from the container's DOMAIN_NAME, which can
# be stale or another site's. A host with no Apache, or a site with no vhost,
# has none.
host_vhost_files() {  # $1 web port
    [ -d /etc/apache2/sites-enabled ] || return 0
    grep -lE "^[[:space:]]*ProxyPass[[:space:]]+/[[:space:]]+http://(127\.0\.0\.1|localhost):$1/" \
        /etc/apache2/sites-enabled/*.conf 2>/dev/null || true
}

# The names those vhosts serve, each www. folded into its apex, one per line.
host_domains() {  # vhost files...
    [ "$#" -gt 0 ] || return 0
    grep -hoE '^[[:space:]]*Server(Name|Alias)[[:space:]]+.*' "$@" \
        | awk '{ for (i = 2; i <= NF; i++) print $i }' | sed 's/^www\.//' | sort -u
}

# How many of the site's files do not match its signed release manifest, or
# "missing" when it has none. The agent verifies every script it runs as root
# against this file, so a tree that does not match has its backups refused.
manifest_failures() {
    docker exec "$SITE" bash -c 'cd "/var/www/html/$1" 2>/dev/null && [ -f RELEASE_MANIFEST ] || { echo missing; exit 0; }
        sha256sum -c --quiet RELEASE_MANIFEST 2>/dev/null | grep -c FAILED || true' _ "$SITE"
}

# The manifest lives in the container's own layer, not on a volume, so a
# rebuilt container carries whichever release its image was built from. The
# code volume comes across unchanged, and the manifest that described it before
# still does: put it back, and succeed only when every file matches.
put_manifest() {
    local f
    for f in RELEASE_MANIFEST RELEASE_MANIFEST.sig; do
        docker cp "${WORK}/${f}" "${SITE}:/var/www/html/${SITE}/${f}" || return 1
    done
    docker exec "$SITE" bash -c 'cd "/var/www/html/$1" && chown root:root RELEASE_MANIFEST RELEASE_MANIFEST.sig && chmod 644 RELEASE_MANIFEST RELEASE_MANIFEST.sig' _ "$SITE" || return 1
    [ "$(manifest_failures)" = "0" ]
}

# The host's vhost files for this site, as they are before the rebuild:
# <site>.conf (install.sh rewrites it) and every other enabled file on the
# site's port (swap disables them). rollback puts back exactly this.
save_host_vhosts() {
    local d="${WORK}/host_vhosts" b
    rm -rf "$d"; mkdir -p "${d}/other"
    [ -d /etc/apache2/sites-available ] || return 0
    if [ -f "/etc/apache2/sites-available/${SITE}.conf" ]; then
        cp -p "/etc/apache2/sites-available/${SITE}.conf" "${d}/own.conf"
    fi
    if [ -e "/etc/apache2/sites-enabled/${SITE}.conf" ]; then touch "${d}/own.enabled"; fi
    for b in $(state_get other_vhosts); do
        if [ -L "/etc/apache2/sites-enabled/${b}" ]; then
            readlink "/etc/apache2/sites-enabled/${b}" > "${d}/other/${b}.link"
        else
            cp -p "/etc/apache2/sites-enabled/${b}" "${d}/other/${b}"
        fi
    done
}

# A rolled-back container starts from the old image, and the old container was
# more than its image. Upgrades had installed PHP extensions into its own
# layer, and the core installers (the agent, the host converger's cron,
# housekeeping's local-only pg_hba and PHP tuning) ran after it was created; an
# image that predates them does not run them at start. So they are put back
# the way a new container gets them: the extensions the site's code declares
# (the loop Dockerfile.template and upgrade.php run) plus any other PHP package
# the old container had, then every core installer, as the start command of a
# current image runs them. Returns 1 when the agent is not running afterwards.
restore_runtime() {
    local extra="" i
    if [ -s "${WORK}/php_packages.txt" ]; then
        extra="$(paste -sd' ' "${WORK}/php_packages.txt")"
    fi
    say "Reinstalling the PHP extensions the site declares and the old container had"
    docker exec -i "$SITE" bash -s -- "$SITE" $extra > "${WORK}/rollback_runtime.log" 2>&1 <<'EOS' || return 1
site="$1"; shift
installed=0
apt_ready=0
need() {  # install the first of the given packages when none is installed
    local p
    for p in "$@"; do dpkg -s "$p" > /dev/null 2>&1 && return 0; done
    if [ "$apt_ready" = 0 ]; then apt-get update -qq || return 1; apt_ready=1; fi
    for p in "$@"; do
        DEBIAN_FRONTEND=noninteractive apt-get install -y -qq "$p" > /dev/null 2>&1 && { echo "installed $p"; installed=1; return 0; }
    done
    echo "WARNING: could not install any of: $*"
}
for spec in $(php "/var/www/html/${site}/public_html/utils/list_dependencies.php" --apt 2>/dev/null); do
    need "${spec%%|*}" "${spec##*|}"
done
for p in "$@"; do need "$p"; done
if [ "$installed" = 1 ]; then
    for s in /etc/init.d/php*-fpm; do [ -e "$s" ] && service "$(basename "$s")" restart; done
fi
true
EOS
    sed 's/^/  /' "${WORK}/rollback_runtime.log"
    say "Running the core installers (the agent, the host converger, housekeeping)"
    docker exec "$SITE" bash "/var/www/html/${SITE}/maintenance_scripts/install_tools/_plugin_installers_start.sh" "$SITE" \
        > "${WORK}/rollback_installers.log" 2>&1 || true
    grep -E '^core installers: .*: (ok|FAILED|failed)' "${WORK}/rollback_installers.log" | sed 's/^/  /' || true
    # The agent is started by its cron supervisor, once a minute.
    for i in $(seq 1 45); do
        docker exec "$SITE" pgrep -x joinery-agent > /dev/null 2>&1 && return 0
        sleep 2
    done
    return 1
}

restore_host_vhosts() {
    local d="${WORK}/host_vhosts" f b
    [ -d "$d" ] || return 0
    if [ -f "${d}/own.conf" ]; then
        cp -p "${d}/own.conf" "/etc/apache2/sites-available/${SITE}.conf"
    else
        rm -f "/etc/apache2/sites-available/${SITE}.conf"
    fi
    if [ -f "${d}/own.enabled" ] && [ -f "/etc/apache2/sites-available/${SITE}.conf" ]; then
        ln -sfn "../sites-available/${SITE}.conf" "/etc/apache2/sites-enabled/${SITE}.conf"
    else
        rm -f "/etc/apache2/sites-enabled/${SITE}.conf"
    fi
    for f in "${d}/other/"*; do
        [ -e "$f" ] || continue
        b="$(basename "$f")"
        case "$b" in
            *.link) ln -sfn "$(cat "$f")" "/etc/apache2/sites-enabled/${b%.link}" ;;
            *) cp -p "$f" "/etc/apache2/sites-enabled/${b}" ;;
        esac
    done
    apache2ctl -t > /dev/null 2>&1 && apache2ctl graceful
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

    # The name install.sh renders the host vhost for is the one the host serves
    # the site under now; the container's DOMAIN_NAME is only the fallback for
    # a site with no host vhost.
    ENV_DOMAIN="$DOMAIN"
    mapfile -t HOST_VHOSTS < <(host_vhost_files "$PORT")
    mapfile -t HOST_NAMES < <(host_domains ${HOST_VHOSTS[@]+"${HOST_VHOSTS[@]}"})
    [ "${#HOST_NAMES[@]}" -le 1 ] || die "the host serves ${SITE} (port ${PORT}) under several names: ${HOST_NAMES[*]}. install.sh renders a vhost for one; nothing was changed"
    [ "${#HOST_NAMES[@]}" -eq 0 ] || DOMAIN="${HOST_NAMES[0]}"
    OTHER_VHOSTS=""
    for f in ${HOST_VHOSTS[@]+"${HOST_VHOSTS[@]}"}; do
        [ "$(basename "$f")" = "${SITE}.conf" ] || OTHER_VHOSTS="${OTHER_VHOSTS:+${OTHER_VHOSTS} }$(basename "$f")"
    done

    MF_FAILED="$(manifest_failures)"
    [ "$MF_FAILED" = "0" ] || die "${SITE}'s files do not match its signed release manifest (${MF_FAILED}). Its agent refuses backups like this, and the move would carry it across. Apply the site's update first; nothing was changed"

    # install.sh recreates exactly two bindings: the web port (127.0.0.1 behind
    # the host proxy, every interface for a site with no domain) and the
    # database port (web + 1000) on 127.0.0.1. The database port on any other
    # address is named and dropped: no pg_hba line admits another machine, so
    # nothing can use it. Anything else was added by hand for something outside
    # this machine, and the rebuild would silently drop it.
    EXTRA_PORTS=""
    DROPPED_DB=""
    while IFS='|' read -r hip hport cport; do
        [ -z "$cport" ] && continue
        case "${cport%%/*}:${hip}:${hport}" in
            "80::${PORT}"|"80:0.0.0.0:${PORT}"|"80:127.0.0.1:${PORT}") ;;
            "5432:127.0.0.1:$((PORT + 1000))") ;;
            "5432:"*":$((PORT + 1000))") DROPPED_DB="${DROPPED_DB:+${DROPPED_DB} }${hip:-0.0.0.0}:${hport}" ;;
            *) EXTRA_PORTS="${EXTRA_PORTS} ${hip:-0.0.0.0}:${hport}->${cport}" ;;
        esac
    done < <(docker inspect -f '{{range $p, $conf := .HostConfig.PortBindings}}{{range $conf}}{{.HostIp}}|{{.HostPort}}|{{$p}}{{println}}{{end}}{{end}}' "$SITE")
    NETWORK_HBA="$(hba_network "$HAVE")"
    [ -z "$NETWORK_HBA" ] || die "${SITE}'s pg_hba admits from the network: $(printf '%s\n' "$NETWORK_HBA" | paste -sd ';' - | sed 's/;/; /g'). A Joinery database answers only its own machine and the rebuild drops these; find what uses them and remove them first. Nothing was changed."
    [ -z "$EXTRA_PORTS" ] || die "${SITE} publishes${EXTRA_PORTS}, which install.sh does not recreate — the rebuild would drop it and whatever depends on it. Nothing was changed."
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
    state_set other_vhosts "$OTHER_VHOSTS"

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
    if [ -n "$ENV_DOMAIN" ] && [ "$ENV_DOMAIN" != "$DOMAIN" ]; then
        echo "  the host serves ${DOMAIN}; the container's DOMAIN_NAME says ${ENV_DOMAIN}. The rebuild uses ${DOMAIN}"
    fi
    if [ "${#HOST_NAMES[@]}" -eq 0 ]; then
        echo "  no host vhost proxies to port ${PORT}; the domain is the container's DOMAIN_NAME"
    elif [ -f "/etc/letsencrypt/live/${DOMAIN}/fullchain.pem" ]; then
        echo "  certificate: the Let's Encrypt lineage for ${DOMAIN}"
    else
        echo "  certificate: none for ${DOMAIN} (install.sh answers with a placeholder until one is issued)"
    fi
    if [ -n "$OTHER_VHOSTS" ]; then
        echo "  also serving port ${PORT}: ${OTHER_VHOSTS}. swap disables them once install.sh has written ${SITE}.conf; rollback enables them again"
    fi
    echo "  every file matches the signed release manifest"
    echo "  $(wc -l < "${WORK}/counts.prepare.tsv") tables; trial dump $(awk -v b="$DUMP_BYTES" 'BEGIN { printf "%.1f", b / 1000000 }') MB in $((T1 - T0)) s"
    echo "  roles beyond postgres: $(grep -c '^CREATE ROLE' "${WORK}/roles.sql" || true)"
    if [ -n "$DROPPED_DB" ]; then
        echo "  database port published on ${DROPPED_DB}: dropped. The rebuild publishes it on 127.0.0.1 only; no pg_hba line admits another machine"
    else
        echo "  database published on 127.0.0.1"
    fi
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
    MF_FAILED="$(manifest_failures)"
    [ "$MF_FAILED" = "0" ] || die "${SITE}'s files do not match its signed release manifest (${MF_FAILED}); apply its update, then prepare again. Nothing was changed"

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
    for f in RELEASE_MANIFEST RELEASE_MANIFEST.sig; do
        docker cp "${SITE}:/var/www/html/${SITE}/${f}" "${WORK}/${f}" \
            || die "could not keep ${SITE}'s ${f}; nothing was moved. Restart it with: docker restart ${SITE}"
    done
    save_host_vhosts

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
    put_manifest || die "the site's files do not match the manifest carried from the old container. Roll back with: $0 ${SITE} rollback"
    say "Carried the signed release manifest across; every file matches it"
    OTHER="$(state_get other_vhosts)"
    if [ -n "$OTHER" ]; then
        for b in $OTHER; do rm -f "/etc/apache2/sites-enabled/${b}"; done
        apache2ctl -t > /dev/null 2>&1 && apache2ctl graceful \
            || die "Apache refuses its configuration without ${OTHER}. Roll back with: $0 ${SITE} rollback"
        say "Disabled ${OTHER}: ${SITE}.conf serves ${SITE} now (the files are kept for rollback)"
    fi
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
    MF_FAILED="$(manifest_failures)"
    [ "$MF_FAILED" = "0" ] || say "WARNING: after the restart, ${MF_FAILED} file(s) do not match the signed release manifest; the agent will refuse backups. Roll back with: $0 ${SITE} rollback"
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
    # The old image's own layer carries the manifest it was built with, not the
    # one that matched the code volume when the swap began.
    if [ -f "${WORK}/RELEASE_MANIFEST" ]; then
        put_manifest || say "WARNING: the site's files do not match the manifest kept from before the swap; its agent refuses backups until the site's update is applied again"
    fi
    restore_host_vhosts || say "WARNING: Apache refused the restored vhosts; the copies are in ${WORK}/host_vhosts"
    # The data is back in ${SITE}_postgres and running; the copy has done its job,
    # and leaving it would block the next swap.
    docker volume rm "$BACKUP_VOL" > /dev/null
    state_set stage rolled_back
    # The old start command runs to its end (Apache) before anything is added.
    say "Front page through the container's port: HTTP $(wait_for_site)"
    restore_runtime || say "WARNING: ${SITE}'s agent is not running after the core installers; see ${WORK}/rollback_installers.log"
    MF_FAILED="$(manifest_failures)"
    [ "$MF_FAILED" = "0" ] || say "WARNING: ${MF_FAILED} file(s) do not match the signed release manifest; the agent will refuse backups"
    say "Front page after the installers: HTTP $(wait_for_site)"
    say "Rolled back: ${SITE} runs PostgreSQL ${FROM} on ${KEEP_IMAGE} again. Prepare again before another swap."
    exit 0
fi

# ───────────────────────────────────────────────────────────────────── finish
if [ "$STAGE" = "finish" ]; then
    [ "$(state_get stage)" = "swapped" ] || die "${SITE} is at stage '$(state_get stage)', not swapped"
    docker volume rm "$BACKUP_VOL" > /dev/null
    docker rmi "$KEEP_IMAGE" > /dev/null 2>&1 || true
    rm -f "${WORK}/${DB}.dump" "${WORK}/run_args" "${WORK}/roles.sql" "${WORK}/RELEASE_MANIFEST" "${WORK}/RELEASE_MANIFEST.sig"
    rm -rf "${WORK}/host_vhosts"
    state_set stage finished
    say "Finished: ${BACKUP_VOL}, ${KEEP_IMAGE} and the dump are gone. ${SITE} runs PostgreSQL ${TO}."
fi
