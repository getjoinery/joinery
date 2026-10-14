#!/usr/bin/env bash
#
# joinery_data_root.sh - the host's data root: everything that is data, under
# /srv/joinery, on a filesystem of its own (specs/one_data_root.md), and on an
# encrypted volume where it was made so (specs/hosted_data_promise_disk_encryption.md).
#
# Version: 1.22 - podman-restart.service joins the consumers: on a host whose sites run on the site
#                container engine (specs/site_container_engine.md WP2) the containers come back
#                at boot only once the data root holding their storage is mounted.
# Version: 1.21 - An encrypt's start ends by saying the services stop again for a few seconds when it finishes (B59).
# Version: 1.20 - An encrypt's start forgets the escrow holder and slot an earlier encryption recorded: its new
#                 header holds no escrow slot, and an enrolment removed "the earlier slot" by that number (B55).
# Version: 1.19 - An encrypt's start cut off while cryptsetup made the header is put back as it was: cryptsetup
#                 opens the volume as it ends, and a signal waits for that, so the mapping held the loop device
#                 and the data root would not mount again (B49). A tick closes such a mapping over a plain data
#                 root, and a data root it mounts again brings back what stopped with it (B51). The root
#                 copy a run's unit starts follows the tree at each tick once no run is recorded: it stayed
#                 at the release that last encrypted the host, and answered `joinery-data-root status` (B46).
# Version: 1.18 - The tick leaves swap in compressed memory (zram) on: it never reaches a disk. It switched it
#                 off every minute, and systemd made it again (B32).
# Version: 1.17 - An encrypt's start that ends after it stopped the data root, however it ends (a refusal, a
#                 failure, a signal), starts the data root and its services again and leaves no keys behind
#                 (B42); it never stops the service it runs in (a site's Backups page asks through the host
#                 converger). A tick finds a data root not mounted with no run holding it and starts it again,
#                 and on a plain one removes keys and sealed copies a cut-off start left. decrypt's finish
#                 holds the lock while the data root is stopped. recipient refuses a key this machine was moved
#                 off (retired_recipients): a management node restored from before a rotation names it (B41).
#                 status says "starting" until cryptsetup writes its first progress line (B27). No spec ids in what
#                 it says.
# Version: 1.16 - Key slots are PBKDF2-SHA256 at 1,000,000 iterations, about a second on a 1 GB Linode: a
#                 512-bit key takes two PBKDF2 blocks, so 2,000,000 took 1.5-2.5 s (T-V11). check accepts a slot at
#                 either count this platform has made (LUKS_ITERATIONS_MADE), and no other.
# Version: 1.15 - A change of volume key keeps its rewrite area to 8 MiB, so the open after a crash part-way is
#                 minutes, not most of an hour, on network block storage (T-F2).
# Version: 1.14 - The filesystem is made with 4096-byte sectors: an in-place encryption keeps the filesystem's sector
#                 size, and with 512 it read sequentially at 527 MB/s against 1246 on a 1 GB Linode (T-S).
# Version: 1.13 - A change of several keys in the record is one rename (T-F2: a cut between two left half a
#                 change); the undo has udev re-read a device it put back.
# Version: 1.12 - What a boot or a recovery relies on is fsynced before it is relied on: the record, units, fstab,
#                 the sealed copies and the decrypt header (T-F2: a cut left the run's unit empty, so masked).
# Version: 1.11 - The tick starts an encryption's (or its undo's) run that is recorded and not running.
# Version: 1.10 - A plain data root on a device keeps its last 32 MiB outside the filesystem, made and grown: the
#                 tick grew into the room an in-place encrypt's header needs (T-F2).
# Version: 1.9 - encrypt starts only once every mount of the data root's filesystem is gone: a busy bind kept it
#                live on its old loop device while the volume was written (T-F2). Services that keep a file there
#                (rsyslog's socket in Postfix's chroot) are stopped for the start and started again.
# Version: 1.8 - An encrypt cut off in its start after cryptsetup wrote the header, before any data was encrypted,
#                is undone by the next tick (T-F2): the moved data goes back and the data root mounts plain.
# Version: 1.7 - A first size made without SIZE leaves 2 GiB above the root disk's reserve for the rest of
#                the install; reopen_logs no longer reports a finished move as failed.
# Version: 1.6 - The agent is always /usr/local/bin/joinery-agent: install.sh places the release's binary there
#                before create --encrypted, so no other path is taken from the environment.
# Version: 1.5 - move-in REL TARGET: a folder that holds data moved onto the data root where it
#                stands, with no moment its path is missing. On an encrypted data root each
#                bare-metal site's config/ is moved in once its encryption is finished (encrypt,
#                and the tick after), so every key in it is encrypted (D9); so are /var/log
#                (journald let go and flushed back, its writers reopened, rsyslog and fail2ban
#                consumers), PHP's sessions and temporary files, and the DKIM keys; core dumps
#                are off, /tmp is in memory from the next boot, and a plain swap is let go (D10). encrypt and decrypt
#                where it stands (D5), and the break-glass copy sealed again on a new recipient (D8).
# Version: 1.4 - create --encrypted: the data root on a LUKS2 volume (encryption WP1, D1-D2a).
#                Its key is random and never written to a disk; what reaches it is the
#                break-glass copy sealed to the recovery recipient, a TPM2 slot where the
#                machine has a chip, and later the escrow slot. No fstab line: a native
#                mount unit after joinery-data-unlock.service, every mount of the data
#                root out of local-fs (D1b). check, status and grow look through the
#                mapping; grow reloads it with its own key, so it asks for none.
#                recipient sets the recovery public key the copy is sealed to. A data
#                root that is not encrypted is written and checked as it was.
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
#   joinery_data_root.sh create [--encrypted] [SIZE] [DEVICE]
#                     Root. Makes the data root. With DEVICE (a path under
#                     /dev, holding no filesystem) it is formatted there;
#                     otherwise it is a fully allocated file, /srv/joinery.img,
#                     of SIZE (16G, 500M), or of the first size D7 gives when
#                     SIZE is left out. XFS, mounted at /srv/joinery with
#                     project quotas through /etc/fstab, and every service
#                     whose data lives there waits for it. --encrypted puts
#                     a LUKS2 volume under the XFS, opened at boot by
#                     joinery-data-unlock.service; refused until recipient
#                     has been given the recovery public key.
#   joinery_data_root.sh encrypt [--keep-provider-layer]
#                     Root. The existing data root is encrypted where it
#                     stands (D5): down for the seconds of the start, then
#                     online at idle priority in joinery-data-encrypt.service.
#   joinery_data_root.sh decrypt
#                     Root. Undo: back to plain, online, from wherever an
#                     encryption stands.
#   joinery_data_root.sh recipient [PUBLIC_KEY]
#                     The recovery public key (base64) the encrypted data
#                     root's break-glass copy is sealed to: shown, or, as
#                     root, set. Not changed under a volume sealed to another.
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
#
# ENCRYPTED (specs/hosted_data_promise_disk_encryption.md). LUKS2 under the
# XFS, every setting in LUKS_FORMAT and LUKS_SLOT below and nowhere else. The
# volume key is 512 random bits made in /run and gone when create ends; the
# machine's disks never hold it. What reaches it: the break-glass copy, the
# key sealed to the recovery recipient (the joinery-agent binary does the
# sealing, the same sealed box a backup's envelope uses, so
# backup_envelope.php open reads it); a TPM2 slot where there is a chip; and
# the escrow slot once the agent has joined (WP2). Every open is
# --disable-keyring, so the key sits in the mapping's table, where grow and a
# re-seal read it, and not in the kernel keyring. The boot opens it in
# joinery-data-unlock.service (joinery-agent unlock), which waits for as long
# as it must; the mount waits on it, and nothing waits on the mount at
# local-fs time, so a locked machine still boots, reaches its network and
# reports itself locked (D1b).

set -euo pipefail

# This script, by its full path: migrate runs from / and starts itself again
# as a unit, so a path it was called by relative to somewhere else is gone.
SELF="$(readlink -f "${BASH_SOURCE[0]}")"

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
# The tick said once that the data root would not mount again.
NOT_MOUNTED_FLAG="${ROOT}/run/joinery/data-root.not-mounted"
BINDS="${ROOT}/etc/joinery/data_binds"
TARGET_UNIT="joinery-data.target"
MOUNT_UNIT="srv-joinery.mount"
DIO_UNIT="joinery-data-dio.service"
DROPIN_NAME="joinery-data-root.conf"
FSTAB_MARK="# joinery-data-root (joinery_data_root.sh)"
BIND_MARK="# Written by joinery_data_root.sh (specs/one_data_root.md D1): a bind mount."
# Every service whose data lives under the data root (D1). A drop-in for a
# unit that is not installed does nothing, and is there when it is.
CONSUMERS="postgresql.service postgresql@.service docker.service containerd.service podman-restart.service apache2.service postfix.service postfix@.service rspamd.service"

# On an encrypted data root, what else on the root disk would hold the data
# (specs/hosted_data_promise_disk_encryption.md D10), each moved onto it once
# it is there, as each site's config/ is (D9). "REL TARGET" a line. /var/log
# is default-deny: one bind covers every log a package adds later.
LUKS_PLACES="log /var/log
php/sessions /var/lib/php/sessions
php/tmp /var/lib/php/tmp
mail/dkim /etc/rspamd/dkim"
# Writers of /var/log that wait for the data root, as the consumers do, while
# /var/log is on it (an encrypted machine's, D10, or one decrypted since).
LOG_CONSUMERS="rsyslog.service fail2ban.service sysstat.service"
# Two early-boot units need /var/log: systemd-update-utmp writes the boot
# record to /var/log/wtmp, and systemd-journal-flush moves the journal there.
# Both run long before any unlock, so a /var/log that waits for the data root
# would be an ordering cycle with them. On an encrypted machine each runs from
# a copy of its own unit file without the mount requirement (a drop-in cannot
# take one away): utmp writes to the root disk's /var/log underneath, which
# is kept writable and swept onto the data root at each unlock
# (luks_log_sweep), and the boot's flush waits for /var/log to be the data
# root, so the journal stays in memory until joinery-journal-flush.service
# moves it there. The copies are made from the package's files each tick, so
# they follow an update.
EARLY_LOG_UNITS="systemd-update-utmp.service systemd-journal-flush.service"
EARLY_LOG_MARK="# Written by joinery_data_root.sh (specs/hosted_data_promise_disk_encryption.md D10)"
LOG_SWEEP_MARK="${ROOT}/run/joinery/data-root.log-swept"
# The journal is in memory until the data root is open: the boot's own flush
# runs long before, and finds no /var/log/journal. This one moves it there.
JOURNAL_FLUSH_UNIT="joinery-journal-flush.service"
JOURNALD_DROPIN="${ROOT}/etc/systemd/journald.conf.d/joinery-data-root.conf"
# PHP's temporary files, on the data root (php/tmp above).
PHP_TMP_DIR="/var/lib/php/tmp"
PHP_TMP_INI_NAME="99-joinery-data-root.ini"
# Core dumps would write a process's memory to the root disk.
CORE_SYSTEM_DROPIN="${ROOT}/etc/systemd/system.conf.d/joinery-data-root.conf"
CORE_COREDUMP_DROPIN="${ROOT}/etc/systemd/coredump.conf.d/joinery-data-root.conf"
CORE_SYSCTL="${ROOT}/etc/sysctl.d/60-joinery-data-root.conf"
# /tmp in memory, from the next boot: mounting it now would hide what running
# processes have there.
TMP_MOUNT_UNIT="tmp.mount"

# Encrypted (specs/hosted_data_promise_disk_encryption.md D1): the standard
# configuration, one for every node whatever its size. The volume, and every
# key slot this platform makes: PBKDF2 at a fixed count, about one second on
# dev's CPU (D-E), never benchmarked per machine, so a header made on a big
# machine opens as fast on a small one. No --allow-discards: a discard through
# the mapping would reach a file-backed volume's image and hand its space back
# to the root disk (see loop_no_discard).
LUKS_FORMAT=(--type luks2 --cipher aes-xts-plain64 --key-size 512 --sector-size 4096)
LUKS_ITERATIONS=1000000
# Every count a release of this platform has made its slots at, the current one
# among them. A slot an earlier release made is ours for as long as its volume
# lives; a slot at any other count is not one we made.
LUKS_ITERATIONS_MADE=(1000000 2000000)
LUKS_SLOT=(--pbkdf pbkdf2 --hash sha256 --pbkdf-force-iterations "$LUKS_ITERATIONS")
MAPPING="joinery-data"
MAPPER_REAL="/dev/mapper/${MAPPING}"
UNLOCK_UNIT="joinery-data-unlock.service"
# The agent binary seals and opens the break-glass copy and opens the volume
# at boot; it is on the root disk, so it needs nothing from the data root.
AGENT_BIN="${ROOT}/usr/local/bin/joinery-agent"
DATA_ROOT_DIR="${ROOT}/etc/joinery/data-root"
RECIPIENT="${DATA_ROOT_DIR}/recovery_recipient"
# Every recovery key this machine was moved off, one fingerprint and when per line.
RETIRED_RECIPIENTS="${DATA_ROOT_DIR}/retired_recipients"
# The break-glass copy: beside the image, or, for a device, on the root disk.
SIDECAR_FILE_REAL="${IMAGE_REAL}.recovery"
SIDECAR_DEVICE_REAL="/etc/joinery/data-root/volume.recovery"
# D4: a host whose policy is to reboot after updates (multi_tenant_host.sh
# writes it) reboots, once encrypted, from here and not from apt: when the
# kernel asks, at its own minute in the window, after its holder answers.
REBOOT_POLICY="${ROOT}/etc/apt/apt.conf.d/52joinery-multi-tenant-reboot"
REBOOT_OVERRIDE="${ROOT}/etc/apt/apt.conf.d/53joinery-encrypted-reboot"
REBOOT_REQUIRED="${ROOT}/var/run/reboot-required"
REBOOT_HELD="${ROOT}/run/joinery/data-root.reboot-held"
REBOOT_WINDOW_START=$((6 * 60))     # 06:00 UTC, after the 05:30 update run
REBOOT_WINDOW_MINUTES=120

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
INSTALL_ROOM=$((2 * GIB))       # a first size made without SIZE also leaves this: the rest of an
                                # install lands on the root disk after it (1.1 GiB measured, bare metal)
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

# The data root is on an encrypted volume: its backing is luks-file or luks-device.
is_luks() { [[ "$(conf_get backing)" == luks-* ]]; }

# What holds the volume's LUKS header: the image, or the device by its UUID
# (a device name can change across boots).
luks_source() {
    local src uuid
    src="$(conf_get source)"; uuid="$(conf_get uuid)"
    if [[ "$(conf_get backing)" == "luks-device" && -n "$uuid" && -e "${ROOT}/dev/disk/by-uuid/${uuid}" ]]; then
        src="/dev/disk/by-uuid/${uuid}"
    fi
    printf '%s' "${ROOT}${src}"
}

# The encrypted volume is open: its mapping exists.
mapping_open() { [[ -e "${ROOT}${MAPPER_REAL}" ]]; }

recipient_get() { awk -F= -v k="$1" '$1 == k { sub(/^[^=]*=/, ""); print; exit }' "$RECIPIENT" 2>/dev/null || true; }

# A key slot this platform did not make, or nothing (D1): every slot is ours
# (PBKDF2-SHA256 at a count in LUKS_ITERATIONS_MADE) or systemd's TPM2 token
# slot. The agent
# reads the header's own metadata, a line a slot: "slot=N kdf=K hash=H
# iterations=I token=T".
luks_slot_problem() {
    local out line slot kdf hash iter token kv
    out="$("$AGENT_BIN" data-root slots "$(luks_source)" 2>&1)" \
        || { printf "the encrypted volume's key slots cannot be read (%s)" "$out"; return; }
    while IFS= read -r line; do
        [[ -n "$line" ]] || continue
        slot=""; kdf=""; hash=""; iter=""; token=""
        for kv in $line; do
            case "$kv" in
                slot=*) slot="${kv#slot=}" ;; kdf=*) kdf="${kv#kdf=}" ;; hash=*) hash="${kv#hash=}" ;;
                iterations=*) iter="${kv#iterations=}" ;; token=*) token="${kv#token=}" ;;
            esac
        done
        [[ "$token" == "systemd-tpm2" ]] && continue
        [[ "$kdf" == "pbkdf2" && "$hash" == "sha256" && " ${LUKS_ITERATIONS_MADE[*]} " == *" $iter "* ]] && continue
        printf 'key slot %s of the encrypted volume was not made by this platform (%s %s, %s iterations); see: cryptsetup luksDump %s' \
            "$slot" "$kdf" "$hash" "$iter" "$(conf_get source)"
        return
    done <<< "$out"
}

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
# The device number (major:minor) of the filesystem mounted at a path, or nothing.
fs_id_at() {  # PATH
    awk -v m="$1" '$5 == m { print $3; exit }' "${ROOT}/proc/self/mountinfo" 2>/dev/null || true
}

