#!/bin/bash
# @joinery-test
# name: net_health
# tier: safe
# env: any
# needs: []
# timeout: 90
# covers: [maintenance_scripts/sysadmin_tools/net_health.sh]
#
# net_health.sh is what the agent's net_health observe word runs as root
# (specs/slow_request_diagnosis_words.md WP2). This gate pins its contract:
# the real run prints one JSON object; against fixture /proc and /sys trees it
# reports each counter's total and its change over the interval, sums
# softnet_stat's hex columns, reads rx_dropped on the default-route interfaces
# only, leaves out a counter the kernel lacks rather than saying 0, reports the
# connection table, refuses in a site container, carries no remote address,
# writes nothing, and runs under mawk as well as gawk.

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
SCRIPT="$ROOT/maintenance_scripts/sysadmin_tools/net_health.sh"
T=$(mktemp -d)
trap 'rm -rf "${T:?}"' EXIT
passed=0; failed=0

chk() {
    if [ "$2" = "$3" ]; then
        echo "  PASS: $1"; passed=$((passed+1))
    else
        echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed+1))
    fi
}
jv() {
    php -r '
        $o = json_decode(file_get_contents($argv[1]), true);
        if (!is_array($o)) { echo "NOTJSON"; exit; }
        $v = $o; if ($argv[2] !== "") { foreach (explode(".", $argv[2]) as $k) { if (!is_array($v) || !array_key_exists($k, $v)) { echo "ABSENT"; exit; } $v = $v[$k]; } }
        if ($argv[3] === "count") { echo is_array($v) ? count($v) : "NOTLIST"; exit; }
        if ($argv[3] === "keys") { echo is_array($v) ? implode(",", array_keys($v)) : "NOTOBJ"; exit; }
        echo is_bool($v) ? ($v ? "true" : "false") : (is_scalar($v) ? $v : json_encode($v));
    ' "$1" "$2" "${3:-value}"
}

echo "=== The real run on this box ==="
NET_HEALTH_INTERVAL=1 bash "$SCRIPT" > "$T/real.json" 2>/dev/null; rc=$?
chk "exit 0" "$rc" "0"
chk "one object with every key" "$(jv "$T/real.json" "" keys)" "counters,conntrack,web_connections,listen_queues,interval_seconds,uptime_seconds,generated_at"
chk "the interval it was given" "$(jv "$T/real.json" interval_seconds)" "1"

echo "=== Fixture /proc and /sys ==="
# A /proc whose counters move between the two reads: a stub sleep swaps the
# first snapshot for the second, so the gate takes no real time.
P="$T/proc"; S="$T/sys"
mkdir -p "$P/net" "$P/sys/net/netfilter" "$S/class/net/eth0/statistics" "$S/class/net/docker0/statistics" "$T/bin"
write_proc() {  # listen_overflows retrans rx_dropped
    printf 'TcpExt: SyncookiesSent ListenOverflows ListenDrops TCPBacklogDrop TCPTimeouts TCPAbortOnTimeout TCPReqQFullDrop\nTcpExt: 0 %s %s 0 7 1 0\n' "$1" "$1" > "$P/net/netstat"
    printf 'Tcp: RtoAlgorithm ActiveOpens AttemptFails EstabResets RetransSegs\nTcp: 1 9 3 4 %s\n' "$2" > "$P/net/snmp"
    printf '0000000a 0000000b 00000010 00000000\n00000001 00000002 00000001 00000000\n' > "$P/net/softnet_stat"
    echo "$3" > "$S/class/net/eth0/statistics/rx_dropped"
}
write_proc 100 1000 5
printf 'Iface\tDestination\tGateway\nent0\t0A000000\t00000000\neth0\t00000000\t0100000A\n' > "$P/net/route"
: > "$P/net/ipv6_route"
echo 999 > "$S/class/net/docker0/statistics/rx_dropped"
echo 50 > "$P/sys/net/netfilter/nf_conntrack_count"
echo 200 > "$P/sys/net/netfilter/nf_conntrack_max"
echo "12345.67 1.0" > "$P/uptime"
cat > "$T/bin/sleep" <<STUB
#!/bin/bash
printf 'TcpExt: SyncookiesSent ListenOverflows ListenDrops TCPBacklogDrop TCPTimeouts TCPAbortOnTimeout TCPReqQFullDrop\nTcpExt: 0 114 114 0 7 1 0\n' > "$P/net/netstat"
printf 'Tcp: RtoAlgorithm ActiveOpens AttemptFails EstabResets RetransSegs\nTcp: 1 9 3 4 1030\n' > "$P/net/snmp"
echo 8 > "$S/class/net/eth0/statistics/rx_dropped"
STUB
cat > "$T/bin/ss" <<'STUB'
#!/bin/bash
if [[ " $* " == *" -l "* ]]; then
    printf 'LISTEN 3 511 *:443 *:*\nLISTEN 0 511 0.0.0.0:80 0.0.0.0:*\n'
else
    printf 'ESTAB 0 0 10.0.0.5:443 203.0.113.9:51234\nESTAB 0 0 10.0.0.5:443 198.51.100.7:40000\nTIME-WAIT 0 0 10.0.0.5:80 203.0.113.9:1\n'
