#!/usr/bin/env bash
#
# recreate_site.sh - replace one site's container with a new one on this host's
# current base image, keeping everything the site is: its volumes (code,
# scripts, database, uploads, config, the agent's identity), its run spec, its
# environment and its signed release manifest
# (specs/multi_tenant_host_hardening.md D9).
#
# Version: 1.5 - A site Docker made before run specs (docker-prod's six) is no longer refused for having
#                none: once it is known to be the site's own, its container's arguments are recorded as
#                its run spec (run_spec_adopt, the step install.sh site takes), so the nightly
#                replacement reaches it. On Docker a container whose image predates the health check
#                (Docker says none) is judged by its answer alone, before the swap and on a rollback
#                onto that image. Such a container's first process is a shell that a stop's TERM does
#                not end (box proof: Docker waited 60 s and killed it, PostgreSQL with it): its
#                PostgreSQL is stopped cleanly from inside first, then its Apache, so it ends on its
#                own, with its restart policy taken off first (else Docker starts it again at once). The base is checked before a run spec is recorded (reviewer2 F1), and on Docker an
#                image built now must carry a health check, so "none" is only ever an old image's
#                (reviewer2 B) (fleet_security_patching.md WP4, B10).
# Version: 1.4 - reviewer2 round 3: a builder working container is matched with its suffix too (buildah
#                names a second one -working-container-1); a site left down with no container keeps its
#                mark and environment file, so the next run (the Replace button) puts it back; a site
#                restored lifts a hold for being down.
# Version: 1.3 - reviewer2's re-check: a run stopped part way (TERM: a stop, a reboot) keeps its mark and its
#                environment file, so the next run completes it, as one killed does; the file is kept
#                under /etc/joinery/sites/SITE, which a reboot keeps (R1). A mark left on a site that
#                answers is cleared (R2). A rollback or a site left down holds the site itself
#                (held/SITE, with this run's reason), so a failed Replace shows its own reason.
# Version: 1.2 - reviewer2's WP8 review: refuses a site that does not answer before anything is built or
#                stopped (G1: a site already failing would roll back and hold); every call into the site
#                (exec, copy, health check) and the build are bounded (G2); the site's own scheduled
#                tasks count as busy, a short one waited out (G3); a failed tag of the new image rolls
#                back (G6); a stale environment file and working folder are removed; a site whose
#                replacement was killed part way (its mark left, stopped) is put back on its previous
#                image (G7); a package name may not end in - (apt reads it as remove, G11); quiet and
#                held are read again after the build (G14); a hold is the site's own
#                (site-refresh/held/SITE).
# Version: 1.1 - carries the release manifest the code matches (run_spec_code_manifest), not the site
#                root's as found: a site recreated from an older image had its older release's (B6)
# Version: 1.0
#
# Why: a site container's OS (PHP, PostgreSQL, Apache, openssl, libxml2, all
# from Ubuntu's archive) is the image's, and nothing inside it installs a fix
# (B7). Everything the site keeps is on volumes, so a container is patched by
# replacing it. site_refresh.sh does this nightly when Ubuntu ships a fix; this
# is the one site, now: the host agent's recreate_site word, or root by hand.
#
# Usage (root, on the container host):   recreate_site.sh SITE
#
# The order is build first: the new image is made while the site serves, and
# the site is down only for stop, remove and run. What is checked before
# anything stops, each a refusal that leaves the site exactly as it was: a run
# spec (the container is made from it and from nothing else; a site Docker made
# before run specs has its container's arguments recorded as one first, as
# install.sh site does, and is refused when they hold what a spec cannot carry);
# not quiet, not held, not being rebased; running, healthy and answering, and
# this site's own (a site that fails now could not be judged after the swap; an
# image built before the health check is judged by its answer); every volume its
# spec mounts present and its code volume populated; a base image here whose
# PostgreSQL opens the site's database; nothing busy inside (an upgrade, a
# backup, a restore, a scheduled task: a short one is waited out for up to two
# minutes); the image built and carrying the base's id; and, after the build,
# still not quiet, held, busy or failing.
#
# Every call into the site (each runs programs the tenant can write) and the
# build are bounded, so one site can cost the nightly run only its own slot:
# 60 s an exec or copy, 30 s a health check, 600 s the build.
#
# A replacement killed part way (the agent's timeout) leaves the site's mark
# and may leave it stopped, on a new image that does not answer, or with no
# container. Run again on such a site, this puts it back on its previous image,
# with no build: outcome "restored". An interrupted site that answers is
# replaced as any other.
#
# The new image is built from this tree alone (its Dockerfile.template, its
# start command and vhost template: the image is the OS layer, so it carries
# this release's start, as an install.sh rebuild does), plus two things from
# the running site: its signed release manifest (read out with the engine's
# copy, which resolves paths inside the container, so a link planted in it
# cannot point at the host), and the PHP extension packages its code declares,
# asked of its own resolver inside it and passed as a build argument. The build
# runs as the host's root, outside the site's id block, so it runs nothing the
# site could have written: no PHP, no Composer. The code is not in the image:
# the volumes hold it, and an image only fills a volume that is empty.
#
# While the swap runs the site is marked as being replaced
# (/etc/joinery/sites/SITE/replacing): the host report says so, and
# restart_container and hold_container refuse it.
#
# Exit status, and the last line of output (one JSON object):
#   0  replaced           the site runs on the new image (or restored: an
#                         interrupted replacement completed onto the previous image)
#   1  refused / failed   nothing was stopped; the site runs as it was
#   2  busy               an upgrade, backup or restore runs in it; nothing changed
#   3  rolled_back        the new container did not come up; the site runs again
#                         on its previous image (kept as joinery-SITE:previous;
#                         the image that failed as joinery-SITE:failed, until
#                         the next failure; it shares the base's layers)
#   4  down               neither the new nor the previous image came up
#
# RECREATE_WAIT_SECONDS (default 180) bounds the wait for each container to
# come up; RECREATE_BUILD_LOG names the build's log (default under
# /var/log/joinery/recreate/). A test, never root, runs it under
# JOINERY_SITE_STATE_ROOT, which stands in for / in every path it keeps (the
# run spec helper's rule).

