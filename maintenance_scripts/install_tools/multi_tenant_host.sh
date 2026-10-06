#!/usr/bin/env bash
#
# multi_tenant_host.sh - what a multi-tenant Docker host carries beyond an
# ordinary Docker host: walls between each site and the host, between sites,
# and between a site and the outside services nothing on it should use; and a
# reboot that follows a kernel update
# (multi_tenant_docker_hosts WP5 items 1, 4, 5, 6 and 7).
#
# Version: 1.0
#
#   multi_tenant_host.sh install   Root. Copies this script to
#                                  /usr/local/sbin/joinery-site-walls, writes and
#                                  starts joinery-site-walls.service (loads the
#                                  walls at every boot, before Docker), and writes
#                                  the reboot policy. Run again, it rewrites only
#                                  what differs.
#   multi_tenant_host.sh walls     Root. Loads the walls (the unit's start
#                                  command). Each table is replaced whole, in one
#                                  transaction, so a run never leaves half a wall.
#   multi_tenant_host.sh ruleset   Prints the walls as nft reads them.
#   multi_tenant_host.sh check     Says whether the walls are loaded and the
#                                  reboot policy is in place; exit 1 if not.
#
# THE WALLS. A site's container is on a bridge on the host: docker0 for a site
# on Docker's default network, jsnet<N> for a site on a network of its own
# (node_outbound_and_transfer WP2). Each rule below names those bridges, so it
# holds for every site there is or will be, with nothing to rewrite when one is
# added. The tables are in the inet family (IPv4 and IPv6 alike) and the bridge
# family, beside Docker's own: a packet any table drops is dropped, so nothing
# here depends on Docker's chains or the order they run in.
#
#   To the host itself (input). A site's packets addressed to the host reach
#   only its public web ports, 80 and 443, which is what lets one site load
#   another's pages or send it a Joinery Direct delivery through the host's
#   proxy. Replies (the proxy's requests to the site, the host's reads of the
#   site's database through its published port) pass, and so does IPv6's
#   neighbour discovery, without which a site's IPv6 has no gateway. Everything
#   else is dropped: the host agent, the host's own database, whatever listens
#   there later.
#   Through the host (forward). From a site's bridge to another site's bridge;
#   to port 25 anywhere (machines we create send no mail themselves,
#   own_mail_server_sending § 4); to the cloud's metadata service
#   (169.254.169.254, and fd00:a9fe:a9fe::1 on Linode).
#   Across one bridge (bridge forward). Two containers on one bridge talk to
#   each other without the host routing anything, so the inet family never
#   sees it; the bridge family does. Every frame from one port of a site bridge
#   to another is dropped.
#   From the host (output). The host's own connections to port 25, loopback
#   apart.
#
# Each drop counts, in the ruleset (`nft list table inet joinery_site_walls`).
#
# THE REBOOT. A kernel update does nothing until the machine reboots, and the
# shared kernel is the last wall between sites. Automatic updates run at 05:30
# UTC (up to 30 minutes later, at random), after the fleet backup window
# (03:00 UTC plus two hours), and reboot at once when an update needs it.
# Containers come back on their own (--restart unless-stopped), a container
# stopped by hand stays stopped, and the walls are back before Docker starts.

set -euo pipefail

SELF_INSTALLED="/usr/local/sbin/joinery-site-walls"
UNIT_NAME="joinery-site-walls.service"

# Tests point these into a scratch directory.
ROOT="${JOINERY_MT_ROOT:-}"
UNIT_PATH="${ROOT}/etc/systemd/system/${UNIT_NAME}"
REBOOT_CONF="${ROOT}/etc/apt/apt.conf.d/52joinery-multi-tenant-reboot"
TIMER_DROPIN="${ROOT}/etc/systemd/system/apt-daily-upgrade.timer.d/joinery-multi-tenant.conf"
INSTALLED="${ROOT}${SELF_INSTALLED}"

say() { printf '%s\n' "$*"; }
die() { printf 'multi_tenant_host: %s\n' "$*" >&2; exit 1; }

walls_ruleset() {
    cat <<'NFT'
table inet joinery_site_walls
delete table inet joinery_site_walls
table inet joinery_site_walls {
	chain input {
		type filter hook input priority filter; policy accept;
		iifname "docker0" jump site_to_host
		iifname "jsnet*" jump site_to_host
	}
	chain site_to_host {
		ct state established,related accept
		icmpv6 type { nd-neighbor-solicit, nd-neighbor-advert, nd-router-solicit } accept
		tcp dport { 80, 443 } accept
		counter drop
	}
	chain forward {
		type filter hook forward priority filter; policy accept;
		iifname "docker0" jump site_through_host
		iifname "jsnet*" jump site_through_host
	}
	chain site_through_host {
		oifname "docker0" counter drop
		oifname "jsnet*" counter drop
		tcp dport 25 counter drop
		ip daddr 169.254.169.254 counter drop
		ip6 daddr fd00:a9fe:a9fe::1 counter drop
	}
	chain output {
		type filter hook output priority filter; policy accept;
		oifname "lo" accept
		tcp dport 25 counter drop
	}
}
table bridge joinery_site_walls
delete table bridge joinery_site_walls
table bridge joinery_site_walls {
	chain forward {
		type filter hook forward priority filter; policy accept;
		meta ibrname "docker0" counter drop
		meta ibrname "jsnet*" counter drop
	}
}
NFT
}

