#!/usr/bin/env bash
# migrate_site_to_code_volumes.sh — move a site's code onto named volumes.
#
# A site installed before the code volumes existed keeps public_html, vendor and
# maintenance_scripts in its container's writable layer, where `docker rm`
# destroys them. This moves them onto volumes, so an in-place upgrade survives
# container recreation.
#
# Any site-root directory that is not on a volume is in the same danger, so
# `storage` is carried across too when the container has no volume for it.
#
# Run in two stages:
#   ./migrate_site_to_code_volumes.sh <site> prepare   # nothing destructive
#   ./migrate_site_to_code_volumes.sh <site> swap      # stops, removes, recreates
#
# prepare tars the code to /root/code-migration/, creates and fills the volumes,
# and verifies the copy. It never touches the container. If any check fails it
# stops there, leaving the site running and the volumes available to inspect.
#
# swap re-verifies, then rebuilds the container's own `docker run` from
# `docker inspect` — same ports, environment, restart policy and existing
# volumes, plus the new mounts — so nothing about the container changes except
# where its code lives.
set -euo pipefail

SITE="${1:-}"
STAGE="${2:-}"

if [ -z "$SITE" ] || { [ "$STAGE" != "prepare" ] && [ "$STAGE" != "swap" ]; }; then
    echo "usage: $0 <site> prepare|swap" >&2
    exit 2
fi

WORK_DIR=/root/code-migration
STATE_FILE="${WORK_DIR}/${SITE}.state"
SITE_ROOT="/var/www/html/${SITE}"

# volume suffix : path under the site root
CODE_TREES=("code:public_html" "vendor:vendor" "scripts:maintenance_scripts")

say()  { echo "[$(date -u +%H:%M:%S)] $*"; }
die()  { echo "FATAL: $*" >&2; exit 1; }

docker inspect "$SITE" > /dev/null 2>&1 || die "no container named ${SITE}"

# Count files in a tree inside the container, and in a volume on the host.
count_in_container() { docker exec "$SITE" find "$1" -type f 2>/dev/null | wc -l; }
count_on_host()      { find "$1" -type f 2>/dev/null | wc -l; }
volume_mountpoint()  { docker volume inspect -f '{{ .Mountpoint }}' "$1"; }

# Destinations already mounted, so the swap never adds a duplicate and prepare
# knows whether storage still needs rescuing.
mounted_destinations() {
    docker inspect -f '{{range .Mounts}}{{if eq .Type "volume"}}{{.Destination}}{{println}}{{end}}{{end}}' "$SITE"
}

is_mounted() {
    mounted_destinations | grep -qx "$1"
}

# The code trees, plus EVERY other site-root directory that has no volume.
# Whatever is not on a volume is destroyed by `docker rm` and exists in no
# image, so the list is discovered rather than hardcoded — sites differ, and the
# one that surprised us held a 400MB restore staging tree nobody had listed.
migration_trees() {
    local t d
    for t in "${CODE_TREES[@]}"; do echo "$t"; done
    while read -r d; do
        [ -z "$d" ] && continue
        case "$d" in public_html|vendor|maintenance_scripts) continue ;; esac
        is_mounted "${SITE_ROOT}/${d}" || echo "${d}:${d}"
    done < <(docker exec "$SITE" bash -c "cd '$SITE_ROOT' && ls -1d */ 2>/dev/null | sed 's#/\$##'")
}

# Loose files sitting directly in the site root. A volume cannot hold a single
# file, so these are tarred during prepare and put back after the swap.
loose_root_files() {
    docker exec "$SITE" bash -c "cd '$SITE_ROOT' && find . -maxdepth 1 -type f -printf '%f\n' 2>/dev/null"
}

