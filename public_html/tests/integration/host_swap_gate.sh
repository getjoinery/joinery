#!/bin/bash
# @joinery-test
# name: host_swap
# tier: safe
# env: any
# needs: []
# timeout: 60
# covers: [maintenance_scripts/install_tools/host_swap.sh]
#
# The host's swap is compressed memory (zram), never a disk: swap through
# dm-crypt over a loop device over a file locked a 1 GB machine up under
# memory pressure (B32). Against a scratch root with the system's commands
# stubbed:
#
#   - not root and no fixture: skipped; in a container: nothing at all.
#   - the generator package installed first, keeping our config where the two
#     meet; the config written; the device's swap unit started.
#   - with zram on, the platform's encrypted swap file is switched off and its
#     crypttab and fstab lines and the file go; a second run says nothing.
#   - memory that cannot take back what the file holds: kept, said.
#   - zram that does not start: the file is kept.
#   - encrypted swap someone else made (crypttab) is left alone.
#   - plain swap goes: a partition named by UUID in fstab, a swap file, and an
#     fstab line for a plain device that is not on now; each zeroed, made swap
#     again under its own UUID, its line behind the marker, its unit masked;
#     device-mapper swap and swap with no room are kept; a second run is quiet.

set -u
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCRIPT="$(cd "$HERE/../../.." && pwd)/maintenance_scripts/install_tools/host_swap.sh"

passed=0; failed=0
chk() {
    if [ "$2" = "$3" ]; then echo "  PASS: $1"; passed=$((passed + 1))
    else echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed + 1)); fi
}

if [ "$(id -u)" = "0" ]; then echo "  SKIP: this gate runs unprivileged"; echo "RESULT: PASS 0 0"; exit 0; fi

T="$(mktemp -d)"
trap 'rm -rf "$T"' EXIT
LOG="$T/log"
export GATE_T="$T" GATE_LOG="$LOG"
mkdir -p "$T/bin"
stub() { printf '#!/bin/bash\n%s\n' "$2" > "$T/bin/$1"; chmod 755 "$T/bin/$1"; }
stub dpkg-query '[ -f "$GATE_T/pkg_installed" ] && echo "install ok installed"; exit 0'
stub apt-get 'echo "apt-get $*" >> "$GATE_LOG"; [ -f "$GATE_T/apt_fails" ] && exit 100; [[ "$*" == *install* ]] && touch "$GATE_T/pkg_installed"; exit 0'
# Starting the device's swap unit turns zram on, unless told it will not.
stub systemctl 'echo "systemctl $*" >> "$GATE_LOG"
if [ "$*" = "start dev-zram0.swap" ] && [ ! -f "$GATE_T/zram_fails" ]; then
  printf "/dev/zram0 partition 492012 0 100\n" >> "$GATE_T/root/proc/swaps"
fi
# The swap units the fixture lists (units: UNIT WHAT).
if [ "$1" = list-units ] && [ -f "$GATE_T/units" ]; then awk "{ print \$1 \" loaded active active swap\" }" "$GATE_T/units"; fi
if [ "$1" = show ] && [ -f "$GATE_T/units" ]; then U="${@: -1}" awk "\$1 == ENVIRON[\"U\"] { print \$2 }" "$GATE_T/units"; fi
exit 0'
stub swapoff 'echo "swapoff $*" >> "$GATE_LOG"; d="${1#"$GATE_T/root"}"; [ "$d" = /dev/mapper/cryptswap ] && d=/dev/dm-0; sed -i "\#^$d #d" "$GATE_T/root/proc/swaps"'
# blkid: the fixture's map of tags to devices (blkmap: TAG=VALUE DEVICE UUID LABEL).
stub blkid 'm="$GATE_T/blkmap"; [ -f "$m" ] || exit 2
case "$1" in
  -o) awk -v t="$4" "\$1 == t { print \$2 }" "$m" ;;
  -s) d="${@: -1}"; d="${d#"$GATE_T/root"}"; f=$([ "$2" = UUID ] && echo 3 || echo 4); awk -v d="$d" -v f="$f" "\$2 == d && \$f != \"-\" { print \$f }" "$m" ;;
