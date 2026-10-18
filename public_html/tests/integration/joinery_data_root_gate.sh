#!/bin/bash
# @joinery-test
# name: joinery_data_root
# tier: safe
# env: any
# needs: []
# timeout: 300
# covers: [maintenance_scripts/install_tools/joinery_data_root.sh]
#
# The host's data root (specs/one_data_root.md WP1), against a scratch root
# with the system's commands stubbed: what joinery_data_root.sh writes and
# asks for, what it refuses, and when it grows (D7).
#
#   - check: 2 on a host with none; 1, with the reason, on one declared and
#     not mounted; 0 mounted as XFS with project quotas.
#   - create: refused for a size that is not one, one the root disk cannot
#     give above its reserve, a mount point that is not empty or already
#     mounted, and a device that carries anything. Otherwise the file is
#     allocated whole and formatted, recorded, given one fstab line, its
#     target, direct I/O unit and every consumer's drop-in, and mounted. Left
#     without a size, the first size is D7's. A device is formatted and
#     mounted by UUID.
#   - tick: keeps the units (quietly when they are right), grows the file by
#     D7's step when it is filling, grows only as far as the root disk can
#     give above its reserve, and says once when it cannot grow at all.
#   - grow SIZE: never shrinks, never takes the root disk under its reserve.
#   - the data root's own mount point (1.24): locked underneath, as a bind's
#     is, by create before the first mount, and by the first tick of a boot
#     on a host made earlier; only while it is empty: one holding files is
#     named and left.
#   - bind / unbind (WP2, D1): a path is a bind mount of its place under the
#     data root, recorded, its unit written and required by the target; a new
#     place takes the path's mode; a path holding data is refused with exit 3
#     and nothing changed; one path, one place. check fails while a recorded
#     bind is not in place and names it; tick mounts it again. unbind leaves
#     the data, and only this script's mount units are ever removed.
#   - the image stays whole: tick stops the loop device passing discards to
#     the file (Ubuntu's weekly fstrim would punch holes in it) and allocates
#     again any space one already took back.
#   - encrypted (hosted_data_promise_disk_encryption WP1): create --encrypted
#     refuses without a recovery recipient, seals the key before the volume is
#     written, formats LUKS2 in the standard configuration, enrols a TPM2 chip,
#     drops the first slot, opens with no keyring and no discards, and writes
#     the mount and unlock units in place of an fstab line; check fails a slot
#     the platform did not make (tick goes on), says "locked" when the volume
#     is not open; grow reloads the mapping with its own key, never as an
#     argument; a create that does not finish leaves nothing.
#   - migrate (WP3, D5): refuses, naming every reason and changing nothing, a
#     symlinked folder, a cluster outside /var/lib/postgresql, a root disk that
#     cannot hold the data twice and a site that is upgrading; otherwise makes
#     the data root, stops the running consumers and cron, copies each place
#     (symlinks and modes kept), switches each path to a mount, keeps the copy
#     from before at /srv/joinery.old until a tick on a later boot, starts the
#     services again, and moves Docker's data-root by daemon.json. A move whose
#     mount fails puts every folder back and starts the services. Run again, it
#     moves only what is not moved.
#   - stop / start by hand (B17): stop stops the sockets that would start a
#     consumer before the data root (a log line through syslog.socket canceled
#     a stop), then the mount, or on an encrypted one the volume's opener;
#     records what it stopped; the tick leaves a stopped one alone; a
#     filesystem still held is started again and said; start starts it and
#     what stopped, and an encrypted one is asked for its key as at a boot.
#   - the TPM2 chip by choice (B43): tpm2 off records tpm2=off and wipes the
#     chip's slot through a slot made for the moment; the re-seal, a rekey's
#     finish and create --encrypted --no-tpm2 then leave the chip alone; tpm2
#     on enrols it again; during a run the choice is only recorded; decrypt
#     clears it; the status file says whether there is a chip, and the choice.
#
# The real XFS behaviour (the loop device, online growth, project quotas) is
# the multi-tenant spec's proof on a scratch Linode; this pins the decisions.

set -u
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$HERE/../../.." && pwd)"
SCRIPT="$ROOT_DIR/maintenance_scripts/install_tools/joinery_data_root.sh"
G=$((1024 * 1024 * 1024))

passed=0; failed=0
chk() {
    if [ "$2" = "$3" ]; then echo "  PASS: $1"; passed=$((passed + 1))
    else echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed + 1)); fi
}

# Unprivileged only: as root the scripts ignore the fixture and the stubs would
# report a real /etc/fstab and real units rewritten as success.
if [ "$(id -u)" = "0" ]; then echo "  SKIP: this gate runs unprivileged"; echo "RESULT: PASS 0 0"; exit 0; fi

T="$(mktemp -d)"
trap 'rm -rf "$T"' EXIT
mkdir -p "$T/bin" "$T/root/etc" "$T/root/srv" "$T/root/lib/systemd/system"
LOG="$T/log"; : > "$LOG"
export GATE_LOG="$LOG" GATE_T="$T"

stub() { printf '#!/bin/bash\n%s\n' "$2" > "$T/bin/$1"; chmod 755 "$T/bin/$1"; }
# fallocate makes a sparse file of the asked length, so stat reads the size.
stub fallocate 'echo "fallocate $*" >> "$GATE_LOG"; truncate -s "$2" "$3"'
stub mkfs.xfs 'echo "mkfs.xfs $*" >> "$GATE_LOG"'
stub mount 'echo "mount $*" >> "$GATE_LOG"; touch "$GATE_T/mounted"'
stub systemctl 'echo "systemctl $*" >> "$GATE_LOG"'
stub apt-get 'echo "apt-get $*" >> "$GATE_LOG"'
stub xfs_growfs 'echo "xfs_growfs $*" >> "$GATE_LOG"'
stub losetup 'echo "losetup $*" >> "$GATE_LOG"; case "$*" in *-j*) echo /dev/loop7 ;; esac'
stub blockdev 'cat "$GATE_T/devsize" 2>/dev/null'
# blkid -p: 0 when the device carries something; -s UUID: the new filesystem's.
stub blkid 'case "$1" in -p) [ -f "$GATE_T/dev_has_fs" ] && exit 0; exit 2 ;; -s) echo 0b9c5a6e-1111-4222-8333-944455556666 ;; esac'
# findmnt: the data root when mounted; a stranger at the mount point when told.
stub findmnt 'case "$*" in
  *"-S "*) exit 1 ;;
  *FSTYPE,OPTIONS*) [ -f "$GATE_T/mounted" ] || exit 1; echo "$(cat "$GATE_T/fstype" 2>/dev/null || echo xfs) rw,relatime,$(cat "$GATE_T/opts" 2>/dev/null || echo prjquota)" ;;
  *"--mountpoint $GATE_T/root/srv/joinery") { [ -f "$GATE_T/mounted" ] || [ -f "$GATE_T/stranger" ]; } || exit 1; echo /srv/joinery ;;
  *--mountpoint*) p="${@: -1}"; grep -qxF "$p" "$GATE_T/othermounts" 2>/dev/null || exit 1; echo "$p" ;;
  *) cat "$GATE_T/othermounts" 2>/dev/null; { [ -f "$GATE_T/mounted" ] || [ -f "$GATE_T/stranger" ]; } || exit 1; echo /srv/joinery ;;
esac'
# df: figures by filesystem, "size used avail" in bytes.
stub df 'p="${@: -1}"; echo "1B-blocks Used Avail"; case "$p" in */srv/joinery) cat "$GATE_T/fig_data" ;; *) cat "$GATE_T/fig_root" ;; esac'
# du: the image is whole, as fallocate makes it (the stub's file is sparse),
# unless a discard has punched holes in it; anything else is the real du.
stub du 'p="${@: -1}"; case "$p" in *joinery.img) if [ -f "$GATE_T/holes" ]; then printf "%s\t%s\n" $(( $(stat -c %s "$p") / 2 )) "$p"; else printf "%s\t%s\n" "$(stat -c %s "$p")" "$p"; fi ;; *) exec /usr/bin/du "$@" ;; esac'

figs() { echo "$(( $2 )) $(( $3 )) $(( $4 ))" > "$T/fig_$1"; }
run() { PATH="$T/bin:$PATH" JOINERY_HOST_ROOT="$T/root" bash "$SCRIPT" "$@"; }
unmount() { rm -f "$T/mounted"; }
# chattr and lsattr as on a filesystem that has the immutable flag: +i is
# recorded, and lsattr reads it back. Unprivileged, the real chattr cannot set it.
imm_stubs() {
    stub chattr 'echo "chattr $*" >> "$GATE_LOG"; [ "$1" = "+i" ] && echo "$2" >> "$GATE_T/immutable"; exit 0'
    stub lsattr 'p="${@: -1}"; if grep -qxF "$p" "$GATE_T/immutable" 2>/dev/null; then echo "----i---------e------- $p"; else echo "--------------e------- $p"; fi'
}
no_imm_stubs() { rm -f "$T/bin/chattr" "$T/bin/lsattr" "$T/immutable"; }

# A 100 GiB root disk with 80 free: its reserve is 15 GiB, so it can give 65.
figs root 100*G 20*G 80*G

echo "=== check, with no data root ==="
out="$(run check 2>&1)"; rc=$?
chk "exit 2, and says there is none" "$rc|$(grep -c 'has no data root' <<< "$out")" "2|1"
chk "status says none" "$(run status)" "data root: none on this host"

echo "=== create: refusals ==="
out="$(run create 16GB 2>&1)"; rc=$?
chk "a size that is not one" "$rc|$(grep -c "not '16GB'" <<< "$out")" "1|1"
out="$(run create 66G 2>&1)"; rc=$?
chk "a size the root disk cannot give above its reserve (65 GiB)" "$rc|$(grep -c 'under its reserve' <<< "$out")" "1|1"
mkdir -p "$T/root/srv/joinery/x"
out="$(run create 16G 2>&1)"; rc=$?
chk "a mount point that is not empty" "$rc|$(grep -c 'is not empty' <<< "$out")" "1|1"
rmdir "$T/root/srv/joinery/x"
touch "$T/stranger"
out="$(run create 16G 2>&1)"; rc=$?
chk "a mount point something else is mounted at" "$rc|$(grep -c 'already mounted' <<< "$out")" "1|1"
rm -f "$T/stranger"
mkdir -p "$T/root/dev"; : > "$T/root/dev/sdb"; touch "$T/dev_has_fs"
out="$(run create /dev/sdb 2>&1)"; rc=$?
chk "a device that carries a filesystem" "$rc|$(grep -c 'wipefs -a /dev/sdb' <<< "$out")" "1|1"
rm -f "$T/dev_has_fs"
out="$(run create 16G /dev/sdb 2>&1)"; rc=$?
chk "a device and a size together" "$rc|$(grep -c 'not both' <<< "$out")" "1|1"
chk "nothing was allocated, formatted or recorded" \
    "$(wc -l < "$LOG" | tr -d ' ')|$( [ -e "$T/root/etc/joinery/data_root" ] && echo conf || echo none)" "0|none"

echo "=== create: units that cannot be written stop it with what to run (reviewer2 N1) ==="
cp "$T/bin/systemctl" "$T/systemctl.ok"
stub systemctl 'echo "systemctl $*" >> "$GATE_LOG"; [ "$1" = daemon-reload ] && exit 1; exit 0'
out="$(run create 2>&1)"; rc=$?
chk "exit 1, the reason, and the commands to finish by hand; not mounted" \
    "$rc|$(grep -c 'daemon-reload failed' <<< "$out")|$(grep -c 'then run: systemctl daemon-reload && mount' <<< "$out")|$(grep -c '^mount ' "$LOG")" "1|1|1|0"
cp "$T/systemctl.ok" "$T/bin/systemctl"
rm -rf "$T/root/etc/joinery" "$T/root/etc/fstab" "$T/root/srv/joinery.img" "$T/root/etc/systemd" "$T/root/run"
: > "$LOG"

echo "=== create: a small root disk keeps room for the rest of the install ==="
figs root 25*G 5*G 20*G
out="$(run create 2>&1)"; rc=$?
chk "25 GiB root, 20 free: reserve 6 and 2 for the install, so 12 GiB (not 14)" \
    "$rc|$(grep -c "^fallocate -l $((12 * G)) $T/root/srv/joinery.img$" "$LOG")" "0|1"
unmount
rm -rf "$T/root/etc/joinery" "$T/root/etc/fstab" "$T/root/srv/joinery.img" "$T/root/etc/systemd" "$T/root/run"
: > "$LOG"
figs root 100*G 20*G 80*G

echo "=== create: a file of the first size D7 gives ==="
: > "$T/root/lib/systemd/system/php8.5-fpm.service"
# The root view's stand-in: the folder the mount will hide at /srv/joinery.
mkdir -p "$T/hidden_create/srv/joinery"
imm_stubs
out="$(JOINERY_TEST_ROOT_VIEW="$T/hidden_create" run create 2>&1)"; rc=$?
no_imm_stubs
chk "exit 0" "$rc" "0"
chk "the mount point underneath: root's, mode 000 and immutable, before the first mount (1.24)" \
    "$(stat -c %a "$T/hidden_create/srv/joinery")|$(grep -n -e "^chattr +i $T/hidden_create/srv/joinery$" -e '^mount ' "$LOG" | cut -d: -f2 | cut -d' ' -f1 | paste -sd,)" "0|chattr,mount"
chk "first size: a quarter of the root disk, 25 GiB (above 16)" \
    "$(grep -c "^fallocate -l $((25 * G)) $T/root/srv/joinery.img$" "$LOG")" "1"
chk "formatted XFS with ftype=1 and 4096-byte sectors" "$(grep -c "^mkfs.xfs -q -n ftype=1 -s size=4096 $T/root/srv/joinery.img$" "$LOG")" "1"
chk "recorded: a file, at /srv/joinery.img" \
    "$(grep -c '^backing=file$' "$T/root/etc/joinery/data_root")|$(grep -c '^source=/srv/joinery.img$' "$T/root/etc/joinery/data_root")" "1|1"
chk "fstab: one line, loop, prjquota, nofail" \
    "$(grep -c '^/srv/joinery.img /srv/joinery xfs loop,prjquota,nofail 0 0$' "$T/root/etc/fstab")" "1"
SD="$T/root/etc/systemd/system"
chk "the target requires the mount and wants direct I/O" \
    "$(grep -c '^Requires=srv-joinery.mount$' "$SD/joinery-data.target")|$(grep -c '^Wants=joinery-data-dio.service$' "$SD/joinery-data.target")" "1|1"
chk "direct I/O on the loop device, before the target" \
    "$(grep -c 'losetup --direct-io=on' "$SD/joinery-data-dio.service")|$(grep -c '^Before=joinery-data.target$' "$SD/joinery-data-dio.service")" "1|1"
consumers=""
for u in postgresql.service postgresql@.service docker.service containerd.service apache2.service postfix.service postfix@.service rspamd.service php8.5-fpm.service; do
    f="$SD/$u.d/joinery-data-root.conf"
    if [ "$(grep -c '^Requires=joinery-data.target$' "$f" 2>/dev/null)|$(grep -c '^After=joinery-data.target$' "$f" 2>/dev/null)" != "1|1" ]; then consumers="$consumers $u"; fi
done
chk "every consumer, PHP-FPM included, requires the target and starts after it" "${consumers:-all}" "all"
chk "the units are known before the mount, and the target started after it" \
    "$(grep -n -e '^systemctl daemon-reload' -e '^mount ' -e '^systemctl start joinery-data.target' "$LOG" | cut -d: -f2 | cut -d' ' -f1-2 | paste -sd,)" "systemctl daemon-reload,mount $T/root/srv/joinery,systemctl start"
chk "check: 0" "$(run check; echo $?)" "0"
: > "$LOG"
out="$(run create 2>&1)"; rc=$?
chk "create again: nothing more is done" "$rc|$(grep -c 'already mounted' <<< "$out")|$(grep -c -e '^fallocate' -e '^mkfs' -e daemon-reload "$LOG")|$(grep -c joinery.img "$T/root/etc/fstab")" "0|1|0|1"
out="$(run create 40G 2>&1)"
chk "and a size given then is said not to be used (reviewer2 F7)" "$(grep -c 'The size 40G was not used' <<< "$out")" "1"

echo "=== check, declared and not right ==="
unmount
out="$(run check 2>&1)"; rc=$?
chk "not mounted: exit 1, and says so" "$rc|$(grep -c '/srv/joinery is not mounted' <<< "$out")" "1|1"
touch "$T/mounted"; echo noquota > "$T/opts"
out="$(run check 2>&1)"; rc=$?
chk "mounted without project quotas: exit 1" "$rc|$(grep -c 'without project quotas' <<< "$out")" "1|1"
rm -f "$T/opts"; echo ext4 > "$T/fstype"
out="$(run check 2>&1)"; rc=$?
chk "mounted as another filesystem: exit 1" "$rc|$(grep -c 'mounted as ext4' <<< "$out")" "1|1"
rm -f "$T/fstype"
out="$(run tick 2>&1)"; rc=0; unmount; run tick >/dev/null 2>&1 || rc=$?
chk "tick exits as check: 1 when not mounted" "$rc" "1"
rm -f "$T/root/run/joinery/data-root.not-mounted"; : > "$LOG"
out="$(run tick 2>&1)"; out2="$(run tick 2>&1)"
chk "not mounted, and no run has it: each tick starts it, and says once that it would not mount (B42)" \
    "$(grep -c '^systemctl start joinery-data.target$' "$LOG")|$(grep -c 'did not mount when started' <<< "$out")|$(grep -c 'did not mount' <<< "$out2")" "2|1|0"