fi
STUB
chmod 755 "$T/bin/sleep" "$T/bin/ss"
PATH="$T/bin:$PATH" NET_HEALTH_PROC="$P" NET_HEALTH_SYS="$S" bash "$SCRIPT" > "$T/fx.json" 2>/dev/null; rc=$?
chk "exit 0" "$rc" "0"
chk "a counter's total and its change over the interval" "$(jv "$T/fx.json" counters.listen_overflows.total)/$(jv "$T/fx.json" counters.listen_overflows.per_interval)" "114/14"
chk "Tcp counters from snmp" "$(jv "$T/fx.json" counters.retrans_segs.total)/$(jv "$T/fx.json" counters.retrans_segs.per_interval)/$(jv "$T/fx.json" counters.attempt_fails.total)" "1030/30/3"
chk "a counter this kernel lacks is left out, never 0" "$(jv "$T/fx.json" counters.syn_queue_full_cookies)" "ABSENT"
chk "softnet_stat's hex columns summed over CPUs" "$(jv "$T/fx.json" counters.softnet_dropped.total)/$(jv "$T/fx.json" counters.softnet_time_squeeze.total)" "13/17"
chk "rx_dropped from the default-route interface only, not a container bridge" "$(jv "$T/fx.json" counters.rx_dropped.total)/$(jv "$T/fx.json" counters.rx_dropped.per_interval)" "8/3"
chk "the connection table" "$(jv "$T/fx.json" conntrack.count)/$(jv "$T/fx.json" conntrack.max)/$(jv "$T/fx.json" conntrack.percent)" "50/200/25"
chk "web connections by state, counts only" "$(jv "$T/fx.json" web_connections.estab)/$(jv "$T/fx.json" web_connections.time-wait)" "2/1"
chk "no remote address anywhere in the answer" "$(grep -c -E '203\.0\.113|198\.51\.100' "$T/fx.json")" "0"
chk "each web listener's queue against its limit" "$(jv "$T/fx.json" listen_queues count)/$(jv "$T/fx.json" listen_queues.0.queued)/$(jv "$T/fx.json" listen_queues.0.limit)/$(jv "$T/fx.json" listen_queues.1.port)" "2/3/511/80"
chk "uptime" "$(jv "$T/fx.json" uptime_seconds)" "12345"

echo "=== Under mawk ==="
write_proc 100 1000 5
ln -s "$(command -v mawk || command -v awk)" "$T/bin/awk"
PATH="$T/bin:$PATH" NET_HEALTH_PROC="$P" NET_HEALTH_SYS="$S" bash "$SCRIPT" > "$T/mawk.json" 2>/dev/null
chk "the same answer with mawk as awk" "$(jv "$T/mawk.json" counters.listen_overflows.per_interval)/$(jv "$T/mawk.json" counters.softnet_dropped.total)/$(jv "$T/mawk.json" conntrack.percent)" "14/13/25"
rm -f "$T/bin/awk"

echo "=== Sources it cannot read ==="
rm -f "$P/sys/net/netfilter/nf_conntrack_count"
cat > "$T/bin/ss" <<'STUB'
#!/bin/bash
exit 1
STUB
PATH="$T/bin:$PATH" NET_HEALTH_PROC="$P" NET_HEALTH_SYS="$S" bash "$SCRIPT" > "$T/gone.json" 2>/dev/null; rc=$?
chk "no connection tracking: absent; ss failing: unknown; still exit 0" "$rc/$(jv "$T/gone.json" conntrack)/$(jv "$T/gone.json" web_connections)/$(jv "$T/gone.json" listen_queues)" "0/absent/unknown/unknown"

echo "=== A site container ==="
mkdir -p "$T/ctr"; touch "$T/ctr/.dockerenv"
NET_HEALTH_ROOT="$T/ctr" bash "$SCRIPT" > "$T/ctr.json" 2>/dev/null; rc=$?
chk "refused, exit 2, nothing read" "$rc/$(jv "$T/ctr.json" refused)/$(jv "$T/ctr.json" counters)" "2/container/ABSENT"
mkdir -p "$T/ctr2/run"; touch "$T/ctr2/run/.containerenv"
NET_HEALTH_ROOT="$T/ctr2" bash "$SCRIPT" > "$T/ctr2.json" 2>/dev/null; rc=$?
chk "the site container engine's marker too" "$rc/$(jv "$T/ctr2.json" refused)" "2/container"

echo "=== Static pins ==="
chk "takes no argument (no shell positional parameter; awk's fields are not)" "$(grep -v '^[[:space:]]*#' "$SCRIPT" | grep -v '^run() {' | grep -c -E '\$\{[1-9@*]|"\$[1-9@*]"|\$#')" "0"
chk "nothing writes" "$(grep -v '^[[:space:]]*#' "$SCRIPT" | grep -c -E '\btee\b|>\s*/(etc|var|srv|usr|run|proc|sys)|\brm |\bmv |\bkill\b')" "0"
chk "no gawk-only function" "$(grep -v '^[[:space:]]*#' "$SCRIPT" | grep -c -E 'strtonum|mktime|gensub|(^|[^_a-z])match\([^)]*,[^)]*,')" "0"

echo
echo "net_health gate: $passed passed, $failed failed"
[ "$failed" -eq 0 ]
