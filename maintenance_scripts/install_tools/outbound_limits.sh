#!/usr/bin/env bash
#
# outbound_limits.sh - what each site on this machine may open toward the
# outside: a rate on new connections, a cap on connections open at once, and
# no UDP at all (node_outbound_and_transfer WP3), and how fast it may send
# (WP4), at figures this machine's owner sets (WP5). Real use never meets
# them; they stop a hacked plugin turning the server into a scanner or a
# flood, and bound what a runaway site costs.
#
# Version: 1.3
#
#   outbound_limits.sh install [--web-user] [--off] [FIGURES] [--by=plane]
#                         Root. Copies this script to /usr/local/sbin/joinery-limits
#                         and the run spec helper beside it, writes joinery-limits.service
#                         (runs at boot after Docker) and joinery-limits.timer (every five
#                         minutes), and runs it. --web-user: this machine serves its sites
#                         itself (install.sh server), so the web server's user is limited;
#                         a machine that once had it keeps it. --off: the limits are off on
#                         this machine (the host file says so). FIGURES (below) are the
#                         machine's own; --by=plane, the management node installed it.
#                         Run again, it rewrites only what differs.
#   joinery-limits refresh
#                         Root. Rewrites the installed copies only where they differ, and
#                         runs the unit only then (host housekeeping, on every converge).
#   joinery-limits apply [--web-user]
#                         Root. Writes the limits (the unit's command). Run by
#                         install.sh site, the rebase and move scripts and
#                         remove_account.sh through the unit, so one run at a time.
#   joinery-limits on | off
#                         Root. Turns every limit on or off on this machine, UDP
#                         drop included, and applies it.
#   joinery-limits set FIGURES [--site=SITE] [--by=plane]
#   joinery-limits ceiling MBIT|off|default [--site=SITE]
#   joinery-limits conn-rate|conn-burst|open-conns N|default [--site=SITE]
#                         Root. Sets the machine's figures (the host file), or one
#                         container site's (its run spec), and applies them. default
#                         takes a figure back to the one below it: a site's to the
#                         machine's, the machine's to the built-in one. --by=plane says
#                         the management node set them, which the site's settings page
#                         repeats.
#   joinery-limits show   Prints each figure, where it comes from, and what is in force.
#   joinery-limits status Prints what is in force, for which sites, and the drops.
#   joinery-limits ruleset [--web-user]
#                         Prints the table as apply would write it.
#
#   FIGURES: --ceiling=MBIT|off  --conn-rate=N  --conn-burst=N  --open-conns=N
#
# THE FIGURES (WP5). Built in: a speed ceiling of 200 Mbit/s, 20 new connections
# a second with bursts to 100, and 256 open at once. The machine's own, where
# only root writes: the host file, /etc/joinery/outbound_limits.json
# (ceiling_mbit, a number or "off"; conn_rate, conn_burst, open_conns; set_by,
# "plane" when the management node set them). A container site's own, in its
# run spec beside its other caps (outbound_ceiling, outbound_conn_rate,
# outbound_conn_burst, outbound_open_conns, and outbound_set_by=plane when the
# management node set them), so a rebuild, rebase or move keeps them. A site
# is told the management node set its figures when it set the machine's or
# that site's own; a site's own set by hand on the machine drops the mark. A figure that is not one (a hand edit) is passed over for the one
# below it, with a warning. On bare metal every site shares the web server's
# user, so the machine's figures are the sites'.
#
# A SITE CAN TIGHTEN ITS CEILING, NEVER LOOSEN IT. The site's own setting,
# outbound_speed_ceiling_mbit, is read from the site on every run (a container
# through docker exec as www-data; a bare-metal site as www-data on the host)
# with utils/outbound_site_ceiling.php, and what comes back is untrusted: a
# whole number of Mbit/s or nothing, applied only when lower than root's
# figure. On bare metal the lowest of the machine's sites counts, since they
# share one ceiling. Every run tells each site the outcome and what is in
# force, in /run/joinery/outbound_limits.site (inside its container; on the
# host for bare metal), so its settings page can say so: on, off, refused,
# ceiling_failed, or uncovered (not on a network of its own). The site cannot ask the host to run
# anything; the host reads.
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
# site already holds its open-connection cap; a new connection dropped above
# its rate a second with bursts to its burst. TCP sends a dropped opening again
# a second later, so a short burst is slowed, not refused. Each drop counts in
# the site's named counter (drops_site_SITE, drops_web_user), which
# host_report.sh reads. Both families: the table is in nftables' inet family,
# so one limit covers IPv4 and IPv6 together. It sits beside Docker's tables
# and the multi-tenant walls (inet joinery_site_walls): a packet any table
# drops is dropped, and an accept here skips nothing there. Chain and counter
# names carry the site name unquoted: nft's names take letters, digits, _ - .
# and /, which covers every site name ([A-Za-z0-9_-], behind a letter prefix),
# and it lists them back unquoted, which is how host_report.sh finds them.
#
# THE SPEED CEILING (WP4). Everything a site sends, its ceiling at most, over
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
# alone what is in force, holding each class to its figure; what it put in
# force is listed in outbound_limits.shaped, and a line no longer wanted is
# taken out filter first, its device only once no filter is seen sending to
# it, so nothing is ever redirected to a device that is gone; clsact left
# holding nothing is removed. A bridge missing at the moment of a run is
# passed over (it carries nothing). With no default route, the web server's
# user's ceiling stays where it was and the run fails. Our filters carry
# preference SHAPE_PREF.
#
# THE RESOLVER. A site's name lookups never leave as its own UDP only while the
# host's resolver is a loopback address: Docker (20.10 and later; WP2 already
# needs 27 for IPv6) then forwards a container's lookups from the host's side, and on bare metal the resolver's queries run as
# its own user. Were /etc/resolv.conf to name an outside resolver, the UDP drop
# would break every site's DNS, so apply refuses: it removes the table, says
# why in the status file, and fails the unit.
#
# KEPT ACROSS RUNS. The table is replaced whole, in one transaction, only when
# what it would hold changes (a site added, moved or removed, or a figure
# changed): a replace starts the rate and open-connection counts afresh. The
# drop counters carry their values into the new table. Nothing here survives a
# reboot; the unit puts the table back at boot and the counters start again
# from zero, which the status file's since says.
#
# STATUS. /run/joinery/outbound_limits.status, key=value lines read by
# host_report.sh: state (on, off, refused), reason (a code), since (when the
# counters began), sites (limited), uncovered (on Docker's default network),
# web_user (yes or no), ceiling_mbit (the machine's ceiling, empty for none),
# conn_rate, conn_burst, open_conns (the machine's), set_by, web_ceiling_mbit
# (the web server's user's ceiling in force), site_figures (each limited
# site's SITE:CEILING:RATE:BURST:OPEN in force, - for no ceiling), site_set_by
# (the sites in site_figures whose own figures the management node set).
# state=off keeps the machine's figures and site_figures as configured: what
# turning the limits on puts in force (a site's own lower setting aside).
# state=on with reason=nft_refused: the last change was refused and the table
# before it, with its sites and counters, is in force. state=on with
# reason=ceiling_failed: the connection limits are in force and the speed
# ceiling is not, or not everywhere (tc refused part of it).

