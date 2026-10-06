#!/usr/bin/env bash
# tune_postgres_memory.sh - size PostgreSQL's memory settings from the machine
# Version: 1.4.0 - the budget and shared_buffers come from _memory_plan.sh, shared with
#                  tune_php_fpm.sh; a budget from a cgroup limit or --ram-mb also sets
#                  max_connections to the PHP pool plus 20 (specs/multi_tenant_docker_hosts.md WP2)
# Version: 1.3.0 - max_parallel_workers_per_gather scales with the CPUs: half of them, capped at
#                  4 (1 CPU: 0, 2-3: 1, 4-5: the default 2, 6-7: 3, 8+: 4), written only
#                  where it differs from the default, so a 4-5 CPU machine's drop-in is unchanged
# Version: 1.2.0 - on a machine with one CPU, max_parallel_workers_per_gather = 0: a parallel
#                  plan's workers share the one core with their leader (a 99k-row count ran
#                  25% slower in parallel on jeremytunnell)
# Version: 1.1.0
#
# Description:
#   PostgreSQL ships with shared_buffers = 128MB whatever the box, which on a
#   2 GB VPS is smaller than one busy table: every page load on a 100k-message
#   mailbox reads the table from the OS cache instead of Postgres's own. This
#   writes the two settings that matter, sized from the RAM this machine
#   actually owns, as a conf.d drop-in:
#
#     shared_buffers        20% of RAM (floor 64MB, cap 2GB) — Postgres's own cache,
#                           kept modest because Apache and PHP share the box
#     effective_cache_size  50% of RAM (floor 128MB) — a planner hint, not an
#                           allocation: how much of the OS cache it may assume
#
#   and max_parallel_workers_per_gather, from the CPUs this machine owns (the
#   smaller of nproc and a cgroup CPU quota): half of them, capped at 4.
#
#     CPUs   workers per query
#     1      0   parallel workers would only take turns on the leader's core
#     2-3    1
#     4-5    2   PostgreSQL's default, so nothing is written
#     6-7    3
#     8+     4
#
#   Half, because a web server runs many requests at once and one query should
#   not take every core from the rest. The line is written only when it differs
#   from the default, so re-running on a 4-5 CPU machine changes nothing.
#
#   "RAM this machine actually owns" is the whole point, and it is the one
#   thing a container cannot read off /proc/meminfo — which reports the host's
#   memory, not the container's budget. Eight containers on one host each
#   reading 20% of the same host is 160% of it. So the budget is resolved in
#   this order, and the script REFUSES rather than guesses:
#
#     1. --ram-mb=N            an explicit budget, and it wins
#     2. the cgroup limit      when one applies (this is a bounded container)
#     3. MemTotal              only when this is not a container
#     4. otherwise             skip, change nothing, say why (exit 3)
#
#   Case 4 is a container with no memory limit. There is no honest answer
#   there: the host's RAM is not this container's to size from, and picking a
#   fraction of it silently over-commits the host once more than one container
#   does the same. Give the container a limit (docker run --memory=512m) or
#   state the budget with --ram-mb, and this tunes correctly.
#
#   When the budget is a container's (the cgroup limit, or --ram-mb), it also
#   writes max_connections: the PHP pool tune_php_fpm.sh sizes from the same
#   budget, one connection per worker, plus 20 for scheduled tasks, the agent
#   and maintenance. The split lives in _memory_plan.sh, which both read.
#
#   Nothing else is touched: work_mem stays at the default and max_wal_size at
#   the installer's small cap, a deliberate disk-space choice on tiny VPSes.
#
#   A drop-in, not a sed on postgresql.conf: conf.d is included last, so it
#   wins, and re-running rewrites one small file. The file is chmod 644 —
#   under a restrictive umask it would land unreadable and Postgres refuses
#   to start (see install.sh's logging drop-in for the history).
#
#   Idempotent. When the drop-in already says what it would say, nothing is
#   written and Postgres is not restarted.
#
# Usage:
#   ./tune_postgres_memory.sh              Write the drop-in and restart PostgreSQL
#   ./tune_postgres_memory.sh --no-restart Write only (the installer restarts itself)
#   ./tune_postgres_memory.sh --dry-run    Print what would be written, change nothing
#   ./tune_postgres_memory.sh --ram-mb=512 Size from a stated budget in MB
#   ./tune_postgres_memory.sh --container  Assert this is a container, so an
#                                          unbounded cgroup is a refusal, not
#                                          a fall back to the host's MemTotal
#
# Exit status:
#   0  tuned (or already tuned, or dry run)
#   1  PostgreSQL configuration directory not found, or a bad option
#   3  skipped: this container's memory budget could not be determined