# The service a process runs in, from its /proc/PID/cgroup, or nothing.
unit_of() {  # CGROUP_FILE
    sed -n 's#^0::/system\.slice/\(.*/\)\{0,1\}\([^/]*\.service\)$#\2#p' "$1" 2>/dev/null || true
}

# The services whose processes keep a file under these mountpoints, but never
# the agent, the journal, cron (a job would die), ssh, the data root's own, or
# the one this run is part of: a site's Backups page asks through the host
# converger, and stopping that would stop this run part-way (B42). A file the
# caller keeps there keeps the filesystem mounted, and the start says so.
holder_units() {  # MOUNTPOINT...
    local m pid own
    own="$(unit_of "${ROOT}/proc/self/cgroup")"
    for m in "$@"; do
        for pid in $(fuser -m "${ROOT}${m}" 2>/dev/null); do
            unit_of "${ROOT}/proc/${pid}/cgroup"
        done
    done | sort -u | grep -v -x -e joinery-agent.service -e systemd-journald.service -e cron.service -e ssh.service \
        -e "$UNLOCK_UNIT" -e "$ENCRYPT_UNIT" ${own:+-e "$own"} || true
}

# Every mountpoint where the filesystem with this device number is still mounted.
mounts_of_fs() {  # MAJ:MIN
    [[ -n "$1" ]] || return 0
    { awk -v d="$1" '$3 == d { print $5 }' "${ROOT}/proc/self/mountinfo" 2>/dev/null || true; } | paste -sd' '
}

mounted_ok() {
    local fstype opts
    read -r fstype opts < <(findmnt -n -o FSTYPE,OPTIONS --mountpoint "$MNT" 2>/dev/null || true) || true
    [[ "${fstype:-}" == "xfs" && ",${opts:-}," == *",prjquota,"* ]]
}

# Why a declared data root is not ready to hold data, or nothing when it is.
ready_reason() {
    local backing source fstype opts
    backing="$(conf_get backing)"; source="$(conf_get source)"
    case "$backing" in
        file|luks-file) [[ -f "${ROOT}${source}" ]] || { printf '%s, which backs the data root, is missing' "$source"; return; } ;;
        device|luks-device) ;;
        *)      printf '%s does not say what backs the data root' "$CONF"; return ;;
    esac
    if [[ "$backing" == luks-* ]] && ! mapping_open; then
        printf 'the data root is locked: its encrypted volume is not open yet (see: systemctl status %s)' "$UNLOCK_UNIT"
        return
    fi
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

# Why a declared data root is not right, or nothing when it is: not ready, or,
# encrypted, a key slot this platform did not make (D1). A strange slot fails
# check, for whoever asks; it does not stop the upkeep (tick asks ready_reason).
check_reason() {
    local why
    why="$(ready_reason)"
    if [[ -z "$why" ]] && is_luks; then
        why="$(luks_slot_problem)"
    fi
    printf '%s' "$why"
}