cp "$T/bin/systemctl" "$T/systemctl.b42"
stub systemctl 'echo "systemctl $*" >> "$GATE_LOG"; [ "$*" = "start joinery-data.target" ] && touch "$GATE_T/mounted"; exit 0'
out="$(run tick 2>&1)"; rc=$?
chk "and when it mounts: exit 0, said, and the once-said note cleared" \
    "$rc|$(grep -c 'mounted it again' <<< "$out")|$( [ -e "$T/root/run/joinery/data-root.not-mounted" ] && echo flag || echo none)" "0|1|none"
chk "and what stopped with it (every consumer requires the target) is started again (B51)" \
    "$(grep -c '^systemctl start --no-block default.target$' "$LOG")" "1"
# A start cut off after cryptsetup opened the volume leaves its mapping over the
# plain image, holding the loop device the mount needs (B49).
cp "$T/bin/cryptsetup" "$T/cryptsetup.b49"
stub cryptsetup 'echo "cryptsetup $*" >> "$GATE_LOG"; [ "$1" = close ] && rm -f "$GATE_T/root/dev/mapper/joinery-data"; exit 0'
unmount; mkdir -p "$T/root/dev/mapper"; : > "$T/root/dev/mapper/joinery-data"; : > "$LOG"
run tick >/dev/null 2>&1
chk "plain, not mounted, the volume's mapping left open: closed, then the data root started (B49)" \
    "$(grep -n -e '^cryptsetup close joinery-data$' -e '^systemctl start joinery-data.target$' "$LOG" | cut -d: -f2 | cut -d' ' -f1-2 | paste -sd,)|$( [ -e "$T/root/dev/mapper/joinery-data" ] && echo open || echo closed)" \
    "cryptsetup close,systemctl start|closed"
cp "$T/cryptsetup.b49" "$T/bin/cryptsetup"
# The root copy a run's unit starts follows the tree once no run needs it (B46).
mkdir -p "$T/root/usr/local/sbin"; echo '# an older release' > "$T/root/usr/local/sbin/joinery-data-root"
run tick >/dev/null 2>&1
chk "the root copy of this tool an older run installed: brought up to this one at the tick (B46)" \
    "$(cmp -s "$T/root/usr/local/sbin/joinery-data-root" "$SCRIPT" && echo same || echo old)" "same"
echo '# an older release' > "$T/root/usr/local/sbin/joinery-data-root"; echo 'reencrypt=encrypt' >> "$T/root/etc/joinery/data_root"
run tick >/dev/null 2>&1
chk "but never while a run is recorded: the run resumes on the copy it started with" \
    "$(cmp -s "$T/root/usr/local/sbin/joinery-data-root" "$SCRIPT" && echo same || echo old)" "old"
sed -i '/^reencrypt=/d' "$T/root/etc/joinery/data_root"; rm -rf "$T/root/usr/local/sbin"; : > "$LOG"

echo "=== stop and start by hand (B17) ==="
cp "$T/bin/systemctl" "$T/systemctl.b17"
# docker.socket starts Docker, a consumer; Apache and a PostgreSQL cluster run.
stub systemctl 'echo "systemctl $*" >> "$GATE_LOG"
case "$*" in
  "list-units --type=socket"*) echo "docker.socket loaded active running Docker Socket" ;;
  "show -p Triggers --value docker.socket") echo docker.service ;;
  "is-active --quiet apache2.service") exit 0 ;;
  "is-active --quiet"*) exit 3 ;;
  "list-units --type=service"*) echo "postgresql@16-main.service loaded active running PostgreSQL" ;;
  "stop srv-joinery.mount") rm -f "$GATE_T/mounted" ;;
  "start joinery-data.target") touch "$GATE_T/mounted" ;;
esac
exit 0'
SF="$T/root/run/joinery/data-root.stopped"
touch "$T/mounted"; rm -f "$SF"; : > "$LOG"
printf 'mail/postfix /var/spool/postfix\n' > "$T/root/etc/joinery/data_binds"
out="$(run stop 2>&1)"; rc=$?
rm -f "$T/root/etc/joinery/data_binds"
chk "stop: the socket that would start a consumer goes first, then the mount" \
    "$rc|$(grep -n -e '^systemctl stop docker.socket$' -e '^systemctl stop srv-joinery.mount$' "$LOG" | cut -d: -f2 | paste -sd,)" \
    "0|systemctl stop docker.socket,systemctl stop srv-joinery.mount"
chk "and what it stopped is recorded, with when, and said" \
    "$(head -n 1 "$SF" | grep -c '^stopped by hand at ')|$(tail -n +2 "$SF" | paste -sd,)|$(grep -c 'It stays stopped until joinery_data_root.sh start, or the next boot' <<< "$out")" \
    "1|apache2.service,docker.socket,postgresql@16-main.service|1"
chk "the status file the site and the host report read says when, at once (reviewer2 B17 D2)" \
    "$(grep -c '^stopped_by_hand=[0-9]\{4\}-[0-9]\{2\}-[0-9]\{2\} [0-9:]\{8\} UTC$' "$T/root/run/joinery/data-root.status")" "1"
chk "a stop that worked clears its mounts' failed results: a folder mount found busy on the first unmount stays 'failed' otherwise (drs1, 2026-10-17)" \
    "$(grep -c '^systemctl reset-failed srv-joinery.mount var-spool-postfix.mount$' "$LOG")" "1"
: > "$LOG"
out="$(run tick 2>&1)"; rc=$?
chk "the tick leaves a stopped data root stopped: exit 1, nothing started, nothing said" \
    "$rc|$(grep -c '^systemctl start' "$LOG")|${out:-quiet}" "1|0|quiet"
chk "status says it is stopped, and how to start it" \
    "$(run status 2>&1 | grep -c '^state: STOPPED - stopped by hand at .*; joinery_data_root.sh start starts it$')" "1"
out="$(run stop 2>&1)"; rc=$?
chk "stop again: nothing to stop, and since when" "$rc|$(grep -c 'not running; nothing to stop (stopped by hand at' <<< "$out")" "0|1"
: > "$LOG"
out="$(run start 2>&1)"; rc=$?
chk "start: the data root, then what the stop stopped, then the record gone" \
    "$rc|$(grep -e '^systemctl start joinery-data.target$' -e '^systemctl start apache2.service docker.socket postgresql@16-main.service$' "$LOG" | paste -sd,)|$( [ -e "$SF" ] && echo kept || echo gone)|$(grep -c '^data root: started, with apache2.service docker.socket postgresql@16-main.service$' <<< "$out")" \
    "0|systemctl start joinery-data.target,systemctl start apache2.service docker.socket postgresql@16-main.service|gone|1"
chk "and the status file says it is not stopped" "$(grep -c '^stopped_by_hand=$' "$T/root/run/joinery/data-root.status")" "1"
# Something keeps the filesystem mounted elsewhere, and no service owns it.
mkdir -p "$T/root/proc/self"
printf '36 25 0:42 / /srv/joinery rw - xfs /dev/loop7 rw\n37 25 0:42 /x /var/spool/postfix/dev/log rw - xfs /dev/loop7 rw\n' > "$T/root/proc/self/mountinfo"
stub fuser 'exit 1'
: > "$LOG"
out="$(run stop 2>&1)"; rc=$?
chk "a filesystem something will not let go of: exit 1, said, started again, no record left" \
    "$rc|$(grep -c 'still mounted at /srv/joinery /var/spool/postfix/dev/log' <<< "$out")|$(grep -c -e '^systemctl start joinery-data.target$' -e '^systemctl start --no-block apache2.service docker.socket postgresql@16-main.service$' "$LOG")|$( [ -e "$SF" ] && echo kept || echo gone)" \
    "1|1|2|gone"
rm -rf "$T/root/proc" "$T/bin/fuser"
echo 'reencrypt=encrypt' >> "$T/root/etc/joinery/data_root"; touch "$T/mounted"; : > "$LOG"
out="$(run stop 2>&1)"; rc=$?
chk "stop while a run is recorded: refused, nothing stopped" "$rc|$(grep -c 'is running' <<< "$out")|$(grep -c '^systemctl stop' "$LOG")" "1|1|0"
sed -i '/^reencrypt=/d' "$T/root/etc/joinery/data_root"
# Started some other way (systemctl, or a repair restarting a service that
# requires it): the next tick drops the stop's record and starts what the
# stop stopped, as start does (drs1, 2026-10-17: they stayed down).
printf 'stopped by hand at then\napache2.service\npostfix.service\n' > "$SF"; touch "$T/mounted"; : > "$LOG"
figs data 20*G 4*G 16*G
run tick >/dev/null 2>&1
chk "a running data root's stale stop record is dropped by the tick" "$( [ -e "$SF" ] && echo kept || echo gone)" "gone"
chk "and what the stop stopped is started again" "$(grep -c '^systemctl start --no-block apache2.service postfix.service$' "$LOG")" "1"
cp "$T/systemctl.b17" "$T/bin/systemctl"; : > "$LOG"
unmount; : > "$LOG"
( exec 8>>"$T/root/run/joinery/data-root.lock"; flock 8; run tick >/dev/null 2>&1 )
chk "while a run holds the data root's lock (an encrypt's start): left stopped" "$(grep -c '^systemctl start joinery-data.target$' "$LOG")" "0"
cp "$T/systemctl.b42" "$T/bin/systemctl"
touch "$T/mounted"
mkdir -p "$T/root/run/joinery/data-root-key.Ab12Cd" "$T/root/etc/joinery/data-root"; : > "$T/root/srv/joinery.img.recovery"; : > "$T/root/etc/joinery/data-root/driving-slot.recovery"
out="$(run tick 2>&1)"; out2="$(run tick 2>&1)"
chk "plain and mounted: the keys and sealed copies a cut-off start left go, said once (B42)" \
    "$(ls -d "$T"/root/run/joinery/data-root-key.* "$T/root/srv/joinery.img.recovery" "$T/root/etc/joinery/data-root/driving-slot.recovery" 2>/dev/null | wc -l)|$(grep -c 'start left behind' <<< "$out")|$(grep -c 'start left behind' <<< "$out2")" "0|1|0"

echo "=== tick: the units ==="
: > "$LOG"
out="$(run tick 2>&1)"; rc=$?
figs data 25*G 2*G 23*G
out="$(run tick 2>&1)"; rc=$?
chk "with the units right and room to spare: exit 0, silent, no reload, no growth" \
    "$rc|${#out}|$(grep -c -e daemon-reload -e '^fallocate' "$LOG")" "0|0|0"
rm -f "$SD/docker.service.d/joinery-data-root.conf"
: > "$T/root/lib/systemd/system/php8.6-fpm.service"
out="$(run tick 2>&1)"
chk "a drop-in removed, and a PHP-FPM installed later: both written, one reload" \
    "$( [ -f "$SD/docker.service.d/joinery-data-root.conf" ] && echo y)|$( [ -f "$SD/php8.6-fpm.service.d/joinery-data-root.conf" ] && echo y)|$(grep -c daemon-reload "$LOG")" "y|y|1"

echo "=== tick: the data root's own mount point, underneath it (1.24) ==="
# A data root made before 1.24: the folder its mount hides is a plain one.
imm_stubs
HV="$T/hidden_tick"; mkdir -p "$HV/srv/joinery"
unlock_hv() { chmod 755 "$HV/srv/joinery"; rm -f "$T/immutable"; }
seen="$T/root/run/joinery/data-root.mountpoint-seen"; rm -f "$seen"
out="$(JOINERY_TEST_ROOT_VIEW="$HV" run tick 2>&1)"; rc=$?
chk "an empty one: locked at the boot's first tick, quietly, and recorded so; the data root itself is not touched" \
    "$rc|${#out}|$(stat -c %a "$HV/srv/joinery")|$(grep -cxF "$HV/srv/joinery" "$T/immutable")|$(stat -c %a "$T/root/srv/joinery")|$(cat "$seen")|$(stat -c %a "$seen")" "0|0|0|1|755|locked|644"
unlock_hv; : > "$HV/srv/joinery/storage.db"
out="$(JOINERY_TEST_ROOT_VIEW="$HV" run tick 2>&1)"
chk "looked at once a boot: the next tick does not look again" "$(stat -c %a "$HV/srv/joinery")|${#out}" "755|0"
rm -f "$seen"
out="$(JOINERY_TEST_ROOT_VIEW="$HV" run tick 2>&1)"; rc=$?
out2="$(JOINERY_TEST_ROOT_VIEW="$HV" run tick 2>&1)"
chk "one holding files: named once, left as it is, and the tick goes on" \
    "$rc|$(grep -c "/srv/joinery underneath the data root holds files" <<< "$out")|$(grep -c 'holds files' <<< "$out2")|$(stat -c %a "$HV/srv/joinery")|$(ls "$HV/srv/joinery")" \
    "0|1|0|755|storage.db"
chk "and recorded so" "$(cat "$seen")" "holds-files"
rm -f "$HV/srv/joinery/storage.db"; : > "$seen"
JOINERY_TEST_ROOT_VIEW="$HV" run tick >/dev/null 2>&1
chk "a flag that records nothing: looked at again" "$(stat -c %a "$HV/srv/joinery")|$(cat "$seen")" "0|locked"
rm -f "$seen"; : > "$LOG"
JOINERY_TEST_ROOT_VIEW="$HV" run tick >/dev/null 2>&1
chk "already immutable at the next boot: recorded locked, and not locked again" "$(cat "$seen")|$(grep -c '^chattr' "$LOG")" "locked|0"
unlock_hv
# A root disk whose filesystem will not take the immutable flag: mode 000
# alone, which root (and so podman) passes straight through.
stub chattr 'echo "chattr $*" >> "$GATE_LOG"; exit 0'
rm -f "$seen"
out="$(JOINERY_TEST_ROOT_VIEW="$HV" run tick 2>&1)"; rc=$?
out2="$(JOINERY_TEST_ROOT_VIEW="$HV" run tick 2>&1)"
chk "a filesystem without the immutable flag: mode 000, recorded mode-only, not locked, said once (reviewer2)" \
    "$rc|$(stat -c %a "$HV/srv/joinery")|$(cat "$seen")|$(grep -c 'would not take the immutable flag' <<< "$out")|$(grep -c 'immutable' <<< "$out2")" "0|0|mode-only|1|0"
unlock_hv; no_imm_stubs
rm -f "$seen"
out="$(run tick 2>&1)"
chk "a test root that gives no view: the data root's own folder is never locked, and nothing is said to be" \
    "$(stat -c %a "$T/root/srv/joinery")|${#out}|$(cat "$seen")" "755|0|unreachable"

echo "=== tick: growth (D7) ==="
: > "$LOG"
# 25 GiB with 9 GiB free: above the 8 GiB line, so it waits.
figs data 25*G 16*G 9*G
run tick >/dev/null 2>&1
chk "9 GiB of 25 free: no growth" "$(grep -c '^fallocate' "$LOG")" "0"
# 5 GiB free: under it. The largest of 25*1.25, 20+12, 20/0.7, rounded up: 32 GiB.
figs data 25*G 20*G 5*G
out="$(run tick 2>&1)"
chk "5 GiB of 25 free: grows to 32 GiB" "$(grep -c "^fallocate -l $((32 * G)) $T/root/srv/joinery.img$" "$LOG")" "1"
chk "the loop device rereads its file, then the filesystem grows" \
    "$(grep -n -e '^losetup -c /dev/loop7' -e "^xfs_growfs $T/root/srv/joinery$" "$LOG" | cut -d: -f2 | cut -c1-8 | paste -sd,)" "losetup ,xfs_grow"
chk "and the transcript says so" "$out" "data root: grew from 25.0 GiB to 32.0 GiB"
: > "$LOG"
# The root disk now has 18 GiB free: 3 above its 15 GiB reserve.
figs root 100*G 82*G 18*G
figs data 32*G 28*G 4*G
out="$(run tick 2>&1)"
chk "the root disk can give 3 GiB: grows by 3, to 35 GiB, not to D7's 40" \
    "$(grep -c "^fallocate -l $((35 * G)) " "$LOG")|$out" "1|data root: grew from 32.0 GiB to 35.0 GiB"
: > "$LOG"
figs root 100*G 84*G 15*G+512*1024*1024
figs data 35*G 33*G 2*G
out="$(run tick 2>&1)"
chk "under 1 GiB to give: no growth, and it says why" \
    "$(grep -c '^fallocate' "$LOG")|$(grep -c 'cannot grow: 2.0 GiB free of 35.0 GiB, and the root disk has 0.5 GiB to give above its reserve' <<< "$out")" "0|1"
out="$(run tick 2>&1)"
chk "said once: the next tick is silent" "${#out}" "0"
chk "status says it too" "$(run status 2>&1 | grep -c 'the root disk can give 0.5 GiB more')" "1"
figs root 100*G 60*G 40*G
run tick >/dev/null 2>&1
chk "once it can grow again, it does, and the note is cleared" \
    "$(grep -c '^fallocate' "$LOG")|$( [ -e "$T/root/run/joinery/data-root.cannot-grow" ] && echo kept || echo cleared)" "1|cleared"

echo "=== tick: upkeep that fails on a mounted data root is said, and is not 'not ready' (reviewer2 F1) ==="
figs root 100*G 20*G 80*G
figs data 40*G 36*G 4*G
cp "$T/bin/fallocate" "$T/fallocate.ok"
stub fallocate 'echo "fallocate $*" >> "$GATE_LOG"; exit 1'
out="$(run tick 2>&1)"; rc=$?
chk "a growth that fails: exit 0, and the transcript says so" \
    "$rc|$(grep -c 'upkeep did not finish' <<< "$out")|$(grep -c 'could not extend' <<< "$out")" "0|1|1"
