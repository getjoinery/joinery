#!/usr/bin/env bash
#
# install_security_updates.sh - install, now, the updates this machine's own
# automatic-update policy allows, and say what changed as ONE JSON object on
# stdout.
#
# Version: 1.0 - the install_security_updates operate word (agent 1.87.0). The
#                agent runs this file, verified against the release manifest,
#                with no arguments.
#
# Why it exists: automatic updates run once a day (apt-daily-upgrade.timer,
# about 06:00). A fix for a hole being attacked today would otherwise wait for
# tomorrow morning, and on a machine nobody logs into there was no way to ask
# for it sooner.
#
# THE CONTRACT, which tests/integration/install_security_updates_gate.sh pins:
#
#   - NO arguments; anything passed is refused. What is installed is decided by
#     the machine's own unattended-upgrades policy: Ubuntu's security pocket,
#     plus the platform's outside PHP and PostgreSQL repositories where
#     host_housekeeping.sh named them (51joinery-platform-repos). The same
#     packages the nightly run would take, never a general `apt upgrade` (which
#     would also take, say, a Docker engine update and restart every site).
#   - It does not reboot. A package that asks for one is reported
#     (reboot_required); rebooting is reboot_machine's, a choice a person makes.
#   - It waits up to WAIT_FOR_APT seconds for another package run (the nightly
#     one, an install) to finish, and reports busy if it never does.
#   - Every key is ALWAYS present; the exit code is 0 whenever the object was
#     printed. outcome is one of: installed, nothing_to_install, failed, busy,
#     unavailable.
#   - Only package names, versions and service names are printed, reduced to a
#     safe character set and bounded, so there is nothing to redact.
#
# Runs on: an Ubuntu machine or site container with unattended-upgrades. A
# container has no systemd; its packages' own scripts restart what they
# replace (install.sh removes the image's policy-rc.d), and what a container
# installs lasts until it is recreated on a fresh image.

set -u
export LC_ALL=C
export DEBIAN_FRONTEND=noninteractive

WAIT_FOR_APT=600        # seconds to wait for another package run to finish
UPDATE_TIMEOUT=300      # apt-get update
UPGRADE_TIMEOUT=1800    # unattended-upgrade; on TERM it stops after the package in hand
MAX_LIST=200            # packages / services listed