do_check() {  # [ready]
    [[ -f "$CONF" ]] || { say "this host has no data root"; return 2; }
    local why
    if [[ "${1:-}" == "ready" ]]; then why="$(ready_reason)"; else why="$(check_reason)"; fi
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
    if is_luks; then
        # Out of local-fs, like the mount it is on (D1b): a mount unit is
        # otherwise before local-fs.target, which the unlock waits behind.
        cat <<EOF
${BIND_MARK}
# ${2} is ${MNT_REAL}/${1}, on the data root.
[Unit]
Description=Joinery data root: ${2}
DefaultDependencies=no
Requires=${MOUNT_UNIT}
After=${MOUNT_UNIT}
Conflicts=umount.target
Before=${TARGET_UNIT} umount.target

[Mount]
What=${MNT_REAL}/${1}
Where=${2}
Type=none
Options=bind
EOF
        return
    fi
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
# does not keep every page twice in its cache, and passes no discard to the
# file, which would give the data root's space back to the root disk.
[Unit]
Description=Joinery data root: direct I/O on its loop device
After=${MOUNT_UNIT}
Requires=${MOUNT_UNIT}
Before=${TARGET_UNIT}

[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart=/bin/sh -c 'for d in \$(losetup -n -O NAME -j ${IMAGE_REAL}); do losetup --direct-io=on "\$d" || true; echo 0 > "/sys/block/\$(basename "\$d")/queue/discard_max_bytes" || true; done'

[Install]
WantedBy=${TARGET_UNIT}
EOF
}

# The encrypted data root's own mount (D1b): no fstab line, which would mount
# it at local-fs time, before anything could open it. After the unlock, out of
# local-fs, so boot never waits on it; joinery-data.target does. And after
# blk-availability.service (lvm2), which at shutdown runs blkdeactivate -u: it
# unmounts every filesystem on a device-mapper device, this one included, and
# would do it while the services using it are still stopping. Ordered after
# it, the data root and its mapping are gone before it runs.
mount_unit_text() {
    cat <<EOF
# Written by joinery_data_root.sh (specs/hosted_data_promise_disk_encryption.md D1b).
# ${MNT_REAL}, the data root, on its encrypted volume once ${UNLOCK_UNIT}
# has opened it. Not part of local-fs: boot does not wait for it.
[Unit]
Description=Joinery data root: ${MNT_REAL}, on its encrypted volume
DefaultDependencies=no
Requires=${UNLOCK_UNIT}
After=${UNLOCK_UNIT} blk-availability.service
Conflicts=umount.target
Before=umount.target ${TARGET_UNIT}

[Mount]
What=${MAPPER_REAL}
Where=${MNT_REAL}
Type=xfs
Options=prjquota
EOF
}

# Opens the encrypted volume at boot (D1b, D3, D2a). A oneshot that waits for
# as long as it must: a unit waiting for a person is waiting, not failed, so
# when the volume opens the mount and every consumer start on their own.
unlock_unit_text() {
    cat <<EOF
# Written by joinery_data_root.sh (specs/hosted_data_promise_disk_encryption.md D1b).
# Opens the encrypted data root: by its TPM2 slot, from its escrow holder, or
# with the recovery key given at the console. It waits for as long as it must.
[Unit]
Description=Joinery data root: open the encrypted volume
Wants=network-online.target
After=network-online.target blk-availability.service

[Service]
Type=oneshot
RemainAfterExit=yes
TimeoutStartSec=infinity
ExecStart=/usr/local/bin/joinery-agent unlock
ExecStop=/usr/local/bin/joinery-agent unlock --close
StandardOutput=journal+console
EOF
}

# The console belongs to the recovery-key prompt until the volume opens
# (D2a). systemd's console password agent answers prompts only until the wall
# agent starts, which stops it first thing, and the wall agent starts at the
# first prompt once user sessions are allowed: for a disk opened in early boot
# that is never, for this one, opened after the network, it is at once. So on
# an encrypted machine the wall agent and the console logins wait for the
# unlock, as they would behind a disk opened in early boot; ssh does not.
CONSOLE_WAITERS="systemd-ask-password-wall.service getty@.service serial-getty@.service"
console_dropin_text() {
    cat <<EOF
# Written by joinery_data_root.sh (specs/hosted_data_promise_disk_encryption.md D2a).
# The console is the recovery-key prompt's until ${UNLOCK_UNIT} has
# opened the data root; this waits for it.
[Unit]
After=${UNLOCK_UNIT}
EOF
}

# The mapping's device unit, which the mount binds to, waits as long as the
# unlock does: by default systemd gives a device 90 seconds to appear and then
# fails the mount and everything behind it.
device_dropin_text() {
    cat <<EOF
# Written by joinery_data_root.sh (specs/hosted_data_promise_disk_encryption.md D1b).
# ${MAPPER_REAL} appears when ${UNLOCK_UNIT} opens the volume, which may
# be hours after boot; the mount waits for it that long.
[Unit]
JobRunningTimeoutSec=infinity
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

journal_flush_unit_text() {
    cat <<EOF
# Written by joinery_data_root.sh (specs/hosted_data_promise_disk_encryption.md D10).
# /var/log is on the encrypted data root. The boot's own journal flush ran
# long before it opened and left the journal in memory; this moves it there.
# It stays active, so that stopping it (before /var/log's mount, at shutdown
# or when the data root stops) has journald let go of /var/log: a journal
# held open there keeps the data root from unmounting and its volume from
# closing.
[Unit]
Description=Move the journal onto the encrypted data root
Requires=$(bind_unit /var/log)
After=$(bind_unit /var/log)

[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart=/usr/bin/journalctl --flush
ExecStop=/usr/bin/journalctl --smart-relinquish-var

[Install]
WantedBy=${TARGET_UNIT}
EOF
}

early_log_unit_text() {  # UNIT
    local src="" f
    for f in "${ROOT}/usr/lib/systemd/system/$1" "${ROOT}/lib/systemd/system/$1"; do
        [[ -f "$f" ]] && { src="$f"; break; }
    done
    [[ -n "$src" ]] || return 1
    printf '%s\n# from %s, without its /var/log mount requirement.\n' "$EARLY_LOG_MARK" "${src#"$ROOT"}"
    awk -v unit="$1" '
        /^RequiresMountsFor=/ {
            line = $0; sub(/^RequiresMountsFor=/, "", line)
            n = split(line, p, /[ \t]+/); keep = ""
            for (i = 1; i <= n; i++) if (p[i] != "" && p[i] !~ /^\/var\/log(\/|$)/) keep = keep " " p[i]
            if (keep != "") print "RequiresMountsFor=" substr(keep, 2)
            next
        }
        { print }
        /^\[Unit\]/ && unit == "systemd-journal-flush.service" { print "ConditionPathIsMountPoint=/var/log" }
    ' "$src"
}

journald_dropin_text() {
    cat <<EOF
# Written by joinery_data_root.sh (specs/hosted_data_promise_disk_encryption.md D10).
# Until the data root opens the journal is in memory: room for a boot that
# waits hours for its key without losing its first lines.
[Journal]
RuntimeMaxUse=32M
EOF
}

# /var/log is a bind of the data root.
log_on_data_root() { binds | awk '$2 == "/var/log" { f = 1 } END { exit !f }'; }

# Every consumer: the fixed list, and each PHP-FPM version installed.
consumer_units() {
    local f
    printf '%s\n' $CONSUMERS
    if log_on_data_root; then printf '%s\n' $LOG_CONSUMERS; fi
    for f in "${ROOT}"/lib/systemd/system/php*-fpm.service "${ROOT}"/usr/lib/systemd/system/php*-fpm.service; do
        [[ -f "$f" ]] && basename "$f"
    done
    return 0
}

# Write PATH with TEXT when it differs: 0 written, 1 already right, 2 failed.
# A file a boot or a recovery relies on is on disk, and so is its folder's
# entry for it. A new file's data is otherwise lost to a power cut seconds
# after it is written (ext4 keeps only a replaced file's): T-F2 found the
# encryption run's unit empty, so masked, after one.
durable() {  # PATH...
    local p
    for p in "$@"; do
        [[ -e "$p" ]] && sync -- "$p" 2>/dev/null
        sync -- "$(dirname "$p")" 2>/dev/null
    done
    return 0
}

write_if_changed() {  # PATH TEXT
    if [[ -f "$1" ]] && [[ "$(cat "$1")" == "$2" ]]; then
        return 1
    fi
    { mkdir -p "$(dirname "$1")" \
        && printf '%s\n' "$2" > "$1.tmp.$$" \
        && chmod 644 "$1.tmp.$$" \
        && { sync -- "$1.tmp.$$" 2>/dev/null; mv -f "$1.tmp.$$" "$1"; }; } || { rm -f "$1.tmp.$$"; return 2; }
    durable "$1"
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
    # Encrypted: the unlock sets the loop up itself, and the mount is a unit
    # of its own rather than an fstab line (D1b).
    if [[ "$backing" == luks-* ]]; then
        put "${SYSTEMD_DIR}/${MOUNT_UNIT}" "$(mount_unit_text)"
        put "${SYSTEMD_DIR}/${UNLOCK_UNIT}" "$(unlock_unit_text)"
        put "${SYSTEMD_DIR}/$(systemd-escape -p --suffix=device "$MAPPER_REAL").d/${DROPIN_NAME}" "$(device_dropin_text)"
        for unit in $CONSOLE_WAITERS; do
            put "${SYSTEMD_DIR}/${unit}.d/${DROPIN_NAME}" "$(console_dropin_text)"
        done
        # An encryption (or its undo) running: its unit, which a boot starts
        # once the data root is open (D5).
        if [[ -n "$(conf_get reencrypt)" ]]; then
            put "${SYSTEMD_DIR}/${ENCRYPT_UNIT}" "$(encrypt_unit_text)"
        elif [[ -f "${SYSTEMD_DIR}/${ENCRYPT_UNIT}" ]]; then
            rm -f "${SYSTEMD_DIR}/${ENCRYPT_UNIT}"; changed=1
        fi
    fi
    # D10: /var/log on the data root. The journal is moved onto it once it is
    # mounted and let go before it unmounts, with room in memory meanwhile, and
    # the writers of /var/log wait for it as the consumers do. On an encrypted
    # one, the two early-boot units that need /var/log run from copies without
    # the requirement (an unlock that waits would be an ordering cycle). What
    # stops applying is taken away.
    local log_bound=0 early
    log_on_data_root && log_bound=1
    if (( log_bound )); then
        put "$JOURNALD_DROPIN" "$(journald_dropin_text)"
        put "${SYSTEMD_DIR}/${JOURNAL_FLUSH_UNIT}" "$(journal_flush_unit_text)"
    else
        [[ -f "$JOURNALD_DROPIN" ]] && { rm -f "$JOURNALD_DROPIN"; changed=1; }
        [[ -f "${SYSTEMD_DIR}/${JOURNAL_FLUSH_UNIT}" ]] && { rm -f "${SYSTEMD_DIR}/${JOURNAL_FLUSH_UNIT}"; changed=1; }
        for unit in $LOG_CONSUMERS; do
            if [[ -f "${SYSTEMD_DIR}/${unit}.d/${DROPIN_NAME}" ]]; then
                rm -f "${SYSTEMD_DIR}/${unit}.d/${DROPIN_NAME}"; changed=1
            fi
        done
    fi
    for unit in $EARLY_LOG_UNITS; do
        if [[ "$backing" == luks-* ]] && (( log_bound )); then
            early="$(early_log_unit_text "$unit")" && put "${SYSTEMD_DIR}/${unit}" "$early"
        elif [[ "$(head -n 1 "${SYSTEMD_DIR}/${unit}" 2>/dev/null)" == "$EARLY_LOG_MARK" ]]; then
            rm -f "${SYSTEMD_DIR}/${unit}"; changed=1
        fi
    done
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
        [[ -n "$(conf_get reencrypt)" ]] && { systemctl enable "$ENCRYPT_UNIT" >/dev/null 2>&1 || true; durable "${SYSTEMD_DIR}/${TARGET_UNIT}.wants/${ENCRYPT_UNIT}"; }
        [[ -f "${SYSTEMD_DIR}/${JOURNAL_FLUSH_UNIT}" ]] && { systemctl enable "$JOURNAL_FLUSH_UNIT" >/dev/null 2>&1 || true; }
        say "data root: units written"
    fi
    (( failed == 0 ))
}

# ---------------------------------------------------------------------------
# create
# ---------------------------------------------------------------------------

# The recipient file is a recovery public key and its fingerprint, as the
# Backups page shows them (D2). Nothing else is sealed to.
recipient_ok() {
    local key fpr
    key="$(recipient_get public_key)"; fpr="$(recipient_get fingerprint)"
    [[ "$key" =~ ^[A-Za-z0-9+/]{43}=$ ]] || return 1
    [[ "$(printf '%s' "$key" | base64 -d 2>/dev/null | sha256sum | awk '{ print $1 }')" == "$fpr" ]]
}

# The machine has a TPM2 chip systemd can enrol.
tpm2_present() { [[ -e "${ROOT}/dev/tpmrm0" ]] && command -v systemd-cryptenroll >/dev/null 2>&1; }

# Everything an encrypted data root needs, said before anything changes.
luks_preflight() {
    [[ -f "$RECIPIENT" ]] || die "an encrypted data root is sealed to a recovery key, and none is set: run joinery_data_root.sh recipient PUBLIC_KEY first. Nothing was changed."
    recipient_ok || die "${RECIPIENT} is not a recovery public key with its fingerprint; set it again with joinery_data_root.sh recipient PUBLIC_KEY. Nothing was changed."
    "$AGENT_BIN" data-root check-recipient "$RECIPIENT" >/dev/null 2>&1 \
        || die "the joinery-agent at ${AGENT_BIN#"$ROOT"} cannot seal to the recovery key (it opens the volume at every boot, so it comes first: install or update it). Nothing was changed."
    if ! command -v cryptsetup >/dev/null 2>&1; then
        say "Installing cryptsetup..."
        DEBIAN_FRONTEND=noninteractive apt-get install -y cryptsetup-bin >/dev/null || die "could not install cryptsetup"
    fi
    [[ ! -e "${ROOT}${MAPPER_REAL}" ]] || die "${MAPPER_REAL} is already open, and no data root is recorded. Nothing was changed."
}

# What a create that did not finish leaves behind is taken away: the mapping,
# the loop device, the image or the device's header, the break-glass copy,
# the record. The key material in /run goes either way.
CREATE_KEYS=""
CREATE_LOOP=""
CREATE_OPENED=0
CREATE_WIPE=""
CREATE_MADE=()
create_abort() {
    local rc=$? f
    trap - EXIT
    set +e
    [[ -z "$CREATE_KEYS" ]] || rm -rf "$CREATE_KEYS"
    if (( rc != 0 )); then
        (( CREATE_OPENED )) && cryptsetup close "$MAPPING"
        [[ -z "$CREATE_LOOP" ]] || losetup -d "$CREATE_LOOP"
        [[ -z "$CREATE_WIPE" ]] || wipefs -a -q "$CREATE_WIPE"
        for f in "${CREATE_MADE[@]}"; do rm -f "$f"; done
        say "Nothing was made: the encrypted data root is taken away again."
    fi
    exit "$rc"
}

# DEV becomes a LUKS2 volume in the standard configuration and is opened as
# the mapping (D1, D2). The volume key is random and lives in /run only for
# this run. It is sealed to the recovery recipient before anything is written,
# so the recovery key opens the volume from its first second. luksFormat must
# make one slot; it gets a random secret, a TPM2 slot is enrolled through it
# where the machine has a chip, and then it goes: no slot holds anything a
# person keeps (D-H). Until the escrow slot is enrolled (WP2), a machine with
# no chip opens by the recovery key.
luks_make() {  # DEV SIDECAR_REAL
    local dev="$1" sidecar="${ROOT}$2"
    mkdir -p "${ROOT}/run/joinery"
    CREATE_KEYS="$(mktemp -d "${ROOT}/run/joinery/data-root-key.XXXXXX")" || die "could not make a place in /run for the volume key"
    ( umask 077
      head -c 64 /dev/urandom > "${CREATE_KEYS}/volume.key"
      head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n' > "${CREATE_KEYS}/slot.key" ) || die "could not make the volume key"
    mkdir -p "$(dirname "$sidecar")"
    "$AGENT_BIN" data-root seal --recipient "$RECIPIENT" < "${CREATE_KEYS}/volume.key" > "${sidecar}.tmp.$$" \
        || { rm -f "${sidecar}.tmp.$$"; die "could not seal the volume key to the recovery key"; }
    chmod 600 "${sidecar}.tmp.$$"
    mv -f "${sidecar}.tmp.$$" "$sidecar"
    durable "$sidecar"
    CREATE_MADE+=("$sidecar")
    say "Encrypting the data root (LUKS2, aes-xts-plain64, 512-bit key)..."
    cryptsetup luksFormat --batch-mode "${LUKS_FORMAT[@]}" "${LUKS_SLOT[@]}" --key-slot 0 \
        --volume-key-file "${CREATE_KEYS}/volume.key" "$dev" "${CREATE_KEYS}/slot.key" \
        || die "could not make ${dev#"$ROOT"} an encrypted volume"
    if tpm2_present; then
        if systemd-cryptenroll --unlock-key-file="${CREATE_KEYS}/slot.key" --tpm2-device=auto --tpm2-pcrs=7 "$dev" >/dev/null 2>&1; then
            say "TPM2: this machine's chip opens the data root at boot"
        else
            say "WARNING: this machine has a TPM2 chip, but enrolling it failed; the data root opens with the recovery key until it is enrolled"
        fi
    fi
    # By its own secret: luksKillSlot asks stdin for "any remaining passphrase"
    # whenever stdin is not a terminal, batch mode or not.
    cryptsetup luksRemoveKey --batch-mode "$dev" "${CREATE_KEYS}/slot.key" || die "could not remove the encrypted volume's first key slot"
    cryptsetup open --disable-keyring --key-size 512 --volume-key-file "${CREATE_KEYS}/volume.key" "$dev" "$MAPPING" \
        || die "could not open the new encrypted volume"
    CREATE_OPENED=1
    rm -f "${CREATE_KEYS}/volume.key" "${CREATE_KEYS}/slot.key"
    # The unlock identity, on the root disk, for the escrow holder to know
    # this machine by once it enrols (D3). Kept if the machine has one.
    "$AGENT_BIN" data-root identity >/dev/null || die "could not make this machine's unlock identity"
}

# The image's loop device, with direct I/O where the root disk allows it (as
# the unencrypted data root's joinery-data-dio.service does) and no discard.
loop_attach() {
    local dev
    dev="$(losetup --direct-io=on --find --show "$IMAGE" 2>/dev/null)" \
        || { dev="$(losetup --find --show "$IMAGE")" && say "data root: the root disk refuses direct I/O; the loop device reads through the page cache" >&2; } \
        || return 1
    { echo 0 > "${ROOT}/sys/block/$(basename "$dev")/queue/discard_max_bytes"; } 2>/dev/null || true
    printf '%s' "$dev"
}

do_create() {
    local arg want="" device="" bytes givable fsize encrypted=0
    for arg in "$@"; do
        case "$arg" in
            --encrypted) encrypted=1 ;;
            /*) device="$arg" ;;
            *) want="$arg" ;;
        esac
    done
    need_root
    take_lock

    if [[ -f "$CONF" ]]; then
        if mounted_ok; then
            ensure_units || die "the data root is mounted, but its units could not be written; fix the reason above, then run this again"
            say "The data root is already mounted at ${MNT_REAL}"
            [[ -z "$want" ]] || say "The size ${want} was not used: an existing data root grows by itself (or: joinery_data_root.sh grow ${want})"
            if (( encrypted )) && ! is_luks; then
                say "It is not encrypted; encrypting an existing data root is joinery_data_root.sh encrypt's"
            fi
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
    (( encrypted == 0 )) || luks_preflight

    if ! command -v mkfs.xfs >/dev/null 2>&1; then
        say "Installing xfsprogs..."
        DEBIAN_FRONTEND=noninteractive apt-get install -y xfsprogs >/dev/null || die "could not install xfsprogs"
    fi

    local fs_dev
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
        local uuid
        if (( encrypted )); then
            trap create_abort EXIT
            CREATE_WIPE="$device"
            luks_make "${ROOT}${device}" "$SIDECAR_DEVICE_REAL"
            fs_dev="${ROOT}${MAPPER_REAL}"
            uuid="$(cryptsetup luksUUID "${ROOT}${device}" 2>/dev/null || true)"
            [[ "$uuid" =~ ^[0-9a-fA-F-]{8,}$ ]] || die "${device} has no LUKS UUID after formatting; see cryptsetup luksDump ${device}"
        else
            fs_dev="$device"
        fi
        say "Formatting ${device} for the data root..."
        local -a fs_size=()
        if (( ! encrypted )); then
            # The last 32 MiB stay outside the filesystem: an in-place encrypt
            # puts its header there, with nothing to ask of the provider (D5).
            local dbytes
            dbytes="$(blockdev --getsize64 "${ROOT}${device}" 2>/dev/null || true)"
            [[ "$dbytes" =~ ^[0-9]+$ ]] && (( dbytes > 2 * ENCRYPT_HEADER_ROOM )) && fs_size=(-d "size=$(( (dbytes - ENCRYPT_HEADER_ROOM) / 4096 * 4096 ))")
        fi
        mkfs.xfs -q -n ftype=1 -s size=4096 "${fs_size[@]}" "$fs_dev" || die "could not format ${device}"
        mkdir -p "$(dirname "$CONF")"
        if (( encrypted )); then
            printf '# Written by joinery_data_root.sh (specs/hosted_data_promise_disk_encryption.md). What backs %s.\nbacking=luks-device\nsource=%s\nuuid=%s\nsidecar=%s\n' \
                "$MNT_REAL" "$device" "$uuid" "$SIDECAR_DEVICE_REAL" > "$CONF"
            CREATE_MADE+=("$CONF")
        else
            uuid="$(blkid -s UUID -o value "$device" 2>/dev/null || true)"
            [[ "$uuid" =~ ^[0-9a-fA-F-]{8,}$ ]] || die "${device} has no UUID after formatting; see blkid ${device}"
            printf '# Written by joinery_data_root.sh (specs/one_data_root.md). What backs %s.\nbacking=device\nsource=%s\nuuid=%s\n' \
                "$MNT_REAL" "$device" "$uuid" > "$CONF"
            fstab_line present
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
            # The install goes on writing to the root disk after this (its
            # packages, the site's tree), so the first size leaves room for
            # that above the reserve: otherwise a small root disk ends the
            # install under its reserve, where the data root cannot grow, an
            # in-place encrypt has no room for its header, and disk_headroom
            # opens a case on a new machine.
            local room=$(( givable > INSTALL_ROOM ? givable - INSTALL_ROOM : 0 ))
            bytes="$(max "$FIRST_MIN" $(( rsize * FIRST_ROOT_PCT / 100 / GIB * GIB )))"
            (( bytes <= room )) || bytes=$(( room / GIB * GIB ))
            (( bytes >= CREATE_FLOOR )) || die "the root disk can give the data root only $(gib "$room") above its reserve and the $(gib "$INSTALL_ROOM") an install still needs; a data root needs at least $(gib "$CREATE_FLOOR")"
        fi
        say "Allocating the data root: $(gib "$bytes") at ${IMAGE_REAL}..."
        mkdir -p "$(dirname "$IMAGE")"
        (( encrypted == 0 )) || { trap create_abort EXIT; CREATE_MADE+=("$IMAGE"); }
        fallocate -l "$bytes" "$IMAGE" || { rm -f "$IMAGE"; die "could not allocate $(gib "$bytes") on the root disk"; }
        chmod 600 "$IMAGE"
        if (( encrypted )); then
            CREATE_LOOP="$(loop_attach)" || die "could not attach ${IMAGE_REAL} to a loop device"
            luks_make "$CREATE_LOOP" "$SIDECAR_FILE_REAL"
            fs_dev="${ROOT}${MAPPER_REAL}"
        else
            fs_dev="$IMAGE"
        fi
        # ftype=1: overlay2, Docker's storage driver, needs the file type in
        # directory entries; a data root is the same filesystem everywhere.
        mkfs.xfs -q -n ftype=1 -s size=4096 "$fs_dev" || { rm -f "$IMAGE"; die "could not format the data root"; }
        mkdir -p "$(dirname "$CONF")"
        if (( encrypted )); then
            printf '# Written by joinery_data_root.sh (specs/hosted_data_promise_disk_encryption.md). What backs %s.\nbacking=luks-file\nsource=%s\nsidecar=%s\n' \
                "$MNT_REAL" "$IMAGE_REAL" "$SIDECAR_FILE_REAL" > "$CONF"
            CREATE_MADE+=("$CONF")
        else
            printf '# Written by joinery_data_root.sh (specs/one_data_root.md). What backs %s.\nbacking=file\nsource=%s\n' \
                "$MNT_REAL" "$IMAGE_REAL" > "$CONF"
            fstab_line present
        fi
    fi
    chmod 644 "$CONF"

    mkdir -p "$MNT"
    if (( encrypted )); then
        # The mount is a unit of its own (D1b); starting the target mounts it,
        # and the unlock finds the volume already open.
        ensure_units || die "the encrypted data root is made, but its units could not be written; fix the reason above"
        systemctl start "$TARGET_UNIT" || die "the data root did not mount at ${MNT_REAL}; see: systemctl status ${MOUNT_UNIT} ${UNLOCK_UNIT}"
        mounted_ok || die "${MNT_REAL} is mounted, but not as XFS with project quotas on"
        trap - EXIT
        [[ -z "$CREATE_KEYS" ]] || rm -rf "$CREATE_KEYS"
        chmod 755 "$MNT"
        read -r fsize _ _ < <(df_bytes "$MNT") || fsize=0
        say "The data root is mounted at ${MNT_REAL}: $(gib "$fsize"), XFS with project quotas, on an encrypted volume; its key is sealed to the recovery key $(recipient_get fingerprint | cut -c1-16)"
        # What else on the root disk would hold the data moves onto it now,
        # so the rest of the install writes there (D10).
        luks_hardening
        luks_moves_due || say "WARNING: something on the root disk did not move onto the data root (above); the tick tries again each minute"
        return 0
    fi
    ensure_units || die "the data root is made and in /etc/fstab, but its units could not be written; fix the reason above, then run: systemctl daemon-reload && mount ${MNT_REAL} && systemctl start ${TARGET_UNIT}"
    mount "$MNT" || die "the data root did not mount at ${MNT_REAL}; see dmesg. /etc/fstab keeps its line, so fix it and run: mount ${MNT_REAL}"
    mounted_ok || die "${MNT_REAL} is mounted, but not as XFS with project quotas on"
    chmod 755 "$MNT"
    systemctl start "$TARGET_UNIT" || say "WARNING: ${TARGET_UNIT} did not start; see: systemctl status ${TARGET_UNIT}"
    read -r fsize _ _ < <(df_bytes "$MNT") || fsize=0
    say "The data root is mounted at ${MNT_REAL}: $(gib "$fsize"), XFS with project quotas"
}

# The recovery public key the break-glass copy is sealed to (D2): shown, or
# set. It is public; what matters is that it is the right one, which is why
# it is a key the site or the operator has proven, passed in by the install
# or the converger, never read from anywhere else.
do_recipient() {
    local key="" fpr cur set_by="install" a
    # Who set it, for the site's page (WP4): the operator (its management
    # node), the site (its own Backups page), or the install.
    for a in "$@"; do
        case "$a" in
            --set-by=site|--set-by=operator|--set-by=install) set_by="${a#--set-by=}" ;;
            --set-by=*) die "--set-by is site, operator or install" ;;
            *) key="$a" ;;
        esac
    done
    if [[ -z "$key" ]]; then
        if [[ -f "$RECIPIENT" ]]; then
            say "recovery recipient: $(recipient_get fingerprint)"
            return 0
        fi
        say "no recovery recipient is set"
        return 1
    fi
    need_root
    [[ "$key" =~ ^[A-Za-z0-9+/]{43}=$ && "$(printf '%s' "$key" | base64 -d 2>/dev/null | wc -c)" -eq 32 ]] \
        || die "that is not a recovery public key: give the one base64 line (44 characters) the Backups page shows for it"
    fpr="$(printf '%s' "$key" | base64 -d | sha256sum | awk '{ print $1 }')"
    cur="$(recipient_get fingerprint)"
    # A key this machine was moved off is never taken again: a rotation is
    # made because the old key may have been seen, and a management node or
    # site restored from before it names the old key (B41).
    local retired
    retired="$(awk -v f="$fpr" '$1 == f { print $2; exit }' "$RETIRED_RECIPIENTS" 2>/dev/null || true)"
    [[ -z "$retired" ]] || die "refused: this machine was moved off the recovery key ${fpr:0:16} on ${retired}, and a key rotated away from is never sealed to again. Whatever sent it holds a key from before that rotation (a restore from an older backup does that): rotate its recovery key again from its Backups page."
    mkdir -p "$DATA_ROOT_DIR"
    if [[ -n "$cur" && "$cur" != "$fpr" ]]; then
        printf '%s %s\n' "$cur" "$(date -u +%Y-%m-%dT%H:%MZ)" >> "$RETIRED_RECIPIENTS" \
            || die "could not record the key it replaces in ${RETIRED_RECIPIENTS}"
        is_luks && say "the break-glass copy is sealed to ${cur:0:16}; the next tick seals it again to this key"
    fi
    printf '# Written by joinery_data_root.sh (specs/hosted_data_promise_disk_encryption.md D2).\n# The recovery public key the encrypted data root'"'"'s break-glass copy is sealed to.\npublic_key=%s\nfingerprint=%s\nset_by=%s\n' \
        "$key" "$fpr" "$set_by" > "${RECIPIENT}.tmp.$$" && chmod 644 "${RECIPIENT}.tmp.$$" && mv -f "${RECIPIENT}.tmp.$$" "$RECIPIENT" \
        || { rm -f "${RECIPIENT}.tmp.$$"; die "could not write ${RECIPIENT}"; }
    say "recovery recipient: ${fpr}"
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

# The root filesystem without the mounts over it: where a folder's own files
# are once a bind hides them. Under a test root nothing is mounted: the test
# says where its stand-in for a mount put what it hid.
ROOT_VIEW=""
root_view_open() {
    if [[ -n "$ROOT" ]]; then ROOT_VIEW="${JOINERY_TEST_ROOT_VIEW:-$ROOT}"; return 0; fi
    ROOT_VIEW="$(mktemp -d /run/joinery-root-view.XXXXXX)" || return 1
    if ! mount --bind / "$ROOT_VIEW" || ! mount --make-private "$ROOT_VIEW"; then
        umount "$ROOT_VIEW" 2>/dev/null || true
        rmdir "$ROOT_VIEW" 2>/dev/null || true
        ROOT_VIEW=""
        return 1
    fi
}
root_view_close() {
    if [[ -z "$ROOT" && -n "$ROOT_VIEW" ]]; then
        umount "$ROOT_VIEW" && rmdir "$ROOT_VIEW"
    fi
    ROOT_VIEW=""
}

# Move a folder that holds data onto the data root where it stands (D9: a
# site's config/ on an encrypted machine), with no moment its path is
# missing: its files are copied to the place, the place is mounted over the
# path, a file written there during the copy is copied again, and only then
# are the root disk's own files deleted, seen through a view of the root
# filesystem without the mount over them. The path is left the locked
# mountpoint every bind leaves. Done already, it only mounts it.
move_in_one() {  # REL TARGET
    local rel="$1" target="$2" t place stamp old f
    t="${ROOT}${target}"; place="${MNT}/${rel}"
    if binds | awk -v r="$rel" -v x="$target" '$1 == r && $2 == x { f = 1 } END { exit !f }'; then
        start_bind "$rel" "$target" || { say "${target} did not mount from ${MNT_REAL}/${rel}"; return 1; }
        return 0
    fi
    if binds | awk -v x="$target" '$2 == x { f = 1 } END { exit !f }'; then
        say "${target} is already mounted from another place on the data root; nothing was changed"; return 1
    fi
    [[ -d "$t" && ! -L "$t" ]] || { say "${target} is not a folder; nothing was changed"; return 1; }
    if findmnt -n --mountpoint "$t" >/dev/null 2>&1; then
        say "something else is mounted at ${target}; nothing was changed"; return 1
    fi
    if [[ -n "$(ls -A "$place" 2>/dev/null)" ]]; then
        say "${MNT_REAL}/${rel} holds data already; nothing was changed"; return 1
    fi
    mkdir -p "$place" || { say "could not make ${MNT_REAL}/${rel}"; return 1; }
    chown --reference="$t" "$place" 2>/dev/null || true
    chmod --reference="$t" "$place" 2>/dev/null || true
    stamp="${MNT}/.move-in.$$"
    : > "$stamp"
    if ! cp -a "$t/." "$place/"; then
        rm -rf "${place:?}"; rm -f "$stamp"
        say "${target} could not be copied to ${MNT_REAL}/${rel}; nothing was changed"; return 1
    fi
    [[ -f "$BINDS" ]] || { mkdir -p "$(dirname "$BINDS")"; printf '# Written by joinery_data_root.sh (specs/one_data_root.md D1).\n# Each line: a path under %s, and where it is mounted.\n' "$MNT_REAL" > "$BINDS"; chmod 644 "$BINDS"; }
    printf '%s %s\n' "$rel" "$target" >> "$BINDS"
    if ! ensure_units >/dev/null || ! start_bind "$rel" "$target"; then
        awk -v x="$target" '!(NF == 2 && $2 == x)' "$BINDS" > "${BINDS}.tmp.$$" && mv -f "${BINDS}.tmp.$$" "$BINDS"
        chmod 644 "$BINDS"; ensure_units >/dev/null || true
        rm -rf "${place:?}"; rm -f "$stamp"
        say "${target} did not mount from ${MNT_REAL}/${rel}; it stays where it is"; return 1
    fi
    if ! root_view_open; then
        rm -f "$stamp"
        say "WARNING: ${target} is on the data root, but its copy on the root disk could not be reached to delete it; it is tried again"
        return 1
    fi
    old="${ROOT_VIEW}${target}"
    while IFS= read -r -d '' f; do
        mkdir -p "$(dirname "${place}/${f}")" && cp -a "${old}/${f}" "${place}/${f}"
    done < <(cd "$old" && find . -mindepth 1 -newer "$stamp" ! -type d -print0)
    rm -f "$stamp"
    find "$old" -mindepth 1 -delete
    lock_mountpoint "$old"
    root_view_close
    say "${target} is on the data root (${MNT_REAL}/${rel}); its copy on the root disk is deleted"
}

# On an encrypted data root, everything that holds the data is on it: each
# bare-metal site's config/ (D9), and /var/log, PHP's sessions and temporary
# files and the DKIM keys (D10), each once it is there. Only once the
# encryption is finished (never into a part-plain volume), and only while it
# is open. The freed blocks are handed back to the provider's storage after
# a move.
luks_moves_due() {
    is_luks || return 0
    [[ -z "$(conf_get reencrypt)" ]] || return 0
    mounted_ok || return 0
    local rel target cfg dir site moved=0 rc=0
    while read -r rel target; do
        [[ -n "$rel" && -d "${ROOT}${target}" && ! -L "${ROOT}${target}" ]] || continue
        binds | awk -v x="$target" '$2 == x { f = 1 } END { exit !f }' && continue
        if [[ "$target" == /var/log ]]; then
            log_move_in && moved=1 || rc=1
        else
            move_in_one "$rel" "$target" && moved=1 || rc=1
        fi
    done <<< "$LUKS_PLACES"
    for cfg in "${ROOT}"/var/www/html/*/config/Globalvars_site.php; do
        [[ -f "$cfg" ]] || continue
        dir="${cfg%/config/Globalvars_site.php}"; dir="${dir#"$ROOT"}"; site="${dir##*/}"
        [[ "$site" =~ ^[a-z0-9][a-z0-9_-]{0,49}$ ]] || continue
        binds | awk -v x="${dir}/config" '$2 == x { f = 1 } END { exit !f }' && continue
        move_in_one "sites/${site}/config" "${dir}/config" && moved=1 || rc=1
    done
    if (( moved )); then
        command -v fstrim >/dev/null 2>&1 && fstrim "${ROOT}/" >/dev/null 2>&1 || true
    fi
    return "$rc"
}

# /var/log: journald lets go of it first and takes it back on the data root
# after, and anything still writing to a file it had open there reopens it,
# so nothing goes on writing into the root disk's copy.
log_move_in() {
    [[ -n "$ROOT" ]] || journalctl --relinquish-var >/dev/null 2>&1 || true
    if ! move_in_one log /var/log; then
        [[ -n "$ROOT" ]] || journalctl --flush >/dev/null 2>&1 || true
        return 1
    fi
    ensure_units >/dev/null || true
    [[ -n "$ROOT" ]] || journalctl --flush >/dev/null 2>&1 || true
    # The root disk's /var/log underneath stays writable for the boot's own
    # early writers (the boot record, cloud-init), swept at each unlock.
    if root_view_open; then
        chattr -i "${ROOT_VIEW}/var/log" 2>/dev/null || true
        chown --reference="${MNT}/log" "${ROOT_VIEW}/var/log" 2>/dev/null || true
        chmod --reference="${MNT}/log" "${ROOT_VIEW}/var/log" 2>/dev/null || true
        root_view_close
    fi
    : > "$LOG_SWEEP_MARK" 2>/dev/null || true
    reopen_logs
}

# What the boot wrote to the root disk's /var/log before the unlock (the boot
# record, cloud-init's lines): appended to the data root's copy and deleted
# there, once a boot, and its freed blocks handed back (D10).
luks_log_sweep() {
    is_luks || return 0
    [[ -e "$LOG_SWEEP_MARK" ]] && return 0
    binds | awk '$2 == "/var/log" { f = 1 } END { exit !f }' || return 0
    bind_ok log /var/log || return 0
    # Under a test root, only where the test says what its mount hid.
    [[ -z "$ROOT" || -n "${JOINERY_TEST_ROOT_VIEW:-}" ]] || return 0
    root_view_open || return 1
    local old="${ROOT_VIEW}/var/log" f n=0
    # Writable, as the move left it, for the next boot's early writers.
    chattr -i "$old" 2>/dev/null || true
    chown --reference="${MNT}/log" "$old" 2>/dev/null || true
    chmod --reference="${MNT}/log" "$old" 2>/dev/null || true
    if [[ -d "$old" ]]; then
        while IFS= read -r -d '' f; do
            f="${f#./}"
            [[ -s "${old}/${f}" ]] || continue
            if [[ -e "${ROOT}/var/log/${f}" ]]; then
                cat "${old}/${f}" >> "${ROOT}/var/log/${f}"
            else
                mkdir -p "$(dirname "${ROOT}/var/log/${f}")" && cp -a "${old}/${f}" "${ROOT}/var/log/${f}"
            fi
            n=$((n + 1))
        done < <(cd "$old" && find . -type f ! -path './journal/*' -print0)
        find "$old" -mindepth 1 -delete
    fi
    root_view_close
    : > "$LOG_SWEEP_MARK"
    if (( n )); then
        command -v fstrim >/dev/null 2>&1 && fstrim "${ROOT}/" >/dev/null 2>&1 || true
        say "data root: ${n} log file(s) this boot wrote before the unlock are on the data root now"
    fi
    reopen_logs
}

# The services whose processes still hold a file under /var/log that the
# move deleted: reloaded, which reopens its logs where that is what a reload
# does (rsyslog, Apache), and restarted where it does not (PostgreSQL's log is
# its stderr). Not the unlock, the agent or this run.
reopen_logs() {
    [[ -z "$ROOT" ]] || return 0
    local unit pass
    for pass in reload restart; do
        for unit in $(log_holders); do
            case "$unit" in
                "$UNLOCK_UNIT"|joinery-agent.service|"$ENCRYPT_UNIT"|systemd-journald.service) continue ;;
            esac
            if [[ "$pass" == reload ]]; then
                systemctl try-reload-or-restart "$unit" >/dev/null 2>&1 || true
            elif systemctl try-restart "$unit" >/dev/null 2>&1; then
                say "data root: ${unit} restarted, to write its log on the data root"
            else
                say "data root: WARNING: ${unit} still writes a log it had open on the root disk; it moves at its next restart"
            fi
        done
        if [[ "$pass" == reload ]]; then sleep 2; fi
    done
    return 0
}

log_holders() {
    local link pid
    for link in /proc/[0-9]*/fd/*; do
        case "$(readlink "$link" 2>/dev/null)" in
            "/var/log/"*" (deleted)") pid="${link#/proc/}"; pid="${pid%%/*}"
                # The unit is the cgroup path's last part, under any slice.
                sed -n 's#^0::/system\.slice/\(.*/\)\{0,1\}\([^/]*\.service\)$#\2#p' "/proc/${pid}/cgroup" 2>/dev/null ;;
        esac
    done | sort -u
}

# What else on an encrypted machine would put the data on the root disk
# (D10): a process's memory in a core dump, /tmp, PHP's temporary files, and
# a swap device that is not the encrypted swap.
luks_hardening() {
    is_luks || return 0
    local changed=0 ini v unit
    write_if_changed "$CORE_SYSTEM_DROPIN" "$(printf '%s\n' '# Written by joinery_data_root.sh (specs/hosted_data_promise_disk_encryption.md D10).' \
        '# No core dumps: one would write a process'"'"'s memory to the root disk.' '[Manager]' 'DefaultLimitCORE=0')" || true
    write_if_changed "$CORE_COREDUMP_DROPIN" "$(printf '%s\n' '# Written by joinery_data_root.sh (specs/hosted_data_promise_disk_encryption.md D10).' \
        '[Coredump]' 'Storage=none' 'ProcessSizeMax=0')" || true
    if write_if_changed "$CORE_SYSCTL" "$(printf '%s\n' '# Written by joinery_data_root.sh (specs/hosted_data_promise_disk_encryption.md D10).' \
        'kernel.core_pattern=|/bin/false' 'fs.suid_dumpable=0')"; then
        [[ -n "$ROOT" ]] || sysctl -q -p "$CORE_SYSCTL" >/dev/null 2>&1 || true
    fi
    if write_if_changed "${SYSTEMD_DIR}/${TMP_MOUNT_UNIT}" "$(tmp_mount_text)"; then
        systemctl daemon-reload >/dev/null 2>&1 || true
        systemctl enable "$TMP_MOUNT_UNIT" >/dev/null 2>&1 || true
        say "data root: /tmp is in memory from the next boot"
    fi
    # PHP's temporary files on the data root (php/tmp in LUKS_PLACES).
    for ini in "${ROOT}"/etc/php/*/fpm/conf.d; do
        [[ -d "$ini" ]] || continue
        if [[ ! -e "${ROOT}${PHP_TMP_DIR}" ]]; then
            mkdir -p "${ROOT}${PHP_TMP_DIR}" && chmod 1733 "${ROOT}${PHP_TMP_DIR}"
        fi
        if write_if_changed "${ini}/${PHP_TMP_INI_NAME}" "$(printf '%s\n' '; Written by joinery_data_root.sh (specs/hosted_data_promise_disk_encryption.md D10).' \
            "upload_tmp_dir = ${PHP_TMP_DIR}" "sys_temp_dir = ${PHP_TMP_DIR}")"; then
            v="${ini%/fpm/conf.d}"; v="${v##*/}"
            unit="php${v}-fpm.service"
            systemctl try-reload-or-restart "$unit" >/dev/null 2>&1 || true
        fi
    done
    # A swap device that is not encrypted (a provider's own swap disk) is let
    # go. Compressed memory (zram, host_swap.sh) never reaches a disk.
    if [[ -z "$ROOT" ]]; then
        local dev
        while read -r dev; do
            [[ -n "$dev" ]] || continue
            case "$(readlink -f "$dev")" in /dev/dm-*|/dev/mapper/*|/dev/zram*) continue ;; esac
            if swapoff "$dev" 2>/dev/null; then
                say "data root: swap on ${dev} is not encrypted; it is no longer used"
            fi
        done < <(swapon --noheadings --show=NAME 2>/dev/null)
    fi
    return 0
}

tmp_mount_text() {
    cat <<EOF
# Written by joinery_data_root.sh (specs/hosted_data_promise_disk_encryption.md D10).
# /tmp in memory: the root disk is not encrypted, so what tools leave in
# /tmp is never written to it.
[Unit]
Description=Temporary files, in memory
ConditionPathIsSymbolicLink=!/tmp
DefaultDependencies=no
Conflicts=umount.target
Before=local-fs.target umount.target
After=swap.target

[Mount]
What=tmpfs
Where=/tmp
Type=tmpfs
Options=mode=1777,strictatime,nosuid,nodev,size=50%%,nr_inodes=1m

[Install]
WantedBy=local-fs.target
EOF
}

do_move_in() {
    local rel="${1:-}" target="${2:-}"
    need_root
    rel_ok "$rel" || die "'${rel}' is not a path under the data root (sites/mysite/config)"
    target_ok "$target" || die "'${target}' is not an absolute path outside the data root"
    mounted_ok || die "the data root is not mounted: $(check_reason)"
    take_lock
    move_in_one "$rel" "$target" || exit 1
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
            /bin/bash "$SELF" migrate ${arg:+"$arg"}
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

    do_check ready >/dev/null 2>&1 || rc=$?
    (( rc != 1 )) || die "this host has a data root that is not ready: $(ready_reason). ${MIGRATE_NOTHING}"

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
        . "$(dirname "$SELF")/_docker_daemon_json.sh" || die "_docker_daemon_json.sh is missing beside this script"
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
# encrypt, decrypt (specs/hosted_data_promise_disk_encryption.md D5)
# ---------------------------------------------------------------------------
#
# An existing data root is encrypted where it stands, by LUKS2's own online
# encryption, not copied into a second image. The site is down for the
# seconds of the start; the encryption itself then runs for hours at idle I/O
# priority while everything works (encrypt-resume, in
# joinery-data-encrypt.service).
#
# THE KEYS. A new volume key, sealed to the recovery recipient as the
# break-glass copy before anything is written, so the recovery key opens the
# result from its first second. And a driving slot: a part-way device opens
# only through a key slot, not a volume key, so its random secret is sealed to
# the recipient as a pending copy, and otherwise kept only in /run. A reboot
# part-way opens by the recovery key through the pending copy (the unlock does
# this), which also puts the secret back in /run for the run to resume.
#
# SECTORS. The filesystem keeps the sector size it was made with: an XFS made
# on the image itself has 512-byte sectors, and dm-crypt refuses to encrypt it
# in larger ones. A data root born encrypted has 4096.
#
# CRASHES. A power cut or a kernel crash part-way is what LUKS2's data-shift
# resilience is for: the next open repairs the hotzone, and the run resumes.
# A SIGTERM (a stop, a shutdown) finishes the current hotzone and exits. A
# SIGKILL of a running encryption is the one thing it cannot survive in place:
# it leaves the hotzone suspended, writes to the data root wait, and only a
# reboot (the crash path) recovers it. So the run's unit is never sent
# SIGKILL and is never the OOM killer's choice.

ENCRYPT_UNIT="joinery-data-encrypt.service"
ENCRYPT_RUN_DIR="${ROOT}/run/joinery/data-root-encrypt"
ENCRYPT_PROGRESS="${ROOT}/run/joinery/data-root-encrypt.progress"
DRIVING_SIDECAR_REAL="/etc/joinery/data-root/driving-slot.recovery"
DECRYPT_HEADER_REAL="/etc/joinery/data-root/decrypt.header"
HEADER_BACKUP_REAL="/etc/joinery/data-root/header.backup"
INSTALLED_SELF_REAL="/usr/local/sbin/joinery-data-root"
# Room past the end of the filesystem for the LUKS2 header (D5): the default
# 16 MiB of metadata and keyslots, doubled, as cryptsetup's own example.
ENCRYPT_HEADER_ROOM=$((32 * 1024 * 1024))
# A change of volume key keeps a checksum of each sector of the area it is
# rewriting, and after a crash rewrites that area sector by sector before the
# volume opens. Left to cryptsetup the area is as large as the header has room
# for checksums: with 512-byte sectors on network block storage the next open
# took longer than 15 minutes (T-F2). Capped, that open takes a minute or two.
REKEY_HOTZONE="8M"

encrypt_unit_text() {
    cat <<EOF
# Written by joinery_data_root.sh (specs/hosted_data_promise_disk_encryption.md D5).
# The data root's in-place encryption (or its undo), online, at idle I/O
# priority. Never sent SIGKILL and never the OOM killer's: a killed run leaves
# its hotzone suspended and the data root waiting for a reboot.
# Ordered after the data root, never requiring it: the undo's finish stops the
# mount to move it off the mapping, and a Requires= would stop this unit, and
# the finish with it, half-way.
[Unit]
Description=Joinery data root: encrypt it where it stands, online
After=${TARGET_UNIT}

[Service]
Type=simple
ExecStart=/bin/bash ${INSTALLED_SELF_REAL} encrypt-resume
Nice=10
IOSchedulingClass=idle
IOWeight=10
OOMScoreAdjust=-1000
KillSignal=SIGTERM
SendSIGKILL=no
TimeoutStopSec=infinity
Restart=on-failure
RestartSec=60
StandardOutput=journal

[Install]
WantedBy=${TARGET_UNIT}
EOF
}

# The script itself, root's, where a unit can name it: the run outlives any
# tree it was started from.
install_self() {
    [[ -z "$ROOT" ]] || return 0
    cmp -s "$SELF" "$INSTALLED_SELF_REAL" 2>/dev/null && return 0
    install -o root -g root -m 755 "$SELF" "$INSTALLED_SELF_REAL" || die "could not install ${INSTALLED_SELF_REAL}"
}

# The root copy a run's unit starts, brought up to this one once no run is
# recorded: a run resumes on the copy it started with, and between runs the
# copy is what `joinery-data-root status` answers with (B46).
refresh_installed_self() {
    local dest="${ROOT}${INSTALLED_SELF_REAL}"
    [[ -f "$dest" && -z "$(reencrypt_mode)" ]] || return 0
    cmp -s "$SELF" "$dest" && return 0
    if [[ -z "$ROOT" ]]; then
        install -o root -g root -m 755 "$SELF" "$dest" 2>/dev/null || true
    else
        install -m 755 "$SELF" "$dest" 2>/dev/null || true
    fi
}

# The fstab line of an unencrypted data root: there, or gone.
fstab_line() {  # present|absent
    local uuid line=""
    if [[ "$1" == "present" ]]; then
        case "$(conf_get backing)" in
            file)   line="${IMAGE_REAL} ${MNT_REAL} xfs loop,prjquota,nofail 0 0" ;;
            device) uuid="$(conf_get uuid)"; line="UUID=${uuid} ${MNT_REAL} xfs prjquota,nofail 0 0" ;;
        esac
    fi
    { awk -v m="$FSTAB_MARK" 'BEGIN { skip = 0 } $0 == m { skip = 1; next } skip == 1 { skip = 0; next } { print }' "$FSTAB" 2>/dev/null
      [[ -z "$line" ]] || printf '%s\n%s\n' "$FSTAB_MARK" "$line"; } > "${FSTAB}.tmp.$$" \
        && sync -- "${FSTAB}.tmp.$$" 2>/dev/null && mv -f "${FSTAB}.tmp.$$" "$FSTAB" && durable "$FSTAB" || rm -f "${FSTAB}.tmp.$$"
    return 0
}

# KEY VALUE [KEY VALUE...] ('' removes one): every pair in one rename, so a
# crash between two of them can never leave half a change recorded (T-F2: the
# start of an encryption recorded its backing, and not its run).
conf_set() {
    local keys="" i
    for (( i = 1; i <= $#; i += 2 )); do keys+="${!i}"$'\n'; done
    awk -F= -v ks="$keys" 'BEGIN { n = split(ks, a, "\n"); for (i = 1; i <= n; i++) if (a[i] != "") drop[a[i]] = 1 } !($1 in drop)' "$CONF" > "${CONF}.tmp.$$" \
        || { rm -f "${CONF}.tmp.$$"; return 1; }
    while (( $# >= 1 )); do
        [[ -z "${2:-}" ]] || printf '%s=%s\n' "$1" "$2" >> "${CONF}.tmp.$$"
        shift; (( $# >= 1 )) && shift
    done
    chmod 644 "${CONF}.tmp.$$" && sync -- "${CONF}.tmp.$$" 2>/dev/null; mv -f "${CONF}.tmp.$$" "$CONF" && durable "$CONF"
}

# What holds the volume now: the image's loop device, made if need be, or the device.
luks_dev() {
    if [[ "$(conf_get backing)" == "luks-device" ]]; then
        luks_source
        return
    fi
    local d
    d="$(losetup -n -O NAME -j "$IMAGE_REAL" 2>/dev/null | head -n 1)"
    [[ -n "$d" ]] || d="$(loop_attach)" || return 1
    printf '%s' "$d"
}

reencrypt_mode() { conf_get reencrypt; }

# The filesystem's size in bytes, from XFS itself.
xfs_bytes() {
    local bsize blocks
    read -r bsize blocks < <(xfs_info "$1" 2>/dev/null | awk '/^data/ { for (i = 1; i <= NF; i++) { if ($i ~ /^bsize=/) b = substr($i, 7); if ($i ~ /^blocks=/) n = substr($i, 8) } } END { gsub(/,/, "", n); print b, n }')
    [[ "$bsize" =~ ^[0-9]+$ && "$blocks" =~ ^[0-9]+$ ]] || return 1
    printf '%s' $(( bsize * blocks ))
}

xfs_sector() {
    xfs_info "$1" 2>/dev/null | awk '/sectsz=/ { for (i = 1; i <= NF; i++) if ($i ~ /^sectsz=/) { print substr($i, 8); exit } }' | tr -d ','
}

# The consumers running now, to start again after the start step.
active_consumers() {
    local u
    while IFS= read -r u; do
        case "$u" in
            *@.service) continue ;;
        esac
        systemctl is-active --quiet "$u" 2>/dev/null && printf '%s\n' "$u"
    done < <(consumer_units | sort -u)
    systemctl list-units --type=service --state=active --plain --no-legend 'postgresql@*' 'postfix@*' 2>/dev/null | awk '{ print $1 }'
}

# Sockets that would start a consumer again the moment the data root stops
# (syslog.socket starts rsyslog at the next log line, docker.socket starts
# Docker), and so start the data root again under a stop: stopped first, and
# started again with the services.
consumer_sockets() {
    local s u
    while read -r s; do
        [[ -n "$s" ]] || continue
        for u in $(systemctl show -p Triggers --value "$s" 2>/dev/null); do
            # Not grep -q: closing the pipe early leaves consumer_units writing to it.
            if consumer_units | grep -xF "$u" >/dev/null; then
                printf '%s\n' "$s"
                break
            fi
        done
    done < <(systemctl list-units --type=socket --state=active --plain --no-legend 2>/dev/null | awk '$1 ~ /\.socket$/ { print $1 }')
    return 0
}

do_encrypt() {
    local keep_layer=0 arg why=() backing source dev sidecar_real bytes fsize sector
    for arg in "$@"; do
        case "$arg" in
            --keep-provider-layer) keep_layer=1 ;;
            *) die "encrypt takes --keep-provider-layer only" ;;
        esac
    done
    need_root
    take_lock
    [[ -f "$CONF" ]] || die "this host has no data root to encrypt"
    is_luks && die "the data root is encrypted already$( [[ -n "$(reencrypt_mode)" ]] && printf ' (its %s is running: joinery_data_root.sh status)' "$(reencrypt_mode)")"
    backing="$(conf_get backing)"; source="$(conf_get source)"

    # D5 step 1: everything that would stop it, said at once, nothing changed.
    do_check ready >/dev/null 2>&1 || why+=("the data root is not ready: $(ready_reason)")
    [[ -f "$RECIPIENT" ]] && recipient_ok || why+=("no recovery recipient is set (joinery_data_root.sh recipient PUBLIC_KEY)")
    "$AGENT_BIN" data-root check-recipient "$RECIPIENT" >/dev/null 2>&1 || why+=("the joinery-agent at ${AGENT_BIN#"$ROOT"} cannot seal to the recovery key")
    command -v cryptsetup >/dev/null 2>&1 || why+=("cryptsetup is not installed")
    [[ ! -e "${ROOT}${MAPPER_REAL}" ]] || why+=("${MAPPER_REAL} is already open")
    fsize="$(xfs_bytes "$MNT" || true)"
    sector="$(xfs_sector "$MNT" || true)"
    [[ "$fsize" =~ ^[0-9]+$ ]] || why+=("the data root's filesystem size cannot be read (xfs_info ${MNT_REAL})")
    [[ "$sector" == "512" || "$sector" == "4096" ]] || why+=("the data root's sector size cannot be read (xfs_info ${MNT_REAL})")
    case "$backing" in
        file)
            sidecar_real="$SIDECAR_FILE_REAL"
            local givable
            givable="$(root_givable || echo 0)"
            (( givable >= ENCRYPT_HEADER_ROOM )) || why+=("the root disk cannot give the 32 MiB the encrypted volume's header needs")
            ;;
        device)
            sidecar_real="$SIDECAR_DEVICE_REAL"
            bytes="$(blockdev --getsize64 "$(luks_source)" 2>/dev/null || true)"
            [[ "$bytes" =~ ^[0-9]+$ && "$fsize" =~ ^[0-9]+$ ]] && (( bytes - fsize >= ENCRYPT_HEADER_ROOM )) \
                || why+=("${source} has no 32 MiB past the end of its filesystem for the header: grow the volume at its provider by 32 MiB first")
            ;;
        *) why+=("the data root's backing is ${backing}, which this does not encrypt") ;;
    esac
    if (( ${#why[@]} > 0 )); then
        printf 'joinery_data_root: cannot encrypt the data root:\n' >&2
        printf '  - %s\n' "${why[@]}" >&2
        die "Nothing was changed."
    fi
    install_self

    # Step 2: the keys, in /run, sealed before anything is written.
    local keys
    mkdir -p "${ROOT}/run/joinery"
    keys="$(mktemp -d "${ROOT}/run/joinery/data-root-key.XXXXXX")" || die "could not make a place in /run for the keys"
    CREATE_KEYS="$keys"
    CREATE_MADE=("${ROOT}${sidecar_real}" "${ROOT}${DRIVING_SIDECAR_REAL}")
    trap create_abort EXIT
    ( umask 077
      head -c 64 /dev/urandom > "${keys}/volume.key"
      head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n' > "${keys}/slot.key" ) || die "could not make the keys"
    mkdir -p "$DATA_ROOT_DIR"
    "$AGENT_BIN" data-root seal --recipient "$RECIPIENT" < "${keys}/volume.key" > "${ROOT}${sidecar_real}.tmp.$$" \
        && chmod 600 "${ROOT}${sidecar_real}.tmp.$$" && mv -f "${ROOT}${sidecar_real}.tmp.$$" "${ROOT}${sidecar_real}" \
        || die "could not seal the new volume key to the recovery key. Nothing was changed."
    "$AGENT_BIN" data-root seal --recipient "$RECIPIENT" --slot < "${keys}/slot.key" > "${ROOT}${DRIVING_SIDECAR_REAL}.tmp.$$" \
        && chmod 600 "${ROOT}${DRIVING_SIDECAR_REAL}.tmp.$$" && mv -f "${ROOT}${DRIVING_SIDECAR_REAL}.tmp.$$" "${ROOT}${DRIVING_SIDECAR_REAL}" \
        || die "could not seal the driving slot's secret to the recovery key. Nothing was changed."
    "$AGENT_BIN" data-root identity >/dev/null || die "could not make this machine's unlock identity. Nothing was changed."
    # A new header holds no escrow slot. The holder and slot an earlier
    # encryption of this machine recorded go: its enrolment is made afresh, and
    # never removes a slot of this header by that old number.
    rm -f "${DATA_ROOT_DIR}/holder"
    durable "${ROOT}${sidecar_real}" "${ROOT}${DRIVING_SIDECAR_REAL}" "${DATA_ROOT_DIR}/unlock_identity"
    sync
    trap - EXIT

    # Step 3: offline, seconds. Every consumer stops with the mount (each one
    # requires the target, which requires the mount). From the first stop to
    # the last start, any way this run ends (a refusal, a failure, a signal)
    # starts again what it stopped (encrypt_start_exit).
    local -a sockets=()
    mapfile -t sockets < <(consumer_sockets | sort -u)
    mapfile -t ENC_RUNNING < <({ active_consumers; printf '%s\n' "${sockets[@]}"; } | grep -v '^$' | sort -u)
    ENC_KEYS="$keys"
    ENC_STAGE=stopped
    trap encrypt_start_exit EXIT
    trap 'exit 129' HUP; trap 'exit 130' INT; trap 'exit 143' TERM
    say "Stopping the data root and its services for the start (seconds)..."
    # Which filesystem the data root is, so that every mount of it, the
    # binds included, can be seen to be gone before the volume is written.
    local fs_id held
    fs_id="$(fs_id_at "$MNT_REAL")"
    (( ${#sockets[@]} == 0 )) || systemctl stop "${sockets[@]}" || true
    systemctl stop "$MOUNT_UNIT" || die "the data root did not stop; nothing was changed"
    # A bind that something holds open (a cron job's log, a message being
    # queued) does not unmount, and keeps the filesystem live on its old
    # loop device: writing the volume under it would interleave with its
    # writes (T-F2). Nothing starts until it is gone.
    held="$(mounts_of_fs "$fs_id")"
    if [[ -n "$held" ]]; then
        # Services that keep a file there: rsyslog keeps its socket in
        # Postfix's chroot (/var/spool/postfix/dev/log) on every Ubuntu host.
        # Stopped for the start and started again with the rest.
        local -a holders=()
        # shellcheck disable=SC2086
        mapfile -t holders < <(holder_units $held)
        if (( ${#holders[@]} > 0 )); then
            say "data root: ${holders[*]} keep a file on it; stopping them for the start"
            ENC_RUNNING+=("${holders[@]}")
            systemctl stop "${holders[@]}" 2>/dev/null || true
            local m
            for m in $held; do
                systemctl stop "$(systemd-escape -p --suffix=mount "$m")" 2>/dev/null || umount "$m" 2>/dev/null || true
            done
            held="$(mounts_of_fs "$fs_id")"
        fi
    fi
    [[ -z "$held" ]] || die "the data root's filesystem is still mounted at ${held}: something there would not let go. Nothing was changed; its services are started again. Try again when it is idle."
    if [[ "$backing" == "file" ]]; then
        losetup -n -O NAME -j "$IMAGE_REAL" 2>/dev/null | while read -r d; do losetup -d "$d" 2>/dev/null || true; done
        fallocate -l $(( $(image_bytes) + ENCRYPT_HEADER_ROOM )) "$IMAGE" || die "could not add 32 MiB to ${IMAGE_REAL}"
        dev="$(loop_attach)" || die "could not attach ${IMAGE_REAL} to a loop device"
    else
        dev="$(luks_source)"
    fi
    say "Starting the encryption (LUKS2, aes-xts-plain64, 512-bit key, ${sector}-byte sectors)..."
    # From here the volume may hold the header: an end before it is recorded
    # is undone as the tick undoes a start a power cut left (encrypt_start_undo).
    ENC_STAGE=header
    cryptsetup reencrypt --encrypt --init-only --batch-mode --disable-keyring --type luks2 \
        --cipher aes-xts-plain64 --key-size 512 --sector-size "$sector" "${LUKS_SLOT[@]}" \
        --reduce-device-size "$ENCRYPT_HEADER_ROOM" --volume-key-file "${keys}/volume.key" \
        --key-slot 0 --key-file "${keys}/slot.key" "$dev" "$MAPPING" \
        || die "cryptsetup could not start the encryption"
    rm -f "${keys}/volume.key"
    # The driving secret, where the run (and a resumed one) reads it.
    mkdir -p "$ENCRYPT_RUN_DIR"; chmod 700 "$ENCRYPT_RUN_DIR"
    mv -f "${keys}/slot.key" "${ENCRYPT_RUN_DIR}/slot.key"
    rmdir "$keys" 2>/dev/null || true
    # From here the data root is the encrypted volume: no fstab line, its own units.
    local luks_uuid=""
    [[ "$backing" == "device" ]] && luks_uuid="$(cryptsetup luksUUID "$dev")"
    if [[ -n "$luks_uuid" ]]; then
        conf_set backing "luks-${backing}" sidecar "$sidecar_real" uuid "$luks_uuid" reencrypt encrypt driving_slot 0 driving_sidecar "$DRIVING_SIDECAR_REAL"
    else
        conf_set backing "luks-${backing}" sidecar "$sidecar_real" reencrypt encrypt driving_slot 0 driving_sidecar "$DRIVING_SIDECAR_REAL"
    fi
    ENC_STAGE=recorded
    fstab_line absent
    ensure_units || say "WARNING: the units could not all be written; see above"
    # The record, the units and the run's enable link on disk before anything runs on them.
    sync
    systemctl start "$TARGET_UNIT" || say "WARNING: ${TARGET_UNIT} did not start; see: systemctl status ${MOUNT_UNIT}"
    (( ${#ENC_RUNNING[@]} == 0 )) || systemctl start "${ENC_RUNNING[@]}" || say "WARNING: not every service started again: ${ENC_RUNNING[*]}"
    ENC_STAGE=""
    trap - EXIT HUP INT TERM
    mounted_ok || die "the encryption has started, but ${MNT_REAL} is not mounted; see: systemctl status ${MOUNT_UNIT}"
    # Step 4: online, at idle priority.
    systemctl start --no-block "$ENCRYPT_UNIT" || say "WARNING: ${ENCRYPT_UNIT} did not start; see: systemctl status ${ENCRYPT_UNIT}"
    say "The data root is back, and encrypting in the background (joinery_data_root.sh status shows how far). When it finishes, the services stop again for a few seconds, as the logs move onto it. A reboot part-way opens it with the recovery key."
}

# The start's end, whichever way it came, once the services were stopped:
# what it stopped starts again, on the data root as the volume now is.
#   stopped:  nothing written to the volume; its sealed copies and keys go,
#             and the data root starts plain, as it was.
#   header:   cryptsetup may have written the header and the record does
#             not say so: the start is undone (the data stays where it is),
#             then as stopped.
#   recorded: the record says encrypting: the data root starts on the
#             volume, and the run with it.
ENC_STAGE=""
ENC_KEYS=""
ENC_RUNNING=()
encrypt_start_exit() {
    local rc=$?
    trap - EXIT HUP INT TERM
    set +e
    case "$ENC_STAGE" in
        stopped|header)
            if [[ "$ENC_STAGE" == header ]]; then
                # cryptsetup opens the volume as its start ends, and a signal
                # waits for it to end: the mapping holds the loop device, and
                # nothing mounts the image until it is closed (B49).
                mapping_open && { cryptsetup close "$MAPPING" 2>/dev/null || say "data root: WARNING: ${MAPPER_REAL} did not close"; }
                # The loop device this start attached; nothing else has the image now.
                [[ "$(conf_get backing)" == file ]] && losetup -n -O NAME -j "$IMAGE_REAL" 2>/dev/null | while read -r d; do losetup -d "$d" 2>/dev/null || true; done
                if ! encrypt_start_undo; then
                    # A header it does not know: left for a person, with the
                    # keys that open it (sealed beside it, and in /run).
                    say "data root: NOT STARTED AGAIN: the volume holds a start this cannot undo; its services stay stopped"
                    exit "$rc"
                fi
            fi
            # The copies sealed for a volume that was not made go too.
            rm -f "${ROOT}${SIDECAR_FILE_REAL}" "${ROOT}${SIDECAR_DEVICE_REAL}" "${ROOT}${DRIVING_SIDECAR_REAL}" "${ENCRYPT_RUN_DIR}/slot.key"
            [[ -z "$ENC_KEYS" ]] || rm -rf "$ENC_KEYS"
            systemctl start "$TARGET_UNIT" 2>/dev/null
            (( ${#ENC_RUNNING[@]} == 0 )) || systemctl start "${ENC_RUNNING[@]}" 2>/dev/null
            say "data root: as it was, unencrypted, and its services started again"
            ;;
        recorded)
            [[ -z "$ENC_KEYS" ]] || rm -rf "$ENC_KEYS"
            systemctl start "$TARGET_UNIT" 2>/dev/null
            (( ${#ENC_RUNNING[@]} == 0 )) || systemctl start "${ENC_RUNNING[@]}" 2>/dev/null
            systemctl start --no-block "$ENCRYPT_UNIT" 2>/dev/null
            say "data root: the encryption is recorded; the data root and its services started again on the volume"
            ;;
    esac
    exit "$rc"
}

# The run: encrypt (or decrypt) online until done, then finish. The unit's.
do_encrypt_resume() {
    need_root
    local mode key slot dev rc=0 header=()
    mode="$(reencrypt_mode)"
    [[ -n "$mode" ]] || { say "data root: no encryption is running"; return 0; }
    if [[ "$mode" == "decrypt" ]]; then
        dev="$(luks_dev)" || { say "data root: the volume's device cannot be found"; return 1; }
        if ! mapping_open && backing_is_plain "$dev"; then
            decrypt_finish "$dev"
            return
        fi
    fi
    mapping_open || { say "data root: locked; the run resumes once it is opened"; return 75; }
    key="${ENCRYPT_RUN_DIR}/slot.key"
    if [[ ! -s "$key" ]]; then
        say "data root: the ${mode} waits for its driving key, which the unlock puts back after a reboot (open it with the recovery key)"
        return 75
    fi
    slot="$(conf_get driving_slot)"
    [[ "$mode" == "decrypt" ]] && header=(--header "${ROOT}$(conf_get decrypt_header)")
    dev="$(luks_dev)" || { say "data root: the volume's device cannot be found"; return 1; }
    # Already done (a run that finished and was stopped before it noted it).
    if cryptsetup luksDump "${header[@]}" "$dev" 2>/dev/null | grep -q 'online-reencrypt'; then
        say "data root: ${mode}ing online, at idle priority"
        # The driving slot is named for an encryption or its undo. A change
        # of volume key has two slots behind the one secret, the old key's
        # and the new key's, and cryptsetup refuses --key-slot for it.
        local slot_args=(--key-slot "$slot")
        [[ "$mode" == "reencrypt" ]] && slot_args=(--hotzone-size "$REKEY_HOTZONE")
        # Line-buffered: into a pipe cryptsetup's reports would otherwise
        # arrive only when it exits.
        set +e
        stdbuf -oL cryptsetup reencrypt --resume-only --disable-keyring "${header[@]}" "${slot_args[@]}" --key-file "$key" \
            --progress-json --progress-frequency 30 --active-name "$MAPPING" 2>&1 \
            | while IFS= read -r line; do
                if [[ "$line" == \{* ]]; then printf '%s\n' "$line" > "$ENCRYPT_PROGRESS"; else printf '%s\n' "$line"; fi
            done
        rc="${PIPESTATUS[0]}"
        set -e
        if cryptsetup luksDump "${header[@]}" "$dev" 2>/dev/null | grep -q 'online-reencrypt'; then
            say "data root: the ${mode} stopped part-way (cryptsetup exit ${rc}); it resumes"
            return 1
        fi
    fi
    case "$mode" in
        encrypt)   encrypt_finish "$dev" "$key" ;;
        reencrypt) rekey_finish "$dev" "$key" ;;
        *)         decrypt_finish "$dev" ;;
    esac
}

# D5 step 6: the driving slot and its pending copy go, the header is backed up.
encrypt_finish() {  # DEV KEYFILE
    local dev="$1" key="$2"
    # A TPM2 chip is enrolled now, through the driving slot before it goes: no
    # slot can be added while an encryption is part-way.
    if tpm2_present && ! "$AGENT_BIN" data-root slots "$dev" 2>/dev/null | grep -q 'token=systemd-tpm2'; then
        if systemd-cryptenroll --unlock-key-file="$key" --tpm2-device=auto --tpm2-pcrs=7 "$dev" >/dev/null 2>&1; then
            say "data root: TPM2: this machine's chip opens it at boot"
        else
            say "data root: WARNING: enrolling the TPM2 chip failed; it opens with the recovery key"
        fi
    fi
    cryptsetup luksRemoveKey --batch-mode "$dev" "$key" || { say "data root: encrypted, but the driving slot did not go; it is tried again"; return 1; }
    rm -f "${ROOT}$(conf_get driving_sidecar)" "$key" "$ENCRYPT_PROGRESS"
    rmdir "$ENCRYPT_RUN_DIR" 2>/dev/null || true
    rm -f "${ROOT}${HEADER_BACKUP_REAL}"
    cryptsetup luksHeaderBackup "$dev" --header-backup-file "${ROOT}${HEADER_BACKUP_REAL}" \
        && chmod 600 "${ROOT}${HEADER_BACKUP_REAL}" || say "data root: WARNING: the header backup was not taken"
    conf_set reencrypt "" driving_slot "" driving_sidecar ""
    # Each site's config/ moves in now, so its secrets are never in a part-plain volume (D9).
    luks_moves_due || say "data root: WARNING: something on the root disk did not move onto it (above); the tick tries again each minute"
    luks_hardening
    # The root disk's freed blocks may hold data from before (copies a move
    # kept until a reboot): handed back to the provider's storage now.
    command -v fstrim >/dev/null 2>&1 && fstrim "${ROOT}/" >/dev/null 2>&1 || true
    date -u +%s > "${DATA_ROOT_DIR}/encrypted-since"
    ensure_units >/dev/null || true
    say "data root: encrypted. Copies of the root disk and provider backups made before now still hold its data in plain text: purge them."
    systemctl disable "$ENCRYPT_UNIT" >/dev/null 2>&1 || true
}

# A leaked recovery key (D8): the volume key itself changes, online. LUKS2
# re-encryption under a new volume key, driven by a slot of its own sealed to
# the recovery key, so a reboot part-way opens with it, as an encryption does.
# The new volume key is sealed to the recovery key as a pending copy before
# anything is written. Every other slot goes with the old volume key: at the
# finish the TPM2 chip is enrolled again, and the escrow slot is made again
# with a new secret (escrow-rekey), the change proven to the holder with the
# old key's report key, which is kept on the data root meanwhile.
REKEY_DIR="${MNT}/.joinery-rekey"
do_rekey() {
    need_root
    take_lock
    is_luks || die "the data root is not encrypted"
    [[ -z "$(reencrypt_mode)" ]] || die "an encryption, its undo or a re-encryption is running (joinery_data_root.sh status)"
    mapping_open && mounted_ok || die "the data root is not open and mounted: $(ready_reason)"
    [[ -f "$RECIPIENT" ]] || die "no recovery recipient is set: the new volume key is sealed to it"
    install_self
    local dev keys slot pending
    dev="$(luks_dev)" || die "the volume's device cannot be found"
    keys="$ENCRYPT_RUN_DIR"
    mkdir -p "$keys"; chmod 700 "$keys"
    pending="$(conf_get sidecar).pending"
    rekey_undo() {
        rm -f "${keys}/new.vk" "${keys}/slot.key" "${ROOT}${pending}" "${ROOT}${DRIVING_SIDECAR_REAL}"
        rm -rf "${REKEY_DIR:?}"
    }
    mkdir -p "$REKEY_DIR"; chmod 700 "$REKEY_DIR"
    if ! "$AGENT_BIN" data-root report-key > "${REKEY_DIR}/old_report_key" 2>/dev/null; then
        rekey_undo; die "could not read the volume's report key. Nothing was changed."
    fi
    chmod 600 "${REKEY_DIR}/old_report_key"
    ( umask 077; head -c 64 /dev/urandom > "${keys}/new.vk" ) || { rekey_undo; die "could not make the new volume key"; }
    if ! "$AGENT_BIN" data-root seal --recipient "$RECIPIENT" < "${keys}/new.vk" > "${ROOT}${pending}"; then
        rekey_undo; die "could not seal the new volume key to the recovery key. Nothing was changed."
    fi
    chmod 600 "${ROOT}${pending}"
    ( umask 077; head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n' > "${keys}/slot.key" ) || { rekey_undo; die "could not make the driving key"; }
    if ! "$AGENT_BIN" data-root seal --recipient "$RECIPIENT" --slot < "${keys}/slot.key" > "${ROOT}${DRIVING_SIDECAR_REAL}"; then
        rekey_undo; die "could not seal the driving key to the recovery key. Nothing was changed."
    fi
    chmod 600 "${ROOT}${DRIVING_SIDECAR_REAL}"
    durable "${ROOT}${pending}" "${ROOT}${DRIVING_SIDECAR_REAL}"
    slot="$("$AGENT_BIN" data-root add-slot "$dev" < "${keys}/slot.key")" || { rekey_undo; die "could not add the driving key slot. Nothing was changed."; }
    [[ "$slot" =~ ^[0-9]+$ ]] || { rekey_undo; die "the driving key slot's number did not come back"; }
    conf_set driving_slot "$slot" driving_sidecar "$DRIVING_SIDECAR_REAL" rekey_sidecar "$pending" reencrypt reencrypt
    say "Re-encrypting the data root under a new volume key, online, at idle priority..."
    if ! cryptsetup reencrypt --init-only --batch-mode --disable-keyring --key-size 512 --hotzone-size "$REKEY_HOTZONE" \
            --pbkdf pbkdf2 --hash sha256 --pbkdf-force-iterations "$LUKS_ITERATIONS" \
            --volume-key-file "${keys}/new.vk" --key-slot "$slot" --key-file "${keys}/slot.key" "$dev"; then
        cryptsetup luksRemoveKey --batch-mode "$dev" "${keys}/slot.key" >/dev/null 2>&1 || true
        conf_set reencrypt "" driving_slot "" driving_sidecar "" rekey_sidecar ""
        rekey_undo
        die "cryptsetup could not start the re-encryption; the data root is as it was"
    fi
    # The new key is in the header now, and in its pending copy.
    rm -f "${keys}/new.vk"
    ensure_units >/dev/null || true
    systemctl enable "$ENCRYPT_UNIT" >/dev/null 2>&1 || true
    sync
    systemctl restart --no-block "$ENCRYPT_UNIT" || say "WARNING: ${ENCRYPT_UNIT} did not start"
    say "The data root is re-encrypting under a new volume key in the background (joinery_data_root.sh status shows how far)."
}

# D8 step 4: the new copy replaces the old, the TPM2 chip is enrolled again,
# the driving slot goes, and the escrow holder is given the new key's slot
# (or the tick asks it each minute until it is).
rekey_finish() {  # DEV KEYFILE
    local dev="$1" key="$2" pending
    pending="${ROOT}$(conf_get rekey_sidecar)"
    if [[ -s "$pending" ]]; then
        mv -f "$pending" "${ROOT}$(conf_get sidecar)" || { say "data root: re-encrypted, but its new break-glass copy did not replace the old; tried again"; return 1; }
    fi
    if tpm2_present; then
        if systemd-cryptenroll --unlock-key-file="$key" --wipe-slot=tpm2 --tpm2-device=auto --tpm2-pcrs=7 "$dev" >/dev/null 2>&1; then
            say "data root: TPM2: this machine's chip opens it at boot again"
        else
            say "data root: WARNING: enrolling the TPM2 chip failed; it opens with escrow or the recovery key"
        fi
    fi
    cryptsetup luksRemoveKey --batch-mode "$dev" "$key" || { say "data root: re-encrypted, but the driving slot did not go; it is tried again"; return 1; }
    # The tokens of the slots the re-encryption took away.
    "$AGENT_BIN" data-root prune-tokens "$dev" >/dev/null 2>&1 || true
    rm -f "${ROOT}$(conf_get driving_sidecar)" "$key" "$ENCRYPT_PROGRESS"
    rmdir "$ENCRYPT_RUN_DIR" 2>/dev/null || true
    rm -f "${ROOT}${HEADER_BACKUP_REAL}"
    cryptsetup luksHeaderBackup "$dev" --header-backup-file "${ROOT}${HEADER_BACKUP_REAL}" \
        && chmod 600 "${ROOT}${HEADER_BACKUP_REAL}" || say "data root: WARNING: the header backup was not taken"
    conf_set reencrypt "" driving_slot "" driving_sidecar "" rekey_sidecar "" escrow_rekey_due 1
    luks_escrow_rekey_due || true
    "$AGENT_BIN" data-root report addresses >/dev/null 2>&1 || true
    ensure_units >/dev/null || true
    systemctl disable "$ENCRYPT_UNIT" >/dev/null 2>&1 || true
    say "data root: re-encrypted under a new volume key. Copies of the old break-glass copy, in backups of the root disk, no longer open it."
}

# The escrow slot made again after a re-encryption (D8), until the holder
# takes it. No holder: nothing to do.
luks_escrow_rekey_due() {
    [[ "$(conf_get escrow_rekey_due)" == "1" ]] || return 0
    mapping_open || return 0
    if [[ ! -f "${DATA_ROOT_DIR}/holder" ]]; then
        conf_set escrow_rekey_due ""; rm -rf "${REKEY_DIR:?}"; return 0
    fi
    if "$AGENT_BIN" data-root escrow-rekey --old-report-key-file "${REKEY_DIR}/old_report_key"; then
        conf_set escrow_rekey_due ""; rm -rf "${REKEY_DIR:?}"
        say "data root: the escrow holder holds the new volume key's slot"
        return 0
    fi
    say "data root: the escrow holder did not take the new volume key's slot (above); it opens by the recovery key until it does; asked each minute"
    return 1
}

# Undo (D5 step 7): back to plain, online. A key slot drives it (one with a
# random secret, added with the volume key from the open mapping), and the
# header goes to a file on the root disk, which a part-way device opens with.
do_decrypt() {
    need_root
    take_lock
    is_luks || die "the data root is not encrypted"
    local mode dev keys slot vk
    mode="$(reencrypt_mode)"
    [[ "$mode" != "decrypt" ]] || die "the data root is being decrypted already (joinery_data_root.sh status)"
    [[ "$mode" != "reencrypt" ]] || die "the data root is being re-encrypted under a new volume key; decrypt it once that is done"
    mapping_open && mounted_ok || die "the data root is not open and mounted: $(ready_reason)"
    install_self
    dev="$(luks_dev)" || die "the volume's device cannot be found"
    keys="$ENCRYPT_RUN_DIR"
    mkdir -p "$keys"; chmod 700 "$keys"
    if [[ "$mode" == "encrypt" ]]; then
        # Stopping an encryption part-way: its own driving slot drives the undo.
        systemctl stop "$ENCRYPT_UNIT" 2>/dev/null || true
        [[ -s "${keys}/slot.key" ]] || die "the encryption's driving key is not in /run; open the data root with the recovery key first"
        slot="$(conf_get driving_slot)"
    else
        ( umask 077; head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n' > "${keys}/slot.key" ) || die "could not make the driving key"
        "$AGENT_BIN" data-root seal --recipient "$RECIPIENT" --slot < "${keys}/slot.key" > "${ROOT}${DRIVING_SIDECAR_REAL}" \
            && chmod 600 "${ROOT}${DRIVING_SIDECAR_REAL}" && durable "${ROOT}${DRIVING_SIDECAR_REAL}" \
            || die "could not seal the driving key to the recovery key. Nothing was changed."
        # The agent adds the slot, authorised by the volume key it reads from
        # the open mapping; both reach cryptsetup through pipes.
        slot="$("$AGENT_BIN" data-root add-slot "$dev" < "${keys}/slot.key")" \
            || { rm -f "${ROOT}${DRIVING_SIDECAR_REAL}" "${keys}/slot.key"; die "could not add the driving key slot. Nothing was changed."; }
        [[ "$slot" =~ ^[0-9]+$ ]] || die "the driving key slot's number did not come back"
    fi
    rm -f "${ROOT}${DECRYPT_HEADER_REAL}"
    conf_set driving_slot "$slot" driving_sidecar "$DRIVING_SIDECAR_REAL" decrypt_header "$DECRYPT_HEADER_REAL" reencrypt decrypt
    sync
    say "Decrypting the data root online, at idle priority..."
    if ! cryptsetup reencrypt --decrypt --init-only --batch-mode --disable-keyring --header "${ROOT}${DECRYPT_HEADER_REAL}" \
            --key-slot "$slot" --key-file "${keys}/slot.key" "$dev"; then
        if [[ -n "$mode" ]]; then conf_set reencrypt "$mode" decrypt_header ""
        else conf_set reencrypt "" decrypt_header "" driving_slot "" driving_sidecar ""; fi
        die "cryptsetup could not start the decryption; the data root is as it was"
    fi
    durable "${ROOT}${DECRYPT_HEADER_REAL}"
    ensure_units >/dev/null || true
    systemctl enable "$ENCRYPT_UNIT" >/dev/null 2>&1 || true
    sync
    systemctl restart --no-block "$ENCRYPT_UNIT" || say "WARNING: ${ENCRYPT_UNIT} did not start"
    say "The data root is decrypting in the background (joinery_data_root.sh status shows how far)."
}

# Decrypted: the data is plain at the start of its backing. A seconds-long
# stop moves the mount from the mapping to the backing itself. Crash-safe in
# its order: the mapping is closed by its own unit's stop (so the unlock unit
# never claims a mapping that is gone), and from the moment it is closed the
# record and the units say plain, so whatever happens next boots the plain
# data root; a run or an unlock that finds a decrypt recorded over a device
# that is already plain XFS comes here to finish it.
decrypt_finish() {  # DEV
    local dev="$1" backing plain
    # The tick starts a data root it finds unmounted unless a run has the lock.
    take_lock
    backing="$(conf_get backing)"; plain="${backing#luks-}"
    local -a running=() sockets=()
    mapfile -t sockets < <(consumer_sockets | sort -u)
    mapfile -t running < <({ active_consumers; printf '%s\n' "${sockets[@]}"; } | grep -v '^$' | sort -u)
    say "data root: decrypted; moving the mount off the mapping (seconds)..."
    if findmnt -n --mountpoint "$MNT" >/dev/null 2>&1; then
        (( ${#sockets[@]} == 0 )) || systemctl stop "${sockets[@]}" || true
        if ! systemctl stop "$MOUNT_UNIT"; then
            (( ${#running[@]} == 0 )) || systemctl start "${running[@]}" 2>/dev/null || true
            say "data root: the mount would not stop; tried again"
            return 1
        fi
    fi
    systemctl stop "$UNLOCK_UNIT" 2>/dev/null || true
    if mapping_open; then
        cryptsetup close "$MAPPING" 2>/dev/null || dmsetup remove "$MAPPING" 2>/dev/null \
            || { say "data root: the mapping would not close; tried again"; return 1; }
    fi
    [[ "$plain" == "file" ]] && losetup -n -O NAME -j "$IMAGE_REAL" 2>/dev/null | while read -r d; do losetup -d "$d" 2>/dev/null || true; done
    # From here the record says plain.
    local plain_uuid=""
    [[ "$plain" == "device" ]] && plain_uuid="$(blkid -s UUID -o value "$(conf_get source)" 2>/dev/null)"
    if [[ -n "$plain_uuid" ]]; then
        conf_set backing "$plain" reencrypt "" driving_slot "" driving_sidecar "" decrypt_header "" sidecar "" uuid "$plain_uuid"
    else
        conf_set backing "$plain" reencrypt "" driving_slot "" driving_sidecar "" decrypt_header "" sidecar ""
    fi
    rm -f "${SYSTEMD_DIR}/${MOUNT_UNIT}" "${SYSTEMD_DIR}/${UNLOCK_UNIT}" "${SYSTEMD_DIR}/${ENCRYPT_UNIT}"
    rm -rf "${SYSTEMD_DIR}/$(systemd-escape -p --suffix=device "$MAPPER_REAL").d"
    local u
    for u in $CONSOLE_WAITERS; do rm -f "${SYSTEMD_DIR}/${u}.d/${DROPIN_NAME}"; done
    fstab_line present
    systemctl daemon-reload
    ensure_units >/dev/null || true
    rm -f "${ROOT}${DECRYPT_HEADER_REAL}" "${ROOT}${DRIVING_SIDECAR_REAL}" "${ROOT}${SIDECAR_FILE_REAL}" "${ROOT}${SIDECAR_DEVICE_REAL}" \
        "${ROOT}${HEADER_BACKUP_REAL}" "${ENCRYPT_RUN_DIR}/slot.key" "$ENCRYPT_PROGRESS" "${DATA_ROOT_DIR}/encrypted-since"
    mount "$MNT" || { say "data root: the plain data root did not mount; see dmesg"; return 1; }
    systemctl start "$TARGET_UNIT" || true
    (( ${#running[@]} == 0 )) || systemctl start "${running[@]}" || true
    systemctl reset-failed "$UNLOCK_UNIT" "$ENCRYPT_UNIT" 2>/dev/null || true
    say "data root: decrypted, and mounted as it was before it was encrypted"
}

# The backing holds plain XFS at its start: a decryption has finished.
backing_is_plain() {  # DEV
    [[ "$(blkid -p -s TYPE -o value "$1" 2>/dev/null)" == "xfs" ]]
}

# What a running encrypt or decrypt has done, for status and the host report.
reencrypt_progress() {
    [[ -n "$(reencrypt_mode)" ]] || return 0
    local line pct
    line="$(cat "$ENCRYPT_PROGRESS" 2>/dev/null || true)"
    pct="$(printf '%s' "$line" | sed -n 's/.*"device_bytes":"\{0,1\}\([0-9]*\).*"device_size":"\{0,1\}\([0-9]*\).*/\1 \2/p' | awk '$2 > 0 { printf "%.1f", $1 * 100 / $2 }')"
    printf '%s %s' "$(reencrypt_mode)" "${pct:-?}"
}