#------------------------------------------------------------------------------
# prepare
#------------------------------------------------------------------------------
if [ "$STAGE" = "prepare" ]; then
    say "Preparing ${SITE}"

    [ "$(docker inspect -f '{{.State.Status}}' "$SITE")" = "running" ] \
        || die "${SITE} is not running; start it before migrating"

    VERSION=$(docker exec "$SITE" cat "${SITE_ROOT}/public_html/VERSION" 2>/dev/null | tr -d '[:space:]')
    [ -n "$VERSION" ] || die "cannot read ${SITE}'s VERSION — refusing to migrate code I cannot identify"
    say "Running version: ${VERSION}"

    mkdir -p "$WORK_DIR"

    # Refuse to run twice: a populated volume means this already happened, and
    # copying over it would overwrite newer code with whatever is in the
    # container now.
    for spec in $(migration_trees); do
        vol="${SITE}_${spec%%:*}"
        if mp=$(volume_mountpoint "$vol" 2>/dev/null); then
            if [ -n "$(ls -A "$mp" 2>/dev/null)" ]; then
                die "volume ${vol} already has content — ${SITE} looks migrated already"
            fi
        fi
    done

    # Insurance against a copy that silently misses something. Streamed straight
    # out of the container, so it needs no staging space inside it.
    TARBALL="${WORK_DIR}/${SITE}-${VERSION}-$(date -u +%Y%m%d_%H%M%S).tar.gz"
    say "Backing up code to ${TARBALL}"
    TREE_NAMES=()
    for spec in $(migration_trees); do TREE_NAMES+=("${spec##*:}"); done
    docker exec "$SITE" tar czf - -C "$SITE_ROOT" "${TREE_NAMES[@]}" > "$TARBALL"
    say "Backup size: $(du -sh "$TARBALL" | cut -f1)"

    : > "$STATE_FILE"
    echo "version=${VERSION}" >> "$STATE_FILE"
    echo "tarball=${TARBALL}" >> "$STATE_FILE"

    # PHP extensions are apt packages, and utils/upgrade.php installs the ones
    # the code declares into the running container — so they live in the
    # writable layer exactly like the code did, and `docker rm` takes them too.
    # Record the installed set now so the swap can restore whatever the new
    # container turns out to be missing.
    docker exec "$SITE" bash -c "dpkg-query -W -f='\${Package}\n' 'php8.3-*' 2>/dev/null | sort" \
        > "${WORK_DIR}/${SITE}.php-packages" || true
    say "Recorded $(wc -l < "${WORK_DIR}/${SITE}.php-packages") installed php packages"

    # System config lives in the writable layer too, and a recreated container
    # gets the image's version of it. On a long-lived site that silently undoes
    # things nothing will complain about: the real-client-IP module, the Apache
    # worker tuning, the management agent binary. Captured whole, because the
    # swap reuses the same image and so the same baseline.
    CONFIG_PATHS=""
    for p in etc/apache2 usr/local/bin var/spool/cron/crontabs; do
        docker exec "$SITE" test -e "/$p" 2>/dev/null && CONFIG_PATHS="${CONFIG_PATHS} ${p}"
    done
    if [ -n "${CONFIG_PATHS// /}" ]; then
        say "Capturing system config:${CONFIG_PATHS}"
        # shellcheck disable=SC2086
        docker exec "$SITE" tar cf - -C / $CONFIG_PATHS > "${WORK_DIR}/${SITE}.config.tar"
        echo "config_tar=${WORK_DIR}/${SITE}.config.tar" >> "$STATE_FILE"
        # Restoring this over a DIFFERENT image would fight whatever that image
        # changed, so the swap checks the image is still the same one.
        echo "image_id=$(docker inspect -f '{{.Image}}' "$SITE")" >> "$STATE_FILE"
    fi

    # Loose files in the site root cannot live on a volume; keep them aside so
    # the swap can put them back rather than silently dropping them.
    LOOSE=$(loose_root_files | tr '\n' ' ')
    ROOT_FILES_TAR="${WORK_DIR}/${SITE}.rootfiles.tar"
    rm -f "$ROOT_FILES_TAR"
    if [ -n "${LOOSE// /}" ]; then
        say "Loose files in the site root will be preserved: ${LOOSE}"
        # shellcheck disable=SC2086
        docker exec "$SITE" tar cf - -C "$SITE_ROOT" $LOOSE > "$ROOT_FILES_TAR"
        echo "rootfiles=${ROOT_FILES_TAR}" >> "$STATE_FILE"
    fi

    for spec in $(migration_trees); do
        suffix="${spec%%:*}"
        tree="${spec##*:}"
        vol="${SITE}_${suffix}"
        src="${SITE_ROOT}/${tree}"

        say "Copying ${tree} -> ${vol}"
        docker volume create "$vol" > /dev/null
        mp=$(volume_mountpoint "$vol")
        [ -n "$mp" ] || die "cannot resolve mountpoint for ${vol}"

        # `/.` copies the directory's contents rather than the directory itself.
        # -a keeps the uid/gid: without it docker cp rewrites everything to the
        # local user, and root-owned code under a www-data Apache is a 500.
        docker cp -a "${SITE}:${src}/." "${mp}/"

        want=$(count_in_container "$src")
        got=$(count_on_host "$mp")
        echo "count_${suffix}=${got}" >> "$STATE_FILE"
        if [ "$want" != "$got" ]; then
            die "${tree}: container has ${want} files, volume has ${got} — stopping before anything is removed"
        fi
        say "  ${tree}: ${got} files verified"
    done

    # The one file the container start-up refuses to run without.
    vol_mp=$(volume_mountpoint "${SITE}_code")
    [ -f "${vol_mp}/VERSION" ] || die "no VERSION on the code volume"
    [ "$(tr -d '[:space:]' < "${vol_mp}/VERSION")" = "$VERSION" ] \
        || die "VERSION on the volume does not match the running container"

    say "PREPARED. ${SITE} is untouched and still serving."
    say "State: ${STATE_FILE}"
    exit 0
