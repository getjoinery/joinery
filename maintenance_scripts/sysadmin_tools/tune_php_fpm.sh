#!/usr/bin/env bash
# tune_php_fpm.sh - size PHP-FPM's worker pool from the memory this site owns
# Version: 1.0.0 (specs/multi_tenant_docker_hosts.md WP2)
#
# Description:
#   The packaged pool allows five PHP workers whatever the machine, and five
#   workers on heavy pages can use a 256 MB site's whole budget on their own.
#   This sets the pool from the budget instead:
#
#     pm.max_children   what is left once PostgreSQL and the rest have their
#                       share, divided by one worker's size, never fewer than
#                       2 and never more than 80 (see _memory_plan.sh)
#
#   with the spare-worker counts held inside it. PostgreSQL's max_connections
#   follows the same figure (tune_postgres_memory.sh reads the same plan).
#
#   The budget is resolved exactly as tune_postgres_memory.sh resolves it:
#   --ram-mb, then the cgroup limit, then MemTotal on a machine that is not a
#   container; a container with no limit is skipped (exit 3), because the
#   host's memory is not this container's to size from.
#
#   The settings go in a drop-in, pool.d/zz-joinery-memory.conf, for every PHP
#   version installed: a second [www] section, read after www.conf, overrides
#   only the lines it names, so the packaged www.conf stays untouched.
#
#   Idempotent. When every drop-in already says what it would say, nothing is
#   written and nothing restarts.
#
# Usage:
#   ./tune_php_fpm.sh              Write the drop-in and restart PHP-FPM
#   ./tune_php_fpm.sh --no-restart Write only (the caller starts PHP-FPM)
#   ./tune_php_fpm.sh --dry-run    Print what would be written, change nothing
#   ./tune_php_fpm.sh --ram-mb=512 Size from a stated budget in MB
#   ./tune_php_fpm.sh --container  Assert this is a container, so an unbounded
#                                  cgroup is a refusal, not a fall back to
#                                  the host's MemTotal
#
# Exit status:
#   0  tuned (or already tuned, or dry run)
#   1  no PHP-FPM pool directory found, or a bad option
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
        -h|--help)    sed -n '2,41p' "$0"; exit 0 ;;
        *) echo "Unknown option: $arg" >&2; exit 1 ;;
    esac
done

. "$(dirname "${BASH_SOURCE[0]}")/_memory_plan.sh"
MEMORY_PLAN_RAM_MB="$RAM_MB_OVERRIDE"
MEMORY_PLAN_CONTAINER="$IS_CONTAINER"
plan_status=0
memory_plan_budget || plan_status=$?
if [ "$plan_status" -ne 0 ]; then
    echo "Not sizing PHP-FPM's pool. PHP keeps its packaged pool; nothing was written." >&2
    exit "$plan_status"
fi
ram_mb="$MEMORY_PLAN_MB"
children="$(memory_plan_php_children "$ram_mb")"

# Spare workers sit inside the pool: start with up to 2, keep 1 to 3 idle.
start=2;     [ "$start" -gt "$children" ] && start=$children
max_spare=3; [ "$max_spare" -gt "$children" ] && max_spare=$children

content="; Managed by Joinery tune_php_fpm.sh. Overwritten when re-run.
; Sized from ${ram_mb} MB of RAM available to this machine (${MEMORY_PLAN_SOURCE}):
; ${MEMORY_PLAN_WORKER_MB} MB a worker, after PostgreSQL's $(memory_plan_shared_buffers "$ram_mb") MB and ${MEMORY_PLAN_BASE_MB} MB for the rest.
[www]
pm.max_children = ${children}
pm.start_servers = ${start}
pm.min_spare_servers = 1
pm.max_spare_servers = ${max_spare}"

FPM_ROOT="${FPM_ROOT:-/etc/php}"
[ "$(id -u)" -eq 0 ] && FPM_ROOT=/etc/php
pools=()
for d in "$FPM_ROOT"/*/fpm/pool.d; do
    [ -d "$d" ] && pools+=("$d")
done
if [ "${#pools[@]}" -eq 0 ]; then
    echo "No PHP-FPM pool directory under ${FPM_ROOT}/*/fpm/pool.d" >&2
    exit 1
fi

echo "RAM available: ${ram_mb} MB (${MEMORY_PLAN_SOURCE}) -> ${children} PHP worker(s)"

if [ "$DRY_RUN" -eq 1 ]; then
    echo "$content"
    exit 0
fi

changed=()
for d in "${pools[@]}"; do
    f="${d}/zz-joinery-memory.conf"
    if [ -f "$f" ] && [ "$(cat "$f")" = "$content" ]; then
        continue
    fi
    printf '%s\n' "$content" > "$f"
    chmod 644 "$f"
    changed+=("$(basename "$(dirname "$(dirname "$d")")")")   # /etc/php/8.5/fpm/pool.d -> 8.5
done

if [ "${#changed[@]}" -eq 0 ]; then
    echo "Already tuned; nothing written."
    exit 0
fi
echo "Written for PHP ${changed[*]}"

if [ "$RESTART" -eq 0 ]; then
    echo "Restart PHP-FPM to apply."
    exit 0
fi
for v in "${changed[@]}"; do
    if [ -d /run/systemd/system ]; then
        systemctl is-active --quiet "php${v}-fpm" && systemctl restart "php${v}-fpm"
    elif [ -x "/etc/init.d/php${v}-fpm" ]; then
        service "php${v}-fpm" status >/dev/null 2>&1 && service "php${v}-fpm" restart
    fi
done
echo "PHP-FPM restarted where it was running."
