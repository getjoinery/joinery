#!/usr/bin/env bash
#
# net_health.sh - is this machine dropping packets or connections? As ONE JSON
# object on stdout.
#
# Version: 1.0 - the net_health observe word of
#                specs/slow_request_diagnosis_words.md WP2 (2026-10-23:
#                jeremytunnell.com's requests stalled for up to 90 s through
#                Cloudflare and never direct, and nothing could say whether
#                the machine was dropping them). The agent runs this file,
#                verified against the release manifest, with no argv and no
#                stdin.
#
# THE CONTRACT, which tests/integration/net_health_gate.sh pins:
#
#   - It takes NOTHING: no argument, no stdin.
#   - COUNTS ONLY. Kernel counters, connection counts by state on the two web
#     ports, the web listeners' queues. Never a remote address, never a port
#     other than 80 and 443, never a process.
#   - Read twice, INTERVAL seconds apart: each counter is its total since boot
#     and its change over the interval, because a total since boot says
#     nothing about now.
#   - A counter this kernel does not have is left out, never reported as 0.
#     Where a whole source cannot be read its key is the string unknown.
#   - HOST ONLY. Inside a site container the counters are the container's own
#     network namespace, which is not where an edge connects; it refuses
#     there (exit 2, refused "container") and the host's node is asked.
#   - READ ONLY: /proc, /sys and ss with fixed arguments.
#   - Any awk: a new server may have mawk alone, so nothing here is gawk's.

set -u
export LC_ALL=C

CMD_TIMEOUT=10
INTERVAL=10
[[ "${NET_HEALTH_INTERVAL:-}" =~ ^[0-9]{1,2}$ ]] && INTERVAL="$NET_HEALTH_INTERVAL"
PROC="${NET_HEALTH_PROC:-/proc}"
SYS="${NET_HEALTH_SYS:-/sys}"
ROOT="${NET_HEALTH_ROOT:-}"

run() { timeout "$CMD_TIMEOUT" "$@" 2>/dev/null; }

# --- A site container is not where the edge connects. ------------------------
if [[ -f "${ROOT}/.dockerenv" || -f "${ROOT}/run/.containerenv" ]]; then
    printf '{"refused":"container","reason":"this is a site container: its network is not where the edge connects; ask its host node"}\n'
    printf 'net_health: this is a site container; ask its host node\n' >&2
    exit 2
fi

# The counters read, by the name this word reports them under. Each is
# FILE:SECTION:FIELD. TcpExt lives in netstat, Tcp in snmp.
COUNTERS=(
    "listen_overflows:netstat:TcpExt:ListenOverflows"
    "listen_drops:netstat:TcpExt:ListenDrops"
    "syn_queue_full_drops:netstat:TcpExt:TCPReqQFullDrop"
    "syn_queue_full_cookies:netstat:TcpExt:TCPReqQFullDoCookies"
    "backlog_drops:netstat:TcpExt:TCPBacklogDrop"
    "timeouts:netstat:TcpExt:TCPTimeouts"
    "abort_on_timeout:netstat:TcpExt:TCPAbortOnTimeout"
    "retrans_segs:snmp:Tcp:RetransSegs"
    "attempt_fails:snmp:Tcp:AttemptFails"
    "estab_resets:snmp:Tcp:EstabResets"
)

