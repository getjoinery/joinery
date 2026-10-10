#!/usr/bin/env bash
#
# joinery_data_root.sh - the host's data root: everything that is data, under
# /srv/joinery, on a filesystem of its own (specs/one_data_root.md).
#
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

take_lock() {  # [nowait]
    mkdir -p "$(dirname "$LOCK_FILE")"
    exec 9>>"$LOCK_FILE"
    if [[ "${1:-}" == "nowait" ]]; then
        flock -n 9
    else
        flock -w 600 9 || die "another run holds ${LOCK_FILE}"
    fi
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
    *) die "usage: joinery_data_root.sh create [SIZE] [DEVICE] | check | grow [SIZE] | status | tick | bind REL TARGET | unbind TARGET | remove-site NAME" ;;
esac
