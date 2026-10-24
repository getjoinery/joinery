#!/usr/bin/env bash
# _memory_plan.sh - how a site's memory budget is split, in one place.
# Sourced, never run: tune_postgres_memory.sh and tune_php_fpm.sh both read it,
# so PostgreSQL's share, the PHP pool and the connections between them come
# from the same figures and cannot drift apart
# (specs/multi_tenant_docker_hosts.md WP2).
#
# Version: 1.1.0 - a machine sized from MemTotal keeps a host reserve back for what is not a site
#                  (the agent, sshd, journald, rspamd, the office editor), the PHP pool is held to
#                  MEMORY_PLAN_CHILDREN_PER_CPU workers a CPU (a container's quota counts as it is),
#                  and never fewer than 3 workers: an AI chat turn holds one for its whole run
#                  (specs/php_fpm_sizing_bare_metal.md WP1, WP2)
# Version: 1.0.2 - a container with no memory limit is told to get one from install.sh site or site_limits.sh,
#                which take it on Docker and on the site container engine alike, not from docker commands
#                (specs/site_container_engine.md B33)
# Version: 1.0.1 - notes that ImageDecoder (public_html/includes/ImageDecoder.php) mirrors the
#                  base and shared_buffers figures to size its decode ceiling; a unit test
#                  (tests/unit/image_decoder_test.php) pins the two equal, so change both.
# Version: 1.0.0
#
#   memory_plan_budget          sets MEMORY_PLAN_MB and MEMORY_PLAN_SOURCE from
#                               --ram-mb, the cgroup limit, or MemTotal less the
#                               host reserve (not a container; MEMORY_PLAN_TOTAL_MB
#                               and MEMORY_PLAN_RESERVE_MB then say how); returns 3
#                               with the reason on stderr when a container has no
#                               limit to read
#   memory_plan_cpu_milli       the CPUs this machine owns, in thousandths
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
# A machine that is not a container is sized from MemTotal, but not all of it
# is the site's: the host keeps a reserve back first (memory_plan_host_reserve).
#
# The caller sets MEMORY_PLAN_RAM_MB (a stated budget) and MEMORY_PLAN_CONTAINER
# (1 when the caller knows this is a container) before memory_plan_budget, and
# may set MEMORY_PLAN_CPUS (a stated CPU count, 0.5 allowed). Test hook, not
# honoured for root: MEMORY_PLAN_ROOT=<dir> reads <dir>/proc/meminfo,
# <dir>/sys/fs/cgroup and the reserve's markers under <dir> instead of /.

MEMORY_PLAN_BASE_MB=128          # also ImageDecoder::PLAN_BASE_MB — change both
MEMORY_PLAN_WORKER_MB=40
# Never fewer PHP workers than this, whatever the budget: one slow request must
# not stop the site answering.
# 3, not 2: an AI chat turn holds a worker for its whole run (ChatAsync detaches
# and lifts the time limit), so on two workers one chat is half the site.
MEMORY_PLAN_MIN_CHILDREN=3
# Most of a worker's time is a wait (the database, an IMAP server, an outside
# API), so more workers than CPUs is right; past this many a burst of heavy
# pages queues in the CPU's run queue, holding memory, instead of in PHP-FPM's
# listen queue. The minimum wins over this ceiling.
MEMORY_PLAN_CHILDREN_PER_CPU=8
# Connections beside the PHP workers: the scheduled-task runner, the site
# agent's scripts, a backup's pg_dump, update_database, a maintenance psql.
MEMORY_PLAN_OTHER_CONNECTIONS=20
# The pool never grows past what PostgreSQL's packaged 100 connections hold.
MEMORY_PLAN_MAX_CHILDREN=$(( 100 - MEMORY_PLAN_OTHER_CONNECTIONS ))

# The host reserve: what a machine that is not a container runs besides its
# site, kept back from MemTotal before the plan splits the rest. Starting
# figures, generous on purpose (a reserve too large costs a few workers, one
# too small costs swap); the host report shows the measured figure beside the
# reserve, so drift is seen and corrected by a release.
#   base     the agent, sshd, fail2ban, journald, cron, Apache's parent, the
#            kernel's own
#   rspamd   the mail filter, when installed: 62 MB resident on dev
#            (2026-10-24); 128 is twice that. At 256 a 1 GB mailbox box fell
#            to the minimum 3 workers and a 64 MB shared_buffers (reviewer2 F2)
#   office   the office editor's own MemoryMax, read from its unit's drop-in
#            (install_office_editor.sh), when that is installed
MEMORY_PLAN_RESERVE_BASE_MB=384
MEMORY_PLAN_RESERVE_RSPAMD_MB=128
MEMORY_PLAN_OFFICE_DROPIN=/etc/systemd/system/coolwsd.service.d/joinery.conf

memory_plan_root() {
    if [ "$(id -u)" -ne 0 ] && [ -n "${MEMORY_PLAN_ROOT:-}" ]; then
        echo "${MEMORY_PLAN_ROOT%/}"
    fi
}

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
    echo $(( $(awk '/^MemTotal:/ {print $2}' "$(memory_plan_root)/proc/meminfo") / 1024 ))
}

