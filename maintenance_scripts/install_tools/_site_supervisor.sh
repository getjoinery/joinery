#!/usr/bin/env bash
#
# _site_supervisor.sh - keep a site container's four main processes running.
#
# Version: 1.0 (specs/multi_tenant_docker_hosts.md WP2 item 2)
#
# When a container runs out of memory, the kernel kills its biggest process. A
# killed worker is replaced by its parent; a killed PostgreSQL postmaster,
# PHP-FPM master, Apache parent or cron daemon is replaced by nothing. A dead
# cron is the worst of them: the site still answers, so nothing notices that
# its scheduled tasks and the agent's supervisor have stopped.
#
#   _site_supervisor.sh SITENAME   The container's main process, exec'd as the
#                                  last step of its start command. Starts
#                                  Apache, then every 5 seconds checks that
#                                  PostgreSQL, PHP-FPM, Apache and cron run.
#                                  One that is gone at three checks in a row
#                                  (10-15 seconds, so a deliberate restart is
#                                  never raced) has whatever it left behind
#                                  killed (a postmaster's backends hold its
#                                  shared memory, a dead FPM master's workers
#                                  hold memory and serve nothing, a dead
#                                  Apache parent's children hold port 80) and
#                                  is started again, in dependency order, one
#                                  per check. Each restart is logged to the
#                                  container's output and the site's
#                                  error log (logs/error.log). On docker stop it
#                                  stops all four cleanly: PostgreSQL gets a
#                                  fast shutdown instead of a kill.
#   _site_supervisor.sh --check    The container's health check: exit 0 when
#                                  all four run, 1 naming the first that does
#                                  not. Before the supervisor is the main
#                                  process (the start command is still
#                                  migrating or installing) and while a hold
#                                  is in place, it answers 0.
#
# A script that stops a service on purpose takes the hold first:
#
#   mkdir -p /run/joinery && echo "who and why" > /run/joinery/supervisor.hold
#
# While it exists nothing is restarted. A container start clears it, so a hold
# never outlives the run that took it.
#
# A process counts as running when its pid file names a live process of the
# right name. Only root reads every pid file; the test hooks below are ignored
# for root.

SITE="${1:-}"
CHECK_EVERY=5
MISSES_TO_RESTART=3
RUN_ROOT=""
if [ "$(id -u)" -ne 0 ]; then
    RUN_ROOT="${SUPERVISOR_TEST_ROOT:-}"
    CHECK_EVERY="${SUPERVISOR_TEST_INTERVAL:-$CHECK_EVERY}"
fi
HOLD="${RUN_ROOT}/run/joinery/supervisor.hold"

newest() { ls -1 "$@" 2>/dev/null | sort -V | tail -1; }

