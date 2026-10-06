#!/usr/bin/env bash
# _memory_plan.sh - how a site's memory budget is split, in one place.
# Sourced, never run: tune_postgres_memory.sh and tune_php_fpm.sh both read it,
# so PostgreSQL's share, the PHP pool and the connections between them come
# from the same figures and cannot drift apart
# (specs/multi_tenant_docker_hosts.md WP2).
#
# Version: 1.0.0
#
#   memory_plan_budget          sets MEMORY_PLAN_MB and MEMORY_PLAN_SOURCE from
#                               --ram-mb, the cgroup limit, or MemTotal (not a
#                               container); returns 3 with the reason on stderr
#                               when a container has no limit to read
#   memory_plan_shared_buffers  PostgreSQL's shared_buffers, in MB
#   memory_plan_php_children    PHP-FPM's pm.max_children
#   memory_plan_max_connections PostgreSQL's max_connections
#
# The PHP pool gets what is left once everything that is not a PHP worker has
# its share: PostgreSQL's shared_buffers, plus MEMORY_PLAN_BASE_MB for the rest
# (PostgreSQL's own processes, Apache, PHP's master and opcache, cron, and the
# kernel memory the container is charged for). Measured on a 2.1 site
# (2026-10-05): about 90 MB of those at rest besides shared_buffers. Each worker
# is counted at MEMORY_PLAN_WORKER_MB, its own memory plus the database
# connection it holds: the heaviest admin page measured used 10 MB of PHP
# memory, so 40 leaves room for a real site's larger pages.
#
# The caller sets MEMORY_PLAN_RAM_MB (a stated budget) and MEMORY_PLAN_CONTAINER
# (1 when the caller knows this is a container) before memory_plan_budget.

MEMORY_PLAN_BASE_MB=128
MEMORY_PLAN_WORKER_MB=40
# Never fewer PHP workers than this, whatever the budget: one slow request must
# not stop the site answering.
MEMORY_PLAN_MIN_CHILDREN=2
# Connections beside the PHP workers: the scheduled-task runner, the site
# agent's scripts, a backup's pg_dump, update_database, a maintenance psql.
MEMORY_PLAN_OTHER_CONNECTIONS=20
# The pool never grows past what PostgreSQL's packaged 100 connections hold.
MEMORY_PLAN_MAX_CHILDREN=$(( 100 - MEMORY_PLAN_OTHER_CONNECTIONS ))

memory_plan_in_container() {
    [ "${MEMORY_PLAN_CONTAINER:-0}" = "1" ] && return 0
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

memory_plan_mem_total_mb() {
    echo $(( $(awk '/^MemTotal:/ {print $2}' /proc/meminfo) / 1024 ))
}

# The cgroup limit, in MB, when one actually applies. Empty when unlimited:
# cgroup v2 says "max", cgroup v1 says a number larger than physical memory.
memory_plan_cgroup_limit_mb() {
    local limit
    if [ -r /sys/fs/cgroup/memory.max ]; then
        limit=$(cat /sys/fs/cgroup/memory.max)
    elif [ -r /sys/fs/cgroup/memory/memory.limit_in_bytes ]; then
        limit=$(cat /sys/fs/cgroup/memory/memory.limit_in_bytes)
    else
        return 0
    fi
    if [[ "$limit" =~ ^[0-9]+$ ]] && [ $(( limit / 1048576 )) -lt "$(memory_plan_mem_total_mb)" ]; then
        echo $(( limit / 1048576 ))
    fi
}

# "RAM this machine actually owns": a container cannot read it off
# /proc/meminfo, which reports the host's memory. Eight containers each sizing
# from the same host would claim it eight times over, so a container with no
# limit is refused rather than guessed at.
memory_plan_budget() {
    local cg_mb
    if [ -n "${MEMORY_PLAN_RAM_MB:-}" ]; then
        MEMORY_PLAN_MB="$MEMORY_PLAN_RAM_MB"
        MEMORY_PLAN_SOURCE="--ram-mb"
        return 0
    fi
    cg_mb="$(memory_plan_cgroup_limit_mb)"
    if [ -n "$cg_mb" ]; then
        MEMORY_PLAN_MB="$cg_mb"
        MEMORY_PLAN_SOURCE="cgroup limit"
        return 0
    fi
    if memory_plan_in_container; then
        cat >&2 <<MSG
This is a container with no memory limit.

/proc/meminfo reports $(memory_plan_mem_total_mb) MB, but that is the HOST's memory, not this
container's budget: every container on the host reads the same figure, so
sizing from it hands each one a fraction of memory they all share.

Give the container a limit, and this sizes itself from that:
    docker run --memory=512m ...          (install.sh site --memory=512m)
    docker update --memory=512m NAME      (an already running container)
Or state the budget directly with --ram-mb=512.
MSG
        return 3
    fi
    MEMORY_PLAN_MB="$(memory_plan_mem_total_mb)"
    MEMORY_PLAN_SOURCE="MemTotal"
}

# 20% of the budget, floor 64 MB, cap 2 GB: PostgreSQL's own cache, kept modest
# because Apache and PHP share the machine.
memory_plan_shared_buffers() {
    local mb=$(( $1 / 5 ))
    [ "$mb" -lt 64 ] && mb=64
    [ "$mb" -gt 2048 ] && mb=2048
    echo "$mb"
}

memory_plan_php_children() {
    local left=$(( $1 - $(memory_plan_shared_buffers "$1") - MEMORY_PLAN_BASE_MB ))
    local n=$(( left / MEMORY_PLAN_WORKER_MB ))
    [ "$n" -lt "$MEMORY_PLAN_MIN_CHILDREN" ] && n=$MEMORY_PLAN_MIN_CHILDREN
    [ "$n" -gt "$MEMORY_PLAN_MAX_CHILDREN" ] && n=$MEMORY_PLAN_MAX_CHILDREN
    echo "$n"
}

memory_plan_max_connections() {
    echo $(( $(memory_plan_php_children "$1") + MEMORY_PLAN_OTHER_CONNECTIONS ))
}
