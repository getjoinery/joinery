#!/bin/bash
# @joinery-test
# name: tune_php_fpm
# tier: safe
# env: any
# needs: []
# timeout: 60
# covers: [maintenance_scripts/sysadmin_tools/tune_php_fpm.sh, maintenance_scripts/sysadmin_tools/_memory_plan.sh, maintenance_scripts/sysadmin_tools/tune_postgres_memory.sh]
#
# A site's PHP pool is sized from its memory budget
# (specs/multi_tenant_docker_hosts.md WP2, specs/php_fpm_sizing_bare_metal.md),
# and PostgreSQL's connections follow the pool, both from _memory_plan.sh:
#
#   - workers = (budget - shared_buffers - 128 MB) / 40 MB, at most 8 a CPU
#     (a cgroup quota counts as it is: 0.5 CPU is 4), never fewer than 3,
#     never more than 80; spare counts stay inside the pool
#   - a machine sized from MemTotal keeps the host reserve back first: 384 MB,
#     128 more with rspamd, and the office editor's own MemoryMax
#   - the drop-in names the plan in one joinery-plan line the host report reads
#   - a reload with process_control_timeout lets a request in flight finish,
#     where the packaged 0 cuts it (with the real php-fpm, where installed)
#   - max_connections = workers + 20, written only for a container's budget
#   - the drop-in is a second [www] section that php-fpm reads over www.conf
#     (checked with the real php-fpm's -tt where one is installed)
#   - a re-run that would write the same thing writes nothing
#
# Everything is written under a scratch directory (FPM_ROOT, which root
# ignores); PostgreSQL's tuner runs in dry-run mode only.

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
TOOLS="$ROOT/maintenance_scripts/sysadmin_tools"
FPM="$TOOLS/tune_php_fpm.sh"
PG="$TOOLS/tune_postgres_memory.sh"
T=$(mktemp -d) || { echo "mktemp failed"; exit 1; }
[ -n "$T" ] && [ -d "$T" ] && [ "$T" != "/" ] || { echo "no scratch dir"; exit 1; }
trap 'rm -rf "${T:?}"' EXIT
passed=0; failed=0

chk() {
    if [ "$2" = "$3" ]; then
        echo "  PASS: $1"; passed=$((passed+1))
    else
        echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed+1))
    fi
}

if [ "$(id -u)" -eq 0 ]; then
    echo "  SKIP: run as root, which ignores the scratch FPM_ROOT"
    echo "tune_php_fpm gate: 0 passed, 0 failed"
    exit 0
fi

for f in "$FPM" "$PG" "$TOOLS/_memory_plan.sh"; do
    chk "$(basename "$f") parses" "$(bash -n "$f" 2>/dev/null && echo yes || echo no)" "yes"
done

mkdir -p "$T/php/8.5/fpm/pool.d" "$T/php/8.3/fpm/pool.d"
# The budget table pins memory alone: 16 CPUs hold up to 128 workers.
export MEMORY_PLAN_CPUS=16
fpm() { FPM_ROOT="$T/php" bash "$FPM" "$@" 2>&1; }
children_for() { fpm --dry-run --ram-mb="$1" | sed -n 's/^pm.max_children = //p'; }

echo "== workers follow the budget =="
for pair in 128:3 256:3 384:4 512:7 768:12 1024:17 2048:37 8192:80; do
    chk "${pair%%:*} MB -> ${pair#*:} workers" "$(children_for "${pair%%:*}")" "${pair#*:}"
done

