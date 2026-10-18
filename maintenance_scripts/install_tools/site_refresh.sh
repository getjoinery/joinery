#!/usr/bin/env bash
#
# site_refresh.sh - replace this host's site containers when Ubuntu ships a
# security fix for something in their image (specs/multi_tenant_host_hardening.md
# D9, B7).
#
# Version: 1.2 - A site is due as well when its container was made under another system call filter
#                than the host's (joinery.seccomp, _site_run_spec.sh 1.17); the log names why each is
#                replaced (multi_tenant_host_hardening WP3).
# Version: 1.1 - reviewer2's WP8 review: a hold is a site's own (held/SITE), and the run goes on
#                with the other sites; it stops only when a second site rolls back on the same base,
#                or one is left down, so one tenant's site cannot hold every other tenant's fix (G1,
#                owner 2026-10-17). Each site's replacement is bounded (G2). The check counts what a
#                full upgrade would install, by the -security pocket's own name, in a container with
#                only apt's three file capabilities (G9, G15); a failed check still replaces the sites due on the
#                current base. The base builds at the lowest best-effort I/O priority and under a
#                memory limit (G10). install checks the files the build and every site image take,
#                stops the timer when the tree is not root's alone, and loads nothing first (G12). The
#                holds are recreate_site.sh's to write; a hold on a site left down outlives a new base
#                (the site is still down, and a new base is no new try for it).
# Version: 1.0
#
# A site container's OS is its image's, and nothing inside it installs a fix:
# the official ubuntu image switches apt's daily run off. Everything a site
# keeps is on volumes, so it is patched by replacing its container on a freshly
# built image (recreate_site.sh). This decides when, nightly, from a systemd
# timer host housekeeping installs on a site container engine host.
#
#   site_refresh.sh check          ask a throwaway container of the base what apt would install;
#                                  record it; change nothing else
#   site_refresh.sh run            check; on a security fix (or a base this tree would build
#                                  differently) build the base fresh; then replace every due
#                                  site, one at a time
#   site_refresh.sh status         the last check and run (the state file), and the holds
#   site_refresh.sh clear [SITE]   lift the holds failed replacements placed, or one site's
#   site_refresh.sh install        write and enable the timer (root, this tree root's own)
#
# A fix is something apt-get -s full-upgrade would install from the release's
# -security pocket; -updates alone starts no cycle, and the fresh build installs
# those too. The base is also due when there is none at this tree's
# BASE_IMAGE_VERSION, or when this tree's server setup differs from the one the
# base was built with (its joinery.install_sh_hash label). A site is due when
# its image does not carry the current base's id (joinery.base_id), so a cycle
# stopped part way is finished on a later night, and when its container was
# made under another system call filter than the host's (joinery.seccomp): the
# engine reads the filter only when it makes a container, so a release that
# changes it reaches every site the night after.
#
# A replacement that is rolled back (recreate_site.sh 3) holds that site
# (held/SITE, naming the base) and the run goes on with the others: one site
# failing says "that site". A second site rolled back on the same base, or a
# site left down (4), says "the base" (or the host), and the run stops; while
# that stands a run only checks. A site's hold is lifted by a new base, by its
# own replacement succeeding, or by clear; a site left down stays held across a
# new base, until it is replaced or cleared. recreate_site.sh writes the holds.
#
# State: /var/lib/joinery/site-refresh: state (key=value, read by
# host_report.sh), held/, and logs. A test, never root, runs it under
# JOINERY_SITE_STATE_ROOT, which stands in for / in every path it keeps.

set -uo pipefail

CMD="${1:-}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TROOT=""
if [ "$(id -u)" -ne 0 ] && [ -n "${JOINERY_SITE_STATE_ROOT:-}" ]; then
    TROOT="${JOINERY_SITE_STATE_ROOT%/}"
