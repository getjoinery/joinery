#!/usr/bin/env bash
#
# outbound_limits.sh - what each site on this machine may open toward the
# outside: a rate on new connections, a cap on connections open at once, and
# no UDP at all (node_outbound_and_transfer WP3), and how fast it may send
# (WP4). Real use never meets them; they stop a hacked plugin turning the
# server into a scanner or a flood, and bound what a runaway site costs.
#
# Version: 1.1
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
# not limited, nor is one whose run spec names any bridge but a jsnetN; status
# names it, and move_site_to_own_network.sh moves it. With
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
# THE SPEED CEILING (WP4). Everything a site sends, CEILING_MBIT at most, over
# both families together. A visitor's download from a container site leaves
# the machine from the host's proxy, not from the site's subnet, so the
# ceiling sits where the site's traffic enters the host: the bridge's incoming
# traffic is redirected (tc clsact ingress, matchall, mirred) to an ifb device
# of its own, jifbN for jsnetN, whose HTB class holds it to the ceiling with
# fq_codel under it, so the site's pages stay quick while it saturates. On
# bare metal the web server sends visitors' downloads itself, and its replies
# carry its user: the table marks what the web server's user sends
# (WEB_MARK, one bit, or-ed into the mark), and each interface a default
# route leaves by redirects marked packets (clsact egress, fw) to one ifb,
# jifbweb, so one ceiling covers every interface. Nothing else on the machine
# passes through any of it: the host agent, updates and the proxy are never
# shaped. Traffic to a site (uploads) is inbound and not shaped. Every run
# puts back whatever is missing (a rebuilt bridge loses its clsact) and leaves
# alone what is in force; what it put in force is listed in
# outbound_limits.shaped, and a line no longer wanted is taken out filter
# first, its device only once no filter is seen sending to it, so nothing is
# ever redirected to a device that is gone; clsact left holding nothing is
# removed. A bridge missing at the moment of a run is passed over (it carries
# nothing). With no default route, the web server's user's ceiling stays
# where it was and the run fails. Our filters carry preference SHAPE_PREF.
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
# web_user (yes or no), ceiling_mbit (the ceiling in force, empty for none).
# state=on with reason=nft_refused: the last change was refused and the table
# before it, with its sites and counters, is in force. state=on with
# reason=ceiling_failed: the connection limits are in force and the speed
# ceiling is not, or not everywhere (tc refused part of it).

set -uo pipefail

SELF_INSTALLED="/usr/local/sbin/joinery-limits"
UNIT_NAME="joinery-limits.service"
TIMER_NAME="joinery-limits.timer"
TABLE="joinery_limits"

