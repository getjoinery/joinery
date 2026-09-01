#!/usr/bin/env bash
# tune_postgres_memory.sh - size PostgreSQL's memory settings from the machine
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
        -h|--help)    sed -n '2,55p' "$0"; exit 0 ;;
        *) echo "Unknown option: $arg" >&2; exit 1 ;;
    esac
done

# ---- is this a container? --------------------------------------------------
# Only consulted when no cgroup limit applies, to decide between "this whole
# machine is mine" and "I cannot tell what is mine". --container states it
# outright, for the caller that already knows (the container start command);
# the probes are a backstop for a manual run. /.dockerenv is absent under
# BuildKit, so it cannot be the only signal.
in_container() {
    [ "$IS_CONTAINER" -eq 1 ] && return 0
    [ -f /.dockerenv ] && return 0
    [ -f /run/.containerenv ] && return 0
    if command -v systemd-detect-virt >/dev/null 2>&1; then
        systemd-detect-virt --container --quiet && return 0
    fi
    # PID 1 in a container is not the host's init: its cgroup path names the
    # container runtime rather than the host's own slice.
    if [ -r /proc/1/cgroup ] && grep -qE '(docker|lxc|containerd|kubepods|podman)' /proc/1/cgroup; then
        return 0
    fi
    return 1
}

# ---- how much RAM is really ours -------------------------------------------
mem_total_mb=$(( $(awk '/^MemTotal:/ {print $2}' /proc/meminfo) / 1024 ))

# The cgroup limit, in MB, when one actually applies. Empty when unlimited:
# cgroup v2 says "max", cgroup v1 says a number larger than physical memory.
cgroup_limit_mb() {
    local limit
    if [ -r /sys/fs/cgroup/memory.max ]; then
        limit=$(cat /sys/fs/cgroup/memory.max)
    elif [ -r /sys/fs/cgroup/memory/memory.limit_in_bytes ]; then
        limit=$(cat /sys/fs/cgroup/memory/memory.limit_in_bytes)
    else
        return 0
    fi
    if [[ "$limit" =~ ^[0-9]+$ ]] && [ $(( limit / 1048576 )) -lt "$mem_total_mb" ]; then
        echo $(( limit / 1048576 ))
    fi
}

if [ -n "$RAM_MB_OVERRIDE" ]; then
    ram_mb="$RAM_MB_OVERRIDE"
    ram_source="--ram-mb"
else
    cg_mb="$(cgroup_limit_mb)"
    if [ -n "$cg_mb" ]; then
        ram_mb="$cg_mb"
        ram_source="cgroup limit"
    elif in_container; then
        cat >&2 <<MSG
Not sizing PostgreSQL's memory: this is a container with no memory limit.

/proc/meminfo reports ${mem_total_mb} MB, but that is the HOST's memory, not this
container's budget — every container on the host reads the same figure, so
sizing from it hands each one a fraction of memory they all share.

Give the container a limit, and this sizes itself from that:
    docker run --memory=512m ...          (install.sh site --memory=512m)
    docker update --memory=512m NAME      (an already running container)
Or state the budget directly:
    $(basename "$0") --ram-mb=512

PostgreSQL keeps its packaged settings. Nothing was written.
MSG
        exit 3
    else
        ram_mb="$mem_total_mb"
        ram_source="MemTotal"
    fi
fi

shared_mb=$(( ram_mb / 5 ))
[ "$shared_mb" -lt 64 ] && shared_mb=64
[ "$shared_mb" -gt 2048 ] && shared_mb=2048
cache_mb=$(( ram_mb / 2 ))
[ "$cache_mb" -lt 128 ] && cache_mb=128

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

echo "RAM available: ${ram_mb} MB (${ram_source}) -> shared_buffers ${shared_mb}MB, effective_cache_size ${cache_mb}MB (${DROPIN})"

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