# ---------------------------------------------------------------------------
# grow
# ---------------------------------------------------------------------------

# The file's size now.
image_bytes() { stat -c %s "$IMAGE" 2>/dev/null; }

# The image's loop devices pass no discard on. A discard (Ubuntu's weekly
# fstrim, which lists the data root from fstab) punches a hole in the file and
# hands that space back to the root disk, so the data root would promise
# space the disk may no longer have (THE FILE, above).
loop_no_discard() {
    local d f
    while IFS= read -r d; do
        [[ -n "$d" ]] || continue
        f="${ROOT}/sys/block/$(basename "$d")/queue/discard_max_bytes"
        [[ -f "$f" && "$(cat "$f" 2>/dev/null)" != "0" ]] || continue
        if { echo 0 > "$f"; } 2>/dev/null; then
            say "data root: ${d} passes no discard to ${IMAGE_REAL} any more"
        fi
    done < <(losetup -n -O NAME -j "$IMAGE_REAL" 2>/dev/null || true)
    return 0
}

reboot_override_text() {
    cat <<EOF
// Written by joinery_data_root.sh (specs/hosted_data_promise_disk_encryption.md D4).
// This host's data root is encrypted: apt does not reboot it. joinery_data_root.sh
// tick does, when the kernel asks, at this host's own minute between 06:00 and
// 08:00 UTC, and only after its escrow holder answers a preflight, so hosts never
// reboot together and never while their holder is known to be down.
Unattended-Upgrade::Automatic-Reboot "false";
EOF
}