set -uo pipefail

SELF_INSTALLED="/usr/local/sbin/joinery-limits"
UNIT_NAME="joinery-limits.service"
TIMER_NAME="joinery-limits.timer"
TABLE="joinery_limits"

# The built-in figures.
DEF_CEILING=200
DEF_RATE=20
DEF_BURST=100
DEF_OPEN=256
WEB_USER="www-data"
WEB_MARK="0x10000000"
WEB_IFB="jifbweb"
SHAPE_PREF=47
# What a site answers its own ceiling with, relative to its site root.
SITE_CEILING_SCRIPT="public_html/utils/outbound_site_ceiling.php"
# Where a site is told what is in force (inside its container, or on the host).
SITE_TOLD="/run/joinery/outbound_limits.site"

# Tests point these into a scratch directory.
ROOT="${JOINERY_LIMITS_ROOT:-}"
UNIT_PATH="${ROOT}/etc/systemd/system/${UNIT_NAME}"
TIMER_PATH="${ROOT}/etc/systemd/system/${TIMER_NAME}"
INSTALLED="${ROOT}${SELF_INSTALLED}"
LIB_INSTALLED="${ROOT}/usr/local/lib/joinery-limits/_site_run_spec.sh"
HOST_FILE="${ROOT}/etc/joinery/outbound_limits.json"
SITES_DIR="${ROOT}/etc/joinery/sites"
WEB_ROOTS="${ROOT}/var/www/html"
RESOLV_CONF="${ROOT}/etc/resolv.conf"
RUN_DIR="${ROOT}/run/joinery"
STATUS_FILE="${RUN_DIR}/outbound_limits.status"
APPLIED_FILE="${RUN_DIR}/outbound_limits.applied"
SHAPED_FILE="${RUN_DIR}/outbound_limits.shaped"
LOCK_FILE="${RUN_DIR}/outbound_limits.lock"
BARE_TOLD="${ROOT}${SITE_TOLD}"

say() { printf '%s\n' "$*"; }
die() { printf 'joinery-limits: %s\n' "$*" >&2; exit 1; }
need_root() { [[ -n "$ROOT" || "$EUID" -eq 0 ]] || die "$1 needs root"; }

# The run spec helper: beside this script in a release or bundle, or where
# install put it beside the installed copy.
SELF_DIR="$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")" && pwd)"
spec_lib() {
    if [[ -f "${SELF_DIR}/_site_run_spec.sh" ]]; then echo "${SELF_DIR}/_site_run_spec.sh"
    elif [[ -f "$LIB_INSTALLED" ]]; then echo "$LIB_INSTALLED"
    else return 1; fi
}

# --- the figures -----------------------------------------------------------------

# Whether VALUE is a figure of KIND: a whole number in bounds, or off for a
# ceiling.
valid_figure() {  # ceiling|count VALUE
    if [[ "$1" == ceiling ]]; then
        [[ "$2" == off || "$2" =~ ^[1-9][0-9]{0,5}$ ]]
    else
        [[ "$2" =~ ^[1-9][0-9]{0,6}$ ]]
    fi
}

# The host file's figures, as key=value lines; a value that is not one is
# printed as bad=KEY.
host_file_figures() {
    [[ -f "$HOST_FILE" ]] || return 0
    python3 - "$HOST_FILE" <<'PY'
import json, re, sys
try:
    with open(sys.argv[1]) as f:
        d = json.load(f)
except Exception:
    sys.exit(0)
if not isinstance(d, dict):
    sys.exit(0)
for k in ("ceiling_mbit", "conn_rate", "conn_burst", "open_conns"):
    if k not in d:
        continue
    v = d[k]
    if k == "ceiling_mbit" and v == "off":
        print(k + "=off")
    elif isinstance(v, int) and not isinstance(v, bool) and 1 <= v <= (999999 if k == "ceiling_mbit" else 9999999):
        print(k + "=" + str(v))
    else:
        print("bad=" + k)
if d.get("set_by") == "plane":
    print("set_by=plane")
PY
}

# Loads the machine's figures: M_CEILING (empty for none), M_RATE, M_BURST,
# M_OPEN, SET_BY.
load_machine_figures() {
    local k v
    M_CEILING="$DEF_CEILING"; M_RATE="$DEF_RATE"; M_BURST="$DEF_BURST"; M_OPEN="$DEF_OPEN"; SET_BY=""
    while IFS='=' read -r k v; do
        case "$k" in
            ceiling_mbit) if [[ "$v" == off ]]; then M_CEILING=""; else M_CEILING="$v"; fi ;;
            conn_rate)  M_RATE="$v" ;;
            conn_burst) M_BURST="$v" ;;
            open_conns) M_OPEN="$v" ;;
            set_by)     SET_BY="$v" ;;
            bad) echo "joinery-limits: ${HOST_FILE} has a ${v} that is not a figure; the built-in one stands" >&2 ;;
        esac
    done <<< "$(host_file_figures)"
}

