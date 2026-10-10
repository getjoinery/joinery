#!/bin/bash
# @joinery-test
# name: joinery_data_root
# tier: safe
# env: any
# needs: []
# timeout: 120
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
#   - bind / unbind (WP2, D1): a path is a bind mount of its place under the
#     data root, recorded, its unit written and required by the target; a new
#     place takes the path's mode; a path holding data is refused with exit 3
#     and nothing changed; one path, one place. check fails while a recorded
#     bind is not in place and names it; tick mounts it again. unbind leaves
#     the data, and only this script's mount units are ever removed.
#   - migrate (WP3, D5): refuses, naming every reason and changing nothing, a
#     symlinked folder, a cluster outside /var/lib/postgresql, a root disk that
#     cannot hold the data twice and a site that is upgrading; otherwise makes
#     the data root, stops the running consumers and cron, copies each place
#     (symlinks and modes kept), switches each path to a mount, keeps the copy
#     from before at /srv/joinery.old until a tick on a later boot, starts the
#     services again, and moves Docker's data-root by daemon.json. A move whose
#     mount fails puts every folder back and starts the services. Run again, it
#     moves only what is not moved.
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

figs() { echo "$(( $2 )) $(( $3 )) $(( $4 ))" > "$T/fig_$1"; }
run() { PATH="$T/bin:$PATH" JOINERY_HOST_ROOT="$T/root" bash "$SCRIPT" "$@"; }
unmount() { rm -f "$T/mounted"; }

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

echo "=== create: a file of the first size D7 gives ==="
: > "$T/root/lib/systemd/system/php8.5-fpm.service"
out="$(run create 2>&1)"; rc=$?
chk "exit 0" "$rc" "0"
chk "first size: a quarter of the root disk, 25 GiB (above 16)" \
    "$(grep -c "^fallocate -l $((25 * G)) $T/root/srv/joinery.img$" "$LOG")" "1"
chk "formatted XFS with ftype=1" "$(grep -c "^mkfs.xfs -q -n ftype=1 $T/root/srv/joinery.img$" "$LOG")" "1"
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
touch "$T/mounted"

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
# one unit named in bind_fail.
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
unmount; : > "$LOG"
out="$(run create /dev/sdb 2>&1)"; rc=$?
chk "create on a device: exit 0, formatted, recorded with its UUID" \
    "$rc|$(grep -c '^mkfs.xfs -q -n ftype=1 /dev/sdb$' "$LOG")|$(grep -c '^backing=device$' "$T/root/etc/joinery/data_root")|$(grep -c '^uuid=0b9c5a6e-1111-4222-8333-944455556666$' "$T/root/etc/joinery/data_root")" "0|1|1|1"
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
chk "once the provider has grown it, the filesystem follows" "$(grep -c "^xfs_growfs $T/root/srv/joinery$" "$LOG")" "1"
out="$(run grow 90G 2>&1)"; rc=$?
chk "grow SIZE on a device is refused: the provider grows it" "$rc|$(grep -c 'when its provider grows the device' <<< "$out")" "1|1"

echo
echo "RESULT: ${passed} passed, ${failed} failed"
[ "$failed" -eq 0 ]