if (( $# > 0 )); then
    printf 'install_security_updates: takes no arguments\n' >&2
    exit 2
fi

json_safe() {
    local s="${1//[^A-Za-z0-9._@:+~-]/}"
    printf '"%s"' "${s:0:128}"
}

OUTCOME=""
REASON=""
APT_UPDATE="skipped"
UPGRADE_EXIT="null"
REBOOT_REQUIRED=false
UPGRADED_JSON="[]"
UPGRADED_COUNT=0
TRUNCATED=false
SERVICES_JSON='"unknown"'

emit() {
    [[ -e /var/run/reboot-required ]] && REBOOT_REQUIRED=true
    printf '{'
    printf '"outcome":%s,' "$(json_safe "$OUTCOME")"
    printf '"reason":"%s",' "$REASON"
    printf '"apt_update":%s,' "$(json_safe "$APT_UPDATE")"
    printf '"upgrade_exit":%s,' "$UPGRADE_EXIT"
    printf '"upgraded":%s,' "$UPGRADED_JSON"
    printf '"upgraded_count":%d,' "$UPGRADED_COUNT"
    printf '"truncated":%s,' "$TRUNCATED"
    printf '"reboot_required":%s,' "$REBOOT_REQUIRED"
    printf '"services_needing_restart":%s' "$SERVICES_JSON"
    printf '}\n'
    exit 0
}

if [[ "$(id -u)" != 0 ]]; then
    OUTCOME="unavailable"; REASON="not run as root"; emit
fi
if ! command -v apt-get >/dev/null 2>&1 || ! command -v dpkg-query >/dev/null 2>&1; then
    OUTCOME="unavailable"; REASON="this machine has no apt"; emit
fi
if ! command -v unattended-upgrade >/dev/null 2>&1; then
    OUTCOME="unavailable"; REASON="unattended-upgrades is not installed"; emit
fi

# Another package run holds apt's locks while it works; unattended-upgrade
# gives up at once when it cannot take them, so wait for the run to end.
apt_busy() {
    pgrep -x 'apt-get|apt|dpkg|unattended-upgr|aptitude' >/dev/null 2>&1
}
wait_for_apt() {
    local waited=0
    while apt_busy; do
        (( waited >= WAIT_FOR_APT )) && return 1
        sleep 5; waited=$(( waited + 5 ))
    done
    return 0
}

installed() {
    dpkg-query -W -f='${db:Status-Abbrev}\t${Package}:${Architecture}\t${Version}\n' 2>/dev/null \
        | awk -F'\t' '$1 ~ /^ii/ {print $2 "\t" $3}' | sort -t $'\t' -k1,1
}

if ! wait_for_apt; then
    OUTCOME="busy"; REASON="another package run did not finish within ten minutes"; emit
fi

BEFORE="$(installed)"

if timeout "$UPDATE_TIMEOUT" apt-get -q update >/dev/null 2>&1; then
    APT_UPDATE="ok"
else
    # Stale lists still install what was already known; say so and go on.
    APT_UPDATE="failed"
fi

# One retry for a run that started between the wait and this line.
LOCK_MSG="could not be acquired"
for attempt in 1 2; do
    out="$(timeout "$UPGRADE_TIMEOUT" unattended-upgrade 2>&1)"
    rc=$?
    if (( rc != 0 )) && [[ "$out" == *"$LOCK_MSG"* ]] && (( attempt == 1 )) && wait_for_apt; then
        continue
    fi
    break
done
UPGRADE_EXIT="$rc"

AFTER="$(installed)"

# Every package whose version changed or that is new (a security fix can pull
# in a new dependency), as {package, from, to}; from is "" for a new one.
items=()
# join fills a missing old version with "-": tab is a whitespace IFS
# character, so an empty field between two tabs would vanish in read.
while IFS=$'\t' read -r pkg from to; do
    [[ -z "$pkg" ]] && continue
    [[ "$from" == "-" ]] && from=""
    UPGRADED_COUNT=$(( UPGRADED_COUNT + 1 ))
    if (( ${#items[@]} < MAX_LIST )); then
        items+=("{\"package\":$(json_safe "$pkg"),\"from\":$(json_safe "$from"),\"to\":$(json_safe "$to")}")
    else
        TRUNCATED=true
    fi
done < <(join -t $'\t' -a 2 -e '-' -o '0,1.2,2.2' <(printf '%s\n' "$BEFORE") <(printf '%s\n' "$AFTER") \
            | awk -F'\t' '$2 != $3')
if (( ${#items[@]} > 0 )); then
    UPGRADED_JSON="[$(IFS=,; printf '%s' "${items[*]}")]"
fi

# Services still running the code a package replaced. needrestart only lists
# here (-r l); the packages' own scripts restart what they own.
if command -v needrestart >/dev/null 2>&1; then
    svcs=()
    while read -r line; do
        svc="${line#NEEDRESTART-SVC: }"
        (( ${#svcs[@]} < MAX_LIST )) && svcs+=("$(json_safe "$svc")")
    done < <(timeout 120 needrestart -b -r l 2>/dev/null | grep '^NEEDRESTART-SVC: ')
    SERVICES_JSON="[$(IFS=,; printf '%s' "${svcs[*]:-}")]"
fi

if (( rc == 124 )); then
    OUTCOME="failed"; REASON="the update run took longer than thirty minutes and was stopped"
elif (( rc != 0 )) && [[ "$out" == *"$LOCK_MSG"* ]]; then
    OUTCOME="busy"; REASON="another package run held the lock"
elif (( rc != 0 )); then
    OUTCOME="failed"; REASON="unattended-upgrade reported an error; its log is /var/log/unattended-upgrades/unattended-upgrades.log"
elif (( UPGRADED_COUNT > 0 )); then
    OUTCOME="installed"
else
    OUTCOME="nothing_to_install"
fi
emit