set -uo pipefail

SITE="${1:-}"
OUTCOME="refused"
REASON=""
DOWN_SECONDS=""
NEW_BASE=""
NEW_IMAGE=""
started_at="$(date -u +%s)"

json_str() {
    local s="$1"
    s="${s//\\/\\\\}"; s="${s//\"/\\\"}"
    s="$(printf '%s' "$s" | tr '\n\t\r' '   ' | tr -d '\000-\037')"
    printf '"%s"' "${s:0:400}"
}
# An outcome reported: the run ended, and what it marked is its to clear.
result() {
    DONE=1
    printf '{"site":%s,"outcome":%s,"down_seconds":%s,"base_id":%s,"image_id":%s,"seconds":%s,"reason":%s}\n' \
        "$(json_str "${SITE:0:50}")" "$(json_str "$OUTCOME")" "${DOWN_SECONDS:-null}" \
        "$(json_str "$NEW_BASE")" "$(json_str "$NEW_IMAGE")" "$(( $(date -u +%s) - started_at ))" "$(json_str "$REASON")"
}
say() { echo "recreate_site: $*"; }
# A refusal before anything stopped: the site runs as it was.
refuse() {
    REASON="$1"
    echo "recreate_site: $1. Nothing was changed." >&2
    result
    exit "${2:-1}"
}

[[ "$SITE" =~ ^[a-z0-9][a-z0-9_-]{0,49}$ ]] || { SITE=""; refuse "usage: recreate_site.sh SITE (a site name, not '${1:0:60}')"; }
TROOT=""
if [ "$(id -u)" -ne 0 ]; then
    [ -n "${JOINERY_SITE_STATE_ROOT:-}" ] || refuse "run as root on the container host"
    TROOT="${JOINERY_SITE_STATE_ROOT%/}"
fi

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TOOLS="$(cd "${HERE}/../install_tools" 2>/dev/null && pwd)" || refuse "there is no install_tools beside this script's tree"
for f in install.sh _site_run_spec.sh _site_engine.sh Dockerfile.template _site_start.sh default_virtualhost.conf; do
    [ -f "${TOOLS}/${f}" ] || refuse "there is no ${f} in ${TOOLS}"
done
# install.sh, for its functions and BASE_IMAGE_VERSION: sourced, it returns
# before its dispatcher. It turns on errexit, which this script does not use.
# shellcheck source=../install_tools/install.sh
. "${TOOLS}/install.sh" || refuse "install.sh could not be loaded"
set +e
# shellcheck source=../install_tools/_site_run_spec.sh
. "${TOOLS}/_site_run_spec.sh" || refuse "_site_run_spec.sh could not be loaded"
QUIET_MODE=1

ENGINE="$(site_engine_bin)" || refuse "this machine's container engine could not be named"
command -v "$ENGINE" > /dev/null 2>&1 || refuse "${ENGINE} is not installed here"
ETC="${TROOT}/etc"
RUN_DIR="${TROOT}/run/joinery"
SITE_ETC="${ETC}/joinery/sites/${SITE}"
REPLACING="${SITE_ETC}/replacing"
HOLD_FILE="${TROOT}/var/lib/joinery/site-refresh/held/${SITE}"
WAIT_SECONDS="${RECREATE_WAIT_SECONDS:-180}"
[[ "$WAIT_SECONDS" =~ ^[0-9]{1,4}$ ]] || WAIT_SECONDS=180

# One replacement of a site at a time; a second asks while the first holds.
# (Descriptor 8; never handed to a container: see run_on.)
mkdir -p "$RUN_DIR" && chmod 755 "$RUN_DIR"
exec 8>>"${RUN_DIR}/recreate.${SITE}.lock" || refuse "the lock ${RUN_DIR}/recreate.${SITE}.lock could not be opened"
# Waited for a little (RECREATE_LOCK_WAIT, 30 s): a run stopped by TERM ends at
# once, but the command it was in (a health check, a stop) holds the lock until
# it returns.
LOCK_WAIT="${RECREATE_LOCK_WAIT:-30}"
[[ "$LOCK_WAIT" =~ ^[0-9]{1,3}$ ]] || LOCK_WAIT=30
flock -w "$LOCK_WAIT" 8 || refuse "another replacement of ${SITE} is under way"