esac; exit 0'
stub shred 'echo "shred $*" >> "$GATE_LOG"; echo zeroed > "${@: -1}"'
stub mkswap 'echo "mkswap $*" >> "$GATE_LOG"'
stub sleep ':'
stub systemd-detect-virt 'exit 1'

fixture() {  # USED_KB AVAIL_KB
    rm -rf "$T/root" "$T/pkg_installed" "$T/zram_fails" "$T/apt_fails" "$T/blkmap" "$T/units"; : > "$LOG"
    R="$T/root"
    mkdir -p "$R/etc/systemd" "$R/proc" "$R/dev/mapper"
    printf '# <target name>\t<source device>\t<key file>\t<options>\ncryptswap /swapfile /dev/urandom swap,cipher=aes-xts-plain64,size=256,nofail\n' > "$R/etc/crypttab"
    printf 'UUID=abc / ext4 defaults 0 1\n/dev/mapper/cryptswap none swap sw,nofail 0 0\n' > "$R/etc/fstab"
    printf 'Filename\t\t\t\tType\t\tSize\t\tUsed\t\tPriority\n/dev/dm-0 partition 1048572 %s -2\n' "$1" > "$R/proc/swaps"
    printf 'MemTotal: 984560 kB\nMemAvailable: %s kB\n' "$2" > "$R/proc/meminfo"
    : > "$R/dev/dm-0"; ln -s ../dm-0 "$R/dev/mapper/cryptswap"
    : > "$R/swapfile"
}
run() { PATH="$T/bin:$PATH" JOINERY_SWAP_ROOT="$T/root" bash "$SCRIPT" "$@"; }

echo "=== not root, no fixture: skipped ==="
out="$(PATH="$T/bin:$PATH" bash "$SCRIPT" 2>&1)"
chk "says it skips" "$(grep -c 'not root - skipping' <<< "$out")" "1"

echo "=== in a container: nothing ==="
fixture 256 500000; : > "$T/root/.dockerenv"
out="$(run 2>&1)"
chk "no output, no command, nothing written" "${#out}|$(wc -l < "$LOG" | tr -d ' ')|$( [ -e "$T/root/etc/systemd/zram-generator.conf" ] && echo conf || echo none)" "0|0|none"

echo "=== the encrypted swap file becomes zram ==="
fixture 256 500000
out="$(run 2>&1)"
chk "the generator package installed first, keeping our config where the two meet" \
    "$(grep -c 'install -y -qq systemd-zram-generator' "$LOG")|$(grep -c 'force-confold' "$LOG")|$(grep -n -e '^apt-get' -e '^systemctl daemon-reload' "$LOG" | head -1 | cut -d: -f2 | cut -d' ' -f1)" "1|1|apt-get"
chk "the size of memory up to 4 GiB, zstd, ahead of any other swap" \
    "$(grep -c -e '^zram-size = min(ram, 4096)$' -e '^compression-algorithm = zstd$' -e '^swap-priority = 100$' "$T/root/etc/systemd/zram-generator.conf")" "3"
chk "its swap unit started, and said" "$(grep -c '^systemctl start dev-zram0.swap$' "$LOG")|$(grep -c 'compressed memory (zram) is on' <<< "$out")" "1|1"
chk "the file switched off, its lines and the file gone, and said" \
    "$(grep -c "^swapoff $T/root/dev/mapper/cryptswap$" "$LOG")|$(grep -c cryptswap "$T/root/etc/crypttab")|$(grep -c cryptswap "$T/root/etc/fstab")|$(grep -c '^UUID=abc' "$T/root/etc/fstab")|$( [ -e "$T/root/swapfile" ] && echo file || echo gone)|$(grep -c 'retired' <<< "$out")" \
    "1|0|0|1|gone|1"
