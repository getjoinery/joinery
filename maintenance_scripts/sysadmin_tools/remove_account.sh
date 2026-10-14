#!/usr/bin/env bash
#VERSION 2.17 - site_quiet_host.sh's record of a quiet site ('quiet') goes with the site's other marks.
#               A certificate retry armed for any name the site's vhost served (arm_ssl_retry.sh)
#               is stopped with it: it kept waiting for a name whose site was gone, and would have
#               issued for it had the name reached the machine again (B15, se1 2026-10-14).
#VERSION 2.16 - reviewer2 R4, R8, S1: on the site container engine the site's id block is claimed
#               (sites/<site>/idblock) before anything of it is removed, and the claim stays until a
#               removal verifies it gone,
#               so no new site is given ids that own them; REMOVE_ACCOUNT_DATA_ROOT is a test's seam,
#               read only under REMOVE_ACCOUNT_ROOT.
#VERSION 2.15 - A site on the site container engine (specs/site_container_engine.md WP3) is removed
#               through the engine this host's sites run on (install_tools/_site_engine.sh), its
#               image read as install.sh named it (Podman reads it back as localhost/joinery-...).
#               With no daemon to answer, the engine sees only what its storage on the data root
#               holds, so a host whose data root is not mounted is refused, nothing removed.
#VERSION 2.14 - A site on a host with restrictions (install.sh docker --multi-tenant) mounts them
#               read-only (_site_run_spec.sh 1.9), and that bind made every such site 'not one
#               install.sh made': none could be removed. That one mount, exactly, is accepted.
#VERSION 2.13 - A site's backups and deploy projects are joinery_<site>.backups and .deploy
#               (docker_disk_pool.sh 2.4): removing site a took joinery_a_deploy, which is also
#               the project of a site named a_deploy. Run from a folder with no install_tools
#               beside it, the removal reaches its warning that joinery_data_root.sh is missing;
#               finding that folder ended it silently, halfway, under set -e.
#VERSION 2.12 - A bare-metal site on a data root (specs/one_data_root.md WP2): its folders are bind
#               mounts of /srv/joinery/sites/<site>, so before its directory goes,
#               joinery_data_root.sh remove-site takes those mounts away (the target first, so no
#               service stops) and removes its data there, and its own test site's too. rm -rf over
#               live mounts emptied them and then stopped the removal at the first mountpoint
#               (reviewer2 F2).
#VERSION 2.11 - A site's disk allowance goes with it (docker_disk_pool.sh, specs/
#               multi_tenant_docker_hosts.md WP4): its projects leave /etc/projid and
#               /etc/projects, and its disk_allowance mark goes; its volumes include deploy
#VERSION 2.10 - Touches nothing of another site: volumes are its own fifteen by exact name
#               (install.sh's ALL_SITE_VOLUMES) plus a rebase's kept database, never every volume
#               starting with its name (removing getjoinery reached for getjoinery_developers_*
#               and getjoinery_orgs_*); docker stop/rm only a container of exactly its name; its
#               <site>_test twin and <site>-le-ssl vhost only when no site of that name exists,
#               and no test twin or host database on a Docker host; the name is checked; Docker
#               installed but not answering refuses before anything is removed; held, suspended
#               and run_spec marks cleared on every path; a rebase's images and work dir go too;
#               REMOVE_ACCOUNT_ROOT runs it under a scratch root for its gate. A mark that is
#               not a plain file (a quiet bare-metal site's held/ folder) is left alone; a Docker
#               the PATH cannot find, or one that hangs, refuses too; a host whose vhost is a
#               proxy is treated as a Docker host even after its container is gone. A container
#               install.sh did not make (another image, a bind mount, any volume not one of the
#               site's own) is refused before anything is removed
#VERSION 2.9 - A site's suspended page mark (suspended_page.sh) goes with it
#VERSION 2.8 - The host's outbound limits stop naming the removed site (joinery-limits unit,
#              specs/node_outbound_and_transfer.md WP3)
#VERSION 2.7 - A switch-over's old container held stopped (hold_container.sh) loses its hold mark
#              with its run spec
#VERSION 2.5 - The site's own network goes with its container, and a network left alone counts as
#              a docker site still to remove (specs/node_outbound_and_transfer.md WP2)
#VERSION 2.4 - The site's HTTPS certificates go with its vhost: every Let's Encrypt lineage and
#              placeholder the vhost named is removed by the sibling remove_site_certificate.sh
#              once nothing on the machine uses it, so certbot stops renewing a domain that left.
#              The site's certbot-made <site>-le-ssl.conf and its vhost backups
#              (<site>.conf.<suffix>) go with its vhost
#VERSION 2.3 - The site's run spec on the Docker host goes with its container (specs/
#              multi_tenant_docker_hosts.md WP0)
#VERSION 2.2 - Self-verifying: after removal the script re-probes (container, volumes, vhost,
#              web root) and emits DECOMMISSION_VERIFIED or DECOMMISSION_FAILED_VERIFY (exit 1),
#              so one run carries its own verdict instead of needing a separate verify step.
#              Orphaned volumes count as a docker site during detection, so a re-run after a
#              failed volume removal removes them instead of reporting nothing to do
#VERSION 2.1 - Machine-readable terminal markers (REMOVE_ACCOUNT_OK / REMOVE_ACCOUNT_NOTHING);
#              nothing-to-remove is now idempotent success (exit 0) so re-runs are safe as a job step
#VERSION 2.0 - Added Docker support
#Usage:  ./remove_account.sh site_name [-y]
#
# Removes a Joinery site completely, whether Docker or bare-metal.
# Automatically detects the deployment type.
#
# Options:
#   -y, --yes    Skip confirmation prompt