PG_VERSION="$(basename "$(newest -d "${RUN_ROOT}"/etc/postgresql/*/)" 2>/dev/null)"
FPM_SERVICE="$(basename "$(newest "${RUN_ROOT}"/etc/init.d/php*-fpm)" 2>/dev/null)"

SERVICES="postgresql php-fpm apache2 cron"

pidfile_of() {
    case "$1" in
        postgresql) echo "${RUN_ROOT}/var/run/postgresql/${PG_VERSION}-main.pid" ;;
        php-fpm)    echo "${RUN_ROOT}/run/php/${FPM_SERVICE}.pid" ;;
        apache2)    echo "${RUN_ROOT}/var/run/apache2/apache2.pid" ;;
        cron)       echo "${RUN_ROOT}/var/run/crond.pid" ;;
    esac
}

# The name the kernel gives the service's processes (comm).
comm_of() {
    case "$1" in
        postgresql) echo postgres ;;
        php-fpm)    echo "${FPM_SERVICE%-fpm}" | sed 's/^php/php-fpm/' ;;
        apache2)    echo apache2 ;;
        cron)       echo cron ;;
    esac
}

is_running() {
    local pid
    pid="$(head -1 "$(pidfile_of "$1")" 2>/dev/null | tr -cd '0-9')"
    [ -n "$pid" ] && [ "$(cat "/proc/${pid}/comm" 2>/dev/null)" = "$(comm_of "$1")" ]
}

held() { [ -e "$HOLD" ]; }

say() {
    local line
    line="$(date -u +%Y-%m-%dT%H:%M:%SZ) supervisor: $*"
    echo "$line"
    local log="/var/www/html/${SITE}/logs/error.log"
    if [ -n "$SITE" ] && [ -z "$RUN_ROOT" ] && [ -d "/var/www/html/${SITE}/logs" ]; then
        echo "$line" >> "$log"
    fi
}

start_service() {
    case "$1" in
        postgresql) service postgresql start ;;
        php-fpm)    service "$FPM_SERVICE" start ;;
        apache2)    apache2ctl start ;;
        cron)       service cron start ;;
    esac
}

stop_service() {
    case "$1" in
        postgresql) service postgresql stop ;;
        php-fpm)    service "$FPM_SERVICE" stop ;;
        apache2)    apache2ctl stop ;;
        cron)       service cron stop ;;
    esac
}

# What a dead master leaves behind. Cron's leftovers are the jobs it started,
# which finish on their own, so nothing of cron's is killed.
kill_leftovers() {
    local name n=0
    [ "$1" = cron ] && return 0
    name="$(comm_of "$1")"
    pkill -KILL -x "$name" 2>/dev/null || return 0
    while pgrep -x "$name" > /dev/null 2>&1 && [ "$n" -lt 20 ]; do
        sleep 0.5; n=$((n + 1))
    done
    say "killed what the dead ${1} left running"
}

if [ "${1:-}" = "--check" ]; then
    # Until the supervisor is PID 1, the start command is still running: the
    # host report's own probe of the site covers a start that never finishes.
    if [ -z "$RUN_ROOT" ] && ! tr '\0' ' ' < /proc/1/cmdline 2>/dev/null | grep -q '_site_supervisor\.sh'; then
        echo "starting"; exit 0
    fi
    if held; then echo "held: $(head -c 200 "$HOLD")"; exit 0; fi
    for s in $SERVICES; do
        if ! is_running "$s"; then echo "${s} is not running"; exit 1; fi
    done
    echo "postgresql, php-fpm, apache2 and cron are running"
    exit 0
fi

# The watch is one function, called from the last line: bash has then read the
# whole file before it runs any of it, so an upgrade that replaces this file
# under the running process can never be read half old, half new.
supervise() {
    if [ -z "$SITE" ]; then
        echo "Usage: $0 SITENAME | --check" >&2
        exit 2
    fi
    if [ -z "$PG_VERSION" ] || [ -z "$FPM_SERVICE" ]; then
        echo "FATAL: no PostgreSQL under /etc/postgresql or no php-fpm under /etc/init.d" >&2
        exit 1
    fi

    rm -f "$HOLD"

    stopping=0
    shutdown() {
        stopping=1
        say "stopping: apache2, php-fpm, cron, postgresql"
        for s in apache2 php-fpm cron postgresql; do
            stop_service "$s" > /dev/null 2>&1 || true
        done
        exit 0
    }
    trap shutdown TERM INT

    if ! is_running apache2; then
        start_service apache2 || { echo "FATAL: Apache did not start" >&2; exit 1; }
    fi
    say "running; watching postgresql, php-fpm, apache2 and cron"

    declare -A misses=()
    while [ "$stopping" -eq 0 ]; do
        # sleep in the background and wait for it, so docker stop's TERM is
        # handled at once rather than after the sleep.
        sleep "$CHECK_EVERY" & wait $! 2>/dev/null
        if held; then
            misses=(); continue
        fi
        for s in $SERVICES; do
            if is_running "$s"; then
                misses[$s]=0; continue
            fi
            misses[$s]=$(( ${misses[$s]:-0} + 1 ))
            [ "${misses[$s]}" -ge "$MISSES_TO_RESTART" ] || break
            kill_leftovers "$s"
            if start_service "$s" > /dev/null 2>&1 && is_running "$s"; then
                say "${s} was not running; started it again"
            else
                say "${s} was not running and did not start; trying again at the next checks"
            fi
            misses[$s]=0
            break
        done
    done
}

supervise; exit $?