fi
STATE_DIR="${TROOT}/var/lib/joinery/site-refresh"
STATE="${STATE_DIR}/state"
HELD_DIR="${STATE_DIR}/held"
RECREATE="${HERE}/../sysadmin_tools/recreate_site.sh"
UNIT_DIR="${TROOT}/etc/systemd/system"
ETC="${TROOT}/etc"
MIN_FREE_BYTES="${SITE_REFRESH_MIN_FREE_BYTES:-3221225472}"   # 3 GiB for a fresh base
CHECK_TIMEOUT="${SITE_REFRESH_CHECK_TIMEOUT:-600}"
SITE_TIMEOUT="${SITE_REFRESH_SITE_TIMEOUT:-2700}"           # one site's replacement, past its own bounds
BUILD_MEMORY="${SITE_REFRESH_BUILD_MEMORY:-2g}"             # the base build's steps

say()  { echo "site_refresh: $*"; }
die()  { echo "site_refresh: $*" >&2; exit 1; }
now()  { date -u +%Y-%m-%dT%H:%M:%SZ; }
# Text a state line can hold: one line, the characters a reason is made of.
clean() { printf '%s' "$1" | tr '\n\t' '  ' | tr -cd "A-Za-z0-9 ,.:;/()_'=+@-" | cut -c1-300; }

case "$CMD" in check|run|status|clear|install) ;; *)
    echo "usage: site_refresh.sh check|run|status|clear [SITE]|install" >&2; exit 2 ;;
esac
[ "$(id -u)" -eq 0 ] || [ -n "$TROOT" ] || die "run as root on the container host"