# One site's figures from root: its run spec's own, else the machine's, as
# CEILING RATE BURST OPEN (CEILING - for none). load_machine_figures first.
site_figures() {  # SITE
    local spec="${SITES_DIR}/$1/run_spec" c r b o v
    c="${M_CEILING:--}"; r="$M_RATE"; b="$M_BURST"; o="$M_OPEN"
    if [[ -f "$spec" ]]; then
        v="$(sed -n 's/^outbound_ceiling=//p' "$spec" | tail -1)"
        if [[ -n "$v" ]]; then
            if valid_figure ceiling "$v"; then [[ "$v" == off ]] && c="-" || c="$v"
            else echo "joinery-limits: $1's run spec has outbound_ceiling=${v:0:20}, not a figure; the machine's stands" >&2; fi
        fi
        local key var
        for key in conn_rate conn_burst open_conns; do
            v="$(sed -n "s/^outbound_${key}=//p" "$spec" | tail -1)"
            [[ -n "$v" ]] || continue
            if valid_figure count "$v"; then
                case "$key" in conn_rate) r="$v" ;; conn_burst) b="$v" ;; open_conns) o="$v" ;; esac
            else
                echo "joinery-limits: $1's run spec has outbound_${key}=${v:0:20}, not a figure; the machine's stands" >&2
            fi
        done
    fi
    echo "$c $r $b $o"
}

# Who set SITE's figures: plane where the management node set its own (its run
# spec says so) or the machine's (the host file says so); else empty.
site_set_by() {  # SITE
    if [[ "$SET_BY" == plane ]]; then
        echo plane
    elif grep -qx 'outbound_set_by=plane' "${SITES_DIR}/$1/run_spec" 2> /dev/null; then
        echo plane
    fi
}

# Each container site's figures from root, as the status's site_figures
# (SITE:CEILING:RATE:BURST:OPEN): what turning the limits on would put in
# force, a site's own lower setting aside. load_machine_figures first.
configured_site_figures() {
    local line out="" c r b o
    while IFS= read -r line; do
        [[ "$line" == "-" ]] && break
        [[ -n "$line" ]] || continue
        read -r c r b o <<< "$(site_figures "${line%% *}")"
        out+="${out:+ }${line%% *}:${c}:${r}:${b}:${o}"
    done <<< "$(container_sites)"
    echo "$out"
}

# The lower of a ceiling (- for none) and a site's own figure (empty for none).
lower_ceiling() {  # CEILING OWN
    if [[ -z "$2" ]]; then echo "$1"
    elif [[ "$1" == "-" ]] || (( $2 < $1 )); then echo "$2"
    else echo "$1"; fi
}

# The ceilings in force, in words: stdin "SITE BRIDGE IN_FORCE" lines, and the
# web server's user's (empty: not limited here; -: no ceiling).
ceilings_in_force() {  # WEB_IN_FORCE
    local site bridge f out=""
    while read -r site bridge f; do
        [[ -n "$site" ]] || continue
        out+="${out:+, }${site} $([[ "$f" == "-" ]] && echo none || echo "${f} Mbit/s")"
    done
    [[ -z "$1" ]] || out+="${out:+, }the web server's user $([[ "$1" == "-" ]] && echo none || echo "${1} Mbit/s")"
    echo "${out:-none}"
}

# What a site's own setting asks for: a whole number of Mbit/s, or nothing.
# Untrusted: anything else the site prints is nothing.
own_ceiling_answer() {
    local v
    v="$(head -c 16 | tr -d '[:space:]')"
    [[ "$v" =~ ^[1-9][0-9]{0,5}$ ]] && echo "$v"
}

# A container site's own ceiling, read inside it as the web server's user.
container_own_ceiling() {  # SITE
    command -v docker > /dev/null 2>&1 || return 0
    timeout 30 docker exec -u "$WEB_USER" "$1" timeout 20 php "/var/www/html/$1/${SITE_CEILING_SCRIPT}" 2> /dev/null \
        | own_ceiling_answer
}

