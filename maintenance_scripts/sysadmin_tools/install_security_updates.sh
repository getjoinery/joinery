#!/usr/bin/env bash
#
# install_security_updates.sh - install, now, the updates this machine's own
# automatic-update policy allows, and say what changed as ONE JSON object on
# stdout.
#
# Version: 1.3 - After an install that changed something, the downloaded packages are cleared with
#                apt-get clean, which waits for no lock and takes apt's own: a site container's image
#                no longer deletes them after every apt run (Dockerfile.template 6.4), so they would
#                otherwise stay in the container until it is made again.
# Version: 1.2 - After the updater, every php-fpm master still running a binary an update replaced
#                (/proc/PID/exe "(deleted)") is reloaded with USR2, which re-executes it with no
#                request dropped: a site container has no systemd, so nothing restarted its php-fpm on
#                2026-10-21 and all six kept serving the unpatched PHP. reloaded and still_on_old_code
#                say what was done and what is left. A package run left half done (dpkg --audit) is
#                finished first with apt-get -f install, which names no package (repaired): two of
#                those six were left so (fleet_security_patching.md WP3).
# Version: 1.1 - unattended-upgrade is told apart from unattended-upgrade-shutdown
#                by its command line: both have the process name unattended-upgr,
#                and the shutdown helper runs for as long as the machine is up,
#                so every run waited its full WAIT_FOR_APT and reported busy
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
#   - A package run left half done is finished first with apt-get -f install,
#     which names no package (repaired: true).
#   - Afterwards, every php-fpm master still running a binary an update
#     replaced is reloaded (USR2: it re-executes, no request dropped);
#     reloaded and still_on_old_code name the binaries.
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
REPAIR_TIMEOUT=900      # finishing a half-done package run
RELOAD_WAIT=20          # seconds for a reloaded php-fpm to re-execute
PROC="${INSTALL_SECURITY_UPDATES_PROC:-/proc}"   # tests only: a stand-in /proc

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
REPAIRED=false
RELOADED_JSON="[]"
OLD_CODE_JSON="[]"

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
    printf '"services_needing_restart":%s,' "$SERVICES_JSON"
    printf '"repaired":%s,' "$REPAIRED"
    printf '"reloaded":%s,' "$RELOADED_JSON"
    printf '"still_on_old_code":%s' "$OLD_CODE_JSON"
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
# unattended-upgrade is found by its command line: its process name, cut to
# 15 characters, is unattended-upgr, the same as unattended-upgrade-shutdown,
# the helper Ubuntu keeps waiting for shutdown the whole time the machine is up.
apt_busy() {
    pgrep -x 'apt-get|apt|dpkg|aptitude' >/dev/null 2>&1 \
        || pgrep -f 'unattended-upgrade( |$)' >/dev/null 2>&1
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

# A package run left half done (a package unpacked and not configured, or
# one whose download went missing mid-run): the updater refuses to work on a
# broken package state, so it is finished first. -f names no package.
if [[ -n "$(dpkg --audit 2>/dev/null)" ]]; then
    if timeout "$REPAIR_TIMEOUT" apt-get -q -y -f -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold install >/dev/null 2>&1 \
            && [[ -z "$(dpkg --audit 2>/dev/null)" ]]; then
        REPAIRED=true
    fi
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

# Every php-fpm master still running a binary an update replaced. Where
# systemd runs php-fpm the package's own scripts restarted it; in a site
# container nothing does. USR2 makes the master re-execute its binary (the new
# one) and replace its workers as they finish: no request is dropped.
fpm_old_masters() {  # one "PID BINARY" a line
    local p cmd exe
    for p in "$PROC"/[0-9]*; do
        [[ -r "$p/cmdline" ]] || continue
        cmd="$(tr '\0' ' ' 2>/dev/null < "$p/cmdline")"
        [[ "$cmd" == "php-fpm: master process"* ]] || continue
        exe="$(readlink "$p/exe" 2>/dev/null)" || continue
        [[ "$exe" == *" (deleted)" ]] || continue
        exe="${exe% (deleted)}"
        printf '%s %s\n' "${p##*/}" "${exe##*/}"
    done
}
json_list() {  # NAMES...: a JSON list of safe strings
    local out="" sep="" n
    for n in "$@"; do out+="${sep}$(json_safe "$n")"; sep=","; done
    printf '[%s]' "$out"
}
reloaded=()
while read -r pid bin; do
    [[ -n "$pid" ]] || continue
    kill -USR2 "$pid" 2>/dev/null && reloaded+=("$bin")
done < <(fpm_old_masters)
if (( ${#reloaded[@]} > 0 )); then
    waited=0
    while [[ -n "$(fpm_old_masters)" ]] && (( waited < RELOAD_WAIT )); do sleep 1; waited=$(( waited + 1 )); done
fi
mapfile -t left < <(fpm_old_masters | cut -d' ' -f2-)
RELOADED_JSON="$(json_list "${reloaded[@]}")"
OLD_CODE_JSON="$(json_list "${left[@]}")"

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
# The downloads are spent. apt-get clean takes apt's archive lock, so it never
# clears another run's; a run holding it is left to finish, and this is skipped.
if (( UPGRADED_COUNT > 0 )); then
    timeout 60 apt-get -q -o DPkg::Lock::Timeout=0 clean >/dev/null 2>&1 || true
fi
emit
