#!/usr/bin/env bash
#
# site_housekeeping.sh - the site's own files outside its tree: its log
# rotation, on bare metal its scheduled-task cron entry, and in a container
# its signed release manifest.
#
# Version: 1.2 - A logrotate file that is the platform's own earlier rendering, which ran as group
#                user1 (a group no new server has, so logrotate refused it and logrotate.service
#                failed), is rewritten from the template. Any other file is still never touched.
# Version: 1.1 - In a container, the release manifest at the site root is the
#                one the code matches: a container recreated from its image got
#                back the manifest the image was built with, and the agent then
#                refused every script. The copy upgrade.php keeps in
#                config/release_manifest/ is put back when the code matches it,
#                and refreshed from the site root when that is the one it
#                matches (specs/implemented/fleet_ubuntu_2604_postgres_upgrade.md B21).
# Version: 1.0 - specs/agent_recipes_and_vocabulary.md, "Host files": the two
#                files _site_init.sh wrote once move into a re-runnable core
#                installer, so install day and repair day run the same code.
#                _site_init.sh calls this; the host timer runs it on every
#                converge; reclaim_managed_file runs it after moving a file
#                aside.
#
# What it leaves behind:
#   - /etc/logrotate.d/joinery-{site}, rendered from logrotate_joinery.conf,
#     written when ABSENT and no other file there already rotates the site's
#     logs (logrotate refuses a log named twice), and rewritten when it is the
#     platform's earlier rendering that ran as group user1;
#   - /etc/cron.d/joinery-{site}, the every-minute scheduled-task runner,
#     written when ABSENT - never inside a container, where the container's
#     start command owns the cron entry (/etc/cron.d does not survive a
#     rebuild), and never with --no-cron.
#   - in a container, {site}/RELEASE_MANIFEST(.sig) and its kept copy in
#     {site}/config/release_manifest/ agree, and are the release the code
#     matches. Neither is ever replaced by one the code does not match.
#
# Absent-only, so an owner's edit survives every converge; moving a file
# aside (the agent's reclaim_managed_file, which keeps a dated copy) is how it
# is put back to the platform's definition.
#
# Contract (docs/plugin_developer_guide.md): idempotent, root,
# non-interactive, exit 0 when not applicable.
#
# Usage:  site_housekeeping.sh [--no-cron] [SITENAME] [SITE_ROOT]

set -u
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
say()  { echo "site housekeeping: $*"; }
warn() { echo "site housekeeping: WARNING - $*" >&2; }

NO_CRON=0
if [[ "${1:-}" == "--no-cron" ]]; then
    NO_CRON=1
    shift
fi
SITE_ROOT="${2:-}"
if [[ -z "${SITE_ROOT}" ]]; then
    SITENAME="${1:-}"
    if [[ -n "${SITENAME}" && -d "/var/www/html/${SITENAME}" ]]; then
        SITE_ROOT="/var/www/html/${SITENAME}"
    else
        SITE_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
    fi
fi
SITENAME="$(basename "${SITE_ROOT}")"
if [[ ! "${SITENAME}" =~ ^[A-Za-z0-9_-]+$ ]]; then
    warn "'${SITENAME}' is not a site name - skipping"
    exit 0
fi

# A test points /etc at a fixture; only an unprivileged run may.
FS_ROOT=""
if [[ "$(id -u)" == "0" ]]; then
    if [[ -n "${JOINERY_SITE_HOUSEKEEPING_ROOT:-}" ]]; then
        say "JOINERY_SITE_HOUSEKEEPING_ROOT is set but this is root - hook ignored"
    fi
elif [[ -n "${JOINERY_SITE_HOUSEKEEPING_ROOT:-}" ]]; then
    FS_ROOT="${JOINERY_SITE_HOUSEKEEPING_ROOT%/}"
    say "override mode: /etc is ${FS_ROOT}/etc"
else
    say "not root - skipping"
    exit 0
fi

FAILED=0

# --- log rotation -------------------------------------------------------------
TEMPLATE="${SCRIPT_DIR}/logrotate_joinery.conf"
LOGROTATE="${FS_ROOT}/etc/logrotate.d/joinery-${SITENAME}"
# Another file already rotating this site's logs (a box set up before the
# platform wrote its own) keeps doing so: logrotate refuses a log named in two
# files, and would skip both.
OTHER_ROTATION="$(grep -lF "${SITE_ROOT}/logs/" "$(dirname "${LOGROTATE}")"/* 2>/dev/null | grep -vxF "${LOGROTATE}" | head -1)"
# The earlier rendering: the template's directives with the group user1,
# comments and blank lines aside. Nobody chose that file, so it is not an
# owner's edit, and on a machine with no user1 group it fails every night.
directives() { grep -v -e '^[[:space:]]*#' -e '^[[:space:]]*$' "$@"; }
if [[ -f "${LOGROTATE}" && -f "${TEMPLATE}" ]] \
    && [[ "$(directives "${LOGROTATE}")" == "$(sed -e "s|{{SITE_ROOT}}|${SITE_ROOT}|g" \
            -e 's|^\([[:space:]]*su www-data\) www-data$|\1 user1|' "${TEMPLATE}" | directives)" ]] \
    && ! grep -q '^[[:space:]]*su www-data user1$' "${TEMPLATE}"; then
    sed "s|{{SITE_ROOT}}|${SITE_ROOT}|g" "${TEMPLATE}" > "${LOGROTATE}" && chmod 644 "${LOGROTATE}" \
        && say "rewrote ${LOGROTATE}: it was the earlier rendering, which ran as group user1" \
        || { warn "could not rewrite ${LOGROTATE}"; FAILED=1; }
