#!/usr/bin/env bash
#
# outbound_limits.sh - what each site on this machine may open toward the
# outside: a rate on new connections, a cap on connections open at once, and
# no UDP at all (node_outbound_and_transfer WP3). Real use never meets them;
# they stop a hacked plugin turning the server into a scanner or a flood.
#
# Version: 1.0
#
#   outbound_limits.sh install [--web-user] [--off]
#                         Root. Copies this script to /usr/local/sbin/joinery-limits,
#                         writes joinery-limits.service (runs at boot after Docker)
#                         and joinery-limits.timer (every five minutes), and runs it.
#                         --web-user: this machine serves its sites itself
#                         (install.sh server), so the web server's user is limited;
#                         a machine that once had it keeps it. --off: the limits
#                         are off on this machine (the host file says so).
#                         Run again, it rewrites only what differs.
#   joinery-limits apply [--web-user]
#                         Root. Writes the limits (the unit's command). Run by
#                         install.sh site, the rebase and move scripts and
#                         remove_account.sh through the unit, so one run at a time.
#   joinery-limits on | off
#                         Root. Turns every limit on or off on this machine, UDP
#                         drop included, and applies it.
#   joinery-limits status Prints what is in force, for which sites, and the drops.
#   joinery-limits ruleset [--web-user]
#                         Prints the table as apply would write it.
#
# WHAT IS LIMITED. A container site is matched by its own network's bridge
# (jsnetN, node_outbound_and_transfer WP2), read from its run spec, on the
# host's forward path: what the site sends out through the host. A site still
# on Docker's default network shares docker0 with every other such site and is
# not limited; status names it, and move_site_to_own_network.sh moves it. With
# --web-user, everything the web server's user (www-data) opens on the host's
# output path is limited as one, loopback apart, so its own database and the
# local resolver are never touched.
#
# Per site, in this order: all UDP dropped; a new connection dropped when the
# site already holds OPEN_CONNS; a new connection dropped above CONN_RATE a
# second with bursts to CONN_BURST. TCP sends a dropped opening again a second
# later, so a short burst is slowed, not refused. Each drop counts in the
# site's named counter (drops_site_SITE, drops_web_user), which host_report.sh
# reads. Both families: the table is in nftables' inet family, so one limit
# covers IPv4 and IPv6 together. It sits beside Docker's tables and the
# multi-tenant walls (inet joinery_site_walls): a packet any table drops is
# dropped, and an accept here skips nothing there. Chain and counter names
# carry the site name unquoted: nft's names take letters, digits, _ - . and /,
# which covers every site name ([A-Za-z0-9_-], behind a letter prefix), and it
# lists them back unquoted, which is how host_report.sh finds them.
#
# THE RESOLVER. A site's name lookups never leave as its own UDP only while the
# host's resolver is a loopback address: Docker (20.10 and later; WP2 already
# needs 27 for IPv6) then forwards a container's lookups from the host's side, and on bare metal the resolver's queries run as
# its own user. Were /etc/resolv.conf to name an outside resolver, the UDP drop
# would break every site's DNS, so apply refuses: it removes the table, says
# why in the status file, and fails the unit.
#
# KEPT ACROSS RUNS. The table is replaced whole, in one transaction, only when
# what it would hold changes (a site added, moved or removed): a replace starts
# the rate and open-connection counts afresh. The drop counters carry their
# values into the new table. Nothing here survives a reboot; the unit puts the
# table back at boot and the counters start again from zero, which the status
# file's since says.
#
# STATUS. /run/joinery/outbound_limits.status, key=value lines read by
# host_report.sh: state (on, off, refused), reason (a code), since (when the
# counters began), sites (limited), uncovered (on Docker's default network),
# web_user (yes or no). state=on with reason=nft_refused: the last change was
# refused and the table before it, with its sites and counters, is in force.

set -uo pipefail

SELF_INSTALLED="/usr/local/sbin/joinery-limits"
UNIT_NAME="joinery-limits.service"
TIMER_NAME="joinery-limits.timer"
TABLE="joinery_limits"

# The built-in figures (WP5 makes them settable).
CONN_RATE=20
CONN_BURST=100
OPEN_CONNS=256
WEB_USER="www-data"