# Bounded calls into the site: each runs programs the tenant can write.
TMO="timeout --kill-after=10"
in_site() {  # SECONDS ARGS...: the engine, bounded
    local t="$1"; shift
    $TMO "$t" "$ENGINE" "$@"
}
# bounded SECONDS COMMAND...: a command, a shell function too, in a process
# group of its own, killed whole (TERM, then KILL ten seconds on) past
# SECONDS. Its status, or 124.
bounded() {
    local secs="$1" pid t=0; shift
    set -m
    "$@" &
    pid=$!
    set +m
    BOUNDED_PID="$pid"   # the exit cleanup stops its group, should this script be stopped first
    while kill -0 "$pid" 2> /dev/null; do
        if [ "$t" -ge $(( secs * 2 )) ]; then
            kill -TERM -- "-$pid" 2> /dev/null; sleep 10; kill -KILL -- "-$pid" 2> /dev/null
            wait "$pid" 2> /dev/null; BOUNDED_PID=""; return 124
        fi
        sleep 0.5; t=$(( t + 1 ))
    done
    BOUNDED_PID=""
    wait "$pid"
}

# --- what is checked before anything stops ----------------------------------
# A container Docker made before run specs has its arguments only in Docker's
# record of it, and is adopted below, once it is known to be the site's own. On
# the site engine every site was born from its spec.
ADOPT=0
if ! run_spec_exists "$SITE"; then
    if site_engine_is_podman || ! site_engine inspect "$SITE" > /dev/null 2>&1; then
        refuse "${SITE} has no run spec on this host ($(run_spec_path "$SITE")); a container is only ever made from one. Rebuild it once with install.sh site"
    fi
    ADOPT=1
fi
run_spec_quiet_refusal "$SITE" 2> /dev/null && refuse "${SITE} is quiet on this host (a dormant copy or a frozen source keeps its own state in its container)"
[ -e "${SITE_ETC}/held" ] && refuse "${SITE} is held stopped (hold_container); start it first"
case "$(grep '^stage=' "${TROOT}/root/rebase/${SITE}/state" 2>/dev/null | tail -1 | cut -d= -f2-)" in
    prepared|swapping|swapped) refuse "${SITE} is being rebased (/root/rebase/${SITE}/state); finish or roll back that move first" ;;
esac
# Under the site's own folder, root's alone, which a reboot keeps: a run
# stopped part way leaves it for the next run to complete with.
ENV_FILE="${SITE_ETC}/recreate.env"
mkdir -p "$SITE_ETC" && chmod 700 "$SITE_ETC" || refuse "${SITE_ETC} could not be made"
# A replacement killed part way (the agent's timeout) left its mark, and the
# site may be stopped, on a new image that does not answer, or (killed between
# remove and run) without a container: then the interrupted run's environment
# file is what is left of it.
COMPLETE=0
INTERRUPTED=0
if [ -e "$REPLACING" ] && site_engine image inspect "joinery-${SITE}:previous" > /dev/null 2>&1; then
    INTERRUPTED=1
fi
if site_engine inspect "$SITE" > /dev/null 2>&1; then
    CONTAINER_ENV="$(site_engine inspect -f '{{range .Config.Env}}{{println .}}{{end}}' "$SITE" 2>/dev/null)"
    # What a killed run left: its environment file (the database password)
    # and its working folder. The container's own environment is read again.
    rm -f "$ENV_FILE" "${RUN_DIR}/recreate.${SITE}.env"
    rm -rf "${TROOT}/var/tmp/joinery-recreate-${SITE}."*
elif [ "$INTERRUPTED" -eq 1 ] && [ -f "$ENV_FILE" ] && [ ! -L "$ENV_FILE" ]; then
    CONTAINER_ENV="$(cat "$ENV_FILE")"
    COMPLETE=1
else
    refuse "${SITE} has no container here, and no interrupted replacement left what to run it again with; install.sh site makes it again from its run spec and volumes"
fi
[ "$(sed -n 's/^SITENAME=//p' <<< "$CONTAINER_ENV" | head -1)" = "$SITE" ] \
    || refuse "the container ${SITE} is not that site's own (its SITENAME is not ${SITE})"
DOMAIN="$(sed -n 's/^DOMAIN_NAME=//p' <<< "$CONTAINER_ENV" | head -1)"
[ -n "$DOMAIN" ] || refuse "the container ${SITE} names no DOMAIN_NAME"
# The step install.sh site takes before it rebuilds such a site: its container's
# arguments are written as its run spec, and anything a spec cannot carry is
# refused by name, never dropped (run_spec_adopt).
if [ "$ADOPT" -eq 1 ]; then
    # The base first: a site adopted and then refused for want of one would
    # have had a run spec written by a run that says nothing was changed
    # (reviewer2 F1).
    base_image_id > /dev/null || refuse "there is no joinery-base:${BASE_IMAGE_VERSION} on this host (install.sh build-base makes it)"
    ADOPT_ERR="$(run_spec_adopt "$SITE" 2>&1 > /dev/null)" \
        || refuse "${SITE} has no run spec, and its container could not be recorded as one: ${ADOPT_ERR#run spec: }"
    say "recorded ${SITE}'s run spec from its container, which Docker made before run specs"
fi