elif [[ -e "${LOGROTATE}" ]]; then
    :
elif [[ -n "${OTHER_ROTATION}" ]]; then
    say "this site's logs are already rotated by ${OTHER_ROTATION} - not writing ${LOGROTATE}"
elif [[ ! -f "${TEMPLATE}" ]]; then
    warn "no logrotate template at ${TEMPLATE} - log rotation not written"
    FAILED=1
elif [[ -d "$(dirname "${LOGROTATE}")" ]]; then
    sed "s|{{SITE_ROOT}}|${SITE_ROOT}|g" "${TEMPLATE}" > "${LOGROTATE}" && chmod 644 "${LOGROTATE}" \
        && say "wrote ${LOGROTATE} (it was absent)" \
        || { warn "could not write ${LOGROTATE}"; FAILED=1; }
else
    say "no logrotate on this machine - rotation skipped"
fi

# --- scheduled tasks ----------------------------------------------------------
# Every minute: the tick is the floor on latency for every every_run task, and
# the runner holds a per-task lock, so a slow task is skipped, not doubled.
CRON="${FS_ROOT}/etc/cron.d/joinery-${SITENAME}"
if [[ "${NO_CRON}" == 1 || -f "${FS_ROOT}/.dockerenv" ]]; then
    : # the container's start command owns the cron entry
elif [[ -e "${CRON}" ]]; then
    :
elif [[ -d "$(dirname "${CRON}")" ]]; then
    printf '%s\n' "* * * * * www-data php ${SITE_ROOT}/public_html/utils/process_scheduled_tasks.php >> ${SITE_ROOT}/logs/cron_scheduled_tasks.log 2>&1" > "${CRON}" \
        && chmod 644 "${CRON}" && say "wrote ${CRON} (it was absent)" \
        || { warn "could not write ${CRON}"; FAILED=1; }
else
    say "no /etc/cron.d on this machine - cron entry skipped"
fi

# --- the release manifest (a container) ----------------------------------------
# The signed manifest the agent checks every script against sits at the site
# root, in the container's own layer; the code it describes is on volumes. A
# container recreated from its image gets back the manifest the image was built
# with, whatever release the code has been upgraded to since. upgrade.php keeps
# each release's copy on the config volume; whichever of the two the code
# matches is kept in both places. A pair that matches nothing is named once,
# not re-hashed on every converge.
MANIFEST="${SITE_ROOT}/RELEASE_MANIFEST"
HELD="${SITE_ROOT}/config/release_manifest"
manifest_matches() {   # $1 = a RELEASE_MANIFEST beside its .sig: does the code match it?
    [[ -f "$1" && -f "$1.sig" ]] && (cd "${SITE_ROOT}" && sha256sum -c --status "$1") 2>/dev/null
}
if [[ ! -f "${FS_ROOT}/.dockerenv" || ! -f "${MANIFEST}" || ! -d "${SITE_ROOT}/config" ]]; then
    :   # on disk nothing puts an older manifest back; a site with no manifest has none to keep
elif cmp -s "${MANIFEST}" "${HELD}/RELEASE_MANIFEST" && cmp -s "${MANIFEST}.sig" "${HELD}/RELEASE_MANIFEST.sig"; then
    :   # the two agree
else
    PAIR_KEY="$(cat "${MANIFEST}" "${HELD}/RELEASE_MANIFEST" "${SITE_ROOT}/public_html/VERSION" 2>/dev/null | sha256sum | cut -c1-64)"
    if [[ "$(cat "${HELD}/unmatched" 2>/dev/null)" == "${PAIR_KEY}" ]]; then
        :   # named already; nothing has changed since
    elif manifest_matches "${MANIFEST}"; then
        mkdir -p "${HELD}" && install -m 644 "${MANIFEST}" "${MANIFEST}.sig" "${HELD}/" && rm -f "${HELD}/unmatched" \
            && say "kept this release's manifest in config/release_manifest" \
            || { warn "could not keep the release manifest in ${HELD}"; FAILED=1; }
    elif manifest_matches "${HELD}/RELEASE_MANIFEST"; then
        install -m 644 "${HELD}/RELEASE_MANIFEST" "${HELD}/RELEASE_MANIFEST.sig" "${SITE_ROOT}/" && rm -f "${HELD}/unmatched" \
            && say "put back the release manifest the code matches; the site root held another release's" \
            || { warn "could not put the release manifest back at ${SITE_ROOT}"; FAILED=1; }
    else
        warn "the code matches neither the release manifest at the site root nor the kept copy; the agent refuses scripts until the next upgrade"
        mkdir -p "${HELD}" && printf '%s\n' "${PAIR_KEY}" > "${HELD}/unmatched"
    fi
fi

[[ "${FAILED}" == 1 ]] && exit 1
exit 0
