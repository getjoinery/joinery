#!/usr/bin/env bash
#
# docker_disk_pool.sh - a Docker host's disk pool, and each site's allowance in
# it (specs/multi_tenant_docker_hosts.md WP4).
#
# Version: 2.2 - Every new Docker host has the data root (one_data_root WP2), so a data root no
#                longer says the host is multi-tenant: can-cap answers whether a site's disk can
#                be capped here (the pool in place and userns-remap on), and allow asks it. create
#                takes a device as well as a size.
# Version: 2.1 - review (reviewer2): JOINERY_POOL_ROOT is refused as root (F3); pool_mounted
#                accepts Docker's data-root with a trailing slash (F7); create says when it
#                ignores a SIZE because the data root exists (F7).
# Version: 2.0 - The pool is the host's data root (joinery_data_root.sh,
#                specs/one_data_root.md WP1): XFS at /srv/joinery, with Docker's
#                data-root at /srv/joinery/docker, set in daemon.json. create
#                makes the data root when the host has none and takes SIZE as
#                its first size, which it then grows by itself; the units that
#                make Docker and containerd wait for it are the data root's.
#                No host ever carried the /var/lib/docker pool outside a scratch
#                box, so that layout is gone, not kept beside this one.
# Version: 1.1 - allow refuses an allowance that, with every other site's limit, would promise
#                more than the pool holds (reviewer2 B2). release removes the allowance from the site's config volume too, not only the
#                host's copy: a site still reading one after its project's limit is gone read
#                the whole pool as its own use and refused every upload (reviewer2 B1).
# Version: 1.0
#
#   docker_disk_pool.sh create [SIZE|DEVICE]
#                                        Root, on a host Docker is not on yet.
#                                        Makes the host's data root when it has
#                                        none (joinery_data_root.sh create: XFS
#                                        at /srv/joinery with project quotas, in
#                                        a file of SIZE grown as it fills, or on
#                                        DEVICE), and points Docker's data-root
#                                        at /srv/joinery/docker.
#   docker_disk_pool.sh check            Exit 0 when the data root is mounted
#                                        with project quotas on and Docker's
#                                        data-root is in it; 1 when not.
#   docker_disk_pool.sh can-cap          Exit 0 when a site's disk can be capped
#                                        here: the pool in place, and Docker
#                                        remapping user ids; 1, with the reason,
#                                        when not.
#   docker_disk_pool.sh allow SITE SIZE  Root. Gives the site's volumes one
#                                        allowance of SIZE: one project over all
#                                        of them but backups and deploy, whose
#                                        hard limit is SIZE plus 10%, and writes
#                                        the allowance where the site reads it.
#                                        Run again to change it.
#   docker_disk_pool.sh release SITE     Root. Takes the site's projects out of
#                                        /etc/projects and /etc/projid.
#   docker_disk_pool.sh show SITE        The site's allowance in bytes, or
#                                        nothing when it has none.
#
# WHY. Docker on ext4 cannot cap one container's disk: a site that fills the
# disk fills it for every site on the host. XFS project quotas can, on the
# directories a site's volumes live in, and only on an XFS filesystem mounted
# with prjquota, which a cloud box's root disk is not. The host's data root is
# exactly that filesystem (joinery_data_root.sh), so Docker keeps its data
# there and the quotas are set on it.
#
# THE POOL. The data root: a file allocated whole, so it never promises space
# the disk does not have, mounted with nofail so a host whose data root fails
# still boots, and required by Docker and containerd (joinery-data.target), so
# they do not start without it. Otherwise Docker would make an empty data-root
# on the root disk, with no limits, and every site would look gone. It starts
# at the size given and grows as it fills; the allowances below are checked
# against its size now.
#
# THE ALLOWANCE. A site's data is its named volumes. All of them but backups
# and deploy share one XFS project, so the allowance covers its whole
# footprint. backups (fleet backups stage there) and deploy (upgrade staging and
# the previous code) are the platform's, each in a project of its own with no
# limit, so a full site can still be backed up and upgraded. The hard limit is
# the allowance plus 10%: the site refuses uploads and stored mail at the
# allowance itself (DiskAllowance), and only the database, logs and system
# writes can use the rest, so PostgreSQL never meets the wall first. Site
# projects are numbered from SITE_PROJID_BASE, far above the ids Docker hands
# out counting up for --storage-opt size, so the two never pool together.
#
# Under user-namespace remapping the volumes live under
# /srv/joinery/docker/<uid>.<gid>/volumes; the paths are asked of Docker, never
# built, so they follow.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Tests point ROOT into a scratch directory, and stub the commands. The data
# root's script is pointed at the same one.
ROOT="${JOINERY_POOL_ROOT:-}"
# A fixture root as root would split the host: the data root tool ignores it
# there and writes the real /etc/fstab and units, while daemon.json and the
# projects went to the fixture.
if [[ -n "$ROOT" && "$EUID" -eq 0 ]]; then
    printf 'docker_disk_pool: JOINERY_POOL_ROOT is for tests, which run unprivileged; unset it to run as root\n' >&2
    exit 1
