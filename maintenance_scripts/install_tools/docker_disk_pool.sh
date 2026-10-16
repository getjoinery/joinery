#!/usr/bin/env bash
#
# docker_disk_pool.sh - a Docker host's disk pool, and each site's allowance in
# it (specs/multi_tenant_docker_hosts.md WP4).
#
# Version: 2.7 - create --encrypted --no-tpm2 passes through: the TPM2 chip never opens the data root (B43).
# Version: 2.6 - can-cap on the site container engine refuses a volumes folder whose project is in the sites'
#                range or above: each new volume's folder would inherit it (reviewer2 V3).
# Version: 2.5 - A host whose sites run on the site container engine (specs/site_container_engine.md WP3)
#                has the pool too: the data root, with the engine's storage on it
#                (/srv/joinery/containers/storage, install_site_engine.sh). Its layer caps are
#                numbered like Docker's, from the project of the folder that holds them
#                (containers/storage drivers/quota), so create gives storage/overlay the base;
#                every site's ids are its own block, so a site cannot move its files out of
#                its project. Volume paths are asked of the engine (site_engine).
# Version: 2.4 - Docker's project ids and the sites' never meet (gvisor_site_sandbox_trial B1).
#                create gives Docker's data-root DOCKER_PROJID_BASE, which every folder Docker
#                makes inherits, so Docker numbers its layer caps above it however often it
#                restarts; can-cap refuses a host whose Docker folders do not carry it. allow
#                takes a site's id past every id in use on the pool as well as in /etc/projid,
#                counts only the sites' limits against the pool, and checks before it moves
#                any volume into a project. A site's backups and deploy projects are
#                joinery_<site>.<volume>: joinery_a_deploy was also the project of a site
#                named a_deploy, and release matched it.
# Version: 2.3 - create --encrypted passes through: the data root under the pool is born on a
#                LUKS2 volume (specs/hosted_data_promise_disk_encryption.md WP3).
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
#                                        at /srv/joinery/docker, which carries
#                                        the project Docker numbers its own
#                                        above (TWO NUMBERINGS).
#   docker_disk_pool.sh check            Exit 0 when the data root is mounted
#                                        with project quotas on and Docker's
#                                        data-root is in it; 1 when not.
#   docker_disk_pool.sh can-cap          Exit 0 when a site's disk can be capped
#                                        here: the pool in place, Docker
#                                        remapping user ids, and Docker's
#                                        project ids kept apart from the sites';
#                                        1, with the reason, when not.
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
# writes can use the rest, so PostgreSQL never meets the wall first.
#
# TWO NUMBERINGS. Docker gives each writable layer capped with --storage-opt
# size an XFS project of its own. It numbers them from one past the project id
# of the folders it keeps them in, and at every start from one past the
# highest id it finds in those folders, the sites' volumes among them (moby
# daemon/internal/quota). Left at 0, the first restart after a site's
# allowance would set Docker counting from the sites' ids, and a site and a
# neighbour's layer would share one limit. So Docker's data-root carries
# DOCKER_PROJID_BASE, with inheritance, from before Docker's first start:
# every folder Docker makes is born with it, and Docker's ids all lie above
# it. Site projects are numbered from SITE_PROJID_BASE, below it. A site's id
# is one past every id in use in that range, on the pool as well as in
# /etc/projid: a released site's files keep their id.
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
# The site container engine's storage, and the folder its layers (and their
# caps) live in (install_site_engine.sh's storage.conf).
ENGINE_STORAGE="/srv/joinery/containers/storage"
ENGINE_LAYERS="${ENGINE_STORAGE}/overlay"
STORAGE_CONF="${ROOT}/etc/containers/storage.conf"
PROJECTS="${ROOT}/etc/projects"
PROJID="${ROOT}/etc/projid"
SITES_DIR="${ROOT}/etc/joinery/sites"
SITE_PROJID_BASE=1000000000
# Docker's own ids lie above this one (TWO NUMBERINGS); below 2^31, for any
# tool that reads an id as signed.
DOCKER_PROJID_BASE=2000000000
# The volumes outside the allowance, each in a project of its own, named
# joinery_<site>.<volume>: no site name holds a dot, so no other site's
# joinery_<site> is ever one of them (a site may be named a_deploy).
OWN_PROJECT_VOLUMES="backups deploy"
HEADROOM_PCT=10
# Where the site reads its allowance: a file in its config volume.
ALLOWANCE_FILE="disk_allowance"
export DOCKER_DAEMON_JSON="${ROOT}/etc/docker/daemon.json"
# shellcheck source=_docker_daemon_json.sh
. "${SCRIPT_DIR}/_docker_daemon_json.sh"
# The engine this host's sites run on (site_engine_is_podman, site_engine).
[[ -f "${SCRIPT_DIR}/_site_engine.sh" ]] || { printf 'docker_disk_pool: _site_engine.sh is missing beside this script\n' >&2; exit 1; }
# shellcheck source=_site_engine.sh
. "${SCRIPT_DIR}/_site_engine.sh"

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
# Docker's data-root in it, or the site container engine's storage.
pool_mounted() {
    data_root check >/dev/null 2>&1 || return 1
    if site_engine_is_podman; then
        [[ "$(sed -n 's/^graphroot = "\(.*\)"$/\1/p' "$STORAGE_CONF" 2>/dev/null | tail -n 1)" == "$ENGINE_STORAGE" ]]
        return
    fi
    local dr
    dr="$(docker_daemon_json_get data-root)"
    dr="${dr#\"}"; dr="${dr%\"}"; dr="${dr%/}"
    [[ "$dr" == "${DOCKER_DATA_ROOT}" ]]
}

