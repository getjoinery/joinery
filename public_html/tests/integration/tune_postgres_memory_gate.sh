#!/bin/bash
# @joinery-test
# name: tune_postgres_memory
# tier: safe
# env: any
# needs: []
# timeout: 60
# covers: [maintenance_scripts/sysadmin_tools/tune_postgres_memory.sh]
#
# tune_postgres_memory.sh sizes PostgreSQL from the machine. This gate pins the
# parallel-worker rule in dry-run mode (nothing is written or restarted):
# max_parallel_workers_per_gather is half the CPUs, capped at 4, and is written
# only where it differs from PostgreSQL's default of 2 — so a 4-5 CPU machine's
# drop-in is unchanged and a re-run there restarts nothing. The CPU count comes
# from a stand-in nproc; a cgroup CPU quota can only lower it.

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
SCRIPT="$ROOT/maintenance_scripts/sysadmin_tools/tune_postgres_memory.sh"
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

if ! ls /etc/postgresql/*/main >/dev/null 2>&1; then
    echo "  SKIP: no PostgreSQL configuration directory on this machine"
    echo "tune_postgres_memory gate: 0 passed, 0 failed"
    exit 0
fi

# A cgroup quota below the stand-in count would lower it; this gate pins the
# rule, so it needs a machine without one below 16 CPUs.
quota_cap=""
if [ -r /sys/fs/cgroup/cpu.max ]; then
    read -r q p < /sys/fs/cgroup/cpu.max || true
    if [[ "${q:-}" =~ ^[0-9]+$ ]] && [[ "${p:-}" =~ ^[0-9]+$ ]] && [ "$p" -gt 0 ]; then
        quota_cap=$(( (q + p - 1) / p ))
    fi
fi

mkdir -p "$T/bin"
line_for() {
    printf '#!/bin/sh\necho %s\n' "$1" > "$T/bin/nproc"
    chmod +x "$T/bin/nproc" 2>/dev/null || true
    PATH="$T/bin:$PATH" bash "$SCRIPT" --dry-run --ram-mb=2048 2>/dev/null | grep '^max_parallel_workers_per_gather' || echo "none"
}

echo "=== workers per query follow the CPUs ==="
for pair in "1:max_parallel_workers_per_gather = 0" "2:max_parallel_workers_per_gather = 1" \
            "3:max_parallel_workers_per_gather = 1" "4:none" "5:none" \
            "6:max_parallel_workers_per_gather = 3" "7:max_parallel_workers_per_gather = 3" \
            "8:max_parallel_workers_per_gather = 4" "16:max_parallel_workers_per_gather = 4"; do
    n="${pair%%:*}"; want="${pair#*:}"
    if [ -n "$quota_cap" ] && [ "$quota_cap" -lt "$n" ]; then
        echo "  SKIP: $n CPUs (this machine's CPU quota is $quota_cap)"; continue
    fi
    chk "$n CPU(s)" "$(line_for "$n")" "$want"
done

echo "=== the memory lines are untouched by the CPU count ==="
printf '#!/bin/sh\necho 1\n' > "$T/bin/nproc"
chk "shared_buffers from --ram-mb" "$(PATH="$T/bin:$PATH" bash "$SCRIPT" --dry-run --ram-mb=2048 2>/dev/null | grep -c '^shared_buffers = 409MB$')" "1"
chk "dry run writes nothing" "$(PATH="$T/bin:$PATH" bash "$SCRIPT" --dry-run --ram-mb=2048 2>/dev/null | grep -c 'Written\|restarted')" "0"

echo
echo "tune_postgres_memory gate: $passed passed, $failed failed"
[ "$failed" -eq 0 ]