cp "$T/fallocate.ok" "$T/bin/fallocate"
figs data 40*G 2*G 38*G
cp "$T/bin/systemctl" "$T/systemctl.ok"
stub systemctl 'echo "systemctl $*" >> "$GATE_LOG"; [ "$1" = daemon-reload ] && exit 1; exit 0'
rm -f "$SD/apache2.service.d/joinery-data-root.conf"
out="$(run tick 2>&1)"; rc=$?
chk "a reload systemd refuses: exit 0, said, and check still 0" \
    "$rc|$(grep -c 'daemon-reload failed' <<< "$out")|$(grep -c 'upkeep did not finish' <<< "$out")|$(run check >/dev/null 2>&1; echo $?)" "0|1|1|0"
cp "$T/systemctl.ok" "$T/bin/systemctl"
run tick >/dev/null 2>&1

echo "=== the image stays whole: no discard reaches it, and space one took back is allocated again ==="
: > "$LOG"
mkdir -p "$T/root/sys/block/loop7/queue"
echo 4294966784 > "$T/root/sys/block/loop7/queue/discard_max_bytes"
touch "$T/holes"
out="$(run tick 2>&1)"; rc=$?
chk "the loop device stops passing discards to the file, and says so" \
    "$rc|$(cat "$T/root/sys/block/loop7/queue/discard_max_bytes")|$(grep -c 'passes no discard' <<< "$out")" "0|0|1"
cur="$(stat -c %s "$T/root/srv/joinery.img")"
chk "holes a discard punched are allocated again, at the image's own size, and said" \
    "$(grep -c "^fallocate -l ${cur} $T/root/srv/joinery.img$" "$LOG")|$(grep -c 'allocated again' <<< "$out")" "1|1"
rm -f "$T/holes"; : > "$LOG"
out="$(run tick 2>&1)"
chk "whole and passing no discard: the next tick does neither" "$(grep -c '^fallocate' "$LOG")|${#out}" "0|0"
chk "the direct I/O unit turns discards off at every boot" \
    "$(grep -c 'queue/discard_max_bytes' "$SD/joinery-data-dio.service")" "1"
rm -r "${T:?}/root/sys"

echo "=== grow SIZE ==="
: > "$LOG"
cur="$(stat -c %s "$T/root/srv/joinery.img")"
out="$(run grow 20G 2>&1)"; rc=$?
chk "never shrinks" "$rc|$(grep -c 'never shrinks' <<< "$out")" "1|1"
figs root 100*G 70*G 30*G
out="$(run grow $(( cur / G + 16 ))G 2>&1)"; rc=$?
chk "never takes the root disk under its reserve (15 to give)" "$rc|$(grep -c 'under its reserve' <<< "$out")" "1|1"
out="$(run grow $(( cur / G + 15 ))G 2>&1)"; rc=$?
chk "grows to a size it can give" "$rc|$(grep -c "^fallocate -l $(( (cur / G + 15) * G )) " "$LOG")" "0|1"

echo "=== bind and unbind (WP2, D1) ==="
figs root 100*G 20*G 80*G
figs data 40*G 2*G 38*G
# A mount unit's start puts its place where its path is (a symlink stands in
# for the bind: stat follows it, as it would see the mounted directory); its
# stop takes it away. GATE_BIND_FAIL makes every start fail.
stub systemctl 'echo "systemctl $*" >> "$GATE_LOG"
case "$1 $2" in
  "start "*.mount|"stop "*.mount)
    # systemd knows a started mount from mountinfo even once its file is gone.
    mkdir -p "$GATE_T/active"
    if [ "$1" = start ]; then
      f="$GATE_T/root/etc/systemd/system/$2"; [ -f "$f" ] || exit 5
      what="$(sed -n "s/^What=//p" "$f")"; where="$(sed -n "s/^Where=//p" "$f")"
      [ -f "$GATE_T/bind_fail" ] && exit 1
      rmdir "$GATE_T/root$where" 2>/dev/null; ln -sfn "$GATE_T/root$what" "$GATE_T/root$where"
      echo "$where" > "$GATE_T/active/$2"
    else
      [ -f "$GATE_T/active/$2" ] || exit 5
      where="$(cat "$GATE_T/active/$2")"; rm -f "$GATE_T/active/$2"
      rm -f "$GATE_T/root$where"; mkdir -p "$GATE_T/root$where"; chmod 000 "$GATE_T/root$where"
    fi ;;
esac
exit 0'
run tick >/dev/null 2>&1
chk "a plain data root: none of D10 (no core dump, /tmp, journal or rsyslog settings)" \
    "$(ls "$T/root/etc/sysctl.d/60-joinery-data-root.conf" "$SD/tmp.mount" "$T/root/etc/systemd/journald.conf.d/joinery-data-root.conf" "$SD/rsyslog.service.d/joinery-data-root.conf" 2>/dev/null | wc -l)" "0"
: > "$LOG"
B="$T/root/etc/joinery/data_binds"
out="$(run bind ../etc /var/lib/x 2>&1)"; rc=$?
chk "a place with .. in it is refused" "$rc|$(grep -c "'../etc' is not a path" <<< "$out")" "1|1"
out="$(run bind postgresql var/lib/postgresql 2>&1)"; rc=$?
chk "a relative path is refused" "$rc|$(grep -c 'is not an absolute path' <<< "$out")" "1|1"
out="$(run bind postgresql /srv/joinery/x 2>&1)"; rc=$?
chk "a path inside the data root is refused" "$rc|$(grep -c 'is not an absolute path outside the data root' <<< "$out")" "1|1"
out="$(run bind postgresql /var/lib/../etc 2>&1)"; rc=$?
chk "a path with .. in it is refused" "$rc" "1"
unmount
out="$(run bind postgresql /var/lib/postgresql 2>&1)"; rc=$?
chk "refused while the data root is not mounted" "$rc|$(grep -c 'the data root is not mounted' <<< "$out")" "1|1"
touch "$T/mounted"
chk "and nothing was recorded or written" "$( [ -e "$B" ] && echo rec || echo none)|$(grep -c -e '^systemctl start' -e daemon-reload "$LOG")" "none|0"

mkdir -p "$T/root/var/lib/postgresql"; chmod 750 "$T/root/var/lib/postgresql"
out="$(run bind postgresql /var/lib/postgresql 2>&1)"; rc=$?
chk "bind an empty path: exit 0, and says where it is" "$rc|$(tail -n 1 <<< "$out")" "0|/var/lib/postgresql is on the data root (/srv/joinery/postgresql)"
chk "recorded once" "$(grep -c '^postgresql /var/lib/postgresql$' "$B")" "1"
chk "the new place took the path's mode" "$(stat -c %a "$T/root/srv/joinery/postgresql")" "750"
U="$SD/var-lib-postgresql.mount"
chk "its unit: the place onto the path, a bind, after the data root's mount" \
    "$(grep -c '^What=/srv/joinery/postgresql$' "$U")|$(grep -c '^Where=/var/lib/postgresql$' "$U")|$(grep -c '^Options=bind$' "$U")|$(grep -c '^Requires=srv-joinery.mount$' "$U")" "1|1|1|1"
chk "the target requires it and orders after it" \
    "$(grep -c '^Requires=var-lib-postgresql.mount$' "$SD/joinery-data.target")|$(grep -c '^After=var-lib-postgresql.mount$' "$SD/joinery-data.target")" "1|1"
chk "units reloaded before it is started" \
    "$(grep -e '^systemctl daemon-reload' -e '^systemctl start var-lib' "$LOG" | cut -d' ' -f2 | paste -sd,)" "daemon-reload,start"
chk "check: 0, and status lists it" "$(run check >/dev/null 2>&1; echo $?)|$(run status 2>&1 | grep -c '^mount: /var/lib/postgresql <- postgresql$')" "0|1"
: > "$LOG"
out="$(run bind postgresql /var/lib/postgresql 2>&1)"; rc=$?
chk "bind again: exit 0, still one line, nothing restarted" "$rc|$(grep -c postgresql "$B")|$(grep -c -e '^systemctl start' -e daemon-reload "$LOG")" "0|1|0"

out="$(run bind postgresql /var/lib/other 2>&1)"; rc=$?
chk "one place mounts at one path" "$rc|$(grep -c 'is already mounted at /var/lib/postgresql' <<< "$out")" "1|1"
out="$(run bind pg2 /var/lib/postgresql 2>&1)"; rc=$?
chk "one path mounts one place" "$rc|$(grep -c 'is already mounted from /srv/joinery/postgresql' <<< "$out")" "1|1"

mkdir -p "$T/root/var/www/html/s1/uploads"; echo photo > "$T/root/var/www/html/s1/uploads/a.jpg"
: > "$LOG"
out="$(run bind sites/s1/uploads /var/www/html/s1/uploads 2>&1)"; rc=$?
chk "a path holding data: exit 3, it says so, nothing changed" \
    "$rc|$(grep -c 'holds data' <<< "$out")|$(grep -c s1 "$B")|$(cat "$T/root/var/www/html/s1/uploads/a.jpg")|$( [ -e "$T/root/srv/joinery/sites/s1" ] && echo made || echo none)|$(wc -l < "$LOG" | tr -d ' ')" "3|1|0|photo|none|0"
echo "$T/root/var/www/html/s2/uploads" > "$T/othermounts"
mkdir -p "$T/root/var/www/html/s2/uploads"
out="$(run bind sites/s2/uploads /var/www/html/s2/uploads 2>&1)"; rc=$?
chk "a path something else is mounted at is refused" "$rc|$(grep -c 'something else is mounted' <<< "$out")" "1|1"
rm -f "$T/othermounts"

touch "$T/bind_fail"
out="$(run bind sites/s3/logs /var/www/html/s3/logs 2>&1)"; rc=$?
chk "a mount that does not take: exit 1, and the unit to look at" "$rc|$(grep -c 'see: systemctl status var-www-html-s3-logs.mount' <<< "$out")" "1|1"
chk "the path underneath is locked: mode 000" "$(stat -c %a "$T/root/var/www/html/s3/logs")" "0"
out="$(run check 2>&1)"; rc=$?
chk "check: 1, naming the path that is not mounted" "$rc|$(grep -c '/var/www/html/s3/logs is not mounted from /srv/joinery/sites/s3/logs' <<< "$out")" "1|1"
out="$(run tick 2>&1)"; rc=$?
chk "tick, while it still will not mount: 1, not ready" "$rc" "1"
rm -f "$T/bind_fail"
out="$(run tick 2>&1)"; rc=$?
chk "tick, once it will: mounts it, says so, exit 0" "$rc|$out" "0|data root: mounted /var/www/html/s3/logs again"

echo keep > "$T/root/srv/joinery/sites/s3/logs/error.log"
: > "$LOG"
echo "# Written by someone else" > "$SD/mnt-other.mount"
out="$(run unbind /var/www/html/s3/logs 2>&1)"; rc=$?
chk "unbind: exit 0, unmounted, no longer recorded, and says where the data is" \
    "$rc|$(grep -c '^systemctl stop var-www-html-s3-logs.mount$' "$LOG")|$(grep -c s3 "$B")|$(grep -c 'its data stays at /srv/joinery/sites/s3/logs' <<< "$out")" "0|1|0|1"
chk "its unit is gone, and the target no longer requires it" \
    "$( [ -e "$SD/var-www-html-s3-logs.mount" ] && echo kept || echo gone)|$(grep -c s3 "$SD/joinery-data.target")" "gone|0"
chk "the target let it go before it was unmounted, so no consumer stopped with it (reviewer2 F1)" \
    "$(grep -e '^systemctl daemon-reload' -e '^systemctl stop var-www-html-s3-logs.mount' "$LOG" | cut -d' ' -f2 | paste -sd,)" "daemon-reload,stop"
chk "the path is left an empty folder with its place's mode, not locked (reviewer2 F4)" \
    "$(stat -c %a "$T/root/var/www/html/s3/logs")|$(stat -c %a "$T/root/srv/joinery/sites/s3/logs")" "$(stat -c %a "$T/root/srv/joinery/sites/s3/logs")|$(stat -c %a "$T/root/srv/joinery/sites/s3/logs")"
chk "the data stays; a mount unit this script did not write stays" \
    "$(cat "$T/root/srv/joinery/sites/s3/logs/error.log")|$( [ -e "$SD/mnt-other.mount" ] && echo kept)" "keep|kept"
out="$(run unbind /var/www/html/s3/logs 2>&1)"; rc=$?
chk "unbind what is not mounted from it: refused" "$rc|$(grep -c 'is not mounted from the data root' <<< "$out")" "1|1"
chk "check: 0 again" "$(run check >/dev/null 2>&1; echo $?)" "0"

echo "=== remove-site: a removed site's mounts and data go (reviewer2 F2) ==="
for d in uploads logs; do run bind "sites/s4/$d" "/var/www/html/s4/$d" >/dev/null 2>&1; done
run bind sites/s4x/uploads /var/www/html/s4x/uploads >/dev/null 2>&1
echo photo > "$T/root/srv/joinery/sites/s4/uploads/a.jpg"
unmount
out="$(run remove-site s4 2>&1)"; rc=$?
chk "refused while the data root is down, nothing changed" "$rc|$(grep -c 'which is not mounted' <<< "$out")|$(grep -c '^sites/s4/' "$B")" "1|1|2"
touch "$T/mounted"; : > "$LOG"
out="$(run remove-site s4 2>&1)"; rc=$?
chk "exit 0, both mounts taken away and its data removed" \
    "$rc|$(grep -c '^sites/s4/' "$B")|$( [ -e "$T/root/srv/joinery/sites/s4" ] && echo kept || echo gone)|$(grep -c '2 mount(s) taken away and its data on the data root removed' <<< "$out")" "0|0|gone|1"
chk "a site whose name starts with it keeps its own" "$(grep -c '^sites/s4x/uploads ' "$B")|$( [ -d "$T/root/srv/joinery/sites/s4x/uploads" ] && echo kept)" "1|kept"
chk "check: 0, so nothing on the host waits on the removed site" "$(run check >/dev/null 2>&1; echo $?)" "0"
chk "a site with nothing on the data root: exit 0, silent" "$(run remove-site nosuch 2>&1; echo $?)" "0"
chk "a name that is not a site name is refused" "$(run remove-site ../etc >/dev/null 2>&1; echo $?)" "1"

echo "=== migrate (WP3, D5): an install's data moves onto the data root ==="
rm -rf "$T/root/etc/joinery" "$T/root/etc/fstab" "$T/root/srv/joinery.img" "$T/root/srv/joinery" "$T/root/srv/joinery.old" "$SD" "$T/root/run" "$T/root/var" "$T/active"
unmount; : > "$LOG"
figs root 100*G 20*G 80*G
figs data 25*G 1*G 24*G
# list-units: the services running now; a mount start can be made to fail for
# one unit named in bind_fail. With hide_on_mount, a mount over a folder that
# holds files hides them under hidden/, as a real mount does.
stub systemctl 'echo "systemctl $*" >> "$GATE_LOG"
case "$1" in
  list-units) cat "$GATE_T/active_units" 2>/dev/null; exit 0 ;;
esac
case "$1 $2" in
  "start "*.mount|"stop "*.mount)
    mkdir -p "$GATE_T/active"
    if [ "$1" = start ]; then
      f="$GATE_T/root/etc/systemd/system/$2"; [ -f "$f" ] || exit 5
      what="$(sed -n "s/^What=//p" "$f")"; where="$(sed -n "s/^Where=//p" "$f")"
      grep -qxF "$2" "$GATE_T/bind_fail" 2>/dev/null && exit 1
      if [ -f "$GATE_T/hide_on_mount" ] && [ -d "$GATE_T/root$where" ] && [ ! -L "$GATE_T/root$where" ]; then
        mkdir -p "$(dirname "$GATE_T/hidden$where")"; mv "$GATE_T/root$where" "$GATE_T/hidden$where"
      fi
      rmdir "$GATE_T/root$where" 2>/dev/null; ln -sfn "$GATE_T/root$what" "$GATE_T/root$where"
      echo "$where" > "$GATE_T/active/$2"
    else
      [ -f "$GATE_T/active/$2" ] || exit 5
      where="$(cat "$GATE_T/active/$2")"; rm -f "$GATE_T/active/$2"
      rm -f "$GATE_T/root$where"; mkdir -p "$GATE_T/root$where"; chmod 000 "$GATE_T/root$where"
    fi ;;
esac
exit 0'
printf '%s\n' 'postgresql@18-main.service loaded active running PostgreSQL Cluster 18-main' \
    'apache2.service loaded active running The Apache HTTP Server' \
    'cron.service loaded active running Regular background program processing daemon' \
    'ssh.service loaded active running OpenBSD Secure Shell server' > "$T/active_units"
R="$T/root"
mkdir -p "$R/var/lib/postgresql/18/main" "$R/etc/postgresql/18/main" "$R/var/spool/postfix/maildrop" "$R/proc"
echo 18 > "$R/var/lib/postgresql/18/main/PG_VERSION"
echo "data_directory = '/var/lib/postgresql/18/main'" > "$R/etc/postgresql/18/main/postgresql.conf"
echo queued > "$R/var/spool/postfix/maildrop/m1"
mkdir -p "$R/var/www/html/s1/config" "$R/var/www/html/s1/uploads/large" "$R/var/www/html/s1/logs" "$R/var/www/html/s1/cache" \
    "$R/var/www/html/s1/storage" "$R/var/www/html/s1/backups" "$R/var/www/html/s1_test/logs" "$R/var/www/html/other/logs"
: > "$R/var/www/html/s1/config/Globalvars_site.php"
echo photo > "$R/var/www/html/s1/uploads/large/a.jpg"
ln -s a.jpg "$R/var/www/html/s1/uploads/large/b.jpg"
chmod 770 "$R/var/www/html/s1/uploads"
echo err > "$R/var/www/html/s1/logs/error.log"
echo t > "$R/var/www/html/s1_test/logs/t.log"
echo o > "$R/var/www/html/other/logs/o.log"
echo 11111111-aaaa-4bbb-8ccc-000000000001 > "$R/proc/boot_id"

