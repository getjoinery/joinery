#!/usr/bin/env bash
#
# docker_disk_pool.sh - a Docker host's disk pool, and each site's allowance in
# it (specs/multi_tenant_docker_hosts.md WP4).
#
# Version: 1.1 - allow refuses an allowance that, with every other site's limit, would promise
#                more than the pool holds (reviewer2 B2). release removes the allowance from the site's config volume too, not only the
#                host's copy: a site still reading one after its project's limit is gone read
#                the whole pool as its own use and refused every upload (reviewer2 B1).
# Version: 1.0
#
#   docker_disk_pool.sh create SIZE      Root, on a host Docker is not on yet.
#                                        Makes the pool: a fully allocated file
#                                        of SIZE (68G, 500M) on the root disk,
#                                        formatted XFS and mounted at
#                                        /var/lib/docker with project quotas,
#                                        through /etc/fstab, and makes Docker
#                                        and containerd wait for it.
#   docker_disk_pool.sh check            Exit 0 when the pool is mounted with
#                                        project quotas on; 1 when it is not.
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
# with prjquota, which a cloud box's root disk is not. So /var/lib/docker is a
# filesystem of its own, in a file, which works the same on any provider.
#
# THE POOL. A file, not a second cloud disk. fallocate allocates every block,
# so the pool can never promise space the disk does not have. The loop device
# reads the file with direct I/O, so the host does not cache each page twice.
# The fstab line carries nofail, so a pool that fails to mount does not stop
# the host booting; Docker and containerd require the mount
# (RequiresMountsFor), so they do not start without it. Otherwise Docker would
# make an empty /var/lib/docker on the root disk, with no limits, and every
# site would look gone.
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
# /var/lib/docker/<uid>.<gid>/volumes; the paths are asked of Docker, never
# built, so they follow.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Tests point ROOT into a scratch directory, and stub the commands.
ROOT="${JOINERY_POOL_ROOT:-}"
IMAGE="${ROOT}/var/lib/joinery-docker-pool.img"
MNT="${ROOT}/var/lib/docker"
FSTAB="${ROOT}/etc/fstab"
PROJECTS="${ROOT}/etc/projects"
PROJID="${ROOT}/etc/projid"
SYSTEMD_DIR="${ROOT}/etc/systemd/system"
SITES_DIR="${ROOT}/etc/joinery/sites"
DIO_UNIT="joinery-docker-pool-dio.service"
FSTAB_MARK="# joinery-docker-pool (docker_disk_pool.sh)"
SITE_PROJID_BASE=1000000000
# Left on the root disk beside the pool: the OS, its logs and headroom.
ROOT_KEEP_BYTES=$((4 * 1024 * 1024 * 1024))
# The volumes outside the allowance, each in a project of its own.
OWN_PROJECT_VOLUMES="backups deploy"
HEADROOM_PCT=10
# Where the site reads its allowance: a file in its config volume.
ALLOWANCE_FILE="disk_allowance"

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

# The pool is mounted at /var/lib/docker, XFS, with project quotas enforced.
pool_mounted() {
    local fstype opts
    read -r fstype opts < <(findmnt -n -o FSTYPE,OPTIONS --mountpoint "$MNT" 2>/dev/null || true) || true
    [[ "${fstype:-}" == "xfs" && ",${opts:-}," == *",prjquota,"* ]]
}

dropin_text() {
    cat <<EOF
# Written by docker_disk_pool.sh (specs/multi_tenant_docker_hosts.md WP4).
# /var/lib/docker is the disk pool. Without it mounted this unit does not
# start: it would build an empty /var/lib/docker on the root disk, with no
# disk limits, and every site would look gone.
[Unit]
RequiresMountsFor=/var/lib/docker
EOF
}

