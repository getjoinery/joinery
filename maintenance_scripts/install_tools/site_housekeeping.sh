#!/usr/bin/env bash
#
# site_housekeeping.sh - the site's own files outside its tree: its log
# rotation and, on bare metal, its scheduled-task cron entry.
#
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
#     logs (logrotate refuses a log named twice);
#   - /etc/cron.d/joinery-{site}, the every-minute scheduled-task runner,
#     written when ABSENT - never inside a container, where the container's
#     start command owns the cron entry (/etc/cron.d does not survive a
#     rebuild), and never with --no-cron.
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
if [[ -e "${LOGROTATE}" ]]; then
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

[[ "${FAILED}" == 1 ]] && exit 1
exit 0