# One "name value" line per counter this kernel has, from netstat and snmp
# (a header line of names followed by a line of values, per section).
read_counters() {
    local spec name file section field
    local netstat snmp
    netstat="$(cat "${PROC}/net/netstat" 2>/dev/null)"
    snmp="$(cat "${PROC}/net/snmp" 2>/dev/null)"
    for spec in "${COUNTERS[@]}"; do
        IFS=: read -r name file section field <<< "$spec"
        if [[ "$file" == netstat ]]; then src="$netstat"; else src="$snmp"; fi
        awk -v sec="${section}:" -v f="$field" -v n="$name" '
            $1 == sec && !hdr { for (i = 2; i <= NF; i++) col[$i] = i; hdr = 1; next }
            $1 == sec && hdr  { if (f in col && $(col[f]) ~ /^[0-9]+$/) print n, $(col[f]); exit }
        ' <<< "$src"
    done
    # softnet_stat: one line per CPU, hex; column 2 dropped, column 3 time_squeeze.
    # Summed in bash: hex is not something every awk reads (mawk has no strtonum).
    if [[ -r "${PROC}/net/softnet_stat" ]]; then
        local _c1 dropped_hex squeeze_hex _rest dropped=0 squeeze=0 lines=0
        while read -r _c1 dropped_hex squeeze_hex _rest; do
            [[ "$dropped_hex" =~ ^[0-9a-fA-F]{1,16}$ && "$squeeze_hex" =~ ^[0-9a-fA-F]{1,16}$ ]] || continue
            dropped=$(( dropped + 16#$dropped_hex )); squeeze=$(( squeeze + 16#$squeeze_hex )); lines=$((lines + 1))
        done < "${PROC}/net/softnet_stat"
        if (( lines > 0 )); then echo "softnet_dropped $dropped"; echo "softnet_time_squeeze $squeeze"; fi
    fi
    # The interfaces that carry a default route: where the edge's packets come in.
    local ifs iface v
    ifs="$(awk 'NR > 1 && $2 == "00000000" { print $1 }' "${PROC}/net/route" 2>/dev/null | sort -u)"
    ifs="${ifs} $(awk '$1 == "00000000000000000000000000000000" && $2 == "00" { print $10 }' "${PROC}/net/ipv6_route" 2>/dev/null | grep -v '^lo$' | sort -u)"
    local rx_dropped="" rx_missed=""
    for iface in $(tr ' ' '\n' <<< "$ifs" | grep -E '^[A-Za-z0-9._-]{1,15}$' | sort -u); do
        v="$(cat "${SYS}/class/net/${iface}/statistics/rx_dropped" 2>/dev/null)"
        [[ "$v" =~ ^[0-9]+$ ]] && rx_dropped=$(( ${rx_dropped:-0} + v ))
        v="$(cat "${SYS}/class/net/${iface}/statistics/rx_missed_errors" 2>/dev/null)"
        [[ "$v" =~ ^[0-9]+$ ]] && rx_missed=$(( ${rx_missed:-0} + v ))
    done
    [[ -n "$rx_dropped" ]] && echo "rx_dropped $rx_dropped"
    [[ -n "$rx_missed" ]] && echo "rx_missed $rx_missed"
}

FIRST="$(read_counters)"
sleep "$INTERVAL"
SECOND="$(read_counters)"

# {"name":{"total":N,"per_interval":D},...}; a counter is reported only when
# both reads have it.
emit_counters() {
    local name total first sep=""
    [[ -n "$SECOND" ]] || { printf '"unknown"'; return; }
    printf '{'
    while read -r name total; do
        [[ "$name" =~ ^[a-z_]+$ && "$total" =~ ^[0-9]+$ ]] || continue
        first="$(awk -v n="$name" '$1 == n { print $2; exit }' <<< "$FIRST")"
        [[ "$first" =~ ^[0-9]+$ ]] || continue
        printf '%s"%s":{"total":%s,"per_interval":%s}' "$sep" "$name" "$total" "$(( total >= first ? total - first : 0 ))"
        sep=","
    done <<< "$SECOND"
    printf '}'
}

emit_conntrack() {
    local count max
    count="$(cat "${PROC}/sys/net/netfilter/nf_conntrack_count" 2>/dev/null)"
    max="$(cat "${PROC}/sys/net/netfilter/nf_conntrack_max" 2>/dev/null)"
    if [[ "$count" =~ ^[0-9]+$ && "$max" =~ ^[0-9]+$ && "$max" -gt 0 ]]; then
        printf '{"count":%d,"max":%d,"percent":%d}' "$count" "$max" "$(( count * 100 / max ))"
    else
        printf '"absent"'
    fi
}

# Connection counts by TCP state on the two web ports: numbers only.
emit_web_connections() {
    local out
    command -v ss >/dev/null 2>&1 || { printf '"unknown"'; return; }
    out="$(run ss -H -t -n -a '( sport = :443 or sport = :80 )')" || { printf '"unknown"'; return; }
    awk '
        $1 == "LISTEN" { next }
        { s = tolower($1); gsub(/[^a-z0-9-]/, "", s); if (s != "") c[s]++ }
        END {
            printf "{"; sep = ""
            for (k in c) { printf "%s\"%s\":%d", sep, k, c[k]; sep = "," }
            printf "}"
        }' <<< "$out"
}

# Each web listener's accept queue now (Recv-Q) against its limit (Send-Q).
emit_listen_queues() {
    local out
    command -v ss >/dev/null 2>&1 || { printf '"unknown"'; return; }
    out="$(run ss -H -l -t -n '( sport = :443 or sport = :80 )')" || { printf '"unknown"'; return; }
    awk '
        BEGIN { printf "["; sep = "" }
        {
            addr = $4; port = addr; sub(/.*:/, "", port); sub(/:[^:]*$/, "", addr)
            gsub(/[^0-9A-Fa-f.:*\[\]]/, "", addr)
            if (port !~ /^[0-9]+$/ || $2 !~ /^[0-9]+$/ || $3 !~ /^[0-9]+$/) next
            printf "%s{\"address\":\"%s\",\"port\":%d,\"queued\":%d,\"limit\":%d}", sep, substr(addr, 1, 47), port, $2, $3
            sep = ","; if (++n >= 16) exit
        }
        END { printf "]" }' <<< "$out"
}

UPTIME="$(awk '{ printf "%d", $1 }' "${PROC}/uptime" 2>/dev/null)"
[[ "$UPTIME" =~ ^[0-9]+$ ]] || UPTIME=0

printf '{"counters":%s,"conntrack":%s,"web_connections":%s,"listen_queues":%s,"interval_seconds":%d,"uptime_seconds":%d,"generated_at":%d}\n' \
    "$(emit_counters)" "$(emit_conntrack)" "$(emit_web_connections)" "$(emit_listen_queues)" \
    "$INTERVAL" "$UPTIME" "$(date +%s)"
exit 0