set -e

# =============================================================================
# ARGUMENT PARSING
# =============================================================================

# Every path below sits under FS. Empty on a real run; remove_account_gate runs
# the whole script under a scratch root with stub commands, without root.
FS="${REMOVE_ACCOUNT_ROOT:-}"

if [ -z "$FS" ] && [ "$EUID" -ne 0 ]; then
    echo "ERROR: You must run as sudo or root."
    exit 1
fi

SITE_NAME=""
ASSUME_YES=false

while [[ $# -gt 0 ]]; do
    case $1 in
        -y|--yes)
            ASSUME_YES=true
            shift
            ;;
        -*)
            echo "Unknown option: $1"
            exit 1
            ;;
        *)
            if [ -z "$SITE_NAME" ]; then
                SITE_NAME="$1"
            else
                echo "ERROR: Unexpected argument: $1"
                exit 1
            fi
            shift
            ;;
    esac
done

if [ -z "$SITE_NAME" ]; then
    echo "Usage:  sudo ./remove_account.sh site_name [-y]"
    echo "ERROR: You must pass the site name to remove as the first argument."
    exit 1
fi

# The shape install.sh gives a site name. Every path and name below is composed
# from it, so anything else ('..', '/', '.') is refused before one is built.
if ! [[ "$SITE_NAME" =~ ^[a-z0-9][a-z0-9_-]{0,49}$ ]]; then
    echo "ERROR: '$SITE_NAME' is not a site name (lowercase letters, digits, _ and -, at most 50)."
    exit 1
fi

# =============================================================================
# DETECT DEPLOYMENT TYPE
# =============================================================================

# The engine this host's sites run on, from install_tools/_site_engine.sh beside
# this script's tree. Without the helper, Docker is the engine, as the helper
# itself answers, except on a machine with the site container engine installed,
# where nothing is removed.
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [ -f "$HERE/../install_tools/_site_engine.sh" ]; then
    . "$HERE/../install_tools/_site_engine.sh"
elif [ -L "${SITE_ENGINE_ROOT:-${JOINERY_SITE_ENGINE_ROOT:-}}/usr/local/lib/joinery-site-engine/current" ] || [ -e "${SITE_ENGINE_ROOT:-${JOINERY_SITE_ENGINE_ROOT:-}}/usr/local/lib/joinery-site-engine/current" ]; then
    echo "ERROR: this machine's sites run on the site container engine, and there is no install_tools/_site_engine.sh beside this script. Nothing was removed."
    exit 1
else
    site_engine_kind() { echo docker; }
    site_engine_bin() { echo docker; }
    site_engine_is_podman() { return 1; }
    site_engine_image() { printf '%s\n' "${1#localhost/}"; }
fi
ENGINE="$(site_engine_bin)" || { echo "ERROR: this machine's container engine could not be named. Nothing was removed."; exit 1; }

WEB_DIR="$FS/var/www/html"
APACHE_SITES="$FS/etc/apache2/sites-available"
SITES_STATE="$FS/etc/joinery/sites"
# On the site container engine the site's files are owned by its own id block
# (its run spec's idblock=, or an install's claim). Read before anything goes:
# the block stays taken while any of them is left (VERIFY GONE).
SITE_IDBLOCK=""
if site_engine_is_podman; then
    SITE_IDBLOCK="$(sed -n 's/^idblock=\([0-9]\{1,5\}\)$/\1/p' "$SITES_STATE/$SITE_NAME/run_spec" 2>/dev/null | tail -n 1)"
    [ -n "$SITE_IDBLOCK" ] || SITE_IDBLOCK="$(grep -x -E '[0-9]{1,5}' "$SITES_STATE/$SITE_NAME/idblock" 2>/dev/null | head -n 1)"
fi

SITE_ROOT="$WEB_DIR/$SITE_NAME"
TEST_SITE_ROOT="$WEB_DIR/${SITE_NAME}_test"
VIRTUALHOST_FILE="$APACHE_SITES/$SITE_NAME.conf"
# The SSL vhost certbot's Apache installer wrote beside it on older installs.
LE_SSL_FILE="$APACHE_SITES/$SITE_NAME-le-ssl.conf"