while IFS= read -r v; do
    v="${v%%:*}"
    site_engine volume inspect "$v" > /dev/null 2>&1 \
        || refuse "the volume ${v} ${SITE}'s run spec mounts does not exist; a run would make it empty"
done < <(run_spec_list "$SITE" volume)
code_volume_is_populated "$SITE" || refuse "${SITE}'s code volume holds no release; this image carries no code to give it"

# Every argument the container is run with, checked now: run_spec_args prints
# nothing at all on any refusal.
RUN_ARGS=()
mapfile -d '' RUN_ARGS < <(run_spec_args "$SITE")
[ "${#RUN_ARGS[@]}" -gt 0 ] || refuse "${SITE}'s run spec gave no arguments (run_spec_args refused it; see above)"
WEB_PORT=""
while IFS= read -r p; do
    if [[ "$p" =~ ^(127\.0\.0\.1:|0\.0\.0\.0:)?([0-9]{1,5}):80(/tcp)?$ ]]; then WEB_PORT="${BASH_REMATCH[2]}"; fi
done < <(run_spec_list "$SITE" publish)
[ -n "$WEB_PORT" ] || refuse "${SITE}'s run spec publishes no web port"

# answers: the site's own port gives a page (below 500), as its host's proxy reaches it.
answers() {
    local code
    code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 3 -H "Host: ${DOMAIN}" "http://127.0.0.1:${WEB_PORT}/" 2>/dev/null)"
    [[ "$code" =~ ^[1-4][0-9][0-9]$ ]]
}
# healthy: the container runs, answers, and its own health check passes. The
# first answer's time is kept (T_ANSWER): the seconds down end there.
healthy() {
    [ "$(site_engine inspect -f '{{.State.Running}}' "$SITE" 2>/dev/null)" = "true" ] || return 1
    answers || return 1
    [ -n "${T_ANSWER:-}" ] || T_ANSWER="$(date -u +%s.%N)"
    if site_engine_is_podman; then
        in_site 30 healthcheck run "$SITE" > /dev/null 2>&1 || return 1
    else
        # none: an image built before the health check (a site Docker made
        # then, and its previous image on a rollback) has no check of its own,
        # and its answer is its health. Every image built now has one.
        case "$(site_engine inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$SITE" 2>/dev/null)" in
            healthy|none) ;;
            *) return 1 ;;
        esac
    fi
}
down_seconds() {
    awk -v a="$t_stop" -v b="${T_ANSWER:-$(date -u +%s.%N)}" 'BEGIN { printf "%.1f", b - a }'
}
# wait_up: 0 once healthy, 1 at the bound or as soon as the container stops.
wait_up() {
    local deadline=$(( $(date -u +%s) + WAIT_SECONDS )) state
    while [ "$(date -u +%s)" -lt "$deadline" ]; do
        healthy && return 0
        state="$(site_engine inspect -f '{{.State.Status}}' "$SITE" 2>/dev/null)"
        case "$state" in exited|stopped|dead) return 1 ;; esac
        sleep 2
    done
    return 1
}
# Stopped and removed; 0 only when no container of the name is left. A healthy
# site gets 60 s to stop cleanly; one that already failed (GRACE 20) less:
# its start may be stuck where nothing answers a stop.
remove_container() {
    local grace="${1:-60}"
    site_engine inspect "$SITE" > /dev/null 2>&1 || return 0
    # A container made before the joinery start command (docker-prod's, from
    # 2026-09) runs a shell as its first process, which a stop's TERM does not
    # end: the stop waited out its grace and killed everything, PostgreSQL
    # mid-write included. Its database is stopped from inside first, cleanly,
    # then its web server, the shell's last command, so the shell ends; its
    # restart policy is taken off first, or Docker would start it again the
    # moment it ended (box proof: it did, and the stop then killed that one).
    # A container that then cannot be removed keeps restart=no: the swap's
    # caller starts it again and puts the policy back; the interrupted restore
    # and the rollback leave it down and held, which no policy would change.
    if ! site_engine_is_podman \
            && [ "$(site_engine inspect -f '{{index .Config.Cmd 1}}' "$SITE" 2>/dev/null)" != "/usr/local/sbin/joinery-site-start" ] \
            && [ "$(site_engine inspect -f '{{.State.Running}}' "$SITE" 2>/dev/null)" = "true" ]; then
        $TMO 60 "$ENGINE" update --restart=no "$SITE" > /dev/null 2>&1 && RESTART_OFF=1
        in_site 90 exec "$SITE" service postgresql stop > /dev/null 2>&1 < /dev/null
        in_site 30 exec "$SITE" apache2ctl -k stop > /dev/null 2>&1 < /dev/null
    fi
    $TMO $(( grace + 60 )) "$ENGINE" stop -t "$grace" "$SITE" > /dev/null 2>&1
    $TMO 60 "$ENGINE" rm -f "$SITE" > /dev/null 2>&1
    ! site_engine inspect "$SITE" > /dev/null 2>&1
}
# The lock's descriptor is closed for what the engine starts: its container
# monitor (conmon) would inherit it and hold the lock for the container's life.
run_on() {  # IMAGE
    site_engine run -d "${RUN_ARGS[@]}" --env-file "$ENV_FILE" "$1" > /dev/null 8>&-
}
mark() {
    mkdir -p "$SITE_ETC" && date -u +%Y-%m-%dT%H:%M:%SZ > "$REPLACING" && MARKED=1
}
# The mark and the environment file go once the run has reported, or when it
# never marked: a run stopped in the swap leaves both for the next to complete,
# and so does one that left the site down with no container, so that the next
# run (the Replace button) can run it again on its previous image.
cleanup() {
    [ -n "${BOUNDED_PID:-}" ] && kill -TERM -- "-${BOUNDED_PID}" 2> /dev/null
    [ -n "${WORK:-}" ] && rm -rf "${WORK:?}"
    if [ "${OUTCOME:-}" = down ] && ! site_engine inspect "$SITE" > /dev/null 2>&1; then
        return 0
    fi
    if [ "${MARKED:-0}" -eq 0 ] || [ "${DONE:-0}" -eq 1 ]; then
        rm -f "$ENV_FILE"
        [ "${MARKED:-0}" -eq 1 ] && rm -f "$REPLACING"
    fi
}
# A rollback, or a site left down, holds the site: site_refresh replaces it
# again only on a new base (a down one only once it is replaced), a
# successful replacement, or clear.
hold_self() {  # OUTCOME
    local d="${TROOT}/var/lib/joinery/site-refresh/held"
    mkdir -p "$d" && chmod 755 "$d" \
        && { printf 'outcome=%s\n' "$1"; printf 'reason=%s\n' "$(printf '%s' "$REASON" | tr '\n\t' '  ' | tr -cd "A-Za-z0-9 ,.:;/()_'=+@-" | cut -c1-300)"
             printf 'at=%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"; printf 'base=%s\n' "${NEW_BASE:0:12}"; } > "${d}/.${SITE}.new" \
        && chmod 644 "${d}/.${SITE}.new" && mv -f "${d}/.${SITE}.new" "${d}/${SITE}"
}
trap cleanup EXIT

# An interrupted replacement whose site does not run and answer now is
# completed onto the previous image. One that answers is the site as it is.
if [ "$COMPLETE" -eq 0 ] && [ "$INTERRUPTED" -eq 1 ]; then
    if healthy; then
        # The interrupted run got as far as a site that answers: its mark goes.
        rm -f "$REPLACING" && say "${SITE}'s interrupted replacement had come up; its mark is cleared"
        INTERRUPTED=0
    else
        COMPLETE=1
    fi
fi
T_ANSWER=""
if [ "$COMPLETE" -eq 0 ] && [ "$(site_engine inspect -f '{{.State.Running}}' "$SITE" 2>/dev/null)" != "true" ]; then
    refuse "${SITE} is not running; a replacement keeps a running site running, so start it first"
fi

# --- an interrupted replacement, completed onto the previous image -----------
if [ "$COMPLETE" -eq 1 ]; then
    say "${SITE}'s replacement was interrupted (${REPLACING#"$TROOT"}) and it does not answer: putting it back on its previous image"
    if site_engine inspect "$SITE" > /dev/null 2>&1; then
        run_spec_save_env "$SITE" "$ENV_FILE" || refuse "${SITE}'s environment could not be kept for its container"
    fi
    mark || refuse "the mark ${REPLACING} could not be written"
    t_stop="$(date -u +%s.%N)"
    remove_container 20 || { OUTCOME="down"; REASON="the container left by the interrupted replacement could not be removed: ${SITE} is down"; echo "recreate_site: ${REASON}" >&2; hold_self down; result; exit 4; }
    site_engine tag "joinery-${SITE}:previous" "joinery-${SITE}" \
        || { OUTCOME="down"; REASON="its previous image could not be tagged to run: ${SITE} is down"; echo "recreate_site: ${REASON}" >&2; hold_self down; result; exit 4; }
    if run_on "joinery-${SITE}" && wait_up; then
        DOWN_SECONDS="$(down_seconds)"; OUTCOME="restored"
        REASON="the interrupted replacement was completed: ${SITE} runs on its previous image"
        # A hold for a site left down goes, now that it answers again.
        [ "$(sed -n 's/^outcome=//p' "$HOLD_FILE" 2> /dev/null)" = down ] && rm -f "$HOLD_FILE"
        say "${REASON}"; result; exit 0
    fi
    OUTCOME="down"; REASON="its previous image did not come up: ${SITE} is down"
    echo "recreate_site: ${REASON}" >&2; hold_self down; result; exit 4
fi

# A site that fails now cannot be judged after a swap: its new container would
# be rolled back for what the site does, not what the image does.
healthy || refuse "${SITE} does not answer now (its health check, or a page below 500 on port ${WEB_PORT}), so a replacement could not be judged"
T_ANSWER=""
OLD_IMAGE="$(site_engine inspect -f '{{.Image}}' "$SITE" 2>/dev/null)"
OLD_IMAGE="${OLD_IMAGE#sha256:}"
[[ "$OLD_IMAGE" =~ ^[0-9a-f]{64}$ ]] || refuse "the image ${SITE} runs could not be read"

NEW_BASE="$(base_image_id)" || refuse "there is no joinery-base:${BASE_IMAGE_VERSION} on this host (install.sh build-base makes it)"
HAVE_MAJORS="$(site_database_majors "$SITE")"
if [ -n "$HAVE_MAJORS" ]; then
    WANT_MAJOR="$(image_postgres_major "joinery-base:${BASE_IMAGE_VERSION}")"
    [ -n "$WANT_MAJOR" ] || refuse "the PostgreSQL version in joinery-base:${BASE_IMAGE_VERSION} could not be read"
    case " ${HAVE_MAJORS} " in
        *" ${WANT_MAJOR} "*) ;;
        *) refuse "${SITE}'s database is PostgreSQL ${HAVE_MAJORS} and joinery-base:${BASE_IMAGE_VERSION} carries ${WANT_MAJOR}; move it with rebase_site_container.sh" ;;
    esac
