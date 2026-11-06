#!/usr/bin/env bash
#
# joinery-unseal-launch - root's side of running one site's unseal daemon
# (specs/unseal_daemon.md § Installing and supervising, § The sentinel's
# hold). install_unseal_daemon.sh copies it to /var/lib/joinery-unseal/KEY/launch;
# the unit and the keepalive call it there, and the agent's unseal_hold runs the
# tree's own copy, verified against the signed release manifest.
#
# Version: 1.2 - prepare records a crash the previous run left no record of (its stop hook killed with it)
# Version: 1.1 - the site's own builds under its state directory (reviewer2 B1); the fallback counts
#               starts since the last run that stayed up five minutes, so a build that hangs falls
#               back too (B3); the ping runs the active build (B4); the daemon is found by systemd's
#               MainPID, or by its executable under cron (B5), a replaced build included (B11);
#               systemctl kill --kill-who, the spelling systemd 249 knows (B12); stop, and a
#               locked keepalive (B7)
# Version: 1.0
#
#   joinery-unseal-launch stop KEY        an intended stop where systemd is not PID 1 (the installer,
#                                          on a changed build): recorded as a stop, not a crash
#   joinery-unseal-launch prepare KEY     before every start (the unit's ExecStartPre, the keepalive):
#                                          the socket directory, the start count, which binary runs
#   joinery-unseal-launch stopped KEY     after every stop (the unit's ExecStopPost): the exit, recorded
#   joinery-unseal-launch keepalive KEY   the cron keepalive where systemd is not PID 1 (a container):
#                                          start it, restart it when it died or stopped answering
#   joinery-unseal-launch hold KEY|--all  drop every window and hold: the hold file, then SIGUSR1
#   joinery-unseal-launch lift KEY|--all  lift the hold: the file removed, then SIGUSR2
#   joinery-unseal-launch status KEY      one line of JSON: installed, running, held, binary, crashes
#
# KEY is the site's key (the basename of its root, made safe for a path); the
# install record /etc/joinery-unseal/KEY/installed says everything else.
#
# What it keeps, per site:
#   /run/joinery-unseal/KEY/          the daemon's socket and lock; joinery-unseal:www-data 0750
#   /run/joinery-unseal/KEY.hold      the hold; in a root-owned directory neither the daemon nor
#                                     the pool can write, read once by the daemon at startup
#   /var/lib/joinery-unseal/KEY/      root-owned, world-readable: the site's builds (joinery-unseal,
#                                     joinery-unseal.previous) and this launcher (launch), binary
#                                     (current or previous), active (the symlink the unit starts),
#                                     starts, events
#
# The previous build: the installer keeps the build it replaced beside the
# current one. A fourth start with no run since that stayed up five minutes
# (three failed runs: crashes, or hangs the watchdog or the start timeout
# ended) moves the site to it until the next install of a changed binary
# moves it back. events is one JSON object per
# line, the newest 200, for the agent's node status: every start, every
# stop with how it ended, every move to the previous build, every hold and
# lift.

set -u
PATH=/usr/sbin:/usr/bin:/sbin:/bin

DAEMON_USER="joinery-unseal"
CONF_ROOT="/etc/joinery-unseal"
RUN_ROOT="/run/joinery-unseal"
STATE_ROOT="/var/lib/joinery-unseal"
CRASH_STARTS=4
HEALTHY_SECONDS=300
EVENTS_KEPT=200

die() { echo "joinery-unseal-launch: $*" >&2; exit 1; }

# The test hook: an unprivileged run with JOINERY_UNSEAL_TEST_ROOT keeps
# everything under that directory and owns what it makes itself
# (tests/vault/unseal_install_test.php). Root ignores it.
TEST_ROOT=""
if [[ "$(id -u)" != "0" ]]; then
    [[ -n "${JOINERY_UNSEAL_TEST_ROOT:-}" && -d "${JOINERY_UNSEAL_TEST_ROOT}" ]] || die "must run as root"
    TEST_ROOT="${JOINERY_UNSEAL_TEST_ROOT%/}"
    CONF_ROOT="${TEST_ROOT}${CONF_ROOT}" RUN_ROOT="${TEST_ROOT}${RUN_ROOT}" STATE_ROOT="${TEST_ROOT}${STATE_ROOT}"
fi

# mkdir_as OWNER GROUP MODE DIR: a directory with exactly that ownership (the
# test hook makes it its own).
mkdir_as() {
    if [[ -n "${TEST_ROOT}" ]]; then
        mkdir -p "$4" && chmod "$3" "$4"
    else
        install -d -o "$1" -g "$2" -m "$3" "$4" && chown "$1:$2" "$4" && chmod "$3" "$4"
    fi
}