fi
DATA_ROOT_TOOL="${SCRIPT_DIR}/joinery_data_root.sh"
MNT="${ROOT}/srv/joinery"
DOCKER_DATA_ROOT="/srv/joinery/docker"
PROJECTS="${ROOT}/etc/projects"
PROJID="${ROOT}/etc/projid"
SITES_DIR="${ROOT}/etc/joinery/sites"
SITE_PROJID_BASE=1000000000
# The volumes outside the allowance, each in a project of its own.
OWN_PROJECT_VOLUMES="backups deploy"
HEADROOM_PCT=10
# Where the site reads its allowance: a file in its config volume.
ALLOWANCE_FILE="disk_allowance"
export DOCKER_DAEMON_JSON="${ROOT}/etc/docker/daemon.json"
# shellcheck source=_docker_daemon_json.sh
. "${SCRIPT_DIR}/_docker_daemon_json.sh"

data_root() { JOINERY_HOST_ROOT="$ROOT" bash "$DATA_ROOT_TOOL" "$@"; }

say() { printf '%s\n' "$*"; }
die() { printf 'docker_disk_pool: %s\n' "$*" >&2; exit 1; }

# SIZE (68G, 500M, 1T, or bytes) in bytes, or nothing when it is not one.
size_bytes() {
    local s="${1:-}" n u
    [[ "$s" =~ ^([0-9]+)([KMGT]?)$ ]] || return 1
    n="${BASH_REMATCH[1]}"; u="${BASH_REMATCH[2]}"
    case "$u" in
        K) n=$((n * 1024)) ;;
        M) n=$((n * 1024 * 1024)) ;;
        G) n=$((n * 1024 * 1024 * 1024)) ;;
        T) n=$((n * 1024 * 1024 * 1024 * 1024)) ;;
    esac
    (( n > 0 )) || return 1
    printf '%s' "$n"
}

# The pool is in place: the data root mounted, XFS with project quotas on, and
# Docker's data-root in it.
pool_mounted() {
    data_root check >/dev/null 2>&1 || return 1
    local dr
    dr="$(docker_daemon_json_get data-root)"
    dr="${dr#\"}"; dr="${dr%\"}"; dr="${dr%/}"
    [[ "$dr" == "${DOCKER_DATA_ROOT}" ]]
}

# A site's disk can be capped here: the pool is in place, and root in a
# container is not root on the host. Without remapping, a site's own
# processes own its files and can move them out of its project (chattr -p).
can_cap() {
    pool_mounted || { printf 'docker_disk_pool: this host has no disk pool\n' >&2; return 1; }
    [[ -n "$(docker_daemon_json_get userns-remap)" ]] \
        || { printf 'docker_disk_pool: Docker does not remap user ids here, so a site could move its own files out of its allowance\n' >&2; return 1; }
}