IS_DOCKER=false
IS_BAREMETAL=false

# The engine is used only when it answers. A Docker daemon that is installed and
# meant to run but does not answer hides every container and volume, and the
# run would remove the proxy and verify a site gone that is still there. The
# site container engine has no daemon: it reads its storage straight off the
# data root, so one not mounted hides them the same way.
DOCKER=false
if site_engine_is_podman; then
    # The seam is a test's, honoured only under its scratch root (REMOVE_ACCOUNT_ROOT).
    DATA_ROOT_MNT="$FS/srv/joinery"
    [ -n "$FS" ] && DATA_ROOT_MNT="${REMOVE_ACCOUNT_DATA_ROOT:-$DATA_ROOT_MNT}"
    if ! mountpoint -q "$DATA_ROOT_MNT" 2>/dev/null; then
        echo "ERROR: this machine's sites run on the site container engine, whose storage is on the data root"
        echo "(/srv/joinery), and the data root is not mounted, so this site's container and volumes cannot be"
        echo "seen. Nothing was removed; mount it (joinery_data_root.sh check says why) and run this again."
        exit 1
    fi
    if ! timeout 30 "$ENGINE" info > /dev/null 2>&1; then
        echo "ERROR: the site container engine is installed here but does not answer, so this site's container"
        echo "and volumes cannot be seen. Nothing was removed; run install_tools/install_site_engine.sh converge."
        exit 1
    fi
    DOCKER=true
elif command -v docker &> /dev/null && timeout 30 docker info > /dev/null 2>&1; then
    DOCKER=true
elif [ -S "$FS/var/run/docker.sock" ] || systemctl is-enabled --quiet docker 2>/dev/null; then
    echo "ERROR: Docker is installed here but not answering (or not on this PATH), so this site's"
    echo "container and volumes cannot be seen. Nothing was removed; start Docker and run this again."
    exit 1
fi

# The suffix of every volume a site owns: install.sh's ALL_SITE_VOLUMES and
# _site_run_spec.sh's RUN_SPEC_VOLUMES, spelled here because this script runs
# with or without install_tools beside it and sources only the engine helper
# (remove_account_gate checks the three agree).
SITE_VOLUME_SUFFIXES="code vendor scripts postgres uploads storage config backups static logs cache sessions apache_logs pg_logs agent deploy"

# _site_run_spec.sh's RUN_SPEC_RESTRICTIONS_DIR: the host's restrictions, which
# every site on a host that has them mounts read-only (specs/host_restrictions.md).
HOST_RESTRICTIONS_DIR="/etc/joinery/host_restrictions"

# A site's volumes that exist, one per line, by exact name: its sixteen, and
# the old database rebase_site_container.sh keeps until a rebase finishes
# (<site>_postgres_pg<major>). Never a prefix match: another site may be named
# <site>_<word> (getjoinery_developers beside getjoinery), and its volumes start
# with this site's name too. A site name holds no regex character.
site_volumes() {
    local name="${1:-$SITE_NAME}" present suffix
    [ "$DOCKER" = true ] || return 0
    present=$("$ENGINE" volume ls --format '{{.Name}}' 2>/dev/null || true)
    for suffix in $SITE_VOLUME_SUFFIXES; do
        printf '%s\n' "$present" | grep -qxF "${name}_${suffix}" && echo "${name}_${suffix}"
    done
    printf '%s\n' "$present" | grep -xE "${name}_postgres_pg[0-9]+" || true
}

# Whether a container of exactly this name exists. Asked before docker stop or
# rm, which fall back to a container ID starting with the name when no
# container has it: a site named 'cafe' must never reach container cafe12...
container_exists() {
    [ "$DOCKER" = true ] || return 1
    "$ENGINE" ps -a --format '{{.Names}}' 2>/dev/null | grep -qxF "$1"
}

# Whether NAME is a site in its own right on this machine: its own container,
# volumes or state directory. A real site can be named <site>_test or
# <site>-le-ssl, which this site's test twin and certbot vhost are also named.
another_site() {
    container_exists "$1" || [ -n "$(site_volumes "$1")" ] || [ -d "$SITES_STATE/$1" ]
}