valid_key() { [[ "$1" =~ ^[A-Za-z0-9._-]{1,64}$ && "$1" != "." && "$1" != ".." ]]; }

# load KEY: the install record's fields into SOCKET, ALLOW_UID, POOL_USER, INIT.
load() {
    KEY="$1"
    valid_key "${KEY}" || die "not a site key: ${KEY}"
    CONF="${CONF_ROOT}/${KEY}/installed"
    [[ -f "${CONF}" ]] || die "no install record at ${CONF}"
    SOCKET="" ALLOW_UID="" POOL_USER="" INIT=""
    local k v
    while IFS='=' read -r k v; do
        case "${k}" in
            socket)    SOCKET="${v}" ;;
            allow_uid) ALLOW_UID="${v}" ;;
            pool_user) POOL_USER="${v}" ;;
            init)      INIT="${v}" ;;
        esac
    done < "${CONF}"
    [[ "${SOCKET}" == "${RUN_ROOT#"${TEST_ROOT}"}/${KEY}/unseal.sock" && "${ALLOW_UID}" =~ ^[0-9]+$ && -n "${POOL_USER}" ]] \
        || die "the install record ${CONF} is malformed"
    RUN_DIR="${RUN_ROOT}/${KEY}"
    HOLD="${RUN_ROOT}/${KEY}.hold"
    STATE_DIR="${STATE_ROOT}/${KEY}"
    CURRENT="${STATE_DIR}/joinery-unseal"
    PREVIOUS="${STATE_DIR}/joinery-unseal.previous"
    SERVICE="joinery-unseal-${KEY}"
    LOG_FILE="/var/log/joinery-unseal-${KEY}.log"
}

# The daemon's arguments: the same for the unit and the keepalive.
daemon_args() {
    printf '%s\n' --socket "${SOCKET}" --allow-uid "${ALLOW_UID}" --hold-file "${HOLD}" --socket-mode 0666
}

event() {
    local line
    line="$(printf '{"time":%s,"event":"%s"%s}' "$(date +%s)" "$1" "${2:-}")"
    mkdir -p "${STATE_DIR}"
    { tail -n $((EVENTS_KEPT - 1)) "${STATE_DIR}/events" 2>/dev/null; echo "${line}"; } > "${STATE_DIR}/events.tmp"
    chmod 644 "${STATE_DIR}/events.tmp"
    mv -f "${STATE_DIR}/events.tmp" "${STATE_DIR}/events"
}

# The daemon's pid. Under systemd, the unit's main process: its cgroup says so,
# and nothing another process names in its arguments can stand in for it.
# Otherwise a process of the daemon's user whose arguments name this socket and
# whose executable is one of this site's two builds.
daemon_pid() {
    local pid exe
    if [[ "${INIT}" == "systemd" && -z "${TEST_ROOT}" ]]; then
        pid="$(systemctl show -p MainPID --value "${SERVICE}" 2>/dev/null)"
        [[ "${pid}" =~ ^[1-9][0-9]*$ ]] && echo "${pid}"
        return 0
    fi
    for pid in $(pgrep -u "${DAEMON_USER}" -f -- "--socket ${SOCKET}( |$)" 2>/dev/null); do
        exe="$(readlink "/proc/${pid}/exe" 2>/dev/null)"
        # A daemon whose build an install replaced still runs the old bytes.
        exe="${exe% (deleted)}"
        if [[ "${exe}" == "${CURRENT}" || "${exe}" == "${PREVIOUS}" ]]; then
            echo "${pid}"
            return 0
        fi
    done
}

# signal_daemon SIG: the daemon gets it; a start racing the signal gets it too.
signal_daemon() {
    local pid again
    if [[ "${INIT}" == "systemd" && -z "${TEST_ROOT}" ]]; then
        systemctl kill -s "$1" --kill-who=main "${SERVICE}" 2>/dev/null
        return 0
    fi
    pid="$(daemon_pid)"
    [[ -n "${pid}" ]] && kill "-$1" "${pid}" 2>/dev/null
    sleep 1
    again="$(daemon_pid)"
    [[ -n "${again}" && "${again}" != "${pid}" ]] && kill "-$1" "${again}" 2>/dev/null
    return 0
}

# A run that stayed up HEALTHY_SECONDS clears the count of starts.
clear_starts_if_healthy() {
    local last
    last="$(tail -n 1 "${STATE_DIR}/starts" 2>/dev/null)"
    if [[ "${last}" =~ ^[0-9]+$ ]] && (( $(date +%s) - last >= HEALTHY_SECONDS )); then
        : > "${STATE_DIR}/starts"
    fi
}