if [ "$CMD" = status ]; then
    [ -f "$STATE" ] && cat "$STATE" || echo "no check has run on this host"
    for f in "${HELD_DIR}"/*; do
        [ -f "$f" ] || continue
        echo "--- held: $(basename "$f")"; cat "$f"
    done
    exit 0
fi
if [ "$CMD" = clear ]; then
    one="${2:-}"
    if [ -n "$one" ]; then
        [[ "$one" =~ ^[a-z0-9][a-z0-9_-]{0,49}$ ]] || die "'${one:0:60}' is not a site name"
        if [ -f "${HELD_DIR}/${one}" ]; then rm -f "${HELD_DIR}/${one}" && say "${one}'s hold is lifted; the next run replaces it"; else say "${one} is not held"; fi
    else
        n=0; for f in "${HELD_DIR}"/*; do [ -f "$f" ] && rm -f "$f" && n=$((n + 1)); done
        say "${n} hold(s) lifted; the next run replaces the due sites"
    fi
    exit 0
fi
# --- install: the timer -------------------------------------------------------
if [ "$CMD" = install ]; then
    # A root timer runs this tree as root, so the tree must be root's alone:
    # a tree another account can write would make the timer a way to run
    # anything as root.
    tree="$(cd "${HERE}/../.." && pwd)"
    want_owner=0
    [ -n "$TROOT" ] && want_owner="$(id -u)"   # a test's tree is its own
    # A tree that is not (any more) root's alone stops a timer already on:
    # housekeeping runs install every minute, so it comes back once it is.
    refuse_tree() {
        if systemctl is-enabled --quiet joinery-site-refresh.timer 2> /dev/null; then
            systemctl disable --now joinery-site-refresh.timer > /dev/null 2>&1 && say "the timer is stopped until the tree is root's alone again"
        fi
        die "$1"
    }
    # Every file the run executes, copies into the base build (run there as
    # root) or into every site's image.
    for p in "$tree" "$tree/maintenance_scripts" "$HERE" "${HERE}/../sysadmin_tools" \
             "${HERE}/site_refresh.sh" "${HERE}/install.sh" "${HERE}/_site_run_spec.sh" "${HERE}/_site_engine.sh" \
             "${HERE}/_host_files.sh" "${HERE}/Dockerfile.base" "${HERE}/Dockerfile.template" \
             "${HERE}/_site_start.sh" "${HERE}/default_virtualhost.conf" "$RECREATE"; do
        [ -e "$p" ] || refuse_tree "${p} is missing; the timer was not installed"
        read -r owner mode < <(stat -L -c '%u %a' "$p")
        [ "$owner" = "$want_owner" ] && [ $(( 8#$mode & 8#022 )) -eq 0 ] \
            || refuse_tree "${p} is not root's alone (owner ${owner}, mode ${mode}); a root timer will not run from this tree"
    done
    svc="$(cat <<EOF
# Written by site_refresh.sh install (specs/multi_tenant_host_hardening.md D9).
[Unit]
Description=Joinery: replace site containers when Ubuntu ships a fix
After=network-online.target podman-restart.service
Wants=network-online.target

[Service]
Type=oneshot
ExecStart=/bin/bash ${HERE}/site_refresh.sh run
TimeoutStartSec=6h
EOF
)"
    tmr="$(cat <<'EOF'
# Written by site_refresh.sh install: nightly, ending before the fleet's backup
# window (03:00 UTC) and the update reboot (05:30 UTC); a missed night is not
# run at the next boot.
[Unit]
Description=Joinery: nightly check for fixes to the site containers' OS

[Timer]
OnCalendar=*-*-* 01:30 UTC
RandomizedDelaySec=30m
Persistent=false

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
    write_unit "${UNIT_DIR}/joinery-site-refresh.service" "$svc"
    write_unit "${UNIT_DIR}/joinery-site-refresh.timer" "$tmr"
    if [ "$changed" -eq 1 ]; then
        systemctl daemon-reload || die "systemd did not reload"
    fi
    if ! systemctl is-enabled --quiet joinery-site-refresh.timer 2> /dev/null || ! systemctl is-active --quiet joinery-site-refresh.timer 2> /dev/null; then
        systemctl enable --now joinery-site-refresh.timer > /dev/null 2>&1 || die "the timer could not be enabled"
        changed=1
    fi
    # The first state says when the timer began, so a timer that never runs
    # is seen (the plane warns when no check has passed for two days).
    if [ ! -f "$STATE" ]; then
        mkdir -p "$STATE_DIR" && chmod 755 "$STATE_DIR" && printf 'installed_at=%s\n' "$(now)" > "$STATE" && chmod 644 "$STATE"
    elif ! grep -q '^installed_at=..*' "$STATE"; then
        { grep -v '^installed_at=' "$STATE"; printf 'installed_at=%s\n' "$(now)"; } > "${STATE}.new" && chmod 644 "${STATE}.new" && mv -f "${STATE}.new" "$STATE"
    fi
    [ "$changed" -eq 1 ] && say "the nightly replacement timer is installed" || say "the nightly replacement timer is current"
    exit 0
fi

# install.sh, for its functions and BASE_IMAGE_VERSION (sourced, it returns
# before its dispatcher; it turns errexit on, which this script does not use).
[ -f "${HERE}/install.sh" ] || die "there is no install.sh beside this script"
# shellcheck source=install.sh
. "${HERE}/install.sh" || die "install.sh could not be loaded"
set +e
# shellcheck source=_site_run_spec.sh
. "${HERE}/_site_run_spec.sh" || die "_site_run_spec.sh could not be loaded"
QUIET_MODE=1

# --- check --------------------------------------------------------------------
declare -A S=()
state_load() { local k v; [ -f "$STATE" ] || return 0; while IFS='=' read -r k v; do [ -n "$k" ] && S[$k]="$v"; done < "$STATE"; }
state_save() {
    local tmp k
    mkdir -p "$STATE_DIR" && chmod 755 "$STATE_DIR"
    tmp="$(mktemp "${STATE_DIR}/.state.XXXXXX")" || return 1
    for k in installed_at checked_at checked_ok_at check check_error pending pending_security security_packages base_version base_id base_built_at base_due \
             ran_at run run_error replaced passed_over sites sites_due; do
        printf '%s=%s\n' "$k" "$(clean "${S[$k]:-}")" >> "$tmp"
    done
    chmod 644 "$tmp" && mv -f "$tmp" "$STATE"
}
held_get() { sed -n "s/^$2=//p" "${HELD_DIR}/$1" 2>/dev/null | head -1; }   # SITE KEY
# A site held on the current base.
held_now() { [ -f "${HELD_DIR}/$1" ] && [ "$(held_get "$1" base)" = "${BASE_ID_FULL:0:12}" ]; }
# The run stops: a site left down on this base, or two rolled back on it.
stopped_reason() {
    local f site n=0 down=""
    for f in "${HELD_DIR}"/*; do
        [ -f "$f" ] || continue
        site="$(basename "$f")"
        held_now "$site" || continue
        n=$((n + 1))
        [ "$(held_get "$site" outcome)" = down ] && down="$site"
    done
    if [ -n "$down" ]; then echo "${down} was left down"; return 0; fi
    if [ "$n" -ge 2 ]; then echo "${n} sites' replacements rolled back on this base"; return 0; fi
    return 1
}

BASE_REF="joinery-base:${BASE_IMAGE_VERSION}"
ENGINE="$(site_engine_bin)" || die "this machine's container engine could not be named"
command -v "$ENGINE" > /dev/null 2>&1 || die "${ENGINE} is not installed here"

# Sets S[base_*] for the current base image.
read_base() {
    local id created
    id="$(base_image_id)" || id=""
    S[base_version]="$BASE_IMAGE_VERSION"
    S[base_id]="${id:0:12}"
    BASE_ID_FULL="$id"
    created="$(site_engine image inspect -f '{{.Created}}' "$BASE_REF" 2>/dev/null | head -1)"
    S[base_built_at]="$(date -u -d "${created% UTC}" +%Y-%m-%dT%H:%M:%SZ 2>/dev/null || true)"
}

# The check: what a full upgrade would install in a throwaway container of the
# base, which holds only the three file capabilities apt's lists need (with
# none, every index fetch fails on se1), and runs apt as root without dropping
# to _apt.
# A full upgrade counts a fix that needs a new package too; a fresh build
# installs it.
do_check() {
    local out rc lines sec
    S[checked_at]="$(now)"; S[check_error]=""
    read_base
    if [ -z "$BASE_ID_FULL" ]; then
        S[check]="ok"; S[base_due]="missing"; S[pending]=""; S[pending_security]=""; S[security_packages]=""
        return 0
    fi
    out="$(timeout --kill-after=10 "$CHECK_TIMEOUT" "$ENGINE" run --rm --memory 512m --pids-limit 512 \
        --cap-drop=ALL --cap-add=CHOWN,DAC_OVERRIDE,FOWNER --security-opt=no-new-privileges --entrypoint bash "$BASE_REF" -c \
        'A="-o APT::Sandbox::User=root"; apt-get $A update -qq > /dev/null 2>&1 || { echo "UPDATE_FAILED"; exit 0; }; apt-get $A -s -q full-upgrade 2> /dev/null | grep "^Inst " || true' 2>&1 < /dev/null)"
    rc=$?
    if [ "$rc" -ne 0 ] || grep -q '^UPDATE_FAILED' <<< "$out"; then
        S[check]="failed"
        S[check_error]="$( [ "$rc" -ne 0 ] && echo "the check container exited ${rc}: $(tail -1 <<< "$out")" || echo "apt-get update failed in the check container")"
        return 1
    fi
    lines="$(grep '^Inst ' <<< "$out")"
    S[check]="ok"; S[checked_ok_at]="${S[checked_at]}"
    S[pending]="$(grep -c '^Inst ' <<< "$lines")"
    # The pocket by its own name (".../resolute-security [amd64]"), never a
    # package whose name holds the word.
    sec="$(grep -E '/[a-z0-9]+-security([], ]|$)' <<< "$lines")"
    S[pending_security]="$(grep -c '^Inst ' <<< "$sec")"
    S[security_packages]="$(awk '{print $2}' <<< "$sec" | head -20 | paste -sd ' ')"
    local label want
    label="$(site_engine image inspect -f '{{index .Config.Labels "joinery.install_sh_hash"}}' "$BASE_REF" 2>/dev/null)"
    want="$(compute_install_sh_hash)"
    if [ "${S[pending_security]}" -gt 0 ]; then
        S[base_due]="fix"
    elif [ -n "$label" ] && [ "$label" != "unknown" ] && [ "$label" != "$want" ]; then
        S[base_due]="setup_changed"
    else
        S[base_due]="no"
    fi
    return 0
}

# The sites on this host: running containers with a run spec. Prints one name a line.
host_sites() {
    local c
    while IFS= read -r c; do
        [[ "$c" =~ ^[a-z0-9][a-z0-9_-]{0,49}$ ]] || continue
        run_spec_exists "$c" || continue
        printf '%s\n' "$c"
    done < <(site_engine ps --format '{{.Names}}' 2>/dev/null | sort)
}
# Due: on an image that does not carry the current base's id, or, on the site
# engine, made under another system call filter than the host's (its
# joinery.seccomp label, run_spec_seccomp_hash; none on a container made before
# the label). Quiet, held stopped and held sites are never due. DUE_WHY says
# which.
site_due() {
    local site="$1" img label want
    DUE_WHY=""
    [ -e "${ETC}/joinery/sites/${site}/quiet" ] && return 1
    [ -e "${ETC}/joinery/sites/${site}/held" ] && return 1
    held_now "$site" && return 1
    img="$(site_engine inspect -f '{{.Image}}' "$site" 2>/dev/null)" || return 1
    label="$(site_engine image inspect -f '{{index .Config.Labels "joinery.base_id"}}' "$img" 2>/dev/null)"
    if [ "$label" != "$BASE_ID_FULL" ]; then DUE_WHY="an older base"; return 0; fi
    site_engine_is_podman || return 1
    want="$(run_spec_seccomp_hash)" || return 1
    label="$(site_engine inspect -f '{{index .Config.Labels "joinery.seccomp"}}' "$site" 2>/dev/null)"
    if [ "$label" != "$want" ]; then DUE_WHY="an older system call filter"; return 0; fi
    return 1
}
count_due() {
    local s n=0 d=0
    while IFS= read -r s; do n=$((n + 1)); site_due "$s" && d=$((d + 1)); done < <(host_sites)
    S[sites]="$n"; S[sites_due]="$d"
}

mkdir -p "${TROOT}/run/joinery" && chmod 755 "${TROOT}/run/joinery"
exec 7>>"${TROOT}/run/joinery/site-refresh.lock" || die "the lock ${TROOT}/run/joinery/site-refresh.lock could not be opened"
flock -n 7 || { say "another run is under way"; exit 0; }

state_load
if [ "$CMD" = check ]; then
    do_check; rc=$?
    count_due; state_save
    say "check: ${S[check]}${S[check_error]:+ (${S[check_error]})}; ${S[pending_security]:-?} security fixes of ${S[pending]:-?} updates pending; base due: ${S[base_due]:-?}; ${S[sites_due]} of ${S[sites]} sites due"
    exit "$rc"
fi

# --- run ----------------------------------------------------------------------
S[ran_at]="$(now)"; S[run_error]=""; S[replaced]=""; S[passed_over]=""
mkdir -p "${STATE_DIR}/logs" && chmod 700 "${STATE_DIR}/logs" || die "${STATE_DIR}/logs could not be made"
# A failed check (a mirror down) builds nothing; the sites already due on the
# current base need no check, and are replaced.
if ! do_check; then
    say "the check failed: ${S[check_error]}; replacing only the sites due on the current base"
elif [ "${S[base_due]}" != "no" ]; then
    graph="$(if site_engine_is_podman; then site_engine info --format '{{.Store.GraphRoot}}'; else site_engine info -f '{{.DockerRootDir}}'; fi 2>/dev/null)"
    free="$(df -B1 --output=avail "${graph:-/}" 2>/dev/null | tail -1 | tr -d ' ')"
    if ! [[ "$free" =~ ^[0-9]+$ ]] || [ "$free" -lt "$MIN_FREE_BYTES" ]; then
        S[run]="base_failed"; S[run_error]="only ${free:-an unknown number of} bytes free where the engine keeps images (${graph:-?}); a fresh base needs ${MIN_FREE_BYTES}"
        count_due; state_save
        say "${S[run_error]}"; exit 0
    fi
    say "building the base fresh (${S[base_due]}: ${S[pending_security]:-0} security fixes)"
    # Best-effort at its lowest, not idle: idle I/O can starve behind a host's
    # databases, and is ignored by the multi-queue schedulers anyway.
    # Bounded (an hour; it is about three minutes), and a build that fails
    # leaves no working container of its own behind (no prune takes those).
    wc_now() { site_engine_is_podman && "$ENGINE" ps -a --external --format '{{.ID}} {{.Names}}' 2> /dev/null | awk '$2 ~ /-working-container(-[0-9a-z]+)?$/ { print $1 }' | sort; }
    WC_BEFORE="$(wc_now)"
    if ! timeout --kill-after=60 "${SITE_REFRESH_BASE_SECONDS:-3600}" nice -n 10 ionice -c 2 -n 7 bash "${HERE}/install.sh" -q build-base --fresh --memory="$BUILD_MEMORY" \
            > "${STATE_DIR}/logs/base.build.log" 2>&1 7>&- < /dev/null; then
        for id in $(comm -13 <(printf '%s\n' "$WC_BEFORE") <(wc_now)); do
            timeout 60 "$ENGINE" rm -f --storage "$id" > /dev/null 2>&1
        done
        S[run]="base_failed"; S[run_error]="the base did not build: $(tail -1 "${STATE_DIR}/logs/base.build.log")"
        count_due; state_save
        say "${S[run_error]} (log: ${STATE_DIR}/logs/base.build.log)"; exit 0
    fi
    read_base
    say "built ${BASE_REF} (${S[base_id]})"
fi
[ -n "${BASE_ID_FULL:-}" ] || read_base

# A hold on a base that is no longer current is lifted: a new base is a new
# try. Not a site left down: it is still down, and needs a replacement or clear.
for f in "${HELD_DIR}"/*; do
    [ -f "$f" ] || continue
    site="$(basename "$f")"
    held_now "$site" && continue
    [ "$(held_get "$site" outcome)" = down ] && continue
    rm -f "$f" && say "a new base lifts ${site}'s hold"
done
if why="$(stopped_reason)"; then
    S[run]="held"; count_due; state_save
    say "held: ${why}; clear the holds, or replace the sites with recreate_site.sh, once that is put right"
    exit 0
fi

replaced=() passed=()
outcome="nothing_due"
while IFS= read -r site; do
    site_due "$site" || continue
    say "replacing ${site} (${DUE_WHY})"
    # Without this run's lock (descriptor 7): the containers it starts would
    # inherit it, and their monitors would hold it for good. Stdin is
    # /dev/null: it is this loop's list of sites, and an engine call that
    # reads stdin took the rest of the list (se1: one site of three replaced).
    # Bounded: past its own bounds, one site costs only its own slot.
    out="$(RECREATE_BUILD_LOG="${STATE_DIR}/logs/${site}.build.log" timeout --kill-after=60 "$SITE_TIMEOUT" \
        bash "$RECREATE" "$site" 2>&1 7>&- < /dev/null)"; rc=$?
    printf '%s\n' "$out" | sed "s/^/  ${site}: /"
    last="$(tail -1 <<< "$out")"
    down="$(sed -n 's/.*"down_seconds":\([0-9.]*\).*/\1/p' <<< "$last")"
    reason="$(sed -n 's/.*"reason":"\(.*\)"}$/\1/p' <<< "$last")"
    case "$rc" in
        0) replaced+=("${site}:${down:-?}"); outcome="replaced" ;;
        2) passed+=("${site}:busy") ;;
        3) say "${site} is held: rolled back (${reason})" ;;
        4) say "${site} is held: left down (${reason})" ;;
        124|137) passed+=("${site}:timed_out"); S[run_error]="${site}: its replacement passed ${SITE_TIMEOUT} s and was stopped" ;;
        *) passed+=("${site}:refused") ; S[run_error]="${site}: $(clean "$reason")" ;;
    esac
    if why="$(stopped_reason)"; then
        outcome="held"
        say "the run stops: ${why}"
        break
    fi
done < <(host_sites)
if [ "$outcome" != held ]; then
    held_any=0; for f in "${HELD_DIR}"/*; do [ -f "$f" ] && held_any=1; done
    if [ "${#passed[@]}" -gt 0 ] || [ "$held_any" -eq 1 ]; then outcome="incomplete"; fi
fi

site_engine image prune -f --filter until=10m > /dev/null 2>&1
S[run]="$outcome"
S[replaced]="${replaced[*]}"
S[passed_over]="${passed[*]}"
count_due; state_save
say "run: ${outcome}; replaced: ${S[replaced]:-none}; passed over: ${S[passed_over]:-none}; ${S[sites_due]} of ${S[sites]} sites still due"
exit 0