unit_text() {
    cat <<EOF
[Unit]
Description=Joinery: walls between this host's sites, the host and the outside (multi_tenant_host.sh)
DefaultDependencies=no
After=local-fs.target
Before=network-pre.target docker.service
Wants=network-pre.target

[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart=${SELF_INSTALLED} walls

[Install]
WantedBy=multi-user.target
EOF
}

reboot_conf_text() {
    cat <<'EOF'
// Written by multi_tenant_host.sh: a multi-tenant host reboots as soon as an
// update needs it, so a kernel fix takes effect the night it arrives. The run
// itself is timed by apt-daily-upgrade.timer (05:30 UTC, after the backup window).
Unattended-Upgrade::Automatic-Reboot "true";
Unattended-Upgrade::Automatic-Reboot-WithUsers "true";
Unattended-Upgrade::Automatic-Reboot-Time "now";
EOF
}

timer_dropin_text() {
    cat <<'EOF'
# Written by multi_tenant_host.sh: automatic updates, and the reboot that may
# follow, run after the fleet backup window (03:00 UTC plus two hours).
[Timer]
OnCalendar=
OnCalendar=*-*-* 05:30 UTC
RandomizedDelaySec=30m
EOF
}

# write_if_differs PATH MODE < text. Prints "changed" when it wrote.
write_if_differs() {
    local path="$1" mode="$2" tmp
    tmp="$(mktemp)"
    cat > "$tmp"
    if [[ -f "$path" ]] && cmp -s "$tmp" "$path"; then
        rm -f "$tmp"
        return 0
    fi
    mkdir -p "$(dirname "$path")"
    install -m "$mode" "$tmp" "$path"
    rm -f "$tmp"
    echo changed
}

do_walls() {
    command -v nft >/dev/null 2>&1 || die "nft is not installed; the walls are not loaded"
    walls_ruleset | nft -f - || die "nft refused the walls; nothing was changed"
    say "site walls loaded"
}

do_install() {
    [[ -n "$ROOT" || "$EUID" -eq 0 ]] || die "install needs root"
    if ! command -v nft >/dev/null 2>&1; then
        apt-get install -y nftables >/dev/null || die "could not install nftables"
    fi
    # The ruleset is checked before anything is written: a host never gets a
    # unit whose start would fail.
    walls_ruleset | nft -c -f - || die "nft refused the walls; nothing was changed"

    local changed=""
    changed+="$(write_if_differs "$INSTALLED" 0755 < "${BASH_SOURCE[0]}")"
    changed+="$(unit_text | write_if_differs "$UNIT_PATH" 0644)"
    local timer_changed
    timer_changed="$(timer_dropin_text | write_if_differs "$TIMER_DROPIN" 0644)"
    reboot_conf_text | write_if_differs "$REBOOT_CONF" 0644 >/dev/null

    if [[ -n "$changed" || -n "$timer_changed" ]]; then
        systemctl daemon-reload
    fi
    systemctl enable "$UNIT_NAME" >/dev/null 2>&1 || die "could not enable ${UNIT_NAME}"
    # restart, not start: a new ruleset takes effect now, not at the next boot.
    systemctl restart "$UNIT_NAME" || die "${UNIT_NAME} did not start: see journalctl -u ${UNIT_NAME}"
    if [[ -n "$timer_changed" ]]; then
        systemctl restart apt-daily-upgrade.timer || die "apt-daily-upgrade.timer did not restart"
    fi
    say "site walls: loaded now and at every boot (${UNIT_NAME})"
    say "kernel updates: reboot at 05:30-06:00 UTC when an update needs it"
}

do_check() {
    local bad=0
    if nft list table inet joinery_site_walls >/dev/null 2>&1 \
            && nft list table bridge joinery_site_walls >/dev/null 2>&1; then
        say "walls: loaded"
    else
        say "walls: NOT loaded"; bad=1
    fi
    if systemctl is-enabled "$UNIT_NAME" >/dev/null 2>&1; then
        say "walls at boot: ${UNIT_NAME} enabled"
    else
        say "walls at boot: ${UNIT_NAME} NOT enabled"; bad=1
    fi
    if [[ -f "$REBOOT_CONF" && -f "$TIMER_DROPIN" ]]; then
        say "reboot after updates: on"
    else
        say "reboot after updates: NOT set"; bad=1
    fi
    return "$bad"
}

case "${1:-}" in
    install) do_install ;;
    walls)   do_walls ;;
    ruleset) walls_ruleset ;;
    check)   do_check ;;
    *) die "usage: multi_tenant_host.sh install|walls|ruleset|check" ;;
esac