prepare() {
    mkdir_as root root 0755 "${RUN_ROOT}"
    mkdir_as root root 0755 "${STATE_ROOT}"
    mkdir_as root root 0755 "${STATE_DIR}"
    # The socket's directory: the daemon writes it, the pool's group enters it,
    # nobody else does. No setgid bit (a container clears it).
    mkdir_as "${DAEMON_USER}" "${POOL_USER}" 0750 "${RUN_DIR}"

    local now count binary target
    now="$(date +%s)"
    # A run that ended with no stop or crash recorded: the stop hook was
    # killed with it (systemctl kill reaches the whole unit), the machine lost
    # power, or the hook failed. Recorded now, so the crash report the agent
    # reads never misses a death.
    if [[ "$(grep -E '"event":"(start|stop|crash)"' "${STATE_DIR}/events" 2>/dev/null | tail -n 1)" == *'"event":"start"'* ]]; then
        event "crash" ",\"result\":\"unrecorded\""
    fi
    # Every start since the last run that stayed up: a crash, a hang the
    # watchdog or the start timeout ended, or an exit, each leaves one here.
    { tail -n 20 "${STATE_DIR}/starts" 2>/dev/null; echo "${now}"; } > "${STATE_DIR}/starts.tmp"
    mv -f "${STATE_DIR}/starts.tmp" "${STATE_DIR}/starts"
    count="$(wc -l < "${STATE_DIR}/starts")"
    binary="$(cat "${STATE_DIR}/binary" 2>/dev/null || echo current)"
    [[ "${binary}" == "previous" ]] || binary="current"
    if [[ "${binary}" == "current" && "${count}" -ge "${CRASH_STARTS}" && -x "${PREVIOUS}" ]]; then
        binary="previous"
        event "moved_to_previous" ",\"failed_starts\":${count}"
        echo "joinery-unseal-launch: ${KEY}: ${count} starts with no run lasting ${HEALTHY_SECONDS} s - running the previous build" >&2
    fi
    target="${CURRENT}"
    [[ "${binary}" == "previous" ]] && target="${PREVIOUS}"
    ln -sfn "${target}" "${STATE_DIR}/active.tmp"
    mv -fT "${STATE_DIR}/active.tmp" "${STATE_DIR}/active"
    echo "${binary}" > "${STATE_DIR}/binary"
    chmod 644 "${STATE_DIR}/binary" "${STATE_DIR}/starts"
    event "start" ",\"binary\":\"${binary}\",\"held\":$([[ -e "${HOLD}" ]] && echo true || echo false)"
}

stopped() {
    # systemd hands ExecStopPost how the main process ended.
    local result="${SERVICE_RESULT:-unknown}" code="${EXIT_CODE:-}" status="${EXIT_STATUS:-}"
    local kind="crash"
    if [[ "${result}" == "success" ]]; then
        kind="stop"
    fi
    clear_starts_if_healthy
    event "${kind}" ",\"result\":\"${result//[^a-z-]/}\",\"code\":\"${code//[^a-z]/}\",\"status\":\"${status//[^A-Za-z0-9]/}\""
}

start_detached() {
    local -a args
    mapfile -t args < <(daemon_args)
    # Every descriptor above stdio is closed before runuser starts, as
    # install_sandbox_host.sh's keepalive does: runuser stays alive as the
    # daemon's parent, and whatever it inherited it would hold. The memory-lock
    # limit is raised as the last hop; the daemon refuses to start under a
    # bounded one.
    nohup bash -c 'for f in /proc/$$/fd/*; do n=${f##*/}; case "$n" in 0|1|2) continue;; esac; eval "exec $n>&-" 2>/dev/null || true; done; umask 0007; exec prlimit --memlock=unlimited:unlimited --core=0:0 runuser -u "$0" -- "$@"' \
        "${DAEMON_USER}" "${STATE_DIR}/active" "${args[@]}" >> "${LOG_FILE}" 2>&1 < /dev/null &
}

# The build the site runs asks: a site on its previous build because the
# current one is bad is never judged by the bad one.
answers() {
    timeout 5 runuser -u "${POOL_USER}" -- "$(readlink -f "${STATE_DIR}/active")" ping --socket "${SOCKET}" --timeout 3 >/dev/null 2>&1
}

# An intended stop where nothing else supervises: recorded as one.
stop_daemon() {
    local pid
    rm -f "${STATE_DIR}/running"
    pid="$(daemon_pid)"
    [[ -n "${pid}" ]] || return 0
    kill -TERM "${pid}" 2>/dev/null
    for _ in 1 2 3 4 5 6 7 8 9 10; do
        [[ -d "/proc/${pid}" ]] || break
        sleep 0.5
    done
    clear_starts_if_healthy
    event "stop" ",\"result\":\"intended\""
}

