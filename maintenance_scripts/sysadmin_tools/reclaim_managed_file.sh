#!/usr/bin/env bash
#
# reclaim_managed_file.sh - put one host file back to the platform's own
# definition: move it aside to a dated copy, run the installer that owns it,
# and report what the installer said.
#
# Version: 1.1 - review 2026-09-23: a machine with no site is known by its
#                missing site config, as the runner knows it (B6); the dated
#                copies live under /var/lib/joinery/reclaimed, which no
#                service reads - a copy beside a logrotate file was read by
#                logrotate as a duplicate entry (B11); a vhost is rebuilt from
#                its copy only through a one-shot marker this run leaves for
#                render_vhost.sh (B9); the move waits for the runner lock (B10);
#                a removed jail.local restarts fail2ban (B8); copies older than
#                180 days are pruned.
# Version: 1.0 - the reclaim_managed_file operate word of
#                specs/agent_recipes_and_vocabulary.md ("Host files: what may
#                be read and what may be reset"). The agent runs this file,
#                verified against the release manifest, with one argv element
#                it has already validated.
#
# THE CONTRACT, which tests/integration/reclaim_managed_file_gate.sh pins:
#
#   - ONE argument, closed: a name from the resettable list below (a mirror of
#     reclaimFiles in the agent's operate_reclaim_managed_file.go). Only a file
#     a re-runnable installer writes is on it; apache2.conf, the certbot
#     vhost, the mail files, the security settings, the apt files and
#     docker's daemon.json never are (owner, 2026-09-23).
#   - Nothing is deleted. The file is moved to a dated copy under
#     /var/lib/joinery/reclaimed (root-only, read by no service), and when the
#     owning installer does not write the file again (it does not own it on
#     this machine) the copy is moved back and the transcript says so.
#     fail2ban's jail.local is the one file whose reset IS its removal: the
#     drop-ins under jail.d carry our jails, and fail2ban is restarted.
#   - The move happens only once the host runner's lock is free, so no
#     converge is mid-way while the file is absent; the owning installer then
#     runs through the runner (--only=) with every check a converge applies.
#   - Exit 2 for a refused argument; otherwise 0, whatever the installer did:
#     the caller reads the transcript.
#
# Runs on: a site (every name), or a machine with no site (the host files
# only, through the runner's --machine mode).

set -u
export LC_ALL=C

TOOL_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SITE_ROOT="$(cd "${TOOL_DIR}/../.." && pwd)"
RUNNER="${SITE_ROOT}/maintenance_scripts/install_tools/_plugin_installers_start.sh"
SITENAME="$(basename "${SITE_ROOT}")"
# A site has its config; the support bundle a machine runs from has none,
# though it ships a public_html directory of its own.
MACHINE=0
[[ -f "${SITE_ROOT}/config/Globalvars_site.php" ]] || MACHINE=1

NAME="${1:-}"

# A gate points /etc (and /var/lib) at a fixture; only an unprivileged run may.
FS_ROOT=""
if [[ "$(id -u)" != "0" && -n "${JOINERY_RECLAIM_ROOT:-}" ]]; then
    FS_ROOT="${JOINERY_RECLAIM_ROOT%/}"
fi
RECLAIM_DIR="${FS_ROOT}/var/lib/joinery/reclaimed"
PRUNE_DAYS=180

refuse() {
    printf 'reclaim_managed_file: %s\n' "$1" >&2
    exit 2
}