: > "$LOG"
out="$(run 2>&1)"
chk "a second run says nothing and changes nothing" "${#out}|$(grep -c -e '^apt-get' -e '^swapoff' -e restart "$LOG")" "0|0"

echo "=== memory that cannot take back what the file holds: kept ==="
fixture 900000 500000
out="$(run 2>&1)"
chk "zram on, the file kept and said, nothing removed" \
    "$(grep -c 'kept until a run with room' <<< "$out")|$(grep -c '^swapoff' "$LOG")|$(grep -c cryptswap "$T/root/etc/crypttab")|$( [ -e "$T/root/swapfile" ] && echo file)" "1|0|1|file"

echo "=== zram that does not start: the file is kept ==="
fixture 256 500000; touch "$T/zram_fails"
out="$(run 2>&1)"
chk "warned, the file kept" "$(grep -c 'zram did not start' <<< "$out")|$(grep -c '^swapoff' "$LOG")|$(grep -c cryptswap "$T/root/etc/crypttab")" "1|0|1"

echo "=== the package that will not install: nothing changes ==="
fixture 256 500000; touch "$T/apt_fails"
out="$(run 2>&1)"
chk "warned, no config, the file kept" "$(grep -c "could not install systemd-zram-generator" <<< "$out")|$( [ -e "$T/root/etc/systemd/zram-generator.conf" ] && echo conf || echo none)|$(grep -c cryptswap "$T/root/etc/crypttab")" "1|none|1"

echo "=== encrypted swap someone else made is left alone ==="
fixture 256 500000
printf 'myswap /dev/sdb /dev/urandom swap\n' > "$T/root/etc/crypttab"
out="$(run 2>&1)"
chk "zram on, the other swap untouched" "$(grep -c '^swapoff' "$LOG")|$(grep -c myswap "$T/root/etc/crypttab")|$( [ -e "$T/root/swapfile" ] && echo file)" "0|1|file"

# plainfix: zram already on, the platform's file long gone; then the rows given.
plainfix() {  # AVAIL_KB, then /proc/swaps rows
    fixture 0 "$1"; shift
    R="$T/root"; touch "$T/pkg_installed"
    printf 'UUID=abc / ext4 defaults 0 1\n' > "$R/etc/fstab"; : > "$R/etc/crypttab"; rm -f "$R/swapfile"
    printf '# Written by host_swap.sh. Swap is compressed memory: nothing reaches a disk.\n[zram0]\nzram-size = min(ram, 4096)\ncompression-algorithm = zstd\nswap-priority = 100\n' > "$R/etc/systemd/zram-generator.conf"
    printf 'Filename\t\t\t\tType\t\tSize\t\tUsed\t\tPriority\n/dev/zram0 partition 984560 1000 100\n' > "$R/proc/swaps"
    local row; for row in "$@"; do printf '%s\n' "$row" >> "$R/proc/swaps"; done
}

echo "=== a plain swap partition named by UUID (Linode's swap disk) goes ==="
plainfix 500000 "/dev/sdb partition 524284 150000 -2"
: > "$T/root/dev/sdb"
printf 'UUID=sw1 none swap sw 0 0\n' >> "$T/root/etc/fstab"
printf 'UUID=sw1 /dev/sdb sw1 -\n' > "$T/blkmap"
printf 'dev-disk-by\\x2duuid-sw1.swap /dev/sdb\n' > "$T/units"
out="$(run 2>&1)"
chk "switched off, zeroed, made swap again under its own UUID" \
    "$(grep -c "^swapoff $T/root/dev/sdb$" "$LOG")|$(cat "$T/root/dev/sdb")|$(grep -c "^mkswap -U sw1 $T/root/dev/sdb$" "$LOG")" "1|zeroed|1"