dio_unit_text() {
    cat <<EOF
# Written by docker_disk_pool.sh (specs/multi_tenant_docker_hosts.md WP4).
# The pool's loop device reads its file with direct I/O, so the host does not
# keep every page twice in its cache.
[Unit]
Description=Joinery Docker disk pool: direct I/O on its loop device
After=var-lib-docker.mount
Requires=var-lib-docker.mount
Before=docker.service containerd.service

[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart=/bin/sh -c 'for d in \$(losetup -n -O NAME -j ${IMAGE}); do losetup --direct-io=on "\$d" || true; done'

[Install]
WantedBy=var-lib-docker.mount
EOF
}

do_create() {
    local want="${1:-}" bytes avail docker_has
    bytes="$(size_bytes "$want")" || die "the pool size is a number with G, M or T (68G), not '${want}'"
    [[ "$EUID" -eq 0 || -n "$ROOT" ]] || die "this must be run as root"

    if pool_mounted; then
        say "The disk pool is already mounted at /var/lib/docker"
        return 0
    fi
    # The pool goes under Docker before Docker's first start. On a host where
    # Docker has run, /var/lib/docker already holds its images and volumes,
    # which the pool would hide.
    if command -v dockerd >/dev/null 2>&1 || command -v docker >/dev/null 2>&1; then
        die "Docker is already installed here; the pool must be made before it. Make it on a new host: install.sh docker --disk-pool=SIZE"
    fi
    docker_has="$(find "$MNT" -mindepth 1 -maxdepth 1 2>/dev/null | head -n 1 || true)"
    [[ -z "$docker_has" ]] || die "${MNT} is not empty; the pool would hide what is in it. Nothing was changed."
    [[ ! -e "$IMAGE" ]] || die "${IMAGE} exists but is not mounted at ${MNT}. Mount it (mount ${MNT}) or remove it, then run this again."

    avail="$(df -B1 --output=avail "${ROOT:-/}" | tail -n 1 | tr -d ' ')"
    [[ "$avail" =~ ^[0-9]+$ ]] || die "could not read the root disk's free space"
    if (( bytes + ROOT_KEEP_BYTES > avail )); then
        die "a pool of ${want} would leave less than 4 GB on the root disk ($((avail / 1024 / 1024 / 1024)) GB free). Choose a smaller size."
    fi

    if ! command -v mkfs.xfs >/dev/null 2>&1; then
        say "Installing xfsprogs..."
        DEBIAN_FRONTEND=noninteractive apt-get install -y xfsprogs >/dev/null || die "could not install xfsprogs"
    fi

    say "Allocating the pool: ${want} at ${IMAGE}..."
    mkdir -p "$(dirname "$IMAGE")"
    fallocate -l "$bytes" "$IMAGE" || { rm -f "$IMAGE"; die "could not allocate ${want} on the root disk"; }
    chmod 600 "$IMAGE"
    # ftype=1: overlay2, Docker's storage driver, needs the file type in
    # directory entries.
    mkfs.xfs -q -n ftype=1 "$IMAGE" || { rm -f "$IMAGE"; die "could not format the pool"; }

    mkdir -p "$MNT"
    if ! grep -qF "$FSTAB_MARK" "$FSTAB" 2>/dev/null; then
        printf '%s\n%s %s xfs loop,prjquota,nofail 0 0\n' "$FSTAB_MARK" "/var/lib/joinery-docker-pool.img" "/var/lib/docker" >> "$FSTAB"
    fi

    local unit
    for unit in docker.service containerd.service; do
        mkdir -p "${SYSTEMD_DIR}/${unit}.d"
        dropin_text > "${SYSTEMD_DIR}/${unit}.d/joinery-disk-pool.conf"
    done
    dio_unit_text > "${SYSTEMD_DIR}/${DIO_UNIT}"
    systemctl daemon-reload

    mount "$MNT" || die "the pool did not mount at ${MNT}; see dmesg. /etc/fstab keeps its line, so fix it and run: mount ${MNT}"
    pool_mounted || die "${MNT} is mounted, but not as XFS with project quotas on"
    systemctl enable "$DIO_UNIT" >/dev/null 2>&1 || true
    systemctl start "$DIO_UNIT" || say "Direct I/O could not be turned on for the pool's loop device; the pool works, with the host caching its pages twice."
    say "The disk pool is mounted at /var/lib/docker: $(df -h --output=size "$MNT" | tail -n 1 | tr -d ' '), XFS with project quotas"
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
    pool_mounted || die "this host has no disk pool, so a site's disk cannot be capped here (install.sh docker --disk-pool=SIZE makes one on a new host)"

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
    allow)   do_allow "${2:-}" "${3:-}" ;;
    release) do_release "${2:-}" ;;
    show)    do_show "${2:-}" ;;
    *) die "usage: docker_disk_pool.sh create SIZE | check | allow SITE SIZE | release SITE | show SITE" ;;
esac
