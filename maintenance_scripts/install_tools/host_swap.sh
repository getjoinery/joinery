#!/usr/bin/env bash
#
# host_swap.sh - this host's swap: compressed memory (zram), never a disk.
#
# Version: 1.0 - Swap moves from an encrypted file to zram. Swap through dm-crypt over a loop
#                device over a file on ext4 (the /swapfile every install made) locks a 1 GB machine
#                up under memory pressure: writing a page out needs page cache for the loop's file,
#                which is the memory reclaim is trying to free. node-n stopped answering 11 s into a
#                memory test (2026-10-13) and never came back; its journal, on the data root, stopped
#                with it. zram keeps swapped pages compressed in memory: nothing reaches a disk, so
#                nothing needs encrypting (VaultHealth counts it as safe), and nothing writes through
#                a filesystem to free memory.
#
# A core installer (every site, every converge) and a host installer (a machine
# with no site). In a container it does nothing: the host owns swap.
#
#   1. zram: systemd-zram-generator, half the memory up to 4 GiB, zstd, ahead
#      of any other swap. Started now, and at every boot.
#   2. The encrypted swap file the platform made (crypttab "cryptswap"
#      over /swapfile) is switched off once zram is on and memory can take
#      back what it holds; its crypttab and fstab lines and the file go.
#      Memory that cannot take it yet: kept, and tried again next run.
#   Swap this did not make is left alone (VaultHealth names a plain one).
#
# Usage:  host_swap.sh [SITENAME] [SITE_ROOT]
#         host_swap.sh --machine ROOT
#
# Test hook, honoured only when this is NOT root:
#   JOINERY_SWAP_ROOT=<dir>   treat <dir> as /: its etc/, proc/ and swapfile
#                             are read and written; system commands are the
#                             ones on PATH (the gate stubs them).

set -u

say()  { echo "swap: $*"; }
warn() { echo "swap: WARNING - $*" >&2; }

FS_ROOT=""
if [[ "$(id -u)" == "0" ]]; then
    [[ -z "${JOINERY_SWAP_ROOT:-}" ]] || { say "JOINERY_SWAP_ROOT is set but this is root - hook ignored"; unset JOINERY_SWAP_ROOT; }
elif [[ -n "${JOINERY_SWAP_ROOT:-}" ]]; then
    FS_ROOT="${JOINERY_SWAP_ROOT%/}"
else
    say "not root - skipping"
    exit 0
fi

if [[ -f "${FS_ROOT}/.dockerenv" ]] || { [[ -z "${FS_ROOT}" ]] && systemd-detect-virt --container --quiet 2>/dev/null; }; then
    exit 0
fi

ZRAM_CONF="${FS_ROOT}/etc/systemd/zram-generator.conf"
CRYPTTAB="${FS_ROOT}/etc/crypttab"
FSTAB="${FS_ROOT}/etc/fstab"
PROC_SWAPS="${FS_ROOT}/proc/swaps"
MEMINFO="${FS_ROOT}/proc/meminfo"
SWAPFILE="${FS_ROOT}/swapfile"
CRYPT_NAME="cryptswap"
ZRAM_UNIT="systemd-zram-setup@zram0.service"
PKG="systemd-zram-generator"

ZRAM_TEXT="# Written by host_swap.sh. Swap is compressed memory: nothing reaches a disk.
[zram0]
zram-size = min(ram / 2, 4096)
compression-algorithm = zstd
swap-priority = 100"

zram_on() { awk 'NR > 1 && $1 ~ /zram/ { f = 1 } END { exit !f }' "$PROC_SWAPS" 2>/dev/null; }

# The swap device the platform's encrypted file is, as /proc/swaps names it.
crypt_device() {
    local dev
    dev="$(readlink -f "${FS_ROOT}/dev/mapper/${CRYPT_NAME}" 2>/dev/null || true)"
    [[ -n "$dev" ]] && printf '%s' "${dev#"$FS_ROOT"}"
}

pkg_installed() { dpkg-query -W -f='${Status}' "$1" 2>/dev/null | grep -q '^install ok installed$'; }