# Why the site's container is not one install.sh made, or nothing when it is:
# it runs the site's own image (joinery-<site>, any tag: a rebase's rollback
# runs pre-rebase-pg<major>) and every mount is one of the site's own named
# volumes, or the host's restrictions read-only. This script knows how to
# remove only that shape; a container made
# by hand may hold a database, a bind mount or another site's volume.
container_not_ours() {
    local image mounts m name suffix own
    image=$("$ENGINE" inspect -f '{{.Config.Image}}' "$SITE_NAME" 2>/dev/null) || { echo "it could not be inspected"; return 0; }
    image=$(site_engine_image "$image")
    case "$image" in
        "joinery-${SITE_NAME}"|"joinery-${SITE_NAME}:"*) ;;
        *) echo "it runs the image '${image}', not joinery-${SITE_NAME}"; return 0 ;;
    esac
    mounts=$("$ENGINE" inspect -f '{{range .Mounts}}{{.Type}}:{{.Name}}:{{.Source}}:{{.Destination}}:{{.RW}} {{end}}' "$SITE_NAME" 2>/dev/null) \
        || { echo "its mounts could not be read"; return 0; }
    local type src dest rw
    for m in $mounts; do
        IFS=: read -r type name src dest rw <<< "$m"
        # The host's restrictions, read-only, which install.sh gives every site
        # on a host that has them: the host's, not the site's, so it stays.
        if [[ "$type" == "bind" && "$dest" == "$HOST_RESTRICTIONS_DIR" && "$rw" == "false" \
            && "$src" == "${FS}${HOST_RESTRICTIONS_DIR}" ]]; then
            continue
        fi
        if [[ "$type" != volume ]]; then
            echo "it has a ${type} mount (${src})"; return 0
        fi
        own=false
        for suffix in $SITE_VOLUME_SUFFIXES; do
            [ "$name" = "${SITE_NAME}_${suffix}" ] && own=true
        done
        if [ "$own" = false ]; then
            echo "it mounts the volume '${name}', which is not one of this site's"; return 0
        fi
    done
}

# Check for Docker container — or its volumes. Volumes alone happen when an
# earlier removal deleted the container and then failed on `docker volume rm`;
# a re-run must see that as a docker site still needing removal, not as
# nothing (which would verify the site gone while its data sits orphaned).
if [ "$DOCKER" = true ]; then
    if container_exists "$SITE_NAME"; then
        IS_DOCKER=true
    elif [ -n "$(site_volumes)" ]; then
        IS_DOCKER=true
    elif "$ENGINE" network inspect "${SITE_NAME}_net" > /dev/null 2>&1; then
        IS_DOCKER=true
    fi
fi

# A container this script did not make is refused before anything is removed.
if container_exists "$SITE_NAME"; then
    NOT_OURS=$(container_not_ours)
    if [ -n "$NOT_OURS" ]; then
        echo "ERROR: the container '$SITE_NAME' is not one install.sh made: ${NOT_OURS}."
        echo "Nothing was removed. Remove it by hand, knowing what it holds."
        exit 1
    fi
fi

# certbot's SSL vhost is this site's only when no site of that name exists.
if [ -f "$LE_SSL_FILE" ] && { another_site "$SITE_NAME-le-ssl" || [ -d "$WEB_DIR/$SITE_NAME-le-ssl" ]; }; then
    LE_SSL_FILE=""
fi

# Whether the site's host part is a container's reverse proxy: its container
# was found, or its vhost proxies (default_proxy_vhost.conf) rather than
# serving a DocumentRoot. A re-run after the container went still finds the
# proxy vhost, and must not be taken for a bare-metal site.
HOST_PROXY="$IS_DOCKER"
if [ "$HOST_PROXY" = false ] && [ -f "$VIRTUALHOST_FILE" ] && grep -qE '^[[:space:]]*ProxyPass[[:space:]]+/[[:space:]]+http://127\.0\.0\.1:' "$VIRTUALHOST_FILE"; then
    HOST_PROXY=true
fi

# A bare-metal site's test twin (_site_init.sh) shares its vhost. Behind a
# container's proxy there is none, and a <site>_test with its own vhost,
# container, volumes or state is another site.
OWN_TEST_SITE=false
if [ "$HOST_PROXY" = false ] && ! another_site "${SITE_NAME}_test" && [ ! -f "$APACHE_SITES/${SITE_NAME}_test.conf" ]; then
    OWN_TEST_SITE=true
fi

# Check for bare-metal installation
if [ -d "$SITE_ROOT" ] || [ -f "$VIRTUALHOST_FILE" ] || [ -f "$LE_SSL_FILE" ]; then
    IS_BAREMETAL=true
fi