fi

#------------------------------------------------------------------------------
# swap
#------------------------------------------------------------------------------
say "Swapping ${SITE}"

[ -f "$STATE_FILE" ] || die "no state file at ${STATE_FILE} — run prepare first"
# shellcheck disable=SC1090
VERSION=$(grep '^version=' "$STATE_FILE" | cut -d= -f2)
[ -n "$VERSION" ] || die "state file has no version"

# Re-verify rather than trust the state file: prepare may have run days ago.
for spec in $(migration_trees); do
    suffix="${spec%%:*}"
    vol="${SITE}_${suffix}"
    mp=$(volume_mountpoint "$vol") || die "volume ${vol} is missing"
    want=$(grep "^count_${suffix}=" "$STATE_FILE" | cut -d= -f2)
    got=$(count_on_host "$mp")
    [ "$want" = "$got" ] || die "${vol}: prepared with ${want} files, now has ${got}"
done
say "Volumes re-verified"

IMAGE=$(docker inspect -f '{{.Config.Image}}' "$SITE")
RESTART=$(docker inspect -f '{{.HostConfig.RestartPolicy.Name}}' "$SITE")

RUN_ARGS=(--name "$SITE")
[ -n "$RESTART" ] && [ "$RESTART" != "no" ] && RUN_ARGS+=(--restart "$RESTART")

# Ports, exactly as bound now.
while IFS='|' read -r hip hport cport; do
    [ -z "$cport" ] && continue
    cport="${cport%%/*}"
    if [ -n "$hip" ]; then
        RUN_ARGS+=(-p "${hip}:${hport}:${cport}")
    else
        RUN_ARGS+=(-p "${hport}:${cport}")
    fi
done < <(docker inspect -f '{{range $p, $conf := .HostConfig.PortBindings}}{{range $conf}}{{.HostIp}}|{{.HostPort}}|{{$p}}{{println}}{{end}}{{end}}' "$SITE")

# Environment, minus what the image sets for itself. Values are never printed.
while IFS= read -r e; do
    [ -z "$e" ] && continue
    case "$e" in
        PATH=*|DEBIAN_FRONTEND=*) continue ;;
    esac
    RUN_ARGS+=(-e "$e")
done < <(docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' "$SITE")

# Existing volumes, unchanged.
while read -r name dest; do
    [ -z "$name" ] && continue
    RUN_ARGS+=(-v "${name}:${dest}")
done < <(docker inspect -f '{{range .Mounts}}{{if eq .Type "volume"}}{{.Name}} {{.Destination}}{{println}}{{end}}{{end}}' "$SITE")

# The new ones.
for spec in $(migration_trees); do
    suffix="${spec%%:*}"
    tree="${spec##*:}"
    RUN_ARGS+=(-v "${SITE}_${suffix}:${SITE_ROOT}/${tree}")
done

# Record what is about to run, with environment values masked.
PLAN="${WORK_DIR}/${SITE}.run-plan"
{
    printf 'docker run -d'
    for a in "${RUN_ARGS[@]}"; do
        case "$a" in
            *=*) printf ' %s=***' "${a%%=*}" ;;
            *)   printf ' %s' "$a" ;;
        esac
    done
    printf ' %s\n' "$IMAGE"
} > "$PLAN"
say "Run plan written to ${PLAN}"