fi

# Busy: anything a stop would cut off part way. An upgrade replaces the code
# tree file by file; a backup or restore writes for minutes.
# A site's own scheduled tasks run inside process_scheduled_tasks.php (its
# backups among them, in-process); a tick is usually over in seconds, so a busy
# site is asked again every five seconds for up to BUSY_WAIT_SECONDS (120).
BUSY_PATTERN='utils/(upgrade|run_backup|restore_objects|upload_backup|download_backup|verify_backup|process_scheduled_tasks)\.php|sysadmin_tools/(backup_[a-z_]+|restore_[a-z_]+|copy_database)\.sh|(^|/)pg_(dump|dumpall|restore|basebackup)( |$)'
BUSY_WAIT="${BUSY_WAIT_SECONDS:-120}"
[[ "$BUSY_WAIT" =~ ^[0-9]{1,4}$ ]] || BUSY_WAIT=120
# Sets BUSY to the first such process; 1 when it cannot be told, which refuses.
busy_in_site() {
    local out rc
    out="$(in_site 30 exec "$SITE" pgrep -af -- "$BUSY_PATTERN" 2> /dev/null)"; rc=$?
    case "$rc" in
        0) BUSY="$(head -1 <<< "$out")"; [ -n "$BUSY" ] || BUSY="(a process pgrep did not name)" ;;
        1) BUSY="" ;;
        *) return 1 ;;
    esac
}
# 0 not busy; 2 busy past the wait; 1 cannot be told.
settle() {
    local deadline=$(( $(date -u +%s) + BUSY_WAIT ))
    while :; do
        busy_in_site || return 1
        [ -z "$BUSY" ] && return 0
        [ "$(date -u +%s)" -lt "$deadline" ] || return 2
        sleep 5
    done
}
settle; rc=$?
[ "$rc" -eq 1 ] && refuse "whether ${SITE} is busy could not be told (pgrep in it did not run)"
[ "$rc" -eq 2 ] && refuse "${SITE} is busy: ${BUSY:0:160}" 2