# The marks other scripts leave for this site by exact path: hold_container.sh's
# 'held' (a switch-over's old container held stopped), site_quiet_host.sh's
# 'quiet' (the host's record of a dormant copy or a frozen source), suspended_page.sh's
# 'suspended', and the run spec (_site_run_spec.sh's run_spec_path). Cleared on
# every path through this script, so a new site given this name starts clean.
# Only a plain file is a mark: on a bare-metal site held/ is _site_state.sh's
# folder of the site's cron files while it is quiet, which is not this
# script's, and rm -f on it would stop the run. The rest of the site's state
# directory is not this script's to remove.
clear_site_marks() {
    local mark path f
    for mark in held quiet suspended run_spec disk_allowance; do
        path="${SITES_STATE:?}/${SITE_NAME:?}/${mark}"
        if [ -f "$path" ] || [ -L "$path" ]; then
            rm -f "$path"
        fi
    done
    # The site's XFS projects on a disk pool (docker_disk_pool.sh): the
    # allowance's and its own backups and deploy volumes', by exact name. Each
    # is joinery_<site> or joinery_<site>.<volume>, and no site name holds a
    # dot, so another site whose name begins with this one's keeps its own.
    local projid="${SITES_STATE%/joinery/sites}/projid" projects="${SITES_STATE%/joinery/sites}/projects" ids name
    [ -f "$projid" ] || return 0
    ids=""
    for name in "joinery_${SITE_NAME}" "joinery_${SITE_NAME}.backups" "joinery_${SITE_NAME}.deploy"; do
        ids="$ids $(awk -F: -v n="$name" '$1 == n { print $2 }' "$projid")"
    done
    for f in "$projid" "$projects"; do
        [ -f "$f" ] || continue
        awk -F: -v ids=" $ids " -v site="$SITE_NAME" '
            FILENAME ~ /projid$/ && ($1 == "joinery_" site || $1 == "joinery_" site ".backups" || $1 == "joinery_" site ".deploy") { next }
            FILENAME ~ /projects$/ && index(ids, " " $1 " ") { next }
            { print }' "$f" > "${f}.tmp.$$" && mv -f "${f}.tmp.$$" "$f"
    done
}

# The certificates the vhost serves with, read before the vhost goes: each
# Let's Encrypt lineage (/etc/letsencrypt/live/<name>/) and placeholder
# directory (/etc/ssl/joinery/<domain>) it names. Names only; the sibling
# script re-validates each and refuses one another enabled site still uses.
CERT_NAMES=""
for vh in "$VIRTUALHOST_FILE" "$LE_SSL_FILE"; do
    [ -f "$vh" ] || continue
    CERT_NAMES="$CERT_NAMES"$'\n'$( { grep -oE '/etc/letsencrypt/live/[a-z0-9.-]+/' "$vh" | cut -d/ -f5
                    grep -oE '/etc/ssl/joinery/[a-z0-9.-]+' "$vh" | cut -d/ -f5; } 2>/dev/null )
done
CERT_NAMES=$(printf '%s\n' "$CERT_NAMES" | grep -v '^$' | sort -u)
# Every name the vhost served, for the certificate retry each may have armed.
SERVED_NAMES=""
for vh in "$VIRTUALHOST_FILE" "$LE_SSL_FILE"; do
    [ -f "$vh" ] || continue
    SERVED_NAMES="$SERVED_NAMES"$'\n'$(grep -iE '^[[:space:]]*Server(Name|Alias)[[:space:]]' "$vh" 2>/dev/null \
        | sed -E 's/^[[:space:]]*[Ss][Ee][Rr][Vv][Ee][Rr]([Nn][Aa][Mm][Ee]|[Aa][Ll][Ii][Aa][Ss])[[:space:]]+//' | tr ' \t' '\n\n' | tr 'A-Z' 'a-z')
done
SERVED_NAMES=$(printf '%s\n%s\n' "$SERVED_NAMES" "$CERT_NAMES" | grep -E '^[a-z0-9][a-z0-9.-]{0,252}$' | sort -u)

if [ "$IS_DOCKER" = false ] && [ "$IS_BAREMETAL" = false ]; then
    # Nothing to remove. This is idempotent success, not an error: a decommission
    # job re-run (or a teardown that already happened) must not fail here. The
    # marker lets the caller distinguish "already gone" from "removed just now".
    clear_site_marks
    # Nothing of the site is left under its id block: a claim on it goes too.
    rm -f "$SITES_STATE/$SITE_NAME/idblock"
    echo "No site found with name '$SITE_NAME':"
    echo "  - No Docker container or volumes named '$SITE_NAME'"
    echo "  - No directory at $SITE_ROOT"
    echo "  - No virtual host at $VIRTUALHOST_FILE"
    echo "REMOVE_ACCOUNT_NOTHING $SITE_NAME"
    echo "DECOMMISSION_VERIFIED $SITE_NAME"
    exit 0
fi

# =============================================================================
# CONFIRMATION
# =============================================================================

echo "WARNING: This will completely remove site '$SITE_NAME' and all its data!"
echo ""

if [ "$IS_DOCKER" = true ]; then
    echo "Detected: DOCKER deployment"
    echo "This includes:"
    echo "  - Docker container: $SITE_NAME"
    echo "  - Docker volumes: ${SITE_NAME}_<name> for each of: $SITE_VOLUME_SUFFIXES"
    echo "  - Docker image: joinery-${SITE_NAME}:latest"
fi