# Sets MEMORY_PLAN_RESERVE_MB and MEMORY_PLAN_RESERVE_DETAIL ("384 base + 128
# rspamd").
memory_plan_host_reserve() {
    local root office
    root="$(memory_plan_root)"
    MEMORY_PLAN_RESERVE_MB=$MEMORY_PLAN_RESERVE_BASE_MB
    MEMORY_PLAN_RESERVE_DETAIL="${MEMORY_PLAN_RESERVE_BASE_MB} base"
    if [ -e "${root}/usr/bin/rspamd" ] || [ -e "${root}/usr/sbin/rspamd" ]; then
        MEMORY_PLAN_RESERVE_MB=$(( MEMORY_PLAN_RESERVE_MB + MEMORY_PLAN_RESERVE_RSPAMD_MB ))
        MEMORY_PLAN_RESERVE_DETAIL="${MEMORY_PLAN_RESERVE_DETAIL} + ${MEMORY_PLAN_RESERVE_RSPAMD_MB} rspamd"
    fi
    if [ -r "${root}${MEMORY_PLAN_OFFICE_DROPIN}" ]; then
        office="$(sed -n 's/^MemoryMax=\([0-9]*\)M$/\1/p' "${root}${MEMORY_PLAN_OFFICE_DROPIN}" | tail -1)"
        if [[ "$office" =~ ^[0-9]+$ ]]; then
            MEMORY_PLAN_RESERVE_MB=$(( MEMORY_PLAN_RESERVE_MB + office ))
            MEMORY_PLAN_RESERVE_DETAIL="${MEMORY_PLAN_RESERVE_DETAIL} + ${office} office editor"
        fi
    fi
}

# The CPUs this machine owns, in thousandths: MEMORY_PLAN_CPUS when stated,
# else nproc, lowered by a cgroup CPU quota (a container's --cpus), which
# counts as it is: 0.5 CPU is 500, not rounded up or down.
memory_plan_cpu_milli() {
    local root milli quota period q
    root="$(memory_plan_root)"
    if [[ "${MEMORY_PLAN_CPUS:-}" =~ ^[0-9]+(\.[0-9]{1,3})?$ ]]; then
        awk -v c="$MEMORY_PLAN_CPUS" 'BEGIN { printf "%d", c * 1000 + 0.5 }'
        return 0
    fi
    milli=$(( $(nproc 2>/dev/null || echo 1) * 1000 ))
    quota=""; period=""
    if [ -r "${root}/sys/fs/cgroup/cpu.max" ]; then
        read -r quota period < "${root}/sys/fs/cgroup/cpu.max" || true
    elif [ -r "${root}/sys/fs/cgroup/cpu/cpu.cfs_quota_us" ] && [ -r "${root}/sys/fs/cgroup/cpu/cpu.cfs_period_us" ]; then
        quota="$(cat "${root}/sys/fs/cgroup/cpu/cpu.cfs_quota_us")"
        period="$(cat "${root}/sys/fs/cgroup/cpu/cpu.cfs_period_us")"
    fi
    if [[ "$quota" =~ ^[0-9]+$ ]] && [[ "$period" =~ ^[0-9]+$ ]] && [ "$period" -gt 0 ]; then
        q=$(( quota * 1000 / period ))
        [ "$q" -gt 0 ] && [ "$q" -lt "$milli" ] && milli=$q
    fi
    echo "$milli"
}

# The cgroup limit, in MB, when one actually applies. Empty when unlimited:
# cgroup v2 says "max", cgroup v1 says a number larger than physical memory.
memory_plan_cgroup_limit_mb() {
    local limit
    local root
    root="$(memory_plan_root)"
    if [ -r "${root}/sys/fs/cgroup/memory.max" ]; then
        limit=$(cat "${root}/sys/fs/cgroup/memory.max")
    elif [ -r "${root}/sys/fs/cgroup/memory/memory.limit_in_bytes" ]; then
        limit=$(cat "${root}/sys/fs/cgroup/memory/memory.limit_in_bytes")
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
    install.sh site NAME DOMAIN --memory=512m        (a new site)
    sysadmin_tools/site_limits.sh NAME 512m          (a site already running, on its host;
                                                      or Site limits on the host's node page)
Or state the budget directly with --ram-mb=512.
MSG
        return 3
    fi
    MEMORY_PLAN_TOTAL_MB="$(memory_plan_mem_total_mb)"
    memory_plan_host_reserve
    MEMORY_PLAN_MB=$(( MEMORY_PLAN_TOTAL_MB - MEMORY_PLAN_RESERVE_MB ))
    # A machine smaller than its reserve still gets the plan's floors.
    [ "$MEMORY_PLAN_MB" -lt 256 ] && MEMORY_PLAN_MB=256
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
    local by_cpu=$(( MEMORY_PLAN_CHILDREN_PER_CPU * $(memory_plan_cpu_milli) / 1000 ))
    [ "$n" -gt "$by_cpu" ] && n=$by_cpu
    [ "$n" -gt "$MEMORY_PLAN_MAX_CHILDREN" ] && n=$MEMORY_PLAN_MAX_CHILDREN
    [ "$n" -lt "$MEMORY_PLAN_MIN_CHILDREN" ] && n=$MEMORY_PLAN_MIN_CHILDREN
    echo "$n"
}

memory_plan_max_connections() {
    echo $(( $(memory_plan_php_children "$1") + MEMORY_PLAN_OTHER_CONNECTIONS ))
}