echo "== at most 8 workers a CPU; the minimum wins =="
chk "2048 MB on 1 CPU -> 8" "$(MEMORY_PLAN_CPUS=1 children_for 2048)" "8"
chk "2048 MB on 0.5 CPU -> 4, not 0 and not 8" "$(MEMORY_PLAN_CPUS=0.5 children_for 2048)" "4"
chk "2048 MB on 0.25 CPU -> the minimum 3" "$(MEMORY_PLAN_CPUS=0.25 children_for 2048)" "3"
mkdir -p "$T/cg/sys/fs/cgroup" "$T/cg/proc"
echo "50000 100000" > "$T/cg/sys/fs/cgroup/cpu.max"
chk "a cgroup quota of half a CPU -> 4" "$(MEMORY_PLAN_CPUS= MEMORY_PLAN_ROOT="$T/cg" children_for 2048)" "4"
echo "max 100000" > "$T/cg/sys/fs/cgroup/cpu.max"
chk "no quota: the CPUs nproc counts" "$(MEMORY_PLAN_CPUS= MEMORY_PLAN_ROOT="$T/cg" children_for 8192)" "$(n=$(( $(nproc) * 8 )); [ "$n" -gt 80 ] && n=80; echo "$n")"

echo "== a machine sized from MemTotal keeps the host reserve back =="
if [ ! -r /sys/fs/cgroup/memory.max ] || ! [[ "$(cat /sys/fs/cgroup/memory.max)" =~ ^[0-9]+$ ]]; then
    M="$T/metal"; mkdir -p "$M/proc" "$M/usr/bin" "$M/etc/systemd/system/coolwsd.service.d"
    printf 'MemTotal:        2057216 kB\n' > "$M/proc/meminfo"     # 2009 MB, jeremytunnell.com
    metal() { MEMORY_PLAN_ROOT="$M" fpm --dry-run; }
    chk "no rspamd: 2009 - 384 = 1625 MB" "$(metal | sed -n 's/^; joinery-plan: budget_mb=\([0-9]*\) .*/\1/p')" "1625"
    touch "$M/usr/bin/rspamd"
    chk "with rspamd: 2009 - 384 - 128 = 1497 MB, 26 workers by memory" \
        "$(metal | sed -n 's/^; joinery-plan: budget_mb=\([0-9]*\) .*/\1/p')/$(MEMORY_PLAN_CPUS=16 metal | sed -n 's/^pm.max_children = //p')" "1497/26"
    chk "1 CPU: 8 workers, and the plan line says so" "$(MEMORY_PLAN_CPUS=1 metal | grep '^; joinery-plan:')" \
        "; joinery-plan: budget_mb=1497 source=MemTotal reserve_mb=512 cpus=1 children=8"
    printf '[Service]\nMemoryMax=1004M\n' > "$M/etc/systemd/system/coolwsd.service.d/joinery.conf"
    chk "with the office editor: its MemoryMax kept back too" "$(metal | sed -n 's/^; joinery-plan: budget_mb=\([0-9]*\) .*/\1/p')" "493"
    chk "the drop-in names what was kept back" "$(metal | grep '^; Sized from' | grep -c 'less 1516 MB kept for the host (384 base + 128 rspamd + 1004 office editor)')" "1"
    printf 'MemTotal:        524288 kB\n' > "$M/proc/meminfo"
    chk "a machine smaller than its reserve still gets the floors" "$(metal | sed -n 's/^pm.max_children = //p')" "3"
else
    echo "  SKIP: this machine has a memory limit of its own, so MemTotal is not its source"
fi

echo "== spare workers stay inside the pool =="
out="$(fpm --dry-run --ram-mb=256)"
chk "3 workers: start 2, min spare 1, max spare 3" \
    "$(echo "$out" | grep -E '^pm\.(start_servers|min_spare_servers|max_spare_servers)' | tr '\n' ' ')" \
    "pm.start_servers = 2 pm.min_spare_servers = 1 pm.max_spare_servers = 3 "
out="$(fpm --dry-run --ram-mb=1024)"
chk "17 workers: start 2, min spare 1, max spare 3" \
    "$(echo "$out" | grep -E '^pm\.(start_servers|min_spare_servers|max_spare_servers)' | tr '\n' ' ')" \
    "pm.start_servers = 2 pm.min_spare_servers = 1 pm.max_spare_servers = 3 "