echo "--- refusals: said all at once, nothing changed ---"
mv "$R/var/www/html/s1/cache" "$R/var/www/html/s1/cache.real"; ln -s cache.real "$R/var/www/html/s1/cache"
echo "data_directory = '/data/pg'" > "$R/etc/postgresql/18/main/postgresql.conf"
out="$(run migrate 2>&1)"; rc=$?
chk "a symlinked folder and a cluster outside /var/lib/postgresql: exit 1, both named" \
    "$rc|$(grep -c '/var/www/html/s1/cache is a symlink' <<< "$out")|$(grep -c 'cluster at /data/pg' <<< "$out")" "1|1|1"
chk "nothing made, nothing stopped" "$( [ -e "$R/etc/joinery/data_root" ] && echo made || echo none)|$(grep -c '^systemctl stop' "$LOG")" "none|0"
rm "$R/var/www/html/s1/cache"; mv "$R/var/www/html/s1/cache.real" "$R/var/www/html/s1/cache"
echo "data_directory = '/var/lib/postgresql/18/main'" > "$R/etc/postgresql/18/main/postgresql.conf"
figs root 100*G 90*G 10*G
out="$(run migrate 2>&1)"; rc=$?
chk "a root disk that cannot give a data root above its reserve: refused, nothing made" \
    "$rc|$(grep -c 'cannot hold this host' <<< "$out")|$( [ -e "$R/srv/joinery.img" ] && echo made || echo none)" "1|1|none"
figs root 100*G 20*G 80*G
: > "$R/var/www/html/s1/uploads/.upgrade.lock"
flock "$R/var/www/html/s1/uploads/.upgrade.lock" sleep 30 & holder=$!
sleep 0.3
out="$(run migrate 2>&1)"; rc=$?
pkill -P "$holder" 2>/dev/null; kill "$holder" 2>/dev/null; wait "$holder" 2>/dev/null
chk "a site that is upgrading: refused, nothing made" \
    "$rc|$(grep -c 's1 is upgrading' <<< "$out")|$( [ -e "$R/srv/joinery.img" ] && echo made || echo none)" "1|1|none"
echo "$R/var/www/html/s1/uploads/nfs" > "$T/othermounts"
out="$(run migrate 2>&1)"; rc=$?
rm -f "$T/othermounts"
chk "a mount inside a folder that moves: refused before anything stops" \
    "$rc|$(grep -c '/var/www/html/s1/uploads/nfs is mounted inside /var/www/html/s1/uploads' <<< "$out")|$(grep -c '^systemctl stop' "$LOG")|$( [ -e "$R/srv/joinery.img" ] && echo made || echo none)" "1|1|0|none"
mv "$R/proc/boot_id" "$R/proc/boot_id.x"
out="$(run migrate 2>&1)"; rc=$?
mv "$R/proc/boot_id.x" "$R/proc/boot_id"
chk "no boot id to tell a later boot by: refused, nothing made (reviewer2 F2)" \
    "$rc|$(grep -c "boot's id .* cannot be read" <<< "$out")|$( [ -e "$R/srv/joinery.img" ] && echo made || echo none)" "1|1|none"
out="$(run migrate 2M 2>&1)"; rc=$?
chk "a size that cannot hold the data with room: refused" "$rc|$(grep -c 'cannot hold this host' <<< "$out")" "1|1"
mkdir -p "$R/usr/local/lib/joinery-site-engine/6.1.4-aaaaaaaaaaaa" "$R/etc/containers"
ln -s 6.1.4-aaaaaaaaaaaa "$R/usr/local/lib/joinery-site-engine/current"
printf '[storage]\ndriver = "overlay"\ngraphroot = "/var/lib/containers/storage"\n' > "$R/etc/containers/storage.conf"
out="$(run migrate 2>&1)"; rc=$?
chk "a site container engine whose storage is off the data root: refused, nothing made" \
    "$rc|$(grep -c 'site container engine keeps its storage at /var/lib/containers/storage, off the data root' <<< "$out")|$( [ -e "$R/srv/joinery.img" ] && echo made || echo none)|$(grep -c '^systemctl stop' "$LOG")" "1|1|none|0"
rm -rf "${R:?}/usr/local/lib/joinery-site-engine" "${R:?}/etc/containers"

echo "--- the move ---"
: > "$LOG"
out="$(run migrate 2>&1)"; rc=$?
chk "exit 0, and says what moved" "$rc|$(grep -c 'onto the data root: 9 folder(s)' <<< "$out")" "0|1"
chk "the data root was made first: D7's first size" "$(grep -c "^fallocate -l $((25 * G)) " "$LOG")" "1"
chk "the converger's timer and path stop first, then a run waiting on the lock, then the running services in one go; ssh is not touched (reviewer2 F1)" \
    "$(grep '^systemctl stop ' "$LOG" | grep -v '\.mount' | paste -sd'|')" \
    "systemctl stop joinery-host-converger.timer joinery-host-converger.path|systemctl stop joinery-host-converger.service|systemctl stop postgresql@18-main.service apache2.service cron.service"
chk "held down for the move (masked, with logrotate and unattended upgrades), unmasked before they start (reviewer2 F4)" \
    "$(grep -e '^systemctl mask' -e '^systemctl unmask' -e '^systemctl start postgresql' "$LOG" | paste -sd'|')" \
    "systemctl mask --runtime postgresql@18-main.service apache2.service cron.service logrotate.service apt-daily-upgrade.service joinery-host-converger.service|systemctl unmask --runtime postgresql@18-main.service apache2.service cron.service logrotate.service apt-daily-upgrade.service joinery-host-converger.service|systemctl start postgresql@18-main.service"
chk "and started again after the mounts, the target first, the converger's timer and path last" \
    "$(grep -e '^systemctl start' "$LOG" | grep -v '\.mount' | tail -n 5 | cut -d' ' -f3- | paste -sd,)" \
    "joinery-data.target,postgresql@18-main.service,apache2.service,cron.service,joinery-host-converger.timer joinery-host-converger.path"
chk "every place recorded: the packages, the site's six folders, the test site's logs" \
    "$(grep -c -e '^postgresql /var/lib/postgresql$' -e '^mail/postfix /var/spool/postfix$' -e '^sites/s1/' -e '^sites/s1_test/logs ' "$B")" "9"
chk "the data is where it was, read through its mount" \
    "$(cat "$R/var/lib/postgresql/18/main/PG_VERSION")|$(cat "$R/var/spool/postfix/maildrop/m1")|$(cat "$R/var/www/html/s1/uploads/large/a.jpg")|$(cat "$R/var/www/html/s1_test/logs/t.log")" "18|queued|photo|t"
chk "and on the data root, a symlink kept a symlink, the folder's mode kept" \
    "$(cat "$R/srv/joinery/sites/s1/uploads/large/a.jpg")|$(readlink "$R/srv/joinery/sites/s1/uploads/large/b.jpg")|$(stat -c %a "$R/srv/joinery/sites/s1/uploads")" "photo|a.jpg|770"
chk "a folder the site lacked (static_files) is made and mounted too" "$(readlink "$R/var/www/html/s1/static_files")" "$R/srv/joinery/sites/s1/static_files"
chk "made like the folders the site had: uploads' mode, not root's 755 (reviewer2 F6)" "$(stat -c %a "$R/srv/joinery/sites/s1/static_files")" "770"
chk "a directory that is not a site is left alone" "$( [ -L "$R/var/www/html/other/logs" ] && echo moved || cat "$R/var/www/html/other/logs/o.log")|$(grep -c other "$B")" "o|0"
chk "the copies from before kept at /srv/joinery.old, root's only" \
    "$(cat "$R/srv/joinery.old/sites/s1/uploads/large/a.jpg")|$(cat "$R/srv/joinery.old/postgresql/18/main/PG_VERSION")|$(stat -c %a "$R/srv/joinery.old")" "photo|18|700"
chk "the boot that moved them recorded; check 0; status says they are kept" \
    "$(grep -c '^boot=11111111-aaaa-4bbb-8ccc-000000000001$' "$R/etc/joinery/data_root_migrated")|$(run check >/dev/null 2>&1; echo $?)|$(run status 2>&1 | grep -c 'kept at /srv/joinery.old')" "1|0|1"
: > "$LOG"
out="$(run migrate 2>&1)"; rc=$?
chk "run again: nothing to move, nothing stopped" "$rc|$out|$(grep -c '^systemctl stop' "$LOG")" "0|This host keeps no data off the data root; nothing to move|0"

echo "--- the copies from before go after a reboot ---"
run tick >/dev/null 2>&1
chk "a tick on the same boot keeps them" "$( [ -d "$R/srv/joinery.old" ] && echo kept)" "kept"
echo 22222222-aaaa-4bbb-8ccc-000000000002 > "$R/proc/boot_id"
out="$(run tick 2>&1)"; rc=$?
chk "a tick on a later boot, check passing: removed, and said" \
    "$rc|$( [ -e "$R/srv/joinery.old" ] && echo kept || echo gone)|$( [ -e "$R/etc/joinery/data_root_migrated" ] && echo rec || echo none)|$(grep -c 'copies from before the move (/srv/joinery.old) are removed' <<< "$out")" "0|gone|none|1"

echo "--- a move that does not finish puts everything back ---"
mkdir -p "$R/var/www/html/s5/config" "$R/var/www/html/s5/uploads" "$R/var/www/html/s5/logs"
: > "$R/var/www/html/s5/config/Globalvars_site.php"
echo five > "$R/var/www/html/s5/uploads/f.jpg"; echo log5 > "$R/var/www/html/s5/logs/e.log"
echo var-www-html-s5-logs.mount > "$T/bind_fail"
mkdir -p "$R/srv/joinery/sites/s5/uploads"
: > "$LOG"
out="$(run migrate 2>&1)"; rc=$?
chk "a mount that does not take: exit 1, and says nothing was moved" "$rc|$(grep -c 'Nothing was moved: this host keeps its data where it did' <<< "$out")" "1|1"
chk "the folders that had mounted are unmounted, and every folder is its own again" \
    "$(grep -c '^systemctl stop var-www-html-s5-' "$LOG")|$( [ -L "$R/var/www/html/s5/uploads" ] && echo link || cat "$R/var/www/html/s5/uploads/f.jpg")|$(cat "$R/var/www/html/s5/logs/e.log")" "4|five|log5"
chk "not recorded, its copies on the data root (one in a place that was there empty, reviewer2 F5) and aside are gone, the moved record too" \
    "$(grep -c s5 "$B")|$( [ -e "$R/srv/joinery/sites/s5/uploads" ] && echo kept || echo gone)|$( [ -e "$R/srv/joinery.old" ] && echo kept || echo gone)|$( [ -e "$R/etc/joinery/data_root_migrated" ] && echo rec || echo none)" "0|gone|gone|none"
chk "the services started again; s1's mounts untouched; check 0" \
    "$(grep -c '^systemctl start apache2.service cron.service$' "$LOG")|$(cat "$R/var/www/html/s1/uploads/large/a.jpg")|$(run check >/dev/null 2>&1; echo $?)" "1|photo|0"
chk "a site added since the first move stops only the web stack and cron, never PostgreSQL" \
    "$(grep '^systemctl stop ' "$LOG" | grep -v -e '\.mount' -e converger | head -n 1)" "systemctl stop apache2.service cron.service"
rm -f "$T/bind_fail"
out="$(run migrate 2>&1)"; rc=$?
chk "once it will mount: exit 0, and only s5 moves" "$rc|$(grep -c 'onto the data root: 6 folder(s)' <<< "$out")|$(cat "$R/var/www/html/s5/uploads/f.jpg")" "0|1|five"

echo "--- Docker's data-root ---"
stub dockerd 'exit 0'
# docker: one container running, stopped and started by name.
stub docker 'echo "docker $*" >> "$GATE_LOG"; case "$1" in ps) echo s9site ;; esac; exit 0'
mkdir -p "$R/var/lib/docker/volumes/s9_uploads/_data" "$R/etc/docker"
echo vol > "$R/var/lib/docker/volumes/s9_uploads/_data/v.jpg"
echo '{"log-driver": "json-file"}' > "$R/etc/docker/daemon.json"
printf '%s\n' 'docker.service loaded active running Docker' 'docker.socket loaded active running Docker Socket' \
    'containerd.service loaded active running containerd' > "$T/active_units"
: > "$LOG"
out="$(run migrate 2>&1)"; rc=$?
chk "exit 0: Docker's data on the data root" "$rc|$(cat "$R/srv/joinery/docker/volumes/s9_uploads/_data/v.jpg")" "0|vol"
chk "its containers stopped by Docker first, then Docker, then containerd last" \
    "$(grep -e '^docker stop' -e '^systemctl stop' "$LOG" | grep -v converger | paste -sd'|')" "docker stop -t 60 s9site|systemctl stop docker.service docker.socket|systemctl stop containerd.service"
chk "and the containers that were running started again after Docker" \
    "$(grep -e '^docker start' -e '^systemctl start docker.service' "$LOG" | paste -sd'|')" "systemctl start docker.service|docker start s9site"
chk "daemon.json: data-root set, every other key kept" \
    "$(python3 -c 'import json,sys; d=json.load(open(sys.argv[1])); print(d.get("data-root"), d.get("log-driver"))' "$R/etc/docker/daemon.json")" "/srv/joinery/docker json-file"
chk "the old data-root kept aside, not bound" \
    "$(cat "$R/srv/joinery.old/docker/volumes/s9_uploads/_data/v.jpg")|$( [ -e "$R/var/lib/docker" ] && echo there || echo gone)|$(grep -c docker "$B")" "vol|gone|0"
out="$(run migrate 2>&1)"; rc=$?
chk "run again: nothing to move" "$rc|$out" "0|This host keeps no data off the data root; nothing to move"
rm -f "$T/bin/dockerd" "$T/bin/docker" "$T/active_units"

echo "=== a device ==="
rm -rf "$T/root/etc/joinery" "$T/root/etc/fstab" "$T/root/srv/joinery.img" "$T/root/srv/joinery" "$SD" "$T/root/run"
unmount; : > "$LOG"; echo $((50 * G)) > "$T/devsize"
out="$(run create /dev/sdb 2>&1)"; rc=$?
chk "create on a device: exit 0, formatted 32 MiB short of its end (an in-place encrypt's header), recorded with its UUID" \
    "$rc|$(grep -c "^mkfs.xfs -q -n ftype=1 -s size=4096 -d size=$(( 50 * G - 32 * 1024 * 1024 )) /dev/sdb$" "$LOG")|$(grep -c '^backing=device$' "$T/root/etc/joinery/data_root")|$(grep -c '^uuid=0b9c5a6e-1111-4222-8333-944455556666$' "$T/root/etc/joinery/data_root")" "0|1|1|1"
chk "fstab mounts it by UUID, prjquota, nofail; no loop, no direct I/O unit" \
    "$(grep -c '^UUID=0b9c5a6e-1111-4222-8333-944455556666 /srv/joinery xfs prjquota,nofail 0 0$' "$T/root/etc/fstab")|$(grep -c '^fallocate' "$LOG")|$( [ -e "$SD/joinery-data-dio.service" ] && echo dio || echo none)" "1|0|none"
figs data 50*G 45*G 5*G
echo $((50 * G)) > "$T/devsize"
mkdir -p "$T/root/dev/disk/by-uuid"; : > "$T/root/dev/disk/by-uuid/0b9c5a6e-1111-4222-8333-944455556666"
stub blockdev 'echo "blockdev $*" >> "$GATE_LOG"; cat "$GATE_T/devsize" 2>/dev/null' 
: > "$LOG"; run tick >/dev/null 2>&1
chk "tick never asks for more than the device holds" "$(grep -c -e xfs_growfs -e '^fallocate' "$LOG")" "0"
chk "and reads the device by its UUID, which a reboot cannot rename (reviewer2 F8)" \
    "$(grep -c '^blockdev --getsize64 /dev/disk/by-uuid/0b9c5a6e-1111-4222-8333-944455556666$' "$LOG")" "1"
echo $((80 * G)) > "$T/devsize"
out="$(run tick 2>&1)"
chk "once the provider has grown it, the filesystem follows, to 32 MiB short of its end" "$(grep -c "^xfs_growfs -D $(( (80 * G - 32 * 1024 * 1024) / 4096 )) $T/root/srv/joinery$" "$LOG")" "1"
out="$(run grow 90G 2>&1)"; rc=$?
chk "grow SIZE on a device is refused: the provider grows it" "$rc|$(grep -c 'when its provider grows the device' <<< "$out")" "1|1"

echo "=== encrypted (hosted_data_promise_disk_encryption WP1) ==="
R="$T/root"
rm -rf "${R:?}/etc/joinery" "${R:?}/etc/fstab" "${R:?}/srv/joinery.img" "${R:?}/srv/joinery" "${SD:?}" "${R:?}/run" "${R:?}/dev"
unmount; : > "$LOG"
figs root 100*G 20*G 80*G
figs data 25*G 2*G 23*G
mkdir -p "$R/usr/local/bin" "$R/sys/dev/block/7:7"
# The agent: check-recipient passes, seal writes how many bytes it was given,
# slots reads the header slots the test sets.
cat > "$R/usr/local/bin/joinery-agent" <<'AGENT'
#!/bin/bash
echo "agent $*" >> "$GATE_LOG"
case "$1 $2" in
  "data-root check-recipient") exit 0 ;;
  "data-root identity") exit 0 ;;
  "data-root add-slot") cat > /dev/null; echo 4 ;;
  "data-root report-key") echo b2xkLXJlcG9ydC1rZXktb2xkLXJlcG9ydC1rZXktb2xk ;;
  "data-root escrow-rekey") [ -f "$GATE_T/escrow_rekey_fails" ] && exit 1; exit 0 ;;
  "data-root prune-tokens") exit 0 ;;
  "data-root seal") [ -f "$GATE_T/seal_fails" ] && exit 1; n="$(wc -c)"; echo "{\"sealed_bytes\": $n}" ;;
  "data-root slots") cat "$GATE_T/slots" 2>/dev/null ;;
  "data-root preflight") [ -f "$GATE_T/preflight_fails" ] && { echo "joinery-agent: data-root preflight: the escrow holder https://holder.example: unreachable" >&2; exit 1; }; echo ready ;;
