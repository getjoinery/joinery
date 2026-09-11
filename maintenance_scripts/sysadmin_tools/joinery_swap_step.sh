#!/usr/bin/env bash
# Standalone run of install.sh host_housekeeping's swap + apport step
# (install.sh 2.68, specs/vault_exposure_quick_fixes.md Q5) for a box
# installed before the step existed. Backs up fstab/crypttab first.
# Usage: sudo bash joinery_swap_step.sh   (re-running is a no-op once the
# 1 GB mapping is active). Converted 2026-09-09: the Docker
# host and jeremytunnell, both live without a reboot; nofail on both lines
# means a boot that cannot bring the mapping up runs without swap.
set -uo pipefail
[ "$(id -u)" -eq 0 ] || { echo "run as root"; exit 1; }
print_step()    { echo "==> $*"; }
print_info()    { echo "    $*"; }
print_success() { echo "OK  $*"; }
print_warning() { echo "WARN $*"; }
BK=/root/joinery-swap-backup-$(date +%Y%m%d%H%M%S)
mkdir -p "$BK"; cp /etc/fstab "$BK/fstab"; cp /etc/crypttab "$BK/crypttab" 2>/dev/null || true
echo "backup of fstab/crypttab in $BK"
swap_step() {
    # --- Swap: 1 GB, encrypted ---
    # Swap stays on: a 1 GB box needs somewhere to put idle php-fpm workers
    # and cold Postgres pages. But an idle worker's pages can hold an open
    # vault window's key, so the device is dm-crypt with a throwaway key drawn
    # from /dev/urandom at every boot (the standard Debian pattern; nothing to
    # manage, nothing to lose). The size is a flat 1 GB on every box: no node
    # has ever used more than half that, and more is only room to thrash in.
    # Both lines carry nofail, so a box whose swap fails to come up boots
    # without it and VaultHealth reports the gap, instead of hanging in the
    # emergency shell.
    print_step "Configuring swap..."
    local SWAPFILE="/swapfile"
    local CRYPT_NAME="cryptswap"
    local CRYPT_DEV="/dev/mapper/$CRYPT_NAME"
    local SWAP_GB=1
    local SWAP_KB
    SWAP_KB=$(awk '/^SwapTotal:/ {print $2}' /proc/meminfo)
    # The kernel names an active mapping by its resolved node (/dev/dm-N),
    # never by the /dev/mapper alias, so compare resolved paths. Skip only
    # when the mapping is up AT this size; anything else is replaced.
    local CRYPT_NODE
    CRYPT_NODE=$(readlink -f "$CRYPT_DEV" 2>/dev/null || echo "$CRYPT_DEV")
    if swapon --show=NAME --noheadings 2>/dev/null | grep -qx "$CRYPT_NODE" \
            && [ "${SWAP_KB:-0}" -ge $((SWAP_GB * 1000000)) ] \
            && [ "${SWAP_KB:-0}" -le $((SWAP_GB * 1100000)) ]; then
        print_info "Encrypted swap already active at $CRYPT_DEV ($((SWAP_KB / 1024))M) — keeping it"
    else
        apt-get install -y cryptsetup > /dev/null 2>&1
        swapoff -a 2>/dev/null || true
        if [ -e "$CRYPT_DEV" ]; then
            systemctl stop "systemd-cryptsetup@$CRYPT_NAME" 2>/dev/null || cryptsetup close "$CRYPT_NAME" 2>/dev/null || true
        fi
        rm -f "$SWAPFILE"
        fallocate -l "${SWAP_GB}G" "$SWAPFILE"
        chmod 600 "$SWAPFILE"
        # crypttab: fresh key each boot; the swap option runs mkswap on the mapping.
        touch /etc/crypttab
        sed -i "/^$CRYPT_NAME[[:space:]]/d" /etc/crypttab
        echo "$CRYPT_NAME $SWAPFILE /dev/urandom swap,cipher=aes-xts-plain64,size=256,nofail" >> /etc/crypttab
        # Replace any existing swap entries (Linode's plain swap disk, an older
        # plain /swapfile line) with the mapping.
        sed -i '/[[:space:]]swap[[:space:]]/d' /etc/fstab
        echo "$CRYPT_DEV none swap sw,nofail 0 0" >> /etc/fstab
        systemctl daemon-reload
        if systemctl start "systemd-cryptsetup@$CRYPT_NAME" && swapon "$CRYPT_DEV"; then
            local ACTIVE
            ACTIVE=$(swapon --show=NAME --noheadings 2>/dev/null | tr '\n' ' ')
            CRYPT_NODE=$(readlink -f "$CRYPT_DEV" 2>/dev/null || echo "$CRYPT_DEV")
            if [ "$(echo "$ACTIVE" | xargs)" = "$CRYPT_NODE" ]; then
                print_success "Swap: ${SWAP_GB}G encrypted swap active at $CRYPT_DEV"
            else
                print_warning "Swap: expected only $CRYPT_DEV active, found: $ACTIVE"
            fi
        else
            print_warning "Swap: could not bring up $CRYPT_DEV — the box runs without swap until check_vault_health.php is green"
        fi
    fi

    # --- apport: off ---
    # kernel.core_pattern pipes cores to apport on Ubuntu, and the kernel
    # ignores the core rlimit for a pipe: apport reads the whole core (an open
    # vault window's key included) into /var/crash whatever rlimit_core says.
    print_step "Disabling apport..."
    if [ -f /etc/default/apport ]; then
        sed -i 's/^enabled=1/enabled=0/' /etc/default/apport
    fi
    systemctl disable --now apport > /dev/null 2>&1 || true
    print_success "apport disabled"
}
swap_step
echo "===== verification"
swapon --show
for d in /sys/block/dm-*/dm/uuid; do [ -e "$d" ] && echo "$d: $(cat $d)"; done
systemctl is-active systemd-cryptsetup@cryptswap
grep -v '^#' /etc/fstab; cat /etc/crypttab
systemctl list-dependencies swap.target --plain | sed 's/^/  dep: /'
echo "core_pattern=$(cat /proc/sys/kernel/core_pattern) apport=$(systemctl is-active apport) $(grep ^enabled /etc/default/apport)"
free -m | sed -n 2,3p