keepalive() {
    local pid attempt
    # One keepalive at a time: cron's and the installer's would count two starts.
    exec 9>"${STATE_DIR}/keepalive.lock"
    flock -w 60 9 || return 0
    pid="$(daemon_pid)"
    if [[ -n "${pid}" ]]; then
        if answers; then
            touch "${STATE_DIR}/running"
            clear_starts_if_healthy
            return 0
        fi
        # Running but not answering: a hang. The watchdog's job under systemd.
        kill -KILL "${pid}" 2>/dev/null || true
        event "crash" ",\"result\":\"watchdog\""
        sleep 1
    elif [[ -f "${STATE_DIR}/running" ]]; then
        event "crash" ",\"result\":\"found-dead\""
    fi
    # Up to four starts five seconds apart, so a build that will not stay up
    # meets the fallback rule inside one run, as under systemd. One that
    # started and does not answer is ended before the next start.
    for attempt in 1 2 3 4; do
        pid="$(daemon_pid)"
        if [[ -n "${pid}" ]]; then
            kill -KILL "${pid}" 2>/dev/null || true
            sleep 1
        fi
        prepare
        start_detached
        sleep 5
        if [[ -n "$(daemon_pid)" ]] && answers; then
            touch "${STATE_DIR}/running"
            return 0
        fi
        [[ "${attempt}" == "4" ]] || event "crash" ",\"result\":\"exited\""
    done
    rm -f "${STATE_DIR}/running"
    echo "joinery-unseal-launch: ${KEY}: the daemon does not stay up; see ${LOG_FILE}" >&2
    return 1
}

each_key() {
    local fn="$1" target="$2" d
    if [[ "${target}" == "--all" ]]; then
        for d in "${CONF_ROOT}"/*/installed; do
            [[ -f "${d}" ]] || continue
            ( load "$(basename "$(dirname "${d}")")" && "${fn}" )
        done
    else
        load "${target}" && "${fn}"
    fi
}

hold_one() {
    # The file first: a daemon that starts after this line starts held.
    if [[ -n "${TEST_ROOT}" ]]; then : > "${HOLD}"; else install -o root -g root -m 0644 /dev/null "${HOLD}"; fi
    local pid
    pid="$(daemon_pid)"
    signal_daemon USR1
    event "held" ",\"running\":$([[ -n "${pid}" ]] && echo true || echo false)"
    echo "${KEY}: held$([[ -n "${pid}" ]] && echo ", every window dropped")"
}

lift_one() {
    rm -f "${HOLD}"
    signal_daemon USR2
    event "lifted"
    echo "${KEY}: hold lifted"
}

status_one() {
    local pid binary crashes
    pid="$(daemon_pid)"
    binary="$(cat "${STATE_DIR}/binary" 2>/dev/null || echo current)"
    crashes="$(awk -v since=$(( $(date +%s) - 3600 )) -F'[:,]' '/"event":"crash"/ && $2 >= since' "${STATE_DIR}/events" 2>/dev/null | wc -l)"
    printf '{"key":"%s","installed":true,"init":"%s","running":%s,"held":%s,"binary":"%s","crashes_last_hour":%s}\n' \
        "${KEY}" "${INIT}" "$([[ -n "${pid}" ]] && echo true || echo false)" \
        "$([[ -e "${HOLD}" ]] && echo true || echo false)" "${binary}" "${crashes}"
}

case "${1:-}" in
    prepare)   [[ $# -eq 2 ]] || die "usage: prepare KEY";   load "$2"; prepare ;;
    stop)      [[ $# -eq 2 ]] || die "usage: stop KEY";      load "$2"; stop_daemon ;;
    stopped)   [[ $# -eq 2 ]] || die "usage: stopped KEY";   load "$2"; stopped ;;
    keepalive) [[ $# -eq 2 ]] || die "usage: keepalive KEY"; load "$2"; keepalive ;;
    hold)      [[ $# -eq 2 ]] || die "usage: hold KEY|--all"; each_key hold_one "$2" ;;
    lift)      [[ $# -eq 2 ]] || die "usage: lift KEY|--all"; each_key lift_one "$2" ;;
    status)    [[ $# -eq 2 ]] || die "usage: status KEY";    load "$2"; status_one ;;
    *) die "usage: joinery-unseal-launch prepare|stopped|stop|keepalive|hold|lift|status KEY" ;;
esac
