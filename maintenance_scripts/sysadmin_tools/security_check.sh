#!/usr/bin/env bash
#
# security_check.sh - which security fixes this machine is waiting for, looked
# for every hour (specs/fleet_security_patching.md WP1, Q3).
#
# Version: 1.1 - a package new to the machine is not a fix waiting: an upgrade
#   pulls it in, and that upgrade is counted where it is a fix (docker-prod's
#   libdrm-amdgpu1, needed only by an fwupd from -updates, waited forever)
#
# Why it exists: on 2026-10-21 Ubuntu published a fix for a critical hole in
# PHP at 13:41 UTC, and nothing on any machine said it was waiting for one.
# Automatic updates take the fix the next morning; a machine whose package
# lists are refreshed twice a day does not even know of it until then.
#
#   security_check.sh run       refresh the package lists (when over 45 minutes old, never
#                               waiting on another package run) and record what a security run
#                               would install now; on a container host, also ask the site
#                               containers' base (site_refresh.sh check)
#   security_check.sh status    print the state
#   security_check.sh install   write and enable the hourly timer (root, this tree root's own)
#
# A security fix is what this machine's own automatic-update policy takes: a
# package from a -security pocket (Ubuntu's, or Ubuntu Pro's), or from an
# outside repository host_housekeeping.sh named in 51joinery-platform-repos
# (the PHP and PostgreSQL ones). Never a package from -updates alone: the
# nightly run does not take those either.
#
# Each package waiting is recorded with its installed and candidate versions,
# when it was first seen waiting, and marked critical when it is something a
# site faces the internet with or the machine boots: PHP, Apache, OpenSSL,
# PostgreSQL, the kernel.
#
# A critical fix is installed at once (Q2), never waiting for the night:
# install_security_updates.sh runs then, the run the nightly updates make, and
# the check is taken again after it. The kernel is the exception: it needs a
# reboot, which is a person's choice. On a container host a critical fix for
# the site containers' base starts their nightly replacement now
# (site_refresh.sh start).
#
# State: /var/lib/joinery/security-check/state (key=value, read by
# host_report.sh). A test, never root, runs it
# under SECURITY_CHECK_ROOT, which stands in for / in every path it keeps.

set -uo pipefail
export LC_ALL=C

CMD="${1:-}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TROOT=""
if [ "$(id -u)" -ne 0 ] && [ -n "${SECURITY_CHECK_ROOT:-}" ]; then
    TROOT="${SECURITY_CHECK_ROOT%/}"
fi
STATE_DIR="${TROOT}/var/lib/joinery/security-check"
STATE="${STATE_DIR}/state"
LISTS_DIR="${TROOT}/var/lib/apt/lists"
PLATFORM_REPOS_CONF="${TROOT}/etc/apt/apt.conf.d/51joinery-platform-repos"
UNIT_DIR="${TROOT}/etc/systemd/system"
SITE_REFRESH="${HERE}/../install_tools/site_refresh.sh"
SITE_REFRESH_STATE="${TROOT}/var/lib/joinery/site-refresh/state"
INSTALL_UPDATES="${HERE}/install_security_updates.sh"
LISTS_MAX_AGE="${SECURITY_CHECK_LISTS_MAX_AGE:-2700}"   # 45 minutes
UPDATE_TIMEOUT=300
SIMULATE_TIMEOUT=300
MAX_PACKAGES=200

say()  { echo "security_check: $*"; }
die()  { echo "security_check: $*" >&2; exit 1; }
now()  { date -u +%Y-%m-%dT%H:%M:%SZ; }
clean() { printf '%s' "$1" | tr '\n\t' '  ' | tr -cd "A-Za-z0-9 ,.:;/()_'=+@-" | cut -c1-300; }
# A package's name or version: the characters Debian allows in either.
word() { printf '%s' "$1" | tr -cd 'A-Za-z0-9.+:~-' | cut -c1-128; }

case "$CMD" in run|status|install) ;; *)
    echo "usage: security_check.sh run|status|install" >&2; exit 2 ;;
esac
[ "$(id -u)" -eq 0 ] || [ -n "$TROOT" ] || die "run as root"

