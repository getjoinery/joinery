#!/bin/bash
# @joinery-test
# name: unseal_daemon_installed
# tier: safe
# env: any
# needs: [unseal-daemon]
# timeout: 60
#
# The unseal daemon as install_unseal_daemon.sh leaves it, from the outside
# (specs/unseal_daemon.md WP3). Runs only where it is installed (needs:
# unseal-daemon). Each check is one promise the installation makes:
#  - the binary and its launcher are root's, and the binary is the build this
#    tree ships (or the previous build, after three crashes);
#  - the socket's directory lets in the pool's group and nobody else, and the
#    hold sits where neither the pool nor the daemon can write;
#  - the process runs as its own user, with locked memory, not dumpable, no
#    core, and (under systemd) no network, a watchdog, a restart and a low
#    OOM score;
#  - the kernel refuses ptrace between unrelated processes;
#  - the socket answers the pool user and refuses anyone else.
# tests/vault/unseal_daemon_test.php drives the protocol itself.

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
KEY="$(printf '%s' "$(basename "${ROOT}")" | tr -c 'A-Za-z0-9._-' '_' | cut -c1-64)"
CONF="/etc/joinery-unseal/${KEY}/installed"
STATE="/var/lib/joinery-unseal/${KEY}"
BIN="${STATE}/joinery-unseal"
LAUNCH="${STATE}/launch"
passed=0; failed=0

chk() {
    if [ "$2" = "$3" ]; then
        echo "  PASS: $1"; passed=$((passed+1))
    else
        echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed+1))
    fi
}
field() { sed -n "s/^$1=//p" "${CONF}" | head -n 1; }

SOCKET="$(field socket)"
ALLOW_UID="$(field allow_uid)"
INIT="$(field init)"
RUN_DIR="$(dirname "${SOCKET}")"
SERVICE="joinery-unseal-${KEY}"

echo "== the binary and the launcher =="
chk "the site's state directory is root's, 755" "$(stat -c '%U:%G %a' "${STATE}" 2>/dev/null)" "root:root 755"
chk "the daemon is root's, 755" "$(stat -c '%U:%G %a' "${BIN}" 2>/dev/null)" "root:root 755"
chk "the launcher is root's, 755" "$(stat -c '%U:%G %a' "${LAUNCH}" 2>/dev/null)" "root:root 755"
SHIPPED="${ROOT}/maintenance_scripts/install_tools/joinery_unseal/bin/joinery-unseal-$(uname -m)"
BINARY_STATE="$(cat "${STATE}/binary" 2>/dev/null || echo current)"
chk "the site's build is the one this tree ships" "$(cmp -s "${SHIPPED}" "${BIN}" && echo same)" "same"
chk "the site runs the current build (not the previous one after a crash loop)" "${BINARY_STATE}" "current"

echo "== the socket's directory and the hold =="
chk "the socket's directory is the daemon's, entered by the pool's group only" \
    "$(stat -c '%U:%G %a' "${RUN_DIR}" 2>/dev/null)" "joinery-unseal:www-data 750"
chk "the hold's directory is root's, 755: neither the pool nor the daemon can write it" \
    "$(stat -c '%U:%G %a' /run/joinery-unseal 2>/dev/null)" "root:root 755"
if [ -e "/run/joinery-unseal/${KEY}.hold" ]; then
    chk "a hold present is root's" "$(stat -c %U "/run/joinery-unseal/${KEY}.hold")" "root"
fi

echo "== the process =="
PID="$(pgrep -u joinery-unseal -f -- "--socket ${SOCKET}( |$)" | head -n 1)"
chk "the daemon is running as joinery-unseal" "$([ -n "${PID}" ] && echo yes)" "yes"
if [ -n "${PID}" ]; then
    VMLCK="$(awk '/^VmLck:/ {print $2}' "/proc/${PID}/status" 2>/dev/null)"
    chk "its memory is locked" "$([ "${VMLCK:-0}" -gt 0 ] 2>/dev/null && echo yes)" "yes"
    chk "it is not dumpable (its /proc entries belong to root)" "$(stat -c %U "/proc/${PID}/status" 2>/dev/null)" "root"
    CORE="$(awk '/^Max core file size/ {print $5}' "/proc/${PID}/limits" 2>/dev/null)"
    [ -n "${CORE}" ] && chk "its core limit is zero" "${CORE}" "0"
fi
if [ "${INIT}" = "systemd" ]; then
    chk "the unit's main process is the daemon" "$(systemctl show -p MainPID --value "${SERVICE}")" "${PID:-none}"
    chk "a start that hangs is ended in 15 s" "$(systemctl show -p TimeoutStartUSec --value "${SERVICE}")" "15s"
    chk "systemctl kill knows --kill-who, so the hold's signal reaches the daemon" \
        "$(systemctl kill --help 2>/dev/null | grep -q -- '--kill-who' && echo yes)" "yes"
    chk "the unit has no network" "$(systemctl show -p PrivateNetwork --value "${SERVICE}")" "yes"
    chk "the unit restarts it" "$(systemctl show -p Restart --value "${SERVICE}")" "always"
    chk "the unit's watchdog is 30 s" "$(systemctl show -p WatchdogUSec --value "${SERVICE}")" "30s"
    chk "the unit's OOM score is -900" "$(systemctl show -p OOMScoreAdjust --value "${SERVICE}")" "-900"
    chk "the unit lets it lock all its memory" "$(systemctl show -p LimitMEMLOCK --value "${SERVICE}")" "infinity"
else
    chk "the keepalive runs every minute" "$(grep -c 'keepalive' "/etc/cron.d/joinery-unseal-${KEY}" 2>/dev/null)" "2"
fi
SCOPE="$(cat /proc/sys/kernel/yama/ptrace_scope 2>/dev/null || echo 0)"
chk "the kernel refuses ptrace between unrelated processes (ptrace_scope >= 1)" "$([ "${SCOPE}" -ge 1 ] && echo yes)" "yes"

echo "== the socket =="
if [ "$(id -u)" = "${ALLOW_UID}" ]; then
    chk "the pool user gets an answer" "$("${BIN}" ping --socket "${SOCKET}" --timeout 3 >/dev/null 2>&1 && echo yes)" "yes"
else
    # Anyone but the pool user: refused at the directory, or by the peer check.
    chk "another user gets no answer" "$("${BIN}" ping --socket "${SOCKET}" --timeout 3 >/dev/null 2>&1 && echo answered || echo refused)" "refused"
fi

echo "================================"
echo "unseal_daemon_installed [safe]"
echo "PASSED: ${passed}   FAILED: ${failed}   ($((passed+failed)) checks)"
[ "${failed}" -eq 0 ]