# Tests point these into a scratch directory.
ROOT="${JOINERY_LIMITS_ROOT:-}"
UNIT_PATH="${ROOT}/etc/systemd/system/${UNIT_NAME}"
TIMER_PATH="${ROOT}/etc/systemd/system/${TIMER_NAME}"
INSTALLED="${ROOT}${SELF_INSTALLED}"
HOST_FILE="${ROOT}/etc/joinery/outbound_limits.json"
SITES_DIR="${ROOT}/etc/joinery/sites"
RESOLV_CONF="${ROOT}/etc/resolv.conf"
RUN_DIR="${ROOT}/run/joinery"
STATUS_FILE="${RUN_DIR}/outbound_limits.status"
APPLIED_FILE="${RUN_DIR}/outbound_limits.applied"
LOCK_FILE="${RUN_DIR}/outbound_limits.lock"

say() { printf '%s\n' "$*"; }
die() { printf 'joinery-limits: %s\n' "$*" >&2; exit 1; }
need_root() { [[ -n "$ROOT" || "$EUID" -eq 0 ]] || die "$1 needs root"; }

# --- what the machine holds ---------------------------------------------------

# Whether the host file turns the limits off. No file, or one that does not say
# enabled false, is on: a machine nobody configured is protected.
limits_enabled() {
    [[ -f "$HOST_FILE" ]] || return 0
    python3 - "$HOST_FILE" <<'PY'
import json, sys
try:
    with open(sys.argv[1]) as f:
        d = json.load(f)
except Exception:
    sys.exit(0)
sys.exit(1 if isinstance(d, dict) and d.get("enabled") is False else 0)
PY
}

# Sets ENABLED in the host file, keeping every other key it holds.
set_enabled() {  # true|false
    mkdir -p "$(dirname "$HOST_FILE")"
    python3 - "$HOST_FILE" "$1" <<'PY' || die "could not write ${HOST_FILE}"
import json, os, sys
path, on = sys.argv[1], sys.argv[2] == "true"
d = {}
try:
    with open(path) as f:
        d = json.load(f)
    if not isinstance(d, dict):
        d = {}
except Exception:
    pass
d["enabled"] = on
tmp = path + ".tmp"
with open(tmp, "w") as f:
    json.dump(d, f, indent=2)
    f.write("\n")
os.chmod(tmp, 0o644)
os.replace(tmp, path)
PY
}

# The resolver addresses /etc/resolv.conf names, one per line.
resolvers() {
    awk '$1 == "nameserver" { print $2 }' "$RESOLV_CONF" 2>/dev/null
}

# 0 when every resolver is a loopback address (and there is at least one).
resolver_is_loopback() {
    local r n=0
    while IFS= read -r r; do
        n=$((n + 1))
        [[ "$r" =~ ^127\.[0-9]+\.[0-9]+\.[0-9]+$ || "$r" == "::1" ]] || return 1
    done <<< "$(resolvers)"
    (( n > 0 ))
}