if [ "$CMD" = status ]; then
    [ -f "$STATE" ] && cat "$STATE" || echo "no check has run on this machine"
    exit 0
fi

# --- install: the hourly timer --------------------------------------------------
if [ "$CMD" = install ]; then
    # A root timer runs this tree as root, so what it runs must be root's alone.
    want_owner=0
    [ -n "$TROOT" ] && want_owner="$(id -u)"
    refuse_tree() {
        if systemctl is-enabled --quiet joinery-security-check.timer 2> /dev/null; then
            systemctl disable --now joinery-security-check.timer > /dev/null 2>&1 && say "the timer is stopped until the tree is root's alone again"
        fi
        die "$1"
    }
    for p in "$HERE" "${HERE}/security_check.sh" "$SITE_REFRESH" "$INSTALL_UPDATES"; do
        [ -e "$p" ] || refuse_tree "${p} is missing; the timer was not installed"
        read -r owner mode < <(stat -L -c '%u %a' "$p")
        [ "$owner" = "$want_owner" ] && [ $(( 8#$mode & 8#022 )) -eq 0 ] \
            || refuse_tree "${p} is not root's alone (owner ${owner}, mode ${mode}); a root timer will not run from this tree"
    done
    # TimeoutStartSec is past the run's own bounds (lists, simulation, an
    # at-once install of up to 3900 s, a second simulation, the base check):
    # systemd must never kill a package run part way (reviewer2 G1).
    svc="$(cat <<EOF
# Written by security_check.sh install (specs/fleet_security_patching.md WP1).
[Unit]
Description=Joinery: look for security fixes this machine is waiting for
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
ExecStart=/bin/bash ${HERE}/security_check.sh run
Nice=10
IOSchedulingClass=best-effort
IOSchedulingPriority=7
TimeoutStartSec=2h
EOF
)"
    tmr="$(cat <<'EOF'
# Written by security_check.sh install: hourly, spread over a quarter of an
# hour so the fleet does not ask the mirrors at once.
[Unit]
Description=Joinery: hourly look for security fixes

[Timer]
OnCalendar=hourly
RandomizedDelaySec=15m
Persistent=true

[Install]
WantedBy=timers.target
EOF
)"
    changed=0
    mkdir -p "$UNIT_DIR" || die "${UNIT_DIR} could not be made"
    write_unit() {  # FILE TEXT
        if [ ! -f "$1" ] || [ "$(cat "$1")" != "$2" ]; then
            printf '%s\n' "$2" > "${1}.new" && chmod 644 "${1}.new" && mv -f "${1}.new" "$1" || die "${1} could not be written"
            changed=1
        fi
    }
    write_unit "${UNIT_DIR}/joinery-security-check.service" "$svc"
    write_unit "${UNIT_DIR}/joinery-security-check.timer" "$tmr"
    if [ "$changed" -eq 1 ]; then
        systemctl daemon-reload || die "systemd did not reload"
    fi
    if ! systemctl is-enabled --quiet joinery-security-check.timer 2> /dev/null || ! systemctl is-active --quiet joinery-security-check.timer 2> /dev/null; then
        systemctl enable --now joinery-security-check.timer > /dev/null 2>&1 || die "the timer could not be enabled"
        changed=1
    fi
    if [ ! -f "$STATE" ]; then
        mkdir -p "$STATE_DIR" && chmod 755 "$STATE_DIR" && printf 'installed_at=%s\n' "$(now)" > "$STATE" && chmod 644 "$STATE"
    fi
    [ "$changed" -eq 1 ] && say "the hourly security check is installed" || say "the hourly security check is current"
    exit 0
fi

# --- run ----------------------------------------------------------------------
mkdir -p "${TROOT}/run/joinery" && chmod 755 "${TROOT}/run/joinery"
exec 7>>"${TROOT}/run/joinery/security-check.lock" || die "the lock could not be opened"
flock -n 7 || { say "another check is under way"; exit 0; }

declare -A S=()
if [ -f "$STATE" ]; then
    while IFS='=' read -r k v; do [ -n "$k" ] && S[$k]="$v"; done < "$STATE"
fi
state_save() {
    local tmp k
    mkdir -p "$STATE_DIR" && chmod 755 "$STATE_DIR"
    tmp="$(mktemp "${STATE_DIR}/.state.XXXXXX")" || return 1
    for k in installed_at checked_at checked_ok_at check check_error lists_at lists_error pending critical packages \
             at_once_at at_once_outcome at_once_refresh_at; do
        printf '%s=%s\n' "$k" "${S[$k]:-}" >> "$tmp"
    done
    chmod 644 "$tmp" && mv -f "$tmp" "$STATE"
}
# When the package lists were last fetched: the newest file apt keeps there.
lists_mtime() {
    find "$LISTS_DIR" -maxdepth 1 -type f -name '*_Packages*' -printf '%T@\n' 2> /dev/null | sort -n | tail -1 | cut -d. -f1
}

S[checked_at]="$(now)"; S[check_error]=""; S[lists_error]=""
# When the lists were last fetched: this check's own last refresh, or the
# newest list file. A list unchanged on the mirror keeps its old date, so the
# files alone would make fresh lists look old.
m="$(lists_mtime)"
own="$(date -u -d "${S[lists_at]:-1970-01-01T00:00:00Z}" +%s 2> /dev/null || echo 0)"
[ "${own:-0}" -gt "${m:-0}" ] && m="$own"
age=$(( $(date -u +%s) - ${m:-0} ))
if [ "$age" -gt "$LISTS_MAX_AGE" ]; then
    # Never waits for another package run: a list refresh the nightly run or
    # an install holds is theirs, and the lists it leaves are as fresh.
    if out="$(timeout --kill-after=10 "$UPDATE_TIMEOUT" apt-get update -qq -o DPkg::Lock::Timeout=0 2>&1 < /dev/null)"; then
        m="$(date -u +%s)"
    else
        S[lists_error]="$(clean "the package lists were not refreshed: $(tail -1 <<< "$out")")"
    fi
fi
S[lists_at]="$( [ -n "$m" ] && [ "$m" -gt 0 ] && date -u -d "@${m}" +%Y-%m-%dT%H:%M:%SZ)"

# The origins the platform's own repositories add to the policy, by name
# ("LP-PPA-ondrej-php:${distro_codename}" -> LP-PPA-ondrej-php).
platform=" "
if [ -f "$PLATFORM_REPOS_CONF" ]; then
    while IFS= read -r o; do platform+="${o} "; done < <(grep -oE '"[A-Za-z0-9._-]+:' "$PLATFORM_REPOS_CONF" | tr -d '":')
fi

# When each package now pending was first seen pending, from the last state:
# the plane's incident counts from it (a critical fix waiting over an hour, any
# fix over a day). A package at a new candidate version is a new fix.
declare -A FIRST=()
for e in ${S[packages]:-}; do
    IFS='|' read -r n_ _ c_ _ f_ <<< "$e"
    [ -n "$n_" ] && [ -n "${f_:-}" ] && FIRST["${n_}|${c_}"]="$f_"
done

# What a full upgrade would install, as the simulation names it:
#   Inst NAME [INSTALLED] (CANDIDATE ORIGIN:VERSION/SUITE, ... [ARCH]) ...
# A package new to the machine has no [INSTALLED], and is not counted: an
# upgrade pulls it in, and that upgrade is counted where it is a fix itself (a
# kernel fix is the new linux-image-X with linux-image-generic's upgrade). One
# needed only by an upgrade from -updates is never installed by the nightly
# run, and would wait forever. Sets the state's pending,
# critical and packages (each name|installed|candidate|critical|first_seen),
# and AT_ONCE: the critical fixes Q2 installs now (the kernel waits for a
# person's reboot).
simulate() {
    local sim re line name installed candidate origins fix o os suite origin c entries=() n=0 crit=0 w f
    AT_ONCE=()
    if ! sim="$(timeout --kill-after=10 "$SIMULATE_TIMEOUT" apt-get -s -q -o Debug::NoLocking=1 dist-upgrade 2>&1 < /dev/null)"; then
        S[check]="failed"; S[check_error]="$(clean "the simulated upgrade failed: $(tail -1 <<< "$sim")")"
        return 1
    fi
    re='^Inst ([^ ]+) (\[([^]]*)\] )?\(([^ ]+) ([^[]*)\[[^]]*\]\)'
    while IFS= read -r line; do
        [[ "$line" =~ $re ]] || continue
        name="${BASH_REMATCH[1]}" installed="${BASH_REMATCH[3]}" candidate="${BASH_REMATCH[4]}" origins="${BASH_REMATCH[5]}"
        [ -n "$installed" ] || continue
        fix=0
        IFS=',' read -ra os <<< "$origins"
        for o in "${os[@]}"; do
            o="${o# }"; o="${o% }"
            suite="${o##*/}" origin="${o%%:*}"
            [[ "$suite" == *-security ]] && fix=1
            [[ "$platform" == *" ${origin} "* ]] && fix=1
        done
        [ "$fix" -eq 1 ] || continue
        c=0
        [[ "$name" =~ ^(php[0-9]|libapache2-mod-php|apache2|libssl|openssl|postgresql|libpq|linux-image|linux-modules) ]] && c=1
        n=$((n + 1)); crit=$((crit + c))
        [ "$c" -eq 1 ] && [[ ! "$name" =~ ^linux-(image|modules) ]] && AT_ONCE+=("$(word "$name")")
        [ "${#entries[@]}" -lt "$MAX_PACKAGES" ] || continue
        w="$(word "$name")|$(word "$installed")|$(word "$candidate")"
        f="${FIRST["$(word "$name")|$(word "$candidate")"]:-${S[checked_at]}}"
        entries+=("${w}|${c}|${f}")
    done <<< "$sim"
    S[check]="ok"; S[checked_ok_at]="${S[checked_at]}"
    S[pending]="$n"; S[critical]="$crit"
    S[packages]="${entries[*]:-}"
    return 0
}

if ! simulate; then
    state_save
    say "${S[check_error]}"; exit 1
fi
state_save
say "check: ${S[pending]} security fixes pending (${S[critical]} critical)${S[lists_error]:+; ${S[lists_error]}}"

# A critical fix is installed at once (Q2: PHP, Apache, OpenSSL, PostgreSQL;
# never the kernel, which needs a reboot a person chooses): the same run the
# nightly updates make, now. Other fixes wait for the night.
if [ "${#AT_ONCE[@]}" -gt 0 ] && [ -f "$INSTALL_UPDATES" ]; then
    say "installing at once, for ${AT_ONCE[*]:0:6}"
    out="$(timeout --kill-after=60 3900 bash "$INSTALL_UPDATES" 7>&- 2>&1 < /dev/null)"
    S[at_once_at]="$(now)"
    S[at_once_outcome]="$(clean "$(sed -n 's/.*"outcome":"\([a-z_]*\)".*/\1/p' <<< "$out" | tail -1)")"
    [ -n "${S[at_once_outcome]}" ] || S[at_once_outcome]="no_result"
    say "installed at once: ${S[at_once_outcome]}"
    S[checked_at]="$(now)"
    simulate || say "${S[check_error]}"
    state_save
fi

# A container host's sites run on its base image, which only site_refresh.sh
# can ask; the nightly run does, and so does this hour's. A critical fix for
# the base starts the nightly run now (Q2), where its timer is on.
# Only where that timer is on: its install turns it off for a tree that is not
# root's alone, and this would otherwise source the same files as root
# (reviewer2 G2).
if [ -f "$SITE_REFRESH" ] && systemctl is-enabled --quiet joinery-site-refresh.timer 2> /dev/null; then
    if timeout --kill-after=30 900 bash "$SITE_REFRESH" check 7>&- < /dev/null; then
        base_critical="$(sed -n 's/^security_packages=//p' "$SITE_REFRESH_STATE" 2> /dev/null | tr ' ' '\n' \
            | grep -E '^(php[0-9]|libapache2-mod-php|apache2|libssl|openssl|postgresql|libpq)' | head -6 | paste -sd ' ')"
        if [ -n "$base_critical" ]; then
            say "the site containers' base waits for ${base_critical}: replacing them now"
            bash "$SITE_REFRESH" start 7>&- < /dev/null || say "the replacement could not be started"
            S[at_once_refresh_at]="$(now)"; state_save
        fi
    else
        say "the site containers' base could not be checked"
    fi
fi
exit 0