chk "the drop-in is a [www] section" "$(echo "$out" | grep -cx '\[www\]')" "1"

echo "== PostgreSQL's connections follow the same pool =="
if ls /etc/postgresql/*/main >/dev/null 2>&1; then
    for mb in 256 512 1024 8192; do
        w="$(children_for "$mb")"
        chk "$mb MB: max_connections = $w workers + 20" \
            "$(bash "$PG" --dry-run --ram-mb="$mb" 2>/dev/null | sed -n 's/^max_connections = //p')" "$((w + 20))"
    done
    chk "the stated budget's shared_buffers is the one the pool is sized after" \
        "$(bash "$PG" --dry-run --ram-mb=512 2>/dev/null | sed -n 's/^shared_buffers = //p')" \
        "$(fpm --dry-run --ram-mb=512 | sed -n "s/.*after PostgreSQL's \([0-9]*\) MB.*/\1MB/p")"
    if [ ! -r /sys/fs/cgroup/memory.max ] || ! [[ "$(cat /sys/fs/cgroup/memory.max)" =~ ^[0-9]+$ ]]; then
        chk "a machine sized from MemTotal keeps PostgreSQL's own max_connections" \
            "$(bash "$PG" --dry-run 2>/dev/null | grep -c '^max_connections')" "0"
    fi
else
    echo "  SKIP: no PostgreSQL configuration directory on this machine"
fi

echo "== writing, and writing nothing the second time =="
out="$(fpm --no-restart --ram-mb=512)"
chk "both PHP versions written, named by version" "$(echo "$out" | grep '^Written for PHP')" "Written for PHP 8.3 8.5"
chk "8.5's drop-in names 7 workers" "$(grep -c '^pm.max_children = 7$' "$T/php/8.5/fpm/pool.d/zz-joinery-memory.conf")" "1"
chk "8.3's drop-in is the same file" "$(cmp -s "$T/php/8.5/fpm/pool.d/zz-joinery-memory.conf" "$T/php/8.3/fpm/pool.d/zz-joinery-memory.conf" && echo same)" "same"
chk "a re-run writes nothing" "$(fpm --no-restart --ram-mb=512 | tail -1)" "Already tuned; nothing written."
chk "a new budget rewrites it" "$(fpm --no-restart --ram-mb=1024 >/dev/null; grep -c '^pm.max_children = 17$' "$T/php/8.5/fpm/pool.d/zz-joinery-memory.conf")" "1"

echo "== PostgreSQL's limit is read without PAM, and a failed read lifts no cap =="
chk "never su or runuser (a PAM session pair in auth.log every minute)" "$(grep -v '^[[:space:]]*#' "$FPM" | grep -c -E '\bsu -|\brunuser\b')" "0"
chk "a failed read leaves the drop-in before anything is written" \
    "$(awk '/Left as it is: PostgreSQL/ { left = NR } /printf .%s\\n. "\$content" > "\$f"/ { write = NR } END { print (left && write && left < write) ? "before" : "after" }' "$FPM")" "before"

echo "== refusals =="
chk "a bad --ram-mb is refused" "$(fpm --dry-run --ram-mb=lots >/dev/null; echo $?)" "1"
chk "no pool directory is exit 1" "$(FPM_ROOT="$T/none" bash "$FPM" --dry-run --ram-mb=512 >/dev/null 2>&1; echo $?)" "1"

