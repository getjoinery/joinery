#!/usr/bin/env bash
#
# security_check.sh - which security fixes this machine is waiting for, looked
# for every hour (specs/fleet_security_patching.md WP1, Q3).
#
# Version: 1.7 - after converge, a withdrawn build is installed back down by install_package_fixes.sh rollback,
#                under its own 30-minute bound (reviewer2 B7); the runner's timer only where this box issues
#                or mirrors the package fix channel (B10)
# Version: 1.6 - install also writes joinery-package-fix-auto.timer where this tree carries the automatic
#                package fix runner (the Server Manager plugin's package_fix_auto.php) and PHP: every ten
#                minutes, root, under the same owner rule (specs/package_fixes_automatic.md WP9)
# Version: 1.5 - a running site behind the base on a critical package (site_refresh.sh 1.7 sites_behind:
#                a site stopped through a cycle and started later) is replaced at once too (B24)
# Version: 1.4 - the timer installs where the tree is its owner's alone, the owner the host converger trusts
#                (_tree_trust.sh, config/tree_owner): the publishing box, whose converger already runs its
#                developer account's installers as root, had no hourly check at all
# Version: 1.3 - package fixes ahead of Ubuntu (specs/package_fixes_ahead_of_ubuntu.md WP3): each run
#   first converges them (install_package_fixes.sh converge: a fetch that failed is tried again, and a
#   fix Ubuntu's own build has caught up with is handed back), and a package from the Joinery-Fixes
#   origin is a security fix, critical and installed at once whatever its name (Postfix included)
# Version: 1.2 - the check also runs right after the nightly updates finish
#   (WantedBy= and After= apt-daily-upgrade.service), so the plane judges a fix
#   from a look taken after the run that should have installed it, never from
#   the hour before it (joinerydemo, 2026-11-11: warned 16 minutes after the
#   nightly run had installed the fixes)
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
#   security_check.sh install   write and enable the hourly timer, and the run after the
#                               nightly updates (root, this tree root's own)
#
# A security fix is what this machine's own automatic-update policy takes: a
# package from a -security pocket (Ubuntu's, or Ubuntu Pro's), or from an
# outside repository host_housekeeping.sh named in 51joinery-platform-repos
# (the PHP and PostgreSQL ones, and our own builds ahead of Ubuntu: origin
# Joinery-Fixes, install_package_fixes.sh). Never a package from -updates
# alone: the nightly run does not take those either.
#
# Each package waiting is recorded with its installed and candidate versions,
# when it was first seen waiting, and marked critical when it is something a
# site faces the internet with or the machine boots: PHP, Apache, OpenSSL,
# PostgreSQL, the kernel; and anything from our own builds, which exist only
# for an emergency.
#
# A critical fix is installed at once (Q2), never waiting for the night:
# install_security_updates.sh runs then, the run the nightly updates make, and
# the check is taken again after it. The kernel is the exception: it needs a
# reboot, which is a person's choice. On a container host a critical fix for
# the site containers' base, or a running site that lacks one the base has,
# starts their nightly replacement now (site_refresh.sh start).
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
PACKAGE_FIXES="${HERE}/../install_tools/install_package_fixes.sh"
# The origin of our own builds of Ubuntu packages, ahead of Ubuntu: the word
# before the colon in a simulated Inst line is the repository's Label.
FIXES_ORIGIN="Joinery-Fixes"
INSTALL_UPDATES="${HERE}/install_security_updates.sh"
# The automatic package fix runner, where this tree carries the Server Manager plugin.
PACKAGE_FIX_AUTO="${HERE}/../../public_html/plugins/server_manager/includes/package_fix_auto.php"
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

# Does this box issue or mirror the package fix channel? It holds the release
# signing key (config/agent_signing_key: the box that logs releases), or its
# site serves releases (the upgrade_server_active setting).
pfa_role_here() {
    local site config name user pass value
    site="$(cd "${HERE}/../.." 2> /dev/null && pwd)" || return 1
    [ -f "$site/config/agent_signing_key" ] && return 0
    config="$site/config/Globalvars_site.php"
    [ -f "$config" ] && [ -f "${HERE}/../install_tools/_config_value.php" ] && command -v psql > /dev/null 2>&1 || return 1
    name="$(php "${HERE}/../install_tools/_config_value.php" "$config" dbname 2> /dev/null)"
    user="$(php "${HERE}/../install_tools/_config_value.php" "$config" dbusername 2> /dev/null)"
    pass="$(php "${HERE}/../install_tools/_config_value.php" "$config" dbpassword 2> /dev/null)"
    [ -n "$name" ] && [ -n "$user" ] || return 1
    value="$(PGPASSWORD="$pass" PGCONNECT_TIMEOUT=10 psql -h localhost -U "$user" -d "$name" -tAX \
        -c "SELECT stg_value FROM stg_settings WHERE stg_name = 'upgrade_server_active'" 2> /dev/null | tr -d '[:space:]')"
    [ "$value" = 1 ] || [ "$value" = true ]
}

