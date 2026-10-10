#!/usr/bin/env bash
#
# joinery_data_root.sh - the host's data root: everything that is data, under
# /srv/joinery, on a filesystem of its own (specs/one_data_root.md).
#
# Version: 1.3 - migrate: an install that keeps its data where it always did moves it onto the
#                data root (one_data_root WP3, D5). The packages' directories, every site's six
#                folders and Docker's data-root are copied with every service stopped, checked,
#                and switched; the copies from before stay at /srv/joinery.old until the data
#                root passes check after a reboot, when tick removes them. A move that does not
#                finish puts everything back. Review (reviewer2): the host converger's timer
#                and path stop first and a run waiting on the lock is stopped (F1); no boot id,
#                no move (F2); the log records a stopped move's put-back (F3); the stopped
#                units are masked for the move and nothing may hold a file at the switch (F4);
#                an empty place it filled goes on a put-back (F5); a lacking folder is made
#                like its siblings (F6); the copies from before are removed on one filesystem (F7).
# Version: 1.2 - bind and unbind: a path the platform uses (a site's uploads, Postgres's
#                directory) is a bind mount of its place under the data root, recorded in
#                /etc/joinery/data_binds; the target requires every one, check fails while one
#                is not in place, tick mounts it again, status lists them (one_data_root WP2, D1).
#                unbind lets the target go before the mount, so no consumer stops with it, and
#                leaves the path usable; remove-site NAME takes a removed site's binds and data
#                (reviewer2 F1, F2, F4).
# Version: 1.1 - review (reviewer2): tick exits non-zero only when the data root is not ready; a
#                growth or reload that fails on a mounted one is said and absorbed (F1).
#                The first size is whole GiB (F6). create says when it ignores a SIZE (F7). A
#                device is read by its UUID path (F8). status counts each directory for at
#                most 20 seconds (F9). create says what to run when its units cannot be written (N1).
# Version: 1.0
#
#   joinery_data_root.sh create [SIZE] [DEVICE]
#                     Root. Makes the data root. With DEVICE (a path under
#                     /dev, holding no filesystem) it is formatted there;
#                     otherwise it is a fully allocated file, /srv/joinery.img,
#                     of SIZE (16G, 500M), or of the first size D7 gives when
#                     SIZE is left out. XFS, mounted at /srv/joinery with
#                     project quotas through /etc/fstab, and every service
#                     whose data lives there waits for it.
#   joinery_data_root.sh check
#                     0 when the data root is mounted as it should be; 1, with
#                     the reason, when this host has one and it is not; 2 when
#                     this host has none.
#   joinery_data_root.sh grow [SIZE]
#                     Root. A file grows in place, to SIZE or by D7's step. A
#                     device grows once its provider has grown it.
#   joinery_data_root.sh status
#                     What backs it, its size, use and free space, whether it
#                     can still grow, and what is under it.
#   joinery_data_root.sh tick
#                     Root. The host converger's call, every minute: check,
#                     keep the units, mount a recorded bind that is not in
#                     place, and grow when D7 says so. Exits as check.
#   joinery_data_root.sh bind REL TARGET
#                     Root. TARGET (an absolute path, /var/lib/postgresql) is
#                     from then on a bind mount of /srv/joinery/REL (postgresql),
#                     recorded in /etc/joinery/data_binds, and the target
#                     requires it. A new REL takes TARGET's owner and mode. Run
#                     again, it does nothing. Exit 3 when TARGET holds data:
#                     moving data is migrate's, and nothing is changed.
#   joinery_data_root.sh unbind TARGET
#                     Root. TARGET is no longer mounted from the data root; its
#                     data stays where it is, under /srv/joinery, and TARGET is
#                     left an empty folder with that place's owner and mode.
#                     The target stops requiring it before it is unmounted, so
#                     no service that waits for the data root stops with it.
#   joinery_data_root.sh remove-site NAME
#                     Root. A site is being removed: every bind under
#                     /srv/joinery/sites/NAME is taken away and that data is
#                     removed. Nothing on a host with no data root.
#   joinery_data_root.sh migrate [SIZE|DEVICE]
#                     Root. This host's data moves onto the data root, made
#                     first when it has none (of SIZE, on DEVICE, or sized to
#                     hold the data): PostgreSQL, Postfix's queue and rspamd's
#                     state, each site's six folders and a test site's logs,
#                     each from then on a bind mount; and Docker's data-root,
#                     set to /srv/joinery/docker. Every service whose data
#                     moves is stopped for the copy, so the downtime is the
#                     time to copy the data once. Refused, with every reason,
#                     before anything changes when the root disk cannot hold
#                     the data twice: the copy from before is kept at
#                     /srv/joinery.old until the data root has passed check
#                     after a reboot, then tick removes it. A move that does
#                     not finish puts everything back and starts the services.
#                     Run again, it moves only what is not moved.
#
# WHY A FILESYSTEM OF ITS OWN. One place to measure, cap, grow, back up and
# move, the same on every install; and the one shape that can later be
# encrypted underneath with nothing above it changing.
#
# THE FILE. fallocate allocates every block, so the data root can never
# promise space the disk does not have. The loop device reads the file with
# direct I/O, so the host does not cache each page twice. The fstab line
# carries nofail, so a data root that fails to mount does not stop the host
# booting, and the agent can still come up and say why.
#
# THE SIZE (D7). XFS grows but never shrinks, so the file starts modest and
# grows as it fills: space handed to the data root never comes back to the
# root disk, which upgrades unpack on. The root disk always keeps its reserve,
# above the agent's disk_headroom floor, so growing here can never be what
# opens a disk case there. When the root disk has nothing more to give, the
# data root fills like any disk and that recipe's floor speaks for it.
#
# THE UNITS (D3). joinery-data.target is reached when /srv/joinery is
# mounted. Every service whose data lives there requires it, so none starts
# on an empty directory on the root disk. The drop-ins exist only where a
# data root does: a Requires= naming a target that does not exist would stop
# the unit starting.
#
# THE BINDS (D1). The paths the platform and its packages use stay as they
# are; each is a bind mount of its place here. Not a symlink: tar, find and
# the site census treat a symlink as one small file and never go inside it,
# so a backup would quietly stop carrying the data behind it. The target
# requires every recorded bind, so a consumer waits for its folders too. The
# directory underneath each one is root's, mode 000 and immutable: while the
# data root is down, nothing writes to the root disk in its place.

set -euo pipefail

# Tests point ROOT at a scratch directory and stub the system's commands.
# Honoured only when this is not root: a root run works on the host.
ROOT=""
if [[ "$EUID" -ne 0 ]]; then
    ROOT="${JOINERY_HOST_ROOT:-}"
fi

MNT_REAL="/srv/joinery"
IMAGE_REAL="/srv/joinery.img"
MNT="${ROOT}${MNT_REAL}"
IMAGE="${ROOT}${IMAGE_REAL}"
CONF="${ROOT}/etc/joinery/data_root"
FSTAB="${ROOT}/etc/fstab"
SYSTEMD_DIR="${ROOT}/etc/systemd/system"
LOCK_FILE="${ROOT}/run/joinery/data-root.lock"
CANNOT_GROW_FLAG="${ROOT}/run/joinery/data-root.cannot-grow"
BINDS="${ROOT}/etc/joinery/data_binds"
TARGET_UNIT="joinery-data.target"
MOUNT_UNIT="srv-joinery.mount"
DIO_UNIT="joinery-data-dio.service"
DROPIN_NAME="joinery-data-root.conf"
FSTAB_MARK="# joinery-data-root (joinery_data_root.sh)"
BIND_MARK="# Written by joinery_data_root.sh (specs/one_data_root.md D1): a bind mount."
# Every service whose data lives under the data root (D1). A drop-in for a
# unit that is not installed does nothing, and is there when it is.
CONSUMERS="postgresql.service postgresql@.service docker.service containerd.service apache2.service postfix.service postfix@.service rspamd.service"

# migrate (D5). The sites, and the six folders of each that are its data.
SITES_REAL="/var/www/html"
SITE_FOLDERS="uploads static_files storage backups logs cache"
# The copies from before a move, on the root disk, until the data root has
# passed check after a reboot; and the record that says which boot moved them.
OLD_REAL="/srv/joinery.old"
OLD="${ROOT}${OLD_REAL}"
MIGRATED="${ROOT}/etc/joinery/data_root_migrated"
BOOT_ID_FILE="/proc/sys/kernel/random/boot_id"
[[ -z "$ROOT" ]] || BOOT_ID_FILE="${ROOT}/proc/boot_id"
DOCKER_DATA_ROOT="${MNT_REAL}/docker"
MIGRATE_UNIT="joinery-data-root-migrate.service"
MIGRATE_LOG="/var/log/joinery-data-root-migrate.log"
# The host converger: its timer and path trigger stop for the move, so no run
# starts and waits on a site's logs (reviewer2 F1).
MIGRATE_CONVERGER=(joinery-host-converger.timer joinery-host-converger.path)
# Masked for the move, besides every unit it stopped: what would otherwise
# start on its own and write where the data moves from (reviewer2 F4).
MIGRATE_MASK_EXTRA=(logrotate.service apt-daily-upgrade.service joinery-host-converger.service)
MIGRATE_XATTR_SKIP=(--filter='-x trusted.SGI_ACL_FILE' --filter='-x trusted.SGI_ACL_DEFAULT')
# What migrate stops while it copies: every consumer, and cron, whose
# scheduled tasks write into a site's folders on their own.
MIGRATE_STOP=('postgresql*.service' 'docker.socket' 'docker.service' 'containerd.service' 'apache2.service'
              'php*-fpm.service' 'postfix*.service' 'rspamd.service' 'cron.service')