if [ "$IS_BAREMETAL" = true ]; then
    if [ "$HOST_PROXY" = true ]; then
        echo ""
        # On a Docker host these are the container's reverse proxy: its vhost,
        # the host-side logs folder install.sh makes, and its certificates.
        echo "Also on the host: the container's proxy"
        echo "This includes:"
        echo "  - Host-side folder (proxy logs): $SITE_ROOT"
    else
        echo "Detected: BARE-METAL deployment"
        echo "This includes:"
        echo "  - Website files: $SITE_ROOT"
        if [ "$OWN_TEST_SITE" = true ]; then
            echo "  - Test site files: $TEST_SITE_ROOT"
        fi
        echo "  - PostgreSQL database: $SITE_NAME"
    fi
    echo "  - Apache virtual host: $VIRTUALHOST_FILE"
    if [ -f "$LE_SSL_FILE" ]; then
        echo "  - Apache SSL virtual host: $LE_SSL_FILE"
    fi
    for cert in $CERT_NAMES; do
        echo "  - HTTPS certificate: $cert"
    done
fi

echo ""
echo "This action CANNOT be undone!"
echo ""

if [ "$ASSUME_YES" = false ]; then
    read -p "Are you sure you want to continue? (type 'yes' to confirm): " confirm
    if [ "$confirm" != "yes" ]; then
        echo "Operation cancelled."
        exit 0
    fi
fi

# The site's id block is claimed before anything of it goes, so no run that
# stops part way (or an install beside this one) frees it while its files are
# still owned by it; only a removal verified gone drops the claim (VERIFY GONE).
if [ -n "$SITE_IDBLOCK" ]; then
    mkdir -p "$SITES_STATE/$SITE_NAME" && printf '%s\n' "$SITE_IDBLOCK" > "$SITES_STATE/$SITE_NAME/idblock"
fi

# =============================================================================
# DOCKER REMOVAL
# =============================================================================

if [ "$IS_DOCKER" = true ]; then
    echo ""
    echo "=== Removing Docker site ==="

    if container_exists "$SITE_NAME"; then
        echo "Stopping container..."
        if "$ENGINE" stop "$SITE_NAME" 2>/dev/null; then
            echo "Container stopped: $SITE_NAME"
        else
            echo "Container was not running or already stopped"
        fi

        echo "Removing container..."
        if "$ENGINE" rm "$SITE_NAME" 2>/dev/null; then
            echo "Container removed: $SITE_NAME"
        else
            echo "WARNING: Failed to remove container"
        fi
    else
        echo "No container named $SITE_NAME"
    fi

    # The site's own network (specs/node_outbound_and_transfer.md WP2): the one
    # its run spec names, or the one named for it when the spec is already gone.
    SITE_NETWORK=$(sed -n 's/^network=//p' "$SITES_STATE/${SITE_NAME}/run_spec" 2>/dev/null | tail -1)
    SITE_NETWORK="${SITE_NETWORK:-${SITE_NAME}_net}"
    if "$ENGINE" network inspect "$SITE_NETWORK" > /dev/null 2>&1; then
        if "$ENGINE" network rm "$SITE_NETWORK" > /dev/null 2>&1; then
            echo "Network removed: $SITE_NETWORK"
        else
            echo "WARNING: Failed to remove network: $SITE_NETWORK"
        fi
    fi

    # Remove volumes
    echo "Removing volumes..."
    VOLUMES=$(site_volumes)
    if [ -n "$VOLUMES" ]; then
        for vol in $VOLUMES; do
            if "$ENGINE" volume rm "$vol" 2>/dev/null; then
                echo "  Removed volume: $vol"
            else
                echo "  WARNING: Failed to remove volume: $vol"
            fi
        done
    else
        echo "  No volumes found for $SITE_NAME"
    fi

    # Its images: the one it runs, and any rebase_site_container.sh kept to go
    # back to (joinery-<site>:pre-rebase-pg<major>).
    echo "Removing Docker images..."
    IMAGES=$("$ENGINE" images --format '{{.Repository}}:{{.Tag}}' 2>/dev/null \
        | while read -r i; do site_engine_image "$i"; done | grep -xE "joinery-${SITE_NAME}:(latest|pre-rebase-pg[0-9]+)" || true)
    if [ -n "$IMAGES" ]; then
        for image in $IMAGES; do
            if "$ENGINE" rmi "$image" > /dev/null 2>&1; then
                echo "Image removed: $image"
            else
                echo "WARNING: Failed to remove image: $image"
            fi
        done
    else
        echo "No image for $SITE_NAME"
    fi

    # The host's outbound limits stop naming the site (outbound_limits.sh;
    # the unit is spelled here because this script sources only the engine helper).
    clear_site_marks
    if [ -f "$FS/etc/systemd/system/joinery-limits.service" ]; then
        systemctl start joinery-limits.service > /dev/null 2>&1 \
            || echo "WARNING: the outbound limits did not follow the removal; their timer tries again within five minutes"
    fi

    # Its build directory, and a rebase's working directory (it holds a dump).
    for dir in "$FS/root/${SITE_NAME:?}-build" "$FS/root/rebase/${SITE_NAME:?}"; do
        if [ -d "$dir" ]; then
            rm -rf "${dir:?}"
            echo "Removed: $dir"
        fi
    done

    echo "Docker cleanup complete."