set -euo pipefail

RESTART=1
DRY_RUN=0
RAM_MB_OVERRIDE=""
IS_CONTAINER=0
for arg in "$@"; do
    case "$arg" in
        --no-restart) RESTART=0 ;;
        --dry-run)    DRY_RUN=1 ;;
        --container)  IS_CONTAINER=1 ;;
        --ram-mb=*)
            RAM_MB_OVERRIDE="${arg#*=}"
            if ! [[ "$RAM_MB_OVERRIDE" =~ ^[0-9]+$ ]] || [ "$RAM_MB_OVERRIDE" -lt 1 ]; then
                echo "--ram-mb needs a whole number of megabytes, got: ${RAM_MB_OVERRIDE}" >&2
                exit 1
            fi
            ;;
        -h|--help)    sed -n '2,85p' "$0"; exit 0 ;;
        *) echo "Unknown option: $arg" >&2; exit 1 ;;
    esac
done

# ---- how much RAM is really ours -------------------------------------------
# The budget and its split live in _memory_plan.sh, which tune_php_fpm.sh reads
# too: PostgreSQL's share and the PHP pool come from the same figures.
. "$(dirname "${BASH_SOURCE[0]}")/_memory_plan.sh"
MEMORY_PLAN_RAM_MB="$RAM_MB_OVERRIDE"
MEMORY_PLAN_CONTAINER="$IS_CONTAINER"
plan_status=0
memory_plan_budget || plan_status=$?
if [ "$plan_status" -ne 0 ]; then
    echo "Not sizing PostgreSQL's memory. PostgreSQL keeps its packaged settings; nothing was written." >&2
    exit "$plan_status"
fi
ram_mb="$MEMORY_PLAN_MB"
ram_source="$MEMORY_PLAN_SOURCE"

shared_mb="$(memory_plan_shared_buffers "$ram_mb")"
cache_mb=$(( ram_mb / 2 ))
[ "$cache_mb" -lt 128 ] && cache_mb=128

# ---- how many CPUs are really ours -----------------------------------------
# nproc honours a cpuset but not a CPU quota (docker run --cpus=1), which
# cgroup v2 states in cpu.max as "quota period", or "max" for none.
cpus=$(nproc 2>/dev/null || echo 1)
if [ -r /sys/fs/cgroup/cpu.max ]; then
    read -r cpu_quota cpu_period < /sys/fs/cgroup/cpu.max || true
    if [[ "${cpu_quota:-}" =~ ^[0-9]+$ ]] && [[ "${cpu_period:-}" =~ ^[0-9]+$ ]] && [ "$cpu_period" -gt 0 ]; then
        quota_cpus=$(( (cpu_quota + cpu_period - 1) / cpu_period ))
        [ "$quota_cpus" -lt "$cpus" ] && cpus=$quota_cpus
    fi
fi
per_gather=$(( cpus / 2 ))
[ "$per_gather" -gt 4 ] && per_gather=4
parallel_line=""
if [ "$per_gather" -ne 2 ]; then
    parallel_line="max_parallel_workers_per_gather = ${per_gather}"
fi