# The bare-metal sites on this machine: each /var/www/html/SITE that is a site.
bare_sites() {
    local d
    for d in "$WEB_ROOTS"/*/; do
        d="${d%/}"
        [[ -f "${d}/config/Globalvars_site.php" && -f "${d}/${SITE_CEILING_SCRIPT}" ]] || continue
        [[ "$(basename "$d")" =~ ^[A-Za-z0-9_-]{1,50}$ ]] && basename "$d"
    done
}

# The lowest ceiling the machine's bare-metal sites ask for, or nothing.
bare_own_ceiling() {
    local site v low=""
    for site in $(bare_sites); do
        v="$(timeout 30 runuser -u "$WEB_USER" -- timeout 20 php "${WEB_ROOTS}/${site}/${SITE_CEILING_SCRIPT}" 2> /dev/null | own_ceiling_answer)"
        [[ -n "$v" ]] || continue
        if [[ -z "$low" ]] || (( v < low )); then low="$v"; fi
    done
    echo "$low"
}

# The lines a site is told: the limits' state (on, off, refused,
# ceiling_failed, or uncovered for a site not on a network of its own), root's
# ceiling for it, the one in force, and who set the figures. Every run tells
# every site, whatever its outcome, so what a site says is never left over
# from an earlier run: the file lives in the container's own layer and
# survives its restarts.
told_text() {  # STATE HOST_CEILING(- none) IN_FORCE(- none) [SET_BY, default the machine's]
    printf 'state=%s\nhost_ceiling_mbit=%s\nceiling_mbit=%s\nset_by=%s\n' "$1" "${2#-}" "${3#-}" "${4-${SET_BY:-}}"
}

tell_container() {  # SITE STATE HOST_CEILING IN_FORCE
    command -v docker > /dev/null 2>&1 || return 0
    told_text "$2" "$3" "$4" "$(site_set_by "$1")" | timeout 30 docker exec -i -u root "$1" sh -c \
        "mkdir -p /run/joinery && chmod 755 /run/joinery && cat > ${SITE_TOLD}.tmp && chmod 644 ${SITE_TOLD}.tmp && mv -f ${SITE_TOLD}.tmp ${SITE_TOLD}" \
        > /dev/null 2>&1
}

tell_bare() {  # STATE HOST_CEILING IN_FORCE
    mkdir -p "$(dirname "$BARE_TOLD")"
    told_text "$1" "$2" "$3" > "${BARE_TOLD}.tmp" && chmod 644 "${BARE_TOLD}.tmp" && mv -f "${BARE_TOLD}.tmp" "$BARE_TOLD"
}

# Tells every container site, and the bare-metal sites with WEB_USER, that the
# limits are STATE with no figure in force (off, or refused).
tell_everyone() {  # STATE WEB_USER(0|1)
    local line
    while IFS= read -r line; do
        [[ -n "$line" && "$line" != "-" ]] && tell_container "${line%% *}" "$1" - -
    done <<< "$(container_sites)"
    [[ "$2" == 1 ]] && tell_bare "$1" - -
    return 0
}

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

# Writes KEY=VALUE pairs into the host file, keeping every other key it holds.
# A value of true or false is a boolean, a number a number, "default" removes
# the key, anything else a string.
host_file_set() {  # KEY=VALUE...
    mkdir -p "$(dirname "$HOST_FILE")"
    python3 - "$HOST_FILE" "$@" <<'PY' || die "could not write ${HOST_FILE}"
import json, os, sys
path = sys.argv[1]
d = {}
try:
    with open(path) as f:
        d = json.load(f)
    if not isinstance(d, dict):
        d = {}
except Exception:
    pass
for pair in sys.argv[2:]:
    k, v = pair.split("=", 1)
    if v == "default":
        d.pop(k, None)
    elif v in ("true", "false"):
        d[k] = (v == "true")
    elif v.isdigit():
        d[k] = int(v)
    else:
        d[k] = v
tmp = path + ".tmp"
with open(tmp, "w") as f:
    json.dump(d, f, indent=2)
    f.write("\n")
os.chmod(tmp, 0o644)
os.replace(tmp, path)
PY
}

set_enabled() { host_file_set "enabled=$1"; }  # true|false

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
sender_chain() {  # CHAIN COUNTER RATE BURST OPEN
    cat <<EOF
	chain $1 {
		meta l4proto udp counter name "$2" drop
		ct state new ct count over $5 counter name "$2" drop
		ct state new limit rate over $3/second burst $4 packets counter name "$2" drop
	}
EOF
}

# The table's body, with every counter at zero. COUNTS (name=packets:bytes
# lines) carries values over from the table being replaced.
table_body() {  # WEB_USER(0|1) [COUNTS]
    local web="$1" counts="${2:-}" line site bridge v p b c r o
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
        for i in "${!sites[@]}"; do
            read -r c r b o <<< "$(site_figures "${sites[$i]}" 2> /dev/null)"
            sender_chain "site_${sites[$i]}" "drops_site_${sites[$i]}" "$r" "$b" "$o"
        done
    fi
    if [[ "$web" == 1 ]]; then
        printf '\tchain output {\n\t\ttype filter hook output priority -10; policy accept;\n'
        printf '\t\toifname "lo" accept\n'
        if [[ -n "$M_CEILING" ]]; then
            printf '\t\tmeta skuid "%s" meta mark set meta mark | %s jump web_user\n' "$WEB_USER" "$WEB_MARK"
        else
            printf '\t\tmeta skuid "%s" jump web_user\n' "$WEB_USER"
        fi
        printf '\t}\n'
        sender_chain web_user drops_web_user "$M_RATE" "$M_BURST" "$M_OPEN"
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

# Where the ceiling goes, as DEV DIRECTION IFB MBIT lines: each site's bridge
# (SITE BRIDGE MBIT lines on stdin, - for none) and, with WEB_MBIT, each
# public interface.
shape_plan() {  # WEB_MBIT(empty: none)
    local site bridge mbit dev
    while read -r site bridge mbit; do
        [[ -n "$bridge" && "$mbit" =~ ^[0-9]+$ ]] && echo "${bridge} ingress jifb${bridge#jsnet} ${mbit}"
    done
    if [[ -n "$1" ]]; then
        for dev in $(public_interfaces); do echo "${dev} egress ${WEB_IFB} $1"; done
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
shape_one() {  # DEV DIRECTION IFB MBIT
    local dev="$1" dir="$2" ifb="$3" mbit="$4"
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
    tc class replace dev "$ifb" parent 1: classid 1:10 htb rate "${mbit}mbit" ceil "${mbit}mbit" quantum 60000 || return 1
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
# it, putting it in force or taking it out. A line is the redirect (its first
# three fields) and its figure; a figure changed changes the class in place.
shape() {  # PLAN
    local plan="$1" wanted old dev dir ifb mbit failed=0 keep="" stuck=""
    wanted="$(awk 'NF >= 3 { print $1, $2, $3 }' <<< "$plan")"
    old="$(cat "$SHAPED_FILE" 2> /dev/null)"
    # What is no longer wanted: the filters first, then each device no wanted
    # line uses. A device is removed only once no filter is seen sending to
    # it, so nothing is ever redirected to a device that is gone: a filter
    # that will not come off keeps its line and its device, and the next run
    # tries again.
    while read -r dev dir ifb mbit; do
        [[ -n "$ifb" ]] || continue
        grep -qxF "${dev} ${dir} ${ifb}" <<< "$wanted" && continue
        ip link show dev "$dev" > /dev/null 2>&1 || continue
        tc filter del dev "$dev" "$dir" pref "$SHAPE_PREF" 2> /dev/null
        if redirects_to "$dev" "$dir" "$ifb"; then
            keep+="${dev} ${dir} ${ifb}${mbit:+ ${mbit}}"$'\n'; stuck+=" ${ifb} "; failed=1
            echo "joinery-limits: tc would not take the speed ceiling off ${dev}" >&2
            continue
        fi
        # clsact this unit added, now holding nothing in either direction.
        if [[ -z "$(tc filter show dev "$dev" ingress 2> /dev/null)$(tc filter show dev "$dev" egress 2> /dev/null)" ]]; then
            tc qdisc del dev "$dev" clsact 2> /dev/null
        fi
    done <<< "$old"
    while read -r dev dir ifb mbit; do
        [[ -n "$ifb" ]] || continue
        awk -v i="$ifb" '$3 == i { f = 1 } END { exit !f }' <<< "$wanted" && continue
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
    while read -r dev dir ifb mbit; do
        [[ -n "$ifb" ]] || continue
        shape_one "$dev" "$dir" "$ifb" "$mbit" || { failed=1; echo "joinery-limits: tc refused the speed ceiling on ${dev}" >&2; }
    done <<< "$plan"
    return "$failed"
}

# write_status STATE REASON [SITES UNCOVERED WEB_USER CEILING_OK SITE_FIGURES WEB_CEILING]
# The machine's figures are load_machine_figures'; CEILING_OK 0 leaves the
# ceiling empty (not in force).
write_status() {
    local since="" ceiling="" web_ceiling=""
    mkdir -p "$RUN_DIR"
    [[ -f "$STATUS_FILE" ]] && since="$(sed -n 's/^since=//p' "$STATUS_FILE")"
    # The counters begin when the table first holds them since boot (/run is
    # emptied at boot, as the table is).
    if [[ "$1" != on ]]; then
        since=""
    elif [[ ! "$since" =~ ^[0-9]+$ ]]; then
        since="$(date -u +%s)"
    fi
    if [[ "${6:-0}" == 1 ]]; then ceiling="${M_CEILING:-}"; web_ceiling="${8:-}"; fi
    {
        echo "state=$1"
        echo "reason=$2"
        echo "since=${since}"
        echo "sites=${3:-}"
        echo "uncovered=${4:-}"
        echo "web_user=${5:-no}"
        echo "ceiling_mbit=${ceiling}"
        echo "conn_rate=${M_RATE:-}"
        echo "conn_burst=${M_BURST:-}"
        echo "open_conns=${M_OPEN:-}"
        echo "set_by=${SET_BY:-}"
        echo "web_ceiling_mbit=${web_ceiling}"
        echo "site_figures=${7:-}"
        echo "site_set_by=$(for e in ${7:-}; do
            grep -qx 'outbound_set_by=plane' "${SITES_DIR}/${e%%:*}/run_spec" 2> /dev/null && printf '%s ' "${e%%:*}"
        done | sed 's/ $//')"
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
    load_machine_figures
    if ! command -v nft > /dev/null 2>&1; then
        shape ""
        write_status refused nft_missing
        tell_everyone refused "$web"
        die "nft is not installed; no limits are in force"
    fi

    if ! limits_enabled; then
        remove_table || die "could not remove the limits table"
        local unshaped=1
        shape "" || unshaped=0
        write_status off off "" "" "$webw" 1 "$(configured_site_figures)"
        tell_everyone off "$web"
        say "outbound limits: off on this machine (${HOST_FILE})"
        (( unshaped )) || die "part of the speed ceiling would not come off (see above); the next run tries again"
        return 0
    fi
    if ! resolver_is_loopback; then
        remove_table
        shape ""
        write_status refused resolver_not_loopback
        tell_everyone refused "$web"
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
            local keep
            keep="$(grep -v -e '^reason=' -e '^applied_at=' "$STATUS_FILE")"
            { printf '%s\n' "$keep" | sed 's/^state=on$/state=on\nreason=nft_refused/'; echo "applied_at=$(date -u +%s)"; } \
                > "${STATUS_FILE}.tmp" && chmod 644 "${STATUS_FILE}.tmp" && mv -f "${STATUS_FILE}.tmp" "$STATUS_FILE"
        else
            shape ""
            write_status refused nft_refused "$sites" "$uncovered"
            tell_everyone refused "$web"
        fi
        die "nft refused the limits; the table is as it was"
    fi
    (( unchanged )) || printf '%s\n' "$want" > "$APPLIED_FILE"

    # Each site's ceiling: root's figure, lowered by the site's own setting.
    # Checked on every run, changed table or not: a site's bridge made again
    # by a rebuild has lost its part, and a site's own setting may have moved.
    local site bridge c r b o own in_force figures="" shaped_sites="" told=""
    while read -r site bridge; do
        [[ -n "$site" ]] || continue
        read -r c r b o <<< "$(site_figures "$site")"
        own="$(container_own_ceiling "$site")"
        in_force="$(lower_ceiling "$c" "$own")"
        told+="${site} ${c} ${in_force}"$'\n'
        figures+="${figures:+ }${site}:${in_force}:${r}:${b}:${o}"
        shaped_sites+="${site} ${bridge} ${in_force}"$'\n'
    done <<< "$pairs"
    local web_mbit="" web_in_force=""
    if [[ "$web" == 1 ]]; then
        web_in_force="$(lower_ceiling "${M_CEILING:--}" "$(bare_own_ceiling)")"
        [[ "$web_in_force" == "-" ]] || web_mbit="$web_in_force"
    fi
    # Each site is told once the outcome is known: what is in force, or that
    # the ceiling is not; a site its limits do not cover, that it is not.
    tell_sites() {  # STATE
        local s h f
        while read -r s h f; do
            [[ -n "$s" ]] || continue
            if [[ "$1" == on ]]; then tell_container "$s" on "$h" "$f"; else tell_container "$s" "$1" "$h" -; fi
        done <<< "$told"
        for s in $uncovered; do tell_container "$s" uncovered - -; done
        if [[ "$web" == 1 ]]; then
            if [[ "$1" == on ]]; then tell_bare on "${M_CEILING:--}" "$web_in_force"; else tell_bare "$1" "${M_CEILING:--}" -; fi
        fi
    }

    local plan noroute=0 shaped_before
    shaped_before="$(cat "$SHAPED_FILE" 2> /dev/null)"
    plan="$(shape_plan "$web_mbit" <<< "$shaped_sites")"
    # With no default route this moment, the web server's user's ceiling stays
    # on the interfaces it was on rather than coming off, and the run fails
    # until a route is back.
    if [[ -n "$web_mbit" ]] && ! grep -q ' egress ' <<< "$plan"; then
        noroute=1
        plan="$(printf '%s\n%s\n' "$plan" "$(grep ' egress ' "$SHAPED_FILE" 2> /dev/null)" | sed '/^$/d')"
    fi
    if ! shape "$plan" || (( noroute )); then
        write_status on ceiling_failed "$sites" "$uncovered" "$webw" 0 "$figures"
        tell_sites ceiling_failed
        (( noroute )) && die "the machine has no default route: the web server's user's speed ceiling stays on the interfaces it was on until a route is back; the connection limits are in force"
        die "the speed ceiling is not in force everywhere (see above); the connection limits are"
    fi
    write_status on "" "$sites" "$uncovered" "$webw" 1 "$figures" "${web_in_force#-}"
    tell_sites on
    if (( unchanged )); then
        # The table is as it was; a ceiling may still have moved (a site's own
        # setting, or a figure set), and that is a change the run says.
        if [[ "$(cat "$SHAPED_FILE" 2> /dev/null)" == "$shaped_before" ]]; then
            say "outbound limits: unchanged"
        else
            say "outbound limits: speed ceiling now in force: $(ceilings_in_force "$web_in_force" <<< "$shaped_sites")"
        fi
        return 0
    fi
    say "outbound limits: in force for ${sites:-no container site}$([[ $web == 1 ]] && echo " and the web server's user (${WEB_USER})")"
    [[ -z "$uncovered" ]] || say "outbound limits: not limited, not on a jsnetN network of their own: ${uncovered} (move_site_to_own_network.sh moves a site off Docker's default network)"
    return 0
}

# --- setting the figures ----------------------------------------------------------

# Parsed from FIGURES flags: F_CEILING F_RATE F_BURST F_OPEN (empty: not given),
# F_SITE, F_BY.
F_CEILING=""; F_RATE=""; F_BURST=""; F_OPEN=""; F_SITE=""; F_BY=""
parse_figure_flag() {  # ARG; 1 when it is not a figure flag
    case "$1" in
        --ceiling=*)    F_CEILING="${1#*=}" ;;
        --conn-rate=*)  F_RATE="${1#*=}" ;;
        --conn-burst=*) F_BURST="${1#*=}" ;;
        --open-conns=*) F_OPEN="${1#*=}" ;;
        *) return 1 ;;
    esac
}

# Refuses, in plain words, a figure that is not one.
check_figures() {
    [[ -z "$F_CEILING" || "$F_CEILING" == default ]] || valid_figure ceiling "$F_CEILING" \
        || die "--ceiling=${F_CEILING:0:20} is not a speed ceiling: a whole number of Mbit/s (200), off, or default"
    local n var v
    for n in conn-rate:F_RATE conn-burst:F_BURST open-conns:F_OPEN; do
        var="${n#*:}"; v="${!var}"
        [[ -z "$v" || "$v" == default ]] || valid_figure count "$v" \
            || die "--${n%%:*}=${v:0:20} is not a figure: a whole number (20), or default"
    done
}

any_figures() { [[ -n "${F_CEILING}${F_RATE}${F_BURST}${F_OPEN}" ]]; }

# Writes the given figures into the host file.
write_machine_figures() {  # BY(plane|empty)
    local -a pairs=()
    [[ -n "$F_CEILING" ]] && pairs+=("ceiling_mbit=${F_CEILING}")
    [[ -n "$F_RATE" ]]    && pairs+=("conn_rate=${F_RATE}")
    [[ -n "$F_BURST" ]]   && pairs+=("conn_burst=${F_BURST}")
    [[ -n "$F_OPEN" ]]    && pairs+=("open_conns=${F_OPEN}")
    if [[ "$1" == plane ]]; then pairs+=("set_by=plane"); else pairs+=("set_by=default"); fi
    host_file_set "${pairs[@]}"
}

# Writes the given figures into SITE's run spec, through the helper install.sh
# site uses, with who set them: outbound_set_by=plane for the management node,
# none for root on the machine.
write_site_figures() {  # SITE BY(plane|empty)
    local lib p lines
    [[ "$1" =~ ^[A-Za-z0-9_-]{1,50}$ ]] || die "'${1:0:60}' is not a site name"
    lib="$(spec_lib)" || die "the run spec helper (_site_run_spec.sh) is not beside this script; run it from the release, or install it again"
    # The helper reads a test's scratch root only from an unprivileged run.
    [[ -n "$ROOT" ]] && export JOINERY_SITE_STATE_ROOT="$ROOT"
    # shellcheck source=_site_run_spec.sh
    . "$lib"
    p="$(run_spec_path "$1")" || exit 1
    [[ -f "$p" ]] || die "$1 has no run spec on this machine (${p}): a site's own figures are a container site's; on bare metal the machine's figures are the sites'"
    lines="$(grep -v -e '^#' "$p")"
    local key val
    for key in ceiling:F_CEILING conn_rate:F_RATE conn_burst:F_BURST open_conns:F_OPEN; do
        val="${key#*:}"; val="${!val}"; key="outbound_${key%%:*}"
        [[ -n "$val" ]] || continue
        lines="$(grep -v "^${key}=" <<< "$lines")"
        [[ "$val" == default ]] || lines+=$'\n'"${key}=${val}"
    done
    lines="$(grep -v '^outbound_set_by=' <<< "$lines")"
    [[ "${2:-}" == plane ]] && lines+=$'\n'"outbound_set_by=plane"
    printf '%s\n' "$lines" | run_spec_write "$1" || die "could not write $1's run spec"
}

# Runs the limits after a change: through the unit where it is installed
# (bringing the installed copy up to this one first), else directly.
run_after_change() {
    if [[ -f "$UNIT_PATH" ]]; then
        do_refresh quiet
        systemctl start "$UNIT_NAME" || die "${UNIT_NAME} failed: see journalctl -u ${UNIT_NAME}"
    elif [[ -f "$INSTALLED" ]] || [[ -n "$ROOT" ]]; then
        ( do_apply "$(unit_has_web_user && echo 1 || echo 0)" ) || true
    else
        say "outbound limits: the figures are saved; the limits are not installed on this machine (install.sh installs them)"
    fi
}

do_set() {  # BY
    need_root set
    any_figures || die "set needs a figure: --ceiling=MBIT|off, --conn-rate=N, --conn-burst=N or --open-conns=N"
    check_figures
    if [[ -n "$F_SITE" ]]; then
        write_site_figures "$F_SITE" "$1"
        say "outbound limits: ${F_SITE}'s own figures saved"
    else
        write_machine_figures "$1"
        say "outbound limits: this machine's figures saved (${HOST_FILE})"
    fi
    run_after_change
    do_show
}

do_show() {
    local c r b o site line spec own_c on=0
    load_machine_figures 2> /dev/null
    # What is in force is only the status's while the limits are on.
    grep -qx 'state=on' "$STATUS_FILE" 2> /dev/null && on=1
    say "outbound limits on this machine$([[ "$SET_BY" == plane ]] && echo ", set by the management node")$( (( on )) || echo " (not in force now: sudo joinery-limits status)"):"
    say "  speed ceiling: $([[ -n "$M_CEILING" ]] && echo "${M_CEILING} Mbit/s" || echo "none")$([[ "$M_CEILING" == "$DEF_CEILING" ]] && echo " (built in)")"
    say "  new connections: ${M_RATE} a second, bursts to ${M_BURST}; ${M_OPEN} open at once"
    while IFS= read -r line; do
        [[ "$line" == "-" ]] && break
        site="${line%% *}"
        read -r c r b o <<< "$(site_figures "$site" 2> /dev/null)"
        spec="${SITES_DIR}/${site}/run_spec"
        own_c="$(grep -c -E '^outbound_(ceiling|conn_rate|conn_burst|open_conns)=' "$spec" 2> /dev/null)"
        line=""
        (( on )) && line="$(sed -n 's/^site_figures=//p' "$STATUS_FILE" 2> /dev/null | tr ' ' '\n' | grep "^${site}:" | cut -d: -f2)"
        say "  ${site}: ceiling $([[ "$c" == "-" ]] && echo none || echo "${c} Mbit/s")${line:+, in force ${line/#-/none}$([[ "$line" != "-" ]] && echo " Mbit/s")}; ${r}/s, bursts to ${b}; ${o} open$([[ "${own_c:-0}" -gt 0 ]] && echo " (its own figures$(grep -qx 'outbound_set_by=plane' "$spec" 2> /dev/null && echo ", set by the management node"))")"
    done <<< "$(container_sites)"
    line="$(sed -n 's/^web_ceiling_mbit=//p' "$STATUS_FILE" 2> /dev/null)"
    (( on )) && unit_has_web_user && say "  the web server's user: ceiling in force ${line:-none}$([[ -n "$line" ]] && echo " Mbit/s")"
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

# The installed copies, rewritten where they differ: the script, the run spec
# helper, the unit and the timer. Prints "changed" when any was.
write_installed() {  # WEB_USER(0|1)
    local changed="" lib
    [[ "$(readlink -f "${BASH_SOURCE[0]}")" == "$(readlink -f "$INSTALLED" 2> /dev/null)" ]] \
        || changed+="$(write_if_differs "$INSTALLED" 0755 < "${BASH_SOURCE[0]}")"
    if lib="$(spec_lib)" && [[ "$(readlink -f "$lib")" != "$(readlink -f "$LIB_INSTALLED" 2> /dev/null)" ]]; then
        changed+="$(write_if_differs "$LIB_INSTALLED" 0644 < "$lib")"
    fi
    changed+="$(unit_text "$1" | write_if_differs "$UNIT_PATH" 0644)"
    changed+="$(timer_text | write_if_differs "$TIMER_PATH" 0644)"
    [[ -z "$changed" ]] || echo changed
}

do_install() {  # WEB_USER(0|1) OFF(0|1)
    local web="$1" off="$2" changed=""
    need_root install
    if ! command -v nft > /dev/null 2>&1; then
        apt-get install -y nftables > /dev/null || die "could not install nftables"
    fi
    check_figures
    unit_has_web_user && web=1
    load_machine_figures 2> /dev/null
    # The ruleset is checked before anything is written.
    ruleset "$web" | nft -c -f - || die "nft refused the limits; nothing was changed"
    [[ "$off" == 1 ]] && set_enabled false
    # The management node installing the machine says so even with no figure
    # of its own: the sites' settings pages then point at whoever hosts them.
    # A reinstall without it leaves set_by as it was.
    if any_figures; then write_machine_figures "$F_BY"
    elif [[ "$F_BY" == plane ]]; then host_file_set set_by=plane; fi
    changed="$(write_installed "$web")"
    [[ -z "$changed" ]] || systemctl daemon-reload
    systemctl enable "$UNIT_NAME" "$TIMER_NAME" > /dev/null 2>&1 || die "could not enable ${UNIT_NAME}"
    systemctl start "$TIMER_NAME" || die "${TIMER_NAME} did not start"
    systemctl start "$UNIT_NAME" || die "${UNIT_NAME} failed: see journalctl -u ${UNIT_NAME}"
    do_status
}

# A script's version, from its "# Version: X.Y" line.
script_version() {  # PATH
    sed -n 's/^# Version: \([0-9][0-9.]*\).*/\1/p' "$1" 2> /dev/null | head -1
}