fi

# =============================================================================
# BARE-METAL REMOVAL
# =============================================================================

if [ "$IS_BAREMETAL" = true ]; then
    echo ""
    if [ "$HOST_PROXY" = true ]; then
        echo "=== Removing the container's proxy on the host ==="
    else
        echo "=== Removing bare-metal site ==="
    fi

    # Disable Apache site if enabled
    echo "Disabling Apache site..."
    if [ -f "$VIRTUALHOST_FILE" ]; then
        a2dissite "$SITE_NAME.conf" 2>/dev/null || echo "Site was not enabled or already disabled"
    else
        echo "Virtual host file does not exist, skipping Apache disable"
    fi
    if [ -f "$LE_SSL_FILE" ]; then
        a2dissite "$SITE_NAME-le-ssl.conf" 2>/dev/null || echo "SSL site was not enabled or already disabled"
    fi

    # Test Apache configuration
    echo "Testing Apache configuration..."
    if ! apache2ctl configtest 2>/dev/null; then
        echo "WARNING: Apache configuration test failed, but continuing with cleanup"
    fi

    # Reload Apache
    echo "Reloading Apache..."
    if ! systemctl reload apache2 2>/dev/null; then
        echo "WARNING: Failed to reload Apache, but continuing with cleanup"
    fi

    # Remove website directories. On a data root the site's folders are
    # mounts of /srv/joinery/sites/<site>: those go first, with the data
    # behind them, so rm meets ordinary directories.
    echo "Removing website directories..."
    # Not cd'd into: under set -e a missing directory would end the removal
    # here, unsaid, instead of at the warning below.
    DATA_ROOT_TOOL="$(dirname "${BASH_SOURCE[0]}")/../install_tools/joinery_data_root.sh"
    if [ -f "$DATA_ROOT_TOOL" ]; then
        for dr_site in "$SITE_NAME" $( [ "$OWN_TEST_SITE" = true ] && echo "${SITE_NAME}_test" ); do
            JOINERY_HOST_ROOT="$FS" bash "$DATA_ROOT_TOOL" remove-site "$dr_site" \
                || { echo "ERROR: ${dr_site}'s folders on the data root could not be taken away (above); nothing more was removed."; exit 1; }
        done
    elif [ -f "$FS/etc/joinery/data_binds" ]; then
        echo "WARNING: this host has a data root, but $DATA_ROOT_TOOL is missing, so ${SITE_NAME}'s folders mounted from it cannot be taken away first."
    fi
    if [ -d "$SITE_ROOT" ]; then
        rm -rf "${SITE_ROOT:?}"
        echo "Removed: $SITE_ROOT"
    else
        echo "Directory $SITE_ROOT does not exist"
    fi

    if [ "$OWN_TEST_SITE" = true ]; then
        if [ -d "$TEST_SITE_ROOT" ]; then
            rm -rf "${TEST_SITE_ROOT:?}"
            echo "Removed: $TEST_SITE_ROOT"
        else
            echo "Directory $TEST_SITE_ROOT does not exist"
        fi
    elif [ -d "$TEST_SITE_ROOT" ]; then
        echo "Kept: $TEST_SITE_ROOT (not this site's test site)"
    fi

    # Remove Apache virtual host file
    echo "Removing Apache virtual host..."
    if [ -f "$VIRTUALHOST_FILE" ]; then
        rm -f "${VIRTUALHOST_FILE:?}"
        echo "Removed: $VIRTUALHOST_FILE"
    else
        echo "Virtual host file $VIRTUALHOST_FILE does not exist"
    fi
    if [ -f "$LE_SSL_FILE" ]; then
        rm -f "${LE_SSL_FILE:?}"
        echo "Removed: $LE_SSL_FILE"
    fi
    # The vhost's backups (render_vhost.sh's .before-render.<time>, .bak, .new).
    for backup in "$VIRTUALHOST_FILE".*; do
        [ -f "$backup" ] || continue
        rm -f "${backup:?}"
        echo "Removed: $backup"
    done

    # Remove the certificates the vhost named, now that no vhost serves with
    # them: certbot stops renewing a domain that has left this machine.
    if [ -n "$CERT_NAMES" ]; then
        echo "Removing HTTPS certificates..."
        CERT_SCRIPT="$(dirname "$0")/remove_site_certificate.sh"
        for cert in $CERT_NAMES; do
            if [ -f "$CERT_SCRIPT" ]; then
                if ! result=$(bash "$CERT_SCRIPT" "$cert" 2>&1); then
                    echo "  WARNING: certificate $cert kept: $result"
                else
                    echo "  $result"
                fi
            else
                echo "  WARNING: certificate $cert kept: remove_site_certificate.sh is not beside this script"
            fi
        done
    fi
    # A certificate retry armed for any of its names (arm_ssl_retry.sh) stops:
    # left, it waits on for a name whose site is gone, and issues for it if the
    # name ever reaches this machine again.
    for name in $SERVED_NAMES; do
        if [ -f "$FS/etc/joinery/ssl-retry/${name}.conf" ] \
           || systemctl is-enabled --quiet "joinery-ssl-retry@${name}.timer" 2>/dev/null; then
            rm -f "$FS/etc/joinery/ssl-retry/${name}.conf"
            systemctl disable --now "joinery-ssl-retry@${name}.timer" > /dev/null 2>&1 || true
            echo "Stopped the certificate retry for ${name}"
        fi
    done

    # The site's databases live on the machine only for a bare-metal site; a
    # container's are in its volumes.
    if [ "$HOST_PROXY" = false ]; then
        echo "Removing PostgreSQL database..."
        if dropdb -U postgres "$SITE_NAME" 2>/dev/null; then
            echo "Removed database: $SITE_NAME"
        else
            echo "Database '$SITE_NAME' does not exist or already removed"
        fi

        if [ "$OWN_TEST_SITE" = true ] && dropdb -U postgres "${SITE_NAME}_test" 2>/dev/null; then
            echo "Removed test database: ${SITE_NAME}_test"
        fi
    fi

    clear_site_marks

    if [ "$HOST_PROXY" = true ]; then
        echo "Host proxy cleanup complete."
    else
        echo "Bare-metal cleanup complete."
    fi