# The highest PHP version with an FPM directory: the one the host serves.
php_fpm_ini() {
    local best="" d v
    for d in "${FS_ROOT}"/etc/php/*/fpm; do
        [[ -d "${d}" ]] || continue
        v="$(basename "$(dirname "${d}")")"
        [[ "${v}" =~ ^[0-9]+\.[0-9]+$ ]] || continue
        if [[ -z "${best}" ]] || [[ "$(printf '%s\n%s\n' "${best}" "${v}" | sort -V | tail -1)" == "${v}" ]]; then
            best="${v}"
        fi
    done
    [[ -n "${best}" ]] && printf '%s/etc/php/%s/fpm/php.ini' "${FS_ROOT}" "${best}"
}

# THE RESETTABLE LIST: name -> path and owning installer. SITE marks a file
# that belongs to a site (not on a machine with no site).
SCOPE=HOST
case "${NAME}" in
    fail2ban_jail_local)     FILE="${FS_ROOT}/etc/fail2ban/jail.local";                          OWNER=host_housekeeping.sh ;;
    fail2ban_joinery_sshd)   FILE="${FS_ROOT}/etc/fail2ban/jail.d/joinery-sshd.local";           OWNER=host_housekeeping.sh ;;
    fail2ban_joinery_apache) FILE="${FS_ROOT}/etc/fail2ban/jail.d/joinery-apache.local";         OWNER=host_housekeeping.sh ;;
    apache_remoteip)         FILE="${FS_ROOT}/etc/apache2/conf-available/joinery-remoteip.conf"; OWNER=host_housekeeping.sh ;;
    apache_mpm_event)        FILE="${FS_ROOT}/etc/apache2/mods-available/mpm_event.conf";        OWNER=host_housekeeping.sh ;;
    journald_size_limit)     FILE="${FS_ROOT}/etc/systemd/journald.conf.d/size-limit.conf";      OWNER=host_housekeeping.sh ;;
    php_fpm_ini)             FILE="$(php_fpm_ini)";                                              OWNER=host_housekeeping.sh ;;
    apache_site)             FILE="${FS_ROOT}/etc/apache2/sites-available/${SITENAME}.conf";     OWNER=render_vhost.sh;      SCOPE=SITE ;;
    cron_agent)              FILE="${FS_ROOT}/etc/cron.d/joinery-agent";                         OWNER=install_agent.sh;     SCOPE=SITE ;;
    logrotate_site)          FILE="${FS_ROOT}/etc/logrotate.d/joinery-${SITENAME}";              OWNER=site_housekeeping.sh; SCOPE=SITE ;;
    cron_site)               FILE="${FS_ROOT}/etc/cron.d/joinery-${SITENAME}";                   OWNER=site_housekeeping.sh; SCOPE=SITE ;;
    *) refuse "${NAME:0:64} is not a file this node will reset" ;;
esac

if [[ "${MACHINE}" == 1 && "${SCOPE}" == SITE ]]; then
    refuse "${NAME} belongs to a site, and this machine has none"
fi
if [[ "${SCOPE}" == SITE && ! "${SITENAME}" =~ ^[A-Za-z0-9_-]+$ ]]; then
    refuse "this site's name is not one a path can be built from"
fi
[[ -n "${FILE}" ]] || refuse "${NAME}: this machine has no such file to reset"
[[ -f "${RUNNER}" ]] || refuse "the host runner is missing from ${RUNNER}"
[[ -L "${FILE}" ]] && refuse "${FILE} is a link; it is never moved"

# The dated copies: root-only, and under no directory a service includes.
mkdir -p "${RECLAIM_DIR}" && chmod 700 "${RECLAIM_DIR}" 2>/dev/null
find "${RECLAIM_DIR}" -maxdepth 1 -type f -name '*.reclaimed-*' -mtime +"${PRUNE_DAYS}" -delete 2>/dev/null || true

# The runner's own lock (the same path it computes): the move waits until no
# converge holds it, so the file is never absent under a run that began
# before it moved. Released before the runner starts, which takes it itself.
if [[ "$(id -u)" == "0" ]]; then
    LOCK_NAME="${SITENAME}"; [[ "${MACHINE}" == 1 ]] && LOCK_NAME=host
    LOCK_FILE="/run/joinery/host-installers.${LOCK_NAME}.lock"
    mkdir -p /run/joinery 2>/dev/null
else
    LOCK_FILE="${SITE_ROOT}/cache/host_installers.lock"
    mkdir -p "${SITE_ROOT}/cache" 2>/dev/null
fi

BACKUP=""
exec 8>>"${LOCK_FILE}" || refuse "cannot open the runner lock ${LOCK_FILE}"
if ! flock -w 600 8; then
    echo "reclaim: the host runner has held its lock for ten minutes - nothing moved; try again" >&2
    exit 0
fi
if [[ -f "${FILE}" ]]; then
    BACKUP="${RECLAIM_DIR}/$(printf '%s' "${FILE#${FS_ROOT}}" | sed 's|^/||; s|/|_|g').reclaimed-$(date -u +%Y%m%d%H%M%S)"
    if ! mv "${FILE}" "${BACKUP}"; then
        flock -u 8
        echo "reclaim: could not move ${FILE} aside - nothing changed" >&2
        exit 0
    fi
    echo "reclaim: moved ${FILE} to ${BACKUP}"
    # render_vhost.sh rebuilds a vhost from its copy only when this run says
    # so, once: a vhost that is simply absent (a site moved off the box) is
    # never brought back by an ordinary converge.
    if [[ "${NAME}" == apache_site ]]; then
        printf '%s\n' "${BACKUP}" > "${RECLAIM_DIR}/vhost-pending.${SITENAME}"
    fi
else
    echo "reclaim: ${FILE} is absent; running ${OWNER} to write it"
fi
flock -u 8
exec 8>&-

RUNNER_ARGS=("--only=${OWNER}")
[[ "${MACHINE}" == 1 ]] && RUNNER_ARGS=(--machine "--only=${OWNER}")
bash "${RUNNER}" "${RUNNER_ARGS[@]}"
rm -f "${RECLAIM_DIR}/vhost-pending.${SITENAME}"

# jail.local's reset is its removal, which fail2ban does not notice by
# itself: a hand-set policy stays in force until it restarts.
if [[ "${NAME}" == fail2ban_jail_local && -n "${BACKUP}" && -z "${FS_ROOT}" ]] && command -v systemctl >/dev/null 2>&1; then
    if systemctl restart fail2ban >/dev/null 2>&1 && systemctl is-active --quiet fail2ban; then
        echo "reclaim: fail2ban restarted without jail.local, and is active"
    else
        echo "reclaim: WARNING - fail2ban did not come back after jail.local was removed" >&2
    fi
fi

# The owner did not write it back: on this machine it is not the owner's to
# write (the agent's cron file on a systemd host, a php.ini with no template).
# Nothing is lost - the copy goes back where it was.
if [[ -n "${BACKUP}" && ! -e "${FILE}" && "${NAME}" != fail2ban_jail_local ]]; then
    if mv "${BACKUP}" "${FILE}"; then
        echo "reclaim: ${OWNER} did not write ${FILE}; the moved copy is back in place"
    else
        echo "reclaim: WARNING - ${OWNER} did not write ${FILE} and the copy at ${BACKUP} could not be moved back" >&2
    fi
elif [[ -n "${BACKUP}" ]]; then
    echo "reclaim: ${FILE} is the platform's again; the previous version is kept at ${BACKUP}"
fi
exit 0