# --- 1. zram ----------------------------------------------------------------
# The package first: it ships a config of its own, and a different file
# already in its place would stop dpkg at a question nobody answers. Ours is
# kept wherever the two meet (confold).
changed=0
apt_install() {
    DEBIAN_FRONTEND=noninteractive apt-get -o DPkg::Lock::Timeout=300 -o Dpkg::Options::=--force-confdef \
        -o Dpkg::Options::=--force-confold install -y -qq "$PKG" >/dev/null 2>&1
}
if ! pkg_installed "$PKG"; then
    apt_install || { apt-get -o DPkg::Lock::Timeout=300 update -qq >/dev/null 2>&1; apt_install; } \
        || { warn "could not install ${PKG}; the swap as it is stays until the next run"; exit 0; }
    changed=1
fi
if [[ ! -f "$ZRAM_CONF" ]] || [[ "$(cat "$ZRAM_CONF")" != "$ZRAM_TEXT" ]]; then
    mkdir -p "$(dirname "$ZRAM_CONF")"
    printf '%s\n' "$ZRAM_TEXT" > "${ZRAM_CONF}.tmp" && mv -f "${ZRAM_CONF}.tmp" "$ZRAM_CONF" \
        || { warn "could not write ${ZRAM_CONF#"$FS_ROOT"}"; exit 0; }
    changed=1
fi
if ! zram_on; then
    # The generator makes the device's setup unit and its swap unit; the
    # swap unit is what turns it on.
    systemctl daemon-reload 2>/dev/null || true
    systemctl restart "$ZRAM_UNIT" 2>/dev/null || true
    systemctl start dev-zram0.swap 2>/dev/null || true
    for _ in 1 2 3 4 5 6 7 8 9 10; do zram_on && break; sleep 1; done
    if ! zram_on; then
        warn "zram did not start (systemctl status ${ZRAM_UNIT}); the swap as it is stays until the next run"
        exit 0
    fi
    say "swap in compressed memory (zram) is on"
elif (( changed )); then
    say "zram settings written; they take effect at the next boot"
fi

# --- 2. the platform's encrypted swap file goes -----------------------------
grep -qE "^${CRYPT_NAME}[[:space:]]+/swapfile[[:space:]]" "$CRYPTTAB" 2>/dev/null || exit 0
dev="$(crypt_device)"
used_kb=0
if [[ -n "$dev" ]]; then
    used_kb="$(awk -v d="$dev" 'NR > 1 && $1 == d { print $4; exit }' "$PROC_SWAPS" 2>/dev/null)"
    used_kb="${used_kb:-0}"
fi
avail_kb="$(awk '$1 == "MemAvailable:" { print $2; exit }' "$MEMINFO" 2>/dev/null)"
# What it holds comes back into memory (zram takes some): only with room to spare.
if (( used_kb > 0 )) && (( used_kb + 262144 > ${avail_kb:-0} )); then
    say "the encrypted swap file holds $(( used_kb / 1024 )) MiB and memory has $(( ${avail_kb:-0} / 1024 )) MiB free: kept until a run with room"
    exit 0
fi
if [[ -n "$dev" ]] && awk -v d="$dev" 'NR > 1 && $1 == d { f = 1 } END { exit !f }' "$PROC_SWAPS" 2>/dev/null; then
    swapoff "${FS_ROOT}/dev/mapper/${CRYPT_NAME}" || { warn "swapoff of the encrypted swap file failed; kept"; exit 0; }
fi
systemctl stop "systemd-cryptsetup@${CRYPT_NAME}.service" 2>/dev/null || true
sed -i "/^${CRYPT_NAME}[[:space:]]/d" "$CRYPTTAB"
sed -i "\#^/dev/mapper/${CRYPT_NAME}[[:space:]]#d" "$FSTAB"
rm -f "$SWAPFILE"
systemctl daemon-reload 2>/dev/null || true
say "the encrypted swap file is retired: swap is compressed memory only"
exit 0