# --- install: the hourly timer --------------------------------------------------
if [ "$CMD" = install ]; then
    # A root timer runs this tree as root, so what it runs must be its owner's
    # alone: the owner the host converger trusts (_tree_trust.sh, the site's
    # config/tree_owner record), root on a node and the developer account on
    # the publishing box, where the converger already runs that account's
    # installers as root every minute. Nobody else may be able to write it.
    want_owner=0
    owner_name=root
    if [ -n "$TROOT" ]; then
        want_owner="$(id -u)"; owner_name="$(id -un)"
    elif [ -f "${HERE}/../install_tools/_tree_trust.sh" ]; then
        # shellcheck source=../install_tools/_tree_trust.sh
        . "${HERE}/../install_tools/_tree_trust.sh"
        owner_name="$(joinery_tree_owner_record "$(cd "${HERE}/../.." && pwd)" 2> /dev/null)" || owner_name=root
        want_owner="$(id -u "$owner_name" 2> /dev/null)" || { want_owner=0; owner_name=root; }
    fi
    refuse_tree() {
        if systemctl is-enabled --quiet joinery-security-check.timer 2> /dev/null; then
            systemctl disable --now joinery-security-check.timer > /dev/null 2>&1 && say "the timer is stopped until the tree is its owner's alone again"
        fi
        systemctl disable joinery-security-check.service > /dev/null 2>&1 || true
        die "$1"
    }
    run_paths=("$HERE" "${HERE}/security_check.sh" "$SITE_REFRESH" "$INSTALL_UPDATES")
    # Run when present (a tree from before package fixes has none).
    [ -e "$PACKAGE_FIXES" ] && run_paths+=("$PACKAGE_FIXES")
    # The package fix runner runs only where this box issues the fix channel
    # (it holds the release signing key) or mirrors it (it serves releases
    # others upgrade from): anywhere else a root timer booting the site every
    # ten minutes to learn it has nothing to do is root for nothing.
    PFA_PHP=""
    if [ -e "$PACKAGE_FIX_AUTO" ] && command -v php > /dev/null 2>&1 && pfa_role_here; then
        PFA_PHP="$(command -v php)"
        # What the run loads, as root, must be the owner's alone too.
        pfa_pub="$(cd "${HERE}/../../public_html" && pwd)"
        run_paths+=("$PACKAGE_FIX_AUTO" "$(dirname "$PACKAGE_FIX_AUTO")" "$pfa_pub" "$pfa_pub/includes" "$pfa_pub/data"
            "$pfa_pub/plugins" "$pfa_pub/plugins/server_manager" "$pfa_pub/plugins/server_manager/data")
    fi
    for p in "${run_paths[@]}"; do
        [ -e "$p" ] || refuse_tree "${p} is missing; the timer was not installed"
        read -r owner mode < <(stat -L -c '%u %a' "$p")
        [ "$owner" = "$want_owner" ] && [ $(( 8#$mode & 8#022 )) -eq 0 ] \
            || refuse_tree "${p} is not ${owner_name}'s alone (owner ${owner}, mode ${mode}); a root timer will not run from this tree"
    done
    # TimeoutStartSec is past the run's own bounds (lists, simulation, an
    # at-once install of up to 3900 s, a second simulation, the base check):
    # systemd must never kill a package run part way (reviewer2 G1).
    svc="$(cat <<EOF
# Written by security_check.sh install (specs/fleet_security_patching.md WP1).
# Also run when the nightly updates finish (WantedBy and After
# apt-daily-upgrade), so what they did not install is seen at once, and an
# hourly run that falls during them waits for them.
[Unit]
Description=Joinery: look for security fixes this machine is waiting for
After=network-online.target apt-daily-upgrade.service
Wants=network-online.target

[Service]
Type=oneshot
ExecStart=/bin/bash ${HERE}/security_check.sh run
Nice=10
IOSchedulingClass=best-effort
IOSchedulingPriority=7
TimeoutStartSec=2h

[Install]
WantedBy=apt-daily-upgrade.service
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
    # The run after the nightly updates: enabling the service is what links it
    # into apt-daily-upgrade.service.wants. Re-linked when the unit changed, so
    # a machine installed before the [Install] section gets the link.
    if [ "$changed" -eq 1 ] || ! systemctl is-enabled --quiet joinery-security-check.service 2> /dev/null; then
        systemctl reenable joinery-security-check.service > /dev/null 2>&1 || die "the run after the nightly updates could not be enabled"
        changed=1
    fi
    # The automatic package fix runner: root, every ten minutes, one run at a
    # time (it holds its own lock through an hour-long build). It does nothing
    # on a site that neither logs nor serves releases.
    if [ -e "$PACKAGE_FIX_AUTO" ] && [ -n "$PFA_PHP" ]; then
        pfa_svc="$(cat <<EOF
# Written by security_check.sh install (specs/package_fixes_automatic.md WP9).
[Unit]
Description=Joinery: build and ship security fixes ahead of Ubuntu
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
ExecStart=${PFA_PHP} ${PACKAGE_FIX_AUTO}
Nice=10
IOSchedulingClass=best-effort
IOSchedulingPriority=7
TimeoutStartSec=6h
EOF
)"
        pfa_tmr="$(cat <<'EOF'
# Written by security_check.sh install: every ten minutes.
[Unit]
Description=Joinery: the automatic package fix runner, every ten minutes

[Timer]
OnCalendar=*:0/10
RandomizedDelaySec=60
Persistent=true

[Install]
WantedBy=timers.target
EOF
)"
        before="$changed"; changed=0
        write_unit "${UNIT_DIR}/joinery-package-fix-auto.service" "$pfa_svc"
        write_unit "${UNIT_DIR}/joinery-package-fix-auto.timer" "$pfa_tmr"
        pfa_changed="$changed"
        [ "$before" -eq 1 ] && changed=1
        if [ "$pfa_changed" -eq 1 ]; then
            systemctl daemon-reload || die "systemd did not reload"
        fi
        if ! systemctl is-enabled --quiet joinery-package-fix-auto.timer 2> /dev/null || ! systemctl is-active --quiet joinery-package-fix-auto.timer 2> /dev/null; then
            systemctl enable --now joinery-package-fix-auto.timer > /dev/null 2>&1 || die "the package fix runner's timer could not be enabled"
            changed=1
        fi
    elif [ -e "${UNIT_DIR}/joinery-package-fix-auto.timer" ]; then
        systemctl disable --now joinery-package-fix-auto.timer > /dev/null 2>&1 || true
        rm -f "${UNIT_DIR}/joinery-package-fix-auto.timer" "${UNIT_DIR}/joinery-package-fix-auto.service"
        systemctl daemon-reload || true
        say "the package fix runner's timer is removed: this tree no longer carries it"
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
# Package fixes ahead of Ubuntu, before anything is simulated: the ones this
# release names are placed and pinned (a fetch that failed last hour is tried
# again), and one Ubuntu's own newer build now carries is handed back. Never
# under a test root: it would act on the machine.
if [ -z "$TROOT" ] && [ -f "$PACKAGE_FIXES" ]; then
    if ! out="$(timeout --kill-after=10 900 bash "$PACKAGE_FIXES" converge 7>&- 2>&1 < /dev/null)"; then
        say "package fixes: $(tail -1 <<< "$out")"
    fi
    # A withdrawn build converge found installed goes back down now, under an
    # install's bound, not converge's (install_package_fixes.sh rollback).
    if [ -s /var/lib/joinery/package-fixes/rollback_due ]; then
        if ! out="$(DEBIAN_FRONTEND=noninteractive timeout --kill-after=60 1800 bash "$PACKAGE_FIXES" rollback 7>&- 2>&1 < /dev/null)"; then
            say "package fixes: $(tail -1 <<< "$out")"
        else
            [ -n "$out" ] && say "$out"
        fi
    fi
fi
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
            [ "$origin" = "$FIXES_ORIGIN" ] && fix=2
        done
        [ "$fix" -ge 1 ] || continue
        c=0
        [[ "$name" =~ ^(php[0-9]|libapache2-mod-php|apache2|libssl|openssl|postgresql|libpq|linux-image|linux-modules) ]] && c=1
        # Our own build exists only for an emergency: critical, whatever its name.
        [ "$fix" -eq 2 ] && c=1
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

# A critical fix is installed at once (Q2: PHP, Apache, OpenSSL, PostgreSQL,
# and anything from our own builds ahead of Ubuntu; never the kernel, which
# needs a reboot a person chooses): the same run the nightly updates make, now.
# Other fixes wait for the night.
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
        # A site stopped while its base was replaced, and started since, runs
        # the old base: the base waits for nothing, the site does.
        sites_behind="$(sed -n 's/^sites_behind=//p' "$SITE_REFRESH_STATE" 2> /dev/null | head -1)"
        if [ -n "$base_critical" ] || [ -n "$sites_behind" ]; then
            if [ -n "$base_critical" ]; then
                say "the site containers' base waits for ${base_critical}: replacing them now"
            else
                say "sites lack a critical package the base has (${sites_behind}): replacing them now"
            fi
            bash "$SITE_REFRESH" start 7>&- < /dev/null || say "the replacement could not be started"
            S[at_once_refresh_at]="$(now)"; state_save
        fi
    else
        say "the site containers' base could not be checked"
    fi
fi
exit 0