# Each container site with a network of its own, as SITE BRIDGE lines; each
# site still on Docker's default network, as SITE alone after a "-" line.
container_sites() {
    local spec site bridge
    local -a uncovered=()
    for spec in "$SITES_DIR"/*/run_spec; do
        [[ -f "$spec" ]] || continue
        site="$(basename "$(dirname "$spec")")"
        [[ "$site" =~ ^[A-Za-z0-9_-]{1,50}$ ]] || continue
        bridge="$(sed -n 's/^bridge=//p' "$spec" | tail -1)"
        # docker0 is every default-network site's at once, never one site's.
        if [[ "$bridge" =~ ^[a-z][a-z0-9]{0,14}$ && "$bridge" != docker0 ]]; then
            echo "$site $bridge"
        else
            uncovered+=("$site")
        fi
    done
    echo "-"
    printf '%s\n' "${uncovered[@]+"${uncovered[@]}"}"
}

# --- the table -------------------------------------------------------------------

# One limited sender's chain: UDP, then the open cap, then the rate.
sender_chain() {  # CHAIN COUNTER
    cat <<EOF
	chain $1 {
		meta l4proto udp counter name "$2" drop
		ct state new ct count over ${OPEN_CONNS} counter name "$2" drop
		ct state new limit rate over ${CONN_RATE}/second burst ${CONN_BURST} packets counter name "$2" drop
	}
EOF
}

# The table's body, with every counter at zero. COUNTS (name=packets:bytes
# lines) carries values over from the table being replaced.
table_body() {  # WEB_USER(0|1) [COUNTS]
    local web="$1" counts="${2:-}" line site bridge v p b
    local -a sites=() bridges=()
    while IFS= read -r line; do
        [[ "$line" == "-" ]] && break
        site="${line%% *}"; bridge="${line#* }"
        sites+=("$site"); bridges+=("$bridge")
    done <<< "$(container_sites)"

    counter_decl() {  # NAME
        v="$(sed -n "s/^$1=//p" <<< "$counts" | tail -1)"
        p="${v%%:*}"; b="${v#*:}"
        [[ "$p" =~ ^[0-9]+$ && "$b" =~ ^[0-9]+$ ]] || { p=0; b=0; }
        printf '\tcounter %s {\n\t\tpackets %s bytes %s\n\t}\n' "$1" "$p" "$b"
    }

    local i
    for i in "${!sites[@]}"; do counter_decl "drops_site_${sites[$i]}"; done
    [[ "$web" == 1 ]] && counter_decl "drops_web_user"
    if (( ${#sites[@]} > 0 )); then
        printf '\tchain forward {\n\t\ttype filter hook forward priority -10; policy accept;\n'
        for i in "${!sites[@]}"; do
            printf '\t\tiifname "%s" jump site_%s\n' "${bridges[$i]}" "${sites[$i]}"
        done
        printf '\t}\n'
        for i in "${!sites[@]}"; do sender_chain "site_${sites[$i]}" "drops_site_${sites[$i]}"; done
    fi
    if [[ "$web" == 1 ]]; then
        printf '\tchain output {\n\t\ttype filter hook output priority -10; policy accept;\n'
        printf '\t\toifname "lo" accept\n'
        printf '\t\tmeta skuid "%s" jump web_user\n' "$WEB_USER"
        printf '\t}\n'
        sender_chain web_user drops_web_user
    fi
}

# The whole transaction: create (so the delete never fails), delete, write.
ruleset() {  # WEB_USER [COUNTS]
    printf 'table inet %s\ndelete table inet %s\ntable inet %s {\n' "$TABLE" "$TABLE" "$TABLE"
    table_body "$@"
    printf '}\n'
}

# The current drop counters, as name=packets:bytes lines; nothing when there
# is no table.
current_counts() {
    nft list counters table inet "$TABLE" 2>/dev/null | awk '
        $1 == "counter" { name = $2 }
        $1 == "packets" && name != "" { print name "=" $2 ":" $4; name = "" }'
}

remove_table() {
    if nft list table inet "$TABLE" > /dev/null 2>&1; then
        nft delete table inet "$TABLE" || return 1
    fi
    rm -f "$APPLIED_FILE"
}

# write_status STATE REASON [SITES UNCOVERED WEB_USER]
write_status() {
    local since=""
    mkdir -p "$RUN_DIR"
    [[ -f "$STATUS_FILE" ]] && since="$(sed -n 's/^since=//p' "$STATUS_FILE")"
    # The counters begin when the table first holds them since boot (/run is
    # emptied at boot, as the table is).
    if [[ "$1" != on ]]; then
        since=""
    elif [[ ! "$since" =~ ^[0-9]+$ ]]; then
        since="$(date -u +%s)"
    fi
    {
        echo "state=$1"
        echo "reason=$2"
        echo "since=${since}"
        echo "sites=${3:-}"
        echo "uncovered=${4:-}"
        echo "web_user=${5:-no}"
        echo "applied_at=$(date -u +%s)"
    } > "${STATUS_FILE}.tmp" && chmod 644 "${STATUS_FILE}.tmp" && mv -f "${STATUS_FILE}.tmp" "$STATUS_FILE"
}

# Whether the installed unit limits the web server's user.
unit_has_web_user() {
    [[ -f "$UNIT_PATH" ]] && grep -q -- '--web-user' "$UNIT_PATH"
}

do_apply() {  # WEB_USER(0|1)
    local web="$1" body want sites uncovered line
    need_root apply
    # A hand run without the flag on a machine whose unit has it keeps it.
    unit_has_web_user && web=1
    command -v nft > /dev/null 2>&1 || { write_status refused nft_missing; die "nft is not installed; no limits are in force"; }
    mkdir -p "$RUN_DIR"
    exec 9> "$LOCK_FILE"
    flock -w 60 9 || die "another run holds ${LOCK_FILE}"

    if ! limits_enabled; then
        remove_table || die "could not remove the limits table"
        write_status off off
        say "outbound limits: off on this machine (${HOST_FILE})"
        return 0
    fi
    if ! resolver_is_loopback; then
        remove_table
        write_status refused resolver_not_loopback
        die "the host's resolver is not a loopback address ($(resolvers | paste -sd ' ')): dropping a site's UDP would break its name lookups, so no limits are in force. Point /etc/resolv.conf at the local resolver (systemd-resolved, 127.0.0.53) and run this again"
    fi

    sites=""; uncovered=""
    local past=0
    while IFS= read -r line; do
        if [[ "$line" == "-" ]]; then past=1; continue; fi
        [[ -n "$line" ]] || continue
        if (( past )); then uncovered+="${uncovered:+ }${line}"; else sites+="${sites:+ }${line%% *}"; fi
    done <<< "$(container_sites)"

    want="$(ruleset "$web")"
    if [[ -f "$APPLIED_FILE" ]] && [[ "$(cat "$APPLIED_FILE")" == "$want" ]] \
            && nft list table inet "$TABLE" > /dev/null 2>&1; then
        write_status on "" "$sites" "$uncovered" "$([[ $web == 1 ]] && echo yes || echo no)"
        say "outbound limits: unchanged"
        return 0
    fi
    body="$(ruleset "$web" "$(current_counts)")"
    if ! printf '%s\n' "$body" | nft -f -; then
        # The table before this change is still in force, its counters still
        # running: the status keeps saying so, with its sites and its since, so
        # a site's drops are neither hidden nor counted twice. The unit fails,
        # which the host report names, and the next run tries again.
        if [[ -f "$STATUS_FILE" ]] && grep -qx 'state=on' "$STATUS_FILE" \
                && nft list table inet "$TABLE" > /dev/null 2>&1; then
            write_status on nft_refused "$(sed -n 's/^sites=//p' "$STATUS_FILE")" \
                "$(sed -n 's/^uncovered=//p' "$STATUS_FILE")" "$(sed -n 's/^web_user=//p' "$STATUS_FILE")"
        else
            write_status refused nft_refused "$sites" "$uncovered"
        fi
        die "nft refused the limits; the table is as it was"
    fi
    printf '%s\n' "$want" > "$APPLIED_FILE"
    write_status on "" "$sites" "$uncovered" "$([[ $web == 1 ]] && echo yes || echo no)"
    say "outbound limits: in force for ${sites:-no container site}$([[ $web == 1 ]] && echo " and the web server's user (${WEB_USER})")"
    [[ -z "$uncovered" ]] || say "outbound limits: not limited, still on Docker's default network: ${uncovered} (move_site_to_own_network.sh moves them)"
    return 0
}

# --- the unit --------------------------------------------------------------------

unit_text() {  # WEB_USER(0|1)
    cat <<EOF
[Unit]
Description=Joinery: outbound connection limits for this machine's sites (outbound_limits.sh)
After=docker.service systemd-resolved.service network-online.target

[Service]
Type=oneshot
ExecStart=${SELF_INSTALLED} apply$([[ "$1" == 1 ]] && echo " --web-user")

[Install]
WantedBy=multi-user.target
EOF
}

timer_text() {
    cat <<'EOF'
[Unit]
Description=Joinery: keep the outbound connection limits in force (outbound_limits.sh)

[Timer]
OnBootSec=2min
OnUnitActiveSec=5min

[Install]
WantedBy=timers.target
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

do_install() {  # WEB_USER(0|1) OFF(0|1)
    local web="$1" off="$2" changed=""
    need_root install
    if ! command -v nft > /dev/null 2>&1; then
        apt-get install -y nftables > /dev/null || die "could not install nftables"
    fi
    unit_has_web_user && web=1
    # The ruleset is checked before anything is written.
    ruleset "$web" | nft -c -f - || die "nft refused the limits; nothing was changed"
    if [[ "$off" == 1 ]]; then
        set_enabled false
    fi
    changed+="$(write_if_differs "$INSTALLED" 0755 < "${BASH_SOURCE[0]}")"
    changed+="$(unit_text "$web" | write_if_differs "$UNIT_PATH" 0644)"
    changed+="$(timer_text | write_if_differs "$TIMER_PATH" 0644)"
    [[ -z "$changed" ]] || systemctl daemon-reload
    systemctl enable "$UNIT_NAME" "$TIMER_NAME" > /dev/null 2>&1 || die "could not enable ${UNIT_NAME}"
    systemctl start "$TIMER_NAME" || die "${TIMER_NAME} did not start"
    systemctl start "$UNIT_NAME" || die "${UNIT_NAME} failed: see journalctl -u ${UNIT_NAME}"
    do_status
}

do_onoff() {  # true|false
    need_root "$([[ $1 == true ]] && echo on || echo off)"
    set_enabled "$1"
    if [[ -f "$UNIT_PATH" ]]; then
        systemctl start "$UNIT_NAME" || die "${UNIT_NAME} failed: see journalctl -u ${UNIT_NAME}"
    else
        do_apply "$(unit_has_web_user && echo 1 || echo 0)"
    fi
    do_status
}

do_status() {
    local state reason sites uncovered web site counts c
    if [[ ! -f "$STATUS_FILE" ]]; then
        say "outbound limits: not applied since boot"
        return 1
    fi
    state="$(sed -n 's/^state=//p' "$STATUS_FILE")"
    reason="$(sed -n 's/^reason=//p' "$STATUS_FILE")"
    sites="$(sed -n 's/^sites=//p' "$STATUS_FILE")"
    uncovered="$(sed -n 's/^uncovered=//p' "$STATUS_FILE")"
    web="$(sed -n 's/^web_user=//p' "$STATUS_FILE")"
    case "$state" in
        on)  say "outbound limits: on (${CONN_RATE} new connections a second, bursts to ${CONN_BURST}; ${OPEN_CONNS} open at once; no UDP)"
             [[ -z "$reason" ]] || say "  the last change was refused (${reason}): the table before it is in force; see journalctl -u ${UNIT_NAME}" ;;
        off) say "outbound limits: off on this machine"; return 0 ;;
        *)   say "outbound limits: NOT in force (${reason})"; return 1 ;;
    esac
    counts="$(current_counts)"
    for site in $sites; do
        c="$(sed -n "s/^drops_site_${site}=//p" <<< "$counts")"
        say "  ${site}: limited, ${c%%:*} packets dropped since $(date -u -d "@$(sed -n 's/^since=//p' "$STATUS_FILE")" '+%Y-%m-%d %H:%M UTC' 2>/dev/null)"
    done
    if [[ "$web" == yes ]]; then
        c="$(sed -n 's/^drops_web_user=//p' <<< "$counts")"
        say "  the web server's user (${WEB_USER}): limited, ${c%%:*} packets dropped"
    fi
    for site in $uncovered; do
        say "  ${site}: NOT limited - on Docker's default network (move_site_to_own_network.sh ${site})"
    done
    return 0
}

# --- arguments -------------------------------------------------------------------

CMD="${1:-}"; shift || true
WEB=0; OFF=0
for arg in "$@"; do
    case "$arg" in
        --web-user) WEB=1 ;;
        --off)      OFF=1 ;;
        *) die "unknown option: ${arg}" ;;
    esac
done

case "$CMD" in
    install) do_install "$WEB" "$OFF" ;;
    apply)   do_apply "$WEB" ;;
    on)      do_onoff true ;;
    off)     do_onoff false ;;
    status)  do_status ;;
    ruleset) ruleset "$WEB" ;;
    *) die "usage: joinery-limits install [--web-user] [--off] | apply [--web-user] | on | off | status | ruleset [--web-user]" ;;
esac