esac
exit 0
AGENT
chmod 755 "$R/usr/local/bin/joinery-agent"
# cryptsetup: open makes the mapping; luksUUID names one.
stub cryptsetup 'echo "cryptsetup $*" >> "$GATE_LOG"; case "$1" in open) mkdir -p "$GATE_T/root/dev/mapper"; : > "$GATE_T/root/dev/mapper/joinery-data" ;; close) rm -f "$GATE_T/root/dev/mapper/joinery-data" ;; luksUUID) echo 5d6e7f80-aaaa-4bbb-8ccc-0123456789ab ;; esac; exit 0'
stub systemd-cryptenroll 'echo "systemd-cryptenroll $*" >> "$GATE_LOG"'
stub wipefs 'echo "wipefs $*" >> "$GATE_LOG"'
stub losetup 'echo "losetup $*" >> "$GATE_LOG"; case "$*" in *-j*|*--show*) echo /dev/loop7 ;; esac'
# dmsetup: the live table, with a key; what reload is handed arrives on stdin.
stub dmsetup 'echo "dmsetup $*" >> "$GATE_LOG"; case "$1 $2" in "table joinery-data") echo "0 52428800 crypt aes-xts-plain64 :0: 0 7:7 32768 1 sector_size:4096" ;; "table --showkeys") echo "0 52428800 crypt aes-xts-plain64 5ec2e7ab1e 0 7:7 32768 1 sector_size:4096" ;; "reload joinery-data") cat > "$GATE_T/reloaded" ;; esac; exit 0'
cp "$T/bin/systemctl" "$T/systemctl.plain"
# Starting the target mounts the encrypted data root (its mount is a unit);
# the rest is the bind-mounting stub above.
stub systemctl '[ "$*" = "start joinery-data.target" ] && touch "$GATE_T/mounted"
[ "$*" = "stop srv-joinery.mount" ] && { echo "systemctl $*" >> "$GATE_LOG"; rm -f "$GATE_T/mounted"; exit 0; }
exec "$GATE_T/systemctl.plain" "$@"'

out="$(run create --encrypted 16G 2>&1)"; rc=$?
chk "no recovery recipient: refused, nothing made" \
    "$rc|$(grep -c 'run joinery_data_root.sh recipient PUBLIC_KEY first' <<< "$out")|$( [ -e "$R/srv/joinery.img" ] && echo img || echo none)|$(grep -c cryptsetup "$LOG")" "1|1|none|0"
out="$(run recipient not-a-key 2>&1)"; rc=$?
chk "recipient: a value that is not a public key is refused" "$rc|$(grep -c 'not a recovery public key' <<< "$out")" "1|1"
PUB="$(head -c 32 /dev/urandom | base64)"
FPR="$(printf '%s' "$PUB" | base64 -d | sha256sum | awk '{ print $1 }')"
out="$(run recipient "$PUB" 2>&1)"; rc=$?
chk "recipient: the key and its fingerprint recorded" \
    "$rc|$(grep -c "^public_key=${PUB}$" "$R/etc/joinery/data-root/recovery_recipient")|$(grep -c "^fingerprint=${FPR}$" "$R/etc/joinery/data-root/recovery_recipient")" "0|1|1"
chk "recipient: shown" "$(run recipient)" "recovery recipient: ${FPR}"

mkdir -p "$R/dev"; : > "$R/dev/tpmrm0"
: > "$LOG"
out="$(run create --encrypted 16G 2>&1)"; rc=$?
chk "create --encrypted: exit 0, and says it is encrypted and to whom it is sealed" \
    "$rc|$(grep -c "on an encrypted volume; its key is sealed to the recovery key ${FPR:0:16}" <<< "$out")" "0|1"
chk "LUKS2 in the standard configuration: aes-xts-plain64, a 512-bit key, 4096-byte sectors, PBKDF2-SHA256 at 1000000" \
    "$(grep -c '^cryptsetup luksFormat --batch-mode --type luks2 --cipher aes-xts-plain64 --key-size 512 --sector-size 4096 --pbkdf pbkdf2 --hash sha256 --pbkdf-force-iterations 1000000 --key-slot 0 --volume-key-file .*/volume.key /dev/loop7 .*/slot.key$' "$LOG")" "1"
chk "on the image's loop device, with direct I/O" "$(grep -c "^losetup --direct-io=on --find --show $R/srv/joinery.img$" "$LOG")" "1"
chk "the key is sealed to the recovery key before the volume is written: 64 bytes, a 512-bit key" \
    "$(grep -n -e '^agent data-root seal' -e '^cryptsetup luksFormat' "$LOG" | cut -d: -f2 | cut -d' ' -f1-2 | paste -sd,)|$(cat "$R/srv/joinery.img.recovery")|$(stat -c %a "$R/srv/joinery.img.recovery")" \
    'agent data-root,cryptsetup luksFormat|{"sealed_bytes": 64}|600'
chk "a TPM2 chip is enrolled (PCR 7), then the first slot goes" \
    "$(grep -c '^systemd-cryptenroll --unlock-key-file=.*/slot.key --tpm2-device=auto --tpm2-pcrs=7 /dev/loop7$' "$LOG")|$(grep -c '^cryptsetup luksRemoveKey --batch-mode /dev/loop7 .*/slot.key$' "$LOG")" "1|1"
chk "opened with no keyring and the key size, never with discards" \
    "$(grep -c '^cryptsetup open --disable-keyring --key-size 512 --volume-key-file .*/volume.key /dev/loop7 joinery-data$' "$LOG")|$(grep -c 'allow-discards' "$LOG")" "1|0"
chk "XFS on the mapping, not the image" \
    "$(grep -c "^mkfs.xfs -q -n ftype=1 -s size=4096 $R/dev/mapper/joinery-data$" "$LOG")|$(grep -c "^mkfs.xfs.*joinery.img" "$LOG")" "1|0"
chk "the key material in /run is gone" "$(ls "$R/run/joinery" | grep -c data-root-key)" "0"
chk "the unlock identity is made for the escrow holder to know it by (D3)" "$(grep -c '^agent data-root identity$' "$LOG")" "1"
# The agent adds escrow slots itself (escrow.go): its slot settings are these.
AGENT_ESCROW="${HOME}/joinery-agent/escrow.go"
if [ -f "$AGENT_ESCROW" ]; then
    chk "the agent's key slots are the script's: PBKDF2-SHA256 at the same count" \
        "$(grep -c -e 'luksSlotPBKDF      = "pbkdf2"' -e 'luksSlotHash       = "sha256"' -e "luksSlotIterations = \"$(sed -n 's/^LUKS_ITERATIONS=//p' "$SCRIPT")\"" "$AGENT_ESCROW")" "3"
fi
chk "today's count is among the counts check accepts" \
    "$(eval "$(grep -E '^LUKS_ITERATIONS(_MADE)?=' "$SCRIPT")"; [[ " ${LUKS_ITERATIONS_MADE[*]} " == *" $LUKS_ITERATIONS "* ]] && echo yes)" "yes"
C="$R/etc/joinery/data_root"
chk "recorded: luks-file, the image, the break-glass copy" \
    "$(grep -c '^backing=luks-file$' "$C")|$(grep -c '^source=/srv/joinery.img$' "$C")|$(grep -c '^sidecar=/srv/joinery.img.recovery$' "$C")" "1|1|1"
chk "no fstab line, and nothing mounted by hand: the target mounts it" \
    "$(grep -c joinery "$R/etc/fstab" 2>/dev/null || true)|$(grep -c '^mount ' "$LOG")|$(grep -c '^systemctl start joinery-data.target$' "$LOG")" "|0|1"
chk "the mount: its own unit, on the mapping, after the unlock, out of local-fs (D1b)" \
    "$(grep -c '^What=/dev/mapper/joinery-data$' "$SD/srv-joinery.mount")|$(grep -c '^DefaultDependencies=no$' "$SD/srv-joinery.mount")|$(grep -c '^Requires=joinery-data-unlock.service$' "$SD/srv-joinery.mount")|$(grep -c '^After=joinery-data-unlock.service blk-availability.service$' "$SD/srv-joinery.mount")|$(grep -c '^Options=prjquota$' "$SD/srv-joinery.mount")|$(grep -c '^Before=umount.target joinery-data.target$' "$SD/srv-joinery.mount")" "1|1|1|1|1|1"
chk "the unlock: the agent, after the network, waiting as long as it must" \
    "$(grep -c '^ExecStart=/usr/local/bin/joinery-agent unlock$' "$SD/joinery-data-unlock.service")|$(grep -c '^After=network-online.target blk-availability.service$' "$SD/joinery-data-unlock.service")|$(grep -c '^TimeoutStartSec=infinity$' "$SD/joinery-data-unlock.service")|$(grep -c '^ExecStop=/usr/local/bin/joinery-agent unlock --close$' "$SD/joinery-data-unlock.service")" "1|1|1|1"
chk "the mapping's device is waited for as long, not 90 seconds" \
    "$(grep -c '^JobRunningTimeoutSec=infinity$' "$SD/dev-mapper-joinery\x2ddata.device.d/joinery-data-root.conf")" "1"
chk "the wall agent and the console logins wait for the unlock, so the console agent keeps the console (D2a)" \
    "$(cat "$SD/systemd-ask-password-wall.service.d/joinery-data-root.conf" "$SD/getty@.service.d/joinery-data-root.conf" "$SD/serial-getty@.service.d/joinery-data-root.conf" | grep -c '^After=joinery-data-unlock.service$')" "3"
chk "no direct I/O unit (the unlock sets the loop up), and the target wants none" \
    "$( [ -e "$SD/joinery-data-dio.service" ] && echo dio || echo none)|$(grep -c dio "$SD/joinery-data.target")" "none|0"
chk "check: 0, mounted and open" "$(run check >/dev/null 2>&1; echo $?)" "0"

echo "--- slots ---"
printf 'slot=1 kdf=pbkdf2 hash=sha256 iterations=1000 token=systemd-tpm2\nslot=2 kdf=pbkdf2 hash=sha256 iterations=1000000 token=-\nslot=5 kdf=pbkdf2 hash=sha256 iterations=2000000 token=-\n' > "$T/slots"
chk "the TPM2 slot, a slot at today's count and one an earlier release made: check 0" "$(run check >/dev/null 2>&1; echo $?)" "0"
cp "$T/slots" "$T/slots.ok"
for n in 500000 1500000 4000000; do
    cp "$T/slots.ok" "$T/slots"; printf 'slot=4 kdf=pbkdf2 hash=sha256 iterations=%s token=-\n' "$n" >> "$T/slots"
    out="$(run check 2>&1)"; rc=$?
    chk "a PBKDF2-SHA256 slot at ${n}, a count this platform never made: check fails and names it" \
        "$rc|$(grep -c "key slot 4 of the encrypted volume was not made by this platform (pbkdf2 sha256, ${n} iterations" <<< "$out")" "1|1"
done
cp "$T/slots.ok" "$T/slots"
printf 'slot=3 kdf=argon2id hash=- iterations=4 token=-\n' >> "$T/slots"
out="$(run check 2>&1)"; rc=$?
chk "a slot this platform did not make: check fails and names it" "$rc|$(grep -c 'key slot 3 of the encrypted volume was not made by this platform (argon2id' <<< "$out")" "1|1"
chk "and tick goes on: a strange slot is not 'not ready'" "$(run tick >/dev/null 2>&1; echo $?)" "0"
chk "status names the slots, the TPM2 chip and the break-glass copy" \
    "$(run status 2>&1 | grep -c -e '^state: mounted' -e '^key slots: 4, one of them the TPM2 chip$' -e "^break-glass copy: /srv/joinery.img.recovery, sealed to the recovery key ${FPR}$" -e '^encryption: LUKS2' -e '^WARNING: key slot 3')" "5"
: > "$T/slots"

echo "--- binds ---"
out="$(run bind enctest /var/lib/enctest 2>&1)"; rc=$?
U="$SD/var-lib-enctest.mount"
chk "a bind on an encrypted data root is out of local-fs too" \
    "$rc|$(grep -c '^DefaultDependencies=no$' "$U")|$(grep -c '^Conflicts=umount.target$' "$U")" "0|1|1"

echo "--- locked ---"
rm -f "$R/dev/mapper/joinery-data"
out="$(run check 2>&1)"; rc=$?
chk "the volume not open: check 1, locked, and where to look" "$rc|$(grep -c 'the data root is locked: its encrypted volume is not open yet (see: systemctl status joinery-data-unlock.service)' <<< "$out")" "1|1"
chk "tick: 1, not ready" "$(run tick >/dev/null 2>&1; echo $?)" "1"
: > "$R/dev/mapper/joinery-data"

echo "--- stop and start by hand, encrypted (B17) ---"
cp "$T/bin/systemctl" "$T/systemctl.b17e"
# The opener's stop takes the mount with it (the mount requires it) and closes the volume.
stub systemctl 'echo "systemctl $*" >> "$GATE_LOG"
case "$*" in
  "stop joinery-data-unlock.service") rm -f "$GATE_T/mounted" "$GATE_T/root/dev/mapper/joinery-data" ;;
esac
exit 0'
SF="$R/run/joinery/data-root.stopped"
was_mounted="$( [ -e "$T/mounted" ] && echo 1 || echo 0)"
touch "$T/mounted"; rm -f "$SF"; : > "$LOG"
out="$(run stop 2>&1)"; rc=$?
chk "stop: the volume's opener is stopped, the volume closed, and said" \
    "$rc|$(grep -c '^systemctl stop joinery-data-unlock.service$' "$LOG")|$(grep -c '^systemctl stop srv-joinery.mount$' "$LOG")|$( [ -e "$R/dev/mapper/joinery-data" ] && echo open || echo closed)|$(grep -c '^data root: stopped, its volume closed' <<< "$out")" \
    "0|1|0|closed|1"
: > "$LOG"
chk "the tick: 1, locked, nothing started" "$(run tick >/dev/null 2>&1; echo $?)|$(grep -c '^systemctl start' "$LOG")" "1|0"
out="$(run start 2>&1)"; rc=$?
chk "start: the target asked for without waiting, as at a boot, and the key asked for" \
    "$rc|$(grep -c '^systemctl start --no-block joinery-data.target$' "$LOG")|$(grep -c 'asking for its key, as at a boot' <<< "$out")|$( [ -e "$SF" ] && echo kept || echo gone)" "0|1|1|gone"
cp "$T/systemctl.b17e" "$T/bin/systemctl"
: > "$R/dev/mapper/joinery-data"; [ "$was_mounted" = 1 ] || rm -f "$T/mounted"; : > "$LOG"

echo "--- grow, through the mapping, with its own key ---"
: > "$LOG"
figs data 25*G 20*G 5*G
echo $(( 32 * G / 512 )) > "$R/sys/dev/block/7:7/size"
out="$(run tick 2>&1)"; rc=$?
chk "the image grows, the loop rereads it, the mapping is reloaded and resumed, then XFS grows" \
    "$rc|$(grep -e '^fallocate -l' -e '^losetup -c' -e '^dmsetup reload' -e '^dmsetup resume' -e '^xfs_growfs' "$LOG" | cut -d' ' -f1-2 | paste -sd,)" \
    "0|fallocate -l,losetup -c,dmsetup reload,dmsetup resume,xfs_growfs $R/srv/joinery"
chk "the new table: the old one's key, to the end of the device in 4096-byte sectors" \
    "$(cat "$T/reloaded")" "0 $(( (32 * G / 512 - 32768) / 8 * 8 )) crypt aes-xts-plain64 5ec2e7ab1e 0 7:7 32768 1 sector_size:4096"
chk "and the key is never an argument" "$(grep -c 5ec2e7ab1e "$LOG")" "0"

echo "--- a device, grown at the provider, through the mapping ---"
cp "$C" "$T/conf.file"
printf 'backing=luks-device\nsource=/dev/vdb\nuuid=5d6e7f80-aaaa-4bbb-8ccc-0123456789ab\nsidecar=/etc/joinery/data-root/volume.recovery\n' > "$C"
figs data 8*G 1*G 7*G
# Opened after a reboot: the mapping already spans the grown device (10 GiB).
echo $(( 10 * G )) > "$T/devsize"
# The live table (the dmsetup stub) is 52428800 sectors from offset 32768: it ends where the device does.
echo $(( 52428800 + 32768 )) > "$R/sys/dev/block/7:7/size"
: > "$LOG"; rm -f "$T/reloaded"
out="$(run tick 2>&1)"; rc=$?
chk "the mapping already fills the device: no reload, and XFS grows to the mapping" \
    "$rc|$( [ -e "$T/reloaded" ] && echo reload || echo none)|$(grep -c "^xfs_growfs $R/srv/joinery$" "$LOG")|$(grep -c 'grew with /dev/vdb' <<< "$out")" "0|none|1|1"