chk "its UUID fstab line behind the marker, the root line untouched" \
    "$(grep -c '^#joinery-host-swap UUID=sw1 none swap sw 0 0$' "$T/root/etc/fstab")|$(grep -c '^UUID=abc / ext4' "$T/root/etc/fstab")" "1|1"
chk "its swap unit masked, and how to restore it said" \
    "$(grep -c '^systemctl mask dev-disk-by\\x2duuid-sw1.swap$' "$LOG")|$(grep -c 'systemctl unmask' <<< "$out")|$(grep -c 'To keep disk swap, encrypt it' <<< "$out")" "1|1|1"
: > "$LOG"
out="$(run 2>&1)"
chk "a second run says nothing and changes nothing" "${#out}|$(grep -c -e '^swapoff' -e '^shred' -e '^mkswap' -e mask "$LOG")" "0|0"

echo "=== a swap file goes, kept as a file ==="
plainfix 500000 "/swap.img file 4194300 1000 -2"
: > "$T/root/swap.img"
printf '/swap.img none swap sw 0 0\n' >> "$T/root/etc/fstab"
out="$(run 2>&1)"
chk "switched off, zeroed in place, its line behind the marker" \
    "$(grep -c "^swapoff $T/root/swap.img$" "$LOG")|$(cat "$T/root/swap.img")|$(grep -c '^#joinery-host-swap /swap.img' "$T/root/etc/fstab")" "1|zeroed|1"

echo "=== device-mapper swap is kept, plain or not ==="
plainfix 500000 "/dev/dm-5 partition 524284 1000 -2"
: > "$T/root/dev/dm-5"; ln -s ../dm-5 "$T/root/dev/mapper/vg-swap"
printf '/dev/mapper/vg-swap none swap sw 0 0\n' >> "$T/root/etc/fstab"
out="$(run 2>&1)"
chk "untouched: no swapoff, its line as it was" "$(grep -c -e '^swapoff' -e '^shred' "$LOG")|$(grep -c '^/dev/mapper/vg-swap' "$T/root/etc/fstab")" "0|1"

echo "=== plain swap memory cannot take back: kept ==="
plainfix 500000 "/dev/sdb partition 524284 400000 -2"
: > "$T/root/dev/sdb"; printf '/dev/sdb none swap sw 0 0\n' >> "$T/root/etc/fstab"
out="$(run 2>&1)"
chk "kept and said, its line as it was" "$(grep -c 'kept until a run with room' <<< "$out")|$(grep -c '^swapoff' "$LOG")|$(grep -c '^/dev/sdb none swap' "$T/root/etc/fstab")" "1|0|1"

echo "=== a plain fstab swap line that is not on now ==="
plainfix 500000
: > "$T/root/dev/sdc"; printf '/dev/sdc none swap sw 0 0\n' >> "$T/root/etc/fstab"
out="$(run 2>&1)"
chk "behind the marker so it stays off at boot, nothing switched off" \
    "$(grep -c '^#joinery-host-swap /dev/sdc' "$T/root/etc/fstab")|$(grep -c '^swapoff' "$LOG")|$(grep -c 'stays off at boot' <<< "$out")" "1|0|1"

echo "=== zram not on: plain swap is never touched ==="
plainfix 500000 "/dev/sdb partition 524284 1000 -2"
sed -i '/zram0/d' "$T/root/proc/swaps"; touch "$T/zram_fails"
: > "$T/root/dev/sdb"; printf '/dev/sdb none swap sw 0 0\n' >> "$T/root/etc/fstab"
out="$(run 2>&1)"
chk "the disk swap stays: no swapoff, no zeroing" "$(grep -c -e '^swapoff' -e '^shred' "$LOG")|$(grep -c '^/dev/sdb none swap' "$T/root/etc/fstab")" "0|1"

echo
echo "RESULT: $( [ "$failed" -eq 0 ] && echo PASS || echo FAIL ) $passed $failed"
[ "$failed" -eq 0 ]