# The built-in figures (WP5 makes them settable).
CONN_RATE=20
CONN_BURST=100
OPEN_CONNS=256
CEILING_MBIT=200
WEB_USER="www-data"
WEB_MARK="0x10000000"
WEB_IFB="jifbweb"
SHAPE_PREF=47

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
SHAPED_FILE="${RUN_DIR}/outbound_limits.shaped"
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
# site without one (Docker's default network), as SITE alone after a "-" line.
container_sites() {
    local spec site bridge
    local -a uncovered=()
    for spec in "$SITES_DIR"/*/run_spec; do
        [[ -f "$spec" ]] || continue
        site="$(basename "$(dirname "$spec")")"
        [[ "$site" =~ ^[A-Za-z0-9_-]{1,50}$ ]] || continue
        bridge="$(sed -n 's/^bridge=//p' "$spec" | tail -1)"
        # A site's own network is a jsnetN (run_spec); docker0 is every
        # default-network site's at once, never one site's.
        if [[ "$bridge" =~ ^jsnet[0-9]{1,3}$ ]]; then
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
        if [[ -n "$CEILING_MBIT" ]]; then
            printf '\t\tmeta skuid "%s" meta mark set meta mark | %s jump web_user\n' "$WEB_USER" "$WEB_MARK"
        else
            printf '\t\tmeta skuid "%s" jump web_user\n' "$WEB_USER"
        fi
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

# --- the speed ceiling -----------------------------------------------------------

# The interfaces the machine's default routes leave by, IPv4 and IPv6.
public_interfaces() {
    { ip -4 route show default; ip -6 route show default; } 2> /dev/null \
        | awk '{ for (i = 1; i < NF; i++) if ($i == "dev") print $(i + 1) }' \
        | grep -E '^[A-Za-z0-9_.-]{1,15}$' | sort -u
}

# Where the ceiling goes, as DEV DIRECTION IFB lines: each site's bridge
# (SITE BRIDGE lines on stdin) and, with WEB_USER, each public interface.
shape_plan() {  # WEB_USER(0|1)
    local site bridge dev
    [[ -n "$CEILING_MBIT" ]] || return 0
    while read -r site bridge; do
        [[ -n "$bridge" ]] && echo "${bridge} ingress jifb${bridge#jsnet}"
    done
    if [[ "$1" == 1 ]]; then
        for dev in $(public_interfaces); do echo "${dev} egress ${WEB_IFB}"; done
    fi
}

# Whether COMMAND's output holds a line matching the extended regex PATTERN.
# The output is read whole first: piped into grep -q, which stops at its first
# match, tc is killed writing the rest and pipefail calls the pipeline failed.
shows() {  # PATTERN COMMAND...
    local pattern="$1" out
    shift
    out="$("$@" 2> /dev/null)"
    grep -qE -- "$pattern" <<< "$out"
}

# Puts one line of the plan in force, leaving alone what already is. Every
# step but the class's rate is checked first: HTB, clsact and matchall refuse
# a replace of themselves, and the class's replace changes it in place. A
# filter whose device was lost shows "to device *)" and is put back against
# the new one: mirred never finds a device again by name.
shape_one() {  # DEV DIRECTION IFB
    local dev="$1" dir="$2" ifb="$3"
    local -a match=(matchall)
    [[ "$dir" == egress ]] && match=(handle "${WEB_MARK}/${WEB_MARK}" fw)
    # A bridge not there at this moment (Docker making its networks again as
    # it starts) carries nothing; the next run, or the site's own start, puts
    # its part on.
    ip link show dev "$dev" > /dev/null 2>&1 || return 0
    if ! ip link show dev "$ifb" > /dev/null 2>&1; then
        # Loaded by hand, ifb would make two spare devices of its own.
        [[ -d /sys/module/ifb ]] || modprobe -q ifb numifbs=0 2> /dev/null
        ip link add "$ifb" type ifb || return 1
    fi
    ip link set dev "$ifb" up || return 1
    shows '^qdisc htb 1: root' tc qdisc show dev "$ifb" \
        || tc qdisc replace dev "$ifb" root handle 1: htb default 10 || return 1
    tc class replace dev "$ifb" parent 1: classid 1:10 htb rate "${CEILING_MBIT}mbit" ceil "${CEILING_MBIT}mbit" quantum 60000 || return 1
    shows '^qdisc fq_codel 10: parent 1:10 ' tc qdisc show dev "$ifb" \
        || tc qdisc replace dev "$ifb" parent 1:10 handle 10: fq_codel || return 1
    shows '^qdisc clsact ' tc qdisc show dev "$dev" || tc qdisc add dev "$dev" clsact || return 1
    if ! redirects_to "$dev" "$dir" "$ifb"; then
        tc filter del dev "$dev" "$dir" pref "$SHAPE_PREF" 2> /dev/null
        tc filter add dev "$dev" "$dir" protocol all pref "$SHAPE_PREF" "${match[@]}" \
            action mirred egress redirect dev "$ifb" || return 1
        say "outbound limits: speed ceiling put in force on ${dev}"
    fi
}

# Whether DEV still sends anything to IFB at our preference.
redirects_to() {  # DEV DIRECTION IFB
    shows "to device ${3}\\)" tc filter show dev "$1" "$2" pref "$SHAPE_PREF"
}

# Brings the ceiling to PLAN (empty: none anywhere). 1 when tc refused any of
# it, putting it in force or taking it out.
shape() {  # PLAN
    local plan="$1" old dev dir ifb failed=0 keep="" stuck=""
    old="$(cat "$SHAPED_FILE" 2> /dev/null)"
    # What is no longer wanted: the filters first, then each device no wanted
    # line uses. A device is removed only once no filter is seen sending to
    # it, so nothing is ever redirected to a device that is gone: a filter
    # that will not come off keeps its line and its device, and the next run
    # tries again.
    while read -r dev dir ifb; do
        [[ -n "$ifb" ]] || continue
        grep -qxF "${dev} ${dir} ${ifb}" <<< "$plan" && continue
        ip link show dev "$dev" > /dev/null 2>&1 || continue
        tc filter del dev "$dev" "$dir" pref "$SHAPE_PREF" 2> /dev/null
        if redirects_to "$dev" "$dir" "$ifb"; then
            keep+="${dev} ${dir} ${ifb}"$'\n'; stuck+=" ${ifb} "; failed=1
            echo "joinery-limits: tc would not take the speed ceiling off ${dev}" >&2
            continue
        fi
        # clsact this unit added, now holding nothing in either direction.
        if [[ -z "$(tc filter show dev "$dev" ingress 2> /dev/null)$(tc filter show dev "$dev" egress 2> /dev/null)" ]]; then
            tc qdisc del dev "$dev" clsact 2> /dev/null
        fi
    done <<< "$old"
    while read -r dev dir ifb; do
        [[ -n "$ifb" ]] || continue
        awk -v i="$ifb" '$3 == i { f = 1 } END { exit !f }' <<< "$plan" && continue
        [[ "$stuck" == *" ${ifb} "* ]] && continue
        ip link show dev "$ifb" > /dev/null 2>&1 && ip link del dev "$ifb"
    done <<< "$old"
    mkdir -p "$RUN_DIR"
    if [[ -z "$plan$keep" ]]; then
        rm -f "$SHAPED_FILE"
        return 0
    fi
    # Listed before it is written, so a line half put in force is taken out
    # by the run that no longer wants it.
    { [[ -z "$plan" ]] || printf '%s\n' "$plan"; printf '%s' "$keep"; } > "$SHAPED_FILE"
    while read -r dev dir ifb; do
        [[ -n "$ifb" ]] || continue
        shape_one "$dev" "$dir" "$ifb" || { failed=1; echo "joinery-limits: tc refused the speed ceiling on ${dev}" >&2; }
    done <<< "$plan"
    return "$failed"
}

# write_status STATE REASON [SITES UNCOVERED WEB_USER CEILING_MBIT]
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
        echo "ceiling_mbit=${6:-}"
        echo "applied_at=$(date -u +%s)"
    } > "${STATUS_FILE}.tmp" && chmod 644 "${STATUS_FILE}.tmp" && mv -f "${STATUS_FILE}.tmp" "$STATUS_FILE"
}

# Whether the installed unit limits the web server's user.
unit_has_web_user() {
    [[ -f "$UNIT_PATH" ]] && grep -q -- '--web-user' "$UNIT_PATH"
}

do_apply() {  # WEB_USER(0|1)
    local web="$1" body want sites uncovered line pairs webw unchanged=0
    need_root apply
    # A hand run without the flag on a machine whose unit has it keeps it.
    unit_has_web_user && web=1
    webw="$([[ $web == 1 ]] && echo yes || echo no)"
    mkdir -p "$RUN_DIR"
    exec 9> "$LOCK_FILE"
    flock -w 60 9 || die "another run holds ${LOCK_FILE}"
    if ! command -v nft > /dev/null 2>&1; then
        shape ""
        write_status refused nft_missing
        die "nft is not installed; no limits are in force"
    fi

    if ! limits_enabled; then
        remove_table || die "could not remove the limits table"
        local unshaped=1
        shape "" || unshaped=0
        write_status off off
        say "outbound limits: off on this machine (${HOST_FILE})"
        (( unshaped )) || die "part of the speed ceiling would not come off (see above); the next run tries again"
        return 0
    fi
    if ! resolver_is_loopback; then
        remove_table
        shape ""
        write_status refused resolver_not_loopback
        die "the host's resolver is not a loopback address ($(resolvers | paste -sd ' ')): dropping a site's UDP would break its name lookups, so no limits are in force. Point /etc/resolv.conf at the local resolver (systemd-resolved, 127.0.0.53) and run this again"
    fi

    sites=""; uncovered=""; pairs=""
    local past=0
    while IFS= read -r line; do
        if [[ "$line" == "-" ]]; then past=1; continue; fi
        [[ -n "$line" ]] || continue
        if (( past )); then uncovered+="${uncovered:+ }${line}"; else sites+="${sites:+ }${line%% *}"; pairs+="${line}"$'\n'; fi
    done <<< "$(container_sites)"

    want="$(ruleset "$web")"
    if [[ -f "$APPLIED_FILE" ]] && [[ "$(cat "$APPLIED_FILE")" == "$want" ]] \
            && nft list table inet "$TABLE" > /dev/null 2>&1; then
        unchanged=1
    else
        body="$(ruleset "$web" "$(current_counts)")"
    fi
    if (( ! unchanged )) && ! printf '%s\n' "$body" | nft -f -; then
        # The table before this change is still in force, its counters still
        # running: the status keeps saying so, with its sites and its since, so
        # a site's drops are neither hidden nor counted twice; its ceiling is
        # left as it was too. The unit fails, which the host report names, and
        # the next run tries again.
        if [[ -f "$STATUS_FILE" ]] && grep -qx 'state=on' "$STATUS_FILE" \
                && nft list table inet "$TABLE" > /dev/null 2>&1; then
            write_status on nft_refused "$(sed -n 's/^sites=//p' "$STATUS_FILE")" \
                "$(sed -n 's/^uncovered=//p' "$STATUS_FILE")" "$(sed -n 's/^web_user=//p' "$STATUS_FILE")" \
                "$(sed -n 's/^ceiling_mbit=//p' "$STATUS_FILE")"
        else
            shape ""
            write_status refused nft_refused "$sites" "$uncovered"
        fi
        die "nft refused the limits; the table is as it was"
    fi
    (( unchanged )) || printf '%s\n' "$want" > "$APPLIED_FILE"

    # The ceiling is checked on every run, changed table or not: a site's
    # bridge made again by a rebuild has lost its part.
    local plan noroute=0
    plan="$(shape_plan "$web" <<< "$pairs")"
    # With no default route this moment, the web server's user's ceiling stays
    # on the interfaces it was on rather than coming off, and the run fails
    # until a route is back.
    if [[ "$web" == 1 && -n "$CEILING_MBIT" ]] && ! grep -q ' egress ' <<< "$plan"; then
        noroute=1
        plan="$(printf '%s\n%s\n' "$plan" "$(grep ' egress ' "$SHAPED_FILE" 2> /dev/null)" | sed '/^$/d')"
    fi
    if ! shape "$plan" || (( noroute )); then
        write_status on ceiling_failed "$sites" "$uncovered" "$webw" ""
        (( noroute )) && die "the machine has no default route: the web server's user's speed ceiling stays on the interfaces it was on until a route is back; the connection limits are in force"
        die "the speed ceiling is not in force everywhere (see above); the connection limits are"
    fi
    write_status on "" "$sites" "$uncovered" "$webw" "$CEILING_MBIT"
    if (( unchanged )); then
        say "outbound limits: unchanged"
        return 0
    fi
    say "outbound limits: in force for ${sites:-no container site}$([[ $web == 1 ]] && echo " and the web server's user (${WEB_USER})")"
    [[ -z "$uncovered" ]] || say "outbound limits: not limited, not on a jsnetN network of their own: ${uncovered} (move_site_to_own_network.sh moves a site off Docker's default network)"
    return 0
}

# --- the unit --------------------------------------------------------------------

unit_text() {  # WEB_USER(0|1)
    cat <<EOF
[Unit]
Description=Joinery: outbound limits and speed ceiling for this machine's sites (outbound_limits.sh)
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
Description=Joinery: keep the outbound limits in force (outbound_limits.sh)

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
    local state reason sites uncovered web ceiling site counts c
    if [[ ! -f "$STATUS_FILE" ]]; then
        say "outbound limits: not applied since boot"
        return 1
    fi
    state="$(sed -n 's/^state=//p' "$STATUS_FILE")"
    reason="$(sed -n 's/^reason=//p' "$STATUS_FILE")"
    sites="$(sed -n 's/^sites=//p' "$STATUS_FILE")"
    uncovered="$(sed -n 's/^uncovered=//p' "$STATUS_FILE")"
    web="$(sed -n 's/^web_user=//p' "$STATUS_FILE")"
    ceiling="$(sed -n 's/^ceiling_mbit=//p' "$STATUS_FILE")"
    case "$state" in
        on)  say "outbound limits: on (${CONN_RATE} new connections a second, bursts to ${CONN_BURST}; ${OPEN_CONNS} open at once; no UDP; $([[ -n "$ceiling" ]] && echo "sending at ${ceiling} Mbit/s at most" || echo "no speed ceiling"))"
             case "$reason" in
                 "") ;;
                 ceiling_failed) say "  the speed ceiling is not in force everywhere (tc refused part of it); see journalctl -u ${UNIT_NAME}" ;;
                 *) say "  the last change was refused (${reason}): the table before it is in force; see journalctl -u ${UNIT_NAME}" ;;
             esac ;;
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
        say "  ${site}: NOT limited - not on a jsnetN network of its own (move_site_to_own_network.sh ${site} moves it off Docker's default network)"
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
