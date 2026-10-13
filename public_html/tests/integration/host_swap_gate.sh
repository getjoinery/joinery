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
#   - swap the platform did not make is left alone.

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
fi; exit 0'
stub swapoff 'echo "swapoff $*" >> "$GATE_LOG"; sed -i "\#^/dev/dm-0 #d" "$GATE_T/root/proc/swaps"'
stub sleep ':'
stub systemd-detect-virt 'exit 1'

fixture() {  # USED_KB AVAIL_KB
    rm -rf "$T/root" "$T/pkg_installed" "$T/zram_fails" "$T/apt_fails"; : > "$LOG"
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
chk "half the memory up to 4 GiB, zstd, ahead of any other swap" \
    "$(grep -c -e '^zram-size = min(ram / 2, 4096)$' -e '^compression-algorithm = zstd$' -e '^swap-priority = 100$' "$T/root/etc/systemd/zram-generator.conf")" "3"
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

echo "=== swap the platform did not make is left alone ==="
fixture 256 500000
printf 'myswap /dev/sdb /dev/urandom swap\n' > "$T/root/etc/crypttab"
out="$(run 2>&1)"
chk "zram on, the other swap untouched" "$(grep -c '^swapoff' "$LOG")|$(grep -c myswap "$T/root/etc/crypttab")|$( [ -e "$T/root/swapfile" ] && echo file)" "0|1|file"

echo
echo "RESULT: $( [ "$failed" -eq 0 ] && echo PASS || echo FAIL ) $passed $failed"
[ "$failed" -eq 0 ]