fi

# =============================================================================
# VERIFY GONE
# =============================================================================
# Re-probe everything a removal is supposed to have taken away. The verdict
# marker is what a caller trusts — not the exit codes of the removal commands
# above, several of which warn-and-continue.

LEFTOVERS=""
ENGINE_LEFT=false

if [ "$DOCKER" = true ]; then
    if ! timeout 30 "$ENGINE" info > /dev/null 2>&1; then
        LEFTOVERS="${LEFTOVERS}  - ${ENGINE} stopped answering, so its container and volumes could not be checked"$'\n'
        ENGINE_LEFT=true
    else
        if container_exists "$SITE_NAME"; then
            LEFTOVERS="${LEFTOVERS}  - Docker container still present: $SITE_NAME"$'\n'
            ENGINE_LEFT=true
        fi
        LEFT_VOLUMES=$(site_volumes)
        if [ -n "$LEFT_VOLUMES" ]; then
            LEFTOVERS="${LEFTOVERS}  - Docker volumes still present: $(echo "$LEFT_VOLUMES" | tr '\n' ' ')"$'\n'
            ENGINE_LEFT=true
        fi
        if "$ENGINE" network inspect "${SITE_NETWORK:-${SITE_NAME}_net}" > /dev/null 2>&1; then
            LEFTOVERS="${LEFTOVERS}  - Docker network still present: ${SITE_NETWORK:-${SITE_NAME}_net}"$'\n'
        fi
    fi
fi

# The site's id block stays taken while anything of it is left under its ids:
# a new site given the block would own what is left (site_container_engine D4).
# Its claim, made before the removal began, stays until a removal verifies it gone.
if [ -n "$SITE_IDBLOCK" ]; then
    if [ "$ENGINE_LEFT" = true ]; then
        LEFTOVERS="${LEFTOVERS}  - id block ${SITE_IDBLOCK} is kept for what is left of the site (${SITES_STATE#"$FS"}/${SITE_NAME}/idblock)"$'\n'
    else
        rm -f "$SITES_STATE/$SITE_NAME/idblock"
    fi
fi

for vh in "$VIRTUALHOST_FILE" "$LE_SSL_FILE"; do
    if [ -f "$vh" ]; then
        LEFTOVERS="${LEFTOVERS}  - Virtual host still present: $vh"$'\n'
    fi
done

if [ -d "$SITE_ROOT" ]; then
    LEFTOVERS="${LEFTOVERS}  - Web root still present: $SITE_ROOT"$'\n'
fi

# =============================================================================
# COMPLETE
# =============================================================================

echo ""
echo "=========================================="
echo "Site '$SITE_NAME' has been removed."
echo "=========================================="
echo ""
echo "REMOVE_ACCOUNT_OK $SITE_NAME"

if [ -n "$LEFTOVERS" ]; then
    echo "Removal ran but the site could not be verified gone:"
    printf '%s' "$LEFTOVERS"
    echo "DECOMMISSION_FAILED_VERIFY $SITE_NAME"
    exit 1
fi

echo "DECOMMISSION_VERIFIED $SITE_NAME"
echo "You can now run 'install.sh site' to create a new site."