# --- the new image, built while the site serves -------------------------------
mkdir -p "${TROOT}/var/tmp"
WORK="$(mktemp -d "${TROOT}/var/tmp/joinery-recreate-${SITE}.XXXXXX")" || refuse "no working folder could be made under /var/tmp"

CTX="${WORK}/context"
mkdir -p "${CTX}/${SITE}" "${CTX}/maintenance_scripts/install_tools"
install -m 644 "${TOOLS}/Dockerfile.template" "${CTX}/Dockerfile" \
    && install -m 644 "${TOOLS}/_site_start.sh" "${TOOLS}/default_virtualhost.conf" "${CTX}/maintenance_scripts/install_tools/" \
    || refuse "this tree's Dockerfile.template, start command or vhost template could not be copied"
# from_container PATH DEST: one regular file out of the running site, never a
# link, at most a megabyte. 1 when there is none such.
from_container() {
    local dest="$2" name
    name="$(basename "$1")"
    rm -rf "${WORK}/cp" && mkdir -p "${WORK}/cp"
    in_site 60 cp "${SITE}:$1" "${WORK}/cp/" > /dev/null 2>&1 || return 1
    [ -f "${WORK}/cp/${name}" ] && [ ! -L "${WORK}/cp/${name}" ] || return 1
    [ "$(stat -c %s "${WORK}/cp/${name}")" -le 1048576 ] || return 1
    install -m 644 "${WORK}/cp/${name}" "$dest"
}
SR="/var/www/html/${SITE}"
# The manifest the code matches, the site root's or the copy its config keeps;
# the site root's as found when the code matches neither (an upgrade realigns them).
# (Bounded: it checks the code against a manifest the site keeps.)
MF_DIR="$(bounded 120 run_spec_code_manifest "$SITE")"
if [ -z "$MF_DIR" ]; then
    MF_DIR="$SR"
    say "WARNING: ${SITE}'s code matches neither its release manifest nor the copy its config keeps; an upgrade realigns them"
fi
if from_container "${MF_DIR}/RELEASE_MANIFEST" "${CTX}/${SITE}/RELEASE_MANIFEST" \
        && from_container "${MF_DIR}/RELEASE_MANIFEST.sig" "${CTX}/${SITE}/RELEASE_MANIFEST.sig"; then
    if [ "$MF_DIR" = "$SR" ]; then say "carrying ${SITE}'s signed release manifest"
    else say "carrying the signed release manifest ${SITE}'s config keeps: the one at its root is another release's"; fi
