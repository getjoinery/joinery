#!/usr/bin/env bash
# tune_postgres_memory.sh - size PostgreSQL's memory settings from the machine
# Version: 1.0.0
#
# Description:
#   PostgreSQL ships with shared_buffers = 128MB whatever the box, and the
#   installer used to lower that to 64MB for everything, so a 2 GB VPS ran its
#   database out of a buffer smaller than one busy table — every page load on a
#   100k-message mailbox read the table from the OS cache instead of Postgres's
#   own. This writes the two settings that matter, sized from the RAM actually
#   available to this machine, as a conf.d drop-in:
#
#     shared_buffers        20% of RAM (floor 64MB, cap 2GB) — Postgres's own cache,
#                           kept modest because Apache and PHP share the box
#     effective_cache_size  50% of RAM (floor 128MB) — a planner hint, not an
#                           allocation: how much of the OS cache it may assume
#
#   RAM is read from the cgroup limit when one applies (a container sees the
#   host's /proc/meminfo, which is not its budget), else from MemTotal.
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
#
# Exit status:
#   0  tuned (or already tuned, or dry run)
#   1  PostgreSQL configuration directory not found

set -euo pipefail

RESTART=1
DRY_RUN=0
for arg in "$@"; do
    case "$arg" in
        --no-restart) RESTART=0 ;;
        --dry-run)    DRY_RUN=1 ;;
        -h|--help)    sed -n '2,40p' "$0"; exit 0 ;;
        *) echo "Unknown option: $arg" >&2; exit 1 ;;
    esac
done

# ---- how much RAM is really ours -------------------------------------------
mem_total_kb=$(awk '/^MemTotal:/ {print $2}' /proc/meminfo)
ram_mb=$(( mem_total_kb / 1024 ))
# cgroup v2 (a plain number when a limit applies; "max" when none)
if [ -r /sys/fs/cgroup/memory.max ]; then
    limit=$(cat /sys/fs/cgroup/memory.max)
    if [[ "$limit" =~ ^[0-9]+$ ]] && [ $(( limit / 1048576 )) -lt "$ram_mb" ]; then
        ram_mb=$(( limit / 1048576 ))
    fi
# cgroup v1 (an absurdly large number when no limit applies)
elif [ -r /sys/fs/cgroup/memory/memory.limit_in_bytes ]; then
    limit=$(cat /sys/fs/cgroup/memory/memory.limit_in_bytes)
    if [[ "$limit" =~ ^[0-9]+$ ]] && [ $(( limit / 1048576 )) -lt "$ram_mb" ]; then
        ram_mb=$(( limit / 1048576 ))
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
# Sized from ${ram_mb} MB of RAM available to this machine.
shared_buffers = ${shared_mb}MB
effective_cache_size = ${cache_mb}MB
CONF
)

echo "RAM available: ${ram_mb} MB -> shared_buffers ${shared_mb}MB, effective_cache_size ${cache_mb}MB (${DROPIN})"

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