# Whether the file MINE is a newer release than the installed THEIRS (or
# there is none to compare).
version_newer() {  # MINE THEIRS
    local mine theirs
    [[ -f "$2" ]] || return 0
    mine="$(script_version "$1")"; theirs="$(script_version "$2")"
    [[ -n "$mine" ]] || return 1
    [[ -n "$theirs" ]] || return 0
    [[ "$mine" != "$theirs" && "$(printf '%s\n%s\n' "$mine" "$theirs" | sort -V | tail -1)" == "$mine" ]]
}

# Whether this script, or the run spec helper beside it, is a newer release
# than its installed copy: a release may change the helper alone.
newer_than_installed() {
    local lib
    version_newer "${BASH_SOURCE[0]}" "$INSTALLED" && return 0
    lib="$(spec_lib)" && [[ "$(readlink -f "$lib")" != "$(readlink -f "$LIB_INSTALLED" 2> /dev/null)" ]] \
        && version_newer "$lib" "$LIB_INSTALLED"
}

# Brings an installed machine's copies up to this script, as an upgrade
# delivers it; runs the unit only when something changed. Only ever forward:
# a machine whose sites' trees are at different releases runs this from each
# of them, and a copy written whenever it differed would go back and forth
# on every converge; and a figure set from an older bundle must not take a
# newer copy back. quiet: the commands that change a figure, which say
# nothing of it. A machine without the unit is left alone: install.sh is what
# installs the limits.
do_refresh() {  # [quiet]
    need_root refresh
    [[ -f "$UNIT_PATH" ]] || { [[ "${1:-}" == quiet ]] || say "outbound limits: not installed on this machine"; return 0; }
    if ! newer_than_installed; then
        [[ "${1:-}" == quiet ]] || say "outbound limits: installed copy current"
        return 0
    fi
    local web=0 changed
    unit_has_web_user && web=1
    changed="$(write_installed "$web")"
    if [[ -z "$changed" ]]; then
        [[ "${1:-}" == quiet ]] || say "outbound limits: installed copy current"
        return 0
    fi
    systemctl daemon-reload
    [[ "${1:-}" == quiet ]] && return 0
    say "outbound limits: installed copy brought up to this release"
    systemctl start "$UNIT_NAME" || die "${UNIT_NAME} failed: see journalctl -u ${UNIT_NAME}"
}