# The reboot an update asked for, on an encrypted host that reboots after
# updates (D4). Its minute in the window comes from its machine id, so a fleet
# spreads across two hours; a tick at or after it, inside the window, reboots
# once the holder answers, and says once a day why when it does not.
luks_reboot_policy() {
    [[ -f "$REBOOT_POLICY" ]] || return 0
    local r=0
    write_if_changed "$REBOOT_OVERRIDE" "$(reboot_override_text)" || r=$?
    (( r == 2 )) && say "data root: could not write ${REBOOT_OVERRIDE#"$ROOT"}; apt may reboot this host on its own"
    if [[ ! -f "$REBOOT_REQUIRED" ]]; then
        rm -f "$REBOOT_HELD"
        return 0
    fi
    local id slot now
    id="$(cat "${ROOT}/etc/machine-id" 2>/dev/null || hostname)"
    slot=$(( 16#$(printf '%s' "$id" | sha256sum | cut -c1-6) % REBOOT_WINDOW_MINUTES ))
    now=$(( 10#$(date -u +%H) * 60 + 10#$(date -u +%M) ))
    # A fixture names the minute (UTC) it runs at; a root run reads the clock.
    [[ -n "$ROOT" && -n "${JOINERY_TEST_UTC_MINUTE:-}" ]] && now="$JOINERY_TEST_UTC_MINUTE"
    (( now >= REBOOT_WINDOW_START + slot && now < REBOOT_WINDOW_START + REBOOT_WINDOW_MINUTES )) || return 0
    local why
    if why="$("$AGENT_BIN" data-root preflight 2>&1)"; then
        rm -f "$REBOOT_HELD"
        say "data root: rebooting for the update that asked for it; its escrow holder answers"
        systemctl reboot || say "data root: the reboot did not start"
        return 0
    fi
    if [[ ! -f "$REBOOT_HELD" ]] || [[ -n "$(find "$REBOOT_HELD" -mmin +1440 2>/dev/null)" ]]; then
        say "data root: a reboot is due and held back: ${why#joinery-agent: }"
    fi
    mkdir -p "$(dirname "$REBOOT_HELD")"
    printf '%s %s\n' "$(date -u +%s)" "${why#joinery-agent: }" | head -c 400 > "$REBOOT_HELD"
}

# D8: every rotation re-seals. When the recipient file names another key than
# the break-glass copy is sealed to, the copy is sealed again from the volume
# key in the open mapping (never to disk) and replaced atomically, and the
# escrow holder hears the new box. Not while an encryption or its undo runs:
# its table is the run's, and the copy is sealed again once it is done.
luks_reseal_if_due() {
    [[ -z "$(conf_get reencrypt)" ]] || return 0
    mapping_open || return 0
    local sidecar want have
    [[ -n "$(conf_get sidecar)" && -f "$RECIPIENT" ]] || return 0
    sidecar="${ROOT}$(conf_get sidecar)"
    want="$(recipient_get fingerprint)"
    have="$(grep -o '"fingerprint": *"[0-9a-f]*"' "$sidecar" 2>/dev/null | head -n 1 | grep -o '[0-9a-f]\{64\}' || true)"
    [[ -n "$want" && "$want" != "$have" ]] || return 0
    # A copy that is gone is made again the same way: from the open volume.
    if "$AGENT_BIN" data-root reseal --recipient "$RECIPIENT" >/dev/null 2>&1; then
        say "data root: the break-glass copy is sealed again, to the recovery key ${want:0:16} ($([[ -n "$have" ]] && echo "it was ${have:0:16}" || echo "it was missing"))"
        "$AGENT_BIN" data-root report addresses >/dev/null 2>&1 || true
    else
        say "data root: the break-glass copy could not be sealed again to ${want:0:16}; tried each minute"
        return 1
    fi
}

# A boot the TPM2 chip did not open, because what it measures changed (a
# firmware or shim update), and that something else opened (escrow, the
# recovery key): the chip's slot is sealed again to this boot's measurements,
# so the next boot opens with it again (D2). Whoever opened this boot vouched
# for it. Through a slot made for the moment from the open volume's key, and
# taken away after.
TPM2_FAILED_MARK="${ROOT}/run/joinery/data-root.tpm2-failed"
luks_tpm2_reseal_if_due() {
    [[ -f "$TPM2_FAILED_MARK" ]] || return 0
    [[ -z "$(conf_get reencrypt)" ]] || return 0
    mapping_open || return 0
    tpm2_present || { rm -f "$TPM2_FAILED_MARK"; return 0; }
    local dev tmp
    dev="$(luks_dev)" || return 1
    tmp="$(mktemp -d "${ROOT}/run/joinery/tpm2-reseal.XXXXXX")" || return 1
    head -c 64 /dev/urandom > "${tmp}/k"
    if ! "$AGENT_BIN" data-root add-slot "$dev" < "${tmp}/k" >/dev/null 2>&1; then
        rm -rf "$tmp"
        say "data root: TPM2: the chip did not open it at this boot, and no slot could be made to seal it again; it is tried each minute"
        return 1
    fi
    if systemd-cryptenroll --unlock-key-file="${tmp}/k" --wipe-slot=tpm2 --tpm2-device=auto --tpm2-pcrs=7 "$dev" >/dev/null 2>&1; then
        rm -f "$TPM2_FAILED_MARK"
        say "data root: TPM2: the chip did not open it at this boot; it is sealed again to this boot's measurements"
    else
        say "data root: WARNING: TPM2: sealing the chip again failed; it opens with escrow or the recovery key until it is"
    fi
    cryptsetup luksRemoveKey --batch-mode "$dev" "${tmp}/k" >/dev/null 2>&1 \
        || say "data root: WARNING: the slot made to seal the chip again did not go; it is a slot of ours, sealed nowhere"
    rm -rf "$tmp"
    "$AGENT_BIN" data-root prune-tokens "$dev" >/dev/null 2>&1 || true
}

# Space a discard took back from the image is allocated again. fallocate
# leaves every block already written as it is, so this is safe while the data
# root is in use, and the image is whole again, as create made it.
image_fill_holes() {
    local size alloc
    size="$(image_bytes)" || return 0
    alloc="$(du -B1 "$IMAGE" 2>/dev/null | cut -f1)" || return 0
    [[ "$size" =~ ^[0-9]+$ && "$alloc" =~ ^[0-9]+$ ]] || return 0
    (( alloc < size )) || return 0
    if ! fallocate -l "$size" "$IMAGE"; then
        say "data root: the root disk took back $(gib $(( size - alloc ))) of ${IMAGE_REAL}, and it could not be allocated again"
        return 1
    fi
    say "data root: allocated again $(gib $(( size - alloc ))) of ${IMAGE_REAL} that a discard had handed back to the root disk"
}

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
    if is_luks; then
        local r=0
        mapping_resize || r=$?
        (( r != 1 )) || die "${IMAGE_REAL} is $(gib "$target"), but the encrypted volume did not grow with it; see: dmsetup table ${MAPPING}"
    fi
    xfs_growfs "$MNT" >/dev/null || die "${IMAGE_REAL} is $(gib "$target"), but the filesystem did not grow with it; run: xfs_growfs ${MNT_REAL}"
    say "data root: grew from $(gib "$cur") to $(gib "$target")"
}

# The open mapping grown to the end of what it sits on (D1b). Its key comes
# from the live table (the volume is opened with --disable-keyring for this)
# and goes to the reload through a pipe, never to a disk or an argument:
# cryptsetup resize would ask for a key slot's passphrase, and on an escrow
# machine that secret is not here. 0 grown, 2 nothing to grow, 1 failed.
mapping_resize() {
    local table len dev offset sectors want
    table="$(dmsetup table "$MAPPING" 2>/dev/null)" || return 1
    read -r _ len _ _ _ _ dev offset _ <<< "$table"
    [[ "$len" =~ ^[0-9]+$ && "$offset" =~ ^[0-9]+$ && "$dev" =~ ^[0-9]+:[0-9]+$ ]] || return 1
    sectors="$(cat "${ROOT}/sys/dev/block/${dev}/size" 2>/dev/null)"
    [[ "$sectors" =~ ^[0-9]+$ ]] || return 1
    # Whole 4096-byte sectors (LUKS_FORMAT's --sector-size).
    want=$(( (sectors - offset) / 8 * 8 ))
    (( want > len )) || return 2
    dmsetup table --showkeys "$MAPPING" | awk -v n="$want" 'NR == 1 { $2 = n; print }' | dmsetup reload "$MAPPING" || return 1
    dmsetup resume "$MAPPING" || return 1
    return 0
}

# A device the provider has grown: the filesystem follows it.
grow_device() {
    local dev dsize fsize
    if is_luks; then
        # The mapping first, when the device grew under an open one; then the
        # filesystem, to the mapping, which an open after a reboot has already
        # sized to the whole device.
        local r=0 msize
        mapping_resize || r=$?
        (( r != 1 )) || die "$(conf_get source) has grown, but the encrypted volume did not grow with it; see: dmsetup table ${MAPPING}"
        msize="$(blockdev --getsize64 "${ROOT}${MAPPER_REAL}" 2>/dev/null || true)"
        read -r fsize _ _ < <(df_bytes "$MNT") || return 0
        [[ "$msize" =~ ^[0-9]+$ ]] || return 0
        (( msize > fsize + GIB )) || return 1
        xfs_growfs "$MNT" >/dev/null || die "the encrypted volume grew, but the filesystem did not grow with it; run: xfs_growfs ${MNT_REAL}"
        read -r fsize _ _ < <(df_bytes "$MNT") || true
        say "data root: grew with $(conf_get source) to $(gib "$fsize")"
        return 0
    fi
    # By its UUID: a device name can change across boots, and the mount is by UUID.
    dev="$(conf_get source)"
    [[ -n "$(conf_get uuid)" && -e "${ROOT}/dev/disk/by-uuid/$(conf_get uuid)" ]] && dev="/dev/disk/by-uuid/$(conf_get uuid)"
    dsize="$(blockdev --getsize64 "$dev" 2>/dev/null || true)"
    read -r fsize _ _ < <(df_bytes "$MNT") || return 0
    [[ "$dsize" =~ ^[0-9]+$ ]] || return 0
    (( dsize > fsize + GIB )) || return 1
    # To 32 MiB short of the device: an in-place encrypt puts its header there,
    # and a filesystem grown into all of it would leave none (D5).
    local bsize
    bsize="$(xfs_info "$MNT" 2>/dev/null | sed -n 's/^data *= *bsize=\([0-9]*\).*/\1/p')"
    [[ "$bsize" =~ ^[0-9]+$ ]] || bsize=4096
    xfs_growfs -D $(( (dsize - ENCRYPT_HEADER_ROOM) / bsize )) "$MNT" >/dev/null \
        || die "${dev} is $(gib "$dsize"), but the filesystem did not grow with it; run: xfs_growfs ${MNT_REAL}"
    read -r fsize _ _ < <(df_bytes "$MNT") || true
    say "data root: grew with ${dev} to $(gib "$fsize")"
}

do_grow() {
    local want="${1:-}" bytes cur givable
    need_root
    do_check ready >/dev/null || die "there is no data root here to grow, or it is not mounted"
    take_lock
    case "$(conf_get backing)" in
        device|luks-device)
            [[ -z "$want" ]] || die "a data root on a device grows when its provider grows the device; then run: joinery_data_root.sh grow"
            grow_device || say "data root: ${MNT_REAL} already fills $(conf_get source); grow the device at its provider first"
            ;;
        file|luks-file)
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
# A plain data root, mounted, with no run of this script going: copies
# sealed and keys made for a volume an encrypt's start never made (one cut
# off where nothing could start it again, B42) are left over, and go.
plain_leftovers() {
    local f gone=0
    for f in "${ROOT}${SIDECAR_FILE_REAL}" "${ROOT}${SIDECAR_DEVICE_REAL}" "${ROOT}${DRIVING_SIDECAR_REAL}" \
             "${ENCRYPT_RUN_DIR}/slot.key" "${ROOT}/run/joinery/data-root-key."*; do
        [[ -e "$f" ]] || continue
        rm -rf "$f" && gone=1
    done
    (( gone == 0 )) || say "data root: removed the keys and sealed copies an encrypt's start left behind; the data root is plain"
    return 0
}

tick_upkeep() {
    ensure_units || return 1
    migrate_forget_old || true
    is_luks || plain_leftovers
    if is_luks; then
        luks_reboot_policy || true
        luks_reseal_if_due || true
        luks_tpm2_reseal_if_due || true
        luks_escrow_rekey_due || true
        luks_hardening
        luks_moves_due || true
        luks_log_sweep || true
    fi
    # While the volume is being encrypted or decrypted its table is the run's:
    # no reload, so no growth until it is done. The run itself is kept going:
    # a boot that opened the volume and did not start it (T-F2: after a cut
    # during a decrypt) leaves it waiting for nothing.
    if [[ -n "$(conf_get reencrypt)" ]]; then
        if is_luks && mapping_open && ! systemctl -q is-active "$ENCRYPT_UNIT" 2>/dev/null \
            && ! systemctl -q is-active joinery-data-decrypt-finish.service 2>/dev/null; then
            say "data root: its $(conf_get reencrypt)ion was not running; started again"
            systemctl start --no-block "$ENCRYPT_UNIT" 2>/dev/null || true
        fi
        return 0
    fi
    case "$(conf_get backing)" in
        file|luks-file)
            loop_no_discard
            image_fill_holes || true
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
        device|luks-device)
            grow_device || true
            ;;
    esac
    return 0
}

