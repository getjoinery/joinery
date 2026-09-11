#!/usr/bin/env bash
#
# install_host_converger.sh - give this machine one root process that keeps
# its host converged to the tree deployed on it (specs/host_converger.md).
#
# Version: 1.2 - Refreshes the out-of-tree copy whenever it DIFFERS from the
#                tree's runner, not only when there is none. A copy placed once
#                and never replaced is a copy from whichever release happened to
#                install it: the first one resolved its tools against
#                /usr/local/sbin, found no installers, and could not reach the
#                runner that would have replaced it. The source must pass the
#                same trust check an installer gets (_tree_trust.sh).
# Version: 1.1 - One minute, not five, and a .path unit so a queued root request
#                is picked up in seconds rather than on the next tick. The
#                entry point is a root-owned copy at
#                /usr/local/sbin/joinery-host-converger rather than the runner
#                inside the tree: the timer runs as root, and a root timer whose
#                entry point sits in a directory the tree's owner can rewrite is
#                one edit away from being somebody else's root timer. The copy is
#                refreshed from the tree by the runner itself, AFTER the
#                ownership assertion has established whose the tree is - the same
#                reason the parser jail's launcher lives outside the tree
#                (specs/read_only_tree.md).
# Version: 1.0
#
# A self-hosted box upgrades from the browser as the web user, which lands the
# code and cannot do the root half of an upgrade: declared PHP extensions and
# the host installers (the agent artifact, the parser jail launcher, a
# plugin's services). This installs a root timer that runs the installers
# runner in its --when-changed mode every minute: nothing happens until the
# deployed release or the set of installers changes, then everything converges,
# and once a day regardless. Root acts on its own clock from a stamp it owns.
#
# The one thing the web side can ask for is a root request, and asking is all it
# does: a request names a kind from a fixed list and root decides what that kind
# means (specs/read_only_tree.md). A .path unit fires the same service when one
# is queued, so an operator watching a transcript sees it move in seconds.
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
PATH_FILE="/etc/systemd/system/${UNIT_NAME}.path"
CRON_FILE="/etc/cron.d/${UNIT_NAME}"
# The entry point the timer runs: a root-owned copy, outside the tree.
ENTRY="/usr/local/sbin/${UNIT_NAME}"
INTERVAL_MIN=1

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
mkdir -p "${SITE_ROOT}/logs" "${SITE_ROOT}/cache" "${SITE_ROOT}/cache/root_requests"
chown www-data:www-data "${SITE_ROOT}/cache/root_requests" 2>/dev/null || true
chmod 770 "${SITE_ROOT}/cache/root_requests" 2>/dev/null || true

# The root timer's entry point is a copy outside the tree, not the file in it.
# Whoever owns the tree can rewrite anything in it, and on a developer box that
# is not root - so pointing a root timer straight at the tree would make every
# tree write a root-execution primitive.
#
# The copy is refreshed whenever it differs from the tree's runner and the
# tree's runner is one this box would be willing to run (owned by the tree
# owner, writable by nobody else - the same check every installer gets). Placing
# it only when absent was not enough: a copy from an older release keeps running
# forever, and the first such copy resolved its tools against /usr/local/sbin,
# found no installers, and could not even reach the runner that would have
# replaced it.
#
# The runner refreshes the copy too, after its ownership assertion. Both do,
# deliberately: this installer is the path that works when the copy is too old
# to find anything, and the runner is the path that works when this installer
# is not the thing being run.
TRUST_HELPER="${SCRIPT_DIR}/_tree_trust.sh"
if [[ -f "${TRUST_HELPER}" ]]; then
    # shellcheck source=_tree_trust.sh
    . "${TRUST_HELPER}"
    # The record is the authority where there is one. Where there is not — a box
    # that has not been through the new fix_permissions.sh yet, which is every
    # box on the day this ships — fall back to whoever owns public_html, exactly
    # as the runner does. Without that this refuses to refresh anything on the
    # very boxes carrying the stale copy.
    if ! TREE_OWNER="$(joinery_tree_owner_record "${SITE_ROOT}")"; then
        TREE_OWNER="$(stat -c '%U' "${SITE_ROOT}/public_html" 2>/dev/null || echo root)"
        # ...but never www-data. The runner reads public_html only AFTER its
        # ownership assertion has taken the tree off the pool; this can be run
        # on its own, and on a tree the pool still owns "whoever owns
        # public_html" is the attacker. Root then refuses, which is the answer.
        [[ -z "${TREE_OWNER}" || "${TREE_OWNER}" == "www-data" ]] && TREE_OWNER="root"
    fi
else
    say "WARNING - _tree_trust.sh missing; not touching ${ENTRY}" >&2
    TREE_OWNER=""
fi

if [[ -n "${TREE_OWNER}" ]] && ! cmp -s "${RUNNER}" "${ENTRY}" 2>/dev/null; then
    if joinery_file_is_trusted "${RUNNER}" "${TREE_OWNER}"; then
        if install -o root -g root -m 755 "${RUNNER}" "${ENTRY}" 2>/dev/null; then
            say "installed ${ENTRY} from the deployed release"
        else
            say "WARNING - could not write ${ENTRY}" >&2
        fi
    else
        say "refusing to refresh ${ENTRY} from an untrusted ${RUNNER}" >&2
    fi
fi
# Fall back to the in-tree runner only where the copy could not be made; a timer
# that runs nothing is worse than one whose entry point is in the tree.
RUN_TARGET="${ENTRY}"
[[ -x "${ENTRY}" ]] || RUN_TARGET="${RUNNER}"

COMMAND="/bin/bash ${RUN_TARGET} --when-changed ${SITENAME} ${SITE_ROOT}"

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
    # A queued root request should not wait for the next tick: an operator who
    # pressed Upgrade is watching a transcript. PathChanged fires the same
    # oneshot service the moment anything lands in the queue directory.
    PATH_TEXT="[Unit]
Description=Joinery host converger path trigger for ${SITENAME}: carry out a queued root request at once

[Path]
PathChanged=${SITE_ROOT}/cache/root_requests
Unit=${UNIT_NAME}.service

[Install]
WantedBy=paths.target"
    changed=0
    write_if_changed "${SERVICE_FILE}" "${SERVICE_TEXT}" 644 && changed=1
    write_if_changed "${TIMER_FILE}" "${TIMER_TEXT}" 644 && changed=1
    write_if_changed "${PATH_FILE}" "${PATH_TEXT}" 644 && changed=1
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
    if ! systemctl is-active --quiet "${UNIT_NAME}.path" 2>/dev/null; then
        systemctl enable --now "${UNIT_NAME}.path" >/dev/null 2>&1 \
            && say "queue watch enabled (a root request runs within seconds)" \
            || say "WARNING - could not enable ${UNIT_NAME}.path; requests wait for the timer" >&2
    fi
else
    # No .path equivalent under cron, so a request waits up to a minute here.
    CRON_TEXT="# Joinery host converger for ${SITENAME} (written by install_host_converger.sh; do not edit)
* * * * * root ${COMMAND} >> ${LOG_FILE} 2>&1"
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