echo "== php-fpm reads the drop-in over www.conf =="
BIN="$(ls -1 /usr/sbin/php-fpm* 2>/dev/null | sort -V | tail -1)"
if [ -n "$BIN" ]; then
    mkdir -p "$T/real/pool.d"
    printf '[global]\npid = %s/real/fpm.pid\nerror_log = %s/real/err.log\ninclude = %s/real/pool.d/*.conf\n' "$T" "$T" "$T" > "$T/real/fpm.conf"
    printf '[www]\nlisten = 127.0.0.1:9\npm = dynamic\npm.max_children = 5\npm.start_servers = 2\npm.min_spare_servers = 1\npm.max_spare_servers = 3\n' > "$T/real/pool.d/www.conf"
    cp "$T/php/8.5/fpm/pool.d/zz-joinery-memory.conf" "$T/real/pool.d/"
    chk "the configuration is valid" "$("$BIN" -t -y "$T/real/fpm.conf" > /dev/null 2>&1 && echo valid)" "valid"
    chk "the pool runs 17 workers, not the packaged 5" "$("$BIN" -tt -y "$T/real/fpm.conf" 2>&1 | grep -o 'pm.max_children = [0-9]*' | tail -1)" "pm.max_children = 17"

    echo "== a reload lets a request in flight finish =="
    # A FastCGI client for one request: FINISHED when the script's whole answer
    # came back, CUT when the connection ended first.
    cat > "$T/fcgi.php" <<'PHP'
<?php
function rec($t, $c) { return pack('CCnnCx', 1, $t, 1, strlen($c), 0) . $c; }
function nv($k, $v) { return chr(strlen($k)) . chr(strlen($v)) . $k . $v; }
$s = @stream_socket_client('unix://' . $argv[1], $en, $es, 5);
if (!$s) { echo "NOCONNECT"; exit; }
fwrite($s, rec(1, pack('nCx5', 1, 0)) . rec(4, nv('SCRIPT_FILENAME', $argv[2]) . nv('REQUEST_METHOD', 'GET')) . rec(4, '') . rec(5, ''));
$out = ''; $ended = false;
while (!feof($s)) {
    $h = fread($s, 8); if (strlen($h) < 8) break;
    $u = unpack('Cv/Ctype/nid/nlen/Cpad', $h);
    $c = $u['len'] ? stream_get_contents($s, $u['len']) : ''; if ($u['pad']) fread($s, $u['pad']);
    if ($u['type'] == 6) $out .= $c;
    if ($u['type'] == 3) { $ended = true; break; }
}
echo (strpos($out, 'DONE') !== false && $ended) ? "FINISHED" : "CUT";
PHP
    echo '<?php sleep(3); echo "DONE";' > "$T/slow.php"
    reload_mid_request() {   # $1 = process_control_timeout
        local d; d="$(mktemp -d)"   # a short path: a socket's is at most 108 bytes
        printf '[global]\npid = %s/fpm.pid\nerror_log = %s/err.log\nprocess_control_timeout = %s\ndaemonize = yes\n[t]\nlisten = %s/s.sock\npm = static\npm.max_children = 2\n' "$d" "$d" "$1" "$d" > "$d/fpm.conf"
        "$BIN" -y "$d/fpm.conf" -n >/dev/null 2>&1 || { echo "NOSTART"; rm -rf "${d:?}"; return; }
        for _ in $(seq 1 25); do [ -S "$d/s.sock" ] && break; sleep 0.2; done
        php "$T/fcgi.php" "$d/s.sock" "$T/slow.php" > "$d/res" &
        local cp=$!
        sleep 1
        kill -USR2 "$(cat "$d/fpm.pid")"     # what systemctl reload sends
        wait "$cp"
        cat "$d/res"
        sleep 1; kill "$(cat "$d/fpm.pid")" 2>/dev/null
        sleep 0.5; rm -rf "${d:?}"
    }
    chk "process_control_timeout 0 (the packaged default): a reload cuts it" "$(reload_mid_request 0)" "CUT"
    chk "process_control_timeout 10s (zz-joinery-status.conf): it finishes" "$(reload_mid_request 10s)" "FINISHED"
else
    echo "  SKIP: no php-fpm on this machine"
fi

echo
echo "tune_php_fpm gate: $passed passed, $failed failed"
[ "$failed" -eq 0 ]