GIB=$((1024 * 1024 * 1024))
# D7, the size rules. In one place, so the numbers in the spec are these.
FIRST_MIN=$((16 * GIB))         # the first size: the larger of this...
FIRST_ROOT_PCT=25               # ...and this share of the root disk
CREATE_FLOOR=$((4 * GIB))       # less than this to give: not made
RESERVE_MIN=$((6 * GIB))        # the root disk keeps the larger of this...
RESERVE_PCT=15                  # ...and this share of itself
GROW_WHEN_MIN=$((8 * GIB))      # grow when free is under the larger of this...
GROW_WHEN_PCT=20                # ...and this share of the data root
GROW_PLUS=$((12 * GIB))         # grow to at least used plus this
GROW_STEP_MIN=$GIB              # less than this to give: no growth

say() { printf '%s\n' "$*"; }
die() { printf 'joinery_data_root: %s\n' "$*" >&2; exit 1; }

# SIZE (16G, 500M, 1T, or bytes) in bytes, or nothing when it is not one.
size_bytes() {
    local s="${1:-}" n u
    [[ "$s" =~ ^([0-9]+)([KMGT]?)$ ]] || return 1
    n="${BASH_REMATCH[1]}"; u="${BASH_REMATCH[2]}"
    case "$u" in
        K) n=$((n * 1024)) ;;
        M) n=$((n * 1024 * 1024)) ;;
        G) n=$((n * GIB)) ;;
        T) n=$((n * 1024 * GIB)) ;;
    esac
    (( n > 0 )) || return 1
    printf '%s' "$n"
}

# Bytes as GiB with one decimal.
gib() { awk -v b="$1" 'BEGIN { printf "%.1f GiB", b / 1073741824 }'; }

max() { local m="$1" v; shift; for v in "$@"; do (( v > m )) && m="$v"; done; printf '%s' "$m"; }

# "size used avail" in bytes, of the filesystem PATH is on.
df_bytes() {
    local line
    line="$(df -B1 --output=size,used,avail "$1" 2>/dev/null | tail -n 1)" || return 1
    set -- $line
    [[ "${1:-}" =~ ^[0-9]+$ && "${2:-}" =~ ^[0-9]+$ && "${3:-}" =~ ^[0-9]+$ ]] || return 1
    printf '%s %s %s\n' "$1" "$2" "$3"
}

# The root disk: the filesystem the image file lives on.
root_disk_path() { local p="${ROOT}/srv"; [[ -d "$p" ]] || p="${ROOT:-/}"; printf '%s' "$p"; }

# What the root disk can give the data root: its free space above its reserve.
root_givable() {
    local size used avail reserve
    read -r size used avail < <(df_bytes "$(root_disk_path)") || return 1
    reserve="$(max "$RESERVE_MIN" $(( size * RESERVE_PCT / 100 )))"
    printf '%s' $(( avail > reserve ? avail - reserve : 0 ))
}

conf_get() { awk -F= -v k="$1" '$1 == k { sub(/^[^=]*=/, ""); print; exit }' "$CONF" 2>/dev/null || true; }

# Every recorded bind, "REL TARGET" a line.
binds() { awk 'NF == 2 && $1 !~ /^#/ { print $1, $2 }' "$BINDS" 2>/dev/null || true; }

# The mount unit for TARGET.
bind_unit() { systemd-escape -p --suffix=mount "$1"; }

# A path under the data root: names of letters, digits, . _ -, none leading
# with a dot, so no .. and nothing hidden.
rel_ok() { [[ "${1:-}" =~ ^[A-Za-z0-9][A-Za-z0-9._-]*(/[A-Za-z0-9][A-Za-z0-9._-]*)*$ ]]; }