do_create() {
    local want="${1:-}" rc=0
    if [[ -n "$want" && "$want" != /dev/* ]]; then
        [[ "$want" =~ ^[0-9]+[KMGT]?$ ]] || die "the size is a number with G, M or T (68G), or a device under /dev, not '${want}'"
    fi
    [[ "$EUID" -eq 0 || -n "$ROOT" ]] || die "this must be run as root"

    if pool_mounted; then
        say "The disk pool is in place: Docker keeps its data on the data root, ${DOCKER_DATA_ROOT}"
        return 0
    fi
    # Docker's data-root is set before Docker's first start. On a host where
    # Docker has run, its images and volumes are where it put them, and
    # pointing it elsewhere would leave every site behind.
    if command -v dockerd >/dev/null 2>&1 || command -v docker >/dev/null 2>&1; then
        die "Docker is already installed here; the pool must be made before it. Make it on a new host: install.sh docker"
    fi

    data_root check >/dev/null 2>&1 || rc=$?
    case "$rc" in
        0) say "The host's data root is already mounted; the pool is made on it"
           [[ -z "$want" ]] || say "${want} was not used: the data root exists and grows by itself" ;;
        2) data_root create ${want:+"$want"} || die "the data root could not be made; nothing else was changed" ;;
        *) die "this host has a data root that is not mounted: $(data_root check 2>&1 || true). Nothing was changed." ;;
    esac

    mkdir -p "${ROOT}${DOCKER_DATA_ROOT}"
    chmod 710 "${ROOT}${DOCKER_DATA_ROOT}"
    docker_daemon_json_set data-root "\"${DOCKER_DATA_ROOT}\"" || die "could not set data-root in $(docker_daemon_json_path) (is it valid JSON?)"
    pool_mounted || die "the data root is mounted, but the pool is not in place; see: joinery_data_root.sh status"
    say "The disk pool is in place: Docker keeps its data at ${DOCKER_DATA_ROOT}, on the data root"
}

# ---------------------------------------------------------------------------
# Projects
# ---------------------------------------------------------------------------

# A project's id from /etc/projid, or nothing.
projid_of() { awk -F: -v n="$1" '$1 == n { print $2; exit }' "$PROJID" 2>/dev/null || true; }

# A project's id, made when it has none: the next one above every site project
# on this host. Under the caller's lock.
projid_ensure() {
    local name="$1" id
    id="$(projid_of "$name")"
    if [[ -z "$id" ]]; then
        id="$(awk -F: -v b="$SITE_PROJID_BASE" '$2 >= b && $2 > m { m = $2 } END { print (m ? m + 1 : b + 1) }' "$PROJID" 2>/dev/null || echo $((SITE_PROJID_BASE + 1)))"
        printf '%s:%s\n' "$name" "$id" >> "$PROJID"
    fi
    printf '%s' "$id"
}

# /etc/projects holds one id:path line for each path of a project; set them
# to exactly these.
projects_set() {
    local id="$1"; shift
    local tmp="${PROJECTS}.tmp.$$" p
    { grep -v "^${id}:" "$PROJECTS" 2>/dev/null || true; for p in "$@"; do printf '%s:%s\n' "$id" "$p"; done; } > "$tmp"
    mv -f "$tmp" "$PROJECTS"
}

# Each volume of the site, as "suffix<TAB>mountpoint", from its run spec.
site_volumes() {
    local site="$1" line name mp
    . "$SCRIPT_DIR/_site_run_spec.sh"
    while IFS= read -r line; do
        name="${line%%:*}"
        [[ "$name" == "${site}_"* ]] || continue
        mp="$(docker volume inspect -f '{{.Mountpoint}}' "$name" 2>/dev/null)" || continue
        [[ "$mp" == /* && "$mp" != *..* ]] || continue
        printf '%s\t%s\n' "${name#"${site}_"}" "$mp"
    done < <(run_spec_list "$site" volume)
}

site_name_ok() { [[ "${1:-}" =~ ^[a-z0-9][a-z0-9_-]{1,49}$ ]]; }

# Where the allowance is read: the site's config volume (DiskAllowance) and
# the host's state directory (show). A value writes both; an empty one
# removes both. A site whose file outlives its project reads the whole pool
# as its own use, since XFS reports a project's figures only while it has a
# limit, and would refuse every upload.
allowance_files_write() {  # SITE BYTES|'' [CONFIG_MOUNTPOINT]
    local site="$1" bytes="$2" config_mp="${3:-}" suffix mp
    if [[ -z "$config_mp" ]]; then
        while IFS=$'\t' read -r suffix mp; do
            [[ "$suffix" == config ]] && config_mp="$mp"
        done < <(site_volumes "$site")
    fi
    if [[ -n "$config_mp" && -d "$config_mp" ]]; then
        if [[ -n "$bytes" ]]; then
            printf '%s\n' "$bytes" > "${config_mp}/${ALLOWANCE_FILE}.tmp"
            chown --reference="$config_mp" "${config_mp}/${ALLOWANCE_FILE}.tmp" 2>/dev/null || true
            chmod 644 "${config_mp}/${ALLOWANCE_FILE}.tmp"
            mv -f "${config_mp}/${ALLOWANCE_FILE}.tmp" "${config_mp}/${ALLOWANCE_FILE}"
        else
            rm -f "${config_mp}/${ALLOWANCE_FILE}"
        fi
    fi
    if [[ -n "$bytes" ]]; then
        mkdir -p "${SITES_DIR}/${site}"
        printf '%s\n' "$bytes" > "${SITES_DIR}/${site}/disk_allowance"
    else
        rm -f "${SITES_DIR}/${site}/disk_allowance"
    fi
}

do_allow() {
    local site="${1:-}" want="${2:-}" bytes hard id own_id suffix mp config_mp=""
    local -a mine=()
    local -A own=()
    site_name_ok "$site" || die "'${site}' is not a site name"
    bytes="$(size_bytes "$want")" || die "the allowance is a number with G, M or T (4G), not '${want}'"
    [[ "$EUID" -eq 0 || -n "$ROOT" ]] || die "this must be run as root"
    can_cap || die "a site's disk cannot be capped here (install.sh docker --multi-tenant makes a host that can, on a new host)"

    while IFS=$'\t' read -r suffix mp; do
        [[ -n "$suffix" ]] || continue
        if [[ " $OWN_PROJECT_VOLUMES " == *" $suffix "* ]]; then
            own[$suffix]="$mp"
        else
            mine+=("$mp")
        fi
        [[ "$suffix" == "config" ]] && config_mp="$mp"
    done < <(site_volumes "$site")
    (( ${#mine[@]} > 0 )) || die "${site} has no volumes on this host"

    hard=$(( bytes + bytes * HEADROOM_PCT / 100 ))
    mkdir -p "$(dirname "$PROJID")"
    touch "$PROJID" "$PROJECTS"
    exec 9>>"$PROJID.lock"
    flock 9
    id="$(projid_ensure "joinery_${site}")"
    projects_set "$id" "${mine[@]}"
    for suffix in "${!own[@]}"; do
        own_id="$(projid_ensure "joinery_${site}_${suffix}")"
        projects_set "$own_id" "${own[$suffix]}"
        xfs_quota -x -c "project -s -p ${own[$suffix]} ${own_id}" "$MNT" >/dev/null
    done
    # project -s marks each directory to pass the project to everything made
    # in it, and gives every file already there the project.
    for mp in "${mine[@]}"; do
        xfs_quota -x -c "project -s -p ${mp} ${id}" "$MNT" >/dev/null
    done
    # Every site's hard limit together must fit the pool: a site at its limit
    # must never be able to fill the disk another site writes to. Under the
    # lock, so two allowances set at once are counted together.
    local pool_total others
    pool_total="$(df -B1 --output=size "$MNT" | tail -n 1 | tr -d ' ' || true)"
    others="$(xfs_quota -x -c 'report -p -n -b -N' "$MNT" 2>/dev/null \
        | awk -v base="$SITE_PROJID_BASE" -v me="$id" '{ p = $1; sub(/^#/, "", p) } p + 0 > base && p != me { s += $4 } END { printf "%.0f", s * 1024 }' || true)"
    if [[ "$pool_total" =~ ^[0-9]+$ ]] && (( ${others:-0} + hard > pool_total )); then
        flock -u 9
        die "${want} for ${site} would promise more than the pool holds: the other sites' limits come to $(( ${others:-0} / 1024 / 1024 )) MB, this one's $(( hard / 1024 / 1024 )) MB with its 10%, and the pool is $(( pool_total / 1024 / 1024 )) MB. Give a smaller allowance, or move a site to another host."
    fi
    xfs_quota -x -c "limit -p bhard=${hard} ${id}" "$MNT"
    flock -u 9

    allowance_files_write "$site" "$bytes" "$config_mp"
    say "${site}: allowance ${want} ($((hard / 1024 / 1024)) MB hard limit), project ${id}"
}

do_release() {
    local site="${1:-}" name id
    site_name_ok "$site" || die "'${site}' is not a site name"
    # The allowance goes from where the site reads it whatever the project
    # files hold.
    allowance_files_write "$site" ""
    [[ -f "$PROJID" ]] || return 0
    exec 9>>"$PROJID.lock"
    flock 9
    for name in "joinery_${site}" $(for s in $OWN_PROJECT_VOLUMES; do printf 'joinery_%s_%s ' "$site" "$s"; done); do
        id="$(projid_of "$name")"
        [[ -n "$id" ]] || continue
        pool_mounted && xfs_quota -x -c "limit -p bhard=0 ${id}" "$MNT" >/dev/null 2>&1 || true
        projects_set "$id"
        { grep -v "^${name}:" "$PROJID" || true; } > "${PROJID}.tmp.$$" && mv -f "${PROJID}.tmp.$$" "$PROJID"
    done
    flock -u 9
}

do_show() {
    local site="${1:-}"
    site_name_ok "$site" || die "'${site}' is not a site name"
    cat "${SITES_DIR}/${site}/disk_allowance" 2>/dev/null || true
}

case "${1:-}" in
    create)  do_create "${2:-}" ;;
    check)   pool_mounted ;;
    can-cap) can_cap ;;
    allow)   do_allow "${2:-}" "${3:-}" ;;
    release) do_release "${2:-}" ;;
    show)    do_show "${2:-}" ;;
    *) die "usage: docker_disk_pool.sh create [SIZE|DEVICE] | check | can-cap | allow SITE SIZE | release SITE | show SITE" ;;
esac
