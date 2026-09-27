#!/usr/bin/env bash
#
# site_quiet.sh - freeze this site for a switch-over, or let it run again
# (specs/site_copy.md WP5). The agent's site_quiet word runs this file.
#
# Version: 1.0
#
#   site_quiet.sh on                    set `quiet switchover`, then wait until the
#                                       web user runs no command-line PHP
#   site_quiet.sh off                   clear `quiet switchover`
#   site_quiet.sh off --copy-promoted   clear `quiet copy` too: the agent passes
#                                       it only once this machine holds the
#                                       node id of the site it is a copy of
#
# What quiet means, and every measure, is _site_state.sh's. This script only
# sets or removes the state file and asserts or clears the measures at once,
# rather than at the converger's next tick.
#
# `on` never sets `quiet copy`: only the installer does, at a dormant install.
# And a copy is never cleared by a bare `off`, so a copy can never go live
# beside its source.
#
# Both hold the host runner lock (host_runner_lock.sh), so neither ever
# interleaves with a converger run.
#
# Exit: 0 done; 1 a measure did not hold (said why; the converger retries it
# every minute); 2 refused, nothing changed; 3 quiet, but the web user still
# runs command-line PHP when the wait ran out.

set -u

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SITE_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
SITENAME="$(basename "${SITE_ROOT}")"
TOOLS_DIR="${SITE_ROOT}/maintenance_scripts/install_tools"
WAIT_SECONDS=300

ACTION="${1:-}"
PROMOTED=0
[[ "${2:-}" == "--copy-promoted" ]] && PROMOTED=1
case "${ACTION}" in
    on|off) : ;;
    *) echo "usage: site_quiet.sh on | off [--copy-promoted]" >&2; exit 2 ;;
esac
if [[ "${ACTION}" == "on" && "${PROMOTED}" == "1" ]]; then
    echo "site_quiet: --copy-promoted goes with off - refused" >&2
    exit 2
fi

if [[ ! -f "${TOOLS_DIR}/_site_state.sh" ]]; then
    echo "site_quiet: ${TOOLS_DIR}/_site_state.sh is missing - refused" >&2
    exit 2
fi
# shellcheck source=../install_tools/_site_state.sh
. "${TOOLS_DIR}/_site_state.sh"
if ! site_state_init "${SITENAME}"; then
    echo "site_quiet: only root can set or clear a site's state - refused" >&2
    exit 2
fi
if [[ "$(id -u)" != "0" && "${JOINERY_SITE_STATE_WAIT:-}" =~ ^[0-9]+$ ]]; then
    WAIT_SECONDS="${JOINERY_SITE_STATE_WAIT}"
fi

# shellcheck source=host_runner_lock.sh
. "${SCRIPT_DIR}/host_runner_lock.sh"
hold_host_runner_lock "${SITENAME}" "${SITE_ROOT}" "Nothing was changed." || exit 2

REASON="$(site_state_read)"

if [[ "${ACTION}" == "on" ]]; then
    if [[ "${REASON}" == "copy" ]]; then
        echo "site_quiet: ${SITENAME} is a copy (quiet copy); only the installer sets that state, and on does not replace it - refused" >&2
        exit 2
    fi
    site_state_write switchover || { echo "site_quiet: cannot write ${SS_STATE}" >&2; exit 1; }
    echo "site_quiet: ${SITENAME} is quiet (switchover)"
    rc=0
    site_state_assert switchover || rc=1
    if ! site_state_wait_cli_php "${WAIT_SECONDS}"; then
        [[ "${rc}" == "0" ]] && rc=3
    else
        echo "site_quiet: the web user runs no command-line PHP"
    fi
    exit "${rc}"
fi

# off
if [[ "${REASON}" == "copy" && "${PROMOTED}" == "0" ]]; then
    echo "site_quiet: ${SITENAME} is a copy (quiet copy); it clears only once this machine holds its source's node id - refused" >&2
    exit 2
fi
if [[ -z "${REASON}" ]] && ! site_state_leftovers; then
    echo "site_quiet: ${SITENAME} is not quiet"
    exit 0
fi
rm -f "${SS_STATE}" || { echo "site_quiet: cannot remove ${SS_STATE}" >&2; exit 1; }
echo "site_quiet: ${SITENAME} is live; the converger's next run writes its cron and runs its installers"
site_state_clear || exit 1
exit 0