else
    rm -f "${CTX}/${SITE}/RELEASE_MANIFEST" "${CTX}/${SITE}/RELEASE_MANIFEST.sig"
    say "WARNING: ${SITE} has no signed release manifest at its root; its start puts back the copy its config keeps"
fi

# The extension packages its code declares, from its own resolver inside it.
DECLARED=""
# A name may not end in -: apt reads "NAME-" as remove NAME.
if DECL_OUT="$(in_site 60 exec "$SITE" php "${SR}/public_html/utils/list_dependencies.php" --apt 2> /dev/null | head -c 65536)"; then
    n=0
    for spec in $DECL_OUT; do
        n=$((n + 1))
        if [ "$n" -gt 100 ] || ! [[ "$spec" =~ ^[a-z0-9]([a-z0-9.+-]{0,61}[a-z0-9+])?(\|[a-z0-9]([a-z0-9.+-]{0,61}[a-z0-9+])?)?$ ]]; then
            refuse "${SITE}'s declared packages include '${spec:0:80}', which is not a package name"
        fi
        DECLARED="${DECLARED:+${DECLARED} }${spec}"
    done
else
    say "WARNING: ${SITE}'s list_dependencies.php did not answer; its start installs what its code declares"
fi

LOG_DIR="${TROOT}/var/log/joinery/recreate"
mkdir -p "$LOG_DIR" && chmod 700 "$LOG_DIR"
BUILD_LOG="${RECREATE_BUILD_LOG:-${LOG_DIR}/${SITE}.build.log}"
mkdir -p "$(dirname "$BUILD_LOG")" && : > "$BUILD_LOG" || refuse "the build's log ${BUILD_LOG} could not be written"
say "building ${SITE}'s image on joinery-base:${BASE_IMAGE_VERSION} (${NEW_BASE:0:12})${DECLARED:+, with ${DECLARED}}"
QUIET_MODE=0
# At low priority, in a subshell of its own: the sites here keep serving
# through the build, and the container run later starts at normal priority.
# Bounded (RECREATE_BUILD_SECONDS, 600): its apt steps reach the network.
BUILD_SECONDS="${RECREATE_BUILD_SECONDS:-600}"
[[ "$BUILD_SECONDS" =~ ^[0-9]{1,5}$ ]] || BUILD_SECONDS=600
# The builder's working containers (Podman: in its storage, outside its own
# list). A build stopped at its bound leaves its own behind, which no prune
# takes; only those this build made are removed, never another build's.
working_containers() {
    site_engine_is_podman || return 0
    "$ENGINE" ps -a --external --format '{{.ID}} {{.Names}}' 2> /dev/null | awk '$2 ~ /-working-container(-[0-9a-z]+)?$/ { print $1 }' | sort
}
WC_BEFORE="$(working_containers)"
drop_build_leftovers() {
    local id
    site_engine_is_podman || return 0
    for id in $(comm -13 <(printf '%s\n' "$WC_BEFORE") <(working_containers)); do
        $TMO 60 "$ENGINE" rm -f --storage "$id" > /dev/null 2>&1
    done
}
low_build() {
    renice -n 10 -p "$BASHPID" > /dev/null 2>&1; ionice -c 2 -n 7 -p "$BASHPID" > /dev/null 2>&1
    build_site_image "$@"
}
if ! bounded "$BUILD_SECONDS" low_build "$SITE" "$DOMAIN" "$CTX" "joinery-${SITE}:next" \
        --build-arg "JOINERY_DECLARED_APT=${DECLARED}" > "$BUILD_LOG" 2>&1; then
    tail -n 15 "$BUILD_LOG" | sed 's/^/  build: /'
    drop_build_leftovers
    site_engine rmi "joinery-${SITE}:next" > /dev/null 2>&1
    refuse "${SITE}'s image did not build (log: ${BUILD_LOG})"
fi
QUIET_MODE=1
LABEL="$(site_engine image inspect -f '{{index .Config.Labels "joinery.base_id"}}' "joinery-${SITE}:next" 2>/dev/null)"
[ "$LABEL" = "$NEW_BASE" ] || { site_engine rmi "joinery-${SITE}:next" > /dev/null 2>&1; refuse "the image built for ${SITE} does not carry the base it was built on (label '${LABEL:0:16}', base ${NEW_BASE:0:12}): the base changed during the build"; }
# On Docker a container whose image has no health check reads "none", which
# healthy() takes from an image built before the check: an image built now
# must carry one, so a new container can never pass on its answer alone.
if ! site_engine_is_podman; then
    HC="$(site_engine image inspect -f '{{if .Config.Healthcheck}}{{index .Config.Healthcheck.Test 0}}{{end}}' "joinery-${SITE}:next" 2>/dev/null)"
    case "$HC" in ""|NONE) site_engine rmi "joinery-${SITE}:next" > /dev/null 2>&1
        refuse "the image built for ${SITE} carries no health check: Dockerfile.template lost its HEALTHCHECK" ;; esac
fi
NEW_IMAGE="$(site_engine image inspect -f '{{.Id}}' "joinery-${SITE}:next" 2>/dev/null)"; NEW_IMAGE="${NEW_IMAGE#sha256:}"