# ---- where the cluster keeps its configuration -----------------------------
PG_VERSION="${PG_VERSION:-$(ls /etc/postgresql 2>/dev/null | sort -n | tail -1)}"
PG_CONFIG_DIR="/etc/postgresql/${PG_VERSION}/main"
if [ -z "$PG_VERSION" ] || [ ! -d "$PG_CONFIG_DIR" ]; then
    echo "PostgreSQL configuration directory not found under /etc/postgresql" >&2
    exit 1
fi
DROPIN="${PG_CONFIG_DIR}/conf.d/20-joinery-memory.conf"

content=$(cat <<CONF
# Managed by Joinery tune_postgres_memory.sh. Overwritten when re-run.
# Sized from ${ram_mb} MB of RAM available to this machine (${ram_source}).
shared_buffers = ${shared_mb}MB
effective_cache_size = ${cache_mb}MB
CONF
)
# A container's PHP pool is sized from the same budget (tune_php_fpm.sh), and
# each worker holds at most one connection: room for the pool plus the
# scheduled tasks, the agent and maintenance, instead of the packaged 100.
connections=""
if [ "$ram_source" != "MemTotal" ]; then
    connections="$(memory_plan_max_connections "$ram_mb")"
    content="${content}
# $(memory_plan_php_children "$ram_mb") PHP workers plus ${MEMORY_PLAN_OTHER_CONNECTIONS} for scheduled tasks, the agent and maintenance.
max_connections = ${connections}"
fi
if [ -n "$parallel_line" ]; then
    content="${content}
# ${cpus} CPU(s): at most half of them on one query (PostgreSQL's default is 2).
${parallel_line}"
fi

echo "RAM available: ${ram_mb} MB (${ram_source}) -> shared_buffers ${shared_mb}MB, effective_cache_size ${cache_mb}MB${connections:+, max_connections ${connections}}; CPUs: ${cpus} -> ${per_gather} parallel worker(s) per query (${DROPIN})"

if [ "$DRY_RUN" -eq 1 ]; then
    echo "$content"
    exit 0
fi

if [ -f "$DROPIN" ] && [ "$(cat "$DROPIN")" = "$content" ]; then
    echo "Already tuned; nothing written."
    exit 0
fi

mkdir -p "${PG_CONFIG_DIR}/conf.d"
chmod 755 "${PG_CONFIG_DIR}/conf.d"
printf '%s\n' "$content" > "$DROPIN"
chmod 644 "$DROPIN"

# A drop-in nobody reads looks configured. Same guard the installer applies.
if ! grep -qE "^[[:space:]]*include_dir[[:space:]]*=[[:space:]]*'conf\.d'" "${PG_CONFIG_DIR}/postgresql.conf"; then
    echo "include_dir = 'conf.d'" >> "${PG_CONFIG_DIR}/postgresql.conf"
    echo "Added include_dir = 'conf.d' to postgresql.conf"
fi

if [ "$RESTART" -eq 0 ]; then
    echo "Written; restart PostgreSQL to apply."
    exit 0
fi

# shared_buffers needs a restart, not a reload. `systemctl restart postgresql`
# is the umbrella unit and restarts nothing — the cluster unit is the one.
if [ -d /run/systemd/system ]; then
    systemctl restart "postgresql@${PG_VERSION}-main"
else
    pg_ctlcluster "$PG_VERSION" main restart
fi
# Read back without a database login (local socket auth may want a password):
# postgres -C reports the effective value in 8 kB pages.
pages=$(su -s /bin/sh postgres -c "/usr/lib/postgresql/${PG_VERSION}/bin/postgres -C shared_buffers --config-file=${PG_CONFIG_DIR}/postgresql.conf" 2>/dev/null || true)
if [[ "$pages" =~ ^[0-9]+$ ]]; then
    echo "PostgreSQL restarted. Effective shared_buffers: $(( pages * 8 / 1024 ))MB"
else
    echo "PostgreSQL restarted (could not read the effective value back)."
fi