figs data 10*G 1*G 9*G
: > "$LOG"; run tick >/dev/null 2>&1
chk "and once XFS fills it, nothing more" "$(grep -c -e xfs_growfs -e '^dmsetup reload' "$LOG")" "0"
cp "$T/conf.file" "$C"

echo "--- reboots after updates, on an encrypted host that takes them (D4) ---"
mkdir -p "$R/etc/apt/apt.conf.d" "$R/var/run"
: > "$R/etc/apt/apt.conf.d/52joinery-multi-tenant-reboot"
echo "gate-machine-id" > "$R/etc/machine-id"
SLOT=$(( 16#$(printf '%s' gate-machine-id | sha256sum | cut -c1-6) % 120 ))
: > "$LOG"
JOINERY_TEST_UTC_MINUTE=$(( 360 + SLOT )) run tick >/dev/null 2>&1
chk "apt's own reboot is turned off over the multi-tenant policy" \
    "$(grep -c 'Automatic-Reboot "false"' "$R/etc/apt/apt.conf.d/53joinery-encrypted-reboot")" "1"
chk "no update asked for one: no reboot" "$(grep -c 'systemctl reboot' "$LOG")" "0"
: > "$R/var/run/reboot-required"
JOINERY_TEST_UTC_MINUTE=$(( 360 + SLOT - 1 )) run tick >/dev/null 2>&1
chk "asked, before this host's minute: not yet" "$(grep -c 'systemctl reboot' "$LOG")" "0"
JOINERY_TEST_UTC_MINUTE=$(( 360 + SLOT )) run tick >/dev/null 2>&1
chk "at its minute, the holder answers the preflight: it reboots" \
    "$(grep -c '^agent data-root preflight$' "$LOG")|$(grep -c '^systemctl reboot$' "$LOG")" "1|1"
: > "$LOG"; touch "$T/preflight_fails"
out="$(JOINERY_TEST_UTC_MINUTE=$(( 360 + SLOT + 1 )) run tick 2>&1)"
chk "the holder does not answer: held back, said, and recorded for the node page" \
    "$(grep -c '^systemctl reboot$' "$LOG")|$(grep -c 'held back' <<< "$out")|$( [ -s "$R/run/joinery/data-root.reboot-held" ] && echo held)" "0|1|held"
out="$(JOINERY_TEST_UTC_MINUTE=$(( 360 + SLOT + 2 )) run tick 2>&1)"
chk "and said once a day, not every minute" "$(grep -c 'held back' <<< "$out")" "0"
JOINERY_TEST_UTC_MINUTE=$(( 360 + 121 )) run tick >/dev/null 2>&1
chk "after the window: nothing until tomorrow's" "$(grep -c '^systemctl reboot$' "$LOG")" "0"
rm -f "$T/preflight_fails" "$R/var/run/reboot-required" "$R/etc/apt/apt.conf.d/52joinery-multi-tenant-reboot"

echo "--- the recipient, once sealed: a rotation re-seals (D8) ---"
NEWPUB="$(head -c 32 /dev/urandom | base64)"
out="$(run recipient "$NEWPUB" 2>&1)"; rc=$?
chk "another recipient under a sealed volume: taken, and it says the next tick seals again" "$rc|$(grep -c 'seals it again' <<< "$out")" "0|1"
printf '{"recipients":[{"fingerprint":"%s","sealed":"x"}]}\n' "$FPR" > "$R/srv/joinery.img.recovery"
: > "$LOG"
run tick >/dev/null 2>&1
chk "the tick seals the copy again from the open mapping, and tells the holder" \
    "$(grep -c '^agent data-root reseal --recipient' "$LOG")|$(grep -c '^agent data-root report addresses$' "$LOG")" "1|1"
chk "who set the recipient is recorded: the install, unless it says" "$(grep -c '^set_by=install$' "$R/etc/joinery/data-root/recovery_recipient")" "1"
run recipient --set-by=operator "$NEWPUB" >/dev/null 2>&1
chk "--set-by=operator: recorded" "$(grep -c '^set_by=operator$' "$R/etc/joinery/data-root/recovery_recipient")" "1"
out="$(run recipient --set-by=someone "$NEWPUB" 2>&1)"; rc=$?
chk "--set-by names site, operator or install only" "$rc|$(grep -c 'site, operator or install' <<< "$out")" "1|1"
chk "the key it replaced is recorded as one this machine was moved off" "$(grep -c "^${FPR} 20[0-9-]*T[0-9:]*Z$" "$R/etc/joinery/data-root/retired_recipients")" "1"
cp "$R/etc/joinery/data-root/recovery_recipient" "$T/recipient.now"
out="$(run recipient --set-by=operator "$PUB" 2>&1)"; rc=$?
chk "a key it was moved off: refused, nothing changed, and it says why (B41: a restore from before a rotation)" \
    "$rc|$(grep -c 'moved off the recovery key' <<< "$out")|$(cmp -s "$T/recipient.now" "$R/etc/joinery/data-root/recovery_recipient" && echo same)" "1|1|same"
echo "--- the TPM2 chip did not open this boot, something else did: sealed again to this boot (D2) ---"
mkdir -p "$R/dev" "$R/run/joinery"; : > "$R/dev/tpmrm0"; echo "2026-10-11 21:13:00 UTC" > "$R/run/joinery/data-root.tpm2-failed"
# The chip's slot, which the re-seal replaces (the tick then finds it as its record says).
printf 'slot=2 kdf=pbkdf2 hash=sha512 iterations=1000 token=systemd-tpm2\n' > "$T/slots"
: > "$LOG"; out="$(run tick 2>&1)"
chk "a slot from the open volume's key, the chip sealed again through it, the slot taken away" \
    "$(grep -n -e '^agent data-root add-slot' -e '^systemd-cryptenroll --unlock-key-file=.* --wipe-slot=tpm2 --tpm2-device=auto --tpm2-pcrs=7' -e '^cryptsetup luksRemoveKey --batch-mode' "$LOG" | cut -d: -f2 | cut -d' ' -f1 | paste -sd,)|$(grep -c 'sealed again to this boot' <<< "$out")|$( [ -e "$R/run/joinery/data-root.tpm2-failed" ] && echo marked || echo cleared)" \
    "agent,systemd-cryptenroll,cryptsetup|1|cleared"
chk "and the token of the slot it replaced is pruned" "$(grep -c '^agent data-root prune-tokens /dev/loop7$' "$LOG")" "1"
: > "$LOG"; run tick >/dev/null 2>&1
chk "and only then: the next tick does nothing more" "$(grep -c -e 'add-slot' -e cryptenroll "$LOG")" "0"
echo "--- the TPM2 chip never used, by choice (B43) ---"
printf 'slot=1 kdf=pbkdf2 hash=sha256 iterations=1000 token=systemd-tpm2\nslot=2 kdf=pbkdf2 hash=sha256 iterations=1000000 token=-\n' > "$T/slots"
: > "$LOG"; out="$(run tpm2 off 2>&1)"; rc=$?
printf 'slot=2 kdf=pbkdf2 hash=sha256 iterations=1000000 token=-\n' > "$T/slots.after_off"
chk "tpm2 off: recorded, the chip's slot wiped through a slot made for the moment, that slot taken away, tokens pruned" \
    "$rc|$(grep -c '^tpm2=off$' "$C")|$(grep -e '^agent data-root add-slot' -e '^systemd-cryptenroll' -e '^cryptsetup luksRemoveKey' -e '^agent data-root prune-tokens' "$LOG" | cut -d' ' -f1-3 | sed 's/ --unlock-key-file=[^ ]*//' | paste -sd,)" \
    "0|1|agent data-root add-slot,systemd-cryptenroll --wipe-slot=tpm2,cryptsetup luksRemoveKey --batch-mode,agent data-root prune-tokens"
chk "and only wiped, nothing enrolled in its place; said" \
    "$(grep -c '^systemd-cryptenroll --unlock-key-file=[^ ]* --wipe-slot=tpm2 /dev/loop7$' "$LOG")|$(grep -c 'the TPM2 chip does not open it' <<< "$out")" "1|1"
cp "$T/slots.after_off" "$T/slots"
chk "status says the chip is never used" "$(run status 2>&1 | grep -c '^TPM2: never used to open it (tpm2 off); this machine has a chip$')" "1"
echo "2026-10-15 05:00:00 UTC" > "$R/run/joinery/data-root.tpm2-failed"; : > "$LOG"
run tick >/dev/null 2>&1
chk "the tick: no re-seal of a chip that is off, its mark dropped; the status file says chip and choice" \
    "$(grep -c -e add-slot -e cryptenroll "$LOG")|$( [ -e "$R/run/joinery/data-root.tpm2-failed" ] && echo marked || echo cleared)|$(grep -c -e '^tpm2_chip=1$' -e '^tpm2=off$' "$R/run/joinery/data-root.status")" "0|cleared|2"
echo 'reencrypt=reencrypt' >> "$C"; : > "$LOG"
out="$(run tpm2 on 2>&1)"; rc=$?
chk "tpm2 on during a run: only recorded, the finish follows it" \
    "$rc|$(grep -c '^tpm2=' "$C")|$(grep -c cryptenroll "$LOG")|$(grep -c 'follows the record at the first tick after it finishes' <<< "$out")" "0|0|0|1"
sed -i '/^reencrypt=/d' "$C"; echo 'tpm2=off' >> "$C"
printf 'slot=2 kdf=pbkdf2 hash=sha256 iterations=1000000 token=-\n' > "$T/slots"; : > "$LOG"
out="$(run tpm2 on 2>&1)"; rc=$?
chk "tpm2 on: the record cleared and the chip enrolled through a slot made for the moment" \
    "$rc|$(grep -c '^tpm2=' "$C")|$(grep -c '^systemd-cryptenroll --unlock-key-file=[^ ]* --tpm2-device=auto --tpm2-pcrs=7 /dev/loop7$' "$LOG")|$(grep -c 'chip opens it at boot' <<< "$out")" "0|0|1|1"
printf 'slot=1 kdf=pbkdf2 hash=sha256 iterations=1000 token=systemd-tpm2\n' > "$T/slots"; : > "$LOG"
out="$(run tpm2 on 2>&1)"; rc=$?
chk "tpm2 on with the chip enrolled already: nothing more" "$rc|$(grep -c cryptenroll "$LOG")" "0|0"
out="$(run tpm2 maybe 2>&1)"; rc=$?
chk "tpm2 takes on or off only" "$rc|$(grep -c 'tpm2 takes on or off' <<< "$out")" "1|1"
# reviewer1 B43 F1: a choice made while the volume is locked is carried out by
# the tick once it is open, not left for a re-seal mark that may never come.
rm -f "$R/dev/mapper/joinery-data"; : > "$LOG"
out="$(run tpm2 off 2>&1)"; rc=$?
chk "tpm2 off while locked: recorded, nothing touched yet" "$rc|$(grep -c '^tpm2=off$' "$C")|$(grep -c -e add-slot -e cryptenroll "$LOG")" "0|1|0"
: > "$R/dev/mapper/joinery-data"; : > "$LOG"
out="$(run tick 2>&1)"
chk "open again: the tick takes the chip's slot away through a slot made for the moment, and says so" \
    "$(grep -c '^systemd-cryptenroll --unlock-key-file=[^ ]* --wipe-slot=tpm2 /dev/loop7$' "$LOG")|$(grep -c 'taken away, as its record says' <<< "$out")" "1|1"
cp "$T/slots.after_off" "$T/slots"; : > "$LOG"
run tick >/dev/null 2>&1
chk "and once the slot is gone, the next tick does nothing" "$(grep -c -e add-slot -e cryptenroll "$LOG")" "0"
# The mirror: wanted (record cleared by hand) and no slot: enrolled by the tick.
sed -i '/^tpm2=/d' "$C"; : > "$LOG"
out="$(run tick 2>&1)"
chk "wanted and no slot: the tick enrols the chip, and says so" \
    "$(grep -c '^systemd-cryptenroll --unlock-key-file=[^ ]* --tpm2-device=auto --tpm2-pcrs=7 /dev/loop7$' "$LOG")|$(grep -c 'opens it at boot, as its record says' <<< "$out")" "1|1"
# An enrolment that fails is said once, and left for the next boot.
cp "$T/bin/systemd-cryptenroll" "$T/cryptenroll.ok"
stub systemd-cryptenroll 'echo "systemd-cryptenroll $*" >> "$GATE_LOG"; exit 1'
rm -f "$R/run/joinery/data-root.tpm2-follow-failed"; : > "$LOG"
out="$(run tick 2>&1)"; out2="$(run tick 2>&1)"
chk "a chip that will not enrol: tried once, said once, left until the next boot" \
    "$(grep -c '^systemd-cryptenroll' "$LOG")|$(grep -c 'could not be enrolled' <<< "$out")|$(grep -c 'could not be enrolled' <<< "$out2")" "1|1|0"
cp "$T/cryptenroll.ok" "$T/bin/systemd-cryptenroll"
rm -f "$R/run/joinery/data-root.tpm2-follow-failed"
# N2: a slot made for the moment that would not go is kept by its key and
# taken away first next time; none is made past the ceiling.
cp "$T/bin/cryptsetup" "$T/cryptsetup.n2"
stub cryptsetup 'echo "cryptsetup $*" >> "$GATE_LOG"; [ "$1" = luksRemoveKey ] && [ ! -f "$GATE_T/remove_ok" ] && exit 1; exit 0'
echo 'tpm2=off' >> "$C"; printf 'slot=1 kdf=pbkdf2 hash=sha512 iterations=1000 token=systemd-tpm2\n' > "$T/slots"; : > "$LOG"
run tpm2 off >/dev/null 2>&1
chk "a slot made for the moment that does not go: its key kept in /run, root's only" \
    "$( [ -f "$R/run/joinery/data-root.tpm2-orphan.key" ] && stat -c %a "$R/run/joinery/data-root.tpm2-orphan.key" || echo none)" "600"
: > "$LOG"; out="$(run tpm2 off 2>&1)"; rc=$?
chk "while it stays: no new slot is made, and the change says it could not be done" \
    "$rc|$(grep -c 'add-slot' "$LOG")|$(grep -c 'could not be taken away' <<< "$out")" "1|0|1"
touch "$T/remove_ok"; : > "$LOG"; run tpm2 off >/dev/null 2>&1
chk "once it goes: it is taken away first, then the change is made" \
    "$(grep -n -e '^cryptsetup luksRemoveKey --batch-mode /dev/loop7 .*tpm2-orphan.key$' -e '^agent data-root add-slot' "$LOG" | cut -d: -f2 | cut -d' ' -f1-2 | paste -sd,)|$( [ -f "$R/run/joinery/data-root.tpm2-orphan.key" ] && echo kept || echo gone)" \
    "cryptsetup luksRemoveKey,agent data-root|gone"
rm -f "$T/remove_ok"; cp "$T/cryptsetup.n2" "$T/bin/cryptsetup"
for i in $(seq 1 24); do printf 'slot=%d kdf=pbkdf2 hash=sha256 iterations=1000000 token=-\n' "$i"; done > "$T/slots"
printf 'slot=25 kdf=pbkdf2 hash=sha512 iterations=1000 token=systemd-tpm2\n' >> "$T/slots"; : > "$LOG"
out="$(run tpm2 off 2>&1)"; rc=$?
chk "with 24 slots or more, no slot is made for the moment" "$rc|$(grep -c add-slot "$LOG")" "1|0"
rm -f "$R/run/joinery/data-root.tpm2-follow-failed"
sed -i '/^tpm2=/d; /^reencrypt=/d' "$C"
: > "$T/slots"
rm -f "$R/dev/tpmrm0"
echo "--- a leaked recovery key: re-encrypted under a new volume key (D8) ---"
: > "$LOG"
out="$(run rekey 2>&1)"; rc=$?
chk "rekey: exit 0, re-encrypting in the background" "$rc|$(grep -c 're-encrypting under a new volume key in the background' <<< "$out")" "0|1"
chk "the old key's report key kept on the data root, for the holder at the finish" \
    "$(cat "$R/srv/joinery/.joinery-rekey/old_report_key")|$(stat -c %a "$R/srv/joinery/.joinery-rekey/old_report_key")" "b2xkLXJlcG9ydC1rZXktb2xkLXJlcG9ydC1rZXktb2xk|600"
chk "the new volume key and the driving key sealed before anything is written" \
    "$(grep -n -e '^agent data-root seal' -e '^cryptsetup reencrypt' "$LOG" | cut -d: -f2 | cut -d' ' -f1-4 | paste -sd,)" \
    "agent data-root seal --recipient,agent data-root seal --recipient,cryptsetup reencrypt --init-only --batch-mode"
chk "the new key's pending copy: 64 bytes, a 512-bit key" "$(grep -c '"sealed_bytes": 64' "$R/srv/joinery.img.recovery.pending")" "1"
chk "LUKS2 re-encryption under that key, through the driving slot, the standard slot settings, no keyring" \
    "$(grep -c -- '^cryptsetup reencrypt --init-only --batch-mode --disable-keyring --key-size 512 --hotzone-size 8M --pbkdf pbkdf2 --hash sha256 --pbkdf-force-iterations 1000000 --volume-key-file .*/new.vk --key-slot 4 --key-file .*/slot.key /dev/loop7$' "$LOG")" "1"
chk "recorded: re-encrypting, its driving slot, the pending copy; the new key itself not kept" \
    "$(grep -c -e '^reencrypt=reencrypt$' -e '^driving_slot=4$' -e '^rekey_sidecar=/srv/joinery.img.recovery.pending$' "$C")|$( [ -e "$R/run/joinery/data-root-encrypt/new.vk" ] && echo kept || echo gone)" "3|gone"
out="$(run decrypt 2>&1)"; rc=$?
chk "decrypt while it re-encrypts: refused" "$rc|$(grep -c 'decrypt it once that is done' <<< "$out")" "1|1"
out="$(run rekey 2>&1)"; rc=$?
chk "a second rekey: refused" "$rc|$(grep -c 'a re-encryption is running' <<< "$out")" "1|1"
printf 'url=https://holder.example\nslot=2\n' > "$R/etc/joinery/data-root/holder"
touch "$T/escrow_rekey_fails"; : > "$LOG"
out="$(run encrypt-resume 2>&1)"
chk "done: the new copy replaces the old, the driving slot goes, the run is over" \
    "$( [ -e "$R/srv/joinery.img.recovery.pending" ] && echo pending || echo replaced)|$(grep -c '"sealed_bytes": 64' "$R/srv/joinery.img.recovery")|$(grep -c '^cryptsetup luksRemoveKey --batch-mode /dev/loop7 .*/slot.key$' "$LOG")|$(grep -c -e '^reencrypt=.' -e '^driving_slot=.' "$C")" \
    "replaced|1|1|0"
chk "the holder unreachable: the run still finishes, and the escrow slot is asked again each minute" \
    "$(grep -c '^agent data-root escrow-rekey --old-report-key-file' "$LOG")|$(grep -c '^escrow_rekey_due=1$' "$C")|$( [ -e "$R/srv/joinery/.joinery-rekey/old_report_key" ] && echo kept)" "1|1|kept"
rm -f "$T/escrow_rekey_fails"; : > "$LOG"
run tick >/dev/null 2>&1
chk "the holder back: the escrow slot made again, and the old key's report key gone" \
    "$(grep -c '^agent data-root escrow-rekey' "$LOG")|$(grep -c '^escrow_rekey_due=.' "$C")|$( [ -e "$R/srv/joinery/.joinery-rekey" ] && echo kept || echo gone)" "1|0|gone"
rm -f "$R/etc/joinery/data-root/holder"
echo "--- the site's view (WP4) ---"
rm -f "$R/srv/joinery.img.recovery"; : > "$LOG"
run tick >/dev/null 2>&1
chk "a break-glass copy that is gone is made again from the open volume" "$(grep -c '^agent data-root reseal --recipient' "$LOG")" "1"
ST="$R/run/joinery/data-root.status"
chk "the status the site reads: encrypted, open, whose key and who set it, the copy" \
    "$(grep -c -e '^backing=luks-file$' -e '^encrypted=1$' -e '^open=1$' -e "^recipient_fingerprint=$(printf '%s' "$NEWPUB" | base64 -d | sha256sum | awk '{ print $1 }')$" -e '^recipient_set_by=operator$' "$ST")|$(stat -c %a "$ST")" "5|644"

echo "--- encrypt an existing data root where it stands (D5) ---"
rm -rf "${R:?}/etc/joinery/data_root" "${R:?}/srv/joinery.img.recovery" "${R:?}/srv/joinery" "${SD:?}" "${R:?}/run" "${R:?}/dev/mapper"
rm -f "$R/etc/joinery/data-root/driving-slot.recovery" "$R/etc/joinery/data_binds"
mkdir -p "$R/srv/joinery"; truncate -s $(( 8 * G )) "$R/srv/joinery.img"
printf 'backing=file\nsource=/srv/joinery.img\n' > "$C"
printf '%s\n/srv/joinery.img /srv/joinery xfs loop,prjquota,nofail 0 0\n' '# joinery-data-root (joinery_data_root.sh)' > "$R/etc/fstab"
touch "$T/mounted"; figs data 8*G 1*G 7*G
stub xfs_info 'echo "meta-data=/dev/loop7 isize=512 agcount=4 agsize=524288 blks"; echo "         =                       sectsz=512   attr=2, projid32bit=1"; echo "data     =                       bsize=4096   blocks=2097152, imaxpct=25"'
# A bind something holds open (T-F2: a message being queued) keeps the filesystem
# mounted after the data root's own mount stops.
mkdir -p "$R/proc/self"
printf '%s\n' '36 25 7:7 / /srv/joinery rw,relatime - xfs /dev/loop7 rw' '41 25 7:7 /mail/postfix /var/spool/postfix rw,relatime - xfs /dev/loop7 rw' > "$R/proc/self/mountinfo"
: > "$LOG"
out="$(run encrypt 2>&1)"; rc=$?
chk "encrypt with the filesystem still mounted at a busy bind: refused, nothing written, the data root started again" \
    "$rc|$(grep -c 'still mounted at /srv/joinery /var/spool/postfix' <<< "$out")|$(grep -c -e '^cryptsetup reencrypt' -e '^fallocate' "$LOG")|$(grep -c '^systemctl start joinery-data.target$' "$LOG")|$( [ -e "$R/etc/joinery/data-root/driving-slot.recovery" ] && echo sealed || echo none)" \
    "1|1|0|1|none"
# The same bind held by a service (rsyslog's socket in Postfix's chroot): stopped for
# the start, the bind unmounted, and the run goes on.
printf '%s\n' '36 25 7:7 / /srv/joinery rw,relatime - xfs /dev/loop7 rw' '41 25 7:7 /mail/postfix /var/spool/postfix rw,relatime - xfs /dev/loop7 rw' > "$R/proc/self/mountinfo"
mkdir -p "$R/proc/4242" "$R/proc/4343"; echo '0::/system.slice/rsyslog.service' > "$R/proc/4242/cgroup"
# The run's own service keeps one too: a site's Backups page asks through the
# host converger (B42). Stopping it would stop this run part-way.
echo '0::/system.slice/joinery-host-converger.service' > "$R/proc/4343/cgroup"; cp "$R/proc/4343/cgroup" "$R/proc/self/cgroup"
stub fuser 'case "$*" in *var/spool/postfix*) echo 4242 4343 ;; esac'
cp "$T/bin/systemctl" "$T/systemctl.d5"
stub systemctl 'case "$*" in "stop srv-joinery.mount") sed -i "\# /srv/joinery #d" "$GATE_T/root/proc/self/mountinfo" ;; "stop var-spool-postfix.mount") sed -i "\# /var/spool/postfix #d" "$GATE_T/root/proc/self/mountinfo" ;; esac
exec "$GATE_T/systemctl.d5" "$@"'
held_binds() { printf '%s\n' '36 25 7:7 / /srv/joinery rw,relatime - xfs /dev/loop7 rw' '41 25 7:7 /mail/postfix /var/spool/postfix rw,relatime - xfs /dev/loop7 rw' > "$R/proc/self/mountinfo"; }
# A start that ends after the services stopped, however it ends, starts them again
# on the data root as it was, and leaves no keys or sealed copies behind (B42).
start_undone() {  # what the transcript says
    printf '%s' "$(grep -c '^systemctl start joinery-data.target$' "$LOG")|$(grep -c '^systemctl start .*rsyslog.service' "$LOG")|$(grep -c 'as it was, unencrypted, and its services started again' <<< "$1")|$( [ -e "$R/srv/joinery.img.recovery" ] || [ -e "$R/etc/joinery/data-root/driving-slot.recovery" ] && echo sealed || echo none)|$(ls -d "$R"/run/joinery/data-root-key.* 2>/dev/null | wc -l)|$(grep -c '^backing=file$' "$C")"
}
cp "$T/bin/fallocate" "$T/fallocate.ok"
stub fallocate 'echo "fallocate $*" >> "$GATE_LOG"; exit 1'
held_binds; touch "$T/mounted"; : > "$LOG"
out="$(run encrypt 2>&1)"; rc=$?
chk "a step that fails after the stop: exit 1, the data root and its services started again, nothing kept" \
    "$rc|$(start_undone "$out")" "1|1|1|1|none|0|1"
stub fallocate 'kill -TERM $PPID; exit 1'
held_binds; touch "$T/mounted"; : > "$LOG"
out="$(run encrypt 2>&1)"; rc=$?
chk "a TERM part-way (whatever ran it was stopped): the same, exit 143" "$rc|$(start_undone "$out")" "143|1|1|1|none|0|1"
cp "$T/fallocate.ok" "$T/bin/fallocate"
cp "$T/bin/cryptsetup" "$T/cryptsetup.ok"
stub cryptsetup 'echo "cryptsetup $*" >> "$GATE_LOG"; [ "$1" = reencrypt ] && exit 1; exit 0'
held_binds; touch "$T/mounted"; : > "$LOG"
out="$(run encrypt 2>&1)"; rc=$?
chk "cryptsetup's start fails: its loop device let go, a header it left undone, then the same" \
    "$rc|$(awk '/^cryptsetup reencrypt/ { f = 1 } f && /^losetup -d \/dev\/loop7$/ { n++ } END { print n + 0 }' "$LOG")|$(grep -c '^cryptsetup luksDump' "$LOG")|$(start_undone "$out")" "1|1|1|1|1|1|none|0|1"
# The TERM that lands while cryptsetup's start runs waits for it to end, and it
# ends with the volume open: the mapping goes before its loop device can (B49).
stub cryptsetup 'echo "cryptsetup $*" >> "$GATE_LOG"; case "$1" in reencrypt) mkdir -p "$GATE_T/root/dev/mapper"; : > "$GATE_T/root/dev/mapper/joinery-data"; kill -TERM $PPID ;; close) rm -f "$GATE_T/root/dev/mapper/joinery-data" ;; esac; exit 0'
held_binds; touch "$T/mounted"; rm -f "$R/dev/mapper/joinery-data"; : > "$LOG"
out="$(run encrypt 2>&1)"; rc=$?
chk "a TERM while cryptsetup's start opens the volume: the mapping closed before its loop device is let go, then the same (B49)" \
    "$rc|$(awk '/^cryptsetup reencrypt/ { f = 1 } f && /^cryptsetup close joinery-data$/ && !c { c = NR } f && /^losetup -d \/dev\/loop7$/ && !l { l = NR } END { print (c && l && c < l) ? "close,detach" : "close=" c " detach=" l }' "$LOG")|$( [ -e "$R/dev/mapper/joinery-data" ] && echo open || echo closed)|$(start_undone "$out")" \
    "143|close,detach|closed|1|1|1|none|0|1"
cp "$T/cryptsetup.ok" "$T/bin/cryptsetup"
truncate -s $(( 8 * G )) "$R/srv/joinery.img"
held_binds; touch "$T/mounted"
printf 'url=https://holder.example\nslot=0\n' > "$R/etc/joinery/data-root/holder"
# What opened a volume earlier this boot (a decrypt before this fix left it).
mkdir -p "$R/run/joinery"; echo 'the escrow holder, https://holder.example, at 2026-10-14 17:31:22 UTC' > "$R/run/joinery/data-root.opened-by"
: > "$LOG"
out="$(run encrypt 2>&1)"; rc=$?
chk "a service that keeps a file on a bind: stopped for the start, the bind unmounted, started again after" \
    "$(grep -c 'rsyslog.service keep a file on it' <<< "$out")|$(grep -c '^systemctl stop rsyslog.service$' "$LOG")|$(grep -c '^systemctl stop var-spool-postfix.mount$' "$LOG")|$(grep -c '^systemctl start .*rsyslog.service' "$LOG")" "1|1|1|1"
chk "but never the service this run is part of (B42)" "$(grep -c 'joinery-host-converger' "$LOG")|$(grep -c 'joinery-host-converger' <<< "$out")" "0|0"
cp "$T/systemctl.d5" "$T/bin/systemctl"; rm -f "$T/bin/fuser"; rm -rf "$R/proc"
chk "encrypt: exit 0, and says it encrypts in the background" "$rc|$(grep -c 'encrypting in the background' <<< "$out")" "0|1"
chk "encrypt: says the services stop again when it finishes (B59)" "$(grep -c 'When it finishes, the services stop again' <<< "$out")" "1"
chk "the volume it opened is recorded as opened by its encryption, not by what opened one earlier (B68)" \
    "$(grep -c '^its encryption, at 20[0-9-]* [0-9:]* UTC$' "$R/run/joinery/data-root.opened-by")|$(grep -c escrow "$R/run/joinery/data-root.opened-by")" "1|0"
chk "the escrow holder and slot an earlier encryption recorded: gone with the old header (B55)" \
    "$( [ -e "$R/etc/joinery/data-root/holder" ] && echo kept || echo gone)" "gone"
chk "the image grows by the 32 MiB the header takes, which XFS never sees" "$(grep -c "^fallocate -l $(( 8 * G + 32 * 1024 * 1024 )) $R/srv/joinery.img$" "$LOG")" "1"
chk "both keys sealed before the volume is written: the volume key, then the driving slot's" \
    "$(grep -n -e '^agent data-root seal' -e '^cryptsetup reencrypt' "$LOG" | cut -d: -f2 | cut -d' ' -f1-4 | paste -sd,)" \
    "agent data-root seal --recipient,agent data-root seal --recipient,cryptsetup reencrypt --encrypt --init-only"
chk "LUKS2 started in place: the filesystem's own 512-byte sectors, the standard slot, 32 MiB reduced, no keyring" \
    "$(grep -c -- '^cryptsetup reencrypt --encrypt --init-only --batch-mode --disable-keyring --type luks2 --cipher aes-xts-plain64 --key-size 512 --sector-size 512 --pbkdf pbkdf2 --hash sha256 --pbkdf-force-iterations 1000000 --reduce-device-size 33554432 --volume-key-file .*/volume.key --key-slot 0 --key-file .*/slot.key /dev/loop7 joinery-data$' "$LOG")" "1"
chk "recorded: luks-file, encrypting, its driving slot and that slot's sealed copy" \
    "$(grep -c -e '^backing=luks-file$' -e '^reencrypt=encrypt$' -e '^driving_slot=0$' -e '^driving_sidecar=/etc/joinery/data-root/driving-slot.recovery$' "$C")" "4"
chk "the fstab line is gone: the mount is a unit of its own now" "$(grep -c joinery.img "$R/etc/fstab")" "0"
chk "the run's unit: never SIGKILLed, never the OOM killer's, after the data root but never requiring it" \
    "$(grep -c -e '^SendSIGKILL=no$' -e '^OOMScoreAdjust=-1000$' -e '^IOSchedulingClass=idle$' -e '^After=joinery-data.target$' "$SD/joinery-data-encrypt.service")|$(grep -c '^Requires=' "$SD/joinery-data-encrypt.service")" "4|0"
chk "and it is started, without waiting" "$(grep -c '^systemctl start --no-block joinery-data-encrypt.service$' "$LOG")" "1"
chk "the driving secret is kept in /run for the run" "$( [ -s "$R/run/joinery/data-root-encrypt/slot.key" ] && echo kept)" "kept"
rm -f "$R/run/joinery/data-root-encrypt.progress"; mkdir -p "$R/dev/mapper"; : > "$R/dev/mapper/joinery-data"
chk "status before cryptsetup's first progress line: starting, not a question mark (B27)" \
    "$(run status 2>&1 | grep -c "running: encryption, starting, at idle")" "1"
rm -f "$R/dev/mapper/joinery-data"
out="$(run encrypt 2>&1)"; rc=$?
chk "a second encrypt: refused, it is running" "$rc|$(grep -c 'encrypted already' <<< "$out")" "1|1"
: > "$LOG"; run tick >/dev/null 2>&1
chk "no growth while it runs: the table is the run's" "$(grep -c -e '^fallocate' -e '^dmsetup reload' "$LOG")" "0"
# A boot that opened the volume and never started the run (T-F2, after a cut in a decrypt).
mkdir -p "$R/dev/mapper"; : > "$R/dev/mapper/joinery-data"; cp "$T/bin/systemctl" "$T/systemctl.run"
stub systemctl 'echo "systemctl $*" >> "$GATE_LOG"; [ "$2" = is-active ] && exit 3; exit 0'
: > "$LOG"; out="$(run tick 2>&1)"
chk "a recorded run that is not running: the tick starts it again" \
    "$(grep -c '^systemctl start --no-block joinery-data-encrypt.service$' "$LOG")|$(grep -c 'encryption was not running; started again' <<< "$out")" "1|1"
cp "$T/systemctl.run" "$T/bin/systemctl"; : > "$LOG"; run tick >/dev/null 2>&1
chk "and leaves a running one alone" "$(grep -c '^systemctl start --no-block joinery-data-encrypt.service$' "$LOG")" "0"
rm -f "$R/dev/mapper/joinery-data"

echo "--- an encrypt cut off in its start, after cryptsetup wrote the header (T-F2) ---"
# The header a power cut left on a 1 GB Linode, 2026-10-12: cryptsetup had moved the first
# 16 MiB to the room at the end and written the header; nothing was encrypted.
cat > "$T/cut_dump.txt" <<'DUMP'
LUKS header information
Version:       	2
Epoch:         	4
Metadata area: 	16384 [bytes]
Keyslots area: 	16744448 [bytes]
UUID:          	5b264164-ba43-42ea-88d7-09410efd180a
Label:         	(no label)
Subsystem:     	(no subsystem)
Flags:       	(no flags)
Requirements:	online-reencrypt-v2 

Data segments:
  0: linear
	offset: 11827937280 [bytes]
	length: 16777216 [bytes]
	cipher: (no SW encryption)

  1: linear
	offset: 16777216 [bytes]
	length: 11794382848 [bytes]
	cipher: (no SW encryption)

  2: linear
	offset: 11827937280 [bytes]
	length: 16777216 [bytes]
	cipher: (no SW encryption)
	flags : backup-moved-segment

  3: linear
	offset: 0 [bytes]
	length: 11811160064 [bytes]
	cipher: (no SW encryption)
	flags : backup-previous

  4: crypt
	offset: 16777216 [bytes]
	length: (whole device)
	cipher: aes-xts-plain64
	sector: 512 [bytes]
	flags : backup-final

Keyslots:
  1: reencrypt (unbound)
	Key:        8 bits
	Priority:   ignored
	Mode:       encrypt
	Direction:  backward
	Resilience: datashift
	Shift size: 16777216[bytes]
	Area offset:290816 [bytes]
	Area length:4096 [bytes]
	Digest ID:  1
Tokens:
DUMP
cp "$C" "$T/conf.running"; cp "$T/bin/cryptsetup" "$T/cryptsetup.ok"
printf 'backing=file\nsource=/srv/joinery.img\n' > "$C"
printf '%s\n/srv/joinery.img /srv/joinery xfs loop,prjquota,nofail 0 0\n' '# joinery-data-root (joinery_data_root.sh)' > "$R/etc/fstab"
rm -f "$T/mounted"; truncate -s 11844714496 "$R/srv/joinery.img"; figs data 11*G 1*G 10*G
: > "$R/etc/joinery/data-root/driving-slot.recovery"; : > "$R/srv/joinery.img.recovery"
stub dd 'echo "dd $*" >> "$GATE_LOG"'
stub cryptsetup 'echo "cryptsetup $*" >> "$GATE_LOG"; case "$1" in isLuks) exit 1 ;; esac; exit 0'
: > "$LOG"; run tick >/dev/null 2>&1
chk "cut off before the header: nothing to undo, and the data root started again (B42)" \
    "$(grep -c '^dd ' "$LOG")|$(stat -c %s "$R/srv/joinery.img")|$(grep -c '^systemctl start joinery-data.target$' "$LOG")" "0|11844714496|1"
rm -f "$T/mounted"
stub cryptsetup 'echo "cryptsetup $*" >> "$GATE_LOG"; case "$1" in isLuks) exit 0 ;; luksDump) cat "$GATE_T/cut_dump.txt" ;; esac; exit 0'
: > "$LOG"; out="$(run tick 2>&1)"
chk "cut off after the header: the moved 16 MiB goes back over it" \
    "$(grep -c "^dd if=$R/srv/joinery.img of=$R/srv/joinery.img bs=1M iflag=skip_bytes,count_bytes oflag=seek_bytes conv=notrunc,fsync skip=11827937280 seek=0 count=16777216 status=none$" "$LOG")" "1"
chk "the image back to its length, the record still plain, its sealed copies gone, mounted again" \
    "$(stat -c %s "$R/srv/joinery.img")|$(grep -c '^backing=file$' "$C")|$(ls "$R/etc/joinery/data-root/driving-slot.recovery" "$R/srv/joinery.img.recovery" 2>/dev/null | wc -l)|$(grep -c '^systemctl start joinery-data.target$' "$LOG")|$(grep -c 'start is undone' <<< "$out")" \
    "11811160064|1|0|1|1"
sed 's/^  1: linear$/  1: crypt/' "$T/cut_dump.txt" > "$T/cut_dump2.txt"
stub cryptsetup 'echo "cryptsetup $*" >> "$GATE_LOG"; case "$1" in isLuks) exit 0 ;; luksDump) cat "$GATE_T/cut_dump2.txt" ;; esac; exit 0'
truncate -s 11844714496 "$R/srv/joinery.img"; rm -f "$T/mounted"; : > "$LOG"; out="$(run tick 2>&1)"
chk "a header with data encrypted already: left for a person, nothing written" "$(grep -c '^dd ' "$LOG")|$(grep -c 'left as it is for a person' <<< "$out")" "0|1"
rm -f "$T/bin/dd"; cp "$T/conf.running" "$C"; cp "$T/cryptsetup.ok" "$T/bin/cryptsetup"; touch "$T/mounted"

echo "--- each site's config/ onto the encrypted data root, once its encryption is done (D9) ---"
touch "$T/hide_on_mount"; rm -rf "${T:?}/hidden"
stub fstrim 'echo "fstrim $*" >> "$GATE_LOG"'
B="$R/etc/joinery/data_binds"
S=/var/www/html/encsite
mkdir -p "$R/dev/mapper"; : > "$R/dev/mapper/joinery-data"
rm -f "$SD/tmp.mount"
mkdir -p "$R/var/log/apt" "$R/var/lib/php/sessions" "$R/etc/php/8.3/fpm/conf.d" "$R/usr/lib/systemd/system"
printf '[Unit]\nDescription=Record System Boot/Shutdown in UTMP\nDefaultDependencies=no\nRequiresMountsFor=/var/log/wtmp\nBefore=sysinit.target\n\n[Service]\nType=oneshot\nExecStart=/usr/lib/systemd/systemd-update-utmp reboot\n' > "$R/usr/lib/systemd/system/systemd-update-utmp.service"
printf '[Unit]\nDescription=Flush Journal to Persistent Storage\nDefaultDependencies=no\nRequiresMountsFor=/var/log/journal /var/lib/x\n\n[Service]\nExecStart=journalctl --flush\n' > "$R/usr/lib/systemd/system/systemd-journal-flush.service"
echo boot > "$R/var/log/syslog"; echo history > "$R/var/log/apt/history.log"; echo s > "$R/var/lib/php/sessions/sess_1"
mkdir -p "$R$S/config/backup-ledger"
printf '<?php\n' > "$R$S/config/Globalvars_site.php"; echo key > "$R$S/config/backup_site_key"; echo l > "$R$S/config/backup-ledger/a"
: > "$LOG"; JOINERY_TEST_ROOT_VIEW="$T/hidden" run tick >/dev/null 2>&1
chk "while it encrypts, config/ stays where it is" "$(cat "$B" 2>/dev/null | grep -c " $S/config$")|$(cat "$R$S/config/backup_site_key")" "0|key"
sed -i 's/^reencrypt=.*/reencrypt=/' "$C"
out="$(JOINERY_TEST_ROOT_VIEW="$T/hidden" run tick 2>&1)"
chk "once it is done, the tick moves it onto the data root, recorded as a bind" \
    "$(grep -c "^sites/encsite/config $S/config$" "$B")|$(cat "$R/srv/joinery/sites/encsite/config/backup_site_key")|$(cat "$R/srv/joinery/sites/encsite/config/backup-ledger/a")" "1|key|l"
chk "seen at its path through the mount" "$(cat "$R$S/config/Globalvars_site.php")" "<?php"
m="$(stat -c %a "$T/hidden$S/config")"; chmod 700 "$T/hidden$S/config"
chk "the root disk's copy deleted, and the folder left a locked mountpoint" "$m|$(ls -A "$T/hidden$S/config" | wc -l)" "0|0"
chk "the freed blocks handed back to the provider" "$(grep -c '^fstrim' "$LOG")" "1"
echo "--- the rest of the root disk (D10) ---"
# Read, not run: the swap rule acts only on a real machine (no fixture root).
chk "a plain swap device is let go, an encrypted one and compressed memory (zram) never (B32)" \
    "$(grep -c '/dev/dm-\*|/dev/mapper/\*|/dev/zram\*) continue' "$SCRIPT")" "1"
chk "/var/log, PHP's sessions and temporary files moved onto the data root" \
    "$(grep -c -e '^log /var/log$' -e '^php/sessions /var/lib/php/sessions$' -e '^php/tmp /var/lib/php/tmp$' "$B")|$(cat "$R/srv/joinery/log/syslog")|$(cat "$R/srv/joinery/log/apt/history.log")|$(cat "$R/srv/joinery/php/sessions/sess_1")" "3|boot|history|s"
chk "no DKIM keys here, so no move for them" "$(grep -c 'mail/dkim' "$B")" "0"
chk "the journal flushed onto it once /var/log is mounted, at each boot, and let go before it unmounts" \
    "$(grep -c -e '^Requires=var-log.mount$' -e '^ExecStart=/usr/bin/journalctl --flush$' -e '^WantedBy=joinery-data.target$' -e '^RemainAfterExit=yes$' -e '^ExecStop=/usr/bin/journalctl --smart-relinquish-var$' "$SD/joinery-journal-flush.service")|$(grep -q '^systemctl enable joinery-journal-flush.service$' "$LOG" && echo enabled)" "5|enabled"
chk "the boot record is written without waiting for /var/log (no ordering cycle with an unlock that waits)" \
    "$(head -n 1 "$SD/systemd-update-utmp.service" | grep -c 'joinery_data_root.sh')|$(grep -c '^RequiresMountsFor' "$SD/systemd-update-utmp.service")|$(grep -c '^ExecStart=/usr/lib/systemd/systemd-update-utmp reboot$' "$SD/systemd-update-utmp.service")" "1|0|1"
chk "the boot's own journal flush keeps its other mounts, and waits for /var/log to be the data root" \
    "$(grep -c '^RequiresMountsFor=/var/lib/x$' "$SD/systemd-journal-flush.service")|$(grep -c '^ConditionPathIsMountPoint=/var/log$' "$SD/systemd-journal-flush.service")" "1|1"
chk "room in memory for the journal of a boot that waits" "$(grep -c '^RuntimeMaxUse=32M$' "$R/etc/systemd/journald.conf.d/joinery-data-root.conf")" "1"
chk "rsyslog and fail2ban wait for the data root" \
    "$(grep -h -c '^Requires=joinery-data.target$' "$SD/rsyslog.service.d/joinery-data-root.conf" "$SD/fail2ban.service.d/joinery-data-root.conf" | paste -sd,)" "1,1"
chk "no core dumps: none in systemd, none from the kernel" \
    "$(grep -c '^DefaultLimitCORE=0$' "$R/etc/systemd/system.conf.d/joinery-data-root.conf")|$(grep -c '^Storage=none$' "$R/etc/systemd/coredump.conf.d/joinery-data-root.conf")|$(grep -c '^kernel.core_pattern=|/bin/false$' "$R/etc/sysctl.d/60-joinery-data-root.conf")" "1|1|1"
chk "/tmp in memory from the next boot (enabled, not started)" \
    "$(grep -c -e '^What=tmpfs$' -e '^Where=/tmp$' -e '^Options=mode=1777,strictatime,nosuid,nodev,size=50%%,nr_inodes=1m$' "$SD/tmp.mount")|$(grep -c '^systemctl enable tmp.mount$' "$LOG")|$(grep -c '^systemctl start tmp.mount' "$LOG")" "3|1|0"
chk "PHP's temporary files go to the data root, and PHP-FPM is reloaded for it" \
    "$(grep -c -e '^upload_tmp_dir = /var/lib/php/tmp$' -e '^sys_temp_dir = /var/lib/php/tmp$' "$R/etc/php/8.3/fpm/conf.d/99-joinery-data-root.ini")|$(grep -c '^systemctl try-reload-or-restart php8.3-fpm.service$' "$LOG")" "2|1"
chk "the root disk's /var/log underneath stays writable, for the boot's early writers" "$(stat -c %a "$T/hidden/var/log")" "$(stat -c %a "$R/srv/joinery/log")"
sed -i 's/^backing=luks-file$/backing=file/' "$C"; rm -f "$R/dev/mapper/joinery-data"; run tick >/dev/null 2>&1
chk "decrypted, /var/log still on the data root: rsyslog still waits for it and journald lets go of it; the early-boot copies go" \
    "$( [ -f "$SD/rsyslog.service.d/joinery-data-root.conf" ] && echo waits)|$( [ -f "$SD/joinery-journal-flush.service" ] && echo flush)|$( [ -f "$SD/systemd-update-utmp.service" ] && echo copy || echo package)" "waits|flush|package"
sed -i 's/^backing=file$/backing=luks-file/' "$C"; : > "$R/dev/mapper/joinery-data"; run tick >/dev/null 2>&1
chk "encrypted again: the early-boot copies are back" "$(head -n 1 "$SD/systemd-update-utmp.service" | grep -c 'joinery_data_root.sh')" "1"
echo "boot record" > "$T/hidden/var/log/wtmp"; echo "early" > "$T/hidden/var/log/cloud-init.log"; : > "$T/hidden/var/log/btmp"
rm -f "$R/run/joinery/data-root.log-swept"; : > "$LOG"
out="$(JOINERY_TEST_ROOT_VIEW="$T/hidden" run tick 2>&1)"
chk "at the next unlock, what the boot wrote there is added to the data root's logs and deleted, once a boot" \
    "$(cat "$R/srv/joinery/log/wtmp")|$(cat "$R/srv/joinery/log/cloud-init.log")|$(ls -A "$T/hidden/var/log" | wc -l)|$(grep -c 'before the unlock are on the data root' <<< "$out")|$(grep -c '^fstrim' "$LOG")" "boot record|early|0|1|1"
echo "again" > "$T/hidden/var/log/wtmp"
JOINERY_TEST_ROOT_VIEW="$T/hidden" run tick >/dev/null 2>&1
chk "and not again in the same boot" "$(cat "$T/hidden/var/log/wtmp")" "again"
rm -f "$T/hidden/var/log/wtmp"
: > "$LOG"; out="$(JOINERY_TEST_ROOT_VIEW="$T/hidden" run tick 2>&1)"
chk "the next tick: nothing more to move" "$(grep -c 'deleted' <<< "$out")|$(grep -c '^fstrim' "$LOG")" "0|0"
out="$(JOINERY_TEST_ROOT_VIEW="$T/hidden" run move-in sites/encsite/config "$S/config" 2>&1)"; rc=$?
chk "move-in by hand once it is done: it only mounts it" "$rc|$(grep -c deleted <<< "$out")" "0|0"
mkdir -p "$R/var/www/html/other/config" "$R/srv/joinery/sites/other/config"
echo x > "$R/var/www/html/other/config/f"; echo y > "$R/srv/joinery/sites/other/config/g"
out="$(run move-in sites/other/config /var/www/html/other/config 2>&1)"; rc=$?
chk "move-in to a place that holds data already: refused, nothing changed" \
    "$rc|$(grep -c 'holds data already' <<< "$out")|$(cat "$R/var/www/html/other/config/f")|$(cat "$B" | grep -c other)" "1|1|x|0"
rm -rf "${R:?}/var/www/html/other" "${R:?}/srv/joinery/sites/other" "${T:?}/hide_on_mount" "${R:?}/dev/mapper/joinery-data" "${R:?}/etc/php"
sed -i 's/^reencrypt=.*/reencrypt=encrypt/' "$C"

echo "--- a decrypt that finishes (B68) ---"
sed -i 's/^reencrypt=.*/reencrypt=decrypt/' "$C"; echo 'tpm2=off' >> "$C"
mkdir -p "$R/run/joinery"; echo 'the escrow holder, https://holder.example, at 2026-10-14 17:31:22 UTC' > "$R/run/joinery/data-root.opened-by"
cp "$T/bin/blkid" "$T/blkid.ok"
stub blkid 'case "$*" in "-p -s TYPE -o value "*) echo xfs ;; *) exec "$GATE_T/blkid.ok" "$@" ;; esac'
rm -f "$R/dev/mapper/joinery-data"; touch "$T/mounted"; : > "$LOG"
out="$(run encrypt-resume 2>&1)"; rc=$?
chk "plain again, and the record of what opened the volume gone with the volume" \
    "$rc|$(grep -c '^backing=file$' "$C")|$(grep -c 'decrypted, and mounted as it was' <<< "$out")|$( [ -e "$R/run/joinery/data-root.opened-by" ] && echo kept || echo gone)" "0|1|1|gone"
chk "and the TPM2 choice goes with the encryption (B43)" "$(grep -c '^tpm2=' "$C")" "0"
cp "$T/blkid.ok" "$T/bin/blkid"

echo "--- a create that does not finish leaves nothing ---"
rm -rf "${R:?}/etc/joinery/data_root" "${R:?}/srv/joinery.img" "${R:?}/srv/joinery.img.recovery" "${R:?}/srv/joinery" "${SD:?}" "${R:?}/run" "${R:?}/dev/mapper"
unmount; touch "$T/seal_fails"; : > "$LOG"
out="$(run create --encrypted 16G 2>&1)"; rc=$?
chk "the seal fails: exit 1, no volume written, nothing left" \
    "$rc|$(grep -c 'could not seal' <<< "$out")|$(grep -c luksFormat "$LOG")|$( [ -e "$R/srv/joinery.img" ] && echo img)$( [ -e "$C" ] && echo conf)$( [ -e "$R/srv/joinery.img.recovery" ] && echo sidecar)|$(ls "$R/run/joinery" 2>/dev/null | grep -c data-root-key)" "1|1|0||0"
rm -f "$T/seal_fails"
echo "--- born encrypted on a machine with a chip, never to use it (B43) ---"
rm -rf "${R:?}/etc/joinery/data_root" "${R:?}/srv/joinery.img" "${R:?}/srv/joinery.img.recovery" "${R:?}/srv/joinery" "${SD:?}" "${R:?}/run" "${R:?}/dev/mapper"
unmount; mkdir -p "$R/dev"; : > "$R/dev/tpmrm0"; : > "$LOG"
out="$(run create --encrypted --no-tpm2 16G 2>&1)"; rc=$?
chk "create --encrypted --no-tpm2: no chip enrolled, recorded tpm2=off, and said" \
    "$rc|$(grep -c cryptenroll "$LOG")|$(grep -c '^tpm2=off$' "$C")|$(grep -c 'has a chip, and it is not used' <<< "$out")" "0|0|1|1"
rm -f "$R/dev/tpmrm0"
cp "$T/systemctl.plain" "$T/bin/systemctl"

echo
chk "the log move never restarts a unit whose restart stops every site (podman-restart.service, docker.service; WP8 review G8)" \
    "$(sed -n '/^reopen_logs() {/,/^}/p' "$SCRIPT" | grep -c '^ *podman-restart.service|docker.service)$')|$(sed -n '/^reopen_logs() {/,/^}/p' "$SCRIPT" | grep -c 'and is not restarted (that would stop every site)')" "1|1"

echo "RESULT: ${passed} passed, ${failed} failed"
[ "$failed" -eq 0 ]