do_onoff() {  # true|false
    need_root "$([[ $1 == true ]] && echo on || echo off)"
    set_enabled "$1"
    if [[ -f "$UNIT_PATH" ]]; then
        do_refresh quiet
        systemctl start "$UNIT_NAME" || die "${UNIT_NAME} failed: see journalctl -u ${UNIT_NAME}"
    else
        do_apply "$(unit_has_web_user && echo 1 || echo 0)"
    fi
    do_status
}

do_status() {
    local state reason sites uncovered web ceiling site counts c fig rate burst open
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
    rate="$(sed -n 's/^conn_rate=//p' "$STATUS_FILE")"; rate="${rate:-$DEF_RATE}"
    burst="$(sed -n 's/^conn_burst=//p' "$STATUS_FILE")"; burst="${burst:-$DEF_BURST}"
    open="$(sed -n 's/^open_conns=//p' "$STATUS_FILE")"; open="${open:-$DEF_OPEN}"
    case "$state" in
        on)  say "outbound limits: on (${rate} new connections a second, bursts to ${burst}; ${open} open at once; no UDP; $([[ -n "$ceiling" ]] && echo "sending at ${ceiling} Mbit/s at most" || echo "no speed ceiling"))"
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
        fig="$(sed -n 's/^site_figures=//p' "$STATUS_FILE" | tr ' ' '\n' | grep "^${site}:" | cut -d: -f2)"
        say "  ${site}: limited$([[ -n "$fig" && "$fig" != "$ceiling" ]] && echo ", sending at $([[ "$fig" == "-" ]] && echo "any speed" || echo "${fig} Mbit/s at most")"), ${c%%:*} packets dropped since $(date -u -d "@$(sed -n 's/^since=//p' "$STATUS_FILE")" '+%Y-%m-%d %H:%M UTC' 2>/dev/null)"
    done
    if [[ "$web" == yes ]]; then
        c="$(sed -n 's/^drops_web_user=//p' <<< "$counts")"
        fig="$(sed -n 's/^web_ceiling_mbit=//p' "$STATUS_FILE")"
        say "  the web server's user (${WEB_USER}): limited$([[ -n "$fig" && "$fig" != "$ceiling" ]] && echo ", sending at ${fig} Mbit/s at most (a site asked for less)"), ${c%%:*} packets dropped"
    fi
    for site in $uncovered; do
        say "  ${site}: NOT limited - not on a jsnetN network of its own (move_site_to_own_network.sh ${site} moves it off Docker's default network)"
    done
    return 0
}

