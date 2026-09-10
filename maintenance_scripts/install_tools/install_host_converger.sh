#!/usr/bin/env bash
#
# install_host_converger.sh - give this machine one root process that keeps
# its host converged to the tree deployed on it (specs/host_converger.md).
#
# Version: 1.0
#
# A self-hosted box upgrades from the browser as the web user, which lands the
# code and cannot do the root half of an upgrade: declared PHP extensions and
# the host installers (the agent artifact, the parser jail launcher, a
# plugin's services). This installs a root timer that runs the installers
# runner in its --when-changed mode every five minutes: nothing happens until
# the deployed release or the set of installers changes, then everything
# converges, and once a day regardless. Root acts on its own clock from a
# stamp it owns; nothing the web user does makes root run anything.
#
# systemd where PID 1 is systemd (a timer + oneshot service), a cron.d entry
# otherwise (a container, a minimal host) - the same rule install_agent.sh
# uses. The unit text lives here and nowhere else; this installer is itself
# one of the runner's core installers, so a damaged unit is rewritten on the
# next tick and a dead timer on a managed node is reinstalled by any
# run_plugin_installers or apply_update job.
#
# Contract (docs/plugin_developer_guide.md): idempotent, root, non-interactive,
# exit 0 when not applicable.
#
# Usage:  install_host_converger.sh [SITENAME] [SITE_ROOT]

set -u

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RUNNER="${SCRIPT_DIR}/_plugin_installers_start.sh"
UNIT_NAME="joinery-host-converger"
SERVICE_FILE="/etc/systemd/system/${UNIT_NAME}.service"
TIMER_FILE="/etc/systemd/system/${UNIT_NAME}.timer"
CRON_FILE="/etc/cron.d/${UNIT_NAME}"
INTERVAL_MIN=5

say() { echo "host converger: $*"; }

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

[[ "$(id -u)" == "0" ]] || { say "not root - skipping (run: sudo bash ${SCRIPT_DIR}/install_host_converger.sh)"; exit 0; }
[[ -f "${RUNNER}" ]] || { say "runner missing at ${RUNNER} - skipping" >&2; exit 0; }
[[ -f "${SITE_ROOT}/config/Globalvars_site.php" ]] || { say "site not initialised yet - skipping"; exit 0; }

LOG_FILE="${SITE_ROOT}/logs/host_converger.log"
mkdir -p "${SITE_ROOT}/logs" "${SITE_ROOT}/cache"
COMMAND="/bin/bash ${RUNNER} --when-changed ${SITENAME} ${SITE_ROOT}"

# Write a file only when its content changed, so an unchanged unit never
# moves a mtime or triggers a reload.
write_if_changed() {
    local path="$1" content="$2" mode="$3"
    if [[ -f "${path}" ]] && [[ "$(cat "${path}")" == "${content}" ]]; then
        return 1
    fi
    printf '%s\n' "${content}" > "${path}.tmp" && chmod "${mode}" "${path}.tmp" && mv -f "${path}.tmp" "${path}"
    return 0
}

if command -v systemctl >/dev/null 2>&1 && [[ -d /run/systemd/system ]]; then
    SERVICE_TEXT="[Unit]
Description=Joinery host converger for ${SITENAME}: run the host installers when the deployed release changes
Documentation=file://${SITE_ROOT}/public_html/specs/host_converger.md

[Service]
Type=oneshot
ExecStart=${COMMAND}
StandardOutput=append:${LOG_FILE}
StandardError=append:${LOG_FILE}
Nice=10"
    TIMER_TEXT="[Unit]
Description=Joinery host converger timer for ${SITENAME}

[Timer]
OnBootSec=2min
OnUnitActiveSec=${INTERVAL_MIN}min
Persistent=true
AccuracySec=30s

[Install]
WantedBy=timers.target"
    changed=0
    write_if_changed "${SERVICE_FILE}" "${SERVICE_TEXT}" 644 && changed=1
    write_if_changed "${TIMER_FILE}" "${TIMER_TEXT}" 644 && changed=1
    # A stale cron entry from a box that moved to systemd would run twice.
    [[ -f "${CRON_FILE}" ]] && rm -f "${CRON_FILE}"
    if [[ "${changed}" == "1" ]]; then
        systemctl daemon-reload
        say "wrote ${SERVICE_FILE} and ${TIMER_FILE}"
    fi
    if ! systemctl is-enabled --quiet "${UNIT_NAME}.timer" 2>/dev/null || ! systemctl is-active --quiet "${UNIT_NAME}.timer" 2>/dev/null; then
        systemctl enable --now "${UNIT_NAME}.timer" >/dev/null 2>&1 \
            && say "timer enabled (every ${INTERVAL_MIN} min, systemd)" \
            || { say "WARNING - could not enable ${UNIT_NAME}.timer" >&2; exit 0; }
    else
        say "timer already active (every ${INTERVAL_MIN} min, systemd)"
    fi
else
    CRON_TEXT="# Joinery host converger for ${SITENAME} (written by install_host_converger.sh; do not edit)
*/${INTERVAL_MIN} * * * * root ${COMMAND} >> ${LOG_FILE} 2>&1"
    if write_if_changed "${CRON_FILE}" "${CRON_TEXT}" 644; then
        say "wrote ${CRON_FILE} (every ${INTERVAL_MIN} min, cron)"
    else
        say "cron entry already current (every ${INTERVAL_MIN} min, cron)"
    fi
    if ! pgrep -x cron >/dev/null 2>&1 && ! pgrep -x crond >/dev/null 2>&1; then
        say "WARNING - no cron daemon is running; the converger will not tick until one does" >&2
    fi
fi

exit 0