# A folder's own project id, not its contents', or nothing.
dir_projid() { xfs_io -r -c lsproj "$1" 2>/dev/null | awk '$1 == "projid" { print $3; exit }'; }

# Docker's data-root carries DOCKER_PROJID_BASE and passes it to every folder
# made in it (TWO NUMBERINGS). That folder only: what is already in it keeps
# its id.
docker_base_ensure() {
    local d="${ROOT}${DOCKER_DATA_ROOT}" id
    if site_engine_is_podman; then
        d="${ROOT}${ENGINE_LAYERS}"
        [[ -d "$d" ]] || { mkdir -p "$d" && chmod 700 "$d"; } || die "could not make ${ENGINE_LAYERS}"
    fi
    id="$(dir_projid "$d")"
    [[ "$id" =~ ^[0-9]+$ ]] && (( id >= DOCKER_PROJID_BASE )) && return 0
    xfs_io -r -c "chproj ${DOCKER_PROJID_BASE}" -c 'chattr +P' "$d" >/dev/null \
        || die "could not give ${d#"$ROOT"} project ${DOCKER_PROJID_BASE}, which keeps the engine's project ids apart from the sites'"
}

# Docker numbers its layer caps above the sites': the folders it keeps the
# layers and the volumes in, whose ids it starts from, carry the base.
docker_ids_apart() {
    local root home id
    if site_engine_is_podman; then
        id="$(dir_projid "${ROOT}${ENGINE_LAYERS}")"
        [[ "$id" =~ ^[0-9]+$ ]] && (( id >= DOCKER_PROJID_BASE ))
        return
    fi
    root="$(docker info -f '{{.DockerRootDir}}' 2>/dev/null)" || return 1
    [[ "$root" == /* ]] || return 1
    for home in overlay2 volumes; do
        id="$(dir_projid "${ROOT}${root}/${home}")"
        [[ "$id" =~ ^[0-9]+$ ]] && (( id >= DOCKER_PROJID_BASE )) || return 1
    done
}

# A site's disk can be capped here: the pool is in place, root in a container
# is not root on the host, and Docker's project ids stay apart from the
# sites'. Without remapping, a site's own processes own its files and can
# move them out of its project (chattr -p).
can_cap() {
    pool_mounted || { printf 'docker_disk_pool: this host has no disk pool\n' >&2; return 1; }
    if site_engine_is_podman; then
        # The folder the engine makes volumes in passes its project to each new
        # volume's own folder: one in the sites' range would put a new volume in
        # a site's allowance, one at the base among the layer caps. It carries
        # none (0) as the engine makes it, and must stay below the sites'.
        local vols_id
        vols_id="$(dir_projid "${ROOT}${ENGINE_STORAGE}/volumes")"
        [[ -z "$vols_id" || ( "$vols_id" =~ ^[0-9]+$ && "$vols_id" -lt "$SITE_PROJID_BASE" ) ]] \
            || { printf "docker_disk_pool: the site container engine's volumes folder (%s) carries project %s, at or above the sites' range (%s): each new volume would join it\n" "${ENGINE_STORAGE}/volumes" "$vols_id" "$SITE_PROJID_BASE" >&2; return 1; }
        # Every site's ids are a block of its own (idblock), never root's.
        docker_ids_apart \
            || { printf "docker_disk_pool: the site container engine's layer folder (%s) does not carry project %s, so it would number its layer caps among the sites' allowances; run docker_disk_pool.sh create\n" "$ENGINE_LAYERS" "$DOCKER_PROJID_BASE" >&2; return 1; }
        return 0
    fi
    [[ -n "$(docker_daemon_json_get userns-remap)" ]] \
        || { printf 'docker_disk_pool: Docker does not remap user ids here, so a site could move its own files out of its allowance\n' >&2; return 1; }
    docker_ids_apart \
        || { printf "docker_disk_pool: Docker's folders here do not carry project %s, so after a restart Docker would number its layer caps among the sites' allowances and a site could share a neighbour's limit\n" "$DOCKER_PROJID_BASE" >&2; return 1; }
}

do_create() {
    # --encrypted: the data root under the pool is born on a LUKS2 volume
    # (specs/hosted_data_promise_disk_encryption.md WP3).
    local want="" rc=0 enc="" notpm=""
    local a
    for a in "$@"; do
        case "$a" in
            --encrypted) enc="--encrypted" ;;
            --no-tpm2) notpm="--no-tpm2" ;;
            *) want="$a" ;;
        esac
    done
    [[ -z "$notpm" || -n "$enc" ]] || die "--no-tpm2 goes with --encrypted: only an encrypted data root has a TPM2 slot"
    if [[ -n "$want" && "$want" != /dev/* ]]; then
        [[ "$want" =~ ^[0-9]+[KMGT]?$ ]] || die "the size is a number with G, M or T (68G), or a device under /dev, not '${want}'"
    fi
    [[ "$EUID" -eq 0 || -n "$ROOT" ]] || die "this must be run as root"

    # The site container engine keeps its storage on the data root from its
    # first start (install.sh docker --site-engine): the pool is the data root
    # and its layer folder's project, set before the first layer if it can be.
    # Layers made before keep their ids, which lie far below the sites'.
    if site_engine_is_podman; then
        pool_mounted || die "this host's site container engine keeps no storage on a mounted data root (${ENGINE_STORAGE}); see: joinery_data_root.sh status, install_site_engine.sh converge"
        docker_base_ensure
        say "The disk pool is in place: the site container engine keeps its data at ${ENGINE_STORAGE}, on the data root"
        return 0
    fi

    if pool_mounted; then
        # A pool made before its data-root carried the base takes it here; it
        # reaches only the folders Docker makes from now on, such as the
        # ones a first start with remapping makes.
        docker_base_ensure
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
        2) data_root create ${enc:+"$enc"} ${notpm:+"$notpm"} ${want:+"$want"} || die "the data root could not be made; nothing else was changed" ;;
        *) die "this host has a data root that is not mounted: $(data_root check 2>&1 || true). Nothing was changed." ;;
    esac

    mkdir -p "${ROOT}${DOCKER_DATA_ROOT}"
    chmod 710 "${ROOT}${DOCKER_DATA_ROOT}"
    # Before Docker's first start, so every folder it makes is born with it.
    docker_base_ensure
    docker_daemon_json_set data-root "\"${DOCKER_DATA_ROOT}\"" || die "could not set data-root in $(docker_daemon_json_path) (is it valid JSON?)"
    pool_mounted || die "the data root is mounted, but the pool is not in place; see: joinery_data_root.sh status"
    say "The disk pool is in place: Docker keeps its data at ${DOCKER_DATA_ROOT}, on the data root"
}

# ---------------------------------------------------------------------------
# Projects
# ---------------------------------------------------------------------------

# A project's id from /etc/projid, or nothing.
projid_of() { awk -F: -v n="$1" '$1 == n { print $2; exit }' "$PROJID" 2>/dev/null || true; }

# Every project on the pool with usage or a limit, one line each: #id, then
# blocks used, soft and hard limits in KiB.
pool_report() { xfs_quota -x -c 'report -p -n -b -N' "$MNT" 2>/dev/null || true; }

# A project's id, made when it has none: one past every id in the sites' range
# in use, named in /etc/projid or holding files on the pool (INUSE, the ids
# the report lists). Under the caller's lock.
projid_ensure() {
    local name="$1" inuse="$2" id
    id="$(projid_of "$name")"
    if [[ -z "$id" ]]; then
        id="$( { awk -F: '{ print $2 }' "$PROJID"; printf '%s\n' "$inuse"; } \
            | awk -v b="$SITE_PROJID_BASE" -v d="$DOCKER_PROJID_BASE" '$1 ~ /^[0-9]+$/ && $1 + 0 >= b && $1 + 0 < d && $1 + 0 > m { m = $1 + 0 } END { print (m ? m + 1 : b + 1) }')"
        (( id < DOCKER_PROJID_BASE )) || die "no project id is left below Docker's, ${DOCKER_PROJID_BASE}"
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
        mp="$(site_engine volume inspect -f '{{.Mountpoint}}' "$name" 2>/dev/null)" || continue
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
    local site="${1:-}" want="${2:-}" bytes hard id own_id suffix mp config_mp="" report inuse ours
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
    report="$(pool_report)"
    # Every site's hard limit together must fit the pool: a site at its limit
    # must never be able to fill the disk another site writes to. Under the
    # lock, so two allowances set at once are counted together, and before
    # anything is moved, so a refusal leaves the site as it was. The other
    # sites' projects are the ones /etc/projid names; Docker's layer caps are
    # not allowances.
    local pool_total others
    ours="$(awk -F: -v me="joinery_${site}" '$1 ~ /^joinery_/ && $1 != me { printf " %s", $2 }' "$PROJID")"
    others="$(awk -v ours="${ours} " '{ p = $1; sub(/^#/, "", p) } p != "" && index(ours, " " p " ") { s += $4 } END { printf "%.0f", s * 1024 }' <<< "$report")"
    pool_total="$(df -B1 --output=size "$MNT" | tail -n 1 | tr -d ' ' || true)"
    if [[ "$pool_total" =~ ^[0-9]+$ ]] && (( ${others:-0} + hard > pool_total )); then
        flock -u 9
        die "${want} for ${site} would promise more than the pool holds: the other sites' limits come to $(( ${others:-0} / 1024 / 1024 )) MB, this one's $(( hard / 1024 / 1024 )) MB with its 10%, and the pool is $(( pool_total / 1024 / 1024 )) MB. Give a smaller allowance, or move a site to another host."
    fi
    inuse="$(awk '{ p = $1; sub(/^#/, "", p); print p }' <<< "$report")"
    id="$(projid_ensure "joinery_${site}" "$inuse")"
    projects_set "$id" "${mine[@]}"
    for suffix in "${!own[@]}"; do
        own_id="$(projid_ensure "joinery_${site}.${suffix}" "$inuse")"
        projects_set "$own_id" "${own[$suffix]}"
        xfs_quota -x -c "project -s -p ${own[$suffix]} ${own_id}" "$MNT" >/dev/null
    done
    # project -s marks each directory to pass the project to everything made
    # in it, and gives every file already there the project.
    for mp in "${mine[@]}"; do
        xfs_quota -x -c "project -s -p ${mp} ${id}" "$MNT" >/dev/null
    done
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
    for name in "joinery_${site}" $(for s in $OWN_PROJECT_VOLUMES; do printf 'joinery_%s.%s ' "$site" "$s"; done); do
        id="$(projid_of "$name")"
        [[ -n "$id" ]] || continue
        pool_mounted && xfs_quota -x -c "limit -p bhard=0 ${id}" "$MNT" >/dev/null 2>&1 || true
        projects_set "$id"
        awk -F: -v n="$name" '$1 != n' "$PROJID" > "${PROJID}.tmp.$$" && mv -f "${PROJID}.tmp.$$" "$PROJID"
    done
    flock -u 9
}

do_show() {
    local site="${1:-}"
    site_name_ok "$site" || die "'${site}' is not a site name"
    cat "${SITES_DIR}/${site}/disk_allowance" 2>/dev/null || true
}

case "${1:-}" in
    create)  shift; do_create "$@" ;;
    check)   pool_mounted ;;
    can-cap) can_cap ;;
    allow)   do_allow "${2:-}" "${3:-}" ;;
    release) do_release "${2:-}" ;;
    show)    do_show "${2:-}" ;;
    *) die "usage: docker_disk_pool.sh create [SIZE|DEVICE] | check | can-cap | allow SITE SIZE | release SITE | show SITE" ;;
esac
