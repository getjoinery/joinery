#!/bin/bash
# @joinery-test
# name: tune_php_fpm
# tier: safe
# env: any
# needs: []
# timeout: 60
# covers: [maintenance_scripts/sysadmin_tools/tune_php_fpm.sh, maintenance_scripts/sysadmin_tools/_memory_plan.sh, maintenance_scripts/sysadmin_tools/tune_postgres_memory.sh]
#
# A site container's PHP pool is sized from its memory budget
# (specs/multi_tenant_docker_hosts.md WP2), and PostgreSQL's connections follow
# the pool, both from _memory_plan.sh:
#
#   - workers = (budget - shared_buffers - 128 MB) / 40 MB, never fewer than 2,
#     never more than 80; spare counts stay inside the pool
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
fpm() { FPM_ROOT="$T/php" bash "$FPM" "$@" 2>&1; }
children_for() { fpm --dry-run --ram-mb="$1" | sed -n 's/^pm.max_children = //p'; }

echo "== workers follow the budget =="
for pair in 128:2 256:2 384:4 512:7 768:12 1024:17 2048:37 8192:80; do
    chk "${pair%%:*} MB -> ${pair#*:} workers" "$(children_for "${pair%%:*}")" "${pair#*:}"
done

echo "== spare workers stay inside the pool =="
out="$(fpm --dry-run --ram-mb=256)"
chk "2 workers: start 2, min spare 1, max spare 2" \
    "$(echo "$out" | grep -E '^pm\.(start_servers|min_spare_servers|max_spare_servers)' | tr '\n' ' ')" \
    "pm.start_servers = 2 pm.min_spare_servers = 1 pm.max_spare_servers = 2 "
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
else
    echo "  SKIP: no php-fpm on this machine"
fi

echo
echo "tune_php_fpm gate: $passed passed, $failed failed"
[ "$failed" -eq 0 ]