# tick exits as check does, and for no other reason: 1 means the data root is
# not ready and the converger runs nothing. Upkeep that fails on a mounted
# data root (a growth that did not take, a reload systemd refused) is said and
# absorbed; the installers still run, and the next tick tries again.
# What a site's own pages may know of the data root (WP4), each tick, for
# PHP to read: the files it comes from are root's. Nothing secret.
STATUS_PUBLIC="${ROOT}/run/joinery/data-root.status"
write_public_status() {
    local out sidecar sfpr=""
    [[ -f "$CONF" ]] || { rm -f "$STATUS_PUBLIC"; return 0; }
    out="# Written by joinery_data_root.sh each tick (specs/hosted_data_promise_disk_encryption.md WP4).
backing=$(conf_get backing)"
    if is_luks; then
        sidecar="${ROOT}$(conf_get sidecar)"
        [[ -f "$sidecar" ]] && sfpr="$(grep -o '"fingerprint": *"[0-9a-f]*"' "$sidecar" 2>/dev/null | head -n 1 | grep -o '[0-9a-f]\{64\}' || true)"
        out+="
encrypted=1
open=$(mapping_open && echo 1 || echo 0)
opened_by=$(head -n 1 "${ROOT}/run/joinery/data-root.opened-by" 2>/dev/null | tr -cd '[:print:]' | cut -c1-120)
recipient_fingerprint=$(recipient_get fingerprint)
recipient_set_by=$(recipient_get set_by)
sidecar=$([[ -f "$sidecar" ]] && echo 1 || echo 0)
sidecar_fingerprint=${sfpr}
reencrypt=$(conf_get reencrypt)
reencrypt_percent=$(reencrypt_progress 2>/dev/null | awk '{ print $2 }' | tr -cd '0-9.')"
    else
        out+="
encrypted=0"
    fi
    mkdir -p "$(dirname "$STATUS_PUBLIC")" 2>/dev/null || true
    write_if_changed "$STATUS_PUBLIC" "$out" >/dev/null || true
}