say "Stopping ${SITE}"
docker stop "$SITE" > /dev/null
say "Removing container (volumes are untouched)"
docker rm "$SITE" > /dev/null
say "Recreating"
docker run -d "${RUN_ARGS[@]}" "$IMAGE" > /dev/null

sleep 5
STATUS=$(docker inspect -f '{{.State.Status}}' "$SITE")
[ "$STATUS" = "running" ] || die "container is ${STATUS} — check: docker logs ${SITE}"

NEW_VERSION=$(docker exec "$SITE" cat "${SITE_ROOT}/public_html/VERSION" 2>/dev/null | tr -d '[:space:]')
[ "$NEW_VERSION" = "$VERSION" ] \
    || die "version changed from ${VERSION} to ${NEW_VERSION} — the site moved when it should not have"

# System config the old container had accumulated. Only safe to put back when
# the image has not changed underneath us — a new image may have deliberately
# changed the very files this would overwrite.
CONFIG_TAR=$(grep '^config_tar=' "$STATE_FILE" | cut -d= -f2 || true)
RECORDED_IMAGE=$(grep '^image_id=' "$STATE_FILE" | cut -d= -f2 || true)
if [ -n "${CONFIG_TAR:-}" ] && [ -s "$CONFIG_TAR" ]; then
    CURRENT_IMAGE=$(docker inspect -f '{{.Image}}' "$SITE")
    if [ "$CURRENT_IMAGE" = "$RECORDED_IMAGE" ]; then
        say "Restoring system config (apache modules and tuning, agent binary, crontabs)"
        docker cp - "${SITE}:/" < "$CONFIG_TAR"
        docker exec "$SITE" apachectl configtest 2>&1 | tail -1
        docker exec "$SITE" service apache2 reload > /dev/null 2>&1 || true
    else
        say "WARNING: image changed since prepare — NOT restoring system config."
        say "         Review ${CONFIG_TAR} by hand before assuming the new image covers it."
    fi
fi

# Loose site-root files live in no volume, so the fresh container does not have
# them. Put them back before anything else looks for them.
ROOT_FILES_TAR=$(grep '^rootfiles=' "$STATE_FILE" | cut -d= -f2 || true)
if [ -n "${ROOT_FILES_TAR:-}" ] && [ -s "$ROOT_FILES_TAR" ]; then
    say "Restoring loose site-root files"
    docker cp - "${SITE}:${SITE_ROOT}" < "$ROOT_FILES_TAR"
fi

# The image's start-up chown covers the trees it knows about, but the volume
# directories themselves are created by Docker as root. Re-assert what the site
# actually needs: Apache runs as www-data and cannot read a root-owned tree.
say "Re-asserting ownership"
docker exec "$SITE" chown -R www-data:www-data \
    "${SITE_ROOT}/public_html" "${SITE_ROOT}/vendor" "${SITE_ROOT}/maintenance_scripts" "${SITE_ROOT}/storage"
docker exec "$SITE" find "${SITE_ROOT}/public_html" -type d -exec chmod 755 {} +
docker exec "$SITE" find "${SITE_ROOT}/public_html" -type f -exec chmod 644 {} +
docker exec "$SITE" bash -c "chmod +x ${SITE_ROOT}/maintenance_scripts/install_tools/*.sh" || true

# Restore any php package the old container had and this one does not. The
# image only ever installed what the code declared when it was built, which for
# a long-lived site is a much older declaration.
PKG_FILE="${WORK_DIR}/${SITE}.php-packages"
if [ -s "$PKG_FILE" ]; then
    MISSING=$(docker exec "$SITE" bash -c \
        "comm -23 <(sort) <(dpkg-query -W -f='\${Package}\n' 'php8.3-*' 2>/dev/null | sort)" < "$PKG_FILE" | tr '\n' ' ')
    if [ -n "${MISSING// /}" ]; then
        say "Reinstalling php packages lost with the old container: ${MISSING}"
        docker exec "$SITE" bash -c "apt-get update -qq && apt-get install -y ${MISSING}" > /dev/null
        docker exec "$SITE" service apache2 reload > /dev/null 2>&1 || true
    else
        say "No php packages missing"
    fi
fi

HTTP=$(docker exec "$SITE" bash -c "curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1/" 2>/dev/null || echo "000")
say "Local HTTP check: ${HTTP}"

say "SWAPPED. ${SITE} is running ${NEW_VERSION} with its code on volumes."
say "Backup retained: $(grep '^tarball=' "$STATE_FILE" | cut -d= -f2)"