# --- arguments -------------------------------------------------------------------

CMD="${1:-}"; shift || true
WEB=0; OFF=0; VALUE=""
case "$CMD" in
    ceiling|conn-rate|conn-burst|open-conns)
        VALUE="${1:-}"; shift || true
        [[ -n "$VALUE" ]] || die "usage: joinery-limits ${CMD} VALUE [--site=SITE]"
        case "$CMD" in
            ceiling)    F_CEILING="$VALUE" ;;
            conn-rate)  F_RATE="$VALUE" ;;
            conn-burst) F_BURST="$VALUE" ;;
            open-conns) F_OPEN="$VALUE" ;;
        esac ;;
esac
for arg in "$@"; do
    case "$arg" in
        --web-user) WEB=1 ;;
        --off)      OFF=1 ;;
        --site=*)   F_SITE="${arg#*=}" ;;
        --by=plane) F_BY=plane ;;
        *) parse_figure_flag "$arg" || die "unknown option: ${arg}" ;;
    esac
done
# A figure flag means something only to the commands that set figures.
case "$CMD" in
    install|set|ceiling|conn-rate|conn-burst|open-conns) ;;
    *) { any_figures || [[ -n "$F_SITE$F_BY" ]]; } && die "${CMD:-this command} takes no figures" ;;
esac
[[ -z "$F_SITE" || "$CMD" != install ]] || die "install sets the machine's figures; a site's are set with: joinery-limits set ... --site=SITE"

case "$CMD" in
    install) do_install "$WEB" "$OFF" ;;
    refresh) do_refresh ;;
    apply)   do_apply "$WEB" ;;
    on)      do_onoff true ;;
    off)     do_onoff false ;;
    set|ceiling|conn-rate|conn-burst|open-conns) do_set "$F_BY" ;;
    show)    do_show ;;
    status)  do_status ;;
    ruleset) load_machine_figures; ruleset "$WEB" ;;
    *) die "usage: joinery-limits install [--web-user] [--off] [FIGURES] | refresh | apply [--web-user] | on | off | set FIGURES [--site=SITE] [--by=plane] | ceiling MBIT|off|default [--site=SITE] | conn-rate|conn-burst|open-conns N|default [--site=SITE] | show | status | ruleset [--web-user]   (FIGURES: --ceiling=MBIT|off --conn-rate=N --conn-burst=N --open-conns=N)" ;;
esac