# An encrypt whose start was cut off (a crash or a power cut) after
# cryptsetup wrote the volume's header and before this script recorded it:
# the record still says a plain file or device, so the boot mounts the
# header as a filesystem and fails. Nothing was encrypted yet (the record
# changes before the run starts), and cryptsetup's start keeps the data
# plain: it moved the first 16 MiB to the room added at the end and wrote
# the header where it was. The key slot it was writing need not have
# reached the disk (T-F2: it had not), so the start is undone rather than
# opened: the moved data goes back over the header, the image back to its
# length, and the data root mounts plain. encrypt can run again.
encrypt_start_undo() {
    [[ -f "$CONF" ]] && ! is_luks || return 0
    local backing dev plan req mode moved_off moved_len prev_len live
    backing="$(conf_get backing)"
    case "$backing" in file) dev="$IMAGE" ;; device) dev="$(luks_source)" ;; *) return 0 ;; esac
    mounted_ok && return 0
    [[ -e "$dev" ]] && cryptsetup isLuks "$dev" 2>/dev/null || return 0
    plan="$(cryptsetup luksDump "$dev" 2>/dev/null | awk '
        /^Requirements:/ && /online-reencrypt/ { req = 1 }
        /^Data segments:/ { seg = 1; next }
        /^Keyslots:/ { seg = 0; ks = 1 }
        /^Tokens:/ { ks = 0 }
        ks && /^\tMode:/ { mode = $2 }
        seg && /^  [0-9]+: / { n = $1; type[n] = $2; order[++c] = n; next }
        seg && /^\toffset:/ { off[n] = $2 }
        seg && /^\tlength:/ { len[n] = $2 }
        seg && /^\tflags :/ { flags[n] = $3 }
        END {
            for (i = 1; i <= c; i++) { k = order[i]
                if (flags[k] == "backup-moved-segment") { mo = off[k]; ml = len[k] }
                else if (flags[k] == "backup-previous") { pl = len[k] }
                else if (flags[k] == "" && type[k] != "linear") { live = live " " type[k] }
            }
            printf "%s %s %s %s %s %s\n", (req ? "req" : "-"), (mode ? mode : "-"), (mo ? mo : "-"), (ml ? ml : "-"), (pl ? pl : "-"), (live == "" ? "plain" : "encrypted")
        }')"
    read -r req mode moved_off moved_len prev_len live <<< "$plan"
    [[ "$req" == req && "$mode" == encrypt ]] || return 0
    if [[ "$live" != plain || ! "$moved_off" =~ ^[0-9]+$ || ! "$moved_len" =~ ^[0-9]+$ || ! "$prev_len" =~ ^[0-9]+$ ]]; then
        say "data root: ${dev#"$ROOT"} holds an encryption that started and is not recorded here (${plan}); it is left as it is for a person"
        return 1
    fi
    need_root
    take_lock nowait || return 0
    say "data root: an encryption's start was cut off before any data was encrypted; undoing it (the data stays where it is)"
    dd if="$dev" of="$dev" bs=1M iflag=skip_bytes,count_bytes oflag=seek_bytes conv=notrunc,fsync \
        skip="$moved_off" seek=0 count="$moved_len" status=none || { say "data root: WARNING: could not put the first ${moved_len} bytes back; ${dev#"$ROOT"} is left as it is"; return 1; }
    [[ "$backing" == file ]] && truncate -s "$prev_len" "$IMAGE"
    [[ "$backing" == device ]] && { udevadm trigger --action=change "$dev" 2>/dev/null; udevadm settle 2>/dev/null; } || true
    rm -f "${ROOT}${SIDECAR_FILE_REAL}" "${ROOT}${SIDECAR_DEVICE_REAL}" "${ROOT}${DRIVING_SIDECAR_REAL}"
    systemctl reset-failed "$MOUNT_UNIT" 2>/dev/null || true
    systemctl start "$TARGET_UNIT" 2>/dev/null || true
    # What the boot could not start without the data root starts now.
    systemctl start --no-block default.target 2>/dev/null || true
    say "data root: the encryption's start is undone and the data root is plain again; run encrypt again when ready"
}