drop_next() { site_engine rmi "joinery-${SITE}:next" > /dev/null 2>&1; }
run_spec_save_env "$SITE" "$ENV_FILE" || { drop_next; refuse "${SITE}'s environment could not be kept for the new container"; }
# What may have changed while the image built: quiet or held, busy, failing.
run_spec_quiet_refusal "$SITE" 2> /dev/null && { drop_next; refuse "${SITE} became quiet on this host while its image built"; }
[ -e "${SITE_ETC}/held" ] && { drop_next; refuse "${SITE} was held stopped while its image built"; }
settle; rc=$?
[ "$rc" -eq 1 ] && { drop_next; refuse "whether ${SITE} is busy could not be told"; }
[ "$rc" -eq 2 ] && { drop_next; refuse "${SITE} is busy: ${BUSY:0:160}" 2; }
healthy || { drop_next; refuse "${SITE} stopped answering while its image built, so a replacement could not be judged"; }
T_ANSWER=""

# --- the swap -----------------------------------------------------------------
mark || { drop_next; refuse "the mark ${REPLACING} could not be written"; }
site_engine tag "$OLD_IMAGE" "joinery-${SITE}:previous" \
    || { site_engine rmi "joinery-${SITE}:next" > /dev/null 2>&1; refuse "the running image could not be kept as joinery-${SITE}:previous"; }

say "replacing ${SITE}'s container"
t_stop="$(date -u +%s.%N)"
if ! remove_container; then
    REASON="the old container could not be removed"
    OUTCOME="refused"
    # Still there: started again as it was, with its restart policy back
    # (remove_container takes it off an old-start container).
    site_engine start "$SITE" > /dev/null 2>&1 8>&-
    [ "${RESTART_OFF:-0}" -eq 1 ] && site_engine update --restart="$(run_spec_get "$SITE" restart)" "$SITE" > /dev/null 2>&1
    echo "recreate_site: ${REASON}; it was started again on its own image" >&2
    site_engine rmi "joinery-${SITE}:next" > /dev/null 2>&1
    result; exit 1
fi
FAIL_WHY=""
if ! site_engine tag "joinery-${SITE}:next" "joinery-${SITE}"; then
    # The old image is still tagged to run: never report it as the new one.
    FAIL_WHY="the new image could not be tagged to run"
elif ! run_on "joinery-${SITE}"; then
    FAIL_WHY="the engine refused to run the new image"
elif ! wait_up; then
    NEW_STATE="$(site_engine inspect -f '{{.State.Status}}' "$SITE" 2>/dev/null || echo gone)"
    case "$NEW_STATE" in
        exited|stopped|dead|gone) FAIL_WHY="the new container stopped (${NEW_STATE}) before it was healthy and answering" ;;
        *) FAIL_WHY="the new container was not healthy and answering within ${WAIT_SECONDS} s (state ${NEW_STATE})" ;;
    esac
fi
if [ -z "$FAIL_WHY" ]; then
    drop_next
    DOWN_SECONDS="$(down_seconds)"
    OUTCOME="replaced"
    run_spec_limits_refresh
    # A hold this site's failure placed is lifted by its replacement.
    if [ -e "$HOLD_FILE" ]; then
        rm -f "$HOLD_FILE" && say "the nightly replacement's hold on ${SITE} is lifted"
    fi
    site_engine image prune -f --filter until=10m > /dev/null 2>&1
    say "${SITE} runs on joinery-base:${BASE_IMAGE_VERSION} (${NEW_BASE:0:12}); ${DOWN_SECONDS} s without an answer"
    result; exit 0
fi

# --- back onto the previous image ---------------------------------------------
echo "recreate_site: ${FAIL_WHY}; its last output:" >&2
site_engine logs --tail 30 "$SITE" 2>&1 | sed 's/^/  new: /' | tee "${LOG_DIR}/${SITE}.failed.log" >&2
site_engine image inspect "joinery-${SITE}:next" > /dev/null 2>&1 \
    && site_engine tag "joinery-${SITE}:next" "joinery-${SITE}:failed" > /dev/null 2>&1 && drop_next
[ "$(site_engine image inspect -f '{{.Id}}' "joinery-${SITE}" 2>/dev/null | sed 's/^sha256://')" = "$NEW_IMAGE" ] \
    && site_engine tag "joinery-${SITE}" "joinery-${SITE}:failed" > /dev/null 2>&1
if ! remove_container 20; then
    OUTCOME="down"
    REASON="${FAIL_WHY}; and its new container could not be removed to put the previous image back: ${SITE} is down"
    echo "recreate_site: ${REASON}" >&2; hold_self down; result; exit 4
fi
site_engine tag "joinery-${SITE}:previous" "joinery-${SITE}"
T_ANSWER=""
if run_on "joinery-${SITE}" && wait_up; then
    DOWN_SECONDS="$(down_seconds)"
    OUTCOME="rolled_back"
    REASON="${FAIL_WHY}; ${SITE} runs again on its previous image"
    echo "recreate_site: ${REASON} (${DOWN_SECONDS} s without an answer)" >&2
    hold_self rolled_back; result; exit 3
fi
OUTCOME="down"
REASON="${FAIL_WHY}; and the previous image did not come up either: ${SITE} is down"
echo "recreate_site: ${REASON}" >&2
hold_self down; result; exit 4