# An absolute path outside the data root, with no . or .. in it.
target_ok() {
    local t="${1:-}"
    [[ "$t" =~ ^(/[A-Za-z0-9][A-Za-z0-9._-]*)+$ ]] || return 1
    [[ "$t" != "$MNT_REAL" && "$t" != "$MNT_REAL"/* && "$t" != "$IMAGE_REAL" ]]
}

# TARGET is the data root's REL, the same directory: the bind is in place.
bind_ok() {  # REL TARGET
    local want got
    want="$(stat -L -c %d:%i "${MNT}/$1" 2>/dev/null)" || return 1
    got="$(stat -L -c %d:%i "${ROOT}$2" 2>/dev/null)" || return 1
    [[ "$want" == "$got" ]]
}

# The data root is mounted at /srv/joinery, XFS, with project quotas on.
mounted_ok() {
    local fstype opts
    read -r fstype opts < <(findmnt -n -o FSTYPE,OPTIONS --mountpoint "$MNT" 2>/dev/null || true) || true
    [[ "${fstype:-}" == "xfs" && ",${opts:-}," == *",prjquota,"* ]]
}

# Why a declared data root is not right, or nothing when it is.
check_reason() {
    local backing source fstype opts
    backing="$(conf_get backing)"; source="$(conf_get source)"
    case "$backing" in
        file)   [[ -f "${ROOT}${source}" ]] || { printf '%s, which backs the data root, is missing' "$source"; return; } ;;
        device) ;;
        *)      printf '%s does not say what backs the data root' "$CONF"; return ;;
    esac
    read -r fstype opts < <(findmnt -n -o FSTYPE,OPTIONS --mountpoint "$MNT" 2>/dev/null || true) || true
    if [[ -z "${fstype:-}" ]]; then
        printf '%s is not mounted (see: journalctl -u %s)' "$MNT_REAL" "$MOUNT_UNIT"
    elif [[ "$fstype" != "xfs" ]]; then
        printf '%s is mounted as %s, not XFS' "$MNT_REAL" "$fstype"
    elif [[ ",${opts:-}," != *",prjquota,"* ]]; then
        printf '%s is mounted without project quotas' "$MNT_REAL"
    else
        local rel target
        while read -r rel target; do
            bind_ok "$rel" "$target" && continue
            printf '%s is not mounted from %s/%s (see: systemctl status %s)' "$target" "$MNT_REAL" "$rel" "$(bind_unit "$target")"
            return
        done < <(binds)
    fi
}

do_check() {
    [[ -f "$CONF" ]] || { say "this host has no data root"; return 2; }
    local why
    why="$(check_reason)"
    [[ -z "$why" ]] && return 0
    printf 'joinery_data_root: %s\n' "$why" >&2
    return 1
}

need_root() { [[ "$EUID" -eq 0 || -n "$ROOT" ]] || die "this must be run as root"; }

LOCK_HELD=0
take_lock() {  # [nowait]
    # Once per run: migrate holds it and calls create, which takes it too.
    (( LOCK_HELD )) && return 0
    mkdir -p "$(dirname "$LOCK_FILE")"
    exec 9>>"$LOCK_FILE"
    if [[ "${1:-}" == "nowait" ]]; then
        flock -n 9 || return 1
    else
        flock -w 600 9 || die "another run holds ${LOCK_FILE}"
    fi
    LOCK_HELD=1
}

# ---------------------------------------------------------------------------
# Units
# ---------------------------------------------------------------------------

target_text() {
    local backing="$1"
    cat <<EOF
# Written by joinery_data_root.sh (specs/one_data_root.md D3).
# Reached when ${MNT_REAL}, the data root, is mounted. Every service whose
# data lives there requires this, so none starts without it.
[Unit]
Description=Joinery data root: ${MNT_REAL} is mounted
Requires=${MOUNT_UNIT}
After=${MOUNT_UNIT}
EOF
    if [[ "$backing" == "file" ]]; then
        printf 'Wants=%s\nAfter=%s\n' "$DIO_UNIT" "$DIO_UNIT"
    fi
    local rel target unit
    while read -r rel target; do
        unit="$(bind_unit "$target")"
        printf 'Requires=%s\nAfter=%s\n' "$unit" "$unit"
    done < <(binds)
    printf '\n[Install]\nWantedBy=multi-user.target\n'
}

bind_unit_text() {  # REL TARGET
    cat <<EOF
${BIND_MARK}
# ${2} is ${MNT_REAL}/${1}, on the data root.
[Unit]
Description=Joinery data root: ${2}
Requires=${MOUNT_UNIT}
After=${MOUNT_UNIT}
Before=${TARGET_UNIT}

[Mount]
What=${MNT_REAL}/${1}
Where=${2}
Type=none
Options=bind
EOF
}

dio_unit_text() {
    cat <<EOF
# Written by joinery_data_root.sh (specs/one_data_root.md D2).
# The data root's loop device reads its file with direct I/O, so the host
# does not keep every page twice in its cache.
[Unit]
Description=Joinery data root: direct I/O on its loop device
After=${MOUNT_UNIT}
Requires=${MOUNT_UNIT}
Before=${TARGET_UNIT}

[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart=/bin/sh -c 'for d in \$(losetup -n -O NAME -j ${IMAGE_REAL}); do losetup --direct-io=on "\$d" || true; done'

[Install]
WantedBy=${TARGET_UNIT}
EOF
}

dropin_text() {
    cat <<EOF
# Written by joinery_data_root.sh (specs/one_data_root.md D3).
# This service's data lives under ${MNT_REAL}. Without the data root mounted
# it does not start: it would make an empty data directory on the root disk
# and run on that.
[Unit]
Requires=${TARGET_UNIT}
After=${TARGET_UNIT}
EOF
}

# Every consumer: the fixed list, and each PHP-FPM version installed.
consumer_units() {
    local f
    printf '%s\n' $CONSUMERS
    for f in "${ROOT}"/lib/systemd/system/php*-fpm.service "${ROOT}"/usr/lib/systemd/system/php*-fpm.service; do
        [[ -f "$f" ]] && basename "$f"
    done
    return 0
}

# Write PATH with TEXT when it differs: 0 written, 1 already right, 2 failed.
write_if_changed() {  # PATH TEXT
    if [[ -f "$1" ]] && [[ "$(cat "$1")" == "$2" ]]; then
        return 1
    fi
    { mkdir -p "$(dirname "$1")" \
        && printf '%s\n' "$2" > "$1.tmp.$$" \
        && chmod 644 "$1.tmp.$$" \
        && mv -f "$1.tmp.$$" "$1"; } || { rm -f "$1.tmp.$$"; return 2; }
    return 0
}

# The target, the direct I/O unit and every consumer's drop-in, as they
# should be. Quiet when nothing changed; systemd reloads only when something
# did.
ensure_units() {
    local backing changed=0 failed=0 unit
    # Every check is explicit: tick runs this where errexit does not reach.
    put() {
        local r=0
        write_if_changed "$1" "$2" || r=$?
        (( r == 0 )) && changed=1
        (( r == 2 )) && { failed=1; printf 'joinery_data_root: could not write %s\n' "$1" >&2; }
        return 0
    }
    backing="$(conf_get backing)"
    put "${SYSTEMD_DIR}/${TARGET_UNIT}" "$(target_text "$backing")"
    if [[ "$backing" == "file" ]]; then
        put "${SYSTEMD_DIR}/${DIO_UNIT}" "$(dio_unit_text)"
    elif [[ -f "${SYSTEMD_DIR}/${DIO_UNIT}" ]]; then
        rm -f "${SYSTEMD_DIR}/${DIO_UNIT}"; changed=1
    fi
    while IFS= read -r unit; do
        put "${SYSTEMD_DIR}/${unit}.d/${DROPIN_NAME}" "$(dropin_text)"
    done < <(consumer_units | sort -u)
    local rel target f keep=" "
    while read -r rel target; do
        unit="$(bind_unit "$target")"
        keep+="${unit} "
        put "${SYSTEMD_DIR}/${unit}" "$(bind_unit_text "$rel" "$target")"
    done < <(binds)
    # A bind this script wrote and no longer records.
    for f in "${SYSTEMD_DIR}"/*.mount; do
        [[ -f "$f" ]] || continue
        [[ "$keep" == *" $(basename "$f") "* ]] && continue
        [[ "$(head -n 1 "$f")" == "$BIND_MARK" ]] || continue
        if rm -f "$f"; then changed=1; fi
    done
    if (( changed )); then
        if ! systemctl daemon-reload; then
            printf 'joinery_data_root: systemctl daemon-reload failed; the units are written and take effect at the next reload\n' >&2
            return 1
        fi
        systemctl enable "$TARGET_UNIT" >/dev/null 2>&1 || true
        [[ "$backing" == "file" ]] && { systemctl enable "$DIO_UNIT" >/dev/null 2>&1 || true; }
        say "data root: units written"
    fi
    (( failed == 0 ))
}

# ---------------------------------------------------------------------------
# create
# ---------------------------------------------------------------------------

do_create() {
    local arg want="" device="" bytes givable fsize
    for arg in "$@"; do
        if [[ "$arg" == /* ]]; then device="$arg"; else want="$arg"; fi
    done
    need_root
    take_lock

    if [[ -f "$CONF" ]]; then
        if mounted_ok; then
            ensure_units || die "the data root is mounted, but its units could not be written; fix the reason above, then run this again"
            say "The data root is already mounted at ${MNT_REAL}"
            [[ -z "$want" ]] || say "The size ${want} was not used: an existing data root grows by itself (or: joinery_data_root.sh grow ${want})"
            return 0
        fi
        die "this host has a data root (${CONF}) that is not mounted: $(check_reason). Nothing was changed."
    fi
    if findmnt -n --mountpoint "$MNT" >/dev/null 2>&1; then
        die "something is already mounted at ${MNT_REAL}, and it is not a data root this script made. Nothing was changed."
    fi
    if [[ -n "$(find "$MNT" -mindepth 1 -maxdepth 1 2>/dev/null | head -n 1 || true)" ]]; then
        die "${MNT_REAL} is not empty; the data root would hide what is in it. Nothing was changed."
    fi

    if ! command -v mkfs.xfs >/dev/null 2>&1; then
        say "Installing xfsprogs..."
        DEBIAN_FRONTEND=noninteractive apt-get install -y xfsprogs >/dev/null || die "could not install xfsprogs"
    fi

    if [[ -n "$device" ]]; then
        [[ -z "$want" ]] || die "a data root on a device takes the device's size; give the device or a size, not both"
        [[ "$device" == /dev/* ]] || die "'${device}' is not a device under /dev"
        [[ -b "${ROOT}${device}" || ( -n "$ROOT" && -e "${ROOT}${device}" ) ]] || die "${device} is not a block device here"
        if findmnt -n -S "$device" >/dev/null 2>&1; then
            die "${device} is mounted; a data root needs a device nothing is using"
        fi
        # A device that carries anything (a filesystem, a partition table, a
        # RAID member) is somebody's. Wiping it is a hand's decision.
        if blkid -p "$device" >/dev/null 2>&1; then
            die "${device} already carries a filesystem or partition table. If it is spare, wipe it first (wipefs -a ${device}), then run this again."
        fi
        say "Formatting ${device} for the data root..."
        mkfs.xfs -q -n ftype=1 "$device" || die "could not format ${device}"
        local uuid
        uuid="$(blkid -s UUID -o value "$device" 2>/dev/null || true)"
        [[ "$uuid" =~ ^[0-9a-fA-F-]{8,}$ ]] || die "${device} has no UUID after formatting; see blkid ${device}"
        mkdir -p "$(dirname "$CONF")"
        printf '# Written by joinery_data_root.sh (specs/one_data_root.md). What backs %s.\nbacking=device\nsource=%s\nuuid=%s\n' \
            "$MNT_REAL" "$device" "$uuid" > "$CONF"
        if ! grep -qF "$FSTAB_MARK" "$FSTAB" 2>/dev/null; then
            printf '%s\nUUID=%s %s xfs prjquota,nofail 0 0\n' "$FSTAB_MARK" "$uuid" "$MNT_REAL" >> "$FSTAB"
        fi
    else
        [[ ! -e "$IMAGE" ]] || die "${IMAGE_REAL} exists but no data root is recorded. Mount it or remove it, then run this again."
        givable="$(root_givable)" || die "could not read the root disk's free space"
        if [[ -n "$want" ]]; then
            bytes="$(size_bytes "$want")" || die "the size is a number with G, M or T (16G), not '${want}'"
            (( bytes <= givable )) || die "a data root of ${want} would leave the root disk under its reserve; it can give $(gib "$givable"). Choose a smaller size."
        else
            # D7: modest, and grown as it fills.
            local rsize
            read -r rsize _ _ < <(df_bytes "$(root_disk_path)") || die "could not read the root disk's size"
            bytes="$(max "$FIRST_MIN" $(( rsize * FIRST_ROOT_PCT / 100 / GIB * GIB )))"
            (( bytes <= givable )) || bytes=$(( givable / GIB * GIB ))
            (( bytes >= CREATE_FLOOR )) || die "the root disk can give the data root only $(gib "$givable") above its reserve; a data root needs at least $(gib "$CREATE_FLOOR")"
        fi
        say "Allocating the data root: $(gib "$bytes") at ${IMAGE_REAL}..."
        mkdir -p "$(dirname "$IMAGE")"
        fallocate -l "$bytes" "$IMAGE" || { rm -f "$IMAGE"; die "could not allocate $(gib "$bytes") on the root disk"; }
        chmod 600 "$IMAGE"
        # ftype=1: overlay2, Docker's storage driver, needs the file type in
        # directory entries; a data root is the same filesystem everywhere.
        mkfs.xfs -q -n ftype=1 "$IMAGE" || { rm -f "$IMAGE"; die "could not format the data root"; }
        mkdir -p "$(dirname "$CONF")"
        printf '# Written by joinery_data_root.sh (specs/one_data_root.md). What backs %s.\nbacking=file\nsource=%s\n' \
            "$MNT_REAL" "$IMAGE_REAL" > "$CONF"
        if ! grep -qF "$FSTAB_MARK" "$FSTAB" 2>/dev/null; then
            printf '%s\n%s %s xfs loop,prjquota,nofail 0 0\n' "$FSTAB_MARK" "$IMAGE_REAL" "$MNT_REAL" >> "$FSTAB"
        fi
    fi
    chmod 644 "$CONF"

    mkdir -p "$MNT"
    ensure_units || die "the data root is made and in /etc/fstab, but its units could not be written; fix the reason above, then run: systemctl daemon-reload && mount ${MNT_REAL} && systemctl start ${TARGET_UNIT}"
    mount "$MNT" || die "the data root did not mount at ${MNT_REAL}; see dmesg. /etc/fstab keeps its line, so fix it and run: mount ${MNT_REAL}"
    mounted_ok || die "${MNT_REAL} is mounted, but not as XFS with project quotas on"
    chmod 755 "$MNT"
    systemctl start "$TARGET_UNIT" || say "WARNING: ${TARGET_UNIT} did not start; see: systemctl status ${TARGET_UNIT}"
    read -r fsize _ _ < <(df_bytes "$MNT") || fsize=0
    say "The data root is mounted at ${MNT_REAL}: $(gib "$fsize"), XFS with project quotas"
}

# ---------------------------------------------------------------------------
# bind, unbind
# ---------------------------------------------------------------------------

# The directory underneath a bind: root's, mode 000 and immutable, so while
# the data root is down nothing writes to the root disk in its place. The
# immutable flag needs a filesystem that has one; the mode alone stops all but
# root.
lock_mountpoint() {
    chown root:root "$1" 2>/dev/null || true
    chmod 000 "$1"
    chattr +i "$1" 2>/dev/null || true
}

# Mount a recorded bind that is not in place. Quiet when it is.
start_bind() {  # REL TARGET
    bind_ok "$1" "$2" && return 0
    systemctl start "$(bind_unit "$2")" || return 1
    bind_ok "$1" "$2"
}

do_bind() {
    local rel="${1:-}" target="${2:-}" cur other fresh=0
    need_root
    rel_ok "$rel" || die "'${rel}' is not a path under the data root (postgresql, sites/mysite/uploads)"
    target_ok "$target" || die "'${target}' is not an absolute path outside the data root"
    mounted_ok || die "the data root is not mounted: $(check_reason)"
    take_lock

    cur="$(binds | awk -v t="$target" '$2 == t { print $1; exit }')"
    other="$(binds | awk -v r="$rel" -v t="$target" '$1 == r && $2 != t { print $2; exit }')"
    [[ -z "$cur" || "$cur" == "$rel" ]] || die "${target} is already mounted from ${MNT_REAL}/${cur}"
    [[ -z "$other" ]] || die "${MNT_REAL}/${rel} is already mounted at ${other}"

    if [[ -z "$cur" ]]; then
        [[ ! -L "${ROOT}${target}" ]] || die "${target} is a symlink; nothing was changed"
        [[ ! -e "${ROOT}${target}" || -d "${ROOT}${target}" ]] || die "${target} is not a directory; nothing was changed"
        if findmnt -n --mountpoint "${ROOT}${target}" >/dev/null 2>&1; then
            die "something else is mounted at ${target}; nothing was changed"
        fi
        if [[ -n "$(find "${ROOT}${target}" -mindepth 1 -maxdepth 1 2>/dev/null | head -n 1 || true)" ]]; then
            printf "joinery_data_root: %s holds data; it stays where it is (moving it onto the data root is migrate's). Nothing was changed.\n" "$target" >&2
            exit 3
        fi
        if [[ ! -e "${MNT}/${rel}" ]]; then
            fresh=1
            mkdir -p "${MNT}/${rel}" || die "could not make ${MNT_REAL}/${rel}"
        fi
        # A new place takes the owner and mode the path had, so the package
        # or site that made it finds it as it left it.
        if (( fresh )) && [[ -d "${ROOT}${target}" ]]; then
            chown --reference="${ROOT}${target}" "${MNT}/${rel}" 2>/dev/null || true
            chmod --reference="${ROOT}${target}" "${MNT}/${rel}" 2>/dev/null || true
        fi
        mkdir -p "${ROOT}${target}" || die "could not make ${target}"
        lock_mountpoint "${ROOT}${target}"
        if [[ ! -f "$BINDS" ]]; then
            mkdir -p "$(dirname "$BINDS")"
            printf '# Written by joinery_data_root.sh (specs/one_data_root.md D1).\n# Each line: a path under %s, and where it is mounted.\n' "$MNT_REAL" > "$BINDS"
            chmod 644 "$BINDS"
        fi
        printf '%s %s\n' "$rel" "$target" >> "$BINDS" || die "could not record the bind in ${BINDS}"
    fi
    ensure_units || die "${target} is recorded in ${BINDS}, but the units could not be written; fix the reason above, then run this again"
    start_bind "$rel" "$target" || die "${target} did not mount from ${MNT_REAL}/${rel}; see: systemctl status $(bind_unit "$target")"
    say "${target} is on the data root (${MNT_REAL}/${rel})"
}

# Take one bind away. The target stops requiring it first: stopping a unit
# the target still requires would stop the target, and every service that
# requires the target with it. Then the mount goes, and the path is left the
# empty folder its package or site expects, with the place's owner and mode.
unbind_one() {  # REL TARGET
    local rel="$1" target="$2"
    awk -v t="$target" '!(NF == 2 && $2 == t)' "$BINDS" > "${BINDS}.tmp.$$" && mv -f "${BINDS}.tmp.$$" "$BINDS" \
        || { rm -f "${BINDS}.tmp.$$"; die "could not update ${BINDS}"; }
    chmod 644 "$BINDS"
    ensure_units || die "${target} is no longer recorded, but the target could not be reloaded without it, so it is still mounted; fix the reason above, then run: systemctl daemon-reload && systemctl stop $(bind_unit "$target")"
    unbind_release "$rel" "$target"
}

# The mount of a bind no longer recorded, once the target has let it go.
unbind_release() {  # REL TARGET
    local rel="$1" target="$2" unit
    unit="$(bind_unit "$target")"
    if bind_ok "$rel" "$target" || findmnt -n --mountpoint "${ROOT}${target}" >/dev/null 2>&1; then
        # systemd still knows a mounted unit whose file is gone, from mountinfo.
        systemctl stop "$unit" 2>/dev/null || umount "${ROOT}${target}" \
            || die "${target} could not be unmounted; see: findmnt ${target}"
    fi
    chattr -i "${ROOT}${target}" 2>/dev/null || true
    if [[ -d "${MNT}/${rel}" && -d "${ROOT}${target}" && ! -L "${ROOT}${target}" ]]; then
        chown --reference="${MNT}/${rel}" "${ROOT}${target}" 2>/dev/null || true
        chmod --reference="${MNT}/${rel}" "${ROOT}${target}" 2>/dev/null || true
    fi
}

do_unbind() {
    local target="${1:-}" rel
    need_root
    target_ok "$target" || die "'${target}' is not an absolute path outside the data root"
    take_lock
    rel="$(binds | awk -v t="$target" '$2 == t { print $1; exit }')"
    [[ -n "$rel" ]] || die "${target} is not mounted from the data root"
    unbind_one "$rel" "$target"
    say "${target} is no longer mounted from the data root; its data stays at ${MNT_REAL}/${rel}"
}

# A site is being removed: every bind of its places (sites/NAME/...) goes,
# and so does its data on the data root, as a container site's volumes go with
# it. Nothing to do on a host with no data root, or for a site with none.
do_remove_site() {
    local name="${1:-}" rel target n=0
    [[ "$name" =~ ^[a-z0-9][a-z0-9_-]{0,49}$ ]] || die "'${name}' is not a site name"
    need_root
    [[ -f "$CONF" ]] || return 0
    take_lock
    # Its data is removed with its mounts, so nothing is touched while the
    # data root it lives on cannot be reached.
    if binds | awk -v p="sites/${name}/" 'index($1, p) == 1 { f = 1 } END { exit !f }' && ! mounted_ok; then
        die "${name}'s data is on the data root, which is not mounted: $(check_reason). Nothing was changed."
    fi
    # All of the site's records go, the units reload once, then each mount.
    local -a gone=()
    while read -r rel target; do
        [[ "$rel" == "sites/${name}/"* ]] && gone+=("$rel $target")
    done < <(binds)
    if (( ${#gone[@]} > 0 )); then
        awk -v p="sites/${name}/" '!(NF == 2 && index($1, p) == 1)' "$BINDS" > "${BINDS}.tmp.$$" && mv -f "${BINDS}.tmp.$$" "$BINDS" \
            || { rm -f "${BINDS}.tmp.$$"; die "could not update ${BINDS}"; }
        chmod 644 "$BINDS"
        ensure_units || die "${name}'s mounts are no longer recorded, but the target could not be reloaded without them, so they are still mounted; fix the reason above, then run: systemctl daemon-reload, and this again"
        local pair
        for pair in "${gone[@]}"; do
            unbind_release "${pair%% *}" "${pair#* }"
            n=$((n + 1))
        done
    fi
    if [[ -e "${MNT}/sites/${name}" ]] && mounted_ok; then
        rm -rf "${MNT:?}/sites/${name:?}" || die "could not remove ${MNT_REAL}/sites/${name}"
        say "${name}: ${n} mount(s) taken away and its data on the data root removed (${MNT_REAL}/sites/${name})"
    elif (( n > 0 )); then
        say "${name}: ${n} mount(s) taken away"
    fi
}

# ---------------------------------------------------------------------------
# migrate (D5)
# ---------------------------------------------------------------------------

# Every place this host keeps data that belongs on the data root (D1), "REL
# TARGET" a line: each package directory that is here, each site's six
# folders, and a companion test site's logs, as a new install binds them.
migrate_places() {
    [[ -d "${ROOT}/var/lib/postgresql" ]] && echo "postgresql /var/lib/postgresql"
    [[ -d "${ROOT}/var/spool/postfix" ]] && echo "mail/postfix /var/spool/postfix"
    [[ -d "${ROOT}/var/lib/rspamd" ]] && echo "mail/rspamd /var/lib/rspamd"
    local d name f
    for d in "${ROOT}${SITES_REAL}"/*/; do
        [[ -d "$d" ]] || continue
        name="$(basename "$d")"
        [[ "$name" =~ ^[a-z0-9][a-z0-9_-]{0,49}$ ]] || continue
        if [[ -f "${d}config/Globalvars_site.php" ]]; then
            for f in $SITE_FOLDERS; do
                printf 'sites/%s/%s %s/%s/%s\n' "$name" "$f" "$SITES_REAL" "$name" "$f"
            done
        elif [[ "$name" == *_test && -f "${ROOT}${SITES_REAL}/${name%_test}/config/Globalvars_site.php" ]]; then
            printf 'sites/%s/logs %s/%s/logs\n' "$name" "$SITES_REAL" "$name"
        fi
    done
    return 0
}

# Docker's data-root, when Docker is installed here and keeps it off the data
# root; nothing otherwise.
migrate_docker_root() {
    local dr
    dr="$(docker_daemon_json_get data-root)"
    dr="${dr#\"}"; dr="${dr%\"}"; dr="${dr%/}"
    [[ -n "$dr" ]] || dr="/var/lib/docker"
    [[ "$dr" != "$DOCKER_DATA_ROOT" && -d "${ROOT}${dr}" ]] && printf '%s' "$dr"
    return 0
}

# Processes with a file open, or their working directory, under any PATH:
# "pid(command)" each.
migrate_holders() {  # PATH...
    local p pid args=()
    for p in "$@"; do args+=(-o -lname "$p" -o -lname "${p}/*"); done
    find /proc/[0-9]*/fd /proc/[0-9]*/cwd -maxdepth 1 \( "${args[@]:1}" \) -print 2>/dev/null \
        | awk -F/ '{ print $3 }' | sort -u | while read -r pid; do
            [[ "$pid" == "$$" ]] && continue
            printf '%s(%s) ' "$pid" "$(cat "/proc/${pid}/comm" 2>/dev/null || echo '?')"
        done
    return 0
}

# Hold every host converger off for the life of this run: each one's lock, by
# the converger's own rule (host_runner_lock.sh), so no installer runs against
# a site whose folders are moving.
migrate_hold_runners() {  # SITE...
    local dir="${ROOT}/run/joinery" f fd name
    mkdir -p "$dir"
    for name in "$@" host; do
        f="${dir}/host-installers.${name}.lock"
        [[ -e "$f" ]] || ( umask 077; : >> "$f" )
    done
    for f in "$dir"/host-installers.*.lock; do
        [[ -e "$f" ]] || continue
        exec {fd}>>"$f"
        flock -w 600 "$fd" || die "a host converger run ($(basename "$f")) did not finish in ten minutes. ${MIGRATE_NOTHING}"
    done
}

# What a move that did not finish leaves: the folders put back where they
# were, Docker pointed back, the places this run made removed, and every
# service it stopped started again. Once the services are started on the data
# root (MIGRATE_PHASE=done) nothing is put back: what they write from then on
# is only there.
# Unmask what the move masked, so the units can start again.
migrate_unmask() {
    (( ${#MIGRATE_MASKED[@]} > 0 )) || return 0
    systemctl unmask --runtime "${MIGRATE_MASKED[@]}" >/dev/null 2>&1 \
        || say "WARNING: could not unmask ${MIGRATE_MASKED[*]}; run: systemctl unmask --runtime ${MIGRATE_MASKED[*]}"
    MIGRATE_MASKED=()
}

MIGRATE_PHASE=""
MIGRATE_SWAPPED=()
MIGRATE_MADE=()
MIGRATE_STOPPED=()
MIGRATE_CONTAINERS=()
MIGRATE_TIMERS=()
MIGRATE_MASKED=()
MIGRATE_BOOT=""
MIGRATE_DOCKER_FROM=""
MIGRATE_DOCKER_JSON=""
MIGRATE_DOCKER_JSON_HAD=0
MIGRATE_NOTHING="Nothing was changed."
migrate_abort() {
    local rc=$? i pair rel target t
    trap - EXIT
    [[ -z "$MIGRATE_PHASE" || "$MIGRATE_PHASE" == "done" ]] && exit "$rc"
    set +e
    if [[ "$MIGRATE_PHASE" == "swap" || "$MIGRATE_PHASE" == "docker" ]]; then
        say "Putting back what was moved..."
        for pair in "${MIGRATE_SWAPPED[@]}"; do
            target="${pair#* }"
            awk -v t="$target" '!(NF == 2 && $2 == t)' "$BINDS" > "${BINDS}.tmp.$$" && mv -f "${BINDS}.tmp.$$" "$BINDS"
        done
        ensure_units >/dev/null
        for (( i = ${#MIGRATE_SWAPPED[@]} - 1; i >= 0; i-- )); do
            pair="${MIGRATE_SWAPPED[$i]}"; rel="${pair%% *}"; target="${pair#* }"; t="${ROOT}${target}"
            if bind_ok "$rel" "$target" || findmnt -n --mountpoint "$t" >/dev/null 2>&1; then
                systemctl stop "$(bind_unit "$target")" 2>/dev/null || umount "$t"
            fi
            chattr -i "$t" 2>/dev/null
            rmdir "$t" 2>/dev/null
            mv -T "${OLD}/${rel}" "$t" || say "COULD NOT PUT BACK ${target}: it is at ${OLD_REAL}/${rel}"
        done
        if [[ -n "$MIGRATE_DOCKER_FROM" && -e "${OLD}/docker" ]]; then
            mv -T "${OLD}/docker" "${ROOT}${MIGRATE_DOCKER_FROM}" || say "COULD NOT PUT BACK ${MIGRATE_DOCKER_FROM}: it is at ${OLD_REAL}/docker"
        fi
        if [[ "$MIGRATE_PHASE" == "docker" ]]; then
            if (( MIGRATE_DOCKER_JSON_HAD )); then
                printf '%s\n' "$MIGRATE_DOCKER_JSON" > "$(docker_daemon_json_path)"
            else
                rm -f "$(docker_daemon_json_path)"
            fi
        fi
        rm -f "$MIGRATED"
        find "$OLD" -depth -type d -empty -delete 2>/dev/null
    fi
    for t in "${MIGRATE_MADE[@]}"; do rm -rf "$t"; done
    migrate_unmask
    if (( ${#MIGRATE_STOPPED[@]} > 0 )); then
        systemctl start "$TARGET_UNIT" 2>/dev/null
        systemctl start "${MIGRATE_STOPPED[@]}" || say "WARNING: not every service started again: $(printf '%s ' "${MIGRATE_STOPPED[@]}")"
    fi
    if (( ${#MIGRATE_CONTAINERS[@]} > 0 )); then
        docker start "${MIGRATE_CONTAINERS[@]}" >/dev/null || say "WARNING: not every container started again: ${MIGRATE_CONTAINERS[*]}"
    fi
    if (( ${#MIGRATE_TIMERS[@]} > 0 )); then
        systemctl start "${MIGRATE_TIMERS[@]}" || say "WARNING: the host converger's ${MIGRATE_TIMERS[*]} did not start again"
    fi
    say "Nothing was moved: this host keeps its data where it did."
    exit "$rc"
}

do_migrate() {
    local arg="${1:-}" want="" device="" rc=0 pair rel target t p sz total=0 need bytes givable rsize
    local fsize used avail diff waited holders mounted name
    local -a moves=() sites=() problems=()
    if [[ "$arg" == /* ]]; then device="$arg"; elif [[ -n "$arg" ]]; then want="$arg"; fi
    need_root
    cd /
    # A container's data is its volumes, on its host: the host is what moves.
    if [[ -z "$ROOT" ]] && { [[ -e /.dockerenv ]] || systemd-detect-virt --container --quiet 2>/dev/null; }; then
        die "this is a container; its data is its volumes, which move with its host (run this on the host). ${MIGRATE_NOTHING}"
    fi
    # A unit of its own: the move outlives whoever started it (an ssh session
    # that drops, an agent restarted or timed out mid-copy, which kills its
    # whole process group), and a second move cannot start beside it. What it
    # says goes to its caller while there is one, and always to MIGRATE_LOG.
    if [[ -z "$ROOT" && -z "${JOINERY_DATA_ROOT_MIGRATE_UNIT:-}" ]] && command -v systemd-run >/dev/null 2>&1 && [[ -d /run/systemd/system ]]; then
        if systemctl is-active --quiet "$MIGRATE_UNIT" 2>/dev/null; then
            die "a move is already running (journalctl -u ${MIGRATE_UNIT}, ${MIGRATE_LOG})"
        fi
        exec systemd-run --quiet --wait --pipe --collect --unit="$MIGRATE_UNIT" \
            -p TimeoutStopSec=30min --setenv=JOINERY_DATA_ROOT_MIGRATE_UNIT=1 \
            /bin/bash "$(readlink -f "${BASH_SOURCE[0]}")" migrate ${arg:+"$arg"}
    fi
    if [[ -n "${JOINERY_DATA_ROOT_MIGRATE_UNIT:-}" ]]; then
        # A caller that goes away must not end the move: nothing here dies of a
        # closed pipe, and tee keeps the log when the caller is gone.
        trap '' PIPE
        # tee ignores TERM too, so a stop (systemctl stop of this unit) still
        # logs the put-back; it ends when this shell does (reviewer2 F3).
        trap '' TERM
        exec > >(tee -p -a "$MIGRATE_LOG") 2>&1
        say "=== $(date -u '+%Y-%m-%d %H:%M:%S UTC'): joinery_data_root.sh migrate ${arg}"
    fi
    # Stopped (systemctl stop, a hand's ^C): the put-back below runs.
    trap 'exit 143' TERM INT HUP
    take_lock

    do_check >/dev/null 2>&1 || rc=$?
    (( rc != 1 )) || die "this host has a data root that is not ready: $(check_reason). ${MIGRATE_NOTHING}"

    # What moves: every place not on the data root already, and Docker's root.
    while read -r rel target; do
        (( rc == 0 )) && bind_ok "$rel" "$target" && continue
        moves+=("$rel $target")
        if [[ "$rel" =~ ^sites/([^/]+)/ ]] && [[ " ${sites[*]} " != *" ${BASH_REMATCH[1]} "* ]]; then
            sites+=("${BASH_REMATCH[1]}")
        fi
    done < <(migrate_places)
    if command -v dockerd >/dev/null 2>&1; then
        export DOCKER_DAEMON_JSON="${ROOT}/etc/docker/daemon.json"
        # shellcheck source=_docker_daemon_json.sh
        . "$(dirname "${BASH_SOURCE[0]}")/_docker_daemon_json.sh" || die "_docker_daemon_json.sh is missing beside this script"
        MIGRATE_DOCKER_FROM="$(migrate_docker_root)"
    fi
    if (( ${#moves[@]} == 0 )) && [[ -z "$MIGRATE_DOCKER_FROM" ]]; then
        say "This host keeps no data off the data root; nothing to move"
        return 0
    fi

    # Everything that would stop the move, said at once, before anything changes.
    for pair in "${moves[@]}" ${MIGRATE_DOCKER_FROM:+"docker ${MIGRATE_DOCKER_FROM}"}; do
        rel="${pair%% *}"; target="${pair#* }"; t="${ROOT}${target}"
        if [[ -L "$t" ]]; then problems+=("${target} is a symlink"); continue; fi
        if [[ -e "$t" && ! -d "$t" ]]; then problems+=("${target} is not a directory"); continue; fi
        [[ -n "$(ls -A "${MNT}/${rel}" 2>/dev/null)" ]] && problems+=("${MNT_REAL}/${rel} already holds data")
        [[ -e "${OLD}/${rel}" ]] && problems+=("${OLD_REAL}/${rel} exists: an earlier move's copy is kept there")
        # A mount inside it would be copied through, and moved aside with it.
        # (Docker's own, while it runs, are checked again once it has stopped.)
        if [[ "$rel" != "docker" && -d "$t" ]]; then
            p="$(findmnt -rn -o TARGET 2>/dev/null | awk -v p="$t" 'index($0, p "/") == 1 { print; exit }' || true)"
            [[ -z "$p" ]] || problems+=("${p#"$ROOT"} is mounted inside ${target}")
        fi
        # The copy from before is kept by a rename, which only one filesystem can do.
        if [[ -d "$t" && "$(stat -c %d "$t")" != "$(stat -c %d "$(root_disk_path)")" ]]; then
            problems+=("${target} is not on the root disk with ${OLD_REAL}")
        fi
    done
    # The copies from before wait for a later boot; without this boot's id
    # there is no telling one boot from the next (reviewer2 F2).
    MIGRATE_BOOT="$(cat "$BOOT_ID_FILE" 2>/dev/null || true)"
    [[ "$MIGRATE_BOOT" =~ ^[0-9a-fA-F-]{8,}$ ]] || problems+=("this boot's id (${BOOT_ID_FILE}) cannot be read, so the copies from before could not wait for a reboot")
    local conf dd
    for conf in "${ROOT}"/etc/postgresql/*/*/postgresql.conf; do
        [[ -f "$conf" ]] || continue
        dd="$(sed -n "s/^[[:space:]]*data_directory[[:space:]]*=[[:space:]]*'\([^']*\)'.*/\1/p" "$conf" | tail -n 1)"
        [[ -z "$dd" || "$dd" == /var/lib/postgresql/* ]] || problems+=("PostgreSQL keeps a cluster at ${dd}, outside /var/lib/postgresql")
    done
    if (( ${#problems[@]} > 0 )); then
        printf 'joinery_data_root: cannot move this host'"'"'s data:\n' >&2
        printf '  - %s\n' "${problems[@]}" >&2
        die "${MIGRATE_NOTHING}"
    fi
    for name in "${sites[@]}"; do
        if [[ -e "${ROOT}${SITES_REAL}/${name}/uploads/.upgrade.lock" ]] \
            && ! flock -n "${ROOT}${SITES_REAL}/${name}/uploads/.upgrade.lock" true; then
            die "${name} is upgrading; run this once it has finished. ${MIGRATE_NOTHING}"
        fi
    done
    if ! command -v rsync >/dev/null 2>&1; then
        say "Installing rsync..."
        DEBIAN_FRONTEND=noninteractive apt-get install -y rsync >/dev/null || die "could not install rsync. ${MIGRATE_NOTHING}"
    fi

    # How much, and a data root to hold it. The copy from before stays on the
    # root disk until the data root has passed a reboot, so both must fit.
    for pair in "${moves[@]}" ${MIGRATE_DOCKER_FROM:+"docker ${MIGRATE_DOCKER_FROM}"}; do
        t="${ROOT}${pair#* }"
        [[ -d "$t" ]] || continue
        sz="$(du -sxB1 "$t" 2>/dev/null | cut -f1)"
        [[ "$sz" =~ ^[0-9]+$ ]] || die "could not measure ${pair#* }. ${MIGRATE_NOTHING}"
        total=$(( total + sz ))
    done
    need=$(( total + total / 10 + GIB ))
    say "This host keeps $(gib "$total") of data off the data root"
    if (( rc == 2 )); then
        if [[ -n "$device" ]]; then
            sz="$(blockdev --getsize64 "$device" 2>/dev/null || true)"
            [[ "$sz" =~ ^[0-9]+$ ]] || die "could not read the size of ${device}. ${MIGRATE_NOTHING}"
            (( sz >= need )) || die "${device} holds $(gib "$sz"); this host's data needs $(gib "$need") with room. ${MIGRATE_NOTHING}"
            do_create "$device"
        else
            givable="$(root_givable)" || die "could not read the root disk's free space. ${MIGRATE_NOTHING}"
            if [[ -n "$want" ]]; then
                bytes="$(size_bytes "$want")" || die "the size is a number with G, M or T (32G), not '${want}'"
                (( bytes >= need )) || die "a data root of ${want} cannot hold this host's data: it needs $(gib "$need") with room. ${MIGRATE_NOTHING}"
            else
                # D7's first size, or room for the data and a growth step if that is more.
                read -r rsize _ _ < <(df_bytes "$(root_disk_path)") || die "could not read the root disk's size. ${MIGRATE_NOTHING}"
                bytes="$(max "$FIRST_MIN" $(( rsize * FIRST_ROOT_PCT / 100 / GIB * GIB )) $(( total + GROW_PLUS )) $(( total * 10 / 7 )))"
                bytes=$(( (bytes + GIB - 1) / GIB * GIB ))
                (( bytes <= givable )) || bytes=$(( givable / GIB * GIB ))
            fi
            (( bytes >= need && bytes <= givable )) || die "the root disk cannot hold this host's data twice: a data root for it needs $(gib "$need"), and the root disk can give $(gib "$givable") above its reserve. The copy from before is kept until the data root has passed a reboot, so both must fit. ${MIGRATE_NOTHING}"
            do_create "$bytes"
        fi
    else
        [[ -z "$arg" ]] || say "${arg} was not used: this host has a data root"
        read -r fsize used avail < <(df_bytes "$MNT") || die "could not read the data root's figures. ${MIGRATE_NOTHING}"
        if (( avail < need )); then
            [[ "$(conf_get backing)" == "file" ]] \
                || die "the data root has $(gib "$avail") free and this host's data needs $(gib "$need"). Grow its device at the provider, run joinery_data_root.sh grow, then this again. ${MIGRATE_NOTHING}"
            sz="$(image_bytes)"
            bytes=$(( (sz + need - avail + GIB - 1) / GIB * GIB ))
            givable="$(root_givable)" || die "could not read the root disk's free space. ${MIGRATE_NOTHING}"
            (( bytes - sz <= givable )) || die "the data root needs $(gib $(( bytes - sz ))) more to hold this host's data, and the root disk can give $(gib "$givable") above its reserve. ${MIGRATE_NOTHING}"
            grow_file_to "$bytes"
        fi
    fi
    MIGRATE_NOTHING="Nothing was moved; the data root is made and empty."
    mounted_ok || die "the data root is not mounted. ${MIGRATE_NOTHING}"

    # Quiet: no converger run, then every service whose data moves, and cron.
    trap migrate_abort EXIT
    MIGRATE_PHASE="stop"
    local u pat
    # The converger's triggers first: a run that starts now would wait on the
    # lock this move holds with the site's logs open (reviewer2 F1).
    for u in "${MIGRATE_CONVERGER[@]}"; do
        systemctl is-active --quiet "$u" 2>/dev/null && MIGRATE_TIMERS+=("$u")
    done
    if (( ${#MIGRATE_TIMERS[@]} > 0 )); then
        systemctl stop "${MIGRATE_TIMERS[@]}" || die "the host converger's ${MIGRATE_TIMERS[*]} did not stop"
    fi
    migrate_hold_runners "${sites[@]}"
    # A run that started before its timer stopped and still waits for a lock
    # this move now holds has changed nothing: it takes the lock before its
    # first change. Stopped, it lets go of the logs it writes to.
    systemctl stop joinery-host-converger.service 2>/dev/null || true
    while read -r u; do
        # Only what keeps data that moves this time: a site added since an
        # earlier move does not stop PostgreSQL or Docker.
        case "$u" in
            docker.service|docker.socket|containerd.service) [[ -n "$MIGRATE_DOCKER_FROM" ]] || continue ;;
            postgresql*) [[ " ${moves[*]} " == *" postgresql /var/lib/postgresql "* ]] || continue ;;
            postfix*)    [[ " ${moves[*]} " == *" mail/postfix "* ]] || continue ;;
            rspamd*)     [[ " ${moves[*]} " == *" mail/rspamd "* ]] || continue ;;
            apache2.service|php*-fpm.service|cron.service)
                (( ${#sites[@]} > 0 )) || [[ -n "$MIGRATE_DOCKER_FROM" ]] || continue ;;
        esac
        for pat in "${MIGRATE_STOP[@]}"; do
            # shellcheck disable=SC2053
            [[ "$u" == $pat ]] && { MIGRATE_STOPPED+=("$u"); break; }
        done
    done < <(systemctl list-units --type=service,socket --state=active --plain --no-legend 2>/dev/null | awk '{ print $1 }')
    # Docker's containers first, by Docker, while containerd still runs: stopped
    # with Docker in one go, Docker cannot reach containerd to stop them, and
    # a container left running keeps writing to its volumes. Each one running
    # now is started again after, whatever its restart policy.
    if [[ -n "$MIGRATE_DOCKER_FROM" ]] && systemctl is-active --quiet docker.service 2>/dev/null; then
        mapfile -t MIGRATE_CONTAINERS < <(docker ps --format '{{.Names}}' 2>/dev/null)
        if (( ${#MIGRATE_CONTAINERS[@]} > 0 )); then
            say "Stopping containers: ${MIGRATE_CONTAINERS[*]}"
            docker stop -t 60 "${MIGRATE_CONTAINERS[@]}" >/dev/null || die "not every container stopped"
        fi
    fi
    if (( ${#MIGRATE_STOPPED[@]} > 0 )); then
        say "Stopping: ${MIGRATE_STOPPED[*]}"
        # containerd last, once everything that talks to it has stopped.
        local -a first=() last=()
        for u in "${MIGRATE_STOPPED[@]}"; do
            if [[ "$u" == containerd.service ]]; then last+=("$u"); else first+=("$u"); fi
        done
        (( ${#first[@]} == 0 )) || systemctl stop "${first[@]}" || die "not every service stopped"
        (( ${#last[@]} == 0 )) || systemctl stop "${last[@]}" || die "containerd did not stop"
    fi
    # Nothing starts them again for the length of the move: an unattended
    # upgrade restarting PHP-FPM, logrotate, a hand (reviewer2 F4).
    # Recorded first: a mask that takes for some and not all must still be
    # undone by the put-back (reviewer2 F8).
    MIGRATE_MASKED=("${MIGRATE_STOPPED[@]}" "${MIGRATE_MASK_EXTRA[@]}")
    say "Held down for the move (if it is ever killed outright: systemctl unmask --runtime ${MIGRATE_MASKED[*]})"
    systemctl mask --runtime "${MIGRATE_MASKED[@]}" >/dev/null 2>&1 \
        || die "could not hold the stopped services down (systemctl mask --runtime)"
    local -a paths=()
    for pair in "${moves[@]}" ${MIGRATE_DOCKER_FROM:+"docker ${MIGRATE_DOCKER_FROM}"}; do paths+=("${ROOT}${pair#* }"); done
    for t in "${paths[@]}"; do
        mounted="$(findmnt -rn -o TARGET 2>/dev/null | awk -v p="$t" 'index($0, p "/") == 1 { print; exit }' || true)"
        [[ -z "$mounted" ]] || die "${mounted} is mounted inside ${t#"$ROOT"}; unmount it first"
    done
    # A scheduled task cron started before it stopped finishes on its own.
    waited=0
    while holders="$(migrate_holders "${paths[@]}")"; [[ -n "$holders" ]]; do
        (( waited < 120 )) || die "these still have files open where the data moves from: ${holders}"
        (( waited % 30 == 0 )) && say "Waiting for: ${holders}"
        sleep 5; waited=$(( waited + 5 ))
    done

    # Copy, then check the copy matches, every place before any is switched.
    MIGRATE_PHASE="copy"
    # A folder the site lacks is made as its others are, owner and mode, so
    # the web user can write to it once mounted (reviewer2 F6).
    local -a lacking=()
    local f ref
    for pair in "${moves[@]}"; do
        [[ -d "${ROOT}${pair#* }" ]] || lacking+=("${ROOT}${pair#* }")
    done
    for t in "${lacking[@]}"; do
        ref=""
        # A companion test site's logs: its site's logs, which the web user writes too.
        if [[ "$t" == "${ROOT}${SITES_REAL}"/*_test/logs ]]; then
            f="${t%_test/logs}/logs"
            [[ -d "$f" && " ${lacking[*]} " != *" ${f} "* ]] && ref="$f"
        fi
        for f in $SITE_FOLDERS; do
            [[ -z "$ref" ]] || break
            # A sibling the site had, not one made a moment ago.
            [[ -d "$(dirname "$t")/${f}" && " ${lacking[*]} " != *" $(dirname "$t")/${f} "* ]] && { ref="$(dirname "$t")/${f}"; break; }
        done
        mkdir -p "$t"
        if [[ -n "$ref" ]]; then
            chown --reference="$ref" "$t" 2>/dev/null || true
            chmod --reference="$ref" "$t" 2>/dev/null || true
        fi
    done
    for pair in "${moves[@]}" ${MIGRATE_DOCKER_FROM:+"docker ${MIGRATE_DOCKER_FROM}"}; do
        rel="${pair%% *}"; target="${pair#* }"; t="${ROOT}${target}"; p="${MNT}/${rel}"
        # Absent or empty (the pre-check proved it empty): this run's to remove.
        [[ -n "$(ls -A "$p" 2>/dev/null)" ]] || MIGRATE_MADE+=("$p")
        mkdir -p "$p"
        say "Copying ${target} to ${MNT_REAL}/${rel}..."
        # Nanoseconds, both times: rsync leaves a time that matches to the second
        # as it is, so a folder made this second would keep its own and fail the
        # check; and a file changed after it was copied shows, even within the second.
        # XFS shows each ACL a second time, as a trusted.SGI_ACL_* attribute; -A
        # copies the ACL itself, and those two names are left out of both.
        rsync -aHAXS --numeric-ids --delete --modify-window=-1 "${MIGRATE_XATTR_SKIP[@]}" "$t/" "$p/" || die "copying ${target} failed"
        diff="$(rsync -aHAXSn --numeric-ids --delete --modify-window=-1 "${MIGRATE_XATTR_SKIP[@]}" -i "$t/" "$p/")" || die "${target} could not be compared with its copy"
        [[ -z "$diff" ]] || die "the copy of ${target} does not match it ($(head -n 1 <<< "$diff"))"
    done

    # Still quiet: anything that opened a file there since the copy began would
    # write into the original after the switch (reviewer2 F4).
    holders="$(migrate_holders "${paths[@]}")"
    [[ -z "$holders" ]] || die "these opened files where the data moves from during the copy: ${holders}"

    # Switch: each folder's own goes aside to the root disk's ${OLD_REAL}, and
    # the path becomes a mount of its copy.
    MIGRATE_PHASE="swap"
    mkdir -p "$OLD"; chmod 700 "$OLD"
    printf '# Written by joinery_data_root.sh migrate (specs/one_data_root.md D5): the boot that moved\n# this host'"'"'s data. %s is removed once the data root passes check on a later one.\nboot=%s\n' \
        "$OLD_REAL" "$MIGRATE_BOOT" > "$MIGRATED"
    chmod 644 "$MIGRATED"
    if [[ ! -f "$BINDS" ]]; then
        mkdir -p "$(dirname "$BINDS")"
        printf '# Written by joinery_data_root.sh (specs/one_data_root.md D1).\n# Each line: a path under %s, and where it is mounted.\n' "$MNT_REAL" > "$BINDS"
        chmod 644 "$BINDS"
    fi
    for pair in "${moves[@]}"; do
        rel="${pair%% *}"; target="${pair#* }"; t="${ROOT}${target}"
        mkdir -p "$(dirname "${OLD}/${rel}")"
        mv -T "$t" "${OLD}/${rel}" || die "could not move ${target} aside"
        MIGRATE_SWAPPED+=("$pair")
        mkdir "$t"
        lock_mountpoint "$t"
        printf '%s %s\n' "$rel" "$target" >> "$BINDS"
    done
    if [[ -n "$MIGRATE_DOCKER_FROM" ]]; then
        MIGRATE_PHASE="docker"
        if [[ -f "$(docker_daemon_json_path)" ]]; then
            MIGRATE_DOCKER_JSON="$(cat "$(docker_daemon_json_path)")"; MIGRATE_DOCKER_JSON_HAD=1
        fi
        mv -T "${ROOT}${MIGRATE_DOCKER_FROM}" "${OLD}/docker" || die "could not move ${MIGRATE_DOCKER_FROM} aside"
        docker_daemon_json_set data-root "\"${DOCKER_DATA_ROOT}\"" || die "could not set data-root in $(docker_daemon_json_path)"
    fi
    ensure_units >/dev/null || die "the units could not be written"
    for pair in "${moves[@]}"; do
        start_bind "${pair%% *}" "${pair#* }" || die "${pair#* } did not mount from ${MNT_REAL}/${pair%% *}; see: systemctl status $(bind_unit "${pair#* }")"
    done
    do_check >/dev/null || die "the data root does not pass check: $(check_reason)"

    # Running on the data root: from here on nothing is put back.
    MIGRATE_PHASE="done"
    trap - EXIT
    local failed=""
    migrate_unmask
    systemctl start "$TARGET_UNIT" || failed="${TARGET_UNIT} "
    if (( ${#MIGRATE_STOPPED[@]} > 0 )); then
        for u in "${MIGRATE_STOPPED[@]}"; do systemctl start "$u" || failed+="${u} "; done
    fi
    if (( ${#MIGRATE_CONTAINERS[@]} > 0 )); then
        docker start "${MIGRATE_CONTAINERS[@]}" >/dev/null || failed+="containers (${MIGRATE_CONTAINERS[*]}) "
    fi
    if (( ${#MIGRATE_TIMERS[@]} > 0 )); then
        systemctl start "${MIGRATE_TIMERS[@]}" || failed+="${MIGRATE_TIMERS[*]} "
    fi
    # Postfix's chroot holds syslog's socket (dev/log), and syslog still listens
    # on the one that moved aside.
    if [[ -e "${ROOT}/var/spool/postfix/dev" ]] && systemctl is-active --quiet rsyslog.service 2>/dev/null; then
        systemctl restart rsyslog.service || failed+="rsyslog.service "
    fi
    local also=""
    [[ -z "$MIGRATE_DOCKER_FROM" ]] || also=", and Docker's data (${DOCKER_DATA_ROOT})"
    if (( ${#moves[@]} > 0 )); then
        say "Moved $(gib "$total") onto the data root: ${#moves[@]} folder(s)${also}"
    else
        say "Moved $(gib "$total") onto the data root: Docker's data (${DOCKER_DATA_ROOT})"
    fi
    say "The copies from before are kept at ${OLD_REAL} until the data root passes check after a reboot; the next tick after that removes them. Reboot when you can."
    [[ -z "$failed" ]] || die "the data is moved, but these did not start: ${failed}(see: systemctl status)"
}

# A tick on a mounted, ready data root, on a boot after the one that moved
# this host's data: the copies from before are no longer needed (D5).
migrate_forget_old() {
    [[ -f "$MIGRATED" ]] || return 0
    local boot now
    boot="$(awk -F= '$1 == "boot" { print $2; exit }' "$MIGRATED")"
    now="$(cat "$BOOT_ID_FILE" 2>/dev/null || true)"
    [[ -n "$now" && -n "$boot" && "$boot" != "unknown" && "$now" != "$boot" ]] || return 0
    if [[ -d "$OLD" ]]; then
        findmnt -n --mountpoint "$OLD" >/dev/null 2>&1 && { say "data root: ${OLD_REAL} is a mount point; left as it is"; return 1; }
        rm -rf --one-file-system "${OLD:?}" || { say "data root: could not remove ${OLD_REAL}"; return 1; }
    fi
    rm -f "$MIGRATED"
    say "data root: passed check after a reboot; the copies from before the move (${OLD_REAL}) are removed"
}

# ---------------------------------------------------------------------------
# grow
# ---------------------------------------------------------------------------

# The file's size now.
image_bytes() { stat -c %s "$IMAGE" 2>/dev/null; }

# D7: what a file-backed data root should grow to now, or nothing when it
# need not. Sets PLAN_TARGET (bytes, or empty) and PLAN_NOTE (why not).
plan_growth() {
    local fsize used avail cur want givable
    PLAN_TARGET=""; PLAN_NOTE=""
    read -r fsize used avail < <(df_bytes "$MNT") || { PLAN_NOTE="its figures cannot be read"; return 0; }
    (( avail < $(max "$GROW_WHEN_MIN" $(( fsize * GROW_WHEN_PCT / 100 ))) )) || return 0
    cur="$(image_bytes)" || { PLAN_NOTE="its file cannot be read"; return 0; }
    want="$(max $(( cur + cur / 4 )) $(( used + GROW_PLUS )) $(( used * 10 / 7 )))"
    want=$(( (want + GIB - 1) / GIB * GIB ))
    givable="$(root_givable)" || { PLAN_NOTE="the root disk's figures cannot be read"; return 0; }
    if (( want - cur > givable )); then
        want=$(( (cur + givable) / GIB * GIB ))
    fi
    if (( want - cur < GROW_STEP_MIN )); then
        PLAN_NOTE="$(gib "$avail") free of $(gib "$fsize"), and the root disk has $(gib "$givable") to give above its reserve"
        return 0
    fi
    PLAN_TARGET="$want"
}

# The file to TARGET bytes, and the filesystem with it, online.
grow_file_to() {
    local target="$1" cur dev
    cur="$(image_bytes)"
    fallocate -l "$target" "$IMAGE" || die "could not extend ${IMAGE_REAL} to $(gib "$target")"
    while IFS= read -r dev; do
        [[ -z "$dev" ]] || losetup -c "$dev" || die "${IMAGE_REAL} is $(gib "$target"), but ${dev} did not reread it; run: losetup -c ${dev} && xfs_growfs ${MNT_REAL}"
    done < <(losetup -n -O NAME -j "$IMAGE_REAL" 2>/dev/null || true)
    xfs_growfs "$MNT" >/dev/null || die "${IMAGE_REAL} is $(gib "$target"), but the filesystem did not grow with it; run: xfs_growfs ${MNT_REAL}"
    say "data root: grew from $(gib "$cur") to $(gib "$target")"
}

# A device the provider has grown: the filesystem follows it.
grow_device() {
    local dev dsize fsize
    # By its UUID: a device name can change across boots, and the mount is by UUID.
    dev="$(conf_get source)"
    [[ -n "$(conf_get uuid)" && -e "${ROOT}/dev/disk/by-uuid/$(conf_get uuid)" ]] && dev="/dev/disk/by-uuid/$(conf_get uuid)"
    dsize="$(blockdev --getsize64 "$dev" 2>/dev/null || true)"
    read -r fsize _ _ < <(df_bytes "$MNT") || return 0
    [[ "$dsize" =~ ^[0-9]+$ ]] || return 0
    (( dsize > fsize + GIB )) || return 1
    xfs_growfs "$MNT" >/dev/null || die "${dev} is $(gib "$dsize"), but the filesystem did not grow with it; run: xfs_growfs ${MNT_REAL}"
    read -r fsize _ _ < <(df_bytes "$MNT") || true
    say "data root: grew with ${dev} to $(gib "$fsize")"
}

do_grow() {
    local want="${1:-}" bytes cur givable
    need_root
    do_check >/dev/null || die "there is no data root here to grow, or it is not mounted"
    take_lock
    case "$(conf_get backing)" in
        device)
            [[ -z "$want" ]] || die "a data root on a device grows when its provider grows the device; then run: joinery_data_root.sh grow"
            grow_device || say "data root: ${MNT_REAL} already fills $(conf_get source); grow the device at its provider first"
            ;;
        file)
            if [[ -n "$want" ]]; then
                bytes="$(size_bytes "$want")" || die "the size is a number with G, M or T (32G), not '${want}'"
                cur="$(image_bytes)"
                (( bytes > cur )) || die "the data root is $(gib "$cur") already; a data root grows, it never shrinks"
                givable="$(root_givable)" || die "could not read the root disk's free space"
                (( bytes - cur <= givable )) || die "growing to ${want} would leave the root disk under its reserve; it can give $(gib "$givable")"
                grow_file_to "$bytes"
            else
                plan_growth
                if [[ -n "$PLAN_TARGET" ]]; then
                    grow_file_to "$PLAN_TARGET"
                elif [[ -n "$PLAN_NOTE" ]]; then
                    say "data root: cannot grow: ${PLAN_NOTE}"
                else
                    say "data root: has room; nothing to grow"
                fi
            fi
            ;;
    esac
}

# ---------------------------------------------------------------------------
# tick
# ---------------------------------------------------------------------------

# The upkeep a tick does on a mounted data root: its units, and growth. Runs
# in a subshell where errexit does not reach, so each step checks itself; a
# die in it ends only the subshell.
tick_upkeep() {
    ensure_units || return 1
    migrate_forget_old || true
    case "$(conf_get backing)" in
        file)
            plan_growth
            if [[ -n "$PLAN_TARGET" ]]; then
                grow_file_to "$PLAN_TARGET" || return 1
                rm -f "$CANNOT_GROW_FLAG"
            elif [[ -n "$PLAN_NOTE" ]]; then
                # Said once a day, not every minute: the transcript is not a
                # dashboard, and the agent's floor speaks for a full disk.
                if [[ ! -f "$CANNOT_GROW_FLAG" ]] || [[ -n "$(find "$CANNOT_GROW_FLAG" -mmin +1440 2>/dev/null)" ]]; then
                    say "data root: cannot grow: ${PLAN_NOTE}"
                    touch "$CANNOT_GROW_FLAG"
                fi
            else
                rm -f "$CANNOT_GROW_FLAG"
            fi
            ;;
        device)
            grow_device || true
            ;;
    esac
    return 0
}

# tick exits as check does, and for no other reason: 1 means the data root is
# not ready and the converger runs nothing. Upkeep that fails on a mounted
# data root (a growth that did not take, a reload systemd refused) is said and
# absorbed; the installers still run, and the next tick tries again.
do_tick() {
    local rc=0 rel target
    do_check >/dev/null 2>&1 || rc=$?
    # The filesystem is mounted and a bind on it is not: mount it again.
    if (( rc == 1 )) && mounted_ok; then
        need_root
        if take_lock nowait; then
            ensure_units || true
            while read -r rel target; do
                bind_ok "$rel" "$target" && continue
                if start_bind "$rel" "$target"; then
                    say "data root: mounted ${target} again"
                fi
            done < <(binds)
        fi
    fi
    rc=0
    do_check >/dev/null || rc=$?
    (( rc == 0 )) || return "$rc"
    need_root
    # Another tick, or a hand's grow, has it: this tick has nothing to add.
    take_lock nowait || return 0
    if ! ( tick_upkeep ); then
        say "data root: this tick's upkeep did not finish (see above); the data root is mounted, so the run goes on and the next tick tries again"
    fi
    return 0
}

# ---------------------------------------------------------------------------
# status
# ---------------------------------------------------------------------------

do_status() {
    local rc=0 why fsize used avail givable d sz
    [[ -f "$CONF" ]] || { say "data root: none on this host"; return 0; }
    say "data root: ${MNT_REAL}, on $(conf_get backing) $(conf_get source)"
    why="$(check_reason)"
    if [[ -n "$why" ]]; then
        say "state: NOT READY - ${why}"
        return 1
    fi
    say "state: mounted, XFS, project quotas on"
    read -r fsize used avail < <(df_bytes "$MNT") || { say "figures: unreadable"; return 1; }
    say "size: $(gib "$fsize"); used $(gib "$used"); free $(gib "$avail")"
    if [[ "$(conf_get backing)" == "file" ]]; then
        givable="$(root_givable || echo 0)"
        say "growth: when free is under $(gib "$(max "$GROW_WHEN_MIN" $(( fsize * GROW_WHEN_PCT / 100 )))"); the root disk can give $(gib "$givable") more"
    else
        say "growth: grows when its device is grown at the provider"
    fi
    if [[ -f "$MIGRATED" ]]; then
        say "moved: the copies from before the move are kept at ${OLD_REAL} until the data root passes check after a reboot"
    fi
    local rel target
    while read -r rel target; do
        if bind_ok "$rel" "$target"; then
            say "mount: ${target} <- ${rel}"
        else
            say "mount: ${target} <- ${rel}: NOT MOUNTED"
        fi
    done < <(binds)
    for d in "$MNT"/*/; do
        [[ -d "$d" ]] || continue
        # Bounded: a Docker host's docker/ holds every image layer.
        sz="$(timeout 20 nice -n 19 du -sx -B1 "$d" 2>/dev/null | cut -f1 || true)"
        if [[ "$sz" =~ ^[0-9]+$ ]]; then
            say "  $(basename "$d"): $(gib "$sz")"
        else
            say "  $(basename "$d"): too large to count in 20 seconds"
        fi
    done
    return "$rc"
}

case "${1:-}" in
    create) shift; do_create "$@" ;;
    check)  do_check ;;
    grow)   do_grow "${2:-}" ;;
    status) do_status ;;
    tick)   do_tick ;;
    bind)   do_bind "${2:-}" "${3:-}" ;;
    unbind) do_unbind "${2:-}" ;;
    remove-site) do_remove_site "${2:-}" ;;
    migrate) do_migrate "${2:-}" ;;
    *) die "usage: joinery_data_root.sh create [SIZE] [DEVICE] | check | grow [SIZE] | status | tick | bind REL TARGET | unbind TARGET | remove-site NAME | migrate [SIZE|DEVICE]" ;;
esac