do_tick() {
    local rc=0 rel target
    encrypt_start_undo || true
    write_public_status
    refresh_installed_self
    # Locked, waiting for its key: not ready, and nothing to say every minute
    # (check and status say why).
    if [[ -f "$CONF" ]] && is_luks && ! mapping_open; then
        return 1
    fi
    do_check ready >/dev/null 2>&1 || rc=$?
    # Not mounted, and no run of this script has it (an encrypt holds the
    # lock while the data root is stopped for its start): a start that ended
    # without starting it again (B42), or a stop by hand. Started again, and
    # said once while it will not mount.
    if (( rc == 1 )) && ! mounted_ok; then
        need_root
        if take_lock nowait; then
            # Plain, with the volume's mapping still open: a start cut off
            # after cryptsetup opened it (B49). The mapping holds the loop
            # device the mount needs.
            if ! is_luks && mapping_open; then
                cryptsetup close "$MAPPING" 2>/dev/null || true
            fi
            if systemctl start "$TARGET_UNIT" 2>/dev/null && mounted_ok; then
                rm -f "$NOT_MOUNTED_FLAG"
                # Every consumer requires the target, so what it holds stopped
                # with it and does not come back with it (B51).
                systemctl start --no-block default.target 2>/dev/null || true
                say "data root: it was not mounted; mounted it again, and started what stopped with it"
            elif [[ ! -f "$NOT_MOUNTED_FLAG" ]]; then
                touch "$NOT_MOUNTED_FLAG"
                say "data root: not mounted, and it did not mount when started: $(ready_reason)"
            fi
        fi
    fi
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
    do_check ready >/dev/null || rc=$?
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

# What opens the encrypted data root, for status: its key slots, the
# break-glass copy and whom it is sealed to, and what opened it this boot.
luks_status() {
    local slots line n=0 tpm=0 sidecar opened
    say "encryption: LUKS2, aes-xts-plain64, 512-bit key"
    slots="$("$AGENT_BIN" data-root slots "$(luks_source)" 2>/dev/null || true)"
    while IFS= read -r line; do
        [[ -n "$line" ]] || continue
        n=$((n + 1))
        [[ "$line" == *"token=systemd-tpm2"* ]] && tpm=1
    done <<< "$slots"
    if (( n == 0 )); then
        say "key slots: none; it opens with the recovery key"
    else
        say "key slots: ${n}$( (( tpm )) && printf ', one of them the TPM2 chip')"
    fi
    sidecar="$(conf_get sidecar)"
    if [[ -f "${ROOT}${sidecar}" ]]; then
        say "break-glass copy: ${sidecar}, sealed to the recovery key $(recipient_get fingerprint)"
    else
        say "break-glass copy: MISSING (${sidecar}); only the slots above open it"
    fi
    opened="$(cat "${ROOT}/run/joinery/data-root.opened-by" 2>/dev/null || true)"
    [[ -z "$opened" ]] || say "opened this boot by: ${opened}"
    if [[ -n "$(reencrypt_mode)" ]]; then
        local p done_text
        p="$(reencrypt_progress)"
        # cryptsetup writes its first progress line some seconds in.
        done_text="${p#* }% done"
        [[ "${p#* }" == "?" ]] && done_text="starting"
        say "running: ${p%% *}ion, ${done_text}, at idle I/O priority (${ENCRYPT_UNIT}); a reboot part-way opens with the recovery key, then it resumes"
    fi
    local problem
    problem="$(luks_slot_problem)"
    [[ -z "$problem" ]] || say "WARNING: ${problem}"
    return 0
}

do_status() {
    local rc=0 why fsize used avail givable d sz
    [[ -f "$CONF" ]] || { say "data root: none on this host"; return 0; }
    say "data root: ${MNT_REAL}, on $(conf_get backing) $(conf_get source)"
    why="$(ready_reason)"
    if [[ -n "$why" ]]; then
        say "state: NOT READY - ${why}"
        return 1
    fi
    say "state: mounted, XFS, project quotas on"
    if is_luks; then
        luks_status
    fi
    read -r fsize used avail < <(df_bytes "$MNT") || { say "figures: unreadable"; return 1; }
    say "size: $(gib "$fsize"); used $(gib "$used"); free $(gib "$avail")"
    if [[ "$(conf_get backing)" == "file" || "$(conf_get backing)" == "luks-file" ]]; then
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
    recipient) shift; do_recipient "$@" ;;
    check)  do_check ;;
    grow)   do_grow "${2:-}" ;;
    status) do_status ;;
    tick)   do_tick ;;
    bind)   do_bind "${2:-}" "${3:-}" ;;
    move-in) do_move_in "${2:-}" "${3:-}" ;;
    unbind) do_unbind "${2:-}" ;;
    remove-site) do_remove_site "${2:-}" ;;
    migrate) do_migrate "${2:-}" ;;
    encrypt) shift; do_encrypt "$@" ;;
    encrypt-resume) do_encrypt_resume ;;
    decrypt) do_decrypt ;;
    rekey)   do_rekey ;;
    *) die "usage: joinery_data_root.sh create [--encrypted] [SIZE] [DEVICE] | recipient [--set-by=site|operator] [PUBLIC_KEY] | check | grow [SIZE] | status | tick | bind REL TARGET | move-in REL TARGET | unbind TARGET | remove-site NAME | migrate [SIZE|DEVICE] | encrypt [--keep-provider-layer] | decrypt | rekey" ;;
esac
